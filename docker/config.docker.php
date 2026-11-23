<?php
/**
 * Configuração do EcoCity no Docker. Montada no container como config/config.local.php
 * (o config.local.php do Wamp não é usado). Todos os valores vêm do docker/.env.
 */
$env = static fn(string $nome, string $padrao = ''): string => (string) (getenv($nome) !== false ? getenv($nome) : $padrao);

return [
    'db' => [
        'host'    => 'db',
        'port'    => 3306,
        'name'    => $env('DB_NAME', 'ecocity'),
        'user'    => $env('DB_USER', 'ecocity'),
        'pass'    => $env('DB_PASSWORD'),
        'charset' => 'utf8mb4',
    ],
    'debug' => $env('DEBUG', '0') === '1',
    'proxy_confiavel' => true,   // atrás do Cloudflare Tunnel
    'url_base' => rtrim($env('URL_BASE', 'http://localhost:8080'), '/'),
    'ia' => [
        'ativo'            => $env('IA_ATIVA', '1') === '1',
        'url'              => rtrim($env('IA_URL', 'http://ollama:11434'), '/'),
        'modelo'           => $env('IA_MODELO', 'qwen3-vl:8b-instruct'),
        'confianca_minima' => 0.8,
        'tempo_limite'     => 90,
    ],
    'smtp' => [
        'host'           => $env('SMTP_HOST'),
        'porta'          => (int) $env('SMTP_PORTA', '587'),
        'seguranca'      => $env('SMTP_SEGURANCA', 'tls'),
        'usuario'        => $env('SMTP_USUARIO'),
        'senha'          => $env('SMTP_SENHA'),
        'remetente'      => $env('SMTP_REMETENTE'),
        'nome_remetente' => $env('SMTP_NOME', 'EcoCity'),
    ],
];
