<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';
redirecionar_se_logado();

$dados = ['nome' => '', 'apelido' => '', 'cidade' => '', 'email' => ''];
$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'nome'      => texto('nome'),
        'apelido'   => texto('apelido'),
        'cidade'    => texto('cidade'),
        'email'     => texto('email'),
        'senha'     => is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '',
        'confirmar' => is_string($_POST['confirmar-senha'] ?? null) ? $_POST['confirmar-senha'] : '',
    ];

    if (!csrf_valido()) {
        $erros['geral'] = 'Sua sessão expirou. Envie o formulário novamente.';
    } elseif (acao_ip_bloqueada('cadastro')) {
        $erros['geral'] = 'Muitas tentativas de cadastro a partir desta rede. Aguarde alguns minutos e tente novamente.';
    } else {
        registrar_acao_ip('cadastro');
        $erros = validar_cadastro($dados) ?: cadastrar_conta($dados);
        if (!$erros) {
            flash('Cidade fundada! Entre com seu e-mail e senha.');
            redirect('login.php');
        }
    }
}

/** Atributo aria-invalid quando o campo tem erro. */
function invalido(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' aria-invalid="true"' : '';
}

auth_inicio('Cadastro', 'cadastro', 'Fundar sua Cidade', 'Crie sua conta para começar a recuperar sua cidade.');
?>
            <div id="alert-box" class="alert <?= isset($erros['geral']) ? 'alert-error' : 'hidden' ?>" role="alert"><?= e($erros['geral'] ?? '') ?></div>

            <form id="form-cadastro" method="post" action="cadastro.php" novalidate>
                <?= csrf_field() ?>
                <?php
                $campos = [
                    ['nome', 'Nome', 'user', 'text', 'Seu nome', 'name'],
                    ['apelido', 'Apelido do Jogador', 'user', 'text', 'Ex.: EcoGuardião', 'nickname'],
                    ['cidade', 'Nome da sua Cidade', 'home', 'text', 'Ex.: Vila Esperança', 'off'],
                    ['email', 'E-mail', 'mail', 'email', 'seu.email@exemplo.com', 'email'],
                ];
                foreach ($campos as [$id, $rotulo, $icone, $tipo, $placeholder, $auto]) {
                    auth_campo($id, $rotulo, $icone, $dados[$id], $placeholder,
                        'type="' . $tipo . '" autocomplete="' . $auto . '" maxlength="100"' . invalido($erros, $id), $erros[$id] ?? '');
                }
                auth_campo('senha', 'Senha', 'lock', '', 'Mínimo 6 caracteres',
                    'type="password" autocomplete="new-password" maxlength="' . SENHA_MAX . '"' . invalido($erros, 'senha'), $erros['senha'] ?? '');
                auth_campo('confirmar-senha', 'Confirmar Senha', 'lock', '', 'Repita a senha',
                    'type="password" autocomplete="new-password" maxlength="' . SENHA_MAX . '"' . invalido($erros, 'confirmar-senha'), $erros['confirmar-senha'] ?? '');
                ?>
                <button type="submit" class="btn btn-primary btn-block btn-game">Fundar Cidade</button>
            </form>
<?php auth_fim('Já possui uma cidade? <a href="login.php">Voltar ao login</a>');
