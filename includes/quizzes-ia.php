<?php
/**
 * Quizzes gerados pela IA a partir de notícias ambientais recentes (RN91).
 *
 * 1. Lê os RSS de fontes públicas (ONU News, Agência Brasil, Copernicus) e guarda as
 *    notícias novas em noticia_ia.
 * 2. A IA escolhe até 3 temas entre as notícias dos últimos 14 dias ainda não usadas e classifica cada um numa área.
 * 3. Para cada tema, gera um quiz Temático de 5 perguntas (4 alternativas, uma correta, explicação e a notícia-fonte).
 * 4. Tudo passa pelas mesmas regras de salvar_quiz() e é gravado DESATIVADO (origem 'ia'): o administrador revisa e ativa.
 */
declare(strict_types=1);

const IA_FONTES_RSS = [
    'ONU News'                => 'https://news.un.org/feed/subscribe/pt/news/topic/climate-change/feed/rss.xml',
    'Agência Brasil'          => 'https://agenciabrasil.ebc.com.br/rss/meio-ambiente/feed.xml',
    'Copernicus'              => 'https://climate.copernicus.eu/rss.xml',
];
const IA_NOTICIA_DIAS = 14;
const IA_QUIZZES_POR_VEZ = 3;
const IA_PERGUNTAS_POR_QUIZ = 5;
const IA_PERGUNTAS_GERADAS = 7;   // gera a mais: as que a conferência reprovar são descartadas
const IA_QUIZ_XP = 40;        // recompensa base dos quizzes (valores aprovados: 40 XP / 25 créditos × dificuldade)
const IA_QUIZ_CREDITO = 25;

