/**
 * Cidade viva: carros, ônibus, bicicletas e pessoas em movimento (só visual).
 *
 *  - Veículos andam pela rede de ruas: avenidas do centro (linhas y = 10 e x = 10),
 *    ruas construídas nos lotes (modelos rua e cruzamento) e peças de avenida 4×4
 *    (modelos avenida*, ligadas entre si e às avenidas do centro pelas pontas). Seguem reto quando
 *    podem, viram nos cruzamentos, dão meia-volta no fim da rua e somem no "limbo"
 *    (pontas (0,10) e (10,0), no fundo do tabuleiro). Vivem 2–3 min.
 *  - A frota segue o estágio da Mobilidade (carros com fumaça → ônibus elétrico e bicicletas).
 *  - Pessoas: tantas quanto os habitantes da cidade (RN77: 5 + moradias, até 50; no fundo do login,
 *    4/6/9/12 pelo nível) andam pelas calçadas do centro e pelos lotes; somem na borda, ao "entrar"
 *    numa construção ou após 1 min. A frota soma os veículos das estruturas (RN78).
 *  - Cidade viva (vida.js): lixo jogado pela Reciclagem, catadores, conversas, uso das estruturas,
 *    cachorros, pássaros, pombos, dia e noite com luzes e a Balada movimentada.
 *  - Um só requestAnimationFrame; pausa com a aba escondida ou o mapa fora da tela.
 *    Com "reduzir movimento" nada é criado (o mapa desenha os veículos parados).
 */
import { p } from './iso.js';
import { car, bus, bike, smoke, pessoa } from './props.js';
import {
    CICLO, escuridao, horaDoJogo, anguloCeu, brilhoCrepusculo, NOITE_OPACIDADE, FATOR_NOITE, CHANCE_LIXO, LIXO_MAX, LIXO_VIDA, CHANCE_CATADOR, CHANCE_CACHORRO, USOS,
    itemLixo, balao, BALAO_BOM, BALAO_RUIM, cachorro, saco, objetoAcao, passaros, pombo, nota,
} from './vida.js';
import { LOTE_ORIGEM } from './expansion.js';
import { CASAS } from './regions.js';
import { icon } from '../ui/icons.js';
import { ambiente as somAmbiente } from '../som.js';

// Pelo modelo de desenho (construcao.desenho), não pelo id: estruturas criadas na administração com o modelo de rua também viram rua
const PISAVEIS = new Set(['rua', 'cruzamento', 'calcada', 'avenida', 'avenida_cruzamento', 'avenida_curva', 'avenida_t']);
/** Braços das peças de avenida 4×4, de frente (vetor do centro até a ponta). */
const BRACOS_AVENIDA = {
    avenida: [[-2, 0], [2, 0]],
    avenida_cruzamento: [[-2, 0], [2, 0], [0, -2], [0, 2]],
    avenida_curva: [[-2, 0], [0, 2]],
    avenida_t: [[-2, 0], [2, 0], [0, 2]],
};
/** Mesmo giro do desenho (iso.js): 1 leva a frente (+y) para +x. */
const girarVetor = ([dx, dy], k) => (k === 1 ? [dy, -dx] : k === 2 ? [-dx, -dy] : k === 3 ? [-dy, dx] : [dx, dy]);
const FAIXA = { av: 0.45, rua: 0.18 };
const VIDA_CARRO = [120, 180];      // segundos
const VIDA_PESSOA = 60;
const INTERVALO_SURGIR = 2.5;       // segundos entre novos agentes
const PESSOAS_POR_NIVEL = [4, 6, 9, 12];   // só no fundo do login (sem lotes)
const USOS_ACOES = new Set(Object.values(USOS));
const VELOCIDADE = { carro: 1.6, onibus: 1.1, bike: 0.8, pessoa: 0.45 };   // unidades da grade por segundo
const NS = 'http://www.w3.org/2000/svg';

/** Frota por estágio da Mobilidade. */
const FROTA = {
    1: [['carro', 4], ['carroFumaca', 3]],
    2: [['carro', 4], ['onibusLaranja', 1]],
    3: [['carro', 2], ['onibusVerde', 1], ['bike', 2]],
    4: [['onibusVerde', 1], ['bike', 4]],
};
const CORES_CARRO = {
    1: ['#757575', '#616161', '#5D4037', '#9E9E9E', '#C62828'],
    2: ['#C62828', '#1E88E5', '#9E9E9E', '#FDD835'],
    3: ['#1E88E5', '#26A69A'],
    4: ['#26A69A'],
};
const CORES_PESSOA = ['#E53935', '#1E88E5', '#FDD835', '#8E24AA', '#43A047', '#FB8C00', '#00ACC1'];

export const movimentoReduzido = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const sortear = (lista) => lista[Math.floor(Math.random() * lista.length)];
const entre = ([a, b]) => a + Math.random() * (b - a);
const chave = (x, y) => `${x},${y}`;
const [OX, OY] = p(0, 0);

/* ---------------------------------------------------------------------------
   Redes
   --------------------------------------------------------------------------- */

