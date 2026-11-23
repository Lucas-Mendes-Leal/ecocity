/**
 * Sons do jogo (RN96): efeitos curtos e o ambiente da cidade, pela Web Audio API (já vem no
 * navegador; nenhuma biblioteca). Arquivos CC0 em assets/sons/ (origem em assets/sons/CREDITOS.md).
 *
 *  - Nada toca antes do primeiro clique ou toque (regra de autoplay dos navegadores) nem com a aba
 *    escondida. O mesmo som não repete em menos de 120 ms; no máximo 6 ao mesmo tempo.
 *  - Canais: efeitos (padrão 100%), interface (cliques, padrão 30%) e ambiente (padrão 60%), cada um com volume
 *    próprio (0–100%) sob o volume mestre.
 *  - Preferências vêm da conta (perfil.conta.som); a área do parceiro guarda as suas no navegador.
 *  - O ambiente é alimentado pela cidade viva (traffic.js) a cada ~1 s enquanto o mapa está visível;
 *    sem notícia por 1,5 s (outra aba, página escondida, modo Construir), ele some suavemente.
 *  - Com ?demo=1, os sons pedidos ficam em window.__sonsTocados (para os testes).
 */

const BASE = 'assets/sons/';
const FORMATO = typeof Audio !== 'undefined' && new Audio().canPlayType('audio/mpeg') ? 'mp3' : 'ogg';

/** nome → [canal, ganho, varia o tom] */
const SONS = {
    xp: ['efeitos', 0.7, true], credito: ['efeitos', 0.7, true], renda: ['efeitos', 0.8, false], gasto: ['efeitos', 0.6, true],
    nivel: ['efeitos', 0.9, false], conquista: ['efeitos', 0.9, false], missao: ['efeitos', 0.8, false], desbloqueio: ['efeitos', 0.85, false],
    construir: ['efeitos', 0.8, true], girar: ['efeitos', 0.6, true], remover: ['efeitos', 0.7, false],
    acerto: ['efeitos', 0.7, false], erro: ['efeitos', 0.6, false], 'quiz-fim': ['efeitos', 0.8, false], 'quiz-treino': ['efeitos', 0.6, false],
    registro: ['efeitos', 0.7, false], 'ia-aprovou': ['efeitos', 0.8, false], 'ia-rejeitou': ['efeitos', 0.6, false],
    mensagem: ['efeitos', 0.6, false], pedido: ['efeitos', 0.6, false], ding: ['efeitos', 0.9, false],
    clique: ['interface', 1, true], abrir: ['interface', 1, false], fechar: ['interface', 1, false],
};
const AMBIENTES = ['passaros', 'grilos', 'transito'];
const INTERVALO_MIN = 120;
const MAX_SIMULTANEOS = 6;
const SILENCIO_AMBIENTE_MS = 1500;

export const VOLUME_CANAL_PADRAO = {
    volumeEfeitos: 100,
    volumeInterface: 30,
    volumeAmbiente: 60,
};

const reduzir = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
const prefs = {
    mudo: false, efeitos: true, interface: true, ambiente: false,
    volume: null, volumeEfeitos: null, volumeInterface: null, volumeAmbiente: null,
};
const st = { ctx: null, ganho: {}, buffers: {}, carregando: null, ultimo: {}, ativos: 0, amb: null, ambUltimo: 0, ambTimer: null };
const debug = new URLSearchParams(location.search).has('demo');
if (debug) window.__sonsTocados = [];

export const volumePadrao = () => (reduzir ? 40 : 60);
const volume = () => (prefs.volume ?? volumePadrao()) / 100;
const volumeCanal = (chave) => (prefs[chave] ?? VOLUME_CANAL_PADRAO[chave]) / 100;

