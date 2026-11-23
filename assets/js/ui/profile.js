/**
 * Aba Meu Progresso (ids "perfil"): faixa do jogador e subabas Resumo (impacto, comparação, estatísticas,
 * últimas conquistas), Evolução e Aprendizado (RN84, carregados ao abrir a aba; perguntas para rever em
 * páginas), Extrato (10/20/30 por página, filtros, por dia).
 * Minha conta (dados, edição, troca de senha e tutorial) fica numa janela aberta pelo avatar do topo.
 */
import { CATEGORIA_POR_ID } from '../data/catalog.js';
import { state, atualizarPerfil, trocarSenha, chamarApi, faixaNivel, acaoConta, resgatarCodigoPromocional } from '../state.js';
import { icon } from './icons.js';
import { $, escapeHTML, pager, toast } from './dom.js';
import { barras, colunas, linhas, empilhada, anel, semanaBR, COR_CATEGORIA } from './graficos.js';
import { initTabs } from './tabs.js';
import { seletorTamanho, tamanhoGuardado, guardarTamanho } from './lista-admin.js';
import { copiarCodigo, rotuloDia, confirmar, AVATAR_ICONES, AVATAR_CORES, classeAvatar, conteudoAvatar } from './friends.js';
import { medalha, ultimasConquistas, abrirDetalhes } from './achievements.js';
import { tocar, aplicarPreferencias, volumePadrao, VOLUME_CANAL_PADRAO } from '../som.js';

