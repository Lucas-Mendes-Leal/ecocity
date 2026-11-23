/**
 * Tutorial de boas-vindas (RN90): passa pelas abas do jogo destacando cada parte, com um balão
 * curto (Passo N de M, Voltar, Próximo, Pular). Aparece uma vez por conta (concluir ou pular
 * grava no servidor); "Ver tutorial" em Minha conta (avatar do topo) mostra de novo sem gravar nada.
 * Só apresentação: não muda regras nem dá recompensa.
 */
import { state, chamarApi } from '../state.js';
import { icon } from './icons.js';
import { $, escapeHTML } from './dom.js';

/** aba: id do botão da aba · subaba: id do botão da subaba · alvo: seletor do destaque (sem alvo = janela no centro). */
const PASSOS = [
    {
        aba: 't-cidade', icone: 'leaf', titulo: (j) => `Bem-vindo, ${escapeHTML(j.apelido)}!`,
        texto: 'Sua cidade começa poluída. Ela melhora com o que você aprende e faz de verdade: <strong>Aprenda → Pratique → Registre → Evolua → Transforme</strong>.',
    },
    {
        aba: 't-cidade', alvo: '.xp-section', titulo: 'Seu progresso',
        texto: 'O <strong>XP</strong> sobe o seu nível. Os <strong>créditos verdes</strong> (no topo) servem para construir na cidade.',
    },
    {
        aba: 't-cidade', alvo: '#eco-categories', titulo: 'A saúde da cidade',
        texto: 'Cada área (Reciclagem, Água, Energia, Mobilidade e Natureza) vai de 0 a 100 e perde 1 ponto por dia. Suas ações fazem subir.',
    },
    {
        aba: 't-cidade', alvo: '.city-frame', titulo: 'Sua cidade',
        texto: 'Toque nos pontos para ver cada região. Com a cidade mais limpa, ela sobe de nível e libera lotes para <strong>Construir</strong>.',
    },
    {
        aba: 't-missoes', subaba: 'mt-agir', alvo: '#mt-agir', titulo: 'Pratique e registre',
        texto: 'Fez algo sustentável hoje? Registre aqui. <strong>Sem foto</strong> ganha na hora (metade); <strong>com foto</strong> vale o dobro quando a IA confirma (se ela errar, dá para contestar). Um registro por atividade por dia.',
    },
    {
        aba: 't-missoes', subaba: 'mt-missoes', alvo: '#mt-missoes', titulo: 'Missões da semana',
        texto: 'Metas da semana com recompensa extra. Se não cumprir, a área da missão perde pontos.',
    },
    {
        aba: 't-quizzes', alvo: '#t-quizzes', titulo: 'Aprenda',
        texto: 'Quiz do Dia, Quiz da Semana e temas. A recompensa vem na <strong>1ª tentativa</strong> com 70% de acertos ou mais.',
    },
    {
        aba: 't-conquistas', alvo: '#t-conquistas', titulo: 'Conquistas',
        texto: 'Medalhas pelo que você faz, cada uma com XP e créditos.',
    },
    {
        aba: 't-perfil', alvo: '#t-perfil', titulo: 'Meu Progresso',
        texto: 'Seu impacto real, sua evolução, o que aprendeu e o extrato de XP e créditos. Os dados da conta e a senha ficam em <strong>Minha conta</strong>, no seu avatar no topo.',
    },
    {
        aba: 't-amigos', alvo: '#t-amigos', alvo2: '#t-comunidade', titulo: 'Junto é melhor',
        texto: 'Adicione amigos pelo código e entre numa comunidade para cumprir missões em grupo.',
    },
    {
        aba: 't-ecoguia', alvo: '#t-ecoguia', titulo: 'EcoGuia',
        texto: 'Ficou com dúvida sobre sustentabilidade, tendências ambientais ou onde descartar perto de você? Converse com o <strong>EcoGuia</strong>, nosso assistente com IA (até 15 perguntas por dia).',
    },
    {
        aba: 't-cidade', icone: 'check', titulo: 'Tudo pronto!', final: true,
        texto: 'Comece agora pela sua primeira ação. Você pode rever este tutorial em <strong>Minha conta</strong> (seu avatar no topo).',
    },
];

