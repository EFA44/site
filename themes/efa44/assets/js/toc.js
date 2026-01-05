document.addEventListener('DOMContentLoaded', () => {
  const toc = document.getElementById('TableOfContents');
  if (!toc) return;

  // Only target nested ULs (assumed to correspond to H3 groups)
  const nestedUls = Array.from(toc.querySelectorAll('ul ul'));
  const ulTargets = new Map(); // ul -> [heading elements]
  const targetToUls = new Map(); // heading -> [uls]
  const targetState = new Map(); // heading -> isIntersecting boolean

  // Scroll offset used by the scroll-spy. You can override with CSS variable `--toc-offset`.
  const OFFSET = (() => {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--toc-offset');
    const n = parseInt(v, 10);
    return Number.isFinite(n) ? n : 120;
  })();

  // helpers to expand/collapse with smooth max-height transition
  function collapseAllExcept(keep = []) {
    const keepSet = new Set(Array.isArray(keep) ? keep : [keep]);
    ulTargets.forEach((_, otherUl) => {
      if (!keepSet.has(otherUl)) collapse(otherUl);
    });
  }

  function expand(ul) {
    // collapse other groups so only one H3 group is visible at a time
    collapseAllExcept(ul);

    if (ul.classList.contains('expanded')) return;
    ul.classList.add('expanded');
    ul.setAttribute('aria-expanded', 'true');
  }

  function collapse(ul) {
    if (!ul.classList.contains('expanded')) return;
    ul.classList.remove('expanded');
    ul.setAttribute('aria-expanded', 'false');
  }

  // Build maps: only include targets that are H3 (user requested to collapse H3 groups)
  nestedUls.forEach(ul => {
    const anchors = Array.from(ul.querySelectorAll('a[href^="#"]'));
    const targets = anchors.map(a => {
      const id = decodeURIComponent(a.getAttribute('href').slice(1));
      try {
        return document.getElementById(id);
      } catch (e) {
        return null;
      }
    }).filter(Boolean).filter(el => el.tagName === 'H3');

    if (targets.length > 0) {
      ulTargets.set(ul, targets);
      targets.forEach(t => {
        const arr = targetToUls.get(t) || [];
        arr.push(ul);
        targetToUls.set(t, arr);
      });
      // initialize collapsed (CSS controls visibility)
      ul.classList.remove('expanded');
      ul.setAttribute('aria-expanded', 'false');
    }
  });

  // If there are no H3 targets, do nothing more
  if (ulTargets.size === 0) return;

  // IntersectionObserver to track headings in viewport
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      targetState.set(entry.target, entry.isIntersecting);
      const affectedUls = targetToUls.get(entry.target) || [];
      affectedUls.forEach(ul => {
        const anyActive = ulTargets.get(ul).some(t => targetState.get(t));
        if (anyActive) expand(ul); else collapse(ul);
      });
    });
  }, { root: null, rootMargin: '-30% 0% -30% 0%', threshold: 0.25 });

  // Observe all targets
  ulTargets.forEach((targets, ul) => {
    targets.forEach(t => observer.observe(t));
    // initial state: expand if any target is already roughly in viewport
    const initialActive = targets.some(t => {
      const r = t.getBoundingClientRect();
      return r.top < window.innerHeight * 0.75 && r.bottom > window.innerHeight * 0.25;
    });
    if (initialActive) expand(ul);
  });

  // Expand group if URL hash matches a target on load or when it changes
  function expandForHash(hash) {
    if (!hash) return;
    const id = decodeURIComponent(hash.slice(1));
    const target = document.getElementById(id);
    if (!target) return;
    const uls = targetToUls.get(target) || [];
    uls.forEach(ul => expand(ul));
  }
  if (location.hash) expandForHash(location.hash);
  window.addEventListener('hashchange', () => expandForHash(location.hash));

  // Clicking a TOC link should ensure its parent group is expanded
  toc.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;
    const parentUl = a.closest('ul');
    if (parentUl && parentUl !== toc.querySelector('ul')) expand(parentUl);
  });

  window.addEventListener('resize', () => {
    // layout changed; rebuild anchors and refresh active state
    buildAnchors();
    updateActive();
  });

  // -------- Simple, concise scroll-spy --------
  let tocAnchors = []
  let entries = []

  function buildAnchors() {
    tocAnchors = Array.from(toc.querySelectorAll('a[href^="#"]'))
    entries = tocAnchors.map(a => {
      const id = decodeURIComponent(a.getAttribute('href').slice(1))
      const el = document.getElementById(id)
      return { a, el }
    }).filter(x => x.el)
  }

  buildAnchors()

  let raf = null
  function onScroll() {
    if (raf) return
    raf = requestAnimationFrame(() => {
      updateActive()
      raf = null
    })
  }

  window.addEventListener('scroll', onScroll, { passive: true })

  function updateActive() {
    if (!entries.length) return

    const pos = window.scrollY + OFFSET

    // choose the last heading whose top is <= pos
    let active = null
    for (let i = 0; i < entries.length; i++) {
      const top = entries[i].el.getBoundingClientRect().top + window.scrollY
      if (top <= pos) active = entries[i]
      else break
    }

    // If nothing matched, and we're above the first heading, clear active state
    if (!active) {
      const firstTop = entries[0].el.getBoundingClientRect().top + window.scrollY
      if (firstTop > pos) {
        tocAnchors.forEach(a => { a.classList.remove('active'); a.removeAttribute('aria-current') })
        return
      } else {
        active = entries[0]
      }
    }

    // update classes and accessibility attribute
    tocAnchors.forEach(a => { a.classList.remove('active'); a.removeAttribute('aria-current') })
    if (active) {
      active.a.classList.add('active')
      active.a.setAttribute('aria-current', 'true')
      // make sure parent group is visible
      const parentUl = active.a.closest('ul')
      if (parentUl && parentUl !== toc.querySelector('ul')) expand(parentUl)
    }
  }

  // initial run and event hooks
  updateActive()
  window.addEventListener('hashchange', () => { expandForHash(location.hash); updateActive() })

  toc.addEventListener('click', (e) => {
    const a = e.target.closest('a[href^="#"]')
    if (a) {
      tocAnchors.forEach(x => { x.classList.remove('active'); x.removeAttribute('aria-current') })
      a.classList.add('active')
      a.setAttribute('aria-current', 'true')
      const parentUl = a.closest('ul')
      if (parentUl && parentUl !== toc.querySelector('ul')) expand(parentUl)
    }
  })

  window.addEventListener('load', () => {
    buildAnchors()
    updateActive()
  })

});