"use strict";

const REPO = "https://github.com/ardelato/ioi-puzzle-finder";

/* Lucide icons (lucide.dev, ISC licence). */
const ICONS = {
  download: '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
  github: '<path d="M15 22v-4a4.8 4.8 0 0 0-1-3.5c3 0 6-2 6-5.5.08-1.25-.27-2.48-1-3.5.28-1.15.28-2.35 0-3.5 0 0-1 0-3 1.5-2.64-.5-5.36-.5-8 0C6 2 5 2 5 2c-.3 1.15-.3 2.35 0 3.5A5.403 5.403 0 0 0 4 9c0 3.5 3 5.5 6 5.5-.39.49-.68 1.05-.85 1.65-.17.6-.22 1.23-.15 1.85v4"/><path d="M9 18c-4.51 2-5-2-7-2"/>',
  radar: '<path d="M19.07 4.93A10 10 0 0 0 6.99 3.34"/><path d="M4 6h.01"/><path d="M2.29 9.62A10 10 0 1 0 21.31 8.35"/><path d="M16.24 7.76A6 6 0 1 0 8.23 16.67"/><path d="M12 18h.01"/><path d="M17.99 11.66A6 6 0 0 1 15.77 16.67"/><circle cx="12" cy="12" r="2"/><path d="m13.41 10.59 5.66-5.66"/>',
  image: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
  gamepad: '<line x1="6" x2="10" y1="12" y2="12"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="15" x2="15.01" y1="13" y2="13"/><line x1="18" x2="18.01" y1="11" y2="11"/><rect width="20" height="12" x="2" y="6" rx="2"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
  question: '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  flask: '<path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M6.453 15h11.094"/><path d="M8.5 2h7"/>',
  gauge: '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
  shuffle: '<path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.3 1.7l.4.6"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/>',
  filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
  check: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
  compass: '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
  updown: '<path d="m21 16-4 4-4-4"/><path d="M17 20V4"/><path d="m3 8 4-4 4 4"/><path d="M7 4v16"/>',
  copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
  layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
  sparkles: '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
  bot: '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>',
  folder: '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
  pin: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
  code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
  refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
  pkg: '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.703 4.734a2 2 0 0 0 1.994 0L20.7 7"/>',
  rotate: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
};
const ic = name => `<svg class="i" viewBox="0 0 24 24" aria-hidden="true">${ICONS[name]}</svg>`;

/* Puzzle types and counts as the game's Puzzles tab shows them for Verdant Glen. */
const TYPES = [
  { id: "cube", name: "Hidden Cube", solved: 42, total: 61, color: "var(--cube)", shape: "square" },
  { id: "ring", name: "Hidden Ring", solved: 28, total: 117, color: "var(--ring)", shape: "ring" },
  { id: "grid", name: "Logic Grid", solved: 124, total: 214, color: "var(--grid)", shape: "diamond" },
];

function mark(shape, x, y, color, s = 7) {
  if (shape === "square") return `<rect x="${x - s}" y="${y - s}" width="${s * 2}" height="${s * 2}" rx="3" fill="${color}"/>`;
  if (shape === "ring") return `<circle cx="${x}" cy="${y}" r="${s - 1}" fill="none" stroke="${color}" stroke-width="3.2"/>`;
  return `<rect x="${x - s * .8}" y="${y - s * .8}" width="${s * 1.6}" height="${s * 1.6}" rx="2" fill="${color}" transform="rotate(45 ${x} ${y})"/>`;
}
const swatch = t => `<svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true">${mark(t.shape, 8, 8, t.color, 5.5)}</svg>`;

