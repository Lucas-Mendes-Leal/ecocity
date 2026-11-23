/**
 * Aba Missões: faixa com o resumo da semana e "Missões da semana" (cards e resultados anteriores).
 * Conclusão, recompensa e penalidade são decididas no servidor (includes/missoes.php);
 * aqui só se calcula o que mostrar a partir do estado.
 */
import { CATEGORIAS } from '../data/catalog.js';
import { state } from '../state.js';
import { icon } from './icons.js';
import { $, escapeHTML, toast } from './dom.js';

/** Categoria pelo id do indicador (ordem de CATEGORIAS = ids 1..5 do banco). */
export const categoriaDoIndicador = (id) => CATEGORIAS[id - 1];

const DIA_MS = 86400000;
const dataCurta = (d) => d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
const fmt = (n) => n.toLocaleString('pt-BR');

/** Ficha de ganho: ⭐ XP, moeda, ou +N na área. */
export const fichaXp = (n) => `<span class="reward-chip chip-xp">${icon('star')}${fmt(n)} XP</span>`;
export const fichaCredito = (n) => `<span class="reward-chip chip-credit">${icon('coin')}${fmt(n)}</span>`;
export const fichaArea = (n, cat) => `<span class="reward-chip chip-area cat-${cat.id}">${icon(cat.icone)}+${n} ${escapeHTML(cat.curto)}</span>`;

const progressoMissao = (m) => {
    const meta = m.metas.reduce((s, x) => s + x.meta, 0);
    const feito = m.metas.reduce((s, x) => s + Math.min(x.feito, x.meta), 0);
    return { meta, feito, pct: meta ? Math.round((feito / meta) * 100) : 0, falta: meta - feito };
};

/** Último instante da semana atual: o fim da missão semanal ou, sem ela, domingo 23h59. */
function fimDaSemana() {
    const semanal = state.missoes.atuais.find((m) => m.tipo === 'Semanal');
    if (semanal) return new Date(semanal.fim);
    const d = new Date();
    d.setDate(d.getDate() + ((7 - d.getDay()) % 7));
    d.setHours(23, 59, 59, 0);
    return d;
}

/* ---------------------------------------------------------------------------
   Faixa do topo
   --------------------------------------------------------------------------- */

export function renderHeroMissoes() {
    const atuais = state.missoes.atuais;
    const concluidas = atuais.filter((m) => m.concluida).length;
    const pendentes = atuais.filter((m) => !m.concluida);
    const xp = pendentes.reduce((s, m) => s + m.recompensaXp, 0);
    const credito = pendentes.reduce((s, m) => s + m.recompensaCredito, 0);
    const hoje = new Date().toDateString();
    const feitasHoje = new Set(state.registros.filter((r) => r.status !== 'rejeitado' && new Date(r.data).toDateString() === hoje).map((r) => r.atividadeId)).size;
    const totalAtiv = state.atividades.length;

    const metas = atuais.flatMap((m) => m.metas);
    const metaTotal = metas.reduce((s, x) => s + x.meta, 0);
    const feitoTotal = metas.reduce((s, x) => s + Math.min(x.feito, x.meta), 0);
    const pct = metaTotal ? Math.round((feitoTotal / metaTotal) * 100) : 0;

    const fim = fimDaSemana();
    const inicio = new Date(fim.getTime() - 6 * DIA_MS);
    const dias = Math.max(0, Math.ceil((fim - new Date()) / DIA_MS));
    $('mh-semana').textContent = `Semana ${dataCurta(inicio)} a ${dataCurta(fim)} · ${dias <= 1 ? 'último dia!' : `faltam ${dias} dias`}`;

    // Frase de incentivo conforme o andamento (texto de interface)
    const maisPerto = pendentes.map((m) => ({ m, ...progressoMissao(m) })).sort((a, b) => a.falta - b.falta)[0];
    let frase;
    if (atuais.length && !pendentes.length) frase = 'Semana completa! Todas as missões concluídas.';
    else if (maisPerto && maisPerto.falta <= 1 && maisPerto.feito > 0) frase = `Falta só 1 ação para concluir "${maisPerto.m.nome}"!`;
    else if (feitasHoje === 0) frase = 'Comece registrando uma ação hoje!';
    else if (feitasHoje < totalAtiv) frase = `Boa! ${feitasHoje} ${feitasHoje === 1 ? 'ação registrada' : 'ações registradas'} hoje. Que tal mais uma?`;
    else frase = 'Você fez todas as ações de hoje. Volte amanhã!';
    $('mh-frase').textContent = frase;

    $('mh-missoes').textContent = `${concluidas}/${atuais.length}`;
    $('mh-hoje').textContent = `${feitasHoje}/${totalAtiv}`;
    $('mh-ganhar').innerHTML = pendentes.length ? `${fmt(xp)} XP <small>+ ${fmt(credito)} créditos</small>` : `${icon('check')} Tudo ganho`;
    $('mh-fill').style.width = `${pct}%`;
    $('mh-track').setAttribute('aria-valuenow', String(pct));
    $('mh-pct').textContent = `${pct}% das metas`;
    $('mt-missoes-count').textContent = `${concluidas}/${atuais.length}`;
    $('mt-agir-count').textContent = `${feitasHoje}/${totalAtiv}`;
}

