/**
 * Administração › Conteúdo: atividades, quizzes, missões, conquistas, estruturas e contas.
 * As regras valem no servidor (includes/conteudo.php); aqui só montamos listas e formulários.
 */
import { icon, hydrateIcons } from './icons.js';
import { escapeHTML, toast } from './dom.js';
import { listaPaginada, definirFiltro } from './lista-admin.js';
import { montarAuditoriaAdmin } from './admin-auditoria.js';
import { desenharEstrutura } from '../city/structures.js';
import { p, quad } from '../city/iso.js';

const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const SECOES = [
    ['atividade', 'Atividades', 'leaf'], ['quiz', 'Quizzes', 'help'], ['missao', 'Missões', 'clipboard'],
    ['conquista', 'Conquistas', 'trophy'], ['construcao', 'Estruturas', 'home'], ['cmissao', 'Missões de comunidade', 'flag'],
    ['promocao', 'Códigos promocionais', 'gift'],
    ['comunidade', 'Comunidades', 'users'], ['conta', 'Contas', 'user'], ['auditoria', 'Auditoria', 'lock'],
];
const SECOES_CATALOGO = ['atividade', 'quiz', 'missao', 'conquista', 'construcao', 'cmissao'];
/** Seções só de lista (sem formulário de criação). */
const SO_LISTA = ['conta', 'comunidade', 'auditoria'];
const LISTA = { atividade: 'atividades', quiz: 'quizzes', missao: 'missoes', conquista: 'conquistas', construcao: 'construcoes', cmissao: 'cmissoes', promocao: 'promocoes', comunidade: 'comunidades', conta: 'contas' };
const NOVO = { atividade: 'Nova atividade', quiz: 'Novo quiz', missao: 'Nova missão', conquista: 'Nova conquista', construcao: 'Nova estrutura', cmissao: 'Nova missão de comunidade', promocao: 'Novo código promocional' };

const dataLocalISO = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const ultimoDiaMesISO = () => {
    const d = new Date();
    return dataLocalISO(new Date(d.getFullYear(), d.getMonth() + 1, 0));
};
const fmtDataBR = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—');
function gerarCodigoPromoAleatorio() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const bytes = new Uint8Array(6);
    crypto.getRandomValues(bytes);
    return 'ECO-' + Array.from(bytes, (b) => chars[b % chars.length]).join('');
}
const AREA_CURTA_IND = { 1: 'Reciclagem', 2: 'Água', 3: 'Energia', 4: 'Mobilidade', 5: 'Natureza' };
const nomeCurtoIndicador = (indId) => AREA_CURTA_IND[Number(indId)]
    || st.dados?.categorias?.find((c) => c.indicadorId === Number(indId))?.nome
    || `#${indId}`;

function resumoIndicadoresPromo(inds = {}) {
    const itens = st.dados.categorias
        .map((c) => [nomeCurtoIndicador(c.indicadorId), Number(inds[c.indicadorId] || 0)])
        .filter(([, pts]) => pts > 0);
    if (!itens.length) return '';
    const vals = itens.map(([, pts]) => pts);
    if (itens.length === st.dados.categorias.length && Math.min(...vals) === Math.max(...vals)) {
        return `+${vals[0]} pts (5 áreas)`;
    }
    return itens.map(([n, pts]) => `+${pts} ${n}`).join(', ');
}
const SITUACAO_PROMO = {
    ativa: ['tag-success', 'Ativo'],
    agendada: ['tag-warning', 'Agendado'],
    expirada: ['tag-error', 'Expirado'],
    esgotada: ['tag-error', 'Esgotado'],
    desativada: ['tag-neutral', 'Desativado'],
};
const NOMES_CRITERIO = {
    atividades_aprovadas: 'Atividades aprovadas (total)', atividades_categoria: 'Atividades aprovadas de uma área',
    impacto_real: 'Impacto real principal de uma área (ex.: litros, mudas)', impacto_atividade: 'Impacto real de uma atividade específica',
    indicador: 'Valor do indicador de uma área',
    registros_evidencia: 'Registros com foto aprovados', verificacoes_parceiro: 'Atividades verificadas por parceiros', quizzes_concluidos: 'Quizzes concluídos', quizzes_perfeitos: 'Quizzes perfeitos',
    nivel_cidade: 'Nível da cidade', nivel_jogador: 'Nível do jogador', construcoes: 'Estruturas construídas (todas)',
    construcoes_ecologicas: 'Estruturas ecológicas construídas', missoes_especiais: 'Missões especiais concluídas',
};

const st = {
    dados: null, secao: 'atividade', modo: 'catalogo', ultimaCatalogo: 'atividade',
    editando: null, raiz: null, rotacaoPrevia: 0, carregando: false,
    contaDetalhe: null, contaCarregando: false, linkRedefinicao: null,
};

/* ---------------------------------------------------------------------------
   API
   --------------------------------------------------------------------------- */

async function api(campos) {
    const corpo = new FormData();
    Object.entries(campos).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        if (v instanceof Blob) corpo.append(k, v);
        else corpo.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
    });
    const r = await fetch('api/conteudo.php', { method: 'POST', body: corpo, headers: { 'X-CSRF-Token': CSRF } });
    const j = await r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!j.ok) throw new Error(j.erro || 'Não foi possível concluir.');
    if (j.conteudo) st.dados = j.conteudo;
    if (j.conta) st.contaDetalhe = j.conta;
    if (j.mensagem) toast(j.mensagem);
    return j;
}

/* ---------------------------------------------------------------------------
   Utilitários de formulário
   --------------------------------------------------------------------------- */

const opcoes = (lista, atual) => lista.map(([v, t]) => `<option value="${escapeHTML(String(v))}" ${String(v) === String(atual ?? '') ? 'selected' : ''}>${escapeHTML(t)}</option>`).join('');
const categorias = () => st.dados.categorias.map((c) => [c.id, c.nome]);
const nomeCategoria = (id) => st.dados.categorias.find((c) => c.id === id)?.nome || '—';

function campo(rotulo, nome, valor = '', { tipo = 'text', attrs = '', ajuda = '' } = {}) {
    return `<div class="form-group">
        <label>${rotulo}
            <input class="form-input" type="${tipo}" name="${nome}" value="${escapeHTML(String(valor ?? ''))}" ${attrs}>
        </label>${ajuda ? `<small class="form-hint">${ajuda}</small>` : ''}
    </div>`;
}
function area(rotulo, nome, valor = '', max = 250) {
    return `<div class="form-group"><label>${rotulo}
        <textarea class="form-input" name="${nome}" rows="2" maxlength="${max}">${escapeHTML(valor ?? '')}</textarea></label></div>`;
}
function selecao(rotulo, nome, lista, atual) {
    return `<div class="form-group"><label>${rotulo}<select class="form-input" name="${nome}">${opcoes(lista, atual)}</select></label></div>`;
}
function ativa(valor = true) {
    return `<label class="check-line"><input type="checkbox" name="ativa" ${valor ? 'checked' : ''}> Ativo (aparece no jogo)</label>`;
}
const linha = (...campos) => `<div class="form-row">${campos.join('')}</div>`;
const tagAtivo = (a) => `<span class="tag ${a ? 'tag-success' : 'tag-neutral'}">${a ? 'Ativo' : 'Desativado'}</span>`;

/* ---------------------------------------------------------------------------
   Formulários por tipo
   --------------------------------------------------------------------------- */

