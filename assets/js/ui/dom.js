/**
 * Utilitários de DOM compartilhados.
 */

/** Atalho para getElementById. */
export const $ = (id) => document.getElementById(id);

/** Escapa texto para inserção segura em HTML (dados vindos do usuário ou do banco). */
export function escapeHTML(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** Data de hoje no formato brasileiro. */
export function hojeBR(date = new Date()) {
    return date.toLocaleDateString('pt-BR');
}

/** Exibe uma notificação curta (substitui alert()). */
export function toast(message, variant = 'success') {
    let region = $('toast-region');
    if (!region) {
        region = document.createElement('div');
        region.id = 'toast-region';
        region.className = 'toast-region';
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }
    const item = document.createElement('div');
    item.className = `toast toast-${variant}`;
    item.textContent = message;
    region.appendChild(item);
    setTimeout(() => {
        item.classList.add('toast-leave');
        item.addEventListener('animationend', () => item.remove(), { once: true });
    }, 3600);
}

/** Barra "‹ Anteriores · Página X de Y · Próximos ›". */
export function pager(el, pagina, total, porPagina, rotulo) {
    const paginas = Math.max(1, Math.ceil(total / porPagina));
    el.hidden = paginas <= 1;
    if (paginas <= 1) { el.innerHTML = ''; return; }
    const ini = (pagina - 1) * porPagina + 1, fim = Math.min(total, pagina * porPagina);
    el.innerHTML = `<button type="button" class="btn btn-outline btn-sm" data-pagina="${pagina - 1}" ${pagina <= 1 ? 'disabled' : ''}>‹ Anteriores</button>
        <span class="pager-info" aria-live="polite">${ini}–${fim} de ${total} ${rotulo} · página ${pagina} de ${paginas}</span>
        <button type="button" class="btn btn-outline btn-sm" data-pagina="${pagina + 1}" ${pagina >= paginas ? 'disabled' : ''}>Próximos ›</button>`;
}
