/**
 * Desenho das 5 regiões da cidade em cada estágio (1–4).
 * Cada região muda de CONDIÇÃO conforme o próprio indicador (sujeira, água,
 * vegetação, fumaça, trânsito); a estrutura (posições, ruas, lago) é sempre a
 * mesma entre os estágios (specs/design.md §7).
 *
 *
 * Layout do tabuleiro 20×20:
 *   fundo (topo)  x 0–8.7,   y 0–8.7   → Energia
 *   direita       x 11.3–20, y 0–8.7   → Bairro e Resíduos
 *   esquerda      x 0–8.7,   y 11.3–20 → Lago
 *   frente        x 11.3–20, y 11.3–20 → Parque
 *   cruzamento    ruas em x 9–11 e y 9–11 → Mobilidade
 */
import { box, gableRoof, quad, poly, groundEllipse, shade, sprite } from './iso.js';
import {
    tree, pine, deadTree, bush, grassTufts, flowers, cracks,
    trashPile, recyclingBins, dumpster,
    solarRow, windTurbine, smoke,
    car, bus, bike,
    reeds, lilyPad, floatingTrash, duck, ripples,
    bench, solarLamp,
} from './props.js';

export const QUADRANTES = {
    energy:    { x: 0,    y: 0,    w: 8.7, d: 8.7 },
    recycling: { x: 11.3, y: 0,    w: 8.7, d: 8.7 },
    water:     { x: 0,    y: 11.3, w: 8.7, d: 8.7 },
    nature:    { x: 11.3, y: 11.3, w: 8.7, d: 8.7 },
};

/** Cor do solo por estágio: terra seca → gramado vivo. */
export const SOLO = ['#A1887F', '#B9B56E', '#8BC34A', '#66BB6A'];

function soloQuadrante(id, stage, seed) {
    const q = QUADRANTES[id];
    let g = quad(q.x, q.y, q.w, q.d, SOLO[stage - 1]);
    if (stage === 1) g += cracks(q.x + 0.4, q.y + 0.4, q.w - 0.8, q.d - 0.8, 7, seed);
    if (stage >= 2) g += grassTufts(q.x + 0.3, q.y + 0.3, q.w - 0.6, q.d - 0.6, stage * 5, seed, shade(SOLO[stage - 1], -0.2));
    return g;
}

/* ---------------------------------------------------------------------------
   ENERGIA — usina poluente → parque solar e eólico
   --------------------------------------------------------------------------- */
export function energia(stage) {
    const sprites = [];
    let ground = soloQuadrante('energy', stage, 11);

    if (stage <= 3) {
        // Fábrica + chaminés (as chaminés começam no telhado)
        const parede = stage === 1 ? '#90A4AE' : '#A7B6BE';
        let fabrica = box(1.0, 1.0, 4.2, 2.8, 2.0, parede, { top: shade(parede, -0.15) })
            + box(1.4, 1.25, 0.55, 0.55, 2.4, '#8D6E63', { z0: 2.0 })
            + box(1.4, 1.25, 0.55, 0.55, 0.3, '#C62828', { z0: 4.1 })
            + box(2.6, 1.25, 0.55, 0.55, 2.0, '#8D6E63', { z0: 2.0 })
            + box(2.6, 1.25, 0.55, 0.55, 0.3, '#C62828', { z0: 3.7 });
        if (stage === 1) fabrica += smoke(1.67, 1.52, 4.4, '#5D5D5D', 5) + smoke(2.87, 1.52, 4.0, '#6D6D6D', 4);
        if (stage === 2) fabrica += smoke(1.67, 1.52, 4.4, '#9E9E9E', 3);
        sprites.push(sprite(3.1, 2.4, fabrica));
    } else {
        // Usina limpa com teto verde
        sprites.push(sprite(3.1, 2.4, box(1.0, 1.0, 4.2, 2.8, 1.5, '#E0E0E0', { top: '#81C784' })));
        sprites.push(sprite(6.25, 3.25, box(5.8, 2.8, 0.9, 0.9, 0.7, '#26A69A')));
    }

    if (stage === 1) {
        sprites.push(sprite(2.1, 5.2, box(1.0, 4.4, 2.2, 1.6, 1.2, '#A1887F', { top: '#8D6E63' })));
        ground += poly(groundEllipse(7.3, 3.9, 1.1, 0.7), '#37474F', 'stroke="#263238" stroke-width="1"'); // pilha de carvão
        [[6.6, 2.0], [7.3, 2.0], [6.95, 2.6]].forEach(([x, y]) => sprites.push(sprite(x, y, box(x, y, 0.45, 0.45, 0.6, '#5D4037', { top: '#3E2723' }))));
    }
    if (stage === 2) {
        sprites.push(sprite(2.1, 5.2, box(1.0, 4.4, 2.2, 1.6, 1.2, '#BCAAA4', { top: '#A1887F' })));
    }
    if (stage === 3) {
        sprites.push(sprite(7.2, 1.6, windTurbine(7.2, 1.6, 6, 'a')));
    }
    if (stage === 4) {
        sprites.push(sprite(6.6, 1.0, windTurbine(6.6, 1.0, 6.4, 'a')));
        sprites.push(sprite(8.0, 3.0, windTurbine(8.0, 3.0, 5.6, 'b')));
    }
    return { ground, sprites };
}

