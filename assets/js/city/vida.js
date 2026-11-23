/**
 * Cidade viva — desenhos e regras visuais usados por traffic.js (só visual, sem efeito nas regras).
 *
 *  - Dia e noite em ciclo rápido (CICLO segundos): dia, entardecer, noite e amanhecer.
 *  - Lixo jogado pelas pessoas conforme o estágio da Reciclagem (CHANCE_LIXO); no estágio 4 ninguém
 *    joga e algumas pessoas catam lixo com um saquinho.
 *  - Balões de conversa, atitudes nas estruturas (USOS, pelo modelo de desenho), cachorros,
 *    pássaros (Natureza ≥ 3), pombos no lixo (Reciclagem ≤ 2), notas musicais da Balada à noite.
 *  - Luzes da noite: casas do centro, calçadas das avenidas e estruturas iluminadas (LUZ, pelo modelo).
 */
import { p } from './iso.js';
import { CASAS } from './regions.js';

/* ---------------------------------------------------------------------------
   Dia e noite (ciclo rápido, valores provisórios)
   --------------------------------------------------------------------------- */

export const CICLO = 480;                 // 8 min: 5 de dia, 1 de entardecer, 1,5 de noite, 0,5 de amanhecer
const [DIA, ENTARDECER, NOITE] = [300, 60, 90];
export const NOITE_OPACIDADE = 0.5;       // camada azul escura no auge da noite
export const FATOR_NOITE = 0.6;           // fração das pessoas que anda na rua à noite

/** Escuridão de 0 (dia) a 1 (noite) no segundo `t` do ciclo. */
export function escuridao(t) {
    const s = ((t % CICLO) + CICLO) % CICLO;
    if (s < DIA) return 0;
    if (s < DIA + ENTARDECER) return (s - DIA) / ENTARDECER;
    if (s < DIA + ENTARDECER + NOITE) return 1;
    return 1 - (s - DIA - ENTARDECER - NOITE) / (CICLO - DIA - ENTARDECER - NOITE);
}

/**
 * Hora da cidade (0–24) no segundo `t` do ciclo, por trechos iguais aos de escuridao():
 * dia 06:00→18:00, entardecer 18:00→19:30, noite 19:30→04:30, amanhecer 04:30→06:00.
 * À noite o relógio corre mais rápido (o dia fica mais longo para jogar).
 */
export function horaDoJogo(t) {
    const s = ((t % CICLO) + CICLO) % CICLO;
    const trechos = [[DIA, 6, 12], [ENTARDECER, 18, 1.5], [NOITE, 19.5, 9], [CICLO - DIA - ENTARDECER - NOITE, 4.5, 1.5]];
    let inicio = 0;
    for (const [duracao, hora, horas] of trechos) {
        if (s < inicio + duracao) return (hora + ((s - inicio) / duracao) * horas) % 24;
        inicio += duracao;
    }
    return 6;
}

/** Ângulo da roda do céu (graus, sentido horário): 0 = sol no alto (meio-dia), 180 = lua no alto. */
export function anguloCeu(hora) {
    return ((hora - 12) / 24) * 360;
}

/** Brilho laranja do pôr e do nascer do sol (0–1), máximo perto das 18:30 e das 05:15. */
export function brilhoCrepusculo(hora) {
    const perto = (alvo, largura) => Math.max(0, 1 - Math.abs(hora - alvo) / largura);
    return Math.max(perto(18.5, 1.2), perto(5.25, 1));
}

/** Estrelas visíveis por nível da cidade (a poluição esconde as estrelas). */
export const ESTRELAS_POR_NIVEL = [15, 30, 50, 80];

/* ---------------------------------------------------------------------------
   Regras visuais
   --------------------------------------------------------------------------- */

/** Chance, por segundo, de uma pessoa andando largar lixo, por estágio da Reciclagem (1–4). */
export const CHANCE_LIXO = [0.05, 0.02, 0.005, 0];
export const LIXO_MAX = 25;               // itens de lixo jogado ao mesmo tempo
export const LIXO_VIDA = 40;              // segundos até sumir
export const CHANCE_CATADOR = 0.3;        // no estágio 4 de Reciclagem
export const CHANCE_CACHORRO = 0.18;

