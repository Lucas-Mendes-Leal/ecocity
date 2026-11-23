<?php
/**
 * Moldura comum do login e do cadastro: fundo com a cidade animada,
 * logo e ciclo do jogo à esquerda, card com abas Entrar/Cadastrar à direita.
 */
declare(strict_types=1);

/** Etapas do ciclo central (specs/site.md §4) com o ícone de cada uma. */
const CICLO_AUTH = [
    ['help', 'Aprenda'], ['leaf', 'Pratique'], ['clipboard', 'Registre'], ['star', 'Evolua'], ['home', 'Transforme'],
];
/** Etapas da área do parceiro (RN69–RN71). */
const CICLO_PARCEIRO = [
    ['flag', 'Cadastre'], ['check', 'Seja aprovado'], ['star', 'Gere o código'], ['leaf', 'Valide a ação'],
];
const ABAS_PARCEIRO = [['login', 'parceiro-entrar.php', 'Entrar'], ['cadastro', 'parceiro-cadastro.php', 'Cadastrar']];
const SLOGAN_PARCEIRO = 'Área do parceiro: confirme as ações sustentáveis dos jogadores.';

/**
 * Abre a página até o início do conteúdo do card.
 * @param string $aba 'login' | 'cadastro'
 * @param array|null $abas abas do card ([id, href, rótulo]); padrão: as do jogador. A área do parceiro passa as suas.
 * @param string|null $slogan texto abaixo do logo (área do parceiro; padrão: slogan do jogo)
 * @param string $classe classe extra do layout (ex.: 'is-sobre', uma coluna com o card largo)
 */
function auth_inicio(string $tituloPagina, string $aba, string $titulo, string $subtitulo, ?array $abas = null, ?string $slogan = null, string $classe = ''): void
{
    $abas ??= [['login', 'login.php', 'Entrar'], ['cadastro', 'cadastro.php', 'Cadastrar']];
    $ciclo = $slogan === null ? CICLO_AUTH : CICLO_PARCEIRO;
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($tituloPagina) ?> — EcoCity</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/logo-ecocity-icone.png') ?>">
    <?= pwa_head() ?>
    <link rel="stylesheet" href="<?= asset('assets/css/base.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/city.css') ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/auth.css') ?>">
    <?= importmap() ?>
</head>
<body class="auth-body">
    <div class="auth-scene" id="auth-scene" aria-hidden="true"></div>
    <div class="auth-shade" aria-hidden="true"></div>

    <main class="auth-layout <?= e($classe) ?>">
        <section class="auth-hero">
            <img class="auth-logo" src="<?= asset('assets/img/logo-ecocity.png') ?>" alt="EcoCity" width="520" height="554">
            <p class="auth-slogan"><?= e($slogan ?? 'Transforme seus hábitos. Transforme sua cidade.') ?></p>
            <ol class="auth-cycle" aria-label="Como funciona">
                <?php foreach ($ciclo as [$icone, $etapa]): ?>
                    <li><span data-icon="<?= $icone ?>" aria-hidden="true"></span><?= $etapa ?></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <div class="auth-card">
            <?php if ($abas): ?>
            <nav class="auth-tabs" aria-label="Acesso">
                <?php foreach ($abas as [$id, $href, $rotulo]): ?>
                    <a href="<?= $href ?>" class="auth-tab<?= $id === $aba ? ' is-active' : '' ?>"<?= $id === $aba ? ' aria-current="page"' : '' ?>><?= $rotulo ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <h1 class="auth-title"><?= e($titulo) ?></h1>
            <p class="auth-subtitle"><?= e($subtitulo) ?></p>
    <?php
}

/** Fecha o card e a página. $sobre: link discreto para a página "Sobre o EcoCity". */
function auth_fim(string $rodapeHtml, bool $sobre = true): void
{
    ?>
            <footer class="auth-footer"><p><?= $rodapeHtml ?></p>
                <?php if ($sobre): ?><p class="auth-sobre"><a href="sobre.php"><span data-icon="help" aria-hidden="true"></span>Sobre o EcoCity: o que é e como funciona</a></p><?php endif; ?>
            </footer>
        </div>
    </main>

    <script type="module" src="<?= asset('assets/js/auth.js') ?>"></script>
</body>
</html>
    <?php
}

/**
 * Campo com ícone à esquerda.
 * @param string $atributos atributos extras já escapados (type, autocomplete, maxlength, aria-invalid...)
 */
function auth_campo(string $id, string $rotulo, string $icone, string $valor, string $placeholder, string $atributos, string $erro = ''): void
{
    ?>
                <div class="form-group">
                    <label for="<?= $id ?>"><?= e($rotulo) ?></label>
                    <div class="input-icon">
                        <span data-icon="<?= $icone ?>" aria-hidden="true"></span>
                        <input id="<?= $id ?>" name="<?= $id ?>" class="form-input" value="<?= e($valor) ?>" placeholder="<?= e($placeholder) ?>" <?= $atributos ?> required>
                    </div>
                    <span class="field-error" id="error-<?= $id ?>"><?= e($erro) ?></span>
                </div>
    <?php
}
