/**
 * Aba Missões — "Agir hoje" (cards de atividades com os ganhos lado a lado), registro (modal)
 * e "Meus registros" (linha do tempo com filtros e o ganho realmente recebido).
 *
 * Autodeclarada: aprovada na hora, recompensa menor.
 * Com evidência: foto obrigatória; aprovada por administrador ou automaticamente
 * após o prazo; recompensa completa.
 */
import { CATEGORIAS, CATEGORIA_POR_ID } from '../data/catalog.js';
import { state, registrarAtividade, decidirRegistroTeste, atividadePorId, validarCodigoParceiro, analisando, contestarRegistro } from '../state.js';
import { isDemo } from './demo.js';
import { icon } from './icons.js';
import { initTabs } from './tabs.js';
import { fichaXp, fichaCredito, fichaArea } from './weekly-missions.js';
import { $, escapeHTML, pager, toast } from './dom.js';
import { seletorTamanho, tamanhoGuardado, guardarTamanho } from './lista-admin.js';
import { tocar } from '../som.js';

const TIPO_AUTO = 1;
const TIPO_EVIDENCIA = 2;
const TIPO_PARCEIRO = 3;
const FOTO_TIPOS = ['image/jpeg', 'image/png', 'image/webp'];

const STATUS = {
    pendente:  { rotulo: 'Pendente',  classe: 'tag-warning' },
    aprovado:  { rotulo: 'Aprovado',  classe: 'tag-success' },
    rejeitado: { rotulo: 'Rejeitado', classe: 'tag-error' },
};
const TIPO_ROTULO = { [TIPO_AUTO]: 'Rápido · sem foto', [TIPO_EVIDENCIA]: 'Com foto', [TIPO_PARCEIRO]: 'Com parceiro' };
const numBR = (n) => Number(n).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
/** Parceiros aprovados que verificam a atividade. */
const parceirosDe = (atividadeId) => state.parceiros.filter((p) => p.atividades.includes(atividadeId));
const FILTROS = [['todos', 'Todos'], ['aprovado', 'Aprovados'], ['pendente', 'Pendentes'], ['rejeitado', 'Rejeitados']];
const TAMANHOS_REGISTROS = [10, 20, 30];
const CHAVE_TAMANHO = 'ecocity-registros-por-pagina';
const porPagina = () => tamanhoGuardado(CHAVE_TAMANHO, TAMANHOS_REGISTROS, 10);

let atividadeSelecionada = null;
let focoAnterior = null;
let enviando = false;
let filtro = 'todos';
let filtroAgir = 'todas';
let pagina = 1;
let contestando = null;   // registro sendo contestado (RN94)
let ativarSubaba = null;

/** Fichas de ganho de uma opção (XP, créditos e pontos na área da atividade). */
const fichas = (r, cat) => `${fichaXp(r.xp)}${fichaCredito(r.credito)}${cat ? fichaArea(r.impacto, cat) : ''}`;

const dataHora = (iso) => new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

const mesmoDia = (iso, ref = new Date()) => new Date(iso).toDateString() === ref.toDateString();

/** Registros de hoje que ocupam a vaga da atividade (a regra vale no servidor). */
function registradaHoje(atividadeId) {
    const feitos = state.registros.filter((r) => r.atividadeId === atividadeId && r.status !== 'rejeitado' && mesmoDia(r.data)).length;
    return feitos >= state.regras.registrosPorAtividadeDia;
}

/** Missões atuais em que a atividade conta, com o andamento da meta. */
function contaPara(atividadeId) {
    return state.missoes.atuais.flatMap((m) => m.metas.filter((x) => x.atividadeId === atividadeId)
        .map((x) => ({ nome: m.nome, feito: Math.min(x.feito, x.meta), meta: x.meta, ok: m.concluida || x.feito >= x.meta })));
}

