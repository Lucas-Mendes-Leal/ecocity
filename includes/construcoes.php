<?php
/**
 * Construção limitada nos lotes de expansão (specs/site.md RN37–RN40).
 *
 *  - Lotes 20×20 ao redor do centro: 2 (direita), 3 (esquerda), 4 (frente).
 *    O lote N está disponível enquanto a cidade estiver no nível N ou acima
 *    (acompanha o nível atual; construções de lotes indisponíveis ficam guardadas e ocultas).
 *  - Colocar: estrutura ativa, nível mínimo, lote disponível, dentro dos limites,
 *    sem sobreposição e com créditos suficientes. O custo é debitado com histórico.
 *  - Remover: apaga a estrutura, sem devolver créditos.
 *  - Estruturas são decorativas; contam para conquistas (critério 'construcoes').
 *  - Lixo nos lotes (RN41): conforme o estágio do indicador de Reciclagem, células fixas
 *    de cada lote ficam com lixo e não aceitam construção.
 *  - Efeitos na cidade (RN77–RN80, migração 020): moradores (habitantes), carros/bicicletas/ônibus
 *    (somados à frota) e renda diária do comércio, coletada pelo jogador. Só contam as estruturas
 *    dos lotes disponíveis.
 */
declare(strict_types=1);

const HABITANTES_BASE = 5;
const HABITANTES_MAX = 50;
const VEICULOS_EXTRA_MAX = 20;
const RENDA_DIAS_MAX = 3;          // a renda acumula até 3 dias sem coletar
const RENDA_TETO_DIA = 15;         // teto de créditos por dia somando todas as estruturas (migração 021)
/** Limites dos efeitos na administração (RN80). */
const EFEITOS_LIMITES = ['moradores' => 20, 'carros' => 5, 'bicicletas' => 5, 'onibus' => 5, 'renda_diaria' => 20];

const LOTE_TAMANHO = 20;
/** Categorias das estruturas (abas da paleta do modo Construir). */
const CATEGORIAS_CONSTRUCAO = [
    'ecologica' => 'Ecológicas', 'vias' => 'Vias', 'comercio' => 'Comércio e lazer',
    'servicos' => 'Serviços e moradia', 'decoracao' => 'Decoração',
];
const EFEITOS_CONSTRUCAO = ['polui', 'compensa'];

/**
 * Desenho das estruturas no mapa (migração 018). Chaves dos 35 modelos de assets/js/city/structures.js
 * (o nome é só para a administração) + 'personalizado', montado pelas opções de APARENCIA_*.
 * As cores ficam no JS (apresentação); aqui só as chaves válidas.
 */
const DESENHOS_ESTRUTURA = [
    'lixeiras' => 'Lixeiras de coleta', 'ponto_onibus' => 'Ponto de ônibus', 'casa' => 'Casa sustentável', 'ciclofaixa' => 'Ciclofaixa',
    'horta' => 'Horta', 'praca' => 'Praça arborizada', 'pomar' => 'Pomar', 'mini_usina' => 'Painéis solares', 'lago' => 'Lago',
    'escola' => 'Escola', 'rua' => 'Rua', 'cruzamento' => 'Cruzamento', 'calcada' => 'Calçada', 'estacionamento' => 'Estacionamento',
    'bar' => 'Bar', 'balada' => 'Balada', 'lanchonete' => 'Lanchonete', 'mercado' => 'Mercado', 'padaria' => 'Padaria',
    'posto' => 'Posto de combustível', 'lava_rapido' => 'Lava-rápido', 'quadra' => 'Quadra esportiva', 'parquinho' => 'Parquinho',
    'predio' => 'Prédio residencial', 'hospital' => 'Hospital', 'bombeiros' => 'Corpo de bombeiros', 'delegacia' => 'Delegacia',
    'prefeitura' => 'Prefeitura', 'arbusto' => 'Arbusto', 'arvore' => 'Árvore', 'canteiro' => 'Canteiro de flores', 'poste' => 'Poste de luz',
    'banco' => 'Banco', 'cerca' => 'Cerca', 'fonte' => 'Fonte',
    'avenida' => 'Avenida (reta)', 'avenida_cruzamento' => 'Avenida (cruzamento)', 'avenida_curva' => 'Avenida (curva)', 'avenida_t' => 'Avenida (T)',
];
const DESENHO_PERSONALIZADO = 'personalizado';
const APARENCIA_PAREDE = ['branco', 'creme', 'cinza', 'verde', 'azul', 'amarelo', 'terracota', 'tijolo'];
const APARENCIA_TELHADO = ['verde', 'vermelho', 'cinza', 'azul', 'marrom', 'laranja'];
const APARENCIA_PLACA = ['verde', 'azul', 'laranja', 'vermelho', 'roxo'];
const APARENCIA_ICONES = ['leaf', 'droplet', 'bolt', 'recycle', 'sun', 'tree', 'bike', 'home', 'star', 'trophy', 'gift', 'coin', 'flask', 'users', 'help', 'flag'];
const APARENCIA_DETALHES = ['solar', 'arvores', 'telhado_verde'];   // legado (migração 018)
const APARENCIA_PADRAO = ['parede' => 'branco', 'telhado' => 'verde', 'formato' => 'plano', 'andares' => 1, 'toldo' => false,
                          'icone' => '', 'placa' => 'verde', 'detalhes' => []];

