/**
 * Modo Construir: escolher lote e estrutura, posicionar na grade (clique/toque),
 * girar, confirmar e remover. As regras valem no servidor (includes/construcoes.php);
 * aqui a validação serve só para a prévia verde/vermelha.
 * A paleta tem abas por categoria; estruturas que poluem ou compensam (RN54) mostram um selo.
 */
import { state, colocarConstrucao, removerConstrucao, coletarRenda, estagioIndicador } from '../state.js';
import { focarLote, setBuildOverlay, pontoNoSvg } from '../city/city-map.js';
import { celulaDoLote, contornoArea, condicoesLotes, LOTE_ORIGEM } from '../city/expansion.js';
import { desenharEstrutura } from '../city/structures.js';
import { BOARD } from '../city/iso.js';
import { CATEGORIA_POR_ID } from '../data/catalog.js';
import { icon } from './icons.js';
import { $, escapeHTML, toast } from './dom.js';
import { tocar } from '../som.js';

const modo = {
    lote: null,          // lote em foco
    categoria: 'ecologica', // aba da paleta
    estruturaId: null,   // estrutura escolhida na paleta
    rotacao: 0,          // 0, 1 (90°), 2 (180°), 3 (270°)
    removendo: false,
    posicao: null,       // célula escolhida com clique { x, y }
    hover: null,         // célula sob o ponteiro
    ocupado: false,
};

const estrutura = () => state.construcao.catalogo.find((c) => c.id === modo.estruturaId);
const nivelDaCidade = () => Math.max(1, ...state.construcao.lotes);   // lote N só existe com cidade nível N
const tamanho = (e) => (modo.rotacao % 2 ? [e.profundidade, e.largura] : [e.largura, e.profundidade]);
const GIRO_TEXTO = ['frente para a esquerda', 'frente para a direita', 'de costas (frente para o fundo)', 'de costas (frente para a esquerda do fundo)'];
const giroTexto = () => `Giro ${modo.rotacao * 90}°: ${GIRO_TEXTO[modo.rotacao]}.`;
/** Gira 90° (para a frente ou, com Shift, para trás). */
function girar(passo = 1) {
    modo.rotacao = (modo.rotacao + passo + 4) % 4;
    tocar('girar');
    atualizarPrevia();
}
const construidasNoLote = () => state.construcao.construidas.filter((c) => c.lote === modo.lote);
const lixoNoLote = () => (state.construcao.lixo || []).filter((l) => l.lote === modo.lote);
const condicoesAtuais = () => condicoesLotes(Object.fromEntries(['water', 'nature', 'energy', 'mobility', 'recycling'].map((k) => [k, estagioIndicador(k)])));

const AREA_CURTA = { recycling: 'Reciclagem', water: 'Água', energy: 'Energia', mobility: 'Mobilidade', nature: 'Natureza' };
const nomeArea = (chave) => AREA_CURTA[chave] ?? chave;

/** Texto do efeito da estrutura (RN54). */
function textoEfeito(e) {
    if (e.efeito === 'polui') {
        const comp = state.construcao.catalogo.filter((c) => c.efeito === 'compensa' && c.indicador === e.indicador).map((c) => c.nome);
        return `Polui: −1 em ${nomeArea(e.indicador)} a cada 2 dias${comp.length ? ` (compensado por ${comp.join(' ou ')})` : ''}.`;
    }
    if (e.efeito === 'compensa') return `Compensa uma estrutura poluidora em ${nomeArea(e.indicador)}.`;
    return '';
}

/** Efeitos da estrutura na cidade (RN77–RN79), em textos curtos. */
function efeitosNaCidade(e) {
    return [
        e.moradores && `+${e.moradores} moradores`, e.carros && `+${e.carros} carros`, e.bicicletas && `+${e.bicicletas} bicicletas`,
        e.onibus && `+${e.onibus} ônibus`, e.renda && `Rende ${e.renda}/dia`,
    ].filter(Boolean);
}

/** Efeito no indicador (RN54) e na cidade (RN77–RN79), para o status. */
function textoEfeitos(e) {
    const naCidade = efeitosNaCidade(e);
    return [textoEfeito(e), naCidade.length ? `Na cidade: ${naCidade.join(', ')}.` : ''].filter(Boolean).join(' ');
}

