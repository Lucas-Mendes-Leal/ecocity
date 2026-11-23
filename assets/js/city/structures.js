/**
 * Desenho das estruturas compráveis (tabela construcao) dentro dos lotes.
 * Cada função recebe a área ocupada em coordenadas globais da grade
 * (x, y, w, d — já com o giro aplicado) e as condições da cidade
 * (estágio 1–4 de cada indicador), e devolve { chao, objeto }:
 * `chao` é desenhado junto com o solo e `objeto` entra na ordenação de profundidade.
 *
 * Condições (specs/design.md §7):
 *  - agua: estruturas com água ficam sujas (1–2) ou limpas (3–4);
 *  - natureza: plantas secas (1), amareladas (2) ou viçosas (3–4);
 *  - energia: painéis solares empoeirados (1–2) ou limpos (3–4);
 *  - mobilidade: carros com fumaça (1–2) ou bicicletas e ônibus elétrico (3–4);
 *  - reciclagem: lixo solto em volta de lanchonete, mercado e bar (1–2).
 * Giro (`rotacao` 0–3): todos os modelos giram nas 4 direções pelo motor de `iso.js`
 * (`desenharGirado`): cada função desenha sempre de frente (face y+d) e o giro é aplicado aos
 * pontos; a 180°/270° aparecem as costas. Peças avulsas (árvores, carros, postes, colunas,
 * bandeiras) usam `objetoEm`/os embrulhos abaixo para entrar na ordem de profundidade.
 *
 * O modelo de cada estrutura vem do banco (`construcao.desenho`, migração 018): uma das chaves
 * de MODELOS ou 'personalizado', montado pelas opções de `construcao.aparencia`.
 */
import { p, pts, box, gableRoof, quad, poly, groundEllipse, shade, objetoEm, desenharGirado, girando } from './iso.js';
import * as P from './props.js';
import { AGUA, MARGEM } from './regions.js';
import { iconPaths } from '../ui/icons.js';

const f = (n) => Math.round(n * 10) / 10;

/** Peça dos props com ponto de apoio para a ordem de profundidade no giro (sem giro, nada muda). */
const apoiado = (fn, ancora = (a) => [a[0], a[1], 0]) => (...a) => {
    const [ax, ay, az] = ancora(a);
    return objetoEm(ax, ay, az, () => fn(...a));
};
const tree = apoiado(P.tree), pine = apoiado(P.pine), deadTree = apoiado(P.deadTree), bush = apoiado(P.bush), bike = apoiado(P.bike);
const car = apoiado(P.car, (a) => (a[3] === 'y' ? [a[0] + 0.31, a[1] + 0.65, 0] : [a[0] + 0.65, a[1] + 0.31, 0]));
const solarRow = apoiado(P.solarRow, (a) => [a[0] + a[2] / 2, a[1] + 0.375, 0]);
const smoke = apoiado(P.smoke, (a) => [a[0], a[1], a[2] + 1]);
const cerca = apoiado(P.cerca, (a) => [(a[0] + a[2]) / 2, (a[1] + a[3]) / 2, 0]);
const bench = apoiado(P.bench, (a) => [a[0] + 0.45, a[1] + 0.15, 0]);
const reeds = apoiado(P.reeds), duck = apoiado(P.duck), trashPile = apoiado(P.trashPile);
const { lilyPad, floatingTrash, ripples, cracks, flowers, grassTufts } = P;   // no chão: só giram
const longoEmX = (w, d) => w >= d;

/** Condições neutras (usadas se nenhuma for informada). */
const CONDICAO_PADRAO = { agua: 3, natureza: 3, energia: 3, mobilidade: 3, reciclagem: 3 };

/** Cor da vegetação pelo estágio da Natureza. */
function verde(natureza, cor) {
    if (natureza === 1) return '#8D6E63';
    if (natureza === 2) return '#9E9D24';
    return cor;
}

/** Árvore conforme a Natureza: seca no estágio 1. */
function arvore(x, y, s, cor, natureza) {
    return natureza === 1 ? deadTree(x, y, s * 0.9) : tree(x, y, s, verde(natureza, cor));
}

function lixeiras(x, y, w, d) {
    const cores = ['#1E88E5', '#43A047', '#FDD835', '#E53935'];
    const objeto = cores.map((c, i) => box(x + 0.12 + (i % 2) * 0.42, y + 0.12 + Math.floor(i / 2) * 0.42, 0.34, 0.34, 0.5, c, { top: shade(c, -0.2) })).join('');
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#B0BEC5'), objeto };
}

function pontoOnibus(x, y, w, d, c) {
    const limpo = c.mobilidade >= 3;
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#CFD8DC');
    const poste = (px, py) => objetoEm(px, py, 0, () => `<polyline points="${[[px, py, 0], [px, py, 1.3]].map(([a, b, z]) => p(a, b, z).map(f).join(',')).join(' ')}" stroke="#546E7A" stroke-width="2"/>`);
    const vidro = longoEmX(w, d)
        ? [[x + 0.15, y + 0.15, 0.2], [x + w - 0.15, y + 0.15, 0.2], [x + w - 0.15, y + 0.15, 1.2], [x + 0.15, y + 0.15, 1.2]]
        : [[x + 0.15, y + 0.15, 0.2], [x + 0.15, y + d - 0.15, 0.2], [x + 0.15, y + d - 0.15, 1.2], [x + 0.15, y + 0.15, 1.2]];
    let objeto = poste(x + 0.15, y + 0.15) + poste(x + w - 0.15, y + 0.15) + poste(x + 0.15, y + d - 0.15) + poste(x + w - 0.15, y + d - 0.15)
        + poly(vidro, limpo ? 'rgba(179,229,252,0.7)' : 'rgba(158,158,158,0.6)', 'stroke="#90A4AE" stroke-width="0.8"')
        + box(x + 0.25, y + 0.25, w - 0.5, d - 0.5, 0.25, '#8D6E63', { z0: 0.2 })
        + box(x, y, w, d, 0.12, limpo ? '#43A047' : '#78909C', { z0: 1.3, top: limpo ? '#66BB6A' : '#90A4AE' });
    // Fumaça de escapamento quando a mobilidade é baixa; bicicleta quando é alta
    objeto += limpo ? bike(x + w - 0.3, y + d - 0.3, '#26A69A') : smoke(x + w / 2, y + d / 2, 0.3, '#757575', c.mobilidade === 1 ? 3 : 2);
    return { chao, objeto };
}

function casa(x, y, w, d) {
    const h = 1.3;
    const objeto = box(x + 0.2, y + 0.2, w - 0.4, d - 0.4, h, '#FFF3E0')
        + gableRoof(x + 0.2, y + 0.2, w - 0.4, d - 0.4, h, 0.7, '#66BB6A')
        + poly([[x + 0.5, y + d - 0.2, 0.5], [x + 0.9, y + d - 0.2, 0.5], [x + 0.9, y + d - 0.2, 0.9], [x + 0.5, y + d - 0.2, 0.9]], '#FFF59D', 'stroke="rgba(0,0,0,0.3)" stroke-width="0.6"')
        + poly([[x + w - 0.2, y + 0.6, 0], [x + w - 0.2, y + 1.0, 0], [x + w - 0.2, y + 1.0, 0.85], [x + w - 0.2, y + 0.6, 0.85]], '#6D4C41');
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#A5D6A7'), objeto };
}

function ciclofaixa(x, y, w, d, c) {
    let chao = quad(x + 0.1, y + 0.1, w - 0.2, d - 0.2, '#66BB6A', 0, 'stroke="#2E7D32" stroke-width="1"');
    const n = Math.max(w, d);
    for (let i = 0.4; i < n - 0.3; i += 1) {
        chao += longoEmX(w, d)
            ? quad(x + i, y + d / 2 - 0.04, 0.5, 0.08, '#FFFFFF')
            : quad(x + w / 2 - 0.04, y + i, 0.08, 0.5, '#FFFFFF');
    }
    if (c.mobilidade >= 3) return { chao, objeto: bike(x + w / 2, y + d / 2, '#FF7043') };

    // Mobilidade baixa: carro parado na ciclofaixa soltando fumaça
    const dir = longoEmX(w, d) ? 'x' : 'y';
    const [cx, cy] = dir === 'x' ? [x + w / 2 - 0.65, y + d / 2 - 0.31] : [x + w / 2 - 0.31, y + d / 2 - 0.65];
    return { chao, objeto: car(cx, cy, '#757575', dir) + smoke(cx, cy, 0.3, '#616161', c.mobilidade === 1 ? 3 : 2) };
}

function horta(x, y, w, d, c) {
    let chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, c.natureza === 1 ? '#A1887F' : '#8D6E63', 0, 'stroke="#5D4037" stroke-width="1.2"');
    let objeto = '';
    const [n, m] = longoEmX(w, d) ? [w, d] : [d, w];
    for (let i = 0; i < m; i += 1) {
        for (let j = 0; j < n; j += 1) {
            const [cx, cy] = longoEmX(w, d) ? [x + j + 0.5, y + i + 0.5] : [x + i + 0.5, y + j + 0.5];
            objeto += bush(cx, cy, c.natureza === 1 ? 0.38 : 0.45, verde(c.natureza, j % 2 ? '#7CB342' : '#558B2F'));
        }
    }
    chao += quad(x, y, w, 0.06, '#A1887F') + quad(x, y, 0.06, d, '#A1887F');
    return { chao, objeto };
}

function praca(x, y, w, d, c) {
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#D7CCC8')
        + poly(groundEllipse(x + w / 2, y + d / 2, Math.min(w, d) * 0.28, Math.min(w, d) * 0.28), verde(c.natureza, '#A5D6A7'), 'stroke="#8D6E63" stroke-width="1"');
    const objeto = arvore(x + 0.6, y + 0.6, 0.6, '#43A047', c.natureza) + arvore(x + w - 0.6, y + 0.6, 0.55, '#388E3C', c.natureza)
        + arvore(x + 0.6, y + d - 0.6, 0.55, '#66BB6A', c.natureza) + arvore(x + w - 0.6, y + d - 0.6, 0.6, '#2E7D32', c.natureza)
        + bench(x + w / 2 - 0.45, y + d - 0.9, c.natureza === 1);
    return { chao, objeto };
}

function pomar(x, y, w, d, c) {
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, verde(c.natureza, '#9CCC65'));
    let objeto = '';
    for (let i = 0.7; i < w; i += 1.3) {
        for (let j = 0.7; j < d; j += 1.3) {
            objeto += arvore(x + i, y + j, 0.5, '#558B2F', c.natureza);
            if (c.natureza >= 3) {   // frutas só com a natureza saudável
                const [fx, fy] = p(x + i, y + j, 1.3);
                objeto += `<circle cx="${f(fx - 4)}" cy="${f(fy)}" r="1.8" fill="#FF7043"/><circle cx="${f(fx + 3)}" cy="${f(fy - 3)}" r="1.8" fill="#FFA726"/>`;
            }
        }
    }
    return { chao, objeto };
}

function miniUsinaSolar(x, y, w, d, c) {
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#BDBDBD');
    let objeto = '';
    for (let j = 0.15; j + 0.75 <= d; j += 1) {   // fileiras ao longo de x
        objeto += solarRow(x + 0.2, y + j, w - 0.4);
        // Poeira e fuligem sobre os painéis quando a energia está em estágio baixo
        if (c.energia <= 2) {
            const [x0, x1, y0, y1] = [x + 0.2, x + w - 0.2, y + j, y + j + 0.75];   // mesmo plano inclinado do painel
            objeto += poly([[x0, y0, 0.71], [x1, y0, 0.71], [x1, y1, 0.26], [x0, y1, 0.26]], c.energia === 1 ? 'rgba(121,85,72,0.6)' : 'rgba(141,110,99,0.35)');
        }
    }
    return { chao, objeto };
}

/** Lago: água conforme o indicador de Água (mesmas cores do lago do centro). */
function lagoArtificial(x, y, w, d, c) {
    const cx = x + w / 2, cy = y + d / 2;
    const e = c.agua;
    let chao = poly(groundEllipse(cx, cy, w / 2 - 0.1, d / 2 - 0.1, 0, 24, 0.05), MARGEM[e - 1])
        + poly(groundEllipse(cx, cy, w / 2 - 0.45, d / 2 - 0.45, 0, 24, 0.05), AGUA[e - 1], `stroke="${shade(AGUA[e - 1], -0.2)}" stroke-width="1"`);
    let objeto = '';
    if (e === 1) chao += floatingTrash(cx - 0.6, cy + 0.2, '#ECEFF1') + floatingTrash(cx + 0.5, cy - 0.4, '#90CAF9') + floatingTrash(cx, cy + 0.8, '#FFCC80');
    if (e === 2) chao += floatingTrash(cx + 0.4, cy, '#ECEFF1');
    if (e >= 3) {
        chao += lilyPad(cx - 0.5, cy + 0.3, e === 4) + lilyPad(cx + 0.6, cy - 0.4) + ripples(cx, cy, 0.6, 0.4);
        objeto = reeds(x + 0.5, y + d - 0.6, 4) + reeds(x + w - 0.6, y + 0.6, 6);
    }
    if (e === 4) chao += duck(cx + 0.2, cy + 0.5);
    return { chao, objeto };
}

function escola(x, y, w, d) {
    const h = 1.8;
    let objeto = box(x + 0.25, y + 0.25, w - 0.5, d - 0.5, h, '#FFE0B2', { top: '#81C784' });
    for (let i = 0.6; i < w - 0.6; i += 0.8) {
        objeto += poly([[x + i, y + d - 0.25, 0.7], [x + i + 0.45, y + d - 0.25, 0.7], [x + i + 0.45, y + d - 0.25, 1.2], [x + i, y + d - 0.25, 1.2]], '#B3E5FC', 'stroke="rgba(0,0,0,0.3)" stroke-width="0.6"');
    }
    objeto += objetoEm(x + w - 0.4, y + 0.4, h, () => {
        const [bx, by] = p(x + w - 0.4, y + 0.4, h);
        return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(bx)}" y2="${f(by - 30)}" stroke="#546E7A" stroke-width="1.6"/>`
            + `<polygon points="${f(bx)},${f(by - 30)} ${f(bx + 14)},${f(by - 25)} ${f(bx)},${f(by - 20)}" fill="#43A047"/>`;
    });
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#CFD8DC'), objeto };
}

