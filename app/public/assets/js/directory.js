/* Directory: mappa interattiva dei profili (Leaflet + OpenStreetMap). I dati arrivano da data-points. */
(function () {
  var box = document.getElementById('dir-map');
  if (!box || typeof L === 'undefined') return;
  var pts = [];
  try { pts = JSON.parse(box.getAttribute('data-points') || '[]'); } catch (e) {}
  var base = box.getAttribute('data-profile-base') || '';
  var map = L.map(box, { scrollWheelZoom: false }).setView([42.5, 12.5], 5);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap' }).addTo(map);
  // Con il dito/rotellina la mappa si muove solo dopo un clic: non ruba lo scroll della pagina.
  map.on('click', function () { map.scrollWheelZoom.enable(); });
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var markers = [];
  pts.forEach(function (p) {
    var html = '<b>' + esc(p.city) + ' (' + esc(p.prov) + ')</b><br>' + p.bands.slice(0, 12).map(function (b) {
      return '<a href="' + esc(base + encodeURIComponent(b.slug)) + '">' + esc(b.name) + '</a>';
    }).join('<br>') + (p.bands.length > 12 ? '<br>… e altri ' + (p.bands.length - 12) : '');
    var m = L.marker([p.lat, p.lng], { title: p.city + ' (' + p.bands.length + ')' }).addTo(map).bindPopup(html);
    markers.push(m);
  });
  if (markers.length) {
    var g = L.featureGroup(markers);
    map.fitBounds(g.getBounds().pad(0.2), { maxZoom: 11 });
  }
  // "Vedi sulla mappa" nell'elenco: centra e apre il punto.
  document.querySelectorAll('[data-locate]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.preventDefault();
      var lat = parseFloat(b.getAttribute('data-lat')), lng = parseFloat(b.getAttribute('data-lng'));
      if (isNaN(lat) || isNaN(lng)) return;
      map.setView([lat, lng], 12);
      markers.forEach(function (m) { var ll = m.getLatLng(); if (Math.abs(ll.lat - lat) < 1e-4 && Math.abs(ll.lng - lng) < 1e-4) m.openPopup(); });
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });
  setTimeout(function () { map.invalidateSize(); }, 200);
})();
