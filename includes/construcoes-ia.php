<?php
/**
 * Geração e refinamento de estruturas 3D isométricas do zero por prompt (e foto opcional)
 * na IA local (Ollama: Qwen2.5-Coder 14B para geometria 3D + Qwen3-VL 8B para visão, RN76/RN91).
 *
 *  - A IA planeja primeiro (`plano_arquitetonico`) e depois modela a estrutura usando o kit
 *    híbrido de construtores arquitetônicos (`edificio`, `barraca`, `pessoa`, elementos vivos)
 *    e primitivas CAD 3D (`piso`, `caixa`, `cilindro`, `telhado`, `trelica`, `linha`, `poligono`,
 *    `cupula`, `fumaca`).
 *  - Cada peça define `estagios` ([1..4]) para evoluir nos 4 estágios ecológicos, e todas as
 *    coordenadas 3D giram automaticamente em 0°, 90°, 180° e 270° em `iso.js`.
 *  - Se o administrador anexar uma imagem de referência (`foto`) ou refinar um desenho existente
 *    (`previa_atual`), o Qwen3-VL analisa a imagem e entrega o diagnóstico visual para o gerador 3D.
 */
declare(strict_types=1);

const IA_FOTO_ESTRUTURA_MAX_BYTES = 5 * 1024 * 1024;
const IA_FOTO_ESTRUTURA_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

const IA_ESQUEMA_GEOMETRIA_3D = [
    'type' => 'object',
    'properties' => [
        'plano_arquitetonico' => ['type' => 'string'],
        'pisoBase'            => ['type' => 'string', 'enum' => CENA_PISOS],
        'corBase'             => ['type' => 'string'],
        'pecas'               => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'tipo'        => ['type' => 'string', 'enum' => GEOMETRIA_TIPOS],
                    'forma'       => ['type' => 'string', 'enum' => GEOMETRIA_FORMAS],
                    'x'           => ['type' => 'number'],
                    'y'           => ['type' => 'number'],
                    'w'           => ['type' => 'number'],
                    'd'           => ['type' => 'number'],
                    'z0'          => ['type' => 'number'],
                    'h'           => ['type' => 'number'],
                    'cor'         => ['type' => 'string'],
                    'topo'        => ['type' => 'string'],
                    'faixa'       => ['type' => 'string'],
                    'andares'     => ['type' => 'integer'],
                    'corJanela'   => ['type' => 'string'],
                    'corPorta'    => ['type' => 'string'],
                    'telhado'     => ['type' => 'string', 'enum' => CENA_TELHADOS],
                    'topoDetalhe' => ['type' => 'string', 'enum' => GEOMETRIA_TOPO_DETALHES],
                    'rodas'       => ['type' => 'boolean'],
                    'chapeu'      => ['type' => 'boolean'],
                    'espessura'   => ['type' => 'number'],
                    'pontos'      => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'array',
                            'items' => ['type' => 'number'],
                        ],
                    ],
                    'estagios'    => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                    ],
                ],
                'required' => ['tipo', 'x', 'y', 'w', 'd', 'z0', 'h', 'cor', 'topo', 'estagios'],
            ],
        ],
    ],
    'required' => ['plano_arquitetonico', 'pisoBase', 'corBase', 'pecas'],
];

const IA_ESQUEMA_ESTRUTURA_COMPLETA = [
    'type' => 'object',
    'properties' => [
        'nome'         => ['type' => 'string'],
        'descricao'    => ['type' => 'string'],
        'categoria'    => ['type' => 'string', 'enum' => ['ecologica', 'vias', 'comercio', 'servicos', 'decoracao']],
        'largura'      => ['type' => 'integer'],
        'profundidade' => ['type' => 'integer'],
        'custo'        => ['type' => 'integer'],
        'nivel'        => ['type' => 'integer'],
        'efeito'       => ['type' => 'string', 'enum' => ['nenhum', 'polui', 'compensa']],
        'area'         => ['type' => 'integer'],
        'moradores'    => ['type' => 'integer'],
        'renda_diaria' => ['type' => 'integer'],
        'carros'       => ['type' => 'integer'],
        'bicicletas'   => ['type' => 'integer'],
        'onibus'       => ['type' => 'integer'],
        'cena'         => IA_ESQUEMA_GEOMETRIA_3D,
    ],
    'required' => [
        'nome', 'descricao', 'categoria', 'largura', 'profundidade', 'custo', 'nivel',
        'efeito', 'area', 'moradores', 'renda_diaria', 'carros', 'bicicletas', 'onibus', 'cena',
    ],
];

