<?php
/**
 * Sobre o EcoCity (pedido da professora, 18/11/2026): o que é o sistema, o problema, a solução e o que
 * se faz nele. Aberta sem login; link discreto no rodapé do login, do cadastro e da área do parceiro.
 * Textos só com o que está em specs/site.md.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/auth-layout.php';

/**
 * "Quem somos": dados oficiais do projeto acadêmico e integrantes.
 */
const SOBRE_EQUIPE = [
    'texto'   => 'Projeto acadêmico desenvolvido em 2026 na Anhembi Morumbi, sob a orientação dos professores Cassilene Rodrigues de Assis e Flavio Copola Azenha.',
    'pessoas' => [
        [
            'nome'     => 'Lucas Mendes Leal',
            'curso'    => 'Estudante de Ciência da Computação',
            'linkedin' => 'https://www.linkedin.com/in/lucas-mendes-leal-2b5b232a5',
        ],
        [
            'nome'     => 'Rosa de Sarom Diambo Vango',
            'curso'    => 'Estudante de Análise e Desenvolvimento de Sistemas',
            'linkedin' => 'https://www.linkedin.com/in/rosa-vango-889351313/',
        ],
    ],
    'links'   => [],
];

$logado = usuario_id() !== null;
$ciclo = [
    ['help', 'Aprenda', 'Quizzes curtos sobre reciclagem, água, energia, mobilidade e natureza.'],
    ['leaf', 'Pratique', 'Uma ação real no dia a dia: separar o lixo, economizar água, apagar as luzes…'],
    ['clipboard', 'Registre', 'Conte o que fez, com ou sem foto. A foto é conferida por uma IA.'],
    ['star', 'Evolua', 'Ganhe XP, créditos verdes, níveis e conquistas.'],
    ['home', 'Transforme', 'Sua cidade virtual sai da poluição e vira uma cidade modelo.'],
];
$recursos = [
    ['help', 'Quizzes do dia, da semana e por tema — inclusive gerados por IA a partir de notícias ambientais recentes, com a fonte de cada pergunta.'],
    ['clipboard', 'Ações sustentáveis do dia a dia, registradas na hora; a foto da ação é conferida por uma IA que roda no próprio servidor.'],
    ['flag', 'Estabelecimentos parceiros (cooperativas, ecopontos, lojas) que confirmam a ação com um código ou QR Code.'],
    ['calendar', 'Missões da semana e eventos especiais do calendário ambiental.'],
    ['trophy', 'Conquistas, níveis e o seu impacto real estimado: quilos reciclados, litros de água, kWh e CO₂ evitado.'],
    ['home', 'Uma cidade viva que muda com os seus hábitos, com lotes para construir praças, hortas, ciclofaixas e usinas solares.'],
    ['users', 'Amigos, conversa e comunidades com missões em grupo e ranking.'],
    ['chart', 'Relatório do seu impacto para imprimir ou salvar em PDF.'],
];

auth_inicio('Sobre', '', 'Sobre o EcoCity', 'Educação ambiental em forma de jogo: o que é, por que existe e como funciona.', [], null, 'is-sobre');
?>
            <section class="sobre-secao">
                <h2><span data-icon="leaf" aria-hidden="true"></span>O que é</h2>
                <p>O EcoCity é uma aplicação web de educação ambiental gamificada. Cada jogador cuida de uma cidade virtual que
                    começa degradada e se recupera conforme ele aprende e pratica <strong>ações sustentáveis reais</strong>.</p>
            </section>

            <section class="sobre-secao">
                <h2><span data-icon="help" aria-hidden="true"></span>O problema</h2>
                <p>Muita gente sabe o que é sustentável, mas não transforma isso em hábito. Existe uma distância entre
                    <strong>conhecer</strong> as práticas e <strong>adotá-las de fato</strong> no dia a dia, em parte porque o
                    resultado de cada pequena atitude não aparece.</p>
            </section>

            <section class="sobre-secao">
                <h2><span data-icon="check" aria-hidden="true"></span>A solução</h2>
                <p>O EcoCity torna o resultado das suas atitudes <strong>visível e recompensado</strong>. Cada ação registrada
                    rende pontos, melhora os indicadores da sua cidade e mostra o impacto real estimado — favorecendo a constância.</p>
            </section>

            <section class="sobre-secao">
                <h2><span data-icon="star" aria-hidden="true"></span>Como funciona</h2>
                <ol class="sobre-ciclo">
                    <?php foreach ($ciclo as [$icone, $etapa, $texto]): ?>
                        <li><span data-icon="<?= $icone ?>" aria-hidden="true"></span><strong><?= $etapa ?></strong><?= e($texto) ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <section class="sobre-secao">
                <h2><span data-icon="clipboard" aria-hidden="true"></span>O que você faz no EcoCity</h2>
                <ul class="sobre-lista">
                    <?php foreach ($recursos as [$icone, $texto]): ?>
                        <li><span data-icon="<?= $icone ?>" aria-hidden="true"></span><span><?= e($texto) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="sobre-secao">
                <h2><span data-icon="lock" aria-hidden="true"></span>Privacidade e tecnologia</h2>
                <p>Seu e-mail e seu nome nunca aparecem para os outros jogadores. A inteligência artificial roda no próprio
                    servidor do EcoCity: nenhuma foto sai da máquina. O sistema é feito com HTML, CSS, JavaScript, PHP e MySQL.</p>
                <p>Para jovens e adultos, a partir de 13 anos. O EcoCity é um projeto acadêmico.</p>
            </section>

            <?php if (SOBRE_EQUIPE['texto'] !== '' || SOBRE_EQUIPE['pessoas']): ?>
            <section class="sobre-secao">
                <h2><span data-icon="users" aria-hidden="true"></span>Quem somos</h2>
                <?php if (SOBRE_EQUIPE['texto'] !== ''): ?><p><?= e(SOBRE_EQUIPE['texto']) ?></p><?php endif; ?>
                <?php if (SOBRE_EQUIPE['pessoas']): ?>
                    <ul class="sobre-equipe">
                        <?php foreach (SOBRE_EQUIPE['pessoas'] as $pessoa): ?>
                            <li>
                                <strong class="sobre-membro-nome"><?= e($pessoa['nome']) ?></strong>
                                <?php if (!empty($pessoa['curso'])): ?>
                                    <span class="sobre-membro-curso"><?= e($pessoa['curso']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($pessoa['linkedin'])): ?>
                                    <a class="sobre-membro-link" href="<?= e($pessoa['linkedin']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e('LinkedIn de ' . $pessoa['nome']) ?>">LinkedIn</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (SOBRE_EQUIPE['links']): ?>
                    <p><?php foreach (SOBRE_EQUIPE['links'] as $i => [$rotulo, $url]): ?><?= $i ? ' · ' : '' ?><a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($rotulo) ?></a><?php endforeach; ?></p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <div class="sobre-acoes">
                <?php if ($logado): ?>
                    <a class="btn btn-primary btn-game" href="jogo.php">Voltar ao jogo</a>
                <?php else: ?>
                    <a class="btn btn-primary btn-game" href="cadastro.php">Criar minha cidade</a>
                    <a class="btn btn-outline" href="login.php">Já tenho conta: entrar</a>
                <?php endif; ?>
            </div>
<?php auth_fim('É um estabelecimento? <a href="parceiro-entrar.php">Área do parceiro</a>', false);
