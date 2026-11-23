/**
 * Quizzes: Quiz do Dia, Quiz da Semana, temas da semana, "Já feitos", execução,
 * resultado e histórico. A rotação vem do servidor (state.rotacaoQuiz, RN53).
 * Tudo validado no servidor (api/quiz.php): a alternativa correta só é
 * revelada depois que o jogador responde.
 */
import { CATEGORIA_POR_ID } from '../data/catalog.js';
import { state, quizPorId, iniciarQuiz, responderQuiz, finalizarQuiz, chamarApi } from '../state.js';
import { icon } from './icons.js';
import { $, escapeHTML, toast, pager } from './dom.js';
import { tocar } from '../som.js';

const ativo = { quiz: null, tentativaId: null, perguntas: [], indice: 0, ocupado: false };

function mostrarView(id) {
    ['quiz-list-view', 'quiz-player-view', 'quiz-result-view'].forEach((v) => $(v).classList.toggle('hidden', v !== id));
}

function metaHTML(q) {
    return `<span>${icon('help')} <strong>${q.totalPerguntas}</strong> ${q.totalPerguntas === 1 ? 'pergunta' : 'perguntas'}</span>
        ${q.dificuldade ? `<span>${icon('chart')} ${escapeHTML(q.dificuldade)}</span>` : ''}
        ${q.concluido
            ? `<span>${icon('scroll')} Modo treino (sem recompensa)</span>`
            : `<span>${icon('gift')} +${q.recompensaXp} XP | +${q.recompensaCredito} ${icon('coin')}</span>
               <span>${icon('star')} Mín. ${state.regras.quizNotaMinima}% na 1ª tentativa</span>`}`;
}

function badgeCategoria(q) {
    const cat = CATEGORIA_POR_ID[q.categoria];
    return cat ? `<span class="cat-badge cat-${q.categoria}">${icon(cat.icone)} ${cat.nome}</span>` : '';
}

/* ---------------------------------------------------------------------------
   Lista
   --------------------------------------------------------------------------- */

/** Card de destaque (Quiz do Dia e Quiz da Semana). */
function renderDestaque(alvo, q, { selo, prazo, nome }) {
    $(alvo).classList.toggle('hidden', !q);
    if (!q) return;
    $(alvo).innerHTML = `
        <span class="daily-quiz-badge">${icon('star')} ${selo}</span>
        <div class="daily-quiz-content">
            <div>
                ${badgeCategoria(q)}
                <h3>${escapeHTML(q.titulo)}</h3>
                <p>${escapeHTML(q.descricao)}</p>
                <div class="quiz-meta">${metaHTML(q)}<span>${icon('calendar')} ${prazo}</span></div>
            </div>
            <button type="button" class="btn btn-primary" data-quiz="${q.id}">${q.concluido ? `Refazer ${nome}` : `Iniciar ${nome}`}</button>
        </div>`;
}

function cardQuiz(q) {
    return `<article class="quiz-card">
        <div class="quiz-card-header">
            ${badgeCategoria(q)}
            <span class="tag ${q.concluido ? 'tag-success' : 'tag-warning'}">${q.concluido ? 'Concluído' : 'Disponível'}</span>
        </div>
        <h4>${escapeHTML(q.titulo)}</h4>
        <p>${escapeHTML(q.descricao)}</p>
        <div class="quiz-meta">${metaHTML(q)}</div>
        <button type="button" class="btn btn-secondary btn-block" data-quiz="${q.id}">${q.concluido ? 'Refazer Quiz' : 'Iniciar Quiz'}</button>
    </article>`;
}

export function renderQuizList() {
    const r = state.rotacaoQuiz;
    const fim = new Date(`${r.fimSemana}T00:00:00`).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' });
    renderDestaque('daily-quiz', quizPorId(r.dia), { selo: 'Quiz do Dia', prazo: 'Novo quiz amanhã', nome: 'Quiz do Dia' });
    renderDestaque('weekly-quiz', quizPorId(r.semana), { selo: 'Quiz da Semana · recompensa ×3', prazo: `Até ${fim}`, nome: 'Quiz da Semana' });

    const temas = r.temas.map(quizPorId).filter(Boolean);
    $('quizzes-grid').innerHTML = temas.length
        ? temas.map(cardQuiz).join('')
        : '<p class="empty-state">Nenhum quiz temático disponível nesta semana.</p>';

    renderFeitos();
    // Tentativas novas do servidor (ex.: quiz finalizado) trazem a 1ª página: volta ao início do histórico
    if (base !== state.tentativas) { base = state.tentativas; historico = null; }
    renderHistorico();
}

