/**
 * Navegação entre as abas do jogo (padrão WAI-ARIA tabs).
 */
import { tocar } from '../som.js';

/** @returns {(tab: HTMLElement, foco?: boolean) => void} ativa uma aba pelo código */
export function initTabs(nav) {
    const tabs = [...nav.querySelectorAll('[role="tab"]')];

    const ativar = (tab, foco = true) => {
        tabs.forEach((t) => {
            const ativo = t === tab;
            t.setAttribute('aria-selected', String(ativo));
            t.tabIndex = ativo ? 0 : -1;
            document.getElementById(t.getAttribute('aria-controls')).hidden = !ativo;
        });
        if (foco) tab.focus();
    };

    nav.addEventListener('click', (e) => {
        const tab = e.target.closest('[role="tab"]');
        if (tab && tab.getAttribute('aria-selected') !== 'true') tocar('clique');
        if (tab) ativar(tab, false);
    });

    nav.addEventListener('keydown', (e) => {
        const visiveis = tabs.filter((t) => !t.hidden);   // abas escondidas ficam fora das setas
        const i = visiveis.indexOf(document.activeElement);
        if (i < 0) return;
        const mapa = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: visiveis.length - 1 };
        if (!(e.key in mapa)) return;
        e.preventDefault();
        ativar(visiveis[(mapa[e.key] + visiveis.length) % visiveis.length]);
    });

    ativar(tabs.find((t) => t.getAttribute('aria-selected') === 'true') || tabs[0], false);
    return ativar;
}
