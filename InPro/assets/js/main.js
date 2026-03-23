/* InPro — main.js */

// ─── Mobile Nav ───
(function () {
  const burger = document.getElementById('burger');
  const mobileNav = document.getElementById('mobileNav');
  if (!burger || !mobileNav) return;
  burger.addEventListener('click', () => {
    const open = mobileNav.classList.toggle('open');
    burger.setAttribute('aria-expanded', open);
  });
  mobileNav.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => mobileNav.classList.remove('open'));
  });
})();

// ─── Active nav link ───
(function () {
  const path = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a, .mobile-nav a').forEach(a => {
    const href = (a.getAttribute('href') || '').split('/').pop();
    if (href === path) a.classList.add('active');
  });
})();

// ─── File Upload UI ───
(function () {
  const area  = document.getElementById('fileUploadArea');
  const input = document.getElementById('fileInput');
  const list  = document.getElementById('fileList');
  if (!area || !input) return;

  area.addEventListener('click', () => input.click());
  area.addEventListener('dragover',  e => { e.preventDefault(); area.classList.add('dragover'); });
  area.addEventListener('dragleave', () => area.classList.remove('dragover'));
  area.addEventListener('drop', e => {
    e.preventDefault(); area.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
  });
  input.addEventListener('change', () => handleFiles(input.files));

  function handleFiles(files) {
    if (!list) return;
    list.innerHTML = '';
    Array.from(files).forEach(f => {
      const li = document.createElement('div');
      li.className = 'file-item';
      li.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="2"/></svg> ${f.name} <span style="color:var(--muted);font-size:11px">(${(f.size/1024).toFixed(0)} KB)</span>`;
      list.appendChild(li);
    });
  }
})();

// ─── Claim Form validation ───
(function () {
  const form = document.getElementById('claimForm');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    let valid = true;
    form.querySelectorAll('[required]').forEach(el => {
      if (!el.value.trim()) {
        el.style.borderColor = '#ef4444';
        valid = false;
        el.addEventListener('input', () => el.style.borderColor = '', { once: true });
      }
    });
    if (!valid) {
      e.preventDefault();
      const first = form.querySelector('[required]');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
})();

// ─── QCL Login (non-functional mock) ───
(function () {
  const loginForm = document.getElementById('qclLoginForm');
  if (!loginForm) return;
  loginForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const btn  = loginForm.querySelector('.btn-login');
    const err  = document.getElementById('loginError');
    const orig = btn.textContent;
    btn.textContent = 'Authenticating…';
    btn.disabled = true;
    if (err) err.style.display = 'none';
    setTimeout(() => {
      btn.textContent = orig;
      btn.disabled = false;
      if (err) err.style.display = 'flex';
    }, 2200);
  });
})();

// ─── QCL Floating Particles ───
(function () {
  const container = document.getElementById('particles');
  if (!container) return;
  for (let i = 0; i < 20; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    const size = Math.random() * 14 + 4;
    p.style.cssText = `width:${size}px;height:${size}px;left:${Math.random()*100}%;animation-duration:${Math.random()*14+10}s;animation-delay:${Math.random()*12}s;`;
    container.appendChild(p);
  }
})();
