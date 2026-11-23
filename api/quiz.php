<?php
/**
 * API de quizzes do jogador logado.
 *
 * POST acao=iniciar    quiz_id                                → { tentativaId, perguntas (sem gabarito) }
 * POST acao=responder  tentativa_id, pergunta_id, alternativa_id → { correta, alternativaCorreta, explicacao }
 * POST acao=finalizar  tentativa_id                           → { resultado, jogador, quizzes, rotacaoQuiz, tentativas }
 * POST acao=historico  pagina                                 → { tentativas: { itens, total, pagina, porPagina } }
 *
 * Exige sessão e token CSRF (campo "csrf" ou cabeçalho X-CSRF-Token).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['usuarioId' => $usuarioId, 'jogadorId' => $jogadorId] = api_jogador();
$num = static fn(string $campo): int => (int) ($_POST[$campo] ?? 0);

try {
    switch ($_POST['acao'] ?? '') {
        case 'iniciar':
            json_resposta(['ok' => true] + iniciar_tentativa($jogadorId, $num('quiz_id')));

        case 'responder':
            json_resposta(['ok' => true] + responder_pergunta($jogadorId, $num('tentativa_id'), $num('pergunta_id'), $num('alternativa_id')));

        case 'finalizar':
            $resultado = finalizar_tentativa($jogadorId, $num('tentativa_id'));
            $quizzes = quizzes_do_jogador($jogadorId);
            json_resposta([
                'ok'         => true,
                'resultado'  => $resultado,
                'jogador'    => carregar_jogador($usuarioId),
                'quizzes'    => $quizzes['quizzes'],
                'rotacaoQuiz' => $quizzes['rotacao'],
                'tentativas' => listar_tentativas($jogadorId),
                'conquistas' => listar_conquistas($jogadorId),
                'perfil'     => perfil_completo($usuarioId, $jogadorId),
            ]);

        case 'historico':
            json_resposta(['ok' => true, 'tentativas' => listar_tentativas($jogadorId, max(1, $num('pagina')))]);

        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/quiz: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}
