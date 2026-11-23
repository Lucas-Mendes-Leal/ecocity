/**
 * Painel do parceiro (RN70, RN95) — "EcoCity Parceiros · Gestão" (Admin) e "Modo Balcão" (Funcionário).
 *  - Atender (modo balcão, administrador e funcionário): cartões de atividade por área, quantidade com −/+,
 *    código de uso único com QR (no card ou em tela cheia), contagem regressiva, códigos de agora, histórico e totais.
 *    O funcionário só vê os próprios. Consulta o painel a cada 10 s para mostrar quando o jogador usou o código.
 *  - Só o administrador: Visão geral (KPIs com comparação ao período anterior, gráfico diário, equipe e últimos
 *    atendimentos), Atendimentos (paginados no servidor), Relatório, Funcionários e Estabelecimento.
 */
import './pwa.js';
import { hydrateIcons, icon } from './ui/icons.js';
import { $, escapeHTML, toast, pager } from './ui/dom.js';
import { initTabs } from './ui/tabs.js';
import { seletorTamanho, pilulas, tamanhoGuardado, guardarTamanho } from './ui/lista-admin.js';
import { barras, colunas, mapaCalor, numero } from './ui/graficos.js';
import { iniciarSom, aplicarPreferencias, tocar } from './som.js';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const ADMIN = document.body.dataset.papel === 'admin';
const CONSULTA_MS = 10000;
const st = { painel: null, atual: null, telaCheia: false };

const num = (n) => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
const quando = (d) => (d ? new Date(d.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');
const atendente = (c) => (c.atendente ? escapeHTML(c.atendente) : '<span class="muted">Administrador</span>');
const fmtCodigo = (cod) => (cod && cod.length === 6 ? `${cod.slice(0, 3)} ${cod.slice(3)}` : escapeHTML(cod || '—'));

/** Categoria ambiental de uma atividade (para a bolinha/barra de cor). */
function categoriaAtividade(idOuNome) {
    const lista = st.painel?.atividadesTodas ?? st.painel?.atividades ?? [];
    const a = lista.find((x) => x.id === Number(idOuNome) || x.nome === idOuNome);
    return a?.categoria || 'nature';
}

function bolinhaCategoria(idOuNome) {
    return `<span class="p-cat-dot cat-${escapeHTML(categoriaAtividade(idOuNome))}" aria-hidden="true"></span>`;
}

async function api(acao, campos = {}) {
    const corpo = new FormData();
    corpo.append('acao', acao);
    Object.entries(campos).forEach(([k, v]) => corpo.append(k, v));
    const r = await fetch('api/parceiro.php', { method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } });
    if (r.status === 401) { location.href = 'parceiro-entrar.php'; throw new Error('Sessão encerrada.'); }
    const j = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!j.ok) throw new Error(j.erro || 'Não foi possível concluir.');
    return j;
}

function marcarSincronizado() {
    const el = $('p-sync-texto');
    if (!el) return;
    const h = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    el.textContent = `Atualizado agora · ${h}`;
}

/* ---------------------------------------------------------------------------
   Atender (Modo Balcão)
   --------------------------------------------------------------------------- */

/** SVG do QR com o link de validação. */
function qrSvg(texto) {
    const qr = window.qrcode(0, 'M');
    qr.addData(texto);
    qr.make();
    return qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true, alt: 'QR Code para o jogador confirmar a atividade' });
}

const restante = (c) => Math.max(0, c.expiraTs - (Math.floor(Date.now() / 1000) + (st.desvio || 0)));   // relógio do servidor
const mmss = (s) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;

const ICONE_EXPANDIR = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';

function renderTelaCheia() {
    const modal = $('partner-fullscreen');
    const body = $('partner-fullscreen-body');
    if (!modal || !body) return;
    const c = st.atual && st.painel?.codigos.find((x) => x.id === st.atual);
    if (!st.telaCheia || !c) {
        st.telaCheia = false;
        modal.classList.add('hidden');
        return;
    }
    modal.classList.remove('hidden');
    if (c.usadoEm) {
        body.innerHTML = `<div class="partner-done">${icon('check')}<strong>Confirmado por ${escapeHTML(c.jogador || 'jogador')}</strong>
            <span>${escapeHTML(c.atividade)} · ${num(c.quantidade)} ${escapeHTML(c.unidade)}</span></div>
            <button type="button" class="btn btn-primary" data-fechar-cheia>Fechar</button>`;
        hydrateIcons(body);
        return;
    }
    const s = restante(c);
    body.innerHTML = `
        <span class="p-rotulo">Aponte a câmera do celular para o código</span>
        <div class="p-fullscreen-qr">${qrSvg(c.link)}</div>
        <p class="p-fullscreen-code" aria-label="Código ${c.codigo.split('').join(' ')}">${fmtCodigo(c.codigo)}</p>
        <p class="p-fullscreen-what">${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)} · <strong>${num(c.quantidade)} ${escapeHTML(c.unidade)}</strong></p>
        <p class="partner-timer ${s < 120 ? 'is-ending' : ''}">${s > 0 ? `${icon('calendar')} Vale por <strong id="pf-timer">${mmss(s)}</strong>` : 'Código vencido'}</p>
        <div class="p-show-actions">
            <button type="button" class="btn btn-outline" data-fechar-cheia>Voltar ao balcão (Esc)</button>
        </div>`;
    hydrateIcons(body);
}

