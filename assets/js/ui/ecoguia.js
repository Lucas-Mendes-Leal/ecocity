/**
 * Aba EcoGuia (RN97): chatbot educacional de sustentabilidade com IA local.
 * Protegido contra sobrecarga (15/dia, cooldown de 15 s) e com guardrails estritos.
 */
import { chamarApi } from '../state.js';
import { $, escapeHTML, toast } from './dom.js';
import { icon } from './icons.js';
import { tocar } from '../som.js';

const SUGESTOES = [
    'Como separar lixo reciclável corretamente em casa?',
    'Quais tendências ambientais estão em destaque no mundo?',
    'Quais pontos de coleta parceiros o EcoCity tem?',
    'Dicas práticas para economizar água e energia no dia a dia',
];

const st = {
    carregado: false,
    carregando: false,
    enviando: false,
    mensagens: [],
    cota: {
        bancoPronto: true, disponivel: false, limiteDia: 15,
        usadasHoje: 0, restantesHoje: 15, cooldownSeg: 15, esperaSeg: 0, maxChars: 400,
    },
    esperaRestante: 0,
    timer: null,
};

function raiz() { return $('ecoguia-app'); }

function horaBR(iso) {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function balao(m) {
    const eu = m.papel === 'jogador';
    const hora = horaBR(m.data);
    const rotulo = eu
        ? ''
        : `<span class="ecoguia-autor">${icon('leaf')} EcoGuia${m.bloqueada ? ' · Escopo' : ''}</span>`;
    const cls = ['chat-msg', eu ? 'is-mine' : 'is-bot', !eu && m.bloqueada ? 'is-blocked' : ''].filter(Boolean).join(' ');
    return `<li class="${cls}">
        ${rotulo}
        <p>${escapeHTML(m.texto)}</p>
        ${hora ? `<span class="chat-meta">${hora}</span>` : ''}
    </li>`;
}

function rolarFim() {
    const lista = $('ecoguia-list');
    if (lista) lista.scrollTop = lista.scrollHeight;
}

function iniciarContagemEspera(seg) {
    clearInterval(st.timer);
    st.timer = null;
    st.esperaRestante = Math.max(0, Number(seg) || 0);
    atualizarEstadoEnvio();
    if (st.esperaRestante <= 0) return;

    st.timer = setInterval(() => {
        st.esperaRestante = Math.max(0, st.esperaRestante - 1);
        atualizarEstadoEnvio();
        if (st.esperaRestante <= 0) {
            clearInterval(st.timer);
            st.timer = null;
        }
    }, 1000);
}

function textoDica() {
    const max = st.cota.maxChars || 400;
    const input = $('ecoguia-text');
    const n = input ? input.value.length : 0;
    const base = `${n}/${max} · Enter envia, Shift+Enter quebra a linha`;
    if (st.enviando) return 'EcoGuia está analisando sua pergunta…';
    if (st.esperaRestante > 0) return `Aguarde ${st.esperaRestante} s para enviar outra pergunta · ${n}/${max}`;
    return base;
}

function atualizarEstadoEnvio() {
    const form = $('ecoguia-form');
    if (!form) return;
    const semCota = st.cota.restantesHoje <= 0;
    const bloqueadoInput = !st.cota.bancoPronto || semCota;
    const bloqueadoBtn = bloqueadoInput || !st.cota.disponivel || st.enviando || st.esperaRestante > 0;

    const input = $('ecoguia-text');
    const btn = $('ecoguia-send');
    const hint = $('ecoguia-count');
    if (input) input.disabled = bloqueadoInput || st.enviando;
    if (btn) btn.disabled = bloqueadoBtn;
    if (hint) hint.textContent = textoDica();
    form.querySelectorAll('.ecoguia-chip').forEach((b) => { b.disabled = bloqueadoBtn; });
}

function avisoEstado() {
    if (!st.cota.bancoPronto) {
        return '<div class="alert alert-warning">O EcoGuia ainda não foi habilitado no banco de dados deste ambiente.</div>';
    }
    if (!st.cota.disponivel) {
        return '<div class="alert alert-warning">O EcoGuia está offline no momento. Assim que o serviço de IA ligar, você poderá conversar novamente.</div>';
    }
    if (st.cota.restantesHoje <= 0) {
        return `<div class="alert alert-warning">Você atingiu o limite diário de ${st.cota.limiteDia} perguntas. Novas perguntas serão liberadas amanhã!</div>`;
    }
    return '';
}

function render() {
    const el = raiz();
    if (!el) return;

    const c = st.cota;
    const temMsgs = st.mensagens.length > 0;
    const listaHtml = temMsgs
        ? st.mensagens.map(balao).join('')
        : `<li class="ecoguia-welcome">
            <div class="ecoguia-welcome-icon">${icon('chat')}</div>
            <strong>Tire suas dúvidas com o EcoGuia</strong>
            <p>Pergunte sobre reciclagem, economia de água e energia, tendências ambientais globais ou pontos de coleta verificados no EcoCity.</p>
            <div class="ecoguia-sugestoes" role="group" aria-label="Perguntas sugeridas">
                ${SUGESTOES.map((s) => `<button type="button" class="ecoguia-chip" data-ecoguia-sug="${escapeHTML(s)}">${escapeHTML(s)}</button>`).join('')}
            </div>
        </li>`;

    const thinkingHtml = st.enviando
        ? `<li class="chat-msg is-bot is-thinking" id="ecoguia-thinking">
            <span class="ecoguia-autor">${icon('leaf')} EcoGuia</span>
            <p>Pensando em uma resposta segura e útil…</p>
        </li>`
        : '';

    const aviso = avisoEstado();
    const max = c.maxChars || 400;

    el.innerHTML = `
        <section class="hero-band ecoguia-hero" aria-label="EcoGuia">
            <div class="friends-me">
                <div class="friend-avatar friend-avatar-lg av-3" aria-hidden="true">${icon('leaf')}</div>
                <div class="friends-me-info">
                    <strong>EcoGuia</strong>
                    <span class="ecoguia-subtitle">Assistente de sustentabilidade, tendências ambientais e pontos de coleta.</span>
                </div>
                <dl class="hero-stats" aria-label="Cota diária e status">
                    <div><dt>Restam hoje</dt><dd id="ecoguia-restam">${c.restantesHoje}/${c.limiteDia}</dd></div>
                    <div><dt>IA</dt><dd class="ecoguia-status-txt">${c.disponivel ? 'Online' : 'Offline'}</dd></div>
                </dl>
            </div>
            <div class="ecoguia-hero-foot">
                <span class="friends-privacy">${icon('lock')} Focado em ecologia e sustentabilidade · ${c.cooldownSeg} s entre envios</span>
                ${temMsgs ? `<button type="button" class="friends-copy" id="ecoguia-limpar">${icon('close')} Limpar conversa</button>` : ''}
            </div>
        </section>

        <section class="card ecoguia-card">
            ${aviso}
            <ol class="chat-list ecoguia-list" id="ecoguia-list" aria-live="polite">
                ${listaHtml}
                ${thinkingHtml}
            </ol>
            <form class="chat-form" id="ecoguia-form" novalidate>
                <div class="chat-input">
                    <label class="sr-only" for="ecoguia-text">Pergunte algo sobre sustentabilidade</label>
                    <textarea id="ecoguia-text" class="form-input" rows="2" maxlength="${max}" placeholder="Pergunte sobre reciclagem, tendências ambientais ou pontos de coleta…"></textarea>
                    <small id="ecoguia-count">0/${max} · Enter envia, Shift+Enter quebra a linha</small>
                </div>
                <button type="submit" class="chat-send-btn" id="ecoguia-send" aria-label="Enviar pergunta">${icon('send')}</button>
            </form>
        </section>`;

    atualizarEstadoEnvio();
    rolarFim();
}

async function carregar(forcar = false) {
    if (st.carregando || (st.carregado && !forcar)) return;
    st.carregando = true;
    try {
        const r = await chamarApi('api/chat-ia.php', { acao: 'carregar' });
        st.mensagens = r.mensagens || [];
        st.cota = r.cota || st.cota;
        st.carregado = true;
        render();
        iniciarContagemEspera(st.cota.esperaSeg || 0);
    } catch (err) {
        toast(err.message, 'error');
    } finally {
        st.carregando = false;
    }
}

async function enviar(textoBruto) {
    if (st.enviando) return;
    const texto = String(textoBruto || '').trim();
    if (!texto) {
        toast('Escreva sua pergunta antes de enviar.', 'error');
        $('ecoguia-text')?.focus();
        return;
    }
    if (st.esperaRestante > 0) {
        toast(`Aguarde ${st.esperaRestante} s antes de enviar outra pergunta.`, 'info');
        return;
    }
    if (st.cota.restantesHoje <= 0) {
        toast(`Você já usou todas as ${st.cota.limiteDia} perguntas de hoje.`, 'info');
        return;
    }

    st.enviando = true;
    render();

    try {
        const r = await chamarApi('api/chat-ia.php', { acao: 'enviar', texto });
        st.mensagens = [...st.mensagens, ...(r.mensagens || [])];
        if (r.cota) st.cota = r.cota;
        st.enviando = false;
        render();
        iniciarContagemEspera(st.cota.esperaSeg || st.cota.cooldownSeg || 15);
        tocar('mensagem');
        $('ecoguia-text')?.focus({ preventScroll: true });
    } catch (err) {
        st.enviando = false;
        if (err.dados?.cota) st.cota = err.dados.cota;
        render();
        const input = $('ecoguia-text');
        if (input && err.status !== 200) input.value = texto;
        if (err.dados?.esperaSeg) iniciarContagemEspera(err.dados.esperaSeg);
        atualizarEstadoEnvio();
        toast(err.message, 'error');
    }
}

async function limparConversa() {
    try {
        const r = await chamarApi('api/chat-ia.php', { acao: 'limpar' });
        st.mensagens = r.mensagens || [];
        st.cota = r.cota || st.cota;
        render();
        iniciarContagemEspera(st.cota.esperaSeg || 0);
        toast('Conversa limpa na tela. Sua cota diária foi mantida.', 'info');
    } catch (err) {
        toast(err.message, 'error');
    }
}

export function initEcoGuia() {
    const painel = $('tab-ecoguia');
    const aba = $('t-ecoguia');
    if (!painel || !aba) return;

    painel.addEventListener('submit', (e) => {
        if (e.target.id !== 'ecoguia-form') return;
        e.preventDefault();
        enviar($('ecoguia-text')?.value || '');
    });

    painel.addEventListener('input', (e) => {
        if (e.target.id === 'ecoguia-text') {
            const hint = $('ecoguia-count');
            if (hint) hint.textContent = textoDica();
        }
    });

    painel.addEventListener('keydown', (e) => {
        if (e.target.id === 'ecoguia-text' && e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $('ecoguia-form')?.requestSubmit();
        }
    });

    painel.addEventListener('click', (e) => {
        if (e.target.closest('#ecoguia-limpar')) {
            limparConversa();
            return;
        }
        const sug = e.target.closest('[data-ecoguia-sug]');
        if (sug && !st.enviando) {
            enviar(sug.dataset.ecoguiaSug);
        }
    });

    new MutationObserver(() => {
        if (aba.getAttribute('aria-selected') === 'true') {
            carregar(false);
        }
    }).observe(aba, { attributes: true, attributeFilter: ['aria-selected'] });
}
