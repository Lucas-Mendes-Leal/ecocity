/**
 * Subaba Auditoria em Administração › Conteúdo (RN101).
 * Exibe registros imutáveis gravados pelos triggers no banco via `api/auditoria.php`
 * (consulta controlada somente leitura, com diff Antes -> Depois e exportação CSV).
 */
import { escapeHTML, pager, toast } from './dom.js';
import { icon, hydrateIcons } from './icons.js';

const estado = {
    tabela: '',
    acao: '',
    ator: '',
    dias: 30,
    busca: '',
    porPagina: 20,
    pagina: 1,
    carregando: false,
    dados: null,
    erro: null,
};

const TAG_ACAO = {
    INSERT: 'tag-success',
    UPDATE: 'tag-warning',
    DELETE: 'tag-error',
};

const TAG_ATOR = {
    admin: 'tag-success',
    usuario: 'tag-neutral',
    parceiro: 'tag-warning',
    sistema: 'tag-neutral',
    sql_direto: 'tag-error',
};

function paramsUrl(acaoApi) {
    const p = new URLSearchParams();
    p.set('acao', acaoApi);
    if (estado.tabela) p.set('tabela', estado.tabela);
    if (estado.acao) p.set('comando', estado.acao);
    if (estado.ator) p.set('ator', estado.ator);
    p.set('dias', String(estado.dias));
    if (estado.busca.trim()) p.set('busca', estado.busca.trim());
    p.set('porPagina', String(estado.porPagina));
    p.set('pagina', String(estado.pagina));
    return `api/auditoria.php?${p.toString()}`;
}

function fmtDataHoraBR(sqlDate) {
    if (!sqlDate) return '—';
    const [dataParte, horaParte = ''] = String(sqlDate).split(' ');
    const dataBR = dataParte.split('-').reverse().join('/');
    return `${dataBR} ${horaParte.slice(0, 8)}`.trim();
}

function resumirTexto(v, max = 52) {
    const s = String(v ?? '');
    return s.length > max ? `${s.slice(0, max - 1)}…` : s;
}

function renderMudancas(item) {
    const diffs = item.diffs || [];
    const listaHtml = diffs.length
        ? `<ul class="aud-diffs">${diffs.slice(0, 5).map((d) => {
            if (d.antes === null) {
                return `<li><strong>${escapeHTML(d.campo)}:</strong> <span class="aud-add">+${escapeHTML(resumirTexto(d.depois))}</span></li>`;
            }
            if (d.depois === null) {
                return `<li><strong>${escapeHTML(d.campo)}:</strong> <span class="aud-del">-${escapeHTML(resumirTexto(d.antes))}</span></li>`;
            }
            return `<li><strong>${escapeHTML(d.campo)}:</strong> <span class="aud-del">${escapeHTML(resumirTexto(d.antes, 32))}</span> → <span class="aud-add">${escapeHTML(resumirTexto(d.depois, 32))}</span></li>`;
        }).join('')}${diffs.length > 5 ? `<li class="muted">+${diffs.length - 5} campo(s)</li>` : ''}</ul>`
        : '<span class="muted">Sem alteração registrada nos campos monitorados.</span>';

    const temJson = item.antes !== null || item.depois !== null;
    if (!temJson) return listaHtml;

    const blocoJson = [
        item.antes !== null ? `Antes:\n${JSON.stringify(item.antes, null, 2)}` : '',
        item.depois !== null ? `Depois:\n${JSON.stringify(item.depois, null, 2)}` : '',
    ].filter(Boolean).join('\n\n');

    return `${listaHtml}<details class="aud-json-box"><summary>Ver JSON completo</summary><pre>${escapeHTML(blocoJson)}</pre></details>`;
}

