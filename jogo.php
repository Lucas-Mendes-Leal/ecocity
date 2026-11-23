<?php
require __DIR__ . '/includes/bootstrap.php';
$usuarioId = exigir_login();

$ids = jogador_do_usuario($usuarioId);
if ($ids === null) {
    // Conta sem jogador/cidade: encerra a sessão para não ficar em estado inválido
    sair();
    redirect('login.php');
}
registrar_acesso((int) $ids['jogador_id']);   // jogador ativo hoje (RN81)
aprovar_vencidos((int) $ids['jogador_id']);
aplicar_queda_diaria(db(), (int) $ids['jogador_id']);   // -1 por dia em cada indicador (RN43)
sincronizar_cidade(db(), (int) $ids['jogador_id']);   // nível da cidade pelos indicadores atuais
$avisosMissoes = avaliar_missoes(db(), (int) $ids['jogador_id']);   // conclui / encerra períodos vencidos
avaliar_conquistas(db(), (int) $ids['jogador_id']);   // progresso anterior às conquistas

$dadosIniciais = dados_jogador($usuarioId, (int) $ids['jogador_id']) + [
    'versoes'      => versoes_jogador((int) $ids['jogador_id']),   // ponto de partida da sincronização (RN85)
    'parceiros'    => listar_parceiros_publicos(),
    'avisosMissoes' => $avisosMissoes,
    'niveis'       => niveis_jogador(),
    'regras'       => [
        'percentualAutodeclarada' => PERCENTUAL_AUTODECLARADA,
        'prazoAprovacaoHoras'     => PRAZO_APROVACAO_HORAS,
        'fotoMaxBytes'            => FOTO_MAX_BYTES,
        'quizNotaMinima'          => QUIZ_NOTA_MINIMA,
        'registrosPorAtividadeDia' => REGISTROS_POR_ATIVIDADE_DIA,
    ],
    'aprovacaoTeste' => (bool) config('debug'),   // botões de teste só em ambiente de teste
    'contaReativada' => !empty($_SESSION['aviso_reativada']),   // entrou de novo depois de desativar (RN93)
];
unset($_SESSION['aviso_reativada']);
$jogador = $dadosIniciais['jogador'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($jogador['cidade']) ?> — EcoCity</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/logo-ecocity-icone.png') ?>">
    <?= pwa_head() ?>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/game.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/city.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/quiz.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/comunidade.css') ?>">
    <?= importmap() ?>
</head>
<body class="game-body">

    <!-- CABEÇALHO DO JOGADOR -->
    <header class="game-header">
        <div class="container header-row">
            <h1 class="sr-only">EcoCity</h1>
            <!-- Avatar e apelido abrem "Minha conta" (dados, edição, senha e tutorial) -->
            <button type="button" class="player-profile" id="abrir-conta" aria-haspopup="dialog" aria-controls="modal-conta" title="Minha conta">
                <span class="avatar" id="player-avatar" aria-hidden="true">
                    <span class="avatar-face" data-icon="leaf"></span>
                    <span class="avatar-level" id="avatar-level"><?= (int) $jogador['nivel'] ?></span>
                </span>
                <span class="player-info">
                    <span class="player-name"><span id="player-name"><?= e($jogador['apelido']) ?></span><span class="player-conta-seta" data-icon="chevron-down" aria-hidden="true"></span></span>
                    <span class="level-badge"><span id="player-level">Nível <?= (int) $jogador['nivel'] ?></span> · <span id="player-city"><?= e($jogador['cidade']) ?></span></span>
                    <span class="sr-only">Abrir minha conta</span>
                </span>
            </button>
            <div class="header-actions">
                <button type="button" class="som-botao" id="som-botao" aria-pressed="false" title="Desligar o som" aria-label="Desligar o som"><span data-icon="volume"></span></button>
                <div class="currency-pill" id="currency-pill" title="Créditos verdes">
                    <span class="currency-coin" data-icon="coin" aria-hidden="true"></span>
                    <strong id="green-credits"><?= (int) $jogador['creditosVerde'] ?></strong>
                    <span class="currency-label">Créditos</span>
                </div>
                <?php if (administrador_id($usuarioId) !== null): ?>
                    <a class="btn btn-outline btn-sm" href="admin.php">Administração</a>
                <?php endif; ?>
                <form method="post" action="logout.php">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline btn-sm">Sair</button>
                </form>
            </div>
        </div>
    </header>

    <!-- XP DO JOGADOR -->
    <section class="xp-section" aria-label="Experiência do jogador">
        <div class="container">
            <div class="xp-row">
                <div class="xp-shield" id="xp-shield" title="Nível do jogador">
                    <span class="xp-shield-num" id="xp-level">1</span>
                    <span class="xp-shield-label">Nível</span>
                </div>
                <div class="xp-main">
                    <div class="xp-track" id="xp-track" role="progressbar" aria-label="XP para o próximo nível" aria-valuemin="0">
                        <div class="xp-fill" id="xp-fill"></div>
                        <div class="xp-pops" id="xp-pops" aria-hidden="true"></div>
                    </div>
                    <div class="xp-info">
                        <span><strong id="xp-current">0</strong> / <span id="xp-next">100</span> XP</span>
                        <span class="xp-falta" id="xp-falta"></span>
                    </div>
                </div>
                <div class="xp-shield is-next" title="Próximo nível" aria-hidden="true">
                    <span class="xp-shield-num" id="xp-level-next">2</span>
                    <span class="xp-shield-label" data-icon="lock"></span>
                </div>
            </div>
            <p class="next-unlock" id="next-unlock"></p>
        </div>
    </section>

    <!-- ABAS -->
    <nav class="game-nav" aria-label="Seções do jogo">
        <div class="container nav-row" role="tablist" id="game-tabs">
            <button type="button" role="tab" class="nav-tab" id="t-cidade" aria-controls="tab-cidade" aria-selected="true"><span data-icon="pin"></span>Minha Cidade</button>
            <button type="button" role="tab" class="nav-tab" id="t-missoes" aria-controls="tab-missoes" aria-selected="false"><span data-icon="clipboard"></span>Missões</button>
            <button type="button" role="tab" class="nav-tab" id="t-quizzes" aria-controls="tab-quizzes" aria-selected="false"><span data-icon="help"></span>Quizzes</button>
            <button type="button" role="tab" class="nav-tab" id="t-conquistas" aria-controls="tab-conquistas" aria-selected="false"><span data-icon="trophy"></span>Conquistas</button>
            <button type="button" role="tab" class="nav-tab" id="t-perfil" aria-controls="tab-perfil" aria-selected="false"><span data-icon="chart"></span>Meu Progresso</button>
            <button type="button" role="tab" class="nav-tab" id="t-amigos" aria-controls="tab-amigos" aria-selected="false"><span data-icon="mail"></span>Amigos <span class="nav-count hidden" id="friends-badge"></span></button>
            <button type="button" role="tab" class="nav-tab" id="t-comunidade" aria-controls="tab-comunidade" aria-selected="false"><span data-icon="users"></span>Comunidade <span class="nav-count hidden" id="community-badge"></span></button>
            <button type="button" role="tab" class="nav-tab" id="t-ecoguia" aria-controls="tab-ecoguia" aria-selected="false"><span data-icon="chat"></span>EcoGuia</button>
        </div>
    </nav>

    <main class="container game-content">

        <!-- Controles de demonstração: visíveis só com ?demo=1 -->
        <div class="demo-bar hidden" id="demo-bar">
            <span><strong>Modo demonstração</strong> — simular estágio de todos os indicadores:</span>
            <div class="demo-buttons" id="demo-stage-buttons"></div>
        </div>

        <!-- ABA 1: MINHA CIDADE -->
        <section id="tab-cidade" role="tabpanel" aria-labelledby="t-cidade" class="tab-panel">

            <div class="card eco-panel">
                <div class="eco-header">
                    <div>
                        <h2>Progresso Ecológico da Cidade</h2>
                        <p class="muted">O impacto das suas ações sustentáveis na saúde ambiental da sua cidade.</p>
                    </div>
                    <div class="eco-score"><strong id="eco-overall">0</strong>/100 <small>pontos</small></div>
                </div>
                <div class="bar-track" id="eco-main-track" role="progressbar" aria-label="Progresso ecológico geral" aria-valuemin="0" aria-valuemax="100">
                    <div class="bar-fill fill-overall" id="eco-main-fill"></div>
                </div>
                <div class="eco-categories" id="eco-categories"></div>
            </div>

            <section class="card today-card" id="today-card" aria-label="Hoje no EcoCity">
                <div class="today-head">
                    <h3><span data-icon="sun"></span> Hoje no EcoCity</h3>
                    <span class="today-sub" id="today-sub"></span>
                </div>
                <div class="today-grid" id="today-grid"></div>
            </section>

            <div class="city-frame">
                <div class="city-selos">
                    <div class="city-level-badge" id="city-level-badge"></div>
                    <div class="city-relogio" id="city-relogio" role="img" hidden></div>
                </div>
                <div class="city-zoom" role="group" aria-label="Zoom do mapa">
                    <button type="button" class="city-zoom-btn" id="zoom-in" aria-label="Aproximar" title="Aproximar">+</button>
                    <button type="button" class="city-zoom-btn" id="zoom-out" aria-label="Afastar" title="Afastar">&minus;</button>
                    <button type="button" class="city-zoom-btn" id="zoom-reset" aria-label="Ver a cidade inteira" title="Ver a cidade inteira">&#x2922;</button>
                </div>
                <div class="city-map" id="city-map"></div>

                <!-- Modo construção: lotes de expansão -->
                <div class="build-panel hidden" id="build-panel" aria-label="Construção">
                    <div class="build-head">
                        <strong><span data-icon="home"></span> Construir</strong>
                        <div class="build-lots" id="build-lots" role="group" aria-label="Lote"></div>
                        <button type="button" class="btn btn-outline btn-sm" id="build-close">Sair da construção</button>
                    </div>
                    <div class="build-cats" id="build-cats" role="group" aria-label="Categoria"></div>
                    <div class="build-palette" id="build-palette"></div>
                    <p class="build-pollution hidden" id="build-pollution"></p>
                    <div class="build-actions">
                        <p class="build-status" id="build-status" role="status"></p>
                        <button type="button" class="btn btn-outline btn-sm" id="build-rotate" title="Girar 90° (tecla R; Shift+R volta)">Girar</button>
                        <button type="button" class="btn btn-danger-outline btn-sm" id="build-remove" aria-pressed="false">Remover estruturas</button>
                        <button type="button" class="btn btn-primary btn-sm" id="build-confirm" disabled>Construir aqui</button>
                    </div>
                </div>

                <div class="city-toolbar" id="city-toolbar">
                    <p class="city-hint">Toque em um ponto da cidade para ver os detalhes da região.</p>
                    <div class="city-actions">
                        <span class="city-pop" id="city-pop" title="Habitantes: 5 + moradias, até 50"></span>
                        <button type="button" class="btn btn-secondary btn-sm city-collect hidden" id="city-collect"></button>
                        <button type="button" class="btn btn-primary btn-sm" id="build-open"><span data-icon="home"></span>Construir</button>
                    </div>
                </div>
                <div class="city-legend" id="city-legend"></div>
            </div>

        </section>

        <!-- ABA 2: MISSÕES -->
        <section id="tab-missoes" role="tabpanel" aria-labelledby="t-missoes" class="tab-panel" hidden>
            <div class="hero-band missions-hero">
                <div class="missions-hero-top">
                    <div class="missions-hero-title">
                        <span class="missions-hero-kicker" id="mh-semana">Semana</span>
                        <strong id="mh-frase">Comece registrando uma ação hoje!</strong>
                    </div>
                    <dl class="hero-stats">
                        <div><dt>Missões</dt><dd id="mh-missoes">0/0</dd></div>
                        <div><dt>Hoje</dt><dd id="mh-hoje">0/0</dd></div>
                        <div class="hero-stat-gold"><dt>Ainda dá para ganhar</dt><dd id="mh-ganhar">0 XP</dd></div>
                    </dl>
                </div>
                <div class="missions-hero-bar">
                    <div class="bar-track" id="mh-track" role="progressbar" aria-label="Progresso das metas da semana" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="bar-fill fill-gold" id="mh-fill"></div>
                    </div>
                    <span id="mh-pct">0%</span>
                </div>
            </div>

            <div class="conteudo-nav" role="tablist" aria-label="Partes da aba Missões" id="missions-tabs">
                <button type="button" role="tab" class="chip" id="mt-agir" aria-controls="mp-agir" aria-selected="true"><span data-icon="leaf"></span>Agir hoje <span class="chip-count" id="mt-agir-count"></span></button>
                <button type="button" role="tab" class="chip" id="mt-missoes" aria-controls="mp-missoes" aria-selected="false"><span data-icon="trophy"></span>Missões da semana <span class="chip-count" id="mt-missoes-count"></span></button>
                <button type="button" role="tab" class="chip" id="mt-registros" aria-controls="mp-registros" aria-selected="false"><span data-icon="scroll"></span>Meus registros <span class="nav-count hidden" id="mt-registros-badge"></span></button>
                <button type="button" role="tab" class="chip" id="mt-parceiros" aria-controls="mp-parceiros" aria-selected="false"><span data-icon="flag"></span>Parceiros</button>
            </div>

            <div role="tabpanel" id="mp-agir" aria-labelledby="mt-agir">
                <p class="muted tab-intro">Faça uma ação no mundo real e registre aqui. <strong>Sem foto</strong> você ganha na hora; <strong>com foto</strong> ganha o dobro depois da aprovação. Cada atividade pode ser registrada uma vez por dia.</p>
                <div class="registros-filtros" id="agir-filtros" role="group" aria-label="Filtrar atividades por área"></div>
                <div class="missions-grid" id="missions-grid"></div>
            </div>

            <div role="tabpanel" id="mp-missoes" aria-labelledby="mt-missoes" hidden>
                <section class="special-section" id="missions-special" aria-label="Missões especiais" hidden></section>
                <p class="muted tab-intro">Cumpra as metas até domingo para ganhar a recompensa extra. Meta não cumprida faz a área perder pontos.</p>
                <div class="weekly-grid" id="missions-current"></div>
                <section class="special-soon" id="missions-soon" aria-labelledby="missions-soon-title" hidden>
                    <h3 class="section-subtitle" id="missions-soon-title"><span data-icon="calendar"></span> Em breve: missões especiais</h3>
                    <ul class="special-soon-list" id="missions-soon-list"></ul>
                </section>
                <details class="card mission-results">
                    <summary><span data-icon="scroll"></span> Resultados anteriores</summary>
                    <ul class="mission-results-list" id="missions-results"></ul>
                </details>
            </div>

            <div role="tabpanel" id="mp-registros" aria-labelledby="mt-registros" hidden>
                <div class="registros-barra">
                    <div class="registros-filtros" id="registros-filtros" role="group" aria-label="Filtrar registros"></div>
                    <span id="registros-tamanho"></span>
                </div>
                <ol class="registros-list" id="registros-list"></ol>
                <nav class="pager" id="registros-pager" aria-label="Páginas dos meus registros" hidden></nav>
            </div>

            <!-- Verificação por parceiros (RN69–RN71) -->
            <div role="tabpanel" id="mp-parceiros" aria-labelledby="mt-parceiros" hidden>
                <p class="muted tab-intro">Faça a ação num estabelecimento parceiro (ex.: entregue recicláveis numa cooperativa). Ele gera um QR: aponte a câmera do celular, ou digite os 6 números abaixo. A atividade entra <strong>aprovada na hora e vale 150%</strong>.</p>
                <form class="card partner-code-form" id="partner-code-form" novalidate>
                    <h3 class="card-title"><span data-icon="star"></span> Tenho um código</h3>
                    <div class="partner-code-row">
                        <label class="sr-only" for="partner-code">Código de 6 números do parceiro</label>
                        <input id="partner-code" class="form-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000 000" required>
                        <button type="submit" class="btn btn-primary">Confirmar atividade</button>
                    </div>
                    <small class="form-hint">O código vale 30 minutos e só pode ser usado uma vez. Continua valendo o limite de 1 registro por atividade por dia.</small>
                </form>
                <h3 class="section-subtitle"><span data-icon="pin"></span> Onde validar</h3>
                <ul class="partner-places" id="partner-places"></ul>
            </div>
        </section>

        <!-- ABA 3: QUIZZES -->
        <section id="tab-quizzes" role="tabpanel" aria-labelledby="t-quizzes" class="tab-panel" hidden>
            <div id="quiz-list-view">
                <div class="quiz-destaques">
                    <div class="daily-quiz" id="daily-quiz"></div>
                    <div class="daily-quiz weekly-quiz" id="weekly-quiz"></div>
                </div>

                <div class="section-head">
                    <h2>Temas da Semana</h2>
                    <p class="muted">Três quizzes por temas ambientais que trocam toda segunda-feira. Teste seus conhecimentos e aprenda práticas para transformar sua cidade e seus hábitos.</p>
                </div>
                <div class="quizzes-grid" id="quizzes-grid"></div>

                <details class="card mission-results quiz-done hidden" id="quiz-done">
                    <summary><span data-icon="scroll"></span> Já feitos <span class="muted" id="quiz-done-count"></span></summary>
                    <p class="muted quiz-done-hint">Quizzes que você já concluiu e que estão fora da rotação. Refaça quando quiser para treinar (sem recompensa).</p>
                    <div class="quizzes-grid" id="quizzes-done-grid"></div>
                    <nav class="pager" id="quiz-done-pager" aria-label="Páginas dos quizzes já feitos" hidden></nav>
                </details>

                <div class="card quiz-history">
                    <h3><span data-icon="scroll"></span> Histórico de Tentativas</h3>
                    <div class="table-scroll">
                        <table class="history-table">
                            <thead>
                                <tr><th scope="col">Quiz</th><th scope="col">Data</th><th scope="col">Acertos</th><th scope="col">Pontuação</th><th scope="col">Situação</th></tr>
                            </thead>
                            <tbody id="quiz-history-body"></tbody>
                        </table>
                    </div>
                    <nav class="pager" id="quiz-history-pager" aria-label="Páginas do histórico de tentativas" hidden></nav>
                </div>
            </div>

            <div id="quiz-player-view" class="hidden">
                <div class="card quiz-player">
                    <div class="quiz-player-header">
                        <button type="button" class="btn btn-outline btn-sm" id="quiz-back"><span data-icon="back"></span>Voltar aos Quizzes</button>
                        <span class="quiz-progress-text">Pergunta <strong id="quiz-current">1</strong> de <strong id="quiz-total">1</strong></span>
                    </div>
                    <p class="quiz-player-title" id="quiz-player-title"></p>
                    <div class="bar-track bar-sm"><div class="bar-fill fill-overall" id="quiz-progress-fill"></div></div>
                    <h3 class="quiz-question" id="quiz-question" tabindex="-1"></h3>
                    <div class="quiz-options" id="quiz-options"></div>
                    <div class="quiz-feedback hidden" id="quiz-feedback" role="status">
                        <p class="quiz-feedback-title" id="quiz-feedback-title"></p>
                        <p id="quiz-feedback-text"></p>
                        <p class="quiz-feedback-fonte" id="quiz-feedback-fonte" hidden></p>
                    </div>
                    <div class="quiz-actions">
                        <button type="button" class="btn btn-primary hidden" id="quiz-next">Próxima pergunta</button>
                    </div>
                </div>
            </div>

            <div id="quiz-result-view" class="hidden">
                <div class="card quiz-result">
                    <div class="result-icon" id="result-icon"></div>
                    <h2 id="result-title" tabindex="-1">Quiz concluído!</h2>
                    <p class="muted" id="result-message"></p>
                    <div class="result-stats">
                        <div><strong id="result-score">0</strong><span>Pontos</span></div>
                        <div><strong id="result-hits">0/0</strong><span>Acertos</span></div>
                        <div><strong id="result-rewards">—</strong><span>Recompensa</span></div>
                    </div>
                    <p class="form-hint hidden" id="result-note">A recompensa deste quiz já foi recebida na primeira tentativa.</p>
                    <button type="button" class="btn btn-primary btn-block" id="result-finish">Voltar aos Quizzes</button>
                </div>
            </div>
        </section>

        <!-- ABA 4: CONQUISTAS -->
        <section id="tab-conquistas" role="tabpanel" aria-labelledby="t-conquistas" class="tab-panel" hidden>
            <div class="hero-band trophy-hero">
                <div class="trophy-top">
                    <span class="trophy-ring" id="tr-ring" role="img"><span id="tr-pct">0%</span></span>
                    <div class="trophy-info">
                        <span class="missions-hero-kicker">Sala de troféus</span>
                        <strong id="tr-count">0 de 0 conquistas</strong>
                        <span class="trophy-earned" id="tr-earned"></span>
                        <div class="trophy-last" id="tr-last"></div>
                    </div>
                </div>
                <dl class="hero-stats trophy-rarities" id="tr-rarities"></dl>
            </div>

            <div class="almost hidden" id="ach-almost-wrap">
                <h3 class="card-title"><span data-icon="star"></span> Quase lá</h3>
                <div class="almost-grid" id="ach-almost"></div>
            </div>

            <div class="registros-filtros" id="ach-filters" role="group" aria-label="Filtrar conquistas"></div>
            <div id="achievements-grid"></div>
        </section>

        <!-- ABA 5: PERFIL -->
        <section id="tab-perfil" role="tabpanel" aria-labelledby="t-perfil" class="tab-panel" hidden>
            <div class="hero-band profile-hero">
                <div class="friends-me">
                    <span class="friend-avatar friend-avatar-lg" id="pf-avatar" aria-hidden="true"></span>
                    <div class="friends-me-info">
                        <strong id="pf-nome"></strong>
                        <span class="profile-hero-sub" id="pf-sub"></span>
                        <span class="friends-code">Seu código: <code id="pf-codigo"></code>
                            <button type="button" class="friends-copy" id="pf-copiar"><span data-icon="clipboard"></span>Copiar</button></span>
                    </div>
                </div>
                <div class="profile-xp">
                    <div class="profile-xp-text"><span id="pf-xp-nivel"></span><span id="pf-xp-falta"></span></div>
                    <div class="profile-xp-track" role="progressbar" aria-label="XP até o próximo nível" id="pf-xp-track" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="pf-xp-fill"></span></div>
                </div>
                <dl class="hero-stats profile-hero-stats">
                    <div><dt>Ações aprovadas</dt><dd id="pf-acoes">0</dd></div>
                    <div><dt>XP total</dt><dd id="pf-xp">0</dd></div>
                    <div><dt>Créditos</dt><dd id="pf-creditos">0</dd></div>
                    <div><dt>Conquistas</dt><dd id="pf-conquistas">0</dd></div>
                </dl>
            </div>

            <div class="conteudo-nav profile-tabs" role="tablist" aria-label="Seções do meu progresso" id="profile-tabs">
                <button type="button" role="tab" class="chip" id="pt-resumo" aria-controls="pp-resumo" aria-selected="true"><span data-icon="leaf"></span>Resumo</button>
                <button type="button" role="tab" class="chip" id="pt-evolucao" aria-controls="pp-evolucao" aria-selected="false"><span data-icon="chart"></span>Evolução</button>
                <button type="button" role="tab" class="chip" id="pt-aprendizado" aria-controls="pp-aprendizado" aria-selected="false"><span data-icon="help"></span>Aprendizado</button>
                <button type="button" role="tab" class="chip" id="pt-extrato" aria-controls="pp-extrato" aria-selected="false"><span data-icon="coin"></span>Extrato</button>
            </div>

            <!-- Resumo -->
            <div role="tabpanel" id="pp-resumo" aria-labelledby="pt-resumo" class="profile-resumo">
                <div class="card impact-panel">
                    <div class="impact-panel-head">
                        <div>
                            <h3 class="card-title"><span data-icon="leaf"></span> Meu impacto</h3>
                            <p class="form-hint">Estimativas reais pelas atividades sustentáveis aprovadas, agrupadas por unidade física e hábito praticado.</p>
                        </div>
                        <div class="impact-panel-tools">
                            <div class="registros-filtros impact-periodos" role="group" aria-label="Período do impacto" id="profile-impact-periodos">
                                <button type="button" class="chip" data-impacto-periodo="tudo" aria-pressed="true">Desde o início</button>
                                <button type="button" class="chip" data-impacto-periodo="dias30" aria-pressed="false">Últimos 30 dias</button>
                                <button type="button" class="chip" data-impacto-periodo="semana" aria-pressed="false">Esta semana</button>
                            </div>
                            <a class="btn btn-outline btn-sm profile-report-link" href="relatorio.php" target="_blank" rel="noopener"><span data-icon="scroll"></span>Relatório do meu impacto</a>
                        </div>
                    </div>
                    <div class="impact-summary-band" id="profile-impact-summary"></div>
                    <div class="impact-areas-grid" id="profile-impact"></div>
                </div>
                <div class="profile-col">
                    <div class="card">
                        <h3 class="card-title"><span data-icon="chart"></span> Estatísticas</h3>
                        <div class="stats-grid" id="profile-stats"></div>
                        <button type="button" class="btn btn-outline btn-sm" id="profile-go-registros"><span data-icon="scroll"></span>Ver meus registros</button>
                    </div>
                </div>
                <div class="profile-col">
                    <div class="card">
                        <h3 class="card-title"><span data-icon="star"></span> Últimas conquistas</h3>
                        <div class="profile-ultimas" id="profile-ultimas"></div>
                        <button type="button" class="btn btn-outline btn-sm" id="profile-go-conquistas"><span data-icon="star"></span>Ver todas as conquistas</button>
                    </div>
                    <div class="card">
                        <h3 class="card-title"><span data-icon="users"></span> Você e os outros jogadores</h3>
                        <div id="profile-comparacao"><p class="muted">Carregando…</p></div>
                    </div>
                </div>
            </div>

            <!-- Evolução -->
            <div role="tabpanel" id="pp-evolucao" aria-labelledby="pt-evolucao" hidden>
                <div class="card">
                    <h3 class="card-title"><span data-icon="chart"></span> Minha evolução</h3>
                    <div id="profile-evolucao" aria-live="polite"><p class="muted">Carregando…</p></div>
                </div>
            </div>

            <!-- Aprendizado -->
            <div role="tabpanel" id="pp-aprendizado" aria-labelledby="pt-aprendizado" hidden>
                <div class="card">
                    <h3 class="card-title"><span data-icon="help"></span> Meu aprendizado</h3>
                    <div id="profile-aprendizado"><p class="muted">Carregando…</p></div>
                    <div class="pager" id="profile-erradas-pager" hidden></div>
                </div>
            </div>

            <!-- Extrato -->
            <div role="tabpanel" id="pp-extrato" aria-labelledby="pt-extrato" hidden>
                <div class="statement-summary" id="profile-extrato-resumo"></div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="coin"></span> Extrato de XP e créditos</h3>
                    <div class="statement-filters">
                        <div class="registros-filtros" role="group" aria-label="Tipo de lançamento" id="profile-extrato-tipo">
                            <button type="button" class="chip" data-tipo="todos" aria-pressed="true">Todos</button>
                            <button type="button" class="chip" data-tipo="ganhos" aria-pressed="false">Ganhos</button>
                            <button type="button" class="chip" data-tipo="gastos" aria-pressed="false">Gastos</button>
                        </div>
                        <label class="statement-origin"><span>Origem</span>
                            <select class="form-input" id="profile-extrato-origem">
                                <option value="todas">Todas</option>
                                <option value="acoes">Ações</option>
                                <option value="quizzes">Quizzes</option>
                                <option value="missoes">Missões</option>
                                <option value="conquistas">Conquistas</option>
                                <option value="construcoes">Construções</option>
                                <option value="renda">Renda do comércio</option>
                                <option value="comunidade">Comunidade</option>
                                <option value="promocoes">Códigos promocionais</option>
                                <option value="ajuste_admin">Ajustes da administração</option>
                            </select>
                        </label>
                        <span id="profile-extrato-tamanho"></span>
                    </div>
                    <div id="profile-statement" aria-live="polite"></div>
                    <div class="pager" id="profile-statement-pager" hidden></div>
                </div>
            </div>

        </section>

        <!-- ABA 6: AMIGOS (RN55–RN60) -->
        <section id="tab-amigos" role="tabpanel" aria-labelledby="t-amigos" class="tab-panel" hidden>
            <div class="hero-band">
                <div class="friends-me">
                    <span class="friend-avatar friend-avatar-lg" id="friends-me-avatar" aria-hidden="true"></span>
                    <div class="friends-me-info">
                        <strong id="friends-me-name"></strong>
                        <span class="friends-code">Seu código: <code id="friends-my-code"></code>
                            <button type="button" class="friends-copy" id="friends-copy"><span data-icon="clipboard"></span>Copiar</button></span>
                    </div>
                    <dl class="hero-stats">
                        <div><dt>Amigos</dt><dd id="friends-stat-amigos">0</dd></div>
                        <div><dt>Pedidos</dt><dd id="friends-stat-pedidos">0</dd></div>
                        <div><dt>Não lidas</dt><dd id="friends-stat-naolidas">0</dd></div>
                    </dl>
                </div>
                <form class="friends-search" id="friends-search" role="search">
                    <label class="sr-only" for="friends-term">Apelido ou código do jogador</label>
                    <input type="search" id="friends-term" class="form-input" maxlength="100" placeholder="Buscar jogador pelo apelido ou código ECO-XXXX-XXXX" autocomplete="off">
                    <button type="submit" class="btn btn-secondary">Buscar</button>
                </form>
                <p class="friends-privacy"><span data-icon="lock"></span> Seu e-mail e seu nome nunca aparecem para os outros jogadores.</p>
            </div>

            <div class="card friends-results hidden" id="friends-results-wrap">
                <div class="friends-section-head">
                    <h3 class="card-title"><span data-icon="user"></span> Resultados da busca</h3>
                    <button type="button" class="btn btn-outline btn-sm" data-acao="fechar-busca">Fechar</button>
                </div>
                <ul class="friends-mini-grid" id="friends-results"></ul>
            </div>

            <div class="friends-main" id="friends-main">
                <div class="friends-suggest hidden" id="friends-suggest-wrap">
                    <div class="friends-section-head">
                        <h3 class="card-title"><span data-icon="star"></span> Sugestões para você</h3>
                        <button type="button" class="btn btn-outline btn-sm" data-acao="outras-sugestoes">Outras</button>
                    </div>
                    <ul class="friends-suggest-row" id="friends-suggest"></ul>
                </div>

                <div class="conteudo-nav friends-tabs" role="tablist" aria-label="Listas de amigos" id="friends-tabs">
                    <button type="button" role="tab" class="chip" id="ft-amigos" aria-controls="fp-amigos" aria-selected="true"><span data-icon="leaf"></span>Amigos <span class="chip-count" id="friends-count">0</span></button>
                    <button type="button" role="tab" class="chip" id="ft-pedidos" aria-controls="fp-pedidos" aria-selected="false"><span data-icon="gift"></span>Pedidos <span class="nav-count hidden" id="friends-req-badge"></span></button>
                    <button type="button" role="tab" class="chip" id="ft-bloqueados" aria-controls="fp-bloqueados" aria-selected="false" hidden><span data-icon="lock"></span>Bloqueados</button>
                </div>
                <div role="tabpanel" id="fp-amigos" aria-labelledby="ft-amigos">
                    <ul class="friends-grid" id="friends-list"></ul>
                </div>
                <div role="tabpanel" id="fp-pedidos" aria-labelledby="ft-pedidos" hidden>
                    <h4 class="friends-subtitle">Recebidos</h4>
                    <ul class="friends-mini-grid" id="friends-received"></ul>
                    <h4 class="friends-subtitle">Enviados</h4>
                    <ul class="friends-mini-grid" id="friends-sent"></ul>
                </div>
                <div role="tabpanel" id="fp-bloqueados" aria-labelledby="ft-bloqueados" hidden>
                    <ul class="friends-mini-grid" id="friends-blocked"></ul>
                </div>
            </div>

            <div class="card friends-panel hidden" id="friends-panel"></div>
        </section>

        <!-- ABA 7: COMUNIDADE (RN61–RN68; montada por assets/js/ui/community.js) -->
        <section id="tab-comunidade" role="tabpanel" aria-labelledby="t-comunidade" class="tab-panel" hidden>
            <div id="community-app"><p class="empty-state">Carregando…</p></div>
        </section>

        <!-- ABA 8: ECOGUIA (chatbot de sustentabilidade por IA local, RN97) -->
        <section id="tab-ecoguia" role="tabpanel" aria-labelledby="t-ecoguia" class="tab-panel" hidden>
            <div id="ecoguia-app"><p class="empty-state">Carregando…</p></div>
        </section>
    </main>

    <!-- PAINEL DE DETALHES DA REGIÃO -->
    <aside class="region-panel hidden" id="region-panel" role="dialog" aria-labelledby="region-panel-title">
        <button type="button" class="region-panel-close" id="region-panel-close" aria-label="Fechar detalhes da região"><span data-icon="close"></span></button>
        <div class="region-panel-head">
            <span class="region-panel-icon" id="region-panel-icon"></span>
            <div>
                <h3 id="region-panel-title"></h3>
                <span class="tag" id="region-panel-stage"></span>
            </div>
        </div>
        <div class="region-panel-media">
            <img id="region-panel-img" class="hidden" alt="">
            <div class="region-panel-placeholder" id="region-panel-placeholder">
                <span data-icon="image" aria-hidden="true"></span>
                <span>Imagem desta região ainda não disponível</span>
                <code id="region-panel-path"></code>
            </div>
        </div>
        <p class="region-panel-desc" id="region-panel-desc"></p>
        <div class="region-panel-indicator">
            <div class="eco-category-label"><span id="region-panel-indicator"></span><strong id="region-panel-value"></strong></div>
            <div class="bar-track bar-sm"><div class="bar-fill" id="region-panel-bar"></div></div>
        </div>
    </aside>

    <!-- MODAL DE REGISTRO DE ATIVIDADE -->
    <div class="modal-overlay hidden" id="modal-registro">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title">
            <h3 id="modal-title">Registrar Atividade Sustentável</h3>
            <p class="modal-activity" id="modal-activity-name"></p>

            <p class="modal-sem-foto" id="modal-sem-foto" hidden>
                <strong>Esta ação não tem como ser comprovada por foto.</strong>
                Registre sem foto: é aprovada na hora e vale o valor cheio.
                <span class="verify-reward" id="modal-reward-0"></span>
            </p>
            <fieldset class="verify-options">
                <legend>Como você quer comprovar?</legend>
                <label class="verify-option">
                    <input type="radio" name="tipo-verificacao" value="1" checked>
                    <span class="verify-body">
                        <strong>Autodeclarada</strong>
                        <span>Aprovada na hora. Recompensa menor.</span>
                        <span class="verify-reward" id="modal-reward-1"></span>
                    </span>
                </label>
                <label class="verify-option">
                    <input type="radio" name="tipo-verificacao" value="2">
                    <span class="verify-body">
                        <strong>Com evidência (foto)</strong>
                        <span>A IA confere a foto na hora e aprova ou rejeita; se discordar, você pode contestar. Recompensa completa.</span>
                        <span class="verify-reward" id="modal-reward-2"></span>
                    </span>
                </label>
            </fieldset>

            <div class="form-group hidden" id="modal-photo-group">
                <label for="modal-photo">Foto da atividade</label>
                <input type="file" id="modal-photo" class="form-input" accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">JPG, PNG ou WebP, até 5 MB.</span>
            </div>

            <div class="form-group">
                <label for="modal-evidence">Descreva como você realizou esta ação <span class="form-hint">(opcional)</span></label>
                <textarea id="modal-evidence" class="form-input" rows="3" maxlength="200" placeholder="Ex.: separei o lixo reciclável da cozinha e levei ao ponto de coleta."></textarea>
            </div>

            <p class="field-error" id="modal-error" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" id="modal-cancel">Cancelar</button>
                <button type="button" class="btn btn-primary" id="modal-confirm">Registrar</button>
            </div>
        </div>
    </div>

    <!-- Contestar um registro com foto rejeitado (RN94) -->
    <div class="modal-overlay hidden" id="modal-contestar">
        <form class="modal-card" role="dialog" aria-modal="true" aria-labelledby="contestar-titulo" id="contestar-form" novalidate>
            <h3 id="contestar-titulo">Contestar a rejeição</h3>
            <p class="modal-activity" id="contestar-atividade"></p>
            <p class="contestar-motivo" id="contestar-motivo"></p>
            <div class="form-group">
                <label for="contestar-texto">Explique por que a sua ação deve ser aprovada</label>
                <textarea id="contestar-texto" class="form-input" rows="4" maxlength="500" required placeholder="Ex.: a muda está no vaso da varanda; a foto ficou escura por causa da luz."></textarea>
                <span class="form-hint"><span id="contestar-contador">0</span>/500 caracteres</span>
            </div>
            <div class="form-group">
                <label for="contestar-foto">Outra foto <span class="form-hint">(opcional)</span></label>
                <input type="file" id="contestar-foto" class="form-input" accept="image/jpeg,image/png,image/webp">
                <span class="form-hint">JPG, PNG ou WebP, até 5 MB.</span>
            </div>
            <p class="form-hint">Um administrador vai conferir. Se ele aceitar, você recebe o valor com foto; a resposta dele é final. Cada registro pode ser contestado uma vez.</p>
            <p class="field-error" id="contestar-erro" role="alert"></p>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" id="contestar-cancelar">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="contestar-enviar">Enviar contestação</button>
            </div>
        </form>
    </div>

    <!-- Minha conta: aberta pelo avatar do topo (separada do progresso, 18/11/2026) -->
    <div class="modal-overlay hidden" id="modal-conta">
        <div class="modal-card modal-conta" role="dialog" aria-modal="true" aria-labelledby="conta-titulo">
            <div class="modal-conta-head">
                <h3 id="conta-titulo"><span data-icon="user"></span> Minha conta</h3>
                <button type="button" class="btn btn-outline btn-sm" id="conta-fechar">Fechar</button>
            </div>
            <div class="profile-conta">
                <div class="card">
                    <h3 class="card-title"><span data-icon="user"></span> Dados da conta</h3>
                    <dl class="profile-data" id="profile-data"></dl>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="star"></span> Avatar</h3>
                    <p class="form-hint">Aparece no topo do jogo, para os amigos e na comunidade.</p>
                    <div class="conta-avatar">
                        <span class="friend-avatar friend-avatar-lg" id="conta-avatar-previa" aria-hidden="true"></span>
                        <div class="conta-avatar-opcoes">
                            <div class="conta-escolha" role="radiogroup" aria-label="Ícone do avatar" id="conta-avatar-icones"></div>
                            <div class="conta-escolha" role="radiogroup" aria-label="Cor do avatar" id="conta-avatar-cores"></div>
                        </div>
                    </div>
                    <p class="field-error" id="avatar-error" role="alert"></p>
                    <div class="form-actions">
                        <button type="button" class="btn btn-primary btn-sm" id="conta-avatar-salvar">Salvar avatar</button>
                        <button type="button" class="btn btn-outline btn-sm" id="conta-avatar-padrao">Usar a inicial</button>
                    </div>
                </div>
                <div class="card promo-card" id="promo-card">
                    <h3 class="card-title"><span data-icon="gift"></span> Código promocional</h3>
                    <p class="form-hint">Digite um código promocional válido para ganhar créditos verdes, XP ou melhorias na cidade.</p>
                    <form class="promo-form" id="promo-form" novalidate>
                        <div class="promo-form-row">
                            <label class="sr-only" for="promo-codigo">Código promocional</label>
                            <input type="text" id="promo-codigo" class="form-input" maxlength="30" autocomplete="off" autocapitalize="characters" placeholder="EX.: BEMVINDO2026" required>
                            <button type="submit" class="btn btn-primary" id="promo-submit">Resgatar</button>
                        </div>
                        <p class="field-error" id="promo-error" role="alert"></p>
                    </form>
                    <div class="promo-ok" id="promo-ok" role="status" hidden></div>
                    <div class="promo-resgates" id="promo-resgates" hidden></div>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="home"></span> Editar perfil</h3>
                    <p class="form-hint">O apelido aparece para os outros jogadores; o nome da cidade, no topo do jogo.</p>
                    <form class="profile-form" id="profile-form" novalidate>
                        <div class="form-group">
                            <label for="profile-apelido">Apelido</label>
                            <input type="text" id="profile-apelido" class="form-input" maxlength="100" required>
                        </div>
                        <div class="form-group">
                            <label for="profile-cidade">Nome da cidade</label>
                            <input type="text" id="profile-cidade" class="form-input" maxlength="100" required>
                        </div>
                        <p class="field-error" id="profile-error" role="alert"></p>
                        <button type="submit" class="btn btn-primary btn-sm">Salvar alterações</button>
                    </form>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="users"></span> Privacidade</h3>
                    <p class="form-hint">Seu e-mail e seu nome nunca aparecem para os outros jogadores. A busca pelo apelido ou código continua funcionando.</p>
                    <div class="conta-privacidade" id="conta-privacidade">
                        <label class="check-line"><input type="checkbox" data-privacidade="apareceSugestoes"> Aparecer nas sugestões de amizade</label>
                        <label class="check-line"><input type="checkbox" data-privacidade="aceitaPedidos"> Receber pedidos de amizade</label>
                        <label class="check-line"><input type="checkbox" data-privacidade="permiteVisita"> Amigos podem visitar minha cidade</label>
                    </div>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="volume"></span> Som</h3>
                    <p class="form-hint">Os sons tocam depois do seu primeiro clique e só com o jogo aberto na tela. O botão de alto-falante no topo silencia tudo.</p>
                    <div class="conta-som" id="conta-som">
                        <div class="conta-som-canal">
                            <label class="conta-som-volume" for="som-volume">Volume geral <strong id="som-volume-valor">60%</strong></label>
                            <input type="range" id="som-volume" data-som-volume="volume" min="0" max="100" step="5" value="60">
                        </div>
                        <div class="conta-som-canal">
                            <div class="conta-som-topo">
                                <label class="check-line"><input type="checkbox" data-som="efeitos"> Efeitos de recompensa e ações</label>
                                <strong class="conta-som-pct" id="som-volume-efeitos-valor">100%</strong>
                            </div>
                            <input type="range" id="som-volume-efeitos" data-som-volume="volumeEfeitos" data-som-canal="efeitos" min="0" max="100" step="5" value="100" aria-label="Volume dos efeitos de recompensa e ações">
                        </div>
                        <div class="conta-som-canal">
                            <div class="conta-som-topo">
                                <label class="check-line"><input type="checkbox" data-som="interface"> Cliques da interface</label>
                                <strong class="conta-som-pct" id="som-volume-interface-valor">30%</strong>
                            </div>
                            <input type="range" id="som-volume-interface" data-som-volume="volumeInterface" data-som-canal="interface" min="0" max="100" step="5" value="30" aria-label="Volume dos cliques da interface">
                        </div>
                        <div class="conta-som-canal">
                            <div class="conta-som-topo">
                                <label class="check-line"><input type="checkbox" data-som="ambiente"> Ambiente da cidade (pássaros, grilos e trânsito)</label>
                                <strong class="conta-som-pct" id="som-volume-ambiente-valor">60%</strong>
                            </div>
                            <input type="range" id="som-volume-ambiente" data-som-volume="volumeAmbiente" data-som-canal="ambiente" min="0" max="100" step="5" value="60" aria-label="Volume do ambiente da cidade">
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" id="som-testar"><span data-icon="volume"></span>Testar som</button>
                    </div>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="lock"></span> Segurança</h3>
                    <p class="form-hint">Para trocar a senha, informe a senha atual.</p>
                    <button type="button" class="btn btn-outline btn-sm" id="profile-senha-abrir" aria-expanded="false" aria-controls="password-form"><span data-icon="lock"></span>Trocar senha</button>
                    <form class="profile-form" id="password-form" novalidate hidden>
                        <div class="form-group">
                            <label for="senha-atual">Senha atual</label>
                            <input type="password" id="senha-atual" class="form-input" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label for="senha-nova">Nova senha</label>
                            <input type="password" id="senha-nova" class="form-input" autocomplete="new-password" maxlength="72" placeholder="Mínimo 6 caracteres" required>
                        </div>
                        <div class="form-group">
                            <label for="senha-confirmar">Confirmar nova senha</label>
                            <input type="password" id="senha-confirmar" class="form-input" autocomplete="new-password" maxlength="72" required>
                        </div>
                        <p class="field-error" id="password-error" role="alert"></p>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Salvar nova senha</button>
                            <button type="button" class="btn btn-outline btn-sm" id="profile-senha-cancelar">Cancelar</button>
                        </div>
                    </form>
                    <hr class="conta-divisor">
                    <p class="form-hint" id="conta-ultima-entrada"></p>
                    <button type="button" class="btn btn-outline btn-sm" id="conta-sair-todos"><span data-icon="close"></span>Sair de todos os outros aparelhos</button>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="scroll"></span> Meus dados</h3>
                    <p class="form-hint">Baixe um arquivo com tudo o que o EcoCity guarda sobre você: conta, cidade, registros, quizzes, extrato, conquistas, amizades e mensagens que você enviou (LGPD). A senha nunca vai junto.</p>
                    <p class="field-error" id="meus-dados-error" role="alert"></p>
                    <button type="button" class="btn btn-outline btn-sm" id="conta-meus-dados"><span data-icon="scroll"></span>Baixar meus dados</button>
                </div>
                <div class="card">
                    <h3 class="card-title"><span data-icon="help"></span> Ajuda</h3>
                    <p class="form-hint">Reveja a explicação rápida das abas do jogo ou leia sobre o projeto.</p>
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline btn-sm" id="profile-tutorial"><span data-icon="help"></span>Ver tutorial</button>
                        <a class="btn btn-outline btn-sm" href="sobre.php"><span data-icon="leaf"></span>Sobre o EcoCity</a>
                    </div>
                </div>
                <div class="card conta-perigo">
                    <h3 class="card-title"><span data-icon="lock"></span> Desativar conta</h3>
                    <p class="form-hint">Sua conta some da busca, dos amigos e das comunidades e ninguém consegue entrar nela. Seus registros e históricos ficam guardados: para voltar, é só entrar de novo com o seu e-mail e a sua senha.</p>
                    <button type="button" class="btn btn-danger-outline btn-sm" id="conta-desativar-abrir" aria-expanded="false" aria-controls="desativar-form">Desativar minha conta</button>
                    <form class="profile-form" id="desativar-form" novalidate hidden>
                        <div class="form-group">
                            <label for="desativar-senha">Sua senha</label>
                            <input type="password" id="desativar-senha" class="form-input" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label for="desativar-confirmacao">Digite DESATIVAR para confirmar</label>
                            <input type="text" id="desativar-confirmacao" class="form-input" autocomplete="off" required>
                        </div>
                        <p class="field-error" id="desativar-error" role="alert"></p>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-danger-outline btn-sm">Desativar agora</button>
                            <button type="button" class="btn btn-outline btn-sm" id="desativar-cancelar">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Conquista: detalhes e comemoração -->
    <div class="modal-overlay hidden" id="modal-conquista">
        <div class="modal-card ach-modal" role="dialog" aria-modal="true" aria-labelledby="mc-title">
            <div class="confetti" id="mc-confetti" aria-hidden="true"></div>
            <p class="ach-modal-kicker" id="mc-kicker"></p>
            <div class="ach-modal-medal" id="mc-medal"></div>
            <h3 id="mc-title"></h3>
            <p class="ach-modal-rarity" id="mc-rarity"></p>
            <p id="mc-desc"></p>
            <div class="ach-modal-body" id="mc-body"></div>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" id="mc-ok">Fechar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay hidden" id="modal-amigos">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="ma-title">
            <h3 id="ma-title"></h3>
            <p id="ma-text"></p>
            <div class="form-group hidden" id="ma-motivo-group">
                <label for="ma-motivo">Motivo <span class="form-hint">(opcional)</span></label>
                <textarea id="ma-motivo" class="form-input" rows="2" maxlength="250"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" id="ma-cancel">Cancelar</button>
                <button type="button" class="btn btn-danger-outline" id="ma-confirm">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- Comunidade: configurações do líder -->
    <div class="modal-overlay hidden" id="modal-comunidade">
        <div class="modal-card cm-modal" role="dialog" aria-modal="true" aria-labelledby="mcm-title">
            <h3 id="mcm-title">Configurações da comunidade</h3>
            <form id="mcm-form" novalidate></form>
        </div>
    </div>

    <!-- Estado inicial vindo do banco (lido por assets/js/state.js) -->
    <script type="application/json" id="dados-iniciais"><?= json_encode($dadosIniciais, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <script type="module" src="<?= asset('assets/js/game.js') ?>"></script>
</body>
</html>