/** Atitudes nas estruturas, pelo modelo de desenho (vale para as da administração com o mesmo modelo). */
export const USOS = {
    banco: 'sentar', praca: 'sentar', quadra: 'jogar', parquinho: 'brincar', horta: 'regar', pomar: 'colher',
};
/** Altura da luz das janelas à noite, pelo modelo de desenho. */
const LUZ = {
    casa: 1, predio: 3, bar: 1, balada: 1.4, lanchonete: 1, mercado: 1, padaria: 1, posto: 1.8, lava_rapido: 1,
    hospital: 2, bombeiros: 1.5, delegacia: 1.5, prefeitura: 2, escola: 1.5, poste: 2.6,
};

/* ---------------------------------------------------------------------------
   Desenhos (strings SVG). `noPonto`: desenho em torno de (0, 0), posicionado depois.
   --------------------------------------------------------------------------- */

const [PX, PY] = p(0, 0, 0);   // ponto de apoio das pessoas (props.js › pessoa)

/** Papel, lata ou garrafa no chão. */
export function itemLixo() {
    const tipo = Math.floor(Math.random() * 3);
    const giro = Math.round(Math.random() * 180);
    if (tipo === 0) return `<rect x="-1.8" y="-1.1" width="3.6" height="2.2" rx="0.4" fill="#ECEFF1" stroke="#90A4AE" stroke-width="0.4" transform="rotate(${giro})"/>`;
    if (tipo === 1) return `<rect x="-1.4" y="-0.8" width="2.8" height="1.6" rx="0.6" fill="#E53935" stroke="#B71C1C" stroke-width="0.4" transform="rotate(${giro})"/>`;
    return `<rect x="-2" y="-0.7" width="4" height="1.4" rx="0.7" fill="#81C784" stroke="#2E7D32" stroke-width="0.4" opacity="0.9" transform="rotate(${giro})"/>`;
}

const ICONES_BALAO = {
    folha: '<path d="M-4 3C-4-3 1-5 5-5 5 0 2 4-4 3Z" fill="#43A047"/><path d="M-4 3 2-2" stroke="#1B5E20" stroke-width="0.7"/>',
    coracao: '<path d="M0 4C-6 0-5-5-2-5-1-5 0-4 0-3 0-4 1-5 2-5 5-5 6 0 0 4Z" fill="#E53935"/>',
    sol: '<circle r="2.6" fill="#FFC107"/><g stroke="#FFA000" stroke-width="0.9" stroke-linecap="round"><path d="M0-5V-4M0 4V5M-5 0H-4M4 0H5M-3.5-3.5-2.8-2.8M2.8 2.8 3.5 3.5M-3.5 3.5-2.8 2.8M2.8-2.8 3.5-3.5"/></g>',
    reciclar: '<path d="M-3 2-4.5-0.5-2.5-3.5M3 2H-1M2.5-3.5 4.5-0.5" fill="none" stroke="#0288D1" stroke-width="1.3" stroke-linecap="round"/>',
    fumaca: '<circle cx="-2.5" cy="1" r="2.3" fill="#757575"/><circle cx="1" cy="-1" r="2.8" fill="#616161"/><circle cx="3.5" cy="1.5" r="1.9" fill="#9E9E9E"/>',
    lixo: '<rect x="-3" y="-2.5" width="6" height="6.5" rx="1" fill="#78909C"/><rect x="-3.8" y="-4" width="7.6" height="1.6" rx="0.6" fill="#546E7A"/>',
    triste: '<circle r="4.3" fill="#FFE082" stroke="#F9A825" stroke-width="0.6"/><circle cx="-1.5" cy="-1" r="0.6" fill="#5D4037"/><circle cx="1.5" cy="-1" r="0.6" fill="#5D4037"/><path d="M-2 2.6Q0 0.8 2 2.6" fill="none" stroke="#5D4037" stroke-width="0.7"/>',
};
export const BALAO_BOM = ['folha', 'coracao', 'sol', 'reciclar'];
export const BALAO_RUIM = ['fumaca', 'lixo', 'triste'];

