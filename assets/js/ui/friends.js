/**
 * Aba Amigos (RN55–RN60): faixa com o jogador e a busca, sugestões de amizade,
 * subabas Amigos / Pedidos / Bloqueados, conversa (consulta a cada 5 s com a conversa aberta)
 * e visita à cidade de um amigo. O selo da aba (não lidas + pedidos) é atualizado a cada 30 s.
 * Tudo é validado no servidor (api/amigos.php); aqui só se exibe e se pede confirmação.
 */
import { state, chamarApi } from '../state.js';
import { desenharCenario, reanexarCidade } from '../city/city-map.js';
import { anexarTrafego } from '../city/traffic.js';
import { CATEGORIAS, REGIOES, NIVEIS_CIDADE, estagioPorPontuacao } from '../data/catalog.js';
import { icon } from './icons.js';
import { initTabs } from './tabs.js';
import { $, escapeHTML, toast } from './dom.js';
import { tocar } from '../som.js';

const API = 'api/amigos.php';
const MENSAGEM_MAX = 500;

const st = {
    dados: { amigos: [], recebidos: [], enviados: [], bloqueados: [] },
    resumo: { naoLidas: 0, pedidos: 0 },
    sugestoes: [],
    painel: null,          // { tipo: 'conversa' | 'visita', amigo }
    ultimaMensagem: 0,
    ultimoDia: '',
    timerConversa: null,
    ocupado: false,
    ativarSubaba: null,
};

