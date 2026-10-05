<?php
// Chat grup anonim — tanpa login, tanpa nama pengirim.
// Butuh: PHP 7.4+ dengan ekstensi pdo_sqlite. Taruh file ini di folder web, lalu buka di browser.

$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
}
$db = new PDO('sqlite:' . $dir . '/chat.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cid TEXT NOT NULL,
    body TEXT NOT NULL,
    ts INTEGER NOT NULL
)');

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if ($_GET['api'] === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in   = json_decode(file_get_contents('php://input'), true) ?: [];
        $body = trim((string)($in['body'] ?? ''));
        $cid  = preg_replace('/[^a-z0-9]/i', '', (string)($in['cid'] ?? ''));
        $body = mb_substr($body, 0, 1000);
        if ($body === '' || $cid === '') { http_response_code(400); echo '{"ok":false}'; exit; }

        // Batas kirim sederhana: maks 5 pesan per 10 detik per klien
        $q = $db->prepare('SELECT COUNT(*) FROM messages WHERE cid = ? AND ts > ?');
        $q->execute([$cid, time() - 10]);
        if ((int)$q->fetchColumn() >= 5) { http_response_code(429); echo '{"ok":false}'; exit; }

        $db->prepare('INSERT INTO messages (cid, body, ts) VALUES (?, ?, ?)')
           ->execute([$cid, $body, time()]);
        // Simpan hanya 500 pesan terakhir
        $db->exec('DELETE FROM messages WHERE id <= (SELECT MAX(id) FROM messages) - 500');
        echo '{"ok":true}';
        exit;
    }

    if ($_GET['api'] === 'poll') {
        $since = (int)($_GET['since'] ?? 0);
        $q = $db->prepare('SELECT id, cid, body, ts FROM (
            SELECT * FROM messages WHERE id > ? ORDER BY id DESC LIMIT 200
        ) ORDER BY id ASC');
        $q->execute([$since]);
        echo json_encode(['messages' => $q->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    http_response_code(404);
    exit;
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Obrolan Bersama</title>
<style>
  :root {
    --bg: #e9edf0; --panel: #ffffff; --ink: #1d2327; --muted: #6b7780;
    --mine: #cdeedd; --theirs: #ffffff; --line: #d7dde1; --accent: #1f8a5b;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #11181c; --panel: #1a2328; --ink: #e6ecef; --muted: #8b99a1;
            --mine: #1d5a40; --theirs: #243038; --line: #2c3a42; --accent: #3fbf88; }
  }
  * { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    background: var(--bg); color: var(--ink);
    font: 16px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    display: flex; justify-content: center;
  }
  .app {
    width: 100%; max-width: 720px; height: 100%;
    display: flex; flex-direction: column; background: var(--bg);
  }
  header {
    background: var(--panel); border-bottom: 1px solid var(--line);
    padding: calc(12px + env(safe-area-inset-top, 0px)) 16px 12px;
    display: flex; align-items: center; gap: 12px;
  }
  .avatar {
    width: 40px; height: 40px; border-radius: 50%; background: var(--accent);
    color: #fff; display: grid; place-items: center; font-weight: 700;
  }
  header h1 { font-size: 17px; margin: 0; }
  header small { color: var(--muted); }
  #log {
    flex: 1; overflow-y: auto; padding: 14px 12px; display: flex;
    flex-direction: column; gap: 4px;
  }
  .day { align-self: center; font-size: 12px; color: var(--muted);
         background: var(--panel); padding: 3px 10px; border-radius: 10px; margin: 8px 0; }
  .msg {
    max-width: 80%; padding: 7px 10px 5px; border-radius: 12px;
    background: var(--theirs); align-self: flex-start;
    border-top-left-radius: 3px; box-shadow: 0 1px 0 rgba(0,0,0,.06);
    overflow-wrap: anywhere; white-space: pre-wrap;
  }
  .msg.me { background: var(--mine); align-self: flex-end;
            border-top-left-radius: 12px; border-top-right-radius: 3px; }
  .msg time { display: block; text-align: right; font-size: 11px;
              color: var(--muted); margin-top: 2px; }
  .empty { margin: auto; color: var(--muted); text-align: center; }
  footer {
    background: var(--panel); border-top: 1px solid var(--line);
    padding: 8px 10px calc(8px + env(safe-area-inset-bottom, 0px));
    display: flex; gap: 8px; align-items: flex-end;
  }
  textarea {
    flex: 1; resize: none; max-height: 120px; padding: 10px 12px;
    border: 1px solid var(--line); border-radius: 20px; font: inherit;
    background: var(--bg); color: var(--ink); outline: none;
  }
  textarea:focus { border-color: var(--accent); }
  button {
    width: 42px; height: 42px; border: 0; border-radius: 50%;
    background: var(--accent); color: #fff; font-size: 18px; cursor: pointer;
  }
  button:disabled { opacity: .5; cursor: default; }
</style>
</head>
<body>
<div class="app">
  <header>
    <div class="avatar">#</div>
    <div><h1>Obrolan Bersama</h1><small>Semua orang di sini anonim</small></div>
  </header>
  <div id="log"><div class="empty">Belum ada pesan. Mulai obrolan!</div></div>
  <footer>
    <textarea id="box" rows="1" maxlength="1000" placeholder="Ketik pesan…" autocomplete="off"></textarea>
    <button id="send" aria-label="Kirim">➤</button>
  </footer>
</div>

<script>
  // ID acak per browser, hanya untuk menandai pesan milik sendiri (rata kanan). Bukan identitas.
  let cid = null;
  try { cid = localStorage.getItem('cid'); } catch (e) {}
  if (!cid) {
    cid = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
    try { localStorage.setItem('cid', cid); } catch (e) {}
  }

  const log = document.getElementById('log');
  const box = document.getElementById('box');
  const btn = document.getElementById('send');
  let lastId = 0, lastDay = '', first = true;

  function addMessage(m) {
    if (first) { log.innerHTML = ''; first = false; }
    const d = new Date(m.ts * 1000);
    const day = d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
    if (day !== lastDay) {
      const s = document.createElement('div');
      s.className = 'day'; s.textContent = day; log.appendChild(s); lastDay = day;
    }
    const el = document.createElement('div');
    el.className = 'msg' + (m.cid === cid ? ' me' : '');
    el.append(document.createTextNode(m.body));
    const t = document.createElement('time');
    t.textContent = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    el.appendChild(t);
    log.appendChild(el);
  }

  async function poll() {
    try {
      const r = await fetch('?api=poll&since=' + lastId, { cache: 'no-store' });
      const { messages } = await r.json();
      if (messages.length) {
        const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 120;
        messages.forEach(m => { addMessage(m); lastId = Math.max(lastId, +m.id); });
        if (nearBottom || lastId === +messages[messages.length - 1].id && first === false && messages.length > 1) {
          log.scrollTop = log.scrollHeight;
        }
      }
    } catch (e) { /* abaikan, coba lagi di putaran berikutnya */ }
  }

  async function send() {
    const body = box.value.trim();
    if (!body) return;
    btn.disabled = true;
    try {
      const r = await fetch('?api=send', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cid, body })
      });
      if (r.ok) { box.value = ''; box.style.height = 'auto'; await poll(); log.scrollTop = log.scrollHeight; }
    } catch (e) {}
    btn.disabled = false; box.focus();
  }

  btn.addEventListener('click', send);
  box.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  box.addEventListener('input', () => {
    box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 120) + 'px';
  });

  poll().then(() => { log.scrollTop = log.scrollHeight; });
  setInterval(poll, 2000);
</script>
</body>
</html>
