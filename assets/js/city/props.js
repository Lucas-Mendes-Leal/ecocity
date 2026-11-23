/**
 * Elementos ilustrados reutilizáveis da cidade (árvores, veículos, lixo...).
 * Todos retornam strings SVG já projetadas.
 */
import { p, box, poly, shade, groundEllipse, pts } from './iso.js';

const f = (n) => Math.round(n * 10) / 10;

/** Gerador pseudoaleatório determinístico (mesmo desenho a cada renderização). */
export function rng(seed) {
    let t = seed >>> 0;
    return () => {
        t += 0x6D2B79F5;
        let r = Math.imul(t ^ (t >>> 15), 1 | t);
        r ^= r + Math.imul(r ^ (r >>> 7), 61 | r);
        return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
    };
}

function circle(cx, cy, r, fill, extra = '') {
    return `<circle cx="${f(cx)}" cy="${f(cy)}" r="${f(r)}" fill="${fill}" ${extra}/>`;
}

function ellipse(cx, cy, rx, ry, fill, extra = '') {
    return `<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(rx)}" ry="${f(ry)}" fill="${fill}" ${extra}/>`;
}

function line([x1, y1], [x2, y2], stroke, width, extra = '') {
    return `<line x1="${f(x1)}" y1="${f(y1)}" x2="${f(x2)}" y2="${f(y2)}" stroke="${stroke}" stroke-width="${width}" stroke-linecap="round" ${extra}/>`;
}

export function groundShadow(x, y, rx, ry = rx / 2) {
    const [sx, sy] = p(x, y, 0);
    return ellipse(sx, sy, rx, ry, 'rgba(0,0,0,0.16)');
}

/* ---------------------------------------------------------------------------
   Vegetação
   --------------------------------------------------------------------------- */

export function tree(x, y, s = 1, color = '#43A047') {
    const trunkH = 0.9 * s;
    const [cx, cy] = p(x, y, trunkH + 0.75 * s);
    const r = 17 * s;
    return groundShadow(x, y, 15 * s)
        + box(x - 0.11 * s, y - 0.11 * s, 0.22 * s, 0.22 * s, trunkH, '#6D4C41', { stroke: 'none' })
        + circle(cx, cy, r, color, `stroke="${shade(color, -0.28)}" stroke-width="1.4"`)
        + circle(cx + r * 0.32, cy + r * 0.28, r * 0.55, shade(color, -0.1))
        + circle(cx - r * 0.3, cy - r * 0.32, r * 0.5, shade(color, 0.12));
}

export function pine(x, y, s = 1, color = '#2E7D32') {
    const [bx, by] = p(x, y, 0.5 * s);
    const tri = (w, top, base) => `<polygon points="${f(bx - w)},${f(base)} ${f(bx + w)},${f(base)} ${f(bx)},${f(top)}" fill="${color}" stroke="${shade(color, -0.3)}" stroke-width="1.2" stroke-linejoin="round"/>`;
    return groundShadow(x, y, 12 * s)
        + box(x - 0.1 * s, y - 0.1 * s, 0.2 * s, 0.2 * s, 0.6 * s, '#5D4037', { stroke: 'none' })
        + tri(15 * s, by - 34 * s, by)
        + tri(12 * s, by - 46 * s, by - 16 * s)
        + `<polygon points="${f(bx)},${f(by - 46 * s)} ${f(bx + 12 * s)},${f(by - 16 * s)} ${f(bx + 4 * s)},${f(by - 16 * s)}" fill="${shade(color, -0.12)}"/>`;
}

export function deadTree(x, y, s = 1) {
    const base = p(x, y, 0);
    const top = p(x, y, 2.0 * s);
    const mid = p(x, y, 1.2 * s);
    const c = '#5D4037';
    return groundShadow(x, y, 10 * s)
        + line(base, top, c, 4.5 * s)
        + line(mid, [mid[0] + 15 * s, mid[1] - 16 * s], c, 2.6 * s)
        + line([mid[0], mid[1] + 6], [mid[0] - 13 * s, mid[1] - 10 * s], c, 2.4 * s)
        + line(top, [top[0] + 8 * s, top[1] - 10 * s], c, 2 * s)
        + line(top, [top[0] - 7 * s, top[1] - 9 * s], c, 2 * s);
}

