(function () {
  var root = document.querySelector('[data-calendar]');
  var dataEl = document.querySelector('[data-cal-data]');
  if (!root || !dataEl) return;

  var events = [];
  try { events = JSON.parse(dataEl.textContent) || []; } catch (e) { events = []; }

  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  var WEEKDAYS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function key(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }

  // Index événements par jour, en développant les événements multi-jours.
  var byDay = {};
  events.forEach(function (ev) {
    var s = new Date(ev.start + 'T00:00:00');
    var e = new Date((ev.end || ev.start) + 'T00:00:00');
    if (isNaN(s) || isNaN(e) || e < s) e = s;
    ev.multi = ev.start !== ev.end;
    for (var d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
      var k = key(d.getFullYear(), d.getMonth(), d.getDate());
      (byDay[k] = byDay[k] || []).push(ev);
    }
  });

  var monthsEl = root.querySelector('[data-cal-months]');
  var rangeEl = root.querySelector('[data-cal-range]');
  var popover = root.querySelector('[data-cal-popover]');
  var today = root.getAttribute('data-today');

  // Vue de départ : mois courant (colonne de gauche) + suivant.
  var start = today ? new Date(today + 'T00:00:00') : new Date();
  var viewY = start.getFullYear();
  var viewM = start.getMonth();

  function buildMonth(y, m) {
    var wrap = document.createElement('div');
    wrap.className = 'calendar__month';
    var title = document.createElement('h3');
    title.className = 'calendar__title';
    title.textContent = MONTHS[m] + ' ' + y;
    wrap.appendChild(title);

    var grid = document.createElement('div');
    grid.className = 'calendar__grid';
    WEEKDAYS.forEach(function (w) {
      var h = document.createElement('div');
      h.className = 'calendar__weekday';
      h.textContent = w;
      grid.appendChild(h);
    });

    var offset = (new Date(y, m, 1).getDay() + 6) % 7; // lundi = 0
    for (var i = 0; i < offset; i++) {
      var blank = document.createElement('div');
      blank.className = 'calendar__cell calendar__cell--empty';
      grid.appendChild(blank);
    }

    var ndays = new Date(y, m + 1, 0).getDate();
    for (var d = 1; d <= ndays; d++) {
      var k = key(y, m, d);
      var dayEvents = byDay[k];
      var cell;
      if (dayEvents && dayEvents.length) {
        cell = document.createElement('button');
        cell.type = 'button';
        var hasAg = dayEvents.some(function (e) { return e.type === 'ag'; });
        var hasSingle = dayEvents.some(function (e) { return !e.multi; });
        var variant = hasAg ? ' calendar__cell--ag' : (hasSingle ? '' : ' calendar__cell--multiday');
        if (today && k < today) variant += ' calendar__cell--past';
        cell.className = 'calendar__cell calendar__cell--event' + variant;
        cell.setAttribute('data-day', k);
        cell.setAttribute('aria-label', d + ' : ' + dayEvents.length + ' événement(s)');
        cell.textContent = d;
        if (dayEvents.length > 1) {
          var badge = document.createElement('span');
          badge.className = 'calendar__count';
          badge.textContent = dayEvents.length;
          cell.appendChild(badge);
        }
      } else {
        cell = document.createElement('div');
        cell.className = 'calendar__cell';
        cell.textContent = d;
      }
      if (k === today) cell.className += ' calendar__cell--today';
      grid.appendChild(cell);
    }
    wrap.appendChild(grid);
    return wrap;
  }

  var mq = window.matchMedia('(max-width: 640px)');
  function monthCount() { return mq.matches ? 1 : 2; }

  function render() {
    hidePopover();
    monthsEl.innerHTML = '';
    var n = monthCount(), lastY = viewY, lastM = viewM;
    for (var i = 0; i < n; i++) {
      var y = viewY, m = viewM + i;
      while (m > 11) { m -= 12; y++; }
      monthsEl.appendChild(buildMonth(y, m));
      lastY = y; lastM = m;
    }
    rangeEl.textContent = n === 1
      ? MONTHS[viewM] + ' ' + viewY
      : MONTHS[viewM] + ' – ' + MONTHS[lastM] + ' ' + lastY;
  }

  function shift(delta) {
    viewM += delta;
    while (viewM > 11) { viewM -= 12; viewY++; }
    while (viewM < 0) { viewM += 12; viewY--; }
    render();
  }

  function hidePopover() { popover.hidden = true; popover.innerHTML = ''; }

  function showPopover(cell) {
    var list = byDay[cell.getAttribute('data-day')] || [];
    popover.innerHTML = '';
    list.forEach(function (ev) {
      var a = document.createElement('a');
      a.href = ev.url;
      a.className = 'calendar__popover-link' + (ev.type === 'ag' ? ' calendar__popover-link--ag' : (ev.multi ? ' calendar__popover-link--multiday' : ''));
      a.textContent = ev.title;
      popover.appendChild(a);
    });
    popover.hidden = false;
    // Positionne sous la cellule, dans le repère du .calendar.
    var cb = cell.getBoundingClientRect();
    var rb = root.getBoundingClientRect();
    popover.style.left = (cb.left - rb.left) + 'px';
    popover.style.top = (cb.bottom - rb.top + 4) + 'px';
  }

  root.querySelector('[data-cal-prev]').addEventListener('click', function () { shift(-monthCount()); });
  root.querySelector('[data-cal-next]').addEventListener('click', function () { shift(monthCount()); });
  mq.addEventListener('change', render);

  monthsEl.addEventListener('click', function (e) {
    var cell = e.target.closest('.calendar__cell--event');
    if (cell) { showPopover(cell); e.stopPropagation(); }
  });
  document.addEventListener('click', function (e) {
    if (!popover.hidden && !popover.contains(e.target)) hidePopover();
  });

  render();
})();
