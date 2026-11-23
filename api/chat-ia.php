<?php
/**
 * API do chatbot EcoGuia (RN97, 19/11/2026). Só POST, com sessão e token CSRF.
 *
 * acao=carregar        -> { ok, mensagens, cota }
 * acao=enviar   texto  -> { ok, mensagens, cota }
 * acao=limpar          -> { ok, mensagens, cota }
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['jogadorId' => $jogadorId] = api_jogador();
$acao = (string) ($_POST['acao'] ?? '');

try {
    switch ($acao) {
        case 'carregar':
            json_resposta(['ok' => true] + chat_ia_listar($jogadorId));
        case 'enviar':
            $texto = is_string($_POST['texto'] ?? null) ? $_POST['texto'] : '';
            json_resposta(chat_ia_enviar($jogadorId, $texto));
        case 'limpar':
            chat_ia_limpar($jogadorId);
            json_resposta(['ok' => true] + chat_ia_listar($jogadorId));
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage(), 'cota' => chat_ia_cota($jogadorId)], 422);
} catch (ChatIaLimite $ex) {
    json_resposta([
        'ok' => false, 'erro' => $ex->getMessage(),
        'esperaSeg' => $ex->esperaSeg, 'cota' => chat_ia_cota($jogadorId),
    ], 429);
} catch (IaIndisponivel $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage(), 'cota' => chat_ia_cota($jogadorId)], 503);
}