/** Baixa um RSS e devolve os itens [titulo, url, resumo, data]. */
function ia_ler_rss(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 15,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT       => 'EcoCity (projeto academico)',
    ]);
    $xml = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($xml === false || $codigo !== 200) return [];
    $doc = @simplexml_load_string((string) $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$doc || !isset($doc->channel->item)) return [];
    $itens = [];
    foreach ($doc->channel->item as $item) {
        $link = trim((string) $item->link);
        if (!preg_match('#^https?://#i', $link)) continue;
        $resumo = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $item->description), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $data = strtotime((string) $item->pubDate);
        $itens[] = [
            'titulo' => mb_substr(trim(html_entity_decode((string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 200),
            'url'    => mb_substr($link, 0, 300),
            'resumo' => mb_substr($resumo, 0, 600),
            'data'   => $data ? date('Y-m-d H:i:s', $data) : null,
        ];
    }
    return $itens;
}

/** Lê todas as fontes, grava as notícias novas e devolve as dos últimos 14 dias ainda não usadas (com resumo). */
function ia_coletar_noticias(): array
{
    $pdo = db();
    $ins = $pdo->prepare('INSERT IGNORE INTO noticia_ia (url, titulo, fonte, publicada_em, lida_em) VALUES (?, ?, ?, ?, NOW())');
    $resumos = [];
    foreach (IA_FONTES_RSS as $fonte => $url) {
        foreach (ia_ler_rss($url) as $n) {
            $ins->execute([$n['url'], $n['titulo'], $fonte, $n['data']]);
            $resumos[$n['url']] = $n['resumo'];
        }
    }
    $s = $pdo->query('SELECT noticia_ia_id, url, titulo, fonte, publicada_em FROM noticia_ia
                       WHERE usada_em IS NULL AND (publicada_em IS NULL OR publicada_em >= NOW() - INTERVAL ' . IA_NOTICIA_DIAS . ' DAY)
                    ORDER BY publicada_em DESC LIMIT 40');
    return array_map(static fn($r) => [
        'id' => (int) $r['noticia_ia_id'], 'titulo' => $r['titulo'], 'url' => $r['url'], 'fonte' => $r['fonte'],
        'data' => $r['publicada_em'], 'resumo' => $resumos[$r['url']] ?? '',
    ], $s->fetchAll());
}

/** Áreas do jogo para a classificação: [id => nome]. */
function ia_areas(): array
{
    return db()->query('SELECT categoria_ambiental_id, nome FROM categoria_ambiental ORDER BY categoria_ambiental_id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** A IA escolhe até 3 temas (cada um com 1 a 3 notícias) e a área de cada um. */
function ia_escolher_temas(array $noticias, array $areas): array
{
    $lista = implode("\n", array_map(static fn($n) => "[{$n['id']}] ({$n['fonte']}) {$n['titulo']}"
        . ($n['resumo'] !== '' ? ' — ' . mb_substr($n['resumo'], 0, 220) : ''), $noticias));
    $nomesAreas = implode('; ', array_map(static fn($id, $nome) => "{$id} = {$nome}", array_keys($areas), $areas));
    $esquema = ['type' => 'object', 'properties' => ['temas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
        'tema' => ['type' => 'string'], 'area' => ['type' => 'integer'], 'noticias' => ['type' => 'array', 'items' => ['type' => 'integer']],
    ], 'required' => ['tema', 'area', 'noticias']]]], 'required' => ['temas']];
    $r = ia_chamar(
        'Você é editor de conteúdo educativo ambiental do EcoCity (jovens a partir de 13 anos). Responda em JSON.',
        "Notícias ambientais recentes (número entre colchetes):\n{$lista}\n\n"
        . 'Escolha até ' . IA_QUIZZES_POR_VEZ . " temas DIFERENTES e relevantes para educação ambiental (ex.: El Niño, queimadas, enchentes, "
        . "acordos do clima, energia renovável). Para cada tema, indique 1 a 3 números de notícias sobre ele e a área do jogo "
        . "mais próxima ({$nomesAreas}). Ignore notícias sem conteúdo ambiental. O nome do tema em português, com até 40 letras.",
        [], $esquema, 180
    );
    $ids = array_column($noticias, 'id');
    $temas = [];
    foreach ($r['dados']['temas'] ?? [] as $t) {
        $nots = array_values(array_unique(array_filter(array_map('intval', (array) ($t['noticias'] ?? [])), static fn($i) => in_array($i, $ids, true))));
        if (!$nots || !isset($areas[(int) ($t['area'] ?? 0)])) continue;
        $temas[] = ['tema' => mb_substr(trim((string) $t['tema']), 0, 40), 'area' => (int) $t['area'], 'noticias' => array_slice($nots, 0, 3)];
        if (count($temas) >= IA_QUIZZES_POR_VEZ) break;
    }
    return $temas;
}

/** Limpa, valida e submete à conferência da IA o conjunto de perguntas de um quiz gerado. */
function ia_montar_quiz_validado(?array $d, int $areaId, string $temaTitulo, string $textoConferencia, array $base = [], bool $usarNoticias = true): ?array
{
    if (!is_array($d) || count($d['perguntas'] ?? []) < 3) return null;
    $limpar = static fn($t) => trim(preg_replace('/^\s*(?:[A-Da-d][\)\.:-]|\([A-Da-d]\))\s+/u', '', (string) $t));   // tira "A) ", "b. " etc.
    $cortar = static fn($t, $max) => mb_strlen(trim((string) $t)) > $max ? rtrim(mb_substr(trim((string) $t), 0, $max - 1)) . '…' : trim((string) $t);
    $perguntas = [];
    foreach ($d['perguntas'] as $p) {
        $alts = array_values(array_filter(array_map($limpar, (array) ($p['alternativas'] ?? [])), static fn($a) => $a !== ''));
        $correta = (int) ($p['correta'] ?? -1);
        if (count($alts) < 2 || count($alts) > 6 || !isset($alts[$correta]) || mb_strlen(trim((string) $p['enunciado'])) < 3) continue;
        if (preg_match('/not[ií]cia\s*\d|segundo a not[ií]cia|todas as anteriores|ambas as anteriores/iu', $p['enunciado'] . ' ' . implode(' ', $alts))) continue;
        $fonte = $base ? ($base[(int) ($p['fonte'] ?? 1) - 1] ?? $base[0]) : null;
        $explicacao = $cortar($p['explicacao'] ?? '', 200);
        $perguntas[] = [
            'enunciado' => $cortar($p['enunciado'], 150),
            'fonteTitulo' => $fonte ? $cortar($fonte['titulo'], 200) : '',
            'fonteUrl' => $fonte ? $fonte['url'] : '',
            'alternativas' => array_map(static fn($i, $a) => ['texto' => $cortar($a, 100), 'correta' => $i === $correta,
                'explicacao' => $i === $correta ? $explicacao : ''], array_keys($alts), $alts),
        ];
    }
    $perguntas = array_slice(ia_conferir_perguntas($perguntas, $textoConferencia, $usarNoticias), 0, IA_PERGUNTAS_POR_QUIZ);
    if (count($perguntas) < QUIZ_TIPOS['tematico']['min']) return null;
    $dific = array_key_exists($d['dificuldade'] ?? '', QUIZ_MULTIPLICADOR) ? $d['dificuldade'] : 'Médio';
    $titulo = $cortar($d['titulo'] ?? $temaTitulo, 50);
    if (mb_strlen($titulo) < 3) $titulo = $cortar($temaTitulo, 50);
    $descricao = $cortar($d['descricao'] ?? '', 150);
    if (mb_strlen($descricao) < 3) $descricao = $cortar("Quiz educativo sobre {$temaTitulo}.", 150);
    return [
        'titulo' => $titulo, 'descricao' => $descricao,
        'categoria' => $areaId, 'xp' => IA_QUIZ_XP, 'credito' => IA_QUIZ_CREDITO, 'ativa' => false,
        'dificuldade' => $dific, 'tipo' => 'tematico', 'perguntas' => $perguntas,
        'noticias' => $base ? array_column($base, 'id') : [],
    ];
}

/** Gera um quiz sobre o tema a partir das notícias; devolve os dados no formato de salvar_quiz(). */
function ia_gerar_quiz(array $tema, array $noticias): ?array
{
    $base = array_values(array_filter($noticias, static fn($n) => in_array($n['id'], $tema['noticias'], true)));
    $textos = implode("\n\n", array_map(static fn($i, $n) => '[' . ($i + 1) . "] {$n['titulo']} ({$n['fonte']}, " . substr((string) $n['data'], 0, 10) . ")\n{$n['resumo']}",
        array_keys($base), $base));
    $esquema = ['type' => 'object', 'properties' => [
        'titulo' => ['type' => 'string'], 'descricao' => ['type' => 'string'], 'dificuldade' => ['type' => 'string', 'enum' => array_keys(QUIZ_MULTIPLICADOR)],
        'perguntas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'enunciado' => ['type' => 'string'], 'fonte' => ['type' => 'integer'], 'correta' => ['type' => 'integer'], 'explicacao' => ['type' => 'string'],
            'alternativas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['enunciado', 'fonte', 'alternativas', 'correta', 'explicacao']]],
    ], 'required' => ['titulo', 'descricao', 'dificuldade', 'perguntas']];
    $r = ia_chamar(
        'Você cria quizzes educativos de educação ambiental em português do Brasil para jovens a partir de 13 anos. '
        . 'Use SOMENTE fatos presentes nas notícias fornecidas ou conhecimento científico básico e consolidado. Nunca invente números ou datas. Responda em JSON.',
        "Tema: {$tema['tema']}\nNotícias:\n{$textos}\n\n"
        . 'Crie um quiz com ' . IA_PERGUNTAS_GERADAS . " perguntas de múltipla escolha. Regras (obrigatórias):\n"
        . "- TUDO em português do Brasil, inclusive as alternativas;\n"
        . "- titulo: até 45 caracteres; descricao: até 140 caracteres;\n"
        . "- enunciado: até 140 caracteres, compreensível sozinho: NUNCA escreva \"segundo a notícia\", \"notícia 1\" nem o nome da agência;\n"
        . "- 4 alternativas de até 90 caracteres, sem letras ou números na frente (nada de \"A)\");\n"
        . "- correta: posição (0 a 3) da alternativa VERDADEIRA de acordo com o texto das notícias; confira antes de responder; varie a posição;\n"
        . "- números, datas e lugares só se estiverem escritos nas notícias, exatamente como estão;\n"
        . "- explicacao: até 190 caracteres, explicando por que a resposta está certa e ligando o tema a uma atitude sustentável;\n"
        . "- fonte: número da notícia em que a pergunta se baseia.\n"
        . 'Pelo menos 2 perguntas sobre o que uma pessoa pode fazer no dia a dia (a alternativa correta é a atitude sustentável). '
        . 'Perguntas claras, sem pegadinhas e sem \"todas as anteriores\".',
        [], $esquema, 240
    );
    return ia_montar_quiz_validado($r['dados'], (int) $tema['area'], (string) $tema['tema'], $textos, $base, true);
}

/** Gera 1 quiz sobre um tema livre sugerido pelo administrador, sem depender de notícias RSS. */
function ia_gerar_quiz_por_tema(string $temaSugerido, array $areas, ?int $areaSugerida = null): ?array
{
    $nomesAreas = implode('; ', array_map(static fn($id, $nome) => "{$id} = {$nome}", array_keys($areas), $areas));
    $props = [
        'titulo' => ['type' => 'string'], 'descricao' => ['type' => 'string'], 'dificuldade' => ['type' => 'string', 'enum' => array_keys(QUIZ_MULTIPLICADOR)],
        'perguntas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'enunciado' => ['type' => 'string'], 'correta' => ['type' => 'integer'], 'explicacao' => ['type' => 'string'],
            'alternativas' => ['type' => 'array', 'items' => ['type' => 'string']],
        ], 'required' => ['enunciado', 'alternativas', 'correta', 'explicacao']]],
    ];
    $req = ['titulo', 'descricao', 'dificuldade', 'perguntas'];
    if ($areaSugerida === null) {
        $props['area'] = ['type' => 'integer'];
        $req[] = 'area';
    }
    $esquema = ['type' => 'object', 'properties' => $props, 'required' => $req];
    $r = ia_chamar(
        'Você cria quizzes educativos de educação ambiental em português do Brasil para jovens a partir de 13 anos. '
        . 'Use SOMENTE conhecimento científico consolidado e fatos corretos. Nunca invente estatísticas, datas ou estudos falsos. Responda em JSON.',
        "Tema sugerido pelo administrador: {$temaSugerido}\n"
        . ($areaSugerida !== null ? "Área ambiental definida: {$areaSugerida} = {$areas[$areaSugerida]}\n\n" : "Áreas disponíveis do jogo: {$nomesAreas}\n\n")
        . 'Crie um quiz com ' . IA_PERGUNTAS_GERADAS . " perguntas de múltipla escolha sobre o tema sugerido. Regras (obrigatórias):\n"
        . "- TUDO em português do Brasil, inclusive as alternativas;\n"
        . ($areaSugerida === null ? "- area: número do ID da área disponível acima que mais combina com o tema;\n" : '')
        . "- titulo: até 45 caracteres; descricao: até 140 caracteres;\n"
        . "- enunciado: até 140 caracteres, claro e autoexplicativo;\n"
        . "- 4 alternativas de até 90 caracteres, sem letras ou números na frente (nada de \"A)\");\n"
        . "- correta: posição (0 a 3) da alternativa VERDADEIRA; confira antes de responder e varie a posição;\n"
        . "- explicacao: até 190 caracteres, explicando por que a resposta está certa e ligando o tema a uma atitude sustentável;\n"
        . 'Pelo menos 2 perguntas sobre o que uma pessoa pode fazer no dia a dia (a alternativa correta é a atitude sustentável). '
        . 'Perguntas claras, sem pegadinhas e sem "todas as anteriores".',
        [], $esquema, 240
    );
    $areaId = $areaSugerida ?? (int) ($r['dados']['area'] ?? 0);
    if (!isset($areas[$areaId])) $areaId = (int) array_key_first($areas);
    $refConferencia = "Tema: {$temaSugerido} (área: {$areas[$areaId]}).";
    return ia_montar_quiz_validado($r['dados'], $areaId, $temaSugerido, $refConferencia, [], false);
}