function renderFiltrosAgir() {
    const box = $('agir-filtros');
    if (!box) return;
    const comParceiro = state.atividades.filter((a) => parceirosDe(a.id).length > 0).length;
    if (filtroAgir === 'parceiro' && !comParceiro) filtroAgir = 'todas';
    const opcoes = [
        ['todas', 'Todas', null, state.atividades.length],
        ...CATEGORIAS.map((c) => [c.id, c.curto, c.icone, state.atividades.filter((a) => a.categoria === c.id).length]),
        ...(comParceiro ? [['parceiro', 'Com parceiro', 'flag', comParceiro]] : []),
    ];
    box.innerHTML = opcoes.map(([id, rotulo, ic, qtd]) =>
        `<button type="button" class="chip ${filtroAgir === id ? 'is-active' : ''}" data-agir-filtro="${escapeHTML(id)}" aria-pressed="${filtroAgir === id}">${ic ? `${icon(ic)}` : ''}${escapeHTML(rotulo)} <span class="chip-count">${qtd}</span></button>`
    ).join('');
}

function renderCards() {
    renderFiltrosAgir();
    const lista = state.atividades.filter((a) => (
        filtroAgir === 'todas'
        || (filtroAgir === 'parceiro' ? parceirosDe(a.id).length > 0 : a.categoria === filtroAgir)
    ));
    $('missions-grid').innerHTML = lista.length ? lista.map((a) => {
        const cat = CATEGORIA_POR_ID[a.categoria];
        const feita = registradaHoje(a.id);
        const missoes = contaPara(a.id);
        const rapido = a.recompensas[TIPO_AUTO];
        const foto = a.recompensas[TIPO_EVIDENCIA];
        return `<article class="mission-card cat-top-${a.categoria} ${feita ? 'is-done-today' : ''}">
            <div class="mission-card-top">
                <span class="mission-card-icon">${icon(cat.icone)}</span>
                <span class="mission-card-area">${escapeHTML(cat.nome)}</span>
                ${feita ? `<span class="mission-today">${icon('check')} Feita hoje</span>` : ''}
            </div>
            <div class="mission-card-body">
                <h4>${escapeHTML(a.nome)}</h4>
                <p>${escapeHTML(a.descricao)}</p>
                ${a.aceitaFoto ? `<div class="reward-options">
                    <div class="reward-option">
                        <span class="reward-option-title">Rápido · sem foto</span>
                        <span class="reward-chips">${fichas(rapido, cat)}</span>
                        <small>Ganha na hora</small>
                    </div>
                    <div class="reward-option is-best">
                        <span class="reward-option-title">Com foto <span class="reward-best">${icon('star')} vale o dobro</span></span>
                        <span class="reward-chips">${fichas(foto, cat)}</span>
                        <small>A IA confere a foto na hora</small>
                    </div>
                </div>` : `<div class="reward-options is-single">
                    <div class="reward-option is-best">
                        <span class="reward-option-title">Sem foto · valor cheio</span>
                        <span class="reward-chips">${fichas(rapido, cat)}</span>
                        <small>Esta ação não tem como ser fotografada: ganha tudo na hora</small>
                    </div>
                </div>`}
                ${parceirosDe(a.id).length ? `<p class="mission-partner">${icon('flag')} <span>Com parceiro <strong>${icon('star')} 150%</strong> em ${parceirosDe(a.id).length} ${parceirosDe(a.id).length === 1 ? 'local' : 'locais'}</span>
                    <button type="button" class="link-btn" data-ir-parceiros>ver onde</button></p>` : ''}
                ${missoes.length ? `<p class="mission-counts">${icon('trophy')} Conta para: ${missoes.map((x) => `<span class="${x.ok ? 'is-ok' : ''}">${escapeHTML(x.nome)} ${x.ok ? '✓' : `${x.feito}/${x.meta}`}</span>`).join(' · ')}</p>` : ''}
                ${feita
                    ? `<p class="mission-done-msg">${icon('check')} Registrada hoje — volte amanhã para ganhar de novo.</p>`
                    : `<button type="button" class="btn btn-primary btn-block btn-action" data-atividade="${a.id}">${icon('leaf')} Registrar ação real</button>`}
            </div>
        </article>`;
    }).join('') : '<p class="card empty-state">Nenhuma atividade encontrada neste filtro.</p>';
}

