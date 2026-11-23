/**
 * Aba Comunidade (RN61–RN68).
 * Sem comunidade: convites, explorar/buscar, criar e prévia do ranking.
 * Com comunidade: faixa com posição e pontos e subabas Mural, Conversa (consulta a cada 5 s),
 * Missões (oficiais e metas internas), Membros e Ranking. O selo da aba é atualizado a cada 30 s.
 * Tudo é validado no servidor (api/comunidades.php); aqui só se exibe e se pede confirmação.
 */
import { state, chamarApi, aplicarDadosServidor } from '../state.js';
import { CATEGORIAS, CATEGORIA_POR_ID } from '../data/catalog.js';
import { icon, hydrateIcons } from './icons.js';
import { initTabs } from './tabs.js';
import { $, escapeHTML, toast } from './dom.js';
import { avatar, rotuloDia, confirmar } from './friends.js';
import { fichaXp, fichaCredito } from './weekly-missions.js';
import { tocar } from '../som.js';

const API = 'api/comunidades.php';
const MENSAGEM_MAX = 500;
const SUBABAS = [
    ['mural', 'Mural', 'flag'], ['conversa', 'Conversa', 'mail'], ['missoes', 'Missões', 'trophy'],
    ['membros', 'Membros', 'users'], ['ranking', 'Ranking', 'chart'],
];
const PAPEL = { lider: 'Líder', moderador: 'Moderador', membro: 'Membro' };

const st = {
    dados: null,
    subaba: 'mural',
    periodo: 'semana',
    novoTopico: false,
    ultimaMensagem: 0,
    ultimoDia: '',
    timer: null,
    ocupado: false,
};

const api = (acao, campos = {}) => chamarApi(API, { acao, ...campos });
const raiz = () => $('community-app');
const abaAberta = () => !$('tab-comunidade').hidden && !document.hidden;
const num = (n, casas = 1) => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: casas });
const paraData = (d) => new Date(d.replace(' ', 'T'));
const dataHora = (d) => paraData(d).toLocaleString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
const dataCurta = (d) => d.slice(0, 10).split('-').reverse().slice(0, 2).join('/');
const botao = (acao, id, texto, classe = 'btn-outline', extra = '') => `<button type="button" class="btn ${classe} btn-sm" data-acao="${acao}" data-id="${id}" ${extra}>${texto}</button>`;
const vazio = (ic, titulo, texto) => `<div class="friends-empty">${icon(ic)}<strong>${titulo}</strong><span>${texto}</span></div>`;
const podeModerar = () => ['lider', 'moderador'].includes(st.dados?.comunidade?.papel);
const souLider = () => st.dados?.comunidade?.papel === 'lider';

/** Emblema: ícone da área escolhida, na cor da área. */
function emblema(chave, extra = '') {
    const c = CATEGORIA_POR_ID[chave] || CATEGORIAS[0];
    return `<span class="cm-emblem cat-top-${c.id} ${extra}" aria-hidden="true">${icon(c.icone)}</span>`;
}

/* ---------------------------------------------------------------------------
   Selo da aba
   --------------------------------------------------------------------------- */

let seloComunidadeVisto = false;
function renderSelo(resumo = state.comunidade) {
    const antes = state.comunidade || {};
    state.comunidade = resumo || {};
    if (seloComunidadeVisto && (state.comunidade.naoLidas ?? 0) > (antes.naoLidas ?? 0)) tocar('mensagem');
    else if (seloComunidadeVisto && ((state.comunidade.pedidos ?? 0) > (antes.pedidos ?? 0) || (state.comunidade.convites ?? 0) > (antes.convites ?? 0))) tocar('pedido');
    seloComunidadeVisto = true;
    const { naoLidas = 0, pedidos = 0, convites = 0 } = state.comunidade;
    const total = naoLidas + pedidos + convites;
    const selo = $('community-badge');
    selo.textContent = total > 99 ? '99+' : String(total);
    selo.classList.toggle('hidden', total === 0);
    selo.setAttribute('aria-label', `${naoLidas} mensagens não lidas, ${pedidos} pedidos de entrada e ${convites} convites`);
    const chips = { conversa: naoLidas, membros: pedidos };
    Object.entries(chips).forEach(([k, n]) => {
        const b = raiz().querySelector(`#cms-${k} .nav-count`);
        if (b) { b.textContent = n; b.classList.toggle('hidden', !n); }
    });
}

/** Alguém está digitando na aba (não recarrega por cima de um formulário ou mensagem). */
function digitando() {
    const el = document.activeElement;
    return el && $('tab-comunidade').contains(el) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) && el.id !== 'cm-chat-text';
}

/** Sincronização (RN85): mudou a comunidade (conversa, mural, membros, missões, ranking) ou a participação do jogador. */
function aoSincronizar(e) {
    if (!e.detail.mudou.includes('comunidade')) return;
    renderSelo(e.detail.dados.comunidade || state.comunidade);
    if (!abaAberta()) return;
    if (st.subaba === 'conversa' && st.dados?.comunidade) buscarNovas();   // se saiu ou foi removido, buscarNovas recarrega
    else if (!digitando()) carregar();
}

/* ---------------------------------------------------------------------------
   Aviso de missão da comunidade concluída (uma vez por missão e semana)
   --------------------------------------------------------------------------- */

const mostrados = new Set();   // reserva se o navegador não deixar guardar
const chaveAvisos = () => `ecocity:avisos-comunidade:${state.jogador.codigoPublico}`;

function jaMostrados() {
    try { return new Set(JSON.parse(localStorage.getItem(chaveAvisos()) || '[]')); } catch { return new Set(mostrados); }
}