/* ---------------------------------------------------------------------------
   Cards das missões
   --------------------------------------------------------------------------- */

const diasAte = (iso) => Math.ceil((new Date(iso) - new Date()) / DIA_MS);
const dataLonga = (iso) => new Date(iso).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' });
/** Dias de calendário do período (início e fim inclusos). */
const duracao = (m) => Math.round((new Date(m.fim).setHours(0, 0, 0, 0) - new Date(m.inicio).setHours(0, 0, 0, 0)) / DIA_MS) + 1;

/** "termina hoje" / "termina amanhã" / "termina em N dias" (missão especial em andamento). */
function prazoEspecial(m) {
    const d = Math.floor((new Date(m.fim).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / DIA_MS);
    if (d <= 0) return 'termina hoje';
    return d === 1 ? 'termina amanhã' : `termina em ${d} dias`;
}

function cardMissao(m) {
    const especial = m.tipo === 'Especial';
    const areas = [...new Set(m.metas.map((x) => x.indicador))].map(categoriaDoIndicador).filter(Boolean);
    const principal = areas[0];
    const { pct } = progressoMissao(m);
    const metas = m.metas.map((x) => {
        const feito = Math.min(x.feito, x.meta);
        const cat = categoriaDoIndicador(x.indicador);
        return `<li class="mission-goal ${feito >= x.meta ? 'is-done' : ''}">
            <div class="mission-goal-label"><span>${feito >= x.meta ? icon('check') : ''}${escapeHTML(x.nome)}</span><strong>${feito}/${x.meta}</strong></div>
            <div class="bar-track bar-sm" role="progressbar" aria-label="${escapeHTML(x.nome)}" aria-valuemin="0" aria-valuemax="${x.meta}" aria-valuenow="${feito}">
                <div class="bar-fill fill-${cat?.id ?? 'overall'}" style="width:${(feito / x.meta) * 100}%"></div>
            </div>
        </li>`;
    }).join('');

    return `<article class="weekly-card ${especial ? 'special-card' : ''} ${m.concluida ? 'is-done' : ''} cat-top-${principal?.id ?? 'none'}">
        ${especial ? `<div class="special-ribbon">${icon('star')} Evento especial <span>· ${m.concluida ? 'concluído' : prazoEspecial(m)}</span></div>` : ''}
        <div class="weekly-head">
            <span class="weekly-icon cat-icon cat-${principal?.id ?? ''}">${principal ? icon(principal.icone) : icon('trophy')}</span>
            <div class="weekly-title">
                <h4>${escapeHTML(m.nome)}</h4>
                <small>${especial ? `${dataCurta(new Date(m.inicio))} a ${dataCurta(new Date(m.fim))}` : `${m.tipo}${m.concluida ? '' : ` · até ${dataLonga(m.fim)}`}`}</small>
            </div>
            ${m.concluida
                ? `<span class="weekly-stamp">${icon('check')} Concluída</span>`
                : `<span class="weekly-ring" style="--pct:${pct}" role="img" aria-label="${pct}% concluída"><span>${pct}%</span></span>`}
        </div>
        <p class="weekly-desc">${escapeHTML(m.descricao)}</p>
        <ul class="mission-goals">${metas}</ul>
        <div class="weekly-reward">
            <span class="weekly-reward-label">${m.concluida ? 'Recompensa recebida' : 'Recompensa extra ao concluir'}</span>
            <span class="reward-chips">${fichaXp(m.recompensaXp)}${fichaCredito(m.recompensaCredito)}</span>
        </div>
        ${m.concluida ? '' : especial
            ? `<p class="weekly-penalty">${icon('help')} Evento opcional: não concluir não tira pontos.</p>`
            : `<p class="weekly-penalty">${icon('help')} Se não concluir: −${m.penalidade} em ${areas.map((c) => escapeHTML(c.curto)).join(', ')}</p>`}
    </article>`;
}

/** Card pequeno de missão especial que ainda vai começar. */
function cardEmBreve(m) {
    const areas = [...new Set(m.metas.map((x) => x.indicador))].map(categoriaDoIndicador).filter(Boolean);
    const d = diasAte(m.inicio);
    const dias = duracao(m);
    return `<li class="special-soon-card cat-top-${areas[0]?.id ?? 'none'}">
        <span class="special-soon-date"><strong>${new Date(m.inicio).toLocaleDateString('pt-BR', { day: '2-digit' })}</strong>${new Date(m.inicio).toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '')}</span>
        <div class="special-soon-info">
            <h4>${escapeHTML(m.nome)}</h4>
            <small>Começa ${d <= 1 ? 'amanhã' : `em ${d} dias`} · ${dias === 1 ? 'só 1 dia' : `${dias} dias, até ${dataCurta(new Date(m.fim))}`}</small>
            <p class="special-soon-goals">${m.metas.map((x) => `${x.meta}× ${escapeHTML(x.nome)}`).join(' · ')}</p>
            <span class="reward-chips">${fichaXp(m.recompensaXp)}${fichaCredito(m.recompensaCredito)}</span>
        </div>
    </li>`;
}

/* ---------------------------------------------------------------------------
   Aviso de missão especial nova (uma vez por missão e período)
   --------------------------------------------------------------------------- */

const avisadas = new Set();   // reserva se o navegador não deixar guardar
const chaveEspeciais = () => `ecocity:especiais-vistas:${state.jogador.codigoPublico}`;

export function notificarEspeciais() {
    const especiais = (state.missoes?.atuais ?? []).filter((m) => m.tipo === 'Especial' && !m.concluida);
    if (!especiais.length) return;
    let vistas;
    try { vistas = new Set(JSON.parse(localStorage.getItem(chaveEspeciais()) || '[]')); } catch { vistas = new Set(avisadas); }
    const novas = especiais.filter((m) => !vistas.has(`${m.id}:${m.inicio}`) && !avisadas.has(`${m.id}:${m.inicio}`));
    if (!novas.length) return;
    novas.forEach((m) => {
        toast(`Missão especial começou: ${m.nome}! Vale até ${dataLonga(m.fim)}. Veja em Missões › Missões da semana.`);
        vistas.add(`${m.id}:${m.inicio}`);
        avisadas.add(`${m.id}:${m.inicio}`);
    });
    try { localStorage.setItem(chaveEspeciais(), JSON.stringify([...vistas].slice(-50))); } catch { /* fica só na memória */ }
}

export function renderWeeklyMissions() {
    const { atuais, resultados, proximas = [] } = state.missoes;
    // Em andamento primeiro (as que estão mais perto de acabar), concluídas no fim
    const porAndamento = (a, b) => (a.concluida - b.concluida) || (progressoMissao(a).falta - progressoMissao(b).falta);
    const especiais = atuais.filter((m) => m.tipo === 'Especial').sort(porAndamento);
    const semanais = atuais.filter((m) => m.tipo !== 'Especial').sort(porAndamento);

    const blocoEspecial = $('missions-special');
    blocoEspecial.hidden = !especiais.length;
    blocoEspecial.innerHTML = especiais.length ? `<div class="special-grid">${especiais.map(cardMissao).join('')}</div>` : '';

    $('missions-current').innerHTML = semanais.length
        ? semanais.map(cardMissao).join('')
        : '<p class="empty-state">Nenhuma missão semanal ativa neste período.</p>';

    $('missions-soon').hidden = !proximas.length;
    $('missions-soon-list').innerHTML = proximas.map(cardEmBreve).join('');

    $('missions-results').innerHTML = resultados.length
        ? resultados.map((r) => `<li>
            <span class="tag ${r.status === 'concluida' ? 'tag-success' : 'tag-error'}">${r.status === 'concluida' ? 'Concluída' : 'Não concluída'}</span>
            <strong>${escapeHTML(r.nome)}</strong>
            <span class="muted">${r.especial ? 'evento especial de' : 'semana de'} ${new Date(`${r.periodo}T00:00:00`).toLocaleDateString('pt-BR')}${r.status === 'concluida' || !r.penalidade ? '' : ` · −${r.penalidade} pontos na área`}</span>
        </li>`).join('')
        : '<li class="empty-state">Nenhum resultado ainda. O primeiro aparece quando uma semana terminar.</li>';

    renderHeroMissoes();
}
