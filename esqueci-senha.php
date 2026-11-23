<?php
/**
 * Pedir o link de redefinição de senha (RN47). Também atende a área do parceiro:
 * parceiro-esqueci-senha.php define AREA_PARCEIRO e inclui esta página.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth-layout.php';
require_once __DIR__ . '/includes/email.php';
$parceiro = defined('AREA_PARCEIRO');
if ($parceiro) {
    if (parceiro_logado()) redirect('parceiro.php');
} else {
    redirecionar_se_logado();
}
$pagina = $parceiro ? 'parceiro-esqueci-senha.php' : 'esqueci-senha.php';

$erro = null;
$enviado = false;
$linkTeste = null;   // só aparece com debug ligado e SMTP não configurado
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = texto('email');
    if (!csrf_valido()) {
        $erro = 'Sua sessão expirou. Tente novamente.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido.';
    } elseif (acao_ip_bloqueada('redefinicao')) {
        $erro = 'Muitos pedidos de recuperação a partir desta rede. Aguarde alguns minutos e tente novamente.';
    } else {
        registrar_acao_ip('redefinicao');
        $pedido = criar_redefinicao($email, $parceiro ? 'parceiro' : 'jogador');
        if ($pedido !== null) {
            $link = url_site(($parceiro ? 'parceiro-redefinir-senha.php' : 'redefinir-senha.php') . '?token=' . $pedido['token']);
            if (!enviar_email_redefinicao($pedido['email'], $pedido['nome'], $link)) {
                if (!smtp_configurado() && config('debug')) {
                    $linkTeste = $link;
                } else {
                    error_log('EcoCity — link de redefinição não enviado para o usuário de e-mail informado.');
                }
            }
        }
        // Mesma resposta exista ou não a conta (não revela e-mails cadastrados)
        $enviado = true;
    }
}

if ($parceiro) {
    auth_inicio('Esqueci a senha do parceiro', '', 'Recuperar acesso', 'Informe o e-mail do estabelecimento e enviaremos um link para criar uma nova senha.', ABAS_PARCEIRO, SLOGAN_PARCEIRO);
} else {
    auth_inicio('Esqueci a senha', '', 'Recuperar acesso', 'Informe o e-mail da sua conta e enviaremos um link para criar uma nova senha.');
}
?>
            <?php if ($erro): ?>
                <div id="alert-box" class="alert alert-error" role="alert"><?= e($erro) ?></div>
            <?php elseif ($enviado): ?>
                <div id="alert-box" class="alert alert-success" role="alert">Se este e-mail estiver cadastrado, você vai receber um link para redefinir a senha. O link vale por <?= REDEFINICAO_VALIDADE_MIN ?> minutos.</div>
            <?php else: ?>
                <div id="alert-box" class="alert hidden" role="alert"></div>
            <?php endif; ?>

            <?php if ($linkTeste): ?>
                <div class="alert alert-warning auth-test-link" role="note">
                    <strong>Ambiente de teste:</strong> o envio de e-mail (SMTP) não está configurado. Link gerado:
                    <a href="<?= e($linkTeste) ?>">Redefinir senha</a>
                </div>
            <?php endif; ?>

            <form id="form-esqueci" method="post" action="<?= $pagina ?>" novalidate>
                <?= csrf_field() ?>
                <?php auth_campo('email', 'E-mail', 'mail', $email, 'seu.email@exemplo.com', 'type="email" autocomplete="email"'); ?>
                <button type="submit" class="btn btn-primary btn-block btn-game">Enviar link</button>
            </form>
<?php auth_fim('Lembrou a senha? <a href="' . ($parceiro ? 'parceiro-entrar.php' : 'login.php') . '">Voltar ao login</a>');