/* ---------------------------------------------------------------------------
   Paginação ("Já feitos" no navegador; histórico de tentativas no servidor)
   --------------------------------------------------------------------------- */

const FEITOS_POR_PAGINA = 12;   // 4 linhas de 3 cards (6×2, 12×1 no celular)
let paginaFeitos = 1;
let historico = null;           // página do histórico pedida ao servidor; null = a 1ª, que vem no estado
let base = null;                // state.tentativas da última vez (para notar quando muda)

function renderFeitos() {
    const r = state.rotacaoQuiz;
    const naRotacao = new Set([r.dia, r.semana, ...r.temas]);
    const feitos = state.quizzes.filter((q) => q.concluido && !naRotacao.has(q.id));
    const paginas = Math.max(1, Math.ceil(feitos.length / FEITOS_POR_PAGINA));
    paginaFeitos = Math.min(paginaFeitos, paginas);
    $('quiz-done').classList.toggle('hidden', !feitos.length);
    $('quiz-done-count').textContent = `(${feitos.length})`;
    $('quizzes-done-grid').innerHTML = feitos.slice((paginaFeitos - 1) * FEITOS_POR_PAGINA, paginaFeitos * FEITOS_POR_PAGINA).map(cardQuiz).join('');
    pager($('quiz-done-pager'), paginaFeitos, feitos.length, FEITOS_POR_PAGINA, 'quizzes');
}

async function irParaHistorico(pagina, botao) {
    botao.disabled = true;
    try {
        historico = (await chamarApi('api/quiz.php', { acao: 'historico', pagina })).tentativas;
        renderHistorico();
        $('quiz-history-body').closest('.quiz-history').scrollIntoView({ block: 'start', behavior: 'smooth' });
    } catch (err) {
        toast(err.message, 'error');
        botao.disabled = false;
    }
}

function renderHistorico() {
    const tbody = $('quiz-history-body');
    const h = historico ?? state.tentativas;
    pager($('quiz-history-pager'), h.pagina, h.total, h.porPagina, 'tentativas');
    if (!h.itens.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Nenhuma tentativa ainda. Que tal começar pelo Quiz do Dia?</td></tr>';
        return;
    }
    tbody.innerHTML = h.itens.map((t) => {
        const data = new Date(`${t.data}T00:00:00`).toLocaleDateString('pt-BR');
        return `<tr>
            <td><strong>${escapeHTML(t.titulo)}</strong></td>
            <td>${data}</td>
            <td>${t.acertos}/${t.total}</td>
            <td><strong>${t.pontuacao} pts</strong></td>
            <td><span class="tag tag-success">Concluído</span></td>
        </tr>`;
    }).join('');
}

/* ---------------------------------------------------------------------------
   Execução
   --------------------------------------------------------------------------- */

async function iniciar(quizId, botao) {
    if (ativo.ocupado) return;
    ativo.ocupado = true;
    botao.disabled = true;
    try {
        const { tentativaId, perguntas } = await iniciarQuiz(quizId);
        Object.assign(ativo, { quiz: quizPorId(quizId), tentativaId, perguntas, indice: 0 });
        mostrarView('quiz-player-view');
        renderPergunta();
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        ativo.ocupado = false;
        botao.disabled = false;
    }
}

function renderPergunta() {
    const { quiz, perguntas, indice } = ativo;
    const pergunta = perguntas[indice];

    $('quiz-player-title').textContent = quiz.titulo;
    $('quiz-current').textContent = indice + 1;
    $('quiz-total').textContent = perguntas.length;
    $('quiz-progress-fill').style.width = `${((indice + 1) / perguntas.length) * 100}%`;
    $('quiz-question').textContent = pergunta.enunciado;
    $('quiz-options').innerHTML = pergunta.alternativas.map((alt) =>
        `<button type="button" class="quiz-option" data-alt="${alt.id}">${escapeHTML(alt.texto)}</button>`).join('');
    $('quiz-feedback').className = 'quiz-feedback hidden';
    $('quiz-next').classList.add('hidden');
    $('quiz-question').focus();
}

