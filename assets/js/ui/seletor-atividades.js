/**
 * Seletor reutilizável de atividades (chips no formulário + modal amplo pesquisável).
 * Progressivo: lê e mantém sincronizados os `<input type="checkbox" name="atividades[]">`
 * dentro de `.act-picker-fallback` para envio padrão por POST sem alterar o backend.
 */
import { escapeHTML } from './dom.js';
import { icon } from './icons.js';

const CATEGORIAS = {
    recycling: { nome: 'Reciclagem', icone: 'recycle' },
    water:     { nome: 'Água',       icone: 'droplet' },
    energy:    { nome: 'Energia',    icone: 'bolt' },
    mobility:  { nome: 'Mobilidade', icone: 'bike' },
    nature:    { nome: 'Natureza',   icone: 'tree' },
};

const ORDEM_AREAS = ['recycling', 'water', 'energy', 'mobility', 'nature'];

const modalEstado = {
    backdrop: null,
    seletorAtivo: null,
    rascunho: new Set(),
    filtroArea: 'todas',
    busca: '',
    ultimoFoco: null,
};

const normalizar = (texto) => String(texto ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();

const rotuloQuantidade = (n) => (n === 1 ? '1 selecionada' : `${n} selecionadas`);

const catSegura = (cat) => (cat && Object.hasOwn(CATEGORIAS, cat) ? cat : 'nature');

function itensDoSeletor(el) {
    return [...el.querySelectorAll('.act-picker-fallback input[type="checkbox"][name="atividades[]"]')].map((cb) => {
        const cat = catSegura(cb.dataset.categoria);
        const catNome = cb.dataset.categoriaNome || CATEGORIAS[cat].nome;
        return {
            cb,
            id: String(cb.value),
            nome: cb.dataset.nome || cb.parentElement?.textContent?.trim() || `Atividade #${cb.value}`,
            unidade: cb.dataset.unidade || '',
            categoria: cat,
            categoriaNome: CATEGORIAS[cat]?.nome || catNome,
            termoBusca: normalizar(`${cb.dataset.nome || ''} ${cb.dataset.unidade || ''} ${CATEGORIAS[cat]?.nome || ''} ${catNome}`),
            checked: cb.checked,
            disabled: cb.disabled,
        };
    });
}

function modoSomenteLeitura(el, itens) {
    return el.dataset.seletorAtividades === 'readonly' || (itens.length > 0 && itens.every((i) => i.disabled));
}

function atualizarChips(el) {
    const ui = el.querySelector('.act-picker-ui');
    if (!ui) return;

    const itens = itensDoSeletor(el);
    const selecionados = itens.filter((i) => i.checked);
    const readonly = modoSomenteLeitura(el, itens);

    const cont = ui.querySelector('.act-picker-count');
    if (cont) cont.textContent = rotuloQuantidade(selecionados.length);

    const erro = el.querySelector('.field-error');
    if (erro && selecionados.length > 0) erro.textContent = '';

    const trigger = ui.querySelector('.act-picker-trigger');
    if (trigger) {
        trigger.hidden = readonly;
        if (selecionados.length > 0) trigger.setAttribute('aria-invalid', 'false');
        else if (erro?.textContent?.trim()) trigger.setAttribute('aria-invalid', 'true');
    }

    const chips = ui.querySelector('.act-picker-chips');
    if (!chips) return;

    if (!selecionados.length) {
        const textoVazio = readonly ? 'Nenhuma atividade vinculada.' : 'Nenhuma atividade selecionada ainda.';
        chips.innerHTML = `<span class="act-picker-empty">${textoVazio}</span>`;
        return;
    }

    chips.innerHTML = selecionados.map((item) => {
        const dica = item.unidade ? ` title="${escapeHTML(`${item.categoriaNome} · em ${item.unidade}`)}"` : '';
        const remover = readonly ? '' : `
            <button type="button" class="act-chip-remove" data-remover-id="${escapeHTML(item.id)}" aria-label="Remover ${escapeHTML(item.nome)}">
                ${icon('close')}
            </button>`;
        return `<span class="act-chip"${dica}>
            <span class="act-dot cat-${escapeHTML(item.categoria)}" aria-hidden="true"></span>
            <span class="act-chip-name">${escapeHTML(item.nome)}</span>
            ${remover}
        </span>`;
    }).join('');
}

function elementosFocaveis(container) {
    return [...container.querySelectorAll('button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((el) => el.offsetParent !== null);
}

function criarModal() {
    if (modalEstado.backdrop) return modalEstado.backdrop;

    const backdrop = document.createElement('div');
    backdrop.id = 'modal-seletor-atividades';
    backdrop.className = 'act-modal-backdrop';
    backdrop.hidden = true;
    backdrop.setAttribute('role', 'presentation');
    backdrop.innerHTML = `
        <div class="act-modal" role="dialog" aria-modal="true" aria-labelledby="act-modal-titulo">
            <div class="act-modal-head">
                <div>
                    <h2 id="act-modal-titulo">Selecionar atividades</h2>
                    <p id="act-modal-sub" class="act-modal-sub"></p>
                </div>
                <button type="button" class="act-modal-close" data-modal-fechar aria-label="Fechar">${icon('close')}</button>
            </div>
            <div class="act-modal-toolbar">
                <div class="act-modal-search">
                    <span class="act-modal-search-icon" aria-hidden="true">${icon('search')}</span>
                    <input type="search" id="act-modal-busca" class="form-input" placeholder="Buscar por nome, unidade ou área..." autocomplete="off">
                </div>
                <div class="act-modal-pills" id="act-modal-pills" role="group" aria-label="Filtrar por área ambiental"></div>
            </div>
            <div class="act-modal-body" id="act-modal-body">
                <div class="act-modal-grid" id="act-modal-grid"></div>
            </div>
            <div class="act-modal-foot">
                <div class="act-modal-status">
                    <span id="act-modal-resumo">0 selecionadas</span>
                    <button type="button" class="act-link-btn" id="act-modal-limpar">Limpar</button>
                </div>
                <div class="act-modal-actions">
                    <button type="button" class="btn btn-outline" data-modal-fechar>Cancelar</button>
                    <button type="button" class="btn btn-primary" id="act-modal-confirmar">Confirmar seleção (0)</button>
                </div>
            </div>
        </div>`;

    document.body.appendChild(backdrop);

    const buscaInput = backdrop.querySelector('#act-modal-busca');
    const pillsEl = backdrop.querySelector('#act-modal-pills');
    const gridEl = backdrop.querySelector('#act-modal-grid');
    const limparBtn = backdrop.querySelector('#act-modal-limpar');
    const confirmarBtn = backdrop.querySelector('#act-modal-confirmar');
    const dialog = backdrop.querySelector('.act-modal');

    buscaInput.addEventListener('input', () => {
        modalEstado.busca = buscaInput.value;
        renderizarModal();
    });

    pillsEl.addEventListener('click', (e) => {
        const pill = e.target.closest('[data-area]');
        if (!pill) return;
        modalEstado.filtroArea = pill.dataset.area;
        renderizarModal();
    });

    gridEl.addEventListener('click', (e) => {
        const limpa = e.target.closest('[data-limpar-filtros]');
        if (limpa) {
            modalEstado.busca = '';
            modalEstado.filtroArea = 'todas';
            buscaInput.value = '';
            renderizarModal();
            buscaInput.focus();
            return;
        }
        const card = e.target.closest('[data-act-id]');
        if (!card || card.disabled) return;
        const id = card.dataset.actId;
        if (modalEstado.rascunho.has(id)) modalEstado.rascunho.delete(id);
        else modalEstado.rascunho.add(id);
        renderizarModal();
    });

    limparBtn.addEventListener('click', () => {
        modalEstado.rascunho.clear();
        renderizarModal();
    });

    confirmarBtn.addEventListener('click', confirmarModal);

    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop || e.target.closest('[data-modal-fechar]')) {
            fecharModal();
        }
    });

    backdrop.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            fecharModal();
            return;
        }
        if (e.key !== 'Tab') return;
        const focaveis = elementosFocaveis(dialog);
        if (!focaveis.length) return;
        const primeiro = focaveis[0];
        const ultimo = focaveis[focaveis.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) {
            e.preventDefault();
            ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
            e.preventDefault();
            primeiro.focus();
        }
    });

    modalEstado.backdrop = backdrop;
    return backdrop;
}