const ORIGEM = { atividade: 'Atividade', quiz: 'Quiz', conquista: 'Conquista', missao: 'Missão', construcao: 'Construção', renda: 'Renda do comércio', comunidade: 'Comunidade', 'missao comunidade': 'Missão da comunidade', 'codigo promocional': 'Código promocional', 'ajuste admin': 'Ajuste da administração' };
const numero = (n) => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
const numeroImpacto = (n) => Number(n ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
const dataBR = (iso) => new Date(iso.length === 10 ? `${iso}T00:00:00` : iso).toLocaleDateString('pt-BR');
let periodoImpacto = 'tudo';
const ROTULO_PERIODO_IMPACTO = {
    tudo: 'desde o início',
    dias30: 'nos últimos 30 dias',
    semana: 'nesta semana',
};
const ESTAGIO_NOME = {
    1: 'Degradado',
    2: 'Em recuperação',
    3: 'Sustentável',
    4: 'Modelo',
};

function renderConta(c) {
    // Privacidade e sessão (RN93)
    document.querySelectorAll('[data-privacidade]').forEach((x) => { x.checked = !!c.privacidade?.[x.dataset.privacidade]; });
    $('conta-ultima-entrada').textContent = c.ultimaEntrada
        ? `Entrada anterior: ${new Date(c.ultimaEntrada).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })}.`
        : 'Encerre as sessões abertas em outros celulares e computadores; esta continua aberta.';
    $('conta-desativar-abrir').hidden = !!c.administrador;
    const linhas = [
        ['Nome', escapeHTML(c.nome)], ['Apelido', escapeHTML(c.apelido)],
        ['E-mail', `${escapeHTML(c.email)}<small>O e-mail não pode ser alterado.</small>`],
        ['Código público', escapeHTML(c.codigoPublico)], ['Cidade', escapeHTML(c.cidade)],
        ['Membro desde', c.cadastro ? dataBR(c.cadastro) : '—'],
    ];
    $('profile-data').innerHTML = linhas.map(([k, v]) => `<dt>${k}</dt><dd>${v}</dd>`).join('');
}

/** Faixa do topo: jogador, código, XP do nível e 4 números. */
function renderFaixa(p) {
    const j = state.jogador;
    const { min, proximo } = faixaNivel();
    const pct = Math.max(0, Math.min(100, ((j.xpAtual - min) / Math.max(1, proximo - min)) * 100));
    const av = p.conta.avatar;
    $('pf-avatar').className = `friend-avatar friend-avatar-lg ${av ? classeAvatar(j, av) : ''}`;
    $('pf-avatar').innerHTML = conteudoAvatar(j.apelido, av);
    // Avatar do topo: o ícone escolhido na cor escolhida (padrão: folha)
    const face = document.querySelector('#player-avatar .avatar-face');
    face.className = `avatar-face ${av ? `av-c-${av.cor}` : ''}`;
    face.innerHTML = icon(av?.icone || 'leaf');
    $('pf-nome').textContent = j.apelido;
    $('pf-sub').textContent = `Nível ${j.nivel} · ${j.cidade}${p.conta.cadastro ? ` · jogando desde ${dataBR(p.conta.cadastro)}` : ''}`;
    $('pf-codigo').textContent = j.codigoPublico || '';
    $('pf-xp-nivel').textContent = `${numero(j.xpAtual)} XP`;
    $('pf-xp-falta').textContent = `faltam ${numero(Math.max(0, proximo - j.xpAtual))} XP para o nível ${j.nivel + 1}`;
    $('pf-xp-fill').style.width = `${pct}%`;
    $('pf-xp-track').setAttribute('aria-valuenow', String(Math.round(pct)));
    $('pf-acoes').textContent = numero(p.estatisticas.registros.aprovado);
    $('pf-xp').textContent = numero(j.xpAtual);
    $('pf-creditos').textContent = numero(j.creditosVerde);
    $('pf-conquistas').textContent = `${p.estatisticas.conquistas}/${p.estatisticas.totalConquistas}`;
}

/** As 3 conquistas mais recentes; clicar abre a mesma janela da aba Conquistas. */
function renderUltimasConquistas() {
    const ultimas = ultimasConquistas();
    $('profile-ultimas').innerHTML = ultimas.length
        ? ultimas.map((c) => `<button type="button" class="profile-ultima" data-conquista="${c.id}">${medalha(c, 'medal-xs')}
            <span><strong>${escapeHTML(c.nome)}</strong><small>${c.obtidaEm ? `Conquistada em ${dataBR(c.obtidaEm)}` : ''}</small></span></button>`).join('')
        : '<p class="muted">Sua primeira medalha está a uma ação de distância!</p>';
}

function renderResumoImpacto(resumo, per) {
    const box = $('profile-impact-summary');
    if (!box) return;
    if (!resumo?.periodos) {
        box.innerHTML = '';
        return;
    }
    const rp = resumo.periodos[per] || resumo.periodos.tudo;
    const totalCat = resumo.totalAtividadesCatalogo || 15;
    const rotPer = ROTULO_PERIODO_IMPACTO[per] || 'no período';

    const mistura = [
        { rotulo: 'Sem foto', valor: rp.semFoto || 0, cor: '#90A4AE' },
        { rotulo: 'Com foto (IA)', valor: rp.comFoto || 0, cor: 'var(--primary-color)' },
        { rotulo: 'Parceiro (150%)', valor: rp.parceiro || 0, cor: 'var(--accent-gold)' },
    ];
    const barraProvas = rp.acoes > 0
        ? empilhada(mistura)
        : '<div class="impact-proof-empty">Nenhuma ação aprovada neste período ainda.</div>';

    const com = resumo.comunidade;
    const blocoComunidade = com
        ? `<div class="impact-kpi">
            <span class="impact-kpi-label">${icon('users')} Impacto coletivo · ${escapeHTML(com.nome)}</span>
            <strong class="impact-kpi-val">+${numero(com.pontos)} pts</strong>
            <span class="impact-kpi-sub">${com.acoes} ${com.acoes === 1 ? 'ação entregue' : 'ações entregues'} à comunidade</span>
        </div>`
        : `<div class="impact-kpi">
            <span class="impact-kpi-label">${icon('users')} Impacto coletivo</span>
            <strong class="impact-kpi-val">Sem comunidade</strong>
            <span class="impact-kpi-sub"><button type="button" class="link-btn" data-impacto-ir="t-comunidade">Entrar numa comunidade</button> para somar impacto em grupo</span>
        </div>`;

    box.innerHTML = `
        <div class="impact-kpi-grid">
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('check')} Ações aprovadas ${rotPer}</span>
                <strong class="impact-kpi-val">${numero(rp.acoes)}</strong>
                <span class="impact-kpi-sub">${rp.areasAtivas} de 5 áreas ativas · ${rp.habitosPraticados} de ${totalCat} hábitos praticados</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('leaf')} Recuperação gerada na cidade</span>
                <strong class="impact-kpi-val">+${numero(rp.pontosCidade)} pts</strong>
                <span class="impact-kpi-sub">${resumo.diasComAcao} ${resumo.diasComAcao === 1 ? 'dia ativo' : 'dias ativos'} · sequência: ${resumo.sequenciaAtual}d (recorde ${resumo.melhorSequencia}d)</span>
            </div>
            <div class="impact-kpi impact-kpi-proof">
                <span class="impact-kpi-label">${icon('image')} Confiabilidade das comprovações</span>
                ${barraProvas}
                <div class="impact-proof-legend">
                    <span><i style="background:var(--accent-gold)"></i>Parceiro: <strong>${rp.parceiro}</strong>${resumo.parceirosDistintos > 0 ? ` (${resumo.parceirosDistintos} estab.)` : ''}</span>
                    <span><i style="background:var(--primary-color)"></i>Foto IA: <strong>${rp.comFoto}</strong></span>
                    <span><i style="background:#90A4AE"></i>Rápido: <strong>${rp.semFoto}</strong></span>
                </div>
            </div>
            ${blocoComunidade}
        </div>`;
}

function renderImpacto(lista, resumo = state.perfil?.impactoResumo) {
    renderResumoImpacto(resumo, periodoImpacto);
    $('profile-impact').innerHTML = (lista || []).map((i) => {
        const cat = CATEGORIA_POR_ID[i.categoria] || { nome: i.categoria, curto: i.categoria, icone: 'leaf' };
        const dadosPer = i.periodos?.[periodoImpacto] || {
            atividades: i.atividades ?? 0,
            pontosRegiao: i.pontosRegiao ?? 0,
            semFoto: i.semFoto ?? 0,
            comFoto: i.comFoto ?? 0,
            parceiro: i.parceiro ?? 0,
            unidades: i.unidades || (i.unidade ? [{ unidade: i.unidade, quantidade: i.quantidade ?? 0, acoes: i.atividades ?? 0 }] : []),
            habitos: i.habitos || [],
        };

        const unidades = dadosPer.unidades?.length
            ? dadosPer.unidades
            : [{ unidade: i.unidade || 'sem estimativa definida', quantidade: i.quantidade ?? 0, acoes: dadosPer.atividades }];
        const multiUnidade = unidades.length > 1;

        const unidadesHtml = `<ul class="impact-unit-list ${multiUnidade ? 'is-multi' : ''}">
            ${unidades.map((u) => `<li class="impact-unit-row ${u.quantidade > 0 ? '' : 'is-zero'}">
                <div class="impact-unit-main">
                    <strong>${numeroImpacto(u.quantidade)}</strong>
                    <span class="impact-unit">${escapeHTML(u.unidade)}</span>
                </div>
                ${multiUnidade ? `<span class="impact-unit-actions">${u.acoes} ${u.acoes === 1 ? 'ação' : 'ações'}</span>` : ''}
            </li>`).join('')}
        </ul>`;

        const habitos = dadosPer.habitos || [];
        const praticados = habitos.filter((h) => h.acoes > 0);
        const naoPraticados = habitos.filter((h) => h.acoes === 0 && h.ativa);
        const maxAcoes = Math.max(1, ...praticados.map((h) => h.acoes));

        const praticadosHtml = praticados.length
            ? `<div class="impact-habits">
                <span class="impact-habits-title">Hábitos praticados (${praticados.length})</span>
                <ul class="impact-habits-list">
                    ${praticados.map((h) => {
                        const pct = Math.max(8, Math.round((h.acoes / maxAcoes) * 100));
                        const badges = [
                            h.parceiro > 0 ? `<span class="impact-verify-badge is-partner" title="${h.parceiro} confirmada(s) por parceiro">${icon('flag')}${h.parceiro}</span>` : '',
                            h.comFoto > 0 ? `<span class="impact-verify-badge is-photo" title="${h.comFoto} com foto validada por IA">${icon('image')}${h.comFoto}</span>` : '',
                        ].filter(Boolean).join('');
                        return `<li class="impact-habit-item">
                            <div class="impact-habit-top">
                                <span class="impact-habit-name">${escapeHTML(h.nome)}</span>
                                <span class="impact-habit-meta">${badges}<strong>${h.acoes}×</strong></span>
                            </div>
                            <div class="impact-habit-bar"><span class="fill-${escapeHTML(i.categoria)}" style="width:${pct}%"></span></div>
                            <div class="impact-habit-foot">
                                <span>${h.unidade ? `${numeroImpacto(h.quantidade)} ${escapeHTML(h.unidade)}` : 'Sem estimativa física'}</span>
                                ${h.pontos > 0 ? `<span class="impact-habit-pts">+${h.pontos} pts</span>` : ''}
                            </div>
                        </li>`;
                    }).join('')}
                </ul>
            </div>`
            : `<p class="impact-empty-habits">Nenhum hábito desta área registrado ${ROTULO_PERIODO_IMPACTO[periodoImpacto] || 'no período'}.</p>`;

        const disponiveisHtml = naoPraticados.length
            ? `<details class="impact-unused">
                <summary>${praticados.length ? `Mais ${naoPraticados.length} ${naoPraticados.length === 1 ? 'hábito disponível' : 'hábitos disponíveis'} na área` : `Ver ${naoPraticados.length} hábitos disponíveis para começar`}</summary>
                <ul>
                    ${naoPraticados.map((h) => `<li>
                        <span>${escapeHTML(h.nome)}</span>
                        <small>${h.padrao && h.unidade ? `+${numeroImpacto(h.padrao)} ${escapeHTML(h.unidade)}/ação` : ''}</small>
                    </li>`).join('')}
                </ul>
                <button type="button" class="link-btn impact-go-act" data-impacto-ir="t-missoes">Ir para Agir hoje →</button>
            </details>`
            : '';

        const valInd = i.valorIndicador ?? 0;
        const estInd = i.estagioIndicador ?? 1;

        return `<article class="impact-area-card cat-top-${escapeHTML(i.categoria)}">
            <header class="impact-area-head">
                <span class="impact-icon cat-${escapeHTML(i.categoria)}">${icon(cat.icone)}</span>
                <div class="impact-area-title">
                    <h4>${escapeHTML(cat.nome)}</h4>
                    <span class="impact-count">${dadosPer.atividades} ${dadosPer.atividades === 1 ? 'atividade aprovada' : 'atividades aprovadas'}${dadosPer.pontosRegiao > 0 ? ` · <strong>+${dadosPer.pontosRegiao} pts</strong> na região` : ''}</span>
                </div>
                <span class="impact-region-pill" title="Saúde atual desta região na sua cidade: Estágio ${estInd} (${ESTAGIO_NOME[estInd] || ''})">
                    ${valInd}/100 · Est. ${estInd}
                </span>
            </header>
            ${unidadesHtml}
            ${praticadosHtml}
            ${disponiveisHtml}
        </article>`;
    }).join('');
}

function renderEstatisticas(e) {
    const itens = [
        ['Nível do jogador', e.nivelJogador],
        ['Nível da cidade', e.nivelCidade],
        ['Registros aprovados', e.registros.aprovado],
        ['Registros pendentes', e.registros.pendente],
        ['Registros rejeitados', e.registros.rejeitado],
        ['Registros com foto aprovados', e.registrosEvidencia],
        ['Quizzes concluídos', e.quizzesConcluidos],
        ['Quizzes perfeitos', e.quizzesPerfeitos],
        ['Conquistas', `${e.conquistas} de ${e.totalConquistas}`],
        ['Estruturas construídas', e.construcoes],
    ];
    $('profile-stats').innerHTML = itens.map(([k, v]) => `<div class="stat-tile"><strong>${escapeHTML(String(v))}</strong><span>${k}</span></div>`).join('');
}

/* ---------------------------------------------------------------------------
   Extrato: resumo da semana e do mês, filtros, lista por dia e 10 por página
   --------------------------------------------------------------------------- */
// Quantos itens por página no Extrato e nas perguntas para rever (escolha guardada no navegador)
const TAMANHOS_PERFIL = [10, 20, 30];
const CHAVE_TAMANHO = 'ecocity-perfil-por-pagina';
const tamanhoPerfil = () => tamanhoGuardado(CHAVE_TAMANHO, TAMANHOS_PERFIL, 10);
const ext = { pagina: 1, tipo: 'todos', origem: 'todas', desatualizado: false };
const VAZIO = {
    todos: 'Nenhum lançamento ainda. Registre uma ação ou faça um quiz para ganhar XP e créditos.',
    ganhos: 'Nenhum ganho com este filtro.',
    gastos: 'Nenhum gasto com este filtro. Os gastos são construções e a criação de comunidade.',
};
const fichaValor = (n, tipo) => {
    if (!n) return '';
    const sinal = n > 0 ? '+' : '−';
    return tipo === 'xp'
        ? `<span class="reward-chip chip-xp">${icon('star')}${sinal}${numero(Math.abs(n))} XP</span>`
        : `<span class="reward-chip chip-credit${n < 0 ? ' is-negative' : ''}">${icon('coin')}${sinal}${numero(Math.abs(n))}</span>`;
};

function renderResumoExtrato(r) {
    const bloco = (titulo, x) => `<div class="statement-period"><h4>${titulo}</h4><div class="reward-chips">
        <span class="reward-chip chip-xp">${icon('star')}+${numero(x.xp)} XP</span>
        <span class="reward-chip chip-credit">${icon('coin')}+${numero(x.ganhos)} ganhos</span>
        <span class="reward-chip chip-credit is-negative">${icon('coin')}−${numero(x.gastos)} gastos</span></div></div>`;
    $('profile-extrato-resumo').innerHTML = bloco('Esta semana', r.semana) + bloco('Este mês', r.mes);
}

function renderExtrato(e) {
    ext.pagina = e.pagina;
    if (!e.itens.length) {
        $('profile-statement').innerHTML = `<p class="empty-state">${ext.origem === 'todas' ? VAZIO[ext.tipo] : 'Nenhum lançamento com este filtro.'}</p>`;
    } else {
        const dias = [];
        e.itens.forEach((x) => {
            const dia = new Date(x.data).toDateString();
            if (dias.at(-1)?.dia !== dia) dias.push({ dia, data: x.data, itens: [] });
            dias.at(-1).itens.push(x);
        });
        $('profile-statement').innerHTML = dias.map((d) => `<h4 class="statement-day">${rotuloDia(d.data)}</h4>
            <ul class="statement-list">${d.itens.map((x) => `<li class="statement-row">
                <span class="statement-time">${new Date(x.data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span>
                <span class="statement-desc"><span class="tag tag-neutral">${ORIGEM[x.origem] || escapeHTML(x.origem)}</span>${escapeHTML(x.referencia || '')}</span>
                <span class="statement-values">${fichaValor(x.xp, 'xp')}${fichaValor(x.credito, 'credito')}</span></li>`).join('')}</ul>`).join('');
    }
    pager($('profile-statement-pager'), e.pagina, e.total, e.porPagina, e.total === 1 ? 'lançamento' : 'lançamentos');
}

async function carregarExtrato(pagina = 1) {
    try {
        const { extrato } = await chamarApi('api/perfil.php', { acao: 'extrato', pagina, tipo: ext.tipo, origem: ext.origem, porPagina: tamanhoPerfil() });
        ext.desatualizado = false;
        renderExtrato(extrato);
    } catch (err) {
        $('profile-statement').innerHTML = `<p class="field-error">${escapeHTML(err.message)}</p>`;
    }
}

function renderPromocoes(lista = []) {
    const wrap = $('promo-resgates');
    if (!lista.length) {
        wrap.hidden = true;
        wrap.innerHTML = '';
        return;
    }
    const chipsInds = (inds = {}) => {
        const pares = Object.entries(inds).filter(([, v]) => Number(v) > 0);
        if (!pares.length) return '';
        const vals = pares.map(([, v]) => Number(v));
        if (pares.length === 5 && Math.min(...vals) === Math.max(...vals)) {
            return `<span class="reward-chip chip-area cat-nature">${icon('leaf')}+${vals[0]} nos 5 indicadores</span>`;
        }
        return pares.map(([k, v]) => {
            const cat = CATEGORIA_POR_ID[k];
            return `<span class="reward-chip chip-area cat-${escapeHTML(k)}">+${numero(v)} ${escapeHTML(cat?.curto || k)}</span>`;
        }).join('');
    };
    wrap.hidden = false;
    wrap.innerHTML = `<h4>Códigos já resgatados</h4><ul class="promo-resgates-list">${lista.map((r) => `<li>
        <div class="promo-resgate-topo"><span><code>${escapeHTML(r.codigo)}</code> · <strong>${escapeHTML(r.nome)}</strong></span><small>${dataBR(r.resgatadoEm)}</small></div>
        <div class="reward-chips">${fichaValor(r.xp, 'xp')}${fichaValor(r.credito, 'credito')}${chipsInds(r.indicadores)}</div>
    </li>`).join('')}</ul>`;
}

export function renderProfile() {
    const p = state.perfil;
    renderFaixa(p);
    renderConta(p.conta);
    renderSom();
    renderImpacto(p.impacto, p.impactoResumo);
    renderEstatisticas(p.estatisticas);
    renderPromocoes(p.codigosPromocionais || []);
    renderUltimasConquistas();
    renderResumoExtrato(p.extratoResumo);
    // O servidor manda a 1ª página sem filtro; com filtro ou noutra página, busca de novo
    if (ext.pagina === 1 && ext.tipo === 'todos' && ext.origem === 'todas' && tamanhoPerfil() === p.extrato.porPagina) renderExtrato(p.extrato);
    else if (!$('pp-extrato').hidden && !$('tab-perfil').hidden) carregarExtrato(ext.pagina);
    else ext.desatualizado = true;
    evolucaoCarregadaEm = 0;   // dados mudaram: recarrega a evolução na próxima visita à aba
    if (!$('tab-perfil').hidden) carregarEvolucao();
}

/* ---------------------------------------------------------------------------
   Minha evolução, Meu aprendizado e comparação anônima (RN84), de api/perfil.php acao=evolucao
   --------------------------------------------------------------------------- */
let evolucaoCarregadaEm = 0;
let ultimaEvolucao = null;
let unidadeEvolucaoSel = null;
let modoGraficoImpacto = 'acumulado'; // 'acumulado' | 'semanal'
let filtroErradasCat = 'todas';
let erradasPagina = 1;

const nomeCurto = (k) => CATEGORIA_POR_ID[k]?.curto || k;
const nomeCompletoCat = (k) => CATEGORIA_POR_ID[k]?.nome || k;
const dias = (n) => `${n} ${n === 1 ? 'dia' : 'dias'}`;

function deltaTag(antes, agora, sufixo = '') {
    const diff = +(agora - antes).toFixed(2);
    if (antes === 0 && agora > 0) return `<span class="evo-delta is-up">▲ novo (+${numeroImpacto(agora)}${sufixo})</span>`;
    if (diff > 0) return `<span class="evo-delta is-up">▲ +${numeroImpacto(diff)}${sufixo}</span>`;
    if (diff < 0) return `<span class="evo-delta is-down">▼ −${numeroImpacto(Math.abs(diff))}${sufixo}</span>`;
    return `<span class="evo-delta is-flat">= manteve</span>`;
}

function renderGraficoImpactoSustentavel(ev) {
    const lista = ev.impactoSemanal || [];
    if (!lista.length) return '<p class="muted">Nenhuma unidade de impacto cadastrada.</p>';
    if (!unidadeEvolucaoSel || !lista.some((u) => u.unidade === unidadeEvolucaoSel)) {
        const comValor = lista.find((u) => u.totalGeral > 0) || lista[0];
        unidadeEvolucaoSel = comValor.unidade;
    }
    const atual = lista.find((u) => u.unidade === unidadeEvolucaoSel) || lista[0];
    const rot = ev.semanas.map((s) => semanaBR(s.semana));
    const cor = COR_CATEGORIA[atual.categoria] || 'var(--primary-color)';

    const pilulasUnidades = lista.map((u) => {
        const ativo = u.unidade === atual.unidade;
        return `<button type="button" class="chip ${ativo ? 'is-active' : ''}" data-evo-unidade="${escapeHTML(u.unidade)}" aria-pressed="${ativo}">
            <span class="cat-dot cat-${escapeHTML(u.categoria)}"></span>
            ${escapeHTML(u.unidade)}
            <span class="chip-count">${numeroImpacto(u.totalGeral)}</span>
        </button>`;
    }).join('');

    const graficoHtml = modoGraficoImpacto === 'acumulado'
        ? linhas(rot, [{ nome: `${atual.unidade} (acumulado)`, cor, valores: atual.acumulado }], {
            max: null,
            sufixo: '',
            titulo: `Evolução acumulada de ${atual.unidade}`,
            vazio: 'Registre ações desta área para ver a curva de evolução.',
        })
        : colunas(rot, atual.semanal, {
            cor,
            titulo: `Impacto por semana em ${atual.unidade}`,
        });

    return `
        <div class="evo-impacto-controls">
            <div class="registros-filtros evo-unidades-nav" role="group" aria-label="Escolher grandeza ecológica">
                ${pilulasUnidades}
            </div>
            <div class="registros-filtros evo-modo-nav" role="group" aria-label="Modo do gráfico">
                <button type="button" class="chip ${modoGraficoImpacto === 'acumulado' ? 'is-active' : ''}" data-evo-modo="acumulado" aria-pressed="${modoGraficoImpacto === 'acumulado'}">Curva acumulada</button>
                <button type="button" class="chip ${modoGraficoImpacto === 'semanal' ? 'is-active' : ''}" data-evo-modo="semanal" aria-pressed="${modoGraficoImpacto === 'semanal'}">Por semana</button>
            </div>
        </div>
        <div class="evo-impacto-meta">
            <span>Área: <strong class="tag cat-${escapeHTML(atual.categoria)}">${escapeHTML(nomeCompletoCat(atual.categoria))}</strong></span>
            <span>Total acumulado: <strong>${numeroImpacto(atual.totalGeral)} ${escapeHTML(atual.unidade)}</strong> (${atual.acoesGeral} ${atual.acoesGeral === 1 ? 'ação' : 'ações'})</span>
            <span>Nas últimas 8 semanas: <strong>+${numeroImpacto(atual.totalPeriodo)} ${escapeHTML(atual.unidade)}</strong></span>
        </div>
        <div class="evo-impacto-chart">${graficoHtml}</div>`;
}

function renderComparativoHabitos(ev) {
    const comp = ev.comparativoHabitos;
    if (!comp) return '';
    const r = comp.resumo;
    const ativos = (comp.atividades || []).filter((a) => a.acoesAntes > 0 || a.acoesAgora > 0);
    const inativos = (comp.atividades || []).filter((a) => a.acoesAntes === 0 && a.acoesAgora === 0);

    const rotTendencia = {
        novo: ['★ Novo hábito', 'tag-success'],
        subiu: ['▲ Aumentou', 'tag-success'],
        manteve: ['= Manteve', 'tag-neutral'],
        caiu: ['▼ Retomar', 'tag-warning'],
    };

    const listaAtivHtml = ativos.length
        ? `<div class="evo-habitos-grid">
            ${ativos.map((a) => {
                const [rot, cls] = rotTendencia[a.tendencia] || ['—', 'tag-neutral'];
                return `<div class="evo-habito-card cat-top-${escapeHTML(a.categoria)}">
                    <div class="evo-habito-top">
                        <strong><span class="cat-dot cat-${escapeHTML(a.categoria)}"></span>${escapeHTML(a.nome)}</strong>
                        <span class="tag ${cls}">${rot}</span>
                    </div>
                    <div class="evo-habito-nums">
                        <div>
                            <small>4 sem. anteriores</small>
                            <strong>${a.acoesAntes}×</strong>
                            <span>${a.unidade ? `${numeroImpacto(a.impactoAntes)} ${escapeHTML(a.unidade)}` : ''}</span>
                        </div>
                        <span class="evo-habito-arrow" aria-hidden="true">→</span>
                        <div>
                            <small>Últimas 4 semanas</small>
                            <strong>${a.acoesAgora}×</strong>
                            <span>${a.unidade ? `${numeroImpacto(a.impactoAgora)} ${escapeHTML(a.unidade)}` : ''}</span>
                        </div>
                    </div>
                </div>`;
            }).join('')}
        </div>`
        : '<p class="muted">Registre ações sustentáveis para acompanhar a evolução dos seus hábitos entre um mês e outro.</p>';

    const experimentarHtml = inativos.length
        ? `<details class="impact-unused evo-habitos-inativos">
            <summary>Hábitos que você ainda não praticou nas últimas 8 semanas (${inativos.length})</summary>
            <div class="evo-inativos-chips">
                ${inativos.map((a) => `<span class="tag cat-${escapeHTML(a.categoria)}">${escapeHTML(a.nome)}</span>`).join(' ')}
            </div>
        </details>`
        : '';

    return `
        <div class="evo-comp-kpis">
            <div class="stat-tile">
                <span class="evo-comp-label">Ações sustentáveis (4 sem.)</span>
                <strong>${r.acoesAntes} → ${r.acoesAgora}</strong>
                ${deltaTag(r.acoesAntes, r.acoesAgora, ' ações')}
            </div>
            <div class="stat-tile">
                <span class="evo-comp-label">Variedade de hábitos</span>
                <strong>${r.habitosAntes} → ${r.habitosAgora} hábitos</strong>
                ${deltaTag(r.habitosAntes, r.habitosAgora, '')}
            </div>
            <div class="stat-tile">
                <span class="evo-comp-label">Com foto IA ou parceiro</span>
                <strong>${r.comprovadasAntes} → ${r.comprovadasAgora}</strong>
                ${deltaTag(r.comprovadasAntes, r.comprovadasAgora, '')}
            </div>
            <div class="stat-tile">
                <span class="evo-comp-label">Pontos gerados na cidade</span>
                <strong>+${r.pontosAntes} → +${r.pontosAgora} pts</strong>
                ${deltaTag(r.pontosAntes, r.pontosAgora, ' pts')}
            </div>
        </div>
        ${listaAtivHtml}
        ${experimentarHtml}`;
}

function renderEvolucaoCidade(ev) {
    const ec = ev.evolucaoCidade;
    if (!ec?.series?.length) return '';
    const rot = ev.semanas.map((s) => semanaBR(s.semana));
    const series = ec.series.map((s) => ({
        nome: nomeCurto(s.categoria),
        cor: COR_CATEGORIA[s.categoria],
        valores: s.valores,
    }));
    const balanco = (ec.balanco || []).filter((b) => b.ganhos !== 0 || b.perdas !== 0);
    const balancoHtml = balanco.length
        ? `<div class="table-scroll"><table class="data-table evo-balanco-table">
            <thead><tr><th scope="col">Semana</th><th scope="col">Recuperação (+ações/bônus)</th><th scope="col">Queda diária / poluição / penalidades</th><th scope="col">Saldo ecológico</th></tr></thead>
            <tbody>${balanco.map((b) => `<tr>
                <td>${semanaBR(b.semana)}</td>
                <td class="evo-pos">+${numero(b.ganhos, 0)} pts</td>
                <td class="valor-negativo">${numero(b.perdas, 0)} pts</td>
                <td><strong class="${b.saldo < 0 ? 'valor-negativo' : 'evo-pos'}">${b.saldo > 0 ? '+' : ''}${numero(b.saldo, 0)} pts</strong></td>
            </tr>`).join('')}</tbody>
        </table></div>`
        : '';

    return `
        ${linhas(rot, series, { max: 100, sufixo: ' pts', titulo: 'Trajetória dos 5 indicadores da cidade (0 a 100)', vazio: 'Sem histórico de indicadores no período.' })}
        ${balancoHtml}`;
}

function renderEvolucao(ev) {
    ultimaEvolucao = ev;
    const rot = ev.semanas.map((s) => semanaBR(s.semana));
    const s = ev.sequencia;
    const ritmo = ev.ritmoPessoal || { diasAtivos: 0, mediaSemanalAcoes: 0, diaMaisAtivo: null, turnoMaisAtivo: null };
    const destaque = ev.maisAge
        ? `Você mais age em <span class="tag cat-${ev.maisAge}">${nomeCurto(ev.maisAge)}</span>
           e menos em <span class="tag cat-${ev.esquecida}">${nomeCurto(ev.esquecida)}</span>.`
        : 'Registre sua primeira ação para descobrir seu equilíbrio entre as 5 áreas.';

    $('profile-evolucao').innerHTML = `
        <div class="evo-kpi-grid">
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('check')} Sequência sustentável</span>
                <strong class="impact-kpi-val">${dias(s.atual)}</strong>
                <span class="impact-kpi-sub">Melhor sequência: <strong>${dias(s.melhor)}</strong> seguidos</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('calendar')} Constância e ritmo</span>
                <strong class="impact-kpi-val">${dias(ritmo.diasAtivos)} ativos</strong>
                <span class="impact-kpi-sub">Média recente: <strong>${numero(ritmo.mediaSemanalAcoes)}</strong> ações/semana</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('star')} Momento mais sustentável</span>
                <strong class="impact-kpi-val">${escapeHTML(ritmo.diaMaisAtivo || 'Sem histórico')}</strong>
                <span class="impact-kpi-sub">${ritmo.turnoMaisAtivo ? `Turno preferido: ${escapeHTML(ritmo.turnoMaisAtivo)}` : 'Registre ações para ver seu turno principal'}</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('leaf')} Equilíbrio ambiental</span>
                <span class="impact-kpi-sub evo-foco-txt">${destaque}</span>
                <button type="button" class="link-btn impact-go-act" data-evo-ir="t-missoes">Registrar nova ação →</button>
            </div>
        </div>

        <section class="evo-section">
            <div class="evo-section-head">
                <div>
                    <h4 class="evo-sub">Progressão do seu impacto sustentável (últimas 8 semanas)</h4>
                    <p class="form-hint">Veja como cada grandeza ecológica (litros, kg, kWh, CO₂, mudas…) evoluiu semana a semana até chegar ao seu total atual.</p>
                </div>
            </div>
            <div id="evo-impacto-box">${renderGraficoImpactoSustentavel(ev)}</div>
        </section>

        <section class="evo-section">
            <div class="evo-section-head">
                <div>
                    <h4 class="evo-sub">Evolução dos seus hábitos: 4 semanas anteriores × últimas 4 semanas</h4>
                    <p class="form-hint">Quais práticas sustentáveis você adotou, acelerou ou precisa retomar neste mês.</p>
                </div>
            </div>
            ${renderComparativoHabitos(ev)}
        </section>

        <section class="evo-section">
            <div class="evo-section-head">
                <div>
                    <h4 class="evo-sub">Evolução da saúde ecológica da sua cidade</h4>
                    <p class="form-hint">Trajetória dos 5 indicadores ambientais (0 a 100) e balanço entre pontos conquistados e queda diária.</p>
                </div>
            </div>
            ${renderEvolucaoCidade(ev)}
        </section>

        <section class="evo-section">
            <h4 class="evo-sub">Ritmo no jogo (ações por área, ações por semana e XP)</h4>
            <div class="evo-two-col">
                <div>
                    <h5 class="evo-mini-title">Ações aprovadas por semana</h5>
                    ${colunas(rot, ev.semanas.map((x) => x.acoes), { titulo: 'Ações aprovadas por semana' })}
                </div>
                <div>
                    <h5 class="evo-mini-title">XP ganho por semana</h5>
                    ${colunas(rot, ev.semanas.map((x) => x.xp), { titulo: 'XP por semana', cor: 'var(--accent-gold)' })}
                </div>
            </div>
            <h5 class="evo-mini-title">Distribuição total de ações por área</h5>
            ${barras(ev.areas.map((a) => ({ rotulo: nomeCurto(a.categoria), valor: a.acoes, cor: COR_CATEGORIA[a.categoria], texto: numero(a.acoes, 0) })))}
        </section>`;
}

const ROTULO_DOMINIO = {
    mestre: ['Mestre (≥ 90%)', 'tag-success'],
    bom: ['Bom domínio (≥ 70%)', 'tag-success'],
    revisar: ['Revisar (< 70%)', 'tag-warning'],
    iniciante: ['Não iniciado', 'tag-neutral'],
};

const ROTULO_TIPO_QUIZ = {
    diario: 'Quiz do Dia',
    semanal: 'Quiz da Semana',
    tematico: 'Temático',
};

function renderAprendizado(ev) {
    ultimaEvolucao = ev;
    const ar = ev.aprendizadoResumo || {
        quizzesDisponiveis: 15, quizzesConcluidos: ev.temas.reduce((s, t) => s + t.quizzes, 0),
        quizzesPerfeitos: 0, totalTentativas: 0, acertoPrimeira: null, acertoAtual: null,
        evolucaoPontos: 0, erradasPrimeira: 0, refeitasTreino: 0, corrigidasTreino: 0,
        temasComQuiz: ev.temas.length, temasComAcao7d: 0,
    };
    const catalogoTemas = ev.catalogoTemas || [];
    const histQ = ev.historicoQuizzes || [];
    const semApr = ev.semanasAprendizado || [];

    const diffPts = ar.evolucaoPontos || 0;
    const kpisHtml = `
        <div class="evo-kpi-grid apr-kpi-grid">
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('help')} Quizzes concluídos</span>
                <strong class="impact-kpi-val">${ar.quizzesConcluidos} de ${ar.quizzesDisponiveis}</strong>
                <span class="impact-kpi-sub"><strong>${ar.quizzesPerfeitos}</strong> com 100% de acerto · ${ar.totalTentativas} ${ar.totalTentativas === 1 ? 'tentativa' : 'tentativas'}</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('chart')} Evolução do acerto</span>
                <strong class="impact-kpi-val">${ar.acertoAtual !== null ? `${numero(ar.acertoAtual)}%` : '—'}</strong>
                <span class="impact-kpi-sub">${ar.acertoPrimeira !== null ? `1ª tentativa: ${numero(ar.acertoPrimeira)}% (${diffPts > 0 ? `+${numero(diffPts)}` : numero(diffPts)} p.p.)` : 'Conclua seu 1º quiz'}</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('check')} Superação no treino</span>
                <strong class="impact-kpi-val">${ar.corrigidasTreino} de ${ar.erradasPrimeira}</strong>
                <span class="impact-kpi-sub">${ar.erradasPrimeira > 0 ? `erros da 1ª tentativa já corrigidos (${ar.refeitasTreino} refeitas)` : 'Nenhum erro na 1ª tentativa!'}</span>
            </div>
            <div class="impact-kpi">
                <span class="impact-kpi-label">${icon('leaf')} Ciclo Aprenda → Pratique</span>
                <strong class="impact-kpi-val">${ar.temasComAcao7d} de ${ar.temasComQuiz || 0} temas</strong>
                <span class="impact-kpi-sub">${ar.temasComQuiz > 0 ? 'estudados tiveram ação real registrada em até 7 dias' : 'Estude um tema e pratique na vida real'}</span>
            </div>
        </div>`;

    const temasCardsHtml = catalogoTemas.length
        ? `<div class="apr-temas-grid">
            ${catalogoTemas.map((t) => {
                const cat = CATEGORIA_POR_ID[t.categoria] || { icone: 'leaf', nome: t.nome, curto: t.nome };
                const [rotDom, clsDom] = ROTULO_DOMINIO[t.nivelDominio] || ROTULO_DOMINIO.iniciante;
                const pctBar = t.acertoAtual !== null ? Math.round(t.acertoAtual) : 0;
                const evTxt = t.acertoPrimeira !== null && t.evolucao > 0
                    ? `1ª tent.: ${numero(t.acertoPrimeira)}% → <strong>+${numero(t.evolucao)} p.p.</strong> no treino`
                    : (t.acertoPrimeira !== null ? `1ª tentativa: ${numero(t.acertoPrimeira)}%` : 'Nenhum quiz concluído neste tema ainda');
                const eloPratica = t.praticouAposQuiz
                    ? `<span class="apr-elo is-ok">${icon('check')} Estudou e praticou em até 7 dias (${t.acoesPraticadas} ${t.acoesPraticadas === 1 ? 'ação' : 'ações'})</span>`
                    : (t.acoesPraticadas > 0
                        ? `<span class="apr-elo">${icon('leaf')} ${t.acoesPraticadas} ${t.acoesPraticadas === 1 ? 'ação prática' : 'ações práticas'} na área</span>`
                        : `<span class="apr-elo is-pending">Ainda sem ação prática nesta área</span>`);
                return `<article class="apr-tema-card cat-top-${escapeHTML(t.categoria)}">
                    <header class="apr-tema-head">
                        <span class="impact-icon cat-${escapeHTML(t.categoria)}">${icon(cat.icone)}</span>
                        <div class="apr-tema-title">
                            <h5>${escapeHTML(t.nome)}</h5>
                            <small>${t.quizzesFeitos} de ${t.quizzesTotal} quizzes concluídos</small>
                        </div>
                        <span class="tag ${clsDom}">${rotDom}</span>
                    </header>
                    <div class="apr-tema-meter">
                        <div class="apr-tema-meter-top">
                            <span>Taxa de acerto atual</span>
                            <strong>${t.acertoAtual !== null ? `${numero(t.acertoAtual)}%` : '—'}</strong>
                        </div>
                        <div class="bar-track"><span class="bar-fill fill-${escapeHTML(t.categoria)}" style="width:${pctBar}%"></span></div>
                        <small class="form-hint">${evTxt}</small>
                    </div>
                    ${eloPratica}
                    <div class="apr-tema-foot">
                        ${t.erradasParaRever > 0
                            ? `<button type="button" class="link-btn" data-apr-filtrar-cat="${escapeHTML(t.categoria)}">Rever ${t.erradasParaRever} ${t.erradasParaRever === 1 ? 'pergunta' : 'perguntas'}</button>`
                            : `<button type="button" class="link-btn" data-evo-ir="t-quizzes">Praticar quizzes →</button>`}
                        <button type="button" class="link-btn" data-evo-ir="t-missoes">Agir na área →</button>
                    </div>
                </article>`;
            }).join('')}
        </div>`
        : '';

    const rotSem = semApr.map((s) => semanaBR(s.semana));
    const temSemQuiz = semApr.some((s) => s.acerto !== null);
    const graficoSemHtml = temSemQuiz
        ? linhas(rotSem, [{ nome: 'Taxa média de acerto', cor: 'var(--primary-color)', valores: semApr.map((s) => s.acerto) }], {
            max: 100,
            sufixo: '%',
            titulo: 'Evolução semanal da taxa de acerto nos quizzes',
            vazio: 'Sem tentativas de quiz nas últimas 8 semanas.',
        })
        : '<p class="muted">Responda quizzes ao longo das semanas para ver a curva de evolução das suas notas.</p>';

    const histQuizzesHtml = histQ.length
        ? `<div class="table-scroll"><table class="data-table apr-quiz-table">
            <thead>
                <tr>
                    <th scope="col">Quiz</th>
                    <th scope="col">Tema</th>
                    <th scope="col">Tentativas</th>
                    <th scope="col">1ª tentativa</th>
                    <th scope="col">Melhor nota</th>
                    <th scope="col">Última vez</th>
                </tr>
            </thead>
            <tbody>
                ${histQ.map((q) => {
                    const subiu = q.notaMelhor > q.notaPrimeira;
                    const tagIa = q.origem === 'ia' ? ' <span class="tag tag-ia" title="Gerado por IA a partir de notícias">IA</span>' : '';
                    return `<tr>
                        <td><strong>${escapeHTML(q.titulo)}</strong>${tagIa}<br><small class="muted">${escapeHTML(ROTULO_TIPO_QUIZ[q.tipo] || q.tipo)}</small></td>
                        <td><span class="tag cat-${escapeHTML(q.categoria)}">${escapeHTML(nomeCurto(q.categoria))}</span></td>
                        <td>${q.tentativas}×</td>
                        <td>${q.notaPrimeira}/${q.totalPerguntas} (${numero(q.pctPrimeira, 0)}%)</td>
                        <td><strong>${q.notaMelhor}/${q.totalPerguntas} (${numero(q.pctMelhor, 0)}%)</strong>${subiu ? ` <span class="evo-delta is-up">▲ +${q.notaMelhor - q.notaPrimeira}</span>` : ''}</td>
                        <td>${dataBR(q.ultimaData)}</td>
                    </tr>`;
                }).join('')}
            </tbody>
        </table></div>`
        : '<p class="muted">Você ainda não concluiu nenhum quiz. Visite a aba Quizzes para começar!</p>';

    // Caderno de revisão interativo (filtrado por tema e paginado)
    const todasErradas = ev.erradas || [];
    if (filtroErradasCat !== 'todas' && !todasErradas.some((e) => e.categoria === filtroErradasCat)) {
        filtroErradasCat = 'todas';
    }
    const erradasFiltradas = filtroErradasCat === 'todas'
        ? todasErradas
        : todasErradas.filter((e) => e.categoria === filtroErradasCat);

    const contagemPorCat = {};
    todasErradas.forEach((e) => { contagemPorCat[e.categoria] = (contagemPorCat[e.categoria] || 0) + 1; });

    const tam = tamanhoPerfil();
    const total = erradasFiltradas.length;
    erradasPagina = Math.min(Math.max(1, erradasPagina), Math.max(1, Math.ceil(total / tam)));
    const pagina = erradasFiltradas.slice((erradasPagina - 1) * tam, erradasPagina * tam);

    const filtrosCatHtml = todasErradas.length
        ? `<div class="registros-filtros apr-erradas-filtros" role="group" aria-label="Filtrar perguntas por tema">
            <button type="button" class="chip ${filtroErradasCat === 'todas' ? 'is-active' : ''}" data-apr-filtrar-cat="todas" aria-pressed="${filtroErradasCat === 'todas'}">Todas <span class="chip-count">${todasErradas.length}</span></button>
            ${Object.entries(contagemPorCat).map(([catKey, qtd]) => `<button type="button" class="chip ${filtroErradasCat === catKey ? 'is-active' : ''}" data-apr-filtrar-cat="${escapeHTML(catKey)}" aria-pressed="${filtroErradasCat === catKey}">
                <span class="cat-dot cat-${escapeHTML(catKey)}"></span>${escapeHTML(nomeCurto(catKey))} <span class="chip-count">${qtd}</span>
            </button>`).join('')}
        </div>`
        : '';

    const erradasHtml = todasErradas.length
        ? `<div class="evo-erradas-head">
                <div>
                    <h4 class="evo-sub">Caderno de revisão: perguntas para rever <span class="chip-count">${total}</span></h4>
                    <p class="form-hint">Perguntas que ficaram erradas na sua última tentativa de cada quiz. Refaça o quiz em "Já feitos" para treinar e dominar o tema!</p>
                </div>
                ${seletorTamanho(tam, TAMANHOS_PERFIL)}
            </div>
            ${filtrosCatHtml}
            <ul class="evo-erradas">${pagina.map((e) => `<li>
                <div class="evo-errada-top">
                    <span class="tag cat-${escapeHTML(e.categoria)}">${escapeHTML(nomeCurto(e.categoria))} · ${escapeHTML(e.quiz)}</span>
                    <button type="button" class="link-btn" data-evo-ir="t-quizzes">Treinar na aba Quizzes →</button>
                </div>
                <strong>${escapeHTML(e.enunciado)}</strong>
                <span class="evo-resp">Sua resposta: ${escapeHTML(e.escolhida)}</span>
                <span class="evo-resp evo-certa">Resposta correta: ${escapeHTML(e.correta)}</span>
                ${e.explicacao ? `<span class="evo-exp">${escapeHTML(e.explicacao)}</span>` : ''}
                ${e.fonteUrl ? `<span class="evo-fonte">Fonte: <a href="${escapeHTML(e.fonteUrl)}" target="_blank" rel="noopener noreferrer">${escapeHTML(e.fonteTitulo || e.fonteUrl)}</a></span>` : ''}
            </li>`).join('')}</ul>`
        : (ar.quizzesConcluidos > 0
            ? '<div class="apr-all-correct"><span data-icon="check"></span><strong>Parabéns! Você acertou todas as perguntas na última tentativa de cada quiz concluído.</strong></div>'
            : '');

    $('profile-aprendizado').innerHTML = `
        ${kpisHtml}
        <section class="evo-section">
            <h4 class="evo-sub">Seu domínio por área ambiental</h4>
            <p class="form-hint">Acompanhe sua evolução desde a 1ª tentativa até o domínio atual e como seu estudo se transforma em ações sustentáveis.</p>
            ${temasCardsHtml}
        </section>
        <section class="evo-section">
            <h4 class="evo-sub">Evolução nos quizzes concluídos (1ª tentativa × Melhor nota)</h4>
            ${graficoSemHtml}
            ${histQuizzesHtml}
        </section>
        <section class="evo-section" id="apr-revisao-sec">
            ${erradasHtml}
        </section>`;
    $('profile-erradas-pager').hidden = total <= tam;
    pager($('profile-erradas-pager'), erradasPagina, total, tam, total === 1 ? 'pergunta' : 'perguntas');
}

function renderComparacao(c) {
    if (!c.disponivel) {
        $('profile-comparacao').innerHTML = `<p class="muted">A comparação aparece quando houver pelo menos ${c.minimo} jogadores ativos nos últimos 30 dias. Ninguém é mostrado: só a posição em porcentagem.</p>`;
        return;
    }
    $('profile-comparacao').innerHTML = c.areas.length
        ? `<ul class="evo-comp">${c.areas.map((a) => `<li>${anel(a.acimaDe, { cor: COR_CATEGORIA[a.categoria], rotulo: nomeCurto(a.categoria) })}
            <span>Em <strong>${nomeCurto(a.categoria)}</strong> você registrou mais ações que <strong>${a.acimaDe}%</strong> dos jogadores ativos (${a.acoes} ${a.acoes === 1 ? 'ação' : 'ações'} em 30 dias).</span></li>`).join('')}</ul>
            <p class="form-hint">Entre ${c.jogadores} jogadores ativos nos últimos 30 dias. Nenhum outro jogador é identificado.</p>`
        : '<p class="muted">Registre ações nos próximos dias para se comparar com os outros jogadores.</p>';
}

async function carregarEvolucao() {
    if (Date.now() - evolucaoCarregadaEm < 30000) return;
    evolucaoCarregadaEm = Date.now();
    try {
        const { evolucao } = await chamarApi('api/perfil.php', { acao: 'evolucao' });
        renderEvolucao(evolucao);
        renderAprendizado(evolucao);
        renderComparacao(evolucao.comparacao);
    } catch (err) {
        evolucaoCarregadaEm = 0;
        $('profile-evolucao').innerHTML = `<p class="field-error">${escapeHTML(err.message)}</p>`;
    }
}

/** Envio com botão bloqueado e mensagem de erro no próprio formulário. */
function aoEnviar(form, erroId, acao) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = form.querySelector('[type="submit"]');
        $(erroId).textContent = '';
        botao.disabled = true;
        try {
            await acao();
        } catch (err) {
            $(erroId).textContent = err.message;
        } finally {
            botao.disabled = false;
        }
    });
}