/* ---------------------------------------------------------------------------
   BAIRRO E RESÍDUOS — lixo acumulado → coleta seletiva e telhados verdes
   --------------------------------------------------------------------------- */
export const CASAS = [[12.2, 1.0], [15.2, 1.0], [12.2, 4.6]];
const PAREDE = ['#BCAAA4', '#D7CCC8', '#FFE0B2', '#FFF3E0'];
const TELHADO = ['#8D6E63', '#A1887F', '#E64A19', '#66BB6A'];

function casa(x, y, stage) {
    const w = 2.4, d = 2.2, h = 1.5;
    const janela = stage === 1 ? '#78909C' : '#FFF59D';
    return box(x, y, w, d, h, PAREDE[stage - 1])
        + gableRoof(x, y, w, d, h, 0.9, TELHADO[stage - 1])
        + poly([[x + 0.35, y + d, 0.6], [x + 0.95, y + d, 0.6], [x + 0.95, y + d, 1.1], [x + 0.35, y + d, 1.1]], janela, 'stroke="rgba(0,0,0,0.3)" stroke-width="0.6"')
        + poly([[x + 1.4, y + d, 0], [x + 1.85, y + d, 0], [x + 1.85, y + d, 0.95], [x + 1.4, y + d, 0.95]], '#6D4C41')
        + poly([[x + w, y + 0.6, 0.6], [x + w, y + 1.2, 0.6], [x + w, y + 1.2, 1.1], [x + w, y + 0.6, 1.1]], janela, 'stroke="rgba(0,0,0,0.3)" stroke-width="0.6"');
}

function predio(stage) {
    const x = 17.6, y = 0.8, w = 1.8, d = 3.2, h = 3.6;
    const cor = ['#90A4AE', '#B0BEC5', '#CFD8DC', '#E3F2FD'][stage - 1];
    let out = box(x, y, w, d, h, cor, { top: stage === 4 ? '#81C784' : shade(cor, -0.1) });
    const janela = stage === 1 ? '#607D8B' : '#FFF9C4';
    for (const z of [0.5, 1.4, 2.3]) {
        for (const yy of [1.1, 2.0, 2.9]) {
            out += poly([[x + w, yy, z], [x + w, yy + 0.5, z], [x + w, yy + 0.5, z + 0.5], [x + w, yy, z + 0.5]], janela, 'stroke="rgba(0,0,0,0.25)" stroke-width="0.5"');
        }
        out += poly([[x + 0.4, y + d, z], [x + 1.4, y + d, z], [x + 1.4, y + d, z + 0.5], [x + 0.4, y + d, z + 0.5]], janela, 'stroke="rgba(0,0,0,0.25)" stroke-width="0.5"');
    }
    return out;
}

