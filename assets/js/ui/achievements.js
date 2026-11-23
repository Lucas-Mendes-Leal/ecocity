/**
 * Aba Conquistas: sala de troféus (resumo e raridades), "Quase lá", medalhas por grupo com filtros,
 * janela de detalhes e comemoração ao desbloquear. Concessão e recompensas são decididas no servidor
 * (includes/conquistas.php); a raridade é só apresentação, pelas faixas de recompensa aprovadas.
 */
import { state } from '../state.js';
import { icon } from './icons.js';
import { fichaXp, fichaCredito } from './weekly-missions.js';
import { $, escapeHTML } from './dom.js';

/** Chave de categoria (cor) por indicador — mesma ordem de INDICADOR_CHAVE no PHP. */
const CATEGORIA_POR_INDICADOR = { 1: 'recycling', 2: 'water', 3: 'energy', 4: 'mobility', 5: 'nature' };
const ICONE_CATEGORIA = { recycling: 'recycle', water: 'droplet', energy: 'bolt', mobility: 'bike', nature: 'tree' };

/** Faixas de recompensa aprovadas (Fácil 30 · Média 100 · Difícil 250 · Épica 500 XP) → raridade visual. */
const RARIDADES = [
    { id: 'bronze', nome: 'Bronze', faixa: 'Fácil', ate: 30 },
    { id: 'prata', nome: 'Prata', faixa: 'Média', ate: 100 },
    { id: 'ouro', nome: 'Ouro', faixa: 'Difícil', ate: 250 },
    { id: 'esmeralda', nome: 'Esmeralda', faixa: 'Épica', ate: Infinity },
];
const raridade = (c) => RARIDADES.find((r) => c.recompensaXp <= r.ate);

const GRUPOS = [
    { id: 'acoes', nome: 'Ações sustentáveis', icone: 'leaf', tipos: ['atividades_aprovadas', 'atividades_categoria', 'registros_evidencia', 'verificacoes_parceiro', 'missoes_especiais'] },
    { id: 'impacto', nome: 'Impacto real', icone: 'droplet', tipos: ['impacto_real', 'impacto_atividade'] },
    { id: 'quiz', nome: 'Quizzes', icone: 'help', tipos: ['quizzes_concluidos', 'quizzes_perfeitos'] },
    { id: 'cidade', nome: 'Cidade e construção', icone: 'home', tipos: ['indicador', 'nivel_cidade', 'construcoes', 'construcoes_ecologicas'] },
    { id: 'jogador', nome: 'Jogador', icone: 'user', tipos: ['nivel_jogador'] },
    { id: 'outras', nome: 'Outras', icone: 'trophy', tipos: [] },
];
const grupoDe = (c) => GRUPOS.find((g) => g.tipos.includes(c.criterio.split(':')[0])) ?? GRUPOS.at(-1);

const FILTROS = [['todas', 'Todas'], ['obtidas', 'Obtidas'], ['andamento', 'Em andamento']];
let filtro = 'todas';
const fila = [];          // conquistas a comemorar (uma janela por vez)
let focoAnterior = null;

/** Ícone e cor de apresentação de cada critério. */
function visual(criterio) {
    const [tipo, ind] = criterio.split(':');
    if (tipo === 'impacto_atividade') {
        const ativ = state.atividades?.find((a) => a.id === Number(ind));
        const catAtiv = ativ?.categoria || 'nature';
        return { icone: ICONE_CATEGORIA[catAtiv] || 'leaf', cor: catAtiv };
    }
    const cat = CATEGORIA_POR_INDICADOR[ind];
    if (cat) return { icone: ICONE_CATEGORIA[cat], cor: cat };
    return {
        atividades_aprovadas: { icone: 'check', cor: 'nature' },
        registros_evidencia: { icone: 'image', cor: 'mobility' },
        verificacoes_parceiro: { icone: 'flag', cor: 'recycling' },
        missoes_especiais: { icone: 'calendar', cor: 'energy' },
        quizzes_concluidos: { icone: 'help', cor: 'water' },
        quizzes_perfeitos: { icone: 'star', cor: 'energy' },
        nivel_cidade: { icone: 'pin', cor: 'nature' },
        nivel_jogador: { icone: 'trophy', cor: 'energy' },
        construcoes: { icone: 'home', cor: 'recycling' },
        construcoes_ecologicas: { icone: 'leaf', cor: 'nature' },
    }[tipo] || { icone: 'trophy', cor: 'nature' };
}