function renderHtml() {
    const d = estado.dados;
    if (estado.carregando && !d) {
        return '<p class="empty-state">Carregando registros de auditoria…</p>';
    }
    if (estado.erro && !d) {
        return `<div class="alert alert-error">${escapeHTML(estado.erro)}</div>`;
    }
    if (!d) {
        return '<p class="empty-state">Nenhum dado carregado.</p>';
    }
    if (!d.pronto) {
        return `<div class="alert alert-warning">A auditoria detalhada (migração 040) ainda não está ativa neste banco de dados.</div>`;
    }

    const res = d.resumo || {};
    const opcoesTabelas = (d.tabelas || []).map((t) =>
        `<option value="${escapeHTML(t.nome)}" ${estado.tabela === t.nome ? 'selected' : ''}>${escapeHTML(t.rotulo)} (${t.total})</option>`
    ).join('');

    const opcoesDias = [
        [1, 'Últimas 24 horas'],
        [7, 'Últimos 7 dias'],
        [30, 'Últimos 30 dias'],
        [90, 'Últimos 90 dias'],
        [0, 'Todo o período'],
    ].map(([v, rot]) => `<option value="${v}" ${estado.dias === v ? 'selected' : ''}>${rot}</option>`).join('');

    const opcoesAcao = [
        ['', 'Todos os comandos'],
        ['INSERT', 'INSERT (criação)'],
        ['UPDATE', 'UPDATE (edição)'],
        ['DELETE', 'DELETE (exclusão)'],
    ].map(([v, rot]) => `<option value="${v}" ${estado.acao === v ? 'selected' : ''}>${rot}</option>`).join('');

    const opcoesAtor = [
        ['', 'Todos os autores'],
        ['admin', 'Administradores'],
        ['usuario', 'Jogadores'],
        ['parceiro', 'Parceiros / funcionários'],
        ['sistema', 'Sistema / rotas públicas'],
        ['sql_direto', 'SQL direto no banco'],
    ].map(([v, rot]) => `<option value="${v}" ${estado.ator === v ? 'selected' : ''}>${rot}</option>`).join('');

    const opcoesTam = [10, 20, 50].map((n) => `<option value="${n}" ${estado.porPagina === n ? 'selected' : ''}>${n}</option>`).join('');

    const linhas = (d.itens || []).map((item) => {
        const tagCmd = TAG_ACAO[item.acao] || 'tag-neutral';
        const tagAtor = TAG_ATOR[item.ator?.tipo] || 'tag-neutral';
        const origemTxt = item.origem ? escapeHTML(item.origem) : '<span class="muted">Fora do PHP (SQL direto)</span>';
        const ipTxt = item.ip ? `<div class="aud-sub">IP: ${escapeHTML(item.ip)}</div>` : '';
        return `<tr>
            <td class="aud-data-cell"><strong>${escapeHTML(fmtDataHoraBR(item.data))}</strong><div class="aud-sub">#${item.id}</div></td>
            <td><span class="tag ${tagCmd}">${escapeHTML(item.acao)}</span></td>
            <td class="aud-tabela-cell"><strong>${escapeHTML(item.tabelaRotulo)}</strong><div class="aud-sub aud-mono">${escapeHTML(item.tabela)} #${escapeHTML(item.registroId)}</div></td>
            <td class="aud-ator-cell"><span class="tag ${tagAtor}">${escapeHTML(item.ator?.rotulo || '—')}</span><div class="aud-ator-desc">${escapeHTML(item.ator?.descricao || '—')}</div><div class="aud-sub aud-mono">${escapeHTML(item.usuarioBanco)}</div></td>
            <td class="aud-origem-cell"><div class="aud-mono">${origemTxt}</div>${ipTxt}</td>
            <td class="aud-diff-cell">${renderMudancas(item)}</td>
        </tr>`;
    }).join('');

    return `
        <div class="aud-kpis">
            <div class="kpi-plain"><span>Eventos no filtro</span><strong>${Number(res.total || 0).toLocaleString('pt-BR')}</strong></div>
            <div class="kpi-plain"><span>INSERT</span><strong>${Number(res.inserts || 0).toLocaleString('pt-BR')}</strong></div>
            <div class="kpi-plain"><span>UPDATE</span><strong>${Number(res.updates || 0).toLocaleString('pt-BR')}</strong></div>
            <div class="kpi-plain"><span>DELETE</span><strong>${Number(res.deletes || 0).toLocaleString('pt-BR')}</strong></div>
            <div class="kpi-plain"><span>Por parceiros</span><strong>${Number(res.porParceiro || 0).toLocaleString('pt-BR')}</strong></div>
            <div class="kpi-plain ${Number(res.sqlDireto || 0) > 0 ? 'aud-kpi-alerta' : ''}"><span>SQL direto</span><strong>${Number(res.sqlDireto || 0).toLocaleString('pt-BR')}</strong></div>
        </div>

        <form class="aud-filtros" id="aud-filtros">
            <label class="form-group"><span>Período</span><select class="form-input" name="dias">${opcoesDias}</select></label>
            <label class="form-group"><span>Tabela</span><select class="form-input" name="tabela"><option value="">Todas as tabelas</option>${opcoesTabelas}</select></label>
            <label class="form-group"><span>Comando</span><select class="form-input" name="acao">${opcoesAcao}</select></label>
            <label class="form-group"><span>Quem fez</span><select class="form-input" name="ator">${opcoesAtor}</select></label>
            <label class="form-group aud-busca"><span>Buscar (registro, conta, IP ou valor)</span><input class="form-input" type="search" name="busca" maxlength="100" value="${escapeHTML(estado.busca)}" placeholder="Ex.: usuario, email, IP, rota…"></label>
            <div class="aud-acoes-topo">
                <button type="submit" class="btn btn-primary btn-sm">${icon('search')} Filtrar</button>
                <button type="button" class="btn btn-outline btn-sm" data-aud-limpar>Limpar</button>
                <a class="btn btn-outline btn-sm" href="${escapeHTML(paramsUrl('csv'))}" download>${icon('scroll')} Baixar CSV</a>
            </div>
        </form>

        <div class="aud-barra-lista">
            <span class="muted">${estado.carregando ? 'Atualizando…' : `${Number(d.total || 0).toLocaleString('pt-BR')} registro(s) encontrado(s)`}</span>
            <label class="lista-tamanho">Mostrar <select class="form-input" id="aud-tam">${opcoesTam}</select> por página</label>
        </div>

        ${(d.itens || []).length ? `
        <div class="table-scroll">
            <table class="data-table aud-table">
                <thead>
                    <tr>
                        <th scope="col">Data e hora</th>
                        <th scope="col">Comando</th>
                        <th scope="col">Tabela / Registro</th>
                        <th scope="col">Quem fez</th>
                        <th scope="col">Origem / IP</th>
                        <th scope="col">Campos alterados</th>
                    </tr>
                </thead>
                <tbody>${linhas}</tbody>
            </table>
        </div>` : '<p class="empty-state">Nenhuma mudança encontrada com os filtros atuais.</p>'}

        <div class="pager" id="aud-pager"></div>
    `;
}

