<?php
// Acciones POST (ya autenticado y con CSRF verificado). $U = id de usuario.
$a = (string)($_POST['a'] ?? '');
$json = !empty($_SERVER['HTTP_X_CSRF_TOKEN']);
$back = function ($p, $q = '') { redirect($p, $q); };
$str = fn($k, $max = 500) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
// Dueño del lead: el propio usuario, u otro solo si es admin (owner_param da 403 si no)
$O = in_array($a, ['lead_save', 'note_del', 'reminder_done', 'lead_delete', 'move'], true) ? owner_param() : $U;
if (in_array($a, ['user_add', 'user_role', 'user_pass'], true)) require_admin();
$uq = fn($id) => 'id=' . rawurlencode($id) . ($O !== $U || is_admin() ? '&u=' . $O : '');

switch ($a) {
  // ---------- Leads ----------
  case 'lead_save': {
    $id = (string)($_POST['id'] ?? '');
    $name = $str('name', 120); $phone = preg_replace('/[^\d+]/', '', $str('phone', 40));
    if ($name === '' && $phone === '') { flash('Escribe al menos el nombre o el teléfono.', 'err'); $back('lead', $id !== '' ? $uq($id) : 'new=1' . ($O !== $U ? '&u=' . $O : '')); }
    $res = mutate($O, function (&$d) use ($id, $name, $phone, $str) {
      $isNew = $id === '' || !isset($d['contacts'][$id]);
      if ($isNew) {
        if ($id === '') $id = $phone !== '' ? preg_replace('/\D/', '', $phone) : 'n:' . $name;
        if (isset($d['contacts'][$id])) return ['dup' => $id];
        $d['contacts'][$id] = ['id' => $id, 'name' => $name ?: $id, 'phone' => $phone, 'stage' => $d['stages'][0], 'labels' => [], 'notes' => [], 'value' => 0,
          'reminder' => '', 'reminderNote' => '', 'reminderDone' => false, 'created' => now_ms(), 'updated' => now_ms()];
      }
      $c = &$d['contacts'][$id];
      $before = ['phone' => (string)($c['phone'] ?? ''), 'value' => (float)($c['value'] ?? 0), 'labels' => (array)($c['labels'] ?? [])];
      $c['name'] = $name ?: ($c['name'] ?? $id);
      $c['phone'] = $phone;
      $stage = $str('stage', 80); $c['stage'] = in_array($stage, $d['stages'], true) ? $stage : $d['stages'][0];
      $valid = array_column($d['labels'], 'id');
      $c['labels'] = array_values(array_intersect(array_map('strval', (array)($_POST['labels'] ?? [])), $valid));
      $c['value'] = max(0, round((float)str_replace(',', '.', (string)($_POST['value'] ?? 0)), 2));
      $rem = $str('reminder', 20);
      if ($rem !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $rem)) $rem = '';
      $changed = $rem !== ($c['reminder'] ?? '');
      $c['reminder'] = $rem;
      $c['reminderNote'] = $str('reminderNote', 300);
      // Igual que la extensión: si cambia la fecha, el recordatorio vuelve a quedar pendiente
      $c['reminderDone'] = $rem !== '' && !$changed && !empty($_POST['reminderDone']);
      // Marcas para la extensión: lo editado a mano no se autocompleta; etiquetas quitadas no se re-agregan solas
      if (!$isNew) {
        $man = (isset($c['manual']) && is_array($c['manual'])) ? $c['manual'] : [];
        if ($c['phone'] !== $before['phone']) $man['phone'] = true;
        if ((float)$c['value'] !== $before['value']) $man['value'] = true;
        if ($man) $c['manual'] = $man;
        $removed = array_diff($before['labels'], $c['labels']); $added = array_diff($c['labels'], $before['labels']);
        if ($removed || $added || isset($c['labelsOff'])) {
          $off = array_values(array_unique(array_merge(array_diff((array)($c['labelsOff'] ?? []), $added), $removed)));
          $c['labelsOff'] = $off;
        }
      } elseif ($phone !== '' || (float)$c['value'] > 0) {
        $c['manual'] = array_filter(['phone' => $phone !== '', 'value' => (float)$c['value'] > 0]);
      }
      $note = $str('note', 4000);
      if ($note !== '') $c['notes'][] = ['id' => rid(), 'ts' => now_ms(), 'text' => $note];
      $c['updated'] = now_ms();
      return ['id' => $id, 'new' => $isNew];
    });
    if (isset($res['dup'])) { flash('Ya existe un lead con ese teléfono/nombre.', 'err'); $back('lead', $uq($res['dup'])); }
    flash($res['new'] ? 'Lead creado.' : 'Cambios guardados.');
    $back('lead', $uq($res['id']));
  }
  case 'note_del': {
    $id = (string)($_POST['id'] ?? ''); $nid = (string)($_POST['nid'] ?? '');
    mutate($O, function (&$d) use ($id, $nid) {
      if (!isset($d['contacts'][$id])) return false;
      $d['contacts'][$id]['notes'] = array_values(array_filter($d['contacts'][$id]['notes'], fn($n) => ($n['id'] ?? '') !== $nid));
      $d['contacts'][$id]['updated'] = now_ms();
    });
    flash('Nota eliminada.'); $back('lead', $uq($id));
  }
  case 'reminder_done': {
    $id = (string)($_POST['id'] ?? '');
    mutate($O, function (&$d) use ($id) { if (!isset($d['contacts'][$id])) return false; $d['contacts'][$id]['reminderDone'] = true; $d['contacts'][$id]['updated'] = now_ms(); });
    flash('Recordatorio marcado como hecho.'); $back((string)($_POST['back'] ?? '') === 'lead' ? 'lead' : '', (string)($_POST['back'] ?? '') === 'lead' ? $uq($id) : '');
  }
  case 'lead_delete': {
    $id = (string)($_POST['id'] ?? '');
    $ok = mutate($O, function (&$d) use ($id) { if (!isset($d['contacts'][$id])) return false; unset($d['contacts'][$id]); });
    if (!empty($_POST['delconv'])) db()->prepare('DELETE FROM messages WHERE user_id=? AND contact_id=?')->execute([$O, $id]);
    flash($ok ? 'Lead eliminado.' : 'El lead no existe.', $ok ? 'ok' : 'err'); $back('leads');
  }
  case 'move': {
    $id = (string)($_POST['id'] ?? ''); $stage = (string)($_POST['stage'] ?? '');
    $ok = mutate($O, function (&$d) use ($id, $stage) {
      if (!isset($d['contacts'][$id]) || !in_array($stage, $d['stages'], true)) return false;
      $d['contacts'][$id]['stage'] = $stage; $d['contacts'][$id]['updated'] = now_ms();
    });
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => (bool)$ok] + ($ok ? [] : ['error' => 'No se pudo mover: esa etapa no existe en el CRM del vendedor.']), JSON_UNESCAPED_UNICODE); exit; }
    $back('embudo');
  }

  // ---------- Etapas ----------
  case 'stage_add': {
    $n = $str('name', 60);
    if ($n !== '') mutate($U, function (&$d) use ($n) { if (in_array($n, $d['stages'], true)) return false; $d['stages'][] = $n; });
    flash('Etapa agregada.'); $back('ajustes', 't=etapas');
  }
  case 'stage_rename': {
    $i = (int)($_POST['i'] ?? -1); $n = $str('name', 60);
    $ok = mutate($U, function (&$d) use ($i, $n) {
      if ($n === '' || !isset($d['stages'][$i]) || in_array($n, $d['stages'], true)) return false;
      $old = $d['stages'][$i]; $d['stages'][$i] = $n;
      foreach ($d['contacts'] as &$c) if (($c['stage'] ?? '') === $old) $c['stage'] = $n;
    });
    flash($ok ? 'Etapa renombrada.' : 'No se pudo renombrar (nombre vacío o repetido).', $ok ? 'ok' : 'err'); $back('ajustes', 't=etapas');
  }
  case 'stage_move': {
    $i = (int)($_POST['i'] ?? -1); $dir = ($_POST['dir'] ?? '') === 'down' ? 1 : -1;
    mutate($U, function (&$d) use ($i, $dir) {
      $j = $i + $dir; if (!isset($d['stages'][$i], $d['stages'][$j])) return false;
      [$d['stages'][$i], $d['stages'][$j]] = [$d['stages'][$j], $d['stages'][$i]];
    });
    $back('ajustes', 't=etapas');
  }
  case 'stage_del': {
    $i = (int)($_POST['i'] ?? -1);
    $ok = mutate($U, function (&$d) use ($i) {
      if (!isset($d['stages'][$i]) || count($d['stages']) <= 1) return false;
      $s = $d['stages'][$i]; array_splice($d['stages'], $i, 1);
      foreach ($d['contacts'] as &$c) if (($c['stage'] ?? '') === $s) $c['stage'] = $d['stages'][0];
    });
    flash($ok ? 'Etapa eliminada; sus leads pasaron a la primera etapa.' : 'Debe quedar al menos una etapa.', $ok ? 'ok' : 'err'); $back('ajustes', 't=etapas');
  }

  // ---------- Etiquetas ----------
  case 'label_save': {
    $id = (string)($_POST['id'] ?? ''); $n = $str('name', 40);
    $col = preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#5ab584';
    if ($n !== '') mutate($U, function (&$d) use ($id, $n, $col) {
      foreach ($d['labels'] as &$l) if (($l['id'] ?? '') === $id) { $l['name'] = $n; $l['color'] = $col; return; }
      $d['labels'][] = ['id' => rid(), 'name' => $n, 'color' => $col];
    });
    flash('Etiqueta guardada.'); $back('ajustes', 't=etiquetas');
  }
  case 'label_del': {
    $id = (string)($_POST['id'] ?? '');
    mutate($U, function (&$d) use ($id) {
      $d['labels'] = array_values(array_filter($d['labels'], fn($l) => ($l['id'] ?? '') !== $id));
      foreach ($d['contacts'] as &$c) $c['labels'] = array_values(array_filter($c['labels'], fn($x) => $x !== $id));
    });
    flash('Etiqueta eliminada.'); $back('ajustes', 't=etiquetas');
  }

  // ---------- Respuestas rápidas ----------
  case 'reply_save': {
    $id = (string)($_POST['id'] ?? ''); $t = $str('title', 80); $x = $str('text', 4000);
    if ($t !== '' && $x !== '') mutate($U, function (&$d) use ($id, $t, $x) {
      foreach ($d['replies'] as &$r) if (($r['id'] ?? '') === $id) { $r['title'] = $t; $r['text'] = $x; return; }
      $d['replies'][] = ['id' => rid(), 'title' => $t, 'text' => $x];
    });
    flash('Respuesta guardada.'); $back('ajustes', 't=respuestas');
  }
  case 'reply_del': {
    $id = (string)($_POST['id'] ?? '');
    mutate($U, function (&$d) use ($id) { $d['replies'] = array_values(array_filter($d['replies'], fn($r) => ($r['id'] ?? '') !== $id)); });
    flash('Respuesta eliminada.'); $back('ajustes', 't=respuestas');
  }

  // ---------- Cuenta ----------
  case 'password': {
    $cur = (string)($_POST['current'] ?? ''); $n = (string)($_POST['new'] ?? ''); $n2 = (string)($_POST['new2'] ?? '');
    $st = db()->prepare('SELECT pass_hash FROM users WHERE id=?'); $st->execute([$U]); $hash = $st->fetch()['pass_hash'] ?? '';
    if (!password_verify($cur, $hash)) flash('La contraseña actual no es correcta.', 'err');
    elseif (mb_strlen($n) < 8) flash('La nueva contraseña debe tener al menos 8 caracteres.', 'err');
    elseif ($n !== $n2) flash('Las contraseñas nuevas no coinciden.', 'err');
    else { db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($n, PASSWORD_DEFAULT), $U]); flash('Contraseña actualizada. Úsala también al reconectar la extensión.'); }
    $back('ajustes', 't=cuenta');
  }
  case 'user_add': {
    $u = strtolower($str('username', 40)); $n = (string)($_POST['pass'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'vendedor';
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) flash('Usuario inválido (3-40 caracteres: letras, números, . _ -).', 'err');
    elseif (mb_strlen($n) < 8) flash('La contraseña debe tener al menos 8 caracteres.', 'err');
    else {
      $st = db()->prepare('SELECT 1 FROM users WHERE username=? COLLATE NOCASE'); $st->execute([$u]);
      if ($st->fetch()) flash('Ese usuario ya existe.', 'err');
      else { db()->prepare('INSERT INTO users(username, pass_hash, role) VALUES(?,?,?)')->execute([$u, password_hash($n, PASSWORD_DEFAULT), $role]); flash("Usuario «{$u}» creado como " . role_name($role) . '.'); }
    }
    $back('ajustes', 't=usuarios');
  }
  case 'user_role': {
    $id = (int)($_POST['id'] ?? 0); $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'vendedor';
    $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
    try {
      $st = $pdo->prepare('SELECT username, role FROM users WHERE id=?'); $st->execute([$id]); $t = $st->fetch();
      if (!$t) { $pdo->exec('ROLLBACK'); flash('Usuario no encontrado.', 'err'); $back('ajustes', 't=usuarios'); }
      if ($t['role'] === 'admin' && $role !== 'admin' && (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role='admin'")->fetch()['c'] <= 1) {
        $pdo->exec('ROLLBACK'); flash('Debe quedar al menos un administrador.', 'err'); $back('ajustes', 't=usuarios');
      }
      $pdo->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role, $id]); $pdo->exec('COMMIT');
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->exec('ROLLBACK'); throw $e; }
    flash('Rol de «' . $t['username'] . '» cambiado a ' . role_name($role) . '.');
    if ($id === $U && $role !== 'admin') { $_SESSION['role'] = $role; $back(''); }
    $back('ajustes', 't=usuarios');
  }
  case 'user_pass': {
    $id = (int)($_POST['id'] ?? 0); $n = (string)($_POST['pass'] ?? '');
    $st = db()->prepare('SELECT username FROM users WHERE id=?'); $st->execute([$id]); $t = $st->fetch();
    if (!$t) flash('Usuario no encontrado.', 'err');
    elseif (mb_strlen($n) < 8) flash('La contraseña debe tener al menos 8 caracteres.', 'err');
    else { db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($n, PASSWORD_DEFAULT), $id]); flash('Contraseña de «' . $t['username'] . '» actualizada.'); }
    $back('ajustes', 't=usuarios');
  }
}
http_response_code(400);
echo 'Acción no válida';