const CENA_PISOS = ['grama', 'concreto', 'pedra', 'madeira', 'terra', 'asfalto', 'areia', 'agua', 'faixa_branca', 'nenhum'];
const CENA_TELHADOS = ['plano', 'duas_aguas', 'verde', 'vidro'];
const CENA_ELEMENTOS = [
    'arvore', 'pinheiro', 'arbusto', 'flores', 'horta', 'lago', 'fonte',
    'painel_solar', 'turbina_eolica', 'chamine', 'lixeiras', 'banco',
    'poste', 'cerca', 'bicicleta', 'carro', 'bandeira', 'placa', 'caixa_dagua',
];
const GEOMETRIA_TIPOS = [
    'piso', 'edificio', 'barraca', 'pessoa',
    'caixa', 'cilindro', 'telhado', 'trelica', 'poligono', 'linha', 'cupula', 'fumaca',
    'arvore', 'pinheiro', 'arbusto', 'flores', 'horta', 'lago', 'fonte',
    'painel_solar', 'turbina_eolica', 'banco', 'poste', 'cerca', 'bicicleta', 'carro',
];
const GEOMETRIA_FORMAS = [
    'retangulo', 'elipse', 'envidracado', 'industrial', 'comercial',
    'duas_aguas_x', 'duas_aguas_y', 'piramide', 'rampa_frente', 'rampa_direita', 'dente_serra',
];
const GEOMETRIA_TOPO_DETALHES = ['', 'cruz_vermelha', 'heliponto', 'caixa_dagua', 'painel_solar', 'antena'];

/** Normaliza uma cor (aceita #RRGGBB ou nome das paletas do jogo; senão devolve o padrão). */
function normalizar_cor_cena(mixed $cor, string $padrao = '#ECEFF1'): string
{
    $mapa = [
        'branco' => '#FFFFFF', 'gelo' => '#ECEFF1', 'creme' => '#FFF3E0', 'cinza' => '#B0BEC5', 'cinza_escuro' => '#607D8B',
        'preto' => '#263238', 'verde' => '#66BB6A', 'verde_escuro' => '#2E7D32', 'verde_claro' => '#C8E6C9',
        'azul' => '#64B5F6', 'azul_escuro' => '#1565C0', 'azul_claro' => '#BBDEFB',
        'amarelo' => '#FFF176', 'dourado' => '#FBC02D', 'laranja' => '#FFB74D', 'laranja_escuro' => '#EF6C00',
        'terracota' => '#FFAB91', 'tijolo' => '#C08A6A', 'marrom' => '#6D4C41', 'pele' => '#FFCC80',
        'vermelho' => '#E53935', 'vermelho_escuro' => '#C62828', 'roxo' => '#9575CD', 'vidro' => '#B3E5FC',
    ];
    $s = strtolower(trim((string) $cor));
    if (isset($mapa[$s])) return $mapa[$s];
    if (preg_match('/^#[0-9a-f]{6}$/i', $s)) return strtoupper($s);
    if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $s, $m)) {
        return strtoupper("#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}");
    }
    return $padrao;
}

function clamp_num(mixed $v, float $min, float $max, float $padrao): float
{
    return is_numeric($v) ? round(max($min, min($max, (float) $v)), 3) : $padrao;
}

/**
 * Valida e normaliza uma cena 3D gerada pela IA (pisos, blocos e elementos) ou o formato legado de prédio.
 * @throws RegistroInvalido
 */
