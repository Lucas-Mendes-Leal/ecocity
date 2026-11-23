/**
 * Fundo do login e do cadastro: a cidade do jogo se recuperando
 * (estágio 1 → 4 em loop). Só decoração: sem pins nem cliques.
 * Com "reduzir movimento" ativado, fica parada no estágio 4.
 * Carros e pessoas andam pelo centro (traffic.js).
 */
import { desenharCenario } from './city/city-map.js';
import { anexarTrafego } from './city/traffic.js';
import { REGIOES } from './data/catalog.js';

const INTERVALO_MS = 5000;

export function iniciarCenaAuth(container) {
    if (!container) return;
    const reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let estagio = reduzir ? 4 : 1;

    const desenhar = () => {
        const estagios = Object.fromEntries(REGIOES.map((r) => [r.id, estagio]));
        const { svg, vista } = desenharCenario({ nivelCidade: estagio, estagios });
        container.innerHTML = `<svg class="city-svg city-svg-enter" viewBox="${vista.join(' ')}"
            preserveAspectRatio="xMidYMid slice" focusable="false">${svg}</svg>`;
        anexarTrafego(container.querySelector('svg'), { estagios, nivelCidade: estagio });
    };

    desenhar();
    if (reduzir) return;
    setInterval(() => {
        if (document.hidden) return;   // não trabalha com a aba em segundo plano
        estagio = estagio % 4 + 1;
        desenhar();
    }, INTERVALO_MS);
}
