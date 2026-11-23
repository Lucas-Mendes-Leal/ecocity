/**
 * Página de administração: ícones, abas e a aba Conteúdo (gestão pela API).
 * Aprovações e os totais da Visão Geral são renderizados pelo PHP; as estatísticas vêm da API.
 */
import './pwa.js';
import { hydrateIcons } from './ui/icons.js';
import { tocar } from './som.js';
import { initConteudoAdmin } from './ui/admin-conteudo.js';
import { initEstatisticasAdmin, limparCacheEstatisticas } from './ui/admin-estatisticas.js';
import { listaPaginada, porPagina, definirPorPagina, pilulas, seletorTamanho } from './ui/lista-admin.js';
import { escapeHTML, pager } from './ui/dom.js';
import { iniciarSeletorAtividades } from './ui/seletor-atividades.js';

const TEXTOS_CONTEUDO = {
    catalogo: [
        'Catálogo do jogo',
        'Crie e edite atividades, quizzes, missões, conquistas, estruturas e missões de comunidade. Itens já usados por jogadores não são apagados: desative-os.',
    ],
    promocao: [
        'Códigos promocionais',
        'Crie campanhas institucionais com recompensas em XP, créditos verdes ou pontos nos indicadores (RN98).',
    ],
    comunidade: [
        'Comunidades',
        'Acompanhe as comunidades ativas e encerre comunidades quando necessário para moderação.',
    ],
    conta: [
        'Contas e Perfis',
        'Gerencie jogadores, suporte de contas e níveis administrativos (RN99 / RN102) conforme as permissões do seu perfil.',
    ],
    auditoria: [
        'Auditoria do banco',
        'Consulta somente leitura e exportação CSV do log imutável de mudanças do banco de dados (RN101).',
    ],
};

function iniciarMenuLateral() {
    const side = document.getElementById('a-sidebar');
    const overlay = document.getElementById('a-sidebar-overlay');
    const abrir = document.getElementById('a-menu-abrir');
    const fechar = document.getElementById('a-menu-fechar');
    if (!side || !abrir) return;
    const alternar = (aberto) => {
        side.classList.toggle('is-open', aberto);
        overlay?.classList.toggle('hidden', !aberto);
        abrir.setAttribute('aria-expanded', String(aberto));
    };
    abrir.addEventListener('click', () => alternar(!side.classList.contains('is-open')));
    fechar?.addEventListener('click', () => alternar(false));
    overlay?.addEventListener('click', () => alternar(false));
    side.addEventListener('click', (e) => { if (e.target.closest('[role="tab"]')) alternar(false); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && side.classList.contains('is-open')) alternar(false); });
}

function iniciarNavegacaoAdmin(nav, conteudoApi) {
    if (!nav) return;
    const tabs = [...nav.querySelectorAll('[role="tab"]')];
    if (!tabs.length) return;
    const paineis = [...document.querySelectorAll('.a-main > .tab-panel')];
    const tabConteudo = document.getElementById('tab-conteudo');
    const titConteudo = document.getElementById('conteudo-titulo');
    const subConteudo = document.getElementById('conteudo-subtitulo');

    const ativar = (tab, { foco = false, som = false, atualizarUrl = true } = {}) => {
        if (!tab) return;
        const jaAtivo = tab.getAttribute('aria-selected') === 'true';
        tabs.forEach((t) => {
            const ativo = t === tab;
            t.setAttribute('aria-selected', String(ativo));
            t.tabIndex = ativo ? 0 : -1;
        });
        const painelId = tab.getAttribute('aria-controls');
        paineis.forEach((p) => { p.hidden = p.id !== painelId; });

        const modo = tab.dataset.conteudoModo;
        if (modo && tabConteudo) {
            tabConteudo.setAttribute('aria-labelledby', tab.id);
            const [tit, sub] = TEXTOS_CONTEUDO[modo] || TEXTOS_CONTEUDO.catalogo;
            if (titConteudo) titConteudo.textContent = tit;
            if (subConteudo) subConteudo.textContent = sub;
            conteudoApi?.abrir(modo);
        }

        if (atualizarUrl && tab.dataset.aba) {
            const url = new URL(location.href);
            if (url.searchParams.get('aba') !== tab.dataset.aba) {
                url.searchParams.set('aba', tab.dataset.aba);
                history.replaceState(null, '', `${url.pathname}?${url.searchParams.toString()}`);
            }
        }
        if (som && !jaAtivo) tocar('clique');
        if (foco) tab.focus();
    };

    nav.addEventListener('click', (e) => {
        const tab = e.target.closest('[role="tab"]');
        if (tab) ativar(tab, { som: true });
    });

    nav.addEventListener('keydown', (e) => {
        const visiveis = tabs.filter((t) => !t.hidden);
        const i = visiveis.indexOf(document.activeElement);
        if (i < 0) return;
        const mapa = {
            ArrowDown: i + 1,
            ArrowUp: i - 1,
            ArrowRight: i + 1,
            ArrowLeft: i - 1,
            Home: 0,
            End: visiveis.length - 1,
        };
        if (!(e.key in mapa)) return;
        e.preventDefault();
        ativar(visiveis[(mapa[e.key] + visiveis.length) % visiveis.length], { foco: true, som: true });
    });

    const inicial = tabs.find((t) => t.getAttribute('aria-selected') === 'true') || tabs[0];
    ativar(inicial, { atualizarUrl: false });
}

