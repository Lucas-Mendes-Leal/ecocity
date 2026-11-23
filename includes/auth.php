<?php
/**
 * Sessão do usuário e proteção CSRF.
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------------
   CSRF
   --------------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido(): bool
{
    $enviado = $_POST['csrf'] ?? '';
    return is_string($enviado) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

/* ---------------------------------------------------------------------------
   Sessão
   --------------------------------------------------------------------------- */

/** Inicia a sessão autenticada (novo ID para evitar fixação de sessão). */
function entrar(int $usuarioId): void
{
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = $usuarioId;
    $_SESSION['sessao_versao'] = versao_sessao($usuarioId);   // "Sair de todos os aparelhos" derruba as anteriores (RN93)
    unset($_SESSION['csrf']);
    sincronizar_contexto_auditoria(false);
}

function sair(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
    ]);
    session_destroy();
    sincronizar_contexto_auditoria(false);
}

function usuario_id(): ?int
{
    if (!isset($_SESSION['usuario_id'])) return null;
    $id = (int) $_SESSION['usuario_id'];
    // Conta bloqueada perde a sessão aberta (RN48); "Sair de todos os aparelhos" também (RN93)
    if (!conta_ativa($id) || versao_sessao($id) !== (int) ($_SESSION['sessao_versao'] ?? 0)) {
        sair();
        return null;
    }
    return $id;
}

/** Interrompe a página e manda para o login se não houver sessão. */
function exigir_login(): int
{
    $id = usuario_id();
    if ($id === null) {
        redirect('login.php');
    }
    return $id;
}

/** Usado em login/cadastro: quem já está logado vai direto para o jogo. */
function redirecionar_se_logado(): void
{
    if (usuario_id() !== null) {
        redirect('jogo.php');
    }
}

/** Mensagem de uso único entre redirecionamentos. */
function flash(?string $mensagem = null): ?string
{
    if ($mensagem !== null) {
        $_SESSION['flash'] = $mensagem;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}
