<?php
require '/home/mmcpe/crm-data/lib.php';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^(chrome-extension://[a-p]{32}|https://web\.whatsapp\.com)$#', $origin)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
function out($d, $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function body() { $b = json_decode(file_get_contents('php://input'), true); return is_array($b) ? $b : []; }
function auth() {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(\S+)$/', $h, $m)) out(['error' => 'No autorizado'], 401);
  $st = db()->prepare('SELECT user_id FROM tokens WHERE hash=?'); $st->execute([hash('sha256', $m[1])]);
  $r = $st->fetch(); if (!$r) out(['error' => 'Token inválido'], 401);
  db()->prepare('UPDATE tokens SET last_used=? WHERE hash=?')->execute([time(), hash('sha256', $m[1])]);
  return (int)$r['user_id'];
}
function newToken($uid, $kind, $label = null) {
  $t = ($kind === 'apikey' ? 'crmk_' : 'crmt_') . bin2hex(random_bytes(24));
  db()->prepare('INSERT INTO tokens(hash,user_id,kind,label,created) VALUES(?,?,?,?,?)')->execute([hash('sha256', $t), $uid, $kind, $label, time()]);
  return $t;
}
function normalizeMsg($m) {
  if (!is_array($m) || empty($m['id'])) return null;
  $dir = ($m['dir'] ?? '') === 'out' ? 'out' : 'in';
  $type = preg_replace('/[^a-z0-9_-]/i', '', (string)($m['type'] ?? 'text')) ?: 'text';
  $ts = (int)($m['ts'] ?? 0);
  if ($ts <= 0) return null;
  $text = mb_substr((string)($m['text'] ?? ''), 0, 8000);
  $meta = isset($m['meta']) ? json_encode($m['meta'], JSON_UNESCAPED_UNICODE) : null;
  $id = mb_substr((string)$m['id'], 0, 128);
  return ['id' => $id, 'ts' => $ts, 'dir' => $dir, 'type' => $type, 'text' => $text, 'meta' => $meta];
}
function trimContactMessages($uid, $contactId) {
  $st = db()->prepare('SELECT COUNT(*) c FROM messages WHERE user_id=? AND contact_id=?');
  $st->execute([$uid, $contactId]);
  $c = (int)$st->fetch()['c'];
  if ($c <= CRM_MSG_CAP) return;
  $extra = $c - CRM_MSG_CAP;
  db()->prepare('DELETE FROM messages WHERE rowid IN (
    SELECT rowid FROM messages WHERE user_id=? AND contact_id=? ORDER BY ts ASC, msg_id ASC LIMIT ?
  )')->execute([$uid, $contactId, $extra]);
}
function fetchMessages($uid, $contactId, $since = 0) {
  if ($since > 0) {
    $st = db()->prepare('SELECT msg_id AS id, ts, dir, type, text, meta FROM messages WHERE user_id=? AND contact_id=? AND ts>=? ORDER BY ts ASC, msg_id ASC');
    $st->execute([$uid, $contactId, $since]);
  } else {
    $st = db()->prepare('SELECT msg_id AS id, ts, dir, type, text, meta FROM messages WHERE user_id=? AND contact_id=? ORDER BY ts DESC, msg_id DESC LIMIT ' . (int)CRM_MSG_CAP);
    $st->execute([$uid, $contactId]);
    $rows = $st->fetchAll();
    $rows = array_reverse($rows);
    foreach ($rows as &$row) {
      if ($row['meta'] !== null) { $d = json_decode($row['meta'], true); $row['meta'] = $d; } else unset($row['meta']);
    }
    return $rows;
  }
  $rows = $st->fetchAll();
  foreach ($rows as &$row) {
    if ($row['meta'] !== null) { $d = json_decode($row['meta'], true); $row['meta'] = $d; } else unset($row['meta']);
  }
  return $rows;
}

$r = trim($_GET['r'] ?? trim($_SERVER['PATH_INFO'] ?? '', '/'), '/');
$m = $_SERVER['REQUEST_METHOD'];
try {
  if ($r === 'health') out(['ok' => true, 'time' => time()]);
  if ($r === 'auth/login' && $m === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    db()->prepare('DELETE FROM attempts WHERE ts<?')->execute([time() - 900]);
    $st = db()->prepare('SELECT COUNT(*) c FROM attempts WHERE ip=?'); $st->execute([$ip]);
    if ($st->fetch()['c'] >= 10) out(['error' => 'Demasiados intentos, espera 15 minutos'], 429);
    $b = body();
    $st = db()->prepare('SELECT id, pass_hash FROM users WHERE username=? COLLATE NOCASE'); $st->execute([(string)($b['user'] ?? '')]);
    $u = $st->fetch();
    if (!$u || !password_verify((string)($b['pass'] ?? ''), $u['pass_hash'])) {
      db()->prepare('INSERT INTO attempts VALUES(?,?)')->execute([$ip, time()]);
      out(['error' => 'Usuario o contraseña incorrectos'], 401);
    }
    $role = 'vendedor';
    try { $rs = db()->prepare('SELECT role FROM users WHERE id=?'); $rs->execute([(int)$u['id']]); $role = ($rs->fetch()['role'] ?? '') === 'admin' ? 'admin' : 'vendedor'; } catch (Throwable $e) {}
    out(['token' => newToken((int)$u['id'], 'session'), 'role' => $role]);
  }
  if ($r === 'auth/logout' && $m === 'POST') {
    auth(); preg_match('/Bearer\s+(\S+)/', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $mm);
    db()->prepare('DELETE FROM tokens WHERE hash=?')->execute([hash('sha256', $mm[1])]); out(['ok' => true]);
  }
  if ($r === 'apikey' && $m === 'POST') { $uid = auth(); out(['apiKey' => newToken($uid, 'apikey', (string)(body()['label'] ?? 'extension'))]); }
  if ($r === 'sync') {
    $uid = auth();
    $st = db()->prepare('SELECT payload, updated_at FROM data WHERE user_id=?'); $st->execute([$uid]);
    $cur = $st->fetch();
    if ($m === 'GET') out($cur ? ['updatedAt' => (int)$cur['updated_at'], 'data' => json_decode($cur['payload'], true)] : ['updatedAt' => 0, 'data' => null]);
    if ($m === 'PUT') {
      $b = body(); $ts = (int)($b['updatedAt'] ?? 0);
      if (!isset($b['data']) || !is_array($b['data']) || $ts <= 0) out(['error' => 'Formato inválido'], 400);
      if ($cur && (int)$cur['updated_at'] > $ts && empty($b['force']))
        out(['error' => 'El servidor tiene datos más nuevos', 'updatedAt' => (int)$cur['updated_at'], 'data' => json_decode($cur['payload'], true)], 409);
      db()->prepare('INSERT INTO data(user_id,payload,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET payload=excluded.payload, updated_at=excluded.updated_at')
        ->execute([$uid, json_encode($b['data'], JSON_UNESCAPED_UNICODE), $ts]);
      out(['ok' => true, 'updatedAt' => $ts]);
    }
  }

  // Conversaciones: preferir ?contactId= (evita 403 de Apache con acentos en la ruta)
  // GET /api/conversations            -> lista
  // GET /api/conversations?contactId= -> mensajes
  // PUT /api/conversations?contactId= -> upsert {messages:[...]}
  // También acepta /api/conversations/{id} para ids ASCII simples
  if ($r === 'conversations' || preg_match('#^conversations/(.+)$#', $r, $mm)) {
    $uid = auth();
    $contactId = '';
    if (!empty($_GET['contactId'])) $contactId = (string)$_GET['contactId'];
    elseif (!empty($mm[1])) $contactId = rawurldecode($mm[1]);
    $contactId = mb_substr(trim($contactId), 0, 200);

    if ($contactId === '') {
      if ($m !== 'GET') out(['error' => 'Falta contactId'], 400);
      $since = (int)($_GET['since'] ?? 0);
      if ($since > 0) {
        $st = db()->prepare('SELECT contact_id, MAX(ts) AS last_ts, COUNT(*) AS cnt FROM messages WHERE user_id=? AND ts>=? GROUP BY contact_id ORDER BY last_ts DESC LIMIT 200');
        $st->execute([$uid, $since]);
      } else {
        $st = db()->prepare('SELECT contact_id, MAX(ts) AS last_ts, COUNT(*) AS cnt FROM messages WHERE user_id=? GROUP BY contact_id ORDER BY last_ts DESC LIMIT 200');
        $st->execute([$uid]);
      }
      out(['conversations' => $st->fetchAll()]);
    }

    if ($m === 'GET') {
      $since = (int)($_GET['since'] ?? 0);
      out(['contactId' => $contactId, 'messages' => fetchMessages($uid, $contactId, $since), 'cap' => CRM_MSG_CAP]);
    }
    if ($m === 'PUT') {
      $b = body();
      $list = $b['messages'] ?? null;
      if (!is_array($list)) out(['error' => 'Se espera { messages: [...] }'], 400);
      $pdo = db();
      $ins = $pdo->prepare('INSERT INTO messages(user_id,contact_id,msg_id,ts,dir,type,text,meta) VALUES(?,?,?,?,?,?,?,?)
        ON CONFLICT(user_id,contact_id,msg_id) DO UPDATE SET ts=excluded.ts, dir=excluded.dir, type=excluded.type, text=excluded.text, meta=excluded.meta');
      $n = 0;
      $pdo->beginTransaction();
      try {
        foreach ($list as $raw) {
          $msg = normalizeMsg($raw);
          if (!$msg) continue;
          $ins->execute([$uid, $contactId, $msg['id'], $msg['ts'], $msg['dir'], $msg['type'], $msg['text'], $msg['meta']]);
          $n++;
        }
        trimContactMessages($uid, $contactId);
        $pdo->commit();
      } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
      }
      out(['ok' => true, 'upserted' => $n, 'messages' => fetchMessages($uid, $contactId), 'cap' => CRM_MSG_CAP]);
    }
  }

  out(['error' => 'No encontrado'], 404);
} catch (Throwable $e) { error_log('crm api: ' . $e->getMessage()); out(['error' => 'Error del servidor'], 500); }
