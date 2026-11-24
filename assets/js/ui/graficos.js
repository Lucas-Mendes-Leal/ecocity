/**
 * Gráficos simples em SVG/HTML, sem biblioteca (stack fixa). Usados na Visão Geral da
 * administração e no Perfil. Cada gráfico tem texto alternativo (aria-label) e os valores escritos.
 * As cores vêm das variáveis CSS das categorias (base.css).
 */
import { escapeHTML } from './dom.js';

export const COR_CATEGORIA = {
    recycling: 'var(--cat-recycling)', water: 'var(--cat-water)', energy: 'var(--cat-energy)',
    mobility: 'var(--cat-mobility)', nature: 'var(--cat-nature)',
};
const COR_PADRAO = 'var(--primary-color)';

export const numero = (n, casas = 1) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('pt-BR', { maximumFractionDigits: casas }));
export const semanaBR = (iso) => { const [, m, d] = iso.split('-'); return `${d}/${m}`; };

/** Aviso padrão para recortes com menos jogadores que o mínimo (RN83). */
export const POUCOS = '<span class="muted" title="Menos de 5 jogadores neste recorte">poucos dados</span>';

let tooltipInit = false;
let tipEl = null;
let ativoAlvo = null;

function limparAtivo() {
    if (ativoAlvo) {
        ativoAlvo.closest('.g-ponto-grupo, .g-barra')?.classList.remove('is-active');
        ativoAlvo = null;
    }
}

function esconderTooltip() {
    if (tipEl) tipEl.hidden = true;
    limparAtivo();
}

function mostrarTooltip(alvo) {
    if (!tipEl || !alvo) return;
    const data = alvo.dataset.gData || '';
    const valor = alvo.dataset.gValor || '';
    const serie = alvo.dataset.gSerie || '';
    const cor = alvo.dataset.gCor || COR_PADRAO;

    limparAtivo();
    ativoAlvo = alvo;
    alvo.closest('.g-ponto-grupo, .g-barra')?.classList.add('is-active');

    tipEl.innerHTML = `<div class="g-tip-data">${escapeHTML(data)}</div>`
        + `<div class="g-tip-linha"><span class="g-tip-dot" style="background:${escapeHTML(cor)}"></span>`
        + `${serie ? `<span class="g-tip-serie">${escapeHTML(serie)}:</span> ` : ''}`
        + `<strong class="g-tip-val">${escapeHTML(valor)}</strong></div>`;
    tipEl.hidden = false;

    const r = alvo.getBoundingClientRect();
    const tr = tipEl.getBoundingClientRect();
    const margem = 8;
    const left = Math.max(margem, Math.min(window.innerWidth - tr.width - margem, r.left + r.width / 2 - tr.width / 2));
    let top = r.top - tr.height - 8;
    if (top < margem) top = r.bottom + 8;
    tipEl.style.left = `${Math.round(left)}px`;
    tipEl.style.top = `${Math.round(top)}px`;
}

function pontoMaisProximo(svg, cx, cy) {
    const hits = svg.querySelectorAll('circle.g-hit');
    if (!hits.length) return null;
    let melhor = null;
    let melhorDist = 18; // raio máximo em px na tela
    hits.forEach((h) => {
        const r = h.getBoundingClientRect();
        const dx = cx - (r.left + r.width / 2);
        const dy = cy - (r.top + r.height / 2);
        const d = Math.hypot(dx, dy);
        if (d <= melhorDist) {
            melhorDist = d;
            melhor = h;
        }
    });
    return melhor;
}

