/**
 * Cabeçalho do jogador, barra de XP e painel de progresso ecológico.
 */
import { CATEGORIAS } from '../data/catalog.js';
import { state, progressoGeral, faixaNivel, nivelCidade } from '../state.js';
import { icon } from './icons.js';
import { $, escapeHTML } from './dom.js';
import { tocar } from '../som.js';
import { abrirAgirComFiltro } from './missions.js';

/* ---------------------------------------------------------------------------
   Animações do topo: contagem dos números, "+N" flutuante, subida de nível e créditos.
   A primeira renderização não anima; com "reduzir movimento", os valores trocam direto.
   --------------------------------------------------------------------------- */
const anterior = { xp: null, nivel: null, creditos: null };
const semMovimento = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const fmt = (n) => Math.round(n).toLocaleString('pt-BR');

/** Conta de `de` até `para` no elemento (≈0,8 s). */
function contar(el, de, para) {
    if (semMovimento() || de === para) { el.textContent = fmt(para); return; }
    const inicio = performance.now(), dur = 800;
    const passo = (agora) => {
        const t = Math.min(1, (agora - inicio) / dur);
        el.textContent = fmt(de + (para - de) * (1 - (1 - t) ** 3));
        if (t < 1) requestAnimationFrame(passo);
    };
    requestAnimationFrame(passo);
}

/** Reinicia uma classe de animação no elemento. */
function animar(el, classe, ms) {
    el.classList.remove(classe);
    void el.offsetWidth;   // força o navegador a recomeçar a animação
    el.classList.add(classe);
    setTimeout(() => el.classList.remove(classe), ms);
}

/** Texto flutuante ("+25 XP", "+15", "−20") que sobe e some. */
function flutuar(pai, texto, classe, esquerda = null) {
    if (semMovimento()) return;
    const el = document.createElement('span');
    el.className = classe;
    el.textContent = texto;
    if (esquerda !== null) el.style.left = esquerda;
    pai.appendChild(el);
    setTimeout(() => el.remove(), 1500);
}

/** Estrelinhas saindo do escudo do nível. */
function estrelas(escudo) {
    if (semMovimento()) return;
    const r = escudo.getBoundingClientRect(), base = escudo.parentElement.getBoundingClientRect();
    for (let i = 0; i < 8; i += 1) {
        const a = (i / 8) * Math.PI * 2;
        const el = document.createElement('span');
        el.className = 'xp-estrela';
        el.style.left = `${r.left - base.left + r.width / 2 - 4}px`;
        el.style.top = `${r.top - base.top + r.height / 2 - 4}px`;
        el.style.setProperty('--dx', `${Math.cos(a) * 38}px`);
        el.style.setProperty('--dy', `${Math.sin(a) * 38}px`);
        escudo.parentElement.appendChild(el);
        setTimeout(() => el.remove(), 1000);
    }
}

/** Barra, números e escudos do XP (com as animações quando o valor muda). */
function renderXp(j) {
    const { min, proximo } = faixaNivel();   // XP é total (nivel_jogador.xp_minimo/xp_maximo)
    const pct = Math.max(0, Math.min(100, ((j.xpAtual - min) / Math.max(1, proximo - min)) * 100));
    const track = $('xp-track'), fill = $('xp-fill');
    const primeira = anterior.xp === null;
    const ganhou = !primeira && j.xpAtual > anterior.xp;
    const subiu = !primeira && j.nivel > anterior.nivel;

    $('xp-level').textContent = j.nivel;
    $('xp-level-next').textContent = j.nivel + 1;
    $('avatar-level').textContent = j.nivel;
    $('xp-next').textContent = fmt(proximo);
    $('xp-falta').textContent = `faltam ${fmt(Math.max(0, proximo - j.xpAtual))} XP para o nível ${j.nivel + 1}`;
    if (primeira) $('xp-current').textContent = fmt(j.xpAtual); else contar($('xp-current'), anterior.xp, j.xpAtual);
    track.setAttribute('aria-valuemin', String(min));
    track.setAttribute('aria-valuenow', String(j.xpAtual));
    track.setAttribute('aria-valuemax', String(proximo));
    track.classList.toggle('is-quase', pct >= 90);
    $('player-avatar').style.setProperty('--pct', pct.toFixed(1));

    if (subiu && !semMovimento()) {
        // Completa a barra, pisca e recomeça do zero até o valor novo
        fill.style.width = '100%';
        animar(track, 'is-nivel', 1000);
        animar($('xp-shield'), 'is-subiu', 900);
        estrelas($('xp-shield'));
        setTimeout(() => {
            fill.style.transition = 'none';
            fill.style.width = '0%';
            void fill.offsetWidth;
            fill.style.transition = '';
            fill.style.width = `${pct}%`;
        }, 750);
    } else {
        fill.style.width = `${pct}%`;
        if (ganhou) animar(track, 'is-ganho', 900);
    }
    if (ganhou && !subiu) tocar('xp');
    if (ganhou) flutuar($('xp-pops'), `+${fmt(j.xpAtual - anterior.xp)} XP`, 'xp-pop', `${subiu ? 50 : Math.max(8, Math.min(92, pct))}%`);
    anterior.xp = j.xpAtual;
    anterior.nivel = j.nivel;
}