function renderizarModal() {
    const backdrop = modalEstado.backdrop;
    const seletor = modalEstado.seletorAtivo;
    if (!backdrop || !seletor) return;

    const itens = itensDoSeletor(seletor);
    const rascunho = modalEstado.rascunho;
    const termo = normalizar(modalEstado.busca);

    const contagemPorArea = {};
    for (const a of ORDEM_AREAS) contagemPorArea[a] = 0;
    for (const item of itens) {
        contagemPorArea[item.categoria] = (contagemPorArea[item.categoria] || 0) + 1;
    }

    const pills = [
        `<button type="button" class="act-pill${modalEstado.filtroArea === 'todas' ? ' is-active' : ''}" data-area="todas" aria-pressed="${modalEstado.filtroArea === 'todas'}">Todas (${itens.length})</button>`,
        ...ORDEM_AREAS.filter((area) => contagemPorArea[area] > 0).map((area) => {
            const ativo = modalEstado.filtroArea === area;
            return `<button type="button" class="act-pill${ativo ? ' is-active' : ''}" data-area="${area}" aria-pressed="${ativo}">
                <span class="act-dot cat-${area}" aria-hidden="true"></span>
                ${escapeHTML(CATEGORIAS[area].nome)} (${contagemPorArea[area]})
            </button>`;
        }),
        `<button type="button" class="act-pill${modalEstado.filtroArea === 'selecionadas' ? ' is-active' : ''}" data-area="selecionadas" aria-pressed="${modalEstado.filtroArea === 'selecionadas'}">
            Selecionadas (${rascunho.size})
        </button>`,
    ];
    backdrop.querySelector('#act-modal-pills').innerHTML = pills.join('');

    const filtrados = itens.filter((item) => {
        if (modalEstado.filtroArea === 'selecionadas' && !rascunho.has(item.id)) return false;
        if (modalEstado.filtroArea !== 'todas' && modalEstado.filtroArea !== 'selecionadas' && item.categoria !== modalEstado.filtroArea) return false;
        if (termo && !item.termoBusca.includes(termo)) return false;
        return true;
    });

    const gridEl = backdrop.querySelector('#act-modal-grid');
    if (!filtrados.length) {
        gridEl.innerHTML = `<div class="act-modal-empty">
            <p>Nenhuma atividade encontrada para esse filtro.</p>
            <button type="button" class="btn btn-outline btn-sm" data-limpar-filtros>Limpar filtros</button>
        </div>`;
    } else {
        gridEl.innerHTML = filtrados.map((item) => {
            const marcada = rascunho.has(item.id);
            const cat = CATEGORIAS[item.categoria];
            const unidadeHtml = item.unidade ? `<span class="act-card-unit">em ${escapeHTML(item.unidade)}</span>` : '';
            return `<button type="button" class="act-card cat-border-${escapeHTML(item.categoria)}${marcada ? ' is-selected' : ''}" role="checkbox" aria-checked="${marcada}" data-act-id="${escapeHTML(item.id)}"${item.disabled ? ' disabled' : ''}>
                <span class="act-card-top">
                    <span class="cat-badge cat-${escapeHTML(item.categoria)}">${icon(cat.icone)} ${escapeHTML(cat.nome)}</span>
                    <span class="act-card-check" aria-hidden="true">${marcada ? icon('check') : ''}</span>
                </span>
                <strong class="act-card-name">${escapeHTML(item.nome)}</strong>
                ${unidadeHtml}
            </button>`;
        }).join('');
    }

    backdrop.querySelector('#act-modal-resumo').textContent = rotuloQuantidade(rascunho.size);
    backdrop.querySelector('#act-modal-limpar').disabled = rascunho.size === 0;
    backdrop.querySelector('#act-modal-confirmar').textContent = `Confirmar seleção (${rascunho.size})`;
}