/** Balão de conversa acima da cabeça (ponto de apoio da pessoa em 0, 0). */
export function balao(icone) {
    return `<g transform="translate(0 -24)"><g class="vida-balao">
        <path d="M-8-7H8A3 3 0 0 1 11-4V3A3 3 0 0 1 8 6H2L0 9-2 6H-8A3 3 0 0 1-11 3V-4A3 3 0 0 1-8-7Z" fill="#FFFFFF" stroke="#546E7A" stroke-width="0.8"/>
        <g transform="translate(0 -0.5)">${ICONES_BALAO[icone] || ''}</g></g></g>`;
}

/** Cachorro que acompanha a pessoa (dentro do desenho da pessoa). */
export function cachorro() {
    const [x, y] = [PX - 7, PY];
    const cor = ['#8D6E63', '#FFFFFF', '#3E2723', '#FFB74D'][Math.floor(Math.random() * 4)];
    return `<ellipse cx="${x}" cy="${y}" rx="2.6" ry="1.1" fill="rgba(0,0,0,0.2)"/><g class="vida-cachorro">
        <path d="M${x - 1.6} ${y} V${y - 2}M${x + 1.4} ${y} V${y - 2}" stroke="#4E342E" stroke-width="0.8"/>
        <ellipse cx="${x}" cy="${y - 2.8}" rx="2.6" ry="1.4" fill="${cor}" stroke="rgba(0,0,0,0.35)" stroke-width="0.4"/>
        <circle cx="${x + 2.6}" cy="${y - 4}" r="1.3" fill="${cor}" stroke="rgba(0,0,0,0.35)" stroke-width="0.4"/>
        <path d="M${x - 2.4} ${y - 3.2} l-1.4 -1.4" stroke="${cor === '#FFFFFF' ? '#BDBDBD' : cor}" stroke-width="0.8" stroke-linecap="round"/></g>`;
}

/** Saquinho verde de quem cata lixo (dentro do desenho da pessoa). */
export function saco() {
    return `<g class="vida-saco"><path d="M${PX + 2.4} ${PY - 6.5}l0.6 -1" stroke="#1B5E20" stroke-width="0.5"/>
        <rect x="${PX + 2.2}" y="${PY - 6.4}" width="3" height="3.6" rx="1" fill="#43A047" stroke="#1B5E20" stroke-width="0.4"/></g>`;
}

/** Objeto da atitude ao lado da pessoa (bola, gotas, fruta). */
export function objetoAcao(acao) {
    if (acao === 'jogar') return `<g transform="translate(5 -2)"><circle class="vida-bola" r="1.8" fill="#FB8C00" stroke="#BF360C" stroke-width="0.4"/></g>`;
    if (acao === 'regar') return `<g transform="translate(4 -6)"><rect x="-1" y="-1.5" width="3" height="2.4" rx="0.5" fill="#26A69A"/>
        <g class="vida-gotas" fill="#4FC3F7"><circle cx="2.6" cy="1.4" r="0.5"/><circle cx="3.4" cy="2.6" r="0.5"/><circle cx="2.4" cy="3.4" r="0.5"/></g></g>`;
    if (acao === 'colher') return `<g transform="translate(3 -14)"><circle class="vida-fruta" r="1.3" fill="#E53935" stroke="#B71C1C" stroke-width="0.3"/></g>`;
    return '';
}

/** Bando de 3 pássaros (posicionado e movido por traffic.js). */
export function passaros() {
    const asa = (x, y) => `<path class="vida-asa" d="M${x - 3} ${y - 1.5}Q${x - 1.5} ${y - 2.5} ${x} ${y}Q${x + 1.5} ${y - 2.5} ${x + 3} ${y - 1.5}" fill="none" stroke="#37474F" stroke-width="1" stroke-linecap="round"/>`;
    return asa(0, 0) + asa(-8, 5) + asa(7, 6);
}

