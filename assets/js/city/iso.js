/**
 * Projeção isométrica e formas básicas para a cidade.
 *
 * Coordenadas de grade: x (desce para a direita), y (desce para a esquerda),
 * z (altura). O tabuleiro vai de 0 a 20 em x e y.
 */

export const TILE_W = 26;   // meia largura de 1 unidade na tela
export const TILE_H = 13;   // meia altura de 1 unidade na tela
export const UNIT_Z = 22;   // altura de 1 unidade na tela
export const ORIGIN = { x: 560, y: 190 };
export const BOARD = 20;

/* ---------------------------------------------------------------------------
   Giro das estruturas em 4 direções (0°, 90°, 180°, 270°)

   Enquanto `desenharGirado()` roda, todo ponto passa por `girarXY()` (giro em torno do
   centro da estrutura) antes da projeção. Como a câmera só vê as faces y+d e x+w:
    - caixas e telhados são redesenhados no retângulo já girado (faces e sombras certas);
    - detalhes colados numa caixa (janelas, porta, letreiro, coisas no teto) vão junto
      com ela, e os que ficaram nas faces de trás (escondidas) são descartados;
    - as demais peças (árvores, carros, colunas…) viram fragmentos ordenados de trás
      para frente (pintor), porque a 180° o que era a frente passa a ficar atrás.
   --------------------------------------------------------------------------- */

let giro = null;   // { k, cx, cy, frags: [{ svg, chave }], caixas: [], nivel }
const MARCA = /\u0001(\d+)\u0002/;
const TOL = 0.03;

function girarXY(x, y) {
    if (!giro) return [x, y];
    const dx = x - giro.cx, dy = y - giro.cy;
    if (giro.k === 1) return [giro.cx + dy, giro.cy - dx];   // frente y+d → x+w
    if (giro.k === 2) return [giro.cx - dx, giro.cy - dy];   // frente → y (costas à vista)
    return [giro.cx - dy, giro.cy + dx];                     // frente → x
}

/** Há uma estrutura sendo desenhada girada? (detalhes das faces de trás só fazem sentido nesse caso) */
export const girando = () => giro !== null;

/** Retângulo (x, y, w, d) depois do giro. */
function limites(x, y, w, d) {
    const [ax, ay] = girarXY(x, y);
    const [bx, by] = girarXY(x + w, y + d);
    return [Math.min(ax, bx), Math.min(ay, by), Math.abs(bx - ax), Math.abs(by - ay)];
}

function projetar(x, y, z = 0) {
    return [ORIGIN.x + (x - y) * TILE_W, ORIGIN.y + (x + y) * TILE_H - z * UNIT_Z];
}

/** Converte coordenadas de grade em pontos de tela (com o giro da estrutura, se houver). */
export function p(x, y, z = 0) {
    const [gx, gy] = girarXY(x, y);
    return projetar(gx, gy, z);
}

const fmt = (n) => Math.round(n * 10) / 10;
const ptsBrutos = (list) => list.map(([x, y, z = 0]) => projetar(x, y, z).map(fmt).join(',')).join(' ');

/** Lista de pontos de grade → atributo "points". */
export function pts(list) {
    return list.map(([x, y, z = 0]) => p(x, y, z).map(fmt).join(',')).join(' ');
}

/** Guarda um fragmento para ordenar depois; devolve a marca que fica no lugar dele. */
function marcar(svg, chave) {
    giro.frags.push({ svg, chave });
    return `\u0001${giro.frags.length - 1}\u0002`;
}

/** Caixa já desenhada que "dona" destes pontos (girados): face, teto ou 'oculta' (face de trás). */
function caixaDona(pontos) {
    for (let i = giro.caixas.length - 1; i >= 0; i -= 1) {
        const c = giro.caixas[i];
        const dentro = pontos.every(([x, y]) => x >= c.X - TOL && x <= c.X + c.W + TOL && y >= c.Y - TOL && y <= c.Y + c.D + TOL);
        if (!dentro) continue;
        const zs = pontos.map((q) => q[2]);
        if (Math.min(...zs) >= c.zt - 0.02) return c;                       // sobre o teto
        if (Math.min(...zs) < c.z0 - 0.02 || Math.max(...zs) > c.zt + 0.02) continue;
        const todos = (f) => pontos.every(f);
        if (todos(([x]) => Math.abs(x - c.X) < TOL) || todos(([, y]) => Math.abs(y - c.Y) < TOL)) return 'oculta';
        if (todos(([x]) => Math.abs(x - c.X - c.W) < TOL) || todos(([, y]) => Math.abs(y - c.Y - c.D) < TOL)) return c;
    }
    return null;
}