const api = (acao, campos = {}) => chamarApi(API, { acao, ...campos });
const abaVisivel = () => !$('tab-amigos').hidden && !document.hidden;
const paraData = (data) => new Date(data.replace(' ', 'T'));
const soHora = (data) => paraData(data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

/** Avatar com a inicial; 4 cores das categorias (sem os dois verdes iguais), dentro da paleta. */
/** Avatar escolhido (ícone + cor, RN93) ou a inicial colorida de antes. */
export const AVATAR_ICONES = ['leaf', 'recycle', 'droplet', 'bolt', 'bike', 'tree', 'sun', 'star'];
export const AVATAR_CORES = [['recycling', 'Azul'], ['water', 'Água'], ['energy', 'Laranja'], ['mobility', 'Verde-claro'], ['nature', 'Verde'], ['gold', 'Dourado']];
const temAvatar = (av) => av && AVATAR_ICONES.includes(av.icone) && AVATAR_CORES.some(([c]) => c === av.cor);
export const classeAvatar = (j, av = j.avatar) => (temAvatar(av) ? `av-c-${av.cor}` : `av-${(j.id ?? 0) % 4}`);
export const conteudoAvatar = (apelido, av) => (temAvatar(av) ? icon(av.icone) : escapeHTML((apelido || '?').slice(0, 1).toUpperCase()));
export const avatar = (j, extra = '') => `<span class="friend-avatar ${classeAvatar(j)} ${extra}" aria-hidden="true">${conteudoAvatar(j.apelido, j.avatar)}</span>`;
const botao = (acao, id, texto, classe = 'btn-outline') => `<button type="button" class="btn ${classe} btn-sm" data-acao="${acao}" data-id="${id}">${texto}</button>`;
const estagiosDe = (ind = {}) => Object.fromEntries(REGIOES.map((r) => [r.id, estagioPorPontuacao(ind[r.indicador] ?? 0)]));
const progressoDe = (ind = {}) => Math.round(CATEGORIAS.reduce((s, c) => s + (ind[c.id] ?? 0), 0) / CATEGORIAS.length);

/* ---------------------------------------------------------------------------
   Selo da aba e números da faixa
   --------------------------------------------------------------------------- */

let seloVisto = false;
function renderSelo(resumo = st.resumo) {
    const antes = st.resumo;
    st.resumo = { naoLidas: resumo.naoLidas ?? 0, pedidos: resumo.pedidos ?? 0 };
    if (seloVisto && st.resumo.naoLidas > antes.naoLidas) tocar('mensagem');
    else if (seloVisto && st.resumo.pedidos > antes.pedidos) tocar('pedido');
    seloVisto = true;
    const { naoLidas, pedidos } = st.resumo;
    const total = naoLidas + pedidos;
    const selo = $('friends-badge');
    selo.textContent = total > 99 ? '99+' : String(total);
    selo.classList.toggle('hidden', total === 0);
    selo.setAttribute('aria-label', `${naoLidas} mensagens não lidas e ${pedidos} pedidos de amizade`);
    $('friends-stat-pedidos').textContent = pedidos;
    $('friends-stat-naolidas').textContent = naoLidas;
    const req = $('friends-req-badge');
    req.textContent = pedidos;
    req.classList.toggle('hidden', pedidos === 0);
}

/** Sincronização (RN85): algo mudou em amizades, pedidos, bloqueios ou mensagens. */
function aoSincronizar(e) {
    if (!e.detail.mudou.includes('social')) return;
    renderSelo(e.detail.dados.social || state.social);
    if ($('t-amigos').getAttribute('aria-selected') !== 'true') return;
    if (st.painel?.tipo === 'conversa') buscarNovas(st.painel.amigo.id);
    else if (!st.painel) carregarListas();   // na visita não mexe
}

function renderHero() {
    const j = state.jogador;
    const avatarEu = $('friends-me-avatar');
    const av = state.perfil?.conta?.avatar;
    avatarEu.className = `friend-avatar friend-avatar-lg ${av ? classeAvatar(j, av) : ''}`;
    avatarEu.innerHTML = conteudoAvatar(j.apelido, av);
    $('friends-me-name').textContent = j.apelido;
    $('friends-my-code').textContent = j.codigoPublico || '';
}

/** Copia o código público; sem permissão da área de transferência, seleciona o texto em `elementoId`. */
export async function copiarCodigo(elementoId = 'friends-my-code') {
    const codigo = state.jogador.codigoPublico || '';
    try {
        await navigator.clipboard.writeText(codigo);
        toast('Código copiado. Passe para um amigo te encontrar!');
    } catch {   // sem permissão de área de transferência: seleciona o texto para copiar à mão
        const r = document.createRange();
        r.selectNodeContents($(elementoId));
        getSelection().removeAllRanges();
        getSelection().addRange(r);
        toast('Código selecionado: ' + codigo + '. Use Ctrl+C para copiar.');
    }
}

/* ---------------------------------------------------------------------------
   Miniatura da cidade (só o centro; desenhada quando o card aparece)
   --------------------------------------------------------------------------- */

const cacheMini = new Map();
const observador = 'IntersectionObserver' in window
    ? new IntersectionObserver((entradas) => entradas.forEach((e) => { if (e.isIntersecting) desenharMini(e.target); }), { rootMargin: '120px' })
    : null;

function desenharMini(el) {
    observador?.unobserve(el);
    const nivelCidade = Number(el.dataset.nivel) || 1;
    const estagios = JSON.parse(el.dataset.estagios);
    const chave = `${nivelCidade}-${REGIOES.map((r) => estagios[r.id]).join('')}`;
    if (!cacheMini.has(chave)) {
        const { svg, vista } = desenharCenario({ nivelCidade, estagios });
        cacheMini.set(chave, `<svg viewBox="${vista.join(' ')}" preserveAspectRatio="xMidYMid slice" aria-hidden="true">${svg}</svg>`);
    }
    el.insertAdjacentHTML('afterbegin', cacheMini.get(chave));
}

function observarMinis() {
    document.querySelectorAll('#friends-list .friend-thumb:not([data-ok])').forEach((el) => {
        el.dataset.ok = '1';
        if (observador) observador.observe(el); else desenharMini(el);
    });
}

/* ---------------------------------------------------------------------------
   Cards e listas
   --------------------------------------------------------------------------- */

function cardAmigo(a) {
    const geral = progressoDe(a.indicadores);
    const nomeNivel = NIVEIS_CIDADE[(a.nivelCidade || 1) - 1]?.nome ?? '';
    const ultima = a.ultima
        ? `<p class="friend-last ${a.naoLidas ? 'is-unread' : ''}" title="${escapeHTML(a.ultima.texto)}">${a.ultima.minha ? 'Você: ' : ''}${escapeHTML(a.ultima.texto)}</p>`
        : '<p class="friend-last">Nenhuma mensagem ainda.</p>';
    return `<li class="friend-card">
        <div class="friend-thumb" data-nivel="${a.nivelCidade || 1}" data-estagios='${JSON.stringify(estagiosDe(a.indicadores))}'
             role="img" aria-label="Cidade ${escapeHTML(a.cidade)}: nível ${a.nivelCidade || 1}, ${escapeHTML(nomeNivel)}">
            <span class="tag">Cidade nv ${a.nivelCidade || 1}</span>
            ${a.naoLidas ? `<span class="nav-count" aria-label="${a.naoLidas} não lidas">${a.naoLidas}</span>` : ''}
        </div>
        <div class="friend-body">
            ${avatar(a)}
            <div class="friend-name"><strong>${escapeHTML(a.apelido)}</strong><small>${escapeHTML(a.cidade)} · Jogador nv ${a.nivel}</small></div>
            <div class="friend-progress" title="Progresso ecológico">
                <div class="bar-track bar-sm" role="progressbar" aria-label="Progresso ecológico de ${escapeHTML(a.apelido)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${geral}">
                    <div class="bar-fill fill-overall" style="width:${geral}%"></div></div>
                <span>${geral}/100</span>
            </div>
            ${ultima}
            <div class="friend-buttons">
                ${botao('conversar', a.id, `${icon('mail')} Conversar`, 'btn-primary')}
                ${a.permiteVisita === false ? '' : botao('visitar', a.id, `${icon('home')} Visitar`)}
            </div>
        </div>
    </li>`;
}

function itemJogador(j, acoes) {
    return `<li class="friend-item">
        ${avatar(j)}
        <span class="friend-info"><strong>${escapeHTML(j.apelido)}</strong>
            <small>${escapeHTML(j.cidade)} · nível ${j.nivel}</small></span>
        <span class="friend-actions">${acoes}</span>
    </li>`;
}

const vazio = (ic, titulo, texto, extra = '') => `<li class="friends-empty">${icon(ic)}<strong>${titulo}</strong><span>${texto}</span>${extra}</li>`;

function renderListas() {
    const d = st.dados;
    $('friends-count').textContent = d.amigos.length;
    $('friends-stat-amigos').textContent = d.amigos.length;
    $('friends-list').innerHTML = d.amigos.length
        ? d.amigos.map(cardAmigo).join('')
        : vazio('leaf', 'Você ainda não tem amigos', 'Adicione alguém das sugestões acima ou busque pelo apelido ou código de outro jogador.');
    $('friends-received').innerHTML = d.recebidos.length
        ? d.recebidos.map((j) => itemJogador(j, botao('aceitar', j.id, 'Aceitar', 'btn-primary') + botao('recusar', j.id, 'Recusar'))).join('')
        : vazio('gift', 'Nenhum pedido recebido', 'Quando alguém te adicionar, o pedido aparece aqui.');
    $('friends-sent').innerHTML = d.enviados.length
        ? d.enviados.map((j) => itemJogador(j, botao('cancelar', j.id, 'Cancelar'))).join('')
        : vazio('mail', 'Nenhum pedido enviado', 'Os pedidos que você mandar ficam aqui até a resposta.');
    $('friends-blocked').innerHTML = d.bloqueados.map((j) => itemJogador(j, botao('desbloquear', j.id, 'Desbloquear'))).join('');
    const abaBloq = $('ft-bloqueados');
    abaBloq.hidden = !d.bloqueados.length;
    if (abaBloq.hidden && abaBloq.getAttribute('aria-selected') === 'true') st.ativarSubaba($('ft-amigos'), false);
    observarMinis();
}

async function carregarListas() {
    try {
        const r = await api('listar');
        st.dados = r.amigos;
        renderSelo(r.resumo);
        renderListas();
    } catch (e) { toast(e.message, 'error'); }
}

/* ---------------------------------------------------------------------------
   Sugestões (RN60)
   --------------------------------------------------------------------------- */

function cardSugestao(s) {
    const motivo = s.comuns
        ? `${s.comuns} ${s.comuns === 1 ? 'amigo' : 'amigos'} em comum: ${s.comunsNomes.map(escapeHTML).join(', ')}${s.comuns > s.comunsNomes.length ? '…' : ''}`
        : 'Jogador do EcoCity';
    return `<li class="suggest-card ${s.comuns ? 'has-comuns' : ''}">
        ${avatar(s)}
        <strong>${escapeHTML(s.apelido)}</strong>
        <small>${escapeHTML(s.cidade)} · Nv ${s.nivel}</small>
        <span class="suggest-why">${motivo}</span>
        ${botao('pedir', s.id, 'Adicionar', 'btn-primary')}
        <button type="button" class="link-btn" data-acao="dispensar" data-id="${s.id}">Agora não</button>
    </li>`;
}

function renderSugestoes(lista) {
    st.sugestoes = lista;
    $('friends-suggest-wrap').classList.toggle('hidden', !lista.length);
    $('friends-suggest').innerHTML = lista.map(cardSugestao).join('');
}

async function carregarSugestoes() {
    try { renderSugestoes((await api('sugestoes')).sugestoes); } catch (e) { toast(e.message, 'error'); }
}

async function dispensar(id) {
    try {
        const r = await api('dispensar', { jogador_id: id });
        toast(r.mensagem);
        renderSugestoes(r.sugestoes);
    } catch (e) { toast(e.message, 'error'); }
}

/* ---------------------------------------------------------------------------
   Busca
   --------------------------------------------------------------------------- */

function renderResultados(lista) {
    const texto = { amigo: 'Amigo', enviado: 'Pedido enviado' };
    $('friends-results-wrap').classList.remove('hidden');
    $('friends-results').innerHTML = lista.length
        ? lista.map((j) => itemJogador(j, j.relacao === 'nenhuma' ? (j.aceitaPedidos === false ? '<span class="tag tag-neutral">Não recebe pedidos</span>' : botao('pedir', j.id, 'Adicionar', 'btn-primary'))
            : j.relacao === 'recebido' ? botao('aceitar', j.id, 'Aceitar pedido', 'btn-primary')
                : `<span class="tag tag-success">${texto[j.relacao]}</span>`)).join('')
        : vazio('user', 'Nenhum jogador encontrado', 'Confira o apelido ou peça o código ECO-XXXX-XXXX.');
}

async function buscar(e) {
    e?.preventDefault();
    const termo = $('friends-term').value.trim();
    if (!termo) return;
    try { renderResultados((await api('buscar', { termo })).resultados); } catch (err) { toast(err.message, 'error'); }
}

function fecharBusca() {
    $('friends-results-wrap').classList.add('hidden');
    $('friends-term').value = '';
}

/* ---------------------------------------------------------------------------
   Painel (conversa ou visita) no lugar das listas
   --------------------------------------------------------------------------- */

function abrirPainel(html) {
    $('friends-panel').innerHTML = html;
    $('friends-main').classList.add('hidden');
    $('friends-results-wrap').classList.add('hidden');
    $('friends-panel').classList.remove('hidden');
    $('friends-panel').scrollIntoView({ block: 'start', behavior: 'smooth' });
}

function fecharPainel() {
    clearInterval(st.timerConversa);
    if (st.painel?.tipo === 'visita') reanexarCidade();
    st.painel = null;
    $('friends-panel').classList.add('hidden');
    $('friends-panel').innerHTML = '';
    $('friends-main').classList.remove('hidden');
    if ($('friends-term').value.trim()) $('friends-results-wrap').classList.remove('hidden');
}

function cabecalhoPainel(amigo, extra) {
    return `<div class="panel-head">
        <button type="button" class="btn btn-outline btn-sm" data-acao="voltar">${icon('back')} Voltar aos amigos</button>
        <div class="panel-who">${avatar(amigo)}<div><strong>${escapeHTML(amigo.apelido)}</strong><small>${escapeHTML(amigo.cidade)} · nível ${amigo.nivel}</small></div></div>
        <div class="panel-actions">${extra}
            ${botao('desfazer', amigo.id, 'Desfazer amizade')}
            ${botao('bloquear', amigo.id, 'Bloquear', 'btn-danger-outline')}</div>
    </div>`;
}

/* --- Conversa --- */

export function rotuloDia(data) {
    const d = paraData(data);
    const hoje = new Date();
    const ontem = new Date(); ontem.setDate(hoje.getDate() - 1);
    if (d.toDateString() === hoje.toDateString()) return 'Hoje';
    if (d.toDateString() === ontem.toDateString()) return 'Ontem';
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: d.getFullYear() === hoje.getFullYear() ? undefined : 'numeric' });
}

