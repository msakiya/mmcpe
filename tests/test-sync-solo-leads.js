const vm = require('vm'), fs = require('fs'), assert = require('assert');
const store = {
  crmw_sync: { url: 'https://srv', token: 'T' },
  crmw_meta: { updatedAt: 5000 },
  crmw_contacts: {
    '51911111111': { id: '51911111111', name: 'Lead A', lead: true, labels: [], notes: [] },
    '51922222222': { id: '51922222222', name: 'No lead', labels: [], notes: [] },
    '51933333333': { id: '51933333333', name: 'Quitado', lead: false, labels: [], notes: [] }
  },
  crmw_conversations: { '51911111111': [{ id: 'a', ts: 1, dir: 'in', text: 'x' }], '51922222222': [{ id: 'b', ts: 1, dir: 'in', text: 'y' }] }
};
const calls = []; let listener;
const ev = { addListener() {} };
const chrome = {
  storage: { local: {
    get: async (k) => { const ks = Array.isArray(k) ? k : [k]; const o = {}; ks.forEach((x) => { if (store[x] !== undefined) o[x] = JSON.parse(JSON.stringify(store[x])); }); return o; },
    set: async (o) => Object.assign(store, JSON.parse(JSON.stringify(o))) }, onChanged: ev },
  runtime: { onInstalled: ev, onStartup: ev, onMessage: { addListener: (f) => (listener = f) } },
  alarms: { getAll: async () => [], clear: async () => {}, create() {}, onAlarm: ev },
  notifications: { create() {}, onClicked: ev }, action: { onClicked: ev }, tabs: {}
};
const fetch = async (url, opts = {}) => {
  calls.push({ url, method: opts.method || 'GET', body: opts.body ? JSON.parse(opts.body) : null });
  if (url.endsWith('/api/sync') && (opts.method || 'GET') === 'GET') return { status: 200, json: async () => ({ updatedAt: 0, data: null }) };
  return { status: 200, json: async () => ({ ok: true, messages: [] }) };
};
const ctx = vm.createContext({ chrome, fetch, console, setTimeout, clearTimeout, Date, Promise, Map });
vm.runInContext(fs.readFileSync(process.argv[2] || __dirname + '/../extension/crm-xigma-cpe/background.js', 'utf8'), ctx);
const send = (m) => new Promise((res) => { const r = listener(m, null, res); if (r !== true) {} });
(async () => {
  await send({ type: 'sync-pull' });
  const put = calls.find((c) => c.url.endsWith('/api/sync') && c.method === 'PUT');
  assert(put, 'debe hacer PUT /api/sync');
  assert.deepStrictEqual(Object.keys(put.body.data.contacts), ['51911111111'], 'solo leads en sync');
  console.log('OK sync: solo envía', Object.keys(put.body.data.contacts));
  calls.length = 0;
  await send({ type: 'conv-put', contactId: '51922222222', messages: [{ id: 'z', ts: 2, dir: 'in', text: 'no' }] });
  await send({ type: 'conv-get', contactId: '51922222222' });
  await send({ type: 'conv-put', contactId: '51933333333', messages: [{ id: 'z', ts: 2, dir: 'in', text: 'no' }] });
  await send({ type: 'conv-put', contactId: '51999999999', messages: [{ id: 'z', ts: 2, dir: 'in', text: 'no' }] });
  assert.strictEqual(calls.length, 0, 'no lead => 0 peticiones: ' + JSON.stringify(calls));
  assert.deepStrictEqual(store.crmw_conversations['51922222222'].map((m) => m.id), ['b'], 'no guarda mensajes nuevos de no-leads');
  assert(!store.crmw_conversations['51999999999'], 'no crea conversación de desconocido');
  console.log('OK conversaciones: no-leads/quitados/desconocidos => 0 peticiones');
  await send({ type: 'conv-put', contactId: '51911111111', messages: [{ id: 'c', ts: 3, dir: 'out', text: 'hola' }] });
  assert(calls.some((c) => c.method === 'PUT' && c.url.includes('conversations?contactId=51911111111')), 'lead => sube conversación');
  console.log('OK lead: sube conversación');
  // applyServer marca lead
  calls.length = 0;
  ctx.fetch = fetch;
  vm.runInContext('applyServer({updatedAt: 9999, data: {contacts: {"5194": {id:"5194", name:"Del panel"}}}})', ctx);
  await new Promise((r) => setTimeout(r, 20));
  assert.strictEqual(store.crmw_contacts['5194'].lead, true, 'lo del servidor queda como lead');
  console.log('OK pull: contactos del servidor quedan marcados como lead');
})().catch((e) => { console.error('FAIL', e.message); process.exit(1); });