function initTooltipGraficos() {
    if (tooltipInit || typeof document === 'undefined' || !document.body) return;
    tooltipInit = true;
    tipEl = document.createElement('div');
    tipEl.className = 'g-tooltip';
    tipEl.setAttribute('role', 'tooltip');
    tipEl.hidden = true;
    document.body.appendChild(tipEl);

    document.addEventListener('mousemove', (e) => {
        const svg = e.target.closest?.('svg.grafico');
        if (!svg) return;
        const perto = pontoMaisProximo(svg, e.clientX, e.clientY);
        if (perto) {
            if (ativoAlvo !== perto || tipEl.hidden) mostrarTooltip(perto);
            return;
        }
        const barra = e.target.closest?.('rect.g-barra[data-g-tip]');
        if (barra) {
            if (ativoAlvo !== barra || tipEl.hidden) mostrarTooltip(barra);
        } else if (!tipEl.hidden) {
            esconderTooltip();
        }
    });
    document.addEventListener('mouseover', (e) => {
        const alvo = e.target.closest?.('[data-g-tip]');
        if (!alvo) return;
        const svg = alvo.closest?.('svg.grafico');
        const escolhido = (svg && e.clientX) ? (pontoMaisProximo(svg, e.clientX, e.clientY) || alvo) : alvo;
        mostrarTooltip(escolhido);
    });
    document.addEventListener('mouseout', (e) => {
        const svg = e.target.closest?.('svg.grafico');
        const proxSvg = e.relatedTarget?.closest?.('svg.grafico');
        if (svg) {
            if (proxSvg !== svg) esconderTooltip();
            return;
        }
        const alvo = e.target.closest?.('[data-g-tip]');
        if (!alvo) return;
        const prox = e.relatedTarget?.closest?.('[data-g-tip]');
        if (prox !== alvo) esconderTooltip();
    });
    document.addEventListener('focusin', (e) => {
        const alvo = e.target.closest?.('[data-g-tip]');
        if (alvo) mostrarTooltip(alvo);
    });
    document.addEventListener('focusout', (e) => {
        if (e.target.closest?.('[data-g-tip]')) esconderTooltip();
    });
    document.addEventListener('click', (e) => {
        const alvo = e.target.closest?.('[data-g-tip]');
        if (!alvo) {
            if (tipEl && !tipEl.hidden) esconderTooltip();
            return;
        }
        if (ativoAlvo === alvo && !tipEl.hidden) esconderTooltip();
        else mostrarTooltip(alvo);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') esconderTooltip();
    });
    window.addEventListener('scroll', esconderTooltip, { capture: true, passive: true });
    window.addEventListener('resize', esconderTooltip, { passive: true });
}

/** Barras horizontais (HTML, mesmo estilo de .stat-bars). itens: { rotulo, valor, texto?, cor?, nota? } */
export function barras(itens, { max = null, vazio = 'Sem dados no período.' } = {}) {
    if (!itens.length) return `<p class="muted">${vazio}</p>`;
    const topo = max ?? Math.max(1, ...itens.map((i) => i.valor ?? 0));
    return `<ul class="stat-bars">${itens.map((i) => `
        <li>
            <span class="stat-label">${i.rotulo}</span>
            <span class="stat-track"><span class="stat-fill" style="width:${i.valor === null ? 0 : Math.max(0, Math.min(100, (i.valor / topo) * 100))}%;background:${i.cor || COR_PADRAO}"></span></span>
            <strong>${i.texto ?? numero(i.valor)}</strong>
        </li>${i.nota ? `<li class="stat-note">${i.nota}</li>` : ''}`).join('')}</ul>`;
}

/** Colunas verticais em SVG. rotulos: string[]; valores: number[] */
export function colunas(rotulos, valores, { cor = COR_PADRAO, titulo = 'Gráfico de colunas', sufixo = '', sufixoTooltip = null, serie = '' } = {}) {
    if (!valores.length || valores.every((v) => !v)) return '<p class="muted">Sem dados no período.</p>';
    initTooltipGraficos();
    const sufTip = sufixoTooltip ?? sufixo;
    const L = 600, A = 180, base = 150, topo = 20;
    const max = Math.max(1, ...valores);
    const passo = L / valores.length;
    const larg = Math.min(48, passo * 0.6);
    const barras = valores.map((v, i) => {
        const h = ((v || 0) / max) * (base - topo);
        const x = i * passo + (passo - larg) / 2;
        const valFmt = `${numero(v, 2)}${sufTip}`;
        const aria = `${serie ? `${serie} · ` : ''}${rotulos[i]}: ${valFmt}`;
        return `<rect class="g-barra" x="${x.toFixed(1)}" y="${(base - h).toFixed(1)}" width="${larg.toFixed(1)}" height="${h.toFixed(1)}" rx="4" style="fill:${cor}" tabindex="0" data-g-tip="1" data-g-data="${escapeHTML(rotulos[i])}" data-g-valor="${escapeHTML(valFmt)}" data-g-serie="${escapeHTML(serie)}" data-g-cor="${escapeHTML(cor)}" aria-label="${escapeHTML(aria)}"><title>${escapeHTML(aria)}</title></rect>
            ${v ? `<text x="${(x + larg / 2).toFixed(1)}" y="${(base - h - 5).toFixed(1)}" class="g-valor">${numero(v)}</text>` : ''}
            <text x="${(x + larg / 2).toFixed(1)}" y="${base + 18}" class="g-eixo">${escapeHTML(rotulos[i])}</text>`;
    }).join('');
    const resumo = rotulos.map((r, i) => `${r}: ${numero(valores[i])}${sufixo}`).join('; ');
    return `<svg class="grafico" viewBox="0 0 ${L} ${A}" role="img" aria-label="${escapeHTML(`${titulo}. ${resumo}`)}">
        <line x1="0" y1="${base}" x2="${L}" y2="${base}" class="g-base"/>${barras}</svg>`;
}