/* ---------------------------------------------------------------------------
   Estruturas de cidade (migração 012): vias, comércio e lazer, serviços e decoração
   --------------------------------------------------------------------------- */

const VIDRO = 'stroke="rgba(0,0,0,0.3)" stroke-width="0.6"';
const ASFALTO = '#546E7A';

/** Janelas nas duas faces visíveis de um bloco (frente esquerda y+d e frente direita x+w). */
function janelas(x, y, w, d, h, { cor = '#B3E5FC', andares = 1, z0 = 0.3, alt = 0.38 } = {}) {
    let out = '';
    for (let a = 0; a < andares; a += 1) {
        const z = z0 + (a * (h - z0)) / andares;
        for (let i = x + 0.25; i + 0.32 <= x + w - 0.12; i += 0.62) {
            out += poly([[i, y + d, z], [i + 0.32, y + d, z], [i + 0.32, y + d, z + alt], [i, y + d, z + alt]], cor, VIDRO);
        }
        for (let j = y + 0.25; j + 0.32 <= y + d - 0.12; j += 0.62) {
            out += poly([[x + w, j, z], [x + w, j + 0.32, z], [x + w, j + 0.32, z + alt], [x + w, j, z + alt]], cor, VIDRO);
        }
        // Girada, as faces de trás podem ficar à vista: janelas nelas também (as escondidas são descartadas pelo giro)
        if (girando()) {
            for (let i = x + 0.25; i + 0.32 <= x + w - 0.12; i += 0.62) {
                out += poly([[i, y, z], [i + 0.32, y, z], [i + 0.32, y, z + alt], [i, y, z + alt]], cor, VIDRO);
            }
            for (let j = y + 0.25; j + 0.32 <= y + d - 0.12; j += 0.62) {
                out += poly([[x, j, z], [x, j + 0.32, z], [x, j + 0.32, z + alt], [x, j, z + alt]], cor, VIDRO);
            }
        }
    }
    return out;
}

/** Faixa colorida (letreiro/toldo) na face da frente (y+d). */
function faixaFrente(x, y, w, d, z, cor, alt = 0.22) {
    return poly([[x + 0.1, y + d, z], [x + w - 0.1, y + d, z], [x + w - 0.1, y + d, z + alt], [x + 0.1, y + d, z + alt]], cor, 'stroke="rgba(0,0,0,0.25)" stroke-width="0.6"');
}

/** Porta na face da frente (y+d). */
function porta(x, y, d, cor = '#6D4C41', larg = 0.36, alt = 0.7) {
    return poly([[x, y + d, 0], [x + larg, y + d, 0], [x + larg, y + d, alt], [x, y + d, alt]], cor, VIDRO);
}

/** Bloco com piso, paredes, janelas e cobertura plana. */
function edificio(x, y, w, d, { h = 1.2, parede = '#ECEFF1', teto, piso = '#CFD8DC', inset = 0.2, andares = 1, janela } = {}) {
    const [bx, by, bw, bd] = [x + inset, y + inset, w - inset * 2, d - inset * 2];
    return {
        chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, piso),
        corpo: box(bx, by, bw, bd, h, parede, { top: teto || shade(parede, -0.12) }) + janelas(bx, by, bw, bd, h, { andares, cor: janela }),
        b: [bx, by, bw, bd],
    };
}

/** Saco/pilha de lixo pequeno (Reciclagem baixa). */
function lixoSolto(x, y, c, seed) {
    if (c.reciclagem >= 3) return '';
    return trashPile(x, y, c.reciclagem === 1 ? 0.45 : 0.32, seed);
}

/* --- Vias --- */

function rua(x, y, w, d) {
    let chao = quad(x, y, w, d, ASFALTO, 0, 'stroke="#455A64" stroke-width="0.6"');
    for (let i = 0.12; i < 1; i += 0.5) {
        chao += quad(x + i, y + d / 2 - 0.03, 0.26, 0.06, '#FFF59D');
    }
    return { chao, objeto: '' };
}

function cruzamento(x, y, w, d) {
    let chao = quad(x, y, w, d, ASFALTO, 0, 'stroke="#455A64" stroke-width="0.6"');
    for (let i = 0.2; i < 0.85; i += 0.16) {
        chao += quad(x + i, y + 0.04, 0.08, 0.16, '#ECEFF1') + quad(x + i, y + d - 0.2, 0.08, 0.16, '#ECEFF1')
            + quad(x + 0.04, y + i, 0.16, 0.08, '#ECEFF1') + quad(x + w - 0.2, y + i, 0.16, 0.08, '#ECEFF1');
    }
    return { chao, objeto: '' };
}

function calcada(x, y, w, d) {
    const chao = quad(x, y, w, d, '#CFD8DC', 0, 'stroke="#90A4AE" stroke-width="0.8"')
        + quad(x, y, w / 2, d / 2, 'none', 0, 'stroke="#B0BEC5" stroke-width="0.6"')
        + quad(x + w / 2, y + d / 2, w / 2, d / 2, 'none', 0, 'stroke="#B0BEC5" stroke-width="0.6"');
    return { chao, objeto: '' };
}

/** Estacionamento: com Mobilidade baixa fica lotado e com fumaça. */
function estacionamento(x, y, w, d, c) {
    const emX = longoEmX(w, d);
    const n = Math.max(w, d);
    let chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, ASFALTO, 0, 'stroke="#455A64" stroke-width="0.8"');
    for (let i = 1; i < n; i += 1) chao += emX ? quad(x + i - 0.03, y + 0.1, 0.06, 0.7, '#ECEFF1') : quad(x + 0.1, y + i - 0.03, 0.7, 0.06, '#ECEFF1');
    const cheio = c.mobilidade <= 2;
    const cores = ['#E53935', '#1E88E5', '#FDD835', '#8E24AA', '#43A047'];
    let objeto = '';
    const vagas = cheio ? n : 1;
    for (let i = 0; i < vagas; i += 1) {
        objeto += emX ? car(x + i + 0.18, y + 0.15, cores[i % cores.length], 'y') : car(x + 0.15, y + i + 0.18, cores[i % cores.length], 'x');
    }
    if (cheio) objeto += smoke(x + w / 2, y + d / 2, 0.4, '#757575', c.mobilidade === 1 ? 3 : 2);
    return { chao, objeto };
}

/* --- Comércio e lazer --- */

function bar(x, y, w, d, c) {
    const e = edificio(x, y, w, d, { h: 1.1, parede: '#A1887F', teto: '#6D4C41', piso: '#D7CCC8', janela: '#FFE082' });
    const [bx, by, bw, bd] = e.b;
    const objeto = e.corpo + faixaFrente(bx, by, bw, bd, 0.85, '#C62828') + porta(bx + bw / 2 - 0.18, by, bd)
        + box(x + 0.08, y + d - 0.18, 0.22, 0.14, 0.3, '#5D4037') + box(x + w - 0.3, y + d - 0.18, 0.22, 0.14, 0.3, '#5D4037');
    return { chao: e.chao, objeto: objeto + lixoSolto(x + w - 0.15, y + d - 0.12, c, 3) };
}

/** Balada: luzes coloridas; com Energia baixa, fumaça do gerador. */
function balada(x, y, w, d, c) {
    const e = edificio(x, y, w, d, { h: 1.6, parede: '#37474F', teto: '#263238', piso: '#455A64', janela: '#CE93D8' });
    const [bx, by, bw, bd] = e.b;
    let objeto = e.corpo + faixaFrente(bx, by, bw, bd, 1.15, '#D81B60', 0.25) + porta(bx + bw / 2 - 0.2, by, bd, '#000000', 0.4);
    const luzes = ['#FF4081', '#40C4FF', '#FFEB3B', '#69F0AE'];
    luzes.forEach((cor, i) => {
        const [lx, ly] = p(bx + 0.3 + (i % 2) * (bw - 0.6), by + 0.3 + Math.floor(i / 2) * (bd - 0.6), 1.6);
        objeto += `<line x1="${f(lx)}" y1="${f(ly)}" x2="${f(lx + (i % 2 ? 10 : -10))}" y2="${f(ly - 26)}" stroke="${cor}" stroke-width="3" opacity="0.55" stroke-linecap="round"/>`
            + `<circle cx="${f(lx)}" cy="${f(ly)}" r="2.4" fill="${cor}"/>`;
    });
    if (c.energia <= 2) objeto += smoke(bx + bw - 0.3, by + 0.3, 1.6, '#616161', c.energia === 1 ? 3 : 2);
    return { chao: e.chao, objeto };
}

function lanchonete(x, y, w, d, c) {
    const e = edificio(x, y, w, d, { h: 1, parede: '#FFE0B2', teto: '#E53935', piso: '#FFF3E0', janela: '#B3E5FC' });
    const [bx, by, bw, bd] = e.b;
    const [sx, sy] = p(bx + bw / 2, by + bd / 2, 1.0);
    const objeto = e.corpo + faixaFrente(bx, by, bw, bd, 0.78, '#FDD835') + porta(bx + 0.2, by, bd)
        + `<line x1="${f(sx)}" y1="${f(sy)}" x2="${f(sx)}" y2="${f(sy - 16)}" stroke="#546E7A" stroke-width="1.6"/>`
        + `<circle cx="${f(sx)}" cy="${f(sy - 20)}" r="6" fill="#FDD835" stroke="#E65100" stroke-width="1.2"/>`;
    return { chao: e.chao, objeto: objeto + lixoSolto(x + 0.3, y + d - 0.1, c, 5) + lixoSolto(x + w - 0.12, y + d - 0.5, c, 9) };
}

function mercado(x, y, w, d, c) {
    const e = edificio(x, y, w, d, { h: 1.2, parede: '#E3F2FD', teto: '#90A4AE', piso: '#CFD8DC', janela: '#81D4FA' });
    const [bx, by, bw, bd] = e.b;
    const objeto = e.corpo + faixaFrente(bx, by, bw, bd, 0.92, '#2E7D32', 0.24) + porta(bx + bw / 2 - 0.25, by, bd, '#B3E5FC', 0.5)
        + box(x + 0.1, y + d - 0.25, 0.3, 0.18, 0.25, '#B0BEC5');   // carrinhos
    return { chao: e.chao, objeto: objeto + lixoSolto(x + w - 0.15, y + d - 0.1, c, 7) + lixoSolto(x + w - 0.1, y + 0.6, c, 11) };
}

function padaria(x, y, w, d) {
    const [bx, by, bw, bd] = [x + 0.2, y + 0.2, w - 0.4, d - 0.4];
    const objeto = box(bx, by, bw, bd, 1, '#FFE0B2') + gableRoof(bx, by, bw, bd, 1, 0.55, '#8D6E63')
        + janelas(bx, by, bw, bd, 1, { cor: '#FFF59D' }) + faixaFrente(bx, by, bw, bd, 0.72, '#EF6C00') + porta(bx + bw - 0.5, by, bd);
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#EFEBE9'), objeto };
}

/** Posto: manchas de óleo, carro e fumaça com Mobilidade baixa; carregador elétrico com Mobilidade alta. */
function posto(x, y, w, d, c) {
    const sujo = c.mobilidade <= 2;
    let chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#B0BEC5', 0, 'stroke="#78909C" stroke-width="0.8"');
    if (sujo) chao += poly(groundEllipse(x + w / 2 - 0.4, y + d / 2 + 0.3, 0.35, 0.22), 'rgba(33,33,33,0.55)') + poly(groundEllipse(x + w / 2 + 0.5, y + d / 2 - 0.2, 0.25, 0.16), 'rgba(33,33,33,0.45)');
    const loja = box(x + 0.15, y + 0.15, w - 0.3, 0.7, 0.9, '#ECEFF1', { top: '#CFD8DC' }) + faixaFrente(x + 0.15, y + 0.15, w - 0.3, 0.7, 0.6, sujo ? '#E53935' : '#43A047');
    const bomba = (bx, by) => box(bx, by, 0.22, 0.22, 0.55, sujo ? '#E53935' : '#43A047', { top: '#ECEFF1' });
    const postes = [[x + 0.5, y + 1.3], [x + w - 0.5, y + 1.3], [x + 0.5, y + d - 0.4], [x + w - 0.5, y + d - 0.4]]
        .map(([px, py]) => objetoEm(px, py, 0, () => `<polyline points="${pts([[px, py, 0], [px, py, 1.3]])}" stroke="#90A4AE" stroke-width="2"/>`)).join('');
    let objeto = loja + postes + bomba(x + w / 2 - 0.6, y + d / 2 + 0.1) + bomba(x + w / 2 + 0.4, y + d / 2 + 0.1)
        + box(x + 0.3, y + 1.15, w - 0.6, d - 1.4, 0.12, sujo ? '#F5F5F5' : '#C8E6C9', { z0: 1.3, top: sujo ? '#FFFFFF' : '#A5D6A7' });
    objeto += sujo
        ? car(x + 0.2, y + d - 0.8, '#757575', 'x') + smoke(x + 0.3, y + d - 0.5, 0.3, '#616161', c.mobilidade === 1 ? 3 : 2)
        : car(x + 0.2, y + d - 0.8, '#26A69A', 'x');
    return { chao, objeto };
}