export function bush(x, y, s = 1, color = '#558B2F') {
    const [cx, cy] = p(x, y, 0.25 * s);
    return circle(cx - 6 * s, cy + 1, 7 * s, shade(color, -0.06), `stroke="${shade(color, -0.3)}" stroke-width="1"`)
        + circle(cx + 6 * s, cy + 1, 7 * s, shade(color, -0.1), `stroke="${shade(color, -0.3)}" stroke-width="1"`)
        + circle(cx, cy - 3 * s, 8 * s, color, `stroke="${shade(color, -0.3)}" stroke-width="1"`);
}

export function grassTufts(x0, y0, w, d, count, seed, color = '#7CB342') {
    const r = rng(seed);
    let out = '';
    for (let i = 0; i < count; i += 1) {
        const [sx, sy] = p(x0 + r() * w, y0 + r() * d, 0);
        out += `<path d="M${f(sx - 3)} ${f(sy)} l1.5 -6 M${f(sx)} ${f(sy)} l0 -8 M${f(sx + 3)} ${f(sy)} l-1.5 -6" stroke="${color}" stroke-width="1.4" stroke-linecap="round" fill="none"/>`;
    }
    return out;
}

export function flowers(x0, y0, w, d, count, seed) {
    const r = rng(seed);
    const colors = ['#E91E63', '#FFEB3B', '#FFFFFF', '#AB47BC', '#FF7043'];
    let out = '';
    for (let i = 0; i < count; i += 1) {
        const [sx, sy] = p(x0 + r() * w, y0 + r() * d, 0);
        out += circle(sx, sy - 2, 2.2, colors[i % colors.length], 'stroke="rgba(0,0,0,0.25)" stroke-width="0.5"');
    }
    return out;
}

/** Rachaduras do solo seco. */
export function cracks(x0, y0, w, d, count, seed) {
    const r = rng(seed);
    let out = '';
    for (let i = 0; i < count; i += 1) {
        const [sx, sy] = p(x0 + r() * w, y0 + r() * d, 0);
        out += `<path d="M${f(sx)} ${f(sy)} l${f(6 + r() * 6)} ${f(2 + r() * 3)} l${f(4 + r() * 5)} ${f(-3 - r() * 2)} m${f(-6)} ${f(1)} l${f(2)} ${f(5)}" stroke="rgba(62,39,35,0.45)" stroke-width="1.1" fill="none" stroke-linecap="round"/>`;
    }
    return out;
}

/* ---------------------------------------------------------------------------
   Resíduos e reciclagem
   --------------------------------------------------------------------------- */

export function trashPile(x, y, s = 1, seed = 1) {
    const [cx, cy] = p(x, y, 0);
    const r = rng(seed);
    const bits = ['#ECEFF1', '#90CAF9', '#FFCC80', '#A5D6A7', '#B0BEC5'];
    let out = ellipse(cx, cy - 3 * s, 16 * s, 7 * s, '#6D5D4B', 'stroke="#4E342E" stroke-width="1"')
        + ellipse(cx - 2, cy - 7 * s, 9 * s, 4.5 * s, '#7B6B57');
    for (let i = 0; i < 6; i += 1) {
        const bx = cx + (r() - 0.5) * 24 * s;
        const by = cy - 4 * s - r() * 7 * s;
        out += `<rect x="${f(bx)}" y="${f(by)}" width="${f(3 + r() * 3)}" height="${f(2 + r() * 2)}" rx="1" fill="${bits[i % bits.length]}" transform="rotate(${Math.round(r() * 60 - 30)} ${f(bx)} ${f(by)})"/>`;
    }
    return out;
}

export function recyclingBins(x, y) {
    const colors = ['#1E88E5', '#E53935', '#FDD835', '#43A047'];
    return colors.map((c, i) => box(x + i * 0.42, y, 0.34, 0.34, 0.5, c, { top: shade(c, -0.2) })).join('');
}

export function dumpster(x, y, overflowing) {
    let out = box(x, y, 1.1, 0.7, 0.6, '#33691E', { top: '#1B5E20' });
    if (overflowing) out += trashPile(x + 0.55, y + 0.35, 0.6, 7);
    return out;
}

/* ---------------------------------------------------------------------------
   Energia
   --------------------------------------------------------------------------- */