/* The compass's own shapes: a disc with edge ticks, a you-arrow, height chevrons. */
function hudDisc(c, r, cross = true) {
  const t = 9;
  return `<circle cx="${c}" cy="${c}" r="${r}" fill="var(--hud-disc)" stroke="var(--hud-ring)" stroke-width="1.5"/>
    ${cross ? `<path d="M${c} ${c - r} V${c + r} M${c - r} ${c} H${c + r}" stroke="var(--ink)" stroke-opacity=".1"/>` : ""}
    <path d="M${c} ${c - r} v${t} M${c} ${c + r} v-${t} M${c - r} ${c} h${t} M${c + r} ${c} h-${t}" stroke="var(--hud-tick)" stroke-width="2.5"/>`;
}
const hudChevron = (x, y, up, s = 5) => `<path d="${up ? `M${x - s} ${y + s / 2} l${s} -${s} l${s} ${s}` : `M${x - s} ${y - s / 2} l${s} ${s} l${s} -${s}`}" fill="none" stroke="var(--ink)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>`;
const hudYou = (x, y, s = 1) => `<path d="M0 -10 L8 8 L0 3 L-8 8 Z" fill="var(--you)" transform="translate(${x} ${y}) scale(${s})"/>`;
const hudTitle = (x, y, text, size = 15) => `<text x="${x}" y="${y}" text-anchor="middle" font-size="${size}" font-weight="600" fill="var(--ink)" font-family="IBM Plex Sans">${text}</text>`;

/* Verdant Glen's outline, traced from a clean in-game map screenshot. The pin spots are illustrative. */
const VG = {"w":762,"h":684,"path":"M657.4 376.0 L653.2 386.0 L648.7 395.8 L639.1 404.8 L632.3 413.6 L625.2 422.0 L618.6 430.0 L611.0 437.5 L603.3 444.5 L600.1 452.6 L597.3 460.8 L594.8 469.1 L595.1 478.7 L594.9 488.5 L593.8 498.0 L589.4 506.0 L584.8 513.7 L576.9 519.4 L566.0 522.5 L552.3 522.9 L539.6 523.1 L528.2 523.6 L519.2 525.5 L510.6 527.5 L503.4 530.5 L496.2 533.3 L489.9 536.7 L483.0 539.4 L476.5 542.3 L470.2 545.6 L464.3 549.2 L458.2 552.5 L452.2 556.3 L447.0 561.7 L442.2 568.8 L437.5 577.1 L432.4 585.8 L427.7 597.1 L421.7 606.2 L414.8 614.0 L407.6 621.5 L399.8 628.8 L391.4 634.5 L382.4 635.3 L373.3 635.8 L364.3 634.6 L355.3 633.1 L346.4 631.3 L337.5 630.6 L328.6 629.5 L319.8 628.1 L310.6 628.3 L301.4 628.2 L291.7 629.1 L282.3 628.3 L272.8 627.2 L263.9 624.4 L255.3 620.8 L246.8 616.8 L237.5 614.4 L227.6 612.7 L217.6 610.6 L208.8 606.4 L200.9 600.9 L193.1 595.0 L185.6 588.9 L177.3 583.5 L169.3 577.9 L161.4 571.9 L153.2 566.0 L144.8 560.1 L136.5 553.9 L130.0 546.1 L124.3 537.8 L118.7 529.4 L113.5 520.7 L108.6 511.9 L103.9 502.9 L99.5 493.8 L95.4 484.6 L91.7 475.1 L88.3 465.6 L85.2 456.0 L82.4 446.2 L79.9 436.4 L77.7 426.5 L75.6 416.5 L73.4 406.5 L65.7 396.8 L57.4 386.7 L50.3 376.0 L48.5 364.9 L48.0 353.8 L48.0 342.7 L49.4 331.7 L51.1 320.7 L64.9 312.3 L79.0 304.8 L93.2 298.2 L82.1 284.3 L66.7 267.7 L52.1 249.8 L51.8 236.9 L60.5 227.8 L69.4 219.2 L78.5 211.0 L87.2 202.9 L99.0 197.0 L114.0 194.1 L127.3 190.9 L138.0 186.1 L147.8 181.0 L157.1 175.9 L168.1 172.9 L177.3 168.4 L186.2 163.8 L195.6 160.0 L205.0 156.7 L214.4 153.8 L220.9 146.6 L226.6 137.5 L231.6 126.4 L238.2 117.5 L246.3 111.0 L255.6 107.1 L265.1 103.4 L274.6 100.2 L283.8 95.3 L292.5 88.1 L301.6 81.0 L311.3 75.8 L321.8 73.6 L332.3 72.0 L343.0 71.4 L353.6 71.1 L364.3 70.9 L374.9 70.5 L385.6 70.3 L396.4 70.4 L407.5 68.6 L419.9 60.2 L433.0 52.5 L446.0 48.0 L457.4 51.1 L468.7 54.5 L479.9 58.3 L490.6 63.2 L500.4 70.2 L509.2 78.8 L517.3 88.1 L525.6 96.5 L527.8 114.2 L523.1 140.5 L513.9 169.9 L510.2 189.1 L513.0 198.6 L519.0 204.1 L524.9 209.6 L530.8 215.2 L542.6 215.4 L561.2 210.7 L586.8 202.1 L606.5 199.9 L619.6 203.7 L625.5 212.7 L632.7 221.0 L639.7 229.5 L656.1 233.6 L674.9 237.7 L693.7 242.8 L702.5 252.8 L706.6 264.7 L710.3 276.7 L713.6 288.9 L714.4 301.5 L701.1 316.6 L683.1 331.1 L664.6 344.4 L659.5 355.3 L658.1 365.7 Z","pyramid":[[360,276],[454,386],[374,456],[274,366]],"lakes":[[104,296],[134,366]],"cube":[[393,422],[355,331],[382,364],[345,365],[123,295],[393,232],[149,218]],"ring":[[476,350],[435,479],[525,291],[346,232],[402,163],[238,209],[381,122]],"grid":[[559,377],[144,325]],"randomSpots":[[624,296],[377,571],[261,569],[539,435],[309,238],[223,314]]};