/** Lava-rápido: poça suja com Água baixa, espuma azul com Água alta. */
function lavaRapido(x, y, w, d, c) {
    const limpo = c.agua >= 3;
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#CFD8DC')
        + poly(groundEllipse(x + w / 2, y + d - 0.45, 0.6, 0.3, 0, 18, 0.05), limpo ? 'rgba(129,212,250,0.8)' : 'rgba(121,85,72,0.75)');
    const [bx, by, bw, bd] = [x + 0.2, y + 0.15, w - 0.4, d - 0.9];
    let objeto = box(bx, by, bw, bd, 1, '#4FC3F7', { top: '#0288D1' }) + faixaFrente(bx, by, bw, bd, 0.75, '#FFFFFF')
        + poly([[bx + 0.2, by + bd, 0], [bx + bw - 0.2, by + bd, 0], [bx + bw - 0.2, by + bd, 0.65], [bx + 0.2, by + bd, 0.65]], '#263238');
    const [ex, ey] = p(x + w / 2, y + d - 0.45, 0.1);
    objeto += [[-6, 0], [4, -3], [8, 2]].map(([dx, dy]) => `<circle cx="${f(ex + dx)}" cy="${f(ey + dy)}" r="2.2" fill="${limpo ? '#FFFFFF' : '#BCAAA4'}" opacity="0.9"/>`).join('');
    return { chao, objeto };
}

function quadra(x, y, w, d) {
    const linha = 'stroke="#FFFFFF" stroke-width="1.2"';
    const emX = longoEmX(w, d);
    let chao = quad(x + 0.1, y + 0.1, w - 0.2, d - 0.2, '#43A047', 0, 'stroke="#2E7D32" stroke-width="1"')
        + quad(x + 0.3, y + 0.3, w - 0.6, d - 0.6, 'none', 0, linha)
        + poly(groundEllipse(x + w / 2, y + d / 2, 0.45, 0.45), 'none', linha);
    chao += emX
        ? `<polyline points="${pts([[x + w / 2, y + 0.3], [x + w / 2, y + d - 0.3]])}" ${linha} fill="none"/>`
        : `<polyline points="${pts([[x + 0.3, y + d / 2], [x + w - 0.3, y + d / 2]])}" ${linha} fill="none"/>`;
    const gol = (gx, gy, gw, gd) => objetoEm(gx + gw / 2, gy + gd / 2, 0, () => `<polyline points="${pts([[gx, gy, 0], [gx, gy, 0.45], [gx + gw, gy + gd, 0.45], [gx + gw, gy + gd, 0]])}" stroke="#ECEFF1" stroke-width="2" fill="none"/>`);
    const objeto = emX
        ? gol(x + 0.3, y + d / 2 - 0.4, 0, 0.8) + gol(x + w - 0.3, y + d / 2 - 0.4, 0, 0.8)
        : gol(x + w / 2 - 0.4, y + 0.3, 0.8, 0) + gol(x + w / 2 - 0.4, y + d - 0.3, 0.8, 0);
    return { chao, objeto };
}

function parquinho(x, y, w, d) {
    const chao = quad(x + 0.08, y + 0.08, w - 0.16, d - 0.16, '#FFE082', 0, 'stroke="#FFB300" stroke-width="0.8"');
    const haste = (a, b) => `<polyline points="${pts([a, b])}" stroke="#E53935" stroke-width="2"/>`;
    // Balanço
    let objeto = objetoEm(x + 0.75, y + 0.3, 0, () => haste([x + 0.3, y + 0.3, 0], [x + 0.3, y + 0.3, 0.9]) + haste([x + 1.2, y + 0.3, 0], [x + 1.2, y + 0.3, 0.9])
        + haste([x + 0.3, y + 0.3, 0.9], [x + 1.2, y + 0.3, 0.9])
        + `<polyline points="${pts([[x + 0.75, y + 0.3, 0.9], [x + 0.75, y + 0.3, 0.3]])}" stroke="#5D4037" stroke-width="1"/>`
        + box(x + 0.65, y + 0.22, 0.2, 0.16, 0.05, '#1E88E5', { z0: 0.25 }));
    // Escorregador
    objeto += box(x + w - 0.6, y + d - 0.9, 0.35, 0.35, 0.8, '#1E88E5')
        + poly([[x + w - 0.6, y + d - 0.55, 0.8], [x + w - 0.25, y + d - 0.55, 0.8], [x + w - 0.25, y + d - 0.1, 0.05], [x + w - 0.6, y + d - 0.1, 0.05]], '#FDD835', 'stroke="rgba(0,0,0,0.25)" stroke-width="0.6"');
    return { chao, objeto };
}

/* --- Serviços e moradia --- */

function predio(x, y, w, d) {
    const e = edificio(x, y, w, d, { h: 3.2, parede: '#B0BEC5', teto: '#78909C', andares: 5, janela: '#E1F5FE' });
    const [bx, by, bw, bd] = e.b;
    return { chao: e.chao, objeto: e.corpo + porta(bx + bw / 2 - 0.18, by, bd, '#455A64') + box(bx + 0.3, by + 0.3, 0.5, 0.4, 0.3, '#90A4AE', { z0: 3.2 }) };
}

function hospital(x, y, w, d) {
    const e = edificio(x, y, w, d, { h: 2.2, parede: '#FFFFFF', teto: '#ECEFF1', andares: 3, janela: '#B3E5FC' });
    const [bx, by, bw, bd] = e.b;
    const [cx, cy] = [bx + bw / 2, by + bd / 2];
    const cruz = quad(cx - 0.45, cy - 0.12, 0.9, 0.24, '#E53935', 2.21) + quad(cx - 0.12, cy - 0.45, 0.24, 0.9, '#E53935', 2.21);
    return { chao: e.chao, objeto: e.corpo + cruz + faixaFrente(bx, by, bw, bd, 0.05, '#E53935', 0.18) + porta(bx + bw / 2 - 0.3, by, bd, '#B3E5FC', 0.6, 0.6) };
}

function bombeiros(x, y, w, d) {
    const [bx, by, bw, bd] = [x + 0.2, y + 0.2, w - 0.4, d - 0.4];
    let objeto = box(bx, by, bw, bd, 1.5, '#E53935', { top: '#B71C1C' }) + faixaFrente(bx, by, bw, bd, 1.15, '#FFFFFF', 0.2);
    for (let i = bx + 0.2; i + 0.7 <= bx + bw; i += 0.9) {
        objeto += poly([[i, by + bd, 0], [i + 0.7, by + bd, 0], [i + 0.7, by + bd, 0.9], [i, by + bd, 0.9]], '#ECEFF1', VIDRO);
    }
    objeto += box(bx + bw - 0.6, by, 0.5, 0.5, 1.0, '#C62828', { z0: 1.5 });
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#CFD8DC'), objeto };
}

function delegacia(x, y, w, d) {
    const e = edificio(x, y, w, d, { h: 1.4, parede: '#E3F2FD', teto: '#1565C0', janela: '#90CAF9' });
    const [bx, by, bw, bd] = e.b;
    const [lx, ly] = p(bx + bw / 2, by + bd / 2, 1.4);
    return {
        chao: e.chao,
        objeto: e.corpo + faixaFrente(bx, by, bw, bd, 1.05, '#1565C0') + porta(bx + bw / 2 - 0.2, by, bd, '#1A237E', 0.4)
            + `<rect x="${f(lx - 4)}" y="${f(ly - 6)}" width="8" height="6" rx="2" fill="#E53935"/><rect x="${f(lx)}" y="${f(ly - 6)}" width="4" height="6" rx="1" fill="#1E88E5"/>`,
    };
}

function prefeitura(x, y, w, d) {
    const [bx, by, bw, bd] = [x + 0.4, y + 0.4, w - 0.8, d - 0.8];
    let objeto = box(bx, by, bw, bd, 1.6, '#FFF8E1', { top: '#EFEBE9' }) + janelas(bx, by, bw, bd, 1.6, { andares: 2, cor: '#B3E5FC' });
    for (let i = bx + 0.2; i < bx + bw - 0.1; i += 0.55) {
        objeto += objetoEm(i, by + bd + 0.25, 0, () => `<polyline points="${pts([[i, by + bd + 0.25, 0], [i, by + bd + 0.25, 1.4]])}" stroke="#D7CCC8" stroke-width="3"/>`);
    }
    objeto += box(bx, by + bd, bw, 0.3, 0.12, '#D7CCC8', { z0: 1.4 }) + gableRoof(bx, by, bw, bd, 1.6, 0.6, '#A1887F');
    objeto += objetoEm(bx + bw / 2, by + bd / 2, 2.2, () => {
        const [fx, fy] = p(bx + bw / 2, by + bd / 2, 2.2);
        return `<line x1="${f(fx)}" y1="${f(fy)}" x2="${f(fx)}" y2="${f(fy - 26)}" stroke="#546E7A" stroke-width="1.6"/>`
            + `<polygon points="${f(fx)},${f(fy - 26)} ${f(fx + 14)},${f(fy - 21)} ${f(fx)},${f(fy - 16)}" fill="#1E88E5"/>`;
    });
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#D7CCC8') + quad(x + 0.3, y + d - 0.4, w - 0.6, 0.35, '#BCAAA4'), objeto };
}

/* --- Decoração --- */

function arbusto(x, y, w, d, c) {
    return { chao: '', objeto: c.natureza === 1 ? bush(x + 0.5, y + 0.5, 0.5, '#8D6E63') : bush(x + 0.5, y + 0.5, 0.6, verde(c.natureza, '#558B2F')) };
}

function arvoreDecorativa(x, y, w, d, c) {
    return { chao: '', objeto: arvore(x + 0.5, y + 0.5, 0.65, '#43A047', c.natureza) };
}

function canteiro(x, y, w, d, c) {
    let chao = quad(x + 0.12, y + 0.12, w - 0.24, d - 0.24, '#795548', 0, 'stroke="#A1887F" stroke-width="1.5"');
    chao += c.natureza === 1 ? cracks(x + 0.2, y + 0.2, 0.6, 0.6, 2, 3) : flowers(x + 0.2, y + 0.2, 0.6, 0.6, c.natureza === 2 ? 3 : 7, 4);
    return { chao, objeto: c.natureza >= 2 ? grassTufts(x + 0.25, y + 0.25, 0.5, 0.5, 2, 6, verde(c.natureza, '#7CB342')) : '' };
}

function poste(x, y) {
    const [bx, by] = p(x + 0.5, y + 0.5, 0);
    const [tx, ty] = p(x + 0.5, y + 0.5, 1.9);
    return {
        chao: '',
        objeto: `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#455A64" stroke-width="2"/>`
            + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx + 7)}" y2="${f(ty + 2)}" stroke="#455A64" stroke-width="2"/>`
            + `<circle cx="${f(tx + 7)}" cy="${f(ty + 4)}" r="3" fill="#FFF59D" stroke="#F9A825" stroke-width="0.8"/>`,
    };
}

function bancoDecorativo(x, y, w, d, c) {
    return { chao: '', objeto: bench(x + 0.05, y + 0.35, c.natureza === 1) };
}

function cercaDecorativa(x, y, w) {
    return { chao: '', objeto: cerca(x, y + 0.5, x + w, y + 0.5, 0.5) };
}

/** Fonte: água da cor do estágio de Água (como o lago). */
function fonte(x, y, w, d, c) {
    const cx = x + w / 2, cy = y + d / 2;
    const agua = AGUA[c.agua - 1];
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#D7CCC8')
        + poly(groundEllipse(cx, cy, 0.85, 0.85), '#BCAAA4', 'stroke="#8D6E63" stroke-width="1"')
        + poly(groundEllipse(cx, cy, 0.68, 0.68, 0.12), agua, `stroke="${shade(agua, -0.2)}" stroke-width="0.8"`);
    const [jx, jy] = p(cx, cy, 0.7);
    let objeto = box(cx - 0.12, cy - 0.12, 0.24, 0.24, 0.6, '#A1887F', { top: '#BCAAA4' });
    if (c.agua >= 2) objeto += `<path d="M${f(jx)} ${f(jy)} q-8 -14 -14 6 M${f(jx)} ${f(jy)} q8 -14 14 6" stroke="#B3E5FC" stroke-width="2" fill="none" opacity="0.9"/>`;
    return { chao, objeto };
}

/* ---------------------------------------------------------------------------
   Avenidas 4×4 (migração 019): mesmo desenho das avenidas do centro (regions.js `vias`):
   2 células de asfalto no meio, calçada de 0,3 dos lados, faixa tracejada e faixas de
   pedestres. Desenhadas de frente (braços W–E; curva W–S; T W–E–S) e giradas pelo motor.
   --------------------------------------------------------------------------- */

const AV = { asfalto: ['#455A64', '#546E7A'], calcada: ['#9E9E9E', '#CFD8DC'] };
const corAv = (tipo, c) => AV[tipo][c.mobilidade === 1 ? 0 : 1];

/** Trecho de avenida ao longo de x (de x0 a x1) com o eixo em y = ey. */
function bracoX(x0, x1, ey, c, tracejado = true) {
    let s = quad(x0, ey - 1.3, x1 - x0, 2.6, corAv('calcada', c)) + quad(x0, ey - 1, x1 - x0, 2, corAv('asfalto', c));
    if (tracejado) for (let i = x0 + 0.3; i + 0.75 <= x1 + 0.01; i += 1.4) s += quad(i, ey - 0.06, 0.75, 0.12, '#FFFFFF');
    return s;
}
/** Trecho ao longo de y (de y0 a y1) com o eixo em x = ex. */
function bracoY(y0, y1, ex, c, tracejado = true) {
    let s = quad(ex - 1.3, y0, 2.6, y1 - y0, corAv('calcada', c)) + quad(ex - 1, y0, 2, y1 - y0, corAv('asfalto', c));
    if (tracejado) for (let i = y0 + 0.3; i + 0.75 <= y1 + 0.01; i += 1.4) s += quad(ex - 0.06, i, 0.12, 0.75, '#FFFFFF');
    return s;
}
/** Faixa de pedestres atravessando um braço (ao longo de x: listras em x = px). */
function faixaPedestre(px, ey, emX) {
    let s = '';
    for (let k = 0; k < 5; k += 1) {
        const o = ey - 0.9 + k * 0.38;
        s += emX ? quad(px, o, 0.55, 0.2, '#ECEFF1') : quad(o, px, 0.2, 0.55, '#ECEFF1');
    }
    return s;
}
const buracos = (cx, cy, c) => (c.mobilidade === 1 ? poly(groundEllipse(cx, cy, 0.3, 0.2), '#263238') : '');

