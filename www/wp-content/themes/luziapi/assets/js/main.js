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
})();