export function solarRow(x, y, w, frame = '#ECEFF1') {
    const d = 0.75;
    const panel = poly([[x, y, 0.7], [x + w, y, 0.7], [x + w, y + d, 0.25], [x, y + d, 0.25]], '#1565C0', `stroke="${frame}" stroke-width="1"`);
    let grid = '';
    for (let i = 1; i < Math.round(w / 0.6); i += 1) {
        const gx = x + (i * w) / Math.round(w / 0.6);
        grid += `<polyline points="${pts([[gx, y, 0.7], [gx, y + d, 0.25]])}" stroke="rgba(255,255,255,0.55)" stroke-width="0.7" fill="none"/>`;
    }
    const leg = (lx) => `<polyline points="${pts([[lx, y + d, 0], [lx, y + d, 0.25]])}" stroke="#78909C" stroke-width="1.5"/>`;
    const shine = poly([[x + 0.1, y + 0.05, 0.68], [x + w * 0.35, y + 0.05, 0.68], [x + w * 0.25, y + d - 0.05, 0.27], [x + 0.05, y + d - 0.05, 0.27]], 'rgba(255,255,255,0.18)');
    return leg(x + 0.2) + leg(x + w - 0.2) + panel + grid + shine;
}

export function windTurbine(x, y, h = 6, key = 'a') {
    const [bx, by] = p(x, y, 0);
    const [hx, hy] = p(x, y, h);
    const blade = (deg) => `<path d="M0 0 C 4 -6, 5 -22, 0 -38 C -3 -22, -3 -6, 0 0z" fill="#FAFAFA" stroke="#90A4AE" stroke-width="0.8" transform="rotate(${deg})"/>`;
    return groundShadow(x, y, 8)
        + `<polygon points="${f(bx - 4)},${f(by)} ${f(bx + 4)},${f(by)} ${f(hx + 1.6)},${f(hy)} ${f(hx - 1.6)},${f(hy)}" fill="#ECEFF1" stroke="#90A4AE" stroke-width="0.8"/>`
        + `<g transform="translate(${f(hx)} ${f(hy)})"><g class="turbine-rotor turbine-${key}">${blade(0)}${blade(120)}${blade(240)}</g>`
        + `<circle r="3.2" fill="#CFD8DC" stroke="#78909C" stroke-width="0.8"/></g>`;
}

/** Coluna de fumaça animada saindo de um ponto (x, y, z). */
export function smoke(x, y, z, color = '#616161', count = 4) {
    const [sx, sy] = p(x, y, z);
    let out = '';
    for (let i = 0; i < count; i += 1) {
        out += circle(sx + i * 5, sy - 8 - i * 13, 7 + i * 3.2, color, `class="smoke-puff" style="animation-delay:${(i * 0.7).toFixed(1)}s" opacity="0.75"`);
    }
    return out;
}

/* ---------------------------------------------------------------------------
   Mobilidade
   --------------------------------------------------------------------------- */

/** Pessoa pequena em pé na origem da grade (posicionada por transform; o balanço vem do CSS). */
export function pessoa(cor = '#1E88E5') {
    const [sx, sy] = p(0, 0, 0);
    return `<ellipse cx="${sx}" cy="${sy}" rx="3.5" ry="1.6" fill="rgba(0,0,0,0.22)"/>`
        + `<g class="pessoa-anda">`
        + line([sx - 1.2, sy], [sx - 1, sy - 5], '#37474F', 1.4) + line([sx + 1.2, sy], [sx + 1, sy - 5], '#37474F', 1.4)
        + `<rect x="${sx - 2.4}" y="${sy - 11}" width="4.8" height="6.5" rx="2" fill="${cor}" stroke="rgba(0,0,0,0.3)" stroke-width="0.5"/>`
        + circle(sx, sy - 13.5, 2.5, '#FFCC80', 'stroke="rgba(0,0,0,0.3)" stroke-width="0.5"')
        + `</g>`;
}

/** Carro orientado ao longo de x ('x') ou de y ('y'). */
export function car(x, y, color, dir = 'x') {
    const [w, d] = dir === 'x' ? [1.3, 0.62] : [0.62, 1.3];
    const [cw, cd, ox, oy] = dir === 'x' ? [0.62, 0.5, 0.32, 0.06] : [0.5, 0.62, 0.06, 0.32];
    return groundShadow(x + w / 2, y + d / 2, 15, 6)
        + box(x, y, w, d, 0.38, color)
        + box(x + ox, y + oy, cw, cd, 0.28, shade(color, 0.12), { z0: 0.38, top: shade(color, 0.2) });
}