function renderAtual() {
    const box = $('partner-show');
    if (!box) return;
    const c = st.atual && st.painel.codigos.find((x) => x.id === st.atual);
    if (!c) {
        box.innerHTML = '<p class="empty-state">Gere um código para mostrar ao jogador.</p>';
        renderTelaCheia();
        return;
    }
    if (c.usadoEm) {
        box.innerHTML = `<div class="partner-done">${icon('check')}<strong>Confirmado por ${escapeHTML(c.jogador || 'jogador')}</strong>
            <span>${escapeHTML(c.atividade)} · ${num(c.quantidade)} ${escapeHTML(c.unidade)}</span></div>`;
        hydrateIcons(box);
        renderTelaCheia();
        return;
    }
    const s = restante(c);
    box.innerHTML = `
        <span class="p-rotulo">Código atual</span>
        <div class="partner-qr">${qrSvg(c.link)}</div>
        <p class="partner-code" aria-label="Código ${c.codigo.split('').join(' ')}">${fmtCodigo(c.codigo)}</p>
        <p class="partner-what">${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)} · <strong>${num(c.quantidade)} ${escapeHTML(c.unidade)}</strong></p>
        <p class="partner-timer ${s < 120 ? 'is-ending' : ''}">${s > 0 ? `${icon('calendar')} Vale por <strong id="pg-timer">${mmss(s)}</strong>` : 'Código vencido'}</p>
        <p class="form-hint">O jogador escaneia o QR com a câmera ou digita os 6 números em Missões → Parceiros.</p>
        <div class="p-show-actions">
            ${s > 0 ? `<button type="button" class="btn btn-primary btn-sm" data-tela-cheia>${ICONE_EXPANDIR} Tela cheia</button>` : ''}
            <button type="button" class="btn btn-danger-outline btn-sm" data-cancelar="${c.id}">Cancelar código</button>
        </div>`;
    hydrateIcons(box);
    renderTelaCheia();
}

function renderListas() {
    const p = st.painel;
    const ativosCount = p.codigos.filter((c) => !c.usadoEm && restante(c) > 0).length;
    if ($('partner-codes-count')) {
        $('partner-codes-count').textContent = `${ativosCount} ${ativosCount === 1 ? 'ativo' : 'ativos'}`;
        $('partner-codes-count').className = `tag ${ativosCount > 0 ? 'tag-warning' : 'tag-neutral'}`;
    }
    $('partner-codes').innerHTML = p.codigos.length ? p.codigos.map((c) => {
        const s = restante(c);
        const sit = c.usadoEm ? `<span class="tag tag-success">Usado · ${escapeHTML(c.jogador || '—')}</span>`
            : s > 0 ? `<span class="tag tag-warning" data-row-timer="${c.id}">${mmss(s)}</span>` : '<span class="tag tag-neutral">Vencido</span>';
        return `<li class="partner-row ${c.id === st.atual ? 'is-current' : ''}">
            <button type="button" class="partner-row-code" data-ver="${c.id}" ${c.usadoEm || s <= 0 ? 'disabled' : ''}>${fmtCodigo(c.codigo)}</button>
            <span class="partner-row-what">${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)} · <strong>${num(c.quantidade)} ${escapeHTML(c.unidade)}</strong>${ADMIN ? ` <span class="muted">· ${atendente(c)}</span>` : ''}</span>
            ${sit}
            ${!c.usadoEm && s > 0 ? `<button type="button" class="link-btn" data-cancelar="${c.id}">Cancelar</button>` : ''}
        </li>`;
    }).join('') : '<li class="empty-state">Nenhum código na última hora.</li>';

    $('partner-totals').innerHTML = p.totais.length ? p.totais.map((t) => `<li><strong>${num(t.total)}</strong><span>${escapeHTML(t.unidade)}</span>
        <small>${escapeHTML(t.atividade)} · ${t.verificacoes} ${t.verificacoes === 1 ? 'verificação' : 'verificações'}</small></li>`).join('')
        : `<li class="empty-state">${ADMIN ? 'As ações confirmadas aqui aparecem somadas.' : 'Os seus atendimentos de hoje aparecem somados.'}</li>`;

    const colunasTabela = ADMIN ? 5 : 4;
    $('partner-history').innerHTML = p.historico.length ? p.historico.map((c) => `<tr><td><strong>${escapeHTML(c.jogador || '—')}</strong></td>
        <td>${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)}</td><td>${num(c.quantidade)} ${escapeHTML(c.unidade)}</td>${ADMIN ? `<td>${atendente(c)}</td>` : ''}<td class="is-num muted">${quando(c.usadoEm)}</td></tr>`).join('')
        : `<tr><td colspan="${colunasTabela}" class="empty-state">${ADMIN ? 'Nenhuma verificação ainda.' : 'Nenhum atendimento seu hoje.'}</td></tr>`;
}

function aplicar(painel) {
    const antes = st.painel?.codigos.find((c) => c.id === st.atual);
    st.painel = painel;
    st.desvio = painel.agora - Math.floor(Date.now() / 1000);   // relógio do servidor
    if (!st.atual) {
        const primeiroAtivo = painel.codigos.find((c) => !c.usadoEm && restante(c) > 0);
        if (primeiroAtivo) st.atual = primeiroAtivo.id;
    }
    const agora = painel.codigos.find((c) => c.id === st.atual);
    if (antes && !antes.usadoEm && agora?.usadoEm) {
        tocar('ding');   // aviso sonoro no balcão (RN96)
        toast(`${agora.jogador} confirmou: ${agora.atividade} · ${num(agora.quantidade)} ${agora.unidade}`);
    }
    marcarSincronizado();
    renderAtual();
    renderListas();
}

function passoAtividade(a) {
    if (!a) return 1;
    return a.fixo && a.fixo > 0 ? Number(a.fixo) : 1;
}

/** Unidade curta para caber dentro do visor numérico e nos atalhos rápidos (ex.: "kg", "mudas", "L"). */
function unidadeCurta(unidade) {
    const u = String(unidade || '').trim();
    return u.split(/\s+/)[0] || u;
}