/** Balão; quando o dia muda em relação à mensagem anterior, coloca antes o separador do dia. */
function balao(m) {
    const dia = paraData(m.data).toDateString();
    const separador = dia !== st.ultimoDia ? `<li class="chat-day">${rotuloDia(m.data)}</li>` : '';
    st.ultimoDia = dia;
    const tique = m.minha ? `<span class="chat-tick ${m.lida ? 'is-read' : ''}" aria-label="${m.lida ? 'Lida' : 'Enviada'}">${m.lida ? '✓✓' : '✓'}</span>` : '';
    const corpo = m.removida
        ? '<p class="chat-removed">Mensagem removida pela moderação</p>'
        : `<p>${escapeHTML(m.texto)}</p>`;
    const acaoDenuncia = m.minha || m.removida ? '' : `<button type="button" class="chat-report" data-acao="denunciar" data-id="${m.id}">Denunciar</button>`;
    return `${separador}<li class="chat-msg ${m.minha ? 'is-mine' : ''}${m.removida ? ' is-removed' : ''}" data-msg="${m.id}">
        ${corpo}
        <span class="chat-meta">
            ${acaoDenuncia}
            <time datetime="${escapeHTML(m.data)}">${soHora(m.data)}</time>${tique}</span>
    </li>`;
}

