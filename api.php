<?php
// API chat anonim. Butuh PHP 7.4+ dengan ekstensi pdo_sqlite.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

$action = $_GET['action'] ?? '';

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 1000);
    $cid  = preg_replace('/[^a-z0-9]/i', '', (string)($in['cid'] ?? ''));
    if ($body === '' || $cid === '') { http_response_code(400); echo '{"ok":false}'; exit; }

    // Batas kirim: maks 5 pesan per 10 detik per klien
    $q = $db->prepare('SELECT COUNT(*) FROM messages WHERE cid = ? AND ts > ?');
    $q->execute([$cid, time() - 10]);
    if ((int)$q->fetchColumn() >= 5) { http_response_code(429); echo '{"ok":false}'; exit; }

    $db->prepare('INSERT INTO messages (cid, body, ts) VALUES (?, ?, ?)')->execute([$cid, $body, time()]);
    $db->exec('DELETE FROM messages WHERE id <= (SELECT MAX(id) FROM messages) - 500'); // simpan 500 terakhir
    echo '{"ok":true}';
    exit;
}

if ($action === 'poll') {
    $since = (int)($_GET['since'] ?? 0);
    $q = $db->prepare('SELECT id, cid, body, ts FROM (
        SELECT * FROM messages WHERE id > ? ORDER BY id DESC LIMIT 200
    ) ORDER BY id ASC');
    $q->execute([$since]);
    echo json_encode(['messages' => $q->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

http_response_code(404);
echo '{"ok":false}';
