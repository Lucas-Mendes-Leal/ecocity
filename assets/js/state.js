/**
 * Estado do jogo no navegador.
 *
 * Fonte da verdade: o servidor (PHP/MySQL). O estado inicial vem embutido em
 * jogo.php (#dados-iniciais) e cada ação (registro, quiz) volta com os dados
 * atualizados da API.
 */
import { CATEGORIAS, INDICADOR_MIN, INDICADOR_MAX, estagioPorPontuacao } from './data/catalog.js';
import { tocar } from './som.js';

const inicial = JSON.parse(document.getElementById('dados-iniciais').textContent);
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const listeners = new Set();

export const state = {
    jogador: inicial.jogador,
    atividades: inicial.atividades,
    registros: inicial.registros,
    niveis: inicial.niveis,
    regras: inicial.regras,
    aprovacaoTeste: inicial.aprovacaoTeste,
    quizzes: inicial.quizzes,
    rotacaoQuiz: inicial.rotacaoQuiz,
    social: inicial.social,
    comunidade: inicial.comunidade || {},
    parceiros: inicial.parceiros || [],
    avisosComunidade: inicial.avisosComunidade || [],   // missões da comunidade concluídas (aviso único)  // estabelecimentos parceiros aprovados (RN69)
    tentativas: inicial.tentativas,   // tentativas concluídas
    conquistas: inicial.conquistas,   // conquistas com progresso
    perfil: inicial.perfil,           // dados da conta, impacto, estatísticas e extrato
    missoes: inicial.missoes,         // missões do período atual + resultados recentes
    avisosMissoes: inicial.avisosMissoes, // resultados processados ao abrir o jogo
    construcao: inicial.construcao,   // catálogo, lotes disponíveis e estruturas colocadas
    versoes: inicial.versoes || {},   // versões conhecidas por domínio (sincronização, RN85)
};

/* ---------------------------------------------------------------------------
   Assinatura de mudanças
   --------------------------------------------------------------------------- */

export function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

function emit(evento = { tipo: 'atualizacao' }) {
    listeners.forEach((fn) => fn(state, evento));
}

/* ---------------------------------------------------------------------------
   Leituras derivadas
   --------------------------------------------------------------------------- */

/** Progresso ecológico geral: média dos 5 indicadores. */
export function progressoGeral() {
    const valores = CATEGORIAS.map((c) => state.jogador.indicadores[c.id] ?? 0);
    return Math.round(valores.reduce((a, b) => a + b, 0) / valores.length);
}

export function nivelCidade() {
    return estagioPorPontuacao(progressoGeral());
}

export function estagioIndicador(indicador) {
    return estagioPorPontuacao(state.jogador.indicadores[indicador] ?? 0);
}

export const atividadePorId = (id) => state.atividades.find((a) => a.id === id);
export const quizPorId = (id) => state.quizzes.find((q) => q.id === id);

/** Faixa de XP do nível atual: { min, proximo } (proximo = XP do próximo nível). */
export function faixaNivel() {
    const n = state.niveis.find((x) => x.nivel === state.jogador.nivel);
    return n ? { min: n.min, proximo: n.max + 1 } : { min: 0, proximo: 100 };
}

/* ---------------------------------------------------------------------------
   Comunicação com a API
   --------------------------------------------------------------------------- */

/** POST para a API com o token CSRF; devolve o JSON ou lança Error com a mensagem do servidor. */
export async function chamarApi(url, campos) {
    const corpo = new FormData();
    Object.entries(campos).forEach(([k, v]) => { if (v !== undefined && v !== null) corpo.append(k, v); });

    let resposta;
    try {
        resposta = await fetch(url, {
            method: 'POST', body: corpo, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF },
        });
    } catch {
        throw new Error('Sem conexão com o servidor. Tente novamente.');
    }
    if (resposta.status === 401) {
        window.location.href = 'login.php';
        throw new Error('Sessão encerrada.');
    }
    const dados = await resposta.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!dados.ok) throw Object.assign(new Error(dados.erro || 'Não foi possível concluir.'), { status: resposta.status, dados });
    if (!url.includes('sincronizar')) document.dispatchEvent(new Event('ecocity:acao'));   // sincroniza logo depois de uma ação
    return dados;
}