/* ---------------------------------------------------------------------------
   Modal de registro
   --------------------------------------------------------------------------- */

const tipoEscolhido = () => Number(document.querySelector('input[name="tipo-verificacao"]:checked').value);

function rotuloConfirmar() {
    $('modal-confirm').textContent = tipoEscolhido() === TIPO_EVIDENCIA ? 'Enviar para aprovação' : 'Registrar e receber';
}

function atualizarModal() {
    $('modal-photo-group').classList.toggle('hidden', tipoEscolhido() !== TIPO_EVIDENCIA);
    rotuloConfirmar();
    $('modal-error').textContent = '';
}

function abrirModal(atividadeId) {
    atividadeSelecionada = atividadePorId(atividadeId);
    focoAnterior = document.activeElement;
    $('modal-activity-name').textContent = atividadeSelecionada.nome;
    const cat = CATEGORIA_POR_ID[atividadeSelecionada.categoria];
    $('modal-reward-1').innerHTML = fichas(atividadeSelecionada.recompensas[TIPO_AUTO], cat);
    $('modal-reward-2').innerHTML = fichas(atividadeSelecionada.recompensas[TIPO_EVIDENCIA], cat);
    // Atividade sem como ter foto (RN92): sem a escolha, só o registro rápido com o valor cheio
    const semFoto = !atividadeSelecionada.aceitaFoto;
    document.querySelector('.verify-options').hidden = semFoto;
    $('modal-sem-foto').hidden = !semFoto;
    $('modal-reward-0').innerHTML = fichas(atividadeSelecionada.recompensas[TIPO_AUTO], cat);
    document.querySelector(`input[name="tipo-verificacao"][value="${TIPO_AUTO}"]`).checked = true;
    $('modal-evidence').value = '';
    $('modal-photo').value = '';
    atualizarModal();
    $('modal-registro').classList.remove('hidden');
    tocar('abrir');
    (semFoto ? $('modal-evidence') : document.querySelector('input[name="tipo-verificacao"]:checked')).focus();
}

function fecharModal() {
    if (enviando) return;
    $('modal-registro').classList.add('hidden');
    tocar('fechar');
    atividadeSelecionada = null;
    focoAnterior?.focus?.();
}

/** Mesmas regras do servidor, para avisar antes do envio. */
function validarFoto(arquivo) {
    if (!arquivo) return 'Escolha uma foto para enviar como evidência.';
    if (!FOTO_TIPOS.includes(arquivo.type)) return 'A foto deve ser JPG, PNG ou WebP.';
    if (arquivo.size > state.regras.fotoMaxBytes) return 'A foto deve ter no máximo 5 MB.';
    return '';
}

async function confirmarRegistro() {
    if (!atividadeSelecionada || enviando) return;
    const tipo = tipoEscolhido();
    const foto = tipo === TIPO_EVIDENCIA ? $('modal-photo').files[0] : null;

    if (tipo === TIPO_EVIDENCIA) {
        const erro = validarFoto(foto);
        if (erro) { $('modal-error').textContent = erro; return; }
    }

    enviando = true;
    $('modal-confirm').disabled = true;
    $('modal-confirm').textContent = 'Enviando...';
    try {
        pagina = 1;
        await registrarAtividade(atividadeSelecionada.id, tipo, $('modal-evidence').value, foto);
        tocar('registro');
        enviando = false;
        fecharModal();
    } catch (e) {
        $('modal-error').textContent = e.message;
    } finally {
        enviando = false;
        $('modal-confirm').disabled = false;
        rotuloConfirmar();
    }
}

/* ---------------------------------------------------------------------------
   Meus registros (linha do tempo com filtros)
   --------------------------------------------------------------------------- */

