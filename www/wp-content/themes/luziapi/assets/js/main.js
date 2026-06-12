(function () {
  'use strict';

  // ----- Menu mobile -----
  var burger = document.querySelector('.burger');
  var menu = document.querySelector('.menu');
  if (burger && menu) {
    burger.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        menu.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // ----- Carte Leaflet / OpenStreetMap -----
  var mapEl = document.getElementById('map');
  if (mapEl && window.L && window.LUZIAPI_MAP) {
    try {
      var cfg = window.LUZIAPI_MAP;
      var map = L.map('map', { scrollWheelZoom: false }).setView([cfg.lat, cfg.lng], 14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
      }).addTo(map);
      L.marker([cfg.lat, cfg.lng]).addTo(map).bindPopup(cfg.label).openPopup();
    } catch (e) {
      mapEl.innerHTML = '<div style="height:440px;display:flex;align-items:center;justify-content:center;color:#866a48">Carte indisponible</div>';
    }
  }

  // ----- Carte « zone d'intervention essaims » : cercle de 15 km -----
  var swarmEl = document.getElementById('essaim-map');
  if (swarmEl && window.L && window.LUZIAPI_MAP) {
    try {
      var s = window.LUZIAPI_MAP;
      var smap = L.map('essaim-map', { scrollWheelZoom: false }).setView([s.lat, s.lng], 10);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
      }).addTo(smap);
      var ring = L.circle([s.lat, s.lng], {
        radius: s.radius || 15000,
        color: '#d33a2c', weight: 2, opacity: .9,
        fillColor: '#d33a2c', fillOpacity: .10
      }).addTo(smap);
      L.marker([s.lat, s.lng]).addTo(smap).bindPopup(s.label);
      smap.fitBounds(ring.getBounds(), { padding: [24, 24] });
    } catch (e) {
      swarmEl.innerHTML = '<div style="height:420px;display:flex;align-items:center;justify-content:center;color:#866a48">Carte indisponible</div>';
    }
  }
})();
