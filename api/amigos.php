<?php
/**
 * API de amigos do jogador logado (RN55–RN59). Só POST, com sessão e token CSRF.
 *
 * acao=buscar      termo                 → { resultados }
 * acao=pedir       jogador_id            → { mensagem, amigos }
 * acao=responder   jogador_id, aceitar   → { mensagem, amigos }
 * acao=cancelar | desfazer | bloquear | desbloquear   jogador_id → { mensagem, amigos }
 * acao=listar                            → { amigos, resumo }
 * acao=resumo                            → { resumo }
 * acao=conversa    jogador_id, desde_id  → { amigo, mensagens, resumo }
 * acao=enviar      jogador_id, texto     → { mensagem: {...} }
 * acao=denunciar   mensagem_id, motivo   → { mensagem }
 * acao=visitar     jogador_id            → { visita }
 * acao=sugestoes                         → { sugestoes }
 * acao=dispensar   jogador_id            → { mensagem, sugestoes }
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['jogadorId' => $eu] = api_jogador();
$num = static fn(string $campo): int => (int) ($_POST[$campo] ?? 0);
$txt = static fn(string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
$comLista = static fn(string $msg): array => ['ok' => true, 'mensagem' => $msg, 'amigos' => listar_amigos($eu), 'resumo' => resumo_social($eu)];

try {
    switch ($_POST['acao'] ?? '') {
        case 'buscar':
            json_resposta(['ok' => true, 'resultados' => buscar_jogadores($eu, $txt('termo'))]);
        case 'pedir':
            json_resposta($comLista(pedir_amizade($eu, $num('jogador_id'))) + ['sugestoes' => sugerir_amigos($eu)]);
        case 'responder':
            json_resposta($comLista(responder_pedido($eu, $num('jogador_id'), ($_POST['aceitar'] ?? '') === '1')));
        case 'cancelar':
            json_resposta($comLista(cancelar_pedido($eu, $num('jogador_id'))));
        case 'desfazer':
            json_resposta($comLista(desfazer_amizade($eu, $num('jogador_id'))));
        case 'bloquear':
            json_resposta($comLista(bloquear_jogador($eu, $num('jogador_id'))));
        case 'desbloquear':
            json_resposta($comLista(desbloquear_jogador($eu, $num('jogador_id'))));
        case 'listar':
            json_resposta(['ok' => true, 'amigos' => listar_amigos($eu), 'resumo' => resumo_social($eu)]);
        case 'resumo':
            json_resposta(['ok' => true, 'resumo' => resumo_social($eu)]);
        case 'conversa':
            json_resposta(['ok' => true] + conversa($eu, $num('jogador_id'), $num('desde_id')) + ['resumo' => resumo_social($eu)]);
        case 'enviar':
            json_resposta(['ok' => true, 'mensagem' => enviar_mensagem($eu, $num('jogador_id'), $txt('texto'))]);
        case 'denunciar':
            $denunciaId = null;
            $msg = denunciar_mensagem($eu, $num('mensagem_id'), $txt('motivo'), $denunciaId);
            if ($denunciaId && denuncia_ia_banco_pronto() && ia_disponivel()) {
                responder_e_continuar(['ok' => true, 'mensagem' => $msg], 90);
                try {
                    analisar_denuncia_ia('amigos', $denunciaId);
                } catch (Throwable $ex) {
                    error_log('EcoCity — analisar denúncia amigos: ' . $ex->getMessage());
                }
                exit;
            }
            json_resposta(['ok' => true, 'mensagem' => $msg]);
        case 'visitar':
            json_resposta(['ok' => true, 'visita' => visitar_cidade($eu, $num('jogador_id'))]);
        case 'sugestoes':
            json_resposta(['ok' => true, 'sugestoes' => sugerir_amigos($eu)]);
        case 'dispensar':
            json_resposta(['ok' => true, 'mensagem' => dispensar_sugestao($eu, $num('jogador_id')), 'sugestoes' => sugerir_amigos($eu)]);
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/amigos: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}