function situacaoTexto(r) {
    if (r.status === 'pendente') {
        if (analisando.has(r.id)) return `<span class="registro-ia is-analisando">${icon('help')} Analisando a foto com IA…</span>`;
        if (r.ia) return `<span class="registro-ia">${icon('help')} A IA não teve certeza pela foto: ${escapeHTML(r.ia.motivo)}</span>
            Um administrador vai conferir; aprovação automática em ${dataHora(r.prazo)}, se não for revisado antes.`;
        return `Aprovação automática em até ${state.regras.prazoAprovacaoHoras} horas, se não for revisado antes.`;
    }
    if (r.status === 'aprovado' && r.aprovadoEm) {
        if (r.contestacao?.status === 'aceita') return `Contestação aceita: aprovado em ${dataHora(r.aprovadoEm)}.`;
        const como = r.tipo === TIPO_PARCEIRO ? `Verificada por ${escapeHTML(r.parceiro || 'parceiro')}`
            : r.tipo === TIPO_AUTO ? 'Aprovado na hora'
            : r.ia?.acao === 'aprovou' && r.aprovacaoAuto ? 'Foto confirmada pela IA'
            : (r.aprovacaoAuto ? 'Aprovado automaticamente' : 'Aprovado por administrador');
        return `${como} em ${dataHora(r.aprovadoEm)}.`;
    }
    return '';
}

/** Contestação (RN94): o botão enquanto dá para contestar, ou a situação dela. */
function contestacaoTexto(r) {
    const c = r.contestacao;
    if (c?.status === 'aberta') return `<p class="registro-contest is-aberta">${icon('scroll')} Contestação enviada em ${dataHora(c.data)}: um administrador vai conferir.</p>`;
    if (c?.status === 'negada') return `<p class="registro-contest is-negada">${icon('close')} Contestação negada: ${escapeHTML(c.resposta || '')}</p>`;
    if (r.contestarAte) {
        return `<div class="registro-actions"><button type="button" class="btn btn-outline btn-sm" data-contestar="${r.id}">${icon('scroll')} Contestar</button>
            <span class="registro-note">Discorda? Dá para contestar até ${new Date(r.contestarAte).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' })}.</span></div>`;
    }
    return '';
}

/* Janela de contestação */
function abrirContestacao(registroId) {
    contestando = state.registros.find((x) => x.id === registroId);
    if (!contestando) return;
    focoAnterior = document.activeElement;
    $('contestar-atividade').textContent = atividadePorId(contestando.atividadeId)?.nome ?? 'Atividade';
    $('contestar-motivo').textContent = contestando.motivoRejeicao ? `Motivo da rejeição: ${contestando.motivoRejeicao}` : '';
    $('contestar-form').reset();
    $('contestar-contador').textContent = '0';
    $('contestar-erro').textContent = '';
    $('modal-contestar').classList.remove('hidden');
    $('contestar-texto').focus();
}

function fecharContestacao() {
    if (enviando) return;
    $('modal-contestar').classList.add('hidden');
    contestando = null;
    focoAnterior?.focus?.();
}

async function enviarContestacao(e) {
    e.preventDefault();
    if (!contestando || enviando) return;
    const texto = $('contestar-texto').value;
    const foto = $('contestar-foto').files[0] || null;
    if (!texto.trim()) { $('contestar-erro').textContent = 'Explique por que a sua ação deve ser aprovada.'; return; }
    if (foto) {
        const erro = validarFoto(foto);
        if (erro) { $('contestar-erro').textContent = erro; return; }
    }
    enviando = true;
    $('contestar-enviar').disabled = true;
    try {
        await contestarRegistro(contestando.id, texto, foto);
        enviando = false;
        fecharContestacao();
    } catch (err) {
        $('contestar-erro').textContent = err.message;
    } finally {
        enviando = false;
        $('contestar-enviar').disabled = false;
    }
}

/** O que o registro rendeu: valores dos históricos (o que foi pago de fato). */
function ganhoTexto(r) {
    if (r.status === 'pendente') return `<span class="registro-gain is-wait">${icon('calendar')} Aguardando aprovação</span>`;
    if (r.status === 'rejeitado') return '<span class="registro-gain is-none">Sem ganho</span>';
    return `<span class="registro-gain">${fichaXp(r.ganhoXp ?? 0)}${fichaCredito(r.ganhoCredito ?? 0)}</span>`;
}