hydrateIcons();
iniciarMenuLateral();
const conteudoApi = initConteudoAdmin(document.getElementById('conteudo-app'));
iniciarNavegacaoAdmin(document.getElementById('admin-tabs'), conteudoApi);
initEstatisticasAdmin(document.getElementById('estatisticas-app'), hydrateIcons);
iniciarSeletorAtividades();

/* Listas das abas Contestações, Denúncias e Parceiros: busca, filtros, "Mostrar" e páginas (no navegador) */
const listaContestacoes = document.getElementById('lista-contestacoes');
listaPaginada({
    lista: listaContestacoes, itens: '.admin-item', chave: 'contestacoes', rotulo: ['contestação', 'contestações'],
    filtros: [['todos', 'Todas'], ['aberta', 'Abertas'], ['aceita', 'Aceitas'], ['negada', 'Negadas']],
    filtroInicial: listaContestacoes?.dataset.filtroInicial, buscaDica: 'Buscar por atividade ou jogador',
});
listaPaginada({
    lista: document.getElementById('lista-pendentes'), itens: '.admin-item', chave: 'pendentes', rotulo: ['registro', 'registros'],
    filtros: [['todos', 'Todos'], ['outros', 'De outros jogadores'], ['ia', 'Analisados pela IA'], ['meus', 'Meus']], buscaDica: 'Buscar por atividade ou jogador',
});
const listaDenuncias = document.getElementById('lista-denuncias');
listaPaginada({
    lista: listaDenuncias, itens: '.admin-item', chave: 'denuncias', rotulo: ['denúncia', 'denúncias'],
    filtros: [['todos', 'Todas'], ['aberta', 'Abertas'], ['ia_resolvida', 'Resolvidas pela IA'], ['amigos', 'Conversas entre amigos'], ['comunidade', 'Comunidades']],
    filtroInicial: listaDenuncias?.dataset.filtroInicial, buscaDica: 'Buscar por autor, denunciante ou comunidade',
});
const listaParceiros = document.getElementById('lista-parceiros');
listaPaginada({
    lista: listaParceiros, itens: '.admin-item', chave: 'parceiros', rotulo: ['parceiro', 'parceiros'],
    filtros: [['todos', 'Todos'], ['pendente', 'Pendentes'], ['aprovado', 'Aprovados'], ['bloqueado', 'Bloqueados'], ['recusado', 'Recusados']],
    filtroInicial: listaParceiros?.dataset.filtroInicial, buscaDica: 'Buscar por nome, responsável ou e-mail',
});

/* Histórico de decisões: paginado no servidor (pode crescer sem limite) */
const decisoes = { raiz: document.getElementById('decisoes-app'), dados: null, carregando: false };

function tabelaDecisoes(itens) {
    return `<div class="table-scroll"><table class="data-table">
        <thead><tr><th scope="col">Atividade</th><th scope="col">Jogador</th><th scope="col">Situação</th><th scope="col">Decidido por</th><th scope="col">Quando</th><th scope="col">Motivo</th></tr></thead>
        <tbody>${itens.map((d) => `<tr>
            <td>${escapeHTML(d.atividade)}</td><td>${escapeHTML(d.jogador)}</td>
            <td><span class="tag ${d.status === 'aprovado' ? 'tag-success' : 'tag-error'}">${d.status === 'aprovado' ? 'Aprovado' : 'Rejeitado'}</span></td>
            <td>${d.admin ? escapeHTML(d.admin) : d.ia ? `<span class="tag ${d.status === 'aprovado' ? 'tag-success' : 'tag-error'}">IA</span>` : `<span class="muted">${d.status === 'aprovado' ? 'Automático' : 'Teste (sem administrador)'}</span>`}</td>
            <td>${d.quando || '—'}</td><td class="wrap">${escapeHTML(d.motivo || '')}</td></tr>`).join('')}</tbody></table></div>`;
}

function desenharDecisoes() {
    const d = decisoes.dados;
    if (!d.contagem.todas) { decisoes.raiz.innerHTML = '<p class="empty-state">Nenhuma decisão ainda.</p>'; return; }
    decisoes.raiz.innerHTML = `
        <div class="lista-barra">
            <div class="lista-filtros" role="group" aria-label="Filtrar">${pilulas([['todas', 'Todas'], ['aprovado', 'Aprovadas'], ['rejeitado', 'Rejeitadas']], d.situacao, d.contagem)}</div>
            ${seletorTamanho(d.porPagina)}
        </div>
        ${d.itens.length ? tabelaDecisoes(d.itens) : '<p class="empty-state">Nenhuma decisão com esta situação.</p>'}
        <nav class="pager" aria-label="Páginas"></nav>`;
    pager(decisoes.raiz.querySelector('.pager'), d.pagina, d.total, d.porPagina, 'decisões');
}