/** Pombo bicando o chão. */
export function pombo() {
    const atraso = (Math.random() * 1.5).toFixed(2);
    return `<ellipse cx="0" cy="0.3" rx="2" ry="0.8" fill="rgba(0,0,0,0.2)"/><g class="vida-pombo" style="animation-delay:${atraso}s">
        <ellipse cx="0" cy="-1.6" rx="2.2" ry="1.3" fill="#90A4AE" stroke="#546E7A" stroke-width="0.4"/>
        <circle cx="2" cy="-2.6" r="0.9" fill="#78909C"/><path d="M2.8 -2.6l0.8 0.3" stroke="#FFA000" stroke-width="0.5"/></g>`;
}

/** Nota musical da Balada. */
export function nota() {
    const cor = ['#E040FB', '#40C4FF', '#FFEB3B', '#FF4081'][Math.floor(Math.random() * 4)];
    return `<path d="M0 0V-6l4-1.2v5.4" fill="none" stroke="${cor}" stroke-width="1"/><ellipse cx="-1" cy="0" rx="1.3" ry="1" fill="${cor}"/><ellipse cx="3" cy="-1.8" rx="1.3" ry="1" fill="${cor}"/>`;
}

/* ---------------------------------------------------------------------------
   Camadas da noite (desenhadas com o cenário em city-map.js)
   --------------------------------------------------------------------------- */

/**
 * Luzes que acendem à noite (opacidade controlada por traffic.js).
 * @param {{ lotes: number[], construidas: object[] }} construcao
 * @param {Record<number, number[]>} origem  LOTE_ORIGEM
 */
export function luzesNoturnas(construcao, origem) {
    const brilho = (x, y, z, r, extra = '') => { const [sx, sy] = p(x, y, z); return `<circle cx="${sx.toFixed(1)}" cy="${sy.toFixed(1)}" r="${r}" fill="url(#city-luz)" ${extra}/>`; };
    let svg = `<defs><radialGradient id="city-luz"><stop offset="0%" stop-color="#FFF59D" stop-opacity="0.95"/><stop offset="45%" stop-color="#FFE082" stop-opacity="0.45"/><stop offset="100%" stop-color="#FFE082" stop-opacity="0"/></radialGradient>
        <radialGradient id="city-luz-balada"><stop offset="0%" stop-color="#E040FB" stop-opacity="0.8"/><stop offset="100%" stop-color="#7C4DFF" stop-opacity="0"/></radialGradient></defs>`;
    // Centro: janelas das casas do bairro e luz nas calçadas das avenidas
    CASAS.forEach(([cx, cy]) => { svg += brilho(cx + 1.1, cy + 1.1, 1.2, 14); });
    [3, 6, 14, 17].forEach((k) => { svg += brilho(8.6, k, 0.3, 9) + brilho(11.4, k, 0.3, 9) + brilho(k, 8.6, 0.3, 9) + brilho(k, 11.4, 0.3, 9); });
    // Lotes: estruturas iluminadas pelo modelo; o prédio personalizado pela altura
    construcao.construidas.filter((c) => construcao.lotes.includes(c.lote)).forEach((c) => {
        const z = c.desenho === 'personalizado' ? 0.65 * (c.aparencia?.andares || 1) : LUZ[c.desenho];
        if (z === undefined) return;
        const [ox, oy] = origem[c.lote];
        const [x, y] = [ox + c.x + c.w / 2, oy + c.y + c.d / 2];
        if (c.desenho === 'poste') { svg += brilho(x, y, z, 12); return; }
        svg += brilho(x, y, z, Math.round(10 + 5 * Math.max(c.w, c.d)));
        if (c.desenho === 'balada') {
            const [sx, sy] = p(x, y, 1);
            svg += `<circle class="vida-luz-balada" cx="${sx.toFixed(1)}" cy="${sy.toFixed(1)}" r="46" fill="url(#city-luz-balada)"/>`;
        }
    });
    return svg;
}