function renderFormulario() {
    const sel = $('pg-atividade');
    const cards = $('pg-atividades-cards');
    const antes = sel.value;
    sel.innerHTML = st.painel.atividades.map((a) => `<option value="${a.id}">${escapeHTML(a.nome)}</option>`).join('');
    if (st.painel.atividades.some((a) => String(a.id) === antes)) sel.value = antes;

    const desenharCartoes = () => {
        if (!cards) return;
        const atualId = Number(sel.value);
        cards.innerHTML = st.painel.atividades.map((a) => {
            const ativo = a.id === atualId;
            return `<button type="button" role="radio" aria-checked="${ativo}" class="p-ativ-card ${ativo ? 'is-selected' : ''}" data-ativ-id="${a.id}">
                <span class="p-ativ-bar cat-${escapeHTML(a.categoria || 'nature')}" aria-hidden="true"></span>
                <span class="p-ativ-body">
                    <strong>${escapeHTML(a.nome)}</strong>
                    <small>em ${escapeHTML(a.unidade)} · até ${num(a.max)}</small>
                </span>
            </button>`;
        }).join('');
    };

    const atualizar = (trocarPadrao = false) => {
        const a = st.painel.atividades.find((x) => x.id === Number(sel.value));
        if (!a) return;
        desenharCartoes();
        const curta = unidadeCurta(a.unidade);
        $('pg-unidade').textContent = `(em ${a.unidade})`;
        if ($('pg-unidade-curta')) $('pg-unidade-curta').textContent = curta;
        $('pg-max').textContent = `Até ${num(a.max)} ${a.unidade} por código · validade de ${st.painel.validadeMin} min para 1 jogador.`;
        const inp = $('pg-quantidade');
        inp.max = a.max;
        const base = passoAtividade(a);
        if (trocarPadrao || !inp.value) inp.value = base;
        if ($('pg-atalhos')) {
            const opcoes = [...new Set([base, +(base * 2).toFixed(2), +(base * 5).toFixed(2), +(base * 10).toFixed(2)].filter((v) => v >= 0.01 && v <= a.max))];
            $('pg-atalhos').innerHTML = opcoes.map((v) => `<button type="button" class="p-atalho-chip" data-qtd-atalho="${v}">${num(v)} ${escapeHTML(curta)}</button>`).join('');
        }
    };

    sel.onchange = () => atualizar(true);
    if (cards && !cards.dataset.bound) {
        cards.dataset.bound = '1';
        cards.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-ativ-id]');
            if (!btn) return;
            sel.value = btn.dataset.ativId;
            atualizar(true);
        });
    }
    atualizar(!($('pg-quantidade').value));
}

function ajustarQuantidade(direcao) {
    const sel = $('pg-atividade');
    const a = st.painel?.atividades.find((x) => x.id === Number(sel?.value));
    const inp = $('pg-quantidade');
    if (!a || !inp) return;
    const passo = passoAtividade(a);
    const atual = parseFloat(inp.value) || 0;
    const novo = Math.min(a.max, Math.max(0.01, +(atual + direcao * passo).toFixed(2)));
    inp.value = novo;
}

function iniciarAtender() {
    $('pg-menos')?.addEventListener('click', () => ajustarQuantidade(-1));
    $('pg-mais')?.addEventListener('click', () => ajustarQuantidade(1));
    $('pg-atalhos')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-qtd-atalho]');
        if (b) $('pg-quantidade').value = b.dataset.qtdAtalho;
    });

    $('partner-gen').addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = e.target.querySelector('[type=submit]');
        botao.disabled = true;
        try {
            const r = await api('gerar', { atividade_id: $('pg-atividade').value, quantidade: $('pg-quantidade').value });
            st.atual = r.codigo.id;
            aplicar(r.painel);
            $('partner-show').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (err) { toast(err.message, 'error'); } finally { botao.disabled = false; }
    });

    $('pp-atender').addEventListener('click', async (e) => {
        if (e.target.closest('[data-tela-cheia]')) {
            st.telaCheia = true;
            renderTelaCheia();
            return;
        }
        const ver = e.target.closest('[data-ver]');
        if (ver) { st.atual = Number(ver.dataset.ver); renderAtual(); renderListas(); return; }
        const b = e.target.closest('[data-cancelar]');
        if (!b || !confirm('Cancelar este código? O jogador não poderá mais usá-lo.')) return;
        try {
            const r = await api('cancelar', { codigo_id: b.dataset.cancelar });
            toast(r.mensagem);
            if (Number(b.dataset.cancelar) === st.atual) { st.atual = null; st.telaCheia = false; }
            aplicar(r.painel);
            if (ADMIN && !$('pp-geral')?.hidden) carregarVisaoGeral();
        } catch (err) { toast(err.message, 'error'); }
    });

    $('partner-fullscreen')?.addEventListener('click', (e) => {
        if (e.target.id === 'partner-fullscreen' || e.target.closest('[data-fechar-cheia]')) {
            st.telaCheia = false;
            renderTelaCheia();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && st.telaCheia) {
            st.telaCheia = false;
            renderTelaCheia();
        }
    });

    // Relógio (1 s) e consulta ao servidor (10 s) para ver quando o jogador usou o código
    setInterval(() => {
        const c = st.painel?.codigos.find((x) => x.id === st.atual);
        if (c && !c.usadoEm) {
            const s = restante(c);
            if (s <= 0) renderAtual();
            else {
                if ($('pg-timer')) $('pg-timer').textContent = mmss(s);
                if ($('pf-timer')) $('pf-timer').textContent = mmss(s);
            }
        }
        document.querySelectorAll('[data-row-timer]').forEach((el) => {
            const cod = st.painel?.codigos.find((x) => x.id === Number(el.dataset.rowTimer));
            if (!cod) return;
            const s = restante(cod);
            if (s <= 0) renderListas();
            else el.textContent = mmss(s);
        });
    }, 1000);

    setInterval(async () => {
        if (document.hidden) return;
        try { aplicar((await api('painel')).painel); } catch { /* tenta de novo no próximo ciclo */ }
    }, CONSULTA_MS);
}

