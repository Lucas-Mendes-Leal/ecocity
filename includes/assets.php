<?php
/**
 * Versionamento de CSS/JS para o navegador nunca usar um arquivo desatualizado.
 *
 * A versão é a data de modificação do arquivo: muda sozinha a cada edição, e
 * arquivos que não mudaram continuam vindo do cache.
 *  - asset()    → URL com ?v= para <link> e o <script> de entrada.
 *  - importmap() → <script type="importmap"> que versiona cada módulo JS
 *                  importado internamente (import ... from './ui/hud.js').
 */
declare(strict_types=1);

const ASSETS_JS = 'assets/js';

/** URL do arquivo com a versão (data de modificação). */
function asset(string $caminho): string
{
    $arquivo = ECOCITY_ROOT . '/' . $caminho;
    $versao = is_file($arquivo) ? filemtime($arquivo) : 0;
    return e($caminho . '?v=' . $versao);
}

/** Import map com todos os módulos de assets/js versionados. Deve vir antes de qualquer <script type="module">. */
function importmap(): string
{
    static $html = null;
    if ($html !== null) return $html;

    $mapa = [];
    $base = ECOCITY_ROOT . '/' . ASSETS_JS;
    $itens = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($itens as $arquivo) {
        if ($arquivo->getExtension() !== 'js') continue;
        $relativo = ASSETS_JS . '/' . str_replace('\\', '/', substr($arquivo->getPathname(), strlen($base) + 1));
        $mapa['./' . $relativo] = './' . $relativo . '?v=' . $arquivo->getMTime();
    }
    ksort($mapa);

    $json = json_encode(['imports' => $mapa], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return $html = '<script type="importmap" nonce="' . e(csp_nonce()) . '">' . $json . '</script>';
}

/** Meta tags e links do PWA básico (manifesto, cor do tema e ícone de instalação). */
function pwa_head(string $corTema = '#2E7D32'): string
{
    return '<meta name="theme-color" content="' . e($corTema) . '">' . "\n    "
        . '<meta name="mobile-web-app-capable" content="yes">' . "\n    "
        . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n    "
        . '<meta name="apple-mobile-web-app-title" content="EcoCity">' . "\n    "
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n    "
        . '<link rel="manifest" href="' . asset('manifest.json') . '">' . "\n    "
        . '<link rel="apple-touch-icon" href="' . asset('assets/img/icone-pwa-180.png') . '">';
}
