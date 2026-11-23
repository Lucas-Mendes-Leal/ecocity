/**
 * Visão Geral da administração (RN81–RN83): subabas Resumo, Aprenda → Pratique, Aprendizado,
 * Ações, Engajamento e Cidade, com filtro de período e CSV anônimo por seção.
 * Dados de api/estatisticas.php (só números somados; recortes com menos de 5 jogadores vêm sem taxa).
 */
import { CATEGORIA_POR_ID, CATEGORIAS } from '../data/catalog.js';
import { escapeHTML } from './dom.js';
import { barras, colunas, linhas, mapaCalor, empilhada, funil, numero, semanaBR, POUCOS, COR_CATEGORIA } from './graficos.js';

const cache = new Map();

/** Dados mudaram (sincronização da administração): a próxima visita a cada seção busca de novo. */
export function limparCacheEstatisticas() { cache.clear(); }
const nomeCat = (k) => CATEGORIA_POR_ID[k]?.curto || k;
const dot = (k) => `<span class="cat-dot cat-${escapeHTML(k)}"></span>`;
const pct = (v) => (v === null || v === undefined ? POUCOS : `${numero(v)}%`);
const card = (titulo, icone, corpo, dica = '') => `<div class="card"><h3 class="card-title"><span data-icon="${icone}"></span> ${titulo}</h3>${corpo}${dica ? `<p class="form-hint">${dica}</p>` : ''}</div>`;
const kpi = (rotulo, valor, sub = '') => `<div class="card kpi"><span class="kpi-label">${rotulo}</span><strong class="kpi-value">${valor}</strong>${sub ? `<span class="kpi-sub">${sub}</span>` : ''}</div>`;
const tabela = (cab, linhasHtml, vazio = 'Sem dados no período.') => linhasHtml.length
    ? `<div class="table-scroll"><table class="data-table"><thead><tr>${cab.map((c) => `<th scope="col">${c}</th>`).join('')}</tr></thead><tbody>${linhasHtml.join('')}</tbody></table></div>`
    : `<p class="muted">${vazio}</p>`;
const AGUARDA = '<span class="muted" title="Essa semana ainda não chegou">aguardando</span>';
const ORIGEM = { atividade: 'Ações aprovadas', 'missao nao concluida': 'Missões não concluídas', 'queda diaria': 'Queda diária', poluicao: 'Poluição das estruturas', 'codigo promocional': 'Códigos promocionais', 'ajuste admin': 'Ajustes da administração' };

