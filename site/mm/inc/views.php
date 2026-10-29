<?php
const ASSET_V = '4';
function icon($n) {
  $p = [
    'home' => '<path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    'users' => '<circle cx="9" cy="8" r="4"/><path d="M1 21c0-4 4-6 8-6s8 2 8 6"/><path d="M17 4a4 4 0 0 1 0 8M23 21c0-3-2-5-5-6"/>',
    'kanban' => '<rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="4" height="8" rx="1"/>',
    'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
    'wa' => '<path d="M3 21l1.6-4.7A9 9 0 1 1 8 19.6z"/><path d="M9 10c0 3 2 5 5 5l1.5-1.5-2-1-1 1c-1-.5-2-1.5-2.5-2.5l1-1-1-2z"/>',
    'down' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
  ][$n] ?? '';
  return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
function head_html($title) {
  return '<!doctype html><html lang="es-PE"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><meta name="csrf" content="' . h(csrf()) . '"><title>' . h($title) . ' · CRM CPE</title>'
    . '<link rel="icon" type="image/png" href="/mm/assets/icon.png"><link rel="apple-touch-icon" href="/mm/assets/apple-touch-icon.png"><meta name="theme-color" content="#1b282e"><link rel="stylesheet" href="/mm/assets/app.css?v=' . ASSET_V . '"><script src="/mm/assets/app.js?v=' . ASSET_V . '" defer></script></head>';
}
function pass_input($name, $label, $auto = 'current-password', $req = true) {
  $id = 'pw_' . $name;
  return '<label for="' . $id . '">' . h($label) . '</label><div class="pw"><input type="password" id="' . $id . '" name="' . h($name) . '" autocomplete="' . $auto . '"' . ($req ? ' required' : '') . '>'
    . '<button type="button" class="eye" data-eye="' . $id . '" aria-label="Mostrar contraseña" title="Mostrar contraseña">' . icon('eye') . '</button></div>';
}
function layout($title, $active, $content) {
  $nav = [['', 'Inicio', 'home'], ['leads', 'Leads', 'users'], ['embudo', 'Embudo', 'kanban'], ['ajustes', 'Ajustes', 'gear']];
  echo head_html($title) . '<body><div class="app"><aside class="side"><a class="brand" href="/mm/" title="CRM CPE"><img class="brand-logo" src="/mm/assets/logo.png" alt="CRM CPE" width="160" height="44"><img class="brand-icon" src="/mm/assets/apple-touch-icon.png" alt="CRM CPE" width="34" height="34"><span class="brand-sub">CRM</span></a><nav>';
  foreach ($nav as [$k, $l, $i]) echo '<a href="/mm/' . $k . '" class="' . ($active === ($k ?: 'dashboard') ? 'on' : '') . '">' . icon($i) . '<span>' . $l . '</span></a>';
  echo '</nav><form method="post" action="/mm/logout" class="logout">' . csrf_field() . '<span class="who" title="' . h(role_name(role())) . '">' . h($_SESSION['user'] ?? '') . ' · ' . h(role_name(role())) . '</span><button class="link" title="Cerrar sesión">' . icon('out') . '<span>Salir</span></button></form></aside><main class="main">';
  if ($f = flash()) echo '<div class="flash ' . h($f[1]) . '" role="status">' . h($f[0]) . '</div>';
  echo $content . '</main></div></body></html>';
}

// ---------- Login ----------
function view_login($err, $user) {
  echo head_html('Iniciar sesión') . '<body class="login-bg"><main class="login"><div class="card login-card"><div class="login-logo"><img src="/mm/assets/logo.png" alt="CRM CPE" width="240" height="65"></div><h1 class="login-title">CRM CPE</h1>'
    . '<p class="muted">Ingresa con el mismo usuario de la extensión.</p>'
    . ($err ? '<div class="flash err">' . h($err) . '</div>' : '')
    . '<form method="post" action="/mm/login" class="form">' . csrf_field()
    . '<label for="user">Usuario</label><input id="user" name="user" autocomplete="username" required autofocus value="' . h($user) . '">'
    . pass_input('pass', 'Contraseña')
    . '<button class="btn primary block">Ingresar</button></form><p class="foot">CRM CPE · Comunidad CPE</p></div></main></body></html>';
}

// ---------- Helpers multiusuario ----------
function owner_badge($V, $c) { return $V['multi'] ? '<span class="badge vend" title="Vendedor">' . h(uname((int)$c['_u'])) . '</span>' : ''; }
function chips_for($V, $c) { return label_chips($c, $V['lm'][(int)$c['_u']] ?? []); }
function vend_select($V, $name = 'v', $sel = 0, $auto = true, $all = 'Todos los vendedores') {
  $o = '<select name="' . h($name) . '"' . ($auto ? ' data-autosubmit' : '') . ' aria-label="Vendedor">' . ($all !== '' ? '<option value="">' . h($all) . '</option>' : '');
  foreach (all_users() as $u) $o .= '<option value="' . $u['id'] . '"' . ((int)$sel === $u['id'] ? ' selected' : '') . '>' . h($u['username']) . ($u['role'] === 'admin' ? ' (admin)' : '') . '</option>';
  return $o . '</select>';
}

// ---------- Dashboard ----------
function view_dashboard($V) {
  $cs = $V['contacts'];
  $total = count($cs); $sum = array_sum(array_map(fn($c) => (float)$c['value'], $cs));
  $by = []; foreach ($V['stages'] as $s) $by[$s] = [0, 0.0];
  foreach ($cs as $c) { $s = $c['stage']; if (!isset($by[$s])) $by[$s] = [0, 0.0]; $by[$s][0]++; $by[$s][1] += (float)$c['value']; }
  $rem = array_filter($cs, fn($c) => in_array(reminder_state($c), ['hoy', 'vencido'], true));
  uasort($rem, fn($a, $b) => strcmp($a['reminder'], $b['reminder']));
  $won = $by['Cliente'][0] ?? 0;
  $vq = $V['fv'] ? '&v=' . $V['fv'] : '';
  $o = '<header class="top"><h1>Hola, ' . h($_SESSION['user']) . '</h1><div class="actions">'
    . ($V['multi'] ? '<form method="get" action="/mm/" class="inline">' . vend_select($V, 'v', $V['fv']) . '</form>' : '')
    . '<a class="btn primary" href="/mm/lead?new=1">' . icon('plus') . 'Nuevo lead</a></div></header>';
  $o .= '<section class="kpis"><div class="kpi"><span>Total de leads</span><b>' . $total . '</b></div><div class="kpi"><span>Valor total</span><b>' . money($sum) . '</b></div>'
    . '<div class="kpi"><span>Clientes</span><b>' . $won . '</b></div><div class="kpi ' . ($rem ? 'warn' : '') . '"><span>Recordatorios hoy / vencidos</span><b>' . count($rem) . '</b></div></section>';
  if ($V['multi'] && !$V['fv']) {
    $pv = []; foreach (all_users() as $u) $pv[$u['id']] = [0, 0.0];
    foreach ($cs as $c) { $pv[$c['_u']][0]++; $pv[$c['_u']][1] += (float)$c['value']; }
    $mx = max(1, ...array_map(fn($x) => $x[0], array_values($pv) ?: [[0]]));
    $o .= '<section class="card"><h2>' . icon('users') . 'Por vendedor</h2><div class="stages">';
    foreach ($pv as $uidv => [$n, $v]) $o .= '<a class="stage-row" href="/mm/leads?v=' . $uidv . '"><span class="dot s4"></span><span class="sn">' . h(uname($uidv)) . ' <span class="muted sm">' . h(role_name(all_users()[$uidv]['role'])) . '</span></span><span class="bar"><i style="width:' . round($n / $mx * 100) . '%"></i></span><b>' . $n . '</b><span class="muted">' . money($v) . '</span></a>';
    $o .= '</div></section>';
  }
  $o .= '<section class="card"><h2>Por etapa</h2><div class="stages">';
  $max = max(1, ...array_map(fn($x) => $x[0], array_values($by) ?: [[0]]));
  foreach ($by as $s => [$n, $v]) $o .= '<a class="stage-row" href="/mm/leads?stage=' . rawurlencode($s) . h($vq) . '"><span class="dot ' . stage_class($s, $V['stages']) . '"></span><span class="sn">' . h($s) . '</span><span class="bar"><i style="width:' . round($n / $max * 100) . '%"></i></span><b>' . $n . '</b><span class="muted">' . money($v) . '</span></a>';
  $o .= '</div></section><div class="grid2"><section class="card"><h2>' . icon('clock') . 'Recordatorios</h2>';
  if (!$rem) $o .= '<p class="muted">No tienes recordatorios para hoy ni vencidos. 🎉</p>';
  else {
    $o .= '<ul class="list">';
    foreach ($rem as $c) $o .= '<li><a href="' . h(lead_url($c)) . '"><b>' . h($c['name']) . ' ' . owner_badge($V, $c) . '</b><small>' . h($c['reminderNote'] ?: 'Seguimiento') . '</small></a><span class="badge ' . (reminder_state($c) === 'vencido' ? 'red' : 'amber') . '">' . (reminder_state($c) === 'vencido' ? 'Vencido' : 'Hoy') . ' · ' . freminder($c['reminder']) . '</span>'
      . '<form method="post">' . csrf_field() . '<input type="hidden" name="a" value="reminder_done"><input type="hidden" name="id" value="' . h($c['id']) . '"><input type="hidden" name="u" value="' . (int)$c['_u'] . '"><button class="btn sm" title="Marcar como hecho">✓</button></form></li>';
    $o .= '</ul>';
  }
  $o .= '</section><section class="card"><h2>' . icon('chat') . 'Conversaciones recientes</h2>';
  $in = implode(',', array_map('intval', $V['owners']));
  $rows = db()->query("SELECT m.user_id, m.contact_id, m.ts, m.dir, m.text, x.cnt FROM messages m JOIN (SELECT user_id, contact_id, MAX(ts) mt, COUNT(*) cnt FROM messages WHERE user_id IN ($in) GROUP BY user_id, contact_id ORDER BY mt DESC LIMIT 8) x ON x.user_id=m.user_id AND x.contact_id=m.contact_id AND x.mt=m.ts GROUP BY m.user_id, m.contact_id ORDER BY m.ts DESC")->fetchAll();
  if (!$rows) $o .= '<p class="muted">Aún no hay conversaciones guardadas. Se guardan al abrir chats en WhatsApp Web con la extensión conectada.</p>';
  else {
    $o .= '<ul class="list">';
    foreach ($rows as $r) { $ou = (int)$r['user_id']; $c = $cs[$ou . '|' . $r['contact_id']] ?? ['_u' => $ou, 'id' => $r['contact_id']];
      $o .= '<li><a href="' . h(lead_url($c, $r['contact_id'])) . '"><b>' . h($c['name'] ?? $r['contact_id']) . ' ' . owner_badge($V, $c) . '</b><small>' . ($r['dir'] === 'out' ? 'Tú: ' : '') . h(mb_strimwidth($r['text'], 0, 80, '…')) . '</small></a><span class="muted sm">' . fdate($r['ts'], 'd/m H:i') . ' · ' . (int)$r['cnt'] . ' msj</span></li>'; }
    $o .= '</ul>';
  }
  $o .= '</section></div>';
  layout('Inicio', 'dashboard', $o);
}

// ---------- Leads ----------
function has_label($V, $c, $fl) {
  if (!$V['multi']) return in_array($fl, $c['labels'], true);
  $lm = $V['lm'][(int)$c['_u']] ?? [];
  foreach ($c['labels'] as $id) if (($lm[$id]['name'] ?? null) === $fl) return true;
  return false;
}
function filter_leads($V) {
  $q = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $fs = (string)($_GET['stage'] ?? ''); $fl = (string)($_GET['label'] ?? '');
  $list = array_filter($V['contacts'], function ($c) use ($q, $fs, $fl, $V) {
    if ($fs !== '' && $c['stage'] !== $fs) return false;
    if ($fl !== '' && !has_label($V, $c, $fl)) return false;
    if ($q === '') return true;
    $hay = mb_strtolower($c['name'] . ' ' . $c['phone'] . ' ' . preg_replace('/\D/', '', $c['phone']) . ' ' . implode(' ', array_map(fn($n) => $n['text'] ?? '', $c['notes'])));
    return mb_strpos($hay, $q) !== false;
  });
  uasort($list, fn($a, $b) => ($b['updated'] ?? 0) <=> ($a['updated'] ?? 0));
  return $list;
}
function filter_bar($V, $action, $withStage = true) {
  $q = (string)($_GET['q'] ?? ''); $fs = (string)($_GET['stage'] ?? ''); $fl = (string)($_GET['label'] ?? '');
  $o = '<form class="filters" method="get" action="/mm/' . $action . '"><input type="search" name="q" placeholder="Buscar nombre, teléfono o nota…" value="' . h($q) . '">';
  if ($V['multi']) $o .= vend_select($V, 'v', $V['fv']);
  if ($withStage) { $o .= '<select name="stage" data-autosubmit><option value="">Todas las etapas</option>'; foreach ($V['stages'] as $s) $o .= '<option' . ($s === $fs ? ' selected' : '') . '>' . h($s) . '</option>'; $o .= '</select>'; }
  $o .= '<select name="label" data-autosubmit><option value="">Todas las etiquetas</option>';
  foreach ($V['labels'] as $l) $o .= '<option value="' . h($l['id']) . '"' . ($l['id'] === $fl ? ' selected' : '') . '>' . h($l['name']) . '</option>';
  return $o . '</select><button class="btn">Filtrar</button>' . (($q || $fs || $fl || $V['fv']) ? '<a class="btn ghost" href="/mm/' . $action . '">Limpiar</a>' : '') . '</form>';
}
function view_leads($V) {
  $list = filter_leads($V); $m = $V['multi'];
  $qs = http_build_query(array_filter(['q' => $_GET['q'] ?? '', 'v' => $V['fv'] ?: '', 'stage' => $_GET['stage'] ?? '', 'label' => $_GET['label'] ?? '']));
  $o = '<header class="top"><h1>Leads <span class="muted">(' . count($list) . ')</span></h1><div class="actions"><a class="btn" href="/mm/exportar' . ($qs ? '?' . h($qs) : '') . '">' . icon('down') . 'Exportar CSV</a><a class="btn primary" href="/mm/lead?new=1' . ($V['fv'] ? '&amp;u=' . $V['fv'] : '') . '">' . icon('plus') . 'Nuevo lead</a></div></header>';
  $o .= filter_bar($V, 'leads');
  $o .= '<div class="card flush"><table class="tbl"><thead><tr><th>Nombre</th>' . ($m ? '<th>Vendedor</th>' : '') . '<th>Teléfono</th><th>Etapa</th><th>Etiquetas</th><th class="r">Valor</th><th>Recordatorio</th><th>Última nota</th></tr></thead><tbody>';
  foreach ($list as $c) {
    $rs = reminder_state($c); $last = end($c['notes']); $url = h(lead_url($c));
    $o .= '<tr data-href="' . $url . '"><td data-l="Nombre"><a href="' . $url . '"><b>' . h($c['name']) . '</b></a></td>' . ($m ? '<td data-l="Vendedor">' . owner_badge($V, $c) . '</td>' : '') . '<td data-l="Teléfono">' . h($c['phone']) . '</td>'
      . '<td data-l="Etapa"><span class="pill ' . stage_class($c['stage'], $V['stages']) . '">' . h($c['stage']) . '</span></td><td data-l="Etiquetas">' . chips_for($V, $c) . '</td><td data-l="Valor" class="r">' . money($c['value']) . '</td>'
      . '<td data-l="Recordatorio">' . ($c['reminder'] ? '<span class="' . ($rs === 'vencido' ? 'txt-red' : ($rs === 'hoy' ? 'txt-amber' : '')) . '">' . freminder($c['reminder']) . ($c['reminderDone'] ? ' ✓' : '') . '</span>' : '') . '</td>'
      . '<td data-l="Nota" class="muted">' . h(mb_strimwidth($last['text'] ?? '', 0, 60, '…')) . '</td></tr>';
  }
  if (!$list) $o .= '<tr><td colspan="' . ($m ? 8 : 7) . '" class="empty">' . ($V['contacts'] ? 'Ningún lead coincide con el filtro.' : 'Todavía no hay leads. Crea uno o ábrelos desde WhatsApp Web.') . '</td></tr>';
  $o .= '</tbody></table></div>';
  layout('Leads', 'leads', $o);
}

// ---------- Detalle / edición ----------
function view_lead($O) {
  $D = load_data($O); $d = $D['data']; $lm = label_map($d); $adm = is_admin();
  $id = (string)($_GET['id'] ?? ''); $new = !empty($_GET['new']) || $id === '';
  $c = $id !== '' ? ($d['contacts'][$id] ?? null) : null;
  $msgs = [];
  if ($id !== '') {
    $st = db()->prepare('SELECT msg_id id, ts, dir, type, text FROM messages WHERE user_id=? AND contact_id=? ORDER BY ts DESC, msg_id DESC LIMIT ' . (int)CRM_MSG_CAP);
    $st->execute([$O, $id]); $msgs = array_reverse($st->fetchAll());
  }
  if ($id !== '' && !$c && !$msgs) { http_response_code(404); layout('Lead', 'leads', '<div class="card empty"><h2>Lead no encontrado</h2><p><a class="btn" href="/mm/leads">Volver a leads</a></p></div>'); return; }
  $orphan = $id !== '' && !$c;
  if (!$c) { $c = ['id' => '', 'name' => '', 'phone' => '', 'stage' => $d['stages'][0], 'labels' => [], 'notes' => [], 'value' => 0, 'reminder' => '', 'reminderNote' => '', 'reminderDone' => false, 'created' => 0, 'updated' => 0];
    if ($orphan) { $c['phone'] = preg_match('/^\d{6,}$/', $id) ? $id : ''; $c['name'] = str_starts_with($id, 'n:') ? substr($id, 2) : ''; } }
  $wa = wa_link($c['phone']);
  $title = $c['id'] !== '' ? $c['name'] : 'Nuevo lead';
  $creating = $c['id'] === '' && !$orphan;
  $uf = '<input type="hidden" name="u" value="' . (int)$O . '">';
  $o = '<header class="top"><div><a class="back" href="/mm/leads">← Leads</a><h1>' . h($title) . '</h1>' . ($c['id'] !== '' ? '<span class="muted sm">Creado ' . fdate($c['created']) . ' · Actualizado ' . fdate($c['updated']) . '</span>' : '')
    . ($adm && !$creating ? ' <span class="badge vend">Vendedor: ' . h(uname($O)) . '</span>' : '') . '</div>'
    . '<div class="actions">' . ($wa ? '<a class="btn wa" href="' . h($wa) . '" target="_blank" rel="noopener">' . icon('wa') . 'Abrir en WhatsApp</a>' : '') . '</div></header>';
  if ($orphan) $o .= '<div class="flash info">Esta conversación está guardada pero el contacto no está en el CRM. Completa los datos y guarda para crear el lead.</div>';
  $o .= '<div class="detail"><section class="card"><h2>Datos del lead</h2><form method="post" action="/mm/lead" class="form" id="leadform">' . csrf_field() . '<input type="hidden" name="a" value="lead_save"><input type="hidden" name="id" value="' . h($c['id'] !== '' ? $c['id'] : ($orphan ? $id : '')) . '">'
    . ($adm && $creating ? '<label for="f-owner">Vendedor (dueño del lead)</label>' . str_replace('<select name="u"', '<select id="f-owner" name="u"', vend_select(null, 'u', $O, false, '')) . '<p class="muted sm">El lead se guarda en el CRM de ese usuario y aparece en su extensión.</p>' : $uf)
    . '<div class="row2"><div><label for="f-name">Nombre</label><input id="f-name" name="name" value="' . h($c['name']) . '" maxlength="120"' . ($new ? ' autofocus' : '') . '></div>'
    . '<div><label for="f-phone">Teléfono (con código de país)</label><input id="f-phone" name="phone" inputmode="tel" placeholder="51987654321" value="' . h($c['phone']) . '"></div></div>'
    . '<div class="row2"><div><label for="f-stage">Etapa</label><select id="f-stage" name="stage">';
  foreach ($d['stages'] as $s) $o .= '<option' . ($s === $c['stage'] ? ' selected' : '') . '>' . h($s) . '</option>';
  $o .= '</select></div><div><label for="f-value">Valor del negocio (S/)</label><input id="f-value" name="value" type="number" min="0" step="0.01" value="' . h((float)$c['value']) . '"></div></div>';
  $o .= '<label>Etiquetas</label><div class="chips-pick">';
  foreach ($d['labels'] as $l) $o .= '<label class="chip pick" style="--c:' . col($l['color']) . '"><input type="checkbox" name="labels[]" value="' . h($l['id']) . '"' . (in_array($l['id'], $c['labels'], true) ? ' checked' : '') . '>' . h($l['name']) . '</label>';
  if (!$d['labels']) $o .= '<span class="muted">Crea etiquetas en Ajustes.</span>';
  $rs = reminder_state($c);
  $o .= '</div><fieldset><legend>Recordatorio de seguimiento ' . ($rs === 'vencido' ? '<span class="badge red">Vencido</span>' : ($rs === 'hoy' ? '<span class="badge amber">Hoy</span>' : '')) . '</legend><div class="row2"><div><label for="f-rem">Fecha y hora</label><input id="f-rem" type="datetime-local" name="reminder" value="' . h($c['reminder']) . '"></div>'
    . '<div><label for="f-remn">Motivo</label><input id="f-remn" name="reminderNote" value="' . h($c['reminderNote']) . '" placeholder="Opcional"></div></div>'
    . '<label class="check"><input type="checkbox" name="reminderDone" value="1"' . ($c['reminderDone'] ? ' checked' : '') . '> Hecho</label></fieldset>'
    . '<label for="f-note">Agregar nota</label><textarea id="f-note" name="note" rows="3" placeholder="Se guardará con la fecha y hora actual"></textarea>'
    . '<div class="actions"><button class="btn primary">' . ($c['id'] !== '' ? 'Guardar cambios' : 'Crear lead') . '</button></div></form>';
  if ($c['id'] !== '') {
    $o .= '<h3>Notas</h3>';
    if (!$c['notes']) $o .= '<p class="muted">Sin notas.</p>';
    else { $o .= '<ul class="notes">'; foreach (array_reverse($c['notes']) as $n) $o .= '<li><div><small>' . fdate($n['ts'] ?? 0) . '</small><p>' . nl2br(h($n['text'] ?? '')) . '</p></div><form method="post" data-confirm="¿Eliminar esta nota?">' . csrf_field() . '<input type="hidden" name="a" value="note_del"><input type="hidden" name="id" value="' . h($c['id']) . '">' . $uf . '<input type="hidden" name="nid" value="' . h($n['id'] ?? '') . '"><button class="x" title="Eliminar nota">×</button></form></li>'; $o .= '</ul>'; }
    $o .= '<form method="post" class="danger-zone" data-confirm="¿Eliminar este lead del CRM? Esta acción no se puede deshacer.">' . csrf_field() . '<input type="hidden" name="a" value="lead_delete"><input type="hidden" name="id" value="' . h($c['id']) . '">' . $uf
      . '<label class="check"><input type="checkbox" name="delconv" value="1" checked> Borrar también su conversación guardada</label><button class="btn danger">Eliminar lead</button></form>';
  }
  $o .= '</section><section class="card chat-card"><h2>' . icon('chat') . 'Conversación <span class="muted sm">(' . count($msgs) . ' mensajes' . (count($msgs) >= CRM_MSG_CAP ? ', últimos ' . CRM_MSG_CAP : '') . ')</span></h2><div class="chat" id="chat">';
  if (!$msgs) $o .= '<p class="muted center">No hay conversación guardada para este contacto.<br>Ábrelo en WhatsApp Web con la extensión conectada para capturarla.</p>';
  $day = '';
  foreach ($msgs as $m) {
    $dd = fdate($m['ts'], 'd/m/Y'); if ($dd !== $day) { $day = $dd; $o .= '<div class="day"><span>' . $dd . '</span></div>'; }
    $o .= '<div class="msg ' . ($m['dir'] === 'out' ? 'out' : 'in') . ($m['type'] !== 'text' ? ' media' : '') . '"><p>' . nl2br(h($m['text'])) . '</p><time>' . fdate($m['ts'], 'H:i') . '</time></div>';
  }
  $o .= '</div><p class="muted sm">Horas en hora de Lima (UTC-5).</p></section></div>';
  layout($title, 'leads', $o);
}

// ---------- Embudo ----------
function view_kanban($V) {
  $list = filter_leads($V);
  $o = '<header class="top"><h1>Embudo</h1><a class="btn primary" href="/mm/lead?new=1' . ($V['fv'] ? '&amp;u=' . $V['fv'] : '') . '">' . icon('plus') . 'Nuevo lead</a></header>' . filter_bar($V, 'embudo', false)
    . '<p class="muted sm hint">Arrastra las tarjetas entre etapas (en el celular usa el selector de cada tarjeta).</p><div class="kanban">';
  foreach ($V['stages'] as $s) {
    $cs = array_filter($list, fn($c) => $c['stage'] === $s);
    $o .= '<div class="col" data-stage="' . h($s) . '"><div class="colh"><span class="dot ' . stage_class($s, $V['stages']) . '"></span><b>' . h($s) . '</b><span class="cnt">' . count($cs) . '</span><span class="muted sm tot">' . money(array_sum(array_map(fn($c) => (float)$c['value'], $cs))) . '</span></div><div class="cards">';
    foreach ($cs as $c) {
      $rs = reminder_state($c); $ost = $V['D'][(int)$c['_u']]['stages'] ?? $V['stages'];
      $o .= '<div class="kcard" draggable="true" data-id="' . h($c['id']) . '" data-u="' . (int)$c['_u'] . '"><a href="' . h(lead_url($c)) . '"><b>' . h($c['name']) . '</b></a>' . ($V['multi'] ? '<div>' . owner_badge($V, $c) . '</div>' : '') . '<small>' . h($c['phone']) . '</small><div>' . chips_for($V, $c) . '</div>'
        . '<div class="kfoot">' . ((float)$c['value'] ? '<span class="val">' . money($c['value']) . '</span>' : '<span></span>') . ($rs && $rs !== 'futuro' ? '<span class="badge ' . ($rs === 'vencido' ? 'red' : 'amber') . '">⏰ ' . freminder($c['reminder']) . '</span>' : ($rs ? '<span class="muted sm">⏰ ' . freminder($c['reminder']) . '</span>' : '')) . '</div>'
        . '<select class="kmove" aria-label="Mover a etapa">';
      foreach ($ost as $s2) $o .= '<option' . ($s2 === $s ? ' selected' : '') . '>' . h($s2) . '</option>';
      $o .= '</select></div>';
    }
    $o .= '</div></div>';
  }
  $o .= '</div>';
  layout('Embudo', 'embudo', $o);
}

// ---------- Ajustes ----------
function hidden($a, $extra = []) { $o = csrf_field() . '<input type="hidden" name="a" value="' . h($a) . '">'; foreach ($extra as $k => $v) $o .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">'; return $o; }
function view_settings($D) {
  $d = $D['data']; $t = (string)($_GET['t'] ?? 'etapas');
  $tabs = ['etapas' => 'Etapas', 'etiquetas' => 'Etiquetas', 'respuestas' => 'Respuestas rápidas', 'cuenta' => 'Mi cuenta'];
  if (is_admin()) $tabs['usuarios'] = 'Usuarios';
  elseif ($t === 'usuarios') deny('Solo un administrador puede gestionar usuarios.');
  if (!isset($tabs[$t])) $t = 'etapas';
  $o = '<header class="top"><h1>Ajustes</h1></header><nav class="tabs">';
  foreach ($tabs as $k => $l) $o .= '<a href="/mm/ajustes?t=' . $k . '" class="' . ($k === $t ? 'on' : '') . '">' . $l . '</a>';
  $o .= '</nav><section class="card">';
  if ($t === 'etapas') {
    $cnt = []; foreach ($d['contacts'] as $c) $cnt[$c['stage']] = ($cnt[$c['stage']] ?? 0) + 1;
    $o .= '<p class="muted">El orden de las etapas es el orden de columnas del embudo. Los cambios se sincronizan con la extensión.</p><ul class="setlist">';
    foreach ($d['stages'] as $i => $s) {
      $o .= '<li><span class="dot ' . stage_class($s, $d['stages']) . '"></span><form method="post" class="inline grow">' . hidden('stage_rename', ['i' => $i]) . '<input name="name" value="' . h($s) . '" required maxlength="60"><button class="btn sm">Renombrar</button></form>'
        . '<span class="muted sm">' . ($cnt[$s] ?? 0) . ' leads</span>'
        . '<form method="post" class="inline">' . hidden('stage_move', ['i' => $i, 'dir' => 'up']) . '<button class="btn sm ghost"' . ($i === 0 ? ' disabled' : '') . ' title="Subir">↑</button></form>'
        . '<form method="post" class="inline">' . hidden('stage_move', ['i' => $i, 'dir' => 'down']) . '<button class="btn sm ghost"' . ($i === count($d['stages']) - 1 ? ' disabled' : '') . ' title="Bajar">↓</button></form>'
        . '<form method="post" class="inline" data-confirm="¿Eliminar la etapa «' . h($s) . '»? Sus leads pasarán a la primera etapa.">' . hidden('stage_del', ['i' => $i]) . '<button class="x" title="Eliminar">×</button></form></li>';
    }
    $o .= '</ul><form method="post" class="inline add">' . hidden('stage_add') . '<input name="name" placeholder="Nueva etapa" required maxlength="60"><button class="btn primary">' . icon('plus') . 'Agregar</button></form>';
  } elseif ($t === 'etiquetas') {
    $o .= '<ul class="setlist">';
    foreach ($d['labels'] as $l) $o .= '<li><form method="post" class="inline grow">' . hidden('label_save', ['id' => $l['id']]) . '<input type="color" name="color" value="' . col($l['color']) . '"><input name="name" value="' . h($l['name']) . '" required maxlength="40"><button class="btn sm">Guardar</button></form>'
      . '<span class="chip" style="--c:' . col($l['color']) . '">' . h($l['name']) . '</span><form method="post" class="inline" data-confirm="¿Eliminar la etiqueta «' . h($l['name']) . '»? Se quitará de todos los leads.">' . hidden('label_del', ['id' => $l['id']]) . '<button class="x" title="Eliminar">×</button></form></li>';
    if (!$d['labels']) $o .= '<li class="muted">Sin etiquetas.</li>';
    $o .= '</ul><form method="post" class="inline add">' . hidden('label_save', ['id' => '']) . '<input type="color" name="color" value="#5ab584"><input name="name" placeholder="Nueva etiqueta" required maxlength="40"><button class="btn primary">' . icon('plus') . 'Agregar</button></form>';
  } elseif ($t === 'respuestas') {
    $o .= '<p class="muted">Aparecen en la pestaña «Respuestas» del panel de WhatsApp Web.</p><ul class="setlist col">';
    foreach ($d['replies'] as $r) $o .= '<li><form method="post" class="form grow">' . hidden('reply_save', ['id' => $r['id']]) . '<input name="title" value="' . h($r['title']) . '" required maxlength="80"><textarea name="text" rows="2" required>' . h($r['text']) . '</textarea><div class="actions"><button class="btn sm">Guardar</button></div></form>'
      . '<form method="post" class="inline" data-confirm="¿Eliminar la respuesta «' . h($r['title']) . '»?">' . hidden('reply_del', ['id' => $r['id']]) . '<button class="x" title="Eliminar">×</button></form></li>';
    if (!$d['replies']) $o .= '<li class="muted">Sin respuestas rápidas.</li>';
    $o .= '</ul><h3>Nueva respuesta</h3><form method="post" class="form">' . hidden('reply_save', ['id' => '']) . '<input name="title" placeholder="Título" required maxlength="80"><textarea name="text" rows="3" placeholder="Texto del mensaje" required></textarea><div class="actions"><button class="btn primary">' . icon('plus') . 'Agregar</button></div></form>';
  } elseif ($t === 'cuenta') {
    $o .= '<div class="grid2 flat"><div><h3>Cambiar mi contraseña</h3><form method="post" class="form">' . hidden('password')
      . pass_input('current', 'Contraseña actual') . pass_input('new', 'Nueva contraseña (mín. 8 caracteres)', 'new-password') . pass_input('new2', 'Repite la nueva contraseña', 'new-password')
      . '<div class="actions"><button class="btn primary">Actualizar contraseña</button></div><p class="muted sm">Es la misma contraseña de la extensión. La extensión ya conectada seguirá funcionando; usa la nueva al volver a conectar.</p></form></div>'
      . '<div><h3>Mi usuario</h3><p><b>' . h($_SESSION['user'] ?? '') . '</b> <span class="badge ' . (is_admin() ? 'admin' : '') . '">' . h(role_name(role())) . '</span></p><p class="muted sm">' . (is_admin() ? 'Como administrador ves los leads de todos los usuarios y gestionas los usuarios en la pestaña «Usuarios».' : 'Como vendedor ves solo tus propios leads y conversaciones.') . '</p></div></div>';
  } else {
    require_admin();
    $us = all_users(); $admins = admin_count();
    $o .= '<h3>Usuarios</h3><div class="card flush"><table class="tbl users"><thead><tr><th>Usuario</th><th>Rol</th><th class="r">Leads</th><th>Cambiar rol</th><th>Restablecer contraseña</th></tr></thead><tbody>';
    foreach ($us as $u) {
      $n = count(load_data($u['id'])['data']['contacts']); $me = $u['id'] === uid(); $last = $u['role'] === 'admin' && $admins <= 1;
      $o .= '<tr><td data-l="Usuario"><b>' . h($u['username']) . '</b>' . ($me ? ' <span class="badge">tú</span>' : '') . '</td>'
        . '<td data-l="Rol"><span class="badge ' . ($u['role'] === 'admin' ? 'admin' : '') . '">' . h(role_name($u['role'])) . '</span></td><td data-l="Leads" class="r">' . $n . '</td>'
        . '<td data-l="Cambiar rol"><form method="post" class="inline"' . ($me ? ' data-confirm="¿Cambiar tu propio rol? Si dejas de ser administrador perderás acceso a esta sección."' : '') . '>' . hidden('user_role', ['id' => $u['id']])
        . '<select name="role"' . ($last ? ' disabled title="Debe quedar al menos un administrador"' : '') . '>';
      foreach (ROLES as $k => $l) $o .= '<option value="' . $k . '"' . ($k === $u['role'] ? ' selected' : '') . '>' . $l . '</option>';
      $o .= '</select><button class="btn sm"' . ($last ? ' disabled' : '') . '>Guardar</button></form></td>'
        . '<td data-l="Contraseña"><form method="post" class="inline pwreset" autocomplete="off" data-confirm="¿Restablecer la contraseña de «' . h($u['username']) . '»?">' . hidden('user_pass', ['id' => $u['id']])
        . '<div class="pw"><input type="password" id="pw_reset' . $u['id'] . '" name="pass" minlength="8" required autocomplete="new-password" placeholder="Nueva (mín. 8)" aria-label="Nueva contraseña de ' . h($u['username']) . '"><button type="button" class="eye" data-eye="pw_reset' . $u['id'] . '" aria-label="Mostrar contraseña" title="Mostrar contraseña">' . icon('eye') . '</button></div>'
        . '<button class="btn sm">Cambiar</button></form></td></tr>';
    }
    $o .= '</tbody></table></div><p class="muted sm">Debe quedar al menos un administrador. Al restablecer una contraseña, la extensión ya conectada de ese usuario sigue funcionando; la nueva se usa al volver a conectar.</p>';
    $o .= '<h3>Crear usuario</h3><form method="post" class="form narrow" autocomplete="off">' . hidden('user_add') . '<label for="nu">Usuario</label><input id="nu" name="username" required pattern="[a-zA-Z0-9._\-]{3,40}" autocomplete="off">'
      . pass_input('pass', 'Contraseña (mín. 8 caracteres)', 'new-password')
      . '<label for="nr">Rol</label><select id="nr" name="role"><option value="vendedor" selected>Vendedor (solo ve sus leads)</option><option value="admin">Administrador (ve todos los leads y gestiona usuarios)</option></select>'
      . '<div class="actions"><button class="btn primary">Crear usuario</button></div><p class="muted sm">Cada usuario tiene su propio CRM (leads y conversaciones) y se conecta desde la extensión con su usuario. Los administradores ven los leads de todos.</p></form>';
  }
  $o .= '</section>';
  layout('Ajustes', 'ajustes', $o);
}

// ---------- Exportar CSV ----------
function export_csv($V) {
  $list = filter_leads($V); $m = $V['multi'];
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="crm-leads-' . date('Y-m-d') . '.csv"');
  $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
  $cell = fn($v) => (is_string($v) && preg_match('/^(?:[=@\t\r]|[+\-](?![\d\s]*$))/', $v)) ? "'" . $v : $v; // evita inyección de fórmulas
  fputcsv($f, array_merge(['id', 'name', 'phone', 'stage', 'labels', 'value', 'reminder', 'reminderNote', 'notes', 'created', 'updated'], $m ? ['vendedor'] : []));
  foreach ($list as $c) {
    $lm = $V['lm'][(int)$c['_u']] ?? [];
    $labels = implode('|', array_filter(array_map(fn($id) => $lm[$id]['name'] ?? '', $c['labels'])));
    $notes = implode(' || ', array_map(fn($n) => '[' . gmdate('Y-m-d\TH:i:s.v\Z', (int)(($n['ts'] ?? 0) / 1000)) . '] ' . ($n['text'] ?? ''), $c['notes']));
    $iso = fn($ms) => $ms ? gmdate('Y-m-d\TH:i:s.v\Z', (int)($ms / 1000)) : '';
    fputcsv($f, array_map($cell, array_merge([$c['id'], $c['name'], $c['phone'], $c['stage'], $labels, $c['value'], $c['reminder'], $c['reminderNote'], $notes, $iso($c['created']), $iso($c['updated'])], $m ? [uname((int)$c['_u'])] : [])));
  }
  fclose($f);
}