let senhaAberta = () => {};
let desativarAberto = () => {};
let focoAntesConta = null;
const escolhaAvatar = { icone: null, cor: null };

/** Grade de ícones e cores do avatar, com a prévia. */
function renderEscolhaAvatar() {
    const j = state.jogador;
    const { icone, cor } = escolhaAvatar;
    const previa = icone && cor ? { icone, cor } : null;
    $('conta-avatar-previa').className = `friend-avatar friend-avatar-lg ${previa ? classeAvatar(j, previa) : 'av-c-nature'}`;
    $('conta-avatar-previa').innerHTML = conteudoAvatar(j.apelido, previa);
    $('conta-avatar-icones').innerHTML = AVATAR_ICONES.map((i) => `<button type="button" role="radio" class="conta-opcao" data-icone="${i}"
        aria-checked="${i === icone}" aria-label="Ícone ${i}">${icon(i)}</button>`).join('');
    $('conta-avatar-cores').innerHTML = AVATAR_CORES.map(([c, nome]) => `<button type="button" role="radio" class="conta-opcao conta-cor av-c-${c}" data-cor="${c}"
        aria-checked="${c === cor}" aria-label="Cor ${nome}" title="${nome}"></button>`).join('');
}

/** Abre "Minha conta" (avatar do topo): dados, edição, senha e tutorial. */
export function abrirConta() {
    focoAntesConta = document.activeElement;
    renderConta(state.perfil.conta);
    Object.assign(escolhaAvatar, state.perfil.conta.avatar || { icone: 'leaf', cor: 'nature' });
    renderEscolhaAvatar();
    $('avatar-error').textContent = '';
    $('profile-apelido').value = state.perfil.conta.apelido;
    $('profile-cidade').value = state.perfil.conta.cidade;
    $('profile-error').textContent = '';
    $('promo-error').textContent = '';
    $('promo-ok').hidden = true;
    $('promo-ok').textContent = '';
    renderPromocoes(state.perfil.codigosPromocionais || []);
    renderSom();
    $('modal-conta').classList.remove('hidden');
    tocar('abrir');
    $('abrir-conta').setAttribute('aria-expanded', 'true');
    $('conta-fechar').focus();
}