const IA_ESQUEMA_VISAO_ESTRUTURA = [
    'type' => 'object',
    'properties' => [
        'analise_visual'      => ['type' => 'string'],
        'orientacoes_3d'      => ['type' => 'string'],
    ],
    'required' => ['analise_visual', 'orientacoes_3d'],
];

/** Lê e reduz uma imagem opcional enviada pelo administrador ou a prévia PNG atual. */
function ia_ler_foto_referencia(?array $arq): array
{
    if (!$arq || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [];
    if (($arq['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($arq['tmp_name']) || !is_file($arq['tmp_name'])) {
        throw new RegistroInvalido('Não foi possível ler a imagem enviada.');
    }
    if (($arq['size'] ?? 0) > IA_FOTO_ESTRUTURA_MAX_BYTES) {
        throw new RegistroInvalido('A imagem de referência deve ter no máximo 5 MB.');
    }
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($arq['tmp_name']) : (string) ($arq['type'] ?? '');
    if (!in_array($mime, IA_FOTO_ESTRUTURA_MIMES, true)) {
        throw new RegistroInvalido('Imagem de referência: envie um arquivo JPG, PNG ou WebP.');
    }
    return [ia_preparar_foto($arq['tmp_name'])];
}

/**
 * Usa o modelo de visão (Qwen3-VL) para inspecionar a foto de referência e/ou a prévia renderizada atual
 * quando o gerador 3D principal é um modelo dedicado de código (Qwen2.5-Coder 14B).
 */
function ia_analisar_imagens_estrutura(string $prompt, array $fotoRef, array $previaAtual): ?string
{
    $imagens = array_merge($fotoRef, $previaAtual);
    if (!$imagens) return null;
    $c = ia_config();
    $nomes = ia_modelos_instalados();
    if (!in_array($c['modelo'], $nomes, true)) return null;

    $sistema = 'Você é um arquiteto e inspetor visual 3D isométrico do jogo EcoCity. Responda em português do Brasil, em JSON.';
    if ($fotoRef && $previaAtual) {
        $pedido = "Você recebeu 2 imagens: a primeira é a IMAGEM DE REFERÊNCIA do administrador e a segunda é a PRÉVIA ATUAL renderizada no jogo.\n"
            . "Pedido do administrador: \"{$prompt}\".\n"
            . "Descreva em `analise_visual` o que aparece na referência e o que está errado ou faltando na prévia atual, e em `orientacoes_3d` dê instruções precisas de volumes, cores (#RRGGBB), andares e posições (x, y, w, d, z0, h) para corrigir o modelo 3D.";
    } elseif ($fotoRef) {
        $pedido = "Analise esta IMAGEM DE REFERÊNCIA para criar uma estrutura 3D isométrica no EcoCity.\n"
            . "Pedido do administrador: \"{$prompt}\".\n"
            . "Descreva em `analise_visual` o tipo de construção, formato principal, número de andares, cores dominantes (#RRGGBB), telhado e detalhes marcantes, e em `orientacoes_3d` liste exatamente quais peças 3D (`edificio`, `barraca`, `pessoa`, `cilindro`, `caixa`, `trelica`, `telhado`, `arvore`, etc.) usar.";
    } else {
        $pedido = "Esta imagem é a PRÉVIA ATUAL renderizada da estrutura 3D isométrica no EcoCity.\n"
            . "Pedido de ajuste do administrador: \"{$prompt}\".\n"
            . "Inspecione visualmente a imagem: em `analise_visual` aponte o que ficou bom e se há peças colidindo, proporções estranhas ou elementos faltando; em `orientacoes_3d` explique exatamente quais peças adicionar, remover ou reposicionar.";
    }

    try {
        $r = ia_chamar($sistema, $pedido, $imagens, IA_ESQUEMA_VISAO_ESTRUTURA, 90, $c['modelo'], 8192);
        if (is_array($r['dados'])) {
            return "Diagnóstico visual da imagem (Qwen3-VL):\n- Análise: " . ($r['dados']['analise_visual'] ?? '')
                . "\n- Orientações 3D: " . ($r['dados']['orientacoes_3d'] ?? '') . "\n\n";
        }
    } catch (Throwable $ex) {
        error_log('EcoCity — inspeção visual de estrutura: ' . $ex->getMessage());
    }
    return null;
}

function ia_instrucoes_geometria_3d(bool $comFoto): string
{
    return "Como modelar a arquitetura 3D Isométrica em `cena` (estilo vetorial SVG limpo do EcoCity):\n"
        . "1. PRIMEIRO preencha `plano_arquitetonico` (1 a 3 frases): planeje qual é o volume principal, quantos andares reais ele tem, quais peças secundárias entram ao redor e garanta que nenhuma peça ocupe o mesmo espaço `(x, y)` no chão.\n"
        . "2. SISTEMA DE COORDENADAS 3D (`[x .. x+w, y .. y+d, z0 .. z0+h]`):\n"
        . "   - `x` (0.06 a 0.85) e `y` (0.06 a 0.85) são o canto inicial no terreno normalizado (0 a 1); `w` e `d` são a largura e a profundidade (`x + w <= 0.94`, `y + d <= 0.94`).\n"
        . "   - `y` perto de `0.10` é o FUNDO (norte); `y + d` perto de `0.88` é a FRENTE (sul), onde ficam as portas, vitrines e balcões.\n"
        . "   - `z0` é a altura da base (`0` para peças apoiadas no chão). Para colocar algo EM CIMA de um bloco de altura `h: 1.2`, use `z0: 1.2`.\n"
        . "   - NUNCA coloque várias caixas lado a lado para fingir andares! Se o usuário pedir um prédio/hospital/hotel de N andares, use UMA ÚNICA peça `tipo: 'edificio'` com `andares: N` e `h: 0.5 + 0.52 * N` (ex.: 5 andares -> `andares: 5, h: 3.1`)!\n"
        . "3. CATÁLOGO HÍBRIDO DE PEÇAS 3D (`tipo` em `pecas`):\n"
        . "   A) CONSTRUTORES ARQUITETÔNICOS E PERSONAGENS (preferidos para prédios, comércios, barracas e pessoas):\n"
        . "      - `edificio`: prédio ou casa completa de 1 a 8 andares empilhados verticalmente! Campos:\n"
        . "        * `andares` (1 a 8), `h` (`0.5 + 0.52 * andares`, ex.: 1 andar=1.1, 3 andares=2.1, 5 andares=3.1),\n"
        . "        * `cor` (parede `#RRGGBB`), `topo` (cor do teto `#RRGGBB`), `faixa` (cor da marquise/letreiro frontal `#RRGGBB`),\n"
        . "        * `corJanela` (`#B3E5FC` vidro azul claro), `corPorta` (`#6D4C41` marrom, `#B3E5FC` vidro ou `#37474F` ferro),\n"
        . "        * `telhado`: `'plano'`, `'duas_aguas'`, `'verde'` ou `'vidro'`,\n"
        . "        * `topoDetalhe`: `''` (nenhum), `'cruz_vermelha'` (cruz vermelha 3D no teto e na fachada — obrigatório para hospital/clínica!), `'heliponto'`, `'caixa_dagua'`, `'painel_solar'` ou `'antena'`.\n"
        . "      - `barraca`: barraquinha de rua, quiosque ou carrinho de lanche/cachorro-quente/feira! Desenha balcão com tampo de inox, bandeja e bisnagas, 4 hastes metálicas e toldo listrado alto nas cores `topo` (cor 1 do toldo, ex.: `#E53935`) e `faixa` (cor 2 do toldo e faixa frontal, ex.: `#FDD835` ou `#FFFFFF`), mais `rodas: true` se for carrinho ambulante.\n"
        . "      - `pessoa`: bonequinho/personagem 3D isométrico (pernas, tronco na `cor`, avental/detalhe em `faixa`, braços, cabeça e boné opcional `chapeu: true`). DICA: se você colocar uma `pessoa` dentro da mesma área `(x, y)` de uma `barraca`, ela aparece automaticamente atrás do balcão como VENDEDOR debaixo do toldo!\n"
        . "   B) PRIMITIVAS CAD 3D (para indústrias, usinas, tanques, torres, canos e detalhes):\n"
        . "      - `piso`: base no chão (`z0: 0, h: 0.04`, `forma`: `'retangulo'` ou `'elipse'`).\n"
        . "      - `caixa`: prisma 3D (`forma`: `'retangulo'`, `'industrial'`, `'envidracado'` ou `'comercial'`).\n"
        . "      - `cilindro`: tanque cilíndrico vertical, silo ou chaminé (`w` igual a `d`, `cor`, `topo` e `faixa` horizontal opcional).\n"
        . "      - `trelica`: torre metálica treliçada 3D em X (torre de petróleo/perfuração, guindaste ou torre elétrica).\n"
        . "      - `telhado`: cobertura inclinada avulsa (`forma`: `'duas_aguas_x'`, `'piramide'`, `'rampa_frente'`, `'dente_serra'`).\n"
        . "      - `linha`: tubulação/cano 3D ligando `pontos: [[x1,y1,z1], [x2,y2,z2]]` com `espessura` (2 a 4).\n"
        . "      - `cupula`: domo/esfera/chama 3D em `z0`.\n"
        . "      - `fumaca`: fumaça/vapor no topo de chaminé (`z0` = altura do topo da chaminé).\n"
        . "   C) ELEMENTOS VIVOS E URBANOS (evoluem sozinhos nos 4 estágios ecológicos):\n"
        . "      - `arvore`, `pinheiro`, `arbusto`, `flores`, `horta`, `lago`, `fonte`, `painel_solar`, `turbina_eolica`, `banco`, `poste`, `cerca`, `bicicleta`, `carro`.\n"
        . "4. EXEMPLOS DE REFERÊNCIA (siga este padrão de qualidade):\n"
        . "   - Exemplo A (\"hospital branco com 5 andares, porta marrom, vidros e cruz vermelha no topo\"):\n"
        . "     `pisoBase: 'concreto'`, `pecas`: [\n"
        . "       `{tipo:'edificio', forma:'retangulo', x:0.18, y:0.16, w:0.64, d:0.58, z0:0, h:3.1, cor:'#FFFFFF', topo:'#ECEFF1', faixa:'#E53935', andares:5, corJanela:'#B3E5FC', corPorta:'#6D4C41', telhado:'plano', topoDetalhe:'cruz_vermelha', estagios:[1,2,3,4]}`,\n"
        . "       `{tipo:'piso', forma:'retangulo', x:0.34, y:0.74, w:0.32, d:0.20, z0:0, h:0.04, cor:'#B0BEC5', topo:'#B0BEC5', estagios:[1,2,3,4]}`,\n"
        . "       `{tipo:'arvore', forma:'retangulo', x:0.06, y:0.72, w:0.18, d:0.18, z0:0, h:1.0, cor:'#43A047', topo:'#66BB6A', estagios:[2,3,4]}`,\n"
        . "       `{tipo:'arvore', forma:'retangulo', x:0.78, y:0.72, w:0.18, d:0.18, z0:0, h:1.0, cor:'#43A047', topo:'#66BB6A', estagios:[3,4]}`\n"
        . "     ]\n"
        . "   - Exemplo B (\"barraquinha de cachorro-quente com um bonequinho dentro como vendedor\"):\n"
        . "     `pisoBase: 'pedra'`, `pecas`: [\n"
        . "       `{tipo:'barraca', forma:'comercial', x:0.20, y:0.22, w:0.60, d:0.52, z0:0, h:1.45, cor:'#E53935', topo:'#E53935', faixa:'#FDD835', rodas:true, estagios:[1,2,3,4]}`,\n"
        . "       `{tipo:'pessoa', forma:'retangulo', x:0.42, y:0.38, w:0.16, d:0.16, z0:0, h:0.95, cor:'#1E88E5', topo:'#E53935', faixa:'#FFFFFF', chapeu:true, estagios:[1,2,3,4]}`,\n"
        . "       `{tipo:'banco', forma:'retangulo', x:0.10, y:0.80, w:0.22, d:0.14, z0:0, h:0.4, cor:'#8D6E63', topo:'#A1887F', estagios:[2,3,4]}`,\n"
        . "       `{tipo:'arvore', forma:'retangulo', x:0.78, y:0.14, w:0.16, d:0.16, z0:0, h:0.9, cor:'#43A047', topo:'#66BB6A', estagios:[3,4]}`\n"
        . "     ]\n"
        . "   - Exemplo C (\"estação petrolífera\"):\n"
        . "     2 `cilindro` grandes brancos com `faixa:'#E53935'` (`x:0.08, y:0.12, w:0.28, d:0.28, h:1.4` e `x:0.08, y:0.46, w:0.28, d:0.28, h:1.4`), 1 `trelica` metálica (`x:0.44, y:0.10, w:0.24, d:0.24, h:2.3, cor:'#37474F'`), 1 galpão `caixa` `forma:'industrial'` (`x:0.44, y:0.48, w:0.44, d:0.36, h:1.1, cor:'#455A64'`), `linha` de tubulação, chaminé flare `cilindro` (`x:0.76, y:0.14, w:0.10, d:0.10, h:2.1`) + `fumaca` escura em `estagios:[1,2]` e `arvore`/`painel_solar` em `estagios:[3,4]`.";
}

/**
 * Gera ou refina uma estrutura 3D usando o Ollama local (Qwen2.5-Coder 14B + Qwen3-VL 8B para visão).
 * @param string $modo 'tudo' (dados + geometria 3D) ou 'desenho' (só geometria 3D)
 * @param array $contexto dados atuais do formulário
 * @param array|null $arquivoFoto elemento de $_FILES['foto'] quando enviado
 * @param array|null $arquivoPrevia elemento de $_FILES['previa_atual'] (PNG da prévia atual no modo 'desenho')
 */
function gerar_estrutura_ia(
    string $prompt,
    string $modo = 'tudo',
    array $contexto = [],
    ?array $arquivoFoto = null,
    ?array $arquivoPrevia = null
): array {
    $prompt = trim($prompt);
    $fotoRef = ia_ler_foto_referencia($arquivoFoto);
    $previaPng = ia_ler_foto_referencia($arquivoPrevia);
    if ($prompt === '' && $fotoRef) {
        $prompt = 'Gere a estrutura 3D isométrica baseada na imagem de referência anexada.';
    }
    $len = mb_strlen($prompt, 'UTF-8');
    if ($len < 3 || $len > 400) {
        throw new RegistroInvalido('Descreva a estrutura que deseja gerar (de 3 a 400 caracteres) ou envie uma imagem de referência.');
    }
    if (!ia_disponivel()) {
        throw new RegistroInvalido('A IA não está disponível no momento. Tente novamente em instantes.');
    }

    $modeloGerador = ia_modelo_estruturas();
    $modeloPadraoVisao = ia_config()['modelo'];
    $tempoVisaoMs = 0;
    $diagnosticoVisual = null;
    $imagensParaGerador = [];

    // Na RTX 3060 (12 GB VRAM), alternar entre qwen3-vl:8b (6,2 GB) e qwen2.5-coder:14b (9,0 GB)
    // descarrega e recarrega pesos do disco (~120 s), enquanto manter o qwen2.5-coder:14b na VRAM
    // gera ou refina estruturas em ~15–20 s. Por isso, só acionamos o modelo de visão (Qwen3-VL)
    // quando o administrador anexa uma IMAGEM DE REFERÊNCIA externa ($fotoRef).
    if ($fotoRef) {
        if ($modeloGerador !== $modeloPadraoVisao) {
            $t0 = microtime(true);
            $diagnosticoVisual = ia_analisar_imagens_estrutura($prompt, $fotoRef, $previaPng);
            $tempoVisaoMs = (int) round((microtime(true) - $t0) * 1000);
        } else {
            $imagensParaGerador = array_merge($fotoRef, $previaPng);
        }
    } elseif ($previaPng && $modeloGerador === $modeloPadraoVisao) {
        $imagensParaGerador = $previaPng;
    }

    $sistema = 'Você é um arquiteto e modelador CAD 3D isométrico especialista no jogo EcoCity. '
        . 'Você planeja a arquitetura em `plano_arquitetonico` e constrói estruturas 3D fiéis ao pedido '
        . 'combinando `edificio` (com `andares` reais empilhados verticalmente e `topoDetalhe`), `barraca` (com toldo listrado), '
        . '`pessoa` (bonequinho/vendedor 3D), primitivas CAD (`cilindro`, `trelica`, `caixa`, `telhado`, `linha`) e elementos ecológicos. '
        . 'Responda SEMPRE em português do Brasil, em JSON válido.';

    $cenaAtual = null;
    if (is_array($contexto['aparencia'] ?? null) && isset($contexto['aparencia']['pecas'])) {
        $cenaAtual = $contexto['aparencia'];
        unset($cenaAtual['prompt']);
    }

    if ($modo === 'desenho') {
        $nome = trim((string) ($contexto['nome'] ?? '')) ?: 'Estrutura';
        $larg = max(1, min(10, (int) ($contexto['largura'] ?? 3)));
        $prof = max(1, min(10, (int) ($contexto['profundidade'] ?? 3)));
        $cat = (string) ($contexto['categoria'] ?? 'ecologica');
        $pedido = "Estrutura: \"{$nome}\" (terreno {$larg}×{$prof} células, categoria: {$cat}).\n"
            . "Pedido visual do administrador: \"{$prompt}\".\n"
            . ($diagnosticoVisual ?? '')
            . ($cenaAtual
                ? "Geometria 3D atual da estrutura (aplique o ajuste pedido ou reconstrua melhorando a arquitetura):\n" . json_encode($cenaAtual, JSON_UNESCAPED_UNICODE) . "\n\n"
                : "Modele a geometria 3D isométrica completa para esta estrutura.\n\n")
            . ia_instrucoes_geometria_3d((bool) ($fotoRef || $previaPng));

        try {
            $resp = ia_chamar($sistema, $pedido, $imagensParaGerador, IA_ESQUEMA_GEOMETRIA_3D, 180, $modeloGerador, 8192);
        } catch (IaIndisponivel $ex) {
            throw new RegistroInvalido('Não foi possível falar com a IA: ' . $ex->getMessage());
        }
        if (!is_array($resp['dados'])) {
            throw new RegistroInvalido('A IA não retornou um desenho válido. Tente detalhar melhor o pedido.');
        }
        $aparencia = validar_aparencia($resp['dados'] + ['prompt' => $prompt]);
        return [
            'modo'      => 'desenho',
            'modelo'    => $modeloGerador,
            'desenho'   => DESENHO_PERSONALIZADO,
            'aparencia' => $aparencia,
            'tempoMs'   => $resp['tempoMs'] + $tempoVisaoMs,
        ];
    }

    // Modo 'tudo': gera os campos sugeridos + a geometria 3D completa
    $pedido = "Pedido do administrador para uma nova estrutura no EcoCity: \"{$prompt}\".\n\n"
        . ($diagnosticoVisual ?? '')
        . "Regras dos campos sugeridos:\n"
        . "- nome: nome curto e claro em PT-BR (3 a 60 caracteres).\n"
        . "- descricao: 1 frase explicando a estrutura (10 a 180 caracteres).\n"
        . "- categoria: 'ecologica', 'vias', 'comercio', 'servicos' ou 'decoracao'.\n"
        . "- largura e profundidade: de 1 a 5 células (ex.: 1×1 decoração, 2×2 barraca/quiosque/loja/casa, 3×3 ou 4×3 hospital/prédio/usina/parque).\n"
        . "- custo: em créditos verdes (decoração 15–180, vias 20–250, comércio 250–650, ecológicas/serviços/indústria 300–1400).\n"
        . "- nivel: nível mínimo da cidade de 2 a 4.\n"
        . "- efeito: 'polui' (se for poluidora como estação petrolífera, fábrica, posto), 'compensa' (se for ecológica/sustentável) ou 'nenhum'.\n"
        . "- area: 1=Reciclagem e resíduos, 2=Água, 3=Energia, 4=Mobilidade, 5=Preservação da natureza.\n"
        . "- moradores (0 a 15, só moradia), renda_diaria (0 a 8, OBRIGATORIAMENTE 0 se categoria != 'comercio'), carros (0 a 4), bicicletas (0 a 4), onibus (0 a 2).\n\n"
        . ia_instrucoes_geometria_3d((bool) $fotoRef);

    try {
        $resp = ia_chamar($sistema, $pedido, $imagensParaGerador, IA_ESQUEMA_ESTRUTURA_COMPLETA, 180, $modeloGerador, 8192);
    } catch (IaIndisponivel $ex) {
        throw new RegistroInvalido('Não foi possível falar com a IA: ' . $ex->getMessage());
    }
    $d = $resp['dados'];
    if (!is_array($d) || !is_array($d['cena'] ?? null)) {
        throw new RegistroInvalido('A IA não retornou uma estrutura válida. Tente reformular o prompt.');
    }

    $cat = array_key_exists($d['categoria'] ?? '', CATEGORIAS_CONSTRUCAO) ? $d['categoria'] : 'ecologica';
    $ef = in_array($d['efeito'] ?? '', EFEITOS_CONSTRUCAO, true) ? $d['efeito'] : '';
    $area = $ef !== '' ? max(1, min(5, (int) ($d['area'] ?? 3))) : '';
    $aparencia = validar_aparencia($d['cena'] + ['prompt' => $prompt]);

    return [
        'modo'      => 'tudo',
        'modelo'    => $modeloGerador,
        'desenho'   => DESENHO_PERSONALIZADO,
        'aparencia' => $aparencia,
        'tempoMs'   => $resp['tempoMs'] + $tempoVisaoMs,
        'estrutura' => [
            'nome'         => mb_substr(trim((string) ($d['nome'] ?? 'Nova Estrutura')) ?: 'Nova Estrutura', 0, 100),
            'descricao'    => mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 250),
            'categoria'    => $cat,
            'largura'      => max(1, min(10, (int) ($d['largura'] ?? 3))),
            'profundidade' => max(1, min(10, (int) ($d['profundidade'] ?? 3))),
            'custo'        => max(0, min(10000, (int) ($d['custo'] ?? 450))),
            'nivel'        => max(1, min(4, (int) ($d['nivel'] ?? 2))),
            'efeito'       => $ef,
            'area'         => $area,
            'moradores'    => max(0, min(20, (int) ($d['moradores'] ?? 0))),
            'renda_diaria' => $cat === 'comercio' ? max(0, min(20, (int) ($d['renda_diaria'] ?? 0))) : 0,
            'carros'       => max(0, min(5, (int) ($d['carros'] ?? 0))),
            'bicicletas'   => max(0, min(5, (int) ($d['bicicletas'] ?? 0))),
            'onibus'       => max(0, min(5, (int) ($d['onibus'] ?? 0))),
            'desenho'      => DESENHO_PERSONALIZADO,
            'aparencia'    => $aparencia,
        ],
    ];
}