/**
 * Aplica os dados devolvidos pelo servidor (só os campos presentes) e avisa os ouvintes.
 * @param {object} dados resposta da API
 * @param {string} tipo  tipo do evento emitido
 */
function aplicarResposta(dados, tipo = 'servidor') {
    const nivelAntes = state.jogador.nivel;
    const cidadeAntes = nivelCidade();
    const conquistasAntes = new Set(state.conquistas.filter((c) => c.obtida).map((c) => c.id));

    const missoesAntes = new Set(state.missoes.atuais.filter((m) => m.concluida).map((m) => m.id));

    ['jogador', 'registros', 'quizzes', 'rotacaoQuiz', 'tentativas', 'conquistas', 'perfil', 'missoes', 'construcao', 'avisosComunidade'].forEach((campo) => {
        if (dados[campo] !== undefined) state[campo] = dados[campo];
    });

    emit({
        tipo,
        mensagem: dados.mensagem,
        subiuNivel: state.jogador.nivel > nivelAntes,
        cidadeEvoluiu: nivelCidade() > cidadeAntes,
        novasConquistas: state.conquistas.filter((c) => c.obtida && !conquistasAntes.has(c.id)).map((c) => c.nome),
        missoesConcluidas: state.missoes.atuais.filter((m) => m.concluida && !missoesAntes.has(m.id)).map((m) => m.nome),
    });
}

/**
 * Aplica o que a sincronização trouxe (RN85). Só campos que realmente mudaram; o jogo é redesenhado
 * (e os avisos de nível, conquista e missão aparecem) apenas se um campo dele mudou. Amigos e comunidade
 * recebem o evento 'ecocity:sincronia' para atualizar as próprias telas.
 */
export function aplicarSincronia({ versoes, mudou, dados }) {
    state.versoes = versoes;
    const diferente = (campo) => dados[campo] !== undefined && JSON.stringify(dados[campo]) !== JSON.stringify(state[campo]);
    const doJogo = ['jogador', 'registros', 'quizzes', 'rotacaoQuiz', 'tentativas', 'conquistas', 'perfil', 'missoes', 'construcao', 'atividades', 'avisosComunidade'].filter(diferente);
    const extras = ['social', 'comunidade'].filter(diferente);
    if (doJogo.length) {
        if (doJogo.includes('atividades')) state.atividades = dados.atividades;
        aplicarResposta(Object.fromEntries(doJogo.map((c) => [c, dados[c]])), 'sincronia');
    }
    extras.forEach((c) => { state[c] = dados[c]; });
    if (mudou.length) document.dispatchEvent(new CustomEvent('ecocity:sincronia', { detail: { mudou, dados } }));
}

/** Aplica jogador/perfil devolvidos por APIs de outras abas (ex.: créditos gastos ao criar comunidade). */
export function aplicarDadosServidor(dados) {
    aplicarResposta({ jogador: dados.jogador, perfil: dados.perfil }, 'comunidade');
}

/* ---------------------------------------------------------------------------
   Ações
   --------------------------------------------------------------------------- */

/**
 * Registra uma atividade no servidor.
 * tipo 1 = autodeclarada (aprovada na hora); tipo 2 = com evidência (foto obrigatória).
 */
export async function registrarAtividade(atividadeId, tipo, descricao, foto) {
    const r = await chamarApi('api/registros.php', { acao: 'criar', atividade_id: atividadeId, tipo, descricao: descricao.trim(), foto });
    if (r.analisar) analisando.add(r.registroId);
    aplicarResposta(r);
    if (r.analisar) analisarFoto(r.registroId);
}

/** Registros com foto sendo analisados pela IA agora (RN91), para a tela mostrar "Analisando com IA…". */
export const analisando = new Set();

/** Pede a análise da foto em segundo plano; o resultado chega com o estado novo (ou pela sincronização). */
async function analisarFoto(registroId) {
    let r = null;
    try {
        r = await chamarApi('api/registros.php', { acao: 'analisar', registro_id: registroId });
    } catch { /* a análise é retomada pela sincronização */ }
    analisando.delete(registroId);
    if (r?.parecer?.acao === 'aprovou') tocar('ia-aprovou');
    else if (r?.parecer?.acao === 'rejeitou') tocar('ia-rejeitou');
    if (r) aplicarResposta(r); else emit({ tipo: 'servidor' });
}

