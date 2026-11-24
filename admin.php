<?php
require __DIR__ . '/includes/bootstrap.php';
$admin = exigir_admin();
$usuarioId = $admin['usuarioId'];
$adminId = $admin['adminId'];
$pode = static fn(string $p): bool => admin_tem_permissao($admin, $p);
$abasPermitidas = admin_abas_permitidas($admin);
if (!$abasPermitidas) {
    http_response_code(403);
    exit('Seu perfil administrativo não possui abas habilitadas.');
}
$secoesConteudo = admin_secoes_conteudo_permitidas($admin);
$stmtAdm = db()->prepare('SELECT nome, email FROM usuario WHERE usuario_id = ? LIMIT 1');
$stmtAdm->execute([$usuarioId]);
$admDados = $stmtAdm->fetch() ?: ['nome' => 'Administração', 'email' => ''];

/* Decisão (POST → redireciona, para não reenviar ao recarregar) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) {
        flash('erro:Sessão expirada. Tente novamente.');
    } else {
        $exigirPost = static function (string $perm) use ($pode, $admin): void {
            if (!$pode($perm)) {
                throw new RegistroInvalido('Seu perfil administrativo (' . $admin['perfilNome'] . ') não tem permissão para esta ação.');
            }
        };
        try {
            if (isset($_POST['parceiro_id'])) {
                $voltar = 'admin.php?aba=parceiros';
                $exigirPost('parceiros');
                $atv = is_array($_POST['atividades'] ?? null) ? $_POST['atividades'] : [];
                flash('ok:' . decidir_parceiro($adminId, (int) $_POST['parceiro_id'], (string) ($_POST['acao'] ?? ''), $atv, (string) ($_POST['motivo'] ?? '')));
            } elseif (isset($_POST['comunidade_denuncia_id'])) {
                $voltar = 'admin.php?aba=denuncias';
                $exigirPost('denuncias');
                $op = (string) ($_POST['acao'] ?? '');
                flash('ok:' . ($op === 'reabrir'
                    ? reabrir_denuncia_ia('comunidade', (int) $_POST['comunidade_denuncia_id'])
                    : resolver_denuncia_comunidade($adminId, $usuarioId, (int) $_POST['comunidade_denuncia_id'], $op)));
            } elseif (isset($_POST['pedido_id'])) {
                $voltar = 'admin.php?aba=parceiros';
                $exigirPost('parceiros');
                flash('ok:' . decidir_pedido_atividade($adminId, (int) $_POST['pedido_id'], (string) ($_POST['acao'] ?? ''), (string) ($_POST['motivo'] ?? '')));
            } elseif (isset($_POST['contestacao_id'])) {
                $voltar = 'admin.php?aba=aprovacoes';
                $exigirPost('contestacoes');
                flash('ok:' . decidir_contestacao($adminId, $usuarioId, (int) $_POST['contestacao_id'], (string) ($_POST['acao'] ?? ''), (string) ($_POST['resposta'] ?? '')));
            } elseif (isset($_POST['denuncia_id'])) {
                $voltar = 'admin.php?aba=denuncias';
                $exigirPost('denuncias');
                $op = (string) ($_POST['acao'] ?? '');
                flash('ok:' . ($op === 'reabrir'
                    ? reabrir_denuncia_ia('amigos', (int) $_POST['denuncia_id'])
                    : resolver_denuncia($adminId, $usuarioId, (int) $_POST['denuncia_id'], $op)));
            } else {
                $voltar = 'admin.php?aba=aprovacoes';
                $exigirPost('contestacoes');
                flash('ok:' . decidir_registro($adminId, $usuarioId, (int) ($_POST['registro_id'] ?? 0), (string) ($_POST['acao'] ?? ''), (string) ($_POST['motivo'] ?? '')));
            }
        } catch (RegistroInvalido $ex) {
            flash('erro:' . $ex->getMessage());
        } catch (Throwable $ex) {
            error_log('EcoCity — admin: ' . $ex->getMessage());
            flash('erro:Não foi possível concluir. Tente novamente.');
        }
    }
    redirect($voltar ?? 'admin.php');
}

if ($pode('contestacoes') || $pode('visao_geral')) {
    aprovar_vencidos(null);   // vencidos de todos os jogadores
}
ativar_conteudo_ia_vencido();   // ativa conteúdos da IA com prazo de revisão vencido (RN91)

$abasConteudo = ['catalogo', 'promocao', 'comunidade', 'conta', 'auditoria'];
$abaPedido = (string) ($_GET['aba'] ?? '');
if ($abaPedido === 'conteudo') {
    foreach ($abasConteudo as $cand) {
        if (in_array($cand, $abasPermitidas, true)) {
            $abaPedido = $cand;
            break;
        }
    }
}
$abaAtiva = in_array($abaPedido, $abasPermitidas, true) ? $abaPedido : $abasPermitidas[0];
$abaEhConteudo = in_array($abaAtiva, $abasConteudo, true);

$pendentes = $pode('contestacoes') ? listar_pendentes($usuarioId) : [];
$contestacoes = $pode('contestacoes') ? listar_contestacoes($usuarioId) : [];   // RN94
$contestacoesAbertas = count(array_filter($contestacoes, static fn($c) => $c['status'] === 'aberta'));
$decisoes = $pode('contestacoes') ? listar_decisoes(1, 20) : null;

$stats = $pode('visao_geral') ? estatisticas_gerais() : null;
$totalRegistros = $stats ? array_sum($stats['registros']) : 0;

$denuncias = $pode('denuncias') ? listar_denuncias_abertas() : [];
$denunciasComunidade = $pode('denuncias') ? listar_denuncias_comunidade() : [];
$denunciasIa = $pode('denuncias') ? listar_denuncias_resolvidas_ia(50) : [];
$todasDenuncias = [];
if ($pode('denuncias')) {
    // Abertas primeiro (das mais antigas às mais novas), depois as resolvidas pela IA (mais recentes primeiro)
    $denunciasAbertas = array_merge(
        array_map(static fn($d) => $d + ['origem' => 'amigos', 'tipo' => 'mensagem'], $denuncias),
        array_map(static fn($d) => $d + ['origem' => 'comunidade'], $denunciasComunidade)
    );
    usort($denunciasAbertas, static fn($a, $b) => $a['data'] <=> $b['data']);
    $todasDenuncias = array_merge($denunciasAbertas, $denunciasIa);
}

$parceiros = $pode('parceiros') ? listar_parceiros_admin() : [];
$parceirosPendentes = count(array_filter($parceiros, static fn($x) => $x['status'] === 'pendente'));
$pedidosAtividade = $pode('parceiros') ? listar_pedidos_atividade() : [];   // RN95
$seloParceiros = $parceirosPendentes + count($pedidosAtividade);
$atividadesVerificaveis = $pode('parceiros') ? atividades_verificaveis() : [];
$temJogador = jogador_do_usuario($usuarioId) !== null;

$podeContas = $pode('contas_suporte') || $pode('contas_progressao') || $pode('contas_admin');
$temGrupoOperacao = $pode('contestacoes') || $pode('denuncias') || $pode('parceiros') || $pode('comunidades');
$temGrupoConteudo = $pode('conteudo_catalogo') || $pode('conteudo_promocoes');
$temGrupoGovernanca = $podeContas || $pode('auditoria');
$seloAprovacoes = $contestacoesAbertas + count($pendentes);
$seloDenuncias = count($denuncias) + count($denunciasComunidade);

$conteudoModoInicial = $abaEhConteudo ? $abaAtiva : '';
if ($conteudoModoInicial === '') {
    foreach ($abasConteudo as $cand) {
        if (in_array($cand, $abasPermitidas, true)) {
            $conteudoModoInicial = $cand;
            break;
        }
    }
}
$textosConteudo = [
    'catalogo'   => ['Catálogo do jogo', 'Crie e edite atividades, quizzes, missões, conquistas, estruturas e missões de comunidade. Itens já usados por jogadores não são apagados: desative-os.'],
    'promocao'   => ['Códigos promocionais', 'Crie campanhas institucionais com recompensas em XP, créditos verdes ou pontos nos indicadores (RN98).'],
    'comunidade' => ['Comunidades', 'Acompanhe as comunidades ativas e encerre comunidades quando necessário para moderação.'],
    'conta'      => ['Contas e Perfis', 'Gerencie jogadores, suporte de contas e níveis administrativos (RN99 / RN102) conforme as permissões do seu perfil.'],
    'auditoria'  => ['Auditoria do banco', 'Consulta somente leitura e exportação CSV do log imutável de mudanças do banco de dados (RN101).'],
];
[$conteudoTituloInicial, $conteudoSubtituloInicial] = $textosConteudo[$conteudoModoInicial] ?? $textosConteudo['catalogo'];

[$tipoAviso, $aviso] = array_pad(explode(':', (string) flash(), 2), 2, '');

$dataHora = static fn(int $ts): string => date('d/m/Y H:i', $ts);
$numero = static fn($n, int $casas = 0): string => number_format((float) $n, $casas, ',', '.');
$horas = static fn(int $seg): string => $seg < 3600 ? 'menos de 1 h' : floor($seg / 3600) . ' h';
$trecho = static fn(string $t, int $max = 80): string => mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
$json = static fn($v): string => json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administração — EcoCity</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/logo-ecocity-icone.png') ?>">
    <?= pwa_head('#1B5E20') ?>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="admin-sincronia" content="<?= e(sincronia_admin($admin)['versao']) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/game.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
    <?= importmap() ?>
</head>
<body class="admin-body">
    <div class="a-shell">
        <div class="a-sidebar-overlay hidden" id="a-sidebar-overlay" aria-hidden="true"></div>

        <aside class="a-sidebar" id="a-sidebar" aria-label="Menu da administração">
            <div class="a-brand">
                <img class="a-brand-logo" src="<?= asset('assets/img/logo-ecocity-icone.png') ?>" alt="" width="38" height="38">
                <div class="a-brand-text">
                    <strong>EcoCity</strong>
                    <span>Administração</span>
                </div>
                <button type="button" class="a-sidebar-fechar" id="a-menu-fechar" aria-label="Fechar menu"><span data-icon="close"></span></button>
            </div>

            <nav class="a-nav" aria-label="Seções da administração">
                <div class="a-nav-list" role="tablist" aria-orientation="vertical" id="admin-tabs">
                    <?php if ($pode('visao_geral')): ?>
                    <span class="a-nav-group">Visão geral</span>
                    <button type="button" role="tab" class="a-nav-item" id="t-visao" data-aba="visao" aria-controls="tab-visao" aria-selected="<?= $abaAtiva === 'visao' ? 'true' : 'false' ?>">
                        <span data-icon="chart"></span><span>Visão Geral</span>
                    </button>
                    <?php endif; ?>

                    <?php if ($temGrupoOperacao): ?>
                    <span class="a-nav-group">Operação</span>
                    <?php if ($pode('contestacoes')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-aprovacoes" data-aba="aprovacoes" aria-controls="tab-aprovacoes" aria-selected="<?= $abaAtiva === 'aprovacoes' ? 'true' : 'false' ?>">
                        <span data-icon="check"></span><span>Contestações</span><span class="a-nav-count<?= $seloAprovacoes ? '' : ' is-zero' ?>" id="badge-aprovacoes"><?= $seloAprovacoes ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($pode('denuncias')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-denuncias" data-aba="denuncias" aria-controls="tab-denuncias" aria-selected="<?= $abaAtiva === 'denuncias' ? 'true' : 'false' ?>">
                        <span data-icon="mail"></span><span>Denúncias</span><span class="a-nav-count<?= $seloDenuncias ? '' : ' is-zero' ?>" id="badge-denuncias"><?= $seloDenuncias ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($pode('parceiros')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-parceiros" data-aba="parceiros" aria-controls="tab-parceiros" aria-selected="<?= $abaAtiva === 'parceiros' ? 'true' : 'false' ?>">
                        <span data-icon="flag"></span><span>Parceiros</span><span id="badge-parceiros" class="a-nav-count<?= $seloParceiros ? '' : ' hidden' ?>"><?= $seloParceiros ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($pode('comunidades')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-comunidade" data-aba="comunidade" data-conteudo-modo="comunidade" aria-controls="tab-conteudo" aria-selected="<?= $abaAtiva === 'comunidade' ? 'true' : 'false' ?>">
                        <span data-icon="users"></span><span>Comunidades</span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($temGrupoConteudo): ?>
                    <span class="a-nav-group">Conteúdo</span>
                    <?php if ($pode('conteudo_catalogo')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-catalogo" data-aba="catalogo" data-conteudo-modo="catalogo" aria-controls="tab-conteudo" aria-selected="<?= $abaAtiva === 'catalogo' ? 'true' : 'false' ?>">
                        <span data-icon="scroll"></span><span>Catálogo do jogo</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($pode('conteudo_promocoes')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-promocao" data-aba="promocao" data-conteudo-modo="promocao" aria-controls="tab-conteudo" aria-selected="<?= $abaAtiva === 'promocao' ? 'true' : 'false' ?>">
                        <span data-icon="gift"></span><span>Códigos promocionais</span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($temGrupoGovernanca): ?>
                    <span class="a-nav-group">Governança</span>
                    <?php if ($podeContas): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-conta" data-aba="conta" data-conteudo-modo="conta" aria-controls="tab-conteudo" aria-selected="<?= $abaAtiva === 'conta' ? 'true' : 'false' ?>">
                        <span data-icon="user"></span><span>Contas e Perfis</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($pode('auditoria')): ?>
                    <button type="button" role="tab" class="a-nav-item" id="t-auditoria" data-aba="auditoria" data-conteudo-modo="auditoria" aria-controls="tab-conteudo" aria-selected="<?= $abaAtiva === 'auditoria' ? 'true' : 'false' ?>">
                        <span data-icon="lock"></span><span>Auditoria do banco</span>
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </nav>

            <div class="a-sidebar-foot">
                <span>Área administrativa · EcoCity</span>
            </div>
        </aside>

        <div class="a-workspace">
            <header class="a-topbar">
                <div class="a-topbar-left">
                    <button type="button" class="a-menu-btn" id="a-menu-abrir" aria-label="Abrir menu" aria-controls="a-sidebar" aria-expanded="false">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>

                    <div class="a-admin-info">
                        <div class="a-admin-title-row">
                            <h1><?= e($admDados['nome']) ?></h1>
                            <span class="a-role-badge is-<?= e($admin['perfil']) ?>"><?= e($admin['perfilNome']) ?></span>
                        </div>
                        <span class="a-admin-sub"><?= e($admDados['email']) ?><?= !empty($admin['perfilResumo']) ? ' · ' . e($admin['perfilResumo']) : '' ?></span>
                    </div>
                </div>

                <div class="a-topbar-right">
                    <span class="a-live-badge" id="a-sync-status" title="A administração verifica novidades automaticamente a cada 10 segundos">
                        <span class="a-live-dot" aria-hidden="true"></span>
                        <span id="a-sync-texto">Atualizado agora · <?= date('H:i') ?></span>
                    </span>
                    <?php if ($temJogador): ?>
                        <a class="btn btn-outline btn-sm" href="jogo.php">Voltar ao jogo</a>
                    <?php endif; ?>
                    <form method="post" action="logout.php">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline btn-sm">Sair</button>
                    </form>
                </div>
            </header>

            <main class="a-main">
                <div class="admin-novidades" id="admin-novidades" role="status" hidden>
                    <span id="admin-novidades-texto"></span>
                    <button type="button" class="btn btn-primary btn-sm" id="admin-novidades-atualizar">Atualizar</button>
                </div>

                <?php if ($aviso !== ''): ?>
                    <div class="alert <?= $tipoAviso === 'ok' ? 'alert-success' : 'alert-error' ?>" role="status"><?= e($aviso) ?></div>
                <?php endif; ?>

        <?php if ($pode('contestacoes')): ?>
        <!-- ABA 1: CONTESTAÇÕES (RN94) e registros que a IA não analisou -->
        <section id="tab-aprovacoes" role="tabpanel" aria-labelledby="t-aprovacoes" class="tab-panel" <?= $abaAtiva === 'aprovacoes' ? '' : 'hidden' ?>>
            <div class="section-head">
                <h2>Contestações</h2>
                <p class="muted">A IA aprova as fotos que comprovam a ação e rejeita as demais com o motivo. Quando o jogador discorda, ele contesta (até <?= CONTESTACAO_PRAZO_DIAS ?> dias depois) e a decisão é sua: aceitar aprova o registro e paga o valor com foto; manter a rejeição exige uma resposta e é final.</p>
            </div>

            <?php if (!$contestacoes): ?>
                <div class="card empty-state">Nenhuma contestação até agora.</div>
            <?php else: ?>
            <div class="admin-itens" id="lista-contestacoes" data-filtro-inicial="<?= $contestacoesAbertas ? 'aberta' : 'todos' ?>">
                <?php foreach ($contestacoes as $i => $c): ?>
                <details class="admin-item item-<?= $c['status'] === 'aberta' ? ($c['proprio'] ? 'proprio' : 'pendente') : ($c['status'] === 'aceita' ? 'aprovado' : 'rejeitado') ?>" <?= $i === 0 && $c['status'] === 'aberta' && !$c['proprio'] ? 'open' : '' ?>
                         data-busca="<?= e($c['atividade'] . ' ' . $c['jogador'] . ' ' . $c['cidade'] . ' ' . $c['categoriaNome']) ?>"
                         data-filtro="<?= e($c['status']) ?>">
                    <summary class="admin-item-resumo">
                        <span class="cat-dot cat-<?= e($c['categoria']) ?>" aria-hidden="true"></span>
                        <span class="admin-item-titulo"><strong><?= e($c['atividade']) ?></strong>
                            <span class="muted"><?= e($c['jogador']) ?> · <?= e($c['cidade']) ?></span></span>
                        <span class="admin-item-meta">contestado há <?= $horas(max(0, time() - $c['contestadoEm'])) ?></span>
                        <?php if ($c['proprio']): ?><span class="tag tag-neutral">Sua contestação</span><?php endif; ?>
                        <?php if ($c['temFotoNova']): ?><span class="tag tag-neutral">Foto nova</span><?php endif; ?>
                        <span class="tag <?= ['aberta' => 'tag-warning', 'aceita' => 'tag-success', 'negada' => 'tag-error'][$c['status']] ?>"><?= ['aberta' => 'Aberta', 'aceita' => 'Aceita', 'negada' => 'Negada'][$c['status']] ?></span>
                    </summary>
                    <div class="admin-item-corpo review-card contest-card">
                        <div class="contest-fotos">
                            <figure class="review-photo">
                                <a href="api/evidencia.php?registro=<?= $c['registroId'] ?>" target="_blank" rel="noopener" title="Abrir foto em tamanho real">
                                    <img src="api/evidencia.php?registro=<?= $c['registroId'] ?>" alt="Foto enviada por <?= e($c['jogador']) ?> para <?= e($c['atividade']) ?>" loading="lazy">
                                </a>
                                <figcaption>Foto do registro</figcaption>
                            </figure>
                            <?php if ($c['temFotoNova']): ?>
                            <figure class="review-photo">
                                <a href="api/evidencia.php?contestacao=<?= $c['id'] ?>" target="_blank" rel="noopener" title="Abrir foto em tamanho real">
                                    <img src="api/evidencia.php?contestacao=<?= $c['id'] ?>" alt="Foto nova enviada na contestação por <?= e($c['jogador']) ?>" loading="lazy">
                                </a>
                                <figcaption>Foto nova da contestação</figcaption>
                            </figure>
                            <?php endif; ?>
                        </div>
                        <div class="review-body">
                            <span class="cat-badge cat-<?= e($c['categoria']) ?>"><?= e($c['categoriaNome']) ?></span>
                            <p class="review-meta">Registrado em <?= $dataHora($c['registradoEm']) ?> · contestado em <?= $dataHora($c['contestadoEm']) ?></p>
                            <?php if ($c['descricao']): ?><blockquote class="review-desc"><?= e($c['descricao']) ?></blockquote><?php endif; ?>
                            <?php if ($c['ia']): ?>
                                <div class="ia-parecer">
                                    <strong><span data-icon="help"></span> Por que a IA rejeitou</strong>
                                    <?php if ($c['ia']['descricaoFoto']): ?><p><span class="muted">Na foto:</span> <?= e($c['ia']['descricaoFoto']) ?></p><?php endif; ?>
                                    <p><span class="muted">Avaliação:</span> <?= e($c['ia']['motivo']) ?> <span class="muted">(certeza <?= (int) round($c['ia']['confianca'] * 100) ?>%)</span></p>
                                </div>
                            <?php elseif ($c['motivoOriginal']): ?>
                                <p class="review-meta">Motivo da rejeição: <?= e($c['motivoOriginal']) ?></p>
                            <?php endif; ?>
                            <div class="contest-texto">
                                <strong>O jogador diz:</strong>
                                <p><?= nl2br(e($c['texto'])) ?></p>
                            </div>

                            <?php if ($c['status'] !== 'aberta'): ?>
                                <p class="form-hint"><?= $c['status'] === 'aceita' ? 'Aceita' : 'Rejeição mantida' ?> por <?= e($c['admin'] ?? '—') ?> em <?= $dataHora($c['decididaEm']) ?><?= $c['resposta'] ? ': ' . e($c['resposta']) : '.' ?></p>
                            <?php elseif ($c['proprio']): ?>
                                <p class="form-hint">Esta contestação é sua. Outro administrador decide.</p>
                            <?php else: ?>
                                <div class="review-actions">
                                    <form method="post" action="admin.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="contestacao_id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="acao" value="aceitar">
                                        <button type="submit" class="btn btn-primary"><span data-icon="check"></span>Aceitar e aprovar</button>
                                    </form>
                                    <details class="reject-box">
                                        <summary class="btn btn-danger-outline"><span data-icon="close"></span>Manter rejeição</summary>
                                        <form method="post" action="admin.php" class="reject-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="contestacao_id" value="<?= $c['id'] ?>">
                                            <input type="hidden" name="acao" value="negar">
                                            <label for="resposta-<?= $c['id'] ?>">Resposta (o jogador verá esta mensagem)</label>
                                            <textarea id="resposta-<?= $c['id'] ?>" name="resposta" class="form-input" rows="2" maxlength="<?= CONTESTACAO_RESPOSTA_MAX ?>" required placeholder="Ex.: a foto não mostra a muda plantada na terra."></textarea>
                                            <button type="submit" class="btn btn-danger-outline">Confirmar: manter rejeição</button>
                                        </form>
                                    </details>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($pendentes): ?>
            <div class="section-head">
                <h2>Registros aguardando decisão</h2>
                <p class="muted">Fotos que a IA ainda não analisou (fora do ar: aprovação automática <?= PRAZO_APROVACAO_HORAS ?> h após o envio) ou que ela achou duvidosas antes da contestação existir (<?= IA_PRAZO_DUVIDA_HORAS ?> h).</p>
            </div>
            <div class="admin-itens" id="lista-pendentes">
                <?php foreach ($pendentes as $i => $p): ?>
                <details class="admin-item item-<?= $p['proprio'] ? 'proprio' : 'pendente' ?>" <?= $i === 0 ? 'open' : '' ?>
                         data-busca="<?= e($p['atividade'] . ' ' . $p['jogador'] . ' ' . $p['cidade'] . ' ' . $p['categoriaNome']) ?>"
                         data-filtro="<?= $p['proprio'] ? 'meus' : 'outros' ?><?= $p['ia'] ? ' ia' : '' ?>">
                    <summary class="admin-item-resumo">
                        <span class="cat-dot cat-<?= e($p['categoria']) ?>" aria-hidden="true"></span>
                        <span class="admin-item-titulo"><strong><?= e($p['atividade']) ?></strong>
                            <span class="muted"><?= e($p['jogador']) ?> · <?= e($p['cidade']) ?></span></span>
                        <span class="admin-item-meta">enviado há <?= $horas(max(0, time() - $p['enviadoEm'])) ?> · aprova sozinho em <?= $horas(max(0, $p['prazo'] - time())) ?></span>
                        <?php if ($p['proprio']): ?><span class="tag tag-neutral">Seu registro</span><?php endif; ?>
                        <?php if (!$p['temFoto']): ?><span class="tag tag-neutral">Sem foto</span><?php endif; ?>
                        <?php if ($p['ia']): ?><span class="tag tag-warning" title="Parecer da IA">IA: <?= e(['aprovar' => 'pouca certeza', 'duvida' => 'duvidosa', 'nao_mostra' => 'não mostra', 'erro' => 'não analisou'][$p['ia']['veredito']] ?? '') ?> · <?= (int) round($p['ia']['confianca'] * 100) ?>%</span><?php endif; ?>
                    </summary>
                    <div class="admin-item-corpo review-card">
                    <div class="review-photo">
                        <?php if ($p['temFoto']): ?>
                            <a href="api/evidencia.php?registro=<?= $p['id'] ?>" target="_blank" rel="noopener" title="Abrir foto em tamanho real">
                                <img src="api/evidencia.php?registro=<?= $p['id'] ?>" alt="Foto enviada por <?= e($p['jogador']) ?> para <?= e($p['atividade']) ?>" loading="lazy">
                            </a>
                        <?php else: ?>
                            <div class="review-nophoto" data-icon="image">Sem foto</div>
                        <?php endif; ?>
                    </div>
                    <div class="review-body">
                        <span class="cat-badge cat-<?= e($p['categoria']) ?>"><?= e($p['categoriaNome']) ?></span>
                        <p class="review-meta">Enviado em <?= $dataHora($p['enviadoEm']) ?> · aprovação automática em <strong><?= $dataHora($p['prazo']) ?></strong></p>
                        <?php if ($p['descricao']): ?>
                            <blockquote class="review-desc"><?= e($p['descricao']) ?></blockquote>
                        <?php endif; ?>
                        <?php if ($p['ia']): ?>
                            <div class="ia-parecer">
                                <strong><span data-icon="help"></span> Parecer da IA</strong>
                                <?php if ($p['ia']['descricaoFoto']): ?><p><span class="muted">Na foto:</span> <?= e($p['ia']['descricaoFoto']) ?></p><?php endif; ?>
                                <p><span class="muted">Avaliação:</span> <?= e($p['ia']['motivo']) ?> <span class="muted">(certeza <?= (int) round($p['ia']['confianca'] * 100) ?>%)</span></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($p['proprio']): ?>
                            <p class="form-hint">Este registro é seu. Outro administrador decide, ou ele será aprovado automaticamente.</p>
                        <?php else: ?>
                            <div class="review-actions">
                                <form method="post" action="admin.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="registro_id" value="<?= $p['id'] ?>">
                                    <input type="hidden" name="acao" value="aprovar">
                                    <button type="submit" class="btn btn-primary"><span data-icon="check"></span>Aprovar</button>
                                </form>
                                <details class="reject-box">
                                    <summary class="btn btn-danger-outline"><span data-icon="close"></span>Rejeitar</summary>
                                    <form method="post" action="admin.php" class="reject-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="registro_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="acao" value="rejeitar">
                                        <label for="motivo-<?= $p['id'] ?>">Motivo (o jogador verá esta mensagem)</label>
                                        <textarea id="motivo-<?= $p['id'] ?>" name="motivo" class="form-input" rows="2" maxlength="<?= MOTIVO_MAX ?>" required placeholder="Ex.: a foto não mostra a atividade realizada."></textarea>
                                        <button type="submit" class="btn btn-danger-outline">Confirmar rejeição</button>
                                    </form>
                                </details>
                            </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3 class="card-title"><span data-icon="scroll"></span> Histórico de decisões</h3>
                <div id="decisoes-app"><p class="empty-state">Carregando…</p></div>
                <script type="application/json" id="decisoes-dados"><?= $json($decisoes) ?></script>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($pode('visao_geral')): ?>
        <!-- ABA 2: VISÃO GERAL -->
        <section id="tab-visao" role="tabpanel" aria-labelledby="t-visao" class="tab-panel" <?= $abaAtiva === 'visao' ? '' : 'hidden' ?>>
            <div class="section-head">
                <h2>Dados do EcoCity</h2>
                <p class="muted">Números reais do banco de dados, sempre somados: nenhum jogador aparece. Recortes com menos de 5 jogadores mostram "poucos dados".</p>
            </div>

            <div id="estatisticas-app" class="est-app">
                <div class="est-barra">
                    <div class="conteudo-nav est-secoes" role="tablist" aria-label="Seções das estatísticas">
                        <button type="button" class="chip" role="tab" data-secao="resumo" aria-controls="est-painel">Resumo</button>
                        <button type="button" class="chip" role="tab" data-secao="pratica" aria-controls="est-painel">Aprenda → Pratique</button>
                        <button type="button" class="chip" role="tab" data-secao="aprendizado" aria-controls="est-painel">Aprendizado</button>
                        <button type="button" class="chip" role="tab" data-secao="acoes" aria-controls="est-painel">Ações</button>
                        <button type="button" class="chip" role="tab" data-secao="engajamento" aria-controls="est-painel">Engajamento</button>
                        <button type="button" class="chip" role="tab" data-secao="cidade" aria-controls="est-painel">Cidade e comunidades</button>
                    </div>
                    <div class="est-filtros">
                        <label for="est-periodo" class="sr-only">Período</label>
                        <select id="est-periodo" class="form-input">
                            <option value="7">Últimos 7 dias</option>
                            <option value="30" selected>Últimos 30 dias</option>
                            <option value="90">Últimos 90 dias</option>
                            <option value="0">Desde o início</option>
                        </select>
                        <a id="est-csv" class="btn btn-outline btn-sm" href="#" download><span data-icon="scroll"></span> Baixar CSV</a>
                    </div>
                </div>
                <div id="est-painel" role="tabpanel" aria-live="polite"></div>
            </div>

            <div id="est-totais">
            <div class="kpi-grid">
                <div class="card kpi"><span class="kpi-label">Jogadores</span><strong class="kpi-value"><?= $numero($stats['jogadores']) ?></strong></div>
                <div class="card kpi"><span class="kpi-label">Atividades aprovadas</span><strong class="kpi-value"><?= $numero($stats['registros']['aprovado'] ?? 0) ?></strong><span class="kpi-sub">de <?= $numero($totalRegistros) ?> registros</span></div>
                <div class="card kpi"><span class="kpi-label">Aguardando decisão</span><strong class="kpi-value"><?= $numero($stats['registros']['pendente'] ?? 0) ?></strong><span class="kpi-sub"><?= $numero($stats['registros']['rejeitado'] ?? 0) ?> rejeitados</span></div>
                <div class="card kpi"><span class="kpi-label">XP distribuído</span><strong class="kpi-value"><?= $numero($stats['xpDistribuido']) ?></strong><span class="kpi-sub"><?= $numero($stats['creditosDistrib']) ?> créditos verdes</span></div>
            </div>

            <div class="card">
                <h3 class="card-title"><span data-icon="droplet"></span> Impacto real estimado</h3>
                <?php if (!$stats['impactoReal']): ?>
                    <p class="muted">Os valores de impacto real das atividades (ex.: litros de água, kWh) ainda não foram definidos.
                        Quando forem preenchidos em <code>atividade_sustentavel.impacto_real_quantidade</code> e <code>impacto_real_unidade</code>, os totais aparecem aqui.</p>
                <?php else: ?>
                    <div class="kpi-grid">
                        <?php foreach ($stats['impactoReal'] as $unidade => $total): ?>
                            <div class="kpi kpi-plain"><strong class="kpi-value"><?= $numero($total, $total == floor($total) ? 0 : 1) ?></strong><span class="kpi-label"><?= e($unidade) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <p class="form-hint">Estimativa: soma do impacto de cada atividade aprovada (valor fixo da atividade ou a quantidade informada pelo parceiro).</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3 class="card-title"><span data-icon="clipboard"></span> Atividades aprovadas por tipo</h3>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead><tr><th scope="col">Atividade</th><th scope="col">Aprovadas</th><th scope="col">Autodeclaradas</th><th scope="col">Com evidência</th><th scope="col">Com parceiro</th><th scope="col">Impacto real estimado</th></tr></thead>
                        <tbody>
                        <?php foreach ($stats['porAtividade'] as $a): ?>
                            <tr>
                                <td><span class="cat-dot cat-<?= e($a['categoria']) ?>"></span><?= e($a['nome']) ?></td>
                                <td><strong><?= $numero($a['aprovados']) ?></strong></td>
                                <td><?= $numero($a['autodeclarados']) ?></td>
                                <td><?= $numero($a['com_evidencia']) ?></td>
                                <td><?= $numero($a['com_parceiro']) ?></td>
                                <td><?= $a['impacto_total'] !== null ? $numero($a['impacto_total'], $a['impacto_total'] == floor($a['impacto_total']) ? 0 : 1) . ' ' . e($a['impacto_real_unidade']) : '<span class="muted">não definido</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="admin-two-col">
                <div class="card">
                    <h3 class="card-title"><span data-icon="pin"></span> Cidades por nível</h3>
                    <ul class="stat-bars">
                        <?php $maxCidades = max(1, ...array_column($stats['cidadesPorNivel'], 'total')); ?>
                        <?php foreach ($stats['cidadesPorNivel'] as $n): ?>
                            <li>
                                <span class="stat-label">Nível <?= (int) $n['nivel'] ?> · <?= e($n['nome']) ?></span>
                                <span class="stat-track"><span class="stat-fill stage-fill-<?= (int) $n['nivel'] ?>" style="width: <?= round($n['total'] / $maxCidades * 100) ?>%"></span></span>
                                <strong><?= $numero($n['total']) ?></strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="leaf"></span> Média dos indicadores</h3>
                    <ul class="stat-bars">
                        <?php foreach ($stats['mediaIndicadores'] as $i): $chave = INDICADOR_CHAVE[(int) $i['indicador_ambiental_id']] ?? ''; ?>
                            <li>
                                <span class="stat-label"><?= e($i['nome']) ?></span>
                                <span class="stat-track"><span class="stat-fill fill-<?= e($chave) ?>" style="width: <?= (int) $i['media'] ?>%"></span></span>
                                <strong><?= (int) $i['media'] ?>/100</strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($secoesConteudo): ?>
        <!-- ABA CONTEÚDO / OPERAÇÃO / GOVERNANÇA (montada por assets/js/ui/admin-conteudo.js) -->
        <section id="tab-conteudo" role="tabpanel" aria-labelledby="t-<?= e($conteudoModoInicial ?: 'catalogo') ?>" class="tab-panel" <?= $abaEhConteudo ? '' : 'hidden' ?>>
            <div class="section-head">
                <h2 id="conteudo-titulo"><?= e($conteudoTituloInicial) ?></h2>
                <p class="muted" id="conteudo-subtitulo"><?= e($conteudoSubtituloInicial) ?></p>
            </div>
            <div id="conteudo-app"></div>
        </section>
        <?php endif; ?>

        <?php if ($pode('denuncias')): ?>
        <!-- ABA 4: DENÚNCIAS DE MENSAGENS (RN58 / RN91) -->
        <section id="tab-denuncias" role="tabpanel" aria-labelledby="t-denuncias" class="tab-panel" <?= $abaAtiva === 'denuncias' ? '' : 'hidden' ?>>
            <div class="section-head">
                <h2>Denúncias</h2>
                <p class="muted">Mensagens entre amigos (RN58) e mensagens e tópicos de comunidades (RN64–RN65). A IA resolve os casos claros automaticamente (ocultando conteúdo ofensivo mantendo a prova) e deixa as dúvidas e decisões de bloqueio de conta com você.</p>
            </div>
            <?php if (!$todasDenuncias): ?>
                <div class="card empty-state">Nenhuma denúncia aberta ou analisada pela IA.</div>
            <?php else: ?>
            <div class="admin-itens" id="lista-denuncias" data-filtro-inicial="<?= ($denuncias || $denunciasComunidade) ? 'aberta' : 'todos' ?>">
                <?php foreach ($todasDenuncias as $d):
                    $amigos = $d['origem'] === 'amigos';
                    $campo = $amigos ? 'denuncia_id' : 'comunidade_denuncia_id';
                    $resolvidaIa = !empty($d['decididaPorIa']);
                    $ia = $d['ia'] ?? null;
                    $classeItem = $resolvidaIa ? (($ia['acao'] ?? '') === 'ocultou' ? 'rejeitado' : 'aprovado') : 'denuncia';
                    $tagIa = $ia ? [
                        'ocultou'  => ['tag-error', 'IA: ocultou'],
                        'arquivou' => ['tag-success', 'IA: improcedente'],
                        'fila'     => ['tag-warning', 'IA: dúvida'],
                        'erro'     => ['tag-neutral', 'IA: erro'],
                    ][$ia['acao'] ?? ''] ?? ['tag-neutral', 'IA'] : null;
                ?>
                <details class="admin-item item-<?= $classeItem ?>"
                         data-busca="<?= e($d['autor'] . ' ' . $d['denunciante'] . ' ' . ($d['comunidade'] ?? '') . ' ' . ($d['titulo'] ?? '') . ' ' . $d['texto']) ?>"
                         data-filtro="<?= ($resolvidaIa ? 'ia_resolvida' : 'aberta') . ' ' . $d['origem'] ?>">
                    <summary class="admin-item-resumo">
                        <span class="tag tag-neutral"><?= $amigos ? 'Conversa' : ($d['tipo'] === 'mensagem' ? 'Comunidade' : 'Tópico do mural') ?></span>
                        <span class="admin-item-titulo"><strong><?= e($d['autor']) ?></strong>
                            <span class="muted">“<?= e($trecho(($d['titulo'] ?? null) !== null ? $d['titulo'] . ' — ' . $d['texto'] : $d['texto'])) ?>”</span></span>
                        <span class="admin-item-meta">por <?= e($d['denunciante']) ?><?= $amigos ? '' : ' · ' . e($d['comunidade']) ?> · <?= $dataHora($d['data']) ?></span>
                        <?php if ($tagIa): ?>
                            <span class="tag <?= $tagIa[0] ?>" title="Parecer da IA"><?= e($tagIa[1]) ?><?= ($ia['confianca'] ?? 0) > 0 ? ' · ' . (int) round($ia['confianca'] * 100) . '%' : '' ?></span>
                        <?php endif; ?>
                    </summary>
                    <div class="admin-item-corpo">
                    <p class="review-meta"><?php if (!$amigos): ?>Comunidade <strong><?= e($d['comunidade']) ?></strong> · <?php endif; ?>denunciado por <strong><?= e($d['denunciante']) ?></strong> em <?= $dataHora($d['data']) ?></p>
                    <?php if ($d['motivo']): ?>
                        <p class="review-meta">Motivo: <?= e($d['motivo']) ?></p>
                    <?php endif; ?>
                    <?php if ($d['contexto']): ?>
                        <ol class="denuncia-contexto" aria-label="Mensagens anteriores da conversa">
                            <?php foreach ($d['contexto'] as $c): ?>
                                <li><strong><?= e($c['apelido']) ?>:</strong> <?= e($c['texto']) ?> <span class="muted">(<?= $dataHora($c['data']) ?>)</span></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                    <blockquote class="review-desc denuncia-texto"><strong><?= e($d['autor']) ?>:</strong>
                        <?php if (($d['titulo'] ?? null) !== null): ?><strong><?= e($d['titulo']) ?></strong> — <?php endif; ?><?= e($d['texto']) ?>
                        <span class="muted">(<?= $dataHora($d['enviadaEm']) ?>)</span><?php if (!empty($d['removido'])): ?> <span class="tag tag-neutral"><?= $d['tipo'] === 'topico' ? 'Apagado do mural' : 'Conteúdo ocultado' ?></span><?php endif; ?></blockquote>
                    <?php if ($ia): ?>
                        <div class="ia-parecer">
                            <strong><span data-icon="help"></span> Parecer da IA</strong>
                            <p><span class="muted">Categoria:</span> <?= e(ia_denuncia_rotulo($ia['categoria'] ?? '')) ?> <span class="muted">(certeza <?= (int) round(($ia['confianca'] ?? 0) * 100) ?>%)</span></p>
                            <p><span class="muted">Avaliação:</span> <?= e($ia['motivo'] ?? '') ?></p>
                            <?php if ($resolvidaIa): ?>
                                <p><span class="muted">Decidido por:</span> <span class="tag <?= ($ia['acao'] ?? '') === 'ocultou' ? 'tag-error' : 'tag-success' ?>">IA</span>
                                    <?= ($ia['acao'] ?? '') === 'ocultou' ? 'Conteúdo ocultado automaticamente' : 'Arquivada como improcedente' ?> em <?= $dataHora($d['resolvidaEm'] ?? $d['data']) ?>.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="review-actions">
                        <?php if ($resolvidaIa): ?>
                            <form method="post" action="admin.php" data-confirma="<?= ($ia['acao'] ?? '') === 'ocultou' ? 'Reabrir esta denúncia para revisão humana? O conteúdo ocultado voltará a ficar visível.' : 'Reabrir esta denúncia para revisão humana?' ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="<?= $campo ?>" value="<?= $d['id'] ?>">
                                <input type="hidden" name="acao" value="reabrir">
                                <button type="submit" class="btn btn-outline"><span data-icon="clipboard"></span>Reabrir para revisão</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="admin.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="<?= $campo ?>" value="<?= $d['id'] ?>">
                                <input type="hidden" name="acao" value="resolver">
                                <button type="submit" class="btn btn-primary"><span data-icon="check"></span>Resolver (manter)</button>
                            </form>
                            <?php if ($d['tipo'] === 'mensagem' && empty($d['removido']) && denuncia_ia_banco_pronto()): ?>
                            <form method="post" action="admin.php" data-confirma="Resolver esta denúncia e ocultar a mensagem para os jogadores?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="<?= $campo ?>" value="<?= $d['id'] ?>">
                                <input type="hidden" name="acao" value="ocultar">
                                <button type="submit" class="btn btn-outline"><span data-icon="close"></span>Resolver e ocultar mensagem</button>
                            </form>
                            <?php elseif (!$amigos && $d['tipo'] === 'topico' && empty($d['removido'])): ?>
                            <form method="post" action="admin.php" data-confirma="Remover este tópico do mural da comunidade?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="comunidade_denuncia_id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="acao" value="remover">
                                <button type="submit" class="btn btn-outline"><span data-icon="close"></span>Resolver e remover tópico</button>
                            </form>
                            <?php endif; ?>
                            <?php if (!$d['autorAtivo']): ?>
                                <span class="tag tag-neutral">Conta do autor já bloqueada</span>
                            <?php elseif (!empty($d['autorAdmin']) && !$pode('contas_admin')): ?>
                                <span class="tag tag-neutral">Conta administrativa (só Admin Master bloqueia)</span>
                            <?php else: ?>
                            <form method="post" action="admin.php" data-confirma="Bloquear a conta de <?= e($d['autor']) ?>? Ela não poderá mais entrar no jogo.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="<?= $campo ?>" value="<?= $d['id'] ?>">
                                <input type="hidden" name="acao" value="bloquear">
                                <button type="submit" class="btn btn-danger-outline"><span data-icon="lock"></span>Resolver e bloquear conta</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($pode('parceiros')): ?>
        <!-- ABA 5: PARCEIROS (RN69) -->
        <section id="tab-parceiros" role="tabpanel" aria-labelledby="t-parceiros" class="tab-panel" <?= $abaAtiva === 'parceiros' ? '' : 'hidden' ?>>
            <div class="section-head">
                <h2>Estabelecimentos parceiros</h2>
                <p class="muted">Parceiros geram códigos/QR de uso único que confirmam ações reais dos jogadores (150% da recompensa). Confira os dados antes de aprovar e marque só as atividades que fazem sentido para o estabelecimento.</p>
            </div>
            <?php if ($pedidosAtividade): ?>
            <div class="card partner-pedidos-admin">
                <h3 class="card-title"><span data-icon="flag"></span> Pedidos de atividade nova (<?= count($pedidosAtividade) ?>)</h3>
                <ul class="admin-pedidos">
                    <?php foreach ($pedidosAtividade as $pd): ?>
                    <li>
                        <div><strong><?= e($pd['parceiro']) ?></strong> quer verificar <strong><?= e($pd['atividade']) ?></strong>
                            <span class="muted">(<?= e($pd['unidade']) ?>) · pedido em <?= $dataHora(strtotime($pd['data'])) ?><?= $pd['statusParceiro'] !== 'aprovado' ? ' · parceiro ' . e($pd['statusParceiro']) : '' ?></span></div>
                        <div class="review-actions">
                            <form method="post" action="admin.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="pedido_id" value="<?= $pd['id'] ?>">
                                <input type="hidden" name="acao" value="aprovar">
                                <button type="submit" class="btn btn-primary btn-sm"><span data-icon="check"></span>Aprovar</button>
                            </form>
                            <details class="reject-box">
                                <summary class="btn btn-danger-outline btn-sm"><span data-icon="close"></span>Recusar</summary>
                                <form method="post" action="admin.php" class="reject-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="pedido_id" value="<?= $pd['id'] ?>">
                                    <input type="hidden" name="acao" value="recusar">
                                    <label for="motivo-pedido-<?= $pd['id'] ?>">Motivo (o parceiro verá esta mensagem)</label>
                                    <textarea id="motivo-pedido-<?= $pd['id'] ?>" name="motivo" class="form-input" rows="2" maxlength="250" required></textarea>
                                    <button type="submit" class="btn btn-danger-outline">Confirmar recusa</button>
                                </form>
                            </details>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <?php if (!$parceiros): ?>
                <div class="card empty-state">Nenhum parceiro cadastrado. O cadastro é feito em <a href="parceiro-cadastro.php">parceiro-cadastro.php</a>.</div>
            <?php endif; ?>
            <div class="admin-itens" id="lista-parceiros" data-filtro-inicial="<?= $parceirosPendentes ? 'pendente' : 'todos' ?>">
                <?php foreach ($parceiros as $pc): ?>
                <details class="admin-item partner-<?= e($pc['status']) ?>"
                         data-busca="<?= e($pc['nome'] . ' ' . ($pc['responsavel'] ?? '') . ' ' . ($pc['email'] ?? '') . ' ' . $pc['endereco']) ?>"
                         data-filtro="<?= e($pc['status']) ?>">
                    <summary class="admin-item-resumo">
                        <span class="admin-item-titulo"><strong><?= e($pc['nome']) ?></strong>
                            <span class="muted"><?= e($pc['responsavel'] ?? '—') ?> · <?= e($pc['email'] ?? '—') ?></span></span>
                        <span class="admin-item-meta">cadastro em <?= $pc['cadastro'] ? date('d/m/Y', strtotime($pc['cadastro'])) : '—' ?><?= in_array($pc['status'], ['aprovado', 'bloqueado'], true) ? ' · ' . $numero($pc['verificacoes']) . ' verificações' : '' ?></span>
                        <span class="tag <?= ['pendente' => 'tag-warning', 'aprovado' => 'tag-success', 'recusado' => 'tag-neutral', 'bloqueado' => 'tag-error'][$pc['status']] ?>"><?= e(ucfirst($pc['status'])) ?></span>
                    </summary>
                    <div class="admin-item-corpo partner-card">
                    <p class="review-meta">Responsável: <strong><?= e($pc['responsavel'] ?? '—') ?></strong> · <?= e($pc['email'] ?? '—') ?> · cadastro em <?= $pc['cadastro'] ? $dataHora(strtotime($pc['cadastro'])) : '—' ?></p>
                    <p class="review-meta">Endereço: <?= e($pc['endereco']) ?></p>
                    <p class="review-desc"><?= e($pc['descricao']) ?></p>
                    <?php if ($pc['status'] === 'recusado' && $pc['motivo']): ?><p class="review-meta">Motivo da recusa: <?= e($pc['motivo']) ?></p><?php endif; ?>
                    <?php if ($pc['status'] === 'aprovado' || $pc['status'] === 'bloqueado'): ?><p class="review-meta"><?= $numero($pc['verificacoes']) ?> <?= $pc['verificacoes'] === 1 ? 'verificação feita' : 'verificações feitas' ?> · <?= $pc['funcionarios'] ?> <?= $pc['funcionarios'] === 1 ? 'funcionário ativo' : 'funcionários ativos' ?> · último atendimento <?= $pc['ultimoAtendimento'] ? $dataHora(strtotime($pc['ultimoAtendimento'])) : '—' ?></p><?php endif; ?>
                    <form method="post" action="admin.php" class="partner-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="parceiro_id" value="<?= $pc['id'] ?>">
                        <fieldset class="partner-acts-admin partner-acts-picker"
                                  data-seletor-atividades="<?= $pc['status'] === 'bloqueado' ? 'readonly' : 'edit' ?>"
                                  data-botao="Alterar atividades"
                                  data-titulo="Atividades de <?= e($pc['nome']) ?>"
                                  data-subtitulo="Defina quais atividades este parceiro está autorizado a confirmar.">
                            <legend>Atividades que pode verificar</legend>
                            <div class="act-picker-fallback">
                                <?php foreach ($atividadesVerificaveis as $av): ?>
                                    <label class="check-line"><input type="checkbox" name="atividades[]" value="<?= $av['id'] ?>"
                                        data-nome="<?= e($av['nome']) ?>"
                                        data-unidade="<?= e($av['unidade']) ?>"
                                        data-categoria="<?= e($av['categoria']) ?>"
                                        data-categoria-nome="<?= e($av['categoriaNome']) ?>"
                                        <?= in_array($av['id'], $pc['atividades'], true) ? 'checked' : '' ?> <?= $pc['status'] === 'bloqueado' ? 'disabled' : '' ?>> <?= e($av['nome']) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <div class="review-actions">
                            <?php if ($pc['status'] === 'pendente' || $pc['status'] === 'recusado'): ?>
                                <button type="submit" name="acao" value="aprovar" class="btn btn-primary"><span data-icon="check"></span>Aprovar</button>
                            <?php endif; ?>
                            <?php if ($pc['status'] === 'aprovado'): ?>
                                <button type="submit" name="acao" value="atividades" class="btn btn-outline">Salvar atividades</button>
                                <button type="submit" name="acao" value="bloquear" class="btn btn-danger-outline" data-confirma-botao="Bloquear <?= e($pc['nome']) ?>? Os códigos em aberto são cancelados."><span data-icon="lock"></span>Bloquear</button>
                            <?php endif; ?>
                            <?php if ($pc['status'] === 'bloqueado'): ?>
                                <button type="submit" name="acao" value="desbloquear" class="btn btn-outline">Desbloquear</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($pc['status'] === 'pendente'): ?>
                            <div class="partner-reject">
                                <label for="motivo-p<?= $pc['id'] ?>">Motivo da recusa</label>
                                <input id="motivo-p<?= $pc['id'] ?>" class="form-input" name="motivo" maxlength="250" placeholder="Ex.: endereço não confere">
                                <button type="submit" name="acao" value="recusar" class="btn btn-danger-outline btn-sm">Recusar cadastro</button>
                            </div>
                        <?php endif; ?>
                    </form>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

            </main>
        </div>
    </div>

    <script type="module" src="<?= asset('assets/js/admin.js') ?>"></script>
</body>
</html>