async function abrirConversa(id) {
    clearInterval(st.timerConversa);
    try {
        const r = await api('conversa', { jogador_id: id });
        st.painel = { tipo: 'conversa', amigo: r.amigo };
        st.ultimaMensagem = r.mensagens.at(-1)?.id ?? 0;
        st.ultimoDia = '';
        abrirPainel(cabecalhoPainel(r.amigo, (r.amigo.permiteVisita === false ? '' : botao('visitar', id, `${icon('home')} Visitar cidade`)))
            + `<div class="panel-body">
                <ol class="chat-list" id="chat-list" aria-live="polite">${r.mensagens.map(balao).join('') || `<li class="friends-empty">${icon('mail')}<strong>Nenhuma mensagem ainda</strong><span>Diga oi para ${escapeHTML(r.amigo.apelido)}!</span></li>`}</ol>
                <form class="chat-form" id="chat-form">
                    <div class="chat-input">
                        <label class="sr-only" for="chat-text">Mensagem para ${escapeHTML(r.amigo.apelido)}</label>
                        <textarea id="chat-text" class="form-input" rows="1" maxlength="${MENSAGEM_MAX}" placeholder="Escreva uma mensagem (só texto)" required></textarea>
                        <small id="chat-count">0/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha</small>
                    </div>
                    <button type="submit" class="chat-send-btn" aria-label="Enviar mensagem">${icon('send')}</button>
                </form>
            </div>`);
        rolarConversa();
        renderSelo(r.resumo);
        marcarLidas(id);
        $('chat-text').focus({ preventScroll: true });
    } catch (e) { toast(e.message, 'error'); }
}

