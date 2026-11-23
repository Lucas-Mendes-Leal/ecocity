<?php
/**
 * Link do QR do parceiro (RN71): mostra a atividade e confirma com o jogador logado.
 * GET mostra a confirmação; POST (com CSRF) usa o código. Sem login, vai ao login e volta para cá.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';

$token = is_string($_GET['c'] ?? $_POST['c'] ?? null) ? (string) ($_GET['c'] ?? $_POST['c']) : '';
if (!preg_match('/^[0-9a-f]{32}$/', $token)) $token = '';
$usuarioId = usuario_id();
if ($usuarioId === null) {
    flash('Entre na sua conta para confirmar a atividade com o parceiro.');
    redirect('login.php' . ($token ? '?voltar=' . rawurlencode('validar.php?c=' . $token) : ''));
}
$jogador = jogador_do_usuario($usuarioId);
if (!$jogador) redirect('login.php');
$jogadorId = (int) $jogador['jogador_id'];

$erro = null;
$feito = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        $erro = 'Sua sessão expirou. Toque em confirmar de novo.';
    } else {
        try {
            aplicar_queda_diaria(db(), $jogadorId);
            $feito = validar_codigo($jogadorId, $token);
        } catch (RegistroInvalido $ex) {
            $erro = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('EcoCity — validar: ' . $ex->getMessage());
            $erro = 'Não foi possível concluir. Tente novamente.';
        }
    }
}
$c = $token ? codigo_por_token($token) : null;

// Ganho com a verificação por parceiro (150%), igual ao do servidor
$ganho = null;
if ($c) {
    foreach (listar_atividades() as $a) {
        if ($a['id'] === $c['atividadeId']) $ganho = $a['recompensas'][TIPO_PARCEIRO];
    }
}
$num = static fn($n): string => number_format((float) $n, floor((float) $n) == (float) $n ? 0 : 2, ',', '.');

auth_inicio('Confirmar atividade', '', 'Confirmar atividade', 'Verificação feita por um estabelecimento parceiro.', []);
?>
            <?php if ($feito): ?>
                <div class="alert alert-success" role="alert">Atividade confirmada! Ela já entrou aprovada na sua cidade.</div>
                <div class="validate-box">
                    <div class="validate-partner"><strong><?= e($feito['parceiro']) ?></strong><span>Verificado agora</span></div>
                    <p class="validate-what"><?= e($feito['atividade']) ?> · <strong><?= $num($feito['quantidade']) ?> <?= e($feito['unidade']) ?></strong></p>
                    <?php if ($ganho): ?><p class="validate-gain">+<?= $ganho['xp'] ?> XP · +<?= $ganho['credito'] ?> créditos · +<?= $ganho['impacto'] ?> no indicador (150%)</p><?php endif; ?>
                    <a class="btn btn-primary btn-block btn-game" href="jogo.php">Ir para o jogo</a>
                </div>
            <?php elseif (!$c): ?>
                <div class="alert alert-error" role="alert">Código não encontrado. Peça um novo QR ao parceiro.</div>
                <a class="btn btn-primary btn-block" href="jogo.php">Ir para o jogo</a>
            <?php else: ?>
                <?php if ($erro): ?>
                    <div class="alert alert-error" role="alert"><?= e($erro) ?></div>
                <?php elseif ($c['situacao'] !== 'valido'): ?>
                    <div class="alert alert-error" role="alert"><?= e(MENSAGEM_SITUACAO[$c['situacao']]) ?></div>
                <?php endif; ?>
                <div class="validate-box">
                    <div class="validate-partner"><strong><?= e($c['parceiro']) ?></strong><span><?= e($c['parceiroEndereco']) ?></span></div>
                    <p class="validate-what"><?= e($c['atividade']) ?> · <strong><?= $num($c['quantidade']) ?> <?= e($c['unidade']) ?></strong></p>
                    <?php if ($ganho): ?><p class="validate-gain">Você ganha +<?= $ganho['xp'] ?> XP, +<?= $ganho['credito'] ?> créditos e +<?= $ganho['impacto'] ?> no indicador (150%).</p><?php endif; ?>
                    <?php if ($c['situacao'] === 'valido' && !$erro): ?>
                        <form method="post" action="validar.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="c" value="<?= e($token) ?>">
                            <button type="submit" class="btn btn-primary btn-block btn-game">Confirmar atividade</button>
                        </form>
                        <p class="form-hint">Confirme só se você realmente entregou ou fez esta ação no estabelecimento. Cada código vale para um jogador.</p>
                    <?php endif; ?>
                    <a class="btn btn-outline btn-block" href="jogo.php">Ir para o jogo</a>
                </div>
            <?php endif; ?>
<?php auth_fim('Verificação por parceiros do EcoCity');