export function bus(x, y, color, dir = 'x') {
    const [w, d] = dir === 'x' ? [2.6, 0.72] : [0.72, 2.6];
    const win = dir === 'x'
        ? poly([[x + 0.25, y + d, 0.5], [x + w - 0.3, y + d, 0.5], [x + w - 0.3, y + d, 0.78], [x + 0.25, y + d, 0.78]], '#B3E5FC')
        : poly([[x + w, y + 0.25, 0.5], [x + w, y + d - 0.3, 0.5], [x + w, y + d - 0.3, 0.78], [x + w, y + 0.25, 0.78]], '#B3E5FC');
    return groundShadow(x + w / 2, y + d / 2, 26, 9)
        + box(x, y, w, d, 0.95, color, { top: '#ECEFF1' })
        + win;
}

export function bike(x, y, shirt = '#E53935') {
    const [sx, sy] = p(x, y, 0);
    return `<g class="bike-rider">`
        + circle(sx - 5, sy - 3, 3.4, 'none', 'stroke="#263238" stroke-width="1.3"')
        + circle(sx + 5, sy - 5.5, 3.4, 'none', 'stroke="#263238" stroke-width="1.3"')
        + line([sx - 5, sy - 3], [sx, sy - 8], '#37474F', 1.3)
        + line([sx, sy - 8], [sx + 5, sy - 5.5], '#37474F', 1.3)
        + line([sx, sy - 8], [sx + 1, sy - 16], shirt, 3)
        + circle(sx + 1.5, sy - 19, 2.6, '#FFCC80')
        + `</g>`;
}

/* ---------------------------------------------------------------------------
   Água
   --------------------------------------------------------------------------- */

export function reeds(x, y, seed = 1) {
    const [sx, sy] = p(x, y, 0);
    const r = rng(seed);
    let out = '';
    for (let i = 0; i < 5; i += 1) {
        const ox = (i - 2) * 3 + r() * 2;
        const h = 12 + r() * 8;
        out += line([sx + ox, sy], [sx + ox + (r() - 0.5) * 3, sy - h], '#689F38', 1.4);
        if (i % 2 === 0) out += ellipse(sx + ox, sy - h, 1.6, 3.6, '#795548');
    }
    return out;
}

export function lilyPad(x, y, flower = false) {
    const [sx, sy] = p(x, y, 0);
    return ellipse(sx, sy, 7, 3.4, '#66BB6A', 'stroke="#2E7D32" stroke-width="0.8"')
        + (flower ? circle(sx + 1, sy - 2, 2.2, '#F48FB1') : '');
}

export function floatingTrash(x, y, color = '#ECEFF1') {
    const [sx, sy] = p(x, y, 0);
    return `<rect x="${f(sx - 4)}" y="${f(sy - 2)}" width="8" height="3.2" rx="1.5" fill="${color}" stroke="rgba(0,0,0,0.3)" stroke-width="0.6" transform="rotate(-18 ${f(sx)} ${f(sy)})"/>`;
}

export function duck(x, y) {
    const [sx, sy] = p(x, y, 0);
    return ellipse(sx, sy - 3, 6, 3.6, '#FAFAFA', 'stroke="#90A4AE" stroke-width="0.6"')
        + circle(sx + 4.5, sy - 7.5, 2.6, '#2E7D32')
        + `<path d="M${f(sx + 6.8)} ${f(sy - 7.5)} l3 0.8 -3 0.8z" fill="#FFA000"/>`;
}

export function ripples(x, y, rx, ry) {
    return `<polygon class="water-ripple" points="${pts(groundEllipse(x, y, rx, ry, 0, 20))}" fill="none" stroke="rgba(255,255,255,0.55)" stroke-width="1.2"/>`;
}

/* ---------------------------------------------------------------------------
   Mobiliário urbano
   --------------------------------------------------------------------------- */

export function bench(x, y, broken = false) {
    const wood = broken ? '#795548' : '#A1887F';
    return box(x, y, 0.9, 0.32, 0.22, wood, { z0: 0.2 })
        + box(x, y, 0.9, 0.08, 0.38, shade(wood, -0.1), { z0: 0.42 })
        + `<polyline points="${pts([[x + 0.1, y + 0.32, 0], [x + 0.1, y + 0.32, 0.2]])}" stroke="#4E342E" stroke-width="1.4"/>`
        + `<polyline points="${pts([[x + 0.8, y + 0.32, 0], [x + 0.8, y + 0.32, 0.2]])}" stroke="#4E342E" stroke-width="1.4"/>`;
}

