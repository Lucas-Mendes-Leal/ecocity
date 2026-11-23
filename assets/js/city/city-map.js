/**
 * Mapa isométrico da cidade: compõe céu, tabuleiro, regiões, lotes, pontos de
 * interação e legenda. Re-renderiza quando os indicadores mudam.
 * Zoom (botões, roda do mouse, pinça) e arrasto mudam só o viewBox.
 */
import { p, poly, quad, shade, renderSprites, BOARD } from './iso.js';
import { energia, bairro, lago, parque, vias, QUADRANTES } from './regions.js';
import { camadasLotes, limitesTela, gradeLote, LOTE_ORIGEM } from './expansion.js';
import { anexarTrafego, movimentoReduzido, trafegoAtual } from './traffic.js';
import { luzesNoturnas, ESTRELAS_POR_NIVEL } from './vida.js';
import { REGIOES, CATEGORIA_POR_ID, NIVEIS_CIDADE } from '../data/catalog.js';
import { iconPaths, icon } from '../ui/icons.js';
import { escapeHTML } from '../ui/dom.js';

const PIN_ESCALA_MOBILE = 1.8;      // pinos maiores para toque em telas estreitas
const LARGURA_MOBILE = 600;
const SLAB = 1.5; // espessura do bloco de terra
const ZOOM_MAX = 4;
const ZOOM_PASSO = 1.4;
const ARRASTO_MIN = 5;   // px de movimento para contar como arrasto (e não clique)

const CEU = [
    ['#90A4AE', '#CFD8DC'],
    ['#81D4FA', '#E0F2F1'],
    ['#4FC3F7', '#E1F5FE'],
    ['#29B6F6', '#E8F5E9'],
];
const SOL = ['#CFD8DC', '#FFE082', '#FFD54F', '#FFC107'];
const HORIZONTE_Y = 300;   // linha do horizonte (base dos prédios do fundo e centro da roda do céu)

/** Cor de destaque de cada categoria (mesmos tokens do CSS). */
const COR_CATEGORIA = {
    recycling: '#0288D1', water: '#00838F', energy: '#EF6C00', mobility: '#43A047', nature: '#2E7D32',
};

/** Âncora (grade) de cada ponto de interação. */
const ANCORA_PIN = {
    energy: [4.4, 4.4, 4.6],
    recycling: [15.6, 4.2, 3.6],
    water: [4.4, 15.7, 1.4],
    nature: [15.6, 15.6, 3.4],
    mobility: [10, 10, 1.4],
};

/* ---------------------------------------------------------------------------
   Camadas
   --------------------------------------------------------------------------- */

