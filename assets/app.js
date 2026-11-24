// Shared map helpers. Requires Leaflet on pages that draw maps.
(function () {
  const GR = window.GR = window.GR || {};

  GR.rankColor = r => r == null ? '#9b1c1c' : r <= 3 ? '#16a34a' : r <= 6 ? '#65b32e' : r <= 10 ? '#eab308' : r <= 15 ? '#f08a24' : '#e2462b';
  GR.esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  GR.makeMap = (id, center, zoom) => {
    const map = L.map(id, { scrollWheelZoom: true, zoomSnap: 0.25 }).setView(center, zoom);
    L.tileLayer(GR.tiles || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);
    return map;
  };

  // Same math as lib/grid.php so the preview matches what gets scanned.
  GR.grid = (lat, lng, n, radius) => {
    const pts = [], half = (n - 1) / 2, step = n > 1 ? (2 * radius) / (n - 1) : 0, mlng = 69.172 * Math.cos(lat * Math.PI / 180);
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++)
      pts.push({ r, c, lat: lat + ((half - r) * step) / 69.0, lng: lng + ((c - half) * step) / mlng });
    return pts;
  };

  GR.bizIcon = () => L.divIcon({ className: 'biz-pin', html: '<span></span>', iconSize: [0, 0] });
  GR.centerIcon = () => L.divIcon({ className: 'center-pin', html: '<span></span>', iconSize: [0, 0] });

  function dotHtml(p) {
    let cls = 'dot', label, bg;
    if (p.status === 'pending' || p.status === 'posted') { cls += ' dot-wait'; label = ''; bg = '#cbd5e1'; }
    else if (p.status === 'error') { cls += ' dot-err'; label = '?'; bg = '#94a3b8'; }
    else { label = p.rank == null ? '20+' : p.rank; bg = GR.rankColor(p.rank); if (p.rank == null) cls += ' dot-miss'; }
    return `<div class="${cls}" style="background:${bg}">${label}</div>`;
  }

  // Draws rank circles; sizes them to the grid spacing at the current zoom.
  GR.renderGrid = (map, points, opts = {}) => {
    const layer = L.layerGroup().addTo(map), markers = {};
    let selected = null;
    function place(p) {
      const m = L.marker([p.lat, p.lng], { icon: L.divIcon({ className: 'dot-wrap', html: dotHtml(p), iconSize: [0, 0] }), keyboard: false });
      if (opts.onClick) m.on('click', () => {
        if (p.status !== 'done' && p.status !== 'error') return;
        if (selected) selected.getElement()?.classList.remove('sel');
        selected = m; m.getElement()?.classList.add('sel');
        opts.onClick(p);
      });
      m.addTo(layer); markers[p.id] = { m, p };
    }
    points.forEach(place);
    const bounds = L.latLngBounds(points.map(p => [p.lat, p.lng]));
    function resize() {
      if (points.length < 2) return;
      const n = opts.size || Math.round(Math.sqrt(points.length));
      const a = map.latLngToContainerPoint([points[0].lat, points[0].lng]);
      const b = map.latLngToContainerPoint([points[Math.min(1, points.length - 1)].lat, points[Math.min(1, points.length - 1)].lng]);
      const gap = n > 1 ? Math.hypot(a.x - b.x, a.y - b.y) : 30;
      const size = Math.max(14, Math.min(36, gap * 0.92));
      map.getContainer().style.setProperty('--dot', size + 'px');
    }
    map.on('zoomend', resize); map.whenReady(resize); setTimeout(resize, 0);
    return {
      bounds,
      update(newPoints) {
        newPoints.forEach(np => {
          const cur = markers[np.id];
          if (!cur) return place(np);
          if (cur.p.status === np.status && cur.p.rank === np.rank) return;
          cur.p = np; cur.m.setIcon(L.divIcon({ className: 'dot-wrap', html: dotHtml(np), iconSize: [0, 0] }));
          cur.m.off('click');
          if (opts.onClick) cur.m.on('click', () => opts.onClick(np));
        });
      }
    };
  };

  GR.showPoint = async (p, panelId) => {
    const el = document.getElementById(panelId);
    el.innerHTML = '<h3>Results at a grid point</h3><p class="muted small">Loading…</p>';
    try {
      const d = await (await fetch(`api.php?action=point&id=${p.id}`)).json();
      if (d.error && !d.items) throw new Error(d.error);
      const head = `<h3>Grid point ${p.r + 1}, ${p.c + 1}</h3>
        <p class="small">Your rank here: <b style="color:${GR.rankColor(d.rank)}">${d.status === 'error' ? 'error' : (d.rank ?? '20+')}</b>
        · <a href="https://www.google.com/maps/search/?api=1&query=${d.lat},${d.lng}" target="_blank" rel="noopener">${d.lat.toFixed(4)}, ${d.lng.toFixed(4)}</a></p>`;
      if (d.status === 'error') { el.innerHTML = head + `<p class="small warn-text">${GR.esc(d.error)}</p>`; return; }
      const list = (d.items || []).map(it => `<li class="${it.self ? 'me' : ''}"><span class="rk" style="background:${GR.rankColor(it.rank)}">${it.rank}</span>
        <span class="nm">${GR.esc(it.title)}</span>${it.rating ? `<span class="rt">★ ${GR.esc(it.rating)}</span>` : ''}</li>`).join('');
      el.innerHTML = head + (list ? `<ol class="results">${list}</ol>` : '<p class="muted small">No map results returned at this point.</p>');
    } catch (e) {
      el.innerHTML = `<h3>Results at a grid point</h3><p class="small warn-text">${GR.esc(e.message)}</p>`;
    }
  };

  GR.copy = (id, btn) => {
    const el = document.getElementById(id); el.select();
    (navigator.clipboard ? navigator.clipboard.writeText(el.value) : Promise.resolve(document.execCommand('copy')))
      .then(() => { const t = btn.textContent; btn.textContent = 'Copied'; setTimeout(() => btn.textContent = t, 1200); });
  };
})();