const t = { ativo: false, passo: 0, rever: false, foco: null, el: null };
const MOBILE = 768;

function montar() {
    const el = document.createElement('div');
    el.className = 'tour';
    el.innerHTML = `<div class="tour-overlay"></div><div class="tour-spot" aria-hidden="true"></div>
        <div class="tour-card" role="dialog" aria-modal="true" aria-labelledby="tour-titulo" aria-describedby="tour-texto" tabindex="-1">
            <div class="tour-top"><span class="tour-step" id="tour-step"></span><button type="button" class="tour-skip" data-tour="pular">Pular tutorial</button></div>
            <div class="tour-progress"><span id="tour-bar"></span></div>
            <h3 class="tour-title" id="tour-titulo"></h3>
            <p class="tour-text" id="tour-texto"></p>
            <div class="tour-actions" id="tour-acoes"></div>
        </div>`;
    el.addEventListener('click', (e) => {
        const b = e.target.closest('[data-tour]');
        if (!b) return;
        const acao = b.dataset.tour;
        if (acao === 'proximo') ir(t.passo + 1);
        if (acao === 'voltar') ir(t.passo - 1);
        if (acao === 'pular' || acao === 'fechar') encerrar('t-cidade');
        if (acao === 'agir') encerrar('t-missoes', 'mt-agir');
    });
    el.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); encerrar('t-cidade'); }
        if (e.key === 'ArrowRight' && t.passo < PASSOS.length - 1) { e.preventDefault(); ir(t.passo + 1); }
        if (e.key === 'ArrowLeft' && t.passo > 0) { e.preventDefault(); ir(t.passo - 1); }
        if (e.key === 'Tab') {   // foco preso no balão
            const f = [...el.querySelectorAll('.tour-card button')];
            const i = f.indexOf(document.activeElement);
            e.preventDefault();
            f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length]?.focus();
        }
    });
    document.body.appendChild(el);
    return el;
}

/** Abre a aba e a subaba do passo (os botões já trocam o conteúdo). */
function abrirAba(p) {
    const aba = $(p.aba);
    if (aba && aba.getAttribute('aria-selected') !== 'true') aba.click();
    if (p.subaba) {
        const sub = $(p.subaba);
        if (sub && sub.getAttribute('aria-selected') !== 'true') sub.click();
    }
}

/** Retângulo do destaque (une dois alvos, se houver), na tela. */
function areaDoAlvo(p) {
    const alvos = [p.alvo, p.alvo2].filter(Boolean).map((s) => document.querySelector(s)).filter((x) => x && x.getClientRects().length);
    if (!alvos.length) return null;
    const rs = alvos.map((a) => a.getBoundingClientRect());
    const r = { top: Math.min(...rs.map((x) => x.top)), left: Math.min(...rs.map((x) => x.left)), right: Math.max(...rs.map((x) => x.right)), bottom: Math.max(...rs.map((x) => x.bottom)) };
    const m = 6;
    return { top: r.top - m, left: r.left - m, width: r.right - r.left + m * 2, height: r.bottom - r.top + m * 2 };
}

/** Posiciona o recorte e o balão (abaixo ou acima do destaque; no celular, fixo embaixo). */
function posicionar() {
    if (!t.ativo) return;
    const p = PASSOS[t.passo];
    const spot = t.el.querySelector('.tour-spot');
    const card = t.el.querySelector('.tour-card');
    const a = p.alvo ? areaDoAlvo(p) : null;
    t.el.classList.toggle('is-centro', !a);
    if (!a) { spot.style.cssText = ''; card.style.cssText = ''; return; }
    // Destaque maior que a tela (mapa no celular): limita à área visível
    const vh = window.innerHeight;
    const top = Math.max(4, a.top), bottom = Math.min(vh - 4, a.top + a.height);
    Object.assign(spot.style, { top: `${top}px`, left: `${Math.max(4, a.left)}px`, width: `${Math.min(window.innerWidth - 8, a.width)}px`, height: `${Math.max(0, bottom - top)}px` });
    if (window.innerWidth < MOBILE) { card.style.cssText = ''; return; }
    const ch = card.offsetHeight, cw = card.offsetWidth, gap = 14;
    // Destaque alto (o mapa): o balão fica no canto de baixo, por cima dele
    if (bottom - top > vh * 0.55) {
        Object.assign(card.style, { top: `${vh - ch - 24}px`, left: `${window.innerWidth - cw - 32}px` });
        return;
    }
    const cabeEmbaixo = bottom + gap + ch < vh;
    const y = cabeEmbaixo ? bottom + gap : Math.max(8, top - gap - ch);
    const x = Math.min(window.innerWidth - cw - 12, Math.max(12, a.left + a.width / 2 - cw / 2));
    Object.assign(card.style, { top: `${Math.max(8, Math.min(y, vh - ch - 8))}px`, left: `${x}px` });
}