function validar_aparencia(mixed $a): array
{
    if (!is_array($a)) throw new RegistroInvalido('Aparência: opções inválidas.');

    // Novo formato de geometria 3D isométrica livre (gerado do zero pela IA local, RN76)
    if (isset($a['pecas']) && is_array($a['pecas'])) {
        $pisoBase = in_array($a['pisoBase'] ?? '', CENA_PISOS, true) ? (string) $a['pisoBase'] : 'concreto';
        $corBase = isset($a['corBase']) && $a['corBase'] !== '' ? normalizar_cor_cena($a['corBase'], '#CFD8DC') : null;
        $pecas = [];
        foreach (array_slice($a['pecas'], 0, 40) as $p) {
            if (!is_array($p)) continue;
            $tipo = (string) ($p['tipo'] ?? '');
            if (!in_array($tipo, GEOMETRIA_TIPOS, true)) continue;

            $estagios = [];
            if (is_array($p['estagios'] ?? null)) {
                foreach ($p['estagios'] as $e) {
                    $ei = (int) $e;
                    if ($ei >= 1 && $ei <= 4 && !in_array($ei, $estagios, true)) $estagios[] = $ei;
                }
            }
            if (!$estagios) $estagios = [1, 2, 3, 4];

            $cor = normalizar_cor_cena($p['cor'] ?? '#CFD8DC', '#CFD8DC');
            $topo = isset($p['topo']) && $p['topo'] !== '' ? normalizar_cor_cena($p['topo'], $cor) : null;
            $faixa = isset($p['faixa']) && $p['faixa'] !== '' ? normalizar_cor_cena($p['faixa'], '#E53935') : null;
            $forma = in_array($p['forma'] ?? '', GEOMETRIA_FORMAS, true) ? (string) $p['forma'] : 'retangulo';
            $andares = (int) clamp_num($p['andares'] ?? ($tipo === 'edificio' ? max(1, round((float) ($p['h'] ?? 1.2) / 0.55)) : 0), 0, 8, 0);
            $hBruto = clamp_num($p['h'] ?? 0.8, 0.02, 5.5, 0.8);
            if ($tipo === 'edificio' && $andares >= 1) {
                $hBruto = max($hBruto, round(0.45 + 0.52 * $andares, 2));
            }

            $item = [
                'tipo'        => $tipo,
                'forma'       => $forma,
                'x'           => clamp_num($p['x'] ?? 0.2, 0.0, 1.0, 0.2),
                'y'           => clamp_num($p['y'] ?? 0.2, 0.0, 1.0, 0.2),
                'w'           => clamp_num($p['w'] ?? 0.3, 0.02, 1.0, 0.3),
                'd'           => clamp_num($p['d'] ?? 0.3, 0.02, 1.0, 0.3),
                'z0'          => clamp_num($p['z0'] ?? 0.0, 0.0, 5.0, 0.0),
                'h'           => $hBruto,
                'cor'         => $cor,
                'topo'        => $topo,
                'faixa'       => $faixa,
                'espessura'   => clamp_num($p['espessura'] ?? 2.0, 0.6, 6.0, 2.0),
                'andares'     => $andares,
                'corJanela'   => isset($p['corJanela']) && $p['corJanela'] !== '' ? normalizar_cor_cena($p['corJanela'], '#B3E5FC') : '#B3E5FC',
                'corPorta'    => isset($p['corPorta']) && $p['corPorta'] !== '' ? normalizar_cor_cena($p['corPorta'], '#6D4C41') : '#6D4C41',
                'toldo'       => (bool) ($p['toldo'] ?? ($tipo === 'barraca')),
                'telhado'     => in_array($p['telhado'] ?? '', CENA_TELHADOS, true) ? (string) ($p['telhado'] ?? 'plano') : 'plano',
                'topoDetalhe' => in_array($p['topoDetalhe'] ?? '', GEOMETRIA_TOPO_DETALHES, true) ? (string) ($p['topoDetalhe'] ?? '') : '',
                'rodas'       => (bool) ($p['rodas'] ?? false),
                'chapeu'      => (bool) ($p['chapeu'] ?? true),
                'estagios'    => $estagios,
            ];

            if (($tipo === 'poligono' || $tipo === 'linha') && is_array($p['pontos'] ?? null)) {
                $pts = [];
                foreach (array_slice($p['pontos'], 0, 12) as $pt) {
                    if (!is_array($pt) || count($pt) < 2) continue;
                    $pts[] = [
                        clamp_num($pt[0] ?? 0.5, 0.0, 1.0, 0.5),
                        clamp_num($pt[1] ?? 0.5, 0.0, 1.0, 0.5),
                        clamp_num($pt[2] ?? 0.0, 0.0, 5.5, 0.0),
                    ];
                }
                if (count($pts) < 2) continue;
                $item['pontos'] = $pts;
            }

            $pecas[] = $item;
        }

        if (!$pecas) {
            $pecas[] = [
                'tipo' => 'edificio', 'forma' => 'retangulo', 'x' => 0.18, 'y' => 0.18, 'w' => 0.64, 'd' => 0.64,
                'z0' => 0.0, 'h' => 1.5, 'cor' => '#ECEFF1', 'topo' => '#90A4AE', 'faixa' => null,
                'espessura' => 2.0, 'andares' => 2, 'corJanela' => '#B3E5FC', 'corPorta' => '#6D4C41',
                'toldo' => false, 'telhado' => 'plano', 'topoDetalhe' => '', 'rodas' => false, 'chapeu' => false,
                'estagios' => [1, 2, 3, 4],
            ];
        }

        return [
            'plano_arquitetonico' => mb_substr(trim((string) ($a['plano_arquitetonico'] ?? '')), 0, 600),
            'pisoBase'            => $pisoBase,
            'corBase'             => $corBase,
            'pecas'               => $pecas,
            'prompt'              => mb_substr(trim((string) ($a['prompt'] ?? '')), 0, 400),
        ];
    }

    // Formato intermediário de cena 3D (blocos/elementos)
    if (isset($a['blocos']) || isset($a['elementos']) || isset($a['pisos']) || isset($a['piso'])) {
        $piso = in_array($a['piso'] ?? '', CENA_PISOS, true) ? (string) $a['piso'] : 'grama';
        $pisos = [];
        foreach (array_slice(is_array($a['pisos'] ?? null) ? $a['pisos'] : [], 0, 10) as $p) {
            if (!is_array($p)) continue;
            $tipo = in_array($p['tipo'] ?? '', CENA_PISOS, true) ? (string) $p['tipo'] : 'pedra';
            $rx = clamp_num($p['rx'] ?? 0.1, 0.0, 0.95, 0.1);
            $ry = clamp_num($p['ry'] ?? 0.1, 0.0, 0.95, 0.1);
            $pisos[] = [
                'tipo'  => $tipo,
                'forma' => ($p['forma'] ?? '') === 'elipse' ? 'elipse' : 'retangulo',
                'rx'    => $rx,
                'ry'    => $ry,
                'rw'    => clamp_num($p['rw'] ?? 0.8, 0.05, 1.0 - $rx, 0.8),
                'rd'    => clamp_num($p['rd'] ?? 0.8, 0.05, 1.0 - $ry, 0.8),
            ];
        }

        $blocos = [];
        foreach (array_slice(is_array($a['blocos'] ?? null) ? $a['blocos'] : [], 0, 10) as $b) {
            if (!is_array($b)) continue;
            $rx = clamp_num($b['rx'] ?? 0.15, 0.02, 0.9, 0.15);
            $ry = clamp_num($b['ry'] ?? 0.15, 0.02, 0.9, 0.15);
            $parede = normalizar_cor_cena($b['parede'] ?? '#ECEFF1', '#ECEFF1');
            $topo = normalizar_cor_cena($b['topo'] ?? ($b['telhado_cor'] ?? '#66BB6A'), '#66BB6A');
            $telhado = in_array($b['telhado'] ?? '', CENA_TELHADOS, true) ? (string) $b['telhado'] : 'plano';
            $andares = (int) clamp_num($b['andares'] ?? 1, 0, 6, 1);
            $hPadrao = $andares > 0 ? 0.55 + 0.58 * $andares : 0.8;
            $blocos[] = [
                'rx'        => $rx,
                'ry'        => $ry,
                'rw'        => clamp_num($b['rw'] ?? 0.7, 0.08, 0.98 - $rx, 0.7),
                'rd'        => clamp_num($b['rd'] ?? 0.7, 0.08, 0.98 - $ry, 0.7),
                'h'         => clamp_num($b['h'] ?? $hPadrao, 0.1, 4.2, $hPadrao),
                'z0'        => clamp_num($b['z0'] ?? 0, 0.0, 3.5, 0.0),
                'parede'    => $parede,
                'topo'      => $topo,
                'telhado'   => $telhado,
                'andares'   => $andares,
                'janelas'   => (bool) ($b['janelas'] ?? ($andares > 0)),
                'corJanela' => normalizar_cor_cena($b['corJanela'] ?? '#B3E5FC', '#B3E5FC'),
                'porta'     => (bool) ($b['porta'] ?? false),
                'toldo'     => (bool) ($b['toldo'] ?? false),
                'corToldo'  => normalizar_cor_cena($b['corToldo'] ?? $topo, $topo),
                'solar'     => (bool) ($b['solar'] ?? false),
            ];
        }

        $elementos = [];
        foreach (array_slice(is_array($a['elementos'] ?? null) ? $a['elementos'] : [], 0, 16) as $el) {
            if (!is_array($el)) continue;
            $tipo = (string) ($el['tipo'] ?? '');
            if (!in_array($tipo, CENA_ELEMENTOS, true)) continue;
            $icone = (string) ($el['icone'] ?? 'leaf');
            if (!in_array($icone, APARENCIA_ICONES, true)) $icone = 'leaf';
            $elementos[] = [
                'tipo'   => $tipo,
                'rx'     => clamp_num($el['rx'] ?? 0.5, 0.05, 0.95, 0.5),
                'ry'     => clamp_num($el['ry'] ?? 0.5, 0.05, 0.95, 0.5),
                'z0'     => clamp_num($el['z0'] ?? 0, 0.0, 4.5, 0.0),
                'escala' => clamp_num($el['escala'] ?? 1.0, 0.35, 1.8, 1.0),
                'cor'    => normalizar_cor_cena($el['cor'] ?? '#43A047', '#43A047'),
                'icone'  => $icone,
            ];
        }

        if (!$blocos && !$elementos && !$pisos) {
            $blocos[] = [
                'rx' => 0.18, 'ry' => 0.18, 'rw' => 0.64, 'rd' => 0.64, 'h' => 1.2, 'z0' => 0.0,
                'parede' => '#ECEFF1', 'topo' => '#66BB6A', 'telhado' => 'plano', 'andares' => 1,
                'janelas' => true, 'corJanela' => '#B3E5FC', 'porta' => true, 'toldo' => false,
                'corToldo' => '#66BB6A', 'solar' => false,
            ];
        }

        return [
            'piso'      => $piso,
            'pisos'     => $pisos,
            'blocos'    => $blocos,
            'elementos' => $elementos,
            'prompt'    => mb_substr(trim((string) ($a['prompt'] ?? '')), 0, 400),
        ];
    }

    // Formato legado (prédio simples)
    $escolha = static function (string $campo, array $lista, string $rotulo) use ($a): string {
        $v = (string) ($a[$campo] ?? APARENCIA_PADRAO[$campo]);
        if (!in_array($v, $lista, true)) throw new RegistroInvalido("{$rotulo}: escolha uma das opções.");
        return $v;
    };
    $r = [
        'parede'  => $escolha('parede', APARENCIA_PAREDE, 'Cor da parede'),
        'telhado' => $escolha('telhado', APARENCIA_TELHADO, 'Cor do telhado'),
        'formato' => $escolha('formato', ['plano', 'duas_aguas'], 'Telhado'),
        'placa'   => $escolha('placa', APARENCIA_PLACA, 'Cor da placa'),
        'icone'   => $escolha('icone', ['', ...APARENCIA_ICONES], 'Ícone da placa'),
    ];
    $andares = filter_var($a['andares'] ?? 1, FILTER_VALIDATE_INT);
    if ($andares === false || $andares < 1 || $andares > 5) throw new RegistroInvalido('Andares: de 1 a 5.');
    $r['andares'] = $andares;
    $r['toldo'] = filter_var($a['toldo'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $detalhes = $a['detalhes'] ?? [];
    if (!is_array($detalhes)) throw new RegistroInvalido('Detalhes: opções inválidas.');
    $detalhes = array_values(array_unique(array_map('strval', $detalhes)));
    foreach ($detalhes as $dt) {
        if (!in_array($dt, APARENCIA_DETALHES, true)) throw new RegistroInvalido('Detalhes: opção desconhecida.');
    }
    if ($r['formato'] !== 'plano' && array_intersect($detalhes, ['solar', 'telhado_verde'])) {
        throw new RegistroInvalido('Painel solar e telhado verde só no telhado plano.');
    }
    $r['detalhes'] = $detalhes;
    return $r;
}

/** Aparência gravada (JSON) → array para o JS; null se não houver. */
function aparencia_do_banco(?string $json): ?array
{
    if ($json === null || $json === '') return null;
    $a = json_decode($json, true);
    return is_array($a) ? $a : null;
}
const LOTES_EXPANSAO = [2, 3, 4];   // número do lote = nível da cidade que o libera
const INDICADOR_RECICLAGEM = 1;
const LIXO_CANDIDATAS = 12;                           // células possíveis de lixo por lote
const LIXO_POR_ESTAGIO = [1 => 12, 2 => 7, 3 => 3, 4 => 0];   // quantas aparecem (provisório)

/** Células candidatas a lixo de um lote: sempre as mesmas, na mesma ordem. */
function celulas_lixo_candidatas(int $lote): array
{
    $s = $lote * 7919 + 13;
    $celulas = [];
    while (count($celulas) < LIXO_CANDIDATAS) {
        $s = ($s * 1103515245 + 12345) % 2147483648;
        $x = intdiv($s, 65536) % LOTE_TAMANHO;
        $s = ($s * 1103515245 + 12345) % 2147483648;
        $y = intdiv($s, 65536) % LOTE_TAMANHO;
        $celulas["$x,$y"] = ['x' => $x, 'y' => $y];
    }
    return array_values($celulas);
}

/**
 * Lixo visível nos lotes disponíveis, conforme o estágio da Reciclagem.
 * Células já ocupadas por estruturas não recebem lixo.
 */
function lixo_dos_lotes(array $lotes, int $estagioReciclagem, array $construidas): array
{
    $quantos = LIXO_POR_ESTAGIO[$estagioReciclagem] ?? 0;
    $lixo = [];
    foreach ($lotes as $lote) {
        foreach (array_slice(celulas_lixo_candidatas($lote), 0, $quantos) as $c) {
            foreach ($construidas as $o) {
                if ($o['lote'] === $lote && $c['x'] >= $o['x'] && $c['x'] < $o['x'] + $o['w'] && $c['y'] >= $o['y'] && $c['y'] < $o['y'] + $o['d']) {
                    continue 2;
                }
            }
            $lixo[] = ['lote' => $lote, 'x' => $c['x'], 'y' => $c['y']];
        }
    }
    return $lixo;
}

/** Nível atual da cidade e id da cidade do jogador. */
function cidade_do_jogador(PDO $pdo, int $jogadorId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.cidade_id, n.nivel FROM cidade c JOIN nivel_cidade n ON n.nivel_cidade_id = c.nivel_cidade_id WHERE c.jogador_id = ?'
    );
    $stmt->execute([$jogadorId]);
    $r = $stmt->fetch();
    return ['cidadeId' => (int) $r['cidade_id'], 'nivel' => (int) $r['nivel']];
}

/** Lotes de expansão disponíveis para um nível de cidade. */
function lotes_disponiveis(int $nivelCidade): array
{
    return array_values(array_filter(LOTES_EXPANSAO, static fn(int $l): bool => $l <= $nivelCidade));
}

function catalogo_construcoes(): array
{
    return array_map(static fn(array $c): array => [
        'id'          => (int) $c['construcao_id'],
        'nome'        => $c['nome'],
        'descricao'   => $c['descricao'],
        'largura'     => (int) $c['largura'],
        'profundidade'=> (int) $c['profundidade'],
        'custo'       => (int) $c['custo'],
        'nivelMinimo' => (int) $c['nivel_cidade_minimo'],
        'categoria'   => $c['categoria'],
        'efeito'      => $c['efeito'],
        'indicador'   => $c['indicador_ambiental_id'] !== null ? INDICADOR_CHAVE[(int) $c['indicador_ambiental_id']] ?? null : null,
        'desenho'     => $c['desenho'],
        'aparencia'   => aparencia_do_banco($c['aparencia']),
        'moradores'   => (int) $c['moradores'],
        'carros'      => (int) $c['carros'],
        'bicicletas'  => (int) $c['bicicletas'],
        'onibus'      => (int) $c['onibus'],
        'renda'       => (int) $c['renda_diaria'],
    ], db()->query('SELECT * FROM construcao WHERE ativa = TRUE ORDER BY custo, construcao_id')->fetchAll());
}

/** Estruturas colocadas na cidade, com a área ocupada (já considerando o giro). */
function construcoes_da_cidade(PDO $pdo, int $cidadeId): array
{
    $stmt = $pdo->prepare(
        'SELECT cc.cidade_construcao_id, cc.construcao_id, cc.lote, cc.x, cc.y, cc.rotacao, c.nome, c.largura, c.profundidade, c.desenho, c.aparencia,
                c.renda_diaria, LEAST(?, DATEDIFF(CURDATE(), DATE(cc.renda_coletada_em))) AS dias_renda
           FROM cidade_construcao cc JOIN construcao c ON c.construcao_id = cc.construcao_id
          WHERE cc.cidade_id = ? ORDER BY cc.cidade_construcao_id'
    );
    $stmt->execute([RENDA_DIAS_MAX, $cidadeId]);
    return array_map(static function (array $r): array {
        $rotacao = (int) $r['rotacao'];
        $girada = $rotacao % 2 === 1;   // 90° ou 270°: largura e profundidade trocam
        return [
            'id'           => (int) $r['cidade_construcao_id'],
            'construcaoId' => (int) $r['construcao_id'],
            'nome'         => $r['nome'],
            'desenho'      => $r['desenho'],
            'aparencia'    => aparencia_do_banco($r['aparencia']),
            'lote'         => (int) $r['lote'],
            'x'            => (int) $r['x'],
            'y'            => (int) $r['y'],
            'w'            => $girada ? (int) $r['profundidade'] : (int) $r['largura'],
            'd'            => $girada ? (int) $r['largura'] : (int) $r['profundidade'],
            'rotacao'      => $rotacao,
            'girada'       => $girada,
            'renda'        => (int) $r['renda_diaria'] * max(0, (int) $r['dias_renda']),   // acumulada (antes do teto geral)
        ];
    }, $stmt->fetchAll());
}

/** Dados para a tela: catálogo, lotes disponíveis e estruturas colocadas. */
function dados_construcao(int $jogadorId): array
{
    $pdo = db();
    $cidade = cidade_do_jogador($pdo, $jogadorId);
    $lotes = lotes_disponiveis($cidade['nivel']);
    $construidas = construcoes_da_cidade($pdo, $cidade['cidadeId']);
    return [
        'catalogo'    => catalogo_construcoes(),
        'categorias'  => CATEGORIAS_CONSTRUCAO,
        'lotes'       => $lotes,
        'construidas' => $construidas,
        'lixo'        => lixo_dos_lotes($lotes, estagio_indicador($pdo, $cidade['cidadeId'], INDICADOR_RECICLAGEM), $construidas),
        'poluicao'    => (object) poluicao_por_chave($pdo, $cidade['cidadeId']),
        'efeitos'     => efeitos_cidade($pdo, $cidade['cidadeId']),
    ];
}

/**
 * Efeitos das estruturas dos lotes disponíveis (RN77–RN79): habitantes, veículos extra e renda a coletar.
 * @return array{habitantes:int, habitantesMax:int, carros:int, bicicletas:int, onibus:int, renda:int, rendaPorDia:int}
 */
function efeitos_cidade(PDO $pdo, int $cidadeId): array
{
    $stmt = $pdo->prepare(
        'SELECT c.moradores, c.carros, c.bicicletas, c.onibus, c.renda_diaria,
                LEAST(?, GREATEST(0, DATEDIFF(CURDATE(), DATE(cc.renda_coletada_em)))) AS dias
           FROM cidade_construcao cc
           JOIN construcao c ON c.construcao_id = cc.construcao_id
           JOIN cidade ci ON ci.cidade_id = cc.cidade_id
           JOIN nivel_cidade n ON n.nivel_cidade_id = ci.nivel_cidade_id
          WHERE cc.cidade_id = ? AND cc.lote <= n.nivel'
    );
    $stmt->execute([RENDA_DIAS_MAX, $cidadeId]);
    $e = ['moradores' => 0, 'carros' => 0, 'bicicletas' => 0, 'onibus' => 0, 'renda' => 0, 'rendaPorDia' => 0, 'diasMax' => 0];
    foreach ($stmt->fetchAll() as $l) {
        foreach (['moradores', 'carros', 'bicicletas', 'onibus'] as $k) $e[$k] += (int) $l[$k];
        $e['renda'] += (int) $l['renda_diaria'] * (int) $l['dias'];
        $e['rendaPorDia'] += (int) $l['renda_diaria'];
        if ((int) $l['renda_diaria'] > 0) $e['diasMax'] = max($e['diasMax'], (int) $l['dias']);
    }
    // Veículos extra até o teto, na ordem ônibus → bicicletas → carros
    $resto = VEICULOS_EXTRA_MAX;
    $onibus = min($e['onibus'], $resto); $resto -= $onibus;
    $bikes = min($e['bicicletas'], $resto); $resto -= $bikes;
    $carros = min($e['carros'], $resto);
    return [
        'habitantes'    => min(HABITANTES_MAX, HABITANTES_BASE + $e['moradores']),
        'habitantesMax' => HABITANTES_MAX,
        'carros'        => $carros,
        'bicicletas'    => $bikes,
        'onibus'        => $onibus,
        'renda'         => min($e['renda'], RENDA_TETO_DIA * $e['diasMax']),
        'rendaPorDia'   => min($e['rendaPorDia'], RENDA_TETO_DIA),
        'rendaTetoDia'  => RENDA_TETO_DIA,
    ];
}

/** Estruturas de lotes escondidos não acumulam renda: o relógio fica sempre em "agora" (RN79). */
function zerar_renda_lotes_escondidos(PDO $pdo, int $cidadeId): void
{
    $pdo->prepare(
        'UPDATE cidade_construcao cc
           JOIN construcao c ON c.construcao_id = cc.construcao_id
           JOIN cidade ci ON ci.cidade_id = cc.cidade_id
           JOIN nivel_cidade n ON n.nivel_cidade_id = ci.nivel_cidade_id
            SET cc.renda_coletada_em = NOW()
          WHERE cc.cidade_id = ? AND cc.lote > n.nivel AND c.renda_diaria > 0'
    )->execute([$cidadeId]);
}

/**
 * Coleta a renda acumulada do comércio (RN79). Retorna os créditos recebidos.
 * Um lançamento de histórico por estrutura (origem "renda"); o teto geral reduz por igual.
 */
function coletar_renda(int $jogadorId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$jogadorId]);
        $cidade = cidade_do_jogador($pdo, $jogadorId);
        $stmt = $pdo->prepare(
            'SELECT cc.cidade_construcao_id, cc.construcao_id, c.renda_diaria,
                    LEAST(?, GREATEST(0, DATEDIFF(CURDATE(), DATE(cc.renda_coletada_em)))) AS dias
               FROM cidade_construcao cc JOIN construcao c ON c.construcao_id = cc.construcao_id
              WHERE cc.cidade_id = ? AND cc.lote <= ? AND c.renda_diaria > 0
              FOR UPDATE'
        );
        $stmt->execute([RENDA_DIAS_MAX, $cidade['cidadeId'], $cidade['nivel']]);
        $linhas = array_filter($stmt->fetchAll(), static fn(array $l): bool => (int) $l['dias'] > 0);
        $bruto = 0;
        $diasMax = 0;
        foreach ($linhas as $l) {
            $bruto += (int) $l['renda_diaria'] * (int) $l['dias'];
            $diasMax = max($diasMax, (int) $l['dias']);
        }
        $total = min($bruto, RENDA_TETO_DIA * $diasMax);
        if ($total <= 0) throw new RegistroInvalido('Ainda não há renda para coletar. O comércio rende uma vez por dia.');

        // Divide o total entre as estruturas (proporcional, sobras para as primeiras) para o histórico
        $restante = $total;
        $partes = [];
        foreach ($linhas as $l) {
            $v = intdiv((int) $l['renda_diaria'] * (int) $l['dias'] * $total, $bruto);
            $partes[] = [$l, $v];
            $restante -= $v;
        }
        foreach ($partes as $i => [$l, $v]) {
            if ($restante <= 0) break;
            $partes[$i][1]++;
            $restante--;
        }
        $upd = $pdo->prepare('UPDATE cidade_construcao SET renda_coletada_em = NOW() WHERE cidade_construcao_id = ?');
        foreach ($partes as [$l, $v]) {
            if ($v > 0) creditar_jogador($pdo, $jogadorId, 0, $v, 'renda', 'construcao_id', (int) $l['construcao_id']);
            $upd->execute([$l['cidade_construcao_id']]);
        }
        $pdo->commit();
        return $total;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Poluição da cidade indexada pela chave do indicador (para a tela). */
