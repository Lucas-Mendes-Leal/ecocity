<?php
/**
 * EcoCity — exporta a base rotulada das fotos (RN91) para um ajuste fino futuro do modelo. Só linha de comando.
 *
 * Cada linha: arquivo da foto (em uploads/evidencias), atividade, veredito e certeza da IA, decisão final
 * (aprovado/rejeitado/pendente) e quem decidiu (ia, administrador, automático). Sem nome, e-mail, apelido ou
 * descrição do jogador (anônimo). CSV UTF-8 com BOM, separador ";".
 *
 *   php database/projeto/exportar-base-ia.php > base-ia.csv
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../../includes/bootstrap.php';

$linhas = db()->query(
    "SELECT e.arquivo, a.nome AS atividade, ae.veredito, ae.confianca, ae.acao, ae.descricao_foto, ae.modelo,
            r.status, r.aprovado_por_administrador_id IS NOT NULL AS por_admin, ae.data
       FROM analise_evidencia ae
       JOIN registro_atividade r ON r.registro_atividade_id = ae.registro_atividade_id
       JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
       JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
   ORDER BY ae.data"
)->fetchAll();

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['arquivo', 'atividade', 'veredito_ia', 'certeza_ia', 'acao_ia', 'descricao_ia', 'modelo', 'decisao_final', 'decidido_por', 'data'], ';');
foreach ($linhas as $l) {
    $quem = $l['status'] === 'pendente' ? '' : ($l['por_admin'] ? 'administrador' : ($l['acao'] === 'aprovou' ? 'ia' : 'automatico'));
    fputcsv($out, [$l['arquivo'], $l['atividade'], $l['veredito'], str_replace('.', ',', $l['confianca']), $l['acao'],
        $l['descricao_foto'], $l['modelo'], $l['status'], $quem, $l['data']], ';');
}
fwrite(STDERR, count($linhas) . " fotos exportadas\n");