/** Sequência pseudoaleatória fixa (mesmo desenho a cada renderização). */
function sorteio(semente) {
    let a = semente >>> 0;
    return () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * Céu cobrindo a área visível [x, y, largura, altura]. Com `ciclo` (mapa do jogo), desenha a roda
 * do céu que gira no sentido horário com o sol e, do lado oposto, a lua e as estrelas, além do céu
 * noturno e do brilho do pôr do sol (opacidades e giro controlados por traffic.js). Começa ao meio-dia.
 */
function ceu(estagio, [vx, vy, vw, vh], ciclo = false) {
    const [topo, base] = CEU[estagio - 1];
    const cx = vx + vw / 2;
    // Sol e lua andam numa elipse (nascem e se põem nas laterais da vista); as estrelas giram num círculo
    const rx = Math.max(240, vw * 0.42);
    const ry = Math.max(160, HORIZONTE_Y - vy - 70);
    let svg = `<defs>
        <linearGradient id="city-sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="${topo}"/><stop offset="100%" stop-color="${base}"/>
        </linearGradient>
        <linearGradient id="city-sky-noite" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#081430"/><stop offset="100%" stop-color="#2A3F6B"/>
        </linearGradient>
        <linearGradient id="city-crepusculo" gradientUnits="userSpaceOnUse" x1="0" y1="${HORIZONTE_Y - 220}" x2="0" y2="${HORIZONTE_Y + 220}">
            <stop offset="0%" stop-color="#FF8A65" stop-opacity="0"/><stop offset="42%" stop-color="#FF8A50" stop-opacity="0.6"/>
            <stop offset="52%" stop-color="#FFB74D" stop-opacity="0.85"/><stop offset="100%" stop-color="#FFB74D" stop-opacity="0"/>
        </linearGradient>
        <clipPath id="city-ceu-recorte"><rect x="${vx}" y="${vy}" width="${vw}" height="${Math.max(0, HORIZONTE_Y - vy)}"/></clipPath>
    </defs>
    <rect x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="url(#city-sky)"/>`;

    let lua = '';
    if (ciclo) {
        svg += `<rect class="city-ceu-noite" x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="url(#city-sky-noite)" opacity="0"/>
            <rect class="city-crepusculo" x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="url(#city-crepusculo)" opacity="0"/>`;
        // Estrelas na metade de baixo da roda (sobem junto com a lua), numa coroa que cobre a vista
        const alcance = Math.hypot(vw / 2, HORIZONTE_Y - vy) + 20;
        const rnd = sorteio(4242);
        let estrelas = '';
        for (let i = 0; i < ESTRELAS_POR_NIVEL[estagio - 1]; i += 1) {
            const ang = rnd() * Math.PI;
            const dist = 60 + Math.sqrt(rnd()) * (alcance - 60);
            const r = 0.8 + rnd() * 1.6;
            estrelas += `<circle cx="${(cx + Math.cos(ang) * dist).toFixed(1)}" cy="${(HORIZONTE_Y + Math.sin(ang) * dist).toFixed(1)}" r="${r.toFixed(1)}"${i % 5 === 0 ? ' class="estrela-pisca"' : ''}/>`;
        }
        lua = `<g class="city-ceu-roda" data-cx="${cx}" data-cy="${HORIZONTE_Y}"><g class="city-estrelas" fill="#FFFFFF" opacity="0">${estrelas}</g></g>
            <g class="city-lua" transform="translate(${cx} ${HORIZONTE_Y + ry})"><circle r="46" fill="#FFFDE7" opacity="0.16"/>
                <circle r="28" fill="#F4F1D0"/><circle cx="-9" cy="-7" r="5" fill="#E2DDB4"/><circle cx="8" cy="6" r="7" fill="#E2DDB4"/>
                <circle cx="10" cy="-11" r="3" fill="#E2DDB4"/></g>`;
    }

    // Céu girando (sentido horário), recortado no horizonte; começa ao meio-dia com o sol no alto
    svg += `<g class="city-ceu" clip-path="url(#city-ceu-recorte)" data-cx="${cx}" data-cy="${HORIZONTE_Y}" data-rx="${rx}" data-ry="${ry}">
        ${lua}<g class="city-sol" transform="translate(${cx} ${HORIZONTE_Y - ry})"><circle r="38" fill="${SOL[estagio - 1]}" ${estagio >= 3 ? 'class="sun-glow"' : ''}/></g>
    </g>`;

    // Nuvens: poluição nos estágios 1–2, nuvens brancas nos 3–4 (quase somem à noite)
    const nuvem = (x, y, s, cor, op) => `<g class="cloud" opacity="${op}" transform="translate(${x} ${y}) scale(${s})">
        <ellipse cx="0" cy="0" rx="60" ry="20" fill="${cor}"/><ellipse cx="-28" cy="-12" rx="30" ry="20" fill="${cor}"/>
        <ellipse cx="22" cy="-16" rx="34" ry="24" fill="${cor}"/></g>`;
    let nuvens = '';
    if (estagio === 1) nuvens = nuvem(230, 80, 1.6, '#6D6D6D', 0.55) + nuvem(640, 60, 2, '#757575', 0.5) + nuvem(880, 150, 1.3, '#616161', 0.45);
    if (estagio === 2) nuvens = nuvem(260, 80, 1.3, '#9E9E9E', 0.45) + nuvem(700, 70, 1.5, '#B0BEC5', 0.4);
    if (estagio >= 3) nuvens = nuvem(240, 90, 1.1, '#FFFFFF', 0.9) + nuvem(720, 60, 1.3, '#FFFFFF', 0.85);
    return `${svg}<g class="city-nuvens">${nuvens}</g>`;
}

/**
 * Silhueta de cidade ao fundo em duas fileiras (parcialmente escondida pelo tabuleiro), com janelas
 * em 4 grupos que acendem aos poucos ao anoitecer (opacidade de cada grupo controlada por traffic.js).
 */
function horizonte(estagio) {
    const cor = ['#78909C', '#90A4AE', '#B0BEC5', '#B3E5FC'][estagio - 1];
    const corLonge = ['#90A4AE', '#B0BEC5', '#CFD8DC', '#D6EEF8'][estagio - 1];
    const longe = [[300, 120], [350, 160], [395, 135], [440, 185], [480, 150], [560, 140], [600, 195], [640, 150], [690, 180], [735, 125], [775, 165], [815, 130]];
    const perto = [[330, 150], [372, 110], [410, 175], [455, 130], [500, 95], [610, 120], [655, 170], [700, 105], [745, 140], [785, 115]];
    const rnd = sorteio(1717);
    const janelas = [[], [], [], []];
    const predio = (x, h, w, fill, op) => {
        const topo = HORIZONTE_Y - h;
        for (let jy = topo + 8; jy < HORIZONTE_Y - 6; jy += 13) {
            for (let jx = x + 5; jx + 9 <= x + w; jx += 9) {
                if (rnd() < 0.45) janelas[Math.floor(rnd() * 4)].push(`<rect x="${jx}" y="${jy}" width="5" height="6" rx="1"/>`);
            }
        }
        return `<rect x="${x}" y="${topo}" width="${w}" height="${h}" fill="${fill}" opacity="${op}"/>`;
    };
    let svg = longe.map(([x, h], i) => predio(x, h, 30 + (i % 2) * 6, corLonge, 0.5)).join('');
    svg += perto.map(([x, h], i) => {
        const w = 36 + (i % 3) * 6;
        const telhado = estagio === 4 && i % 2 === 0 ? `<rect x="${x}" y="${HORIZONTE_Y - h - 6}" width="${w}" height="8" rx="3" fill="#81C784"/>` : '';
        return predio(x, h, w, cor, 0.75) + telhado;
    }).join('');
    return svg + janelas.map((lista, k) => `<g class="city-janelas city-janelas-${k + 1}" fill="#FFD54F" opacity="0">${lista.join('')}</g>`).join('');
}

function bloco() {
    const n = BOARD;
    const gramaBorda = (list) => poly(list, '#7CB342');
    return [
        `<ellipse cx="${p(n, n)[0]}" cy="${p(n, n)[1] + 40}" rx="520" ry="46" fill="rgba(0,0,0,0.08)"/>`,
        poly([[0, n, 0], [n, n, 0], [n, n, -SLAB], [0, n, -SLAB]], '#6D4C41'),
        poly([[n, 0, 0], [n, n, 0], [n, n, -SLAB], [n, 0, -SLAB]], '#5D4037'),
        gramaBorda([[0, n, 0], [n, n, 0], [n, n, -0.3], [0, n, -0.3]]),
        poly([[n, 0, 0], [n, n, 0], [n, n, -0.3], [n, 0, -0.3]], '#689F38'),
        quad(0, 0, n, n, '#9E9D7A'),
    ].join('');
}

function areaDeClique(regiaoId) {
    if (regiaoId === 'mobility') {
        return poly([[0, 8.7, 0], [20, 8.7, 0], [20, 11.3, 0], [0, 11.3, 0]], 'transparent')
            + poly([[8.7, 0, 0], [11.3, 0, 0], [11.3, 20, 0], [8.7, 20, 0]], 'transparent');
    }
    const q = QUADRANTES[regiaoId];
    return poly([[q.x, q.y, 0], [q.x + q.w, q.y, 0], [q.x + q.w, q.y + q.d, 0], [q.x, q.y + q.d, 0]], 'transparent');
}

/** Área de toque mínima dos pins, em pixels da tela (design.md §9). */
const TOQUE_MIN_PX = 44;

function pin(regiao, estagio, escala, raioToque) {
    const [x, y, z] = ANCORA_PIN[regiao.id];
    const [sx, sy] = p(x, y, z);
    const [gx, gy] = p(x, y, 0);
    const cor = COR_CATEGORIA[regiao.indicador];
    const rotulo = `${regiao.nome} — estágio ${estagio}`;
    return `<g class="city-pin" data-region="${regiao.id}" role="button" tabindex="0" aria-label="${escapeHTML(rotulo)}">
        <title>${escapeHTML(rotulo)}</title>
        <line x1="${gx}" y1="${gy}" x2="${sx}" y2="${sy + 22}" stroke="${cor}" stroke-width="2" stroke-dasharray="3 3" opacity="0.7"/>
        <ellipse cx="${gx}" cy="${gy}" rx="7" ry="3.5" fill="${cor}" opacity="0.5"/>
        <g transform="translate(${Math.round(sx)} ${Math.round(sy)}) scale(${escala})">
            <circle class="city-pin-hit" r="${raioToque}" fill="transparent"/>
            <circle class="city-pin-pulse" r="24" fill="${cor}"/>
            <circle class="city-pin-body" r="23" fill="#FFFFFF" stroke="${cor}" stroke-width="3"/>
            <g transform="translate(-12 -12)" fill="none" stroke="${cor}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${iconPaths(CATEGORIA_POR_ID[regiao.indicador].icone)}</g>
            <g transform="translate(17 -19)">
                <circle r="9.5" fill="${shade(cor, -0.1)}" stroke="#FFFFFF" stroke-width="2"/>
                <text y="4" text-anchor="middle" font-size="11" font-weight="700" fill="#FFFFFF">${estagio}</text>
            </g>
        </g>
    </g>`;
}

/** Moedas sobre as estruturas com renda a coletar (RN79); clicar coleta (build-mode.js). */
function moedas(construcao) {
    if (!(construcao.efeitos?.renda > 0)) return '';
    return construcao.construidas.filter((c) => c.renda > 0 && construcao.lotes.includes(c.lote)).map((c) => {
        const [ox, oy] = LOTE_ORIGEM[c.lote];
        const [sx, sy] = p(ox + c.x + c.w / 2, oy + c.y + c.d / 2, 3.2);
        const rotulo = `Coletar a renda do comércio (${c.nome})`;
        return `<g class="city-coin" data-coletar role="button" tabindex="0" aria-label="${escapeHTML(rotulo)}" transform="translate(${Math.round(sx)} ${Math.round(sy)})">
            <title>${escapeHTML(rotulo)}</title>
            <circle r="22" fill="transparent"/>
            <g class="city-coin-body"><circle r="11" fill="#FBC02D" stroke="#F57F17" stroke-width="2.5"/><circle r="6" fill="none" stroke="#F57F17" stroke-width="1.6"/></g>
        </g>`;
    }).join('');
}

/* ---------------------------------------------------------------------------
   API pública
   --------------------------------------------------------------------------- */

let refs = null;
let selecionada = null;
let ultimaChave = '';
let ultimosDados = null;
let modoMobile = false;
let loteEmFoco = null;   // lote aberto no modo construção (null = cidade inteira)
let vistaBase = null;    // viewBox completo [x, y, largura, altura]
let vistaAtual = null;   // viewBox com zoom aplicado

/* ---------------------------------------------------------------------------
   Zoom e arrasto
   --------------------------------------------------------------------------- */

function svgAtual() {
    return refs?.container.querySelector('svg');
}

function aplicarVista() {
    const svg = svgAtual();
    if (!svg || !vistaAtual) return;
    svg.setAttribute('viewBox', vistaAtual.map((n) => Math.round(n * 10) / 10).join(' '));
    const escala = vistaBase[2] / vistaAtual[2];
    svg.classList.toggle('is-zoomed', escala > 1.01);
    if (refs.zoomIn) refs.zoomIn.disabled = escala >= ZOOM_MAX - 0.01;
    if (refs.zoomOut) refs.zoomOut.disabled = escala <= 1.01;
    if (refs.zoomReset) refs.zoomReset.disabled = escala <= 1.01;
}

/** Mantém a vista dentro dos limites da cidade. */
function limitar([x, y, w, h]) {
    const [bx, by, bw, bh] = vistaBase;
    return [Math.min(Math.max(x, bx), bx + bw - w), Math.min(Math.max(y, by), by + bh - h), w, h];
}

/** Multiplica o zoom por `fator`, mantendo o ponto (sx, sy) do SVG no mesmo lugar da tela. */
function zoomEm(fator, sx, sy) {
    if (!vistaAtual) return;
    const [x, y, w, h] = vistaAtual;
    const escala = Math.min(ZOOM_MAX, Math.max(1, (vistaBase[2] / w) * fator));
    const nw = vistaBase[2] / escala, nh = vistaBase[3] / escala;
    const ax = sx ?? x + w / 2, ay = sy ?? y + h / 2;
    vistaAtual = limitar([ax - (ax - x) * (nw / w), ay - (ay - y) * (nh / h), nw, nh]);
    aplicarVista();
}

function moverVista(dx, dy) {
    const [x, y, w, h] = vistaAtual;
    vistaAtual = limitar([x - dx, y - dy, w, h]);
    aplicarVista();
}

export function resetZoom() {
    vistaAtual = vistaBase && [...vistaBase];
    aplicarVista();
}

function initZoom(container) {
    const ponteiros = new Map();
    let arrastou = false;
    let distanciaPinca = 0;

    const escalaTela = () => {   // unidades do SVG por pixel da tela
        const svg = svgAtual();
        return svg ? vistaAtual[2] / svg.getBoundingClientRect().width : 1;
    };

    container.addEventListener('wheel', (e) => {
        if (!vistaAtual) return;
        e.preventDefault();
        const pt = pontoNoSvg(e.clientX, e.clientY);
        zoomEm(e.deltaY < 0 ? 1.15 : 1 / 1.15, pt?.x, pt?.y);
    }, { passive: false });

    container.addEventListener('pointerdown', (e) => {
        ponteiros.set(e.pointerId, { x: e.clientX, y: e.clientY, x0: e.clientX, y0: e.clientY });
        if (ponteiros.size === 1) arrastou = false;
        if (ponteiros.size === 2) {
            const [a, b] = [...ponteiros.values()];
            distanciaPinca = Math.hypot(a.x - b.x, a.y - b.y);
        }
    });
    container.addEventListener('pointermove', (e) => {
        const pt = ponteiros.get(e.pointerId);
        if (!pt || !vistaAtual) return;
        const dx = e.clientX - pt.x, dy = e.clientY - pt.y;
        pt.x = e.clientX;
        pt.y = e.clientY;

        if (ponteiros.size === 2) {   // pinça
            const [a, b] = [...ponteiros.values()];
            const dist = Math.hypot(a.x - b.x, a.y - b.y);
            if (distanciaPinca) {
                const centro = pontoNoSvg((a.x + b.x) / 2, (a.y + b.y) / 2);
                zoomEm(dist / distanciaPinca, centro?.x, centro?.y);
            }
            distanciaPinca = dist;
            arrastou = true;
            return;
        }
        if (!arrastou && Math.hypot(e.clientX - pt.x0, e.clientY - pt.y0) < ARRASTO_MIN) return;
        if (vistaBase[2] / vistaAtual[2] <= 1.01) return;   // sem zoom não há o que arrastar
        if (!arrastou) {
            arrastou = true;
            container.setPointerCapture?.(e.pointerId);
        }
        const k = escalaTela();
        moverVista(dx * k, dy * k);
    });
    const soltar = (e) => {
        ponteiros.delete(e.pointerId);
        if (ponteiros.size < 2) distanciaPinca = 0;
    };
    container.addEventListener('pointerup', soltar);
    container.addEventListener('pointercancel', soltar);

    // Um arrasto não vira clique (não abre região nem posiciona construção)
    container.addEventListener('click', (e) => {
        if (!arrastou) return;
        arrastou = false;
        e.stopImmediatePropagation();
        e.preventDefault();
    }, true);

    refs.zoomIn?.addEventListener('click', () => zoomEm(ZOOM_PASSO));
    refs.zoomOut?.addEventListener('click', () => zoomEm(1 / ZOOM_PASSO));
    refs.zoomReset?.addEventListener('click', resetZoom);
}

/**
 * @param {object} opts
 * @param {HTMLElement} opts.container  elemento que recebe o SVG
 * @param {HTMLElement} opts.legend     elemento da legenda de regiões
 * @param {HTMLElement} opts.badge      selo do nível da cidade
 * @param {HTMLButtonElement} [opts.zoomIn]    botão de aproximar
 * @param {HTMLButtonElement} [opts.zoomOut]   botão de afastar
 * @param {HTMLButtonElement} [opts.zoomReset] botão de ver a cidade inteira
 * @param {(regiaoId: string, origem: Element) => void} opts.onSelect
 */
export function initCityMap(opts) {
    refs = opts;
    initZoom(opts.container);

    const ativar = (target) => {
        const alvo = target.closest('[data-region]');
        if (alvo) opts.onSelect(alvo.dataset.region, alvo);
    };
    opts.container.addEventListener('click', (e) => ativar(e.target));
    opts.container.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('.city-pin')) {
            e.preventDefault();
            ativar(e.target);
        }
    });
    opts.legend.addEventListener('click', (e) => ativar(e.target));

    // Destaque da região ao passar o mouse sobre o pin ou a legenda
    const destacar = (e, on) => {
        const alvo = e.target.closest('[data-region]');
        if (!alvo) return;
        opts.container.querySelector(`.region-hit[data-region="${alvo.dataset.region}"]`)?.classList.toggle('is-hover', on);
    };
    [opts.container, opts.legend].forEach((el) => {
        el.addEventListener('pointerover', (e) => destacar(e, true));
        el.addEventListener('pointerout', (e) => destacar(e, false));
    });

    // Redesenha só quando cruza o limite celular/desktop (tamanho dos pinos)
    window.addEventListener('resize', () => {
        const mobile = window.innerWidth < LARGURA_MOBILE;
        if (mobile !== modoMobile && ultimosDados) renderCity(ultimosDados);
    });
}

