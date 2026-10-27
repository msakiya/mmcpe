<?php
define('CRM_DB', __DIR__ . '/crm.sqlite');
function db() {
  static $p = null;
  if ($p) return $p;
  $p = new PDO('sqlite:' . CRM_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $p->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
  $p->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, pass_hash TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS tokens(hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, kind TEXT NOT NULL, label TEXT, created INTEGER NOT NULL, last_used INTEGER);
    CREATE TABLE IF NOT EXISTS data(user_id INTEGER PRIMARY KEY, payload TEXT NOT NULL, updated_at INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS attempts(ip TEXT, ts INTEGER);
    CREATE TABLE IF NOT EXISTS messages(
      user_id INTEGER NOT NULL,
      contact_id TEXT NOT NULL,
      msg_id TEXT NOT NULL,
      ts INTEGER NOT NULL,
      dir TEXT NOT NULL,
      type TEXT NOT NULL DEFAULT \'text\',
      text TEXT NOT NULL DEFAULT \'\',
      meta TEXT,
      PRIMARY KEY(user_id, contact_id, msg_id)
    );
    CREATE INDEX IF NOT EXISTS idx_messages_contact ON messages(user_id, contact_id, ts);');
  return $p;
}

/** Cap de mensajes por contacto (los más recientes). */
define('CRM_MSG_CAP', 500);