const numero = (n) => n.toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const dataBR = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('pt-BR');
const pctDe = (c) => (c.obtida ? 100 : Math.min(100, Math.round((c.progresso / c.meta) * 100)));
/** "falta 1 · 2/3 quizzes" (a unidade vem do servidor, sempre no plural, por isso fica junto do total). */
const falta = (c) => `falta${c.meta - c.progresso === 1 ? '' : 'm'} ${numero(c.meta - c.progresso)} · ${numero(c.progresso)}/${numero(c.meta)} ${escapeHTML(c.unidade)}`;

/** Medalha: aro na cor da raridade (ou anel de progresso, se bloqueada) e ícone do critério. */
export function medalha(c, tamanho = '') {
    const v = visual(c.criterio);
    const r = raridade(c);
    return `<span class="medal ${tamanho} rarity-${r.id} cat-top-${v.cor} ${c.obtida ? 'is-earned' : 'is-locked'}" style="--pct:${pctDe(c)}" aria-hidden="true">
        <span class="medal-face">${icon(v.icone)}</span>
    </span>`;
}

/* ---------------------------------------------------------------------------
   Sala de troféus (faixa)
   --------------------------------------------------------------------------- */

function renderVitrine(lista) {
    const obtidas = lista.filter((c) => c.obtida);
    const pct = lista.length ? Math.round((obtidas.length / lista.length) * 100) : 0;
    $('tr-ring').style.setProperty('--pct', pct);
    $('tr-ring').setAttribute('aria-label', `${pct}% das conquistas obtidas`);
    $('tr-pct').textContent = `${pct}%`;
    $('tr-count').textContent = `${obtidas.length} de ${lista.length} conquistas`;
    const xp = obtidas.reduce((s, c) => s + c.recompensaXp, 0);
    const cr = obtidas.reduce((s, c) => s + c.recompensaCredito, 0);
    $('tr-earned').textContent = obtidas.length ? `+${numero(xp)} XP e +${numero(cr)} créditos ganhos com conquistas` : 'Sua primeira medalha está a uma ação de distância!';
    const ultimas = ultimasConquistas(lista);
    $('tr-last').innerHTML = ultimas.length
        ? `<span>Últimas:</span>${ultimas.map((c) => `<button type="button" class="trophy-mini" data-conquista="${c.id}" title="${escapeHTML(c.nome)}">${medalha(c, 'medal-xs')}<span>${escapeHTML(c.nome)}</span></button>`).join('')}`
        : '';
    $('tr-rarities').innerHTML = RARIDADES.map((r) => {
        const todas = lista.filter((c) => raridade(c) === r);
        const tem = todas.filter((c) => c.obtida).length;
        return `<div class="rarity-stat"><dt><span class="rarity-dot rarity-${r.id}"></span>${r.nome}</dt><dd>${tem}/${todas.length}</dd></div>`;
    }).join('');
}

/* ---------------------------------------------------------------------------
   Quase lá
   --------------------------------------------------------------------------- */

