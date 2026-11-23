<?php
/**
 * Painel do estabelecimento parceiro (RN69–RN71, RN95). Fora do jogo; sessão própria.
 *  - Administrador do estabelecimento (conta de e-mail): menu lateral com Visão geral, Atender,
 *    Atendimentos, Relatório, Funcionários e Estabelecimento.
 *  - Funcionário (usuário + senha): modo balcão focado em atender, com os próprios códigos e os atendimentos de hoje.
 */
require __DIR__ . '/includes/bootstrap.php';
$p = exigir_parceiro();
$aprovado = $p['status'] === 'aprovado';
$admin = $p['papel'] === 'admin';
$comGestao = $admin && $aprovado && parceiro_funcionarios_pronto();
$status = ['pendente' => 'Aguardando aprovação', 'aprovado' => 'Parceiro aprovado', 'recusado' => 'Cadastro recusado'][$p['status']] ?? $p['status'];
$papel = $admin ? 'Administrador' : 'Funcionário: ' . $p['funcionario_nome'];
$primeiroNomeFunc = !$admin && !empty($p['funcionario_nome']) ? explode(' ', trim($p['funcionario_nome']))[0] : '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($p['nome']) ?> — Parceiro EcoCity</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" type="image/png" href="<?= asset('assets/img/logo-ecocity-icone.png') ?>">
    <?= pwa_head('#1B5E20') ?>
    <link rel="stylesheet" href="<?= asset('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/game.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/parceiro.css') ?>">
    <?= importmap() ?>
