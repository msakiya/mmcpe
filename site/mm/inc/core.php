<?php
// CRM CPE – núcleo del panel CRM (sesión, CSRF, datos compartidos con la extensión)
define('CRM_LIB', getenv('CRM_LIB') ?: '/home/mmcpe/crm-data/lib.php');
require CRM_LIB;
ensure_roles();
date_default_timezone_set('America/Lima');
mb_internal_encoding('UTF-8');

const DEFAULT_STAGES = ['Nuevo', 'Contactado', 'Interesado', 'Negociación', 'Cliente', 'Perdido'];
const DEFAULT_LABELS = [
  ['id' => 'l1', 'name' => 'VIP', 'color' => '#f5a623'],
  ['id' => 'l2', 'name' => 'Pendiente pago', 'color' => '#e74c3c'],
  ['id' => 'l3', 'name' => 'Mayorista', 'color' => '#3498db'],
];
const DEFAULT_REPLIES = [
  ['id' => 'r1', 'title' => 'Saludo', 'text' => '¡Hola! Gracias por escribirnos. ¿En qué te podemos ayudar?'],
  ['id' => 'r2', 'title' => 'Pago', 'text' => 'Puedes pagar por Yape, Plin o transferencia. Te envío los datos.'],
];

// ---------- Sesión ----------
function start_session() {
  $dir = dirname(CRM_DB) . '/sessions';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
  ini_set('session.use_strict_mode', '1');
  ini_set('session.gc_maxlifetime', '43200');
  session_name('crm_mm');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/mm', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
  if (!empty($_SESSION['uid']) && (time() - ($_SESSION['seen'] ?? 0)) > 43200) { $_SESSION = []; session_regenerate_id(true); }
  $_SESSION['seen'] = time();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function csrf() { return $_SESSION['csrf']; }
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }
function check_csrf() {
  $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!is_string($t) || !hash_equals(csrf(), $t)) { http_response_code(400); exit('Token CSRF inválido. Recarga la página.'); }
}
function uid() { return (int)($_SESSION['uid'] ?? 0); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect($p, $q = '') { header('Location: /mm/' . $p . ($q ? '?' . $q : '')); exit; }
function flash($msg = null, $type = 'ok') {
  if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return; }
  $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

// ---------- Login con límite de intentos (tabla attempts de la API) ----------
function too_many_attempts($ip) {
  db()->prepare('DELETE FROM attempts WHERE ts<?')->execute([time() - 900]);
  $st = db()->prepare('SELECT COUNT(*) c FROM attempts WHERE ip=?'); $st->execute([$ip]);
  return $st->fetch()['c'] >= 10;
}
function try_login($user, $pass) {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (too_many_attempts($ip)) return 'Demasiados intentos, espera 15 minutos.';
  $st = db()->prepare('SELECT id, username, pass_hash FROM users WHERE username=? COLLATE NOCASE'); $st->execute([$user]);
  $u = $st->fetch();
  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    db()->prepare('INSERT INTO attempts VALUES(?,?)')->execute([$ip, time()]);
    return 'Usuario o contraseña incorrectos.';
  }
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['user'] = $u['username'];
  $_SESSION['role'] = user_role((int)$u['id']);
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return true;
}