function abrirModal(el) {
    const itens = itensDoSeletor(el);
    if (modoSomenteLeitura(el, itens)) return;

    const backdrop = criarModal();
    modalEstado.seletorAtivo = el;
    modalEstado.rascunho = new Set(itens.filter((i) => i.checked).map((i) => i.id));
    modalEstado.filtroArea = 'todas';
    modalEstado.busca = '';
    modalEstado.ultimoFoco = document.activeElement;

    backdrop.querySelector('#act-modal-titulo').textContent = el.dataset.titulo || 'Selecionar atividades';
    backdrop.querySelector('#act-modal-sub').textContent = el.dataset.subtitulo || 'Escolha uma ou mais atividades sustentáveis que o estabelecimento pode confirmar.';
    const buscaInput = backdrop.querySelector('#act-modal-busca');
    buscaInput.value = '';

    renderizarModal();
    backdrop.hidden = false;
    document.body.style.overflow = 'hidden';
    buscaInput.focus();
}

function fecharModal() {
    const backdrop = modalEstado.backdrop;
    if (!backdrop || backdrop.hidden) return;

    backdrop.hidden = true;
    document.body.style.overflow = '';
    const foco = modalEstado.ultimoFoco;
    modalEstado.seletorAtivo = null;
    modalEstado.ultimoFoco = null;
    if (foco && typeof foco.focus === 'function') foco.focus();
}