function rotuloDia(iso) {
    const d = new Date(iso);
    const hoje = new Date();
    const ontem = new Date(); ontem.setDate(hoje.getDate() - 1);
    if (d.toDateString() === hoje.toDateString()) return 'Hoje';
    if (d.toDateString() === ontem.toDateString()) return 'Ontem';
    return d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: '2-digit' });
}

/** Situação para os filtros: rejeitado com contestação aberta conta como pendente. */
const situacaoFiltro = (r) => (r.status === 'rejeitado' && r.contestacao?.status === 'aberta' ? 'pendente' : r.status);

function renderFiltros() {
    const conta = { todos: state.registros.length };
    state.registros.forEach((r) => { const s = situacaoFiltro(r); conta[s] = (conta[s] ?? 0) + 1; });
    $('registros-filtros').innerHTML = FILTROS.map(([k, nome]) =>
        `<button type="button" class="chip chip-${k} ${filtro === k ? 'is-active' : ''}" data-filtro="${k}" aria-pressed="${filtro === k}">${nome} <span class="chip-count">${conta[k] ?? 0}</span></button>`).join('');
    const pend = conta.pendente ?? 0;
    const selo = $('mt-registros-badge');
    selo.textContent = pend;
    selo.classList.toggle('hidden', pend === 0);
    selo.setAttribute('aria-label', `${pend} registros aguardando aprovação`);
}

/** Aviso quando uma contestação aberta é decidida com o jogo aberto (chega pela sincronização). */
let contestacoesVistas = null;
function avisarContestacoes() {
    const atual = new Map(state.registros.filter((r) => r.contestacao).map((r) => [r.id, r.contestacao.status]));
    if (contestacoesVistas) {
        atual.forEach((situacao, id) => {
            if (contestacoesVistas.get(id) !== 'aberta' || situacao === 'aberta') return;
            const r = state.registros.find((x) => x.id === id);
            const nome = atividadePorId(r.atividadeId)?.nome ?? 'sua ação';
            tocar(situacao === 'aceita' ? 'ia-aprovou' : 'ia-rejeitou');
            if (situacao === 'aceita') toast(`Contestação aceita: ${nome} foi aprovada! +${r.ganhoXp} XP e +${r.ganhoCredito} créditos.`);
            else toast(`Contestação negada: ${nome}. Veja a resposta em Meus registros.`, 'error');
        });
    }
    contestacoesVistas = atual;
}

