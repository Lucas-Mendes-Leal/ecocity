/**
 * Listas da administração: busca, filtros em pílula, "Mostrar 10/20/50" e páginas.
 * Trabalha sobre elementos já desenhados (cards, <details> ou linhas de tabela). Cada item traz
 *   data-busca  — texto pesquisável
 *   data-filtro — situações separadas por espaço (ex.: "ativo usado")
 * Página, busca e filtro ficam guardados por lista (chave); o tamanho da página vale para toda a administração.
 */
import { pager, escapeHTML } from './dom.js';

export const TAMANHOS = [10, 20, 50];
const CHAVE_TAMANHO = 'ecocity-admin-por-pagina';
const estados = new Map();

/** Tamanho de página guardado no navegador numa chave (também usado pelo Perfil, com 10/20/30). */
export function tamanhoGuardado(chave, tamanhos, padrao) {
    try {
        const n = Number(localStorage.getItem(chave));
        if (tamanhos.includes(n)) return n;
    } catch { /* navegador sem armazenamento */ }
    return padrao;
}

export function guardarTamanho(chave, n) {
    try { localStorage.setItem(chave, String(n)); } catch { /* ignora */ }
}

/** Tamanho de página escolhido na administração. */
export const porPagina = () => tamanhoGuardado(CHAVE_TAMANHO, TAMANHOS, 20);
export const definirPorPagina = (n) => guardarTamanho(CHAVE_TAMANHO, n);

/** Troca o filtro de uma lista (vale no próximo desenho), voltando à página 1. */
export function definirFiltro(chave, filtro) {
    const est = estados.get(chave);
    if (est) { est.filtro = filtro; est.pagina = 1; } else estados.set(chave, { pagina: 1, busca: '', filtro });
}

/** Minúsculas e sem acento, para a busca. */
export const normalizar = (t) => String(t ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Seletor "Mostrar 10/20/50" (ou outros tamanhos). */
export function seletorTamanho(atual, tamanhos = TAMANHOS) {
    return `<label class="lista-tamanho">Mostrar
        <select class="form-input" data-lista-tamanho>${tamanhos.map((n) => `<option value="${n}" ${n === atual ? 'selected' : ''}>${n}</option>`).join('')}</select>
        por página</label>`;
}

/** Pílulas de filtro com contadores. */
export function pilulas(filtros, ativo, contagem) {
    return filtros.map(([valor, rotulo]) => `<button type="button" class="chip" data-lista-filtro="${valor}" aria-pressed="${valor === ativo}">
        ${escapeHTML(rotulo)} <span class="chip-count">${contagem[valor] ?? 0}</span></button>`).join('');
}

/**
 * @param {object} o
 * @param {Element} o.lista      elemento que contém os itens
 * @param {string}  o.itens      seletor dos itens dentro da lista
 * @param {Element} [o.bloco]    elemento antes do qual vai a barra e depois do qual vão as páginas (padrão: a lista)
 * @param {string}  o.chave      nome da lista (guarda o estado)
 * @param {string[]} o.rotulo    [singular, plural], ex.: ['registro', 'registros']
 * @param {Array}   [o.filtros]  [[valor, rótulo], …]; 'todos' mostra tudo
 * @param {string}  [o.filtroInicial]
 * @param {string}  [o.buscaDica] texto do campo de busca (sem ele, não há busca)
 */
export function listaPaginada({ lista, itens, bloco = lista, chave, rotulo, filtros = [], filtroInicial, buscaDica = '' }) {
    if (!lista) return;
    const todos = [...lista.querySelectorAll(itens)];
    if (!todos.length) return;
    if (!estados.has(chave)) estados.set(chave, { pagina: 1, busca: '', filtro: filtroInicial || filtros[0]?.[0] || 'todos' });
    const est = estados.get(chave);
    const [singular, plural] = Array.isArray(rotulo) ? rotulo : [rotulo, rotulo];

    const barra = document.createElement('div');
    barra.className = 'lista-barra';
    const rodape = document.createElement('div');
    rodape.className = 'lista-rodape';
    bloco.before(barra);
    bloco.after(rodape);

    const tokens = (el) => (el.dataset.filtro || '').split(/\s+/);
    const contagem = { todos: todos.length };
    filtros.forEach(([v]) => { if (v !== 'todos') contagem[v] = todos.filter((el) => tokens(el).includes(v)).length; });

    barra.innerHTML = `
        ${buscaDica ? `<input type="search" class="form-input lista-busca" placeholder="${escapeHTML(buscaDica)}" aria-label="${escapeHTML(buscaDica)}" value="${escapeHTML(est.busca)}">` : ''}
        ${filtros.length ? `<div class="lista-filtros" role="group" aria-label="Filtrar">${pilulas(filtros, est.filtro, contagem)}</div>` : ''}
        ${seletorTamanho(porPagina())}`;

    const desenhar = () => {
        const termo = normalizar(est.busca.trim());
        const visiveis = todos.filter((el) => (est.filtro === 'todos' || tokens(el).includes(est.filtro))
            && (!termo || normalizar(el.dataset.busca).includes(termo)));
        const tam = porPagina();
        const paginas = Math.max(1, Math.ceil(visiveis.length / tam));
        est.pagina = Math.min(Math.max(1, est.pagina), paginas);
        const ini = (est.pagina - 1) * tam;
        const mostrar = new Set(visiveis.slice(ini, ini + tam));
        todos.forEach((el) => { el.hidden = !mostrar.has(el); });
        barra.querySelectorAll('[data-lista-filtro]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.listaFiltro === est.filtro)));

        if (!visiveis.length) {
            bloco.hidden = true;
            rodape.innerHTML = `<div class="empty-state lista-vazia" role="status">Nenhum item encontrado.
                ${est.busca || est.filtro !== 'todos' ? '<button type="button" class="btn btn-outline btn-sm" data-lista-limpar>Limpar busca e filtro</button>' : ''}</div>`;
            return;
        }
        bloco.hidden = false;
        rodape.innerHTML = '<nav class="pager" aria-label="Páginas"></nav>';
        const p = rodape.querySelector('.pager');
        pager(p, est.pagina, visiveis.length, tam, plural);
        if (paginas <= 1) {   // uma página só: mostra o total mesmo assim
            p.hidden = false;
            p.innerHTML = `<span class="pager-info" aria-live="polite">${visiveis.length} ${visiveis.length === 1 ? singular : plural}</span>`;
        }
    };

    let espera;
    barra.addEventListener('input', (e) => {
        if (!e.target.matches('.lista-busca')) return;
        clearTimeout(espera);
        espera = setTimeout(() => { est.busca = e.target.value; est.pagina = 1; desenhar(); }, 200);
    });
    barra.addEventListener('change', (e) => {
        if (!e.target.matches('[data-lista-tamanho]')) return;
        definirPorPagina(Number(e.target.value));
        est.pagina = 1;
        desenhar();
    });
    barra.addEventListener('click', (e) => {
        const b = e.target.closest('[data-lista-filtro]');
        if (!b) return;
        est.filtro = b.dataset.listaFiltro;
        est.pagina = 1;
        desenhar();
    });
    rodape.addEventListener('click', (e) => {
        const pag = e.target.closest('[data-pagina]');
        if (pag) {
            est.pagina = Number(pag.dataset.pagina);
            desenhar();
            barra.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            return;
        }
        if (e.target.closest('[data-lista-limpar]')) {
            est.busca = '';
            est.filtro = 'todos';
            est.pagina = 1;
            const campo = barra.querySelector('.lista-busca');
            if (campo) campo.value = '';
            desenhar();
        }
    });
    desenhar();
}