/** Devolve o trânsito ao mapa próprio (depois de uma visita, que usa o mesmo motor). */
export function reanexarCidade() {
    const svg = refs?.container.querySelector('svg');
    if (!svg || !ultimosDados) return;
    const atual = trafegoAtual();
    if (atual.cidade === 'propria' && atual.svg === svg) return;
    anexarTrafego(svg, ultimosDados);
}

/** Marca a região selecionada (painel aberto). */
export function setSelectedRegion(regiaoId) {
    selecionada = regiaoId;
    refs?.container.querySelectorAll('.region-hit').forEach((el) => {
        el.classList.toggle('is-active', el.dataset.region === regiaoId);
    });
}

/** Abre (número do lote) ou fecha (null) o modo construção e redesenha. */
export function focarLote(lote) {
    loteEmFoco = lote;
    if (ultimosDados) renderCity(ultimosDados);
}

/** Atualiza só a camada de prévia do modo construção (sem redesenhar a cidade). */
export function setBuildOverlay(html) {
    const g = refs?.container.querySelector('#build-overlay');
    if (g) g.innerHTML = html;
}

/** Converte a posição do ponteiro na tela para coordenadas do SVG. */
export function pontoNoSvg(clientX, clientY) {
    const svg = refs?.container.querySelector('svg');
    if (!svg) return null;
    const pt = svg.createSVGPoint();
    pt.x = clientX;
    pt.y = clientY;
    return pt.matrixTransform(svg.getScreenCTM().inverse());
}