function avenida(x, y, w, d, c) {
    return { chao: bracoX(x, x + w, y + d / 2, c) + buracos(x + 1.2, y + d / 2 + 0.4, c), objeto: '' };
}

function avenidaCruzamento(x, y, w, d, c) {
    const [cx, cy] = [x + w / 2, y + d / 2];
    const chao = bracoX(x, x + w, cy, c, false) + bracoY(y, y + d, cx, c, false)
        + quad(cx - 1, cy - 1, 2, 2, corAv('asfalto', c))
        + faixaPedestre(x + 0.05, cy, true) + faixaPedestre(x + w - 0.6, cy, true)
        + faixaPedestre(y + 0.05, cx, false) + faixaPedestre(y + d - 0.6, cx, false);
    return { chao, objeto: '' };
}

/** Curva: entra pelo lado oeste (x) e sai pelo sul (y + d). */
function avenidaCurva(x, y, w, d, c) {
    const [cx, cy] = [x + w / 2, y + d / 2];
    const chao = quad(x, cy - 1.3, cx + 1.3 - x, 2.6, corAv('calcada', c)) + quad(cx - 1.3, cy - 1.3, 2.6, y + d - cy + 1.3, corAv('calcada', c))
        + quad(x, cy - 1, cx + 1 - x, 2, corAv('asfalto', c)) + quad(cx - 1, cy - 1, 2, y + d - cy + 1, corAv('asfalto', c))
        + quad(x + 0.3, cy - 0.06, 0.75, 0.12, '#FFFFFF') + quad(cx - 0.06, y + d - 1.05, 0.12, 0.75, '#FFFFFF');
    return { chao, objeto: '' };
}

/** T: eixo oeste–leste com saída para o sul. */
function avenidaT(x, y, w, d, c) {
    const [cx, cy] = [x + w / 2, y + d / 2];
    const chao = bracoX(x, x + w, cy, c, false) + bracoY(cy, y + d, cx, c, false)
        + quad(x + 0.3, cy - 0.06, 0.4, 0.12, '#FFFFFF') + quad(x + w - 0.7, cy - 0.06, 0.4, 0.12, '#FFFFFF')
        + faixaPedestre(y + d - 0.6, cx, false);
    return { chao, objeto: '' };
}

/* ---------------------------------------------------------------------------
   Prédio personalizado (administração, migração 018)
   --------------------------------------------------------------------------- */

/** Paletas do prédio personalizado (as chaves são validadas no PHP: APARENCIA_*). */
export const PALETA_APARENCIA = {
    parede: { branco: '#ECEFF1', creme: '#FFF3E0', cinza: '#B0BEC5', verde: '#C8E6C9', azul: '#BBDEFB', amarelo: '#FFF59D', terracota: '#FFAB91', tijolo: '#C08A6A' },
    telhado: { verde: '#66BB6A', vermelho: '#E57373', cinza: '#90A4AE', azul: '#64B5F6', marrom: '#8D6E63', laranja: '#FFB74D' },
    placa: { verde: '#2E7D32', azul: '#1565C0', laranja: '#EF6C00', vermelho: '#C62828', roxo: '#6A1B9A' },
};
const APARENCIA_PADRAO = { parede: 'branco', telhado: 'verde', formato: 'plano', andares: 1, toldo: false, icone: '', placa: 'verde', detalhes: [] };

function corPisoCena(tipo, c) {
    if (tipo === 'grama') return c.natureza === 1 ? '#A1887F' : c.natureza === 2 ? '#AED581' : c.natureza === 3 ? '#81C784' : '#66BB6A';
    if (tipo === 'agua') return AGUA[(c.agua || 3) - 1] || AGUA[2];
    const mapa = {
        concreto: '#CFD8DC', pedra: '#D7CCC8', madeira: '#BCAAA4',
        terra: c.natureza === 1 ? '#A1887F' : '#8D6E63',
        asfalto: c.mobilidade === 1 ? '#455A64' : '#546E7A',
        areia: '#FFE0B2', faixa_branca: '#ECEFF1',
    };
    return mapa[tipo] || '#CFD8DC';
}

/** Renderiza uma cena 3D composta gerada pela IA local (RN76), reativa aos 4 estágios e às 4 rotações. */
function desenharCenaIA(x, y, w, d, c, a) {
    let chao = '';
    let objeto = '';
    const estagioMedio = Math.round(((c.agua || 3) + (c.natureza || 3) + (c.energia || 3) + (c.mobilidade || 3) + (c.reciclagem || 3)) / 5);
    const desgaste = estagioMedio === 1 ? -0.08 : estagioMedio === 2 ? -0.03 : 0;

    // 1. Piso base do lote da estrutura
    const tipoBase = a.piso || 'grama';
    if (tipoBase !== 'nenhum') {
        chao += quad(x + 0.04, y + 0.04, w - 0.08, d - 0.08, corPisoCena(tipoBase, c));
        if (tipoBase === 'grama' || tipoBase === 'terra') {
            if (c.natureza === 1) chao += cracks(x + 0.15, y + 0.15, Math.max(0.5, w - 0.3), Math.max(0.5, d - 0.3), Math.max(2, Math.round((w * d) / 2)), 17);
            if (c.natureza >= 3) objeto += grassTufts(x + 0.15, y + 0.15, Math.max(0.5, w - 0.3), Math.max(0.5, d - 0.3), Math.max(3, Math.round(w * d)), 23, verde(c.natureza, '#558B2F'));
            if (c.natureza === 4) chao += flowers(x + 0.18, y + 0.18, Math.max(0.5, w - 0.36), Math.max(0.5, d - 0.36), Math.max(4, Math.round(w * d)), 31);
        }
    }

    // 2. Sub-áreas de piso (caminhos, pátios, espelhos d'água, canteiros)
    (a.pisos || []).forEach((ps, idx) => {
        const px = x + (Number(ps.rx) || 0) * w;
        const py = y + (Number(ps.ry) || 0) * d;
        const pw = Math.max(0.15, (Number(ps.rw) || 0.4) * w);
        const pd = Math.max(0.15, (Number(ps.rd) || 0.4) * d);
        const cor = corPisoCena(ps.tipo, c);
        if (ps.forma === 'elipse') {
            const cx = px + pw / 2, cy = py + pd / 2;
            if (ps.tipo === 'agua') {
                chao += poly(groundEllipse(cx, cy, pw / 2, pd / 2, 0, 20, 0.03), MARGEM[(c.agua || 3) - 1]);
            }
            chao += poly(groundEllipse(cx, cy, pw / 2 * 0.88, pd / 2 * 0.88, 0, 20, 0.03), cor, `stroke="${shade(cor, -0.15)}" stroke-width="0.8"`);
            if (ps.tipo === 'agua') {
                if (c.agua <= 2) chao += floatingTrash(cx, cy, '#ECEFF1');
                else chao += lilyPad(cx - pw * 0.15, cy, c.agua === 4) + ripples(cx, cy, Math.min(0.4, pw * 0.25), Math.min(0.25, pd * 0.2));
            }
        } else {
            chao += quad(px, py, pw, pd, cor, 0.01, ps.tipo === 'agua' ? `stroke="${shade(cor, -0.2)}" stroke-width="0.8"` : '');
            if (ps.tipo === 'agua' && c.agua >= 3) {
                chao += ripples(px + pw / 2, py + pd / 2, Math.min(0.35, pw * 0.25), Math.min(0.22, pd * 0.2));
            }
        }
        if (ps.tipo === 'grama' && c.natureza === 4) {
            chao += flowers(px + 0.05, py + 0.05, Math.max(0.2, pw - 0.1), Math.max(0.2, pd - 0.1), 3, 40 + idx);
        }
    });

    // 3. Volumes 3D (blocos arquitetônicos, anexos, torres, estufas)
    (a.blocos || []).forEach((b) => {
        const bx = x + (Number(b.rx) || 0.15) * w;
        const by = y + (Number(b.ry) || 0.15) * d;
        const bw = Math.max(0.2, (Number(b.rw) || 0.7) * w);
        const bd = Math.max(0.2, (Number(b.rd) || 0.7) * d);
        const z0 = Math.max(0, Number(b.z0) || 0);
        const h = Math.max(0.15, Number(b.h) || 1.2);
        const andares = Math.max(0, Math.min(6, Number(b.andares) || 0));
        const parede = shade(b.parede || '#ECEFF1', desgaste);
        const topoCor = shade(b.topo || '#66BB6A', desgaste);
        const telhado = b.telhado || 'plano';
        const gramado = verde(c.natureza, '#7CB342');
        const corTetoCaixa = telhado === 'verde' ? gramado : telhado === 'vidro' ? '#B3E5FC' : telhado === 'plano' ? topoCor : shade(parede, -0.1);

        objeto += box(bx, by, bw, bd, h, parede, { z0, top: corTetoCaixa });
        if (b.janelas && andares > 0 && bw >= 0.55 && bd >= 0.55) {
            const corJan = c.energia === 1 ? '#90A4AE' : (b.corJanela || '#B3E5FC');
            objeto += janelas(bx, by, bw, bd, z0 + h, { andares, z0: z0 + 0.22, cor: corJan, alt: Math.min(0.36, (h / Math.max(1, andares)) * 0.48) });
        }
        if (b.porta && z0 < 0.1 && bw >= 0.45) {
            const largP = Math.min(0.36, bw * 0.35);
            const altP = Math.min(0.7, h * 0.65);
            objeto += porta(bx + bw / 2 - largP / 2, by, bd, '#6D4C41', largP, altP);
        }
        if (b.toldo && bw >= 0.5) {
            const zToldo = z0 + Math.min(h - 0.15, 0.35 + (h - 0.3) / Math.max(1, andares) - 0.1);
            objeto += faixaFrente(bx, by, bw, bd, Math.max(z0 + 0.2, zToldo), shade(b.corToldo || topoCor, desgaste), 0.18);
        }

        const zTopo = z0 + h;
        if (telhado === 'duas_aguas') {
            const rise = Math.min(0.95, bd * 0.42);
            objeto += gableRoof(bx, by, bw, bd, zTopo, rise, topoCor);
        } else if (telhado === 'verde' && bw > 0.35 && bd > 0.35) {
            objeto += quad(bx + 0.1, by + 0.1, bw - 0.2, bd - 0.2, shade(gramado, -0.12), zTopo + 0.01);
            if (c.natureza === 4) {
                objeto += poly([[bx + bw * 0.3, by + bd * 0.5, zTopo + 0.03], [bx + bw * 0.7, by + bd * 0.5, zTopo + 0.03], [bx + bw * 0.5, by + bd * 0.7, zTopo + 0.03]], '#FFEB3B');
            }
        } else if (telhado === 'vidro' && bw > 0.35 && bd > 0.35) {
            objeto += quad(bx + 0.08, by + 0.08, bw - 0.16, bd - 0.16, 'rgba(225,245,254,0.75)', zTopo + 0.01, 'stroke="#0288D1" stroke-width="0.9"');
        }

        if (b.solar && telhado !== 'duas_aguas' && bw >= 0.5 && bd >= 0.5) {
            for (let j = by + 0.12; j + 0.45 <= by + bd - 0.05; j += 0.65) {
                const [x0, x1, y0, y1] = [bx + 0.12, bx + bw - 0.12, j, j + 0.45];
                objeto += poly([[x0, y0, zTopo + 0.35], [x1, y0, zTopo + 0.35], [x1, y1, zTopo + 0.06], [x0, y1, zTopo + 0.06]], '#1565C0', 'stroke="#ECEFF1" stroke-width="0.9"');
                if (c.energia <= 2) {
                    objeto += poly([[x0, y0, zTopo + 0.36], [x1, y0, zTopo + 0.36], [x1, y1, zTopo + 0.07], [x0, y1, zTopo + 0.07]], c.energia === 1 ? 'rgba(121,85,72,0.62)' : 'rgba(141,110,99,0.35)');
                }
            }
        }
    });

    // 4. Elementos 3D e ecológicos (ordenados por objetoEm para girar nas 4 direções)
    (a.elementos || []).forEach((el, idx) => {
        const ex = x + (Number(el.rx) || 0.5) * w;
        const ey = y + (Number(el.ry) || 0.5) * d;
        const ez = Math.max(0, Number(el.z0) || 0);
        const s = Math.max(0.35, Math.min(1.8, Number(el.escala) || 1));
        const cor = el.cor || '#43A047';

        switch (el.tipo) {
            case 'arvore':
                objeto += arvore(ex, ey, 0.55 * s, cor, c.natureza);
                if (c.natureza === 4) {
                    objeto += objetoEm(ex, ey, 1.2 * s, () => {
                        const [fx, fy] = p(ex, ey, 1.25 * s);
                        return `<circle cx="${f(fx - 3)}" cy="${f(fy)}" r="1.8" fill="#FF7043"/><circle cx="${f(fx + 3)}" cy="${f(fy - 2)}" r="1.8" fill="#FFD54F"/>`;
                    });
                }
                break;
            case 'pinheiro':
                objeto += c.natureza === 1 ? deadTree(ex, ey, 0.5 * s) : pine(ex, ey, 0.55 * s, verde(c.natureza, cor));
                break;
            case 'arbusto':
                objeto += bush(ex, ey, (c.natureza === 1 ? 0.38 : 0.48) * s, c.natureza === 1 ? '#8D6E63' : verde(c.natureza, cor));
                break;
            case 'flores':
                chao += c.natureza === 1
                    ? cracks(ex - 0.25 * s, ey - 0.25 * s, 0.5 * s, 0.5 * s, 2, 50 + idx)
                    : flowers(ex - 0.3 * s, ey - 0.3 * s, 0.6 * s, 0.6 * s, c.natureza === 2 ? 3 : 7, 50 + idx);
                break;
            case 'horta': {
                const hw = Math.min(w * 0.45, 0.9 * s), hd = Math.min(d * 0.45, 0.9 * s);
                chao += quad(ex - hw / 2, ey - hd / 2, hw, hd, c.natureza === 1 ? '#A1887F' : '#8D6E63', 0.01, 'stroke="#5D4037" stroke-width="0.9"');
                objeto += bush(ex - hw * 0.22, ey - hd * 0.22, 0.32 * s, verde(c.natureza, '#7CB342'))
                    + bush(ex + hw * 0.22, ey - hd * 0.22, 0.32 * s, verde(c.natureza, '#558B2F'))
                    + bush(ex - hw * 0.22, ey + hd * 0.22, 0.32 * s, verde(c.natureza, '#558B2F'))
                    + bush(ex + hw * 0.22, ey + hd * 0.22, 0.32 * s, verde(c.natureza, '#7CB342'));
                break;
            }
            case 'lago': {
                const rx = Math.min(w * 0.42, 0.65 * s), ry = Math.min(d * 0.42, 0.65 * s);
                const e = c.agua || 3;
                chao += poly(groundEllipse(ex, ey, rx, ry, 0, 20, 0.04), MARGEM[e - 1])
                    + poly(groundEllipse(ex, ey, rx * 0.82, ry * 0.82, 0, 20, 0.04), AGUA[e - 1], `stroke="${shade(AGUA[e - 1], -0.2)}" stroke-width="0.8"`);
                if (e <= 2) chao += floatingTrash(ex, ey, '#ECEFF1');
                else {
                    chao += lilyPad(ex - rx * 0.3, ey + ry * 0.2, e === 4) + ripples(ex, ey, rx * 0.4, ry * 0.3);
                    if (e === 4) chao += duck(ex + rx * 0.2, ey);
                }
                break;
            }
            case 'fonte': {
                const r = Math.min(w, d, 1.4) * 0.35 * s;
                const ag = AGUA[(c.agua || 3) - 1];
                chao += poly(groundEllipse(ex, ey, r, r), '#BCAAA4', 'stroke="#8D6E63" stroke-width="0.9"')
                    + poly(groundEllipse(ex, ey, r * 0.8, r * 0.8, 0.08), ag, `stroke="${shade(ag, -0.2)}" stroke-width="0.7"`);
                objeto += box(ex - 0.1 * s, ey - 0.1 * s, 0.2 * s, 0.2 * s, 0.5 * s, '#A1887F', { z0: ez, top: '#BCAAA4' });
                if (c.agua >= 2) {
                    objeto += objetoEm(ex, ey, ez + 0.55 * s, () => {
                        const [jx, jy] = p(ex, ey, ez + 0.58 * s);
                        return `<path d="M${f(jx)} ${f(jy)} q-7 -12 -12 5 M${f(jx)} ${f(jy)} q7 -12 12 5" stroke="#B3E5FC" stroke-width="1.8" fill="none" opacity="0.9"/>`;
                    });
                }
                break;
            }
            case 'painel_solar': {
                const sw = Math.min(w * 0.6, 0.9 * s);
                objeto += solarRow(ex - sw / 2, ey - 0.35, sw);
                if (c.energia <= 2) {
                    const [x0, x1, y0, y1] = [ex - sw / 2, ex + sw / 2, ey - 0.35, ey + 0.4];
                    objeto += poly([[x0, y0, 0.71], [x1, y0, 0.71], [x1, y1, 0.26], [x0, y1, 0.26]], c.energia === 1 ? 'rgba(121,85,72,0.6)' : 'rgba(141,110,99,0.35)');
                }
                break;
            }
            case 'turbina_eolica':
                objeto += objetoEm(ex, ey, ez, () => {
                    const hTorre = 1.9 * s;
                    const [bx, by] = p(ex, ey, ez);
                    const [tx, ty] = p(ex, ey, ez + hTorre);
                    const L = 16 * s;
                    const corPa = c.energia === 1 ? '#B0BEC5' : '#FFFFFF';
                    const corCubo = c.energia >= 3 ? '#43A047' : '#78909C';
                    return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#CFD8DC" stroke-width="${f(3 * s)}" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx)}" y2="${f(ty - L)}" stroke="${corPa}" stroke-width="${f(2.2 * s)}" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx + L * 0.86)}" y2="${f(ty + L * 0.5)}" stroke="${corPa}" stroke-width="${f(2.2 * s)}" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx - L * 0.86)}" y2="${f(ty + L * 0.5)}" stroke="${corPa}" stroke-width="${f(2.2 * s)}" stroke-linecap="round"/>`
                        + `<circle cx="${f(tx)}" cy="${f(ty)}" r="${f(2.8 * s)}" fill="${corCubo}" stroke="#455A64" stroke-width="0.8"/>`;
                });
                break;
            case 'chamine':
                objeto += box(ex - 0.14 * s, ey - 0.14 * s, 0.28 * s, 0.28 * s, 1.05 * s, cor || '#8D6E63', { z0: ez, top: '#424242' })
                    + smoke(ex, ey, ez + 1.05 * s, '#616161', c.energia <= 2 ? 3 : 1);
                break;
            case 'lixeiras': {
                const cores = ['#1E88E5', '#43A047', '#FDD835', '#E53935'];
                cores.forEach((cl, i) => {
                    objeto += box(ex - 0.24 + (i % 2) * 0.26, ey - 0.24 + Math.floor(i / 2) * 0.26, 0.2, 0.2, 0.36 * s, cl, { z0: ez, top: shade(cl, -0.2) });
                });
                break;
            }
            case 'banco':
                objeto += bench(ex - 0.2, ey - 0.1, c.natureza === 1);
                break;
            case 'poste':
                objeto += objetoEm(ex, ey, ez, () => {
                    const [bx, by] = p(ex, ey, ez);
                    const [tx, ty] = p(ex, ey, ez + 1.6 * s);
                    const luz = c.energia === 1 ? '#B0BEC5' : '#FFF59D';
                    return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#455A64" stroke-width="2"/>`
                        + `<circle cx="${f(tx)}" cy="${f(ty)}" r="3.2" fill="${luz}" stroke="#F9A825" stroke-width="0.8"/>`;
                });
                break;
            case 'cerca':
                objeto += cerca(Math.max(x + 0.06, ex - 0.4 * s), ey, Math.min(x + w - 0.06, ex + 0.4 * s), ey, 0.45 * s);
                break;
            case 'bicicleta':
                objeto += bike(ex, ey, cor || '#26A69A');
                break;
            case 'carro':
                objeto += car(ex - 0.3, ey - 0.15, cor || '#546E7A', 'x')
                    + (c.mobilidade <= 2 ? smoke(ex, ey, 0.25, '#616161', c.mobilidade === 1 ? 3 : 1) : '');
                break;
            case 'bandeira':
                objeto += objetoEm(ex, ey, ez, () => {
                    const [bx, by] = p(ex, ey, ez);
                    return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(bx)}" y2="${f(by - 28 * s)}" stroke="#546E7A" stroke-width="1.6"/>`
                        + `<polygon points="${f(bx)},${f(by - 28 * s)} ${f(bx + 13 * s)},${f(by - 23 * s)} ${f(bx)},${f(by - 18 * s)}" fill="${cor || '#43A047'}"/>`;
                });
                break;
            case 'placa':
                objeto += objetoEm(ex, ey, ez, () => {
                    const [sx, sy] = p(ex, ey, ez);
                    return `<line x1="${f(sx)}" y1="${f(sy)}" x2="${f(sx)}" y2="${f(sy - 14)}" stroke="#546E7A" stroke-width="1.6"/>`
                        + `<circle cx="${f(sx)}" cy="${f(sy - 24)}" r="10" fill="${cor || '#2E7D32'}" stroke="#FFFFFF" stroke-width="1.6"/>`
                        + `<g transform="translate(${f(sx - 7)} ${f(sy - 31)}) scale(0.5833)" fill="none" stroke="#FFFFFF" color="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">${iconPaths(el.icone || 'leaf')}</g>`;
                });
                break;
            case 'caixa_dagua':
                objeto += box(ex - 0.18 * s, ey - 0.18 * s, 0.36 * s, 0.36 * s, 0.65 * s, '#90A4AE', { z0: ez, top: '#CFD8DC' });
                break;
            default:
                break;
        }
    });

    // Resíduo visível no canto frontal quando a Reciclagem está degradada (Estágios 1–2)
    if (c.reciclagem <= 2) {
        objeto += lixoSolto(x + w - 0.22, y + d - 0.22, c, Math.round((x + y + w + d) * 7) + 1);
    }

    return { chao, objeto };
}