function poluicao_por_chave(PDO $pdo, int $cidadeId): array
{
    $r = [];
    foreach (poluicao_cidade($pdo, $cidadeId) as $ind => $p) $r[INDICADOR_CHAVE[$ind]] = $p;
    return $r;
}

/** Debita créditos e registra o gasto (quantidade negativa). Deve rodar numa transação. */
function debitar_creditos(PDO $pdo, int $jogadorId, int $valor, int $construcaoId): void
{
    $saldo = $pdo->prepare('SELECT creditos_verde FROM jogador WHERE jogador_id = ? FOR UPDATE');
    $saldo->execute([$jogadorId]);
    if ((int) $saldo->fetchColumn() < $valor) {
        throw new RegistroInvalido('Créditos verdes insuficientes.');
    }
    if ($valor <= 0) {
        return;   // estrutura gratuita (custo 0 é permitido na administração): sem lançamento de valor zero
    }
    $pdo->prepare('UPDATE jogador SET creditos_verde = creditos_verde - ? WHERE jogador_id = ?')->execute([$valor, $jogadorId]);
    $pdo->prepare("INSERT INTO historico_credito_verde (jogador_id, quantidade, origem, data, construcao_id) VALUES (?, ?, 'construcao', NOW(), ?)")
        ->execute([$jogadorId, -$valor, $construcaoId]);
}

