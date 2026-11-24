// PDF reports, generated in the browser with jsPDF (loaded on demand).
// The heatmap is drawn onto a canvas: map tiles first (if the tile server allows it), then the rank circles.
(function () {
  const GR = window.GR = window.GR || {};
  const JSPDF_URL = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
  const INK = [15, 23, 42], MUTED = [100, 116, 139], LINE = [226, 232, 240], BRAND = [29, 78, 216];

  const loadScript = src => new Promise((ok, fail) => {
    const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = () => fail(new Error('Could not load the PDF library. Check your connection and try again.'));
    document.head.appendChild(s);
  });
  async function jsPDFCtor() {
    if (!window.jspdf) await loadScript(JSPDF_URL);
    return window.jspdf.jsPDF;
  }
  const hexRgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
  const fmt = (v, d = 2) => v == null ? '-' : Number(v).toFixed(d);
  const loadImg = (src, cors) => new Promise(ok => {
    const im = new Image(); if (cors) im.crossOrigin = 'anonymous';
    const t = setTimeout(() => ok(null), 8000);
    im.onload = () => { clearTimeout(t); ok(im); }; im.onerror = () => { clearTimeout(t); ok(null); };
    im.src = src;
  });

  // ---- heatmap image -------------------------------------------------------
  const project = (lat, lng, z) => {
    const s = 256 * Math.pow(2, z), sin = Math.sin(lat * Math.PI / 180);
    return { x: (lng + 180) / 360 * s, y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s };
  };

  async function heatmap(points, biz, px = 1100, withTiles = true) {
    const lats = points.map(p => p.lat), lngs = points.map(p => p.lng);
    const n = Math.round(Math.sqrt(points.length));
    // pad by ~0.7 grid steps so edge circles aren't clipped
    const padLat = (Math.max(...lats) - Math.min(...lats)) / Math.max(n - 1, 1) * 0.75 || 0.01;
    const padLng = (Math.max(...lngs) - Math.min(...lngs)) / Math.max(n - 1, 1) * 0.75 || 0.01;
    const north = Math.max(...lats) + padLat, south = Math.min(...lats) - padLat;
    const west = Math.min(...lngs) - padLng, east = Math.max(...lngs) + padLng;
    const w0 = project(south, east, 0).x - project(north, west, 0).x;
    const z = Math.max(1, Math.min(18, Math.floor(Math.log2(px / w0))));
    const tl = project(north, west, z), br = project(south, east, z);
    const scale = px / (br.x - tl.x);
    const W = px, H = Math.round((br.y - tl.y) * scale);
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#e9edf2'; ctx.fillRect(0, 0, W, H);
    const toPx = (lat, lng) => { const p = project(lat, lng, z); return { x: (p.x - tl.x) * scale, y: (p.y - tl.y) * scale }; };

    if (withTiles) {
      const tpl = GR.tiles || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
      const jobs = [];
      for (let tx = Math.floor(tl.x / 256); tx <= Math.floor(br.x / 256); tx++)
        for (let ty = Math.floor(tl.y / 256); ty <= Math.floor(br.y / 256); ty++) {
          const url = tpl.replace('{s}', 'abc'[(tx + ty) % 3]).replace('{z}', z).replace('{x}', tx).replace('{y}', ty).replace('{r}', '');
          jobs.push(loadImg(url, true).then(im => im && ctx.drawImage(im, (tx * 256 - tl.x) * scale, (ty * 256 - tl.y) * scale, 256 * scale + 0.5, 256 * scale + 0.5)));
        }
      await Promise.all(jobs);
      ctx.fillStyle = 'rgba(255,255,255,.18)'; ctx.fillRect(0, 0, W, H); // soften the map so circles pop
    }

    // circle size from the distance between neighbouring points
    const byRC = {}; points.forEach(p => byRC[p.r + ':' + p.c] = p);
    const a = byRC['0:0'], b = byRC['0:1'];
    const gap = a && b ? Math.hypot(toPx(a.lat, a.lng).x - toPx(b.lat, b.lng).x, toPx(a.lat, a.lng).y - toPx(b.lat, b.lng).y) : 60;
    const R = Math.max(8, Math.min(42, gap * 0.45));

    if (biz) {
      const q = toPx(biz.lat, biz.lng);
      ctx.fillStyle = '#1d4ed8'; ctx.beginPath(); ctx.arc(q.x, q.y, R * 1.25, 0, 7); ctx.fill();
    }
    points.forEach(p => {
      const q = toPx(p.lat, p.lng);
      let color, label;
      if (p.status === 'error') { color = '#94a3b8'; label = '?'; }
      else if (p.status !== 'done') { color = '#cbd5e1'; label = ''; }
      else { color = GR.rankColor(p.rank); label = p.rank == null ? '20+' : String(p.rank); }
      ctx.save();
      ctx.shadowColor = 'rgba(0,0,0,.28)'; ctx.shadowBlur = R * 0.25; ctx.shadowOffsetY = R * 0.06;
      ctx.fillStyle = color; ctx.beginPath(); ctx.arc(q.x, q.y, R, 0, 7); ctx.fill();
      ctx.restore();
      ctx.lineWidth = Math.max(1.5, R * 0.1); ctx.strokeStyle = 'rgba(255,255,255,.95)'; ctx.stroke();
      ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = `700 ${Math.round(R * (label.length > 2 ? 0.72 : 0.9))}px Helvetica, Arial, sans-serif`;
      ctx.fillText(label, q.x, q.y + R * 0.04);
    });
    if (withTiles) {
      ctx.font = '500 15px Helvetica, Arial, sans-serif'; const t = '© OpenStreetMap contributors';
      const tw = ctx.measureText(t).width; ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.fillRect(W - tw - 16, H - 26, tw + 16, 26);
      ctx.fillStyle = '#475569'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle'; ctx.fillText(t, W - 8, H - 13);
    }
    try { return { data: cv.toDataURL('image/jpeg', 0.9), w: W, h: H }; }
    catch (e) { return withTiles ? heatmap(points, biz, px, false) : null; } // tile server blocked reading pixels: redraw without the map
  }

  // ---- drawing helpers -----------------------------------------------------
  function setFont(doc, size, style = 'normal', color = INK) { doc.setFont('helvetica', style); doc.setFontSize(size); doc.setTextColor(...color); }

  async function header(doc, R, W, M) {
    let x = M;
    if (R.logo) {
      const im = await loadImg(R.logo, false);
      if (im) {
        const h = 26, w = Math.min(160, im.width * h / im.height);
        const c = document.createElement('canvas'); c.width = im.width; c.height = im.height; c.getContext('2d').drawImage(im, 0, 0);
        doc.addImage(c.toDataURL('image/png'), 'PNG', x, M - 4, w, h); x += w + 10;
      }
    }
    setFont(doc, 13, 'bold', BRAND); doc.text(R.app, x, M + 13);
    setFont(doc, 9, 'normal', MUTED); doc.text(R.kind, W - M, M + 13, { align: 'right' });
    doc.setDrawColor(...LINE); doc.setLineWidth(1); doc.line(M, M + 28, W - M, M + 28);
    return M + 50;
  }

  function metrics(doc, x, y, w, m) {
    const items = [
      ['AGR', m.agr, 2, false, 'Avg rank where found'],
      ['ATGR', m.atgr, 2, false, 'Avg rank, all points'],
      ['SoLV', m.solv, 1, true, 'Top-3 share'],
      ['FOUND IN', null, 0, false, 'Points in top 20'],
    ];
    const gap = 8, bw = (w - gap * 3) / 4, bh = 54;
    items.forEach(([label, v, dec, pct, sub], i) => {
      const bx = x + i * (bw + gap);
      doc.setFillColor(248, 250, 252); doc.setDrawColor(...LINE); doc.roundedRect(bx, y, bw, bh, 5, 5, 'FD');
      setFont(doc, 7, 'bold', MUTED); doc.text(label, bx + 9, y + 13);
      setFont(doc, 15, 'bold', INK);
      const val = label === 'FOUND IN' ? `${m.found ?? '-'} / ${m.total}` : (v == null ? '-' : fmt(v, dec) + (pct ? '%' : ''));
      doc.text(val, bx + 9, y + 32);
      setFont(doc, 7, 'normal', MUTED); doc.text(sub, bx + 9, y + 46);
    });
    return y + bh;
  }

  function legend(doc, x, y, center, W) {
    const items = [['1-3', 1], ['4-6', 5], ['7-10', 8], ['11-15', 12], ['16-20', 18], ['20+', null]];
    setFont(doc, 8, 'normal', MUTED);
    const widths = items.map(([l]) => 12 + doc.getTextWidth(l) + 12);
    let cx = center ? (W - widths.reduce((s, v) => s + v, 0)) / 2 : x;
    items.forEach(([l, r], i) => {
      doc.setFillColor(...hexRgb(GR.rankColor(r))); doc.circle(cx + 4, y - 3, 4, 'F');
      doc.text(l, cx + 11, y); cx += widths[i];
    });
  }

  function footer(doc, R, W, H, M) {
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.setDrawColor(...LINE); doc.line(M, H - M + 6, W - M, H - M + 6);
      setFont(doc, 7.5, 'normal', MUTED);
      doc.text(`${R.app} · generated ${R.generated}`, M, H - M + 18);
      doc.text(`Page ${i} of ${n}`, W - M, H - M + 18, { align: 'right' });
    }
  }

  const fileName = (R, suffix) => (`${R.business.name} ${R.keyword} ${suffix}`).replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') + '.pdf';

  // ---- single-scan report --------------------------------------------------
  GR.scanPdf = async function (R) {
    const jsPDF = await jsPDFCtor();
    const doc = new jsPDF({ unit: 'pt', format: 'letter' });
    const W = 612, H = 792, M = 40, CW = W - 2 * M;
    let y = await header(doc, { ...R, kind: 'Local Rank Report' }, W, M);

    setFont(doc, 18, 'bold'); doc.text(R.business.name, M, y); y += 15;
    if (R.business.address) { setFont(doc, 9, 'normal', MUTED); doc.text(R.business.address, M, y); y += 13; }
    setFont(doc, 10, 'normal', INK);
    doc.text(`Keyword: "${R.keyword}"   ·   ${R.date}   ·   ${R.gridLabel}`, M, y + 4); y += 20;

    y = metrics(doc, M, y, CW, R.metrics) + 8;

    const img = await heatmap(R.points, R.business, 1300);
    const maxH = H - M - 34 - y - 10, mw = Math.min(CW, maxH * img.w / img.h), mh = mw * img.h / img.w;
    doc.addImage(img.data, 'JPEG', M + (CW - mw) / 2, y + 6, mw, mh);
    doc.setDrawColor(...LINE); doc.roundedRect(M + (CW - mw) / 2, y + 6, mw, mh, 3, 3, 'S');
    legend(doc, M, y + mh + 24, true, W);

    // page 2: competitors + definitions
    doc.addPage(); y = await header(doc, { ...R, kind: 'Local Rank Report' }, W, M);
    setFont(doc, 13, 'bold'); doc.text('Who ranks across this grid', M, y); y += 8;
    setFont(doc, 8.5, 'normal', MUTED);
    doc.text(`Businesses that appear most often in the top 3 across all ${R.metrics.total} grid points.`, M, y + 8); y += 22;
    const cols = [['Business', M + 6, 'left'], ['Top-3 share', M + 330, 'right'], ['Points in top 20', M + 420, 'right'], ['Avg rank', M + 480, 'right'], ['Rating', W - M - 6, 'right']];
    doc.setFillColor(241, 245, 249); doc.rect(M, y, CW, 20, 'F');
    setFont(doc, 7.5, 'bold', MUTED); cols.forEach(([t, x, al]) => doc.text(t.toUpperCase(), x, y + 13, { align: al }));
    y += 20;
    const rows = [{ title: R.business.name + '  (you)', solv: R.metrics.solv, n: R.metrics.found, avg: R.metrics.agr, rating: null, me: true }, ...R.competitors];
    rows.forEach(c => {
      if (c.me) { doc.setFillColor(232, 239, 255); doc.rect(M, y, CW, 20, 'F'); }
      setFont(doc, 9, c.me ? 'bold' : 'normal', INK);
      const name = doc.splitTextToSize(c.title, 300)[0];
      doc.text(name, cols[0][1], y + 13);
      doc.text(fmt(c.solv, 1) + '%', cols[1][1], y + 13, { align: 'right' });
      doc.text(String(c.n ?? '-'), cols[2][1], y + 13, { align: 'right' });
      doc.text(fmt(c.avg, 1), cols[3][1], y + 13, { align: 'right' });
      if (c.rating) { setFont(doc, 9, 'normal', MUTED); doc.text(`${c.rating} (${c.votes ?? 0})`, cols[4][1], y + 13, { align: 'right' }); }
      doc.setDrawColor(...LINE); doc.line(M, y + 20, W - M, y + 20); y += 20;
    });

    y += 28; setFont(doc, 13, 'bold'); doc.text('What the numbers mean', M, y); y += 16;
    const defs = [
      ['AGR (Average Grid Rank)', 'The average map-pack position across the grid points where the business appears in the top 20.'],
      ['ATGR (Average Total Grid Rank)', 'The average position across every grid point, counting "not in the top 20" as 21. The best single measure of overall visibility.'],
      ['SoLV (Share of Local Voice)', 'The percentage of grid points where the business ranks 1-3, the positions that show in the Google local pack.'],
      ['How the scan works', `Google Maps results change with the searcher's location. We searched "${R.keyword}" from each of the ${R.metrics.total} points on a ${R.gridLabel.replace(' · ', ', ')} and recorded where the business ranked.`],
    ];
    defs.forEach(([t, d]) => {
      setFont(doc, 9.5, 'bold'); doc.text(t, M, y); y += 12;
      setFont(doc, 9, 'normal', [51, 65, 85]); const lines = doc.splitTextToSize(d, CW); doc.text(lines, M, y); y += lines.length * 11 + 9;
    });
    footer(doc, R, W, H, M);
    doc.save(fileName(R, R.dateShort));
  };

  // Button helper: shows progress and errors on the button itself.
  GR.pdfButton = (btn, fn) => btn.addEventListener('click', async () => {
    const label = btn.textContent; btn.disabled = true; btn.textContent = 'Building PDF…';
    try { await fn(); btn.textContent = label; }
    catch (e) { console.error(e); alert('Could not create the PDF: ' + e.message); btn.textContent = label; }
    finally { btn.disabled = false; }
  });
})();
