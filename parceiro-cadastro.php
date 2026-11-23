<?php
/** Cadastro do estabelecimento parceiro (RN69): fica pendente até um administrador aprovar. */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';
if (parceiro_logado()) redirect('parceiro.php');

$dados = ['nome' => '', 'responsavel' => '', 'email' => '', 'endereco' => '', 'descricao' => '', 'atividades' => []];
$erros = [];
$atividades = atividades_verificaveis();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dados = [
        'nome' => texto('nome'), 'responsavel' => texto('responsavel'), 'email' => texto('email'),
        'endereco' => texto('endereco'), 'descricao' => texto('descricao'),
        'atividades' => array_map('intval', is_array($_POST['atividades'] ?? null) ? $_POST['atividades'] : []),
        'senha' => is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '',
        'confirmar' => is_string($_POST['confirmar-senha'] ?? null) ? $_POST['confirmar-senha'] : '',
    ];
    if (!csrf_valido()) {
        $erros['geral'] = 'Sua sessão expirou. Envie o formulário novamente.';
    } elseif (acao_ip_bloqueada('parceiro_cadastro')) {
        $erros['geral'] = 'Muitas tentativas de cadastro de parceiro a partir desta rede. Aguarde alguns minutos e tente novamente.';
    } else {
        registrar_acao_ip('parceiro_cadastro');
        $erros = cadastrar_parceiro($dados);
        if (!$erros) {
            flash('Cadastro enviado! Assim que um administrador aprovar, você já pode gerar códigos. Entre para acompanhar.');
            redirect('parceiro-entrar.php');
        }
        if (isset($erros['confirmar'])) $erros['confirmar-senha'] = $erros['confirmar'];
    }
}
$inv = static fn(string $c): string => isset($erros[$c]) ? ' aria-invalid="true"' : '';

auth_inicio('Cadastro de parceiro', 'cadastro', 'Seja um Parceiro', 'Cooperativas, mercados, ecopontos e lojas que confirmam ações reais dos jogadores.', ABAS_PARCEIRO, SLOGAN_PARCEIRO);
?>
            <div id="alert-box" class="alert <?= isset($erros['geral']) ? 'alert-error' : 'hidden' ?>" role="alert"><?= e($erros['geral'] ?? '') ?></div>

            <form id="form-parceiro" method="post" action="parceiro-cadastro.php" novalidate>
                <?= csrf_field() ?>
                <?php
                auth_campo('nome', 'Nome do estabelecimento', 'home', $dados['nome'], 'Ex.: Cooperativa Recicla Bairro', 'type="text" maxlength="100" autocomplete="organization"' . $inv('nome'), $erros['nome'] ?? '');
                auth_campo('responsavel', 'Responsável', 'user', $dados['responsavel'], 'Nome de quem cuida da conta', 'type="text" maxlength="100" autocomplete="name"' . $inv('responsavel'), $erros['responsavel'] ?? '');
                auth_campo('email', 'E-mail', 'mail', $dados['email'], 'contato@estabelecimento.com', 'type="email" maxlength="100" autocomplete="email"' . $inv('email'), $erros['email'] ?? '');
                auth_campo('endereco', 'Endereço', 'pin', $dados['endereco'], 'Rua, número, bairro e cidade', 'type="text" maxlength="200" autocomplete="street-address"' . $inv('endereco'), $erros['endereco'] ?? '');
                ?>
                <div class="form-group">
                    <label for="descricao">O que o estabelecimento faz</label>
                    <textarea id="descricao" name="descricao" class="form-input" rows="2" maxlength="250" placeholder="Ex.: recebemos garrafas PET, papelão e latinhas de segunda a sábado."<?= $inv('descricao') ?> required><?= e($dados['descricao']) ?></textarea>
                    <span class="field-error" id="error-descricao"><?= e($erros['descricao'] ?? '') ?></span>
                </div>
                <fieldset class="form-group partner-acts partner-acts-picker"
                          data-seletor-atividades="edit"
                          data-trigger-id="atividades"
                          data-botao="Selecionar atividades"
                          data-titulo="Atividades que vocês podem confirmar"
                          data-subtitulo="Escolha uma ou mais atividades sustentáveis que o estabelecimento consegue verificar.">
                    <legend>Atividades que vocês podem confirmar</legend>
                    <div class="act-picker-fallback">
                        <?php foreach ($atividades as $a): ?>
                            <label class="check-line"><input type="checkbox" name="atividades[]" value="<?= $a['id'] ?>"
                                data-nome="<?= e($a['nome']) ?>"
                                data-unidade="<?= e($a['unidade']) ?>"
                                data-categoria="<?= e($a['categoria']) ?>"
                                data-categoria-nome="<?= e($a['categoriaNome']) ?>"
                                <?= in_array($a['id'], $dados['atividades'], true) ? 'checked' : '' ?>>
                                <?= e($a['nome']) ?> <span class="form-hint">(em <?= e($a['unidade']) ?>)</span></label>
                        <?php endforeach; ?>
                    </div>
                    <span class="field-error" id="error-atividades"><?= e($erros['atividades'] ?? '') ?></span>
                </fieldset>
                <?php
                auth_campo('senha', 'Senha', 'lock', '', 'Mínimo 6 caracteres', 'type="password" autocomplete="new-password" maxlength="72"' . $inv('senha'), $erros['senha'] ?? '');
                auth_campo('confirmar-senha', 'Confirmar senha', 'lock', '', 'Repita a senha', 'type="password" autocomplete="new-password" maxlength="72"' . $inv('confirmar-senha'), $erros['confirmar-senha'] ?? '');
                ?>
                <p class="form-hint">O cadastro passa pela aprovação da equipe do EcoCity antes de liberar a geração de códigos.</p>
                <button type="submit" class="btn btn-primary btn-block btn-game">Enviar cadastro</button>
            </form>
<?php auth_fim('Já é parceiro? <a href="parceiro-entrar.php">Entrar</a>');