export function fecharConta({ devolverFoco = true } = {}) {
    if ($('modal-conta').classList.contains('hidden')) return;
    senhaAberta(false, { semFoco: true });
    desativarAberto(false);
    $('modal-conta').classList.add('hidden');
    tocar('fechar');
    $('abrir-conta').setAttribute('aria-expanded', 'false');
    if (devolverFoco) (focoAntesConta || $('abrir-conta')).focus?.();
}

/* Som (RN96): preferências gravadas na conta; o som.js aplica na hora */
const somAtual = () => state.perfil?.conta?.som || {
    mudo: false, efeitos: true, interface: true, ambiente: false,
    volume: null, volumeEfeitos: null, volumeInterface: null, volumeAmbiente: null,
};
const ehOpcaoVolume = (op) => op === 'volume' || op in VOLUME_CANAL_PADRAO;

/** Botão do topo e card "Som" de Minha conta, conforme a preferência atual. */
export function renderSom() {
    const p = somAtual();
    const b = $('som-botao');
    b.setAttribute('aria-pressed', String(p.mudo));
    b.title = p.mudo ? 'Ligar o som' : 'Desligar o som';
    b.setAttribute('aria-label', b.title);
    b.innerHTML = icon(p.mudo ? 'volume-off' : 'volume');
    document.querySelectorAll('[data-som-volume]').forEach((el) => {
        const chave = el.dataset.somVolume;
        const padrao = chave === 'volume' ? volumePadrao() : VOLUME_CANAL_PADRAO[chave];
        const vol = p[chave] ?? padrao;
        if (document.activeElement !== el) el.value = vol;
        $(`${el.id}-valor`).textContent = `${vol}%`;
        if (el.dataset.somCanal) {
            const ativo = !!p[el.dataset.somCanal];
            el.disabled = !ativo;
            el.closest('.conta-som-canal')?.classList.toggle('is-desligado', !ativo);
        }
    });
    document.querySelectorAll('[data-som]').forEach((x) => { x.checked = !!p[x.dataset.som]; });
}