/** Pílula de habitantes e botão de coletar a renda (abaixo do mapa). */
function renderEfeitosCidade() {
    const ef = state.construcao.efeitos;
    if (!ef) return;
    $('city-pop').innerHTML = `${icon('users')} Habitantes <strong>${ef.habitantes}/${ef.habitantesMax}</strong>`;
    const botao = $('city-collect');
    botao.classList.toggle('hidden', !(ef.renda > 0));
    botao.innerHTML = `${icon('coin')} Coletar +${ef.renda}`;
    botao.title = `Renda do comércio: ${ef.rendaPorDia} créditos por dia (acumula até 3 dias)`;
}

async function coletar() {
    if (modo.coletando) return;
    modo.coletando = true;
    $('city-collect').disabled = true;
    try {
        await coletarRenda();
        tocar('renda');
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        modo.coletando = false;
        $('city-collect').disabled = false;
    }
}

/** Resumo da poluição da cidade por área. */
function renderPoluicao() {
    const linhas = Object.entries(state.construcao.poluicao || {}).filter(([, p]) => p.polui > 0)
        .map(([k, p]) => `<span class="${p.queda ? 'is-bad' : 'is-ok'}">${icon(CATEGORIA_POR_ID[k]?.icone || 'leaf')} ${nomeArea(k)}: ${p.polui} ${p.polui === 1 ? 'polui' : 'poluem'}, ${p.compensa} ${p.compensa === 1 ? 'compensa' : 'compensam'} → ${p.queda ? `−${p.queda} a cada 2 dias` : 'sem perda'}</span>`);
    $('build-pollution').classList.toggle('hidden', !linhas.length);
    $('build-pollution').innerHTML = linhas.length ? `<strong>Poluição da cidade:</strong> ${linhas.join('')}` : '';
}

/** Ajusta a posição para a estrutura caber no lote. */
function encaixar(cel, w, d) {
    return { x: Math.min(cel.x, BOARD - w), y: Math.min(cel.y, BOARD - d) };
}

function validar(pos, w, d, e) {
    if (state.jogador.creditosVerde < e.custo) return 'Créditos insuficientes.';
    const bate = construidasNoLote().some((o) => pos.x < o.x + o.w && o.x < pos.x + w && pos.y < o.y + o.d && o.y < pos.y + d);
    if (bate) return 'Já existe uma estrutura nesse espaço.';
    const sujo = lixoNoLote().some((l) => l.x >= pos.x && l.x < pos.x + w && l.y >= pos.y && l.y < pos.y + d);
    return sujo ? 'Há lixo nesse espaço. Melhore a reciclagem da cidade para limpar o lote.' : '';
}

function estruturaNaCelula(cel) {
    return cel && construidasNoLote().find((o) => cel.x >= o.x && cel.x < o.x + o.w && cel.y >= o.y && cel.y < o.y + o.d);
}

/* ---------------------------------------------------------------------------
   Prévia
   --------------------------------------------------------------------------- */

function atualizarPrevia() {
    if (!modo.lote) return;
    const status = $('build-status');
    $('build-confirm').disabled = true;

    if (modo.removendo) {
        const alvo = estruturaNaCelula(modo.posicao || modo.hover);
        const nome = alvo?.nome ?? 'esta estrutura';
        setBuildOverlay(alvo ? contornoArea(modo.lote, alvo.x, alvo.y, alvo.w, alvo.d, 'preview-remove') : '');
        status.textContent = modo.posicao && alvo
            ? `Remover ${nome}? Os créditos não são devolvidos.`
            : 'Toque em uma estrutura para removê-la.';
        $('build-confirm').textContent = 'Remover';
        $('build-confirm').disabled = !(modo.posicao && alvo);
        return;
    }

    $('build-confirm').textContent = 'Construir aqui';
    const e = estrutura();
    const cel = modo.posicao || modo.hover;
    if (!e) {
        setBuildOverlay('');
        status.textContent = 'Escolha uma estrutura na lista.';
        return;
    }
    if (!cel) {
        setBuildOverlay('');
        const efeitos = textoEfeitos(e);
        status.textContent = `${e.nome}: toque no lote para posicionar.${efeitos ? ` ${efeitos}` : ""}`;
        return;
    }

    const [w, d] = tamanho(e);
    const pos = encaixar(cel, w, d);
    const erro = validar(pos, w, d, e);
    const [x0, y0] = LOTE_ORIGEM[modo.lote];
    const desenho = desenharEstrutura(e, x0 + pos.x, y0 + pos.y, w, d, condicoesAtuais(), modo.rotacao);
    setBuildOverlay(
        contornoArea(modo.lote, pos.x, pos.y, w, d, erro ? 'preview-bad' : 'preview-ok')
        + `<g class="preview-ghost">${desenho.chao}${desenho.objeto}</g>`
    );
    const efeito = textoEfeitos(e);
    if (modo.posicao) {
        status.textContent = erro || `${e.nome} (${w}×${d}) por ${e.custo} créditos. ${giroTexto()}${efeito ? ` ${efeito}` : ""} Confirme para construir.`;
        $('build-confirm').disabled = Boolean(erro);
    } else {
        status.textContent = erro || `${e.nome} (${w}×${d}) · ${e.custo} créditos. ${giroTexto()}${efeito ? ` ${efeito}` : ""} Toque para escolher o lugar.`;
    }
}