function confirmarModal() {
    const el = modalEstado.seletorAtivo;
    if (!el) return;

    for (const item of itensDoSeletor(el)) {
        if (!item.disabled) item.cb.checked = modalEstado.rascunho.has(item.id);
    }
    el.dispatchEvent(new Event('change', { bubbles: true }));
    atualizarChips(el);
    fecharModal();
}

/** Inicializa todos os seletores `[data-seletor-atividades]` dentro de `raiz`. */
export function iniciarSeletorAtividades(raiz = document) {
    raiz.querySelectorAll('[data-seletor-atividades]').forEach((el) => {
        if (el.dataset.seletorPronto === '1') return;
        const fallback = el.querySelector('.act-picker-fallback');
        if (!fallback) return;

        el.dataset.seletorPronto = '1';
        fallback.hidden = true;

        const ui = document.createElement('div');
        ui.className = 'act-picker-ui';
        const textoBotao = el.dataset.botao || 'Selecionar atividades';
        ui.innerHTML = `
            <div class="act-picker-top">
                <span class="act-picker-count">0 selecionadas</span>
                <button type="button" class="btn btn-outline btn-sm act-picker-trigger">
                    ${icon('search')} ${escapeHTML(textoBotao)}
                </button>
            </div>
            <div class="act-picker-chips" aria-live="polite"></div>`;

        fallback.insertAdjacentElement('afterend', ui);
        if (el.dataset.triggerId) {
            ui.querySelector('.act-picker-trigger').id = el.dataset.triggerId;
        }
        atualizarChips(el);

        ui.querySelector('.act-picker-trigger').addEventListener('click', () => abrirModal(el));
        ui.querySelector('.act-picker-chips').addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remover-id]');
            if (!btn) return;
            const id = btn.dataset.removerId;
            const item = itensDoSeletor(el).find((i) => i.id === id);
            if (!item || item.disabled) return;
            item.cb.checked = false;
            el.dispatchEvent(new Event('change', { bubbles: true }));
            atualizarChips(el);
        });
    });
}