function marcarLidas(id) {
    const a = st.dados.amigos.find((x) => x.id === id);
    if (a && a.naoLidas) { a.naoLidas = 0; renderListas(); }
}

function rolarConversa() {
    const lista = $('chat-list');
    if (lista) lista.scrollTop = lista.scrollHeight;
}

function anexarMensagens(msgs) {
    if (!msgs.length) return;
    const lista = $('chat-list');
    lista.querySelector('.friends-empty')?.remove();
    let adicionou = false;
    msgs.forEach((m) => {
        const el = lista.querySelector(`[data-msg="${m.id}"]`);
        if (!el) {
            lista.insertAdjacentHTML('beforeend', balao(m));
            adicionou = true;
        } else if (Boolean(m.removida) !== el.classList.contains('is-removed')) {
            el.classList.toggle('is-removed', !!m.removida);
            const p = el.querySelector('p');
            if (p) {
                p.className = m.removida ? 'chat-removed' : '';
                p.textContent = m.removida ? 'Mensagem removida pela moderação' : m.texto;
            }
            if (m.removida) el.querySelector('.chat-report')?.remove();
        }
    });
    st.ultimaMensagem = Math.max(st.ultimaMensagem, ...msgs.map((m) => m.id));
    if (adicionou) rolarConversa();
}