/** Grafo de ruas: nós { x, y, faixa, limbo }, vizinhos por id. */
function redeDeRuas(construcao) {
    const nos = new Map();
    const viz = new Map();
    const no = (id, x, y, faixa, limbo = false) => { nos.set(id, { id, x, y, faixa, limbo }); viz.set(id, new Set()); };
    const ligar = (a, b) => { viz.get(a).add(b); viz.get(b).add(a); };

    no('A0', 0, 10, FAIXA.av, true); no('B0', 10, 0, FAIXA.av, true);
    no('C', 10, 10, FAIXA.av); no('A20', 20, 10, FAIXA.av); no('B20', 10, 20, FAIXA.av);
    ligar('A0', 'C'); ligar('C', 'A20'); ligar('B0', 'C'); ligar('C', 'B20');

    if (construcao) {
        // Células de rua nos lotes disponíveis: direções permitidas
        const celulas = new Map();
        construcao.construidas.forEach((c) => {
            if (!construcao.lotes.includes(c.lote) || (c.desenho !== 'rua' && c.desenho !== 'cruzamento')) return;
            const [ox, oy] = LOTE_ORIGEM[c.lote];
            const dirs = c.desenho === 'cruzamento' ? 'xy' : (c.girada ? 'y' : 'x');
            for (let i = 0; i < c.w; i += 1) {
                for (let j = 0; j < c.d; j += 1) celulas.set(chave(ox + c.x + i, oy + c.y + j), { x: ox + c.x + i, y: oy + c.y + j, dirs });
            }
        });
        celulas.forEach((c, k) => no(k, c.x + 0.5, c.y + 0.5, FAIXA.rua));
        celulas.forEach((c, k) => {
            const dir = celulas.get(chave(c.x + 1, c.y));
            if (dir && c.dirs.includes('x') && dir.dirs.includes('x')) ligar(k, chave(c.x + 1, c.y));
            const baixo = celulas.get(chave(c.x, c.y + 1));
            if (baixo && c.dirs.includes('y') && baixo.dirs.includes('y')) ligar(k, chave(c.x, c.y + 1));
        });
        // Peças de avenida: nó no centro e um nó em cada ponta; pontas iguais de peças vizinhas são o mesmo nó
        construcao.construidas.forEach((c) => {
            const bracos = BRACOS_AVENIDA[c.desenho];
            if (!bracos || !construcao.lotes.includes(c.lote)) return;
            const [ox, oy] = LOTE_ORIGEM[c.lote];
            const cx = ox + c.x + c.w / 2, cy = oy + c.y + c.d / 2;
            const centro = `av:${chave(cx, cy)}`;
            if (!nos.has(centro)) no(centro, cx, cy, FAIXA.av);
            bracos.forEach((v) => {
                const [dx, dy] = girarVetor(v, c.rotacao || 0);
                const ponta = `av:${chave(cx + dx, cy + dy)}`;
                if (!nos.has(ponta)) no(ponta, cx + dx, cy + dy, FAIXA.av);
                ligar(centro, ponta);
            });
        });
        if (nos.has(`av:${chave(20, 10)}`)) ligar('A20', `av:${chave(20, 10)}`);   // saída da avenida para o lote 2
        if (nos.has(`av:${chave(10, 20)}`)) ligar('B20', `av:${chave(10, 20)}`);   // e para o lote 3
        // Encaixe das avenidas (2 faixas) com as ruas 1×1 dos lotes 2 e 3
        [chave(20, 9), chave(20, 10)].forEach((k) => { if (celulas.get(k)?.dirs.includes('x')) ligar('A20', k); });
        [chave(9, 20), chave(10, 20)].forEach((k) => { if (celulas.get(k)?.dirs.includes('y')) ligar('B20', k); });
    }
    return { nos, viz };
}

/** Calçadas do centro: 4 linhas com cantos nas faixas de pedestres e portas das casas. */
function redeDeCalcadas() {
    const nos = new Map();
    const viz = new Map();
    const no = (id, x, y, borda = false) => { nos.set(id, { id, x, y, borda }); viz.set(id, new Set()); };
    const ligar = (a, b) => { viz.get(a).add(b); viz.get(b).add(a); };
    const [a, b] = [8.85, 11.15];
    // Cantos (ligados entre si pelas faixas de pedestres)
    no('aa', a, a); no('ba', b, a); no('ab', a, b); no('bb', b, b);
    ligar('aa', 'ba'); ligar('ab', 'bb'); ligar('aa', 'ab'); ligar('ba', 'bb');
    // Pontas na borda do tabuleiro
    [['y-a0', 0, a, 'aa'], ['y-a20', 20, a, 'ba'], ['y-b0', 0, b, 'ab'], ['y-b20', 20, b, 'bb'],
     ['x-a0', a, 0, 'aa'], ['x-a20', a, 20, 'ab'], ['x-b0', b, 0, 'ba'], ['x-b20', b, 20, 'bb']]
        .forEach(([id, x, y, canto]) => { no(id, x, y, true); ligar(id, canto); });
    // Portas das casas do bairro (frente em y + 2,2; porta em x + 1,6) ligadas à calçada x = 11,15
    viz.get('x-b0').delete('ba'); viz.get('ba').delete('x-b0');
    let anterior = 'x-b0';
    CASAS.filter(([cx]) => cx < 13).sort((m, n) => m[1] - n[1]).forEach(([cx, cy], i) => {
        const yPorta = cy + 2.3;
        no(`j${i}`, b, yPorta); ligar(anterior, `j${i}`); anterior = `j${i}`;
        no(`porta${i}`, cx + 1.6, yPorta, true); ligar(`j${i}`, `porta${i}`);
    });
    ligar(anterior, 'ba');
    CASAS.filter(([cx]) => cx >= 13).forEach(([cx, cy], i) => {
        const id = `portaB${i}`;
        no(id, cx + 1.6, cy + 2.3, true);
        ligar(id, 'porta0');   // caminha pela frente das casas até a primeira porta
    });
    return { nos, viz };
}