export function bairro(stage) {
    const sprites = [];
    let ground = soloQuadrante('recycling', stage, 23)
        + quad(11.3, 3.6, 5.6, 0.7, stage === 1 ? '#9E9E9E' : '#CFD8DC'); // calçada interna

    // Casas e prédio: estrutura fixa do bairro
    CASAS.forEach(([x, y]) => sprites.push(sprite(x + 1.2, y + 1.1, casa(x, y, stage))));
    sprites.push(sprite(18.5, 2.4, predio(stage)));

    if (stage === 1) {
        [[14.6, 7.6, 1, 3], [19.0, 8.1, 1.1, 5], [12.6, 8.0, 0.9, 9], [16.2, 4.0, 0.7, 4]]
            .forEach(([x, y, s, seed]) => sprites.push(sprite(x, y, trashPile(x, y, s, seed))));
        sprites.push(sprite(16.5, 5.4, dumpster(16.0, 5.0, true)));
    }
    if (stage === 2) {
        sprites.push(sprite(19.0, 8.1, trashPile(19.0, 8.1, 0.9, 5)));
        sprites.push(sprite(16.5, 5.4, dumpster(16.0, 5.0, false)));
    }
    if (stage >= 3) {
        sprites.push(sprite(15.5, 5.6, recyclingBins(14.8, 5.4)));
        sprites.push(sprite(13.4, 7.6, bush(13.4, 7.6, 0.9)));
    }
    if (stage === 4) {
        sprites.push(sprite(14.6, 7.8, tree(14.6, 7.8, 0.75, '#43A047')));
        sprites.push(sprite(12.0, 7.9, tree(12.0, 7.9, 0.7, '#66BB6A')));
    }
    return { ground, sprites };
}

/* ---------------------------------------------------------------------------
   LAGO — água poluída → lago limpo com vida
   --------------------------------------------------------------------------- */
export const AGUA = ['#5D4037', '#00838F', '#26C6DA', '#4DD0E1'];
export const MARGEM = ['#6D4C41', '#BCAAA4', '#E6D3A3', '#E6D3A3'];

export function lago(stage) {
    const sprites = [];
    const cx = 4.4, cy = 15.7;
    let ground = soloQuadrante('water', stage, 37)
        + poly(groundEllipse(cx, cy, 3.85, 3.45, 0, 32, 0.07), MARGEM[stage - 1])
        + poly(groundEllipse(cx, cy, 3.3, 2.95, 0, 32, 0.07), AGUA[stage - 1], `stroke="${shade(AGUA[stage - 1], -0.2)}" stroke-width="1.2"`)
        + poly(groundEllipse(cx - 0.6, cy - 0.5, 1.6, 0.9, 0, 20), shade(AGUA[stage - 1], 0.1), 'opacity="0.7"');

    if (stage === 1) {
        ground += poly(groundEllipse(5.0, 16.6, 1.0, 0.6, 0, 18), '#4A148C', 'opacity="0.25"');
        ground += poly([[1.3, 13.3, 0], [2.4, 13.9, 0], [2.0, 14.6, 0], [1.1, 13.9, 0]], '#4E342E', 'opacity="0.8"');
        [[3.4, 14.6, '#ECEFF1'], [5.2, 16.4, '#90CAF9'], [4.0, 17.4, '#FFCC80'], [6.0, 15.0, '#ECEFF1']]
            .forEach(([x, y, c]) => { ground += floatingTrash(x, y, c); });
        sprites.push(sprite(0.8, 13.4, box(0.3, 13.2, 1.1, 0.4, 0.4, '#757575')));
    }
    if (stage === 2) {
        ground += floatingTrash(5.2, 16.4, '#ECEFF1');
        sprites.push(sprite(1.2, 14.0, reeds(1.2, 14.0, 3)));
    }
    if (stage >= 3) {
        [[3.6, 15.0], [5.4, 16.8], [2.6, 16.6], [5.9, 15.2]].forEach(([x, y], i) => { ground += lilyPad(x, y, stage === 4 && i % 2 === 0); });
        [[1.2, 14.0, 3], [7.4, 17.8, 5], [2.0, 18.6, 8]].forEach(([x, y, s]) => sprites.push(sprite(x, y, reeds(x, y, s))));
        ground += ripples(4.6, 15.9, 0.9, 0.6);
    }
    if (stage === 4) {
        ground += box(7.0, 15.2, 1.7, 0.8, 0.15, '#8D6E63', { top: '#A1887F' });
        ground += duck(3.8, 16.6) + ripples(3.2, 15.4, 1.3, 0.9);
        sprites.push(sprite(1.0, 12.3, tree(1.0, 12.3, 0.8, '#43A047')));
        sprites.push(sprite(7.9, 19.2, tree(7.9, 19.2, 0.85, '#388E3C')));
        ground += flowers(0.4, 18.2, 2.2, 1.5, 8, 41);
    }
    return { ground, sprites };
}