/** Contesta um registro com foto rejeitado (RN94): explicação e, se quiser, outra foto. */
export async function contestarRegistro(registroId, texto, foto) {
    aplicarResposta(await chamarApi('api/registros.php', { acao: 'contestar', registro_id: registroId, texto: texto.trim(), foto }));
}

/** SOMENTE TESTE: aprova/rejeita um registro pendente (servidor exige debug). */
/** Código de 6 dígitos do parceiro (RN71): registro verificado por parceiro, aprovado na hora. */
export async function validarCodigoParceiro(codigo) {
    aplicarResposta(await chamarApi('api/registros.php', { acao: 'codigo', codigo }));
}

export async function decidirRegistroTeste(registroId, acao) {
    aplicarResposta(await chamarApi('api/registros.php', { acao, registro_id: registroId }));
}

/** Inicia uma tentativa: devolve { tentativaId, perguntas } (sem gabarito). */
export function iniciarQuiz(quizId) {
    return chamarApi('api/quiz.php', { acao: 'iniciar', quiz_id: quizId });
}

/** Responde uma pergunta: devolve { correta, alternativaCorreta, explicacao }. */
export function responderQuiz(tentativaId, perguntaId, alternativaId) {
    return chamarApi('api/quiz.php', { acao: 'responder', tentativa_id: tentativaId, pergunta_id: perguntaId, alternativa_id: alternativaId });
}

/**
 * Conclui a tentativa no servidor. Recompensa só na primeira tentativa concluída (RN21).
 * Devolve { acertos, total, pontuacao, recompensado, recompensa }.
 */
export async function finalizarQuiz(tentativaId) {
    const dados = await chamarApi('api/quiz.php', { acao: 'finalizar', tentativa_id: tentativaId });
    aplicarResposta(dados, 'tentativa');
    return dados.resultado;
}

/** Atualiza apelido e nome da cidade. */
export async function atualizarPerfil(apelido, cidade) {
    aplicarResposta(await chamarApi('api/perfil.php', { acao: 'atualizar', apelido: apelido.trim(), cidade: cidade.trim() }));
}

/** Avatar, privacidade e sessão (RN93): o servidor devolve jogador e perfil atualizados. */
export async function acaoConta(dados) {
    aplicarResposta(await chamarApi('api/perfil.php', dados));
}

/** Troca a senha (exige a senha atual). */
export async function trocarSenha(atual, nova, confirmar) {
    aplicarResposta(await chamarApi('api/perfil.php', { acao: 'senha', senha_atual: atual, senha_nova: nova, senha_confirmar: confirmar }));
}

/** Resgata um código promocional (RN98): atualiza saldo, XP, níveis, indicadores, conquistas e construções. */
export async function resgatarCodigoPromocional(codigo) {
    const r = await chamarApi('api/perfil.php', { acao: 'resgatar_codigo', codigo: codigo.trim() });
    aplicarResposta(r);
    return r;
}

/** Coloca uma estrutura num lote de expansão (custo debitado no servidor). */
export async function colocarConstrucao(construcaoId, lote, x, y, rotacao) {
    aplicarResposta(await chamarApi('api/construcoes.php', { acao: 'colocar', construcao_id: construcaoId, lote, x, y, rotacao }));
}

/** Coleta a renda acumulada do comércio (RN79); o aviso vem da mensagem do servidor. */
export async function coletarRenda() {
    aplicarResposta(await chamarApi('api/construcoes.php', { acao: 'coletar' }));
}

/** Remove uma estrutura (sem devolução de créditos). */
export async function removerConstrucao(id) {
    aplicarResposta(await chamarApi('api/construcoes.php', { acao: 'remover', id }));
}

/** DEMONSTRAÇÃO (?demo=1): define todos os indicadores só na tela, sem salvar. */
export function demoDefinirIndicadores(valor) {
    const v = Math.max(INDICADOR_MIN, Math.min(INDICADOR_MAX, valor));
    CATEGORIAS.forEach((c) => { state.jogador.indicadores[c.id] = v; });
    emit({ tipo: 'demo' });
}

export function iniciarEstado() {
    emit({ tipo: 'inicio' });
}