</head>
<body class="partner-body <?= $comGestao ? 'has-sidebar' : 'is-balcao' ?>" data-papel="<?= $admin ? 'admin' : 'funcionario' ?>">
    <div class="p-shell">
        <?php if ($comGestao): ?>
        <div class="p-sidebar-overlay hidden" id="p-sidebar-overlay" aria-hidden="true"></div>
        <aside class="p-sidebar" id="p-sidebar" aria-label="Menu do estabelecimento">
            <div class="p-brand">
                <img class="p-brand-logo" src="<?= asset('assets/img/logo-ecocity-icone.png') ?>" alt="" width="38" height="38">
                <div class="p-brand-text">
                    <strong>EcoCity</strong>
                    <span>Parceiros · Gestão</span>
                </div>
                <button type="button" class="p-sidebar-fechar" id="p-menu-fechar" aria-label="Fechar menu"><span data-icon="close"></span></button>
            </div>

            <nav class="p-nav" aria-label="Seções do estabelecimento">
                <div class="p-nav-list" role="tablist" aria-orientation="vertical" id="partner-tabs">
                    <span class="p-nav-group">Operação</span>
                    <button type="button" role="tab" class="p-nav-item" id="pt-geral" aria-controls="pp-geral" aria-selected="true">
                        <span data-icon="home"></span><span>Visão geral</span>
                    </button>
                    <button type="button" role="tab" class="p-nav-item" id="pt-atender" aria-controls="pp-atender" aria-selected="false">
                        <span data-icon="clipboard"></span><span>Atender</span>
                    </button>
                    <button type="button" role="tab" class="p-nav-item" id="pt-atendimentos" aria-controls="pp-atendimentos" aria-selected="false">
                        <span data-icon="scroll"></span><span>Atendimentos</span>
                    </button>
                    <button type="button" role="tab" class="p-nav-item" id="pt-relatorio" aria-controls="pp-relatorio" aria-selected="false">
                        <span data-icon="chart"></span><span>Relatório</span>
                    </button>

                    <span class="p-nav-group">Administração</span>
                    <button type="button" role="tab" class="p-nav-item" id="pt-funcionarios" aria-controls="pp-funcionarios" aria-selected="false">
                        <span data-icon="users"></span><span>Funcionários</span>
                    </button>
                    <button type="button" role="tab" class="p-nav-item" id="pt-estabelecimento" aria-controls="pp-estabelecimento" aria-selected="false">
                        <span data-icon="pin"></span><span>Estabelecimento</span>
                    </button>
                </div>
            </nav>

            <div class="p-sidebar-foot">
                <span>Área do parceiro · EcoCity</span>
            </div>
        </aside>
        <?php endif; ?>

        <div class="p-workspace">
            <header class="p-topbar <?= !$admin ? 'is-funcionario' : '' ?>">
                <div class="p-topbar-left">
                    <?php if ($comGestao): ?>
                    <button type="button" class="p-menu-btn" id="p-menu-abrir" aria-label="Abrir menu" aria-controls="p-sidebar" aria-expanded="false">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                    <?php else: ?>
                    <img class="partner-logo" src="<?= asset('assets/img/logo-ecocity-icone.png') ?>" alt="" width="38" height="38">
                    <?php endif; ?>

                    <div class="player-info p-est-info">
                        <div class="p-est-title-row">
                            <h1 id="p-est-nome"><?= e($p['nome']) ?></h1>
                            <span class="p-role-badge <?= $aprovado ? 'is-aprovado' : 'is-pendente' ?>"><?= e($aprovado ? ($admin ? 'Administrador' : 'Balcão de atendimento') : $status) ?></span>
                        </div>
                        <span class="p-est-sub" id="p-est-endereco"><?= e(!empty($p['endereco']) ? $p['endereco'] : ('Parceiro EcoCity · ' . ($aprovado ? $papel : $status))) ?></span>
                    </div>
                </div>

                <div class="p-topbar-right">
                    <?php if ($aprovado): ?>
                    <span class="p-live-badge" id="p-sync-status" title="O painel verifica confirmações automaticamente a cada 10 segundos">
                        <span class="p-live-dot" aria-hidden="true"></span>
                        <span id="p-sync-texto">Atualizado agora</span>
                    </span>
                    <button type="button" class="som-botao" id="som-botao" aria-pressed="false" title="Desligar o aviso sonoro" aria-label="Desligar o aviso sonoro"><span data-icon="volume"></span></button>
                    <?php endif; ?>

                    <?php if (!$admin && $aprovado): ?>
                    <button type="button" class="p-user-pill" id="p-func-senha-btn" aria-expanded="false" aria-controls="p-func-senha-card" title="Trocar minha senha">
                        <span data-icon="user"></span>
                        <span><?= e($p['funcionario_nome']) ?></span>
                        <span data-icon="chevron-down" class="p-user-chevron"></span>
                    </button>
                    <?php endif; ?>

                    <form method="post" action="parceiro-sair.php">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline btn-sm p-sair-btn">Sair</button>
                    </form>
                </div>
            </header>

            <main class="p-main partner-main">
                <?php if (!$aprovado): ?>
                    <div class="card partner-wait">
                        <span data-icon="<?= $p['status'] === 'recusado' ? 'close' : 'calendar' ?>" aria-hidden="true"></span>
                        <?php if ($p['status'] === 'recusado'): ?>
                            <h2>Cadastro recusado</h2>
                            <p>Motivo: <?= e($p['motivo_recusa'] ?? '—') ?></p>
                            <p class="muted">Se quiser revisar os dados, fale com a equipe do EcoCity.</p>
                        <?php else: ?>
                            <h2>Cadastro em análise</h2>
                            <p>A equipe do EcoCity vai conferir os dados do <strong><?= e($p['nome']) ?></strong>. Assim que for aprovado, este painel libera a geração de códigos.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>

                <?php if ($comGestao): ?>
                <!-- VISÃO GERAL (administrador) -->
                <section id="pp-geral" role="tabpanel" aria-labelledby="pt-geral" class="partner-painel">
                    <div class="p-sec-head">
                        <div>
                            <h2>Visão geral</h2>
                            <p class="muted">Acompanhe o desempenho do estabelecimento, a equipe e os últimos códigos gerados.</p>
                        </div>
                        <div class="p-sec-actions">
                            <label for="vg-periodo" class="sr-only">Período da visão geral</label>
                            <select id="vg-periodo" class="form-input p-select-sm">
                                <option value="7" selected>Últimos 7 dias</option>
                                <option value="30">Últimos 30 dias</option>
                                <option value="90">Últimos 90 dias</option>
                                <option value="0">Desde o início</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" id="vg-ir-atender">
                                <span data-icon="clipboard"></span> Atender agora
                            </button>
                        </div>
                    </div>

                    <div class="p-kpi-grid" id="vg-kpis">
                        <div class="card p-kpi-card"><span class="p-rotulo">Códigos usados</span><strong class="p-kpi-num">—</strong><span class="p-kpi-delta muted">Carregando…</span></div>
                        <div class="card p-kpi-card"><span class="p-rotulo">Jogadores atendidos</span><strong class="p-kpi-num">—</strong><span class="p-kpi-delta muted">Carregando…</span></div>
                        <div class="card p-kpi-card"><span class="p-rotulo">Taxa de uso</span><strong class="p-kpi-num">—</strong><span class="p-kpi-delta muted">Carregando…</span></div>
                        <div class="card p-kpi-card"><span class="p-rotulo">Impacto confirmado</span><strong class="p-kpi-num">—</strong><span class="p-kpi-delta muted">Carregando…</span></div>
                    </div>

                    <div class="p-vg-mid">
                        <section class="card p-card-pad">
                            <div class="p-card-head">
                                <h3 class="card-title"><span data-icon="chart"></span> Atendimentos por dia</h3>
                                <span class="p-rotulo" id="vg-grafico-sub">Códigos usados</span>
                            </div>
                            <div id="vg-grafico"><p class="empty-state">Carregando…</p></div>
                        </section>

                        <section class="card p-card-pad">
                            <div class="p-card-head">
                                <h3 class="card-title"><span data-icon="users"></span> Equipe no período</h3>
                                <button type="button" class="link-btn" data-ir-aba="pt-funcionarios">Gerenciar</button>
                            </div>
                            <ul class="p-vg-equipe" id="vg-equipe"><li class="empty-state">Carregando…</li></ul>
                        </section>
                    </div>

                    <section class="card p-card-table">
                        <div class="p-card-head p-card-head-pad">
                            <div>
                                <h3 class="card-title"><span data-icon="scroll"></span> Últimos atendimentos</h3>
                            </div>
                            <button type="button" class="link-btn" data-ir-aba="pt-atendimentos">Ver todos</button>
                        </div>
                        <div class="table-scroll">
                            <table class="data-table p-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Código</th>
                                        <th scope="col">Atividade</th>
                                        <th scope="col">Qtd.</th>
                                        <th scope="col">Atendente</th>
                                        <th scope="col">Situação</th>
                                        <th scope="col" class="is-num">Quando</th>
                                    </tr>
                                </thead>
                                <tbody id="vg-ultimos"><tr><td colspan="6" class="empty-state">Carregando…</td></tr></tbody>
                            </table>
                        </div>
                    </section>
                </section>
                <?php endif; ?>

                <!-- ATENDER (modo balcão — administrador e funcionário) -->
                <section id="pp-atender" role="tabpanel" aria-labelledby="pt-atender" class="partner-painel" <?= $comGestao ? 'hidden' : '' ?>>
                    <?php if (!$admin): ?>
                    <details class="card partner-senha p-func-senha" id="p-func-senha-card">
                        <summary class="card-title"><span data-icon="lock"></span> Trocar minha senha</summary>
                        <form id="minha-senha" class="partner-form" novalidate>
                            <div class="p-senha-grid">
                                <div class="form-group"><label for="ms-atual">Senha atual</label><input id="ms-atual" class="form-input" type="password" autocomplete="current-password" required></div>
                                <div class="form-group"><label for="ms-nova">Nova senha</label><input id="ms-nova" class="form-input" type="password" autocomplete="new-password" minlength="6" maxlength="72" required></div>
                                <div class="form-group"><label for="ms-confirmar">Confirme a nova senha</label><input id="ms-confirmar" class="form-input" type="password" autocomplete="new-password" required></div>
                            </div>
                            <p class="field-error" role="alert"></p>
                            <button type="submit" class="btn btn-primary btn-sm">Salvar nova senha</button>
                        </form>
                    </details>
                    <?php endif; ?>

                    <div class="p-sec-head">
                        <div>
                            <h2><?= $admin ? 'Atender cliente' : 'Olá, ' . e($primeiroNomeFunc) . '! Bom atendimento.' ?></h2>
                            <p class="muted">1. Escolha a atividade entregue · 2. Ajuste a quantidade · 3. Gere o código e mostre o QR (no card ou em tela cheia).</p>
                        </div>
                    </div>

                    <div class="p-balcao-grid">
                        <!-- Passo 1 e 2: Gerar código -->
                        <form class="card partner-gen p-balcao-form" id="partner-gen" novalidate>
                            <div class="p-step-block">
                                <span class="p-rotulo">Passo 1 · Atividade</span>
                                <label class="p-step-title" for="pg-atividade">O que o cliente entregou?</label>
                                <div class="p-ativ-grid" id="pg-atividades-cards" role="radiogroup" aria-label="Escolha a atividade"></div>
                                <select id="pg-atividade" class="sr-only" name="atividade_id" tabindex="-1" aria-hidden="true"></select>
                            </div>

                            <div class="p-step-block">
                                <div class="p-step-head">
                                    <span class="p-rotulo">Passo 2 · Quantidade</span>
                                    <span class="form-hint" id="pg-unidade"></span>
                                </div>
                                <div class="p-stepper">
                                    <button type="button" class="p-step-btn" id="pg-menos" aria-label="Diminuir quantidade">−</button>
                                    <div class="p-step-input-wrap">
                                        <input id="pg-quantidade" class="form-input p-step-input" type="number" name="quantidade" min="0.01" step="0.01" required placeholder="Ex.: 1">
                                        <span class="p-step-unit" id="pg-unidade-curta"></span>
                                    </div>
                                    <button type="button" class="p-step-btn" id="pg-mais" aria-label="Aumentar quantidade">+</button>
                                </div>
                                <div class="p-atalhos-qtd" id="pg-atalhos" aria-label="Quantidades rápidas"></div>
                                <p class="form-hint" id="pg-max"></p>
                            </div>

                            <button type="submit" class="btn btn-primary btn-block p-gerar-btn">Gerar código</button>
                        </form>

                        <!-- Código atual (QR + 6 dígitos + tela cheia) -->
                        <section class="card partner-show" id="partner-show" aria-live="polite">
                            <p class="empty-state">Gere um código para mostrar ao jogador.</p>
                        </section>

                        <!-- Coluna lateral do balcão: códigos ativos e resumo -->
                        <div class="p-balcao-side">
                            <section class="card p-card-pad">
                                <div class="p-card-head">
                                    <h3 class="card-title"><span data-icon="clipboard"></span> <?= $admin ? 'Códigos de agora' : 'Meus códigos de agora' ?></h3>
                                    <span class="tag tag-warning" id="partner-codes-count">0 ativos</span>
                                </div>
                                <ul class="partner-list" id="partner-codes"></ul>
                            </section>

                            <section class="card p-card-pad">
                                <div class="p-card-head">
                                    <h3 class="card-title"><span data-icon="chart"></span> <?= $admin ? 'Impacto confirmado aqui' : 'Confirmado hoje por mim' ?></h3>
                                </div>
                                <ul class="partner-totals" id="partner-totals"></ul>
                            </section>
                        </div>
                    </div>

                    <section class="card p-card-table">
                        <div class="p-card-head p-card-head-pad">
                            <h3 class="card-title"><span data-icon="scroll"></span> <?= $admin ? 'Últimas verificações' : 'Meus atendimentos de hoje' ?></h3>
                        </div>
                        <div class="table-scroll">
                            <table class="data-table p-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Jogador</th>
                                        <th scope="col">Atividade</th>
                                        <th scope="col">Quantidade</th>
                                        <?php if ($admin): ?><th scope="col">Atendente</th><?php endif; ?>
                                        <th scope="col" class="is-num">Quando</th>
                                    </tr>
                                </thead>
                                <tbody id="partner-history"></tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <?php if ($comGestao): ?>
                <!-- ATENDIMENTOS -->
                <section id="pp-atendimentos" role="tabpanel" aria-labelledby="pt-atendimentos" class="partner-painel" hidden>
                    <div class="p-sec-head">
                        <div>
                            <h2>Atendimentos</h2>
                            <p class="muted">Histórico completo dos códigos gerados no estabelecimento, quem atendeu e a situação de cada um.</p>
                        </div>
                        <div class="p-sec-actions">
                            <button type="button" class="btn btn-primary btn-sm" data-ir-aba="pt-atender"><span data-icon="clipboard"></span> Novo atendimento</button>
                        </div>
                    </div>
                    <div class="card p-card-pad" id="atendimentos-app"><p class="empty-state">Carregando…</p></div>
                </section>

                <!-- RELATÓRIO -->
                <section id="pp-relatorio" role="tabpanel" aria-labelledby="pt-relatorio" class="partner-painel" hidden>
                    <div class="p-sec-head">
                        <div>
                            <h2>Relatório</h2>
                            <p class="muted">Números consolidados do estabelecimento e comparação com o período anterior. O CSV não inclui apelidos de jogadores.</p>
                        </div>
                        <div class="p-sec-actions">
                            <label for="rel-periodo" class="sr-only">Período</label>
                            <select id="rel-periodo" class="form-input p-select-sm">
                                <option value="7">Últimos 7 dias</option>
                                <option value="30" selected>Últimos 30 dias</option>
                                <option value="90">Últimos 90 dias</option>
                                <option value="0">Desde o início</option>
                            </select>
                            <a id="rel-csv" class="btn btn-outline btn-sm" href="api/parceiro.php?acao=csv&amp;dias=30" download><span data-icon="scroll"></span> Baixar CSV</a>
                        </div>
                    </div>
                    <div id="relatorio-app"><p class="empty-state">Carregando…</p></div>
                </section>

                <!-- FUNCIONÁRIOS -->
                <section id="pp-funcionarios" role="tabpanel" aria-labelledby="pt-funcionarios" class="partner-painel" hidden>
                    <div class="p-sec-head">
                        <div>
                            <h2>Funcionários</h2>
                            <p class="muted">Cada funcionário entra em <strong>parceiro-entrar.php</strong> com o usuário e a senha criados abaixo e vê apenas o próprio balcão. Até <?= PARCEIRO_FUNCIONARIOS_MAX ?> ativos.</p>
                        </div>
                        <div class="p-sec-actions">
                            <button type="button" class="btn btn-primary btn-sm" id="func-focar-novo"><span data-icon="user"></span> Novo funcionário</button>
                        </div>
                    </div>
                    <div class="partner-grid partner-grid-func">
                        <form class="card partner-form p-card-pad" id="func-novo" novalidate>
                            <h3 class="card-title"><span data-icon="user"></span> Cadastrar funcionário</h3>
                            <div class="form-group"><label for="fn-nome">Nome completo</label><input id="fn-nome" class="form-input" maxlength="80" required placeholder="Ex.: Maria Souza"></div>
                            <div class="form-group"><label for="fn-usuario">Usuário (para entrar)</label><input id="fn-usuario" class="form-input" maxlength="30" required autocapitalize="none" spellcheck="false" placeholder="Ex.: maria.souza">
                                <span class="form-hint">3 a 30 caracteres: letras minúsculas sem acento, números, ponto, hífen ou sublinhado.</span></div>
                            <div class="form-group"><label for="fn-senha">Senha inicial</label><input id="fn-senha" class="form-input" type="text" autocomplete="off" minlength="6" maxlength="72" required>
                                <span class="form-hint">De 6 a 72 caracteres. Passe para a pessoa; ela pode trocar depois.</span></div>
                            <p class="field-error" role="alert"></p>
                            <button type="submit" class="btn btn-primary">Criar funcionário</button>
                        </form>
                        <div class="card p-card-pad">
                            <div class="p-card-head">
                                <h3 class="card-title"><span data-icon="users"></span> Equipe cadastrada</h3>
                                <span class="p-rotulo" id="func-contagem"></span>
                            </div>
                            <ul class="partner-equipe" id="func-lista"><li class="empty-state">Carregando…</li></ul>
                        </div>
                    </div>
                </section>

                <!-- ESTABELECIMENTO -->
                <section id="pp-estabelecimento" role="tabpanel" aria-labelledby="pt-estabelecimento" class="partner-painel" hidden>
                    <div class="p-sec-head">
                        <div>
                            <h2>Estabelecimento</h2>
                            <p class="muted">Estes dados aparecem para os jogadores na lista de locais parceiros do EcoCity.</p>
                        </div>
                    </div>
                    <div class="partner-grid">
                        <form class="card partner-form p-card-pad" id="est-dados" novalidate>
                            <h3 class="card-title"><span data-icon="pin"></span> Dados cadastrais</h3>
                            <div class="form-group"><label for="ed-nome">Nome do estabelecimento</label><input id="ed-nome" name="nome" class="form-input" maxlength="100" required></div>
                            <div class="form-group"><label for="ed-responsavel">Responsável</label><input id="ed-responsavel" name="responsavel" class="form-input" maxlength="100" required></div>
                            <div class="form-group"><label for="ed-endereco">Endereço</label><input id="ed-endereco" name="endereco" class="form-input" maxlength="200" required></div>
                            <div class="form-group"><label for="ed-descricao">Descrição</label><textarea id="ed-descricao" name="descricao" class="form-input" rows="3" maxlength="250" required></textarea></div>
                            <p class="form-hint" id="ed-email"></p>
                            <p class="field-error" role="alert"></p>
                            <button type="submit" class="btn btn-primary">Salvar dados</button>
                        </form>
                        <div class="partner-col">
                            <div class="card p-card-pad">
                                <h3 class="card-title"><span data-icon="check"></span> Atividades que vocês verificam</h3>
                                <ul class="partner-atividades" id="est-atividades"></ul>
                                <form id="est-pedir" class="partner-pedir" novalidate>
                                    <label for="ep-atividade">Solicitar nova atividade</label>
                                    <div class="partner-pedir-linha"><select id="ep-atividade" class="form-input"></select>
                                        <button type="submit" class="btn btn-secondary btn-sm">Pedir</button></div>
                                    <span class="form-hint">A administração do EcoCity analisa o pedido.</span>
                                </form>
                                <ul class="partner-pedidos" id="est-pedidos"></ul>
                            </div>
                            <details class="card partner-senha p-card-pad">
                                <summary class="card-title"><span data-icon="lock"></span> Trocar senha do administrador</summary>
                                <form id="minha-senha" class="partner-form" novalidate>
                                    <div class="form-group"><label for="ms-atual">Senha atual</label><input id="ms-atual" class="form-input" type="password" autocomplete="current-password" required></div>
                                    <div class="form-group"><label for="ms-nova">Nova senha</label><input id="ms-nova" class="form-input" type="password" autocomplete="new-password" minlength="6" maxlength="72" required></div>
                                    <div class="form-group"><label for="ms-confirmar">Confirme a nova senha</label><input id="ms-confirmar" class="form-input" type="password" autocomplete="new-password" required></div>
                                    <p class="field-error" role="alert"></p>
                                    <button type="submit" class="btn btn-primary">Salvar nova senha</button>
                                </form>
                            </details>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Modal QR Code em tela cheia para mostrar ao cliente no balcão -->
    <div class="p-fullscreen hidden" id="partner-fullscreen" role="dialog" aria-modal="true" aria-label="Código QR em tela cheia">
        <div class="p-fullscreen-box" id="partner-fullscreen-body"></div>
    </div>

    <?php if ($aprovado): ?>
        <script src="<?= asset('assets/vendor/qrcode-generator/qrcode.js') ?>"></script>
        <script type="module" src="<?= asset('assets/js/parceiro.js') ?>"></script>
    <?php else: ?>
        <script type="module" nonce="<?= e(csp_nonce()) ?>">import { hydrateIcons } from './assets/js/ui/icons.js'; hydrateIcons();</script>
    <?php endif; ?>
</body>
</html>