const SECOES = {
    resumo(d) {
        return `<div class="kpi-grid">
            ${kpi('Ativos hoje', numero(d.ativosHoje, 0))}
            ${kpi('Ativos nos últimos 7 dias', numero(d.ativosSemana, 0))}
            ${kpi('Ativos no período', numero(d.ativos, 0), 'abriram o jogo ou usaram uma função')}
            ${kpi('Acerto na 1ª tentativa', d.acertoPrimeira === null ? '—' : `${numero(d.acertoPrimeira)}%`, d.acertoPrimeira === null ? 'menos de 5 jogadores com quiz' : `${d.jogadoresQuiz} jogadores`)}
        </div>
        <p class="form-hint est-total-aviso">Abaixo, os totais desde o início do EcoCity.</p>`;
    },

    pratica(d) {
        const comDados = d.temas.filter((t) => !t.poucos);
        const grafico = comDados.length
            ? barras(comDados.flatMap((t) => [
                { rotulo: `${dot(t.categoria)}${escapeHTML(nomeCat(t.categoria))} · depois do quiz`, valor: t.depois, texto: pct(t.depois), cor: COR_CATEGORIA[t.categoria] },
                { rotulo: '<span class="est-sub-rotulo">sem quiz do tema</span>', valor: t.semQuizDepois, texto: pct(t.semQuizDepois), cor: 'var(--track-strong, #9E9E9E)' },
            ]), { max: 100 })
            : '<p class="muted">Ainda não há 5 jogadores com quiz concluído em nenhum tema neste período.</p>';
        return card('Aprenda → Pratique', 'leaf', `
            <p class="est-explica">Dos jogadores que concluíram um quiz de um tema, quantos registraram uma ação do mesmo tema
            nos ${d.janela} dias seguintes, comparado com os ${d.janela} dias anteriores ao quiz e com quem não fez quiz do tema
            (contado a partir do primeiro acesso no período). O dia do quiz não conta, e só entram quizzes com as duas janelas completas.</p>${grafico}`)
            + card('Por tema', 'clipboard', tabela(['Tema', 'Fizeram quiz', `Ação nos ${d.janela} dias antes`, `Ação nos ${d.janela} dias depois`, 'Sem quiz', `Sem quiz: ação em ${d.janela} dias`],
                d.temas.map((t) => `<tr><td>${dot(t.categoria)}${escapeHTML(t.nome)}</td><td>${t.comQuiz}</td><td>${pct(t.antes)}</td><td><strong>${pct(t.depois)}</strong></td><td>${t.semQuiz}</td><td>${pct(t.semQuizDepois)}</td></tr>`)),
            'É uma <strong>associação, não prova de causa</strong>: quem faz quizzes pode já ser mais engajado. A comparação "antes × depois" no mesmo grupo ajuda a separar os dois efeitos.');
    },

    aprendizado(d) {
        const temas = barras(d.temas.map((t) => ({
            rotulo: `${dot(t.categoria)}${escapeHTML(t.nome)}`, valor: t.acerto, cor: COR_CATEGORIA[t.categoria],
            texto: t.acerto === null ? POUCOS : `${numero(t.acerto)}% · ${t.jogadores} jog.`,
        })), { max: 100 });
        const rot = d.semanas.map((s) => semanaBR(s.semana));
        const series = [{ nome: 'Geral', cor: 'var(--text-color)', valores: d.semanas.map((s) => s.geral.acerto) },
            ...CATEGORIAS.map((c) => ({ nome: c.curto, cor: COR_CATEGORIA[c.id], valores: d.semanas.map((s) => s.temas[c.id] ?? null) }))];
        const perguntas = tabela(['Pergunta', 'Quiz', 'Erro', 'Resposta certa', 'Errada mais escolhida'], d.perguntas.map((p) => `<tr>
            <td class="wrap">${dot(p.categoria)}${escapeHTML(p.enunciado)}</td><td class="wrap">${escapeHTML(p.quiz)}</td>
            <td><strong>${numero(p.erro)}%</strong><br><span class="kpi-sub">${p.respostas} resp.</span></td>
            <td class="wrap">${escapeHTML(p.correta)}</td>
            <td class="wrap">${p.maisEscolhida ? `${escapeHTML(p.maisEscolhida.texto)} <span class="kpi-sub">(${numero(p.maisEscolhida.pct, 0)}% dos erros)</span>` : '—'}</td></tr>`),
            'Nenhuma pergunta respondida por 5 jogadores ou mais neste período.');
        const quizzes = tabela(['Quiz', 'Tipo', 'Respostas', 'Jogadores', 'Acerto 1ª tentativa'], d.quizzes.map((q) =>
            `<tr><td>${dot(q.categoria)}${escapeHTML(q.titulo)}</td><td>${escapeHTML(q.tipo)}</td><td>${q.respostas}</td><td>${q.jogadores}</td><td>${pct(q.acerto)}</td></tr>`));
        return `<div class="kpi-grid">
                ${kpi('Corrigidas ao refazer', d.treino.correcao === null ? '—' : `${numero(d.treino.correcao)}%`, `${d.treino.refeitas} de ${d.treino.erradas} perguntas erradas foram refeitas`)}
                ${kpi('Tentativas abandonadas', d.abandono.pct === null ? '—' : `${numero(d.abandono.pct)}%`, `${d.abandono.abandonadas} de ${d.abandono.iniciadas} iniciadas (até ontem)`)}
            </div>`
            + card('Acerto na 1ª tentativa por tema', 'help', temas, 'Só a primeira tentativa de cada jogador em cada quiz conta (as seguintes são treino).')
            + card('Evolução semanal do acerto', 'chart', linhas(rot, series, { titulo: 'Acerto na 1ª tentativa por semana' }))
            + card('As 10 perguntas mais erradas', 'scroll', perguntas, 'A alternativa errada mais escolhida mostra onde está a dúvida mais comum. Só perguntas respondidas por 5 jogadores ou mais.')
            + card('Acerto por quiz', 'clipboard', quizzes);
    },

    acoes(d) {
        const rot = d.semanas.map((s) => semanaBR(s.semana));
        const unidades = [...new Set(d.semanas.flatMap((s) => Object.keys(s.impacto)))];
        const impacto = tabela(['Semana', ...unidades.map(escapeHTML)], d.semanas.map((s) => `<tr><td>${semanaBR(s.semana)}</td>${unidades.map((u) => `<td>${numero(s.impacto[u] ?? 0)}</td>`).join('')}</tr>`));
        const ev = d.evidencias;
        const mistura = [{ rotulo: 'Sem foto', cor: '#90A4AE' }, { rotulo: 'Com foto', cor: 'var(--primary-color)' }, { rotulo: 'Com parceiro', cor: 'var(--accent-gold)' }];
        const atividades = tabela(['Atividade', 'Aprovadas', 'Mistura', 'Jogadores', 'Pendentes', 'Rejeitadas', 'Impacto real'], d.atividades.map((a) => `<tr>
            <td>${dot(a.categoria)}${escapeHTML(a.nome)}</td><td><strong>${a.aprovados}</strong></td>
            <td>${empilhada([{ ...mistura[0], valor: a.semFoto }, { ...mistura[1], valor: a.comFoto }, { ...mistura[2], valor: a.parceiro }])}</td>
            <td>${a.jogadores}</td><td>${a.pendentes}</td><td>${a.rejeitados}</td><td>${a.unidade ? `${numero(a.impacto)} ${escapeHTML(a.unidade)}` : '—'}</td></tr>`));
        const legenda = `<ul class="g-legenda">${mistura.map((m) => `<li><span style="background:${m.cor}"></span>${m.rotulo}</li>`).join('')}</ul>`;
        const motivos = d.motivos.length
            ? `<ul class="est-motivos">${d.motivos.map((m) => `<li><strong>${escapeHTML(m.atividade)}</strong> · ${escapeHTML(m.motivo || '')}</li>`).join('')}</ul>`
            : '<p class="muted">Nenhuma rejeição no período.</p>';
        return card('Ações aprovadas por semana', 'chart', colunas(rot, d.semanas.map((s) => s.total), { titulo: 'Ações aprovadas por semana' }))
            + card('Quando as pessoas agem', 'calendar', mapaCalor(d.calor), 'Horário do registro (não rejeitados). Mostra os melhores momentos para lembretes e eventos.')
            + card('Por atividade', 'clipboard', atividades + legenda)
            + `<div class="admin-two-col">
                ${card('Ações com foto', 'image', `<div class="kpi-grid">
                    <div class="kpi kpi-plain"><strong class="kpi-value">${ev.rejeicao === null ? '—' : `${numero(ev.rejeicao)}%`}</strong><span class="kpi-label">rejeitadas (${ev.rejeitados} de ${ev.aprovados + ev.rejeitados})</span></div>
                    <div class="kpi kpi-plain"><strong class="kpi-value">${ev.minutosAdmin === null ? '—' : ev.minutosAdmin < 60 ? `${ev.minutosAdmin} min` : `${numero(ev.minutosAdmin / 60)} h`}</strong><span class="kpi-label">até a decisão do administrador (${ev.porAdmin})</span></div>
                    <div class="kpi kpi-plain"><strong class="kpi-value">${ev.automaticos}</strong><span class="kpi-label">aprovadas automaticamente em ${ev.horasAutomatica} h</span></div>
                </div>`)}
                ${card('Motivos de rejeição recentes', 'close', motivos)}
            </div>`
            + card('Impacto real por semana', 'droplet', impacto, 'Estimativa: valor fixo da atividade ou a quantidade informada pelo parceiro.');
    },

    engajamento(d) {
        const ret = tabela(['Semana do cadastro', 'Jogadores', 'Voltou na semana seguinte', 'Depois de 2 semanas', 'Depois de 4 semanas'], d.retencao.map((r) =>
            `<tr><td>${semanaBR(r.semana)}</td><td>${r.jogadores}</td>${[1, 2, 4].map((k) => `<td>${r[`aguarda${k}`] ? AGUARDA : r.poucos ? POUCOS : pct(r[`s${k}`])}</td>`).join('')}</tr>`), 'Nenhum cadastro no período.');
        const g = d.retencaoGeral;
        const geral = (k) => (g[`base${k}`] === 0 ? AGUARDA : pct(g[`s${k}`]));
        const m = (t) => d.missoes[t] ? `${d.missoes[t].pct === null ? '—' : `${numero(d.missoes[t].pct)}%`}` : '—';
        const mSub = (t) => d.missoes[t] ? `${d.missoes[t].concluidas} de ${d.missoes[t].total} resultados` : 'sem resultados no período';
        return `<div class="kpi-grid">
                ${kpi('Ativos nos últimos 7 dias', `${numero(d.ativosSemana, 0)}`, `de ${numero(d.jogadores, 0)} jogadores`)}
                ${kpi(`Sem usar há ${d.inativoDias} dias ou mais`, numero(d.inativos, 0), 'jogadores (número somado)')}
                ${kpi('Maior sequência de dias (média)', d.sequencia.media === null ? '—' : numero(d.sequencia.media), d.sequencia.media === null ? 'menos de 5 jogadores com ações' : `recorde: ${d.sequencia.maior} dias`)}
                ${kpi('Missões semanais concluídas', m('semanal'), mSub('semanal'))}
                ${kpi('Missões especiais concluídas', m('especial'), mSub('especial'))}
            </div>`
            + `<div class="admin-two-col">
                ${card('Jogadores ativos por semana', 'users', colunas(d.semanal.map((s) => semanaBR(s.semana)), d.semanal.map((s) => s.n), { titulo: 'Jogadores ativos por semana' }))}
                ${card('Novos cadastros por semana', 'user', colunas(d.novos.map((s) => semanaBR(s.semana)), d.novos.map((s) => s.n), { titulo: 'Novos cadastros por semana', cor: 'var(--accent-gold)' }))}
            </div>`
            + card('Retenção', 'calendar', `<div class="kpi-grid est-ret">
                    <div class="kpi kpi-plain"><strong class="kpi-value">${geral(1)}</strong><span class="kpi-label">voltam na semana seguinte</span></div>
                    <div class="kpi kpi-plain"><strong class="kpi-value">${geral(2)}</strong><span class="kpi-label">depois de 2 semanas</span></div>
                    <div class="kpi kpi-plain"><strong class="kpi-value">${geral(4)}</strong><span class="kpi-label">depois de 4 semanas</span></div>
                </div>${ret}`, 'Retenção = jogadores cadastrados numa semana que usaram o jogo na semana +1, +2 ou +4. Semanas que ainda não chegaram não contam.')
            + card('Do cadastro à prática', 'flag', funil([
                { rotulo: 'Cadastraram', n: d.funil.cadastrados }, { rotulo: '1ª ação aprovada', n: d.funil.acao },
                { rotulo: '1º quiz concluído', n: d.funil.quiz }, { rotulo: '1ª missão concluída', n: d.funil.missao },
                { rotulo: '1ª construção', n: d.funil.construcao }]), 'Jogadores cadastrados no período e quantos deles já chegaram a cada etapa.');
    },

    cidade(d) {
        const origens = tabela(['Origem', 'Total', ...CATEGORIAS.map((c) => c.curto)], d.origens.map((o) =>
            `<tr><td>${escapeHTML(ORIGEM[o.origem] || o.origem)}</td><td><strong class="${o.total < 0 ? 'valor-negativo' : ''}">${o.total > 0 ? '+' : ''}${numero(o.total, 0)}</strong></td>${CATEGORIAS.map((c) => `<td>${numero(o.indicadores[c.id] ?? 0, 0)}</td>`).join('')}</tr>`));
        const falhas = barras(CATEGORIAS.map((c) => ({ rotulo: `${dot(c.id)}${c.curto}`, valor: d.missoesFalhadas[c.id] ?? 0, cor: COR_CATEGORIA[c.id], texto: numero(d.missoesFalhadas[c.id] ?? 0, 0) })));
        const semanas = tabela(['Semana', 'Pontos ganhos', 'Pontos perdidos', 'Saldo'], d.semanas.map((s) =>
            `<tr><td>${semanaBR(s.semana)}</td><td>+${s.ganhos}</td><td class="valor-negativo">${s.perdas}</td><td><strong>${s.ganhos + s.perdas}</strong></td></tr>`));
        const estruturas = barras(d.estruturas.map((e) => ({ rotulo: escapeHTML(e.nome), valor: e.n, texto: `${e.n} · ${e.cidades} cid.` })), { vazio: 'Nenhuma construção no período.' });
        const comunidades = tabela(['Comunidade', 'Ações', 'Pontos', 'Jogadores', 'Missões concluídas'], d.comunidades.map((c) =>
            `<tr><td>${dot(c.icone)}${escapeHTML(c.nome)}</td><td>${c.acoes}</td><td><strong>${c.pontos}</strong></td><td>${c.jogadores}</td><td>${c.missoes}</td></tr>`), 'Nenhuma contribuição de comunidade no período.');
        const parceiros = tabela(['Parceiro', 'Atividade', 'Verificações', 'Impacto confirmado', 'Jogadores'], d.parceiros.map((p) =>
            `<tr><td>${escapeHTML(p.nome)}</td><td>${escapeHTML(p.atividade)}</td><td><strong>${p.verificacoes}</strong></td><td>${numero(p.impacto)} ${escapeHTML(p.unidade || '')}</td><td>${p.jogadores}</td></tr>`), 'Nenhuma verificação de parceiro no período.');
        return card('De onde vêm (e para onde vão) os pontos dos indicadores', 'leaf', origens, 'Soma das variações de todas as cidades. Mostra quanto as ações compensam a queda diária, a poluição e as missões não concluídas.')
            + `<div class="admin-two-col">
                ${card('Áreas mais negligenciadas', 'flag', falhas, 'Metas de missões semanais não cumpridas, por área.')}
                ${card('Estruturas mais construídas', 'pin', estruturas)}
            </div>`
            + card('Saldo semanal dos indicadores', 'chart', semanas)
            + card('Comunidades', 'users', comunidades)
            + card('Parceiros', 'flag', parceiros);
    },
};

