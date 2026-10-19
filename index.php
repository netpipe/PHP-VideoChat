<?php
/* ============================================================================
   STUDIO SIX — single-file PHP video chat room (6 cameras max, mesh WebRTC)
   ----------------------------------------------------------------------------
   Run:        php -S 0.0.0.0:8080 index.php
   Smooth:     PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:8080 index.php   (unix)
   Reset room: GET index.php?api=reset
   Requires:   PHP 7.4+ (8.x fine). Cameras need localhost or HTTPS.
   No libraries. Signaling = PHP long-poll over a flock()-guarded JSON file.
============================================================================ */

declare(strict_types=1);

define('SEATS', 6);
define('STALE_AFTER', 15);      // seconds without heartbeat -> ejected
define('POLL_SECONDS', 6);      // long-poll window
define('DATA_DIR', getenv('CAMROOM_DATA') ?: __DIR__ . '/.studio6');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0777, true);

function clip(string $s, int $n): string {
  $s = trim(preg_replace('/\s+/', ' ', $s)) ?: '';
  return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/** Open state file under exclusive lock, prune it, run $fn(&$state,$now), persist. */
function with_state(callable $fn) {
  $path = DATA_DIR . '/state.json';
  $fh = @fopen($path, 'c+');
  if (!$fh) { http_response_code(500); exit(json_encode(['error' => 'state storage unavailable'])); }
  flock($fh, LOCK_EX);
  $s = json_decode(stream_get_contents($fh) ?: '', true);
  if (!is_array($s)) $s = ['seq' => 0, 'parts' => [], 'msgs' => []];

  $now = time();
  foreach ($s['parts'] as $id => $p) if (($p['last'] ?? 0) < $now - STALE_AFTER) unset($s['parts'][$id]);
  $s['msgs'] = array_values(array_filter($s['msgs'] ?? [], fn($m) => ($m['t'] ?? 0) > $now - 120));
  if (count($s['msgs']) > 800) $s['msgs'] = array_slice($s['msgs'], -800);

  [$out, $dirty] = $fn($s, $now);
  if ($dirty) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); fflush($fh); }
  flock($fh, LOCK_UN); fclose($fh);
  return $out;
}

function roster_of(array $s): array {
  $out = [];
  foreach ($s['parts'] as $p) $out[] = ['id' => $p['id'], 'name' => $p['name'], 'role' => $p['role'], 'seat' => $p['role'] === 'cam' ? (int)$p['seat'] : -1];
  usort($out, fn($a, $b) => [($a['seat'] < 0 ? 99 : $a['seat']), $a['name']] <=> [($b['seat'] < 0 ? 99 : $b['seat']), $b['name']]);
  return $out;
}
function push_msg(array &$s, string $to, string $from, string $type, $data): void {
  $s['msgs'][] = ['seq' => ++$s['seq'], 'to' => $to, 'from' => $from, 'type' => $type, 'data' => $data, 't' => time()];
}
function seat_taken(array $s, int $seat, string $except): bool {
  foreach ($s['parts'] as $id => $p) if ($id !== $except && $p['role'] === 'cam' && (int)($p['seat'] ?? -1) === $seat) return true;
  return false;
}
function first_free_seat(array $s, string $except): int {
  for ($i = 0; $i < SEATS; $i++) if (!seat_taken($s, $i, $except)) return $i;
  return 0;
}

/* ------------------------------ API router ------------------------------ */
if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, max-age=0');
  $api = (string)$_GET['api'];
  $in  = json_decode(file_get_contents('php://input') ?: 'null', true);
  if (!is_array($in)) $in = [];
  $cid  = fn($v) => preg_replace('/[^a-z0-9\-_]/i', '', (string)($v ?? '')) ?: '';
  $cname = fn($v) => clip((string)($v ?? ''), 22) ?: 'Guest';

  if ($api === 'reset') { @unlink(DATA_DIR . '/state.json'); echo json_encode(['ok' => true]); exit; }

  if ($api === 'peek') {
    echo json_encode(with_state(function (&$s) {
      $cams = count(array_filter($s['parts'], fn($p) => $p['role'] === 'cam'));
      return [['seats' => SEATS, 'cams' => $cams, 'roster' => roster_of($s)], false];
    }, )); exit;
  }

  if ($api === 'hb') {                       // heartbeat + seat assignment
    $id = $cid($in['id'] ?? '');
    if ($id === '') { echo json_encode(['error' => 'bad id']); exit; }
    $name = $cname($in['name'] ?? 'Guest');
    $want = !empty($in['want']);
    echo json_encode(with_state(function (&$s, $now) use ($id, $name, $want) {
      $prev = $s['parts'][$id] ?? null;
      $otherCams = count(array_filter($s['parts'], fn($p) => $p['id'] !== $id && $p['role'] === 'cam'));
      $role = 'viewer';
      if ($want) $role = ((($prev['role'] ?? '') === 'cam') || $otherCams < SEATS) ? 'cam' : 'viewer';
      $seat = -1;
      if ($role === 'cam') {
        $seat = (int)($prev['seat'] ?? -1);
        if ($seat < 0 || $seat >= SEATS || seat_taken($s, $seat, $id)) $seat = first_free_seat($s, $id);
      }
      $s['parts'][$id] = ['id' => $id, 'name' => $name, 'role' => $role, 'seat' => $seat, 'want' => $want, 'last' => $now];
      if (!$prev || $prev['role'] !== $role) push_msg($s, '*', 'sys', 'roster', roster_of($s)); // instant topology update
      $cams = count(array_filter($s['parts'], fn($p) => $p['role'] === 'cam'));
      return [['ok' => true, 'role' => $role, 'seat' => $seat, 'cams' => $cams, 'seq' => (int)$s['seq'], 'roster' => roster_of($s)], true];
    })); exit;
  }

  if ($api === 'send') {                     // signaling relay (sdp / ice / chat)
    $from = $cid($in['from'] ?? '');
    $to   = (($in['to'] ?? '') === '*') ? '*' : $cid($in['to']);
    $type = (string)($in['type'] ?? '');
    if ($from === '' || $to === '' || !in_array($type, ['sdp', 'ice', 'chat'], true)) { echo json_encode(['error' => 'bad request']); exit; }
    $data = $in['data'] ?? null;
    if ($type === 'chat') {
      $data = ['name' => $cname($data['name'] ?? 'Guest'), 'text' => clip((string)($data['text'] ?? ''), 500)];
      if ($data['text'] === '') { echo json_encode(['error' => 'empty']); exit; }
    }
    echo json_encode(with_state(function (&$s) use ($to, $from, $type, $data) {
      push_msg($s, $to, $from, $type, $data);
      return [['ok' => true, 'seq' => (int)$s['seq']], true];
    })); exit;
  }

  if ($api === 'poll') {                     // long-poll inbox
    $me = $cid($_GET['me'] ?? '');
    $after = (int)($_GET['after'] ?? 0);
    @set_time_limit(POLL_SECONDS + 20);
    $deadline = microtime(true) + POLL_SECONDS;
    $out = ['msgs' => [], 'cursor' => $after];
    while (true) {
      $out = with_state(function (&$s) use ($me, $after) {
        $msgs = [];
        foreach ($s['msgs'] as $m) if ($m['seq'] > $after && ($m['to'] === '*' || $m['to'] === $me)) $msgs[] = $m;
        return [['msgs' => $msgs, 'cursor' => (int)$s['seq']], false];
      });
      if ($out['msgs'] || microtime(true) >= $deadline || connection_aborted()) break;
      usleep(250000);
    }
    echo json_encode($out); exit;
  }

  if ($api === 'leave') {
    $id = $cid($in['id'] ?? '');
    with_state(function (&$s) use ($id) {
      if (isset($s['parts'][$id])) { unset($s['parts'][$id]); push_msg($s, '*', 'sys', 'roster', roster_of($s)); return [true, true]; }
      return [false, false];
    });
    echo json_encode(['ok' => true]); exit;
  }

  echo json_encode(['error' => 'unknown api']); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>STUDIO SIX — live camera booth</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' fill='%23ff4d3d'/%3E%3Ctext x='8' y='12' font-size='11' text-anchor='middle' fill='%23fff' font-family='Arial' font-weight='bold'%3E6%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0e1116; --panel:#171e26; --panel2:#1f2833; --line:#2a3542; --line2:#3b4859;
  --ink:#eef0e8; --dim:#93a0ad; --faint:#5b6774;
  --red:#ff4d3d; --red-dk:#a3251a; --amber:#ffb454; --green:#5fd068; --cyan:#59c2d6;
  --bebas:"Bebas Neue","Arial Narrow",Impact,sans-serif;
  --sans:"IBM Plex Sans",system-ui,-apple-system,sans-serif;
  --mono:"IBM Plex Mono",ui-monospace,Menlo,monospace;
  --noise:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)'/%3E%3C/svg%3E");
  --bars7:linear-gradient(90deg,#b8b8b8 0 14.3%,#b8b800 0 28.6%,#00b8b8 0 42.9%,#00b800 0 57.2%,#b800b8 0 71.5%,#b80000 0 85.8%,#0000b8 0 100%);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:var(--sans); color:var(--ink); background:
    radial-gradient(1100px 560px at 88% -10%, rgba(255,180,84,.07), transparent 60%),
    radial-gradient(900px 520px at -8% 112%, rgba(89,194,214,.055), transparent 60%),
    var(--bg);
  display:flex; flex-direction:column; overflow:hidden;
}
/* film grain + scanlines over everything */
body::before{content:"";position:fixed;inset:-40px;z-index:200;pointer-events:none;background-image:var(--noise);opacity:.05;animation:grain 1.1s steps(4) infinite}
body::after{content:"";position:fixed;inset:0;z-index:201;pointer-events:none;background:repeating-linear-gradient(0deg,rgba(255,255,255,.025) 0 1px,transparent 1px 3px)}
@keyframes grain{0%{transform:translate(0,0)}25%{transform:translate(-14px,9px)}50%{transform:translate(9px,-12px)}75%{transform:translate(-7px,-8px)}100%{transform:translate(11px,10px)}}
.mono{font-family:var(--mono)}
.flex1{flex:1}
button{font-family:inherit;color:inherit;background:none;border:none;cursor:pointer}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-thumb{background:var(--line2);border-radius:5px}
::-webkit-scrollbar-track{background:transparent}