function aplicarGanhos() {
    if (!st.ctx) return;
    const t = st.ctx.currentTime;
    st.ganho.mestre.gain.setTargetAtTime(prefs.mudo ? 0 : volume(), t, 0.05);
    st.ganho.efeitos.gain.setTargetAtTime(prefs.efeitos ? volumeCanal('volumeEfeitos') : 0, t, 0.05);
    st.ganho.interface.gain.setTargetAtTime(prefs.interface ? volumeCanal('volumeInterface') : 0, t, 0.05);
    st.ganho.ambiente.gain.setTargetAtTime(prefs.ambiente ? volumeCanal('volumeAmbiente') : 0, t, 0.3);
}

/** Cria o contexto de áudio no primeiro gesto e carrega os efeitos (pequenos, ~200 KB). */
function liberar() {
    if (st.ctx) { if (st.ctx.state === 'suspended') st.ctx.resume(); return; }
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    st.ctx = new Ctx();
    st.ganho.mestre = st.ctx.createGain();
    st.ganho.mestre.connect(st.ctx.destination);
    ['efeitos', 'interface', 'ambiente'].forEach((c) => { st.ganho[c] = st.ctx.createGain(); st.ganho[c].connect(st.ganho.mestre); });
    aplicarGanhos();
    st.carregando = Promise.all(Object.keys(SONS).map((n) => carregar(`efeitos/${n}`, n)));
}

async function carregar(caminho, chave) {
    if (st.buffers[chave]) return st.buffers[chave];
    try {
        const r = await fetch(`${BASE}${caminho}.${FORMATO}`);
        if (!r.ok) return null;
        const buf = await st.ctx.decodeAudioData(await r.arrayBuffer());
        st.buffers[chave] = buf;
        return buf;
    } catch { return null; }   // sem o arquivo ou formato não suportado: segue sem som
}

/** Preferências (da conta ou do navegador). Também prepara a liberação no primeiro gesto. */
export function aplicarPreferencias(p = {}) {
    Object.assign(prefs, p);
    aplicarGanhos();
    if (!prefs.ambiente) pararAmbiente();
}

export function iniciarSom(p = {}) {
    aplicarPreferencias(p);
    const gesto = () => liberar();
    ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => document.addEventListener(ev, gesto, { capture: true, passive: true }));
    document.addEventListener('visibilitychange', () => { if (document.hidden) pararAmbiente(); });
}

/**
 * Sons pedidos no mesmo instante (até 40 ms) viram um lote: os "grandes" calam os pequenos que vêm
 * junto (ex.: subir de nível já inclui o XP; construir já inclui o gasto; coletar renda, a moeda).
 */
const CALA = {
    nivel: ['xp', 'credito', 'gasto', 'quiz-fim', 'quiz-treino', 'registro', 'missao'],
    conquista: ['xp', 'credito', 'gasto', 'nivel', 'missao', 'desbloqueio', 'quiz-fim', 'quiz-treino', 'registro', 'ia-aprovou', 'construir'],
    missao: ['xp', 'credito'], desbloqueio: ['xp', 'credito', 'gasto'], renda: ['credito'], construir: ['gasto'],
    'quiz-fim': ['xp', 'credito'], 'quiz-treino': ['xp', 'credito'], registro: ['xp', 'credito'],
    'ia-aprovou': ['xp', 'credito', 'registro'], 'ia-rejeitou': ['registro'],
};
let lote = null;

/** Toca um efeito pelo nome (ver SONS). Ignora com a aba escondida, sem o primeiro gesto ou desligado. */
export function tocar(nome) {
    if (!SONS[nome]) return;
    if (!lote) {
        lote = new Set();
        setTimeout(() => {
            const pedidos = lote;
            lote = null;
            const calados = new Set([...pedidos].flatMap((n) => CALA[n] || []));
            pedidos.forEach((n) => { if (!calados.has(n)) tocarAgora(n); });
        }, 40);
    }
    lote.add(nome);
}