/**
 * Conferência: a IA responde de novo cada pergunta SEM ver o gabarito, só com as notícias ou o tema.
 * Fica só a pergunta em que ela marca a mesma alternativa (descarta erro factual e gabarito trocado).
 */
function ia_conferir_perguntas(array $perguntas, string $textos, bool $usarNoticias = true): array
{
    if (!$perguntas) return [];
    $lista = implode("\n\n", array_map(static fn($i, $p) => 'Pergunta ' . ($i + 1) . ': ' . $p['enunciado'] . "\n"
        . implode("\n", array_map(static fn($j, $a) => "  {$j}) {$a['texto']}", array_keys($p['alternativas']), $p['alternativas'])),
        array_keys($perguntas), $perguntas));
    $esquema = ['type' => 'object', 'properties' => ['respostas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
        'pergunta' => ['type' => 'integer'], 'alternativa' => ['type' => 'integer'],
    ], 'required' => ['pergunta', 'alternativa']]]], 'required' => ['respostas']];
    $pedidoConf = $usarNoticias
        ? "Notícias de referência:\n{$textos}\n\nPara cada pergunta abaixo, escolha a alternativa correta (número) com base nas notícias "
            . "e no conhecimento científico consolidado. Em perguntas sobre atitudes, a correta é a atitude sustentável. "
            . "Se nenhuma alternativa estiver correta, responda -1.\n\n{$lista}"
        : "Tema de referência: {$textos}\n\nPara cada pergunta abaixo, escolha a alternativa correta (número) com base no conhecimento científico consolidado "
            . "sobre educação ambiental. Em perguntas sobre atitudes, a correta é a atitude sustentável. "
            . "Se nenhuma alternativa estiver correta, responda -1.\n\n{$lista}";
    try {
        $r = ia_chamar(
            'Você é um revisor rigoroso de quizzes de educação ambiental. Responda em JSON.',
            $pedidoConf,
            [], $esquema, 240
        );
    } catch (IaIndisponivel) {
        return [];   // sem conferência, nada é gravado
    }
    $resp = [];
    foreach ($r['dados']['respostas'] ?? [] as $x) $resp[(int) ($x['pergunta'] ?? 0) - 1] = (int) ($x['alternativa'] ?? -1);
    return array_values(array_filter($perguntas, static function ($p, $i) use ($resp) {
        $certa = array_search(true, array_column($p['alternativas'], 'correta'), true);
        return isset($resp[$i]) && $resp[$i] === $certa;
    }, ARRAY_FILTER_USE_BOTH));
}