/* Redraw a diagram without losing keyboard focus: the control marked data-focus that had it gets it back. */
function redraw(el, render) {
  const active = document.activeElement;
  // Only for keyboard users: a mouse click shouldn't leave a focus ring behind.
  const key = el.contains(active) && active.matches(":focus-visible") ? active.dataset.focus : null;
  render();
  if (!key) return;
  const again = el.querySelector(`[data-focus="${key}"]`) || el.querySelector("[data-focus]");
  if (again) again.focus();
}

/* ---------- Chapter 02: the map and its Puzzles panel ---------- */
function MapFilters() {
  const el = document.createElement("div");
  el.className = "mapwrap";
  const on = new Set(["cube", "ring"]);
  let random = false;
  const draw = () => redraw(el, render);
  function render() {
    el.innerHTML = `<svg class="mapsvg" viewBox="0 0 ${VG.w} ${VG.h}" role="img" aria-label="Verdant Glen, with a pin for each unsolved puzzle of the ticked types">
        <path d="${VG.path}" fill="var(--map-land)" stroke="var(--hud-tick)" stroke-width="4" stroke-linejoin="round"/>
        <polygon points="${VG.pyramid.map(p => p.join(",")).join(" ")}" fill="var(--map-pyramid)" stroke="var(--line)" stroke-width="2"/>
        ${VG.lakes.map(([x, y]) => `<ellipse cx="${x}" cy="${y}" rx="18" ry="11" fill="var(--map-lake)"/>`).join("")}
        ${TYPES.map(t => VG[t.id].map(([x, y]) => `<g class="pin ${on.has(t.id) ? "" : "off"}">${mark(t.shape, x, y, t.color, 11)}</g>`).join("")).join("")}
        ${VG.randomSpots.map(([x, y]) => `<g class="pin ${random && on.has("grid") ? "" : "off"}"><circle cx="${x}" cy="${y}" r="16" fill="var(--hud-disc)" stroke="var(--hud-tick)" stroke-width="2.2" stroke-dasharray="5 4"/><text x="${x}" y="${y + 6}" text-anchor="middle" font-size="17" font-weight="600" fill="var(--ink)" font-family="IBM Plex Sans">?</text></g>`).join("")}
      </svg>
      <div class="card filter" role="group" aria-label="Puzzle types in Verdant Glen">
        <div class="tabs" aria-hidden="true"><span>Legend</span><span class="on">Puzzles</span><span>Compass</span></div>
        <div class="zone"><b>1. Verdant Glen</b><span>407 left in this zone</span></div>
        <div class="search" aria-hidden="true">Search puzzle types</div>
        <div class="list-head" aria-hidden="true"><span>Puzzle types</span><i></i><span>Clear</span></div>
        ${TYPES.map(t => `<button class="type" type="button" data-type="${t.id}" data-focus="type-${t.id}" aria-pressed="${on.has(t.id)}"><span class="dia"></span>${swatch(t)}<span>${t.name} <span class="count">${t.solved} / ${t.total}</span></span></button>`).join("")}
        <button class="switch" type="button" data-focus="random" aria-pressed="${random}"><span>Show random-spot puzzles</span><span class="t"></span></button>
        <div class="switch" aria-hidden="true"><span>Hide completed</span><span class="t"></span></div>
      </div>`;
    el.querySelectorAll("[data-type]").forEach(b => b.onclick = () => {
      const id = b.dataset.type;
      if (on.has(id)) on.delete(id); else on.add(id);
      draw();
    });
    el.querySelector("button.switch").onclick = () => {
      random = !random;
      if (random) on.add("grid");
      draw();
    };
  }
  render();
  return el;
}

