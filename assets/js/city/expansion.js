/**
 * Lotes de expansão (20×20) ao redor do centro e as estruturas colocadas neles.
 * Lote 2 = direita (x 20–40), 3 = esquerda (y 20–40), 4 = frente (x e y 20–40).
 *
 * Os indicadores também agem nos lotes (specs/design.md §7):
 * Natureza → cor do solo; Reciclagem → lixo (células vindas do servidor);
 * Energia → névoa de poluição; Água e Mobilidade → aparência das estruturas.
 */
import { p, poly, quad, shade, sprite, BOARD, TILE_W, TILE_H } from './iso.js';
import { placa, trashPile, cracks, grassTufts } from './props.js';
import { desenharEstrutura } from './structures.js';
import { SOLO } from './regions.js';

export const LOTE_ORIGEM = { 2: [BOARD, 0], 3: [0, BOARD], 4: [BOARD, BOARD] };
const SLAB = 1.5;
const NEBLINA_ENERGIA = [0.55, 0.3, 0, 0];   // opacidade da névoa por estágio da Energia

/** Estágios dos indicadores no formato usado pelo desenho das estruturas. */
export function condicoesLotes(estagios) {
    return { agua: estagios.water, natureza: estagios.nature, energia: estagios.energy, mobilidade: estagios.mobility, reciclagem: estagios.recycling };
}

/** Bloco de terra do lote (topo + laterais da frente); o solo segue o estágio da Natureza. */
function blocoLote(lote, estagio) {
    const [x0, y0] = LOTE_ORIGEM[lote];
    const [x1, y1] = [x0 + BOARD, y0 + BOARD];
    return poly([[x0, y1, 0], [x1, y1, 0], [x1, y1, -SLAB], [x0, y1, -SLAB]], '#6D4C41')
        + poly([[x1, y0, 0], [x1, y1, 0], [x1, y1, -SLAB], [x1, y0, -SLAB]], '#5D4037')
        + poly([[x0, y1, 0], [x1, y1, 0], [x1, y1, -0.3], [x0, y1, -0.3]], '#7CB342')
        + poly([[x1, y0, 0], [x1, y1, 0], [x1, y1, -0.3], [x1, y0, -0.3]], '#689F38')
        + quad(x0, y0, BOARD, BOARD, SOLO[estagio - 1], 0, `stroke="${shade(SOLO[estagio - 1], -0.15)}" stroke-width="1"`)
        + (estagio === 1 ? cracks(x0 + 0.5, y0 + 0.5, BOARD - 1, BOARD - 1, 18, lote * 31)
                         : grassTufts(x0 + 0.4, y0 + 0.4, BOARD - 0.8, BOARD - 0.8, estagio * 12, lote * 31, shade(SOLO[estagio - 1], -0.2)));
}

/** Névoa de poluição (nuvens baixas e cinzentas) sobre o lote quando a Energia está baixa. */
function neblinaLote(lote, energia) {
    const op = NEBLINA_ENERGIA[energia - 1];
    if (!op) return '';
    const [x0, y0] = LOTE_ORIGEM[lote];
    const nuvens = [[5, 5], [15, 5], [10, 10], [5, 15], [15, 15]].map(([dx, dy], i) => {
        const [sx, sy] = p(x0 + dx, y0 + dy, 3);
        return `<ellipse cx="${Math.round(sx)}" cy="${Math.round(sy)}" rx="${100 + (i % 2) * 30}" ry="${30 + (i % 3) * 6}"/>`;
    }).join('');
    return `<g class="cloud" fill="#9E9E90" opacity="${op}" filter="url(#neblina-lote)" pointer-events="none">${nuvens}</g>`;
}

/** Posição da placa do próximo lote: logo depois da borda do centro, dentro do enquadramento. */
const PLACA_LOTE = { 2: [21.5, 4], 3: [4, 21.5], 4: [21.5, 21.5] };

/**
 * Próximo lote ainda bloqueado: contorno tracejado (pode sair do enquadramento)
 * e placa perto do centro. Não amplia a vista da cidade.
 */
function loteBloqueado(lote) {
    const [x0, y0] = LOTE_ORIGEM[lote];
    const [px, py] = PLACA_LOTE[lote];
    return {
        chao: quad(x0, y0, BOARD, BOARD, 'rgba(255,255,255,0.18)', 0, 'stroke="#FFFFFF" stroke-width="2" stroke-dasharray="10 8"'),
        sprite: { depth: px + py, svg: placa(px, py, `Novo lote · Cidade nível ${lote}`, true) },
    };
}

