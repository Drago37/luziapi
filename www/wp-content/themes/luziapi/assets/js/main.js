(function () {
  'use strict';

  // ----- Hauteur réelle du header -> variable CSS (le hero remplit l'écran sous le header) -----
  var header = document.querySelector('.site-header');
  if (header) {
    var setHeaderH = function () {
      document.documentElement.style.setProperty('--header-h', header.offsetHeight + 'px');
    };
    setHeaderH();
    window.addEventListener('resize', setHeaderH);
  }

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

  // ----- Navigation one-page : points à droite + chevrons entre sections -----
  var navSecs = Array.prototype.slice.call(document.querySelectorAll('section[data-nav]'));
  if (navSecs.length > 1) {
    // Le fond de la section est-il sombre ? (sert à adapter la couleur des repères)
    function sectionIsDark(el) {
      var st = getComputedStyle(el);
      var m = (st.backgroundColor || '').match(/[\d.]+/g);
      if (m && !(m.length >= 4 && parseFloat(m[3]) === 0)) {
        var lum = 0.2126 * +m[0] + 0.7152 * +m[1] + 0.0722 * +m[2];
        return lum < 130;
      }
      // Fond transparent (image / dégradé) : on déduit du texte — texte clair => fond foncé.
      var tc = (st.color || '').match(/[\d.]+/g);
      if (tc) {
        var tlum = 0.2126 * +tc[0] + 0.7152 * +tc[1] + 0.0722 * +tc[2];
        return tlum > 150;
      }
      return false;
    }

    var CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';

    // 1) Points de navigation (à droite)
    var nav = document.createElement('nav');
    nav.className = 'dotnav';
    nav.setAttribute('aria-label', 'Sections de la page');
    var links = [];
    navSecs.forEach(function (sec) {
      var label = sec.getAttribute('data-nav');
      var a = document.createElement('a');
      a.href = '#' + sec.id;
      a.setAttribute('data-label', label);
      a.setAttribute('aria-label', label);
      a.innerHTML = '<span class="dot"></span>';
      nav.appendChild(a);
      links.push(a);
    });
    document.body.appendChild(nav);

    // 2) Chevron « section suivante » (sauf le hero, qui a déjà le sien, et la dernière)
    navSecs.forEach(function (sec, i) {
      if (i >= navSecs.length - 1 || sec.id === 'top') { return; }
      var next = navSecs[i + 1];
      var ch = document.createElement('a');
      ch.className = 'sec-next ' + (sectionIsDark(sec) ? 'on-dark' : 'on-light');
      ch.href = '#' + next.id;
      ch.setAttribute('aria-label', 'Aller à : ' + next.getAttribute('data-nav'));
      ch.innerHTML = CHEV;
      sec.appendChild(ch);
    });

    // 3) Scroll-spy : repère actif + couleur des points adaptée au fond courant
    if ('IntersectionObserver' in window) {
      var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (!en.isIntersecting) { return; }
          links.forEach(function (l) {
            l.classList.toggle('is-active', l.getAttribute('href') === '#' + en.target.id);
          });
          nav.classList.toggle('on-dark', sectionIsDark(en.target));
        });
      }, { rootMargin: '-45% 0px -45% 0px', threshold: 0 });
      navSecs.forEach(function (sec) { spy.observe(sec); });
    }
  }
})();
