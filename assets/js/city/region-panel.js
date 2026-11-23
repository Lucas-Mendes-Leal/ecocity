/**
 * Painel de detalhes de uma região: imagem real do estágio atual,
 * descrição e indicador.
 */
import { REGIAO_POR_ID, CATEGORIA_POR_ID, NIVEIS_CIDADE } from '../data/catalog.js';
import { state } from '../state.js';
import { icon } from '../ui/icons.js';
import { $, escapeHTML } from '../ui/dom.js';

let regiaoAberta = null;
let origemFoco = null;
let onClose = () => {};

/** Caminho da imagem por convenção: assets/img/locais/{prefixo}-estagio-{n}.png */
export const caminhoImagem = (prefixo, estagio) => `assets/img/locais/${prefixo}-estagio-${estagio}.png`;

export function initRegionPanel({ onClosed }) {
    onClose = onClosed || onClose;
    $('region-panel-close').addEventListener('click', closeRegionPanel);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && regiaoAberta) closeRegionPanel();
    });
}

export function openRegionPanel(regiaoId, dados, origem) {
    regiaoAberta = regiaoId;
    origemFoco = origem || null;
    renderRegionPanel(dados);
    $('region-panel').classList.remove('hidden');
    $('region-panel-close').focus();
}

export function closeRegionPanel() {
    if (!regiaoAberta) return;
    const id = regiaoAberta;
    regiaoAberta = null;
    $('region-panel').classList.add('hidden');
    onClose();
    // O mapa pode ter sido redesenhado: volta o foco ao elemento equivalente atual
    const alvo = origemFoco?.isConnected
        ? origemFoco
        : document.querySelector(`${origemFoco?.classList.contains('legend-chip') ? '.legend-chip' : '.city-pin'}[data-region="${id}"]`);
    alvo?.focus?.();
}

export const regiaoAtual = () => regiaoAberta;

/**
 * @param {{ indicadores: Record<string, number>, estagios: Record<string, number>, nivelCidade: number }} dados
 */
export function renderRegionPanel(dados) {
    if (!regiaoAberta) return;
    const regiao = REGIAO_POR_ID[regiaoAberta];
    const cat = CATEGORIA_POR_ID[regiao.indicador];
    const valor = dados.indicadores[regiao.indicador];
    const estagio = dados.estagios[regiao.id];
    const nivel = NIVEIS_CIDADE[estagio - 1];

    $('region-panel-icon').className = `region-panel-icon cat-${regiao.indicador}`;
    $('region-panel-icon').innerHTML = icon(cat.icone);
    $('region-panel-title').textContent = regiao.nome;
    $('region-panel-stage').textContent = `Estágio ${estagio} · ${nivel.situacao}`;
    $('region-panel-stage').className = `tag stage-tag-${estagio}`;
    $('region-panel-desc').textContent = regiao.descricao;
    $('region-panel-indicator').textContent = cat.nome;
    $('region-panel-value').textContent = `${valor}/100`;
    $('region-panel-bar').style.width = `${valor}%`;
    $('region-panel-bar').className = `bar-fill fill-${regiao.indicador}`;

    // Imagem real do estágio; sem arquivo, mostra placeholder neutro com o caminho esperado
    const caminho = caminhoImagem(regiao.prefixo, estagio);
    const img = $('region-panel-img');
    const placeholder = $('region-panel-placeholder');
    img.alt = `${regiao.nome} no estágio ${estagio}`;
    img.onload = () => { img.classList.remove('hidden'); placeholder.classList.add('hidden'); };
    img.onerror = () => {
        img.classList.add('hidden');
        placeholder.classList.remove('hidden');
        $('region-panel-path').textContent = caminho;
    };
    if (!img.src.endsWith(caminho)) img.src = caminho;

}