function ir(n) {
    t.passo = Math.max(0, Math.min(PASSOS.length - 1, n));
    const p = PASSOS[t.passo];
    abrirAba(p);
    const total = PASSOS.length;
    $('tour-step').innerHTML = p.icone ? `${icon(p.icone)} Passo ${t.passo + 1} de ${total}` : `Passo ${t.passo + 1} de ${total}`;
    $('tour-bar').style.width = `${((t.passo + 1) / total) * 100}%`;
    $('tour-titulo').innerHTML = typeof p.titulo === 'function' ? p.titulo(state.jogador) : p.titulo;
    $('tour-texto').innerHTML = p.texto;
    t.el.querySelector('.tour-skip').hidden = !!p.final;
    $('tour-acoes').innerHTML = p.final
        ? `<button type="button" class="btn btn-outline btn-sm" data-tour="fechar">Fechar</button>
           <button type="button" class="btn btn-primary btn-sm" data-tour="agir">${icon('leaf')}Registrar minha primeira ação</button>`
        : `<button type="button" class="btn btn-outline btn-sm" data-tour="voltar" ${t.passo === 0 ? 'disabled' : ''}>‹ Voltar</button>
           <button type="button" class="btn btn-primary btn-sm" data-tour="proximo">${t.passo === 0 ? 'Começar' : 'Próximo ›'}</button>`;
    // Rola até o destaque e posiciona depois que a aba aparece
    const alvo = p.alvo && document.querySelector(p.alvo);
    if (alvo) alvo.scrollIntoView({ block: window.innerWidth < MOBILE ? 'start' : 'center', inline: 'center', behavior: 'instant' });
    else window.scrollTo({ top: 0, behavior: 'instant' });
    requestAnimationFrame(() => { posicionar(); t.el.querySelector('[data-tour="proximo"], [data-tour="agir"]')?.focus({ preventScroll: true }); });
}

function encerrar(aba, subaba) {
    if (!t.ativo) return;
    t.ativo = false;
    t.el.remove();
    t.el = null;
    document.querySelectorAll('[data-tour-inert]').forEach((x) => { x.removeAttribute('inert'); x.removeAttribute('data-tour-inert'); });
    window.removeEventListener('resize', posicionar);
    window.removeEventListener('scroll', posicionar, true);
    abrirAba({ aba, subaba });
    window.scrollTo({ top: 0, behavior: 'instant' });
    if (!t.rever && !state.jogador.tutorialVisto) {
        state.jogador.tutorialVisto = true;
        chamarApi('api/perfil.php', { acao: 'tutorial' }).catch(() => { state.jogador.tutorialVisto = false; });
    }
    (subaba ? $(subaba) : t.foco)?.focus?.();
}

/** Abre o tutorial. `rever`: aberto pelo botão do Perfil (não grava nada). */
export function iniciarTutorial({ rever = false } = {}) {
    if (t.ativo) return;
    t.ativo = true;
    t.rever = rever;
    t.foco = document.activeElement;
    t.el = montar();
    // O resto da página fica inerte enquanto o tutorial está aberto
    [...document.body.children].filter((x) => x !== t.el && x.tagName !== 'SCRIPT').forEach((x) => {
        if (!x.hasAttribute('inert')) { x.setAttribute('inert', ''); x.setAttribute('data-tour-inert', ''); }
    });
    window.addEventListener('resize', posicionar);
    window.addEventListener('scroll', posicionar, true);
    ir(0);
}
