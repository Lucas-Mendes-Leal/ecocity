<?php
/**
 * Relatório do meu impacto (RN84): página para imprimir ou salvar em PDF pelo navegador,
 * só com os dados do jogador logado (impacto real, ações por área, aprendizado e conquistas).
 */
require __DIR__ . '/includes/bootstrap.php';
$usuarioId = exigir_login();
$ids = jogador_do_usuario($usuarioId);
if ($ids === null) {
    redirect('login.php');
}
$jogadorId = (int) $ids['jogador_id'];
registrar_acesso($jogadorId);

$perfil = perfil_completo($usuarioId, $jogadorId);
$ev = est_jogador($jogadorId);
$conq = db()->prepare('SELECT c.nome, jc.data_conquista FROM jogador_conquista jc JOIN conquista c ON c.conquista_id = jc.conquista_id WHERE jc.jogador_id = ? ORDER BY jc.data_conquista, c.nome');
$conq->execute([$jogadorId]);
$conquistas = $conq->fetchAll();

$cats = categorias_estatistica(db());
$nomePorChave = array_column($cats, 'nome', 'chave');
$numero = static function ($n, int $casas = 2): string {
    $v = round((float) $n, $casas);
    if ($v == floor($v)) return number_format($v, 0, ',', '.');
    return rtrim(rtrim(number_format($v, $casas, ',', '.'), '0'), ',');
};
$data = static fn(string $iso): string => date('d/m/Y', strtotime($iso));
$c = $perfil['conta'];
$e = $perfil['estatisticas'];
$impResumo = $perfil['impactoResumo'] ?? null;
$impTudo = $impResumo['periodos']['tudo'] ?? ['acoes' => (int) $e['registros']['aprovado'], 'semFoto' => 0, 'comFoto' => (int) $e['registrosEvidencia'], 'parceiro' => 0, 'pontosCidade' => 0, 'areasAtivas' => 0, 'habitosPraticados' => 0];
$maxArea = max(1, ...array_column($ev['areas'], 'acoes'));
$habitosPraticadosLista = [];
foreach ($perfil['impacto'] as $areaImp) {
    foreach ($areaImp['habitos'] ?? [] as $h) {
        if ((int) $h['acoes'] > 0) {
            $habitosPraticadosLista[] = $h + ['categoria' => $areaImp['categoria']];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de impacto — <?= e($c['apelido']) ?> — EcoCity</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/logo-ecocity-icone.png') ?>">
    <?= pwa_head() ?>
    <link rel="stylesheet" href="<?= asset('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/relatorio.css') ?>">
    <?= importmap() ?>
</head>
<body class="relatorio-body">
    <div class="relatorio-acoes">
        <a class="btn btn-outline btn-sm" href="jogo.php">Voltar ao jogo</a>
        <button type="button" class="btn btn-primary btn-sm" id="imprimir">Imprimir ou salvar em PDF</button>
    </div>

    <main class="relatorio">
        <header class="rel-topo">
            <img src="assets/img/logo-ecocity.png" alt="EcoCity" class="rel-logo">
            <div>
                <h1>Relatório do meu impacto</h1>
                <p><strong><?= e($c['apelido']) ?></strong> · cidade <?= e($c['cidade']) ?></p>
                <p class="rel-muted">Jogando desde <?= $c['cadastro'] ? $data($c['cadastro']) : '—' ?> · gerado em <?= date('d/m/Y \à\s H:i') ?></p>
            </div>
        </header>

        <section class="rel-numeros">
            <div><strong><?= (int) $e['nivelJogador'] ?></strong><span>nível do jogador</span></div>
            <div><strong><?= (int) $e['nivelCidade'] ?></strong><span>nível da cidade</span></div>
            <div><strong><?= (int) $e['registros']['aprovado'] ?></strong><span>ações aprovadas</span></div>
            <div><strong>+<?= (int) $impTudo['pontosCidade'] ?> pts</strong><span>recuperação gerada na cidade</span></div>
            <div><strong><?= (int) $impTudo['habitosPraticados'] ?>/<?= (int) ($impResumo['totalAtividadesCatalogo'] ?? 15) ?></strong><span>hábitos sustentáveis praticados</span></div>
            <div><strong><?= (int) $ev['sequencia']['melhor'] ?></strong><span>dias seguidos (recorde)</span></div>
            <div><strong><?= (int) $impTudo['parceiro'] ?></strong><span>confirmadas em parceiros<?= !empty($impResumo['parceirosDistintos']) ? ' (' . (int) $impResumo['parceirosDistintos'] . ' estab.)' : '' ?></span></div>
            <div><strong><?= (int) $impTudo['comFoto'] ?></strong><span>com foto validada por IA</span></div>
            <div><strong><?= (int) $e['conquistas'] ?>/<?= (int) $e['totalConquistas'] ?></strong><span>conquistas desbloqueadas</span></div>
        </section>

        <?php if (!empty($impResumo['comunidade'])): ?>
        <section class="rel-comunidade-box">
            <strong>Impacto coletivo na comunidade <?= e($impResumo['comunidade']['nome']) ?>:</strong>
            <?= (int) $impResumo['comunidade']['acoes'] ?> <?= (int) $impResumo['comunidade']['acoes'] === 1 ? 'ação entregue' : 'ações entregues' ?>
            · +<?= (int) $impResumo['comunidade']['pontos'] ?> pontos coletivos.
        </section>
        <?php endif; ?>

        <section>
            <h2>Impacto real estimado por área e unidade</h2>
            <table class="rel-tabela">
                <thead>
                    <tr>
                        <th scope="col">Área ambiental</th>
                        <th scope="col">Saúde da região</th>
                        <th scope="col">Ações da área</th>
                        <th scope="col">Impacto estimado por unidade física</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($perfil['impacto'] as $i):
                    $unidadesArea = !empty($i['unidades']) ? $i['unidades'] : [['unidade' => $i['unidade'] ?: 'sem estimativa', 'quantidade' => $i['quantidade'], 'acoes' => $i['atividades']]];
                ?>
                    <tr>
                        <td><span class="rel-dot cat-<?= e($i['categoria']) ?>"></span><?= e($nomePorChave[$i['categoria']] ?? $i['categoria']) ?></td>
                        <td><?= (int) ($i['valorIndicador'] ?? 0) ?>/100<?= !empty($i['pontosRegiao']) ? ' <span class="rel-muted">(+' . (int) $i['pontosRegiao'] . ' pts)</span>' : '' ?></td>
                        <td><?= (int) $i['atividades'] ?></td>
                        <td>
                            <ul class="rel-unidades-lista">
                                <?php foreach ($unidadesArea as $u): ?>
                                    <li>
                                        <strong><?= $numero($u['quantidade']) ?></strong> <?= e($u['unidade'] ?: 'sem estimativa') ?>
                                        <?php if (count($unidadesArea) > 1): ?>
                                            <span class="rel-muted">(<?= (int) $u['acoes'] ?> <?= (int) $u['acoes'] === 1 ? 'ação' : 'ações' ?>)</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="rel-muted">Estimativa: cada ação aprovada vale o impacto médio da atividade (ou a quantidade confirmada por um parceiro), agrupado por unidade física sem misturar grandezas distintas. Fontes e premissas na documentação do projeto (RN26).</p>
        </section>

        <section>
            <h2>Detalhamento por hábito e atividade sustentável</h2>
            <?php if (!$habitosPraticadosLista): ?>
                <p class="rel-muted">Nenhuma atividade sustentável aprovada ainda.</p>
            <?php else: ?>
                <table class="rel-tabela">
                    <thead>
                        <tr>
                            <th scope="col">Hábito / Atividade</th>
                            <th scope="col">Área</th>
                            <th scope="col">Ações</th>
                            <th scope="col">Comprovação (Rápido / Foto / Parceiro)</th>
                            <th scope="col">Impacto real acumulado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($habitosPraticadosLista as $h): ?>
                        <tr>
                            <td><strong><?= e($h['nome']) ?></strong></td>
                            <td><span class="rel-dot cat-<?= e($h['categoria']) ?>"></span><?= e($nomePorChave[$h['categoria']] ?? $h['categoria']) ?></td>
                            <td><?= (int) $h['acoes'] ?></td>
                            <td><?= (int) $h['semFoto'] ?> rápido · <?= (int) $h['comFoto'] ?> foto · <?= (int) $h['parceiro'] ?> parceiro</td>
                            <td><strong><?= $numero($h['quantidade']) ?></strong> <?= e($h['unidade'] ?: '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section>
            <h2>Ações por área e evolução recente</h2>
            <?php if (!empty($ev['comparativoHabitos']['resumo'])):
                $rc = $ev['comparativoHabitos']['resumo'];
            ?>
                <p class="rel-muted" style="margin-bottom: 10px;">
                    Ritmo recente (4 semanas anteriores → últimas 4 semanas):
                    <strong><?= (int) $rc['acoesAntes'] ?> → <?= (int) $rc['acoesAgora'] ?> ações</strong> ·
                    <strong><?= (int) $rc['habitosAntes'] ?> → <?= (int) $rc['habitosAgora'] ?> hábitos diferentes</strong> ·
                    <strong>+<?= (int) $rc['pontosAntes'] ?> → +<?= (int) $rc['pontosAgora'] ?> pts na cidade</strong>.
                </p>
            <?php endif; ?>
            <ul class="rel-barras">
                <?php foreach ($ev['areas'] as $a): ?>
                    <li><span><?= e($nomePorChave[$a['categoria']] ?? $a['categoria']) ?></span>
                        <span class="rel-trilha"><span class="rel-barra cat-<?= e($a['categoria']) ?>" style="width: <?= round($a['acoes'] / $maxArea * 100) ?>%"></span></span>
                        <strong><?= (int) $a['acoes'] ?></strong></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section>
            <h2>Aprendizado e ciclo Aprenda → Pratique</h2>
            <?php if (!$ev['temas']): ?>
                <p class="rel-muted">Nenhum quiz concluído ainda.</p>
            <?php else:
                $ar = $ev['aprendizadoResumo'] ?? null;
            ?>
                <?php if ($ar): ?>
                    <p class="rel-muted" style="margin-bottom: 10px;">
                        Quizzes concluídos: <strong><?= (int) $ar['quizzesConcluidos'] ?> de <?= (int) $ar['quizzesDisponiveis'] ?></strong> (<?= (int) $ar['quizzesPerfeitos'] ?> com 100%) ·
                        Acerto geral: <strong><?= $ar['acertoPrimeira'] !== null ? $numero($ar['acertoPrimeira']) . '%' : '—' ?> (1ª tentativa) → <?= $ar['acertoAtual'] !== null ? $numero($ar['acertoAtual']) . '%' : '—' ?> (atual)</strong> ·
                        Erros corrigidos no treino: <strong><?= (int) $ar['corrigidasTreino'] ?> de <?= (int) $ar['erradasPrimeira'] ?></strong> ·
                        Ciclo Aprenda → Pratique: <strong><?= (int) $ar['temasComAcao7d'] ?> de <?= (int) $ar['temasComQuiz'] ?> temas com ação em até 7 dias</strong>.
                    </p>
                <?php endif; ?>
                <table class="rel-tabela">
                    <thead><tr><th scope="col">Tema</th><th scope="col">Quizzes</th><th scope="col">1ª tentativa</th><th scope="col">Acerto atual</th></tr></thead>
                    <tbody>
                    <?php foreach ($ev['temas'] as $t): ?>
                        <tr>
                            <td><?= e($t['nome']) ?></td>
                            <td><?= (int) $t['quizzes'] ?><?= isset($t['quizzesTotal']) ? '/' . (int) $t['quizzesTotal'] : '' ?></td>
                            <td><?= isset($t['acertoPrimeira']) && $t['acertoPrimeira'] !== null ? $numero($t['acertoPrimeira']) . '%' : '—' ?></td>
                            <td><strong><?= $numero($t['acerto']) ?>%</strong><?= $t['revisar'] ? ' <span class="rel-muted">(revisar)</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section>
            <h2>Conquistas</h2>
            <?php if (!$conquistas): ?>
                <p class="rel-muted">Nenhuma conquista ainda.</p>
            <?php else: ?>
                <ul class="rel-conquistas">
                    <?php foreach ($conquistas as $q): ?>
                        <li><?= e($q['nome']) ?> <span class="rel-muted">· <?= $data($q['data_conquista']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <footer class="rel-rodape">EcoCity — educação ambiental gamificada · Aprenda → Pratique → Registre → Evolua → Transforme</footer>
    </main>
    <script type="module" nonce="<?= e(csp_nonce()) ?>">
        document.getElementById('imprimir').addEventListener('click', () => window.print());
    </script>
</body>
</html>
