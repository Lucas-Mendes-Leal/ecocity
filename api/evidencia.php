<?php
/**
 * Entrega a foto de evidência de um registro — ao dono do registro ou a um administrador ativo.
 * GET ?registro={id}      — a foto do registro
 * GET ?contestacao={id}   — a foto nova enviada na contestação (RN94)
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$usuarioId = usuario_id();
if ($usuarioId === null) {
    http_response_code(401);
    exit;
}

$ehAdmin = admin_tem_permissao($usuarioId, 'contestacoes');
if (isset($_GET['contestacao'])) {
    $stmt = db()->prepare(
        'SELECT c.arquivo FROM contestacao c JOIN jogador j ON j.jogador_id = c.jogador_id
          WHERE c.contestacao_id = ? AND (j.usuario_id = ? OR ?) AND c.arquivo IS NOT NULL LIMIT 1'
    );
    $stmt->execute([(int) $_GET['contestacao'], $usuarioId, (int) $ehAdmin]);
} else {
    $stmt = db()->prepare(
        'SELECT e.arquivo FROM evidencia_atividade e
           JOIN registro_atividade r ON r.registro_atividade_id = e.registro_atividade_id
           JOIN jogador j ON j.jogador_id = r.jogador_id
          WHERE e.registro_atividade_id = ? AND (j.usuario_id = ? OR ?) AND e.arquivo IS NOT NULL
          LIMIT 1'
    );
    $stmt->execute([(int) ($_GET['registro'] ?? 0), $usuarioId, (int) $ehAdmin]);
}
$arquivo = $stmt->fetchColumn();

// Nome gerado pelo sistema: só hexadecimal + extensão conhecida
if (!$arquivo || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $arquivo)) {
    http_response_code(404);
    exit;
}
$caminho = PASTA_EVIDENCIAS . '/' . $arquivo;
if (!is_file($caminho)) {
    http_response_code(404);
    exit;
}

$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($arquivo, PATHINFO_EXTENSION)];
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($caminho));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($caminho);