/** Grava uma opção de som (a tela muda na hora; volta atrás se o servidor recusar). */
async function salvarSom(opcao, valor) {
    const anterior = { ...somAtual() };
    const novo = { ...anterior, [opcao]: ehOpcaoVolume(opcao) ? Number(valor) : valor === '1' };
    if (state.perfil?.conta) state.perfil.conta.som = novo;
    aplicarPreferencias(novo);
    renderSom();
    try { await acaoConta({ acao: 'som', opcao, valor }); }
    catch (err) {
        if (state.perfil?.conta) state.perfil.conta.som = anterior;
        aplicarPreferencias(anterior);
        renderSom();
        toast(err.message, 'error');
    }
}

function initSom() {
    renderSom();
    $('som-botao').addEventListener('click', () => salvarSom('mudo', somAtual().mudo ? '0' : '1'));
    $('conta-som').addEventListener('change', (e) => {
        const c = e.target.closest('[data-som]');
        if (c) salvarSom(c.dataset.som, c.checked ? '1' : '0');
        const v = e.target.closest('[data-som-volume]');
        if (v) salvarSom(v.dataset.somVolume, v.value);
    });
    $('conta-som').addEventListener('input', (e) => {
        const v = e.target.closest('[data-som-volume]');
        if (!v) return;
        $(`${v.id}-valor`).textContent = `${v.value}%`;
        aplicarPreferencias({ ...somAtual(), [v.dataset.somVolume]: Number(v.value) });   // ouve enquanto arrasta; grava ao soltar
    });
    $('som-testar').addEventListener('click', () => tocar('conquista'));
}

