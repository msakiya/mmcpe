// CRM Xigma - service worker: recordatorios + sync + conversaciones
const PREFIX = 'crmw-rem-';
const MSG_CAP = 500;

async function syncAlarms() {
  const { crmw_contacts = {} } = await chrome.storage.local.get('crmw_contacts');
  const existing = await chrome.alarms.getAll();
  for (const a of existing) if (a.name.startsWith(PREFIX)) await chrome.alarms.clear(a.name);
  const now = Date.now();
  for (const [id, c] of Object.entries(crmw_contacts)) {
    if (c.reminder && !c.reminderDone) {
      const when = new Date(c.reminder).getTime();
      if (!isNaN(when)) chrome.alarms.create(PREFIX + id, { when: Math.max(when, now + 1000) });
    }
  }
}

chrome.runtime.onInstalled.addListener(syncAlarms);
chrome.runtime.onStartup.addListener(syncAlarms);
chrome.storage.onChanged.addListener((ch, area) => { if (area === 'local' && ch.crmw_contacts) syncAlarms(); });

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (!alarm.name.startsWith(PREFIX)) return;
  const id = alarm.name.slice(PREFIX.length);
  const { crmw_contacts = {} } = await chrome.storage.local.get('crmw_contacts');
  const c = crmw_contacts[id];
  if (!c) return;
  chrome.notifications.create(alarm.name, {
    type: 'basic', iconUrl: 'icons/icon128.png',
    title: 'Recordatorio de seguimiento',
    message: `Contactar a ${c.name || id}${c.reminderNote ? ': ' + c.reminderNote : ''}`,
    priority: 2
  });
});

chrome.notifications.onClicked.addListener(async () => {
  const tabs = await chrome.tabs.query({ url: 'https://web.whatsapp.com/*' });
  if (tabs[0]) { chrome.tabs.update(tabs[0].id, { active: true }); chrome.windows.update(tabs[0].windowId, { focused: true }); }
  else chrome.tabs.create({ url: 'https://web.whatsapp.com/' });
});

chrome.action.onClicked.addListener(async (tab) => {
  if (tab.url && tab.url.startsWith('https://web.whatsapp.com')) chrome.tabs.sendMessage(tab.id, { type: 'toggle' }).catch(() => {});
  else chrome.tabs.create({ url: 'https://web.whatsapp.com/' });
});

// ---------- Sincronización CRM (contactos/etiquetas/etapas/respuestas) ----------
const DATA_KEYS = ['contacts', 'labels', 'stages', 'replies', 'settings'];
let pushTimer = null, busy = Promise.resolve();
const getSync = async () => (await chrome.storage.local.get('crmw_sync')).crmw_sync || {};
const setSync = async (patch) => chrome.storage.local.set({ crmw_sync: { ...(await getSync()), ...patch } });

async function api(cfg, path, opts = {}) {
  const r = await fetch(cfg.url + '/api/' + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', ...(cfg.token ? { Authorization: 'Bearer ' + cfg.token } : {}) }
  });
  let j = {}; try { j = await r.json(); } catch (_) {}
  if (r.status === 401 && cfg.token) await setSync({ token: '', lastError: 'Sesión expirada, vuelve a conectar' });
  return { status: r.status, body: j };
}
// v1.5: solo se envían al servidor los contactos marcados con "Guardar como lead" (lead === true).
const onlyLeads = (contacts) => {
  const out = {};
  for (const [k, c] of Object.entries(contacts || {})) if (c && typeof c === 'object' && c.lead === true) out[k] = c;
  return out;
};
async function isLeadId(contactId) {
  const { crmw_contacts = {} } = await chrome.storage.local.get('crmw_contacts');
  return !!(contactId && crmw_contacts[contactId] && crmw_contacts[contactId].lead === true);
}
async function localPayload() {
  const d = await chrome.storage.local.get(DATA_KEYS.map((k) => 'crmw_' + k).concat('crmw_meta'));
  const data = {}; DATA_KEYS.forEach((k) => { if (d['crmw_' + k] !== undefined) data[k] = d['crmw_' + k]; });
  if (data.contacts) data.contacts = onlyLeads(data.contacts);
  return { updatedAt: d.crmw_meta?.updatedAt || 0, data };
}
async function applyServer(s) {
  const o = { crmw_meta: { updatedAt: s.updatedAt } };
  DATA_KEYS.forEach((k) => { if (s.data && s.data[k] !== undefined) o['crmw_' + k] = s.data[k]; });
  // Lo que viene del servidor ya está en el CRM: se marca como lead.
  if (o.crmw_contacts && typeof o.crmw_contacts === 'object') {
    const c = {}; for (const [k, v] of Object.entries(o.crmw_contacts)) if (v && typeof v === 'object') c[k] = { ...v, lead: true };
    o.crmw_contacts = c;
  }
  await chrome.storage.local.set(o);
}
async function push(cfg, local) {
  if (!local.updatedAt) local = { ...local, updatedAt: Date.now() };
  const r = await api(cfg, 'sync', { method: 'PUT', body: JSON.stringify(local) });
  if (r.status === 409 && r.body.data) { await applyServer(r.body); return; }
  if (r.status !== 200) throw new Error(r.body.error || 'HTTP ' + r.status);
  if (!(await localPayload()).updatedAt) await chrome.storage.local.set({ crmw_meta: { updatedAt: local.updatedAt } });
}
function run(fn) {
  const p = busy.then(fn); busy = p.catch(() => {});
  return p;
}
async function pullOrPush() {
  const cfg = await getSync(); if (!cfg.url || !cfg.token) return { ok: false, error: 'No conectado' };
  try {
    const r = await api(cfg, 'sync'); if (r.status !== 200) throw new Error(r.body.error || 'HTTP ' + r.status);
    const local = await localPayload();
    if (r.body.data && r.body.updatedAt > local.updatedAt) await applyServer(r.body);
    else if (local.updatedAt > (r.body.updatedAt || 0) || !r.body.data) await push(cfg, local);
    await setSync({ lastSync: Date.now(), lastError: '' });
    return { ok: true };
  } catch (e) { await setSync({ lastError: String(e.message || e) }); return { ok: false, error: String(e.message || e) }; }
}
async function pushNow() {
  const cfg = await getSync(); if (!cfg.url || !cfg.token) return;
  try { await push(cfg, await localPayload()); await setSync({ lastSync: Date.now(), lastError: '' }); }
  catch (e) { await setSync({ lastError: String(e.message || e) }); }
}
async function login(m) {
  try {
    const r = await api({ url: m.url }, 'auth/login', { method: 'POST', body: JSON.stringify({ user: m.user, pass: m.pass }) });
    if (r.status !== 200 || !r.body.token) return { ok: false, error: r.body.error || 'HTTP ' + r.status };
    await setSync({ url: m.url, user: m.user, token: r.body.token, lastError: '' });
    const res = await run(pullOrPush);
    pushAllConversations().catch(() => {}); // sube las conversaciones guardadas solo en este navegador
    return res;
  } catch (e) { return { ok: false, error: 'No se pudo conectar: ' + (e.message || e) }; }
}