/** Linhas em SVG (0 a max; max = null calcula o topo automático). series: { nome, cor, valores: (number|null)[] } */
export function linhas(rotulos, series, { max = 100, sufixo = '%', sufixoTooltip = null, titulo = 'Gráfico de linhas', vazio = 'Sem dados suficientes no período (mínimo de 5 jogadores por ponto).' } = {}) {
    const comDados = series.filter((s) => s.valores.some((v) => v !== null && v !== undefined));
    if (!rotulos.length || !comDados.length) return `<p class="muted">${vazio}</p>`;
    initTooltipGraficos();
    const sufTip = sufixoTooltip ?? sufixo;
    const maxVal = max ?? Math.max(1, ...comDados.flatMap((s) => s.valores.filter((v) => v !== null && v !== undefined)));
    const L = 600, A = 190, esq = 42, dir = 12, topo = 12, base = 160;
    const x = (i) => (rotulos.length === 1 ? (esq + L - dir) / 2 : esq + (i * (L - esq - dir)) / (rotulos.length - 1));
    const y = (v) => base - (Math.min(v, maxVal) / maxVal) * (base - topo);
    const casasEixo = maxVal < 10 ? 1 : 0;
    const grade = [0, 0.5, 1].map((f) => `<line x1="${esq}" y1="${y(maxVal * f)}" x2="${L - dir}" y2="${y(maxVal * f)}" class="g-grade"/><text x="${esq - 6}" y="${y(maxVal * f) + 4}" class="g-eixo g-eixo-y">${numero(maxVal * f, casasEixo)}${sufixo}</text>`).join('');
    const eixoX = rotulos.map((r, i) => `<text x="${x(i)}" y="${base + 18}" class="g-eixo">${escapeHTML(r)}</text>`).join('');
    const caminhos = [];
    const pontos = [];
    comDados.forEach((s) => {
        const cor = s.cor || COR_PADRAO;
        const pts = s.valores.map((v, i) => (v === null || v === undefined ? null : [x(i), y(v), v, i])).filter(Boolean);
        const caminho = pts.map(([px, py], k) => `${k ? 'L' : 'M'}${px.toFixed(1)},${py.toFixed(1)}`).join(' ');
        caminhos.push(`<path d="${caminho}" class="g-linha" style="stroke:${cor}"/>`);
        pts.forEach(([px, py, v, i]) => {
            const valFmt = `${numero(v, 2)}${sufTip}`;
            const label = `${s.nome} · ${rotulos[i]}: ${valFmt}`;
            pontos.push(
                `<g class="g-ponto-grupo">`
                + `<circle class="g-ponto" cx="${px.toFixed(1)}" cy="${py.toFixed(1)}" r="4.5" style="fill:${cor}"/>`
                + `<circle class="g-hit" cx="${px.toFixed(1)}" cy="${py.toFixed(1)}" r="10" tabindex="0" data-g-tip="1" data-g-data="${escapeHTML(rotulos[i])}" data-g-valor="${escapeHTML(valFmt)}" data-g-serie="${escapeHTML(s.nome)}" data-g-cor="${escapeHTML(cor)}" aria-label="${escapeHTML(label)}"><title>${escapeHTML(label)}</title></circle>`
                + `</g>`
            );
        });
    });
    const tracos = caminhos.join('') + pontos.join('');
    const legenda = comDados.length > 1
        ? `<ul class="g-legenda">${comDados.map((s) => `<li><span style="background:${s.cor || COR_PADRAO}"></span>${escapeHTML(s.nome)}</li>`).join('')}</ul>` : '';
    const resumo = comDados.map((s) => `${s.nome}: ${s.valores.map((v, i) => `${rotulos[i]} ${v === null ? 'sem dados' : numero(v, 2) + sufTip}`).join(', ')}`).join('. ');
    return `<svg class="grafico" viewBox="0 0 ${L} ${A}" role="img" aria-label="${escapeHTML(`${titulo}. ${resumo}`)}">${grade}${eixoX}${tracos}</svg>${legenda}`;
}

