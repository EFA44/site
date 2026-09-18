document.addEventListener('DOMContentLoaded', () => {
  const toc = document.getElementById('TableOfContents');
  if (!toc) return;

  const rootUl = toc.querySelector('ul');
  const nestedUls = Array.from(toc.querySelectorAll('ul ul'));

  // Décalage du scroll-spy (hauteur des en-têtes fixes). Surchargé via --toc-offset.
  const OFFSET = (() => {
    const n = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--toc-offset'), 10);
    return Number.isFinite(n) ? n : 120;
  })();

  // Un seul groupe ouvert à la fois.
  function expand(ul) {
    if (!ul || ul === rootUl) return;
    nestedUls.forEach(other => { if (other !== ul) collapse(other); });
    ul.classList.add('expanded');
    ul.setAttribute('aria-expanded', 'true');
  }
  function collapse(ul) {
    ul.classList.remove('expanded');
    ul.setAttribute('aria-expanded', 'false');
  }
  nestedUls.forEach(collapse);

  // --- Scroll-spy : ancre active = dernier titre au-dessus de la position. ---
  let entries = [];
  function buildAnchors() {
    entries = Array.from(toc.querySelectorAll('a[href^="#"]')).map(a => {
      const el = document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)));
      return el ? { a, el } : null;
    }).filter(Boolean);
  }

  function updateActive() {
    if (!entries.length) return;
    const pos = window.scrollY + OFFSET;
    let active = null;
    for (const entry of entries) {
      if (entry.el.getBoundingClientRect().top + window.scrollY <= pos) active = entry;
      else break;
    }
    entries.forEach(({ a }) => { a.classList.remove('active'); a.removeAttribute('aria-current'); });
    if (!active) return;
    active.a.classList.add('active');
    active.a.setAttribute('aria-current', 'true');
    expand(active.a.closest('ul'));
  }

  function expandForHash(hash) {
    if (!hash) return;
    const target = document.getElementById(decodeURIComponent(hash.slice(1)));
    if (!target) return;
    const link = toc.querySelector('a[href="#' + CSS.escape(decodeURIComponent(hash.slice(1))) + '"]');
    if (link) expand(link.closest('ul'));
  }

  let raf = null;
  window.addEventListener('scroll', () => {
    if (raf) return;
    raf = requestAnimationFrame(() => { updateActive(); raf = null; });
  }, { passive: true });

  window.addEventListener('resize', () => { buildAnchors(); updateActive(); });
  window.addEventListener('hashchange', () => { expandForHash(location.hash); updateActive(); });

  toc.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (a) expand(a.closest('ul'));
  });

  buildAnchors();
  if (location.hash) expandForHash(location.hash);
  updateActive();
});