// ---------- Conversaciones ----------
function mergeMsgs(a = [], b = []) {
  const map = new Map();
  for (const m of [...a, ...b]) {
    if (!m || !m.id) continue;
    const prev = map.get(m.id);
    if (!prev || (m.ts || 0) >= (prev.ts || 0)) map.set(m.id, m);
  }
  return [...map.values()].sort((x, y) => x.ts - y.ts || String(x.id).localeCompare(String(y.id))).slice(-MSG_CAP);
}

async function getLocalConvs() {
  return (await chrome.storage.local.get('crmw_conversations')).crmw_conversations || {};
}
async function setLocalConv(contactId, messages) {
  const all = await getLocalConvs();
  all[contactId] = mergeMsgs(messages).slice(-MSG_CAP);
  await chrome.storage.local.set({ crmw_conversations: all });
  return all[contactId];
}

async function convPut(contactId, messages) {
  if (!(await isLeadId(contactId))) return { ok: true, offline: true, notLead: true, messages: [] }; // nunca guardar/enviar chats no guardados como lead
  const cfg = await getSync();
  const local = mergeMsgs((await getLocalConvs())[contactId] || [], messages || []);
  await setLocalConv(contactId, local);
  if (!cfg.url || !cfg.token) return { ok: true, offline: true, messages: local };
  try {
    const path = 'conversations?contactId=' + encodeURIComponent(contactId);
    const r = await api(cfg, path, { method: 'PUT', body: JSON.stringify({ messages: local }) });
    if (r.status !== 200) throw new Error(r.body.error || 'HTTP ' + r.status);
    const merged = mergeMsgs(local, r.body.messages || []);
    await setLocalConv(contactId, merged);
    return { ok: true, messages: merged, upserted: r.body.upserted };
  } catch (e) {
    return { ok: false, error: String(e.message || e), messages: local };
  }
}

async function pushAllConversations() {
  const cfg = await getSync(); if (!cfg.url || !cfg.token) return;
  const { crmw_contacts = {} } = await chrome.storage.local.get('crmw_contacts');
  const all = await getLocalConvs();
  for (const [cid, msgs] of Object.entries(all)) {
    if (!crmw_contacts[cid] || crmw_contacts[cid].lead !== true || !msgs?.length) continue;
    await convPut(cid, []);
  }
}

async function convGet(contactId) {
  const cfg = await getSync();
  const local = (await getLocalConvs())[contactId] || [];
  if (!cfg.url || !cfg.token || !(await isLeadId(contactId))) return { ok: true, offline: true, messages: local };
  try {
    const path = 'conversations?contactId=' + encodeURIComponent(contactId);
    const r = await api(cfg, path);
    if (r.status !== 200) throw new Error(r.body.error || 'HTTP ' + r.status);
    const merged = mergeMsgs(local, r.body.messages || []);
    await setLocalConv(contactId, merged);
    return { ok: true, messages: merged };
  } catch (e) {
    return { ok: false, error: String(e.message || e), messages: local };
  }
}

chrome.runtime.onMessage.addListener((m, _s, reply) => {
  if (!m?.type) return;
  if (m.type.startsWith('sync-')) {
    if (m.type === 'sync-dirty') { clearTimeout(pushTimer); pushTimer = setTimeout(() => run(pushNow), 2000); reply({ ok: true }); return; }
    if (m.type === 'sync-pull') { run(pullOrPush).then(reply); return true; }
    if (m.type === 'sync-login') { login(m).then(reply); return true; }
    if (m.type === 'sync-logout') {
      getSync().then(async (cfg) => { if (cfg.token) api(cfg, 'auth/logout', { method: 'POST' }).catch(() => {}); await setSync({ token: '' }); reply({ ok: true }); });
      return true;
    }
  }
  if (m.type === 'conv-put') { convPut(m.contactId, m.messages || []).then(reply); return true; }
  if (m.type === 'conv-get') { convGet(m.contactId).then(reply); return true; }
  if (m.type === 'conv-local') {
    getLocalConvs().then((all) => reply({ ok: true, messages: all[m.contactId] || [] }));
    return true;
  }
});
chrome.alarms.create('crmw-sync', { periodInMinutes: 5 });
chrome.alarms.onAlarm.addListener((a) => { if (a.name === 'crmw-sync') run(pullOrPush); });
