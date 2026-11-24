<?php
/**
 * Sincronização em tempo real do jogo (RN85).
 *
 * POST versoes (JSON {dominio: versão} recebido antes) → { ok, versoes, mudou: [dominios], dados: {campos de state.js} }
 * Chamado pelo jogo a cada 4 s com a aba visível. Também aplica as regras que dependem do tempo
 * (queda diária via api_jogador, aprovação automática em 24 h e, no máximo 1 vez por minuto,
 * o encerramento das missões e as conquistas), sem precisar de agendador.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['usuarioId' => $usuarioId, 'jogadorId' => $jogadorId] = api_jogador();

// Libera a sessão: a consulta periódica não pode travar as outras requisições do mesmo jogador
$avaliar = time() - (int) ($_SESSION['sinc_avaliado'] ?? 0) >= SINC_AVALIAR_SEGUNDOS;
if ($avaliar) {
    $_SESSION['sinc_avaliado'] = time();
}
session_write_close();

$conhecidas = json_decode(is_string($_POST['versoes'] ?? null) ? $_POST['versoes'] : '{}', true);
if (!is_array($conhecidas)) {
    $conhecidas = [];
}

try {
    aprovar_vencidos($jogadorId);
    if ($avaliar) {
        ia_analisar_fila($jogadorId);   // no máximo 1 por minuto (RN91)
        ativar_conteudo_ia_vencido();   // ativa conteúdos da IA com prazo de revisão vencido (RN91)
        sincronizar_cidade(db(), $jogadorId);
        avaliar_missoes(db(), $jogadorId);
        avaliar_conquistas(db(), $jogadorId);
    }
    json_resposta(['ok' => true] + sincronizar_jogador($usuarioId, $jogadorId, $conhecidas));
} catch (Throwable $ex) {
    error_log('EcoCity — api/sincronizar: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível atualizar agora.'], 500);
}
