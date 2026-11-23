<?php
/**
 * Consulta e exportação CSV do log detalhado de auditoria do banco (RN101).
 * Acesso restrito a administradores ativos; aceita apenas GET.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
exigir_admin_api('auditoria');
session_write_close();

try {
    $acao = (string) ($_GET['acao'] ?? 'listar');
    switch ($acao) {
        case 'listar':
            json_resposta(['ok' => true] + consultar_auditoria($_GET));
        case 'csv':
            $csv = csv_auditoria($_GET);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="ecocity-auditoria-' . date('Y-m-d') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            echo $csv;
            exit;
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (Throwable $ex) {
    error_log('EcoCity — api/auditoria: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível carregar o log de auditoria.'], 500);
}