// ---------- Datos compartidos con la extensión (tabla data) ----------
function normalize_data($d) {
  if (!is_array($d)) $d = [];
  $c = (isset($d['contacts']) && is_array($d['contacts'])) ? $d['contacts'] : [];
  $out = [];
  foreach ($c as $k => $x) {
    if (!is_array($x)) continue;
    $id = (string)($x['id'] ?? $k);
    $out[$id] = array_merge(['id' => $id, 'name' => $id, 'phone' => '', 'stage' => '', 'labels' => [], 'notes' => [], 'value' => 0,
      'reminder' => '', 'reminderNote' => '', 'reminderDone' => false, 'created' => 0, 'updated' => 0], $x);
    if (!is_array($out[$id]['labels'])) $out[$id]['labels'] = [];
    if (!is_array($out[$id]['notes'])) $out[$id]['notes'] = [];
  }
  $d['contacts'] = $out;
  if (!isset($d['stages']) || !is_array($d['stages']) || !$d['stages']) $d['stages'] = DEFAULT_STAGES;
  $d['stages'] = array_values(array_map('strval', $d['stages']));
  if (!isset($d['labels']) || !is_array($d['labels'])) $d['labels'] = DEFAULT_LABELS;
  $d['labels'] = array_values(array_filter($d['labels'], 'is_array'));
  if (!isset($d['replies']) || !is_array($d['replies'])) $d['replies'] = DEFAULT_REPLIES;
  $d['replies'] = array_values(array_filter($d['replies'], 'is_array'));
  return $d;
}
function load_data($uid) {
  $st = db()->prepare('SELECT payload, updated_at FROM data WHERE user_id=?'); $st->execute([$uid]);
  $r = $st->fetch();
  return ['updatedAt' => $r ? (int)$r['updated_at'] : 0, 'data' => normalize_data($r ? json_decode($r['payload'], true) : [])];
}
function encode_data($d) {
  // contacts SIEMPRE como objeto JSON (la extensión lo usa como mapa id->contacto)
  $d['contacts'] = (object)$d['contacts'];
  if (isset($d['settings']) && is_array($d['settings']) && !$d['settings']) $d['settings'] = new stdClass;
  return json_encode($d, JSON_UNESCAPED_UNICODE);
}
/** Lee-modifica-escribe atómico; sube updatedAt para que la extensión baje la versión del servidor. */
function mutate($uid, callable $fn) {
  $pdo = db();
  $pdo->exec('BEGIN IMMEDIATE');
  try {
    $cur = load_data($uid);
    $d = $cur['data'];
    $res = $fn($d);
    if ($res === false) { $pdo->exec('ROLLBACK'); return false; }
    $ts = max((int)round(microtime(true) * 1000), $cur['updatedAt'] + 1);
    $pdo->prepare('INSERT INTO data(user_id,payload,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET payload=excluded.payload, updated_at=excluded.updated_at')
      ->execute([$uid, encode_data($d), $ts]);
    $pdo->exec('COMMIT');
    return $res === null ? true : $res;
  } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
}
function now_ms() { return (int)round(microtime(true) * 1000); }
function rid() { return substr(base_convert(bin2hex(random_bytes(6)), 16, 36), 0, 8); }

// ---------- Formato ----------
function money($v) { return 'S/ ' . number_format((float)$v, 2, '.', ','); }
function fdate($ms, $fmt = 'd/m/Y H:i') { return $ms ? date($fmt, (int)floor($ms / 1000)) : ''; }
function freminder($r) { if (!$r) return ''; $t = strtotime($r); return $t ? date('d/m/Y H:i', $t) : h($r); }
function reminder_state($c) {
  if (empty($c['reminder']) || !empty($c['reminderDone'])) return '';
  $t = strtotime($c['reminder']); if (!$t) return '';
  if ($t < time()) return 'vencido';
  if (date('Y-m-d', $t) === date('Y-m-d')) return 'hoy';
  return 'futuro';
}
function label_map($d) { $m = []; foreach ($d['labels'] as $l) $m[$l['id'] ?? ''] = $l; return $m; }
function col($c) { return preg_match('/^#[0-9a-f]{3,8}$/i', (string)$c) ? $c : '#888888'; }
function label_chips($c, $lm) {
  $o = '';
  foreach ($c['labels'] as $id) if (isset($lm[$id])) $o .= '<span class="chip" style="--c:' . col($lm[$id]['color'] ?? '') . '">' . h($lm[$id]['name'] ?? '') . '</span>';
  return $o;
}
function wa_link($phone) { $p = preg_replace('/\D/', '', (string)$phone); return $p ? 'https://wa.me/' . $p : ''; }
function stage_class($stage, $stages) { $i = array_search($stage, $stages, true); return 's' . (($i === false ? 0 : $i) % 6); }

