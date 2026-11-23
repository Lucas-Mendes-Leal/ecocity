<?php
/**
 * API de comunidades do jogador logado (RN61–RN68). Só POST, com sessão e token CSRF.
 *
 * acao=dados                                     → { dados }
 * acao=resumo                                    → { resumo, avisos }
 * acao=explorar   termo                          → { comunidades }
 * acao=criar | editar   nome, descricao, icone, entrada → { mensagem, dados }
 * acao=entrar | cancelar   comunidade_id         → { mensagem, dados }
 * acao=responder  jogador_id, aceitar            → { mensagem, dados }
 * acao=convidar   jogador_id                     → { mensagem, dados }
 * acao=sair | dissolver                          → { mensagem, dados }
 * acao=remover | lideranca   jogador_id          → { mensagem, dados }
 * acao=papel      jogador_id, papel              → { mensagem, dados }
 * acao=conversa   desde_id                       → { mensagens, resumo }
 * acao=enviar     texto                          → { mensagem: {...} }
 * acao=denunciar  tipo, alvo_id, motivo          → { mensagem }
 * acao=topico     titulo, texto, data_evento, local, fixado → { mensagem, dados }
 * acao=fixar      topico_id, fixar               → { mensagem, dados }
 * acao=apagar_topico | presenca   topico_id (presenca: vou) → { mensagem, dados }
 * acao=meta       nome, atividade_id, meta, dias → { mensagem, dados }
 * acao=remover_meta   meta_id                    → { mensagem, dados }
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['jogadorId' => $eu, 'usuarioId' => $usuarioId] = api_jogador();
$num = static fn(string $campo): int => (int) ($_POST[$campo] ?? 0);
$txt = static fn(string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
$comDados = static fn(string $msg): array => ['ok' => true, 'mensagem' => $msg, 'dados' => dados_comunidade($eu)];

try {
    switch ($_POST['acao'] ?? '') {
        case 'dados':
            json_resposta(['ok' => true, 'dados' => dados_comunidade($eu)]);
        case 'resumo':
            json_resposta(['ok' => true, 'resumo' => resumo_comunidade($eu), 'avisos' => avisos_missoes_comunidade($eu)]);
        case 'explorar':
            json_resposta(['ok' => true, 'comunidades' => explorar_comunidades($eu, $txt('termo'))]);
        case 'criar':   // créditos debitados: devolve também jogador e perfil (extrato)
            json_resposta($comDados(criar_comunidade($eu, $_POST)) + ['jogador' => carregar_jogador($usuarioId), 'perfil' => perfil_completo($usuarioId, $eu)]);
        case 'editar':
            json_resposta($comDados(editar_comunidade($eu, $_POST)));
        case 'entrar':
            json_resposta($comDados(entrar_comunidade($eu, $num('comunidade_id'))));
        case 'cancelar':
            json_resposta($comDados(cancelar_pedido_comunidade($eu, $num('comunidade_id'))));
        case 'responder':
            json_resposta($comDados(responder_pedido_comunidade($eu, $num('jogador_id'), ($_POST['aceitar'] ?? '') === '1')));
        case 'convidar':
            json_resposta($comDados(convidar_para_comunidade($eu, $num('jogador_id'))));
        case 'sair':
            json_resposta($comDados(sair_comunidade($eu)));
        case 'dissolver':
            json_resposta($comDados(dissolver_comunidade($eu)));
        case 'remover':
            json_resposta($comDados(remover_membro($eu, $num('jogador_id'))));
        case 'lideranca':
            json_resposta($comDados(passar_lideranca($eu, $num('jogador_id'))));
        case 'papel':
            json_resposta($comDados(mudar_papel($eu, $num('jogador_id'), $txt('papel'))));
        case 'conversa':
            json_resposta(['ok' => true, 'mensagens' => conversa_comunidade($eu, $num('desde_id')), 'resumo' => resumo_comunidade($eu)]);
        case 'enviar':
            json_resposta(['ok' => true, 'mensagem' => enviar_mensagem_comunidade($eu, $txt('texto'))]);
        case 'denunciar':
            $denunciaId = null;
            $msg = denunciar_comunidade($eu, $txt('tipo'), $num('alvo_id'), $txt('motivo'), $denunciaId);
            if ($denunciaId && denuncia_ia_banco_pronto() && ia_disponivel()) {
                responder_e_continuar(['ok' => true, 'mensagem' => $msg], 90);
                try {
                    analisar_denuncia_ia('comunidade', $denunciaId);
                } catch (Throwable $ex) {
                    error_log('EcoCity — analisar denúncia comunidade: ' . $ex->getMessage());
                }
                exit;
            }
            json_resposta(['ok' => true, 'mensagem' => $msg]);
        case 'topico':
            json_resposta($comDados(publicar_topico($eu, $_POST)));
        case 'fixar':
            json_resposta($comDados(fixar_topico($eu, $num('topico_id'), ($_POST['fixar'] ?? '') === '1')));
        case 'apagar_topico':
            json_resposta($comDados(remover_topico($eu, $num('topico_id'))));
        case 'presenca':
            json_resposta($comDados(marcar_presenca($eu, $num('topico_id'), ($_POST['vou'] ?? '') === '1')));
        case 'meta':
            json_resposta($comDados(criar_meta_interna($eu, $_POST)));
        case 'remover_meta':
            json_resposta($comDados(remover_meta_interna($eu, $num('meta_id'))));
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/comunidades: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}
