<?php
/**
 * Conexão única com o MySQL via PDO.
 */
declare(strict_types=1);

function db_instancia(?PDO $definir = null): ?PDO
{
    static $pdo = null;
    if ($definir !== null) {
        $pdo = $definir;
    }
    return $pdo;
}

/** Rótulo curto da rota/operação atual para registrar na coluna `auditoria.origem`. */
function auditoria_origem_atual(): string
{
    if (PHP_SAPI === 'cli') {
        $script = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? 'cli'));
        return mb_substr('CLI: ' . ($script !== '' ? $script : 'script'), 0, 120);
    }
    $metodo = strtoupper(preg_replace('/[^A-Z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));
    $caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $caminho = preg_replace('/[^\w\/.\-]/', '', $caminho) ?: '/';
    $acao = $_POST['acao'] ?? ($_GET['acao'] ?? '');
    $acao = is_string($acao) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $acao) : '';
    return mb_substr(trim($metodo . ' ' . $caminho) . ($acao !== '' ? " [{$acao}]" : ''), 0, 120);
}

/**
 * Sincroniza as variáveis de sessão MySQL usadas por `registrar_auditoria` (migrações 025 e 040).
 * Com `$abrirSeFechado = false`, evita abrir conexão à toa se ainda não houver PDO ativo.
 */
function sincronizar_contexto_auditoria(bool $abrirSeFechado = true): void
{
    $pdo = $abrirSeFechado ? db() : db_instancia();
    if (!$pdo instanceof PDO) {
        return;
    }

    $uid = isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    $pid = isset($_SESSION['parceiro_id']) ? (int) $_SESSION['parceiro_id'] : null;
    $fid = isset($_SESSION['parceiro_funcionario_id']) ? (int) $_SESSION['parceiro_funcionario_id'] : null;
    $ip  = function_exists('ip_cliente') ? ip_cliente() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ori = auditoria_origem_atual();

    $pdo->exec(
        'SET @app_usuario_id = ' . ($uid !== null ? (string) $uid : 'NULL')
        . ', @app_parceiro_id = ' . ($pid !== null ? (string) $pid : 'NULL')
        . ', @app_funcionario_id = ' . ($fid !== null ? (string) $fid : 'NULL')
        . ', @app_ip = ' . ($ip !== '' ? $pdo->quote(substr($ip, 0, 45)) : 'NULL')
        . ', @app_origem = ' . ($ori !== '' ? $pdo->quote($ori) : 'NULL')
    );
}

function db(): PDO
{
    $pdo = db_instancia();
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        error_log('EcoCity — falha de conexão: ' . $ex->getMessage());
        http_response_code(500);
        exit(config('debug')
            ? 'Não foi possível conectar ao banco: ' . e($ex->getMessage())
            : 'Serviço indisponível no momento. Tente novamente mais tarde.');
    }

    db_instancia($pdo);
    sincronizar_contexto_auditoria(false);

    return $pdo;
}

/**
 * Cache leve para checagens de esquema em information_schema (memória + arquivo em tmp por UID/banco).
 * Evita repetir ~13 consultas DDL por requisição HTTP; só persiste em disco quando verdadeira.
 */
function schema_flag(string $chave, callable $verificador, int $ttlSeg = 120): bool
{
    static $mem = [];
    if (array_key_exists($chave, $mem)) {
        return $mem[$chave];
    }

    $c = config('db') ?? [];
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) @getmyuid();
    $escopo = md5(($c['host'] ?? '') . ':' . ($c['port'] ?? '') . ':' . ($c['name'] ?? '') . ':' . $uid);
    $arq = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecocity-schema-' . $escopo . '.json';

    $dados = [];
    $bruto = @file_get_contents($arq);
    if ($bruto !== false) {
        $dec = json_decode($bruto, true);
        if (is_array($dec)) {
            $dados = $dec;
            if (isset($dados[$chave]['v'], $dados[$chave]['t']) && $dados[$chave]['v'] === true && (time() - (int) $dados[$chave]['t']) < $ttlSeg) {
                return $mem[$chave] = true;
            }
        }
    }

    $valor = (bool) $verificador();
    $mem[$chave] = $valor;
    if ($valor) {
        $dados[$chave] = ['v' => true, 't' => time()];
        $tmp = $arq . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($dados)) !== false) {
            if (!@rename($tmp, $arq)) {
                @unlink($tmp);
            }
        }
    }
    return $valor;
}

/** Erro levantado por um trigger de regra de negócio (SIGNAL SQLSTATE '45000'): devolve a mensagem, senão null. */
function erro_regra_banco(Throwable $ex): ?string
{
    if ($ex instanceof PDOException && ($ex->errorInfo[0] ?? '') === '45000') {
        return (string) ($ex->errorInfo[2] ?? 'Operação recusada pelo banco de dados.');
    }
    return null;
}