/** Caminho mais curto (BFS) entre dois nós de um grafo. */
function caminhoGrafo(viz, de, para) {
    const veio = new Map([[de, null]]);
    const fila = [de];
    while (fila.length) {
        const atual = fila.shift();
        if (atual === para) break;
        viz.get(atual).forEach((v) => { if (!veio.has(v)) { veio.set(v, atual); fila.push(v); } });
    }
    if (!veio.has(para)) return null;
    const rota = [];
    for (let n = para; n !== null; n = veio.get(n)) rota.unshift(n);
    return rota;
}

/** Grade de cada lote: células pisáveis, portas (frente das construções) e bordas. */
function gradesDosLotes(construcao) {
    if (!construcao) return [];
    return construcao.lotes.map((lote) => {
        const bloqueada = new Set();
        const portas = [];
        const baladas = [];
        const usos = [];
        const [ox, oy] = LOTE_ORIGEM[lote];
        construcao.construidas.filter((c) => c.lote === lote).forEach((c) => {
            if (PISAVEIS.has(c.desenho)) return;
            for (let i = 0; i < c.w; i += 1) for (let j = 0; j < c.d; j += 1) bloqueada.add(chave(c.x + i, c.y + j));
            // Porta na frente, conforme o giro: y + d (0), x + w (90°), y (180°), x (270°)
            const r = c.rotacao || 0;
            let porta = null;
            if (r === 0 && c.y + c.d < 20) porta = [c.x + Math.floor(c.w / 2), c.y + c.d];
            if (r === 1 && c.x + c.w < 20) porta = [c.x + c.w, c.y + Math.floor(c.d / 2)];
            if (r === 2 && c.y > 0) porta = [c.x + Math.floor(c.w / 2), c.y - 1];
            if (r === 3 && c.x > 0) porta = [c.x - 1, c.y + Math.floor(c.d / 2)];
            if (porta) portas.push(porta);
            if (porta && c.desenho === 'balada') baladas.push(porta);
            // Estruturas de usar (banco, quadra, horta…): entra por uma célula livre vizinha
            if (USOS[c.desenho]) {
                const vizinhas = [porta];
                for (let i = 0; i < c.w; i += 1) vizinhas.push([c.x + i, c.y - 1], [c.x + i, c.y + c.d]);
                for (let j = 0; j < c.d; j += 1) vizinhas.push([c.x - 1, c.y + j], [c.x + c.w, c.y + j]);
                usos.push({ acao: USOS[c.desenho], vizinhas: vizinhas.filter(Boolean), area: { x0: ox + c.x, y0: oy + c.y, w: c.w, d: c.d } });
            }
        });
        (construcao.lixo || []).filter((l) => l.lote === lote).forEach((l) => bloqueada.add(chave(l.x, l.y)));
        const livre = (x, y) => x >= 0 && y >= 0 && x < 20 && y < 20 && !bloqueada.has(chave(x, y));
        const bordas = [];
        for (let i = 0; i < 20; i += 1) [[i, 0], [i, 19], [0, i], [19, i]].forEach(([x, y]) => { if (livre(x, y)) bordas.push([x, y]); });
        const usosLivres = usos.map((u) => ({ ...u, entrada: u.vizinhas.find(([x, y]) => livre(x, y)) })).filter((u) => u.entrada);
        return {
            lote, origem: LOTE_ORIGEM[lote], livre, portas: portas.filter(([x, y]) => livre(x, y)), bordas,
            baladas: baladas.filter(([x, y]) => livre(x, y)), usos: usosLivres,
        };
    });
}