/**
 * Executa a geração completa. Devolve um resumo: { criados: [ {id, titulo} ], noticias: N, erros: [...] }.
 * Uma geração por vez (trava no banco). Quando $temaSugerido está preenchido, cria 1 rascunho focado nele.
 */
function gerar_quizzes_ia(string $temaSugerido = '', ?int $areaSugerida = null): array
{
    $temaSugerido = ia_normalizar_tema($temaSugerido);
    $areas = ia_areas();
    if ($areaSugerida !== null && !isset($areas[$areaSugerida])) {
        throw new RegistroInvalido('Área ambiental inválida.');
    }
    if (!ia_disponivel()) throw new RegistroInvalido('A IA não está disponível no momento. Tente novamente em instantes.');
    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('ecocity_quizzes_ia', 0)")->fetchColumn() !== 1) {
        throw new RegistroInvalido('Já existe uma geração de quizzes em andamento.');
    }
    try {
        $salvarGerado = static function (array $quiz, string $rotulo, array &$resumo) use ($pdo): void {
            salvar_quiz($quiz, $novoId);
            ia_aplicar_politica_publicacao($pdo, 'quiz', $novoId);
            if ($quiz['noticias']) {
                $ids = $quiz['noticias'];
                $pdo->prepare('UPDATE noticia_ia SET usada_em = NOW(), quiz_id = ? WHERE noticia_ia_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                    ->execute([$novoId, ...$ids]);
            }
            $resumo['criados'][] = ['id' => $novoId, 'titulo' => $quiz['titulo']];
        };

        if ($temaSugerido !== '') {
            $resumo = ['criados' => [], 'noticias' => 0, 'erros' => []];
            try {
                $quiz = ia_gerar_quiz_por_tema($temaSugerido, $areas, $areaSugerida);
                if (!$quiz) {
                    $resumo['erros'][] = "{$temaSugerido}: a IA não gerou perguntas válidas.";
                } else {
                    $salvarGerado($quiz, $temaSugerido, $resumo);
                }
            } catch (RegistroInvalido | IaIndisponivel $ex) {
                $resumo['erros'][] = "{$temaSugerido}: " . $ex->getMessage();
            }
            return $resumo;
        }

        $noticias = ia_coletar_noticias();
        if (count($noticias) < 2) throw new RegistroInvalido('Não há notícias novas suficientes (últimos ' . IA_NOTICIA_DIAS . ' dias).');
        $resumo = ['criados' => [], 'noticias' => count($noticias), 'erros' => []];
        try {
            $temas = ia_escolher_temas($noticias, $areas);
        } catch (IaIndisponivel $ex) {
            throw new RegistroInvalido('A IA não respondeu ao escolher os temas. Tente de novo em alguns minutos.');
        }
        foreach ($temas as $tema) {
            try {
                $quiz = ia_gerar_quiz($tema, $noticias);
                if (!$quiz) { $resumo['erros'][] = "{$tema['tema']}: a IA não gerou perguntas válidas."; continue; }
                $salvarGerado($quiz, $tema['tema'], $resumo);
            } catch (RegistroInvalido | IaIndisponivel $ex) {
                $resumo['erros'][] = "{$tema['tema']}: " . $ex->getMessage();
            }
        }
        return $resumo;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ecocity_quizzes_ia')");
    }
}