/** Trocar a própria senha (funcionário no topo do balcão; administrador na aba Estabelecimento). */
function iniciarSenha() {
    const form = $('minha-senha');
    if (!form) return;
    $('p-func-senha-btn')?.addEventListener('click', () => {
        const card = $('p-func-senha-card');
        if (!card) return;
        card.open = !card.open;
        $('p-func-senha-btn').setAttribute('aria-expanded', String(card.open));
        if (card.open) $('ms-atual')?.focus();
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const erro = form.querySelector('.field-error');
        erro.textContent = '';
        if ($('ms-nova').value !== $('ms-confirmar').value) { erro.textContent = 'As senhas não conferem.'; return; }
        try {
            const r = await api('senha', { atual: $('ms-atual').value, nova: $('ms-nova').value, confirmar: $('ms-confirmar').value });
            form.reset();
            const details = form.closest('details');
            if (details) details.open = false;
            $('p-func-senha-btn')?.setAttribute('aria-expanded', 'false');
            toast(r.mensagem);
        } catch (err) { erro.textContent = err.message; }
    });
}

/* ---------------------------------------------------------------------------
   Comparação com período anterior (KPIs de Visão geral e Relatório)
   --------------------------------------------------------------------------- */

function deltaPercentual(atual, ant, dias, sufixo = '') {
    if (!dias || !ant) return sufixo ? `<span class="muted">${sufixo}</span>` : '<span class="muted">Desde o início</span>';
    const rotulo = `vs ${dias} dias anteriores`;
    if (ant === 0) {
        return atual > 0
            ? `<span class="p-delta-tag is-up">▲ novo</span> <span class="muted">${rotulo}</span>`
            : `<span class="p-delta-tag is-flat">= 0%</span> <span class="muted">${rotulo}</span>`;
    }
    const pct = Math.round(((atual - ant) / ant) * 100);
    if (pct > 0) return `<span class="p-delta-tag is-up">▲ +${pct}%</span> <span class="muted">${rotulo}</span>`;
    if (pct < 0) return `<span class="p-delta-tag is-down">▼ ${pct}%</span> <span class="muted">${rotulo}</span>`;
    return `<span class="p-delta-tag is-flat">= 0%</span> <span class="muted">${rotulo}</span>`;
}

function deltaPontos(atual, ant, dias) {
    if (!dias || ant === null || ant === undefined || atual === null || atual === undefined) {
        return '<span class="muted">códigos usados ÷ gerados</span>';
    }
    const diff = +(atual - ant).toFixed(1);
    const rotulo = `vs ${dias} dias anteriores`;
    if (diff > 0) return `<span class="p-delta-tag is-up">▲ +${num(diff)} p.p.</span> <span class="muted">${rotulo}</span>`;
    if (diff < 0) return `<span class="p-delta-tag is-down">▼ ${num(diff)} p.p.</span> <span class="muted">${rotulo}</span>`;
    return `<span class="p-delta-tag is-flat">= 0 p.p.</span> <span class="muted">${rotulo}</span>`;
}

function iniciaisNome(nome) {
    const partes = String(nome || 'A').trim().split(/\s+/);
    return ((partes[0]?.[0] || '') + (partes[1]?.[0] || partes[0]?.[1] || '')).toUpperCase();
}

/* ---------------------------------------------------------------------------
   Visão geral (administrador)
   --------------------------------------------------------------------------- */

const ROTULO_SITUACAO = {
    usado: ['Usado', 'tag-success'],
    aguardando: ['Aguardando', 'tag-warning'],
    vencido: ['Vencido', 'tag-neutral'],
    cancelado: ['Cancelado', 'tag-error'],
};