function caminhoGrade(g, [x0, y0], [x1, y1]) {
    const veio = new Map([[chave(x0, y0), null]]);
    const fila = [[x0, y0]];
    while (fila.length) {
        const [x, y] = fila.shift();
        if (x === x1 && y === y1) break;
        [[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(([dx, dy]) => {
            const k = chave(x + dx, y + dy);
            if (g.livre(x + dx, y + dy) && !veio.has(k)) { veio.set(k, [x, y]); fila.push([x + dx, y + dy]); }
        });
    }
    if (!veio.has(chave(x1, y1))) return null;
    const rota = [];
    for (let c = [x1, y1]; c; c = veio.get(chave(c[0], c[1]))) rota.unshift(c);
    return rota.map(([x, y]) => ({ x: g.origem[0] + x + 0.5, y: g.origem[1] + y + 0.5 }));
}

/* ---------------------------------------------------------------------------
   Agentes
   --------------------------------------------------------------------------- */

const el = (html) => {
    const g = document.createElementNS(NS, 'g');
    g.innerHTML = html;
    return g;
};

function desenhoVeiculo(tipo, cor) {
    const centro = (fn, w, d, dir, extra = '') => fn(-w / 2, -d / 2, cor, dir) + extra;
    if (tipo === 'bike') return { x: bike(0, 0, cor), y: bike(0, 0, cor) };
    if (tipo.startsWith('onibus')) return { x: centro(bus, 2.6, 0.72, 'x'), y: centro(bus, 0.72, 2.6, 'y') };
    const fumaca = tipo === 'carroFumaca' ? smoke(0, 0, 0.4, '#757575', 2) : '';
    return { x: centro(car, 1.3, 0.62, 'x', fumaca), y: centro(car, 0.62, 1.3, 'y', fumaca) };
}

const estado = {
    camada: null, topo: null, noite: null, luzes: null, pombos: null,
    agentes: [], efemeros: [], ruas: null, calcadas: redeDeCalcadas(), grades: [], baladas: [],
    mobilidade: 1, nivel: 1, estagios: {}, extras: { carros: 0, bicicletas: 0, onibus: 0 }, habitantes: null, comLotes: false,
    chaveRede: '', cidade: 'propria', svg: null, visivel: true, ultimo: 0, surgir: 0, conversar: 0, passaros: 8, notas: 0, quadro: 0, velocidade: 1,
    tempo: 0, relogio: (Date.now() / 1000) % CICLO, escuro: -1,
};
const reciclagem = () => estado.estagios.recycling || 1;
const ehNoite = () => estado.escuro > 0.5;

/** Frota da Mobilidade + veículos das estruturas (RN78): o tipo segue a Mobilidade. */
function frotaAtual() {
    const lista = (FROTA[estado.mobilidade] || []).map(([t, q]) => [t, q]);
    const somar = (tipo, qtd) => {
        if (!qtd) return;
        const l = lista.find(([t]) => t === tipo);
        if (l) l[1] += qtd; else lista.push([tipo, qtd]);
    };
    const { carros, bicicletas, onibus } = estado.extras;
    const m = estado.mobilidade;
    if (m === 1) { somar('carroFumaca', Math.ceil(carros / 2)); somar('carro', Math.floor(carros / 2)); }
    else if (m === 2) somar('carro', carros);
    else somar('carroEletrico', carros);
    somar('bike', bicicletas);
    somar(m >= 3 ? 'onibusVerde' : 'onibusLaranja', onibus);
    return lista;
}

function criarElemento(a, html) {
    a.g = el(`<g class="trafego-x">${html.x}</g><g class="trafego-y" style="display:none">${html.y}</g>`);
    a.g.setAttribute('class', `trafego-agente ${a.classe || ''}`);
    estado.camada?.appendChild(a.g);
}

function novoVeiculo(tipo) {
    const inicio = sortear(['A0', 'B0']);
    const a = {
        especie: 'veiculo', tipo, de: inicio, para: 'C', t: 0, idade: 0,
        vida: entre(VIDA_CARRO), vel: VELOCIDADE[tipo === 'bike' ? 'bike' : tipo.startsWith('onibus') ? 'onibus' : 'carro'],
    };
    const cor = tipo === 'onibusLaranja' ? '#FB8C00' : tipo === 'onibusVerde' ? '#2E7D32' : tipo === 'carroEletrico' ? '#26A69A'
        : tipo === 'bike' ? sortear(CORES_PESSOA) : sortear(CORES_CARRO[estado.mobilidade]);
    criarElemento(a, desenhoVeiculo(tipo, cor));
    return a;
}

/** Nova pessoa: anda pelas calçadas do centro ou por um lote (até uma porta, uma borda ou uma estrutura para usar). */
function novaPessoa() {
    const cor = sortear(CORES_PESSOA);
    let rota = null;
    let uso = null;
    if (estado.grades.length && Math.random() < 0.55) {
        const g = sortear(estado.grades);
        const origem = Math.random() < 0.5 && g.portas.length ? sortear(g.portas) : sortear(g.bordas);
        let destino;
        if (ehNoite() && g.baladas.length && Math.random() < 0.5) destino = sortear(g.baladas);   // à noite, gente indo à Balada
        else if (!ehNoite() && g.usos.length && Math.random() < 0.5) { uso = sortear(g.usos); destino = uso.entrada; }
        else destino = Math.random() < 0.6 && g.portas.length ? sortear(g.portas) : sortear(g.bordas);
        if (origem && destino && chave(...origem) !== chave(...destino)) rota = caminhoGrade(g, origem, destino);
        if (rota && uso) {
            const { x0, y0, w, d } = uso.area;   // ponto dentro da estrutura (banco, quadra, horta…)
            rota.push({ x: x0 + 0.25 + Math.random() * Math.max(0, w - 0.5), y: y0 + 0.25 + Math.random() * Math.max(0, d - 0.5) });
            uso = { ...uso, grade: g };
        } else uso = null;
    } else {
        const { nos, viz } = estado.calcadas;
        const pontas = [...nos.values()].filter((n) => n.borda).map((n) => n.id);
        const de = sortear(pontas);
        const para = sortear(pontas.filter((id) => id !== de));
        rota = caminhoGrafo(viz, de, para)?.map((id) => ({ x: nos.get(id).x, y: nos.get(id).y }));
    }
    if (!rota || rota.length < 2) return null;
    const a = {
        especie: 'pessoa', rota, i: 0, t: 0, idade: 0, vida: VIDA_PESSOA, vel: VELOCIDADE.pessoa * (0.8 + Math.random() * 0.4),
        classe: 'trafego-pessoa', uso, catador: reciclagem() === 4 && Math.random() < CHANCE_CATADOR, procurar: 3 + Math.random() * 6,
        x: rota[0].x, y: rota[0].y, calma: estado.tempo + 5,
    };
    const html = pessoa(cor) + (a.catador ? saco() : '') + (Math.random() < CHANCE_CACHORRO ? cachorro() : '');
    criarElemento(a, { x: html, y: html });
    return a;
}

function remover(a, esmaecer = true) {
    a.morto = true;
    a.glifo?.remove();
    if (!a.g) return;
    if (!esmaecer) { a.g.remove(); return; }
    a.g.classList.add('is-saindo');
    setTimeout(() => a.g.remove(), 500);
}

/** Desenho solto (lixo, balão, nota, pássaros) na posição de tela (sx, sy). */
function glifo(html, sx, sy, camada) {
    const g = el(html);
    g.setAttribute('transform', `translate(${sx.toFixed(1)} ${sy.toFixed(1)})`);
    camada?.appendChild(g);
    return g;
}

function efemero(tipo, html, sx, sy, { vida, vx = 0, vy = 0, noTopo = true }) {
    const e = { tipo, g: glifo(html, sx, sy, noTopo ? estado.topo : estado.camada), sx, sy, vx, vy, idade: 0, vida, noTopo };
    estado.efemeros.push(e);
    return e;
}

/** Para a pessoa por `dur` segundos fazendo `acao` (conversa, sentar, jogar, cata…). */
function pausar(a, dur, acao, extra = '') {
    a.pausa = dur;
    a.acao = acao;
    a.vida = Math.max(a.vida, a.idade + dur + 20);
    a.g.classList.add('is-parada', `faz-${acao}`);
    const [sx, sy] = p(a.x, a.y);
    if (USOS_ACOES.has(acao)) {           // dentro da estrutura: desenhada por cima dela
        estado.topo?.appendChild(a.g);
        a.noTopo = true;
    }
    const html = extra || objetoAcao(acao);
    if (html) a.glifo = glifo(html, sx, sy, estado.topo);
}

function terminarPausa(a) {
    a.g.classList.remove('is-parada', `faz-${a.acao}`);
    a.glifo?.remove();
    a.glifo = null;
    if (a.noTopo) { estado.camada?.appendChild(a.g); a.noTopo = false; }
    if (a.acao === 'conversa') a.calma = estado.tempo + 25;
    if (USOS_ACOES.has(a.acao) && a.uso) {   // terminou de usar a estrutura: sai pela entrada até uma borda
        const g = a.uso.grade;
        const volta = caminhoGrade(g, a.uso.entrada, sortear(g.bordas) || a.uso.entrada);
        a.rota = [{ x: a.x, y: a.y }, ...(volta || [])];
        a.i = 0;
        a.t = 0;
        a.uso = null;
        if (a.rota.length < 2) remover(a);
    }
    a.acao = null;
}

/** Chegou ao fim do caminho: usa a estrutura ou some (borda ou "entrou" na construção). */
function chegou(a) {
    if (a.uso) {
        pausar(a, entre([5, 9]), a.uso.acao);
        return;
    }
    remover(a);
}

function jogarLixo(x, y) {
    if (estado.efemeros.filter((e) => e.tipo === 'lixo').length >= LIXO_MAX) return;
    const [sx, sy] = p(x + (Math.random() - 0.5) * 0.2, y + (Math.random() - 0.5) * 0.2);
    efemero('lixo', itemLixo(), sx, sy, { vida: LIXO_VIDA, noTopo: false });
}

/** Próximo nó do veículo ao chegar em `a.para`: reto se der, senão vira; sem saída, meia-volta. */
function proximoNo(a) {
    const { nos, viz } = estado.ruas;
    const atual = nos.get(a.para);
    const anterior = nos.get(a.de);
    const opcoes = [...viz.get(a.para)].filter((id) => id !== a.de);
    if (!opcoes.length) return a.de;
    const [hx, hy] = [Math.sign(atual.x - anterior.x), Math.sign(atual.y - anterior.y)];
    const reto = opcoes.find((id) => { const n = nos.get(id); return Math.sign(n.x - atual.x) === hx && Math.sign(n.y - atual.y) === hy; });
    return reto && Math.random() < 0.6 ? reto : sortear(opcoes);
}

function moverVeiculo(a, dt) {
    const { nos } = estado.ruas;
    let de = nos.get(a.de);
    let para = nos.get(a.para);
    if (!de || !para || !estado.ruas.viz.get(a.de)?.has(a.para)) { remover(a, false); return; }
    let len = Math.hypot(para.x - de.x, para.y - de.y) || 0.01;
    a.t += (a.vel * dt) / len;
    while (a.t >= 1) {
        if (para.limbo && a.idade > 3) { remover(a); return; }
        const prox = proximoNo(a);
        a.t = (a.t - 1) * len;
        [a.de, a.para] = [a.para, prox];
        de = nos.get(a.de); para = nos.get(a.para);
        len = Math.hypot(para.x - de.x, para.y - de.y) || 0.01;
        a.t /= len;
    }
    const [hx, hy] = [(para.x - de.x) / len, (para.y - de.y) / len];
    const faixa = de.faixa + (para.faixa - de.faixa) * a.t + (a.tipo === 'bike' ? 0.25 : 0);
    const x = de.x + (para.x - de.x) * a.t - hy * faixa;
    const y = de.y + (para.y - de.y) * a.t + hx * faixa;
    posicionar(a, x, y, Math.abs(hx) >= Math.abs(hy) ? 'x' : 'y');
}

function moverPessoa(a, dt) {
    if (a.pausa > 0) {
        a.pausa -= dt;
        if (a.pausa > 0) return;
        terminarPausa(a);
        if (a.morto) return;
    }
    let de = a.rota[a.i];
    let para = a.rota[a.i + 1];
    let len = Math.hypot(para.x - de.x, para.y - de.y) || 0.01;
    a.t += (a.vel * dt) / len;
    while (a.t >= 1) {
        a.i += 1;
        if (a.i >= a.rota.length - 1) {
            [a.x, a.y] = [a.rota[a.rota.length - 1].x, a.rota[a.rota.length - 1].y];
            posicionar(a, a.x, a.y, 'x');
            chegou(a);
            return;
        }
        a.t = (a.t - 1) * len;
        de = a.rota[a.i]; para = a.rota[a.i + 1];
        len = Math.hypot(para.x - de.x, para.y - de.y) || 0.01;
        a.t /= len;
    }
    a.x = de.x + (para.x - de.x) * a.t;
    a.y = de.y + (para.y - de.y) * a.t;
    posicionar(a, a.x, a.y, 'x');
    // Reciclagem baixa: joga lixo no chão; no estágio 4, quem tem saquinho para e cata
    const chance = CHANCE_LIXO[reciclagem() - 1] || 0;
    if (chance && Math.random() < chance * dt) jogarLixo(a.x, a.y);
    if (a.catador) {
        a.procurar -= dt;
        if (a.procurar <= 0) { a.procurar = 6 + Math.random() * 6; pausar(a, 1.6, 'cata'); }
    }
}

function posicionar(a, x, y, dir) {
    const [sx, sy] = p(x, y);
    a.g.setAttribute('transform', `translate(${(sx - OX).toFixed(1)} ${(sy - OY).toFixed(1)})`);
    if (a.dir !== dir) {
        a.dir = dir;
        a.g.firstChild.style.display = dir === 'x' ? '' : 'none';
        a.g.lastChild.style.display = dir === 'y' ? '' : 'none';
    }
}

/** Duas pessoas perto uma da outra param para conversar, com um balão de ícone. */
function conversas() {
    const livres = estado.agentes.filter((a) => a.especie === 'pessoa' && !a.morto && !(a.pausa > 0) && !a.uso && a.calma <= estado.tempo);
    const usadas = new Set();
    for (let i = 0; i < livres.length; i += 1) {
        const a = livres[i];
        if (usadas.has(a)) continue;
        const b = livres.find((o, j) => j > i && !usadas.has(o) && Math.hypot(o.x - a.x, o.y - a.y) < 0.8);
        if (!b || Math.random() > 0.35) continue;
        usadas.add(a).add(b);
        const dur = entre([3, 5]);
        const icones = estado.nivel >= 3 ? BALAO_BOM : BALAO_RUIM;   // cidade boa (média ≥ 50) ou ruim
        pausar(a, dur, 'conversa', balao(sortear(icones)));
        pausar(b, dur, 'conversa');
    }
}

/** Pássaros sobre o parque (Natureza ≥ 3, de dia) e notas da Balada (à noite). */
function animais(dt) {
    if ((estado.estagios.nature || 1) >= 3 && !ehNoite()) {
        estado.passaros -= dt;
        if (estado.passaros <= 0) {
            estado.passaros = entre([20, 35]);
            const [x0, y0] = p(10, 19, 9);
            const [x1] = p(20, 9, 9);
            efemero('passaros', `<g class="vida-bando">${passaros()}</g>`, x0 - 40, y0 + (Math.random() - 0.5) * 40, { vida: 14, vx: (x1 - x0 + 80) / 14, vy: -2 });
        }
    }
    if (ehNoite() && estado.baladas.length) {
        estado.notas -= dt;
        if (estado.notas <= 0) {
            estado.notas = 1.2;
            estado.baladas.slice(0, 3).forEach(([x, y]) => {
                const [sx, sy] = p(x, y, 3.6);
                efemero('nota', nota(), sx + (Math.random() - 0.5) * 30, sy, { vida: 2.5, vx: (Math.random() - 0.5) * 6, vy: -14 });
            });
        }
    }
}

function moverEfemeros(dt) {
    estado.efemeros.forEach((e) => {
        e.idade += dt;
        if (e.idade > e.vida) { e.g.remove(); e.morto = true; return; }
        if (e.vx || e.vy) {
            e.sx += e.vx * dt;
            e.sy += e.vy * dt;
            e.g.setAttribute('transform', `translate(${e.sx.toFixed(1)} ${e.sy.toFixed(1)})`);
        }
        const resta = e.vida - e.idade;
        if (resta < 1.5) e.g.setAttribute('opacity', Math.max(0, resta / 1.5).toFixed(2));
    });
    estado.efemeros = estado.efemeros.filter((e) => !e.morto);
}

/** Dia e noite: camada escura, luzes e menos gente na rua. Só no mapa do jogo (com lotes). */
function atualizarNoite(dt) {
    estado.relogio += dt;
    // Modo Construir: sempre meio-dia, com o relógio escondido
    const construindo = !!estado.camada?.closest('svg')?.classList.contains('is-building');
    const ciclo = estado.comLotes && !construindo;
    const hora = ciclo ? horaDoJogo(estado.relogio) : 12;
    atualizarCeu(hora);
    atualizarRelogioTela(ciclo ? hora : null);

    const esc = ciclo ? escuridao(estado.relogio) : 0;
    if (Math.abs(esc - estado.escuro) < 0.004) return;
    estado.escuro = esc;
    estado.noite?.setAttribute('opacity', (esc * NOITE_OPACIDADE).toFixed(3));
    estado.luzes?.setAttribute('opacity', Math.min(1, esc * 1.4).toFixed(3));
    const { noite, estrelas, nuvens, janelas, lua } = estado.ceu || {};
    noite?.setAttribute('opacity', esc.toFixed(3));
    lua?.setAttribute('opacity', (0.2 + esc * 0.8).toFixed(3));   // de dia, só uma lua bem clarinha
    estrelas?.setAttribute('opacity', Math.max(0, esc * 1.3 - 0.3).toFixed(3));
    nuvens?.setAttribute('opacity', (1 - esc * 0.8).toFixed(3));
    // Janelas dos prédios do fundo: o grupo k acende quando a escuridão passa de k × 0,2
    janelas?.forEach((g, k) => g.setAttribute('opacity', Math.min(1, Math.max(0, (esc - k * 0.2) / 0.2)).toFixed(3)));
    estado.camada?.closest('svg')?.classList.toggle('is-noite', esc > 0.5);
}

/**
 * Céu em sentido horário: o sol e a lua (opostos) andam numa elipse e nascem/se põem nas laterais;
 * as estrelas giram juntas. Também ajusta o brilho do pôr do sol.
 */
function atualizarCeu(hora) {
    const { ceu, roda, sol, lua, crepusculo } = estado.ceu || {};
    if (!ceu || !roda) return;
    const angulo = anguloCeu(hora);
    if (estado.angulo !== null && Math.abs(angulo - estado.angulo) < 0.1) return;
    estado.angulo = angulo;
    const [cx, cy, rx, ry] = ['cx', 'cy', 'rx', 'ry'].map((k) => Number(ceu.dataset[k]));
    const em = (graus) => {
        const r = (graus * Math.PI) / 180;
        return `translate(${(cx + rx * Math.sin(r)).toFixed(1)} ${(cy - ry * Math.cos(r)).toFixed(1)})`;
    };
    sol?.setAttribute('transform', em(angulo));
    lua?.setAttribute('transform', em(angulo + 180));
    roda.setAttribute('transform', `rotate(${angulo.toFixed(2)} ${cx} ${cy})`);
    crepusculo?.setAttribute('opacity', brilhoCrepusculo(hora).toFixed(3));
}

/** Pílula com a hora da cidade, em passos de 10 minutos. */
function atualizarRelogioTela(hora) {
    const el = estado.relogioTela;
    if (!el) return;
    if (hora === null) { el.hidden = true; estado.horaTela = ''; return; }
    const minutos = Math.floor((hora * 60) / 10) * 10;
    const texto = `${String(Math.floor(minutos / 60) % 24).padStart(2, '0')}:${String(minutos % 60).padStart(2, '0')}`;
    if (texto === estado.horaTela && !el.hidden) return;
    estado.horaTela = texto;
    el.hidden = false;
    const dia = hora >= 6 && hora < 18;
    el.innerHTML = `${icon(dia ? 'sun' : 'moon')}<span>${texto}</span>`;
    el.classList.toggle('is-noite', !dia);
    el.setAttribute('aria-label', `Hora na cidade: ${texto}`);
    el.title = `Hora na cidade (${dia ? 'dia' : 'noite'})`;
}

/** Cria o que falta da frota e das pessoas (habitantes; menos à noite). */
function repor() {
    const vivos = estado.agentes.filter((a) => !a.morto);
    const falta = frotaAtual().find(([tipo, qtd]) => vivos.filter((a) => a.tipo === tipo).length < qtd);
    if (falta) estado.agentes.push(novoVeiculo(falta[0]));
    const habitantes = estado.habitantes ?? PESSOAS_POR_NIVEL[estado.nivel - 1];
    const alvo = Math.round(habitantes * (ehNoite() ? FATOR_NOITE : 1));
    let pessoas = vivos.filter((a) => a.especie === 'pessoa').length;
    for (let k = 0; k < 3 && pessoas < alvo; k += 1) {
        const pes = novaPessoa();
        if (pes) { estado.agentes.push(pes); pessoas += 1; }
    }
}

function quadro(agora) {
    estado.quadro = requestAnimationFrame(quadro);
    const dt = Math.min(0.1, (agora - (estado.ultimo || agora)) / 1000) * estado.velocidade;
    estado.ultimo = agora;
    if (!estado.visivel || document.hidden || !estado.camada?.isConnected) return;

    estado.tempo += dt;
    atualizarNoite(dt);
    // Ambiente sonoro (RN96): a cada ~1 s com o mapa do jogo ou da visita visível, fora do modo Construir
    estado.somEm = (estado.somEm || 0) - dt;
    if (estado.somEm <= 0 && estado.comLotes) {
        estado.somEm = 1;
        if (!estado.camada?.closest('svg')?.classList.contains('is-building')) {
            somAmbiente({ noite: estado.escuro > 0.5, natureza: estado.estagios.nature || 1, mobilidade: estado.estagios.mobility || 1 });
        }
    }
    estado.surgir -= dt;
    if (estado.surgir <= 0) { repor(); estado.surgir = INTERVALO_SURGIR; }
    estado.conversar -= dt;
    if (estado.conversar <= 0) { conversas(); estado.conversar = 0.5; }
    animais(dt);

    estado.agentes.forEach((a) => {
        if (a.morto) return;
        a.idade += dt;
        if (a.idade > a.vida) { remover(a); return; }
        if (a.especie === 'veiculo') moverVeiculo(a, dt); else moverPessoa(a, dt);
    });
    estado.agentes = estado.agentes.filter((a) => !a.morto);
    moverEfemeros(dt);
}

/** Pombos bicando perto das pilhas de lixo dos lotes (Reciclagem ≤ 2). */
function desenharPombos(construcao) {
    estado.pombos?.remove();
    estado.pombos = null;
    if (reciclagem() > 2 || !construcao?.lixo?.length || !estado.camada) return;
    let html = '';
    construcao.lixo.filter((l) => construcao.lotes.includes(l.lote)).slice(0, 6).forEach((l, i) => {
        const [ox, oy] = LOTE_ORIGEM[l.lote];
        [[0.9, 0.2], [0.3, 0.95]].slice(0, i % 2 ? 1 : 2).forEach(([dx, dy]) => {
            const [sx, sy] = p(ox + l.x + dx, oy + l.y + dy);
            html += `<g transform="translate(${sx.toFixed(1)} ${sy.toFixed(1)})">${pombo()}</g>`;
        });
    });
    estado.pombos = el(html);
    estado.pombos.setAttribute('class', 'vida-pombos');
    estado.camada.appendChild(estado.pombos);
}

let observador = null;

/**
 * Liga o tráfego ao SVG recém-desenhado (chamar após cada render do mapa).
 * @param {SVGSVGElement} svg
 * @param {{ estagios: Record<string, number>, nivelCidade: number, construcao?: object | null }} dados
 * @param {{ cidade?: string, relogio?: HTMLElement | null }} [opcoes] cidade: 'propria' ou 'visita:ID' (o motor
 *        atende uma cidade por vez; ao trocar, as pessoas, os veículos e o lixo da outra somem na hora);
 *        relogio: pílula da hora (padrão: #city-relogio do mapa próprio)
 */
export function anexarTrafego(svg, { estagios, nivelCidade, construcao = null }, { cidade = 'propria', relogio } = {}) {
    if (!svg || movimentoReduzido()) return;
    const camada = svg.querySelector('.city-traffic');
    if (!camada) return;
    if (cidade !== estado.cidade) {
        estado.agentes.forEach((a) => remover(a, false));
        estado.agentes = [];
        estado.efemeros.forEach((e) => e.g?.remove());
        estado.efemeros = [];
        estado.chaveRede = '';
        estado.cidade = cidade;
    }
    estado.svg = svg;

    const mobilidade = estagios.mobility || 1;
    const chaveRede = construcao
        ? JSON.stringify([construcao.lotes, construcao.construidas.map((c) => [c.id, c.desenho, c.lote, c.x, c.y, c.rotacao]), (construcao.lixo || []).length])
        : 'centro';
    if (chaveRede !== estado.chaveRede) {
        estado.ruas = redeDeRuas(construcao);
        estado.grades = gradesDosLotes(construcao);
        estado.agentes.filter((a) => a.especie === 'pessoa' && a.rota.some((pt) => pt.x >= 20 || pt.y >= 20)).forEach((a) => remover(a, false));
        estado.chaveRede = chaveRede;
    }
    estado.baladas = construcao
        ? construcao.construidas.filter((c) => c.desenho === 'balada' && construcao.lotes.includes(c.lote))
            .map((c) => [LOTE_ORIGEM[c.lote][0] + c.x + c.w / 2, LOTE_ORIGEM[c.lote][1] + c.y + c.d / 2])
        : [];
    estado.estagios = estagios;
    estado.nivel = nivelCidade;
    estado.comLotes = !!construcao;
    estado.habitantes = construcao?.efeitos?.habitantes ?? null;
    estado.extras = {
        carros: construcao?.efeitos?.carros || 0, bicicletas: construcao?.efeitos?.bicicletas || 0, onibus: construcao?.efeitos?.onibus || 0,
    };
    // Mudou a Mobilidade ou a frota: veículos que não fazem parte dela saem aos poucos
    estado.mobilidade = mobilidade;
    const tipos = new Set(frotaAtual().map(([t]) => t));
    estado.agentes.filter((a) => a.especie === 'veiculo' && !tipos.has(a.tipo)).forEach((a) => remover(a));

    estado.camada = camada;
    estado.topo = svg.querySelector('.city-vida-topo');
    estado.noite = svg.querySelector('.city-noite');
    estado.luzes = svg.querySelector('.city-luzes');
    estado.ceu = {
        ceu: svg.querySelector('.city-ceu'), roda: svg.querySelector('.city-ceu-roda'),
        sol: svg.querySelector('.city-sol'), lua: svg.querySelector('.city-lua'), noite: svg.querySelector('.city-ceu-noite'),
        crepusculo: svg.querySelector('.city-crepusculo'), estrelas: svg.querySelector('.city-estrelas'),
        nuvens: svg.querySelector('.city-nuvens'), janelas: [...svg.querySelectorAll('.city-janelas')],
    };
    estado.relogioTela = relogio !== undefined ? relogio : (construcao ? document.getElementById('city-relogio') : null);
    estado.escuro = -1;   // reaplica a noite no SVG novo
    estado.angulo = null;
    estado.horaTela = '';
    estado.agentes.forEach((a) => {
        if (a.morto || !a.g) return;
        (a.noTopo ? estado.topo : camada)?.appendChild(a.g);
        if (a.glifo) estado.topo?.appendChild(a.glifo);
    });
    estado.efemeros.forEach((e) => (e.noTopo ? estado.topo : camada)?.appendChild(e.g));
    desenharPombos(construcao);

    observador?.disconnect();
    observador = new IntersectionObserver(([e]) => { estado.visivel = e.isIntersecting; });
    observador.observe(svg);
    if (!estado.quadro) estado.quadro = requestAnimationFrame(quadro);
}

/** Cidade e SVG que o motor está animando agora. */
export const trafegoAtual = () => ({ cidade: estado.cidade, svg: estado.svg });

/** Só para testes no console: acelera o relógio do tráfego (1 = normal). */
export function velocidadeTrafego(fator) {
    estado.velocidade = fator;
    return estado.agentes.length;
}

/** Só para testes: muda a hora do ciclo de dia e noite (segundos: 0 = manhã, 380 = noite). */
export function relogioVida(segundos) {
    estado.relogio = segundos;
    return escuridao(segundos);
}

/** Só para testes: resumo dos agentes vivos. */
export function resumoTrafego() {
    return estado.agentes.filter((a) => !a.morto).map((a) => ({
        especie: a.especie, tipo: a.tipo, de: a.de, para: a.para, idade: Math.round(a.idade), acao: a.acao || null, uso: a.uso?.acao || null, catador: !!a.catador,
    }));
}

/** Só para testes: contagem dos desenhos soltos (lixo, balões, notas, pássaros). */
export function resumoVida() {
    const conta = {};
    estado.efemeros.forEach((e) => { conta[e.tipo] = (conta[e.tipo] || 0) + 1; });
    return { ...conta, escuro: Number(estado.escuro.toFixed(2)), pombos: estado.pombos?.childElementCount || 0, habitantes: estado.habitantes, frota: frotaAtual() };
}