function guardarMostrados(conjunto) {
    [...conjunto].forEach((c) => mostrados.add(c));
    try { localStorage.setItem(chaveAvisos(), JSON.stringify([...conjunto].slice(-100))); } catch { /* fica só na memória */ }
}

/** Mostra (uma única vez) as missões e metas que a comunidade concluiu. */
export function notificarMissoesComunidade(lista = []) {
    if (!lista?.length) return;
    const vistos = jaMostrados();
    const novos = lista.filter((a) => !vistos.has(a.chave) && !mostrados.has(a.chave));
    if (!novos.length) return;
    novos.forEach((a) => {
        if (!a.oficial) toast(`Meta da comunidade batida: ${a.nome}!`);
        else if (a.xp || a.credito) toast(`Missão da comunidade concluída: ${a.nome}! Você ajudou e ganhou +${a.xp} XP e +${a.credito} créditos.`);
        else toast(`Sua comunidade concluiu a missão ${a.nome} (+${a.pontos} pontos no ranking). Contribua na próxima para ganhar a recompensa!`);
        vistos.add(a.chave);
    });
    guardarMostrados(vistos);
    if (!$('tab-comunidade').hidden) carregar();   // aba aberta: atualiza o "Concluída"
}

/* ---------------------------------------------------------------------------
   Sem comunidade
   --------------------------------------------------------------------------- */