/** Desenha um cilindro 3D vertical em coordenadas isométricas (gira perfeitamente em 0°, 90°, 180° e 270°). */
function cilindro3D(cx, cy, rx, ry, z0, h, cor, topoCor, faixaCor = null) {
    return objetoEm(cx, cy, z0, () => {
        const n = 24;
        const anel = (z) => groundEllipse(cx, cy, rx, ry, z, n).map(([gx, gy, gz]) => p(gx, gy, gz));
        const B = anel(z0);
        const T = anel(z0 + h);
        const F0 = faixaCor ? anel(z0 + h * 0.68) : null;
        const F1 = faixaCor ? anel(z0 + h * 0.84) : null;
        const [centroX, centroY] = p(cx, cy, z0);
        let out = '';
        for (let i = 0; i < n; i += 1) {
            const j = (i + 1) % n;
            const ym = (B[i][1] + B[j][1]) / 2;
            if (ym < centroY - 1.5) continue;   // só a metade frontal visível da parede curva
            const xm = (B[i][0] + B[j][0]) / 2;
            const tom = xm < centroX - 4 ? -0.06 : xm > centroX + 4 ? -0.24 : -0.14;
            const pQuad = (A0, A1, B1, B0, fill) => `<polygon points="${[A0, A1, B1, B0].map(([px, py]) => `${f(px)},${f(py)}`).join(' ')}" fill="${fill}" stroke="${fill}" stroke-width="0.4"/>`;
            out += pQuad(B[i], B[j], T[j], T[i], shade(cor, tom));
            if (F0 && F1) out += pQuad(F0[i], F0[j], F1[j], F1[i], shade(faixaCor, tom));
        }
        const ptsTopo = T.map(([px, py]) => `${f(px)},${f(py)}`).join(' ');
        out += `<polygon points="${ptsTopo}" fill="${topoCor || shade(cor, 0.08)}" stroke="rgba(0,0,0,0.22)" stroke-width="0.8"/>`;
        return out;
    });
}

/** Desenha pirâmide, rampa ou telhado industrial dente-de-serra em 3D. */
function telhadoGeometrico3D(bx, by, bw, bd, z0, h, forma, cor) {
    if (forma === 'duas_aguas_x' || forma === 'duas_aguas_y') {
        return gableRoof(bx, by, bw, bd, z0, h, cor);
    }
    if (forma === 'dente_serra') {
        const dentes = bw >= 1.6 ? 3 : 2;
        const dw = bw / dentes;
        let s = '';
        for (let i = 0; i < dentes; i += 1) {
            s += gableRoof(bx + i * dw, by, dw, bd, z0, h * 0.75, i % 2 ? shade(cor, -0.05) : cor);
        }
        return s;
    }
    return objetoEm(bx + bw / 2, by + bd / 2, z0, () => {
        const c00 = [bx, by, z0], c10 = [bx + bw, by, z0], c11 = [bx + bw, by + bd, z0], c01 = [bx, by + bd, z0];
        let faces = [];
        if (forma === 'rampa_frente') {
            const t0 = [bx, by, z0 + h], t1 = [bx + bw, by, z0 + h];
            faces = [
                { pts: [c00, c10, t1, t0], cor: shade(cor, -0.18) },
                { pts: [c00, c01, t0], cor: shade(cor, -0.12) },
                { pts: [c10, c11, t1], cor: shade(cor, -0.22) },
                { pts: [t0, t1, c11, c01], cor: shade(cor, 0.05) },
            ];
        } else if (forma === 'rampa_direita') {
            const t0 = [bx, by, z0 + h], t1 = [bx, by + bd, z0 + h];
            faces = [
                { pts: [c00, c01, t1, t0], cor: shade(cor, -0.15) },
                { pts: [c00, c10, t0], cor: shade(cor, -0.18) },
                { pts: [c01, c11, t1], cor: shade(cor, -0.12) },
                { pts: [t0, c10, c11, t1], cor: shade(cor, 0.04) },
            ];
        } else {
            const topo = [bx + bw / 2, by + bd / 2, z0 + h];
            faces = [
                { pts: [c00, c10, topo], cor: shade(cor, -0.2) },
                { pts: [c00, c01, topo], cor: shade(cor, -0.14) },
                { pts: [c10, c11, topo], cor: shade(cor, -0.24) },
                { pts: [c01, c11, topo], cor: shade(cor, -0.08) },
            ];
        }
        const projetadas = faces.map((fc) => {
            const sc = fc.pts.map(([gx, gy, gz]) => p(gx, gy, gz));
            const ym = sc.reduce((acc, [, py]) => acc + py, 0) / sc.length;
            return { svg: `<polygon points="${sc.map(([px, py]) => `${f(px)},${f(py)}`).join(' ')}" fill="${fc.cor}" stroke="rgba(0,0,0,0.2)" stroke-width="0.7" stroke-linejoin="round"/>`, ym };
        });
        projetadas.sort((a, b) => a.ym - b.ym);
        return projetadas.map((fc) => fc.svg).join('');
    });
}