/* ---------- Chapter 03: the compass ---------- */
function Compass() {
  const el = document.createElement("div");
  el.className = "card compass";
  const RANGE = 100;
  // [type, metres away, bearing in degrees, height: 1 above you, -1 below, 0 level]
  const pins = [["cube", 38, 300, -1], ["cube", 70, 20, 1], ["ring", 55, 120, 0], ["ring", 82, 200, -1], ["ring", 26, 60, 0]];
  const markers = [["1.0 km", 300], ["960 m", 345], ["762 m", 40]];
  const solved = new Set();
  const R = 120, C = 140;
  const polar = (r, deg) => { const a = (deg - 90) * Math.PI / 180; return [C + r * Math.cos(a), C + r * Math.sin(a)]; };
  const draw = () => redraw(el, render);
  function render() {
    const pinMarks = pins.map(([id, m, deg, h], i) => {
      if (solved.has(i)) return "";
      const t = TYPES.find(x => x.id === id);
      const [x, y] = polar(m / RANGE * (R - 10), deg);
      const where = h > 0 ? ", above you" : h < 0 ? ", below you" : "";
      return `<g class="pin" data-pin="${i}" data-focus="pin-${i}" tabindex="0" role="button" aria-label="${t.name}, ${m} m${where}. Mark as solved"><circle cx="${x}" cy="${y}" r="14" fill="transparent"/>${mark(t.shape, x, y, t.color, 6.5)}${h ? hudChevron(x + 15, y - 3, h > 0, 4.5) : ""}</g>`;
    }).join("");
    const rim = markers.map(([label, deg]) => {
      const [x, y] = polar(R + 2, deg), [lx, ly] = polar(R - 22, deg);
      return `<path d="M0 -7 L6 5 L0 2 L-6 5 Z" fill="var(--you)" transform="translate(${x} ${y}) rotate(${deg})"/><text x="${lx}" y="${ly + 4}" text-anchor="middle" font-size="11.5" font-weight="600" font-family="IBM Plex Sans" fill="var(--ink)">${label}</text>`;
    }).join("");
    el.innerHTML = `<svg viewBox="0 0 280 326" width="260" height="303" role="img" aria-label="A compass centred on you, showing nearby unsolved puzzles and your map markers on the rim">
        ${hudDisc(C, R)}${pinMarks}${rim}${hudYou(C, C)}
        ${hudTitle(C, 292, "2 puzzle types")}<text x="${C}" y="310" text-anchor="middle" font-size="12" fill="var(--ink-2)" font-family="IBM Plex Sans">${RANGE} m</text>
      </svg>
      ${solved.size ? `<button class="btn quiet small" type="button" data-reset data-focus="reset">${ic("rotate")} Bring them back</button>` : ""}`;
    el.querySelectorAll("[data-pin]").forEach(g => {
      const solve = () => { solved.add(+g.dataset.pin); g.classList.add("solved"); setTimeout(draw, 800); };
      g.onclick = solve;
      g.onkeydown = e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); solve(); } };
    });
    const reset = el.querySelector("[data-reset]");
    if (reset) reset.onclick = () => { solved.clear(); draw(); };
  }
  render();
  return el;
}

