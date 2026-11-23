<?php
/**
 * EcoCity — geração semanal de missões e missões de comunidade pela IA (RN91 / RN67). Só linha de comando.
 *
 * Gera 2 missões semanais extras e 1 missão oficial de comunidade para a semana atual (segunda a domingo)
 * como RASCUNHO (desativadas), usando apenas as atividades cadastradas no banco (sem internet).
 *
 *   Docker:  docker exec ecocity-web-1 php /var/www/html/database/projeto/gerar-missoes-ia.php
 *   Wamp:    php database/projeto/gerar-missoes-ia.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../../includes/bootstrap.php';

try {
    $r = gerar_missoes_ia('todas', true);
    echo date('d/m/Y H:i'), " — semana {$r['semana']}\n";
    foreach ($r['criadas'] as $m) {
        echo "  rascunho criado ({$m['escopo']}): #{$m['id']} {$m['nome']}\n";
    }
    foreach ($r['erros'] as $e) echo "  problema: {$e}\n";
    exit($r['erros'] && !$r['criadas'] ? 1 : 0);
} catch (Throwable $ex) {
    fwrite(STDERR, date('d/m/Y H:i') . ' — ' . $ex->getMessage() . "\n");
    exit(2);
}
