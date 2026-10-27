<?php
require __DIR__ . '/inc/core.php';
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
start_session();
require __DIR__ . '/inc/views.php';

$p = preg_replace('/[^a-z\-]/', '', $_GET['p'] ?? '');
if ($p === '') $p = 'dashboard';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

try {
  if ($p === 'login') {
    if (uid()) redirect('');
    $err = '';
    if ($isPost) {
      check_csrf();
      $r = try_login(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''));
      if ($r === true) redirect('');
      $err = $r;
    }
    view_login($err, (string)($_POST['user'] ?? ''));
    exit;
  }
  if (!uid()) { if ($isPost && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) { http_response_code(401); exit('{"error":"Sesión expirada"}'); } redirect('login'); }
  $U = uid();

  if ($p === 'logout') {
    if ($isPost) { check_csrf(); $_SESSION = []; session_regenerate_id(true); session_destroy(); }
    redirect('login');
  }

  if ($isPost) { check_csrf(); require __DIR__ . '/inc/actions.php'; exit; }

  switch ($p) {
    case 'dashboard': view_dashboard(build_view()); break;
    case 'leads': view_leads(build_view()); break;
    case 'lead': view_lead(owner_param()); break;
    case 'embudo': view_kanban(build_view()); break;
    case 'ajustes': view_settings(load_data($U)); break;
    case 'exportar': export_csv(build_view()); break;
    default: http_response_code(404); layout('No encontrado', '', '<div class="card empty"><h2>Página no encontrada</h2><p><a class="btn" href="/mm/">Volver al inicio</a></p></div>');
  }
} catch (Throwable $e) {
  error_log('crm panel: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
  http_response_code(500);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Ocurrió un error en el servidor. <a href="/mm/">Volver</a></p>';
}
