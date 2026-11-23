<?php
/**
 * EcoCity — geração semanal de quizzes pela IA (RN91). Só linha de comando.
 *
 * Lê as notícias ambientais recentes (RSS), a IA cria até 3 quizzes temáticos e eles ficam DESATIVADOS
 * (rascunho) na aba Conteúdo › Quizzes, para o administrador revisar e ativar.
 *
 *   Docker:  docker exec ecocity-web-1 php /var/www/html/database/projeto/gerar-quizzes-ia.php
 *   Wamp:    php database/projeto/gerar-quizzes-ia.php
 *
 * Agendada no Windows (tarefa "EcoCity - Quizzes IA", segunda às 6h) pelo docker/agendar-quizzes-ia.ps1.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../../includes/bootstrap.php';

try {
    $r = gerar_quizzes_ia();
    echo date('d/m/Y H:i'), " — notícias disponíveis: {$r['noticias']}\n";
    foreach ($r['criados'] as $q) echo "  rascunho criado: #{$q['id']} {$q['titulo']}\n";
    foreach ($r['erros'] as $e) echo "  problema: {$e}\n";
    exit($r['criados'] ? 0 : 1);
} catch (Throwable $ex) {
    fwrite(STDERR, date('d/m/Y H:i') . ' — ' . $ex->getMessage() . "\n");
    exit(2);
}