function cardComunidade(c, convite = null) {
    let acao;
    if (convite || c.meuPedido === 'convite') acao = botao('entrar', c.id, 'Aceitar convite', 'btn-primary') + botao('cancelar', c.id, 'Recusar');
    else if (c.meuPedido === 'pedido') acao = '<span class="tag tag-warning">Pedido enviado</span>' + botao('cancelar', c.id, 'Cancelar');
    else if (c.cheia) acao = '<span class="tag tag-neutral">Cheia</span>';
    else acao = botao('entrar', c.id, c.entrada === 'aberta' ? 'Entrar' : 'Pedir para entrar', 'btn-primary');
    const regras = st.dados.regras;
    return `<li class="cm-card">
        ${emblema(c.icone)}
        <div class="cm-card-info">
            <strong>${escapeHTML(c.nome)}</strong>
            ${c.descricao ? `<p>${escapeHTML(c.descricao)}</p>` : ''}
            <small>${icon('users')} ${c.membros}/${regras.maxMembros} membros · ${c.entrada === 'aberta' ? 'Aberta' : 'Com aprovação'}
                ${c.posicao ? ` · #${c.posicao} na semana (${c.pontos} pts)` : ''}${convite ? ` · convite de ${escapeHTML(convite)}` : ''}</small>
        </div>
        <div class="cm-card-actions">${acao}</div>
    </li>`;
}

/** Fim da espera após sair de uma comunidade (ou null). */
const esperaAte = () => (st.dados.eu.esperaAte && paraData(st.dados.eu.esperaAte) > new Date() ? st.dados.eu.esperaAte : null);

function formCriar() {
    const r = st.dados.regras;
    const nivel = state.jogador.nivel;
    const creditos = state.jogador.creditosVerde;
    const faltas = [];
    if (nivel < r.nivelMin) faltas.push(`chegar ao nível ${r.nivelMin} (você está no ${nivel})`);
    if (creditos < r.custo) faltas.push(`juntar ${r.custo} créditos (você tem ${creditos})`);
    if (esperaAte()) faltas.push(`esperar até ${dataHora(esperaAte())}`);
    const travado = faltas.length > 0;
    return `<form class="card cm-create" id="cm-criar" novalidate>
        <h3 class="card-title">${icon('flag')} Criar uma comunidade</h3>
        <ul class="cm-reqs">
            <li class="${nivel >= r.nivelMin ? 'is-ok' : ''}">${icon(nivel >= r.nivelMin ? 'check' : 'lock')} Nível ${r.nivelMin} do jogador</li>
            <li class="${creditos >= r.custo ? 'is-ok' : ''}">${icon(creditos >= r.custo ? 'check' : 'lock')} ${r.custo} créditos verdes</li>
        </ul>
        ${travado ? `<p class="form-hint cm-create-hint">Para criar, falta ${faltas.join(' e ')}.${esperaAte() ? '' : ' Enquanto isso, entre numa comunidade!'}</p>` : ''}
        <fieldset ${travado ? 'disabled' : ''}>
            ${camposComunidade({ entrada: 'aberta', icone: 'recycling' })}
            <button type="submit" class="btn btn-primary btn-block">${icon('coin')} Criar por ${r.custo} créditos</button>
        </fieldset>
    </form>`;
}

/** Campos comuns do criar e do editar. */
function camposComunidade(c) {
    return `<div class="form-group"><label>Nome<input class="form-input" name="nome" maxlength="40" required value="${escapeHTML(c.nome || '')}" placeholder="Ex.: Guardiões do Bairro"></label></div>
        <div class="form-group"><label>Descrição <span class="form-hint">(opcional)</span>
            <textarea class="form-input" name="descricao" rows="2" maxlength="250" placeholder="O que vocês querem fazer juntos?">${escapeHTML(c.descricao || '')}</textarea></label></div>
        <fieldset class="cm-emblems"><legend>Emblema</legend>
            ${CATEGORIAS.map((k) => `<label class="cm-emblem-opt" title="${escapeHTML(k.curto)}">
                <input type="radio" name="icone" value="${k.id}" ${c.icone === k.id ? 'checked' : ''}>${emblema(k.id)}<span class="sr-only">${escapeHTML(k.curto)}</span></label>`).join('')}
        </fieldset>
        <fieldset class="cm-entry"><legend>Como os jogadores entram</legend>
            <label><input type="radio" name="entrada" value="aberta" ${c.entrada === 'aberta' ? 'checked' : ''}> <strong>Aberta</strong> — qualquer um entra direto</label>
            <label><input type="radio" name="entrada" value="aprovacao" ${c.entrada === 'aprovacao' ? 'checked' : ''}> <strong>Com aprovação</strong> — líder ou moderador aceita o pedido</label>
        </fieldset>`;
}

function renderSem() {
    const d = st.dados;
    raiz().innerHTML = `
        <div class="hero-band">
            <div class="cm-hero">
                <span class="cm-emblem cm-hero-icon" aria-hidden="true">${icon('users')}</span>
                <div class="cm-hero-info">
                    <strong>Junte-se a uma comunidade</strong>
                    <span>Reúna outros jogadores, combinem ações reais, cumpram missões coletivas e subam juntos no ranking.</span>
                </div>
                <dl class="hero-stats">
                    <div><dt>Comunidades</dt><dd>${d.ranking.total}</dd></div>
                    <div><dt>Convites</dt><dd>${d.convites.length}</dd></div>
                </dl>
            </div>
            <form class="friends-search" id="cm-busca" role="search">
                <label class="sr-only" for="cm-termo">Nome da comunidade</label>
                <input type="search" id="cm-termo" class="form-input" maxlength="40" placeholder="Buscar comunidade pelo nome" autocomplete="off">
                <button type="submit" class="btn btn-secondary">Buscar</button>
            </form>
        </div>
        ${esperaAte() ? `<p class="alert alert-warning cm-wait">${icon('calendar')} Você saiu de uma comunidade há pouco: poderá entrar em outra a partir de ${dataHora(esperaAte())}.</p>` : ''}
        ${d.convites.length ? `<div class="card cm-block"><h3 class="card-title">${icon('gift')} Convites para você</h3>
            <ul class="cm-list">${d.convites.map((c) => cardComunidade(c, c.convidou || 'um amigo')).join('')}</ul></div>` : ''}
        <div class="cm-cols">
            <div class="card cm-block">
                <h3 class="card-title">${icon('users')} Comunidades</h3>
                <ul class="cm-list" id="cm-explorar">${listaExplorar(d.explorar)}</ul>
            </div>
            <div class="cm-side">
                ${formCriar()}
                <div class="card cm-block">
                    <h3 class="card-title">${icon('chart')} Ranking da semana</h3>
                    ${tabelaRanking(d.ranking, null, 10)}
                </div>
            </div>
        </div>`;
}

function listaExplorar(lista) {
    return lista.length ? lista.map((c) => cardComunidade(c)).join('')
        : `<li>${vazio('users', 'Nenhuma comunidade encontrada', 'Que tal criar a primeira?')}</li>`;
}

/* ---------------------------------------------------------------------------
   Com comunidade
   --------------------------------------------------------------------------- */

function renderCom() {
    const d = st.dados;
    const c = d.comunidade;
    const r = d.regras;
    const concl = d.missoes.oficiais.filter((m) => m.concluida).length;
    raiz().innerHTML = `
        <div class="hero-band">
            <div class="cm-hero">
                ${emblema(c.icone, 'cm-hero-icon')}
                <div class="cm-hero-info">
                    <strong>${escapeHTML(c.nome)} <span class="cm-role">${PAPEL[c.papel]}</span></strong>
                    ${c.descricao ? `<span>${escapeHTML(c.descricao)}</span>` : ''}
                    <span class="cm-hero-actions">
                        ${souLider() ? `<button type="button" class="friends-copy" data-acao="configurar">${icon('clipboard')} Configurações</button>` : ''}
                        <button type="button" class="friends-copy" data-acao="sair">${icon('back')} Sair</button>
                    </span>
                </div>
                <dl class="hero-stats">
                    <div><dt>Posição</dt><dd>${c.posicaoSemana ? `#${c.posicaoSemana}` : '—'}<small>de ${c.total} na semana</small></dd></div>
                    <div><dt>Pontos</dt><dd>${c.pontosSemana}<small>esta semana</small></dd></div>
                    <div><dt>Membros</dt><dd>${c.membros}<small>de ${r.maxMembros}</small></dd></div>
                    <div><dt>Missões</dt><dd>${concl}/${d.missoes.oficiais.length}<small>da semana</small></dd></div>
                </dl>
            </div>
        </div>
        <div class="conteudo-nav cm-tabs" role="tablist" aria-label="Seções da comunidade" id="cm-tabs">
            ${SUBABAS.map(([id, nome, ic]) => `<button type="button" role="tab" class="chip" id="cms-${id}" aria-controls="cmp-${id}" aria-selected="${st.subaba === id}">
                ${icon(ic)}${nome}${id === 'conversa' || id === 'membros' ? ' <span class="nav-count hidden"></span>' : ''}</button>`).join('')}
        </div>
        <div role="tabpanel" id="cmp-mural" aria-labelledby="cms-mural">${painelMural()}</div>
        <div role="tabpanel" id="cmp-conversa" aria-labelledby="cms-conversa" hidden>${painelConversa()}</div>
        <div role="tabpanel" id="cmp-missoes" aria-labelledby="cms-missoes" hidden>${painelMissoes()}</div>
        <div role="tabpanel" id="cmp-membros" aria-labelledby="cms-membros" hidden>${painelMembros()}</div>
        <div role="tabpanel" id="cmp-ranking" aria-labelledby="cms-ranking" hidden>${painelRanking()}</div>`;
    const ativar = initTabs($('cm-tabs'));
    ativar($(`cms-${st.subaba}`), false);
    $('cm-tabs').addEventListener('click', () => trocouSubaba());
    $('cm-tabs').addEventListener('keyup', () => trocouSubaba());
}

function trocouSubaba() {
    const ativa = raiz().querySelector('#cm-tabs [aria-selected="true"]');
    const nova = ativa?.id.replace('cms-', '') || 'mural';
    if (nova === st.subaba) return;
    st.subaba = nova;
    if (nova === 'conversa') iniciarConversa();
    else pararConversa();
}

/* --- Mural --- */

function painelMural() {
    const d = st.dados;
    const form = podeModerar() ? (st.novoTopico ? `<form class="card cm-topic-form" id="cm-topico" novalidate>
            <h3 class="card-title">${icon('flag')} Novo tópico</h3>
            <div class="form-group"><label>Título<input class="form-input" name="titulo" maxlength="100" required placeholder="Ex.: Mutirão de reciclagem no sábado"></label></div>
            <div class="form-group"><label>Texto <span class="form-hint">(opcional)</span><textarea class="form-input" name="texto" rows="3" maxlength="1000" placeholder="Ex.: Vamos tentar juntar 10 kg de plástico. Tragam sacolas!"></textarea></label></div>
            <div class="form-row">
                <div class="form-group"><label>Data e hora do encontro <span class="form-hint">(opcional)</span><input class="form-input" type="datetime-local" name="data_evento"></label></div>
                <div class="form-group"><label>Local <span class="form-hint">(opcional)</span><input class="form-input" name="local" maxlength="150" placeholder="Ex.: Praça Central, perto da fonte"></label></div>
            </div>
            <label class="check-line"><input type="checkbox" name="fixado"> Fixar no topo (até ${d.regras.fixadosMax})</label>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Publicar</button>
                <button type="button" class="btn btn-outline" data-acao="fechar-topico">Cancelar</button></div>
        </form>` : `<button type="button" class="btn btn-primary cm-new-topic" data-acao="novo-topico">${icon('flag')} Novo tópico no mural</button>`)
        : '<p class="form-hint cm-hint">O líder e os moderadores publicam os tópicos. Converse com todos na aba Conversa.</p>';
    return form + (d.topicos.length ? `<ul class="cm-topics">${d.topicos.map(cardTopico).join('')}</ul>`
        : vazio('flag', 'O mural está vazio', podeModerar() ? 'Publique o primeiro tópico: um aviso, um desafio ou um encontro.' : 'Quando o líder publicar avisos e encontros, eles aparecem aqui.'));
}

function cardTopico(t) {
    const eu = st.dados.eu.id;
    const evento = t.dataEvento || t.local ? `<div class="cm-event">
            ${t.dataEvento ? `<span>${icon('calendar')} ${dataHora(t.dataEvento)}</span>` : ''}
            ${t.local ? `<span>${icon('pin')} ${escapeHTML(t.local)}</span>` : ''}
        </div>` : '';
    const presenca = t.dataEvento ? `<div class="cm-rsvp">
            ${t.aberto ? botao('presenca', t.id, t.euVou ? `${icon('check')} Vou participar` : 'Vou participar', t.euVou ? 'btn-primary' : 'btn-outline', `data-vou="${t.euVou ? 0 : 1}" aria-pressed="${t.euVou}"`) : '<span class="tag tag-neutral">Encontro encerrado</span>'}
            <span class="cm-rsvp-who">${t.vao ? `${t.vao} ${t.vao === 1 ? 'vai' : 'vão'}: ${t.vaoNomes.map(escapeHTML).join(', ')}${t.vao > t.vaoNomes.length ? '…' : ''}` : 'Ninguém confirmou ainda.'}</span>
        </div>` : '';
    const acoes = [
        podeModerar() ? `<button type="button" class="link-btn" data-acao="fixar" data-id="${t.id}" data-fixar="${t.fixado ? 0 : 1}">${t.fixado ? 'Desafixar' : 'Fixar'}</button>` : '',
        t.autorId === eu || podeModerar() ? `<button type="button" class="link-btn" data-acao="apagar-topico" data-id="${t.id}">Apagar</button>` : '',
        t.autorId !== eu ? `<button type="button" class="link-btn cm-report" data-acao="denunciar" data-tipo="topico" data-id="${t.id}">Denunciar</button>` : '',
    ].join('');
    return `<li class="card cm-topic ${t.fixado ? 'is-pinned' : ''}">
        ${t.fixado ? `<span class="cm-pin">${icon('star')} Fixado</span>` : ''}
        <h4>${escapeHTML(t.titulo)}</h4>
        <p class="cm-topic-meta">${escapeHTML(t.autor)} · ${dataHora(t.data)}</p>
        ${t.texto ? `<p class="cm-topic-text">${escapeHTML(t.texto)}</p>` : ''}
        ${evento}${presenca}
        <div class="cm-topic-actions">${acoes}</div>
    </li>`;
}

/* --- Conversa --- */

function painelConversa() {
    return `<div class="card cm-chat">
        <ol class="chat-list" id="cm-chat-list" aria-live="polite"><li class="friends-empty">Carregando…</li></ol>
        <form class="chat-form" id="cm-chat-form">
            <div class="chat-input">
                <label class="sr-only" for="cm-chat-text">Mensagem para a comunidade</label>
                <textarea id="cm-chat-text" class="form-input" rows="1" maxlength="${MENSAGEM_MAX}" placeholder="Escreva para a comunidade (só texto)" required></textarea>
                <small id="cm-chat-count">0/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha</small>
            </div>
            <button type="submit" class="chat-send-btn" aria-label="Enviar mensagem">${icon('send')}</button>
        </form>
    </div>`;
}

function balao(m) {
    const dia = paraData(m.data).toDateString();
    const separador = dia !== st.ultimoDia ? `<li class="chat-day">${rotuloDia(m.data)}</li>` : '';
    st.ultimoDia = dia;
    const hora = paraData(m.data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const corpo = m.removida
        ? '<p class="chat-removed">Mensagem removida pela moderação</p>'
        : `<p>${escapeHTML(m.texto)}</p>`;
    const acaoDenuncia = m.minha || m.removida ? '' : `<button type="button" class="chat-report" data-acao="denunciar" data-tipo="mensagem" data-id="${m.id}">Denunciar</button>`;
    return `${separador}<li class="chat-msg ${m.minha ? 'is-mine' : ''}${m.removida ? ' is-removed' : ''}" data-msg="${m.id}">
        ${m.minha ? '' : `<span class="cm-chat-author av-text-${m.autorId % 4}">${escapeHTML(m.autor)}</span>`}
        ${corpo}
        <span class="chat-meta">
            ${acaoDenuncia}
            <time datetime="${escapeHTML(m.data)}">${hora}</time></span>
    </li>`;
}

function anexar(msgs, inicio = false) {
    const lista = $('cm-chat-list');
    if (!lista) return;
    let adicionou = false;
    if (inicio) {
        st.ultimoDia = '';
        lista.innerHTML = msgs.length ? msgs.map(balao).join('')
            : `<li class="friends-empty">${icon('mail')}<strong>Nenhuma mensagem ainda</strong><span>Dê boas-vindas à comunidade!</span></li>`;
        adicionou = msgs.length > 0;
    } else if (msgs.length) {
        lista.querySelector('.friends-empty')?.remove();
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
    }
    if (msgs.length) st.ultimaMensagem = Math.max(st.ultimaMensagem, ...msgs.map((m) => m.id));
    if (inicio || adicionou) lista.scrollTop = lista.scrollHeight;
}

async function iniciarConversa() {
    pararConversa();
    try {
        const r = await api('conversa');
        st.ultimaMensagem = 0;
        anexar(r.mensagens, true);
        renderSelo(r.resumo);
    } catch (e) { toast(e.message, 'error'); }
}

function pararConversa() { clearInterval(st.timer); st.timer = null; }

async function buscarNovas() {
    if (!abaAberta() || st.subaba !== 'conversa') return;
    try {
        const r = await api('conversa');
        anexar(r.mensagens);
        renderSelo(r.resumo);
    } catch (e) {   // saiu, foi removido ou a comunidade acabou
        pararConversa();
        toast(e.message, 'error');
        carregar();
    }
}

async function enviar(e) {
    e.preventDefault();
    const campo = $('cm-chat-text');
    const texto = campo.value.trim();
    if (!texto || st.ocupado) return;
    st.ocupado = true;
    try {
        const r = await api('enviar', { texto });
        anexar([r.mensagem]);
        campo.value = '';
        $('cm-chat-count').textContent = `0/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha`;
    } catch (err) { toast(err.message, 'error'); } finally { st.ocupado = false; campo.focus(); }
}

/* --- Missões --- */

function unidadeCurta(u = '') {
    return u.split(' ')[0] || '';
}

function cardMissao(m, oficial) {
    const cat = CATEGORIA_POR_ID[m.categoria] || CATEGORIAS[0];
    const pct = Math.min(100, Math.round((m.feito / m.meta) * 100));
    const un = unidadeCurta(m.unidade);
    const prazo = oficial ? '' : `<span class="cm-mission-deadline">${m.encerrada ? 'Encerrada' : `Até ${dataCurta(m.dataFim)}`}</span>`;
    return `<li class="card cm-mission cat-top-${cat.id} ${m.concluida ? 'is-done' : ''}">
        <div class="cm-mission-head">
            <span class="cm-mission-icon">${icon(cat.icone)}</span>
            <div><strong>${escapeHTML(m.nome)}</strong><small>${escapeHTML(m.atividade)}${prazo ? ' · ' : ''}${prazo}</small></div>
            ${m.concluida ? `<span class="cm-done">${icon('check')} Concluída</span>` : ''}
        </div>
        ${m.descricao ? `<p class="cm-mission-desc">${escapeHTML(m.descricao)}</p>` : ''}
        <div class="cm-mission-progress">
            <div class="bar-track" role="progressbar" aria-label="Progresso de ${escapeHTML(m.nome)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}">
                <div class="bar-fill fill-${cat.id}" style="width:${pct}%"></div></div>
            <span><strong>${num(m.feito)}</strong> / ${num(m.meta)} ${escapeHTML(un)} · ${pct}%</span>
        </div>
        <p class="cm-mission-mine">${m.minhasAcoes ? `${icon('check')} Você contribuiu com ${m.minhasAcoes} ${m.minhasAcoes === 1 ? 'ação' : 'ações'} (${num(m.meuImpacto)} ${escapeHTML(un)})`
            : `Você ainda não contribuiu${oficial ? ': registre esta ação para ganhar a recompensa quando a meta for batida.' : '.'}`}</p>
        ${oficial ? `<div class="cm-mission-reward"><span>Para quem contribuiu:</span>${fichaXp(m.xp)}${fichaCredito(m.credito)}<span class="reward-chip chip-points">${icon('chart')}+${m.pontos} pts</span></div>`
            : (podeModerar() ? `<div class="cm-topic-actions"><button type="button" class="link-btn" data-acao="remover-meta" data-id="${m.id}">Remover meta</button></div>` : '')}
    </li>`;
}

function painelMissoes() {
    const { missoes: ms, regras: r, atividades } = st.dados;
    const formMeta = podeModerar() ? `<form class="card cm-meta-form" id="cm-meta" novalidate>
            <h4>${icon('flag')} Nova meta interna</h4>
            <div class="form-row">
                <div class="form-group"><label>Nome<input class="form-input" name="nome" maxlength="100" required placeholder="Ex.: 100 kg até o fim do mês"></label></div>
                <div class="form-group"><label>Atividade<select class="form-input" name="atividade_id">${atividades.filter((a) => a.unidade).map((a) => `<option value="${a.id}">${escapeHTML(a.nome)} (${escapeHTML(a.unidade)})</option>`).join('')}</select></label></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Meta (impacto real)<input class="form-input" type="number" name="meta" min="0.01" step="0.01" required></label></div>
                <div class="form-group"><label>Prazo em dias<input class="form-input" type="number" name="dias" min="1" max="${r.metaDias}" value="30" required></label></div>
            </div>
            <p class="form-hint">Metas internas só acompanham o progresso: sem recompensa e fora do ranking. Até ${r.metasMax} em andamento.</p>
            <button type="submit" class="btn btn-primary btn-sm">Criar meta</button>
        </form>` : '';
    return `<p class="cm-week">${icon('calendar')} Semana de ${dataCurta(ms.semanaInicio)} a ${dataCurta(ms.semanaFim)} · as metas somam o impacto real dos registros aprovados de todos os membros.</p>
        <h3 class="cm-section-title">${icon('trophy')} Missões oficiais da semana</h3>
        ${ms.oficiais.length ? `<ul class="cm-missions">${ms.oficiais.map((m) => cardMissao(m, true)).join('')}</ul>` : vazio('trophy', 'Nenhuma missão oficial nesta semana', 'Volte na segunda-feira.')}
        <h3 class="cm-section-title">${icon('flag')} Metas da comunidade</h3>
        ${ms.internas.length ? `<ul class="cm-missions">${ms.internas.map((m) => cardMissao(m, false)).join('')}</ul>` : '<p class="form-hint cm-hint">Nenhuma meta interna. O líder e os moderadores podem criar metas próprias, como "100 kg até o fim do mês".</p>'}
        ${formMeta}`;
}

/* --- Membros --- */

function painelMembros() {
    const d = st.dados;
    const eu = d.eu.id;
    const lider = souLider();
    const linha = (m) => {
        const acoes = [];
        if (m.id !== eu && m.papel !== 'lider') {
            if (lider) {
                acoes.push(m.papel === 'moderador' ? botao('papel', m.id, 'Rebaixar', 'btn-outline', 'data-papel="membro"') : botao('papel', m.id, 'Tornar moderador', 'btn-outline', 'data-papel="moderador"'));
                acoes.push(botao('lideranca', m.id, 'Passar liderança'));
            }
            if (lider || (d.comunidade.papel === 'moderador' && m.papel === 'membro')) acoes.push(botao('remover', m.id, 'Remover', 'btn-danger-outline'));
        }
        return `<li class="friend-item ${m.id === eu ? 'is-me' : ''}">
            ${avatar(m)}
            <span class="friend-info"><strong>${escapeHTML(m.apelido)}${m.id === eu ? ' (você)' : ''} <span class="cm-role-tag cm-role-${m.papel}">${PAPEL[m.papel]}</span></strong>
                <small>${escapeHTML(m.cidade)} · nível ${m.nivel} · ${m.pontosSemana} pts esta semana (${m.acoesSemana} ${m.acoesSemana === 1 ? 'ação' : 'ações'})</small></span>
            <span class="friend-actions">${acoes.join('')}</span>
        </li>`;
    };
    const pedidos = podeModerar() ? `<div class="card cm-block"><h3 class="card-title">${icon('gift')} Pedidos para entrar (${d.pedidos.length})</h3>
        ${d.pedidos.length ? `<ul class="friends-mini-grid">${d.pedidos.map((j) => `<li class="friend-item">${avatar(j)}
            <span class="friend-info"><strong>${escapeHTML(j.apelido)}</strong><small>${escapeHTML(j.cidade)} · nível ${j.nivel}</small></span>
            <span class="friend-actions">${botao('aceitar', j.id, 'Aceitar', 'btn-primary')}${botao('recusar', j.id, 'Recusar')}</span></li>`).join('')}</ul>`
            : '<p class="form-hint">Nenhum pedido no momento.</p>'}</div>` : '';
    const convidar = `<div class="card cm-block"><h3 class="card-title">${icon('mail')} Convidar amigos</h3>
        ${d.amigosConvidaveis.length ? `<ul class="friends-mini-grid">${d.amigosConvidaveis.map((j) => `<li class="friend-item">${avatar(j)}
            <span class="friend-info"><strong>${escapeHTML(j.apelido)}</strong><small>${escapeHTML(j.cidade)} · nível ${j.nivel}</small></span>
            <span class="friend-actions">${botao('convidar', j.id, 'Convidar', 'btn-primary')}</span></li>`).join('')}</ul>`
            : '<p class="form-hint">Nenhum amigo disponível para convidar (sem comunidade e sem convite pendente). Adicione amigos na aba Amigos.</p>'}</div>`;
    return `${pedidos}<div class="card cm-block"><h3 class="card-title">${icon('users')} Membros (${d.membros.length}/${d.regras.maxMembros})</h3>
        <ul class="friends-mini-grid cm-members">${d.membros.map(linha).join('')}</ul>
        <p class="form-hint">Pontos: ${d.regras.pontosRapido} por ação sem foto e ${d.regras.pontosFoto} com foto, quando aprovada.</p></div>${convidar}`;
}

/* --- Ranking --- */

function tabelaRanking(rk, minhaId, limite = 20) {
    if (!rk.top.length) return '<p class="form-hint">Nenhuma comunidade ainda.</p>';
    const linha = (l) => `<li class="cm-rank-row ${l.id === minhaId ? 'is-mine' : ''}">
        <span class="cm-rank-pos ${l.posicao <= 3 ? `top-${l.posicao}` : ''}">${l.posicao}</span>
        ${emblema(l.icone)}
        <span class="cm-rank-name"><strong>${escapeHTML(l.nome)}</strong><small>${l.membros} ${l.membros === 1 ? 'membro' : 'membros'}</small></span>
        <span class="cm-rank-pts">${l.pontos} pts</span>
    </li>`;
    const top = rk.top.slice(0, limite);
    const fora = rk.minha && !top.some((l) => l.id === rk.minha.id);
    return `<ol class="cm-rank">${top.map(linha).join('')}${fora ? `<li class="cm-rank-gap" aria-hidden="true">…</li>${linha(rk.minha)}` : ''}</ol>`;
}

function painelRanking() {
    const d = st.dados;
    const rk = st.periodo === 'geral' ? d.rankingGeral : d.ranking;
    return `<div class="card cm-block">
        <div class="cm-rank-head">
            <h3 class="card-title">${icon('chart')} Ranking das comunidades</h3>
            <div class="conteudo-nav" role="group" aria-label="Período">
                <button type="button" class="chip ${st.periodo === 'semana' ? 'is-active' : ''}" data-acao="periodo" data-periodo="semana" aria-pressed="${st.periodo === 'semana'}">Esta semana</button>
                <button type="button" class="chip ${st.periodo === 'geral' ? 'is-active' : ''}" data-acao="periodo" data-periodo="geral" aria-pressed="${st.periodo === 'geral'}">Geral</button>
            </div>
        </div>
        <p class="form-hint">${st.periodo === 'semana' ? 'Zera toda segunda-feira. ' : ''}Pontos: ações aprovadas dos membros (${d.regras.pontosRapido} sem foto, ${d.regras.pontosFoto} com foto) + missões oficiais concluídas.
            Sua comunidade: <strong>${rk.minha ? `#${rk.minha.posicao} de ${rk.total} · ${rk.minha.pontos} pts` : '—'}</strong></p>
        ${tabelaRanking(rk, d.comunidade.id)}
    </div>`;
}

/* ---------------------------------------------------------------------------
   Carregar e agir
   --------------------------------------------------------------------------- */

function render() {
    if (!st.dados) return;
    pararConversa();
    if (st.dados.comunidade) renderCom(); else renderSem();
    hydrateIcons(raiz());
    renderSelo(st.dados.resumo);
    if (st.dados.comunidade && st.subaba === 'conversa') iniciarConversa();
}

async function carregar() {
    try {
        st.dados = (await api('dados')).dados;
        render();
    } catch (e) { toast(e.message, 'error'); }
}

/** Executa uma ação que devolve { mensagem, dados } e redesenha. */
async function executar(acao, campos = {}) {
    try {
        const r = await api(acao, campos);
        toast(r.mensagem);
        const tinha = st.dados?.comunidade?.id;
        st.dados = r.dados;
        if (st.dados.comunidade?.id !== tinha) { st.subaba = 'mural'; st.novoTopico = false; }
        if (r.jogador) aplicarDadosServidor(r);
        render();
        return true;
    } catch (e) { toast(e.message, 'error'); return false; }
}

const nomeMembro = (id) => [...(st.dados.membros || []), ...(st.dados.pedidos || [])].find((m) => m.id === id)?.apelido || 'este jogador';

async function aoClicar(e) {
    const b = e.target.closest('[data-acao]');
    if (!b) return;
    const id = Number(b.dataset.id);
    const acao = b.dataset.acao;
    switch (acao) {
        case 'entrar': return executar('entrar', { comunidade_id: id });
        case 'cancelar': return executar('cancelar', { comunidade_id: id });
        case 'aceitar': return executar('responder', { jogador_id: id, aceitar: 1 });
        case 'recusar': return executar('responder', { jogador_id: id, aceitar: 0 });
        case 'convidar': return executar('convidar', { jogador_id: id });
        case 'papel': return executar('papel', { jogador_id: id, papel: b.dataset.papel });
        case 'presenca': return executar('presenca', { topico_id: id, vou: b.dataset.vou });
        case 'fixar': return executar('fixar', { topico_id: id, fixar: b.dataset.fixar });
        case 'novo-topico': st.novoTopico = true; render(); $('cm-topico')?.querySelector('input')?.focus(); return;
        case 'fechar-topico': st.novoTopico = false; render(); return;
        case 'periodo': st.periodo = b.dataset.periodo; $('cmp-ranking').innerHTML = painelRanking(); hydrateIcons($('cmp-ranking')); return;
        case 'configurar': return abrirConfiguracoes();
        case 'denunciar': {
            const r = await confirmar({ titulo: b.dataset.tipo === 'topico' ? 'Denunciar tópico' : 'Denunciar mensagem',
                texto: 'Será enviado para a equipe do EcoCity analisar. Se quiser, explique o motivo.', botaoTexto: 'Denunciar', comMotivo: true });
            if (!r) return;
            try { toast((await api('denunciar', { tipo: b.dataset.tipo, alvo_id: id, motivo: r.motivo })).mensagem); } catch (err) { toast(err.message, 'error'); }
            return;
        }
        default: break;
    }
    const confirmacoes = {
        sair: { titulo: 'Sair da comunidade', botaoTexto: 'Sair',
            texto: `Sair de ${st.dados.comunidade?.nome}? Seus pontos continuam com ela${souLider() ? ' e a liderança passa para outro membro' : ''}. Você só poderá entrar em outra comunidade depois de ${st.dados.regras.esperaHoras} h.` },
        remover: { titulo: 'Remover membro', texto: `Remover ${nomeMembro(id)} da comunidade?`, botaoTexto: 'Remover' },
        lideranca: { titulo: 'Passar liderança', texto: `Passar a liderança para ${nomeMembro(id)}? Você vira moderador (ou membro, se já houver ${st.dados.regras.maxModeradores} moderadores).`, botaoTexto: 'Passar' },
        'apagar-topico': { titulo: 'Apagar tópico', texto: 'Apagar este tópico do mural?', botaoTexto: 'Apagar' },
        'remover-meta': { titulo: 'Remover meta', texto: 'Remover esta meta interna?', botaoTexto: 'Remover' },
    };
    if (!confirmacoes[acao] || !(await confirmar(confirmacoes[acao]))) return;
    const mapa = { sair: ['sair', {}], remover: ['remover', { jogador_id: id }], lideranca: ['lideranca', { jogador_id: id }],
        'apagar-topico': ['apagar_topico', { topico_id: id }], 'remover-meta': ['remover_meta', { meta_id: id }] };
    const [apiAcao, campos] = mapa[acao];
    executar(apiAcao, campos);
}

const lerForm = (form) => Object.fromEntries(new FormData(form).entries());

async function aoEnviar(e) {
    const f = e.target;
    if (f.id === 'cm-chat-form') return enviar(e);
    e.preventDefault();
    if (f.id === 'cm-busca') {
        try {
            const termo = $('cm-termo').value.trim();
            $('cm-explorar').innerHTML = listaExplorar((await api('explorar', { termo })).comunidades);
            hydrateIcons($('cm-explorar'));
        } catch (err) { toast(err.message, 'error'); }
        return;
    }
    if (f.id === 'cm-criar') return executar('criar', lerForm(f));
    if (f.id === 'cm-topico') {
        const dados = lerForm(f);
        dados.fixado = f.querySelector('[name="fixado"]').checked ? 1 : 0;
        if (await executar('topico', dados)) { st.novoTopico = false; render(); }
        return;
    }
    if (f.id === 'cm-meta') return executar('meta', lerForm(f));
}

/* --- Configurações do líder (modal) --- */

function abrirConfiguracoes() {
    const c = st.dados.comunidade;
    const modal = $('modal-comunidade');
    const form = $('mcm-form');
    const foco = document.activeElement;
    form.innerHTML = `${camposComunidade(c)}
        <div class="modal-actions">
            <button type="button" class="btn btn-danger-outline" data-mcm="dissolver">Encerrar comunidade</button>
            <span class="cm-spacer"></span>
            <button type="button" class="btn btn-outline" data-mcm="cancelar">Cancelar</button>
            <button type="submit" class="btn btn-primary">Salvar</button>
        </div>`;
    hydrateIcons(form);
    modal.classList.remove('hidden');
    form.querySelector('input').focus();
    const fechar = () => { modal.classList.add('hidden'); form.onsubmit = form.onclick = modal.onkeydown = null; foco?.focus?.(); };
    form.onsubmit = async (e) => { e.preventDefault(); if (await executar('editar', lerForm(form))) fechar(); };
    form.onclick = async (e) => {
        const b = e.target.closest('[data-mcm]');
        if (!b) return;
        if (b.dataset.mcm === 'cancelar') return fechar();
        fechar();
        if (await confirmar({ titulo: 'Encerrar comunidade', botaoTexto: 'Encerrar',
            texto: `Encerrar ${c.nome}? Todos os membros saem, ela some do ranking e isso não pode ser desfeito.` })) executar('dissolver');
    };
    modal.onkeydown = (e) => { if (e.key === 'Escape') fechar(); };
}

export function initCommunity() {
    const aba = $('tab-comunidade');
    aba.addEventListener('click', aoClicar);
    aba.addEventListener('submit', aoEnviar);
    aba.addEventListener('input', (e) => {
        if (e.target.id === 'cm-chat-text') $('cm-chat-count').textContent = `${e.target.value.length}/${MENSAGEM_MAX} · Enter envia, Shift+Enter quebra a linha`;
    });
    aba.addEventListener('keydown', (e) => {   // Enter envia; Shift+Enter quebra linha
        if (e.target.id === 'cm-chat-text' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('cm-chat-form').requestSubmit(); }
    });
    // Abrir a aba (clique ou teclado) recarrega os dados; sair dela para a conversa
    new MutationObserver(() => {
        if ($('t-comunidade').getAttribute('aria-selected') === 'true') carregar(); else pararConversa();
    }).observe($('t-comunidade'), { attributes: true, attributeFilter: ['aria-selected'] });
    renderSelo(state.comunidade);
    document.addEventListener('ecocity:sincronia', aoSincronizar);   // substitui as consultas de 5 s e 30 s
}