async function carregarDecisoes(pagina, situacao = decisoes.dados.situacao) {
    if (decisoes.carregando) return;
    decisoes.carregando = true;
    decisoes.raiz.setAttribute('aria-busy', 'true');
    try {
        const q = new URLSearchParams({ acao: 'decisoes', pagina, por: porPagina(), situacao });
        const r = await (await fetch(`api/admin-listas.php?${q}`, { headers: { Accept: 'application/json' } })).json();
        if (!r.ok) throw new Error(r.erro);
        decisoes.dados = r;
        desenharDecisoes();
    } catch {
        decisoes.raiz.insertAdjacentHTML('afterbegin', '<p class="alert alert-error" role="alert">Não foi possível carregar o histórico. Tente de novo.</p>');
    } finally {
        decisoes.carregando = false;
        decisoes.raiz.removeAttribute('aria-busy');
    }
}

if (decisoes.raiz) {
    decisoes.dados = JSON.parse(document.getElementById('decisoes-dados').textContent);
    if (decisoes.dados.porPagina !== porPagina()) carregarDecisoes(1); else desenharDecisoes();
    decisoes.raiz.addEventListener('click', (e) => {
        const pag = e.target.closest('[data-pagina]');
        if (pag) { carregarDecisoes(Number(pag.dataset.pagina)); return; }
        const f = e.target.closest('[data-lista-filtro]');
        if (f) carregarDecisoes(1, f.dataset.listaFiltro);
    });
    decisoes.raiz.addEventListener('change', (e) => {
        if (!e.target.matches('[data-lista-tamanho]')) return;
        definirPorPagina(Number(e.target.value));
        carregarDecisoes(1);
    });
}

// Formulários com confirmação (ex.: bloquear conta na aba Denúncias) e botões com confirmação própria (aba Parceiros)
document.addEventListener('submit', (e) => {
    const msg = e.submitter?.dataset?.confirmaBotao || e.target.dataset?.confirma;
    if (msg && !window.confirm(msg)) e.preventDefault();
});

/* Novidades da administração (RN85): a cada 10 s com a aba visível, selos atualizados e faixa
   "Há novidades" quando a fila, as denúncias, os parceiros ou o conteúdo mudam (sem recarregar sozinho). */
const NOVIDADES_MS = 10000;
let versaoAdmin = document.querySelector('meta[name="admin-sincronia"]')?.content || '';
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

function selo(id, n) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = n;
    el.classList.toggle('hidden', id === 'badge-parceiros' && !n);
    el.classList.toggle('is-zero', id !== 'badge-parceiros' && !n);
}

function marcarSincronizado() {
    const badge = document.getElementById('a-sync-status');
    const txt = document.getElementById('a-sync-texto');
    if (!txt || badge?.classList.contains('has-news')) return;
    const h = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    txt.textContent = `Atualizado agora · ${h}`;
}

async function buscarNovidades() {
    if (document.hidden) return;
    try {
        const r = await (await fetch('api/admin-sincronia.php', { headers: { Accept: 'application/json' } })).json();
        if (!r.ok) return;
        if ('contestacoes' in r || 'pendentes' in r) selo('badge-aprovacoes', (r.contestacoes ?? 0) + (r.pendentes ?? 0));
        if ('denuncias' in r) selo('badge-denuncias', r.denuncias ?? 0);
        if ('parceiros' in r) selo('badge-parceiros', r.parceiros ?? 0);
        if (r.versao !== versaoAdmin) {
            versaoAdmin = r.versao;
            limparCacheEstatisticas();
            const partes = [];
            if ('contestacoes' in r) partes.push(plural(r.contestacoes, 'contestação aberta', 'contestações abertas'));
            if ('pendentes' in r) partes.push(plural(r.pendentes, 'registro aguardando', 'registros aguardando'));
            if ('denuncias' in r) partes.push(plural(r.denuncias, 'denúncia', 'denúncias'));
            if ('parceiros' in r) partes.push(plural(r.parceiros, 'pendência de parceiros', 'pendências de parceiros'));
            const txtEl = document.getElementById('admin-novidades-texto');
            const boxEl = document.getElementById('admin-novidades');
            if (txtEl) txtEl.textContent = partes.length ? `Há novidades: ${partes.join(', ')}.` : 'Há novidades na administração.';
            if (boxEl) boxEl.hidden = false;
            const badge = document.getElementById('a-sync-status');
            const syncTxt = document.getElementById('a-sync-texto');
            if (badge) badge.classList.add('has-news');
            if (syncTxt) syncTxt.textContent = 'Há novidades · atualizar';
        } else {
            marcarSincronizado();
        }
    } catch { /* tenta de novo no próximo ciclo */ }
}

document.getElementById('admin-novidades-atualizar')?.addEventListener('click', () => {
    const aba = document.querySelector('#admin-tabs [aria-selected="true"]')?.dataset?.aba;
    location.href = `admin.php${aba ? `?aba=${encodeURIComponent(aba)}` : ''}`;
});
setInterval(buscarNovidades, NOVIDADES_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) buscarNovidades(); });