const FORM = {
    atividade: (a = {}) => campo('Nome', 'nome', a.nome, { attrs: 'maxlength="100" required' })
        + area('Descrição', 'descricao', a.descricao)
        + linha(selecao('Categoria', 'categoria', categorias(), a.categoria),
            campo('Impacto no indicador (com foto)', 'impacto', a.impacto ?? 6, { tipo: 'number', attrs: 'min="0" max="100"', ajuda: 'Autodeclarada recebe 50%.' }))
        + linha(campo('XP (com foto)', 'xp', a.xp ?? 50, { tipo: 'number', attrs: 'min="0"' }),
            campo('Créditos (com foto)', 'credito', a.credito ?? 30, { tipo: 'number', attrs: 'min="0"' }))
        + linha(campo('Impacto real por registro', 'impactoRealQuantidade', a.impactoRealQuantidade ?? '', { tipo: 'number', attrs: 'min="0" step="0.01"', ajuda: 'Opcional. Ex.: 90' }),
            campo('Unidade', 'impactoRealUnidade', a.impactoRealUnidade ?? '', { attrs: 'maxlength="30"', ajuda: 'Ex.: litros de água' }))
        + `<label class="check-line"><input type="checkbox" name="aceitaFoto" ${a.aceitaFoto ?? true ? 'checked' : ''}> Pode ser comprovada por foto</label>
           <p class="form-hint">Desmarque quando não há como provar a ação com foto (ex.: banho mais curto): o jogador só registra sem foto e recebe o valor cheio.</p>`
        + `<label class="check-line"><input type="checkbox" name="aceitaParceiro" ${a.aceitaParceiro ?? true ? 'checked' : ''}> Pode ser verificada por parceiro</label>
           <p class="form-hint">Marque apenas ações que um estabelecimento parceiro (cooperativa, ecoponto, mercado, viveiro, bicicletaria) consegue confirmar presencialmente. Exige impacto real preenchido.</p>`
        + (a.usado ? '<p class="form-hint">Já usada por jogadores: a categoria não pode mudar.</p>' : '')
        + ativa(a.ativa ?? true),

    quiz: (q = {}) => campo('Título', 'titulo', q.titulo, { attrs: 'maxlength="50" required' })
        + area('Descrição', 'descricao', q.descricao, 150)
        + linha(selecao('Tipo', 'tipo', Object.entries(st.dados.tiposQuiz).map(([k, t]) => [k, t.nome]), q.tipo || 'tematico'),
            selecao('Tema', 'categoria', categorias(), q.categoria),
            selecao('Dificuldade', 'dificuldade', st.dados.dificuldades.map((d) => [d, d]), q.dificuldade || st.dados.dificuldades[0]))
        + `<p class="form-hint quiz-tipo-hint">${Object.values(st.dados.tiposQuiz).map((t) => `${t.nome}: ${t.min} a ${t.max} perguntas${t.multiplicador > 1 ? `, recompensa ×${t.multiplicador}` : ''}`).join(' · ')}. Diário = Quiz do Dia (troca todo dia); Semanal = Quiz da Semana; Temático = 3 por semana.</p>`
        + linha(campo('XP base', 'xp', q.xp ?? 40, { tipo: 'number', attrs: 'min="0"', ajuda: 'Multiplicado pela dificuldade (×1, ×1,5, ×2) e pelo tipo.' }),
            campo('Créditos base', 'credito', q.credito ?? 25, { tipo: 'number', attrs: 'min="0"' }))
        + ativa(q.ativa ?? true)
        + (q.usado ? '<p class="alert alert-warning">Este quiz já foi respondido: só os textos podem mudar. Para mudar perguntas, alternativas ou a resposta correta, crie um novo quiz.</p>' : '')
        + `<div class="quiz-editor" data-travado="${q.usado ? '1' : ''}">${(q.perguntas?.length ? q.perguntas : [perguntaVazia()]).map((p, i) => blocoPergunta(p, i, q.usado)).join('')}</div>`
        + (q.usado ? '' : '<button type="button" class="btn btn-outline btn-sm" data-add-pergunta>+ Pergunta</button>'),

    missao: (m = {}) => linha(campo('Nome', 'nome', m.nome, { attrs: 'maxlength="100" required' }),
            selecao('Tipo', 'tipo', [[1, 'Semanal (recorrente)'], [2, 'Especial (com fim)']], m.tipo ?? 1))
        + area('Descrição', 'descricao', m.descricao)
        + linha(campo('Início', 'dataInicio', m.dataInicio ?? new Date().toISOString().slice(0, 10), { tipo: 'date' }),
            campo('Fim', 'dataFim', m.dataFim ?? '', { tipo: 'date', ajuda: 'Obrigatório na especial; opcional na semanal.' }))
        + linha(campo('XP extra', 'xp', m.xp ?? 60, { tipo: 'number', attrs: 'min="0"' }),
            campo('Créditos extras', 'credito', m.credito ?? 30, { tipo: 'number', attrs: 'min="0"' }),
            `<div class="mis-penalidade" ${Number(m.tipo) === 2 ? 'hidden' : ''}>${campo('Penalidade (pontos)', 'penalidade', m.penalidade ?? 5, { tipo: 'number', attrs: 'min="0" max="100"', ajuda: 'Só na semanal: a especial não penaliza.' })}</div>`)
        + `<fieldset class="metas-editor"><legend>Metas</legend>${(m.metas?.length ? m.metas : [{}]).map(blocoMeta).join('')}</fieldset>`
        + '<button type="button" class="btn btn-outline btn-sm" data-add-meta>+ Meta</button>'
        + '<p class="form-hint mis-sugestao" data-sugestao></p>'
        + ativa(m.ativa ?? true),

    conquista: (c = {}) => campo('Nome', 'nome', c.nome, { attrs: 'maxlength="50" required' })
        + area('Descrição', 'descricao', c.descricao)
        + linha(selecao('Critério', 'criterio', Object.keys(st.dados.criterios).map((k) => [k, NOMES_CRITERIO[k] || k]), c.criterio),
            selecao('Área (quando o critério é por área)', 'indicador', st.dados.categorias.map((k) => {
                const a0 = (st.dados.atividades || []).find((a) => a.categoria === k.id && a.impactoRealUnidade);
                return [k.indicadorId, a0 ? `${k.nome} (${a0.impactoRealUnidade})` : k.nome];
            }), c.indicador ?? 1),
            selecao('Atividade (quando o critério é por atividade)', 'atividade', (st.dados.atividades || []).filter((a) => a.impactoRealQuantidade > 0).map((a) => [a.id, `${a.nome} (${a.impactoRealUnidade})`]), c.atividade ?? 1),
            campo('Meta', 'meta', c.meta ?? 1, { tipo: 'number', attrs: 'min="1"' }))
        + '<p class="form-hint">Para metas de impacto real de atividades com unidade própria (ex.: compostagem em kg ou limpeza em kg), escolha "Impacto real de uma atividade específica".</p>'
        + linha(campo('XP', 'xp', c.xp ?? 100, { tipo: 'number', attrs: 'min="0"' }), campo('Créditos', 'credito', c.credito ?? 200, { tipo: 'number', attrs: 'min="0"' }))
        + ativa(c.ativa ?? true),

    construcao: (e = {}) => editorDesenhoIA(e)
        + campo('Nome', 'nome', e.nome, { attrs: 'maxlength="100" required' })
        + area('Descrição', 'descricao', e.descricao)
        + linha(campo('Largura (células)', 'largura', e.largura ?? 2, { tipo: 'number', attrs: 'min="1" max="10"' }),
            campo('Profundidade (células)', 'profundidade', e.profundidade ?? 2, { tipo: 'number', attrs: 'min="1" max="10"' }))
        + linha(campo('Custo (créditos)', 'custo', e.custo ?? 300, { tipo: 'number', attrs: 'min="0"' }),
            selecao('Nível mínimo da cidade', 'nivel', [[1, 'Nível 1'], [2, 'Nível 2'], [3, 'Nível 3'], [4, 'Nível 4']], e.nivel ?? 2))
        + linha(selecao('Categoria', 'categoria', Object.entries(st.dados.categoriasConstrucao), e.categoria || 'ecologica'),
            selecao('Efeito no indicador', 'efeito', [['', 'Nenhum'], ['polui', 'Polui (−1 a cada 2 dias)'], ['compensa', 'Compensa uma poluidora']], e.efeito || ''),
            selecao('Área do efeito', 'area', [['', '—'], ...categorias()], e.area ?? ''))
        + (e.usado ? '<p class="form-hint">Já construída: o tamanho não pode mudar.</p>' : '')
        + `<fieldset class="desenho-editor efeitos-editor"><legend>Efeitos na cidade</legend>
            ${linha(campo('Moradores', 'moradores', e.moradores ?? 0, { tipo: 'number', attrs: 'min="0" max="20"', ajuda: 'Habitantes a mais (a cidade começa com 5, até 50).' }),
                campo('Renda por dia (créditos)', 'renda_diaria', e.renda_diaria ?? 0, { tipo: 'number', attrs: 'min="0" max="20"', ajuda: 'Só Comércio e lazer. Acumula até 3 dias; teto de 15 por dia na cidade.' }))}
            ${linha(campo('Carros', 'carros', e.carros ?? 0, { tipo: 'number', attrs: 'min="0" max="5"' }),
                campo('Bicicletas', 'bicicletas', e.bicicletas ?? 0, { tipo: 'number', attrs: 'min="0" max="5"' }),
                campo('Ônibus', 'onibus', e.onibus ?? 0, { tipo: 'number', attrs: 'min="0" max="5"', ajuda: 'Somam à frota da Mobilidade (até 20 veículos).' }))}
        </fieldset>`
        + ativa(e.ativa ?? true),

    cmissao: (m = {}) => campo('Nome', 'nome', m.nome, { attrs: 'maxlength="100" required' })
        + area('Descrição', 'descricao', m.descricao)
        + linha(selecao('Atividade', 'atividade', st.dados.atividades.filter((a) => a.impactoRealQuantidade).map((a) => [a.id, `${a.nome} (${String(a.impactoRealQuantidade).replace('.', ',')} ${a.impactoRealUnidade} por registro)`]), m.atividade),
            campo('Meta em impacto real', 'meta', m.meta ?? '', { tipo: 'number', attrs: 'min="0.01" step="0.01" required', ajuda: 'Na unidade da atividade. Ex.: 15 (kg).' }))
        + '<p class="form-hint">Semanal (segunda a domingo), igual para todas as comunidades. 30 membros fazem no máximo 7 registros de cada atividade por semana: Separação 63 kg, Banho 18.900 L, Luzes 147 kWh, Transporte 126 kg CO₂, Plantio 210 mudas.</p>'
        + linha(campo('Início', 'dataInicio', m.dataInicio ?? new Date().toISOString().slice(0, 10), { tipo: 'date' }),
            campo('Fim', 'dataFim', m.dataFim ?? '', { tipo: 'date', ajuda: 'Opcional.' }))
        + linha(campo('XP para quem contribuiu', 'xp', m.xp ?? 60, { tipo: 'number', attrs: 'min="0"' }),
            campo('Créditos para quem contribuiu', 'credito', m.credito ?? 40, { tipo: 'number', attrs: 'min="0"' }),
            campo('Pontos de ranking', 'pontos', m.pontos ?? 100, { tipo: 'number', attrs: 'min="0"' }))
        + (m.usado ? '<p class="form-hint">Já concluída por comunidades: a atividade não pode mudar.</p>' : '')
        + ativa(m.ativa ?? true),

    promocao: (p = {}) => linha(
            `<div class="form-group">
                <label>Código promocional
                    <div class="promo-code-input-row">
                        <input class="form-input" type="text" name="codigo" maxlength="30" required placeholder="EX.: BEMVINDO2026" value="${escapeHTML(p.codigo || '')}" ${p.usado ? 'readonly' : ''}>
                        ${p.usado ? '' : '<button type="button" class="btn btn-outline btn-sm" data-gerar-codigo-promo>Gerar código</button>'}
                    </div>
                </label>
                <small class="form-hint">4 a 30 letras, números, hífen (-) ou sublinhado (_). Sempre em maiúsculas.</small>
            </div>`,
            campo('Nome da campanha', 'nome', p.nome, { attrs: 'maxlength="100" required placeholder="Ex.: Boas-vindas de novembro"' })
        )
        + area('Descrição (opcional)', 'descricao', p.descricao, 250)
        + linha(
            campo('Data de início', 'dataInicio', p.dataInicio ?? dataLocalISO(), { tipo: 'date', attrs: p.usado ? 'readonly required' : 'required' }),
            campo('Data de término', 'dataFim', p.dataFim ?? ultimoDiaMesISO(), { tipo: 'date', attrs: 'required' }),
            campo('Limite de resgates', 'limiteUsos', p.limiteUsos ?? '', { tipo: 'number', attrs: `min="${Math.max(1, p.resgates || 1)}" max="1000000"`, ajuda: 'Opcional. Vazio = ilimitado.' })
        )
        + linha(
            campo('XP', 'xp', p.xp ?? 0, { tipo: 'number', attrs: `min="0" max="100000" ${p.usado ? 'disabled' : ''}` }),
            campo('Créditos verdes', 'credito', p.credito ?? 0, { tipo: 'number', attrs: `min="0" max="100000" ${p.usado ? 'disabled' : ''}` })
        )
        + `<fieldset class="desenho-editor"><legend>Pontos nos indicadores ambientais (opcional)</legend>
            ${p.usado ? '' : `<div class="promo-ind-todos">
                <input type="number" class="form-input" id="promo-todos-valor" min="0" max="100" placeholder="Ex.: 30" aria-label="Pontos para aplicar em todas as áreas">
                <button type="button" class="btn btn-outline btn-sm" data-promo-todos>Aplicar nas 5 áreas</button>
                <small class="form-hint">Cada indicador vai de 0 a 100 pontos.</small>
            </div>`}
            <div class="promo-ind-grid">
                ${st.dados.categorias.map((c) => `<div class="form-group">
                    <label>${escapeHTML(c.nome)}
                        <input class="form-input" type="number" min="0" max="100" data-promo-ind="${c.indicadorId}" value="${p.indicadores?.[c.indicadorId] ?? 0}" ${p.usado ? 'disabled' : ''}>
                    </label>
                </div>`).join('')}
            </div>
        </fieldset>`
        + (p.usado ? `<p class="alert alert-warning">Este código já foi resgatado (${p.resgates}×): o código, as recompensas e a data de início ficam travados.</p>` : '')
        + `<label class="check-line"><input type="checkbox" name="somenteNovos" ${p.somenteNovos ? 'checked' : ''}> Restringir a contas criadas dentro do período (início a término)</label>`
        + `<label class="check-line"><input type="checkbox" name="ativa" ${p.ativa ?? true ? 'checked' : ''}> Ativo (disponível para resgate dentro da validade)</label>`,
};

/* ---------------------------------------------------------------------------
   Geração e prévia 4×4 da estrutura por IA local (RN76)
   --------------------------------------------------------------------------- */

const ESTAGIOS_ROTULO = [
    [1, 'Estágio 1 · Degradado'],
    [2, 'Estágio 2 · Em recuperação'],
    [3, 'Estágio 3 · Sustentável'],
    [4, 'Estágio 4 · Modelo exemplar'],
];
const DIRECOES_ROTULO = [
    [0, '0° (Frente)'],
    [1, '90° (Direita)'],
    [2, '180° (Costas)'],
    [3, '270° (Esquerda)'],
];