/* ---------------------------------------------------------------------------
   PARQUE — árvores secas → floresta urbana
   --------------------------------------------------------------------------- */
export function parque(stage) {
    const sprites = [];
    let ground = soloQuadrante('nature', stage, 53)
        + quad(11.3, 15.4, 8.7, 0.8, stage === 1 ? '#8D7B6A' : '#D7CCC8');

    const vivas = {
        2: [[13.0, 13.0, 0.6], [15.5, 13.4, 0.55], [14.6, 18.4, 0.6]],
        3: [[12.6, 12.6, 0.95], [15.2, 13.0, 0.9], [18.0, 12.8, 1], [13.4, 18.2, 0.9], [16.4, 18.6, 0.95], [19.0, 17.6, 0.85], [19.2, 14.0, 0.8]],
    };
    vivas[4] = [...vivas[3], [12.2, 14.6, 0.8], [17.4, 14.2, 0.75], [11.9, 17.4, 0.85], [18.2, 19.4, 0.8], [14.8, 19.6, 0.75]];
    const cores = ['#43A047', '#388E3C', '#66BB6A', '#2E7D32'];

    if (stage <= 2) {
        const secas = stage === 1
            ? [[13.0, 13.0], [17.5, 12.8], [14.6, 18.4], [18.8, 17.6], [15.6, 13.6]]
            : [[17.5, 12.8], [18.8, 17.6]];
        secas.forEach(([x, y]) => sprites.push(sprite(x, y, deadTree(x, y, 1))));
    }
    if (stage >= 2) {
        vivas[stage].forEach(([x, y, s], i) => {
            const draw = stage === 4 && i % 4 === 3 ? pine(x, y, s, '#2E7D32') : tree(x, y, s, cores[i % cores.length]);
            sprites.push(sprite(x, y, draw));
        });
    }
    if (stage === 1) {
        sprites.push(sprite(16.4, 13.8, trashPile(16.4, 13.8, 0.8, 12)));
        sprites.push(sprite(13.2, 16.6, bench(13.2, 16.5, true)));
    } else {
        sprites.push(sprite(13.2, 16.6, bench(13.2, 16.5)));
    }
    if (stage >= 3) {
        [[17.2, 16.8], [12.4, 19.2], [19.4, 15.9]].forEach(([x, y]) => sprites.push(sprite(x, y, bush(x, y, 0.9))));
        ground += flowers(16.6, 16.6, 3, 1.4, stage === 4 ? 16 : 6, 61);
    }
    if (stage === 4) {
        ground += flowers(11.8, 12.0, 2.5, 2.2, 10, 67);
        sprites.push(sprite(16.6, 16.3, solarLamp(16.6, 16.3)));
        sprites.push(sprite(14.0, 15.3, solarLamp(14.0, 15.3)));
    }
    return { ground, sprites };
}