/** Saldo de créditos: conta, a moeda pula e o "+N"/"−N" sai da pílula. */
function renderCreditos(valor) {
    if (anterior.creditos === null || anterior.creditos === valor) {
        $('green-credits').textContent = valor;
    } else {
        const dif = valor - anterior.creditos;
        tocar(dif > 0 ? 'credito' : 'gasto');
        contar($('green-credits'), anterior.creditos, valor);
        if (!semMovimento()) animar($('currency-pill'), 'is-mudou', 700);
        flutuar($('currency-pill'), `${dif > 0 ? '+' : '−'}${fmt(Math.abs(dif))}`, `credito-pop ${dif > 0 ? 'is-mais' : 'is-menos'}`);
    }
    anterior.creditos = valor;
}

/** Monta a grade dos 5 indicadores (uma vez). */
export function initHud() {
    $('eco-categories').innerHTML = CATEGORIAS.map((c) => `
        <div class="eco-category">
            <div class="eco-category-label">
                <span class="eco-category-name"><span class="cat-icon cat-${c.id}">${icon(c.icone)}</span>${c.nome}</span>
                <span class="eco-category-value" id="eco-val-${c.id}">0/100</span>
            </div>
            <div class="bar-track bar-sm" role="progressbar" aria-label="${c.nome}" aria-valuemin="0" aria-valuemax="100" id="eco-track-${c.id}">
                <div class="bar-fill fill-${c.id}" id="eco-fill-${c.id}"></div>
            </div>
        </div>`).join('');
    initHoje();
}

function initHoje() {
    const grid = $('today-grid');
    if (!grid) return;
    grid.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-hoje-acao]');
        if (!btn) return;
        const acao = btn.dataset.hojeAcao;
        if (acao === 'agir') {
            $('t-missoes')?.click();
            abrirAgirComFiltro('todas');
            $('tab-missoes')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        } else if (acao === 'area') {
            $('t-missoes')?.click();
            abrirAgirComFiltro(btn.dataset.categoria || 'todas');
            $('agir-filtros')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        } else if (acao === 'quiz') {
            $('t-quizzes')?.click();
            ($('daily-quiz') && !$('daily-quiz').classList.contains('hidden') ? $('daily-quiz') : $('tab-quizzes'))
                ?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        } else if (acao === 'missoes') {
            $('t-missoes')?.click();
            $('mt-missoes')?.click();
            $('mp-missoes')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        } else if (acao === 'coletar') {
            $('city-collect')?.click();
        } else if (acao === 'ecoguia') {
            $('t-ecoguia')?.click();
        }
    });
}

