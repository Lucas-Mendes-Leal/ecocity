/**
 * Modo demonstração: ativo apenas com ?demo=1 na URL.
 * Permite simular estágios da cidade e aprovar/rejeitar registros.
 */
import { demoDefinirIndicadores } from '../state.js';
import { NIVEIS_CIDADE } from '../data/catalog.js';
import { $ } from './dom.js';

export const isDemo = () => new URLSearchParams(window.location.search).get('demo') === '1';

export function initDemo() {
    const bar = $('demo-bar');
    if (!isDemo()) { bar.remove(); return; }

    // Um valor dentro de cada faixa de estágio
    const amostras = [10, 35, 65, 90];
    $('demo-stage-buttons').innerHTML = NIVEIS_CIDADE.map((n, i) =>
        `<button type="button" class="btn btn-outline btn-sm" data-valor="${amostras[i]}">Estágio ${n.nivel}</button>`).join('');

    bar.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-valor]');
        if (btn) demoDefinirIndicadores(Number(btn.dataset.valor));
    });
    bar.classList.remove('hidden');
}