/** Desenha uma torre metálica treliçada 3D (4 colunas convergindo com vigas em X) — ex.: torre de petróleo, energia ou guindaste. */
function trelica3D(bx, by, bw, bd, z0, h, cor) {
    return objetoEm(bx + bw / 2, by + bd / 2, z0, () => {
        const cx = bx + bw / 2, cy = by + bd / 2;
        const tw = bw * 0.28, td = bd * 0.28;
        const niveis = Math.max(2, Math.min(4, Math.round(h / 0.65)));
        const canto = (t, ix, iy) => {
            const wN = bw + (tw - bw) * t;
            const dN = bd + (td - bd) * t;
            return [cx + (ix ? wN / 2 : -wN / 2), cy + (iy ? dN / 2 : -dN / 2), z0 + h * t];
        };
        const seg = (a, b, wLinha, cLinha) => `<polyline points="${pts([a, b])}" stroke="${cLinha}" stroke-width="${f(wLinha)}" stroke-linecap="round" fill="none"/>`;
        let out = '';
        const cEsc = shade(cor, -0.2);
        // 4 montantes principais
        [[0, 0], [1, 0], [1, 1], [0, 1]].forEach(([ix, iy]) => {
            out += seg(canto(0, ix, iy), canto(1, ix, iy), 2.1, ix && iy ? cor : cEsc);
        });
        // Travessas horizontais e diagonais em X por nível
        for (let k = 0; k < niveis; k += 1) {
            const t0 = k / niveis, t1 = (k + 1) / niveis;
            const p00 = canto(t0, 0, 1), p10 = canto(t0, 1, 1), p11 = canto(t0, 1, 0);
            const q00 = canto(t1, 0, 1), q10 = canto(t1, 1, 1), q11 = canto(t1, 1, 0), q01 = canto(t1, 0, 0);
            out += seg(q00, q10, 1.3, cor) + seg(q10, q11, 1.3, cEsc) + seg(q11, q01, 1.1, cEsc) + seg(q01, q00, 1.1, cEsc);
            out += seg(p00, q10, 1.1, cor) + seg(p10, q00, 1.1, cor);
            out += seg(p10, q11, 1.1, cEsc) + seg(p11, q10, 1.1, cEsc);
        }
        // Plataforma superior
        out += quad(cx - tw / 2 - 0.04, cy - td / 2 - 0.04, tw + 0.08, td + 0.08, shade(cor, 0.1), z0 + h);
        return out;
    });
}

/** Desenha uma pessoa/vendedor 3D isométrico (com opção de venda atrás de balcão quando `soTronco = true`). */
function pessoa3D(cx, cy, z0, h, corRoupa = '#E53935', corDetalhe = '#FFFFFF', temChapeu = true, soTronco = false) {
    const zRef = soTronco ? z0 + 0.35 : z0 + 0.08;
    return objetoEm(cx, cy, zRef, () => {
        const s = Math.max(0.75, Math.min(1.45, (Number(h) || 0.95) / 0.95));
        let out = '';
        if (!soTronco) {
            out += poly(groundEllipse(cx, cy, 0.14 * s, 0.14 * s, z0 + 0.01, 14), 'rgba(0,0,0,0.22)');
            const [pEsq0, pEsq1] = [p(cx - 0.05 * s, cy + 0.02 * s, z0), p(cx - 0.05 * s, cy + 0.02 * s, z0 + 0.36 * s)];
            const [pDir0, pDir1] = [p(cx + 0.05 * s, cy - 0.02 * s, z0), p(cx + 0.05 * s, cy - 0.02 * s, z0 + 0.36 * s)];
            out += `<line x1="${f(pEsq0[0])}" y1="${f(pEsq0[1])}" x2="${f(pEsq1[0])}" y2="${f(pEsq1[1])}" stroke="#37474F" stroke-width="${f(3.2 * s)}" stroke-linecap="round"/>`
                + `<line x1="${f(pDir0[0])}" y1="${f(pDir0[1])}" x2="${f(pDir1[0])}" y2="${f(pDir1[1])}" stroke="#263238" stroke-width="${f(3.2 * s)}" stroke-linecap="round"/>`;
        }
        const zBaseTronco = soTronco ? z0 : z0 + 0.34 * s;
        const hTronco = 0.42 * s;
        const [tx, ty] = p(cx, cy, zBaseTronco + hTronco * 0.5);
        const [bx, by] = p(cx, cy, zBaseTronco);
        const [ox, oy] = p(cx, cy, zBaseTronco + hTronco);
        // Tronco e avental
        out += `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(ox)}" y2="${f(oy)}" stroke="${corRoupa}" stroke-width="${f(8.5 * s)}" stroke-linecap="round"/>`
            + `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty + 1)}" stroke="${corDetalhe || '#FFFFFF'}" stroke-width="${f(6.2 * s)}" stroke-linecap="round"/>`;
        // Braços e mãos
        out += `<line x1="${f(ox - 4 * s)}" y1="${f(oy + 2 * s)}" x2="${f(tx - 6.5 * s)}" y2="${f(ty + 4 * s)}" stroke="${shade(corRoupa, -0.12)}" stroke-width="${f(2.8 * s)}" stroke-linecap="round"/>`
            + `<line x1="${f(ox + 4 * s)}" y1="${f(oy + 2 * s)}" x2="${f(tx + 6.5 * s)}" y2="${f(ty + 4 * s)}" stroke="${shade(corRoupa, -0.18)}" stroke-width="${f(2.8 * s)}" stroke-linecap="round"/>`
            + `<circle cx="${f(tx - 6.5 * s)}" cy="${f(ty + 4 * s)}" r="${f(1.8 * s)}" fill="#FFCC80"/>`
            + `<circle cx="${f(tx + 6.5 * s)}" cy="${f(ty + 4 * s)}" r="${f(1.8 * s)}" fill="#FFCC80"/>`;
        // Cabeça e rosto
        const [hx, hy] = p(cx, cy, zBaseTronco + hTronco + 0.16 * s);
        const rCab = 4.4 * s;
        out += `<circle cx="${f(hx)}" cy="${f(hy)}" r="${f(rCab)}" fill="#FFCC80" stroke="#8D6E63" stroke-width="0.8"/>`
            + `<circle cx="${f(hx - 1.4 * s)}" cy="${f(hy + 0.5 * s)}" r="${f(0.75 * s)}" fill="#263238"/>`
            + `<circle cx="${f(hx + 1.6 * s)}" cy="${f(hy + 0.5 * s)}" r="${f(0.75 * s)}" fill="#263238"/>`;
        // Boné / chapéu de vendedor
        if (temChapeu) {
            const cCap = corDetalhe && corDetalhe !== '#FFFFFF' ? corDetalhe : corRoupa;
            out += `<ellipse cx="${f(hx + 1.2 * s)}" cy="${f(hy - rCab * 0.55)}" rx="${f(rCab * 1.15)}" ry="${f(rCab * 0.42)}" fill="${shade(cCap, -0.15)}"/>`
                + `<path d="M${f(hx - rCab)} ${f(hy - rCab * 0.5)} A${f(rCab)} ${f(rCab * 0.85)} 0 0 1 ${f(hx + rCab)} ${f(hy - rCab * 0.5)} Z" fill="${cCap}" stroke="rgba(0,0,0,0.22)" stroke-width="0.6"/>`;
        }
        return out;
    });
}

/** Desenha uma barraca / carrinho / quiosque 3D (com balcão, vitrine/bandeja, rodas opcionais, 4 hastes e toldo listrado alto). */
function barraca3D(px, py, pw, pd, z0, h, cor, topo, faixa, rodas = false) {
    const hBalcao = Math.max(0.44, Math.min(0.6, h * 0.42));
    const zToldo = Math.max(z0 + h, z0 + 1.18 + (pw + pd) * 0.32);
    const corToldo1 = topo || '#E53935';
    const corToldo2 = faixa || '#FFFFFF';
    let out = '';

    // 1. Balcão inferior com tampo de inox e faixa frontal
    out += box(px, py, pw, pd, hBalcao, cor, { z0, top: '#ECEFF1' });
    out += faixaFrente(px, py, pw, pd, z0 + hBalcao * 0.22, faixa || '#FDD835', hBalcao * 0.42);

    // 2. Detalhes sobre o balcão frontal (estufa/chapa inox e bisnagas de ketchup/mostarda)
    if (pw >= 0.4 && pd >= 0.4) {
        const zTampo = z0 + hBalcao;
        out += box(px + pw * 0.12, py + pd * 0.6, pw * 0.32, pd * 0.26, 0.14, '#B0BEC5', { z0: zTampo, top: '#FFFFFF' });
        const [kx, ky] = p(px + pw * 0.68, py + pd * 0.74, zTampo + 0.08);
        const [mx, my] = p(px + pw * 0.8, py + pd * 0.74, zTampo + 0.08);
        out += objetoEm(px + pw * 0.74, py + pd * 0.74, zTampo + 0.1, () =>
            `<rect x="${f(kx - 2)}" y="${f(ky - 5)}" width="4" height="7" rx="1.2" fill="#E53935" stroke="#B71C1C" stroke-width="0.5"/>`
            + `<rect x="${f(mx - 2)}" y="${f(my - 5)}" width="4" height="7" rx="1.2" fill="#FDD835" stroke="#F57F17" stroke-width="0.5"/>`
        );
    }

    // 3. Rodas de carrinho (quando ativadas)
    if (rodas) {
        [px + pw * 0.24, px + pw * 0.76].forEach((rx) => {
            const ry = py + pd + 0.02;
            out += objetoEm(rx, ry, z0 + 0.12, () => {
                const [wx, wy] = p(rx, ry, z0 + 0.14);
                return `<ellipse cx="${f(wx)}" cy="${f(wy)}" rx="5.5" ry="4.5" fill="#263238" stroke="#000000" stroke-width="0.8"/>`
                    + `<circle cx="${f(wx)}" cy="${f(wy)}" r="2.2" fill="#CFD8DC"/>`;
            });
        });
    }

    // 4. Hastes metálicas nos 4 cantos e cobertura listrada do toldo no topo
    const cantos = [
        [px + 0.04, py + 0.04],
        [px + pw - 0.04, py + 0.04],
        [px + 0.04, py + pd - 0.04],
        [px + pw - 0.04, py + pd - 0.04],
    ];
    cantos.forEach(([hx, hy]) => {
        out += objetoEm(hx, hy, z0 + hBalcao, () =>
            `<polyline points="${pts([[hx, hy, z0 + hBalcao], [hx, hy, zToldo]])}" stroke="#78909C" stroke-width="2.1" stroke-linecap="round"/>`
        );
    });

    // Toldo listrado em 3D (5 faixas alternadas com cumeeira suave)
    out += objetoEm(px + pw / 2, py + pd / 2, zToldo, () => {
        const listras = 5;
        const lw = (pw + 0.08) / listras;
        const xIni = px - 0.04;
        const yIni = py - 0.04;
        const dTot = pd + 0.08;
        const yMeio = yIni + dTot / 2;
        const zCumeeira = zToldo + 0.22;
        let svgToldo = '';
        for (let i = 0; i < listras; i += 1) {
            const x0 = xIni + i * lw;
            const x1 = x0 + lw;
            const cFaixa = i % 2 === 0 ? corToldo1 : corToldo2;
            const pTras = [[x0, yIni, zToldo], [x1, yIni, zToldo], [x1, yMeio, zCumeeira], [x0, yMeio, zCumeeira]];
            const pFrente = [[x0, yMeio, zCumeeira], [x1, yMeio, zCumeeira], [x1, yIni + dTot, zToldo], [x0, yIni + dTot, zToldo]];
            const pBabadinho = [[x0, yIni + dTot, zToldo], [x1, yIni + dTot, zToldo], [x1, yIni + dTot, zToldo - 0.11], [x0, yIni + dTot, zToldo - 0.11]];
            svgToldo += `<polygon points="${pts(pTras)}" fill="${shade(cFaixa, 0.04)}" stroke="rgba(0,0,0,0.18)" stroke-width="0.5"/>`
                + `<polygon points="${pts(pFrente)}" fill="${shade(cFaixa, -0.06)}" stroke="rgba(0,0,0,0.18)" stroke-width="0.5"/>`
                + `<polygon points="${pts(pBabadinho)}" fill="${shade(cFaixa, -0.16)}" stroke="rgba(0,0,0,0.2)" stroke-width="0.5"/>`;
        }
        return svgToldo;
    });

    return out;
}