function renderQuaseLa(lista) {
    const perto = lista.filter((c) => !c.obtida && c.progresso > 0).sort((a, b) => pctDe(b) - pctDe(a)).slice(0, 3);
    $('ach-almost-wrap').classList.toggle('hidden', !perto.length);
    $('ach-almost').innerHTML = perto.map((c) => {
        const v = visual(c.criterio);
        return `<button type="button" class="almost-card" data-conquista="${c.id}">
            ${medalha(c, 'medal-sm')}
            <span class="almost-info">
                <strong>${escapeHTML(c.nome)}</strong>
                <span class="bar-track"><span class="bar-fill fill-${v.cor}" style="width:${pctDe(c)}%"></span></span>
                <small>${pctDe(c)}% · ${falta(c)}</small>
            </span>
        </button>`;
    }).join('');
}

/* ---------------------------------------------------------------------------
   Grupos e filtros
   --------------------------------------------------------------------------- */

function cardConquista(c) {
    const r = raridade(c);
    const rodape = c.obtida
        ? `<span class="ach-date">${icon('check')} ${dataBR(c.obtidaEm)}</span>`
        : `<span class="ach-progress">${numero(c.progresso)} / ${numero(c.meta)} ${escapeHTML(c.unidade)}</span>`;
    return `<button type="button" class="ach-card ${c.obtida ? 'is-earned' : 'is-locked'} rarity-${r.id}" data-conquista="${c.id}"
                aria-label="${escapeHTML(c.nome)}: ${c.obtida ? 'obtida' : `${pctDe(c)}%`}. Ver detalhes">
        ${medalha(c)}
        <span class="ach-ribbon">${r.nome}</span>
        <strong class="ach-name">${escapeHTML(c.nome)}</strong>
        <span class="ach-desc">${escapeHTML(c.descricao)}</span>
        <span class="reward-chips">${fichaXp(c.recompensaXp)}${fichaCredito(c.recompensaCredito)}</span>
        ${rodape}
    </button>`;
}

function renderGrupos(lista) {
    const conta = { todas: lista.length, obtidas: lista.filter((c) => c.obtida).length };
    conta.andamento = conta.todas - conta.obtidas;
    $('ach-filters').innerHTML = FILTROS.map(([k, nome]) =>
        `<button type="button" class="chip ${filtro === k ? 'is-active' : ''}" data-filtro="${k}" aria-pressed="${filtro === k}">${nome} <span class="chip-count">${conta[k]}</span></button>`).join('');

    const visiveis = lista.filter((c) => filtro === 'todas' || (filtro === 'obtidas' ? c.obtida : !c.obtida));
    const html = GRUPOS.map((g) => {
        const todas = lista.filter((c) => grupoDe(c) === g);
        const itens = visiveis.filter((c) => grupoDe(c) === g)
            .sort((a, b) => (b.obtida - a.obtida) || (pctDe(b) - pctDe(a)));   // obtidas primeiro, depois as mais perto
        if (!itens.length) return '';
        return `<section class="ach-group">
            <h3 class="ach-group-title">${icon(g.icone)} ${g.nome} <span class="chip-count">${todas.filter((c) => c.obtida).length}/${todas.length}</span></h3>
            <div class="ach-grid">${itens.map(cardConquista).join('')}</div>
        </section>`;
    }).join('');
    $('achievements-grid').innerHTML = html || `<p class="empty-state">${filtro === 'obtidas' ? 'Nenhuma conquista obtida ainda. Veja em "Quase lá" as mais perto!' : 'Nenhuma conquista aqui.'}</p>`;
}

export function renderAchievements() {
    const lista = state.conquistas;
    renderVitrine(lista);
    renderQuaseLa(lista);
    renderGrupos(lista);
}

/* ---------------------------------------------------------------------------
   Janela: detalhes e comemoração
   --------------------------------------------------------------------------- */