/** Mapa de calor 7 dias × 24 horas (HTML). matriz[dia 0=seg][hora] */
export function mapaCalor(matriz) {
    const max = Math.max(0, ...matriz.flat());
    if (!max) return '<p class="muted">Sem ações no período.</p>';
    const dias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
    const cab = `<span></span>${Array.from({ length: 24 }, (_, h) => `<span class="calor-h">${h % 3 === 0 ? `${h}h` : ''}</span>`).join('')}`;
    const linhasHtml = matriz.map((horas, d) => `<span class="calor-dia">${dias[d]}</span>${horas.map((n, h) =>
        `<span class="calor-cel" style="--forca:${n ? (0.15 + 0.85 * (n / max)).toFixed(2) : 0}" title="${dias[d]} ${h}h: ${n} ${n === 1 ? 'ação' : 'ações'}"></span>`).join('')}`).join('');
    const pico = matriz.flatMap((horas, d) => horas.map((n, h) => ({ n, d, h }))).sort((a, b) => b.n - a.n)[0];
    return `<div class="calor" role="img" aria-label="Ações por dia da semana e hora. Pico: ${dias[pico.d]} às ${pico.h}h com ${pico.n}.">${cab}${linhasHtml}</div>
        <p class="form-hint">Mais escuro = mais ações. Pico: <strong>${dias[pico.d]} às ${pico.h}h</strong> (${pico.n}).</p>`;
}

/** Barra empilhada (HTML). partes: { rotulo, valor, cor } */
export function empilhada(partes) {
    const total = partes.reduce((s, p) => s + p.valor, 0);
    if (!total) return '<span class="muted">—</span>';
    return `<span class="pilha" role="img" aria-label="${escapeHTML(partes.map((p) => `${p.rotulo}: ${p.valor}`).join(', '))}">${partes.filter((p) => p.valor).map((p) =>
        `<span style="width:${(p.valor / total) * 100}%;background:${p.cor}" title="${escapeHTML(p.rotulo)}: ${p.valor}"></span>`).join('')}</span>`;
}

/** Funil (HTML): etapas { rotulo, n }, % sobre a primeira. */
export function funil(etapas) {
    const base = etapas[0]?.n || 0;
    if (!base) return '<p class="muted">Nenhum cadastro no período.</p>';
    return barras(etapas.map((e) => ({ rotulo: e.rotulo, valor: e.n, texto: `${numero(e.n, 0)} · ${numero((e.n / base) * 100, 0)}%` })), { max: base });
}

/** Anel com porcentagem (SVG pequeno). */
export function anel(pct, { cor = COR_PADRAO, rotulo = '' } = {}) {
    const r = 26, c = 2 * Math.PI * r;
    const v = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct));
    return `<svg class="g-anel" viewBox="0 0 64 64" role="img" aria-label="${escapeHTML(rotulo)} ${pct === null ? 'sem dados' : numero(pct, 0) + '%'}">
        <circle cx="32" cy="32" r="${r}" class="g-anel-trilha"/>
        <circle cx="32" cy="32" r="${r}" class="g-anel-valor" style="stroke:${cor};stroke-dasharray:${(c * v / 100).toFixed(1)} ${c.toFixed(1)}"/>
        <text x="32" y="37" class="g-anel-texto">${pct === null ? '—' : `${numero(pct, 0)}%`}</text></svg>`;
}