/** Renderiza uma estrutura 3D modelada do zero por primitivas geométricas e arquitetônicas (`pecas`, RN76). */
function desenharGeometria3D(x, y, w, d, c, a) {
    let chao = '';
    let objeto = '';
    const estagio = Math.max(1, Math.min(4, Math.round(((c.agua || 3) + (c.natureza || 3) + (c.energia || 3) + (c.mobilidade || 3) + (c.reciclagem || 3)) / 5)));
    const desgaste = estagio === 1 ? -0.1 : estagio === 2 ? -0.04 : estagio === 4 ? 0.02 : 0;

    // 1. Piso base do terreno
    const tipoBase = a.pisoBase || 'concreto';
    if (tipoBase !== 'nenhum') {
        const corBase = a.corBase && tipoBase !== 'grama' && tipoBase !== 'agua'
            ? shade(a.corBase, desgaste)
            : corPisoCena(tipoBase, c);
        chao += quad(x + 0.04, y + 0.04, w - 0.08, d - 0.08, corBase, 0, `stroke="${shade(corBase, -0.18)}" stroke-width="0.7"`);
        if (tipoBase === 'grama' || tipoBase === 'terra') {
            if (estagio === 1) chao += cracks(x + 0.15, y + 0.15, Math.max(0.5, w - 0.3), Math.max(0.5, d - 0.3), Math.max(2, Math.round((w * d) / 2)), 17);
            if (estagio >= 3) objeto += grassTufts(x + 0.15, y + 0.15, Math.max(0.5, w - 0.3), Math.max(0.5, d - 0.3), Math.max(3, Math.round(w * d)), 23, verde(c.natureza, '#558B2F'));
        }
    }

    // 2. Ordenar peças 3D de trás para frente e de baixo para cima (algoritmo do pintor isométrico)
    const pecasAtivas = [...(a.pecas || [])]
        .filter((pc) => !Array.isArray(pc.estagios) || !pc.estagios.length || pc.estagios.includes(estagio));
    const barracas = pecasAtivas.filter((pc) => pc.tipo === 'barraca');

    const barracaDona = (pc) => {
        if (pc.tipo !== 'pessoa') return null;
        const cxP = (Number(pc.x) || 0) + (Number(pc.w) || 0.16) / 2;
        const cyP = (Number(pc.y) || 0) + (Number(pc.d) || 0.16) / 2;
        return barracas.find((b) => {
            const bx = Number(b.x) || 0, by = Number(b.y) || 0;
            const bw = Number(b.w) || 0.4, bd = Number(b.d) || 0.4;
            return cxP >= bx - 0.06 && cxP <= bx + bw + 0.06 && cyP >= by - 0.06 && cyP <= by + bd + 0.06;
        }) || null;
    };

    const chaveProfundidade = (pc) => {
        if (pc.tipo === 'piso' || pc.tipo === 'flores' || pc.tipo === 'lago') return -100;
        if (pc.tipo === 'fumaca') return 1000 + (Number(pc.z0) || 0);
        if (Array.isArray(pc.pontos) && pc.pontos.length) {
            const n = pc.pontos.length;
            const xm = pc.pontos.reduce((s, pt) => s + (Number(pt[0]) || 0), 0) / n;
            const ym = pc.pontos.reduce((s, pt) => s + (Number(pt[1]) || 0), 0) / n;
            const zm = Math.min(...pc.pontos.map((pt) => Number(pt[2]) || 0));
            return (xm + ym) * (w + d) + 2.2 * zm;
        }
        const bDona = barracaDona(pc);
        if (bDona) {
            const bx = (Number(bDona.x) || 0) + (Number(bDona.w) || 0.4) / 2;
            const by = (Number(bDona.y) || 0) + (Number(bDona.d) || 0.4) / 2;
            return bx * w + by * d + 2.2 * (Number(bDona.z0) || 0) + 0.35;
        }
        const cx = (Number(pc.x) || 0) + (Number(pc.w) || 0.25) / 2;
        const cy = (Number(pc.y) || 0) + (Number(pc.d) || 0.25) / 2;
        const z0 = Number(pc.z0) || 0;
        return cx * w + cy * d + 2.2 * z0;
    };

    const pecasOrdenadas = pecasAtivas
        .map((pc, idx) => ({ pc, idx, prof: chaveProfundidade(pc) }))
        .sort((u, v) => u.prof - v.prof || u.idx - v.idx);

    pecasOrdenadas.forEach(({ pc, idx }) => {
        const px = x + (Number(pc.x) || 0) * w;
        const py = y + (Number(pc.y) || 0) * d;
        const pw = Math.max(0.05, Math.min(w - (px - x), (Number(pc.w) || 0.25) * w));
        const pd = Math.max(0.05, Math.min(d - (py - y), (Number(pc.d) || 0.25) * d));
        const cx = px + pw / 2;
        const cy = py + pd / 2;
        const z0 = Math.max(0, Number(pc.z0) || 0);
        const h = Math.max(0.04, Number(pc.h) || 0.6);
        const cor = shade(pc.cor || '#CFD8DC', desgaste);
        const topo = pc.topo ? shade(pc.topo, desgaste) : shade(cor, 0.06);
        const faixa = pc.faixa ? shade(pc.faixa, desgaste) : null;

        switch (pc.tipo) {
            case 'piso':
                if (pc.forma === 'elipse') {
                    chao += poly(groundEllipse(cx, cy, pw / 2, pd / 2, 0, 22, 0.02), cor, `stroke="${shade(cor, -0.18)}" stroke-width="0.7"`);
                } else if (pw > 0.04 && pd > 0.04) {
                    chao += quad(px, py, pw, pd, cor, 0.01, `stroke="${shade(cor, -0.15)}" stroke-width="0.6"`);
                }
                break;

            case 'edificio': {
                const andares = Math.max(1, Math.min(8, Number(pc.andares) || Math.round(h / 0.55) || 1));
                const hEd = Math.max(h, 0.45 + 0.52 * andares);
                const telhado = pc.telhado || 'plano';
                const gramado = verde(c.natureza, '#7CB342');
                const tetoBox = telhado === 'verde' ? gramado : telhado === 'vidro' ? '#B3E5FC' : topo;
                const corJan = pc.corJanela || '#B3E5FC';
                const corPorta = pc.corPorta || '#6D4C41';

                objeto += box(px, py, pw, pd, hEd, cor, { z0, top: tetoBox });
                if (pw >= 0.4 && pd >= 0.4) {
                    objeto += janelas(px, py, pw, pd, z0 + hEd, {
                        andares,
                        z0: z0 + 0.24,
                        cor: corJan,
                        alt: Math.min(0.35, ((hEd - 0.24) / andares) * 0.56),
                    });
                }
                if (z0 < 0.12 && pw >= 0.42) {
                    const largP = Math.min(0.42, pw * 0.32);
                    const altP = Math.min(0.66, (hEd / andares) * 0.85, hEd * 0.55);
                    objeto += porta(px + pw / 2 - largP / 2, py, pd, corPorta, largP, altP);
                }
                if ((faixa || pc.toldo) && pw >= 0.42) {
                    const zFaixa = z0 + Math.min(0.68, (hEd / andares) * 0.88);
                    objeto += faixaFrente(px, py, pw, pd, zFaixa, faixa || topo, 0.16);
                }

                const zTopo = z0 + hEd;
                if (telhado === 'duas_aguas') {
                    objeto += gableRoof(px, py, pw, pd, zTopo, Math.min(0.9, pd * 0.42), topo);
                } else if (telhado === 'verde' && pw > 0.35 && pd > 0.35) {
                    objeto += quad(px + 0.08, py + 0.08, pw - 0.16, pd - 0.16, shade(gramado, -0.12), zTopo + 0.01);
                } else if (telhado === 'vidro' && pw > 0.35 && pd > 0.35) {
                    objeto += quad(px + 0.08, py + 0.08, pw - 0.16, pd - 0.16, 'rgba(225,245,254,0.78)', zTopo + 0.01, 'stroke="#0288D1" stroke-width="0.9"');
                }

                if (pc.topoDetalhe === 'cruz_vermelha') {
                    const tamC = Math.min(pw, pd) * 0.54;
                    const espC = Math.max(0.16, tamC * 0.28);
                    objeto += quad(cx - tamC / 2, cy - espC / 2, tamC, espC, '#E53935', zTopo + 0.02)
                        + quad(cx - espC / 2, cy - tamC / 2, espC, tamC, '#E53935', zTopo + 0.02);
                    // Emblema da cruz vermelha no topo da fachada frontal
                    if (pw >= 0.7 && hEd >= 1.2) {
                        const zEm = zTopo - 0.36;
                        objeto += poly([[cx - 0.16, py + pd, zEm], [cx + 0.16, py + pd, zEm], [cx + 0.16, py + pd, zEm + 0.1], [cx - 0.16, py + pd, zEm + 0.1]], '#E53935')
                            + poly([[cx - 0.05, py + pd, zEm - 0.11], [cx + 0.05, py + pd, zEm - 0.11], [cx + 0.05, py + pd, zEm + 0.21], [cx - 0.05, py + pd, zEm + 0.21]], '#E53935');
                    }
                } else if (pc.topoDetalhe === 'heliponto') {
                    const rH = Math.min(pw, pd) * 0.36;
                    objeto += poly(groundEllipse(cx, cy, rH, rH, zTopo + 0.02, 20), '#455A64', 'stroke="#FDD835" stroke-width="1.1"')
                        + quad(cx - rH * 0.4, cy - rH * 0.4, rH * 0.18, rH * 0.8, '#FFFFFF', zTopo + 0.03)
                        + quad(cx + rH * 0.22, cy - rH * 0.4, rH * 0.18, rH * 0.8, '#FFFFFF', zTopo + 0.03)
                        + quad(cx - rH * 0.25, cy - rH * 0.09, rH * 0.5, rH * 0.18, '#FFFFFF', zTopo + 0.03);
                } else if (pc.topoDetalhe === 'caixa_dagua') {
                    const rCx = Math.min(0.24, pw * 0.22, pd * 0.22);
                    objeto += cilindro3D(cx, cy, rCx, rCx, zTopo, 0.55, '#90A4AE', '#CFD8DC');
                } else if (pc.topoDetalhe === 'painel_solar' && telhado !== 'duas_aguas' && pw >= 0.5 && pd >= 0.5) {
                    for (let j = py + 0.12; j + 0.45 <= py + pd - 0.05; j += 0.65) {
                        const [x0, x1, y0, y1] = [px + 0.12, px + pw - 0.12, j, j + 0.45];
                        objeto += poly([[x0, y0, zTopo + 0.34], [x1, y0, zTopo + 0.34], [x1, y1, zTopo + 0.06], [x0, y1, zTopo + 0.06]], '#1565C0', 'stroke="#ECEFF1" stroke-width="0.9"');
                    }
                } else if (pc.topoDetalhe === 'antena') {
                    objeto += objetoEm(cx, cy, zTopo, () => {
                        const [bx, by] = p(cx, cy, zTopo);
                        const [tx, ty] = p(cx, cy, zTopo + 0.9);
                        return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#546E7A" stroke-width="1.8"/>`
                            + `<circle cx="${f(tx)}" cy="${f(ty)}" r="2.2" fill="#E53935"/>`;
                    });
                }
                break;
            }

            case 'barraca':
                objeto += barraca3D(px, py, pw, pd, z0, h, cor, pc.topo || '#E53935', pc.faixa || '#FFFFFF', !!pc.rodas);
                break;

            case 'pessoa': {
                const bDona = barracaDona(pc);
                if (bDona) {
                    const bx = x + (Number(bDona.x) || 0) * w;
                    const by = y + (Number(bDona.y) || 0) * d;
                    const bw = Math.max(0.2, (Number(bDona.w) || 0.4) * w);
                    const bd = Math.max(0.2, (Number(bDona.d) || 0.4) * d);
                    const hb = Math.max(0.44, Math.min(0.6, (Number(bDona.h) || 1.3) * 0.42));
                    const zBalcao = (Number(bDona.z0) || 0) + hb;
                    objeto += pessoa3D(bx + bw * 0.5, by + bd * 0.42, zBalcao, h, pc.cor || '#1E88E5', pc.faixa || pc.topo || '#FFFFFF', pc.chapeu !== false, true);
                } else {
                    objeto += pessoa3D(cx, cy, z0, h, pc.cor || '#1E88E5', pc.faixa || pc.topo || '#FFFFFF', pc.chapeu !== false, false);
                }
                break;
            }

            case 'caixa': {
                objeto += box(px, py, pw, pd, h, cor, { z0, top: topo });
                if (faixa && pw >= 0.22 && h >= 0.35) {
                    objeto += faixaFrente(px, py, pw, pd, z0 + h * 0.72, faixa, Math.min(0.2, h * 0.18));
                }
                if (pc.forma === 'envidracado' && pw >= 0.4 && pd >= 0.4 && h >= 0.5) {
                    const and = Math.max(1, Math.min(6, Number(pc.andares) || Math.round(h / 0.65) || 1));
                    objeto += janelas(px, py, pw, pd, z0 + h, { andares: and, z0: z0 + 0.18, cor: pc.corJanela || '#B3E5FC', alt: Math.min(0.36, (h / and) * 0.55) });
                } else if (pc.forma === 'industrial' && pw >= 0.45 && h >= 0.55 && z0 < 0.15) {
                    const largPortao = Math.min(0.48, pw * 0.42);
                    const altPortao = Math.min(0.75, h * 0.68);
                    objeto += porta(px + pw / 2 - largPortao / 2, py, pd, pc.corPorta || '#37474F', largPortao, altPortao);
                } else if (pc.forma === 'comercial' && pw >= 0.45 && h >= 0.55 && z0 < 0.15) {
                    objeto += janelas(px, py, pw, pd, z0 + h, { andares: 1, z0: z0 + 0.25, cor: pc.corJanela || '#E1F5FE', alt: Math.min(0.38, h * 0.45) })
                        + porta(px + pw / 2 - 0.16, py, pd, pc.corPorta || '#5D4037', 0.32, Math.min(0.68, h * 0.65));
                }
                break;
            }

            case 'cilindro':
                objeto += cilindro3D(cx, cy, pw / 2, pd / 2, z0, h, cor, topo, faixa);
                break;

            case 'telhado':
                objeto += telhadoGeometrico3D(px, py, pw, pd, z0, h, pc.forma || 'duas_aguas_x', cor);
                break;

            case 'trelica':
                objeto += trelica3D(px, py, pw, pd, z0, h, cor);
                break;

            case 'poligono':
                if (Array.isArray(pc.pontos) && pc.pontos.length >= 3) {
                    const verts = pc.pontos.map(([vx, vy, vz = 0]) => [x + Number(vx) * w, y + Number(vy) * d, Number(vz)]);
                    objeto += poly(verts, cor, `stroke="${shade(cor, -0.25)}" stroke-width="0.65" stroke-linejoin="round"`);
                }
                break;

            case 'linha':
                if (Array.isArray(pc.pontos) && pc.pontos.length >= 2) {
                    const verts = pc.pontos.map(([vx, vy, vz = 0]) => [x + Number(vx) * w, y + Number(vy) * d, Number(vz)]);
                    const n = verts.length;
                    const mx = verts.reduce((s, v) => s + v[0], 0) / n;
                    const my = verts.reduce((s, v) => s + v[1], 0) / n;
                    const mz = Math.min(...verts.map((v) => v[2]));
                    const esp = Math.max(0.8, Math.min(6, Number(pc.espessura) || 2.2));
                    objeto += objetoEm(mx, my, mz, () => `<polyline points="${pts(verts)}" stroke="${cor}" stroke-width="${f(esp)}" stroke-linecap="round" stroke-linejoin="round" fill="none"/>`);
                }
                break;

            case 'cupula':
                objeto += objetoEm(cx, cy, z0, () => {
                    const [sx, sy] = p(cx, cy, z0 + h * 0.45);
                    const rx = Math.max(3.5, (pw / 2) * 20);
                    const ry = Math.max(3, (h / 2) * 18);
                    return `<ellipse cx="${f(sx)}" cy="${f(sy)}" rx="${f(rx)}" ry="${f(ry)}" fill="${cor}" stroke="${shade(cor, -0.28)}" stroke-width="1"/>`
                        + `<ellipse cx="${f(sx - rx * 0.25)}" cy="${f(sy - ry * 0.25)}" rx="${f(rx * 0.45)}" ry="${f(ry * 0.4)}" fill="${shade(cor, 0.14)}"/>`;
                });
                break;

            case 'fumaca': {
                const qtd = Math.max(1, Math.min(5, Math.round(h * (estagio <= 2 ? 1.2 : 0.6))));
                objeto += smoke(cx, cy, z0, pc.cor || (estagio <= 2 ? '#546E7A' : '#CFD8DC'), qtd);
                break;
            }

            case 'arvore':
                objeto += arvore(cx, cy, Math.max(0.42, Math.min(0.85, h * 0.55)), pc.cor || '#43A047', c.natureza);
                break;

            case 'pinheiro':
                objeto += c.natureza === 1 ? deadTree(cx, cy, 0.5) : pine(cx, cy, Math.max(0.45, Math.min(0.85, h * 0.55)), verde(c.natureza, pc.cor || '#2E7D32'));
                break;

            case 'arbusto':
                objeto += bush(cx, cy, c.natureza === 1 ? 0.36 : 0.46, c.natureza === 1 ? '#8D6E63' : verde(c.natureza, pc.cor || '#558B2F'));
                break;

            case 'flores':
                chao += c.natureza === 1
                    ? cracks(px, py, pw, pd, 2, 50 + idx)
                    : flowers(px, py, pw, pd, c.natureza === 2 ? 3 : 7, 50 + idx);
                break;

            case 'horta':
                chao += quad(px, py, pw, pd, c.natureza === 1 ? '#A1887F' : '#8D6E63', 0.01, 'stroke="#5D4037" stroke-width="0.9"');
                objeto += bush(cx - pw * 0.22, cy - pd * 0.22, 0.3, verde(c.natureza, '#7CB342'))
                    + bush(cx + pw * 0.22, cy - pd * 0.22, 0.3, verde(c.natureza, '#558B2F'))
                    + bush(cx - pw * 0.22, cy + pd * 0.22, 0.3, verde(c.natureza, '#558B2F'))
                    + bush(cx + pw * 0.22, cy + pd * 0.22, 0.3, verde(c.natureza, '#7CB342'));
                break;

            case 'lago': {
                const eAg = c.agua || 3;
                chao += poly(groundEllipse(cx, cy, pw / 2, pd / 2, 0, 20, 0.04), MARGEM[eAg - 1])
                    + poly(groundEllipse(cx, cy, pw * 0.41, pd * 0.41, 0, 20, 0.04), AGUA[eAg - 1], `stroke="${shade(AGUA[eAg - 1], -0.2)}" stroke-width="0.8"`);
                if (eAg <= 2) chao += floatingTrash(cx, cy, '#ECEFF1');
                else chao += lilyPad(cx - pw * 0.15, cy + pd * 0.1, eAg === 4) + ripples(cx, cy, pw * 0.2, pd * 0.15);
                break;
            }

            case 'fonte': {
                const rF = Math.min(pw, pd) * 0.48;
                const ag = AGUA[(c.agua || 3) - 1];
                chao += poly(groundEllipse(cx, cy, rF, rF), '#BCAAA4', 'stroke="#8D6E63" stroke-width="0.9"')
                    + poly(groundEllipse(cx, cy, rF * 0.8, rF * 0.8, 0.06), ag, `stroke="${shade(ag, -0.2)}" stroke-width="0.7"`);
                objeto += cilindro3D(cx, cy, rF * 0.24, rF * 0.24, z0, 0.48, '#A1887F', '#BCAAA4');
                if (c.agua >= 2) {
                    objeto += objetoEm(cx, cy, z0 + 0.52, () => {
                        const [jx, jy] = p(cx, cy, z0 + 0.55);
                        return `<path d="M${f(jx)} ${f(jy)} q-7 -12 -12 5 M${f(jx)} ${f(jy)} q7 -12 12 5" stroke="#B3E5FC" stroke-width="1.8" fill="none" opacity="0.9"/>`;
                    });
                }
                break;
            }

            case 'painel_solar':
                objeto += solarRow(px, cy - 0.35, Math.max(0.5, pw));
                break;

            case 'turbina_eolica':
                objeto += objetoEm(cx, cy, z0, () => {
                    const hT = Math.max(1.6, h);
                    const [bx, by] = p(cx, cy, z0);
                    const [tx, ty] = p(cx, cy, z0 + hT);
                    const L = 15;
                    const corPa = c.energia === 1 ? '#B0BEC5' : '#FFFFFF';
                    return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#CFD8DC" stroke-width="3" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx)}" y2="${f(ty - L)}" stroke="${corPa}" stroke-width="2.2" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx + L * 0.86)}" y2="${f(ty + L * 0.5)}" stroke="${corPa}" stroke-width="2.2" stroke-linecap="round"/>`
                        + `<line x1="${f(tx)}" y1="${f(ty)}" x2="${f(tx - L * 0.86)}" y2="${f(ty + L * 0.5)}" stroke="${corPa}" stroke-width="2.2" stroke-linecap="round"/>`
                        + `<circle cx="${f(tx)}" cy="${f(ty)}" r="2.8" fill="${c.energia >= 3 ? '#43A047' : '#78909C'}" stroke="#455A64" stroke-width="0.8"/>`;
                });
                break;

            case 'banco':
                objeto += bench(cx - 0.2, cy - 0.1, c.natureza === 1);
                break;

            case 'poste':
                objeto += objetoEm(cx, cy, z0, () => {
                    const [bx, by] = p(cx, cy, z0);
                    const [tx, ty] = p(cx, cy, z0 + 1.55);
                    return `<line x1="${f(bx)}" y1="${f(by)}" x2="${f(tx)}" y2="${f(ty)}" stroke="#455A64" stroke-width="2"/>`
                        + `<circle cx="${f(tx)}" cy="${f(ty)}" r="3.2" fill="${c.energia === 1 ? '#B0BEC5' : '#FFF59D'}" stroke="#F9A825" stroke-width="0.8"/>`;
                });
                break;

            case 'cerca':
                objeto += cerca(px, cy, px + pw, cy, Math.max(0.35, Math.min(0.65, h)));
                break;

            case 'bicicleta':
                objeto += bike(cx, cy, pc.cor || '#26A69A');
                break;

            case 'carro':
                objeto += car(cx - 0.3, cy - 0.15, pc.cor || '#546E7A', 'x');
                break;

            default:
                break;
        }
    });

    return { chao, objeto };
}