function preencherModal(c, { comemorar = false, posicao = '' } = {}) {
    const r = raridade(c);
    const v = visual(c.criterio);
    $('mc-kicker').textContent = comemorar ? `Conquista desbloqueada!${posicao}` : `Conquista · ${grupoDe(c).nome}`;
    $('mc-medal').innerHTML = medalha(c, 'medal-lg');
    $('mc-title').textContent = c.nome;
    $('mc-rarity').innerHTML = `<span class="rarity-dot rarity-${r.id}"></span> ${r.nome} · ${r.faixa}`;
    $('mc-desc').textContent = c.descricao;
    $('mc-body').innerHTML = `
        <p class="ach-modal-label">${c.obtida ? 'Recompensa recebida' : 'Recompensa ao conquistar'}</p>
        <span class="reward-chips">${fichaXp(c.recompensaXp)}${fichaCredito(c.recompensaCredito)}</span>
        ${c.obtida
            ? `<p class="ach-date">${icon('check')} Conquistada em ${dataBR(c.obtidaEm)}</p>`
            : `<div class="bar-track"><div class="bar-fill fill-${v.cor}" style="width:${pctDe(c)}%"></div></div>
               <p class="ach-progress">${falta(c)}</p>`}`;
    const modal = $('modal-conquista');
    modal.classList.toggle('is-celebrating', comemorar);
    $('mc-confetti').innerHTML = comemorar ? Array.from({ length: 14 }, (_, i) => `<i style="--i:${i}"></i>`).join('') : '';
    $('mc-ok').textContent = fila.length ? 'Próxima' : (comemorar ? 'Oba!' : 'Fechar');
}

function abrirModal() {
    const modal = $('modal-conquista');
    if (modal.classList.contains('hidden')) focoAnterior = document.activeElement;
    modal.classList.remove('hidden');
    $('mc-ok').focus();
}

function fecharModal() {
    const proxima = fila.shift();
    if (proxima) { preencherModal(proxima.c, proxima.opcoes); return; }
    $('modal-conquista').classList.add('hidden');
    $('mc-confetti').innerHTML = '';
    focoAnterior?.focus?.();
}

/** As `n` conquistas obtidas mais recentes (mais nova primeiro). */
export function ultimasConquistas(lista = state.conquistas, n = 3) {
    return lista.filter((c) => c.obtida).sort((a, b) => (b.obtidaEm || '').localeCompare(a.obtidaEm || '') || b.id - a.id).slice(0, n);
}

/** Janela de detalhes de uma conquista (também usada pelo Perfil). */
export function abrirDetalhes(id) {
    const c = state.conquistas.find((x) => x.id === id);
    if (!c) return;
    preencherModal(c);
    abrirModal();
}

/** Comemoração das conquistas recém-ganhas (nomes vindos do estado), uma janela por vez. */
export function comemorarConquistas(nomes = []) {
    const novas = nomes.map((n) => state.conquistas.find((c) => c.nome === n)).filter(Boolean);
    if (!novas.length) return;
    const total = novas.length;
    const itens = novas.map((c, i) => ({ c, opcoes: { comemorar: true, posicao: total > 1 ? ` (${i + 1} de ${total})` : '' } }));
    const aberta = !$('modal-conquista').classList.contains('hidden');
    const [primeira, ...resto] = itens;
    if (aberta) { fila.push(...itens); return; }
    fila.push(...resto);
    preencherModal(primeira.c, primeira.opcoes);
    abrirModal();
}

export function initAchievements() {
    $('tab-conquistas').addEventListener('click', (e) => {
        const f = e.target.closest('[data-filtro]');
        if (f) { filtro = f.dataset.filtro; renderGrupos(state.conquistas); return; }
        const b = e.target.closest('[data-conquista]');
        if (b) abrirDetalhes(Number(b.dataset.conquista));
    });
    $('mc-ok').addEventListener('click', fecharModal);
    $('modal-conquista').addEventListener('click', (e) => { if (e.target.id === 'modal-conquista') { fila.length = 0; fecharModal(); } });
    $('modal-conquista').addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { fila.length = 0; fecharModal(); }
        if (e.key === 'Tab') { e.preventDefault(); $('mc-ok').focus(); }   // único controle: foco preso no botão
    });
}