/**
 * Cenário da cidade sem interação (céu, horizonte, tabuleiro, regiões, lotes e neblina).
 * Usado pelo mapa do jogo e pelo fundo do login/cadastro.
 * @param {{ nivelCidade: number, estagios: Record<string, number>,
 *           construcao?: { lotes: number[], construidas: object[], lixo: object[] } | null,
 *           foco?: number | null }} dados  sem `construcao`, os lotes não são desenhados
 * @returns {{ svg: string, vista: number[] }}
 */
export function desenharCenario({ nivelCidade, estagios, construcao = null, foco = null }) {
    const camadas = [
        energia(estagios.energy), bairro(estagios.recycling), lago(estagios.water),
        parque(estagios.nature), vias(estagios.mobility, { parados: movimentoReduzido() }),
    ];
    const lotes = construcao ? camadasLotes(construcao, estagios) : { blocos: '', solo: '', sprites: [], neblina: '' };
    const solo = camadas.map((c) => c.ground).join('') + lotes.solo;
    const objetos = renderSprites([...camadas.flatMap((c) => c.sprites), ...lotes.sprites]);
    const neblina = [0.2, 0.08, 0, 0][nivelCidade - 1];
    const vista = limitesTela(construcao ? construcao.lotes : [], foco);
    const [vx, vy, vw, vh] = vista;

    const svg = `${ceu(nivelCidade, vista, !!construcao)}
        ${horizonte(nivelCidade)}
        <g class="city-board">${bloco()}${lotes.blocos}${solo}<g class="city-traffic" aria-hidden="true"></g>${objetos}${lotes.neblina}</g>
        ${neblina ? `<rect x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="#8D8D7A" opacity="${neblina}" pointer-events="none"/>` : ''}
        ${construcao ? `<defs><linearGradient id="city-noite-grad" gradientUnits="userSpaceOnUse" x1="0" y1="${Math.min(vy, 109)}" x2="0" y2="110">
            <stop offset="0%" stop-color="#0B1A3A" stop-opacity="0"/><stop offset="100%" stop-color="#0B1A3A" stop-opacity="1"/></linearGradient></defs>
        <rect class="city-noite" x="${vx}" y="${vy}" width="${vw}" height="${vh}" fill="url(#city-noite-grad)" opacity="0"/>
        <g class="city-luzes" opacity="0">${luzesNoturnas(construcao, LOTE_ORIGEM)}</g>` : ''}
        <g class="city-vida-topo" aria-hidden="true"></g>`;
    return { svg, vista };
}