async function buscarNovas(id) {
    if (!abaVisivel() || st.painel?.tipo !== 'conversa' || st.painel.amigo.id !== id) return;
    try {
        const r = await api('conversa', { jogador_id: id });
        anexarMensagens(r.mensagens);
        renderSelo(r.resumo);
    } catch (e) {   // deixou de ser amigo ou foi bloqueado
        toast(e.message, 'error');
        fecharPainel();
        carregarListas();
    }
}

async function enviar(e) {
    e.preventDefault();
    const campo = $('chat-text');
    const texto = campo.value.trim();
    if (!texto || st.ocupado || st.painel?.tipo !== 'conversa') return;
    st.ocupado = true;
    try {
        const r = await api('enviar', { jogador_id: st.painel.amigo.id, texto });
        anexarMensagens([r.mensagem]);
        campo.value = '';
        $('chat-count').textContent = `0/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha`;
        const a = st.dados.amigos.find((x) => x.id === st.painel.amigo.id);
        if (a) a.ultima = { texto: r.mensagem.texto.slice(0, 60), minha: true, data: r.mensagem.data };
    } catch (err) { toast(err.message, 'error'); } finally { st.ocupado = false; campo.focus(); }
}

/* --- Visita --- */

async function abrirVisita(id) {
    clearInterval(st.timerConversa);
    try {
        const { visita: v } = await api('visitar', { jogador_id: id });
        const amigo = st.dados.amigos.find((a) => a.id === id) || { id, apelido: v.apelido, cidade: v.cidade, nivel: v.nivel };
        st.painel = { tipo: 'visita', amigo };
        const cena = desenharCenario({ nivelCidade: v.nivelCidade, estagios: estagiosDe(v.indicadores), construcao: v.construcao });
        const vista = cena.vista;
        // Ids próprios: os gradientes do céu e da noite têm os mesmos ids do mapa próprio (escondido noutra aba)
        const svg = cena.svg.replace(/id="([^"]+)"/g, 'id="vis-$1"').replace(/url\(#([^)]+)\)/g, 'url(#vis-$1)');
        const geral = progressoDe(v.indicadores);
        const nomeNivel = NIVEIS_CIDADE[v.nivelCidade - 1]?.nome ?? '';
        abrirPainel(cabecalhoPainel(amigo, botao('conversar', id, `${icon('mail')} Conversar`, 'btn-primary'))
            + `<div class="panel-body">
                <div class="visit-map">
                    <svg class="city-svg" viewBox="${vista.join(' ')}" role="img" aria-label="Cidade ${escapeHTML(v.cidade)} de ${escapeHTML(v.apelido)}">${svg}</svg>
                    <div class="visit-stats">
                        <span class="city-relogio visit-relogio" hidden></span>
                        <span class="tag">Jogador nível ${v.nivel}</span>
                        <span class="tag">Cidade nível ${v.nivelCidade} · ${escapeHTML(nomeNivel)}</span>
                        <span class="tag">${geral}/100 de progresso ecológico</span>
                        ${v.construcao?.efeitos?.habitantes ? `<span class="tag">${icon('users')} Habitantes ${v.construcao.efeitos.habitantes}/50</span>` : ''}
                    </div>
                </div>
                <div class="visit-indicators">${CATEGORIAS.map((c) => `<div class="visit-ind">
                    <span>${icon(c.icone)} ${escapeHTML(c.nome)}</span><strong>${v.indicadores[c.id] ?? 0}/100</strong>
                    <div class="bar-track bar-sm"><div class="bar-fill fill-${c.id}" style="width:${v.indicadores[c.id] ?? 0}%"></div></div>
                </div>`).join('')}</div>
                <h4 class="visit-title">${icon('trophy')} Conquistas (${v.conquistas.length})</h4>
                <ul class="visit-badges">${v.conquistas.map((c) => `<li title="${escapeHTML(c.descricao || '')}">${icon('star')} ${escapeHTML(c.nome)}</li>`).join('') || '<li class="empty-state">Nenhuma conquista ainda.</li>'}</ul>
            </div>`);
        // Cidade funcionando: pessoas, veículos, lixo, dia e noite (mesmo motor do mapa próprio)
        const mapa = $('friends-panel').querySelector('.visit-map');
        anexarTrafego(mapa.querySelector('svg'), { estagios: estagiosDe(v.indicadores), nivelCidade: v.nivelCidade, construcao: v.construcao },
            { cidade: `visita:${id}`, relogio: mapa.querySelector('.visit-relogio') });
    } catch (e) { toast(e.message, 'error'); }
}

