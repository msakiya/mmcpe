// CRM Xigma - content script para WhatsApp Web
(() => {
  'use strict';
  if (window.__crmwLoaded) return;
  window.__crmwLoaded = true;

  const DEFAULT_STAGES = ['Nuevo', 'Contactado', 'Interesado', 'Negociación', 'Cliente', 'Perdido'];
  const DEFAULT_LABELS = [
    { id: 'l1', name: 'VIP', color: '#f5a623' },
    { id: 'l2', name: 'Pendiente pago', color: '#e74c3c' },
    { id: 'l3', name: 'Mayorista', color: '#3498db' }
  ];
  const DEFAULT_REPLIES = [
    { id: 'r1', title: 'Saludo', text: '¡Hola! Gracias por escribirnos. ¿En qué te podemos ayudar?' },
    { id: 'r2', title: 'Pago', text: 'Puedes pagar por Yape, Plin o transferencia. Te envío los datos.' }
  ];
  const MSG_CAP = 500;

  let state = { contacts: {}, labels: [], stages: [], replies: [], settings: {}, ui: { open: true } };
  let current = null; // { id, name, phone }
  let convCache = {}; // contactId -> messages[]
  let convStatus = ''; // UI hint
  let uploadTimer = null;
  let lastScrapedKey = '';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const uid = () => Math.random().toString(36).slice(2, 10);
  const fmtDate = (ts) => new Date(ts).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' });
  const fmtTime = (ts) => new Date(ts).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' });
  const money = (v) => 'S/ ' + Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // ---------- Storage ----------
  async function load() {
    const d = await chrome.storage.local.get(['crmw_contacts', 'crmw_labels', 'crmw_stages', 'crmw_replies', 'crmw_settings', 'crmw_ui', 'crmw_conversations']);
    state.contacts = d.crmw_contacts || {};
    state.labels = d.crmw_labels || DEFAULT_LABELS;
    state.stages = d.crmw_stages || DEFAULT_STAGES;
    state.replies = d.crmw_replies || DEFAULT_REPLIES;
    state.ui = d.crmw_ui || { open: true };
    state.settings = withDefaults(d.crmw_settings);
    convCache = d.crmw_conversations || {};
  }
  const save = (keys = ['contacts', 'labels', 'stages', 'replies', 'settings', 'ui']) => {
    const o = {}; keys.forEach((k) => (o['crmw_' + k] = state[k]));
    const dataChanged = keys.some((k) => k !== 'ui');
    if (dataChanged) o.crmw_meta = { updatedAt: Date.now() };
    return chrome.storage.local.set(o).then(() => { if (dataChanged) bg({ type: 'sync-dirty' }); });
  };
  const bg = (m) => chrome.runtime.sendMessage(m).catch((e) => ({ ok: false, error: String(e.message || e) }));
  let syncInfo = {};
  async function loadSyncInfo() { syncInfo = (await chrome.storage.local.get('crmw_sync')).crmw_sync || {}; }
  const synced = () => !!(syncInfo.url && syncInfo.token);
  chrome.storage.onChanged.addListener((ch, area) => {
    if (area !== 'local') return;
    let changed = false;
    for (const k of ['contacts', 'labels', 'stages', 'replies']) if (ch['crmw_' + k]) { state[k] = ch['crmw_' + k].newValue || state[k]; changed = true; }
    if (ch.crmw_settings) { state.settings = withDefaults(ch.crmw_settings.newValue); changed = true; }
    if (ch.crmw_conversations) convCache = ch.crmw_conversations.newValue || {};
    if (ch.crmw_sync) { syncInfo = ch.crmw_sync.newValue || {}; if (tab === 'ajustes' && !document.activeElement?.closest?.('#crmw-panel')) renderPanel(); }
    if (changed && !document.activeElement?.closest?.('#crmw-panel, #crmw-modal')) render();
  });

  function getContact(id) {
    if (!state.contacts[id]) state.contacts[id] = { id, name: current?.name || id, phone: current?.phone || '', stage: state.stages[0], labels: [], notes: [], value: 0, reminder: '', reminderNote: '', reminderDone: false, created: Date.now(), updated: Date.now() };
    return state.contacts[id];
  }
  function touch(c) { c.updated = Date.now(); save(['contacts']); }
  /** v1.5: solo los contactos marcados con "Guardar como lead" van al CRM (local y servidor). */
  const isLead = (id) => !!(id && state.contacts[id] && state.contacts[id].lead === true);
  const leadContacts = () => Object.values(state.contacts).filter((c) => c && c.lead === true);

  function mergeMsgs(a = [], b = []) {
    const map = new Map();
    for (const m of [...a, ...b]) {
      if (!m || !m.id) continue;
      const prev = map.get(m.id);
      if (!prev || (m.ts || 0) >= (prev.ts || 0)) map.set(m.id, m);
    }
    return [...map.values()].sort((x, y) => x.ts - y.ts || String(x.id).localeCompare(String(y.id))).slice(-MSG_CAP);
  }

  // ---------- Hash estable para ids de mensaje ----------
  function hashId(s) {
    let h = 2166136261;
    for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); }
    return (h >>> 0).toString(36);
  }

  // ---------- Captura de conversación (solo lectura, nunca envía) ----------
  function parsePrePlain(attr) {
    // Ej: [10:32, 15/10/2026] Nombre:  ó [10:32 am, 10/15/2026] ~
    if (!attr) return null;
    const m = attr.match(/^\[([^\]]+)\]/);
    if (!m) return null;
    const raw = m[1].trim();
    // Intentar Date.parse con varios formatos
    let ts = Date.parse(raw);
    if (!isNaN(ts)) return ts;
    // dd/mm/yyyy, hh:mm
    let m2 = raw.match(/(\d{1,2}):(\d{2})(?:\s*(am|pm))?\s*,\s*(\d{1,2})\/(\d{1,2})\/(\d{2,4})/i);
    if (m2) {
      let [, hh, mm, ap, d, mo, y] = m2;
      hh = +hh; if (ap) { const a = ap.toLowerCase(); if (a === 'pm' && hh < 12) hh += 12; if (a === 'am' && hh === 12) hh = 0; }
      y = +y; if (y < 100) y += 2000;
      return new Date(y, +mo - 1, +d, hh, +mm).getTime();
    }
    // mm/dd/yyyy variant already covered by Date.parse often
    m2 = raw.match(/(\d{1,2})\/(\d{1,2})\/(\d{2,4}),?\s+(\d{1,2}):(\d{2})(?:\s*(am|pm))?/i);
    if (m2) {
      let [, a, b, y, hh, mm, ap] = m2;
      y = +y; if (y < 100) y += 2000;
      hh = +hh; if (ap) { const p = ap.toLowerCase(); if (p === 'pm' && hh < 12) hh += 12; if (p === 'am' && hh === 12) hh = 0; }
      // Prefer day-first (PE): d/m/y if day > 12
      let d = +a, mo = +b;
      if (d > 12) { /* d/m */ }
      else if (b > 12) { d = +b; mo = +a; }
      return new Date(y, mo - 1, d, hh, +mm).getTime();
    }
    return null;
  }

  function detectMediaType(el) {
    const tip = (el.getAttribute('data-testid') || '') + ' ' + (el.innerHTML || '').slice(0, 500);
    const nested = $$('[data-testid]', el).map((n) => n.getAttribute('data-testid') || '').join(' ');
    const blob = (tip + ' ' + nested).toLowerCase();
    if (blob.includes('image') || el.querySelector('img[src*="blob:"], img[src*="mmg"]')) return { type: 'image', label: '[Imagen]' };
    if (blob.includes('ptt') || blob.includes('audio') || el.querySelector('audio')) return { type: 'audio', label: '[Audio]' };
    if (blob.includes('video') || el.querySelector('video')) return { type: 'video', label: '[Video]' };
    if (blob.includes('sticker')) return { type: 'sticker', label: '[Sticker]' };
    if (blob.includes('document') || blob.includes('download-')) return { type: 'document', label: '[Documento]' };
    if (blob.includes('gif')) return { type: 'gif', label: '[GIF]' };
    if (blob.includes('location') || blob.includes('map')) return { type: 'location', label: '[Ubicación]' };
    if (blob.includes('vcard') || blob.includes('contact-card')) return { type: 'contact', label: '[Contacto]' };
    if (blob.includes('poll')) return { type: 'poll', label: '[Encuesta]' };
    return null;
  }

  function msgDirection(el) {
    const did0 = el.getAttribute('data-id') || el.closest('[data-id]')?.getAttribute('data-id') || '';
    if (/^true_/.test(did0)) return 'out';
    if (/^false_/.test(did0)) return 'in';
    if (el.classList.contains('message-out') || el.closest('.message-out')) return 'out';
    if (el.classList.contains('message-in') || el.closest('.message-in')) return 'in';
    // WhatsApp a veces usa data-id con true_/false_ o clases en el contenedor padre
    const row = el.closest('[data-testid="msg-container"]') || el;
    const cls = row.className || '';
    if (/\bmessage-out\b/.test(cls)) return 'out';
    if (/\bmessage-in\b/.test(cls)) return 'in';
    // Heurística: mensajes salientes suelen alinearse a la derecha
    const style = getComputedStyle(row);
    if (style.alignSelf === 'flex-end' || style.marginLeft === 'auto') return 'out';
    // data-id format: true_... = from me sometimes
    const did = row.getAttribute('data-id') || el.getAttribute('data-id') || '';
    if (/^true_/.test(did)) return 'out';
    if (/^false_/.test(did)) return 'in';
    return 'in';
  }

  function extractText(el) {
    const sels = [
      'span.selectable-text.copyable-text',
      'span.selectable-text',
      '[data-testid="conversation-text"]',
      '.copyable-text span[dir="ltr"]',
      '.copyable-text span[dir="auto"]'
    ];
    for (const s of sels) {
      const nodes = $$(s, el);
      if (nodes.length) return nodes.map((n) => n.innerText || n.textContent || '').join('\n').trim();
    }
    return '';
  }

  function scrapeMessages() {
    const main = $('#main');
    if (!main) return [];
    const seen = new Set();
    const out = [];
    const isMsgRow = (n) => /^(true|false)_/.test(n.getAttribute('data-id') || '');
    const rows = $$('[data-id]', main).filter((n) => isMsgRow(n) && !(n.parentElement && n.parentElement.closest('[data-id^="true_"],[data-id^="false_"]')));
    const candidates = [
      ...rows,
      ...$$('[data-testid="msg-container"]', main).filter((n) => !n.closest('[data-id^="true_"],[data-id^="false_"]')),
      ...$$('.message-in, .message-out', main).filter((n) => !n.closest('[data-id^="true_"],[data-id^="false_"]')),
      ...$$('div.copyable-text[data-pre-plain-text]', main).filter((n) => !n.closest('[data-id^="true_"],[data-id^="false_"]')).map((n) => n.closest('[data-testid="msg-container"], .message-in, .message-out') || n)
    ];
    for (const el of candidates) {
      if (!el || seen.has(el)) continue;
      seen.add(el);
      // Evitar filas de sistema / fecha del día sin contenido
      const preEl = el.matches?.('[data-pre-plain-text]') ? el : $('[data-pre-plain-text]', el);
      const pre = preEl?.getAttribute('data-pre-plain-text') || '';
      let ts = parsePrePlain(pre);
      if (!ts) {
        const meta = $('[data-testid="msg-meta"]', el) || $('span[dir="auto"][title]', el);
        const tAttr = meta?.getAttribute('title') || meta?.textContent || '';
        // Solo hora HH:MM → usar hoy
        const hm = tAttr.match(/(\d{1,2}):(\d{2})/);
        if (hm) {
          const d = new Date();
          d.setHours(+hm[1], +hm[2], 0, 0);
          ts = d.getTime();
        }
      }
      if (!ts) ts = Date.now();

      const dir = msgDirection(el);
      const body = (isMsgRow(el) && el.querySelector('.message-in, .message-out')) || el;
      const media = detectMediaType(body);
      let text = extractText(body);
      let type = 'text';
      if (media) {
        type = media.type;
        text = text ? (media.label + ' ' + text) : media.label;
      } else if (!text) {
        // Puede ser emoji-only o sticker sin testid claro
        const alt = el.querySelector('img[alt]')?.getAttribute('alt');
        if (alt && alt.length < 40) { type = 'sticker'; text = '[Sticker] ' + alt; }
        else continue; // sin contenido usable
      }

      const dataId = el.getAttribute('data-id') || el.closest('[data-id]')?.getAttribute('data-id') || '';
      const id = dataId || ('h_' + hashId([ts, dir, type, text].join('|')).slice(0, 16));
      out.push({ id, ts, dir, type, text: text.slice(0, 8000) });
    }
    // Ordenar y dedupe por id
    return mergeMsgs(out);
  }

  function scheduleUpload(contactId) {
    clearTimeout(uploadTimer);
    uploadTimer = setTimeout(() => flushConversation(contactId), 1500);
  }

  async function flushConversation(contactId) {
    if (!contactId || !isLead(contactId)) return; // nunca subir chats no guardados como lead
    const msgs = convCache[contactId] || [];
    if (!msgs.length) return;
    convStatus = synced() ? 'Guardando en el servidor…' : 'Solo en este navegador (no conectado al servidor)';
    if (tab === 'contacto') renderConvOnly();
    const r = await bg({ type: 'conv-put', contactId, messages: msgs });
    if (r?.messages) convCache[contactId] = r.messages;
    if (current?.id !== contactId) return;
    convStatus = r?.ok
      ? (r.offline ? 'Solo en este navegador · conéctate al servidor en Ajustes para guardarla en la nube' : `En el servidor ✓ (${(r.messages || msgs).length} mensajes)`)
      : ('Error al guardar en el servidor: ' + (r?.error || '') + ' · se reintentará');
    if (tab === 'contacto') renderConvOnly();
  }

  async function loadConversation(contactId) {
    if (!contactId) return;
    const local = await bg({ type: 'conv-local', contactId });
    if (local?.messages) convCache[contactId] = mergeMsgs(convCache[contactId] || [], local.messages);
    if (!isLead(contactId)) {
      convStatus = 'Pulsa "Guardar como lead" para guardar este contacto y su conversación en el CRM';
    } else if (synced()) {
      convStatus = 'Cargando conversación…';
      if (tab === 'contacto') renderConvOnly();
      const r = await bg({ type: 'conv-get', contactId });
      if (r?.messages) convCache[contactId] = r.messages;
      convStatus = r?.ok ? `En el servidor ✓ (${(convCache[contactId] || []).length} mensajes)` : ('Sin conexión con el servidor: ' + (r?.error || ''));
    } else {
      convStatus = 'Solo en este navegador · conéctate al servidor en Ajustes para guardarla en la nube';
    }
    if (current?.id === contactId && tab === 'contacto') renderConvOnly();
  }

  // ---------- Autocompletado de leads ----------
  const DEFAULT_RULES = [
    { kw: 'mayorista|por mayor|al por mayor', labelName: 'Mayorista' },
    { kw: 'yape|plin|transferencia|pago pendiente|te pago', labelName: 'Pendiente pago' }
  ];
  function withDefaults(st) {
    const s = (st && typeof st === 'object' && !Array.isArray(st)) ? { ...st } : {};
    const a = (s.autoFill && typeof s.autoFill === 'object') ? { ...s.autoFill } : {};
    a.reminder = { on: true, days: 1, hour: 10, note: 'Primer seguimiento', ...(a.reminder || {}) };
    if (a.note === undefined) a.note = true;
    if (a.value === undefined) a.value = true;
    if (a.labels === undefined) a.labels = true;
    if (!Array.isArray(a.labelRules)) a.labelRules = DEFAULT_RULES.map((r) => ({ id: uid(), ...r }));
    s.autoFill = a;
    return s;
  }
  const AF = () => state.settings.autoFill;
  const LIMA = 'America/Lima';
  const pad = (n) => String(n).padStart(2, '0');
  function limaParts(ts = Date.now()) {
    const o = {}; new Intl.DateTimeFormat('en-CA', { timeZone: LIMA, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(new Date(ts)).forEach((p) => { o[p.type] = p.value; });
    return o;
  }
  const limaStamp = (ts = Date.now()) => { const p = limaParts(ts); return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`; };
  function defaultReminder(cfg, now = Date.now()) {
    const p = limaParts(now);
    const d = new Date(Date.UTC(+p.year, +p.month - 1, +p.day + (Math.max(0, parseInt(cfg.days, 10)) || 0)));
    const h = Math.min(23, Math.max(0, parseInt(cfg.hour, 10) || 0));
    return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(h)}:00`;
  }
  const fold = (t) => String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  function parseAmount(raw) {
    let s = String(raw).replace(/\s/g, '');
    const lc = s.lastIndexOf(','), ld = s.lastIndexOf('.');
    if (lc >= 0 && ld >= 0) { const dec = lc > ld ? ',' : '.'; s = s.split(dec === ',' ? '.' : ',').join(''); s = s.replace(dec, '.'); }
    else if (lc >= 0 || ld >= 0) {
      const sep = lc >= 0 ? ',' : '.'; const parts = s.split(sep);
      s = (parts.length > 2 || parts[parts.length - 1].length === 3) ? parts.join('') : parts.join('.');
    }
    const n = parseFloat(s); return isFinite(n) ? n : 0;
  }
  const NUM = '(\\d{1,3}(?:[.,\\s]\\d{3})+(?:[.,]\\d{1,2})?|\\d+(?:[.,]\\d{1,2})?)';
  const AMOUNT_RES = [new RegExp('s\\/\\.?\\s*' + NUM, 'gi'), new RegExp(NUM + '\\s*(?:soles|sol|pen)\\b', 'gi'), new RegExp('\\bpen\\s*' + NUM, 'gi')];
  function detectAmount(msgs) {
    let best = 0;
    for (const m of (msgs || []).slice(-60)) {
      const t = String(m.text || '');
      for (const re of AMOUNT_RES) { re.lastIndex = 0; let x; while ((x = re.exec(t))) { const v = parseAmount(x[1]); if (v > best && v < 1e7) best = v; } }
    }
    return Math.round(best * 100) / 100;
  }
  function ruleLabelId(r) {
    if (r.labelId && state.labels.some((l) => l.id === r.labelId)) return r.labelId;
    const n = fold(r.labelName); return n ? (state.labels.find((l) => fold(l.name) === n)?.id || '') : '';
  }
  function matchRules(msgs) {
    const txt = fold((msgs || []).filter((m) => m.dir !== 'out').map((m) => m.text).join('\n'));
    const ids = [];
    if (!txt) return ids;
    for (const r of AF().labelRules || []) {
      const kws = String(r.kw || '').split(/[|,]/).map((k) => fold(k).trim()).filter(Boolean);
      const lid = ruleLabelId(r);
      if (lid && kws.some((k) => txt.includes(k)) && !ids.includes(lid)) ids.push(lid);
    }
    return ids;
  }
  const isManual = (c, f) => !!(c.manual && c.manual[f]);
  /** Rellena campos vacíos de un lead a partir de la conversación. Nunca pisa lo que escribió el usuario. */
  function autoFromMessages(c, msgs) {
    if (!c || !msgs || !msgs.length) return false;
    const cfg = AF(); let changed = false;
    if (cfg.value && !(Number(c.value) > 0) && !isManual(c, 'value')) {
      const v = detectAmount(msgs); if (v > 0) { c.value = v; changed = true; }
    }
    if (cfg.labels) {
      c.labels = Array.isArray(c.labels) ? c.labels : [];
      const off = Array.isArray(c.labelsOff) ? c.labelsOff : [];
      for (const lid of matchRules(msgs)) if (!c.labels.includes(lid) && !off.includes(lid)) { c.labels.push(lid); changed = true; }
    }
    if (c.autoNote && !c.autoNote.done) {
      const n = (c.notes || []).find((x) => x.id === c.autoNote.id);
      const inc = msgs.filter((m) => m.dir !== 'out' && m.text);
      if (!n || n.text !== c.autoNote.text) { c.autoNote.done = true; changed = true; }
      else if (inc.length) {
        const snip = (m) => '"' + String(m.text).replace(/\s+/g, ' ').trim().slice(0, 120) + '"';
        const first = inc[0], last = inc[inc.length - 1];
        n.text += '\nPrimer mensaje: ' + snip(first) + (last !== first ? '\nÚltimo mensaje: ' + snip(last) : '');
        c.autoNote.done = true; changed = true;
      }
    }
    return changed;
  }
  /** Inicializa un lead recién creado (al pulsar "Guardar como lead"). */
  function initNewLead(c) {
    const cfg = AF();
    c.name = current?.name || c.name; if (!c.phone && current?.phone) c.phone = current.phone;
    c.stage = c.stage || state.stages[0] || 'Nuevo';
    if (cfg.reminder?.on && !c.reminder) { c.reminder = defaultReminder(cfg.reminder); c.reminderNote = c.reminderNote || cfg.reminder.note || 'Primer seguimiento'; c.reminderDone = false; }
    if (cfg.note) {
      const n = { id: uid(), ts: Date.now(), text: 'Lead creado desde WhatsApp el ' + limaStamp() + ' (hora Lima)' };
      c.notes = [...(c.notes || []), n]; c.autoNote = { id: n.id, text: n.text, done: false };
    }
    autoFromMessages(c, convCache[c.id] || []);
  }

  /** v1.5: ya no se crean leads automáticamente; solo cuenta si el chat fue guardado como lead. */
  function ensureLeadForCapture() {
    return !!current && isLead(current.id);
  }
  function saveAsLead() {
    if (!current) return;
    const isNew = !state.contacts[current.id];
    const c = getContact(current.id);
    if (isNew) initNewLead(c);
    if (!c.phone && current.phone) c.phone = current.phone;
    c.lead = true; c.leadAt = Date.now();
    touch(c);
    convStatus = synced() ? 'Guardado en CRM · Guardando conversación…' : 'Guardado en CRM (solo en este navegador hasta que te conectes al servidor)';
    lastScrapedKey = '';
    captureNow('force');
    if (synced()) loadConversation(current.id);
    toast('Guardado en CRM ✓');
  }
  function removeLead() {
    const c = current && state.contacts[current.id]; if (!c) return;
    if (!confirm('¿Quitar este contacto del CRM?\n\nDeja de sincronizarse: su ficha desaparece del CRM en la próxima sincronización. Lo ya guardado de la conversación en el servidor no se borra. Puedes volver a guardarlo cuando quieras.')) return;
    c.lead = false; c.leadAt = 0;
    touch(c);
    convStatus = '';
    toast('Quitado del CRM');
  }

  function captureNow(reason) {
    if (!current) return;
    const scraped = scrapeMessages();
    if (!scraped.length) return;
    const key = scraped.map((m) => m.id).join(',') + '|' + scraped.length;
    if (key === lastScrapedKey && reason !== 'force') return;
    lastScrapedKey = key;

    const ok = ensureLeadForCapture();
    if (!ok) {
      // No es lead: no se guarda ni se envía nada (ni la ficha ni la conversación).
      convStatus = 'Pulsa "Guardar como lead" para guardar este contacto y su conversación en el CRM';
      if (tab === 'contacto') renderConvOnly();
      return;
    }
    const cid = current.id;
    const prev = convCache[cid] || [];
    const merged = mergeMsgs(prev, scraped);
    const c = state.contacts[cid];
    if (c && autoFromMessages(c, merged)) { touch(c); if (tab === 'contacto' && !document.activeElement?.closest?.('#crmw-panel')) renderPanel(); }
    if (merged.length === prev.length && merged.every((m, i) => m.id === prev[i]?.id) && reason !== 'force') return;
    convCache[cid] = merged;
    chrome.storage.local.get('crmw_conversations').then((d) => {
      const all = d.crmw_conversations || {};
      all[cid] = merged;
      chrome.storage.local.set({ crmw_conversations: all });
    });
    convStatus = synced() ? 'Guardando en el servidor…' : 'Solo en este navegador (no conectado al servidor)';
    if (tab === 'contacto') renderConvOnly();
    scheduleUpload(cid);
  }

  // ---------- Detección del chat y del teléfono ----------
  const PHONE_RE = /^\+?[\d\s()-]{7,}$/;
  const INTL_PHONE_RE = /^\+\d[\d\s().-]{6,}\d$/;
  function normPhone(raw) {
    let d = String(raw || '').replace(/\D/g, '');
    if (d.length === 9 && d[0] === '9') d = '51' + d; // celular peruano sin código de país
    return d.length >= 8 && d.length <= 15 ? d : '';
  }
  function parseMsgJid(did) {
    const m = String(did || '').match(/^(true|false)_([^@_]+)@([a-z.]+)_/i);
    return m ? { fromMe: m[1] === 'true', user: m[2], server: m[3].toLowerCase() } : null;
  }
  /** (b) data-id de los mensajes: false_51999999999@c.us_ID. Grupos (@g.us) y @lid no dan número. */
  function phoneFromMessageIds(main) {
    const users = new Set(); let group = false, lid = false;
    for (const el of $$('[data-id]', main)) {
      const j = parseMsgJid(el.getAttribute('data-id'));
      if (!j) continue;
      if (j.server === 'g.us') group = true;
      else if (j.server === 'c.us' || j.server === 's.whatsapp.net') users.add(j.user);
      else if (j.server === 'lid') lid = true;
    }
    if (group) return { group: true, phone: '' };
    return { group: false, lid, phone: users.size === 1 ? normPhone([...users][0]) : '' };
  }
  /** (d) atributos del encabezado con ...@c.us */
  function phoneFromAttrs(root) {
    if (!root) return '';
    const found = new Set();
    for (const el of [root, ...root.querySelectorAll('*')]) {
      for (const at of el.attributes || []) {
        const m = String(at.value).match(/(\d{8,15})@c\.us/); if (m) found.add(m[1]);
      }
    }
    return found.size === 1 ? normPhone([...found][0]) : '';
  }
  /** (c) panel lateral "Info. del contacto" abierto: texto con formato +51 999 999 999 */
  function phoneFromDrawer() {
    const found = new Set();
    for (const el of $$('span, div[title]')) {
      if (el.children.length > 1) continue;
      if (el.closest('#main, #pane-side, #side, #crmw-panel, #crmw-modal, [data-testid="chat-list"]')) continue;
      const t = (el.getAttribute('title') || el.textContent || '').trim();
      if (t.length <= 24 && INTL_PHONE_RE.test(t)) { const p = normPhone(t); if (p) found.add(p); }
      if (found.size > 1) return '';
    }
    return found.size === 1 ? [...found][0] : '';
  }
  let phoneVote = { name: '', phone: '', n: 0 };
  const phoneMemo = {}; // nombre -> teléfono confirmado en esta sesión
  function detectChat() {
    const main = $('#main');
    const header = $('#main header') || $('[data-testid="conversation-header"]') || $('#main [role="banner"]');
    if (!header) return null;
    const candidates = [
      header.querySelector('[data-testid="conversation-info-header-chat-title"]'),
      header.querySelector('span[dir="auto"][title]'),
      header.querySelector('div[role="button"] span[dir="auto"]'),
      header.querySelector('span[dir="auto"]')
    ].filter(Boolean);
    let name = '';
    for (const el of candidates) { name = (el.getAttribute('title') || el.textContent || '').trim(); if (name) break; }
    if (!name) return null;
    let phone = '', src = '', pending = false;
    // (a) título o subtítulo del encabezado
    if (PHONE_RE.test(name)) { phone = normPhone(name); src = 'título'; }
    if (!phone) {
      const spans = [...header.querySelectorAll('span[title], span[dir="auto"]')].map((s) => (s.getAttribute('title') || s.textContent || '').trim());
      const p = spans.find((t) => t !== name && PHONE_RE.test(t) && !/,/.test(t));
      if (p) { phone = normPhone(p); src = 'encabezado'; }
    }
    const ids = main ? phoneFromMessageIds(main) : { group: false, phone: '' };
    if (ids.group) return { id: 'n:' + name, name, phone: '', group: true };
    if (!phone) {
      let cand = '', csrc = '';
      if (ids.phone) { cand = ids.phone; csrc = 'mensajes'; }
      else { const a = phoneFromAttrs(header); if (a) { cand = a; csrc = 'atributos'; } }
      if (!cand) { const d = phoneFromDrawer(); if (d) { cand = d; csrc = 'info del contacto'; } }
      if (cand) {
        // Confirmar en 2 lecturas seguidas (evita usar mensajes del chat anterior al cambiar de chat)
        if (phoneVote.name === name && phoneVote.phone === cand) phoneVote.n++; else phoneVote = { name, phone: cand, n: 1 };
        if (phoneVote.n >= 2 || phoneMemo[name] === cand) { phone = cand; src = csrc; phoneMemo[name] = cand; } else pending = true;
      } else if (phoneMemo[name]) { phone = phoneMemo[name]; src = 'memoria'; }
    }
    return { id: resolveKey(name, phone), name, phone, phoneSrc: src, phonePending: pending, group: false };
  }
  /** Clave estable: reutiliza la ficha existente (por teléfono, por id o por nombre 'n:Nombre'); nunca duplica. */
  function resolveKey(name, phone) {
    if (phone) {
      if (state.contacts[phone]) return phone;
      const byPhone = Object.values(state.contacts).find((c) => c && normPhone(c.phone) === phone);
      if (byPhone) return byPhone.id;
    }
    if (state.contacts['n:' + name]) return 'n:' + name;
    return phone || 'n:' + name;
  }
  /** Back-fill: si la ficha existe sin teléfono y lo detectamos, lo guardamos (sin re-clavear). */
  function backfillPhone() {
    const c = current && state.contacts[current.id];
    if (!c || !current.phone || c.phone || isManual(c, 'phone')) return;
    c.phone = current.phone; touch(c);
    if (tab === 'contacto' && !document.activeElement?.closest?.('#crmw-panel')) renderPanel();
  }
  function checkChat() {
    const c = detectChat();
    const nid = c?.id || null;
    const prevId = current?.id || null;
    if (nid !== prevId) {
      if (prevId) flushConversation(prevId);
      current = c;
      lastScrapedKey = '';
      renderPanel();
      if (current) {
        backfillPhone();
        loadConversation(current.id).then(() => captureNow('open'));
      }
    } else if (current) {
      const hadPhone = current.phone;
      current = c;
      if (current.phone && !hadPhone) { backfillPhone(); if (tab === 'contacto' && !state.contacts[current.id]) renderPanel(); }
      captureNow('poll');
    }
  }

  // ---------- Inserción en caja de mensaje (nunca envía) ----------
  function insertText(text) {
    const box = $('#main footer div[contenteditable="true"]') || $('footer [contenteditable="true"][role="textbox"]') || $('#main [contenteditable="true"][data-tab="10"]');
    if (!box) { toast('Abre un chat primero'); return; }
    box.focus();
    const sel = window.getSelection();
    const range = document.createRange(); range.selectNodeContents(box); range.collapse(false);
    sel.removeAllRanges(); sel.addRange(range);
    const personalized = text.replace(/\{nombre\}/gi, (current?.name || '').split(' ')[0]);
    if (!document.execCommand('insertText', false, personalized)) {
      const dt = new DataTransfer(); dt.setData('text/plain', personalized);
      box.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    }
    toast('Texto insertado (no enviado)');
  }

  function toast(msg) {
    const t = document.createElement('div'); t.className = 'crmw-toast'; t.textContent = msg;
    document.body.appendChild(t); setTimeout(() => t.remove(), 2200);
  }

  // ---------- UI base ----------
  let panel, toggleBtn, tab = 'contacto';
  function buildShell() {
    toggleBtn = document.createElement('button');
    toggleBtn.id = 'crmw-toggle'; toggleBtn.title = 'Mostrar/ocultar CRM'; toggleBtn.textContent = 'CRM';
    toggleBtn.onclick = () => setOpen(!state.ui.open);
    panel = document.createElement('aside'); panel.id = 'crmw-panel';
    document.body.append(toggleBtn, panel);
    setOpen(state.ui.open, false);
  }
  function setOpen(v, persist = true) {
    state.ui.open = v;
    panel.classList.toggle('crmw-hidden', !v);
    document.documentElement.classList.toggle('crmw-open', v);
    toggleBtn.classList.toggle('crmw-active', v);
    if (persist) save(['ui']);
    if (v && persist) bg({ type: 'sync-pull' });
  }
  chrome.runtime.onMessage.addListener((m) => { if (m?.type === 'toggle') setOpen(!state.ui.open); });

  function render() { renderPanel(); if ($('#crmw-modal')) renderModal(); }

  function labelChip(l, extra = '') { return `<span class="crmw-chip" style="background:${esc(l.color)}" ${extra}>${esc(l.name)}</span>`; }

  function convHTML(contactId) {
    const msgs = convCache[contactId] || [];
    const status = convStatus ? `<p class="crmw-muted crmw-conv-status">${esc(convStatus)}</p>` : '';
    if (!msgs.length) return `<div class="crmw-conv"><label>Conversación</label>${status}<p class="crmw-muted">Aún no hay mensajes guardados. Abre el chat y se capturarán solos.</p></div>`;
    const rows = msgs.slice(-200).map((m) => {
      const cls = m.dir === 'out' ? 'out' : 'in';
      const who = m.dir === 'out' ? 'Tú' : 'Ellos';
      return `<div class="crmw-msg ${cls}"><div class="crmw-msg-meta"><span>${who}</span><span>${esc(fmtTime(m.ts))}</span></div><div class="crmw-msg-text">${esc(m.text)}</div></div>`;
    }).join('');
    return `<div class="crmw-conv"><label>Conversación <small>(${msgs.length}${msgs.length >= MSG_CAP ? ' · tope' : ''})</small></label>${status}<div class="crmw-conv-list" id="crmw-conv-list">${rows}</div>
      ${synced() ? '<button class="crmw-btn crmw-sm" data-act="resync" title="Normalmente no hace falta: se sincroniza sola">Reintentar sincronización</button>' : '<button class="crmw-btn crmw-sm" data-act="goset">Conectar al servidor</button>'}</div>`;
  }

  function renderConvOnly() {
    const box = $('#crmw-conv-wrap');
    if (!box || !current) return;
    const scroll = $('#crmw-conv-list');
    const atBottom = scroll ? (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 40) : true;
    box.innerHTML = convHTML(current.id);
    const list = $('#crmw-conv-list');
    if (list && atBottom) list.scrollTop = list.scrollHeight;
  }

  function renderPanel() {
    if (!panel) return;
    const tabs = [['contacto', 'Contacto'], ['respuestas', 'Respuestas'], ['ajustes', 'Ajustes']];
    panel.innerHTML = `
      <div class="crmw-head"><strong>CRM <span>Xigma</span></strong>
        <div><button class="crmw-btn crmw-sm" data-act="kanban" title="Tablero">▦ Embudo</button>
        <button class="crmw-btn crmw-sm" data-act="list" title="Lista">☰ Lista</button>
        <button class="crmw-x" data-act="close" title="Ocultar">×</button></div></div>
      <nav class="crmw-tabs">${tabs.map(([k, n]) => `<button data-tab="${k}" class="${tab === k ? 'on' : ''}">${n}</button>`).join('')}</nav>
      <div class="crmw-body">${tab === 'contacto' ? contactHTML() : tab === 'respuestas' ? repliesHTML() : settingsHTML()}</div>`;
    if (tab === 'contacto' && current) {
      const list = $('#crmw-conv-list');
      if (list) list.scrollTop = list.scrollHeight;
    }
  }

  function contactHTML() {
    if (!current) return `<p class="crmw-muted">Abre un chat para ver y editar la ficha del contacto.</p>`;
    const c = state.contacts[current.id];
    if (!c || c.lead !== true) return `<div class="crmw-card"><h3>${esc(current.name)}</h3>${current.phone ? `<p class="crmw-muted">${esc(current.phone)}</p>` : ''}
      <p class="crmw-muted"><span class="crmw-badge off">No está en el CRM</span></p>
      <button class="crmw-btn crmw-lead" data-act="add">💾 Guardar como lead</button>
      <p class="crmw-muted" style="margin-top:8px">Solo los chats que guardes como lead van al CRM: se crea su ficha y se guarda su conversación${synced() ? ' en el servidor' : ''}. Los demás chats nunca se envían.</p></div>`;
    const reminderPast = c.reminder && !c.reminderDone && new Date(c.reminder) < new Date();
    return `<div class="crmw-card">
      <p class="crmw-row" style="margin:0 0 4px"><span class="crmw-badge">✓ Guardado en CRM</span></p>
      <input class="crmw-title" data-f="name" value="${esc(c.name)}">
      <input class="crmw-in" data-f="phone" placeholder="Teléfono" value="${esc(c.phone)}">
      <label>Etapa</label>
      <select class="crmw-in" data-f="stage">${state.stages.map((s) => `<option ${s === c.stage ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>
      <label>Valor del negocio (S/)</label>
      <input class="crmw-in" type="number" min="0" step="0.01" data-f="value" value="${Number(c.value) || 0}">
      <label>Etiquetas</label>
      <div class="crmw-chips">${state.labels.map((l) => `<span class="crmw-chip ${c.labels.includes(l.id) ? '' : 'off'}" style="--c:${esc(l.color)}" data-act="tl" data-id="${l.id}">${esc(l.name)}</span>`).join('') || '<span class="crmw-muted">Crea etiquetas en Ajustes</span>'}</div>
      <label>Recordatorio de seguimiento ${reminderPast ? '<b class="crmw-warn">vencido</b>' : ''}</label>
      <input class="crmw-in" type="datetime-local" data-f="reminder" value="${esc(c.reminder)}">
      <input class="crmw-in" data-f="reminderNote" placeholder="Motivo (opcional)" value="${esc(c.reminderNote)}">
      ${c.reminder ? `<label class="crmw-row"><input type="checkbox" data-f="reminderDone" ${c.reminderDone ? 'checked' : ''}> Hecho</label>` : ''}
      <label>Notas</label>
      <textarea class="crmw-in" id="crmw-note" rows="2" placeholder="Escribe una nota…"></textarea>
      <button class="crmw-btn" data-act="note">Guardar nota</button>
      <ul class="crmw-notes">${[...c.notes].reverse().map((n) => `<li><small>${fmtDate(n.ts)}</small><button class="crmw-x" data-act="delnote" data-id="${n.id}">×</button><div>${esc(n.text)}</div></li>`).join('')}</ul>
      <div id="crmw-conv-wrap">${convHTML(current.id)}</div>
      <button class="crmw-btn crmw-danger crmw-sm" data-act="unlead" title="Deja de sincronizar este contacto (no borra la conversación del servidor)">Quitar del CRM</button>
    </div>`;
  }

  function repliesHTML() {
    return `<p class="crmw-muted">Clic para insertar en el chat (no se envía). Usa {nombre} para personalizar.</p>
      ${state.replies.map((r) => `<div class="crmw-reply" data-act="ins" data-id="${r.id}"><b>${esc(r.title)}</b><div>${esc(r.text)}</div>
        <span><button class="crmw-x" data-act="editr" data-id="${r.id}" title="Editar">✎</button><button class="crmw-x" data-act="delr" data-id="${r.id}" title="Eliminar">×</button></span></div>`).join('')}
      <div class="crmw-card"><input class="crmw-in" id="crmw-rt" placeholder="Título"><textarea class="crmw-in" id="crmw-rx" rows="3" placeholder="Texto de la respuesta"></textarea>
      <input type="hidden" id="crmw-rid"><button class="crmw-btn" data-act="saver">Guardar respuesta</button></div>`;
  }

  function autoFillHTML() {
    const a = AF(), r = a.reminder;
    const chk = (k, v, label) => `<label class="crmw-row crmw-af"><input type="checkbox" data-af="${k}" ${v ? 'checked' : ''}> ${label}</label>`;
    const rules = (a.labelRules || []).map((x) => { const lid = ruleLabelId(x);
      return `<div class="crmw-row"><input class="crmw-in" data-rule="${esc(x.id)}" data-rf="kw" value="${esc(x.kw)}" placeholder="palabra|otra frase">
        <select class="crmw-in" data-rule="${esc(x.id)}" data-rf="label" style="max-width:120px">${lid ? '' : '<option value="">(etiqueta)</option>'}${state.labels.map((l) => `<option value="${esc(l.id)}" ${l.id === lid ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>
        <button class="crmw-x" data-act="delrule" data-id="${esc(x.id)}" title="Eliminar regla">×</button></div>`; }).join('');
    return `<div class="crmw-card"><h4>Autocompletar leads nuevos</h4>
      <p class="crmw-muted">El teléfono y el nombre se toman del chat. Nunca se reemplaza lo que escribes tú.</p>
      ${chk('rem.on', r.on, 'Recordatorio de primer seguimiento')}
      <div class="crmw-row"><span class="crmw-muted">a los</span><input class="crmw-in" type="number" min="0" max="60" data-af="rem.days" value="${esc(r.days)}" style="width:60px"><span class="crmw-muted">día(s), a las</span><input class="crmw-in" type="number" min="0" max="23" data-af="rem.hour" value="${esc(r.hour)}" style="width:60px"><span class="crmw-muted">h (Lima)</span></div>
      <input class="crmw-in" data-af="rem.note" value="${esc(r.note)}" placeholder="Motivo">
      ${chk('note', a.note, 'Nota inicial con fecha y mensaje del cliente')}
      ${chk('value', a.value, 'Valor S/ desde montos del chat (S/ 150, 150 soles)')}
      ${chk('labels', a.labels, 'Etiquetas por palabras clave (mensajes del cliente)')}
      ${rules}<button class="crmw-btn crmw-sm" data-act="addrule">+ Regla</button></div>`;
  }

  function settingsHTML() {
    return `<div class="crmw-card"><h4>Etiquetas</h4>
      ${state.labels.map((l) => `<div class="crmw-row"><input type="color" data-lc="${l.id}" value="${esc(l.color)}"><input class="crmw-in" data-ln="${l.id}" value="${esc(l.name)}"><button class="crmw-x" data-act="dell" data-id="${l.id}">×</button></div>`).join('')}
      <div class="crmw-row"><input type="color" id="crmw-nlc" value="#5ab584"><input class="crmw-in" id="crmw-nl" placeholder="Nueva etiqueta"><button class="crmw-btn crmw-sm" data-act="addl">+</button></div></div>
      <div class="crmw-card"><h4>Etapas del embudo</h4>
      ${state.stages.map((s, i) => `<div class="crmw-row"><input class="crmw-in" data-sn="${i}" value="${esc(s)}"><button class="crmw-x" data-act="up" data-i="${i}">↑</button><button class="crmw-x" data-act="dels" data-i="${i}">×</button></div>`).join('')}
      <div class="crmw-row"><input class="crmw-in" id="crmw-ns" placeholder="Nueva etapa"><button class="crmw-btn crmw-sm" data-act="adds">+</button></div></div>
      ${autoFillHTML()}
      <div class="crmw-card"><h4>Datos</h4>
      <button class="crmw-btn crmw-sm" data-act="csv">Exportar CSV</button>
      <button class="crmw-btn crmw-sm" data-act="json">Backup JSON</button>
      <button class="crmw-btn crmw-sm" data-act="imp">Importar CSV/JSON</button>
      <input type="file" id="crmw-file" accept=".csv,.json" hidden>
      <p class="crmw-muted">Se guarda en este navegador${syncInfo.token ? ' y se sincroniza con tu servidor' : ''}.</p>
      <p class="crmw-muted">Las conversaciones se guardan aparte (máx. ${MSG_CAP} mensajes por contacto).</p></div>
      <div class="crmw-card"><h4>Sincronización (servidor)</h4>
      <input class="crmw-in" id="crmw-surl" placeholder="URL del servidor" value="${esc(syncInfo.url || 'https://mm.comunidadcpe.com')}">
      <input class="crmw-in" id="crmw-suser" placeholder="Usuario" value="${esc(syncInfo.user || '')}">
      <input class="crmw-in" id="crmw-spass" type="password" placeholder="${syncInfo.token ? 'Contraseña (ya conectado)' : 'Contraseña'}">
      <button class="crmw-btn crmw-sm" data-act="sconnect">${syncInfo.token ? 'Reconectar' : 'Conectar'}</button>
      ${syncInfo.token ? '<button class="crmw-btn crmw-sm" data-act="snow">Sincronizar ahora</button> <button class="crmw-btn crmw-sm crmw-danger" data-act="sout">Desconectar</button>' : ''}
      <p class="crmw-muted">${syncInfo.token ? 'Conectado como <b>' + esc(syncInfo.user) + '</b>' : 'No conectado (solo local)'}<br>
      Última sincronización: ${syncInfo.lastSync ? fmtDate(syncInfo.lastSync) : '—'}${syncInfo.lastError ? '<br><span style="color:#e74c3c">Error: ' + esc(syncInfo.lastError) + '</span>' : ''}</p></div>`;
  }

  // ---------- Eventos del panel ----------
  function onPanelClick(e) {
    const t = e.target.closest('[data-tab],[data-act]'); if (!t) return;
    if (t.dataset.tab) { tab = t.dataset.tab; return renderPanel(); }
    const a = t.dataset.act, id = t.dataset.id;
    const c = current && state.contacts[current.id];
    switch (a) {
      case 'close': return setOpen(false);
      case 'kanban': return openModal('kanban');
      case 'list': return openModal('list');
      case 'add': saveAsLead(); break;
      case 'unlead': removeLead(); break;
      case 'goset': tab = 'ajustes'; break;
      case 'tl': {
        if (!c) return;
        const on = c.labels.includes(id), off = Array.isArray(c.labelsOff) ? c.labelsOff : [];
        c.labels = on ? c.labels.filter((x) => x !== id) : [...c.labels, id];
        c.labelsOff = on ? [...new Set([...off, id])] : off.filter((x) => x !== id); // lo que quitas a mano no se vuelve a poner solo
        touch(c); break;
      }
      case 'addrule': AF().labelRules.push({ id: uid(), kw: '', labelId: state.labels[0]?.id || '' }); save(['settings']); break;
      case 'delrule': AF().labelRules = AF().labelRules.filter((r) => r.id !== id); save(['settings']); break;
      case 'note': { const v = $('#crmw-note').value.trim(); if (!v || !c) return; c.notes.push({ id: uid(), ts: Date.now(), text: v }); touch(c); break; }
      case 'delnote': c.notes = c.notes.filter((n) => n.id !== id); touch(c); break;
      case 'delc': if (confirm('¿Eliminar este contacto del CRM?')) { delete state.contacts[current.id]; save(['contacts']); } break;
      case 'ins': if (e.target.closest('.crmw-x')) return; insertText(state.replies.find((r) => r.id === id)?.text || ''); return;
      case 'editr': { const r = state.replies.find((x) => x.id === id); $('#crmw-rt').value = r.title; $('#crmw-rx').value = r.text; $('#crmw-rid').value = r.id; return; }
      case 'delr': if (confirm('¿Eliminar respuesta?')) { state.replies = state.replies.filter((r) => r.id !== id); save(['replies']); } break;
      case 'saver': {
        const title = $('#crmw-rt').value.trim(), text = $('#crmw-rx').value.trim(), rid = $('#crmw-rid').value;
        if (!text) return toast('Escribe el texto');
        const ex = state.replies.find((r) => r.id === rid);
        if (ex) Object.assign(ex, { title: title || 'Sin título', text }); else state.replies.push({ id: uid(), title: title || 'Sin título', text });
        save(['replies']); break;
      }
      case 'addl': { const n = $('#crmw-nl').value.trim(); if (!n) return; state.labels.push({ id: uid(), name: n, color: $('#crmw-nlc').value }); save(['labels']); break; }
      case 'dell': if (confirm('¿Eliminar etiqueta?')) { state.labels = state.labels.filter((l) => l.id !== id); Object.values(state.contacts).forEach((x) => (x.labels = x.labels.filter((l) => l !== id))); save(['labels', 'contacts']); } break;
      case 'adds': { const n = $('#crmw-ns').value.trim(); if (!n || state.stages.includes(n)) return; state.stages.push(n); save(['stages']); break; }
      case 'dels': {
        const i = +t.dataset.i; if (state.stages.length <= 1) return;
        const s = state.stages[i]; if (!confirm(`¿Eliminar etapa "${s}"? Sus contactos pasan a "${state.stages[i === 0 ? 1 : 0]}".`)) return;
        state.stages.splice(i, 1); Object.values(state.contacts).forEach((x) => { if (x.stage === s) x.stage = state.stages[0]; });
        save(['stages', 'contacts']); break;
      }
      case 'up': { const i = +t.dataset.i; if (i > 0) { [state.stages[i - 1], state.stages[i]] = [state.stages[i], state.stages[i - 1]]; save(['stages']); } break; }
      case 'csv': return download('crm-xigma-contactos.csv', toCSV(), 'text/csv;charset=utf-8');
      case 'json': return download('crm-xigma-backup.json', JSON.stringify({ version: 2, exported: new Date().toISOString(), contacts: state.contacts, labels: state.labels, stages: state.stages, replies: state.replies, conversations: convCache }, null, 2), 'application/json');
      case 'imp': return $('#crmw-file').click();
      case 'sconnect': {
        const url = $('#crmw-surl').value.trim().replace(/\/+$/, ''), user = $('#crmw-suser').value.trim(), pass = $('#crmw-spass').value;
        if (!url || !user || !pass) return toast('Completa URL, usuario y contraseña');
        toast('Conectando…');
        bg({ type: 'sync-login', url, user, pass }).then((r) => {
          toast(r?.ok ? 'Conectado y sincronizado ✓' : 'Error: ' + (r?.error || 'sin respuesta'));
          if (r?.ok && current) { ensureLeadForCapture(); captureNow('force'); loadConversation(current.id); }
        });
        return;
      }
      case 'snow': toast('Sincronizando…'); bg({ type: 'sync-pull' }).then((r) => toast(r?.ok ? 'Sincronizado ✓' : 'Error: ' + (r?.error || ''))); return;
      case 'sout': if (confirm('¿Desconectar del servidor? Los datos locales se mantienen.')) bg({ type: 'sync-logout' }); return;
      case 'resync': if (current) { captureNow('force'); flushConversation(current.id).then(() => loadConversation(current.id)); toast('Sincronizando conversación…'); } return;
      default: return;
    }
    renderPanel();
  }

  function onPanelChange(e) {
    const t = e.target;
    if (t.id === 'crmw-file') return importFile(t.files[0]);
    if (t.dataset.f && current) {
      const c = state.contacts[current.id]; if (!c || c.lead !== true) return;
      const f = t.dataset.f;
      c[f] = t.type === 'checkbox' ? t.checked : f === 'value' ? parseFloat(t.value) || 0 : t.value;
      c.manual = { ...(c.manual && !Array.isArray(c.manual) ? c.manual : {}), [f]: true }; // no autocompletar lo que editas tú
      if (f === 'reminder') c.reminderDone = false;
      touch(c); if (['reminder', 'stage'].includes(f)) renderPanel();
    } else if (t.dataset.af) {
      const cfg = AF(), k = t.dataset.af, v = t.type === 'checkbox' ? t.checked : t.value;
      if (k.startsWith('rem.')) cfg.reminder[k.slice(4)] = (k === 'rem.on') ? v : (k === 'rem.note' ? String(v).trim() : Math.max(0, parseInt(v, 10) || 0));
      else cfg[k] = v;
      save(['settings']);
    } else if (t.dataset.rule) {
      const r = AF().labelRules.find((x) => x.id === t.dataset.rule); if (!r) return;
      if (t.dataset.rf === 'kw') r.kw = t.value.trim(); else { r.labelId = t.value; r.labelName = state.labels.find((l) => l.id === t.value)?.name || ''; }
      save(['settings']);
    } else if (t.dataset.lc || t.dataset.ln) {
      const l = state.labels.find((x) => x.id === (t.dataset.lc || t.dataset.ln));
      if (t.dataset.lc) l.color = t.value; else l.name = t.value.trim() || l.name;
      save(['labels']);
    } else if (t.dataset.sn !== undefined) {
      const i = +t.dataset.sn, old = state.stages[i], n = t.value.trim();
      if (!n || state.stages.includes(n)) { t.value = old; return; }
      state.stages[i] = n; Object.values(state.contacts).forEach((x) => { if (x.stage === old) x.stage = n; });
      save(['stages', 'contacts']);
    }
  }

  // ---------- Export / Import ----------
  const CSV_COLS = ['id', 'name', 'phone', 'stage', 'labels', 'value', 'reminder', 'reminderNote', 'notes', 'created', 'updated'];
  const csvCell = (v) => { const s = String(v ?? ''); return /[",\n;]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  function toCSV() {
    const lname = (id) => state.labels.find((l) => l.id === id)?.name || '';
    const rows = leadContacts().map((c) => [c.id, c.name, c.phone, c.stage, c.labels.map(lname).filter(Boolean).join('|'), c.value, c.reminder, c.reminderNote,
      c.notes.map((n) => `[${new Date(n.ts).toISOString()}] ${n.text}`).join(' || '), new Date(c.created).toISOString(), new Date(c.updated).toISOString()]);
    return '\uFEFF' + [CSV_COLS, ...rows].map((r) => r.map(csvCell).join(',')).join('\n');
  }
  function parseCSV(text) {
    const rows = []; let row = [], cell = '', q = false;
    text = text.replace(/^\uFEFF/, '');
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (q) { if (ch === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += ch; }
      else if (ch === '"') q = true;
      else if (ch === ',') { row.push(cell); cell = ''; }
      else if (ch === '\n' || ch === '\r') { if (ch === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
      else cell += ch;
    }
    if (cell || row.length) { row.push(cell); rows.push(row); }
    return rows.filter((r) => r.some((x) => x.trim()));
  }
  function download(name, data, type) {
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([data], { type })); a.download = name;
    document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  async function importFile(file) {
    if (!file) return;
    const text = await file.text();
    try {
      let n = 0;
      if (file.name.toLowerCase().endsWith('.json')) {
        const d = JSON.parse(text);
        if (!confirm('Importar backup JSON. Los contactos con el mismo ID se sobrescriben. ¿Continuar?')) return;
        for (const [k, v] of Object.entries(d.contacts || {})) if (v && typeof v === 'object') state.contacts[k] = { ...v, lead: true }; n = Object.keys(d.contacts || {}).length;
        if (Array.isArray(d.labels)) d.labels.forEach((l) => { if (!state.labels.some((x) => x.id === l.id)) state.labels.push(l); });
        if (Array.isArray(d.stages)) d.stages.forEach((s) => { if (!state.stages.includes(s)) state.stages.push(s); });
        if (Array.isArray(d.replies)) d.replies.forEach((r) => { if (!state.replies.some((x) => x.id === r.id)) state.replies.push(r); });
        if (d.conversations && typeof d.conversations === 'object') {
          for (const [cid, msgs] of Object.entries(d.conversations)) convCache[cid] = mergeMsgs(convCache[cid] || [], msgs || []);
          await chrome.storage.local.set({ crmw_conversations: convCache });
        }
      } else {
        const [head, ...rows] = parseCSV(text); const idx = (k) => head.indexOf(k);
        for (const r of rows) {
          const g = (k) => (idx(k) >= 0 ? r[idx(k)] : '');
          const phone = g('phone'), name = g('name');
          const id = g('id') || (phone ? phone.replace(/\D/g, '') : 'n:' + name); if (!id || id === 'n:') continue;
          const labels = g('labels').split('|').map((s) => s.trim()).filter(Boolean).map((ln) => {
            let l = state.labels.find((x) => x.name.toLowerCase() === ln.toLowerCase());
            if (!l) { l = { id: uid(), name: ln, color: '#' + Math.floor(Math.random() * 0xaaaaaa + 0x222222).toString(16) }; state.labels.push(l); }
            return l.id;
          });
          const stage = g('stage') || state.stages[0]; if (!state.stages.includes(stage)) state.stages.push(stage);
          const notes = g('notes') ? g('notes').split(' || ').map((t) => { const m = t.match(/^\[(.+?)\] ([\s\S]*)$/); return { id: uid(), ts: m ? Date.parse(m[1]) || Date.now() : Date.now(), text: m ? m[2] : t }; }) : [];
          state.contacts[id] = { ...(state.contacts[id] || {}), lead: true, id, name: name || id, phone, stage, labels, value: parseFloat(g('value')) || 0, reminder: g('reminder'), reminderNote: g('reminderNote'), reminderDone: false, notes, created: Date.parse(g('created')) || Date.now(), updated: Date.now() };
          n++;
        }
      }
      await save(); render(); toast(`${n} contactos importados`);
    } catch (err) { alert('Error al importar: ' + err.message); }
    $('#crmw-file') && ($('#crmw-file').value = '');
  }

  // ---------- Modal: Kanban y Lista ----------
  let modalView = 'kanban', filters = { q: '', label: '', stage: '' };
  function openModal(v) {
    modalView = v;
    if (!$('#crmw-modal')) {
      const m = document.createElement('div'); m.id = 'crmw-modal';
      m.addEventListener('click', onModalClick); m.addEventListener('input', onModalInput);
      m.addEventListener('dragstart', (e) => { const card = e.target.closest('[data-cid]'); if (card) { e.dataTransfer.setData('text/plain', card.dataset.cid); card.classList.add('drag'); } });
      m.addEventListener('dragend', (e) => e.target.closest?.('[data-cid]')?.classList.remove('drag'));
      m.addEventListener('dragover', (e) => { const col = e.target.closest('[data-stage]'); if (col) { e.preventDefault(); col.classList.add('over'); } });
      m.addEventListener('dragleave', (e) => e.target.closest('[data-stage]')?.classList.remove('over'));
      m.addEventListener('drop', (e) => {
        const col = e.target.closest('[data-stage]'); if (!col) return; e.preventDefault();
        const c = state.contacts[e.dataTransfer.getData('text/plain')];
        if (c) { c.stage = col.dataset.stage; touch(c); renderModal(); renderPanel(); }
      });
      document.body.appendChild(m);
      document.addEventListener('keydown', escClose);
    }
    renderModal();
  }
  function escClose(e) { if (e.key === 'Escape') closeModal(); }
  function closeModal() { $('#crmw-modal')?.remove(); document.removeEventListener('keydown', escClose); }
  function filtered() {
    const q = filters.q.toLowerCase();
    return leadContacts().filter((c) =>
      (!q || (c.name + ' ' + c.phone + ' ' + c.notes.map((n) => n.text).join(' ')).toLowerCase().includes(q)) &&
      (!filters.label || c.labels.includes(filters.label)) && (!filters.stage || c.stage === filters.stage))
      .sort((a, b) => b.updated - a.updated);
  }
  function cardLabels(c) { return c.labels.map((id) => state.labels.find((l) => l.id === id)).filter(Boolean).map((l) => labelChip(l)).join(''); }
  function renderModal() {
    const m = $('#crmw-modal'); if (!m) return;
    const list = filtered();
    const total = list.reduce((s, c) => s + (Number(c.value) || 0), 0);
    const focus = document.activeElement?.id;
    const bar = `<div class="crmw-mhead"><div class="crmw-seg"><button data-v="kanban" class="${modalView === 'kanban' ? 'on' : ''}">▦ Embudo</button><button data-v="list" class="${modalView === 'list' ? 'on' : ''}">☰ Lista</button></div>
      <input id="crmw-q" class="crmw-in" placeholder="Buscar nombre, teléfono o nota…" value="${esc(filters.q)}">
      <select id="crmw-fl" class="crmw-in"><option value="">Todas las etiquetas</option>${state.labels.map((l) => `<option value="${l.id}" ${filters.label === l.id ? 'selected' : ''}>${esc(l.name)}</option>`).join('')}</select>
      ${modalView === 'list' ? `<select id="crmw-fs" class="crmw-in"><option value="">Todas las etapas</option>${state.stages.map((s) => `<option ${filters.stage === s ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select>` : ''}
      <span class="crmw-muted">${list.length} contactos · ${money(total)}</span><button class="crmw-x crmw-big" data-close>×</button></div>`;
    let body;
    if (modalView === 'kanban') {
      body = `<div class="crmw-kanban">${state.stages.map((s) => { const cs = list.filter((c) => c.stage === s);
        return `<div class="crmw-col" data-stage="${esc(s)}"><div class="crmw-colh">${esc(s)} <span>${cs.length} · ${money(cs.reduce((a, c) => a + (Number(c.value) || 0), 0))}</span></div>
        ${cs.map((c) => `<div class="crmw-kcard" draggable="true" data-cid="${esc(c.id)}"><b>${esc(c.name)}</b><small>${esc(c.phone)}</small><div>${cardLabels(c)}</div>${c.value ? `<small class="crmw-val">${money(c.value)}</small>` : ''}${c.reminder && !c.reminderDone ? `<small>⏰ ${esc(new Date(c.reminder).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }))}</small>` : ''}</div>`).join('')}</div>`; }).join('')}</div>`;
    } else {
      body = `<table class="crmw-table"><thead><tr><th>Nombre</th><th>Teléfono</th><th>Etapa</th><th>Etiquetas</th><th>Valor</th><th>Recordatorio</th><th>Última nota</th></tr></thead><tbody>
        ${list.map((c) => `<tr data-cid="${esc(c.id)}"><td><b>${esc(c.name)}</b></td><td>${esc(c.phone)}</td><td>${esc(c.stage)}</td><td>${cardLabels(c)}</td><td>${money(c.value)}</td><td>${c.reminder ? esc(new Date(c.reminder).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' })) + (c.reminderDone ? ' ✓' : '') : ''}</td><td>${esc(c.notes.at(-1)?.text || '').slice(0, 60)}</td></tr>`).join('') || '<tr><td colspan="7" class="crmw-muted">Sin contactos</td></tr>'}
      </tbody></table>`;
    }
    m.innerHTML = `<div class="crmw-mbox">${bar}<div class="crmw-mbody">${body}</div><p class="crmw-muted crmw-foot">Arrastra las tarjetas entre columnas. Doble clic en un contacto con teléfono para abrir su chat.</p></div>`;
    if (focus && $('#' + focus)) { const el = $('#' + focus); el.focus(); if (el.setSelectionRange) el.setSelectionRange(el.value.length, el.value.length); }
  }
  function onModalClick(e) {
    if (e.target.id === 'crmw-modal' || e.target.closest('[data-close]')) return closeModal();
    const v = e.target.closest('[data-v]'); if (v) { modalView = v.dataset.v; if (modalView === 'kanban') filters.stage = ''; renderModal(); }
  }
  function onModalInput(e) {
    if (e.target.id === 'crmw-q') filters.q = e.target.value;
    else if (e.target.id === 'crmw-fl') filters.label = e.target.value;
    else if (e.target.id === 'crmw-fs') filters.stage = e.target.value;
    else return;
    renderModal();
  }
  document.addEventListener('dblclick', (e) => {
    const el = e.target.closest?.('#crmw-modal [data-cid]'); if (!el) return;
    const c = state.contacts[el.dataset.cid];
    if (c?.phone) { closeModal(); location.href = 'https://web.whatsapp.com/send?phone=' + c.phone.replace(/\D/g, ''); }
    else toast('Este contacto no tiene teléfono guardado');
  });

  // ---------- Init ----------
  async function init() {
    await load();
    await loadSyncInfo();
    buildShell();
    bg({ type: 'sync-pull' });
    panel.addEventListener('click', onPanelClick);
    panel.addEventListener('change', onPanelChange);
    renderPanel();
    let pending = false;
    new MutationObserver(() => {
      if (pending) return;
      pending = true;
      setTimeout(() => { pending = false; checkChat(); }, 300);
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
    setInterval(checkChat, 2000);
    window.addEventListener('beforeunload', () => { if (current) flushConversation(current.id); });
    checkChat();
  }
  init();
})();