export function initEstatisticasAdmin(raiz, hydrateIcons) {
    if (!raiz) return;
    const painel = raiz.querySelector('#est-painel');
    const periodo = raiz.querySelector('#est-periodo');
    const csv = raiz.querySelector('#est-csv');
    const totais = document.getElementById('est-totais');
    const chips = [...raiz.querySelectorAll('[data-secao]')];
    let secao = 'resumo';

    async function carregar() {
        const p = periodo.value;
        csv.href = `api/estatisticas.php?secao=${secao}&periodo=${p}&formato=csv`;
        totais.hidden = secao !== 'resumo';
        const chave = `${secao}:${p}`;
        painel.setAttribute('aria-busy', 'true');
        if (!cache.has(chave)) painel.innerHTML = '<p class="muted">Carregando…</p>';
        try {
            if (!cache.has(chave)) {
                const r = await fetch(`api/estatisticas.php?secao=${secao}&periodo=${p}`, { headers: { Accept: 'application/json' } });
                const j = await r.json();
                if (!j.ok) throw new Error(j.erro || 'Não foi possível carregar.');
                cache.set(chave, j.dados);
            }
            if (`${secao}:${periodo.value}` !== chave) return;   // trocou de seção no meio
            painel.innerHTML = SECOES[secao](cache.get(chave));
            hydrateIcons(painel);
        } catch (err) {
            painel.innerHTML = `<div class="alert alert-error">${escapeHTML(err.message)}</div>`;
        } finally {
            painel.removeAttribute('aria-busy');
        }
    }

    const ativar = (chip, foco = false) => {
        chips.forEach((c) => { const a = c === chip; c.setAttribute('aria-selected', String(a)); c.tabIndex = a ? 0 : -1; });
        secao = chip.dataset.secao;
        if (foco) chip.focus();
        carregar();
    };
    raiz.querySelector('.est-secoes').addEventListener('click', (e) => { const c = e.target.closest('[data-secao]'); if (c) ativar(c); });
    raiz.querySelector('.est-secoes').addEventListener('keydown', (e) => {
        const i = chips.indexOf(document.activeElement);
        const mapa = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: chips.length - 1 };
        if (i < 0 || !(e.key in mapa)) return;
        e.preventDefault();
        ativar(chips[(mapa[e.key] + chips.length) % chips.length], true);
    });
    periodo.addEventListener('change', carregar);
    ativar(chips[0]);
}
