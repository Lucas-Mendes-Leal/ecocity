<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';
// Volta ao link do QR do parceiro depois do login (único destino aceito, RN71)
$voltar = is_string($_GET['voltar'] ?? $_POST['voltar'] ?? null) ? (string) ($_GET['voltar'] ?? $_POST['voltar']) : '';
if (!preg_match('/^validar\.php\?c=[0-9a-f]{32}$/', $voltar)) $voltar = '';
if ($voltar !== '' && usuario_id() !== null) redirect($voltar);
redirecionar_se_logado();

$erro = null;
$email = '';
$aviso = flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = texto('email');
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';

    if (!csrf_valido()) {
        $erro = 'Sua sessão expirou. Tente entrar novamente.';
    } elseif ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } elseif ($bloqueio = login_bloqueado_ate($email)) {
        // Bloqueado por erros recentes (RN46): não confere a senha nem conta nova tentativa
        $min = max(1, (int) ceil(($bloqueio->getTimestamp() - time()) / 60));
        $erro = "Muitas tentativas de login. Tente novamente em {$min} " . ($min === 1 ? 'minuto' : 'minutos') . ' ou redefina sua senha.';
    } else {
        $reativada = false;   // autenticar() marca true quando reativa a conta desativada pelo jogador (RN93)
        $usuarioId = autenticar($email, $senha, $reativada);
        registrar_tentativa_login($email, $usuarioId !== null);
        if ($usuarioId !== null) {
            entrar($usuarioId);
            if ($reativada) $_SESSION['aviso_reativada'] = true;
            redirect($voltar !== '' ? $voltar : 'jogo.php');
        }
        $erro = 'E-mail ou senha incorretos.';
    }
}
auth_inicio('Entrar', 'login', 'Bem-vindo de volta!', 'Entre para continuar cuidando da sua cidade.');
?>
            <?php if ($erro): ?>
                <div id="alert-box" class="alert alert-error" role="alert"><?= e($erro) ?></div>
            <?php elseif ($aviso): ?>
                <div id="alert-box" class="alert alert-success" role="alert"><?= e($aviso) ?></div>
            <?php else: ?>
                <div id="alert-box" class="alert hidden" role="alert"></div>
            <?php endif; ?>

            <form id="form-login" method="post" action="login.php" novalidate>
                <?= csrf_field() ?>
                <?php if ($voltar !== ''): ?><input type="hidden" name="voltar" value="<?= e($voltar) ?>"><?php endif; ?>
                <?php
                auth_campo('email', 'E-mail', 'mail', $email, 'seu.email@exemplo.com', 'type="email" autocomplete="email"');
                auth_campo('senha', 'Senha', 'lock', '', 'Mínimo 6 caracteres', 'type="password" autocomplete="current-password"');
                ?>
                <p class="auth-forgot"><a href="esqueci-senha.php">Esqueci minha senha</a></p>
                <button type="submit" class="btn btn-primary btn-block btn-game">Entrar na Cidade</button>
            </form>
<?php auth_fim('Ainda não tem uma cidade? <a href="cadastro.php">Cadastre-se aqui</a>');