function renderHoje(s) {
    const grid = $('today-grid');
    if (!grid) return;
    const sub = $('today-sub');
    if (sub) {
        const data = new Date().toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' });
        sub.textContent = `${data} · toque para ir direto`;
    }

    const hojeStr = new Date().toDateString();
    const feitasHoje = new Set((s.registros || []).filter((r) => r.status !== 'rejeitado' && new Date(r.data).toDateString() === hojeStr).map((r) => r.atividadeId)).size;
    const totalAtiv = (s.atividades || []).length;
    const cardAcoes = {
        acao: 'agir', icone: feitasHoje >= totalAtiv && totalAtiv > 0 ? 'check' : 'leaf',
        classe: feitasHoje >= totalAtiv && totalAtiv > 0 ? 'is-done' : '',
        rotulo: 'Ações de hoje', valor: `${feitasHoje}/${totalAtiv}`,
        dica: feitasHoje === 0 ? 'Registre sua 1ª ação' : (feitasHoje < totalAtiv ? 'Mais ações disponíveis' : 'Tudo feito hoje ✓'),
    };

    const quizDia = (s.quizzes || []).find((q) => q.id === s.rotacaoQuiz?.dia);
    const cardQuiz = quizDia ? {
        acao: 'quiz', icone: quizDia.concluido ? 'check' : 'help',
        classe: quizDia.concluido ? 'is-done' : 'is-gold',
        rotulo: 'Quiz do Dia',
        valor: quizDia.concluido ? 'Concluído ✓' : `+${quizDia.recompensaXp} XP · +${quizDia.recompensaCredito}`,
        dica: quizDia.concluido ? 'Modo treino disponível' : quizDia.titulo,
    } : {
        acao: 'quiz', icone: 'help', classe: '',
        rotulo: 'Quizzes', valor: 'Ver quizzes', dica: 'Rotação da semana',
    };

    const atuais = s.missoes?.atuais || [];
    const concluidas = atuais.filter((m) => m.concluida).length;
    const pendentes = atuais.filter((m) => !m.concluida);
    const especial = pendentes.find((m) => m.tipo === 'Especial');
    const maisPerto = pendentes
        .map((m) => ({ m, falta: (m.metas || []).reduce((acc, x) => acc + Math.max(0, x.meta - Math.min(x.feito, x.meta)), 0) }))
        .sort((a, b) => a.falta - b.falta)[0];
    let dicaMissoes = 'Nenhuma missão ativa';
    if (atuais.length && !pendentes.length) dicaMissoes = 'Semana completa ✓';
    else if (especial) dicaMissoes = `Especial: ${especial.nome}`;
    else if (maisPerto) dicaMissoes = `Falta ${maisPerto.falta} ${maisPerto.falta === 1 ? 'ação' : 'ações'}: ${maisPerto.m.nome}`;
    const cardMissoes = {
        acao: 'missoes', icone: 'trophy',
        classe: atuais.length && !pendentes.length ? 'is-done' : '',
        rotulo: 'Missões', valor: `${concluidas}/${atuais.length} concluídas`,
        dica: dicaMissoes,
    };

    const renda = Number(s.construcao?.efeitos?.renda || 0);
    let cardExtra;
    if (renda > 0) {
        cardExtra = {
            acao: 'coletar', icone: 'coin', classe: 'is-gold',
            rotulo: 'Renda pronta', valor: `Coletar +${fmt(renda)}`,
            dica: 'Créditos do comércio prontos',
        };
    } else {
        const ind = s.jogador?.indicadores || {};
        const menor = [...CATEGORIAS].sort((a, b) => (ind[a.id] ?? 0) - (ind[b.id] ?? 0))[0];
        const minVal = menor ? (ind[menor.id] ?? 0) : 100;
        if (menor && minVal < 100) {
            cardExtra = {
                acao: 'area', cat: menor.id, icone: menor.icone, classe: '',
                rotulo: `Atenção: ${menor.curto}`, valor: `${minVal}/100`,
                dica: 'Fortaleça esta área hoje',
            };
        } else {
            cardExtra = {
                acao: 'ecoguia', icone: 'chat', classe: '',
                rotulo: 'EcoGuia', valor: 'Assistente IA',
                dica: 'Tire dúvidas de sustentabilidade',
            };
        }
    }

    grid.innerHTML = [cardAcoes, cardQuiz, cardMissoes, cardExtra].map((c) => `
        <button type="button" class="today-item ${c.classe}" data-hoje-acao="${escapeHTML(c.acao)}"${c.cat ? ` data-categoria="${escapeHTML(c.cat)}"` : ''}>
            <span class="today-icon${c.cat ? ` cat-${escapeHTML(c.cat)}` : ''}">${icon(c.icone)}</span>
            <span class="today-body">
                <span class="today-label">${escapeHTML(c.rotulo)}</span>
                <strong class="today-value">${escapeHTML(c.valor)}</strong>
                <span class="today-hint">${escapeHTML(c.dica)}</span>
            </span>
        </button>`).join('');
}

export function renderHud(state) {
    const j = state.jogador;
    const geral = progressoGeral();

    $('player-name').textContent = j.apelido;
    $('player-city').textContent = j.cidade;
    document.title = `${j.cidade} — EcoCity`;
    $('player-level').textContent = `Nível ${j.nivel}`;
    renderCreditos(j.creditosVerde);
    renderXp(j);

    // O que o próximo nível da cidade libera: lote de expansão e estruturas do modo Construir
    const proximoNivel = nivelCidade() + 1;
    // Mostra o lote e até 4 estruturas; o resto vira "e mais N" (o catálogo tem dezenas no nível 2)
    const MAX_NOMES = 4;
    const estruturas = state.construcao.catalogo.filter((c) => c.nivelMinimo === proximoNivel).map((c) => c.nome);
    const libera = [
        ...(proximoNivel <= 4 ? [`Lote ${proximoNivel}`] : []),
        ...estruturas.slice(0, MAX_NOMES),
    ].map(escapeHTML);
    if (estruturas.length > MAX_NOMES) libera.push(`e mais ${estruturas.length - MAX_NOMES} estruturas`);
    $('next-unlock').innerHTML = libera.length
        ? `${icon('lock')}<span>Cidade nível ${proximoNivel} libera: <strong>${libera.join(', ')}</strong></span>`
        : `${icon('check')}<span>Todos os lotes e construções estão liberados</span>`;

    $('eco-overall').textContent = geral;
    $('eco-main-fill').style.width = `${geral}%`;
    $('eco-main-track').setAttribute('aria-valuenow', String(geral));
    CATEGORIAS.forEach((c) => {
        const v = j.indicadores[c.id];
        $(`eco-val-${c.id}`).textContent = `${v}/100`;
        $(`eco-fill-${c.id}`).style.width = `${v}%`;
        $(`eco-track-${c.id}`).setAttribute('aria-valuenow', String(v));
    });
    renderHoje(state);
}