/** Coloca uma estrutura no lote. Retorna o nome da estrutura. */
/** @param int $rotacao 0, 1 (90°), 2 (180°) ou 3 (270°) */
function colocar_construcao(int $jogadorId, int $construcaoId, int $lote, int $x, int $y, int $rotacao): string
{
    if ($rotacao < 0 || $rotacao > 3) throw new RegistroInvalido('Direção inválida.');
    $girada = $rotacao % 2 === 1;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Bloqueia o jogador: serializa construções simultâneas (sobreposição e saldo)
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$jogadorId]);
        $cidade = cidade_do_jogador($pdo, $jogadorId);

        $stmt = $pdo->prepare('SELECT * FROM construcao WHERE construcao_id = ? AND ativa = TRUE');
        $stmt->execute([$construcaoId]);
        $c = $stmt->fetch();
        if (!$c) throw new RegistroInvalido('Estrutura não encontrada.');
        if ($cidade['nivel'] < (int) $c['nivel_cidade_minimo']) {
            throw new RegistroInvalido('Esta estrutura exige cidade nível ' . $c['nivel_cidade_minimo'] . '.');
        }
        if (!in_array($lote, lotes_disponiveis($cidade['nivel']), true)) {
            throw new RegistroInvalido('Este lote ainda não está disponível.');
        }

        $w = $girada ? (int) $c['profundidade'] : (int) $c['largura'];
        $d = $girada ? (int) $c['largura'] : (int) $c['profundidade'];
        if ($x < 0 || $y < 0 || $x + $w > LOTE_TAMANHO || $y + $d > LOTE_TAMANHO) {
            throw new RegistroInvalido('A estrutura não cabe nessa posição do lote.');
        }
        $construidas = construcoes_da_cidade($pdo, $cidade['cidadeId']);
        foreach ($construidas as $o) {
            if ($o['lote'] === $lote && $x < $o['x'] + $o['w'] && $o['x'] < $x + $w && $y < $o['y'] + $o['d'] && $o['y'] < $y + $d) {
                throw new RegistroInvalido('Já existe uma estrutura nesse espaço.');
            }
        }
        $estagio = estagio_indicador($pdo, $cidade['cidadeId'], INDICADOR_RECICLAGEM);
        foreach (lixo_dos_lotes([$lote], $estagio, $construidas) as $l) {
            if ($l['x'] >= $x && $l['x'] < $x + $w && $l['y'] >= $y && $l['y'] < $y + $d) {
                throw new RegistroInvalido('Há lixo nesse espaço. Melhore a reciclagem da cidade para limpar o lote.');
            }
        }

        debitar_creditos($pdo, $jogadorId, (int) $c['custo'], $construcaoId);
        $pdo->prepare(
            'INSERT INTO cidade_construcao (cidade_id, construcao_id, lote, x, y, rotacao, data_construcao, renda_coletada_em) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$cidade['cidadeId'], $construcaoId, $lote, $x, $y, $rotacao]);

        avaliar_conquistas($pdo, $jogadorId);
        $pdo->commit();
        return $c['nome'];
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Remove uma estrutura da cidade do jogador (sem devolver créditos). */
function remover_construcao(int $jogadorId, int $cidadeConstrucaoId): string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cidade = cidade_do_jogador($pdo, $jogadorId);
        $stmt = $pdo->prepare(
            'SELECT cc.lote, c.nome FROM cidade_construcao cc JOIN construcao c ON c.construcao_id = cc.construcao_id
              WHERE cc.cidade_construcao_id = ? AND cc.cidade_id = ? FOR UPDATE'
        );
        $stmt->execute([$cidadeConstrucaoId, $cidade['cidadeId']]);
        $r = $stmt->fetch();
        if (!$r) throw new RegistroInvalido('Estrutura não encontrada.');
        if (!in_array((int) $r['lote'], lotes_disponiveis($cidade['nivel']), true)) {
            throw new RegistroInvalido('Este lote não está disponível no nível atual da cidade.');
        }
        $pdo->prepare('DELETE FROM cidade_construcao WHERE cidade_construcao_id = ?')->execute([$cidadeConstrucaoId]);
        $pdo->commit();
        return $r['nome'];
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}
