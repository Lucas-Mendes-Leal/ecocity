<?php
/**
 * Criar a nova senha pelo link do e-mail (RN47). Também atende a área do parceiro:
 * parceiro-redefinir-senha.php define AREA_PARCEIRO e inclui esta página.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth-layout.php';
$parceiro = defined('AREA_PARCEIRO');
$tipo = $parceiro ? 'parceiro' : 'jogador';
$pagina = $parceiro ? 'parceiro-redefinir-senha.php' : 'redefinir-senha.php';
$entrar = $parceiro ? 'parceiro-entrar.php' : 'login.php';
$esqueci = $parceiro ? 'parceiro-esqueci-senha.php' : 'esqueci-senha.php';

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : (is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nova = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
    $confirmar = is_string($_POST['confirmar-senha'] ?? null) ? $_POST['confirmar-senha'] : '';
    if (!csrf_valido()) {
        $erros['geral'] = 'Sua sessão expirou. Tente novamente.';
    } else {
        $erros = redefinir_senha($token, $nova, $confirmar, $tipo);
        if (!$erros) {
            // a nova senha vale a partir do próximo login
            if ($parceiro) {
                if (parceiro_logado()) sair_parceiro();
            } elseif (usuario_id() !== null) {
                sair();
            }
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            flash('Senha redefinida! Entre com a nova senha.');
            redirect($entrar);
        }
    }
}

$valido = redefinicao_valida($token, false, $tipo) !== null;
$invalido = static fn(string $campo): string => isset($erros[$campo]) ? ' aria-invalid="true"' : '';

if ($parceiro) {
    auth_inicio('Nova senha do parceiro', '', 'Criar nova senha', 'Escolha uma nova senha para entrar na área do parceiro.', ABAS_PARCEIRO, SLOGAN_PARCEIRO);
} else {
    auth_inicio('Nova senha', '', 'Criar nova senha', 'Escolha uma nova senha para entrar na sua cidade.');
}
?>
            <?php if (!$valido): ?>
                <div id="alert-box" class="alert alert-error" role="alert"><?= e($erros['geral'] ?? 'Este link expirou ou já foi usado. Peça um novo.') ?></div>
                <a href="<?= $esqueci ?>" class="btn btn-primary btn-block btn-game">Pedir novo link</a>
            <?php else: ?>
                <div id="alert-box" class="alert <?= isset($erros['geral']) ? 'alert-error' : 'hidden' ?>" role="alert"><?= e($erros['geral'] ?? '') ?></div>
                <form id="form-redefinir" method="post" action="<?= $pagina ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <?php
                    auth_campo('senha', 'Nova senha', 'lock', '', 'Mínimo 6 caracteres',
                        'type="password" autocomplete="new-password" maxlength="' . SENHA_MAX . '"' . $invalido('senha'), $erros['senha'] ?? '');
                    auth_campo('confirmar-senha', 'Confirmar nova senha', 'lock', '', 'Repita a senha',
                        'type="password" autocomplete="new-password" maxlength="' . SENHA_MAX . '"' . $invalido('confirmar-senha'), $erros['confirmar-senha'] ?? '');
                    ?>
                    <button type="submit" class="btn btn-primary btn-block btn-game">Salvar nova senha</button>
                </form>
            <?php endif; ?>
<?php auth_fim('Lembrou a senha? <a href="' . $entrar . '">Voltar ao login</a>');