/**
 * @param {{ nivelCidade: number, estagios: Record<string, number>,
 *           construcao: { lotes: number[], construidas: object[], lixo: object[] } }} dados
 */
export function renderCity({ nivelCidade, estagios, construcao }) {
    if (!refs) return;
    ultimosDados = { nivelCidade, estagios, construcao };
    if (loteEmFoco && !construcao.lotes.includes(loteEmFoco)) loteEmFoco = null;
    modoMobile = window.innerWidth < LARGURA_MOBILE;
    const escalaPin = modoMobile ? PIN_ESCALA_MOBILE : 1;

    const chave = `${nivelCidade}-${REGIOES.map((r) => estagios[r.id]).join('')}-${construcao.lotes.join('.')}-${construcao.construidas.length}-${loteEmFoco}`;
    const cenario = desenharCenario({ nivelCidade, estagios, construcao, foco: loteEmFoco });
    const vista = cenario.vista;
    // Mantém o zoom entre atualizações; volta à vista inteira quando a área muda (lote em foco, novo lote)
    if (!vistaBase || vistaBase.join() !== vista.join()) vistaAtual = [...vista];
    vistaBase = vista;

    // No modo construção, regiões e pins dão lugar à grade e à prévia do lote em foco
    const hits = loteEmFoco ? '' : REGIOES.map((r) => `<g class="region-hit" data-region="${r.id}">${areaDeClique(r.id)}</g>`).join('');
    // Círculo invisível garante 44px de toque mesmo com o mapa encolhido na tela
    const pxPorUnidade = (refs.container.clientWidth || vista[2]) / vista[2];
    const raioToque = Math.max(24, Math.round(TOQUE_MIN_PX / 2 / (pxPorUnidade * escalaPin)));
    const pins = loteEmFoco ? '' : REGIOES.map((r) => pin(r, estagios[r.id], escalaPin, raioToque)).join('');
    const construir = loteEmFoco ? `${gradeLote(loteEmFoco)}<g id="build-overlay"></g>` : '';

    const nivel = NIVEIS_CIDADE[nivelCidade - 1];
    refs.container.innerHTML = `
        <svg class="city-svg ${chave !== ultimaChave ? 'city-svg-enter' : ''} ${loteEmFoco ? 'is-building' : ''}" viewBox="${vista.join(' ')}"
             role="group" aria-label="Mapa da cidade — nível ${nivelCidade}: ${nivel.nome}">
            ${cenario.svg}
            <g class="city-hits">${hits}</g>
            <g class="city-pins">${pins}</g>
            ${loteEmFoco ? '' : `<g class="city-coins">${moedas(construcao)}</g>`}
            ${construir}
        </svg>`;
    ultimaChave = chave;
    aplicarVista();
    anexarTrafego(refs.container.querySelector('svg'), { estagios, nivelCidade, construcao });

    refs.badge.innerHTML = `${icon('pin')}<span>Cidade nível ${nivelCidade} · <strong>${nivel.nome}</strong></span>`;

    refs.legend.innerHTML = REGIOES.map((r) => {
        const cat = CATEGORIA_POR_ID[r.indicador];
        return `<button type="button" class="legend-chip" data-region="${r.id}">
            <span class="legend-icon cat-${r.indicador}">${icon(cat.icone)}</span>
            <span class="legend-name">${escapeHTML(r.nome)}</span>
            <span class="legend-stage stage-${estagios[r.id]}">Estágio ${estagios[r.id]}</span>
        </button>`;
    }).join('');

    if (selecionada) setSelectedRegion(selecionada);
}