/* ---------------------------------------------------------------------------
   Painel
   --------------------------------------------------------------------------- */

function renderPainel() {
    const nivel = nivelDaCidade();
    $('build-lots').innerHTML = [2, 3, 4].map((l) => {
        const ok = state.construcao.lotes.includes(l);
        return `<button type="button" class="btn btn-sm ${modo.lote === l ? 'btn-primary' : 'btn-outline'}" data-lote="${l}" ${ok ? '' : 'disabled'}
            title="${ok ? `Lote ${l}` : `Libera com a cidade no nível ${l}`}">${ok ? '' : icon('lock')}Lote ${l}</button>`;
    }).join('');

    const cats = Object.entries(state.construcao.categorias || {}).filter(([k]) => state.construcao.catalogo.some((e) => e.categoria === k));
    if (!cats.some(([k]) => k === modo.categoria)) modo.categoria = cats[0]?.[0] ?? null;
    $('build-cats').innerHTML = cats.map(([k, nome]) =>
        `<button type="button" class="build-cat ${modo.categoria === k ? 'is-active' : ''}" data-categoria="${k}" aria-pressed="${modo.categoria === k}">${escapeHTML(nome)}</button>`).join('');

    $('build-palette').innerHTML = state.construcao.catalogo.filter((e) => e.categoria === modo.categoria).map((e) => {
        const bloqueada = nivel < e.nivelMinimo;
        const semSaldo = state.jogador.creditosVerde < e.custo;
        return `<button type="button" class="build-item ${modo.estruturaId === e.id ? 'is-selected' : ''}" data-estrutura="${e.id}"
                ${bloqueada ? 'disabled' : ''} aria-pressed="${modo.estruturaId === e.id}" title="${escapeHTML(e.descricao || '')}">
            <strong>${escapeHTML(e.nome)}</strong>
            <span>${e.largura}×${e.profundidade}</span>
            <span class="build-cost ${semSaldo ? 'is-short' : ''}">${icon('coin')} ${e.custo}</span>
            ${e.efeito === 'polui' ? `<span class="build-effect is-bad" title="${escapeHTML(textoEfeito(e))}">Polui · ${escapeHTML(nomeArea(e.indicador))}</span>` : ''}
            ${e.efeito === 'compensa' ? `<span class="build-effect is-ok" title="${escapeHTML(textoEfeito(e))}">Compensa · ${escapeHTML(nomeArea(e.indicador))}</span>` : ''}
            ${efeitosNaCidade(e).map((t) => `<span class="build-effect is-info">${escapeHTML(t)}</span>`).join('')}
            ${bloqueada ? `<span class="build-lock">${icon('lock')} Cidade nível ${e.nivelMinimo}</span>` : ''}
        </button>`;
    }).join('');

    renderPoluicao();
    $('build-remove').setAttribute('aria-pressed', String(modo.removendo));
    $('build-remove').classList.toggle('is-active', modo.removendo);
    atualizarPrevia();
}

function abrir() {
    if (!state.construcao.lotes.length) {
        toast('Os lotes de construção são liberados quando a cidade chega ao nível 2.', 'info');
        return;
    }
    modo.lote = modo.lote && state.construcao.lotes.includes(modo.lote) ? modo.lote : state.construcao.lotes[0];
    $('build-panel').classList.remove('hidden');
    $('city-toolbar').classList.add('hidden');
    $('city-legend').classList.add('hidden');
    focarLote(modo.lote);
    renderPainel();
}