function tocarAgora(nome) {
    const def = SONS[nome];
    if (!def || document.hidden || prefs.mudo || !prefs[def[0]]) return;
    const agora = performance.now();
    if (agora - (st.ultimo[nome] || 0) < INTERVALO_MIN) return;
    st.ultimo[nome] = agora;
    if (debug) window.__sonsTocados.push(nome);
    const buf = st.buffers[nome];
    if (!st.ctx || !buf || st.ativos >= MAX_SIMULTANEOS) return;
    const fonte = st.ctx.createBufferSource();
    fonte.buffer = buf;
    if (def[2]) fonte.playbackRate.value = 0.95 + Math.random() * 0.1;   // variação leve para não cansar
    const g = st.ctx.createGain();
    g.gain.value = def[1];
    fonte.connect(g).connect(st.ganho[def[0]]);
    st.ativos++;
    fonte.onended = () => { st.ativos--; };
    fonte.start();
}

/* ---------------------------------------------------------------------------
   Ambiente da cidade
   --------------------------------------------------------------------------- */

/**
 * Chamado pela cidade viva enquanto o mapa está visível.
 * @param {{ noite: boolean, natureza: number, mobilidade: number }} e estágios 1–4
 */
export function ambiente({ noite, natureza = 1, mobilidade = 1 }) {
    if (!prefs.ambiente || prefs.mudo || !st.ctx || document.hidden) return;
    st.ambUltimo = performance.now();
    if (!st.amb) iniciarAmbiente();
    const alvo = {
        passaros: noite ? 0 : 0.25 + 0.5 * (natureza - 1) / 3,
        grilos: noite ? 0.6 : 0,
        transito: 0.15 + 0.35 * (4 - mobilidade) / 3,   // cidade poluída: mais carros
    };
    if (debug) window.__ambiente = alvo;
    const t = st.ctx.currentTime;
    AMBIENTES.forEach((n) => st.amb.ganho[n]?.gain.setTargetAtTime(alvo[n], t, 1));   // transição suave (~3 s)
    if (!st.ambTimer) {
        st.ambTimer = setInterval(() => {
            if (performance.now() - st.ambUltimo > SILENCIO_AMBIENTE_MS) pararAmbiente();
        }, 500);
    }
}

function iniciarAmbiente() {
    st.amb = { fontes: {}, ganho: {} };
    const amb = st.amb;
    AMBIENTES.forEach(async (n) => {
        const g = st.ctx.createGain();
        g.gain.value = 0;
        g.connect(st.ganho.ambiente);
        amb.ganho[n] = g;
        const buf = await carregar(`ambiente/${n}`, `amb-${n}`);   // só baixa quando o ambiente é usado
        if (!buf || st.amb !== amb) return;
        const f = st.ctx.createBufferSource();
        f.buffer = buf;
        f.loop = true;
        f.connect(g);
        f.start(0, Math.random() * buf.duration);   // começa num ponto qualquer do loop
        amb.fontes[n] = f;
    });
}

function pararAmbiente() {
    clearInterval(st.ambTimer);
    st.ambTimer = null;
    if (!st.amb || !st.ctx) { st.amb = null; return; }
    const { fontes, ganho } = st.amb;
    st.amb = null;
    const t = st.ctx.currentTime;
    Object.values(ganho).forEach((g) => g.gain.setTargetAtTime(0, t, 0.4));
    setTimeout(() => Object.values(fontes).forEach((f) => { try { f.stop(); } catch { /* já parado */ } }), 2000);
    if (debug) window.__ambiente = null;
}

/** Para os testes e o botão "Testar som". */
export const somLiberado = () => !!st.ctx && st.ctx.state === 'running';
if (debug) window.__som = {
    liberado: somLiberado, prefs, buffers: () => Object.keys(st.buffers),
    ganho: () => st.ganho.mestre?.gain.value ?? null,
    ganhos: () => ({
        mestre: st.ganho.mestre?.gain.value ?? null,
        efeitos: st.ganho.efeitos?.gain.value ?? null,
        interface: st.ganho.interface?.gain.value ?? null,
        ambiente: st.ganho.ambiente?.gain.value ?? null,
    }),
};
export const efeitosCarregados = () => st.carregando ?? Promise.resolve();
