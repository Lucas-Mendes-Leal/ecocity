/**
 * Conjunto único de ícones SVG da interface (substitui os emojis do protótipo).
 * Ícones de traço, 24×24, herdam a cor do texto (currentColor).
 */

const PATHS = {
    leaf: '<path d="M5 19c0-8 5-14 15-15-1 10-7 15-15 15z"/><path d="M5 19c3-4 6-7 10-9"/>',
    home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
    pin: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    clipboard: '<rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M9 11h6M9 15h4"/>',
    help: '<circle cx="12" cy="12" r="9.5"/><path d="M9.6 9.2a2.5 2.5 0 1 1 3.4 2.4c-.6.3-1 .8-1 1.5v.6"/><circle cx="12" cy="17" r=".6" fill="currentColor"/>',
    trophy: '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4"/><path d="M12 13v4M9 21h6M10 17h4v4h-4z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.8"/><path d="M15.5 14.2A5.5 5.5 0 0 1 21.5 20"/>',
    flag: '<path d="M5 21V4"/><path d="M5 4h11l-2 4 2 4H5"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    coin: '<circle cx="12" cy="12" r="9" fill="#FBC02D" stroke="#F57F17"/><circle cx="12" cy="12" r="5.5" stroke="#F57F17"/>',
    lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
    gift: '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v8h14v-8M12 8v13"/><path d="M12 8c-2-4-6-4-6-1.5S9 8 12 8zm0 0c2-4 6-4 6-1.5S15 8 12 8z"/>',
    chart: '<path d="M4 20h16"/><path d="M7 16v-5M12 16V7M17 16v-8"/>',
    back: '<path d="M15 5l-7 7 7 7"/>',
    'chevron-down': '<path d="M6 9l6 6 6-6"/>',
    send: '<path d="M4 12l16-8-6 16-2.5-6.5z"/><path d="M11.5 13.5L20 4"/>',
    chat: '<path d="M21 14a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    image: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/>',
    scroll: '<path d="M7 4h11a2 2 0 0 1 2 2v1h-4"/><path d="M16 7v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1h10"/><path d="M7 4a2 2 0 0 0-2 2v11"/><path d="M9 9h4M9 13h4"/>',
    sun: '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>',
    moon: '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
    volume: '<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/><path d="M19 6a8.5 8.5 0 0 1 0 12"/>',
    'volume-off': '<path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M17 9l5 6M22 9l-5 6"/>',
    flask: '<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3"/>',
    /* Categorias ambientais */
    recycle: '<path d="M8.6 7.3l2.2-3.7a1.4 1.4 0 0 1 2.4 0l2 3.4"/><path d="M13.6 6.9h2.1V4.7"/><path d="M17.8 11.2l2.1 3.6a1.4 1.4 0 0 1-1.2 2.1h-4.2"/><path d="M15.9 14.7l-1.4 2.2 1.4 2"/><path d="M9.6 16.9H5.3a1.4 1.4 0 0 1-1.2-2.1l2.1-3.6"/><path d="M4.4 12.5l1.8-1.3 1.9 1.1"/>',
    droplet: '<path d="M12 3s-6.5 7.3-6.5 11.5a6.5 6.5 0 0 0 13 0C18.5 10.3 12 3 12 3z"/>',
    bolt: '<path d="M13 2L4.5 13.5H11L10 22l8.5-11.5H12L13 2z"/>',
    bike: '<circle cx="6" cy="16" r="3.5"/><circle cx="18" cy="16" r="3.5"/><path d="M6 16l4-7h5l3 7M10 9l2.5 7H6M14 5h2.5l-1.5 4"/>',
    tree: '<path d="M12 3a5 5 0 0 0-5 5 4 4 0 0 0-1 7.5 4 4 0 0 0 4 2.5h4a4 4 0 0 0 4-2.5A4 4 0 0 0 17 8a5 5 0 0 0-5-5z"/><path d="M12 12v9"/>',
};

/**
 * Retorna o SVG de um ícone como string.
 * @param {string} name  chave em PATHS
 * @param {string} [extraClass]
 */
export function icon(name, extraClass = '') {
    const body = PATHS[name] || PATHS.help;
    return `<svg class="icon ${extraClass}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${body}</svg>`;
}

/** Corpo bruto do ícone (para embutir dentro de outro SVG, ex.: pins da cidade). */
export function iconPaths(name) {
    return PATHS[name] || PATHS.help;
}

/** Preenche todos os elementos com [data-icon] já presentes no HTML. */
export function hydrateIcons(root = document) {
    root.querySelectorAll('[data-icon]').forEach((el) => {
        if (el.dataset.iconReady) return;
        el.insertAdjacentHTML('afterbegin', icon(el.dataset.icon));
        el.dataset.iconReady = '1';
    });
}