export function renderRegistros() {
    avisarContestacoes();
    renderCards();   // vaga do dia de cada atividade depende dos registros
    renderFiltros();
    const lista = $('registros-list');
    const paginador = $('registros-pager');
    const tam = porPagina();
    $('registros-tamanho').innerHTML = state.registros.length ? seletorTamanho(tam, TAMANHOS_REGISTROS) : '';
    if (!state.registros.length) {
        lista.innerHTML = `<li class="friends-empty">${icon('scroll')}<strong>Você ainda não registrou nenhuma ação</strong>
            <span>Cada ação real que você registrar aparece aqui, com o que você ganhou.</span>
            <button type="button" class="btn btn-primary btn-sm" data-ir="agir">Registrar minha primeira ação</button></li>`;
        paginador.hidden = true;
        paginador.innerHTML = '';
        return;
    }
    const itens = state.registros.filter((r) => filtro === 'todos' || situacaoFiltro(r) === filtro);
    if (!itens.length) {
        lista.innerHTML = '<li class="empty-state">Nenhum registro com esta situação.</li>';
        paginador.hidden = true;
        paginador.innerHTML = '';
        return;
    }
    const total = itens.length;
    pagina = Math.min(Math.max(1, pagina), Math.max(1, Math.ceil(total / tam)));
    const recorte = itens.slice((pagina - 1) * tam, pagina * tam);
    const teste = isDemo() && state.aprovacaoTeste;
    let diaAnterior = '';
    lista.innerHTML = recorte.map((r) => {
        const a = atividadePorId(r.atividadeId);
        const cat = a ? CATEGORIA_POR_ID[a.categoria] : null;
        const emContestacao = r.status === 'rejeitado' && r.contestacao?.status === 'aberta';
        const s = emContestacao ? { rotulo: 'Em contestação', classe: 'tag-warning' } : (STATUS[r.status] || STATUS.pendente);
        const dia = new Date(r.data).toDateString();
        const separador = dia !== diaAnterior ? `<li class="registro-day">${rotuloDia(r.data)}</li>` : '';
        diaAnterior = dia;
        const acoes = teste && r.status === 'pendente'
            ? `<div class="registro-actions">
                   <button type="button" class="btn btn-primary btn-sm" data-teste="aprovar" data-registro="${r.id}">${icon('check')} Aprovar (teste)</button>
                   <button type="button" class="btn btn-danger-outline btn-sm" data-teste="rejeitar" data-registro="${r.id}">${icon('close')} Rejeitar (teste)</button>
               </div>`
            : '';
        return `${separador}<li class="registro-item is-${r.status}">
            <span class="registro-dot cat-icon cat-${a?.categoria ?? ''}">${cat ? icon(cat.icone) : ''}</span>
            <div class="registro-card">
                <div class="registro-main">
                    <div>
                        <strong>${escapeHTML(a?.nome ?? 'Atividade')}</strong>
                        <span class="registro-date">${new Date(r.data).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })} · <span class="registro-type">${r.tipo === TIPO_EVIDENCIA ? icon('image') : r.tipo === TIPO_PARCEIRO ? icon('flag') : ''}${TIPO_ROTULO[r.tipo] ?? ''}${r.tipo === TIPO_PARCEIRO && r.quantidade !== null ? ` · ${numBR(r.quantidade)} ${escapeHTML(r.unidade || '')}` : ''}</span></span>
                    </div>
                    <span class="tag ${s.classe}">${s.rotulo}</span>
                </div>
                ${ganhoTexto(r)}
                ${r.descricao ? `<p class="registro-desc">${escapeHTML(r.descricao)}</p>` : ''}
                ${r.temFoto ? `<a class="registro-photo" href="api/evidencia.php?registro=${r.id}" target="_blank" rel="noopener">${icon('image')} Ver foto enviada</a>` : ''}
                ${situacaoTexto(r) ? `<p class="registro-note">${situacaoTexto(r)}</p>` : ''}
                ${r.motivoRejeicao ? `<p class="registro-reason">${r.ia?.acao === 'rejeitou' ? 'Rejeitado pela IA' : 'Motivo'}: ${escapeHTML(r.motivoRejeicao)}</p>` : ''}
                ${contestacaoTexto(r)}
                ${acoes}
            </div>
        </li>`;
    }).join('');
    pager(paginador, pagina, total, tam, total === 1 ? 'registro' : 'registros');
}

/* ---------------------------------------------------------------------------
   Parceiros (RN69–RN71): código digitado e lista de onde validar
   --------------------------------------------------------------------------- */

function renderParceiros() {
    const lista = $('partner-places');
    lista.innerHTML = state.parceiros.length ? state.parceiros.map((p) => `<li class="card partner-place">
        <strong>${escapeHTML(p.nome)}</strong>
        <span class="partner-place-addr">${icon('pin')} ${escapeHTML(p.endereco)}</span>
        <p>${escapeHTML(p.descricao)}</p>
        <span class="reward-chips">${p.atividades.map((id) => atividadePorId(id)).filter(Boolean).map((a) => {
            const cat = CATEGORIA_POR_ID[a.categoria];
            return `<span class="reward-chip chip-area cat-${a.categoria}">${icon(cat.icone)}${escapeHTML(a.nome)}</span>`;
        }).join('')}</span>
    </li>`).join('') : `<li class="friends-empty">${icon('flag')}<strong>Ainda não há parceiros</strong><span>Quando estabelecimentos da sua região se cadastrarem e forem aprovados, eles aparecem aqui.</span></li>`;
}