const chaveDe = (pontos) => {
    const n = pontos.length;
    return pontos.reduce((s, [x, y]) => s + x + y, 0) / n + 2 * Math.min(...pontos.map((q) => q[2]));
};

/** Põe o desenho de uma peça no lugar certo: grudado numa caixa, descartado ou como fragmento. */
function encaixar(svg, pontos) {
    if (!giro || giro.nivel > 0) return svg;
    const dona = caixaDona(pontos);
    if (dona === 'oculta') return '';
    if (dona) { dona.frag.svg += svg; return ''; }
    return marcar(svg, chaveDe(pontos));
}

export function poly(list, fill, extra = '') {
    const svg = `<polygon points="${pts(list)}" fill="${fill}" ${extra}/>`;
    if (!giro || giro.nivel > 0) return svg;
    return encaixar(svg, list.map(([x, y, z = 0]) => [...girarXY(x, y), z]));
}

/**
 * Peça avulsa (árvore, carro, poste, bandeira…) desenhada por `fn` com o ponto de apoio (x, y, z):
 * no giro vira um fragmento ordenado por esse ponto; fora do giro devolve o desenho como está.
 */
export function objetoEm(x, y, z, fn) {
    if (!giro || giro.nivel > 0) return fn();
    giro.nivel += 1;
    let svg;
    try { svg = fn(); } finally { giro.nivel -= 1; }
    const [gx, gy] = girarXY(x, y);
    return marcar(svg, gx + gy + 2 * z);
}

/** Troca as marcas pelo desenho; `ordenar` aplica o pintor (fragmentos de trás para frente). */
function resolver(str, ordenar) {
    if (!str) return '';
    const partes = str.split(MARCA);   // texto, id, texto, id, …
    const itens = [];
    let ultima = null;
    const soltos = [];
    partes.forEach((parte, i) => {
        if (i % 2 === 1) {
            const f = giro.frags[Number(parte)];
            ultima = f.chave;
            soltos.splice(0).forEach((s) => itens.push({ svg: s, chave: f.chave - 0.0001, ordem: itens.length }));
            itens.push({ svg: f.svg, chave: f.chave, ordem: itens.length });
        } else if (parte) {
            if (ultima === null) soltos.push(parte);
            else itens.push({ svg: parte, chave: ultima + 0.0001, ordem: itens.length });   // texto solto segue a peça anterior
        }
    });
    soltos.forEach((s) => itens.push({ svg: s, chave: 0, ordem: itens.length }));
    if (ordenar) itens.sort((a, b) => a.chave - b.chave || a.ordem - b.ordem);
    return itens.map((it) => it.svg).join('');
}

/**
 * Desenha uma estrutura girada k × 90° em torno de (cx, cy). `fn` desenha na posição sem giro
 * e devolve { chao, objeto }.
 */
export function desenharGirado(k, cx, cy, fn) {
    giro = { k, cx, cy, frags: [], caixas: [], nivel: 0 };
    try {
        const r = fn();
        return { ...r, chao: resolver(r.chao, false), objeto: resolver(r.objeto, true) };
    } finally {
        giro = null;
    }
}

/** Retângulo plano no plano z. */
export function quad(x, y, w, d, fill, z = 0, extra = '') {
    return poly([[x, y, z], [x + w, y, z], [x + w, y + d, z], [x, y + d, z]], fill, extra);
}

/** Escurece (amt < 0) ou clareia (amt > 0) uma cor hex. */
export function shade(hex, amt) {
    const n = parseInt(hex.slice(1), 16);
    const ch = (v) => Math.max(0, Math.min(255, Math.round(v + 255 * amt)));
    const r = ch((n >> 16) & 255), g = ch((n >> 8) & 255), b = ch(n & 255);
    return `#${((1 << 24) | (r << 16) | (g << 8) | b).toString(16).slice(1)}`;
}

/**
 * Caixa sólida com as três faces visíveis.
 * @param {number} z0 altura da base
 */