/* ---------------------------------------------------------------------------
   MOBILIDADE — trânsito poluente → ônibus elétrico e ciclovias
   --------------------------------------------------------------------------- */
/** @param {{ parados?: boolean }} [opcoes] parados = veículos fixos (sem o tráfego animado de traffic.js) */
export function vias(stage, { parados = true } = {}) {
    const sprites = [];
    const asfalto = stage === 1 ? '#455A64' : '#546E7A';
    const calcada = stage === 1 ? '#9E9E9E' : '#CFD8DC';

    let ground = quad(0, 8.7, 20, 2.6, calcada) + quad(8.7, 0, 2.6, 20, calcada)
        + quad(0, 9, 20, 2, asfalto) + quad(9, 0, 2, 20, asfalto);

    // Faixas centrais tracejadas (fora do cruzamento)
    for (let i = 0.4; i < 20; i += 1.4) {
        if (i > 8.2 && i < 11.3) continue;
        ground += quad(i, 9.94, 0.75, 0.12, '#FFFFFF') + quad(9.94, i, 0.12, 0.75, '#FFFFFF');
    }
    // Faixas de pedestres
    for (let k = 0; k < 5; k += 1) {
        const o = 9.1 + k * 0.38;
        ground += quad(8.05, o, 0.55, 0.2, '#ECEFF1') + quad(11.4, o, 0.55, 0.2, '#ECEFF1')
            + quad(o, 8.05, 0.2, 0.55, '#ECEFF1') + quad(o, 11.4, 0.2, 0.55, '#ECEFF1');
    }
    if (stage === 1) {
        ground += poly(groundEllipse(4.0, 10.4, 0.35, 0.22), '#263238') + poly(groundEllipse(15.6, 9.5, 0.3, 0.2), '#263238');
    }

    const carros = {
        1: [[1.4, 9.2, '#C62828', 'x'], [4.8, 9.2, '#757575', 'x'], [13.8, 9.2, '#5D4037', 'x'], [3.0, 10.15, '#9E9E9E', 'x'], [16.6, 10.15, '#C62828', 'x'], [9.2, 2.0, '#616161', 'y'], [10.15, 15.4, '#8D6E63', 'y']],
        2: [[2.5, 9.2, '#C62828', 'x'], [15.0, 10.15, '#1E88E5', 'x'], [9.2, 4.0, '#9E9E9E', 'y']],
        3: [[15.5, 10.15, '#1E88E5', 'x']],
        4: [],
    };
    if (!parados) return { ground, sprites };   // veículos em movimento vêm de traffic.js
    carros[stage].forEach(([x, y, c, d]) => sprites.push(sprite(x + 0.6, y + 0.3, car(x, y, c, d))));
    if (stage === 1) {
        sprites.push(sprite(1.2, 9.5, smokeAt(1.2, 9.5)));
        sprites.push(sprite(13.6, 9.5, smokeAt(13.6, 9.5)));
    }
    if (stage === 2) sprites.push(sprite(6.8, 10.5, bus(5.5, 10.15, '#FB8C00', 'x')));
    if (stage >= 3) {
        sprites.push(sprite(5.3, 9.6, bus(4.0, 9.2, '#43A047', 'x')));
        [[2.5, 11.2, '#E53935'], [14.0, 11.2, '#1E88E5']].forEach(([x, y, c]) => sprites.push(sprite(x, y, bike(x, y, c))));
    }
    if (stage === 4) {
        sprites.push(sprite(9.6, 15.0, bus(10.15, 13.4, '#2E7D32', 'y')));
        [[6.4, 11.2, '#FDD835'], [17.6, 11.2, '#AB47BC'], [11.2, 4.5, '#FF7043'], [11.2, 16.4, '#26A69A']]
            .forEach(([x, y, c]) => sprites.push(sprite(x, y, bike(x, y, c))));
    }
    return { ground, sprites };
}

function smokeAt(x, y) {
    return smoke(x, y, 0.4, '#757575', 2);
}