/* ---------- Chapter 04: a Crystal Labyrinth floor plan ---------- */
function Labyrinth() {
  const el = document.createElement("div");
  el.className = "card lab";
  // Two floors on a 4 x 4 grid of one-cell corridors. Walls are [x1, y1, x2, y2, glass?];
  // you reach floor 2 by the way up on floor 1, and floor 2's trap doors sit in a dead end.
  const floors = [{"w":[[1,1,1,2],[1,2,1,3],[1,3,1,4,1],[1,1,2,1],[1,3,2,3],[2,1,3,1,1],[2,2,3,2],[2,3,3,3],[3,2,4,2],[0,0,1,0],[0,0,0,1],[4,0,4,1],[1,0,2,0],[1,4,2,4],[0,1,0,2],[4,1,4,2],[2,0,3,0],[2,4,3,4],[0,2,0,3],[4,2,4,3],[3,0,4,0],[3,4,4,4],[0,3,0,4],[4,3,4,4]],"traps":[],"up":[1,3],"down":null,"you":[0,3]},{"w":[[2,2,2,3],[2,3,2,4,1],[3,1,3,2],[3,2,3,3],[0,2,1,2],[1,1,2,1],[1,3,2,3,1],[2,1,3,1],[3,1,4,1],[0,0,1,0],[0,4,1,4],[0,0,0,1],[4,0,4,1],[1,0,2,0],[1,4,2,4],[0,1,0,2],[4,1,4,2],[2,0,3,0],[2,4,3,4],[0,2,0,3],[4,2,4,3],[3,4,4,4],[0,3,0,4],[4,3,4,4]],"traps":[[2,2],[3,2]],"up":null,"down":[1,3],"you":[0,3]}];
  let f = 1;
  const C = 130, R = 118, s = 40, o = C - 2 * s;
  const at = ([x, y]) => [o + x * s, o + y * s];
  const chip = inner => `<svg width="22" height="14" aria-hidden="true"><rect width="22" height="14" rx="4" fill="var(--hud-disc)"/>${inner}</svg>`;
  const draw = () => redraw(el, render);
  function render() {
    const fl = floors[f];
    const cellChevron = (cell, up) => { const [x, y] = at(cell); return hudChevron(x + s / 2, y + s / 2, up, 6); };
    const [yx, yy] = at(fl.you);
    el.innerHTML = `<svg viewBox="0 0 260 300" width="240" height="277" role="img" aria-label="Floor ${f + 1} of ${floors.length}: solid walls, glass walls, trap doors and the ways up or down">
        ${hudDisc(C, R, false)}
        ${fl.traps.map(c => { const [x, y] = at(c); return `<rect x="${x}" y="${y}" width="${s}" height="${s}" fill="var(--hud-trap)"/>`; }).join("")}
        ${fl.w.map(([a, b, c, d, glass]) => `<line x1="${o + a * s}" y1="${o + b * s}" x2="${o + c * s}" y2="${o + d * s}" stroke="${glass ? "var(--hud-glass)" : "var(--hud-wall)"}" stroke-width="3" stroke-linecap="square" ${glass ? 'stroke-dasharray="7 5"' : ""}/>`).join("")}
        ${fl.up ? cellChevron(fl.up, true) : ""}${fl.down ? cellChevron(fl.down, false) : ""}
        ${hudYou(yx + s / 2, yy + s / 2, .8)}
        ${hudTitle(C, 282, "Crystal Labyrinth")}
      </svg>
      <div class="seg" role="group" aria-label="Floor">${floors.map((_, i) => `<button type="button" aria-pressed="${i === f}" data-floor="${i}" data-focus="floor-${i}">Floor ${i + 1}</button>`).join("")}</div>
      <div class="legend">
        <span>${chip('<line x1="5" y1="7" x2="17" y2="7" stroke="var(--hud-wall)" stroke-width="3"/>')} wall</span>
        <span>${chip('<line x1="5" y1="7" x2="17" y2="7" stroke="var(--hud-glass)" stroke-width="3" stroke-dasharray="4 2"/>')} glass</span>
        <span>${chip('<rect x="7" y="3" width="8" height="8" fill="var(--hud-trap)"/>')} trap door</span>
        <span>${chip(hudChevron(11, 7, true, 4))} way up</span>
        <span>${chip(hudChevron(11, 7, false, 4))} way down</span>
      </div>`;
    el.querySelectorAll("[data-floor]").forEach(b => b.onclick = () => { f = +b.dataset.floor; draw(); });
  }
  render();
  return el;
}

