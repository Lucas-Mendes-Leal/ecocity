<?php
/**
 * API de construção nos lotes de expansão.
 *
 * POST acao=colocar  construcao_id, lote (2–4), x, y (0–19), rotacao (0–3: 0°, 90°, 180°, 270°)
 * POST acao=remover  id (cidade_construcao_id)
 * POST acao=coletar  renda acumulada do comércio (RN79)
 *
 * Exige sessão e token CSRF. Resposta: { ok, mensagem, jogador, construcao, conquistas, perfil }.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['usuarioId' => $usuarioId, 'jogadorId' => $jogadorId] = api_jogador();
$num = static fn(string $campo): int => (int) ($_POST[$campo] ?? 0);

try {
    switch ($_POST['acao'] ?? '') {
        case 'colocar':
            $nome = colocar_construcao($jogadorId, $num('construcao_id'), $num('lote'), $num('x'), $num('y'), $num('rotacao'));
            $mensagem = $nome . ' construída!';
            break;

        case 'remover':
            $nome = remover_construcao($jogadorId, $num('id'));
            $mensagem = $nome . ' removida.';
            break;

        case 'coletar':
            $mensagem = 'Renda do comércio coletada: +' . coletar_renda($jogadorId) . ' créditos!';
            break;

        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/construcoes: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}

json_resposta([
    'ok'         => true,
    'mensagem'   => $mensagem,
    'jogador'    => carregar_jogador($usuarioId),
    'construcao' => dados_construcao($jogadorId),
    'conquistas' => listar_conquistas($jogadorId),
    'perfil'     => perfil_completo($usuarioId, $jogadorId),
]);