function fechar() {
    Object.assign(modo, { lote: null, posicao: null, hover: null, removendo: false });
    $('build-panel').classList.add('hidden');
    $('city-toolbar').classList.remove('hidden');
    $('city-legend').classList.remove('hidden');
    focarLote(null);
}

async function confirmar() {
    if (modo.ocupado || !modo.posicao) return;
    modo.ocupado = true;
    $('build-confirm').disabled = true;
    try {
        if (modo.removendo) {
            const alvo = estruturaNaCelula(modo.posicao);
            if (alvo) { await removerConstrucao(alvo.id); tocar('remover'); }
        } else {
            const e = estrutura();
            if (!e) throw new Error('Esta estrutura não está mais disponível. Escolha outra na lista.');
            const [w, d] = tamanho(e);
            const pos = encaixar(modo.posicao, w, d);
            await colocarConstrucao(e.id, modo.lote, pos.x, pos.y, modo.rotacao);
            tocar('construir');
        }
        modo.posicao = null;
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        modo.ocupado = false;
        renderPainel();
    }
}

/** Chamado a cada atualização do estado (após renderizar a cidade). */
export function renderBuildMode() {
    renderEfeitosCidade();
    if (!modo.lote) {
        $('build-open').disabled = false;
        return;
    }
    if (!state.construcao.lotes.includes(modo.lote)) {   // cidade perdeu o nível do lote
        fechar();
        return;
    }
    renderPainel();
}

export function initBuildMode() {
    $('build-open').addEventListener('click', abrir);
    $('city-collect').addEventListener('click', coletar);
    // Moedas sobre o comércio no mapa (city-map.js) também coletam
    $('city-map').addEventListener('click', (e) => { if (e.target.closest('[data-coletar]')) coletar(); });
    $('city-map').addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-coletar]')) { e.preventDefault(); coletar(); }
    });
    $('build-close').addEventListener('click', fechar);
    $('build-confirm').addEventListener('click', confirmar);
    $('build-rotate').addEventListener('click', (e) => girar(e.shiftKey ? -1 : 1));
    $('build-remove').addEventListener('click', () => {
        modo.removendo = !modo.removendo;
        modo.posicao = null;
        renderPainel();
    });

    $('build-lots').addEventListener('click', (e) => {
        const b = e.target.closest('[data-lote]');
        if (!b) return;
        modo.lote = Number(b.dataset.lote);
        modo.posicao = null;
        focarLote(modo.lote);
        renderPainel();
    });

    $('build-cats').addEventListener('click', (e) => {
        const b = e.target.closest('[data-categoria]');
        if (!b) return;
        modo.categoria = b.dataset.categoria;
        renderPainel();
    });

    $('build-palette').addEventListener('click', (e) => {
        const b = e.target.closest('[data-estrutura]');
        if (!b) return;
        modo.estruturaId = Number(b.dataset.estrutura);
        modo.removendo = false;
        modo.posicao = null;
        renderPainel();
    });

    const celulaDoEvento = (e) => {
        const pt = pontoNoSvg(e.clientX, e.clientY);
        return pt && modo.lote ? celulaDoLote(modo.lote, pt.x, pt.y) : null;
    };
    const mapa = $('city-map');
    mapa.addEventListener('pointermove', (e) => {
        if (!modo.lote || modo.posicao) return;
        const cel = celulaDoEvento(e);
        if (cel?.x !== modo.hover?.x || cel?.y !== modo.hover?.y) {
            modo.hover = cel;
            atualizarPrevia();
        }
    });
    mapa.addEventListener('click', (e) => {
        if (!modo.lote) return;
        const cel = celulaDoEvento(e);
        modo.posicao = cel;
        modo.hover = cel;
        atualizarPrevia();
    });

    document.addEventListener('keydown', (e) => {
        // Só com o modo Construir aberto e a aba da cidade à vista (não reage em outras abas nem com janelas abertas)
        if (!modo.lote || $('tab-cidade').hidden || e.target.closest('input, textarea, select, dialog, [role="dialog"]')) return;
        if (e.key === 'r' || e.key === 'R') girar(e.shiftKey ? -1 : 1);
        if (e.key === 'Escape') { modo.posicao ? (modo.posicao = null, atualizarPrevia()) : fechar(); }
        if (e.key === 'Enter' && !$('build-confirm').disabled) confirmar();
    });
}