/** Grade do lote em modo construção. */
export function gradeLote(lote) {
    const [x0, y0] = LOTE_ORIGEM[lote];
    let linhas = '';
    for (let i = 0; i <= BOARD; i += 1) {
        const a = p(x0 + i, y0), b = p(x0 + i, y0 + BOARD);
        const c = p(x0, y0 + i), d = p(x0 + BOARD, y0 + i);
        linhas += `<line x1="${a[0]}" y1="${a[1]}" x2="${b[0]}" y2="${b[1]}"/><line x1="${c[0]}" y1="${c[1]}" x2="${d[0]}" y2="${d[1]}"/>`;
    }
    return `<g class="build-grid">${linhas}</g>`;
}

/** Contorno da área (prévia de construção ou destaque de remoção). */
export function contornoArea(lote, x, y, w, d, classe) {
    const [x0, y0] = LOTE_ORIGEM[lote];
    return poly([[x0 + x, y0 + y, 0.02], [x0 + x + w, y0 + y, 0.02], [x0 + x + w, y0 + y + d, 0.02], [x0 + x, y0 + y + d, 0.02]], 'currentColor', `class="${classe}"`);
}

/**
 * Camadas dos lotes para o mapa.
 * @param {{ lotes: number[], construidas: object[], lixo: object[] }} construcao
 * @param {Record<string, number>} estagios estágio de cada indicador
 * @returns {{ blocos: string, solo: string, sprites: object[], neblina: string }}
 */
export function camadasLotes(construcao, estagios) {
    const disponiveis = construcao.lotes;
    const condicoes = condicoesLotes(estagios);
    let blocos = '';
    let solo = '';
    let neblina = '';
    const sprites = [];

    disponiveis.forEach((lote) => {
        blocos += blocoLote(lote, estagios.nature);
        neblina += neblinaLote(lote, estagios.energy);
    });

    // Mostra só o próximo lote a liberar, como incentivo
    const proximo = [2, 3, 4].find((l) => !disponiveis.includes(l));
    if (proximo) {
        const b = loteBloqueado(proximo);
        solo += b.chao;
        sprites.push(b.sprite);
    }

    construcao.construidas.filter((c) => disponiveis.includes(c.lote)).forEach((c) => {
        const [x0, y0] = LOTE_ORIGEM[c.lote];
        const gx = x0 + c.x, gy = y0 + c.y;
        const desenho = desenharEstrutura(c, gx, gy, c.w, c.d, condicoes, c.rotacao);
        solo += desenho.chao;
        sprites.push({ depth: gx + c.w / 2 + gy + c.d / 2, svg: `<g data-construcao="${c.id}">${desenho.objeto}</g>` });
    });

    // Lixo (Reciclagem baixa): uma pilha por célula, calculada no servidor
    (construcao.lixo || []).filter((l) => disponiveis.includes(l.lote)).forEach((l, i) => {
        const [x0, y0] = LOTE_ORIGEM[l.lote];
        const x = x0 + l.x + 0.5, y = y0 + l.y + 0.5;
        sprites.push(sprite(x, y, `<g data-lixo>${trashPile(x, y, 0.9, i + 3)}</g>`));
    });

    if (neblina) neblina = `<filter id="neblina-lote" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="16"/></filter>${neblina}`;
    return { blocos, solo, sprites, neblina };
}

/** Limites de tela (viewBox) para a cidade inteira ou para um lote em foco. */
export function limitesTela(lotes, foco = null) {
    if (foco) {
        const [x0, y0] = LOTE_ORIGEM[foco];
        const esq = p(x0, y0 + BOARD)[0] - 30, dir = p(x0 + BOARD, y0)[0] + 30;
        const topo = p(x0, y0, 4)[1] - 40, base = p(x0 + BOARD, y0 + BOARD)[1] + 60;
        return [esq, topo, dir - esq, base - topo];
    }
    // Só os lotes liberados ampliam a vista; o próximo lote aparece apenas como borda
    const maxX = lotes.some((l) => l === 2 || l === 4) ? 2 * BOARD : BOARD;
    const maxY = lotes.some((l) => l === 3 || l === 4) ? 2 * BOARD : BOARD;
    const esq = p(0, maxY)[0] - 30, dir = p(maxX, 0)[0] + 30;
    const topo = 40, base = p(maxX, maxY)[1] + 80;
    return [esq, topo, dir - esq, base - topo];
}

/** Converte coordenadas do SVG (plano do chão) para a célula do lote, ou null fora dele. */
export function celulaDoLote(lote, sx, sy) {
    const [ox, oy] = p(0, 0);
    const a = (sx - ox) / TILE_W, b = (sy - oy) / TILE_H;  // a = x − y, b = x + y
    const gx = (a + b) / 2, gy = (b - a) / 2;
    const [x0, y0] = LOTE_ORIGEM[lote];
    const x = Math.floor(gx - x0), y = Math.floor(gy - y0);
    return x >= 0 && y >= 0 && x < BOARD && y < BOARD ? { x, y } : null;
}
