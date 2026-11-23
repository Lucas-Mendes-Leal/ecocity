<?php
/**
 * Listas paginadas da administração (somente leitura, só administradores ativos).
 *
 * GET acao=decisoes&pagina=N&por=10|20|50&situacao=todas|aprovado|rejeitado
 *   → { ok, itens, total, pagina, porPagina, situacao, contagem }
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
exigir_admin_api('contestacoes');
session_write_close();

try {
    switch ((string) ($_GET['acao'] ?? '')) {
        case 'decisoes':
            json_resposta(['ok' => true] + listar_decisoes(
                (int) ($_GET['pagina'] ?? 1),
                (int) ($_GET['por'] ?? 20),
                (string) ($_GET['situacao'] ?? 'todas')
            ));
            break;
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/admin-listas: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível carregar a lista.'], 500);
}
