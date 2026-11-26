/* Studio · Cruscotto: grafici in SVG/DOM senza librerie. Ogni elemento [data-chart] legge i dati da data-config (JSON). */
(function () {
  'use strict';
  var COL = { accent: '#7C5CFF', info: '#3B82F6', ok: '#22A06B', warn: '#F59E0B', bad: '#E5484D', mute: '#D6DAE3', pink: '#EC4899', teal: '#0D9488' };
  var NS = 'http://www.w3.org/2000/svg';
  function svg(tag, attrs, parent) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]); }
    if (parent) parent.appendChild(e);
    return e;
  }
  function el(tag, cls, text, parent) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    if (parent) parent.appendChild(e);
    return e;
  }
  function color(c) { return COL[c] || c || COL.mute; }
  function legend(host, items) {
    var ul = el('ul', 'crx-legend', null, host);
    items.forEach(function (it) {
      var li = el('li', '', null, ul);
      var dot = el('i', 'crx-lg', null, li); dot.style.background = color(it.color);
      el('span', '', it.label, li);
      if (it.value !== undefined) el('b', '', String(it.value), li);
    });
  }

  function donut(host, d) {
    var S = 180, R = 70, W = 20, C = 2 * Math.PI * R, total = 0, acc = 0;
    d.segments.forEach(function (s) { total += s.value; });
    var wrap = el('div', 'crx-donut', null, host);
    var s = svg('svg', { viewBox: '0 0 ' + S + ' ' + S, role: 'img', 'aria-label': (d.center || '') + ' ' + (d.sub || '') }, wrap);
    svg('circle', { cx: S / 2, cy: S / 2, r: R, fill: 'none', stroke: '#EEF0F5', 'stroke-width': W }, s);
    var segs = [];
    if (total > 0) {
      d.segments.forEach(function (g) {
        if (g.value <= 0) return;
        var len = g.value / total * C;
        var c = svg('circle', { cx: S / 2, cy: S / 2, r: R, fill: 'none', stroke: color(g.color), 'stroke-width': W,
          'stroke-dasharray': '0 ' + C, 'stroke-dashoffset': -acc, transform: 'rotate(-90 ' + S / 2 + ' ' + S / 2 + ')' }, s);
        svg('title', {}, c).textContent = g.label + ': ' + g.value;
        segs.push([c, len]);
        acc += len;
      });
    }
    var t1 = svg('text', { x: S / 2, y: total > 0 || d.center !== '0' ? S / 2 + 4 : S / 2 - 2, 'text-anchor': 'middle', 'class': 'crx-big' }, s);
    t1.textContent = d.center;
    var t2 = svg('text', { x: S / 2, y: S / 2 + 24, 'text-anchor': 'middle', 'class': 'crx-small' }, s);
    t2.textContent = total > 0 || d.center !== '0' ? d.sub : d.empty;
    requestAnimationFrame(function () {
      segs.forEach(function (p) { p[0].style.transition = 'stroke-dasharray .9s ease'; p[0].setAttribute('stroke-dasharray', Math.max(0, p[1] - 2) + ' ' + C); });
    });
    legend(host, d.segments);
  }

  function axes(s, L, T, W, H, max, labels, n) {
    var steps = 4;
    for (var i = 0; i <= steps; i++) {
      var y = T + H - H * i / steps;
      svg('line', { x1: L, x2: L + W, y1: y, y2: y, stroke: '#EEF0F5' }, s);
      var tx = svg('text', { x: L - 6, y: y + 3, 'text-anchor': 'end', 'class': 'crx-ax' }, s);
      tx.textContent = String(Math.round(max * i / steps));
    }
    labels.forEach(function (lb, i) {
      if (n > 8 && i % 2 === 1) return;
      var x = L + W * (i + .5) / n;
      var t = svg('text', { x: x, y: T + H + 16, 'text-anchor': 'middle', 'class': 'crx-ax' }, s);
      t.textContent = lb;
    });
  }
  function niceMax(m) { return Math.max(4, Math.ceil(m / 4) * 4); }

  function stack(host, d) {
    var n = d.labels.length, L = 34, T = 8, W = 600, H = 170, tot = [];
    var max = 0;
    for (var i = 0; i < n; i++) { var sum = 0; d.series.forEach(function (s) { sum += s.values[i] || 0; }); tot.push(sum); if (sum > max) max = sum; }
    max = niceMax(max);
    var s = svg('svg', { viewBox: '0 0 ' + (L + W + 6) + ' ' + (T + H + 26), role: 'img' }, el('div', 'crx-svg', null, host));
    axes(s, L, T, W, H, max, d.labels, n);
    var bw = Math.min(26, W / n * .6);
    for (var k = 0; k < n; k++) {
      var y = T + H, x = L + W * (k + .5) / n - bw / 2;
      d.series.forEach(function (se) {
        var v = se.values[k] || 0; if (!v) return;
        var h = v / max * H; y -= h;
        var r = svg('rect', { x: x, y: y, width: bw, height: h, rx: 3, fill: color(se.color) }, s);
        svg('title', {}, r).textContent = d.labels[k] + ' · ' + se.name + ': ' + v;
      });
    }
    legend(host, d.series.map(function (se) { var t = 0; se.values.forEach(function (v) { t += v; }); return { label: se.name, color: se.color, value: t }; }));
  }

  function line(host, d) {
    var n = d.labels.length, L = 34, T = 8, W = 600, H = 170, max = 0;
    d.series.forEach(function (se) { se.values.forEach(function (v) { if (v > max) max = v; }); });
    max = niceMax(max);
    var s = svg('svg', { viewBox: '0 0 ' + (L + W + 6) + ' ' + (T + H + 26), role: 'img' }, el('div', 'crx-svg', null, host));
    axes(s, L, T, W, H, max, d.labels, n);
    d.series.forEach(function (se, idx) {
      var pts = se.values.map(function (v, i) { return [L + W * (i + .5) / n, T + H - v / max * H]; });
      if (idx === 0 && pts.length) {
        svg('polygon', { points: pts.map(function (p) { return p.join(','); }).join(' ') + ' ' + pts[pts.length - 1][0] + ',' + (T + H) + ' ' + pts[0][0] + ',' + (T + H), fill: color(se.color), opacity: .12 }, s);
      }
      svg('polyline', { points: pts.map(function (p) { return p.join(','); }).join(' '), fill: 'none', stroke: color(se.color), 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, s);
      pts.forEach(function (p, i) {
        var c = svg('circle', { cx: p[0], cy: p[1], r: 3.5, fill: '#fff', stroke: color(se.color), 'stroke-width': 2 }, s);
        svg('title', {}, c).textContent = d.labels[i] + ' · ' + se.name + ': ' + se.values[i];
      });
    });
    legend(host, d.series.map(function (se) { var t = 0; se.values.forEach(function (v) { t += v; }); return { label: se.name, color: se.color, value: t }; }));
  }

  function hbars(host, d) {
    var max = 0; d.items.forEach(function (i) { if (i.value > max) max = i.value; });
    var ul = el('ul', 'crx-hbars', null, host);
    d.items.forEach(function (it) {
      var li = el('li', '', null, ul), a = el('a', '', null, li); a.href = it.url || '#';
      el('span', 'nm', it.label, a);
      var tr = el('span', 'tr', null, a), fill = el('i', '', null, tr);
      fill.style.background = color(it.color);
      requestAnimationFrame(function () { fill.style.width = (max ? Math.max(4, it.value / max * 100) : 0) + '%'; });
      el('b', '', String(it.value), a);
    });
  }

  var TYPES = { donut: donut, stack: stack, line: line, hbars: hbars };
  document.querySelectorAll('[data-chart]').forEach(function (host) {
    var fn = TYPES[host.getAttribute('data-chart')], cfg;
    try { cfg = JSON.parse(host.getAttribute('data-config') || '{}'); } catch (e) { return; }
    if (fn) fn(host, cfg);
  });
})();
