<?php
/**
 * Estatísticas da administração (RN81–RN83). Só administradores ativos; somente leitura.
 *
 * GET secao (resumo|aprendizado|acoes|engajamento|cidade|pratica), periodo (7|30|90|0 = tudo)
 *     → { ok, secao, periodo, dados }
 * GET ... &formato=csv → arquivo CSV (UTF-8 com BOM, separador ";") só com números somados,
 *     sem nome, e-mail, apelido ou código público.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
exigir_admin_api('visao_geral');
session_write_close();

$secao = (string) ($_GET['secao'] ?? '');
$periodo = (int) ($_GET['periodo'] ?? 30);

try {
    if (($_GET['formato'] ?? '') === 'csv') {
        $tabelas = estatistica_csv($secao, $periodo);
        $nome = 'ecocity-' . $secao . '-' . ($periodo ?: 'tudo') . ($periodo ? 'dias' : '') . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map('csv_celula_segura', ['EcoCity — estatísticas: ' . $secao]), ';');
        fputcsv($out, array_map('csv_celula_segura', ['Período', $periodo ? "últimos $periodo dias (desde " . date('d/m/Y', strtotime(inicio_periodo($periodo))) . ')' : 'desde o início']), ';');
        fputcsv($out, ['Gerado em', date('d/m/Y H:i')], ';');
        fputcsv($out, ['Somente números somados; recortes com menos de ' . MIN_GRUPO . ' jogadores ficam em branco.'], ';');
        foreach ($tabelas as $titulo => [$cabecalho, $linhas]) {
            fputcsv($out, [], ';');
            fputcsv($out, [csv_celula_segura($titulo)], ';');
            fputcsv($out, array_map('csv_celula_segura', $cabecalho), ';');
            foreach ($linhas as $l) {
                fputcsv($out, array_map(static fn($x) => is_float($x) ? str_replace('.', ',', (string) $x) : csv_celula_segura($x), $l), ';');
            }
        }
        exit;
    }
    json_resposta(['ok' => true, 'secao' => $secao, 'periodo' => $periodo, 'dados' => estatistica_secao($secao, $periodo)]);
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/estatisticas: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível carregar as estatísticas.'], 500);
}