async function carregarVisaoGeral() {
    if (!$('pp-geral')) return;
    const dias = Number($('vg-periodo')?.value ?? 7);
    try {
        const [{ relatorio: r }, { atendimentos: ult }] = await Promise.all([
            api('relatorio', { dias }),
            api('atendimentos', { funcionario: 'todos', situacao: 'todas', atividade: 0, dias: 0, pagina: 1, por_pagina: 10 }),
        ]);
        const k = r.resumo;
        const ant = r.anterior;
        const porUnidadeMap = new Map();
        (r.porAtividade || []).forEach((a) => {
            const u = a.unidade || 'unidades';
            const atual = porUnidadeMap.get(u) || { unidade: u, total: 0, verificacoes: 0, atividades: [] };
            atual.total = +(atual.total + Number(a.total || 0)).toFixed(2);
            atual.verificacoes += Number(a.verificacoes || 0);
            atual.atividades.push(a.atividade);
            porUnidadeMap.set(u, atual);
        });
        const porUnidade = [...porUnidadeMap.values()];
        const impPrincipal = porUnidade[0];
        const impValor = impPrincipal ? `${num(impPrincipal.total)} <span class="p-kpi-unit">${escapeHTML(impPrincipal.unidade)}</span>` : '0';
        const impSub = impPrincipal
            ? (porUnidade.length > 1
                ? `Também: ${porUnidade.slice(1).map((u) => `${num(u.total)} ${escapeHTML(u.unidade)}`).join(' · ')}`
                : escapeHTML(impPrincipal.atividades.join(' + ')))
            : 'Sem verificações no período';

        $('vg-kpis').innerHTML = `
            <div class="card p-kpi-card">
                <span class="p-rotulo">Códigos usados</span>
                <strong class="p-kpi-num">${numero(k.usados, 0)}</strong>
                <span class="p-kpi-delta">${deltaPercentual(k.usados, ant?.usados ?? null, r.dias, `${numero(k.gerados, 0)} códigos gerados`)}</span>
            </div>
            <div class="card p-kpi-card">
                <span class="p-rotulo">Jogadores atendidos</span>
                <strong class="p-kpi-num">${numero(k.jogadores, 0)}</strong>
                <span class="p-kpi-delta">${deltaPercentual(k.jogadores, ant?.jogadores ?? null, r.dias, `${numero(k.voltaram, 0)} voltaram`)}</span>
            </div>
            <div class="card p-kpi-card">
                <span class="p-rotulo">Taxa de uso</span>
                <strong class="p-kpi-num">${k.taxaUso === null ? '—' : `${numero(k.taxaUso)}%`}</strong>
                <span class="p-kpi-delta">${deltaPontos(k.taxaUso, ant?.taxaUso ?? null, r.dias)}</span>
            </div>
            <div class="card p-kpi-card">
                <span class="p-rotulo">Impacto confirmado</span>
                <strong class="p-kpi-num">${impValor}</strong>
                <span class="p-kpi-delta muted">${impSub}</span>
            </div>`;

        const diasRot = r.porDia.map((d, i, todos) => {
            const [, m, dd] = d.data.split('-');
            return todos.length <= 14 || i % 5 === 0 || i === todos.length - 1 ? `${dd}/${m}` : '';
        });
        $('vg-grafico-sub').textContent = r.dias > 0 && r.dias <= 30 ? `Últimos ${r.dias} dias` : 'Últimos 30 dias';
        $('vg-grafico').innerHTML = colunas(diasRot, r.porDia.map((d) => d.n), { titulo: 'Atendimentos confirmados por dia' });

        $('vg-equipe').innerHTML = r.porFuncionario.length
            ? r.porFuncionario.slice(0, 6).map((f, idx) => `
                <li class="p-vg-membro">
                    <span class="p-avatar-ini av-${idx % 5}" aria-hidden="true">${escapeHTML(iniciaisNome(f.atendente))}</span>
                    <div class="p-vg-membro-info">
                        <strong>${escapeHTML(f.atendente)}</strong>
                        <span>${f.gerados} ${f.gerados === 1 ? 'gerado' : 'gerados'} · ${f.jogadores} ${f.jogadores === 1 ? 'jogador' : 'jogadores'}</span>
                    </div>
                    <span class="p-vg-membro-qtd" title="Códigos usados">${f.usados}</span>
                </li>`).join('')
            : '<li class="empty-state">Nenhum atendimento no período.</li>';

        const recentes = ult.itens.slice(0, 6);
        $('vg-ultimos').innerHTML = recentes.length
            ? recentes.map((c) => {
                const [rotSit, clsSit] = ROTULO_SITUACAO[c.situacao] || ['—', 'tag-neutral'];
                const detalheSit = c.situacao === 'usado' && c.jogador ? `${rotSit} · ${escapeHTML(c.jogador)}` : rotSit;
                return `<tr>
                    <td><span class="p-code-mono">${fmtCodigo(c.codigo)}</span></td>
                    <td>${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)}</td>
                    <td>${num(c.quantidade)} ${escapeHTML(c.unidade)}</td>
                    <td>${atendente(c)}</td>
                    <td><span class="tag ${clsSit}">${detalheSit}</span></td>
                    <td class="is-num muted">${quando(c.usadoEm || c.criadoEm)}</td>
                </tr>`;
            }).join('')
            : '<tr><td colspan="6" class="empty-state">Nenhum código gerado ainda.</td></tr>';
    } catch (err) {
        $('vg-grafico').innerHTML = `<p class="field-error">${escapeHTML(err.message)}</p>`;
    }
}

/* ---------------------------------------------------------------------------
   Atendimentos (administrador): paginados no servidor, com filtros
   --------------------------------------------------------------------------- */

const SITUACOES = [['todas', 'Todas'], ['usado', 'Usados'], ['aguardando', 'Aguardando'], ['vencido', 'Vencidos'], ['cancelado', 'Cancelados']];
const CHAVE_TAMANHO = 'ecocity-parceiro-por-pagina';
const at = { filtros: { funcionario: 'todos', situacao: 'todas', atividade: '0', dias: '0' }, pagina: 1, dados: null, equipe: [] };

async function carregarAtendimentos(pagina = at.pagina) {
    const raiz = $('atendimentos-app');
    try {
        const r = await api('atendimentos', { ...at.filtros, pagina, por_pagina: tamanhoGuardado(CHAVE_TAMANHO, [10, 20, 50], 20) });
        at.dados = r.atendimentos;
        at.pagina = r.atendimentos.pagina;
        desenharAtendimentos();
    } catch (err) { raiz.innerHTML = `<p class="field-error">${escapeHTML(err.message)}</p>`; }
}

function desenharAtendimentos() {
    const d = at.dados;
    const opcao = (v, t, atual) => `<option value="${escapeHTML(String(v))}" ${String(v) === String(atual) ? 'selected' : ''}>${escapeHTML(t)}</option>`;
    $('atendimentos-app').innerHTML = `
        <div class="lista-barra partner-filtros">
            <label>Atendente <select class="form-input" data-filtro="funcionario">${opcao('todos', 'Todos', at.filtros.funcionario)}${opcao('admin', 'Administrador', at.filtros.funcionario)}
                ${at.equipe.map((f) => opcao(f.id, f.nome + (f.ativo ? '' : ' (desativado)'), at.filtros.funcionario)).join('')}</select></label>
            <label>Atividade <select class="form-input" data-filtro="atividade">${opcao(0, 'Todas', at.filtros.atividade)}
                ${(st.painel?.atividadesTodas ?? st.painel?.atividades ?? []).map((a) => opcao(a.id, a.nome, at.filtros.atividade)).join('')}</select></label>
            <label>Período <select class="form-input" data-filtro="dias">${[[0, 'Desde o início'], [7, '7 dias'], [30, '30 dias'], [90, '90 dias']].map(([v, t]) => opcao(v, t, at.filtros.dias)).join('')}</select></label>
            ${seletorTamanho(d.porPagina)}
        </div>
        <div class="lista-filtros" role="group" aria-label="Situação">${pilulas(SITUACOES, d.situacao, d.contagem)}</div>
        ${d.itens.length ? `<div class="table-scroll"><table class="data-table p-table">
            <thead><tr><th scope="col">Código</th><th scope="col">Quando</th><th scope="col">Atendente</th><th scope="col">Atividade</th><th scope="col">Quantidade</th><th scope="col">Jogador</th><th scope="col">Situação</th></tr></thead>
            <tbody>${d.itens.map((c) => `<tr><td><span class="p-code-mono">${fmtCodigo(c.codigo)}</span></td><td>${quando(c.criadoEm)}</td><td>${atendente(c)}</td>
                <td>${bolinhaCategoria(c.atividadeId || c.atividade)}${escapeHTML(c.atividade)}</td>
                <td>${num(c.quantidade)} ${escapeHTML(c.unidade)}</td><td>${c.jogador ? escapeHTML(c.jogador) : '—'}</td>
                <td><span class="tag ${ROTULO_SITUACAO[c.situacao][1]}">${ROTULO_SITUACAO[c.situacao][0]}</span>${c.usadoEm ? ` <span class="muted">${quando(c.usadoEm)}</span>` : ''}</td></tr>`).join('')}</tbody>
        </table></div>` : '<p class="empty-state">Nenhum atendimento com estes filtros.</p>'}
        <nav class="pager" aria-label="Páginas"></nav>`;
    pager($('atendimentos-app').querySelector('.pager'), d.pagina, d.total, d.porPagina, 'atendimentos');
}