// ---------- Roles (admin ve todo; vendedor solo lo suyo) ----------
const ROLES = ['admin' => 'Administrador', 'vendedor' => 'Vendedor'];
/** Migración segura: agrega users.role si falta (por defecto 'vendedor'). */
function ensure_roles() {
  static $done = false; if ($done) return; $done = true;
  $cols = array_column(db()->query('PRAGMA table_info(users)')->fetchAll(), 'name');
  if (!in_array('role', $cols, true)) {
    try { db()->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'vendedor'"); }
    catch (Throwable $e) { $cols = array_column(db()->query('PRAGMA table_info(users)')->fetchAll(), 'name'); if (!in_array('role', $cols, true)) throw $e; }
  }
}
function user_role($id) {
  $st = db()->prepare('SELECT role FROM users WHERE id=?'); $st->execute([(int)$id]);
  $r = $st->fetch(); return ($r && $r['role'] === 'admin') ? 'admin' : 'vendedor';
}
/** Rol leído de la BD en cada petición (un cambio de rol aplica de inmediato). */
function role() { static $r = null; if ($r === null) $r = uid() ? user_role(uid()) : 'vendedor'; return $r; }
function is_admin() { return role() === 'admin'; }
function role_name($r) { return ROLES[$r] ?? ROLES['vendedor']; }
function deny($msg = 'No tienes permiso para esto.') {
  http_response_code(403);
  if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) { header('Content-Type: application/json'); exit(json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE)); }
  if (function_exists('layout')) layout('Sin permiso', '', '<div class="card empty"><h2>Sin permiso</h2><p class="muted">' . h($msg) . '</p><p><a class="btn" href="/mm/">Volver al inicio</a></p></div>');
  else echo h($msg);
  exit;
}
function require_admin() { if (!is_admin()) deny('Solo un administrador puede gestionar usuarios.'); }
function all_users() {
  static $u = null;
  if ($u === null) { $u = []; foreach (db()->query("SELECT id, username, role FROM users ORDER BY id")->fetchAll() as $r) $u[(int)$r['id']] = ['id' => (int)$r['id'], 'username' => $r['username'], 'role' => $r['role'] === 'admin' ? 'admin' : 'vendedor']; }
  return $u;
}
function admin_count() { return (int)db()->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c']; }
/** Dueño del lead pedido (?u= o POST u). Vendedor: solo él mismo, si no 403. */
function owner_param() {
  $raw = $_POST['u'] ?? $_GET['u'] ?? '';
  if ($raw === '' || $raw === null) return uid();
  $o = (int)$raw;
  if ($o === uid()) return $o;
  if (!is_admin()) deny('Este lead pertenece a otro usuario.');
  if (!isset(all_users()[$o])) deny('Usuario no encontrado.');
  return $o;
}
function uname($id) { return all_users()[$id]['username'] ?? ('#' . $id); }
/**
 * Vista combinada: admin → leads de todos (o del vendedor filtrado con ?v=), vendedor → solo los suyos.
 * Cada contacto lleva _u (dueño). Etapas: unión (las del usuario actual primero).
 */
function build_view() {
  $multi = is_admin();
  $owners = $multi ? array_keys(all_users()) : [uid()];
  $fv = $multi ? (int)($_GET['v'] ?? 0) : 0;
  if ($fv && in_array($fv, $owners, true)) $owners = [$fv]; else $fv = 0;
  $own = load_data(uid());
  $V = ['multi' => $multi, 'fv' => $fv, 'owners' => $owners, 'me' => $own['data'], 'D' => [], 'lm' => [], 'contacts' => [], 'stages' => $own['data']['stages'], 'labels' => []];
  $labelNames = [];
  foreach ($owners as $o) {
    $d = $o === uid() ? $own['data'] : load_data($o)['data'];
    $V['D'][$o] = $d; $V['lm'][$o] = label_map($d);
    foreach ($d['stages'] as $s) if (!in_array($s, $V['stages'], true)) $V['stages'][] = $s;
    foreach ($d['labels'] as $l) { $n = (string)($l['name'] ?? ''); if ($n !== '' && !isset($labelNames[$n])) $labelNames[$n] = ['id' => $multi ? $n : ($l['id'] ?? ''), 'name' => $n, 'color' => $l['color'] ?? '']; }
    foreach ($d['contacts'] as $id => $c) { $c['_u'] = $o; $V['contacts'][$o . '|' . $id] = $c; }
  }
  // Vendedor: lista de etiquetas propia exactamente como antes (por id)
  $V['labels'] = $multi ? array_values($labelNames) : $own['data']['labels'];
  if (!$multi) $V['stages'] = $own['data']['stages'];
  return $V;
}
function lead_url($c, $id = null) {
  $id = $id ?? $c['id']; $u = (int)($c['_u'] ?? uid());
  return '/mm/lead?id=' . rawurlencode($id) . ($u !== uid() || is_admin() ? '&u=' . $u : '');
}
