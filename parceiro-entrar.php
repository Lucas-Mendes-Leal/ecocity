<?php
/** Login do estabelecimento parceiro (RN69) — separado do login do jogador. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';
if (parceiro_logado()) redirect('parceiro.php');

$erro = null;
$email = '';
$aviso = flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = texto('email');
    $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    if (!csrf_valido()) {
        $erro = 'Sua sessão expirou. Tente entrar novamente.';
    } elseif ($email === '' || $senha === '') {
        $erro = 'Informe o e-mail (ou o usuário) e a senha.';
    } elseif ($bloqueio = login_bloqueado_ate('parceiro:' . strtolower($email))) {   // mesmo limite da RN46, contado à parte do jogador
        $min = max(1, (int) ceil(($bloqueio->getTimestamp() - time()) / 60));
        $erro = "Muitas tentativas de login. Tente novamente em {$min} " . ($min === 1 ? 'minuto' : 'minutos') . ' ou redefina sua senha.';
    } else {
        // Com "@": administrador do estabelecimento; sem "@": usuário de funcionário (RN95)
        $conta = autenticar_parceiro($email, $senha);
        registrar_tentativa_login('parceiro:' . strtolower($email), $conta !== null);
        if ($conta !== null) {
            entrar_parceiro($conta['parceiroId'], $conta['funcionarioId'], $conta['versao']);
            redirect('parceiro.php');
        }
        $erro = str_contains($email, '@') ? 'E-mail ou senha incorretos.' : 'Usuário ou senha incorretos (ou o seu acesso está desativado).';
    }
}
auth_inicio('Entrar como parceiro', 'login', 'Área do Parceiro', 'Entre para gerar códigos e validar as ações dos jogadores.', ABAS_PARCEIRO, SLOGAN_PARCEIRO);
?>
            <?php if ($erro): ?>
                <div id="alert-box" class="alert alert-error" role="alert"><?= e($erro) ?></div>
            <?php elseif ($aviso): ?>
                <div id="alert-box" class="alert alert-success" role="alert"><?= e($aviso) ?></div>
            <?php else: ?>
                <div id="alert-box" class="alert hidden" role="alert"></div>
            <?php endif; ?>

            <form id="form-login" method="post" action="parceiro-entrar.php" data-aceita-usuario novalidate>
                <?= csrf_field() ?>
                <?php
                auth_campo('email', 'E-mail do estabelecimento ou usuário', 'mail', $email, 'contato@estabelecimento.com ou seu.usuario', 'type="text" autocomplete="username" autocapitalize="none" spellcheck="false"');
                auth_campo('senha', 'Senha', 'lock', '', 'Mínimo 6 caracteres', 'type="password" autocomplete="current-password"');
                ?>
                <p class="auth-forgot"><a href="parceiro-esqueci-senha.php">Esqueci minha senha</a></p>
                <p class="form-hint">Funcionário: entre com o usuário que o administrador do estabelecimento criou para você. Esqueceu a senha? Peça a ele.</p>
                <button type="submit" class="btn btn-primary btn-block btn-game">Entrar</button>
            </form>
<?php auth_fim('Ainda não é parceiro? <a href="parceiro-cadastro.php">Cadastre seu estabelecimento</a> · É jogador? <a href="login.php">Entrar no jogo</a>');