function editorDesenhoIA(e) {
    const desenho = e.desenho || 'personalizado';
    const promptSalvo = e.aparencia?.prompt || '';
    const iaOk = !!st.dados.iaDisponivel;
    const temDesenhoIA = desenho === 'personalizado' && (e.aparencia?.pecas || e.aparencia?.blocos || e.aparencia?.elementos);
    const rotuloOrigem = temDesenhoIA
        ? '<span class="tag tag-ia">Gerado por IA</span>'
        : desenho !== 'personalizado' && st.dados.desenhos[desenho]
            ? `<span class="tag tag-neutral">Modelo original: ${escapeHTML(st.dados.desenhos[desenho])}</span>`
            : '<span class="tag tag-neutral">Desenho personalizado</span>';

    return `<fieldset class="desenho-editor ia-estrutura-editor">
        <legend>Criar ou ajustar estrutura com IA ${rotuloOrigem}</legend>
        <input type="hidden" name="desenho" value="${escapeHTML(desenho)}">
        <div class="ia-prompt-bloco">
            <label for="ia-estrutura-prompt" class="form-label">Descreva a estrutura ou o ajuste visual que deseja</label>
            <textarea id="ia-estrutura-prompt" class="form-input" rows="2" maxlength="400" placeholder="Ex.: Estação petrolífera com dois tanques cilíndricos, torre metálica de extração, galpão de controle e chaminé flare.">${escapeHTML(promptSalvo)}</textarea>
            <div class="ia-foto-linha">
                <label for="ia-estrutura-foto" class="form-label">Imagem de referência <span class="form-hint">(opcional — envie uma foto ou desenho para a IA usar de base; JPG, PNG ou WebP até 5 MB)</span></label>
                <div class="ia-foto-controles">
                    <input type="file" id="ia-estrutura-foto" class="form-input" accept="image/jpeg,image/png,image/webp">
                    <div class="ia-foto-previa" id="ia-foto-previa" hidden>
                        <img id="ia-foto-img" alt="Miniatura da imagem de referência">
                        <button type="button" class="btn btn-outline btn-sm" data-limpar-foto-ia>Remover imagem</button>
                    </div>
                </div>
            </div>
            <div class="ia-prompt-acoes">
                <button type="button" class="btn btn-primary btn-sm" data-ia-estrutura="tudo" ${iaOk ? '' : 'disabled title="IA indisponível no momento"'}>
                    ${icon('star')} Gerar tudo com IA (campos + desenho)
                </button>
                <button type="button" class="btn btn-outline btn-sm" data-ia-estrutura="desenho" ${iaOk ? '' : 'disabled title="IA indisponível no momento"'}>
                    ${icon('image')} ${temDesenhoIA ? 'Ajustar só o desenho com IA' : 'Gerar só o desenho com IA'}
                </button>
                <span class="form-hint ia-status-msg" id="ia-estrutura-status">${iaOk ? 'A IA modela a geometria 3D isométrica do zero (ou a partir da sua foto). Depois você pode editar qualquer campo.' : 'O serviço de IA está indisponível no momento.'}</span>
            </div>
        </div>
        <div class="previa-topo">
            <span class="form-label">Prévia da estrutura (4 estágios ecológicos × 4 direções)</span>
            <div class="previa-giro" role="group" aria-label="Girar estrutura nas 4 direções">
                ${DIRECOES_ROTULO.map(([r, t]) => `<button type="button" class="chip ${st.rotacaoPrevia === r ? 'is-active' : ''}" data-previa-rotacao="${r}" aria-pressed="${st.rotacaoPrevia === r}">${t}</button>`).join('')}
            </div>
        </div>
        <div class="desenho-previa desenho-previa-4" aria-live="polite">
            ${ESTAGIOS_ROTULO.map(([n, rot]) => `<figure><div data-previa="${n}"></div><figcaption>${rot}</figcaption></figure>`).join('')}
        </div>
    </fieldset>`;
}

/** Lote pequeno com a estrutura no centro (mesmo motor do mapa), num estágio (1..4) e rotação (0..3). */
function svgPrevia(estrutura, w, d, estagio, rotacao = 0) {
    const k = ((Math.trunc(Number(rotacao)) || 0) % 4 + 4) % 4;
    const [wr, dr] = k % 2 ? [d, w] : [w, d];
    const m = 1;   // margem de 1 célula
    const W = wr + m * 2, D = dr + m * 2;
    const cond = { agua: estagio, natureza: estagio, energia: estagio, mobilidade: estagio, reciclagem: estagio };
    const des = desenharEstrutura(estrutura, m, m, wr, dr, cond, k);
    let topo = 3.5;
    if (estrutura.desenho === 'personalizado') {
        if (Array.isArray(estrutura.aparencia?.pecas) && estrutura.aparencia.pecas.length) {
            const maxPeca = Math.max(...estrutura.aparencia.pecas.map((pc) => {
                if (Array.isArray(pc.pontos) && pc.pontos.length) return Math.max(...pc.pontos.map((pt) => Number(pt[2]) || 0));
                const z0 = Number(pc.z0) || 0;
                const h = Number(pc.h) || 1.0;
                if (pc.tipo === 'edificio') return z0 + Math.max(h, 0.45 + 0.52 * (Number(pc.andares) || 1)) + 0.5;
                if (pc.tipo === 'barraca') return z0 + Math.max(h, 2.0);
                return z0 + h;
            }));
            topo = Math.max(2.8, maxPeca + 1.0);
        } else if (Array.isArray(estrutura.aparencia?.blocos) && estrutura.aparencia.blocos.length) {
            const maxBloco = Math.max(...estrutura.aparencia.blocos.map((b) => (Number(b.z0) || 0) + (Number(b.h) || 1.2)));
            topo = Math.max(2.8, maxBloco + 1.2);
        } else {
            topo = 0.5 + 0.65 * (estrutura.aparencia?.andares || 1) + 1.2;
        }
    }
    const cantos = [[0, 0, 0], [W, 0, 0], [0, D, 0], [W, D, 0], [m, m, topo], [m + wr, m, topo]].map(([x, y, z]) => p(x, y, z));
    const xs = cantos.map((c) => c[0]), ys = cantos.map((c) => c[1]);
    const [x0, y0] = [Math.min(...xs) - 6, Math.min(...ys) - 34];
    const vb = `${Math.round(x0)} ${Math.round(y0)} ${Math.round(Math.max(...xs) + 10 - x0)} ${Math.round(Math.max(...ys) + 10 - y0)}`;
    const SOLOS = { 1: '#C8B48A', 2: '#C5CE7A', 3: '#9CCC65', 4: '#7CB342' };
    const solo = SOLOS[estagio] || '#9CCC65';
    return `<svg viewBox="${vb}" role="img" aria-label="Prévia no estágio ${estagio} a ${k * 90} graus">${quad(0, 0, W, D, solo, 0, 'stroke="rgba(0,0,0,0.15)"')}${des.chao}${des.objeto}</svg>`;
}