function personalizado(x, y, w, d, c, aparencia) {
    if (aparencia && Array.isArray(aparencia.pecas)) {
        return desenharGeometria3D(x, y, w, d, c, aparencia);
    }
    if (aparencia && (Array.isArray(aparencia.blocos) || Array.isArray(aparencia.elementos) || Array.isArray(aparencia.pisos) || aparencia.piso)) {
        return desenharCenaIA(x, y, w, d, c, aparencia);
    }
    const a = { ...APARENCIA_PADRAO, ...(aparencia || {}) };
    const tem = (k) => (a.detalhes || []).includes(k);
    const arvores = tem('arvores') && w >= 2 && d >= 2;
    const inset = arvores ? 0.45 : Math.min(0.2, w * 0.15, d * 0.15);
    const [bx, by, bw, bd] = [x + inset, y + inset, w - inset * 2, d - inset * 2];
    const andares = Math.max(1, Math.min(5, Number(a.andares) || 1));
    const h = 0.5 + 0.65 * andares;
    const parede = PALETA_APARENCIA.parede[a.parede] || PALETA_APARENCIA.parede.branco;
    const telhado = PALETA_APARENCIA.telhado[a.telhado] || PALETA_APARENCIA.telhado.verde;
    const plano = a.formato !== 'duas_aguas';
    const tetoVerde = plano && tem('telhado_verde');
    const gramado = verde(c.natureza, '#7CB342');

    let objeto = arvores ? arvore(x + 0.25, y + 0.25, 0.45, '#43A047', c.natureza) : '';
    objeto += box(bx, by, bw, bd, h, parede, { top: plano ? (tetoVerde ? gramado : telhado) : shade(parede, -0.1) })
        + janelas(bx, by, bw, bd, h, { andares, cor: '#B3E5FC' })
        + porta(bx + Math.max(0.1, bw / 2 - 0.18), by, bd);
    if (a.toldo) objeto += faixaFrente(bx, by, bw, bd, 0.3 + (h - 0.3) / andares - 0.12, telhado, 0.2);

    let topo = h;
    if (!plano) {
        const rise = Math.min(0.9, bd * 0.45);
        objeto += gableRoof(bx, by, bw, bd, h, rise, telhado);
        topo = h + rise;
    }
    if (tetoVerde) {
        objeto += quad(bx + 0.12, by + 0.12, bw - 0.24, bd - 0.24, shade(gramado, -0.12), h + 0.01);
    }
    if (plano && tem('solar')) {
        // Fileiras inclinadas sobre o teto; empoeiradas com Energia baixa (como a Mini Usina)
        for (let j = by + 0.12; j + 0.55 <= by + bd - 0.05; j += 0.75) {
            const [x0, x1, y0, y1] = [bx + 0.15, bx + bw - 0.15, j, j + 0.55];
            objeto += poly([[x0, y0, h + 0.4], [x1, y0, h + 0.4], [x1, y1, h + 0.08], [x0, y1, h + 0.08]], '#1565C0', 'stroke="#ECEFF1" stroke-width="1"');
            if (c.energia <= 2) objeto += poly([[x0, y0, h + 0.41], [x1, y0, h + 0.41], [x1, y1, h + 0.09], [x0, y1, h + 0.09]], c.energia === 1 ? 'rgba(121,85,72,0.6)' : 'rgba(141,110,99,0.35)');
        }
    }
    if (a.icone) objeto += objetoEm(bx + bw / 2, by + bd / 2, topo, () => {
        const [sx, sy] = p(bx + bw / 2, by + bd / 2, topo);
        const cor = PALETA_APARENCIA.placa[a.placa] || PALETA_APARENCIA.placa.verde;
        return `<line x1="${f(sx)}" y1="${f(sy)}" x2="${f(sx)}" y2="${f(sy - 14)}" stroke="#546E7A" stroke-width="1.6"/>`
            + `<circle cx="${f(sx)}" cy="${f(sy - 24)}" r="10" fill="${cor}" stroke="#FFFFFF" stroke-width="1.6"/>`
            + `<g transform="translate(${f(sx - 7)} ${f(sy - 31)}) scale(0.5833)" fill="none" stroke="#FFFFFF" color="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">${iconPaths(a.icone)}</g>`;
    });
    if (arvores) objeto += arvore(x + w - 0.25, y + d - 0.25, 0.45, '#43A047', c.natureza);
    const chao = quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, arvores ? verde(c.natureza, '#A5D6A7') : '#CFD8DC');
    return { chao, objeto };
}

/** Modelos do mapa pela chave gravada em `construcao.desenho` (as mesmas de DESENHOS_ESTRUTURA no PHP). */
const MODELOS = {
    lixeiras, ponto_onibus: pontoOnibus, casa, ciclofaixa, horta, praca, pomar, mini_usina: miniUsinaSolar, lago: lagoArtificial, escola,
    rua, cruzamento, calcada, estacionamento,
    bar, balada, lanchonete, mercado, padaria, posto, lava_rapido: lavaRapido, quadra, parquinho,
    predio, hospital, bombeiros, delegacia, prefeitura,
    arbusto, arvore: arvoreDecorativa, canteiro, poste, banco: bancoDecorativo, cerca: cercaDecorativa, fonte,
    personalizado,
    avenida, avenida_cruzamento: avenidaCruzamento, avenida_curva: avenidaCurva, avenida_t: avenidaT,
};

/** Desenho genérico para estruturas cadastradas sem desenho próprio. */
function generico(x, y, w, d) {
    return { chao: quad(x + 0.05, y + 0.05, w - 0.1, d - 0.1, '#C8E6C9'), objeto: box(x + 0.2, y + 0.2, w - 0.4, d - 0.4, 0.8, '#A5D6A7') };
}

/**
 * @param {{ desenho?: string|null, aparencia?: object|null }} estrutura  item do catálogo ou estrutura construída
 * @param {number} x @param {number} y @param {number} w @param {number} d área na grade global
 * @param {{ agua: number, natureza: number, energia: number, mobilidade: number, reciclagem: number }} [condicoes]
 * @param {number} [rotacao] 0, 1 (90°), 2 (180°) ou 3 (270°); true conta como 1
 */
export function desenharEstrutura(estrutura, x, y, w, d, condicoes = CONDICAO_PADRAO, rotacao = 0) {
    const fn = MODELOS[estrutura?.desenho] || generico;
    const c = { ...CONDICAO_PADRAO, ...condicoes };
    const k = ((Math.trunc(Number(rotacao)) || 0) % 4 + 4) % 4;
    if (!k) return fn(x, y, w, d, c, estrutura?.aparencia);
    // (x, y, w, d) já vêm com o giro aplicado: desenha de frente no tamanho original e gira em torno do centro
    const [wb, db] = k % 2 ? [d, w] : [w, d];
    const cx = x + w / 2, cy = y + d / 2;
    return desenharGirado(k, cx, cy, () => fn(cx - wb / 2, cy - db / 2, wb, db, c, estrutura?.aparencia));
}