function iniciarAtendimentos() {
    const raiz = $('atendimentos-app');
    raiz.addEventListener('change', (e) => {
        const f = e.target.closest('[data-filtro]');
        if (f) { at.filtros[f.dataset.filtro] = f.value; carregarAtendimentos(1); return; }
        if (e.target.matches('[data-lista-tamanho]')) { guardarTamanho(CHAVE_TAMANHO, Number(e.target.value)); carregarAtendimentos(1); }
    });
    raiz.addEventListener('click', (e) => {
        const s = e.target.closest('[data-lista-filtro]');
        if (s) { at.filtros.situacao = s.dataset.listaFiltro; carregarAtendimentos(1); return; }
        const p = e.target.closest('[data-pagina]');
        if (p && !p.disabled) carregarAtendimentos(Number(p.dataset.pagina));
    });
}

/* ---------------------------------------------------------------------------
   Relatório (administrador)
   --------------------------------------------------------------------------- */

async function carregarRelatorio() {
    const dias = $('rel-periodo').value;
    $('rel-csv').href = `api/parceiro.php?acao=csv&dias=${dias}`;
    const raiz = $('relatorio-app');
    try {
        const { relatorio: r } = await api('relatorio', { dias });
        const k = r.resumo;
        const ant = r.anterior;
        const diasRot = r.porDia.map((d, i, todos) => {
            const [, m, dd] = d.data.split('-');
            return todos.length <= 14 || i % 5 === 0 || i === todos.length - 1 ? `${dd}/${m}` : '';
        });
        raiz.innerHTML = `
            <div class="p-kpi-grid">
                <div class="card p-kpi-card">
                    <span class="p-rotulo">Verificações (usados)</span>
                    <strong class="p-kpi-num">${numero(k.usados, 0)}</strong>
                    <span class="p-kpi-delta">${deltaPercentual(k.usados, ant?.usados ?? null, r.dias)} · <span class="muted">${numero(k.gerados, 0)} gerados</span></span>
                </div>
                <div class="card p-kpi-card">
                    <span class="p-rotulo">Taxa de uso</span>
                    <strong class="p-kpi-num">${k.taxaUso === null ? '—' : `${numero(k.taxaUso)}%`}</strong>
                    <span class="p-kpi-delta">${deltaPontos(k.taxaUso, ant?.taxaUso ?? null, r.dias)}</span>
                </div>
                <div class="card p-kpi-card">
                    <span class="p-rotulo">Jogadores atendidos</span>
                    <strong class="p-kpi-num">${numero(k.jogadores, 0)}</strong>
                    <span class="p-kpi-delta">${deltaPercentual(k.jogadores, ant?.jogadores ?? null, r.dias)} · <span class="muted">${numero(k.voltaram, 0)} voltaram</span></span>
                </div>
                <div class="card p-kpi-card">
                    <span class="p-rotulo">Sem uso</span>
                    <strong class="p-kpi-num">${numero(k.vencidos + k.cancelados, 0)}</strong>
                    <span class="p-kpi-delta muted">${numero(k.vencidos, 0)} vencidos · ${numero(k.cancelados, 0)} cancelados</span>
                </div>
            </div>
            <div class="partner-grid">
                <section class="card"><h3 class="card-title">${icon('chart')} Impacto confirmado</h3>
                    ${r.porAtividade.length ? `<ul class="partner-totals">${r.porAtividade.map((a) => `<li><strong>${num(a.total)}</strong><span>${escapeHTML(a.unidade)}</span>
                        <small>${escapeHTML(a.atividade)} · ${a.verificacoes} ${a.verificacoes === 1 ? 'verificação' : 'verificações'}</small></li>`).join('')}</ul>` : '<p class="muted">Sem verificações no período.</p>'}
                </section>
                <section class="card"><h3 class="card-title">${icon('users')} Verificações por atendente</h3>
                    ${barras(r.porFuncionario.filter((f) => f.usados > 0).map((f) => ({ rotulo: escapeHTML(f.atendente), valor: f.usados, texto: numero(f.usados, 0) })), { vazio: 'Sem verificações no período.' })}
                </section>
            </div>
            <section class="card"><h3 class="card-title">${icon('calendar')} Verificações por dia ${r.dias === 0 || r.dias > 30 ? '<span class="muted">(últimos 30 dias)</span>' : ''}</h3>
                ${colunas(diasRot, r.porDia.map((d) => d.n), { titulo: 'Verificações por dia' })}
            </section>
            <section class="card"><h3 class="card-title">${icon('calendar')} Dias e horários de mais movimento</h3>${mapaCalor(r.calor)}</section>
            <section class="card"><h3 class="card-title">${icon('scroll')} Por atendente</h3>
                ${r.porFuncionario.length ? `<div class="table-scroll"><table class="data-table p-table"><thead><tr><th scope="col">Atendente</th><th scope="col">Códigos gerados</th><th scope="col">Usados</th><th scope="col">Vencidos</th><th scope="col">Cancelados</th><th scope="col">Jogadores</th></tr></thead>
                <tbody>${r.porFuncionario.map((f) => `<tr><td><strong>${escapeHTML(f.atendente)}</strong></td><td>${f.gerados}</td><td>${f.usados}</td><td>${f.vencidos}</td><td>${f.cancelados}</td><td>${f.jogadores}</td></tr>`).join('')}</tbody></table></div>`
                : '<p class="muted">Nenhum código gerado no período.</p>'}
            </section>`;
        hydrateIcons(raiz);
    } catch (err) { raiz.innerHTML = `<p class="field-error">${escapeHTML(err.message)}</p>`; }
}