export function initProfile() {
    initSom();
    // Avatar: escolher ícone e cor, salvar ou voltar à inicial
    const salvarAvatar = (icone, cor, botao) => async () => {
        botao.disabled = true;
        $('avatar-error').textContent = '';
        try { await acaoConta({ acao: 'avatar', icone, cor }); renderEscolhaAvatar(); }
        catch (err) { $('avatar-error').textContent = err.message; }
        finally { botao.disabled = false; }
    };
    $('conta-avatar-icones').addEventListener('click', (e) => {
        const b = e.target.closest('[data-icone]');
        if (b) { escolhaAvatar.icone = b.dataset.icone; renderEscolhaAvatar(); }
    });
    $('conta-avatar-cores').addEventListener('click', (e) => {
        const b = e.target.closest('[data-cor]');
        if (b) { escolhaAvatar.cor = b.dataset.cor; renderEscolhaAvatar(); }
    });
    $('conta-avatar-salvar').addEventListener('click', (e) => salvarAvatar(escolhaAvatar.icone, escolhaAvatar.cor, e.currentTarget)());
    $('conta-avatar-padrao').addEventListener('click', (e) => {
        Object.assign(escolhaAvatar, { icone: 'leaf', cor: 'nature' });
        salvarAvatar('', '', e.currentTarget)();
    });

    // Privacidade: cada caixa salva na hora (volta atrás se o servidor recusar)
    $('conta-privacidade').addEventListener('change', async (e) => {
        const caixa = e.target.closest('[data-privacidade]');
        if (!caixa) return;
        caixa.disabled = true;
        try { await acaoConta({ acao: 'privacidade', opcao: caixa.dataset.privacidade, valor: caixa.checked ? '1' : '0' }); }
        catch (err) { caixa.checked = !caixa.checked; toast(err.message, 'error'); }
        finally { caixa.disabled = false; }
    });

    // Sair de todos os outros aparelhos
    $('conta-sair-todos').addEventListener('click', async () => {
        const botao = $('conta-sair-todos');
        const ok = await confirmar({ titulo: 'Sair de todos os outros aparelhos?', texto: 'As sessões abertas em outros celulares e computadores serão encerradas. Esta continua aberta.', botaoTexto: 'Encerrar as outras' });
        if (!ok) return;
        botao.disabled = true;
        try { await acaoConta({ acao: 'sair_todos' }); }
        catch (err) { toast(err.message, 'error'); }
        finally { botao.disabled = false; }
    });

    // Meus dados (LGPD): o servidor monta o arquivo e o navegador baixa
    $('conta-meus-dados').addEventListener('click', async (e) => {
        const botao = e.currentTarget;
        botao.disabled = true;
        $('meus-dados-error').textContent = '';
        try {
            const { dados } = await chamarApi('api/perfil.php', { acao: 'meus_dados' });
            const url = URL.createObjectURL(new Blob([JSON.stringify(dados, null, 2)], { type: 'application/json' }));
            const a = Object.assign(document.createElement('a'), { href: url, download: `ecocity-meus-dados-${new Date().toISOString().slice(0, 10)}.json` });
            document.body.append(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast('Arquivo com os seus dados baixado.');
        } catch (err) { $('meus-dados-error').textContent = err.message; }
        finally { botao.disabled = false; }
    });

    // Desativar conta: senha + "DESATIVAR"
    desativarAberto = (aberto) => {
        $('desativar-form').hidden = !aberto;
        $('conta-desativar-abrir').setAttribute('aria-expanded', String(aberto));
        if (aberto) $('desativar-senha').focus();
        else { $('desativar-form').reset(); $('desativar-error').textContent = ''; }
    };
    $('conta-desativar-abrir').addEventListener('click', () => desativarAberto($('desativar-form').hidden));
    $('desativar-cancelar').addEventListener('click', () => { desativarAberto(false); $('conta-desativar-abrir').focus(); });
    aoEnviar($('desativar-form'), 'desativar-error', async () => {
        if ($('desativar-confirmacao').value.trim().toUpperCase() !== 'DESATIVAR') throw new Error('Digite DESATIVAR para confirmar.');
        const r = await chamarApi('api/perfil.php', { acao: 'desativar', senha: $('desativar-senha').value, confirmacao: $('desativar-confirmacao').value });
        window.location.href = r.redirecionar || 'login.php';
    });

    // Minha conta: o avatar do topo abre; Fechar, Esc e clique fora fecham; Tab fica dentro da janela
    $('abrir-conta').setAttribute('aria-expanded', 'false');
    $('abrir-conta').addEventListener('click', abrirConta);
    $('conta-fechar').addEventListener('click', () => fecharConta());
    $('modal-conta').addEventListener('click', (e) => { if (e.target.id === 'modal-conta') fecharConta(); });
    $('modal-conta').addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); fecharConta(); return; }
        if (e.key !== 'Tab') return;
        const focaveis = [...$('modal-conta').querySelectorAll('button, input, select, textarea, a[href]')].filter((x) => !x.disabled && x.offsetParent !== null);
        const [primeiro, ultimo] = [focaveis[0], focaveis.at(-1)];
        if (e.shiftKey && document.activeElement === primeiro) { e.preventDefault(); ultimo.focus(); }
        else if (!e.shiftKey && document.activeElement === ultimo) { e.preventDefault(); primeiro.focus(); }
    });

    aoEnviar($('profile-form'), 'profile-error', async () => {
        const apelido = $('profile-apelido').value.trim();
        const cidade = $('profile-cidade').value.trim();
        if (apelido.length < 3 || cidade.length < 3) throw new Error('Apelido e nome da cidade precisam ter ao menos 3 caracteres.');
        await atualizarPerfil(apelido, cidade);
    });

    aoEnviar($('password-form'), 'password-error', async () => {
        const nova = $('senha-nova').value;
        if (nova.length < 6) throw new Error('A nova senha deve ter no mínimo 6 caracteres.');
        if (nova !== $('senha-confirmar').value) throw new Error('As senhas novas não coincidem.');
        await trocarSenha($('senha-atual').value, nova, $('senha-confirmar').value);
        senhaAberta(false);
    });

    // Carrega a evolução quando a aba Perfil aparece (clique ou setas do teclado)
    new MutationObserver(() => { if (!$('tab-perfil').hidden) carregarEvolucao(); })
        .observe($('tab-perfil'), { attributes: true, attributeFilter: ['hidden'] });
    if (!$('tab-perfil').hidden) carregarEvolucao();

    // Subabas; o extrato filtrado é recarregado ao voltar a ele se os dados mudaram
    initTabs($('profile-tabs'));
    $('pt-extrato').addEventListener('click', () => { if (ext.desatualizado) carregarExtrato(ext.pagina); });

    $('pf-copiar').addEventListener('click', () => copiarCodigo('pf-codigo'));
    $('profile-ultimas').addEventListener('click', (e) => {
        const b = e.target.closest('[data-conquista]');
        if (b) abrirDetalhes(Number(b.dataset.conquista));
    });
    $('profile-go-conquistas').addEventListener('click', () => $('t-conquistas').click());

    // Extrato: tipo, origem e páginas
    $('profile-extrato-tipo').addEventListener('click', (e) => {
        const b = e.target.closest('[data-tipo]');
        if (!b || b.dataset.tipo === ext.tipo) return;
        ext.tipo = b.dataset.tipo;
        $('profile-extrato-tipo').querySelectorAll('[data-tipo]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        carregarExtrato(1);
    });
    $('profile-extrato-origem').addEventListener('change', (e) => { ext.origem = e.target.value; carregarExtrato(1); });
    $('profile-statement-pager').addEventListener('click', (e) => {
        const b = e.target.closest('[data-pagina]');
        if (b && !b.disabled) carregarExtrato(Number(b.dataset.pagina));
    });

    // "Mostrar 10/20/30 por página" (vale para o Extrato e para as perguntas para rever)
    const mudarTamanho = (n) => {
        guardarTamanho(CHAVE_TAMANHO, n);
        $('profile-extrato-tamanho').innerHTML = seletorTamanho(n, TAMANHOS_PERFIL);
        erradasPagina = 1;
        if (ultimaEvolucao) renderAprendizado(ultimaEvolucao);
        carregarExtrato(1);
    };
    $('profile-extrato-tamanho').innerHTML = seletorTamanho(tamanhoPerfil(), TAMANHOS_PERFIL);
    const aoMudar = (e) => { if (e.target.matches('[data-lista-tamanho]')) mudarTamanho(Number(e.target.value)); };
    $('profile-extrato-tamanho').addEventListener('change', aoMudar);
    $('profile-aprendizado').addEventListener('change', aoMudar);
    $('profile-erradas-pager').addEventListener('click', (e) => {
        const b = e.target.closest('[data-pagina]');
        if (!b || b.disabled || !ultimaEvolucao) return;
        erradasPagina = Number(b.dataset.pagina);
        renderAprendizado(ultimaEvolucao);
        $('profile-aprendizado').querySelector('.evo-erradas-head')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    // Segurança: formulário de senha recolhido até pedir
    senhaAberta = (aberta, { semFoco = false } = {}) => {
        $('password-form').hidden = !aberta;
        $('profile-senha-abrir').hidden = aberta;
        $('profile-senha-abrir').setAttribute('aria-expanded', String(aberta));
        if (aberta) $('senha-atual').focus();
        else { $('password-form').reset(); $('password-error').textContent = ''; if (!semFoco) $('profile-senha-abrir').focus(); }
    };
    $('profile-senha-abrir').addEventListener('click', () => senhaAberta(true));
    $('profile-senha-cancelar').addEventListener('click', () => senhaAberta(false));

    $('profile-go-registros').addEventListener('click', () => {
        $('t-missoes').click();
        document.querySelector('.registros')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Meu impacto: seletor de período (Desde o início / Últimos 30 dias / Esta semana) e atalhos rápidos
    $('profile-impact-periodos')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-impacto-periodo]');
        if (!b || b.dataset.impactoPeriodo === periodoImpacto) return;
        periodoImpacto = b.dataset.impactoPeriodo;
        $('profile-impact-periodos').querySelectorAll('[data-impacto-periodo]').forEach((x) => {
            x.setAttribute('aria-pressed', String(x === b));
        });
        renderImpacto(state.perfil.impacto, state.perfil.impactoResumo);
    });
    $('pp-resumo')?.addEventListener('click', (e) => {
        const ir = e.target.closest('[data-impacto-ir]');
        if (!ir) return;
        const abaId = ir.dataset.impactoIr;
        $(abaId)?.click();
        if (abaId === 't-missoes') $('mt-agir')?.click();
    });

    // Minha evolução: trocar unidade física ou modo do gráfico (Acumulado / Por semana) e atalhos
    $('profile-evolucao')?.addEventListener('click', (e) => {
        const uBtn = e.target.closest('[data-evo-unidade]');
        if (uBtn && ultimaEvolucao) {
            unidadeEvolucaoSel = uBtn.dataset.evoUnidade;
            const box = $('evo-impacto-box');
            if (box) box.innerHTML = renderGraficoImpactoSustentavel(ultimaEvolucao);
            return;
        }
        const mBtn = e.target.closest('[data-evo-modo]');
        if (mBtn && ultimaEvolucao) {
            modoGraficoImpacto = mBtn.dataset.evoModo;
            const box = $('evo-impacto-box');
            if (box) box.innerHTML = renderGraficoImpactoSustentavel(ultimaEvolucao);
            return;
        }
        const ir = e.target.closest('[data-evo-ir]');
        if (ir) {
            const abaId = ir.dataset.evoIr;
            $(abaId)?.click();
            if (abaId === 't-missoes') $('mt-agir')?.click();
        }
    });

    // Meu aprendizado: filtrar perguntas para rever por tema e atalhos para Quizzes / Agir hoje
    $('profile-aprendizado')?.addEventListener('click', (e) => {
        const fBtn = e.target.closest('[data-apr-filtrar-cat]');
        if (fBtn && ultimaEvolucao) {
            filtroErradasCat = fBtn.dataset.aprFiltrarCat;
            erradasPagina = 1;
            renderAprendizado(ultimaEvolucao);
            document.getElementById('apr-revisao-sec')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            return;
        }
        const ir = e.target.closest('[data-evo-ir]');
        if (ir) {
            const abaId = ir.dataset.evoIr;
            $(abaId)?.click();
            if (abaId === 't-missoes') $('mt-agir')?.click();
        }
    });

    // Código promocional (RN98): resgate em Minha conta (modal do avatar)
    aoEnviar($('promo-form'), 'promo-error', async () => {
        const inp = $('promo-codigo');
        const okBox = $('promo-ok');
        okBox.hidden = true;
        okBox.textContent = '';
        const codigo = inp.value.trim();
        if (codigo.length < 4) throw new Error('Digite o código promocional (mínimo 4 caracteres).');
        const r = await resgatarCodigoPromocional(codigo);
        inp.value = '';
        okBox.textContent = r.mensagem;
        okBox.hidden = false;
    });
}