async function responder(altId) {
    if (ativo.ocupado) return;
    ativo.ocupado = true;
    const botoes = $('quiz-options').querySelectorAll('.quiz-option');
    botoes.forEach((b) => { b.disabled = true; });

    try {
        const pergunta = ativo.perguntas[ativo.indice];
        const r = await responderQuiz(ativo.tentativaId, pergunta.id, altId);

        botoes.forEach((btn) => {
            const id = Number(btn.dataset.alt);
            if (id === r.alternativaCorreta) btn.classList.add('is-correct');
            else if (id === altId) btn.classList.add('is-wrong');
        });
        tocar(r.correta ? 'acerto' : 'erro');
        $('quiz-feedback').className = `quiz-feedback ${r.correta ? 'is-correct' : 'is-wrong'}`;
        $('quiz-feedback-title').innerHTML = r.correta ? `${icon('check')} Resposta correta!` : `${icon('close')} Resposta incorreta`;
        $('quiz-feedback-text').textContent = r.explicacao || '';
        const fonte = $('quiz-feedback-fonte');
        fonte.hidden = !r.fonte;
        if (r.fonte) fonte.innerHTML = `Fonte: <a href="${escapeHTML(r.fonte.url)}" target="_blank" rel="noopener noreferrer">${escapeHTML(r.fonte.titulo)}</a>`;

        const ultima = ativo.indice === ativo.perguntas.length - 1;
        $('quiz-next').textContent = ultima ? 'Ver resultado' : 'Próxima pergunta';
        $('quiz-next').classList.remove('hidden');
        $('quiz-next').focus();
    } catch (e) {
        toast(e.message, 'error');
        botoes.forEach((b) => { b.disabled = false; });
    } finally {
        ativo.ocupado = false;
    }
}

async function avancar() {
    if (ativo.ocupado) return;
    ativo.indice += 1;
    if (ativo.indice < ativo.perguntas.length) {
        renderPergunta();
        return;
    }

    ativo.ocupado = true;
    $('quiz-next').disabled = true;
    try {
        const r = await finalizarQuiz(ativo.tentativaId);
        tocar(r.recompensado ? 'quiz-fim' : 'quiz-treino');
        $('result-icon').innerHTML = icon(r.acertos === r.total ? 'trophy' : 'star');
        $('result-score').textContent = r.pontuacao;
        $('result-hits').textContent = `${r.acertos}/${r.total}`;
        $('result-rewards').innerHTML = r.recompensado
            ? `+${r.recompensa.xp} XP | +${r.recompensa.credito} ${icon('coin')}`
            : (r.primeira ? 'Não recebida' : 'Treino');
        $('result-message').textContent = r.acertos === r.total
            ? 'Incrível! Você demonstrou domínio total sobre as práticas sustentáveis deste tema.'
            : 'Bom trabalho! Continue praticando para aprender mais sobre sustentabilidade.';
        $('result-note').textContent = r.primeira
            ? `Você precisava de ${r.notaMinima}% de acertos na primeira tentativa para receber a recompensa. Pode refazer para treinar.`
            : 'A recompensa só vale na primeira tentativa. Esta foi uma tentativa de treino.';
        $('result-note').classList.toggle('hidden', r.recompensado);
        mostrarView('quiz-result-view');
        $('result-title').focus();
    } catch (e) {
        ativo.indice -= 1;
        toast(e.message, 'error');
    } finally {
        ativo.ocupado = false;
        $('quiz-next').disabled = false;
    }
}

export function initQuiz() {
    $('tab-quizzes').addEventListener('click', (e) => {
        const iniciarBtn = e.target.closest('[data-quiz]');
        const alt = e.target.closest('[data-alt]');
        if (iniciarBtn) iniciar(Number(iniciarBtn.dataset.quiz), iniciarBtn);
        if (alt) responder(Number(alt.dataset.alt));
        const pag = e.target.closest('[data-pagina]');
        if (pag && pag.closest('#quiz-done-pager')) {
            paginaFeitos = Number(pag.dataset.pagina);
            renderFeitos();
            $('quiz-done').scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
        if (pag && pag.closest('#quiz-history-pager')) irParaHistorico(Number(pag.dataset.pagina), pag);
    });
    $('quiz-next').addEventListener('click', avancar);
    $('quiz-back').addEventListener('click', () => mostrarView('quiz-list-view'));
    $('result-finish').addEventListener('click', () => mostrarView('quiz-list-view'));
}