/* ---------------------------------------------------------------------------
   Funcionários (administrador)
   --------------------------------------------------------------------------- */

function desenharEquipe(lista) {
    at.equipe = lista;
    const ativos = lista.filter((f) => f.ativo).length;
    if ($('func-contagem')) $('func-contagem').textContent = `${ativos} ${ativos === 1 ? 'ativo' : 'ativos'} de ${lista.length}`;
    $('func-lista').innerHTML = lista.length ? lista.map((f) => `<li class="partner-membro ${f.ativo ? '' : 'is-inativo'}">
        <div class="partner-membro-dados">
            <strong>${escapeHTML(f.nome)}</strong>
            <span class="muted">usuário <code>${escapeHTML(f.usuario)}</code> · ${f.ultimoAcesso ? `último acesso ${quando(f.ultimoAcesso)}` : 'ainda não entrou'} · ${f.atendimentos30} ${f.atendimentos30 === 1 ? 'atendimento' : 'atendimentos'} em 30 dias</span>
        </div>
        <span class="tag ${f.ativo ? 'tag-success' : 'tag-neutral'}">${f.ativo ? 'Ativo' : 'Desativado'}</span>
        <div class="partner-membro-acoes">
            <button type="button" class="btn btn-outline btn-sm" data-func="${f.id}" data-op="senha">Redefinir senha</button>
            <button type="button" class="btn ${f.ativo ? 'btn-danger-outline' : 'btn-secondary'} btn-sm" data-func="${f.id}" data-op="${f.ativo ? 'desativar' : 'reativar'}">${f.ativo ? 'Desativar' : 'Reativar'}</button>
        </div>
    </li>`).join('') : '<li class="empty-state">Nenhum funcionário ainda. Crie o primeiro ao lado.</li>';
}

function iniciarFuncionarios() {
    $('func-focar-novo')?.addEventListener('click', () => $('fn-nome')?.focus());
    const form = $('func-novo');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const erro = form.querySelector('.field-error');
        erro.textContent = '';
        try {
            const r = await api('funcionario_criar', { nome: $('fn-nome').value, usuario: $('fn-usuario').value, senha: $('fn-senha').value });
            form.reset();
            desenharEquipe(r.funcionarios);
            toast(r.mensagem);
        } catch (err) { erro.textContent = err.message; }
    });
    $('func-lista').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-func]');
        if (!b) return;
        const f = at.equipe.find((x) => x.id === Number(b.dataset.func));
        const campos = { funcionario_id: b.dataset.func, operacao: b.dataset.op };
        if (b.dataset.op === 'senha') {
            const senha = prompt(`Nova senha para ${f.nome} (6 a 72 caracteres). A sessão aberta dessa pessoa será encerrada.`);
            if (!senha) return;
            campos.senha = senha;
        } else if (b.dataset.op === 'desativar' && !confirm(`Desativar o acesso de ${f.nome}? A pessoa sai na hora e não consegue mais entrar; os atendimentos continuam no histórico.`)) {
            return;
        }
        b.disabled = true;
        try {
            const r = await api('funcionario_alterar', campos);
            desenharEquipe(r.funcionarios);
            toast(r.mensagem);
        } catch (err) { toast(err.message, 'error'); b.disabled = false; }
    });
}

/* ---------------------------------------------------------------------------
   Estabelecimento (administrador)
   --------------------------------------------------------------------------- */

const SITUACAO_PEDIDO = { pendente: ['Aguardando a equipe do EcoCity', 'tag-warning'], aprovado: ['Aprovado', 'tag-success'], recusado: ['Recusado', 'tag-error'] };

function desenharEstabelecimento(est) {
    const d = est.dados;
    ['nome', 'responsavel', 'endereco', 'descricao'].forEach((k) => { $(`ed-${k}`).value = d[k] ?? ''; });
    $('ed-email').textContent = `E-mail de acesso do administrador: ${d.email} (não pode ser alterado).`;
    $('est-atividades').innerHTML = est.autorizadas.map((a) => `<li><span class="reward-chip chip-area cat-${a.categoria}">${escapeHTML(a.nome)}</span>
        <span class="muted">até ${num(a.max)} ${escapeHTML(a.unidade)} por código</span>
        ${est.autorizadas.length > 1 ? `<button type="button" class="link-btn" data-remover="${a.id}">Remover</button>` : ''}</li>`).join('');
    $('est-pedir').hidden = !est.podePedir.length;
    $('ep-atividade').innerHTML = est.podePedir.map((a) => `<option value="${a.id}">${escapeHTML(a.nome)}</option>`).join('');
    $('est-pedidos').innerHTML = est.pedidos.map((x) => `<li><strong>${escapeHTML(x.atividade)}</strong>
        <span class="tag ${SITUACAO_PEDIDO[x.status][1]}">${SITUACAO_PEDIDO[x.status][0]}</span>
        <span class="muted">pedido em ${quando(x.data)}${x.motivo ? ` · motivo: ${escapeHTML(x.motivo)}` : ''}</span></li>`).join('');
}