export function box(x, y, w, d, h, color, { z0 = 0, top, stroke = 'rgba(0,0,0,0.18)' } = {}) {
    const zt = z0 + h;
    const s = `stroke="${stroke}" stroke-width="0.8" stroke-linejoin="round"`;
    // No giro, a caixa é redesenhada no retângulo girado (as faces visíveis são sempre y+d e x+w)
    const [X, Y, W, D] = giro ? limites(x, y, w, d) : [x, y, w, d];
    const face = (list, cor) => `<polygon points="${ptsBrutos(list)}" fill="${cor}" ${s}/>`;
    const svg = face([[X, Y + D, z0], [X + W, Y + D, z0], [X + W, Y + D, zt], [X, Y + D, zt]], shade(color, -0.12))   // frente-esquerda
        + face([[X + W, Y, z0], [X + W, Y + D, z0], [X + W, Y + D, zt], [X + W, Y, zt]], shade(color, -0.24))      // frente-direita
        + face([[X, Y, zt], [X + W, Y, zt], [X + W, Y + D, zt], [X, Y + D, zt]], top || shade(color, 0.06));         // topo
    if (!giro || giro.nivel > 0) return svg;
    const registro = { X, Y, W, D, z0, zt };
    const base = [[X, Y, z0], [X + W, Y, z0], [X + W, Y + D, z0], [X, Y + D, z0]];
    const sobre = caixaDona(base);   // caixa sobre o teto de outra (caixa-d'água, torre…) vai junto com ela
    if (sobre && sobre !== 'oculta') {
        sobre.frag.svg += svg;
        registro.frag = sobre.frag;
        giro.caixas.push(registro);
        return '';
    }
    const marca = marcar(svg, X + W / 2 + Y + D / 2 + 2 * z0);
    registro.frag = giro.frags[giro.frags.length - 1];
    giro.caixas.push(registro);
    return marca;
}

/** Telhado de duas águas sobre uma caixa (cumeeira ao longo de x). */
export function gableRoof(x, y, w, d, h, rise, color) {
    const s = 'stroke="rgba(0,0,0,0.2)" stroke-width="0.8" stroke-linejoin="round"';
    const face = (list, cor) => `<polygon points="${ptsBrutos(list)}" fill="${cor}" ${s}/>`;
    const [X, Y, W, D] = giro ? limites(x, y, w, d) : [x, y, w, d];
    let svg;
    if (giro && giro.k % 2 === 1) {
        // Girado 90°/270°: cumeeira ao longo de y
        const rx = X + W / 2;
        svg = face([[X, Y, h], [X, Y + D, h], [rx, Y + D, h + rise], [rx, Y, h + rise]], shade(color, 0.02))
            + face([[X + W, Y, h], [X + W, Y + D, h], [rx, Y + D, h + rise], [rx, Y, h + rise]], shade(color, -0.2))
            + face([[X, Y + D, h], [X + W, Y + D, h], [rx, Y + D, h + rise]], shade(color, -0.1));
    } else {
        const ridgeY = Y + D / 2;
        svg = face([[X, Y, h], [X + W, Y, h], [X + W, ridgeY, h + rise], [X, ridgeY, h + rise]], shade(color, 0.08))
            + face([[X, Y + D, h], [X + W, Y + D, h], [X + W, ridgeY, h + rise], [X, ridgeY, h + rise]], shade(color, -0.06))
            + face([[X + W, Y, h], [X + W, Y + D, h], [X + W, ridgeY, h + rise]], shade(color, -0.22));
    }
    if (!giro || giro.nivel > 0) return svg;
    return encaixar(svg, [[X, Y, h], [X + W, Y, h], [X + W, Y + D, h], [X, Y + D, h]]);
}

/** Pontos de uma elipse no plano do chão (em coordenadas de grade). */
export function groundEllipse(cx, cy, rx, ry, z = 0, n = 28, wobble = 0) {
    const list = [];
    for (let i = 0; i < n; i += 1) {
        const a = (i / n) * Math.PI * 2;
        const k = 1 + wobble * Math.sin(a * 3 + 0.7);
        list.push([cx + Math.cos(a) * rx * k, cy + Math.sin(a) * ry * k, z]);
    }
    return list;
}

/** Sprite com chave de profundidade para ordenação (pintor). */
export const sprite = (x, y, svg) => ({ depth: x + y, svg });

/** Ordena e concatena sprites de trás para frente. */
export function renderSprites(list) {
    return list.sort((a, b) => a.depth - b.depth).map((s) => s.svg).join('');
}