/* ---------- Chapter 04: a Hidden Pentad's relics ---------- */
function Pentad() {
  const el = document.createElement("div");
  el.className = "card pentad";
  let found = 2;
  const spots = [0, 1, 2, 3, 4].map(i => { const a = (-90 + i * 72) * Math.PI / 180; return [100 + 70 * Math.cos(a), 100 + 70 * Math.sin(a)]; });
  const relic = (x, y) => `<g transform="translate(${x} ${y})" fill="var(--hud-relic)"><path d="M0 -9 C3 -5 3 -2 0 1 C-3 -2 -3 -5 0 -9Z"/><path d="M-1.2 0 h2.4 v9 h-2.4z"/><path d="M-6 -2 C-3 -2 -2 1 -1 3 C-4 3 -6 1 -6 -2Z"/><path d="M6 -2 C3 -2 2 1 1 3 C4 3 6 1 6 -2Z"/></g>`;
  const draw = () => redraw(el, render);
  function render() {
    el.innerHTML = `<svg viewBox="0 0 200 222" width="248" height="275" role="img" aria-label="${5 - found} of 5 relics left">
        ${hudDisc(100, 88, false)}
        ${spots.filter((_, i) => i >= found).map(([x, y]) => relic(x, y)).join("")}
        ${hudYou(100, 100, .9)}
        ${hudTitle(100, 213, "Hidden Pentad", 11.1)}
      </svg>
      <p class="relics-left"><b>${5 - found}</b> of 5 left</p>
      ${found < 5
        ? `<button class="btn quiet small" type="button" data-find data-focus="relic">Find one</button>`
        : `<button class="btn quiet small" type="button" data-restart data-focus="relic">${ic("rotate")} Start again</button>`}`;
    const find = el.querySelector("[data-find]");
    if (find) find.onclick = () => { found++; draw(); };
    const restart = el.querySelector("[data-restart]");
    if (restart) restart.onclick = () => { found = 0; draw(); };
  }
  render();
  return el;
}

/* ---------- Diagram / In game switch ---------- */
// A width of "full" fills the pane, for the map whose diagram also fills it.
const shot = ([src, alt, width]) => `<figure class="shot${width === "full" ? " full" : ""}"><div class="mat"><img src="${src}" alt="${alt}" data-zoom${width === "full" ? "" : ` style="width:${width}px"`}></div></figure>`;
function Flip(diagrams, shots) {
  const box = document.createElement("div");
  box.className = "flip";
  box.innerHTML = `<div class="seg" role="group" aria-label="View">
      <button type="button" aria-pressed="true" data-view="diagram">${ic("radar")} Diagram</button>
      <button type="button" aria-pressed="false" data-view="game">${ic("gamepad")} In game</button>
    </div>
    <div class="stack"><div class="pane" data-pane="diagram"></div><div class="pane" data-pane="game" hidden>${shots.map(shot).join("")}</div></div>`;
  diagrams.forEach(d => box.querySelector('[data-pane="diagram"]').appendChild(d));
  const buttons = box.querySelectorAll(":scope > .seg button");
  buttons.forEach(b => b.onclick = () => {
    buttons.forEach(x => x.setAttribute("aria-pressed", x === b));
    box.querySelectorAll(":scope > .stack > .pane").forEach(p => { p.hidden = p.dataset.pane !== b.dataset.view; });
  });
  return box;
}

const FLIPS = {
  map: () => Flip([MapFilters()], [["assets/map-puzzles.jpg", "The in-game map of Verdant Glen with Hidden Cube, Pentad and Ring pins, and the Puzzles tab listing the zone's types with solved counts", "full"]]),
  compass: () => Flip([Compass()], [["assets/compass.jpg", "The in-game compass with puzzle pins and three map markers on its rim", 304]]),
  hard: () => Flip([Labyrinth(), Pentad()], [
    ["assets/compass-labyrinth.jpg", "The in-game compass drawing a Crystal Labyrinth floor with two trap doors", 284],
    ["assets/compass-pentad.jpg", "The in-game compass showing three Pentad relics left", 284],
  ]),
};