/* ---------------------------------------------------------------------------
   Confirmação (modal)
   --------------------------------------------------------------------------- */

export function confirmar({ titulo, texto, botaoTexto, comMotivo = false }) {
    return new Promise((resolve) => {
        const modal = $('modal-amigos');
        const foco = document.activeElement;
        $('ma-title').textContent = titulo;
        $('ma-text').textContent = texto;
        $('ma-confirm').textContent = botaoTexto;
        $('ma-motivo-group').classList.toggle('hidden', !comMotivo);
        $('ma-motivo').value = '';
        modal.classList.remove('hidden');
        (comMotivo ? $('ma-motivo') : $('ma-confirm')).focus();
        const fim = (ok) => {
            modal.classList.add('hidden');
            $('ma-confirm').onclick = $('ma-cancel').onclick = modal.onkeydown = null;
            foco?.focus?.();
            resolve(ok ? { motivo: $('ma-motivo').value } : null);
        };
        $('ma-confirm').onclick = () => fim(true);
        $('ma-cancel').onclick = () => fim(false);
        modal.onkeydown = (e) => { if (e.key === 'Escape') fim(false); };
    });
}

/* ---------------------------------------------------------------------------
   Ações
   --------------------------------------------------------------------------- */

const nomeDe = (id) => [...st.dados.amigos, ...st.dados.recebidos, ...st.dados.enviados, ...st.dados.bloqueados].find((j) => j.id === id)?.apelido
    ?? st.painel?.amigo?.apelido ?? 'este jogador';

