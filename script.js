// ID acak per browser, hanya untuk menandai pesan milik sendiri (rata kanan). Bukan identitas.
let cid = null;
try { cid = localStorage.getItem('cid'); } catch (e) {}
if (!cid) {
  cid = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
  try { localStorage.setItem('cid', cid); } catch (e) {}
}

const log = document.getElementById('log');
const box = document.getElementById('box');
const btn = document.getElementById('send');
let lastId = 0, lastDay = '', empty = true;

function addMessage(m) {
  if (empty) { log.innerHTML = ''; empty = false; }
  const d = new Date(m.ts * 1000);
  const day = d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long' });
  if (day !== lastDay) {
    const s = document.createElement('div');
    s.className = 'day'; s.textContent = day; log.appendChild(s); lastDay = day;
  }
  const el = document.createElement('div');
  el.className = 'msg' + (m.cid === cid ? ' me' : '');
  el.append(document.createTextNode(m.body));
  const t = document.createElement('time');
  t.textContent = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  el.appendChild(t);
  log.appendChild(el);
}

async function poll() {
  try {
    const r = await fetch('api.php?action=poll&since=' + lastId, { cache: 'no-store' });
    const { messages } = await r.json();
    if (messages.length) {
      const nearBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 120;
      const firstLoad = lastId === 0;
      messages.forEach(m => { addMessage(m); lastId = Math.max(lastId, +m.id); });
      if (nearBottom || firstLoad) log.scrollTop = log.scrollHeight;
    }
  } catch (e) { /* coba lagi di putaran berikutnya */ }
}

async function send() {
  const body = box.value.trim();
  if (!body) return;
  btn.disabled = true;
  try {
    const r = await fetch('api.php?action=send', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cid, body })
    });
    if (r.ok) {
      box.value = ''; box.style.height = 'auto';
      await poll(); log.scrollTop = log.scrollHeight;
    }
  } catch (e) {}
  btn.disabled = false; box.focus();
}

btn.addEventListener('click', send);
box.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
});
box.addEventListener('input', () => {
  box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight, 120) + 'px';
});

poll();
setInterval(poll, 2000);