/* ---------- Chapter 05: how it was made ---------- */
// Each step: its icon, the question or problem it started from, and what came of it.
const PHASES = [
  ["Is it even possible?", [
    ["question", "Could SaveStats live inside the game?", "What SaveStats does, but on the in-game map."],
    ["search", "How would we do it?", "Finding where the game could be extended at all."],
    ["flask", "A first probe", "A few static puzzles from the stats, pinned on the map."],
  ]],
  ["Making the map work", [
    ["gauge", "Every puzzle at once lagged the map", "So it became an optimization problem."],
    ["shuffle", "Hub puzzles aren't static puzzles", "Telling the rotating Hub puzzles apart from the fixed ones."],
    ["filter", "A way to filter", "Pick puzzle types; pins render on demand."],
    ["check", "Solve it and the pin goes", "The map follows your progress live."],
  ]],
  ["Taking it on the move", [
    ["compass", "Could there be a compass in the HUD?", "No more going back and forth to the map."],
    ["updown", "Above, below, how far", "Height marks, range and size."],
  ]],
  ["The hard puzzles", [
    ["copy", "Pentads showed the same puzzle five times", "Now only the relics of the Pentad you started."],
    ["layers", "Mazes are hard to find things in", "The compass draws the labyrinth floor you're on."],
  ]],
  ["Polish", [
    ["sparkles", "Clean and quick to use", "A tidier UI, saved settings, rebindable keys."],
  ]],
];
const timeline = () => PHASES.map(([phase, steps], i) => `<section class="card phase"><h3><span class="n">0${i + 1}</span>${phase}</h3>${steps.map(([icon, title, text]) => `<div class="step"><span class="step-icon">${ic(icon)}</span><b>${title}</b><p>${text}</p></div>`).join("")}</section>`).join("");

// How the agents generate the mod, from the README's developer notes.
const PIPELINE = [
  ["folder", "Extract", "The map, HUD and puzzle data from the installed mod"],
  ["pin", "Catalog", "Every puzzle's spot, from SaveStats and the game"],
  ["code", "Generate", "The map panel and compass, as new game code"],
  ["refresh", "Verify", "Rebuilt and compared, so nothing drifts"],
  ["pkg", "Pack", "One mod file that asks the game what's solved"],
];
const pipeline = () => PIPELINE.map(([icon, title, text]) => `<div class="pipe-step"><span class="pipe-icon">${ic(icon)}</span><b>${title}</b><p>${text}</p></div>`).join("");

/* ---------- Page ---------- */
const buttons = () => `<a class="btn primary" href="${REPO}">${ic("github")} Source on GitHub</a><a class="btn quiet" href="${REPO}/releases">${ic("download")} Download</a>`;

function setThemeButton() {
  const dark = document.documentElement.classList.contains("dark");
  const b = document.querySelector("[data-theme-toggle]");
  b.innerHTML = ic(dark ? "sun" : "moon");
  b.setAttribute("aria-label", dark ? "Switch to light theme" : "Switch to dark theme");
  b.title = dark ? "Light theme" : "Dark theme";
}

document.querySelectorAll("[data-icon]").forEach(el => { el.innerHTML = ic(el.dataset.icon); });
document.querySelectorAll("[data-buttons]").forEach(el => { el.innerHTML = buttons(); });
document.querySelectorAll("[data-repo]").forEach(a => { a.href = REPO + a.dataset.repo; });
document.querySelectorAll("[data-flip]").forEach(el => el.replaceWith(FLIPS[el.dataset.flip]()));
document.querySelector("[data-timeline]").innerHTML = timeline();
document.querySelector("[data-pipeline]").innerHTML = pipeline();
setThemeButton();

document.querySelector("[data-theme-toggle]").addEventListener("click", () => {
  const dark = document.documentElement.classList.toggle("dark");
  try { localStorage.setItem("pf-theme", dark ? "dark" : "light"); } catch {}
  setThemeButton();
});

const lightbox = document.getElementById("lightbox");
document.addEventListener("click", e => {
  const img = e.target.closest("[data-zoom]");
  if (!img) return;
  lightbox.querySelector("img").src = img.src;
  lightbox.querySelector("img").alt = img.alt;
  lightbox.showModal();
});
lightbox.addEventListener("click", () => lightbox.close());