/** Converte o SVG atual da prévia em um arquivo PNG 512×512 para autoinspeção visual da IA no modo de ajuste. */
async function svgParaPngFile(svgEl) {
    if (!svgEl) return null;
    try {
        const clone = svgEl.cloneNode(true);
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        clone.setAttribute('width', '512');
        clone.setAttribute('height', '512');
        const xml = new XMLSerializer().serializeToString(clone);
        const url = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(xml)}`;
        const img = new Image();
        await new Promise((resolve, reject) => {
            img.onload = resolve;
            img.onerror = reject;
            img.src = url;
        });
        const canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 512;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#F7F9F7';
        ctx.fillRect(0, 0, 512, 512);
        ctx.drawImage(img, 0, 0, 512, 512);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png', 0.9));
        return blob ? new File([blob], 'previa-atual.png', { type: 'image/png' }) : null;
    } catch {
        return null;
    }
}

/** Atualiza a habilitação de renda e redesenha os 4 quadros de prévia na direção atual. */
function atualizarDesenhoForm(form) {
    if (!form) return;
    const renda = form.querySelector('[name="renda_diaria"]');
    if (renda) {
        const fora = form.querySelector('[name="categoria"]').value !== 'comercio';
        if (fora && !renda.disabled) { renda.dataset.antes = renda.value; renda.value = 0; }
        if (!fora && renda.disabled && renda.dataset.antes) renda.value = renda.dataset.antes;
        renda.disabled = fora;
    }
    const desenho = form.querySelector('[name="desenho"]')?.value || st.editando?.desenho || 'personalizado';
    const aparencia = desenho === 'personalizado' ? (st.editando?.aparencia || st.dados.aparenciaPadrao) : null;
    const w = Math.max(1, Math.min(10, Number(form.querySelector('[name="largura"]')?.value) || 2));
    const d = Math.max(1, Math.min(10, Number(form.querySelector('[name="profundidade"]')?.value) || 2));
    form.querySelectorAll('[data-previa]').forEach((el) => {
        el.innerHTML = svgPrevia({ desenho, aparencia }, w, d, Number(el.dataset.previa), st.rotacaoPrevia);
    });
    form.querySelectorAll('[data-previa-rotacao]').forEach((btn) => {
        const ativo = Number(btn.dataset.previaRotacao) === st.rotacaoPrevia;
        btn.classList.toggle('is-active', ativo);
        btn.setAttribute('aria-pressed', String(ativo));
    });
}

const perguntaVazia = () => ({ enunciado: '', alternativas: [{ texto: '', correta: true }, { texto: '', correta: false }] });

function blocoPergunta(p, i, travado) {
    const nome = `correta-${i}-${Math.random().toString(36).slice(2, 7)}`;
    return `<fieldset class="pergunta-bloco" data-id="${p.id ?? ''}">
        <legend>Pergunta ${i + 1}${travado ? '' : ' <button type="button" class="link-btn" data-del-pergunta>remover</button>'}</legend>
        <input class="form-input" data-enunciado maxlength="150" placeholder="Enunciado" value="${escapeHTML(p.enunciado || '')}">
        ${p.fonteUrl ? `<p class="pergunta-fonte" data-fonte-titulo="${escapeHTML(p.fonteTitulo || '')}" data-fonte-url="${escapeHTML(p.fonteUrl)}">
            Fonte: <a href="${escapeHTML(p.fonteUrl)}" target="_blank" rel="noopener noreferrer">${escapeHTML(p.fonteTitulo || p.fonteUrl)}</a></p>` : ''}
        <ol class="alt-lista">${p.alternativas.map((a) => blocoAlternativa(a, nome, travado)).join('')}</ol>
        ${travado ? '' : '<button type="button" class="link-btn" data-add-alt>+ alternativa</button>'}
    </fieldset>`;
}
function blocoAlternativa(a, nome, travado) {
    return `<li class="alt-item" data-id="${a.id ?? ''}">
        <label class="alt-correta" title="Correta"><input type="radio" name="${nome}" data-correta ${a.correta ? 'checked' : ''} ${travado ? 'disabled' : ''}> correta</label>
        <input class="form-input" data-texto maxlength="100" placeholder="Alternativa" value="${escapeHTML(a.texto || '')}">
        <input class="form-input" data-explicacao maxlength="200" placeholder="Explicação (opcional)" value="${escapeHTML(a.explicacao || '')}">
        ${travado ? '' : '<button type="button" class="link-btn" data-del-alt aria-label="Remover alternativa">×</button>'}
    </li>`;
}
function blocoMeta(m = {}) {
    const atividades = st.dados.atividades.map((a) => [a.id, a.nome + (a.ativa ? '' : ' (desativada)')]);
    return `<div class="meta-linha">
        <select class="form-input" data-atividade>${opcoes(atividades, m.atividade)}</select>
        <input class="form-input" type="number" min="1" data-quantidade value="${m.quantidade ?? 1}" aria-label="Quantidade">
        <button type="button" class="link-btn" data-del-meta aria-label="Remover meta">×</button>
    </div>`;
}

/**
 * Missão: recompensa sugerida (semanal 50%, especial 100% da soma das recompensas das atividades exigidas)
 * e limite de cada meta pelo nº de dias (1 registro por atividade por dia, RN44). Só ajuda: quem valida é o servidor.
 */
function atualizarMissaoForm(form) {
    const especial = form.querySelector('[name="tipo"]').value === '2';
    form.querySelector('.mis-penalidade').hidden = especial;
    let xp = 0, credito = 0;
    form.querySelectorAll('.meta-linha').forEach((l) => {
        const a = st.dados.atividades.find((x) => String(x.id) === l.querySelector('[data-atividade]').value);
        const q = Math.max(0, Number(l.querySelector('[data-quantidade]').value) || 0);
        if (a) { xp += a.xp * q; credito += a.credito * q; }
    });
    const fator = especial ? 1 : 0.5;
    const sx = Math.floor(xp * fator), sc = Math.floor(credito * fator);
    let dias = 7;
    if (especial) {
        const ini = form.querySelector('[name="dataInicio"]').value, fim = form.querySelector('[name="dataFim"]').value;
        dias = ini && fim ? Math.round((new Date(`${fim}T00:00:00`) - new Date(`${ini}T00:00:00`)) / 86400000) + 1 : 0;
    }
    form.querySelectorAll('[data-quantidade]').forEach((i) => { if (dias > 0) i.max = dias; else i.removeAttribute('max'); });
    const limite = dias > 0 ? ` Cada meta vai até ${dias} (1 registro por atividade por dia${especial ? `; a missão dura ${dias} ${dias === 1 ? 'dia' : 'dias'}` : ''}).` : ' Informe o fim para ver o limite das metas.';
    form.querySelector('[data-sugestao]').innerHTML = `Recompensa sugerida (${especial ? '100%' : '50%'} das recompensas das atividades exigidas): `
        + `<strong>${sx} XP e ${sc} créditos</strong> <button type="button" class="link-btn" data-usar-sugestao data-xp="${sx}" data-credito="${sc}">usar</button>.${escapeHTML(limite)}`;
}

/** Lê o formulário aberto e devolve o objeto enviado à API. */
function lerFormulario(form, tipo) {
    const d = Object.fromEntries(new FormData(form).entries());
    d.ativa = form.querySelector('[name="ativa"]')?.checked ?? true;
    if (tipo === 'atividade') {
        d.aceitaFoto = form.querySelector('[name="aceitaFoto"]').checked;
        d.aceitaParceiro = form.querySelector('[name="aceitaParceiro"]').checked;
    }
    if (st.editando?.id) d.id = st.editando.id;
    if (tipo === 'quiz') {
        d.perguntas = [...form.querySelectorAll('.pergunta-bloco')].map((p) => ({
            id: Number(p.dataset.id) || undefined,
            enunciado: p.querySelector('[data-enunciado]').value,
            fonteTitulo: p.querySelector('[data-fonte-url]')?.dataset.fonteTitulo || undefined,
            fonteUrl: p.querySelector('[data-fonte-url]')?.dataset.fonteUrl || undefined,
            alternativas: [...p.querySelectorAll('.alt-item')].map((a) => ({
                id: Number(a.dataset.id) || undefined,
                texto: a.querySelector('[data-texto]').value,
                explicacao: a.querySelector('[data-explicacao]').value,
                correta: a.querySelector('[data-correta]').checked,
            })),
        }));
    }
    if (tipo === 'construcao') {
        d.desenho = form.querySelector('[name="desenho"]')?.value || st.editando?.desenho || 'personalizado';
        d.aparencia = d.desenho === 'personalizado' ? (st.editando?.aparencia || st.dados.aparenciaPadrao) : null;
    }
    if (tipo === 'missao') {
        d.metas = [...form.querySelectorAll('.meta-linha')].map((m) => ({ atividade: m.querySelector('[data-atividade]').value, quantidade: m.querySelector('[data-quantidade]').value }));
    }
    if (tipo === 'promocao') {
        d.codigo = form.querySelector('[name="codigo"]')?.value || '';
        d.xp = form.querySelector('[name="xp"]')?.value ?? '0';
        d.credito = form.querySelector('[name="credito"]')?.value ?? '0';
        d.dataInicio = form.querySelector('[name="dataInicio"]')?.value || '';
        d.somenteNovos = form.querySelector('[name="somenteNovos"]')?.checked ?? false;
        d.indicadores = Object.fromEntries([...form.querySelectorAll('[data-promo-ind]')].map((inp) => [inp.dataset.promoInd, inp.value === '' ? '0' : inp.value]));
    }
    return d;
}

/* ---------------------------------------------------------------------------
   Listas
   --------------------------------------------------------------------------- */

const COLUNAS = {
    atividade: [['Atividade', (a) => escapeHTML(a.nome)], ['Área', (a) => nomeCategoria(a.categoria)], ['Recompensa', (a) => `${a.xp} XP · ${a.credito} cr · +${a.impacto}`],
        ['Impacto real', (a) => a.impactoRealQuantidade !== null ? `${String(a.impactoRealQuantidade).replace('.', ',')} ${escapeHTML(a.impactoRealUnidade || '')}` : '—'],
        ['Comprovação', (a) => `${a.aceitaFoto ? 'Com ou sem foto' : '<span class="tag tag-neutral">Só sem foto · 100%</span>'} ${a.aceitaParceiro ? '<span class="tag tag-success">Parceiro</span>' : '<span class="tag tag-neutral">Só jogador</span>'}`]],
    quiz: [['Quiz', (q) => `${escapeHTML(q.titulo)}${q.origem === 'ia' ? ' <span class="tag tag-ia" title="Gerado pela IA a partir de notícias">IA</span>' : ''}`], ['Tipo', (q) => escapeHTML(st.dados.tiposQuiz[q.tipo]?.nome ?? q.tipo)], ['Tema', (q) => nomeCategoria(q.categoria)], ['Dificuldade', (q) => escapeHTML(q.dificuldade || '')], ['Perguntas', (q) => q.perguntas.length]],
    missao: [['Missão', (m) => `${escapeHTML(m.nome)}${m.origem === 'ia' ? ' <span class="tag tag-ia" title="Gerada pela IA">IA</span>' : ''}`],
        ['Tipo', (m) => {
            const fmt = (d) => d?.split('-').reverse().slice(0, 2).join('/') || '';
            if (m.tipo === 1) return m.dataFim ? `Semanal (${fmt(m.dataInicio)} a ${fmt(m.dataFim)})` : 'Semanal';
            return `Especial até ${m.dataFim?.split('-').reverse().join('/')}`;
        }],
        ['Metas', (m) => m.metas.length],
        ['Recompensa', (m) => `${m.xp} XP · ${m.credito} cr · ${m.tipo === 1 ? `−${m.penalidade}` : "sem penalidade"}`]],
    conquista: [['Conquista', (c) => escapeHTML(c.nome)], ['Critério', (c) => {
        const base = NOMES_CRITERIO[c.criterio] || c.criterio;
        if (c.criterio === 'impacto_atividade' && c.atividade) {
            const atv = (st.dados.atividades || []).find((a) => a.id === c.atividade);
            return `${escapeHTML(atv ? `${atv.nome} (${atv.impactoRealUnidade || ''})` : `Atividade #${c.atividade}`)} ≥ ${c.meta}`;
        }
        const areaTxt = c.indicador ? ` (${nomeCategoria(st.dados.categorias.find((k) => k.indicadorId === c.indicador)?.id)})` : '';
        return `${base}${areaTxt} ≥ ${c.meta}`;
    }],
        ['Recompensa', (c) => `${c.xp} XP · ${c.credito} cr`]],
    construcao: [['Estrutura', (e) => escapeHTML(e.nome)], ['Categoria', (e) => escapeHTML(st.dados.categoriasConstrucao[e.categoria] ?? e.categoria)],
        ['Efeito', (e) => (e.efeito ? `${e.efeito === 'polui' ? 'Polui' : 'Compensa'} · ${nomeCategoria(e.area)}` : '—')], ['Tamanho', (e) => `${e.largura}×${e.profundidade}`], ['Custo', (e) => `${e.custo} cr`], ['Nível', (e) => e.nivel],
        ['Na cidade', (e) => [e.moradores && `+${e.moradores} moradores`, e.carros && `+${e.carros} carros`, e.bicicletas && `+${e.bicicletas} bicicletas`,
            e.onibus && `+${e.onibus} ônibus`, e.renda_diaria && `${e.renda_diaria} cr/dia`].filter(Boolean).join(' · ') || '—'],
        ['Desenho', (e) => (e.desenho === 'personalizado' ? (e.aparencia?.pecas || e.aparencia?.blocos || e.aparencia?.elementos ? '<span class="tag tag-ia">Gerado por IA</span>' : 'Personalizado') : escapeHTML(st.dados.desenhos[e.desenho] ?? 'Genérico'))]],
    cmissao: [['Missão', (m) => `${escapeHTML(m.nome)}${m.origem === 'ia' ? ' <span class="tag tag-ia" title="Gerada pela IA">IA</span>' : ''}`],
        ['Atividade', (m) => escapeHTML(st.dados.atividades.find((a) => a.id === m.atividade)?.nome || '—')],
        ['Meta', (m) => {
            const fmt = (d) => d?.split('-').reverse().slice(0, 2).join('/') || '';
            const per = m.dataFim ? ` <span class="muted">(${fmt(m.dataInicio)} a ${fmt(m.dataFim)})</span>` : '';
            return `${String(m.meta).replace('.', ',')} ${escapeHTML(m.unidade || '')}${per}`;
        }],
        ['Recompensa', (m) => `${m.xp} XP · ${m.credito} cr · ${m.pontos} pts`]],
    promocao: [
        ['Código', (p) => `<code>${escapeHTML(p.codigo)}</code>`],
        ['Campanha', (p) => `${escapeHTML(p.nome)}${p.somenteNovos ? ' <span class="tag tag-neutral">Só novas contas</span>' : ''}`],
        ['Validade', (p) => `${fmtDataBR(p.dataInicio)} a ${fmtDataBR(p.dataFim)}`],
        ['Recompensas', (p) => [
            p.xp > 0 ? `${p.xp} XP` : '',
            p.credito > 0 ? `${p.credito.toLocaleString('pt-BR')} cr` : '',
            resumoIndicadoresPromo(p.indicadores),
        ].filter(Boolean).join(' · ') || '—'],
        ['Resgates', (p) => (p.limiteUsos ? `${p.resgates} / ${p.limiteUsos}` : String(p.resgates))],
    ],
};

function listaConteudo(tipo) {
    const itens = st.dados[LISTA[tipo]];
    if (!itens.length) return '<p class="empty-state">Nenhum item cadastrado.</p>';
    return `<div class="table-scroll"><table class="data-table">
        <thead><tr>${COLUNAS[tipo].map(([t]) => `<th scope="col">${t}</th>`).join('')}<th scope="col">Situação</th><th scope="col">Ações</th></tr></thead>
        <tbody>${itens.map((it) => {
            const sitTag = tipo === 'promocao'
                ? (() => {
                    const [cls, lbl] = SITUACAO_PROMO[it.situacao] || ['tag-neutral', it.situacao];
                    return `<span class="tag ${cls}">${lbl}</span>`;
                })()
                : tagAtivo(it.ativa);
            const filtroAttr = tipo === 'promocao'
                ? `${it.situacao}${it.usado ? ' usado' : ''}`
                : `${it.ativa ? 'ativo' : 'desativado'}${it.usado ? ' usado' : ''}${it.origem === 'ia' ? ' ia' : ''}${it.origem === 'ia' && !it.ativa && !it.usado ? ' rascunho' : ''}`;
            const buscaTxt = `${it.codigo || ''} ${it.nome || it.titulo || ''} ${it.descricao || ''}`;
            return `<tr class="${it.ativa ? '' : 'is-off'}" data-busca="${escapeHTML(buscaTxt)}" data-filtro="${filtroAttr}">
            ${COLUNAS[tipo].map(([, f]) => `<td>${f(it)}</td>`).join('')}
            <td>${sitTag}${it.usado ? ' <span class="tag tag-warning" title="Usado por jogadores">Em uso</span>' : ''}</td>
            <td class="acoes">
                <button type="button" class="btn btn-outline btn-sm" data-editar="${it.id}">Editar</button>
                <button type="button" class="btn btn-outline btn-sm" data-ativo="${it.id}" data-valor="${it.ativa ? 0 : 1}">${it.ativa ? 'Desativar' : 'Ativar'}</button>
                ${it.usado ? '' : `<button type="button" class="btn btn-danger-outline btn-sm" data-excluir="${it.id}">Excluir</button>`}
            </td></tr>`;
        }).join('')}</tbody></table></div>`;
}

const fmtDataHoraBR = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const fmtNum = (n) => Number(n ?? 0).toLocaleString('pt-BR');
const OP_SEG_NOME = {
    bloquear: 'Conta bloqueada',
    desbloquear: 'Conta desbloqueada',
    promover: 'Promovida a admin',
    alterar_perfil: 'Perfil admin alterado',
    rebaixar: 'Admin removido',
    encerrar_sessoes: 'Sessões encerradas',
    liberar_login: 'Trava de login liberada',
    redefinir_senha: 'Link de senha gerado',
};
const PERFIL_ROTULO_PADRAO = {
    master: 'Admin Master',
    moderador: 'Moderador',
    editor: 'Editor de Conteúdo',
    parceiros: 'Gestor de Parceiros',
    analista: 'Analista / Auditor',
};
function nomePerfilAdminJS(chave) {
    if (!chave) return 'Administrador';
    return st.dados?.perfisAdmin?.find((p) => p.chave === chave)?.nome
        || PERFIL_ROTULO_PADRAO[chave]
        || chave;
}

function resumoAjusteHistorico(h) {
    if (h.tipo === 'progressao') {
        const p = [];
        if (h.deltaCredito) p.push(`${h.deltaCredito > 0 ? '+' : '−'}${fmtNum(Math.abs(h.deltaCredito))} cr`);
        if (h.deltaXp) p.push(`+${fmtNum(h.deltaXp)} XP`);
        if (h.indicadores) {
            Object.entries(h.indicadores).forEach(([indId, d]) => {
                p.push(`${escapeHTML(nomeCurtoIndicador(indId))} ${d > 0 ? '+' : '−'}${Math.abs(d)}`);
            });
        }
        return p.join(' · ') || 'Progressão';
    }
    if (h.tipo === 'perfil' && h.detalhes) {
        const p = [];
        if (h.detalhes.apelido_depois) p.push(`Apelido: "${escapeHTML(h.detalhes.apelido_antes)}" → "${escapeHTML(h.detalhes.apelido_depois)}"`);
        if (h.detalhes.cidade_depois) p.push(`Cidade: "${escapeHTML(h.detalhes.cidade_antes)}" → "${escapeHTML(h.detalhes.cidade_depois)}"`);
        return p.join(' · ') || 'Perfil';
    }
    if (h.tipo === 'seguranca' && h.detalhes) {
        const base = OP_SEG_NOME[h.detalhes.operacao] || escapeHTML(h.detalhes.operacao || 'Segurança');
        if (h.detalhes.operacao === 'promover' && h.detalhes.perfil_depois) {
            return `${base} (${escapeHTML(nomePerfilAdminJS(h.detalhes.perfil_depois))})`;
        }
        if (h.detalhes.operacao === 'alterar_perfil' && h.detalhes.perfil_depois) {
            return `${escapeHTML(nomePerfilAdminJS(h.detalhes.perfil_antes))} → ${escapeHTML(nomePerfilAdminJS(h.detalhes.perfil_depois))}`;
        }
        if (h.detalhes.operacao === 'rebaixar' && h.detalhes.perfil_antes) {
            return `${base} (era ${escapeHTML(nomePerfilAdminJS(h.detalhes.perfil_antes))})`;
        }
        return base;
    }
    return 'Ajuste';
}

function tagsSituacaoConta(u) {
    const proprio = u.id === st.dados?.usuarioAtualId;
    const tags = [
        u.ativa
            ? '<span class="tag tag-success">Ativa</span>'
            : u.desativadaPeloJogador
                ? '<span class="tag tag-neutral">Desativada pelo jogador</span>'
                : '<span class="tag tag-error">Bloqueada</span>',
    ];
    if (u.admin) {
        const cls = u.adminPerfil === 'master' ? 'tag-master' : 'tag-warning';
        tags.push(`<span class="tag ${cls}">${escapeHTML(u.adminPerfilNome || 'Administrador')}</span>`);
    }
    if (proprio) tags.push('<span class="tag tag-neutral">Sua conta</span>');
    if (u.demonstracao) tags.push('<span class="tag tag-neutral">Demo</span>');
    if (u.loginTravado || u.travaLogin?.travadoAte) tags.push('<span class="tag tag-error">Login travado</span>');
    return tags.join(' ');
}

function guiaPerfisAdmin() {
    const perfis = st.dados?.perfisAdmin || [];
    if (!perfis.length) return '';
    return `<details class="perfis-guia">
        <summary>Perfis administrativos e níveis de acesso (${perfis.length})</summary>
        <ul class="perfis-guia-lista">
            ${perfis.map((p) => `<li><strong>${escapeHTML(p.nome)}:</strong> ${escapeHTML(p.resumo)}</li>`).join('')}
        </ul>
    </details>`;
}

function listaContas() {
    return `${guiaPerfisAdmin()}<div class="table-scroll"><table class="data-table">
        <thead><tr><th scope="col">Conta</th><th scope="col">E-mail</th><th scope="col">Progresso</th><th scope="col">Cadastro</th><th scope="col">Situação</th><th scope="col">Ações</th></tr></thead>
        <tbody>${st.dados.contas.map((u) => {
            const estado = u.ativa ? 'ativa' : (u.desativadaPeloJogador ? 'desativada' : 'bloqueada');
            const filtros = `${estado}${u.admin ? ' admin' : ''}${u.demonstracao ? ' demo' : ' real'}${u.loginTravado ? ' travado' : ''}`;
            const sub = u.temJogador
                ? `@${escapeHTML(u.apelido || '—')} · <code>${escapeHTML(u.codigoPublico || '')}</code>`
                : '<span class="muted">Sem perfil de jogador</span>';
            const prog = u.temJogador
                ? `Nível ${u.nivelJogador} · ${fmtNum(u.creditosVerde)} cr · Cidade nv ${u.nivelCidade ?? 1}`
                : '<span class="muted">—</span>';
            return `<tr class="${u.ativa ? '' : 'is-off'}" data-busca="${escapeHTML(`${u.nome} ${u.apelido || ''} ${u.email} ${u.codigoPublico || ''} ${u.adminPerfilNome || ''}`)}" data-filtro="${filtros}">
            <td><strong>${escapeHTML(u.nome)}</strong><br><span class="kpi-sub">${sub}</span></td>
            <td class="conta-email-cell">${escapeHTML(u.email)}</td>
            <td class="conta-prog-cell">${prog}</td>
            <td>${fmtDataBR(u.cadastro)}</td>
            <td><div class="conta-tags">${tagsSituacaoConta(u)}</div></td>
            <td class="acoes">
                <button type="button" class="btn btn-primary btn-sm" data-conta-abrir="${u.id}">Gerenciar</button>
            </td></tr>`;
        }).join('')}</tbody></table></div>`;
}

function atualizarPreviasConta(painel) {
    if (!painel || !st.contaDetalhe) return;
    const c = st.contaDetalhe;
    const credInp = painel.querySelector('#conta-delta-credito');
    const credPrev = painel.querySelector('#conta-previa-credito');
    if (credInp && credPrev) {
        const d = Math.trunc(Number(credInp.value) || 0);
        const novo = (c.creditosVerde ?? 0) + d;
        credPrev.textContent = `Atual: ${fmtNum(c.creditosVerde)} → Novo: ${fmtNum(novo)}`;
        credPrev.className = novo < 0 ? 'form-hint is-danger' : 'form-hint';
    }
    const xpInp = painel.querySelector('#conta-delta-xp');
    const xpPrev = painel.querySelector('#conta-previa-xp');
    if (xpInp && xpPrev) {
        const d = Math.max(0, Math.trunc(Number(xpInp.value) || 0));
        xpPrev.textContent = `Atual: ${fmtNum(c.xpAtual)} XP → Novo: ${fmtNum((c.xpAtual ?? 0) + d)} XP`;
    }
    painel.querySelectorAll('[data-conta-ind]').forEach((inp) => {
        const atual = Number(inp.dataset.atual || 0);
        const min = Number(inp.dataset.min || 0);
        const max = Number(inp.dataset.max || 100);
        const d = Math.trunc(Number(inp.value) || 0);
        const novo = Math.max(min, Math.min(max, atual + d));
        const out = painel.querySelector(`[data-conta-ind-previa="${inp.dataset.contaInd}"]`);
        if (out) out.textContent = `${atual} → ${novo}/100`;
    });
}

function painelConta() {
    if (st.contaCarregando) {
        return `<div class="card conteudo-form conta-painel" id="conta-painel"><p class="empty-state">Carregando dados da conta…</p></div>`;
    }
    const c = st.contaDetalhe;
    if (!c) return '';

    const proprio = c.id === st.dados?.usuarioAtualId;
    const bloqueadaPorAdmin = !c.ativa && !c.desativadaPeloJogador;
    const trava = c.travaLogin || { travadoAte: null, errosRecentes: 0, ultimoLoginCerto: null };
    const redef = st.linkRedefinicao && st.linkRedefinicao.usuarioId === c.id ? st.linkRedefinicao : null;
    const cats = st.dados?.categorias || [];

    const blocoResumo = c.temJogador ? `
        <div class="conta-kpis">
            <div class="kpi kpi-plain"><span class="kpi-label">Jogador</span><strong>Nível ${c.nivelJogador} · ${fmtNum(c.xpAtual)} XP</strong></div>
            <div class="kpi kpi-plain"><span class="kpi-label">Créditos verdes</span><strong>${fmtNum(c.creditosVerde)}</strong></div>
            <div class="kpi kpi-plain"><span class="kpi-label">Cidade</span><strong>${escapeHTML(c.cidade || '—')} · Nv ${c.nivelCidade ?? 1} (${c.progresso ?? 0}%)</strong></div>
            <div class="kpi kpi-plain"><span class="kpi-label">Ações</span><strong>${c.resumo.registrosAprovados} aprov. · ${c.resumo.registrosPendentes} pend. · ${c.resumo.registrosRejeitados} rej.</strong></div>
            <div class="kpi kpi-plain"><span class="kpi-label">Conquistas / Estruturas</span><strong>${c.resumo.conquistas}/${c.resumo.totalConquistas} · ${c.resumo.construcoes} est.</strong></div>
            <div class="kpi kpi-plain ${c.resumo.denunciasContra > 0 ? 'is-alerta' : ''}"><span class="kpi-label">Denúncias contra</span><strong>${c.resumo.denunciasContra}</strong></div>
        </div>
        <div class="conta-indicadores-resumo">
            ${c.indicadores.map((ind) => `<div class="conta-ind-pill">
                <div class="conta-ind-pill-top"><span><span class="cat-dot cat-${escapeHTML(ind.chave)}"></span>${escapeHTML(ind.nome)}</span><strong>${ind.valor}/100</strong></div>
                <span class="stat-track"><span class="stat-fill fill-${escapeHTML(ind.chave)}" style="width:${ind.valor}%"></span></span>
            </div>`).join('')}
        </div>
        ${c.impacto ? `<div class="conta-impacto-resumo">
            <div class="conta-impacto-head">
                <strong>Impacto ecológico real e evolução</strong>
                <span class="kpi-sub">+${fmtNum(c.impacto.pontosCidade)} pts gerados na cidade · ${c.impacto.habitosPraticados}/${c.impacto.totalCatalogo} hábitos · Comprovação: ${c.impacto.semFoto} rápido, ${c.impacto.comFoto} foto IA, ${c.impacto.parceiro} parceiro${c.impacto.ritmo4Semanas ? ` · Ritmo 4 sem.: ${c.impacto.ritmo4Semanas.acoesAntes} → ${c.impacto.ritmo4Semanas.acoesAgora} ações` : ''}${c.impacto.aprendizado ? ` · Quizzes: ${c.impacto.aprendizado.quizzesConcluidos}/${c.impacto.aprendizado.quizzesDisponiveis} (${c.impacto.aprendizado.acertoAtual !== null ? `${c.impacto.aprendizado.acertoAtual}% acerto` : 'sem nota'}, ${c.impacto.aprendizado.corrigidasTreino}/${c.impacto.aprendizado.erradasPrimeira} erros corrigidos)` : ''}</span>
            </div>
            <div class="conta-impacto-chips">
                ${c.impacto.unidades?.length
                    ? c.impacto.unidades.map((u) => `<span class="tag tag-neutral"><span class="cat-dot cat-${escapeHTML(u.categoria)}"></span><strong>${Number(u.quantidade).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}</strong> ${escapeHTML(u.unidade)}</span>`).join(' ')
                    : '<span class="muted">Nenhum impacto real aprovado ainda.</span>'}
            </div>
        </div>` : ''}` : '<p class="form-hint">Esta conta é apenas administrativa/usuário e não possui cidade nem perfil de jogador.</p>';

    const blocoProgressao = c.temJogador && c.podeAjustarProgressao !== false ? `
        <form class="conta-bloco" id="conta-form-progressao" data-conta-usuario="${c.id}" novalidate>
            <h4>${icon('coin')} Ajustar créditos, XP e indicadores</h4>
            <p class="form-hint">Todo ajuste exige motivo e aparece no Extrato do jogador e na auditoria (RN99).</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Créditos verdes (+ adiciona / − desconta)
                        <input id="conta-delta-credito" class="form-input" type="number" name="deltaCredito" value="0" min="-100000" max="100000" step="1">
                    </label>
                    <small class="form-hint" id="conta-previa-credito">Atual: ${fmtNum(c.creditosVerde)} → Novo: ${fmtNum(c.creditosVerde)}</small>
                </div>
                <div class="form-group">
                    <label>XP (+ somente acréscimo, RN86)
                        <input id="conta-delta-xp" class="form-input" type="number" name="deltaXp" value="0" min="0" max="100000" step="1">
                    </label>
                    <small class="form-hint" id="conta-previa-xp">Atual: ${fmtNum(c.xpAtual)} XP → Novo: ${fmtNum(c.xpAtual)} XP</small>
                </div>
            </div>
            <fieldset class="metas-editor">
                <legend>Indicadores ambientais (−100 a +100, limitado a 0–100)</legend>
                <div class="promo-ind-todos">
                    <input type="number" class="form-input" id="conta-ind-todos-val" min="-100" max="100" value="0" aria-label="Delta para os 5 indicadores">
                    <button type="button" class="btn btn-outline btn-sm" data-conta-ind-todos>Aplicar nas 5 áreas</button>
                </div>
                <div class="promo-ind-grid">
                    ${c.indicadores.map((ind) => `<div class="form-group">
                        <label>${escapeHTML(nomeCurtoIndicador(ind.id))}
                            <input class="form-input" type="number" min="-100" max="100" step="1" value="0" data-conta-ind="${ind.id}" data-atual="${ind.valor}" data-min="${ind.min}" data-max="${ind.max}">
                        </label>
                        <small class="form-hint" data-conta-ind-previa="${ind.id}">${ind.valor} → ${ind.valor}/100</small>
                    </div>`).join('')}
                </div>
            </fieldset>
            <div class="form-group">
                <label>Motivo obrigatório (5 a 250 caracteres)
                    <input class="form-input" type="text" name="motivo" minlength="5" maxlength="250" required placeholder="Ex.: bonificação de evento acadêmico, estorno de bug…">
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" ${c.tabelaPronta ? '' : 'disabled title="Aplique a migração 037 antes"'}>Aplicar ajuste</button>
            </div>
        </form>` : '';

    const blocoModerar = c.temJogador && c.podeModerarPerfil !== false ? `
        <form class="conta-bloco" id="conta-form-perfil" data-conta-usuario="${c.id}" novalidate>
            <h4>${icon('user')} Moderar perfil</h4>
            <p class="form-hint">Altere apelido ou nome da cidade inadequados. O e-mail não pode ser alterado (RN32).</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Apelido (3 a 100 caracteres)
                        <input class="form-input" type="text" name="apelido" minlength="3" maxlength="100" required value="${escapeHTML(c.apelido || '')}">
                    </label>
                </div>
                <div class="form-group">
                    <label>Nome da cidade (3 a 100 caracteres)
                        <input class="form-input" type="text" name="cidade" minlength="3" maxlength="100" required value="${escapeHTML(c.cidade || '')}">
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label>Motivo obrigatório (5 a 250 caracteres)
                    <input class="form-input" type="text" name="motivo" minlength="5" maxlength="250" required placeholder="Ex.: correção de apelido inadequado">
                </label>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-outline" ${c.tabelaPronta ? '' : 'disabled title="Aplique a migração 037 antes"'}>Salvar alterações do perfil</button>
            </div>
        </form>` : '';

    const podeLiberarLogin = trava.travadoAte !== null || trava.errosRecentes > 0;
    const historicoHtml = c.historico.length
        ? `<div class="table-scroll"><table class="data-table">
            <thead><tr><th scope="col">Data</th><th scope="col">Admin</th><th scope="col">Tipo</th><th scope="col">Alteração</th><th scope="col">Motivo</th></tr></thead>
            <tbody>${c.historico.map((h) => `<tr>
                <td>${fmtDataHoraBR(h.criadoEm)}</td>
                <td>${escapeHTML(h.adminNome)}</td>
                <td><span class="tag tag-neutral">${({ progressao: 'Progressão', perfil: 'Perfil', seguranca: 'Segurança' }[h.tipo] || h.tipo)}</span></td>
                <td class="wrap">${resumoAjusteHistorico(h)}</td>
                <td class="wrap">${escapeHTML(h.motivo)}</td>
            </tr>`).join('')}</tbody></table></div>`
        : '<p class="muted">Nenhum ajuste administrativo registrado para esta conta.</p>';

    const perfisLista = st.dados?.perfisAdmin || [];
    const perfilSel = c.admin ? (c.adminPerfil || 'moderador') : 'moderador';
    const descPerfilSel = perfisLista.find((p) => p.chave === perfilSel)?.resumo || '';
    const blocoAdminPerfil = c.podeGerenciarAdmin && perfisLista.length ? `
        <div class="conta-admin-box">
            <label for="conta-admin-perfil">Perfil administrativo (RN102)</label>
            <div class="conta-admin-row">
                <select id="conta-admin-perfil" class="form-input" ${st.dados?.perfisPronto === false ? 'disabled title="Aplique a migração 041 antes"' : ''}>
                    ${perfisLista.map((p) => `<option value="${escapeHTML(p.chave)}" ${p.chave === perfilSel ? 'selected' : ''}>${escapeHTML(p.nome)}</option>`).join('')}
                </select>
                ${c.admin
                    ? `<button type="button" class="btn btn-primary btn-sm" data-conta="${c.id}" data-op="alterar_perfil" ${proprio || st.dados?.perfisPronto === false ? 'disabled title="Você não pode alterar o próprio perfil administrativo (RN102)"' : ''}>Salvar perfil</button>
                       <button type="button" class="btn btn-danger-outline btn-sm" data-conta="${c.id}" data-op="rebaixar" ${proprio ? 'disabled title="Você não pode remover seu acesso de administrador (RN52)"' : ''}>Remover admin</button>`
                    : `<button type="button" class="btn btn-outline btn-sm" data-conta="${c.id}" data-op="promover">Conceder acesso admin</button>`}
            </div>
            <small class="form-hint" id="conta-admin-perfil-desc">${escapeHTML(descPerfilSel)}</small>
        </div>` : '';

    const blocoSeguranca = c.alvoProtegido
        ? `<div class="conta-bloco conta-protegida-aviso">
            <h4>${icon('lock')} Conta administrativa protegida</h4>
            <p class="form-hint">Esta conta possui o perfil <strong>${escapeHTML(c.adminPerfilNome || 'Administrador')}</strong>. Alterações de perfil, bloqueio, senha ou sessões em contas administrativas são exclusivas de um <strong>Admin Master</strong>.</p>
        </div>`
        : (c.podeSeguranca !== false || c.podeGerenciarAdmin ? `
            <div class="conta-bloco">
                <h4>${icon('lock')} Segurança e acesso</h4>
                <div class="form-group">
                    <label>Motivo para o histórico (opcional nas ações rápidas)
                        <input class="form-input" type="text" id="conta-seguranca-motivo" maxlength="250" placeholder="Opcional (ex.: solicitação de suporte ou violação de regra)">
                    </label>
                </div>
                ${c.podeSeguranca !== false ? `<div class="conta-seg-acoes">
                    <button type="button" class="btn ${c.ativa ? 'btn-danger-outline' : 'btn-primary'} btn-sm" data-conta="${c.id}" data-op="${c.ativa ? 'bloquear' : 'desbloquear'}" ${proprio && c.ativa ? 'disabled title="Você não pode bloquear sua própria conta (RN52)"' : ''}>
                        ${c.ativa ? 'Bloquear conta' : (bloqueadaPorAdmin ? 'Desbloquear conta' : 'Reativar conta')}
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-conta="${c.id}" data-op="encerrar_sessoes">
                        Encerrar todas as sessões
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-conta="${c.id}" data-op="liberar_login" ${podeLiberarLogin ? '' : 'disabled title="Sem erros recentes de login para limpar"'}>
                        Liberar bloqueio de login${trava.errosRecentes ? ` (${trava.errosRecentes})` : ''}
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" data-conta="${c.id}" data-op="redefinir_senha" ${c.ativa ? '' : 'disabled title="Desbloqueie a conta antes de gerar o link"'}>
                        Gerar link de redefinição de senha
                    </button>
                </div>` : ''}
                ${blocoAdminPerfil}
                ${redef ? `<div class="conta-redef-box" id="conta-redef-box" role="status">
                    <strong>Link temporário de redefinição (válido por ${redef.expiraEmMin} min, uso único)</strong>
                    <p class="form-hint">${redef.emailEnviado ? 'E-mail também enviado via SMTP para o usuário.' : 'SMTP não configurado: copie o link abaixo e envie ao usuário por canal seguro.'}</p>
                    <div class="conta-redef-row">
                        <input type="text" class="form-input" id="conta-redef-url" readonly value="${escapeHTML(redef.link)}">
                        <button type="button" class="btn btn-primary btn-sm" data-copiar-redef>Copiar link</button>
                    </div>
                </div>` : ''}
            </div>` : '');

    return `<section class="card conteudo-form conta-painel" id="conta-painel" aria-label="Gerenciar conta">
        <div class="conta-painel-topo">
            <div>
                <h3 class="card-title">${icon('user')} ${escapeHTML(c.nome)} ${c.apelido ? `<span class="muted">(@${escapeHTML(c.apelido)})</span>` : ''}</h3>
                <p class="conta-meta">
                    ${escapeHTML(c.email)}
                    ${c.codigoPublico ? ` · Código <code>${escapeHTML(c.codigoPublico)}</code>` : ''}
                    · Cadastro: ${fmtDataBR(c.cadastro)}
                    · Último login: ${fmtDataHoraBR(trava.ultimoLoginCerto)}
                    ${c.resumo ? ` · Último acesso: ${fmtDataBR(c.resumo.ultimoAcesso)}` : ''}
                    ${c.comunidade ? ` · Comunidade: <strong>${escapeHTML(c.comunidade.nome)}</strong> (${escapeHTML(c.comunidade.papel)})` : ''}
                </p>
                <div class="conta-tags">${tagsSituacaoConta(c)}</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" data-conta-fechar>${icon('close')} Fechar</button>
        </div>

        ${blocoResumo}

        <div class="conta-blocos">
            ${blocoProgressao}
            ${blocoModerar}
            ${blocoSeguranca}
        </div>

        <div class="conta-bloco conta-bloco-hist">
            <h4>${icon('scroll')} Histórico administrativo desta conta</h4>
            ${historicoHtml}
        </div>
    </section>`;
}

function listaComunidades() {
    if (!st.dados.comunidades.length) return '<p class="empty-state">Nenhuma comunidade ativa.</p>';
    return `<div class="table-scroll"><table class="data-table">
        <thead><tr><th scope="col">Comunidade</th><th scope="col">Líder</th><th scope="col">Membros</th><th scope="col">Entrada</th><th scope="col">Pontos (geral)</th><th scope="col">Criada em</th><th scope="col">Ações</th></tr></thead>
        <tbody>${st.dados.comunidades.map((c) => `<tr data-busca="${escapeHTML(`${c.nome} ${c.lider || ''}`)}">
            <td>${escapeHTML(c.nome)}</td><td>${escapeHTML(c.lider || '—')}</td><td>${c.membros}</td>
            <td>${c.entrada === 'aberta' ? 'Aberta' : 'Com aprovação'}</td><td>${c.pontos}${c.posicao ? ` (#${c.posicao})` : ''}</td>
            <td>${c.criadaEm.slice(0, 10).split('-').reverse().join('/')}</td>
            <td class="acoes"><button type="button" class="btn btn-danger-outline btn-sm" data-dissolver="${c.id}">Encerrar</button></td></tr>`).join('')}</tbody></table></div>`;
}

/* ---------------------------------------------------------------------------
   Render
   --------------------------------------------------------------------------- */

function secoesVisiveis() {
    const perm = st.dados?.secoesPermitidas;
    if (!Array.isArray(perm) || !perm.length) return SECOES;
    return SECOES.filter(([id]) => perm.includes(id));
}

function secoesDoModo() {
    const vis = secoesVisiveis();
    if (st.modo === 'catalogo') {
        const cat = vis.filter(([id]) => SECOES_CATALOGO.includes(id));
        return cat.length ? cat : vis;
    }
    const alvo = vis.filter(([id]) => id === st.modo);
    return alvo.length ? alvo : vis;
}

function render() {
    const visiveis = secoesDoModo();
    if (!visiveis.length) {
        st.raiz.innerHTML = '<div class="card"><p class="empty-state">Nenhuma seção habilitada para o seu perfil administrativo.</p></div>';
        return;
    }
    if (!visiveis.some(([id]) => id === st.secao)) {
        st.secao = visiveis[0][0];
        st.editando = null;
        st.contaDetalhe = null;
        st.contaCarregando = false;
        st.linkRedefinicao = null;
    }
    const { secao } = st;
    const mostrarNav = st.modo === 'catalogo' && visiveis.length > 1;
    const formulario = st.editando && !SO_LISTA.includes(secao)
        ? `<form class="card conteudo-form" id="conteudo-form" novalidate>
            <h3 class="card-title">${st.editando.id ? 'Editar' : NOVO[secao]}</h3>
            ${FORM[secao](st.editando)}
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Salvar</button>
                <button type="button" class="btn btn-outline" data-cancelar>Cancelar</button>
            </div>
        </form>` : '';
    st.raiz.innerHTML = `
        ${mostrarNav ? `<div class="conteudo-nav" role="group" aria-label="Tipo de conteúdo">
            ${visiveis.map(([id, nome, ic]) => `<button type="button" class="chip ${id === secao ? 'is-active' : ''}" data-secao="${id}" aria-pressed="${id === secao}">${icon(ic)} ${nome}</button>`).join('')}
        </div>` : ''}
        ${formulario}
        ${secao === 'conta' ? painelConta() : ''}
        <div class="card">
            <div class="conteudo-topo">
                <h3 class="card-title">${visiveis.find(([id]) => id === secao)[1]}</h3>
                <div class="conteudo-topo-acoes">
                ${secao === 'quiz' && !st.editando ? `<button type="button" class="btn btn-outline btn-sm" data-gerar-ia ${st.dados.iaDisponivel ? '' : 'disabled title="IA desligada ou fora do ar"'}>${icon('help')} Gerar quizzes com IA</button>` : ''}
                ${secao === 'missao' && !st.editando ? `<button type="button" class="btn btn-outline btn-sm" data-gerar-missoes-ia="missao" ${st.dados.iaDisponivel ? '' : 'disabled title="IA desligada ou fora do ar"'}>${icon('clipboard')} Gerar missões com IA</button>` : ''}
                ${secao === 'cmissao' && !st.editando ? `<button type="button" class="btn btn-outline btn-sm" data-gerar-missoes-ia="cmissao" ${st.dados.iaDisponivel ? '' : 'disabled title="IA desligada ou fora do ar"'}>${icon('flag')} Gerar missão com IA</button>` : ''}
                ${!SO_LISTA.includes(secao) && !st.editando ? `<button type="button" class="btn btn-primary btn-sm" data-novo>${icon('gift')} ${NOVO[secao]}</button>` : ''}
                </div>
            </div>
            ${secao === 'conta' ? listaContas() : secao === 'comunidade' ? listaComunidades() : secao === 'auditoria' ? '<div id="auditoria-app"><p class="empty-state">Carregando registros de auditoria…</p></div>' : listaConteudo(secao)}
        </div>`;
    hydrateIcons(st.raiz);
    if (secao === 'auditoria') {
        montarAuditoriaAdmin(st.raiz.querySelector('#auditoria-app'));
    } else {
        paginarLista(secao);
    }
    if (st.editando && secao === 'missao') atualizarMissaoForm(st.raiz.querySelector('#conteudo-form'));
    if (st.editando && secao === 'construcao') atualizarDesenhoForm(st.raiz.querySelector('#conteudo-form'));
    if (st.editando) st.raiz.querySelector('#conteudo-form input, #conteudo-form textarea')?.focus();
}

/** Busca, filtros e páginas da lista da subaba (o estado de cada subaba é mantido entre as ações). */
const FILTROS_IA = [['todos', 'Todos'], ['ativo', 'Ativos'], ['desativado', 'Desativados'], ['usado', 'Em uso'], ['rascunho', 'Rascunhos da IA'], ['ia', 'Gerados por IA']];
const FILTROS_LISTA = {
    conta: {
        filtros: [
            ['todos', 'Todas'], ['real', 'Reais'], ['ativa', 'Ativas'],
            ['bloqueada', 'Bloqueadas'], ['desativada', 'Desativadas'],
            ['admin', 'Administradores'], ['travado', 'Login travado'], ['demo', 'Demonstração'],
        ],
        busca: 'Buscar por nome, apelido, e-mail ou código', rotulo: ['conta', 'contas'],
    },
    comunidade: { filtros: [], busca: 'Buscar por nome ou líder', rotulo: ['comunidade', 'comunidades'] },
    quiz: { filtros: FILTROS_IA, busca: 'Buscar pelo nome', rotulo: ['item', 'itens'] },
    missao: { filtros: FILTROS_IA, busca: 'Buscar pelo nome', rotulo: ['missão', 'missões'] },
    cmissao: { filtros: FILTROS_IA, busca: 'Buscar pelo nome', rotulo: ['missão', 'missões'] },
    promocao: {
        filtros: [
            ['todos', 'Todos'], ['ativa', 'Ativos'], ['agendada', 'Agendados'],
            ['expirada', 'Expirados'], ['esgotada', 'Esgotados'], ['desativada', 'Desativados'], ['usado', 'Com resgates'],
        ],
        busca: 'Buscar por código ou campanha', rotulo: ['código', 'códigos'],
    },
};
function paginarLista(secao) {
    const tabela = st.raiz.querySelector('.card:last-child .table-scroll');
    if (!tabela) return;
    const cfg = FILTROS_LISTA[secao] || {
        filtros: [['todos', 'Todos'], ['ativo', 'Ativos'], ['desativado', 'Desativados'], ['usado', 'Em uso']],
        busca: 'Buscar pelo nome', rotulo: ['item', 'itens'],
    };
    listaPaginada({ lista: tabela.querySelector('tbody'), itens: 'tr', bloco: tabela, chave: `conteudo-${secao}`, rotulo: cfg.rotulo, filtros: cfg.filtros, buscaDica: cfg.busca });
}

async function executar(fn) {
    try {
        await fn();
        render();
    } catch (err) {
        toast(err.message, 'error');
        if (!st.dados && st.raiz) {
            st.raiz.innerHTML = `<div class="alert alert-error" role="alert">${escapeHTML(err.message)}</div>`;
        }
    }
}

function ligarEventos() {
    st.raiz.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        const id = Number(b.dataset.editar || b.dataset.ativo || b.dataset.excluir || 0);
        if (b.dataset.secao) {
            st.secao = b.dataset.secao;
            if (SECOES_CATALOGO.includes(b.dataset.secao)) st.ultimaCatalogo = b.dataset.secao;
            st.editando = null;
            st.contaDetalhe = null;
            st.contaCarregando = false;
            st.linkRedefinicao = null;
            render();
            return;
        }
        if (b.dataset.contaAbrir) {
            const usuarioId = b.dataset.contaAbrir;
            st.contaCarregando = true;
            st.linkRedefinicao = null;
            render();
            (async () => {
                try {
                    await api({ acao: 'conta_detalhe', usuario_id: usuarioId });
                } catch (err) {
                    toast(err.message, 'error');
                    st.contaDetalhe = null;
                } finally {
                    st.contaCarregando = false;
                    render();
                    st.raiz.querySelector('#conta-painel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            })();
            return;
        }
        if (b.hasAttribute('data-conta-fechar')) {
            st.contaDetalhe = null;
            st.contaCarregando = false;
            st.linkRedefinicao = null;
            render();
            return;
        }
        if (b.hasAttribute('data-conta-ind-todos')) {
            const form = b.closest('form');
            const valEl = form?.querySelector('#conta-ind-todos-val');
            const v = Math.max(-100, Math.min(100, Math.trunc(Number(valEl?.value) || 0)));
            form?.querySelectorAll('[data-conta-ind]').forEach((el) => { el.value = String(v); });
            atualizarPreviasConta(st.raiz.querySelector('#conta-painel'));
            return;
        }
        if (b.hasAttribute('data-copiar-redef')) {
            const inp = st.raiz.querySelector('#conta-redef-url');
            if (inp) {
                inp.select();
                navigator.clipboard?.writeText(inp.value).catch(() => {});
                toast('Link de redefinição copiado.');
            }
            return;
        }
        if (b.hasAttribute('data-novo')) { st.editando = {}; st.rotacaoPrevia = 0; render(); return; }
        if (b.hasAttribute('data-previa-rotacao')) {
            st.rotacaoPrevia = Number(b.dataset.previaRotacao) || 0;
            atualizarDesenhoForm(b.closest('form'));
            return;
        }
        if (b.hasAttribute('data-limpar-foto-ia')) {
            const form = b.closest('form');
            const inp = form?.querySelector('#ia-estrutura-foto');
            const prev = form?.querySelector('#ia-foto-previa');
            if (inp) inp.value = '';
            if (prev) prev.hidden = true;
            return;
        }
        if (b.hasAttribute('data-ia-estrutura')) {
            const form = b.closest('form');
            const promptEl = form?.querySelector('#ia-estrutura-prompt');
            const fotoEl = form?.querySelector('#ia-estrutura-foto');
            const arquivoFoto = fotoEl?.files?.[0] || null;
            const prompt = promptEl?.value.trim() || '';
            if (prompt.length < 3 && !arquivoFoto) {
                toast('Descreva a estrutura no campo da IA (mínimo 3 caracteres) ou envie uma imagem de referência.', 'error');
                promptEl?.focus();
                return;
            }
            const modo = b.dataset.iaEstrutura;
            const botoesIa = [...form.querySelectorAll('[data-ia-estrutura]')];
            const statusEl = form.querySelector('#ia-estrutura-status');
            botoesIa.forEach((btn) => { btn.disabled = true; });
            if (statusEl) statusEl.textContent = arquivoFoto ? 'Analisando a imagem de referência e modelando em 3D com a IA…' : 'Modelando a estrutura 3D com a IA… aguarde alguns segundos.';
            const contexto = {
                nome: form.querySelector('[name="nome"]')?.value || '',
                largura: Number(form.querySelector('[name="largura"]')?.value) || 3,
                profundidade: Number(form.querySelector('[name="profundidade"]')?.value) || 3,
                categoria: form.querySelector('[name="categoria"]')?.value || 'ecologica',
                aparencia: st.editando?.aparencia || null,
            };
            (async () => {
                try {
                    const temPecasAtuais = Array.isArray(st.editando?.aparencia?.pecas) && st.editando.aparencia.pecas.length > 0;
                    const previaAtual = modo === 'desenho' && temPecasAtuais
                        ? await svgParaPngFile(form.querySelector('[data-previa="3"] svg'))
                        : null;
                    const r = await api({ acao: 'gerar_estrutura_ia', assincrono: '1', modo, prompt, contexto, foto: arquivoFoto, previa_atual: previaAtual });
                    let finalResp = r;
                    if (r.assincrono && r.jobId) {
                        const tIni = Date.now();
                        let concluido = false;
                        for (let tent = 0; tent < 120; tent++) {
                            await new Promise((resolve) => setTimeout(resolve, 2500));
                            const seg = Math.max(1, Math.round((Date.now() - tIni) / 1000));
                            if (statusEl) statusEl.textContent = `Modelando a estrutura 3D com a IA… (${seg}s)`;
                            const poll = await api({ acao: 'gerar_estrutura_ia_status', job_id: r.jobId });
                            if (poll.status !== 'processando') {
                                finalResp = poll;
                                concluido = true;
                                break;
                            }
                        }
                        if (!concluido) throw new Error('Tempo limite excedido ao aguardar a IA gerar a estrutura.');
                    }
                    const res = finalResp.resultado;
                    st.editando.desenho = 'personalizado';
                    st.editando.aparencia = res.aparencia;
                    form.querySelector('[name="desenho"]').value = 'personalizado';
                    if (modo === 'tudo' && res.estrutura) {
                        const est = res.estrutura;
                        ['nome', 'descricao', 'custo', 'nivel', 'categoria', 'efeito', 'area', 'moradores', 'renda_diaria', 'carros', 'bicicletas', 'onibus'].forEach((campoNome) => {
                            const inp = form.querySelector(`[name="${campoNome}"]`);
                            if (inp && est[campoNome] !== undefined) inp.value = est[campoNome];
                        });
                        if (!st.editando.usado) {
                            ['largura', 'profundidade'].forEach((campoTam) => {
                                const inp = form.querySelector(`[name="${campoTam}"]`);
                                if (inp && est[campoTam] !== undefined) inp.value = est[campoTam];
                            });
                        }
                    }
                    atualizarDesenhoForm(form);
                    const modTxt = res.modelo ? ` com ${res.modelo}` : '';
                    if (statusEl) statusEl.textContent = `Pronto (${(res.tempoMs / 1000).toFixed(1)}s${modTxt})! Você pode mandar outro prompt (ou outra imagem) para ajustar o desenho ou editar os campos abaixo.`;
                } catch (err) {
                    toast(err.message, 'error');
                    if (statusEl) statusEl.textContent = err.message;
                } finally {
                    botoesIa.forEach((btn) => { btn.disabled = false; });
                }
            })();
            return;
        }
        if (b.hasAttribute('data-gerar-ia')) {
            if (!confirm('A IA vai ler as notícias ambientais dos últimos 14 dias e criar até 3 quizzes como rascunho (desativados). Leva de 5 a 15 minutos e roda em segundo plano: você pode continuar usando a administração. Continuar?')) return;
            b.disabled = true;
            b.textContent = 'Iniciando…';
            executar(async () => {
                await api({ acao: 'gerar_ia' });
                definirFiltro('conteudo-quiz', 'rascunho');
            });
            return;
        }
        if (b.hasAttribute('data-gerar-missoes-ia')) {
            const escopo = b.dataset.gerarMissoesIa;
            b.disabled = true;
            b.textContent = 'Gerando…';
            executar(async () => {
                await api({ acao: 'gerar_missoes_ia', escopo });
                definirFiltro(`conteudo-${st.secao}`, 'rascunho');
            });
            return;
        }
        if (b.hasAttribute('data-cancelar')) { st.editando = null; render(); return; }
        if (b.dataset.editar) { st.editando = structuredClone(st.dados[LISTA[st.secao]].find((x) => x.id === id)); st.rotacaoPrevia = 0; render(); return; }
        if (b.dataset.ativo) { executar(() => api({ acao: 'ativo', tipo: st.secao, id, ativo: b.dataset.valor })); return; }
        if (b.dataset.excluir) {
            if (confirm('Excluir este item? Esta ação não pode ser desfeita.')) executar(() => api({ acao: 'excluir', tipo: st.secao, id }));
            return;
        }
        if (b.dataset.dissolver) {
            if (confirm('Encerrar esta comunidade? Os membros saem dela e ela some do ranking. Não pode ser desfeito.')) executar(() => api({ acao: 'dissolver', comunidade_id: b.dataset.dissolver }));
            return;
        }
        if (b.dataset.conta) {
            const op = b.dataset.op;
            const perfil = (op === 'promover' || op === 'alterar_perfil')
                ? (st.raiz.querySelector('#conta-admin-perfil')?.value || '')
                : '';
            const nomePerfil = nomePerfilAdminJS(perfil);
            const textos = {
                bloquear: 'Bloquear esta conta e encerrar suas sessões abertas? Ela não conseguirá mais entrar.',
                promover: `Conceder acesso administrativo "${nomePerfil}" para esta conta?`,
                alterar_perfil: `Alterar o perfil administrativo desta conta para "${nomePerfil}"?`,
                rebaixar: 'Remover o acesso de administrador desta conta?',
                encerrar_sessoes: 'Encerrar todas as sessões abertas desta conta?',
                redefinir_senha: 'Gerar um novo link de redefinição de senha (1 h, uso único)? O link anterior, se houver, será invalidado.',
            };
            if (!textos[op] || confirm(textos[op])) {
                const usuarioId = Number(b.dataset.conta);
                const motivo = st.raiz.querySelector('#conta-seguranca-motivo')?.value || '';
                executar(async () => {
                    const res = await api({ acao: 'conta', usuario_id: usuarioId, operacao: op, perfil, motivo });
                    st.linkRedefinicao = res.redefinicao ? { usuarioId, ...res.redefinicao } : null;
                });
            }
            return;
        }
        // Editor de quiz e de metas
        const form = b.closest('form');
        if (b.hasAttribute('data-add-pergunta')) {
            const ed = form.querySelector('.quiz-editor');
            ed.insertAdjacentHTML('beforeend', blocoPergunta(perguntaVazia(), ed.children.length, false));
        }
        if (b.hasAttribute('data-del-pergunta') && form.querySelectorAll('.pergunta-bloco').length > 1) b.closest('.pergunta-bloco').remove();
        if (b.hasAttribute('data-add-alt')) {
            const p = b.closest('.pergunta-bloco');
            const nome = p.querySelector('[data-correta]').name;
            p.querySelector('.alt-lista').insertAdjacentHTML('beforeend', blocoAlternativa({ texto: '', correta: false }, nome, false));
        }
        if (b.hasAttribute('data-del-alt') && b.closest('.alt-lista').children.length > 2) b.closest('.alt-item').remove();
        if (b.hasAttribute('data-add-meta')) form.querySelector('.metas-editor').insertAdjacentHTML('beforeend', blocoMeta());
        if (b.hasAttribute('data-del-meta') && form.querySelectorAll('.meta-linha').length > 1) b.closest('.meta-linha').remove();
        if (b.hasAttribute('data-usar-sugestao')) {
            form.querySelector('[name="xp"]').value = b.dataset.xp;
            form.querySelector('[name="credito"]').value = b.dataset.credito;
        }
        if (b.hasAttribute('data-gerar-codigo-promo')) {
            const inp = form?.querySelector('[name="codigo"]');
            if (inp && !inp.readOnly) {
                inp.value = gerarCodigoPromoAleatorio();
                inp.focus();
            }
        }
        if (b.hasAttribute('data-promo-todos')) {
            const valEl = form?.querySelector('#promo-todos-valor');
            const v = Math.max(0, Math.min(100, Math.trunc(Number(valEl?.value) || 0)));
            form?.querySelectorAll('[data-promo-ind]').forEach((el) => { if (!el.disabled) el.value = String(v); });
        }
        if (st.secao === 'missao' && form && (b.hasAttribute('data-add-meta') || b.hasAttribute('data-del-meta'))) atualizarMissaoForm(form);
    });

    const aoMudar = (e) => {
        if (e.target.id === 'ia-estrutura-foto') {
            const form = e.target.closest('#conteudo-form');
            const prev = form?.querySelector('#ia-foto-previa');
            const img = form?.querySelector('#ia-foto-img');
            const arq = e.target.files?.[0];
            if (arq && prev && img) {
                img.src = URL.createObjectURL(arq);
                prev.hidden = false;
            } else if (prev) {
                prev.hidden = true;
            }
            return;
        }
        if (e.target.id === 'conta-admin-perfil') {
            const descEl = st.raiz.querySelector('#conta-admin-perfil-desc');
            const info = st.dados?.perfisAdmin?.find((p) => p.chave === e.target.value);
            if (descEl) descEl.textContent = info?.resumo || '';
            return;
        }
        const painelContaEl = e.target.closest('#conta-painel');
        if (painelContaEl) {
            atualizarPreviasConta(painelContaEl);
            return;
        }
        const form = e.target.closest('#conteudo-form');
        if (form && st.secao === 'missao') atualizarMissaoForm(form);
        if (form && st.secao === 'construcao') atualizarDesenhoForm(form);
    };
    st.raiz.addEventListener('input', aoMudar);
    st.raiz.addEventListener('change', aoMudar);

    st.raiz.addEventListener('submit', (e) => {
        if (e.target.id === 'aud-filtros' || e.target.closest('#auditoria-app')) return;
        e.preventDefault();
        if (e.target.id === 'conta-form-progressao') {
            const form = e.target;
            const usuarioId = Number(form.dataset.contaUsuario);
            const dados = {
                deltaCredito: form.querySelector('[name="deltaCredito"]')?.value ?? '0',
                deltaXp: form.querySelector('[name="deltaXp"]')?.value ?? '0',
                motivo: form.querySelector('[name="motivo"]')?.value ?? '',
                indicadores: Object.fromEntries(
                    [...form.querySelectorAll('[data-conta-ind]')].map((inp) => [inp.dataset.contaInd, inp.value === '' ? '0' : inp.value])
                ),
            };
            executar(() => api({ acao: 'conta_ajustar', usuario_id: usuarioId, dados }));
            return;
        }
        if (e.target.id === 'conta-form-perfil') {
            const form = e.target;
            const usuarioId = Number(form.dataset.contaUsuario);
            const dados = {
                apelido: form.querySelector('[name="apelido"]')?.value ?? '',
                cidade: form.querySelector('[name="cidade"]')?.value ?? '',
                motivo: form.querySelector('[name="motivo"]')?.value ?? '',
            };
            executar(() => api({ acao: 'conta_moderar', usuario_id: usuarioId, dados }));
            return;
        }
        if (e.target.id !== 'conteudo-form') return;
        const dados = lerFormulario(e.target, st.secao);
        executar(async () => {
            await api({ acao: 'salvar', tipo: st.secao, dados });
            st.editando = null;
        });
    });
}

/** Carrega e exibe a seção de conteúdo solicitada na primeira abertura ou troca pela barra lateral. */
export function initConteudoAdmin(raiz) {
    if (!raiz) return { abrir() {} };
    st.raiz = raiz;
    ligarEventos();
    return {
        abrir(modo = 'catalogo') {
            const novoModo = modo || 'catalogo';
            const proximaSecao = novoModo === 'catalogo' ? st.ultimaCatalogo : novoModo;
            const mudou = novoModo !== st.modo || proximaSecao !== st.secao;
            st.modo = novoModo;
            if (mudou) {
                st.secao = proximaSecao;
                st.editando = null;
                st.contaDetalhe = null;
                st.contaCarregando = false;
                st.linkRedefinicao = null;
            }
            if (!st.dados) {
                if (st.carregando) return;
                st.carregando = true;
                raiz.innerHTML = '<p class="empty-state">Carregando…</p>';
                executar(async () => {
                    try {
                        await api({ acao: 'listar' });
                    } finally {
                        st.carregando = false;
                    }
                });
                return;
            }
            render();
        },
    };
}