async function acaoLista(acao, id) {
    const confirmacoes = {
        desfazer: { titulo: 'Desfazer amizade', texto: `Desfazer a amizade com ${nomeDe(id)}? A conversa deixa de aparecer.`, botaoTexto: 'Desfazer' },
        bloquear: { titulo: 'Bloquear jogador', texto: `Bloquear ${nomeDe(id)}? A amizade é desfeita e ele não poderá encontrar você, mandar pedidos nem mensagens.`, botaoTexto: 'Bloquear' },
    };
    if (confirmacoes[acao] && !(await confirmar(confirmacoes[acao]))) return;
    const mapa = { pedir: ['pedir', {}], aceitar: ['responder', { aceitar: 1 }], recusar: ['responder', { aceitar: 0 }], cancelar: ['cancelar', {}],
        desfazer: ['desfazer', {}], bloquear: ['bloquear', {}], desbloquear: ['desbloquear', {}] };
    if (!mapa[acao]) return;
    const [apiAcao, extra] = mapa[acao];
    try {
        const r = await api(apiAcao, { jogador_id: id, ...extra });
        toast(r.mensagem);
        st.dados = r.amigos;
        renderSelo(r.resumo);
        renderListas();
        if (r.sugestoes) renderSugestoes(r.sugestoes);
        else if (['aceitar', 'desfazer', 'bloquear', 'desbloquear'].includes(acao)) carregarSugestoes();
        if (['desfazer', 'bloquear'].includes(acao) && st.painel?.amigo.id === id) fecharPainel();
        if ($('friends-term').value.trim() && !$('friends-results-wrap').classList.contains('hidden')) buscar();
    } catch (e) { toast(e.message, 'error'); }
}

async function denunciar(mensagemId) {
    const r = await confirmar({ titulo: 'Denunciar mensagem', texto: 'A mensagem será enviada para a equipe do EcoCity analisar. Se quiser, explique o motivo.', botaoTexto: 'Denunciar', comMotivo: true });
    if (!r) return;
    try { toast((await api('denunciar', { mensagem_id: mensagemId, motivo: r.motivo })).mensagem); } catch (e) { toast(e.message, 'error'); }
}

export function initFriends() {
    renderHero();
    st.ativarSubaba = initTabs($('friends-tabs'));
    $('friends-search').addEventListener('submit', buscar);
    $('friends-copy').addEventListener('click', () => copiarCodigo());
    $('tab-amigos').addEventListener('click', (e) => {
        const b = e.target.closest('[data-acao]');
        if (!b) return;
        const id = Number(b.dataset.id);
        const acao = b.dataset.acao;
        if (acao === 'conversar') abrirConversa(id);
        else if (acao === 'visitar') abrirVisita(id);
        else if (acao === 'voltar') fecharPainel();
        else if (acao === 'denunciar') denunciar(id);
        else if (acao === 'dispensar') dispensar(id);
        else if (acao === 'outras-sugestoes') carregarSugestoes();
        else if (acao === 'fechar-busca') fecharBusca();
        else acaoLista(acao, id);
    });
    $('tab-amigos').addEventListener('submit', (e) => { if (e.target.id === 'chat-form') enviar(e); });
    $('tab-amigos').addEventListener('input', (e) => {
        if (e.target.id === 'chat-text') $('chat-count').textContent = `${e.target.value.length}/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha`;
    });
    $('tab-amigos').addEventListener('keydown', (e) => {   // Enter envia; Shift+Enter quebra linha
        if (e.target.id === 'chat-text' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('chat-form').requestSubmit(); }
    });
    // Abrir a aba (clique ou teclado) recarrega as listas e as sugestões
    new MutationObserver(() => {
        if ($('t-amigos').getAttribute('aria-selected') === 'true') { carregarListas(); carregarSugestoes(); }
    }).observe($('t-amigos'), { attributes: true, attributeFilter: ['aria-selected'] });
    renderSelo(state.social || {});
    document.addEventListener('ecocity:sincronia', aoSincronizar);   // substitui as consultas de 5 s e 30 s
}