function desenhar(raiz) {
    if (!raiz || !raiz.isConnected) return;
    raiz.innerHTML = renderHtml();
    hydrateIcons(raiz);

    const d = estado.dados;
    const pagEl = raiz.querySelector('#aud-pager');
    if (d && pagEl) {
        pager(pagEl, d.pagina, d.total, d.porPagina, 'registros');
    }
}

async function carregar(raiz) {
    estado.carregando = true;
    estado.erro = null;
    desenhar(raiz);
    try {
        const resp = await fetch(paramsUrl('listar'), { credentials: 'same-origin' });
        const j = await resp.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
        if (!resp.ok || !j.ok) {
            throw new Error(j.erro || 'Não foi possível carregar o log de auditoria.');
        }
        estado.dados = j;
        estado.pagina = j.pagina || 1;
    } catch (err) {
        estado.erro = err.message || 'Erro ao consultar auditoria.';
        toast(estado.erro, 'error');
    } finally {
        estado.carregando = false;
        desenhar(raiz);
    }
}

/** Inicializa e carrega a subaba Auditoria dentro do container alvo. */
export function montarAuditoriaAdmin(raiz) {
    if (!raiz) return;

    raiz.addEventListener('change', (e) => {
        if (e.target.id === 'aud-tam') {
            e.stopPropagation();
            estado.porPagina = Number(e.target.value) || 20;
            estado.pagina = 1;
            carregar(raiz);
            return;
        }
        const form = e.target.closest('#aud-filtros');
        if (!form || e.target.name === 'busca') return;
        e.stopPropagation();
        estado.dias = Number(form.querySelector('[name="dias"]')?.value ?? 30);
        estado.tabela = form.querySelector('[name="tabela"]')?.value || '';
        estado.acao = form.querySelector('[name="acao"]')?.value || '';
        estado.ator = form.querySelector('[name="ator"]')?.value || '';
        estado.pagina = 1;
        carregar(raiz);
    });

    raiz.addEventListener('submit', (e) => {
        if (e.target.id !== 'aud-filtros') return;
        e.preventDefault();
        e.stopPropagation();
        const form = e.target;
        estado.dias = Number(form.querySelector('[name="dias"]')?.value ?? 30);
        estado.tabela = form.querySelector('[name="tabela"]')?.value || '';
        estado.acao = form.querySelector('[name="acao"]')?.value || '';
        estado.ator = form.querySelector('[name="ator"]')?.value || '';
        estado.busca = form.querySelector('[name="busca"]')?.value || '';
        estado.pagina = 1;
        carregar(raiz);
    });

    raiz.addEventListener('click', (e) => {
        if (e.target.closest('[data-aud-limpar]')) {
            estado.tabela = '';
            estado.acao = '';
            estado.ator = '';
            estado.dias = 30;
            estado.busca = '';
            estado.pagina = 1;
            carregar(raiz);
            return;
        }
        const btnPag = e.target.closest('#aud-pager [data-pagina]');
        if (btnPag && !btnPag.disabled) {
            estado.pagina = Math.max(1, Number(btnPag.dataset.pagina) || 1);
            carregar(raiz);
        }
    });

    carregar(raiz);
}