/* ============ masthead ============ */
.masthead{display:flex;align-items:center;gap:18px;padding:10px 18px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,#1c242f,#151b23);flex:0 0 auto}
.brand{display:flex;flex-direction:column;gap:1px}
.logo{font-family:var(--bebas);font-size:29px;letter-spacing:1.5px;line-height:1}
.logo .chip6{display:inline-block;background:var(--red);color:#fff;padding:1px 9px 0;margin-left:5px;transform:skewX(-6deg);box-shadow:3px 3px 0 rgba(0,0,0,.45)}
.tag{font-family:var(--mono);font-size:9.5px;letter-spacing:2.2px;color:var(--faint)}
#seatdots{display:flex;gap:5px;align-items:center}
#seatdots .dot{width:11px;height:11px;border-radius:50%;border:1px solid var(--line2);background:#141a21;transition:.3s}
#seatdots .dot.on{background:var(--amber);border-color:#ffd9a0;box-shadow:0 0 9px rgba(255,180,84,.8)}
.onair{font-family:var(--bebas);font-size:20px;letter-spacing:4px;padding:5px 15px 3px;border:1px solid var(--line2);border-radius:6px;color:var(--faint);transition:.4s}
.onair.hot{color:#fff;background:linear-gradient(180deg,#c22e20,var(--red-dk));border-color:#ff7a6d;text-shadow:0 0 12px rgba(255,90,70,.9);animation:onair 1.5s ease-in-out infinite}
@keyframes onair{0%,100%{box-shadow:0 0 18px -2px rgba(255,77,61,.65)}50%{box-shadow:0 0 6px -3px rgba(255,77,61,.25)}}
.clock{font-size:12.5px;color:var(--dim);letter-spacing:1px;min-width:104px;text-align:right}

/* ============ ticker ============ */
.ticker{border-bottom:1px solid var(--line);background:#12171e;overflow:hidden;flex:0 0 auto}
.tk{display:flex;width:max-content;animation:tk 34s linear infinite}
.ticker:hover .tk{animation-play-state:paused}
.tk span{font-family:var(--mono);font-size:10.5px;letter-spacing:2px;color:#c79a4e;padding:6px 0;white-space:pre}
@keyframes tk{to{transform:translateX(-50%)}}

#app{flex:1;display:flex;min-height:0;position:relative}

/* ============ entrance / green room ============ */
#entrance{flex:1;display:grid;grid-template-columns:minmax(0,1.12fr) minmax(330px,.88fr);gap:36px;align-items:center;padding:30px clamp(20px,4vw,56px);max-width:1280px;margin:0 auto;width:100%;overflow:auto}
.card{position:relative;aspect-ratio:4/3;border:1px solid var(--line2);border-radius:10px;overflow:hidden;box-shadow:0 24px 60px -24px rgba(0,0,0,.85), inset 0 0 0 6px #0a0d11;background:var(--bars7)}
.card::before{content:"";position:absolute;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,.14) 0 2px,transparent 2px 4px)}
.card::after{content:"";position:absolute;left:0;right:0;bottom:0;height:24%;background:#0b0e12;border-top:3px solid #05070a}
.nosignal{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;z-index:2}
.nosignal b{font-family:var(--bebas);font-size:clamp(40px,6vw,72px);letter-spacing:8px;color:#fff;text-shadow:0 4px 30px rgba(0,0,0,.8);animation:flick 3.4s steps(2) infinite}
.nosignal small{font-family:var(--mono);font-size:10.5px;letter-spacing:3px;color:rgba(255,255,255,.85);text-shadow:0 1px 8px #000}
@keyframes flick{0%,93%{opacity:1}94%{opacity:.55}96%{opacity:1}98%{opacity:.7}100%{opacity:1}}
.ch{position:absolute;top:14px;right:16px;z-index:2;font-size:11px;letter-spacing:2px;color:#fff;background:rgba(0,0,0,.55);padding:3px 8px;border-radius:4px}

.eyebrow{font-size:10.5px;letter-spacing:3px;color:var(--amber);margin-bottom:12px}
#entrance h1{font-family:var(--bebas);font-size:clamp(54px,7vw,88px);line-height:.9;letter-spacing:1px;margin-bottom:14px}
#entrance h1 em{font-style:normal;color:var(--red)}
.lede{color:var(--dim);font-size:14.5px;line-height:1.55;max-width:47ch;margin-bottom:22px}
.field-lbl{display:block;font-size:10px;letter-spacing:2.5px;color:var(--faint);margin-bottom:6px}
#nameInput{width:100%;background:#10151b;border:1px solid var(--line2);border-radius:8px;color:var(--ink);font:500 15px var(--sans);padding:11px 14px;outline:none;transition:.2s;margin-bottom:16px}
#nameInput:focus{border-color:var(--amber);box-shadow:0 0 0 3px rgba(255,180,84,.15)}
.seatrow{display:flex;align-items:center;gap:14px;margin-bottom:8px}
.lamps{display:flex;gap:6px}
.lamps i{width:14px;height:14px;border-radius:3px;border:1px solid var(--line2);background:#141a21;transition:.3s}
.lamps i.on{background:var(--amber);border-color:#ffd9a0;box-shadow:0 0 10px rgba(255,180,84,.75)}
#seatcount{font-size:11px;letter-spacing:1.5px;color:var(--dim)}
.who{font-size:11px;color:var(--faint);margin-bottom:20px;min-height:15px}
.big-btn{display:flex;justify-content:space-between;align-items:center;width:100%;padding:14px 18px;border-radius:9px;border:1px solid;font-size:14px;font-weight:700;letter-spacing:.5px;transition:.16s;margin-bottom:10px}
.big-btn .arw{font-family:var(--bebas);font-size:22px;line-height:0;transition:transform .16s}
.big-btn:hover .arw{transform:translateX(5px)}
.big-btn:hover{transform:translateY(-2px)}
.big-btn:active{transform:translateY(1px)}
.big-btn.primary{background:linear-gradient(180deg,#ffc873,#f2a53a);border-color:#ffd9a0;color:#241602;box-shadow:0 8px 24px -10px rgba(255,180,84,.5)}
.big-btn.primary:hover{box-shadow:0 12px 30px -10px rgba(255,180,84,.7)}
.big-btn.ghost{border-color:var(--line2);color:var(--dim)}
.big-btn.ghost:hover{border-color:var(--cyan);color:var(--cyan)}
.big-btn.busy{opacity:.6;pointer-events:none}
.fine{font-size:9.5px;letter-spacing:1.4px;color:var(--faint);line-height:1.9;margin-top:14px}
.fine b{color:var(--dim);font-weight:500}

/* ============ studio ============ */
#studio{flex:1;display:none;min-width:0}
#studio.live{display:flex;animation:fadein .5s ease}
@keyframes fadein{from{opacity:0;transform:scale(.992)}to{opacity:1;transform:none}}
.stagewrap{flex:1;display:flex;flex-direction:column;min-width:0;min-height:0}
#slots{flex:1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:repeat(2,minmax(0,1fr));gap:12px;padding:14px 16px;min-height:0}
.slot{position:relative;background:#090d12;border:1px solid var(--line);border-radius:10px;overflow:hidden;transition:border-color .25s,box-shadow .25s;min-height:0}
.slot::after{content:"";position:absolute;inset:-20px;background-image:var(--noise);opacity:.055;pointer-events:none;animation:grain .9s steps(3) infinite;z-index:2}
.slot .empty{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px}
.slot .empty .bars{width:58%;height:13%;border-radius:4px;opacity:.25;background:var(--bars7)}
.slot .empty .slab{font-family:var(--bebas);font-size:clamp(20px,2.6vw,32px);letter-spacing:3px;color:#38465c}
.slot .empty .st{font-family:var(--mono);font-size:9.5px;letter-spacing:2px;color:var(--faint)}
.slot.occupied .empty{display:none}
.slot .body{position:absolute;inset:0}
.tile{position:absolute;inset:0;animation:fadein .35s ease}
.tile video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000}
.tile video.mirror{transform:scaleX(-1)}
.tile .tag{position:absolute;top:8px;left:8px;z-index:4;font-family:var(--mono);font-size:9.5px;letter-spacing:1.5px;color:var(--amber);background:rgba(7,9,13,.75);border:1px solid var(--line2);border-radius:5px;padding:3px 8px}
.tile .plate{position:absolute;left:8px;right:8px;bottom:8px;z-index:4;display:flex;align-items:center;gap:9px;background:rgba(7,9,13,.72);border:1px solid rgba(255,255,255,.09);border-radius:7px;padding:6px 10px}
.tile .nm{font-size:13px;font-weight:600;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tile .live{display:flex;align-items:center;gap:5px;font-family:var(--mono);font-size:9px;letter-spacing:1.5px;color:#ff8a80}
.tile .live i{width:7px;height:7px;border-radius:50%;background:var(--red);animation:blink 1.15s steps(2) infinite}
@keyframes blink{50%{opacity:.15}}
.tile .nocam-ov{position:absolute;inset:0;z-index:3;display:none;align-items:center;justify-content:center;background:repeating-linear-gradient(45deg,#0d1218 0 14px,#111820 14px 28px)}
.tile.nocam .nocam-ov{display:flex}
.tile .nocam-ov span{font-family:var(--mono);font-size:10.5px;letter-spacing:3px;color:var(--faint);border:1px solid var(--line2);padding:7px 13px;border-radius:6px;background:rgba(9,12,16,.8)}
.slot.talking{border-color:var(--green);box-shadow:0 0 0 1px var(--green),0 0 26px -6px rgba(95,208,104,.55)}

/* deck */
.deck{flex:0 0 auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 16px;border-top:1px solid var(--line);background:linear-gradient(180deg,#181f28,#131920)}
.seatstat{font-family:var(--mono);font-size:10.5px;letter-spacing:1.6px;padding:9px 14px;border-radius:7px;border:1px solid var(--line2);color:var(--dim);transition:.2s}
.seatstat.onair{background:linear-gradient(180deg,#c22e20,var(--red-dk));border-color:#ff7a6d;color:#fff}
.seatstat.want{border-color:var(--amber);color:var(--amber)}
.seatstat.want:hover{background:rgba(255,180,84,.1)}
.hint{font-size:10px;letter-spacing:1.4px;color:var(--faint)}
.cbtn{display:flex;align-items:center;gap:9px;padding:9px 15px;border:1px solid var(--line2);border-radius:8px;background:linear-gradient(180deg,#232c38,#1a222c);font-size:12px;font-weight:600;letter-spacing:1px;transition:.15s;position:relative}
.cbtn:hover{transform:translateY(-1px);border-color:#54657c}
.cbtn:active{transform:translateY(1px)}
.cbtn .led{width:9px;height:9px;border-radius:50%;background:#39434f;box-shadow:inset 0 1px 2px rgba(0,0,0,.6);transition:.2s}
.cbtn.on .led{background:var(--green);box-shadow:0 0 8px rgba(95,208,104,.9)}
.cbtn.off .led{background:var(--red);box-shadow:0 0 8px rgba(255,77,61,.9)}
.cbtn.hot .led{background:var(--green);box-shadow:0 0 8px rgba(95,208,104,.9)}
.cbtn.dead{opacity:.35;pointer-events:none}
.cbtn.leave{border-color:#7e2a22;color:#ffb4ab;background:linear-gradient(180deg,#2a1512,#1f0f0c)}
.cbtn.leave:hover{border-color:var(--red);box-shadow:0 0 14px -4px rgba(255,77,61,.6)}
.cbtn .badge{display:none;position:absolute;top:-6px;right:-6px;background:var(--red);color:#fff;font:700 10px var(--mono);min-width:17px;height:17px;border-radius:9px;align-items:center;justify-content:center;padding:0 4px}

/* talkback / chat */
#chat{width:332px;flex:0 0 auto;border-left:1px solid var(--line);background:#131920;display:flex;flex-direction:column;min-height:0}
.chead{display:flex;justify-content:space-between;align-items:baseline;padding:11px 15px;border-bottom:1px solid var(--line)}
.chead b{font-family:var(--bebas);font-size:21px;letter-spacing:3px;color:var(--amber);font-weight:400}
.chead span{font-size:9px;letter-spacing:1.6px;color:var(--faint)}
#msgs{flex:1;overflow-y:auto;padding:12px 15px;display:flex;flex-direction:column;gap:7px;font-size:13px;line-height:1.45}
.line{animation:slidein .25s ease;word-break:break-word}
@keyframes slidein{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.line .t{font-family:var(--mono);font-size:9.5px;color:var(--faint);margin-right:7px}
.line .n{color:var(--amber);font-weight:600;margin-right:6px}
.line.sys{color:#7f90a3;font-style:italic;font-size:12px}
.cin{display:flex;gap:8px;padding:10px;border-top:1px solid var(--line)}
.cin input{flex:1;background:#10151b;border:1px solid var(--line2);border-radius:7px;color:var(--ink);font:400 13px var(--sans);padding:9px 12px;outline:none}
.cin input:focus{border-color:var(--amber)}
.cin button{background:var(--amber);color:#241602;font:700 11px var(--sans);letter-spacing:1px;padding:0 16px;border-radius:7px;transition:.15s}
.cin button:hover{filter:brightness(1.12)}

@media (max-width:1080px){#entrance{grid-template-columns:1fr;gap:22px;padding-top:22px}.card{max-width:560px;margin:0 auto}}
@media (max-width:980px){
  #slots{grid-template-columns:repeat(2,minmax(0,1fr));grid-template-rows:repeat(3,minmax(0,1fr));gap:8px;padding:8px}
  #chat{position:absolute;top:0;right:0;bottom:0;z-index:30;transform:translateX(105%);transition:transform .25s ease;box-shadow:-20px 0 50px rgba(0,0,0,.55);width:min(332px,86vw)}
  #app.chat-open #chat{transform:none}
  .clock{display:none}
}
@media (min-width:981px){#app:not(.chat-open) #chat{display:none}}
@media (max-width:600px){.tag{display:none}.masthead{gap:10px}.logo{font-size:24px}}

.toasts{position:fixed;top:110px;right:16px;z-index:300;display:flex;flex-direction:column;gap:8px;max-width:320px}
.toast{background:#1d2630;border:1px solid var(--line2);border-left:3px solid var(--amber);border-radius:8px;padding:10px 14px;font-size:13px;box-shadow:0 12px 30px rgba(0,0,0,.5);animation:slidein .3s ease;transition:opacity .3s,transform .3s}
.toast.err{border-left-color:var(--red)}
.toast.good{border-left-color:var(--green)}
.toast.bye{opacity:0;transform:translateX(20px)}
.tapsound{display:none;position:fixed;left:50%;bottom:76px;transform:translateX(-50%);z-index:300;align-items:center;gap:8px;padding:11px 20px;border-radius:9px;border:1px solid #ffd9a0;background:linear-gradient(180deg,#ffc873,#f2a53a);color:#241602;font-size:12px;font-weight:600;letter-spacing:1.5px;box-shadow:0 12px 30px rgba(0,0,0,.55)}
</style>
</head>
<body>

<header class="masthead">
  <div class="brand">
    <div class="logo">STUDIO<span class="chip6">SIX</span></div>
    <div class="tag">PUBLIC-ACCESS VIDEO ROUTER</div>
  </div>
  <div class="flex1"></div>
  <div id="seatdots" title="seat occupancy">
    <span class="dot"></span><span class="dot"></span><span class="dot"></span>
    <span class="dot"></span><span class="dot"></span><span class="dot"></span>
  </div>
  <div id="onair" class="onair">STANDBY</div>
  <div id="clock" class="clock mono">--:--:-- UTC</div>
</header>

<div class="ticker"><div class="tk">
  <span>● STUDIO SIX LIVE ROUTER&nbsp;&nbsp;● SIX SEATS MAX&nbsp;&nbsp;● FULL-MESH WEBRTC&nbsp;&nbsp;● SIGNALING: PHP LONG-POLL&nbsp;&nbsp;● NO ACCOUNTS · NO RECORDING&nbsp;&nbsp;● TALKBACK IS OPEN&nbsp;&nbsp;● WHEN A FEED DIES, ITS SEAT FREES UP&nbsp;&nbsp;</span>
  <span>● STUDIO SIX LIVE ROUTER&nbsp;&nbsp;● SIX SEATS MAX&nbsp;&nbsp;● FULL-MESH WEBRTC&nbsp;&nbsp;● SIGNALING: PHP LONG-POLL&nbsp;&nbsp;● NO ACCOUNTS · NO RECORDING&nbsp;&nbsp;● TALKBACK IS OPEN&nbsp;&nbsp;● WHEN A FEED DIES, ITS SEAT FREES UP&nbsp;&nbsp;</span>
</div></div>

<div id="app">

  <!-- ================ GREEN ROOM ================ -->
  <section id="entrance">
    <div class="card">
      <div class="nosignal"><b>NO SIGNAL</b><small>BOOTH SIX · AWAITING FEED</small></div>
      <div class="ch mono">CH·06</div>
    </div>
    <div class="console">
      <div class="eyebrow mono">PUBLIC ACCESS · LIVE · NO ACCOUNTS</div>
      <h1>TAKE A SEAT<em>.</em></h1>
      <p class="lede">Six cameras, one room. Video runs peer-to-peer in a full mesh straight between browsers — this PHP page only carries the handshake. Grab a seat, or lurk in the talkback until one opens.</p>
      <label class="field-lbl mono" for="nameInput">YOUR CALLSIGN</label>
      <input id="nameInput" maxlength="22" placeholder="e.g. Ruby Cam" autocomplete="off" spellcheck="false">
      <div class="seatrow">
        <div class="lamps" id="elamps"><i></i><i></i><i></i><i></i><i></i><i></i></div>
        <span id="seatcount" class="mono">— / 6 ON AIR</span>
      </div>
      <div class="who mono" id="who">checking the booth…</div>
      <button id="goLive" class="big-btn primary"><span class="lbl">GO LIVE — TAKE A SEAT</span><span class="arw">▸</span></button>
      <button id="watchOnly" class="big-btn ghost"><span class="lbl">WATCH ONLY — STAY IN THE TALKBACK</span><span class="arw">▸</span></button>
      <div class="fine mono">CAMERAS NEED <b>LOCALHOST</b> OR <b>HTTPS</b> · NOTHING IS RECORDED · WEAR HEADPHONES IF YOU OPEN TWO TABS · SEATS FREE UP AUTOMATICALLY</div>
    </div>
  </section>

  <!-- ================ STUDIO ================ -->
  <section id="studio">
    <div class="stagewrap">
      <div id="slots">
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 01</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 02</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 03</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 04</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 05</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
        <div class="slot"><div class="empty"><div class="bars"></div><div class="slab">SLOT 06</div><div class="st">OPEN — AWAITING FEED</div></div><div class="body"></div></div>
      </div>
      <div class="deck">
        <button id="seatChip" class="seatstat mono" title="">…</button>
        <span id="linkinfo" class="hint mono"></span>
        <span class="flex1"></span>
        <button id="btnMic" class="cbtn"><i class="led"></i>MIC</button>
        <button id="btnCam" class="cbtn"><i class="led"></i>CAMERA</button>
        <button id="btnChat" class="cbtn"><i class="led"></i>TALKBACK<span class="badge"></span></button>
        <button id="btnLeave" class="cbtn leave"><i class="led"></i>LEAVE</button>
      </div>
    </div>
    <aside id="chat">
      <div class="chead"><b>TALKBACK</b><span class="mono">ROOM TEXT CHANNEL</span></div>
      <div id="msgs"></div>
      <form id="chatForm" class="cin" autocomplete="off">
        <input id="chatInput" maxlength="500" placeholder="say something…">
        <button type="submit">SEND</button>
      </form>
    </aside>
  </section>

</div>

<div class="toasts" id="toasts"></div>
<button id="tapSound" class="tapsound mono">▶ TAP TO START VIDEO &amp; SOUND</button>

<script>
'use strict';
/* ============================ STUDIO SIX client ============================ */
const $  = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const API = '?api=';
const SEATS = 6;
const ICE = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };

const me = { id: 'u' + Math.random().toString(36).slice(2, 10), name: '' };
let role = 'viewer', want = false, joined = false, hbTimer = null;
let localStream = null, cursor = 0, lastRoster = [], firstRoster = true;
let prevIds = new Map(), unread = 0;
const pcs = {}, tiles = {}, remoteStreams = {}, meters = new Map();
let AC = null, camBusy = false;

/* ---------------- utilities ---------------- */
async function api(name, body) {
  const opt = body === undefined ? {} :
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
  const r = await fetch(API + name, opt);
  if (!r.ok) throw new Error('http ' + r.status);
  return r.json();
}
function toast(msg, kind) {
  const t = document.createElement('div');
  t.className = 'toast ' + (kind || '');
  t.textContent = msg;
  $('#toasts').append(t);
  setTimeout(() => { t.classList.add('bye'); setTimeout(() => t.remove(), 320); }, kind === 'err' ? 12000 : 4200);
}
function wakeAudio() {
  try { AC = AC || new (window.AudioContext || window.webkitAudioContext)(); AC.resume(); } catch (e) {}
}
setInterval(() => { $('#clock').textContent = new Date().toISOString().slice(11, 19) + ' UTC'; }, 1000);

/* ---------------- capture (camera + mic) ----------------
   Video and audio are requested together first; if that fails each one is retried on its own,
   so a camera problem never silently degrades to audio-only and the real reason is reported. */
const VIDEO_C = { width: { ideal: 640 }, height: { ideal: 480 } };
const AUDIO_C = { echoCancellation: true, noiseSuppression: true };
const inIframe = (() => { try { return window.self !== window.top; } catch (e) { return true; } })();

function mediaErrText(err, what) {
  const n = (err && err.name) || 'Error';
  if (n === 'NotAllowedError' || n === 'SecurityError')
    return what + ' blocked by the browser' + (inIframe ? ' (the embedding iframe needs allow="camera; microphone")' : ' (check the site permissions)');
  if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'No usable ' + what.toLowerCase() + ' found';
  if (n === 'NotReadableError' || n === 'AbortError')
    return what + ' could not start (' + n + ')' + (err.tried && err.tried.length ? ' — tried: ' + err.tried.join(', ') : '') +
      '. It is in use by another app or tab (Zoom, Teams, FaceTime, OBS…) or stuck: close those apps, or restart the computer';
  if (n === 'InsecureContext') return what + ' needs HTTPS or localhost';
  return what + ' failed: ' + n + (err && err.message ? ' (' + err.message + ')' : '');
}
async function tryGUM(c) {
  try { return { stream: await navigator.mediaDevices.getUserMedia(c) }; }
  catch (err) { console.warn('getUserMedia', JSON.stringify(c), err && err.name, err && err.message); return { err }; }
}
async function getCamera() {
  let r = await tryGUM({ video: VIDEO_C });
  if (r.err && r.err.name !== 'NotAllowedError' && r.err.name !== 'SecurityError') {
    const r2 = await tryGUM({ video: true });      // drop resolution hints (Safari / Continuity Camera)
    if (r2.stream) r = r2;
  }
  if (r.stream || r.err.name === 'NotAllowedError' || r.err.name === 'SecurityError') return r;
  const err0 = r.err;
  // the default camera would not start (often a virtual / iPhone camera, or one held by another app):
  // try every other camera on the machine before giving up
  let cams = [];
  try { cams = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput' && d.deviceId); } catch (e) {}
  r.tried = [];
  for (const d of cams) {
    const name = d.label || ('camera ' + (r.tried.length + 1));
    r.tried.push(name);
    const r3 = await tryGUM({ video: { deviceId: { exact: d.deviceId } } });
    if (r3.stream) { r3.device = name; return r3; }
  }
  try { err0.tried = r.tried; } catch (e) {}
  return r;
}
async function getMic() {
  let r = await tryGUM({ audio: AUDIO_C });
  if (r.err && r.err.name !== 'NotAllowedError' && r.err.name !== 'SecurityError') {
    const r2 = await tryGUM({ audio: true });
    if (r2.stream) r = r2;
  }
  return r;
}
async function acquireMedia() {
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    const err = { name: 'InsecureContext' };
    return { stream: null, videoErr: err, audioErr: err };
  }
  const both = await tryGUM({ video: VIDEO_C, audio: AUDIO_C });
  if (both.stream && both.stream.getVideoTracks().length) return { stream: both.stream };
  if (both.stream) both.stream.getTracks().forEach(t => t.stop());
  const a = await getMic(), v = await getCamera();
  const tracks = [...(v.stream ? v.stream.getVideoTracks() : []), ...(a.stream ? a.stream.getAudioTracks() : [])];
  return { stream: tracks.length ? new MediaStream(tracks) : null, videoErr: v.err, audioErr: a.err, device: v.device };
}
function reportMedia(r) {
  if (r.device) toast('Using camera: ' + r.device, 'good');
  if (!r.stream) { toast(mediaErrText(r.videoErr, 'Camera') + ' — joining as a watcher', 'err'); return; }
  if (r.videoErr) toast(mediaErrText(r.videoErr, 'Camera') + ' — mic only for now, press CAMERA to retry', 'err');
  else if (r.audioErr) toast(mediaErrText(r.audioErr, 'Microphone') + ' — camera only', 'err');
}
function watchLocalTracks() {
  if (!localStream) return;
  for (const t of localStream.getVideoTracks()) {
    if (t._watched) continue; t._watched = true;
    // Safari lets one tab capture at a time: another tab taking the camera mutes this track
    t.onmute = () => { renderSlots(); toast('Camera paused by the browser (another tab or app took it)', 'err'); };
    t.onunmute = () => renderSlots();
    t.onended = () => { renderSlots(); setDeckMedia(); };
  }
}

/* Safari only autoplays unmuted media when the page itself is capturing; watchers get a tap-to-play bar. */
let needTap = false;
function playVideo(v) {
  const p = v.play && v.play();
  if (p && p.catch) p.catch(() => {
    if (!v.muted) { v.muted = true; v._autoMuted = true; v.play().catch(() => {}); }
    if (!needTap) { needTap = true; $('#tapSound').style.display = 'flex'; }
  });
}
function unlockPlayback() {
  wakeAudio();
  $$('video').forEach(v => { if (v._autoMuted) { v.muted = false; v._autoMuted = false; } v.play && v.play().catch(() => {}); });
  needTap = false; $('#tapSound').style.display = 'none';
}

/* ---------------- entrance: live room status ---------------- */
try { $('#nameInput').value = localStorage.getItem('s6name') || ''; } catch (e) {}

async function peek() {
  try {
    const j = await api('peek');
    $('#seatcount').textContent = j.cams + ' / ' + SEATS + ' ON AIR';
    $$('#elamps i').forEach((el, i) => el.classList.toggle('on', j.roster.some(p => p.role === 'cam' && p.seat === i)));
    const names = j.roster.filter(p => p.role === 'cam').map(p => p.name);
    $('#who').textContent = names.length ? 'on air now: ' + names.join(' · ') : 'nobody on air — the booth is dark';
    const full = j.cams >= SEATS;
    $('#goLive .lbl').textContent = full ? 'BOOTH FULL — JOIN THE QUEUE' : 'GO LIVE — TAKE A SEAT';
  } catch (e) {}
}
peek(); setInterval(() => { if (!joined) peek(); }, 3000);

/* ---------------- joining ---------------- */
$('#goLive').onclick  = () => enter(true);
$('#watchOnly').onclick = () => enter(false);
$('#nameInput').addEventListener('keydown', e => { if (e.key === 'Enter') enter(true); });

async function enter(withCam) {
  if (joined) return;
  const btn = withCam ? $('#goLive') : $('#watchOnly');
  btn.classList.add('busy');
  me.name = ($('#nameInput').value.trim()).slice(0, 22) || ('Guest-' + me.id.slice(1, 5).toUpperCase());
  try { localStorage.setItem('s6name', me.name); } catch (e) {}
  wakeAudio();

  if (withCam) {
    const r = await acquireMedia();
    localStream = r.stream; reportMedia(r); watchLocalTracks();
  }
  want = withCam && !!localStream && localStream.getTracks().length > 0;

  joined = true;
  $('#entrance').style.display = 'none';
  $('#studio').classList.add('live');
  if (matchMedia('(min-width:981px)').matches) $('#app').classList.add('chat-open');

  const r = await heartbeat();
  if (r) { cursor = r.seq || 0; applyHb(r); }
  else toast('Signaling unreachable — is the PHP server running?', 'err');
  addSys('connected as ' + me.name);

  hbTimer = setInterval(async () => { const h = await heartbeat(); if (h) applyHb(h); }, 4000);
  pollLoop();
  btn.classList.remove('busy');
}
function heartbeat() { return api('hb', { id: me.id, name: me.name, want }).catch(() => null); }

/* ---------------- role transitions ---------------- */
function applyHb(r) {
  const prev = role; role = r.role;
  if (r.seq) cursor = Math.max(cursor, r.seq);
  if (role === 'cam' && prev !== 'cam') onPromoted(r.seat);
  else if (role !== 'cam' && prev === 'cam') onDemoted();
  applyRoster(r.roster || []);
}
async function onPromoted(seat) {
  wakeAudio();
  if (!localStream) {
    const r = await acquireMedia();
    localStream = r.stream; watchLocalTracks();
    if (!localStream) { want = false; toast('A seat opened, but ' + mediaErrText(r.videoErr, 'Camera').toLowerCase(), 'err'); return; }
    reportMedia(r);
  }
  publish();
  toast('YOU ARE ON AIR — SEAT 0' + (((seat ?? 0)) + 1), 'good');
}
function onDemoted() {
  if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
  for (const pid in pcs) pcs[pid].getSenders().forEach(s => { try { pcs[pid].removeTrack(s); } catch (e) {} });
  setDeckMedia(); renderSlots();
}
function publish() {
  // add every local track a peer is not sending yet (a camera turned on later included)
  for (const pid in pcs) {
    const pc = pcs[pid], sent = new Set(pc.getSenders().map(s => s.track).filter(Boolean));
    localStream.getTracks().forEach(t => { if (!sent.has(t)) pc.addTrack(t, localStream); });
  }
  reconcile(); renderSlots(); setDeckMedia();
}

/* ---------------- roster / mesh reconciliation ---------------- */
function applyRoster(roster) {
  lastRoster = roster;
  reconcile(); renderSlots(); rosterNotices(roster); updateHeader(); updateSeatChip();
}
function reconcile() {
  if (!joined) return;
  const others = lastRoster.filter(p => p.id !== me.id);
  for (const p of others) if (role === 'cam' || p.role === 'cam') getPC(p.id); // cam side adds tracks -> auto offer
  for (const pid of Object.keys(pcs)) {
    const still = others.some(p => p.id === pid && (role === 'cam' || p.role === 'cam'));
    if (!still) closePeer(pid);
  }
}
function closePeer(pid) {
  const pc = pcs[pid]; if (pc) { try { pc.close(); } catch (e) {} delete pcs[pid]; }
  delete remoteStreams[pid]; meters.delete(pid);
  const t = tiles[pid]; if (t) { t.remove(); delete tiles[pid]; }
}

/* ---------------- peer connections (perfect negotiation, lite) ---------------- */
function signal(pid, type, data) { api('send', { from: me.id, to: pid, type, data }).catch(() => {}); }

function getPC(pid) {
  if (pcs[pid]) return pcs[pid];
  const pc = new RTCPeerConnection(ICE);
  pc._polite = me.id > pid;
  pc._making = false;
  pcs[pid] = pc;
  if (localStream) localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
  pc.onicecandidate = e => { if (e.candidate) signal(pid, 'ice', e.candidate.toJSON()); };
  pc.ontrack = e => {
    const st = e.streams[0] || new MediaStream([e.track]);
    remoteStreams[pid] = st;
    const tile = tiles[pid];
    if (tile) { const v = tile.querySelector('video'); if (v.srcObject !== st) v.srcObject = st; playVideo(v); }
    syncVU();
  };
  pc.onnegotiationneeded = async () => {
    try { pc._making = true; await pc.setLocalDescription(); signal(pid, 'sdp', pc.localDescription); }
    catch (e) {} finally { pc._making = false; }
  };
  pc.onconnectionstatechange = () => {
    if (pc.connectionState === 'failed') { closePeer(pid); setTimeout(reconcile, 600); }
  };
  return pc;
}

/* ---------------- inbox (long-poll) ---------------- */
let chain = Promise.resolve();
async function pollLoop() {
  while (joined) {
    try {
      const ctl = new AbortController();
      const t = setTimeout(() => ctl.abort(), 12000);
      const r = await fetch(API + 'poll&me=' + encodeURIComponent(me.id) + '&after=' + cursor, { signal: ctl.signal });
      clearTimeout(t);
      const j = await r.json();
      if (!joined) return;
      if (j && Array.isArray(j.msgs)) {
        for (const m of j.msgs) chain = chain.then(() => handleMsg(m)).catch(() => {});
        if (typeof j.cursor === 'number') cursor = Math.max(cursor, j.cursor);
      }
    } catch (e) { await new Promise(res => setTimeout(res, 1200)); }
  }
}

async function handleMsg(m) {
  if (!joined || m.from === me.id) return;
  if (m.type === 'roster') { applyRoster(m.data || []); return; }
  if (m.type === 'chat') { addChat(m.data.name, m.data.text, m.t); bumpChat(); return; }
  if (m.type === 'sdp') {
    const pc = getPC(m.from), d = m.data;
    try {
      if (d.type === 'offer') {
        const collision = pc.signalingState !== 'stable' || pc._making;
        if (collision && !pc._polite) return;                    // impolite side wins
        if (collision) { try { await pc.setLocalDescription({ type: 'rollback' }); } catch (e) {} }
        await pc.setRemoteDescription(d);
        await pc.setLocalDescription();
        signal(m.from, 'sdp', pc.localDescription);
      } else {
        await pc.setRemoteDescription(d);
      }
    } catch (e) {}
    return;
  }
  if (m.type === 'ice') { try { await getPC(m.from).addIceCandidate(m.data); } catch (e) {} }
}

/* ---------------- rendering ---------------- */
const slotEls = $$('#slots .slot');

function makeTile(pid) {
  const el = document.createElement('div');
  el.className = 'tile';
  el.innerHTML = '<video autoplay playsinline></video>' +
    '<div class="nocam-ov"><span>CAMERA MUTED</span></div>' +
    '<div class="tag mono"></div>' +
    '<div class="plate"><span class="nm"></span><span class="live"><i></i>LIVE</span></div>';
  if (pid === me.id) { const v = el.querySelector('video'); v.muted = true; v.setAttribute('muted', ''); v.classList.add('mirror'); }
  return el;
}

function renderSlots() {
  const cams = lastRoster.filter(p => p.role === 'cam');
  for (const id of Object.keys(tiles)) {
    if (!cams.some(p => p.id === id)) { tiles[id].remove(); delete tiles[id]; meters.delete(id); }
  }
  slotEls.forEach((slot, i) => {
    const p = cams.find(x => x.seat === i);
    const body = slot.querySelector('.body');
    slot.classList.toggle('occupied', !!p);
    if (!p) { body.innerHTML = ''; slot.classList.remove('talking'); return; }
    let tile = tiles[p.id];
    if (!tile) { tile = makeTile(p.id); tiles[p.id] = tile; }
    tile.querySelector('.tag').textContent = (p.id === me.id ? 'YOU · ' : '') + 'CAM 0' + (i + 1);
    tile.querySelector('.nm').textContent = p.name + (p.id === me.id ? ' (you)' : '');
    body.append(tile);
    const v = tile.querySelector('video');
    if (p.id === me.id) {
      if (v.srcObject !== localStream) { v.srcObject = localStream; if (localStream) playVideo(v); }
      const live = localStream && localStream.getVideoTracks().some(t => t.enabled && !t.muted && t.readyState === 'live');
      tile.classList.toggle('nocam', !live);
    } else {
      const st = remoteStreams[p.id] || null;
      if (st && v.srcObject !== st) { v.srcObject = st; playVideo(v); }
    }
  });
  syncVU();
}

function updateHeader() {
  const cams = lastRoster.filter(p => p.role === 'cam');
  $$('#seatdots .dot').forEach((d, i) => d.classList.toggle('on', cams.some(p => p.seat === i)));
  const oa = $('#onair'), any = cams.length > 0;
  oa.classList.toggle('hot', any);
  oa.textContent = any ? 'ON AIR' : 'STANDBY';
  const n = Object.keys(pcs).length;
  $('#linkinfo').textContent = joined ? ('MESH ' + n + ' LINK' + (n === 1 ? '' : 'S') + ' · SIG LONG-POLL') : '';
}

function updateSeatChip() {
  const c = $('#seatChip'), cams = lastRoster.filter(p => p.role === 'cam');
  const mine = cams.find(p => p.id === me.id);
  if (mine) { c.textContent = 'ON AIR · SEAT 0' + (mine.seat + 1); c.className = 'seatstat mono onair'; c.title = 'Click to free your seat'; }
  else if (cams.length < SEATS) { c.textContent = 'REQUEST A SEAT'; c.className = 'seatstat mono want'; c.title = ''; }
  else { c.textContent = 'BOOTH FULL · WATCHING'; c.className = 'seatstat mono'; c.title = ''; }
  setDeckMedia();
}
$('#seatChip').onclick = () => {
  if (!joined) return;
  if (role === 'cam') { want = false; toast('Freeing your seat…'); heartbeat().then(r => r && applyHb(r)); }
  else if (lastRoster.filter(p => p.role === 'cam').length < SEATS) { want = true; toast('Requesting a seat…'); heartbeat().then(r => r && applyHb(r)); }
};

function setDeckMedia() {
  $('#btnMic').classList.toggle('dead', !localStream || !localStream.getAudioTracks().length);
  $('#btnCam').classList.toggle('dead', !localStream || camBusy);   // no video yet = clickable, asks again
  const vt = localStream && localStream.getVideoTracks().find(t => t.readyState === 'live');
  const at = localStream && localStream.getAudioTracks()[0];
  $('#btnCam').classList.toggle('on', !!(vt && vt.enabled));
  $('#btnCam').classList.toggle('off', !!(vt && !vt.enabled));
  $('#btnMic').classList.toggle('on', !!(at && at.enabled));
  $('#btnMic').classList.toggle('off', !!(at && !at.enabled));
}
$('#btnCam').onclick = async () => {
  if (!localStream || camBusy) return;
  const t = localStream.getVideoTracks().find(x => x.readyState === 'live');
  if (t) { t.enabled = !t.enabled; renderSlots(); setDeckMedia(); return; }
  // joined mic-only (or the camera ended): ask for it again, from this click
  camBusy = true; setDeckMedia();
  const r = await getCamera();
  camBusy = false;
  if (!r.stream) { toast(mediaErrText(r.err, 'Camera'), 'err'); setDeckMedia(); return; }
  if (r.device) toast('Using camera: ' + r.device, 'good');
  localStream.getVideoTracks().forEach(x => localStream.removeTrack(x));
  const nt = r.stream.getVideoTracks()[0];
  localStream.addTrack(nt); watchLocalTracks();
  for (const pid in pcs) {
    const old = pcs[pid].getSenders().find(s => s.track && s.track.kind === 'video');
    if (old) old.replaceTrack(nt); else pcs[pid].addTrack(nt, localStream);   // addTrack renegotiates
  }
  const sv = tiles[me.id] && tiles[me.id].querySelector('video');
  if (sv) { sv.srcObject = null; sv.srcObject = localStream; playVideo(sv); }
  toast('Camera on', 'good'); renderSlots(); setDeckMedia();
};
$('#btnMic').onclick = () => {
  const t = localStream && localStream.getAudioTracks()[0]; if (!t) return;
  t.enabled = !t.enabled; setDeckMedia();
};

/* ---------------- speech meters (tally lights) ---------------- */
function syncVU() {
  if (!AC) return;
  const cams = lastRoster.filter(p => p.role === 'cam');
  const keep = new Set();
  for (const p of cams) {
    const stream = p.id === me.id ? localStream : remoteStreams[p.id];
    if (!stream || !tiles[p.id]) continue;
    keep.add(p.id);
    const m = meters.get(p.id);
    if (m) { m.el = tiles[p.id]; if (m.stream === stream) continue; meters.delete(p.id); }
    try {
      const src = AC.createMediaStreamSource(stream);
      const an = AC.createAnalyser(); an.fftSize = 1024; src.connect(an);
      meters.set(p.id, { an, buf: new Uint8Array(an.fftSize), el: tiles[p.id], stream });
    } catch (e) {}
  }
  for (const id of [...meters.keys()]) if (!keep.has(id)) meters.delete(id);
}
let lastVU = 0;
function vuLoop(ts) {
  requestAnimationFrame(vuLoop);
  if (ts - lastVU < 110) return; lastVU = ts;
  let meTalking = false;
  for (const [pid, m] of meters) {
    m.an.getByteTimeDomainData(m.buf);
    let sum = 0;
    for (let i = 0; i < m.buf.length; i += 4) { const v = (m.buf[i] - 128) / 128; sum += v * v; }
    const rms = Math.sqrt(sum / (m.buf.length / 4));
    const talking = rms > 0.055;
    if (m.el) m.el.classList.toggle('talking', talking);
    if (pid === me.id) meTalking = talking;
  }
  $('#btnMic').classList.toggle('hot', meTalking);
}
requestAnimationFrame(vuLoop);

/* ---------------- talkback (chat) ---------------- */
function pushMsgEl(d) {
  const box = $('#msgs'); box.append(d);
  while (box.children.length > 250) box.firstChild.remove();
  box.scrollTop = box.scrollHeight;
}
function addChat(name, text, ts) {
  const d = document.createElement('div'); d.className = 'line';
  const t = new Date((ts || Math.floor(Date.now() / 1000)) * 1000);
  const tt = document.createElement('span'); tt.className = 't'; tt.textContent = t.toTimeString().slice(0, 8);
  const nn = document.createElement('span'); nn.className = 'n'; nn.textContent = name;
  const xx = document.createTextNode(' ' + text);
  d.append(tt, nn, xx); pushMsgEl(d);
}
function addSys(text) { const d = document.createElement('div'); d.className = 'line sys'; d.textContent = '— ' + text; pushMsgEl(d); }

$('#chatForm').addEventListener('submit', e => {
  e.preventDefault();
  const inp = $('#chatInput'), text = inp.value.trim();
  if (!text || !joined) return;
  inp.value = '';
  addChat(me.name, text);
  signal('*', 'chat', { name: me.name, text });
});
function bumpChat() {
  if (matchMedia('(max-width:980px)').matches && !$('#app').classList.contains('chat-open')) {
    unread++;
    const b = $('#btnChat .badge'); b.textContent = unread; b.style.display = 'inline-flex';
  }
}
$('#tapSound').onclick = unlockPlayback;
$('#btnChat').onclick = () => {
  $('#app').classList.toggle('chat-open');
  unread = 0; $('#btnChat .badge').style.display = 'none';
};
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && matchMedia('(max-width:980px)').matches) $('#app').classList.remove('chat-open');
});

/* ---------------- join / leave notices ---------------- */
function rosterNotices(roster) {
  const nowMap = new Map(roster.map(p => [p.id, p]));
  if (!firstRoster) {
    for (const [id, p] of prevIds) if (!nowMap.has(id) && id !== me.id) addSys(p.name + ' left the booth');
    for (const [id, p] of nowMap) {
      if (id === me.id) continue;
      const old = prevIds.get(id);
      if (!old) addSys(p.name + (p.role === 'cam' ? ' is on air — seat 0' + (p.seat + 1) : ' is watching'));
      else if (old.role !== p.role) addSys(p.role === 'cam' ? p.name + ' took seat 0' + (p.seat + 1) : p.name + ' freed a seat');
    }
  }
  firstRoster = false; prevIds = nowMap;
}

/* ---------------- leaving ---------------- */
function sayLeave() {
  try { navigator.sendBeacon(API + 'leave', new Blob([JSON.stringify({ id: me.id })], { type: 'application/json' })); }
  catch (e) { try { fetch(API + 'leave', { method: 'POST', body: JSON.stringify({ id: me.id }), keepalive: true }); } catch (e2) {} }
}
$('#btnLeave').onclick = leave;
function leave() {
  if (!joined) return;
  joined = false; clearInterval(hbTimer); sayLeave();
  Object.keys(pcs).forEach(closePeer);
  if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
  role = 'viewer'; want = false; lastRoster = []; prevIds = new Map(); firstRoster = true; cursor = 0;
  $('#msgs').innerHTML = '';
  $('#studio').classList.remove('live');
  $('#app').classList.remove('chat-open');
  $('#entrance').style.display = '';
  updateHeader(); peek();
}
window.addEventListener('pagehide', () => { if (joined) sayLeave(); });
</script>
</body>
</html>