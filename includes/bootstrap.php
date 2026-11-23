<?php
/**
 * Inicialização comum a todas as páginas: configuração, sessão e helpers.
 */
declare(strict_types=1);

const ECOCITY_ROOT = __DIR__ . '/..';

$configFile = ECOCITY_ROOT . '/config/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuração ausente: copie config/config.example.php para config/config.local.php e preencha a senha do MySQL.');
}

/** @var array{db: array<string, mixed>, debug: bool} */
$GLOBALS['ecocity_config'] = require $configFile;

function config(string $key)
{
    return $GLOBALS['ecocity_config'][$key] ?? null;
}

// Mesmo fuso do MySQL/Windows (datas de NOW() são interpretadas corretamente pelo PHP)
date_default_timezone_set('America/Sao_Paulo');

ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);

/**
 * Atrás do Cloudflare Tunnel (config 'proxy_confiavel'), o PHP recebe HTTP do container do túnel:
 * o protocolo e o IP do visitante vêm dos cabeçalhos da Cloudflare, aceitos só de endereço de rede privada.
 */
function atras_do_proxy(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return (bool) config('proxy_confiavel')
        && filter_var($ip, FILTER_VALIDATE_IP) !== false
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

function pedido_https(): bool
{
    return !empty($_SERVER['HTTPS']) || (atras_do_proxy() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/* Sessão: cookie só via HTTP, mesmo site, sem ID na URL */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ECOCITYSESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => pedido_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/** Nonce único por requisição para scripts inline autorizados na CSP. */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self'; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "worker-src 'self'; "
        . "manifest-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );
    if (pedido_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    // No Docker o Apache envia esses cabeçalhos com Header always set; evita duplicá-los lá.
    if (empty($_SERVER['ECOCITY_APACHE_HEADERS'])) {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }
}

/** Escapa texto para HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/seguranca.php';
require_once __DIR__ . '/conta.php';
require_once __DIR__ . '/registros.php';
require_once __DIR__ . '/ia.php';
require_once __DIR__ . '/contestacoes.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/conteudo.php';
require_once __DIR__ . '/amigos.php';
require_once __DIR__ . '/comunidades.php';
require_once __DIR__ . '/parceiros.php';
require_once __DIR__ . '/parceiro-gestao.php';
require_once __DIR__ . '/quizzes.php';
require_once __DIR__ . '/quizzes-ia.php';
require_once __DIR__ . '/conquistas.php';
require_once __DIR__ . '/promocoes.php';
require_once __DIR__ . '/contas-admin.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/perfil.php';
require_once __DIR__ . '/missoes.php';
require_once __DIR__ . '/cidade.php';
require_once __DIR__ . '/construcoes.php';
require_once __DIR__ . '/construcoes-ia.php';
require_once __DIR__ . '/denuncias-ia.php';
require_once __DIR__ . '/missoes-ia.php';
require_once __DIR__ . '/chat-ia.php';
require_once __DIR__ . '/estatisticas.php';
require_once __DIR__ . '/sincronia.php';

/**
 * Verificações comuns da pasta api/: POST, sessão, CSRF (campo "csrf" ou cabeçalho X-CSRF-Token)
 * e jogador existente. Encerra com JSON de erro se algo falhar.
 * @return array{usuarioId: int, jogadorId: int}
 */
function api_jogador(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
    }
    $usuarioId = usuario_id();
    if ($usuarioId === null) {
        json_resposta(['ok' => false, 'erro' => 'Sua sessão terminou. Entre novamente.'], 401);
    }
    if (!isset($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $_POST['csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (!csrf_valido()) {
        json_resposta(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], 403);
    }
    $jogador = jogador_do_usuario($usuarioId);
    if (!$jogador) {
        json_resposta(['ok' => false, 'erro' => 'Jogador não encontrado.'], 404);
    }
    registrar_acesso((int) $jogador['jogador_id']);   // jogador ativo hoje (RN81)
    aplicar_queda_diaria(db(), (int) $jogador['jogador_id']);   // dias pendentes, inclusive após a meia-noite
    return ['usuarioId' => $usuarioId, 'jogadorId' => (int) $jogador['jogador_id']];
}

/** Responde JSON e encerra (usado pela pasta api/). */
function json_resposta(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Responde JSON imediatamente ao cliente e libera o PHP para continuar em segundo plano. */
function responder_e_continuar(array $dados, int $tempoLimite = 120): void
{
    session_write_close();
    $corpo = json_encode($dados, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    set_time_limit($tempoLimite);
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($corpo));
    header('Connection: close');
    echo $corpo;
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}