async function enviarCodigo(e) {
    e.preventDefault();
    const campo = $('partner-code');
    const codigo = campo.value.replace(/\D/g, '');
    if (codigo.length !== 6) { toast('Digite os 6 números do código do parceiro.', 'error'); campo.focus(); return; }
    const botao = e.target.querySelector('[type=submit]');
    botao.disabled = true;
    try {
        pagina = 1;
        await validarCodigoParceiro(codigo);
        campo.value = '';
        ativarSubaba($('mt-registros'));
    } catch (err) { toast(err.message, 'error'); } finally { botao.disabled = false; }
}

export function abrirAgirComFiltro(categoria = 'todas') {
    if (categoria === 'todas' || categoria === 'parceiro' || CATEGORIA_POR_ID[categoria]) {
        filtroAgir = categoria;
    } else {
        filtroAgir = 'todas';
    }
    renderCards();
    if (ativarSubaba && $('mt-agir')) ativarSubaba($('mt-agir'), false);
}

export function initMissions() {
    ativarSubaba = initTabs($('missions-tabs'));
    renderParceiros();
    $('partner-code-form').addEventListener('submit', enviarCodigo);

    $('agir-filtros')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-agir-filtro]');
        if (!b) return;
        filtroAgir = b.dataset.agirFiltro;
        renderCards();
    });

    $('registros-filtros').addEventListener('click', (e) => {
        const b = e.target.closest('[data-filtro]');
        if (!b) return;
        filtro = b.dataset.filtro;
        pagina = 1;
        renderRegistros();
    });

    $('registros-tamanho').addEventListener('change', (e) => {
        if (!e.target.matches('[data-lista-tamanho]')) return;
        guardarTamanho(CHAVE_TAMANHO, Number(e.target.value));
        pagina = 1;
        renderRegistros();
    });

    $('registros-pager').addEventListener('click', (e) => {
        const b = e.target.closest('[data-pagina]');
        if (!b || b.disabled) return;
        pagina = Number(b.dataset.pagina);
        renderRegistros();
        $('registros-filtros').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    $('missions-grid').addEventListener('click', (e) => {
        if (e.target.closest('[data-ir-parceiros]')) { ativarSubaba($('mt-parceiros')); return; }
        const btn = e.target.closest('[data-atividade]');
        if (btn) abrirModal(Number(btn.dataset.atividade));
    });

    $('registros-list').addEventListener('click', async (e) => {
        if (e.target.closest('[data-ir="agir"]')) { ativarSubaba($('mt-agir')); return; }
        const contestar = e.target.closest('[data-contestar]');
        if (contestar) { abrirContestacao(Number(contestar.dataset.contestar)); return; }
        const btn = e.target.closest('[data-teste]');
        if (!btn) return;
        btn.disabled = true;
        try {
            await decidirRegistroTeste(Number(btn.dataset.registro), btn.dataset.teste);
        } catch (err) {
            toast(err.message, 'error');
            btn.disabled = false;
        }
    });

    document.querySelectorAll('input[name="tipo-verificacao"]').forEach((r) => r.addEventListener('change', atualizarModal));
    $('modal-cancel').addEventListener('click', fecharModal);
    $('modal-confirm').addEventListener('click', confirmarRegistro);
    $('modal-registro').addEventListener('click', (e) => { if (e.target.id === 'modal-registro') fecharModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('modal-registro').classList.contains('hidden')) fecharModal();
        if (e.key === 'Escape' && !$('modal-contestar').classList.contains('hidden')) fecharContestacao();
    });

    // Contestação (RN94)
    $('contestar-form').addEventListener('submit', enviarContestacao);
    $('contestar-cancelar').addEventListener('click', fecharContestacao);
    $('modal-contestar').addEventListener('click', (e) => { if (e.target.id === 'modal-contestar') fecharContestacao(); });
    $('contestar-texto').addEventListener('input', (e) => { $('contestar-contador').textContent = e.target.value.length; });
}