function iniciarEstabelecimento() {
    const form = $('est-dados');
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const erro = form.querySelector('.field-error');
        erro.textContent = '';
        const campos = Object.fromEntries(['nome', 'responsavel', 'endereco', 'descricao'].map((k) => [k, $(`ed-${k}`).value]));
        try {
            const r = await api('estabelecimento_salvar', campos);
            desenharEstabelecimento(r.estabelecimento);
            const h1 = $('p-est-nome') || document.querySelector('.player-info h1');
            if (h1) h1.textContent = r.estabelecimento.dados.nome;
            if ($('p-est-endereco') && r.estabelecimento.dados.endereco) $('p-est-endereco').textContent = r.estabelecimento.dados.endereco;
            toast(r.mensagem);
        } catch (err) { erro.textContent = err.message; }
    });
    $('est-atividades').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-remover]');
        if (!b || !confirm('Deixar de verificar esta atividade? Os códigos dela em aberto serão cancelados; para voltar, será preciso pedir de novo.')) return;
        try {
            const r = await api('atividade_remover', { atividade_id: b.dataset.remover });
            desenharEstabelecimento(r.estabelecimento);
            aplicar(r.painel);
            renderFormulario();
            toast(r.mensagem);
        } catch (err) { toast(err.message, 'error'); }
    });
    $('est-pedir').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const r = await api('atividade_pedir', { atividade_id: $('ep-atividade').value });
            desenharEstabelecimento(r.estabelecimento);
            toast(r.mensagem);
        } catch (err) { toast(err.message, 'error'); }
    });
}

/* ---------------------------------------------------------------------------
   Menu lateral responsivo (gaveta no celular) e aviso sonoro
   --------------------------------------------------------------------------- */

function iniciarMenuLateral() {
    const side = $('p-sidebar');
    const overlay = $('p-sidebar-overlay');
    const abrir = $('p-menu-abrir');
    const fechar = $('p-menu-fechar');
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

/* Aviso sonoro (RN96): o parceiro não tem conta de jogador, então a escolha fica no navegador */
const CHAVE_SOM = 'ecocity-parceiro-mudo';
function lerMudo() { try { return localStorage.getItem(CHAVE_SOM) === '1'; } catch { return false; } }
function iniciarSomParceiro() {
    const b = $('som-botao');
    if (!b) return;
    const pintar = (mudo) => {
        b.setAttribute('aria-pressed', String(mudo));
        b.title = mudo ? 'Ligar o aviso sonoro' : 'Desligar o aviso sonoro';
        b.setAttribute('aria-label', b.title);
        b.innerHTML = icon(mudo ? 'volume-off' : 'volume');
    };
    let mudo = lerMudo();
    iniciarSom({ mudo, efeitos: true, interface: false, ambiente: false, volume: 80 });
    pintar(mudo);
    b.addEventListener('click', () => {
        mudo = !mudo;
        try { localStorage.setItem(CHAVE_SOM, mudo ? '1' : '0'); } catch { /* só nesta visita */ }
        aplicarPreferencias({ mudo });
        pintar(mudo);
        if (!mudo) tocar('ding');
    });
}

async function iniciar() {
    hydrateIcons();
    iniciarSomParceiro();
    iniciarMenuLateral();
    try {
        aplicar((await api('painel')).painel);
        renderFormulario();
    } catch (e) { toast(e.message, 'error'); }
    iniciarAtender();
    iniciarSenha();
    if (!ADMIN || !$('partner-tabs')) return;

    const ativarAba = initTabs($('partner-tabs'));
    iniciarAtendimentos();
    iniciarFuncionarios();
    iniciarEstabelecimento();
    $('rel-periodo').addEventListener('change', carregarRelatorio);
    $('vg-periodo')?.addEventListener('change', carregarVisaoGeral);

    // Cada aba carrega ao ser aberta (e de novo a cada abertura, para trazer o que mudou)
    const carregar = {
        'pt-geral': carregarVisaoGeral,
        'pt-atendimentos': async () => { at.equipe = (await api('funcionarios')).funcionarios; carregarAtendimentos(1); },
        'pt-relatorio': carregarRelatorio,
        'pt-funcionarios': async () => desenharEquipe((await api('funcionarios')).funcionarios),
        'pt-estabelecimento': async () => desenharEstabelecimento((await api('estabelecimento')).estabelecimento),
    };

    const irParaAba = (tabId) => {
        const tab = $(tabId);
        if (!tab) return;
        ativarAba(tab, false);
        carregar[tabId]?.().catch((err) => toast(err.message, 'error'));
    };

    $('vg-ir-atender')?.addEventListener('click', () => irParaAba('pt-atender'));
    document.addEventListener('click', (e) => {
        const ir = e.target.closest('[data-ir-aba]');
        if (ir) irParaAba(ir.dataset.irAba);
    });

    $('partner-tabs').addEventListener('click', (e) => {
        const tab = e.target.closest('[role="tab"]');
        carregar[tab?.id]?.().catch((err) => toast(err.message, 'error'));
    });
    $('partner-tabs').addEventListener('keydown', (e) => {
        if (['ArrowUp', 'ArrowDown'].includes(e.key)) {
            const tabs = [...$('partner-tabs').querySelectorAll('[role="tab"]:not([hidden])')];
            const i = tabs.indexOf(document.activeElement);
            if (i >= 0) {
                e.preventDefault();
                const prox = tabs[(i + (e.key === 'ArrowDown' ? 1 : -1) + tabs.length) % tabs.length];
                ativarAba(prox, true);
                carregar[prox.id]?.().catch((err) => toast(err.message, 'error'));
            }
        }
    });
    $('partner-tabs').addEventListener('keyup', (e) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
        carregar[document.activeElement?.id]?.().catch((err) => toast(err.message, 'error'));
    });

    // Carrega a Visão geral logo na entrada do administrador
    carregarVisaoGeral();
}

iniciar();
