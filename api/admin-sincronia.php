<?php
/**
 * Novidades da administração (RN85): GET → { ok, versao, pendentes, denuncias, parceiros }.
 * Só administradores ativos; somente leitura. Consultado a cada 10 s pela página admin.php.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
$admin = exigir_admin_api();
session_write_close();

try {
    if (admin_tem_permissao($admin, 'contestacoes')) {
        aprovar_vencidos(null);   // a fila não guarda registros que já venceram o prazo
        ia_analisar_fila(null);   // IA retoma fotos sem análise (RN91)
    }
    if (admin_tem_permissao($admin, 'denuncias')) {
        ia_analisar_fila_denuncias();   // IA retoma denúncias sem análise (RN58/RN91)
    }
    json_resposta(['ok' => true] + sincronia_admin($admin));
} catch (Throwable $ex) {
    error_log('EcoCity — api/admin-sincronia: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível atualizar agora.'], 500);
}
