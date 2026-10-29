// CRM CPE – panel
(() => {
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const csrf = document.querySelector('meta[name="csrf"]')?.content || '';

  // Ojito: mostrar/ocultar contraseña
  $$('[data-eye]').forEach((b) => b.addEventListener('click', () => {
    const i = document.getElementById(b.dataset.eye); if (!i) return;
    const show = i.type === 'password'; i.type = show ? 'text' : 'password';
    b.classList.toggle('on', show);
    b.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña'); b.title = b.getAttribute('aria-label');
    i.focus();
  }));

  // Confirmaciones
  $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));
  // Filtros autoenviables
  $$('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));
  // Filas clicables
  $$('tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => { if (!e.target.closest('a,button,input,select')) location.href = tr.dataset.href; }));
  // Chat: ir al final
  const chat = document.getElementById('chat'); if (chat) chat.scrollTop = chat.scrollHeight;
  // Ocultar avisos
  $$('.flash.ok').forEach((f) => setTimeout(() => f.classList.add('hide'), 4000));

  // Embudo: drag & drop
  async function move(card, stage) {
    const fd = new FormData(); fd.append('a', 'move'); fd.append('id', card.dataset.id); if (card.dataset.u) fd.append('u', card.dataset.u); fd.append('stage', stage); fd.append('csrf', csrf);
    card.classList.add('saving');
    try {
      const r = await fetch('/mm/embudo', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
      const j = await r.json(); if (!j.ok) throw new Error(j.error || '');
      const col = document.querySelector(`.col[data-stage="${CSS.escape(stage)}"] .cards`); col.prepend(card);
      card.querySelector('.kmove').value = stage; recount();
    } catch (err) { alert(err && err.message ? err.message : 'No se pudo mover el lead. Recarga la página.'); card.querySelector('.kmove').value = card.closest('.col').dataset.stage; }
    card.classList.remove('saving');
  }
  function recount() { $$('.col').forEach((c) => { c.querySelector('.cnt').textContent = c.querySelectorAll('.kcard').length; }); }
  let dragged = null;
  $$('.kcard').forEach((k) => {
    k.addEventListener('dragstart', (e) => { dragged = k; k.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', k.dataset.id); });
    k.addEventListener('dragend', () => { k.classList.remove('drag'); dragged = null; $$('.col').forEach((c) => c.classList.remove('over')); });
    k.querySelector('.kmove')?.addEventListener('change', (e) => move(k, e.target.value));
  });
  $$('.col').forEach((c) => {
    c.addEventListener('dragover', (e) => { if (dragged) { e.preventDefault(); c.classList.add('over'); } });
    c.addEventListener('dragleave', (e) => { if (!c.contains(e.relatedTarget)) c.classList.remove('over'); });
    c.addEventListener('drop', (e) => { e.preventDefault(); c.classList.remove('over'); if (dragged && dragged.closest('.col') !== c) move(dragged, c.dataset.stage); });
  });
})();