export function solarLamp(x, y) {
    const base = p(x, y, 0);
    const top = p(x, y, 1.8);
    return line(base, top, '#546E7A', 2)
        + `<polygon points="${pts([[x - 0.25, y - 0.25, 1.95], [x + 0.25, y - 0.25, 1.95], [x + 0.25, y + 0.25, 1.95], [x - 0.25, y + 0.25, 1.95]])}" fill="#1565C0" stroke="#ECEFF1" stroke-width="0.6"/>`
        + circle(top[0], top[1] + 2, 3.2, '#FFF59D', 'stroke="#F9A825" stroke-width="0.8"');
}

/* ---------------------------------------------------------------------------
   Edifícios do catálogo (aparecem quando liberados)
   --------------------------------------------------------------------------- */

/** Placa fincada no chão; com cadeado quando `bloqueado`. */
export function placa(x, y, texto, bloqueado = false) {
    const base = p(x, y, 0);
    const topo = p(x, y, 1.15);
    const larg = Math.max(26, texto.length * 5.6 + (bloqueado ? 16 : 8));
    const bx = topo[0] - larg / 2;
    const by = topo[1] - 14;
    const cadeado = bloqueado
        ? `<g transform="translate(${f(bx + 4)} ${f(by + 2.5)}) scale(0.42)" fill="none" stroke="#5D4037" stroke-width="2.6" stroke-linecap="round">${LOCK}</g>`
        : '';
    return line(base, topo, '#5D4037', 2.4)
        + `<rect x="${f(bx)}" y="${f(by)}" width="${f(larg)}" height="14" rx="2" fill="#FFF8E1" stroke="#5D4037" stroke-width="1.2"/>`
        + cadeado
        + `<text x="${f(bx + (bloqueado ? 15 : 4))}" y="${f(by + 10)}" font-size="8.5" font-weight="700" fill="#5D4037" font-family="Segoe UI, sans-serif">${texto}</text>`;
}
const LOCK = '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>';

/** Terreno vazio reservado para um edifício ainda bloqueado (só o chão; a placa vai à parte). */
export function lote(x, y, w, d) {
    return poly([[x, y, 0], [x + w, y, 0], [x + w, y + d, 0], [x, y + d, 0]], '#B79F86',
        'stroke="#8D6E63" stroke-width="1.4" stroke-dasharray="5 4"');
}

/** Tanque cilíndrico (estação de reúso). */
export function tanque(x, y, raio, h, cor) {
    const [bx, by] = p(x, y, 0);
    const [tx, ty] = p(x, y, h);
    const rx = raio * 29;
    const ry = rx / 2;
    return groundShadow(x, y, rx + 4, ry + 2)
        + `<path d="M${f(bx - rx)} ${f(by)} L${f(tx - rx)} ${f(ty)} A${f(rx)} ${f(ry)} 0 0 1 ${f(tx + rx)} ${f(ty)} L${f(bx + rx)} ${f(by)} A${f(rx)} ${f(ry)} 0 0 1 ${f(bx - rx)} ${f(by)}z" fill="${shade(cor, -0.12)}" stroke="rgba(0,0,0,0.25)" stroke-width="0.8"/>`
        + ellipse(tx, ty, rx, ry, shade(cor, 0.12), 'stroke="rgba(0,0,0,0.25)" stroke-width="0.8"')
        + ellipse(tx, ty, rx * 0.7, ry * 0.7, '#4FC3F7', 'opacity="0.85"');
}

/** Cerca de madeira entre dois pontos do chão. */
export function cerca(x1, y1, x2, y2, passo = 0.7) {
    const n = Math.max(1, Math.round(Math.hypot(x2 - x1, y2 - y1) / passo));
    let out = `<polyline points="${pts([[x1, y1, 0.45], [x2, y2, 0.45]])}" stroke="#8D6E63" stroke-width="2" fill="none"/>`
        + `<polyline points="${pts([[x1, y1, 0.22], [x2, y2, 0.22]])}" stroke="#8D6E63" stroke-width="2" fill="none"/>`;
    for (let i = 0; i <= n; i += 1) {
        const x = x1 + ((x2 - x1) * i) / n;
        const y = y1 + ((y2 - y1) * i) / n;
        out += `<polyline points="${pts([[x, y, 0], [x, y, 0.6]])}" stroke="#6D4C41" stroke-width="2.4"/>`;
    }
    return out;
}
