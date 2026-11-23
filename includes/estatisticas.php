<?php
/**
 * Estatísticas e relatórios (RN81–RN84).
 *
 * Administração: uma função por seção (est_aprendizado, est_acoes, est_engajamento, est_cidade,
 * est_aprenda_pratique), todas com o período em dias (0 = desde o início).
 * Jogador: est_jogador() para o Perfil e o relatório pessoal.
 *
 * Privacidade (RN83): só números somados; recortes com menos de MIN_GRUPO jogadores
 * vêm com 'poucos' => true e sem as taxas.
 */
declare(strict_types=1);

const MIN_GRUPO = 5;
const PERIODOS_ESTATISTICA = [7, 30, 90, 0];
const INATIVO_DIAS = 14;
const JANELA_PRATICA_DIAS = 7;
const SEMANAS_PERFIL = 8;

/** Semana (segunda-feira) de uma data, em SQL. */
const SQL_SEMANA = "DATE_SUB(DATE(%s), INTERVAL WEEKDAY(%s) DAY)";

function sql_semana(string $coluna): string
{
    return sprintf(SQL_SEMANA, $coluna, $coluna);
}

/** Grava que o jogador usou o jogo hoje (uma consulta por dia e sessão). */
function registrar_acesso(int $jogadorId): void
{
    $hoje = date('Y-m-d');
    if (($_SESSION['acesso_registrado'] ?? '') === $hoje . ':' . $jogadorId) {
        return;
    }
    db()->prepare('INSERT IGNORE INTO acesso_diario (jogador_id, data) VALUES (?, ?)')->execute([$jogadorId, $hoje]);
    $_SESSION['acesso_registrado'] = $hoje . ':' . $jogadorId;
}

/** Data inicial do período (inclusive). */
function inicio_periodo(int $dias): string
{
    if (!in_array($dias, PERIODOS_ESTATISTICA, true)) {
        throw new RegistroInvalido('Período inválido.');
    }
    return $dias === 0 ? '1000-01-01' : date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
}

/** Percentual com 1 casa (null sem base). */
function pct(float|int $parte, float|int $total): ?float
{
    return $total > 0 ? round($parte / $total * 100, 1) : null;
}

/** Nome curto das categorias (ids 1–5 = indicadores 1–5). */
function categorias_estatistica(PDO $pdo): array
{
    $lista = [];
    foreach ($pdo->query('SELECT categoria_ambiental_id, nome FROM categoria_ambiental ORDER BY categoria_ambiental_id') as $c) {
        $lista[(int) $c['categoria_ambiental_id']] = ['chave' => INDICADOR_CHAVE[(int) $c['categoria_ambiental_id']] ?? '', 'nome' => $c['nome']];
    }
    return $lista;
}

/** Maior sequência e sequência atual (até hoje ou ontem) de dias seguidos numa lista de datas Y-m-d. */
function sequencias_de_dias(array $datas): array
{
    $datas = array_values(array_unique($datas));
    sort($datas);
    $melhor = 0; $atual = 0; $anterior = null;
    foreach ($datas as $d) {
        $atual = ($anterior !== null && $d === date('Y-m-d', strtotime($anterior . ' +1 day'))) ? $atual + 1 : 1;
        $melhor = max($melhor, $atual);
        $anterior = $d;
    }
    $vivo = $anterior !== null && $anterior >= date('Y-m-d', strtotime('-1 day'));
    return ['melhor' => $melhor, 'atual' => $vivo ? $atual : 0];
}

/* ---------------------------------------------------------------------------
   Respostas de quiz das tentativas concluídas, com a ordem de cada jogador × quiz
   --------------------------------------------------------------------------- */
function respostas_concluidas(PDO $pdo, string $desde, ?int $jogadorId = null): array
{
    $sql = 'SELECT t.tentativa_id, t.jogador_id, t.quiz_id, t.data, q.categoria_ambiental_id AS cat,
                   r.pergunta_id, r.alternativa_id, r.correta, pt.tentativa_id AS primeira   -- view vw_quiz_primeira_tentativa
              FROM tentativa_quiz t
              JOIN quiz q ON q.quiz_id = t.quiz_id
              JOIN resposta_quiz r ON r.tentativa_id = t.tentativa_id
         LEFT JOIN vw_quiz_primeira_tentativa pt ON pt.tentativa_id = t.tentativa_id
             WHERE t.acertos IS NOT NULL AND t.data >= ?' . ($jogadorId ? ' AND t.jogador_id = ?' : '') . '
          ORDER BY t.tentativa_id';
    $s = $pdo->prepare($sql);
    $s->execute($jogadorId ? [$desde, $jogadorId] : [$desde]);
    return $s->fetchAll();
}

/** Soma uma resposta no grupo $id (respostas, acertos e jogadores distintos). */
function acumular_respostas(array &$grupos, int $id, int $correta, int $jogador): void
{
    $grupos[$id] ??= ['respostas' => 0, 'acertos' => 0, 'jogadores' => []];
    $grupos[$id]['respostas']++;
    $grupos[$id]['acertos'] += $correta;
    $grupos[$id]['jogadores'][$jogador] = true;
}

/* ---------------------------------------------------------------------------
   Administração — Aprendizado
   --------------------------------------------------------------------------- */
function est_aprendizado(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);
    $cats = categorias_estatistica($pdo);
    $linhas = respostas_concluidas($pdo, $desde);

    $porTema = []; $porQuiz = []; $porPergunta = []; $porSemana = [];
    $primeiraResposta = [];   // jogador:pergunta → correta na 1ª tentativa
    $treino = ['erradas' => 0, 'refeitas' => 0, 'corrigidas' => 0, 'jogadores' => []];
    $refeitas = [];

    foreach ($linhas as $l) {
        $jog = (int) $l['jogador_id'];
        $chave = $jog . ':' . $l['pergunta_id'];
        if ($l['primeira'] !== null) {   // é a 1ª tentativa concluída deste jogador neste quiz
            $c = (int) $l['correta'];
            acumular_respostas($porTema, (int) $l['cat'], $c, $jog);
            acumular_respostas($porQuiz, (int) $l['quiz_id'], $c, $jog);
            acumular_respostas($porPergunta, (int) $l['pergunta_id'], $c, $jog);
            if (!$c) {
                $porPergunta[(int) $l['pergunta_id']]['erradas'][(int) $l['alternativa_id']] = ($porPergunta[(int) $l['pergunta_id']]['erradas'][(int) $l['alternativa_id']] ?? 0) + 1;
            }
            $semana = date('Y-m-d', strtotime($l['data'] . ' -' . ((int) date('N', strtotime($l['data'])) - 1) . ' days'));
            $porSemana[$semana] ??= [];
            acumular_respostas($porSemana[$semana], (int) $l['cat'], $c, $jog);
            $primeiraResposta[$chave] = $c;
            if (!$c) { $treino['erradas']++; }
        } elseif (isset($primeiraResposta[$chave]) && !$primeiraResposta[$chave] && !isset($refeitas[$chave])) {
            // Primeira vez que refez uma pergunta errada na 1ª tentativa
            $refeitas[$chave] = true;
            $treino['refeitas']++;
            $treino['corrigidas'] += (int) $l['correta'];
            $treino['jogadores'][$jog] = true;
        }
    }

    $fechar = static function (array $g): array {
        $n = count($g['jogadores']);
        return ['respostas' => $g['respostas'], 'jogadores' => $n, 'poucos' => $n < MIN_GRUPO,
                'acerto' => $n < MIN_GRUPO ? null : pct($g['acertos'], $g['respostas'])];
    };

    $temas = [];
    foreach ($cats as $id => $c) {
        $temas[] = ['categoria' => $c['chave'], 'nome' => $c['nome']] + (isset($porTema[$id]) ? $fechar($porTema[$id]) : ['respostas' => 0, 'jogadores' => 0, 'poucos' => true, 'acerto' => null]);
    }

    $quizzes = [];
    if ($porQuiz) {
        $nomes = $pdo->query('SELECT quiz_id, titulo, tipo, categoria_ambiental_id FROM quiz')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($porQuiz as $id => $g) {
            $q = $nomes[$id] ?? null;
            $quizzes[] = ['titulo' => $q['titulo'] ?? '—', 'tipo' => $q['tipo'] ?? '', 'categoria' => INDICADOR_CHAVE[(int) ($q['categoria_ambiental_id'] ?? 0)] ?? ''] + $fechar($g);
        }
        usort($quizzes, static fn($a, $b) => [$a['acerto'] === null, $a['acerto']] <=> [$b['acerto'] === null, $b['acerto']]);
    }

    // As 10 perguntas mais erradas (só com jogadores suficientes) e a alternativa errada mais escolhida
    $erradas = [];
    foreach ($porPergunta as $id => $g) {
        if (count($g['jogadores']) < MIN_GRUPO || $g['acertos'] === $g['respostas']) continue;   // poucos jogadores ou ninguém errou
        $erradas[$id] = $g;
    }
    uasort($erradas, static fn($a, $b) => ($a['acertos'] / $a['respostas']) <=> ($b['acertos'] / $b['respostas']));
    $erradas = array_slice($erradas, 0, 10, true);
    $perguntas = [];
    if ($erradas) {
        $ids = implode(',', array_map('intval', array_keys($erradas)));
        $txt = $pdo->query("SELECT p.pergunta_id, p.enunciado, q.titulo, q.categoria_ambiental_id FROM pergunta p JOIN quiz q ON q.quiz_id = p.quiz_id WHERE p.pergunta_id IN ($ids)")->fetchAll(PDO::FETCH_UNIQUE);
        $alts = $pdo->query("SELECT alternativa_id, texto FROM alternativa WHERE pergunta_id IN ($ids)")->fetchAll(PDO::FETCH_KEY_PAIR);
        $corretas = $pdo->query("SELECT pergunta_id, texto FROM alternativa WHERE correta = TRUE AND pergunta_id IN ($ids)")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($erradas as $id => $g) {
            $maisEscolhida = null;
            if (!empty($g['erradas'])) {
                arsort($g['erradas']);
                $alt = array_key_first($g['erradas']);
                $maisEscolhida = ['texto' => $alts[$alt] ?? '—', 'vezes' => $g['erradas'][$alt], 'pct' => pct($g['erradas'][$alt], $g['respostas'] - $g['acertos'])];
            }
            $perguntas[] = [
                'enunciado' => $txt[$id]['enunciado'] ?? '—', 'quiz' => $txt[$id]['titulo'] ?? '—',
                'categoria' => INDICADOR_CHAVE[(int) ($txt[$id]['categoria_ambiental_id'] ?? 0)] ?? '',
                'respostas' => $g['respostas'], 'jogadores' => count($g['jogadores']),
                'erro' => pct($g['respostas'] - $g['acertos'], $g['respostas']),
                'correta' => $corretas[$id] ?? '—', 'maisEscolhida' => $maisEscolhida,
            ];
        }
    }

    ksort($porSemana);
    $semanas = [];
    foreach ($porSemana as $sem => $porCat) {
        $total = ['respostas' => 0, 'acertos' => 0, 'jogadores' => []];
        $linha = ['semana' => $sem, 'temas' => []];
        foreach ($porCat as $cat => $g) {
            $linha['temas'][$cats[$cat]['chave'] ?? $cat] = $fechar($g)['acerto'];
            $total['respostas'] += $g['respostas'];
            $total['acertos'] += $g['acertos'];
            $total['jogadores'] += $g['jogadores'];
        }
        $semanas[] = $linha + ['geral' => $fechar($total)];
    }

    $ab = $pdo->prepare('SELECT COUNT(*) AS iniciadas, SUM(acertos IS NULL) AS abandonadas FROM tentativa_quiz WHERE data >= ? AND data < CURDATE()');
    $ab->execute([$desde]);
    $ab = $ab->fetch();

    $nTreino = count($treino['jogadores']);
    return [
        'temas' => $temas,
        'quizzes' => $quizzes,
        'perguntas' => $perguntas,
        'semanas' => $semanas,
        'treino' => [
            'erradas' => $treino['erradas'], 'refeitas' => $treino['refeitas'], 'jogadores' => $nTreino,
            'poucos' => $nTreino < MIN_GRUPO,
            'correcao' => $nTreino < MIN_GRUPO ? null : pct($treino['corrigidas'], $treino['refeitas']),
        ],
        'abandono' => [
            'iniciadas' => (int) $ab['iniciadas'], 'abandonadas' => (int) $ab['abandonadas'],
            'pct' => pct((int) $ab['abandonadas'], (int) $ab['iniciadas']),
        ],
    ];
}

/* ---------------------------------------------------------------------------
   Administração — Ações sustentáveis
   --------------------------------------------------------------------------- */
function est_acoes(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);

    $porAtividade = $pdo->prepare(
        "SELECT a.nome, a.categoria_ambiental_id AS cat, a.impacto_real_unidade AS unidade,
                SUM(r.status = 'aprovado') AS aprovados, SUM(r.status = 'pendente') AS pendentes, SUM(r.status = 'rejeitado') AS rejeitados,
                SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 1) AS sem_foto,
                SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 2) AS com_foto,
                SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 3) AS parceiro,
                SUM(IF(r.status = 'aprovado', COALESCE(r.impacto_real, a.impacto_real_quantidade), 0)) AS impacto,
                COUNT(DISTINCT IF(r.status = 'aprovado', r.jogador_id, NULL)) AS jogadores
           FROM atividade_sustentavel a
      LEFT JOIN registro_atividade r ON r.atividade_sustentavel_id = a.atividade_sustentavel_id AND r.data_registro >= ?
       GROUP BY a.atividade_sustentavel_id, a.nome, a.categoria_ambiental_id, a.impacto_real_unidade
       ORDER BY a.atividade_sustentavel_id"
    );
    $porAtividade->execute([$desde]);
    $atividades = array_map(static fn(array $a): array => [
        'nome' => $a['nome'], 'categoria' => INDICADOR_CHAVE[(int) $a['cat']] ?? '', 'unidade' => $a['unidade'],
        'aprovados' => (int) $a['aprovados'], 'pendentes' => (int) $a['pendentes'], 'rejeitados' => (int) $a['rejeitados'],
        'semFoto' => (int) $a['sem_foto'], 'comFoto' => (int) $a['com_foto'], 'parceiro' => (int) $a['parceiro'],
        'impacto' => round((float) $a['impacto'], 2), 'jogadores' => (int) $a['jogadores'],
    ], $porAtividade->fetchAll());

    $semana = sql_semana('r.data_registro');
    $s = $pdo->prepare(
        "SELECT $semana AS semana, r.categoria_ambiental_id AS cat, COUNT(*) AS total,
                SUM(r.impacto_efetivo) AS impacto, r.unidade
           FROM vw_registro_detalhado r
          WHERE r.status = 'aprovado' AND r.data_registro >= ?
       GROUP BY semana, r.categoria_ambiental_id, r.unidade ORDER BY semana"
    );
    $s->execute([$desde]);
    $semanas = [];
    foreach ($s as $l) {
        $semanas[$l['semana']] ??= ['semana' => $l['semana'], 'total' => 0, 'categorias' => [], 'impacto' => []];
        $semanas[$l['semana']]['total'] += (int) $l['total'];
        $semanas[$l['semana']]['categorias'][INDICADOR_CHAVE[(int) $l['cat']] ?? ''] = (int) $l['total'];
        if ($l['unidade']) {
            $semanas[$l['semana']]['impacto'][$l['unidade']] = round(($semanas[$l['semana']]['impacto'][$l['unidade']] ?? 0) + (float) $l['impacto'], 2);
        }
    }

    // Mapa de calor: dia da semana (0 = segunda) × hora, registros não rejeitados
    $calor = array_fill(0, 7, array_fill(0, 24, 0));
    $h = $pdo->prepare("SELECT WEEKDAY(data_registro) AS d, HOUR(data_registro) AS h, COUNT(*) AS n FROM registro_atividade WHERE status <> 'rejeitado' AND data_registro >= ? GROUP BY d, h");
    $h->execute([$desde]);
    foreach ($h as $l) {
        $calor[(int) $l['d']][(int) $l['h']] = (int) $l['n'];
    }

    // Registros com foto: rejeição e tempo até a decisão
    $f = $pdo->prepare(
        "SELECT SUM(status = 'aprovado') AS aprovados, SUM(status = 'rejeitado') AS rejeitados, SUM(status = 'pendente') AS pendentes,
                AVG(IF(status = 'aprovado' AND aprovado_por_administrador_id IS NOT NULL, TIMESTAMPDIFF(MINUTE, data_registro, data_aprovacao), NULL)) AS min_admin,
                SUM(status = 'aprovado' AND aprovado_por_administrador_id IS NOT NULL) AS por_admin,
                SUM(status = 'aprovado' AND aprovado_por_administrador_id IS NULL) AS automaticos
           FROM registro_atividade WHERE tipo_verificacao_id = 2 AND data_registro >= ?"
    );
    $f->execute([$desde]);
    $f = $f->fetch();
    $motivos = $pdo->prepare(
        "SELECT a.nome AS atividade, r.motivo_rejeicao AS motivo, DATE(r.data_aprovacao) AS data
           FROM registro_atividade r JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
          WHERE r.status = 'rejeitado' AND r.data_registro >= ? ORDER BY r.registro_atividade_id DESC LIMIT 8"
    );
    $motivos->execute([$desde]);

    $decididos = (int) $f['aprovados'] + (int) $f['rejeitados'];
    return [
        'atividades' => $atividades,
        'semanas' => array_values($semanas),
        'calor' => $calor,
        'evidencias' => [
            'aprovados' => (int) $f['aprovados'], 'rejeitados' => (int) $f['rejeitados'], 'pendentes' => (int) $f['pendentes'],
            'rejeicao' => pct((int) $f['rejeitados'], $decididos),
            'porAdmin' => (int) $f['por_admin'], 'automaticos' => (int) $f['automaticos'],
            'minutosAdmin' => $f['min_admin'] !== null ? (int) round((float) $f['min_admin']) : null,
            'horasAutomatica' => PRAZO_APROVACAO_HORAS,
        ],
        'motivos' => $motivos->fetchAll(),
    ];
}

/* ---------------------------------------------------------------------------
   Administração — Engajamento e retenção
   --------------------------------------------------------------------------- */
function est_engajamento(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);

    $diario = $pdo->prepare('SELECT data, COUNT(*) AS n FROM acesso_diario WHERE data >= ? GROUP BY data ORDER BY data');
    $diario->execute([$dias === 0 ? date('Y-m-d', strtotime('-89 days')) : $desde]);
    $semana = sql_semana('data');
    $semanal = $pdo->prepare("SELECT $semana AS semana, COUNT(DISTINCT jogador_id) AS n FROM acesso_diario WHERE data >= ? GROUP BY semana ORDER BY semana");
    $semanal->execute([$desde]);
    $semanaC = sql_semana('data_criacao');
    $novos = $pdo->prepare("SELECT $semanaC AS semana, COUNT(*) AS n FROM jogador WHERE data_criacao >= ? GROUP BY semana ORDER BY semana");
    $novos->execute([$desde]);

    // Retenção por semana de cadastro: voltou na semana +1, +2 e +4
    $acessos = [];
    foreach ($pdo->query("SELECT jogador_id, $semana AS semana FROM acesso_diario GROUP BY jogador_id, semana") as $a) {
        $acessos[(int) $a['jogador_id']][$a['semana']] = true;
    }
    $coortes = [];
    $hoje = date('Y-m-d');
    $c = $pdo->prepare("SELECT jogador_id, $semanaC AS semana FROM jogador WHERE data_criacao >= ?");
    $c->execute([$desde]);
    foreach ($c as $j) {
        $sem = $j['semana'];
        $coortes[$sem] ??= ['semana' => $sem, 'jogadores' => 0, 'voltas' => [1 => 0, 2 => 0, 4 => 0], 'base' => [1 => 0, 2 => 0, 4 => 0]];
        $coortes[$sem]['jogadores']++;
        foreach ([1, 2, 4] as $k) {
            $alvo = date('Y-m-d', strtotime("$sem +$k weeks"));
            if ($alvo > $hoje) continue;   // semana ainda não chegou
            $coortes[$sem]['base'][$k]++;
            $coortes[$sem]['voltas'][$k] += isset($acessos[(int) $j['jogador_id']][$alvo]) ? 1 : 0;
        }
    }
    ksort($coortes);
    $retencao = []; $totais = ['base' => [1 => 0, 2 => 0, 4 => 0], 'voltas' => [1 => 0, 2 => 0, 4 => 0]];
    foreach ($coortes as $co) {
        $linha = ['semana' => $co['semana'], 'jogadores' => $co['jogadores'], 'poucos' => $co['jogadores'] < MIN_GRUPO];
        foreach ([1, 2, 4] as $k) {
            $linha['s' . $k] = $co['jogadores'] < MIN_GRUPO || !$co['base'][$k] ? null : pct($co['voltas'][$k], $co['base'][$k]);
            $linha['aguarda' . $k] = !$co['base'][$k];   // a semana +k ainda não chegou
            $totais['base'][$k] += $co['base'][$k];
            $totais['voltas'][$k] += $co['voltas'][$k];
        }
        $retencao[] = $linha;
    }
    $geral = [];
    foreach ([1, 2, 4] as $k) {
        $geral['s' . $k] = $totais['base'][$k] < MIN_GRUPO ? null : pct($totais['voltas'][$k], $totais['base'][$k]);
        $geral['base' . $k] = $totais['base'][$k];
    }

    // Sequência de dias com ação (registros não rejeitados)
    $dias_jog = [];
    $r = $pdo->prepare("SELECT jogador_id, DATE(data_registro) AS d FROM registro_atividade WHERE status <> 'rejeitado' AND data_registro >= ?");
    $r->execute([$desde]);
    foreach ($r as $l) {
        $dias_jog[(int) $l['jogador_id']][] = $l['d'];
    }
    $melhores = array_map(static fn(array $d): int => sequencias_de_dias($d)['melhor'], $dias_jog);
    $nSeq = count($melhores);

    $m = $pdo->prepare(
        "SELECT IF(mi.tipo_missao_id = 2, 'especial', 'semanal') AS tipo, COUNT(*) AS total, SUM(jm.status = 'concluida') AS concluidas, COUNT(DISTINCT jm.jogador_id) AS jogadores
           FROM jogador_missao jm JOIN missao mi ON mi.missao_id = jm.missao_id
          WHERE jm.periodo_inicio >= ? GROUP BY tipo"
    );
    $m->execute([$desde]);
    $missoes = [];
    foreach ($m as $l) {
        $missoes[$l['tipo']] = ['total' => (int) $l['total'], 'concluidas' => (int) $l['concluidas'], 'jogadores' => (int) $l['jogadores'],
                                'pct' => (int) $l['jogadores'] < MIN_GRUPO ? null : pct((int) $l['concluidas'], (int) $l['total'])];
    }

    $inativos = (int) $pdo->query(
        'SELECT COUNT(*) FROM jogador j WHERE COALESCE((SELECT MAX(a.data) FROM acesso_diario a WHERE a.jogador_id = j.jogador_id), j.data_criacao) < CURDATE() - INTERVAL ' . INATIVO_DIAS . ' DAY'
    )->fetchColumn();

    // Funil dos jogadores cadastrados no período
    $f = $pdo->prepare(
        "SELECT COUNT(*) AS cadastrados,
                SUM(EXISTS(SELECT 1 FROM registro_atividade r WHERE r.jogador_id = j.jogador_id AND r.status = 'aprovado')) AS acao,
                SUM(EXISTS(SELECT 1 FROM tentativa_quiz t WHERE t.jogador_id = j.jogador_id AND t.acertos IS NOT NULL)) AS quiz,
                SUM(EXISTS(SELECT 1 FROM jogador_missao jm WHERE jm.jogador_id = j.jogador_id AND jm.status = 'concluida')) AS missao,
                SUM(EXISTS(SELECT 1 FROM cidade c JOIN cidade_construcao cc ON cc.cidade_id = c.cidade_id WHERE c.jogador_id = j.jogador_id)) AS construcao
           FROM jogador j WHERE j.data_criacao >= ?"
    );
    $f->execute([$desde]);
    $f = array_map('intval', $f->fetch());

    $ativosSemana = (int) $pdo->query("SELECT COUNT(DISTINCT jogador_id) FROM acesso_diario WHERE data >= CURDATE() - INTERVAL 6 DAY")->fetchColumn();

    return [
        'ativosSemana' => $ativosSemana,
        'jogadores' => (int) $pdo->query('SELECT COUNT(*) FROM jogador')->fetchColumn(),
        'diario' => array_map(static fn($l) => ['data' => $l['data'], 'n' => (int) $l['n']], $diario->fetchAll()),
        'semanal' => array_map(static fn($l) => ['semana' => $l['semana'], 'n' => (int) $l['n']], $semanal->fetchAll()),
        'novos' => array_map(static fn($l) => ['semana' => $l['semana'], 'n' => (int) $l['n']], $novos->fetchAll()),
        'retencao' => $retencao,
        'retencaoGeral' => $geral,
        'sequencia' => [
            'jogadores' => $nSeq, 'poucos' => $nSeq < MIN_GRUPO,
            'media' => $nSeq < MIN_GRUPO ? null : round(array_sum($melhores) / $nSeq, 1),
            'maior' => $nSeq < MIN_GRUPO ? null : max($melhores),
        ],
        'missoes' => $missoes,
        'inativos' => $inativos,
        'inativoDias' => INATIVO_DIAS,
        'funil' => $f,
    ];
}

/* ---------------------------------------------------------------------------
   Administração — Cidade, comunidades e parceiros
   --------------------------------------------------------------------------- */
function est_cidade(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);

    // O histórico guarda o valor depois de cada mudança: a variação é a diferença para o anterior
    $d = $pdo->prepare(
        "SELECT origem, indicador_ambiental_id AS ind, SUM(delta) AS soma, COUNT(DISTINCT cidade_id) AS cidades
           FROM (SELECT cidade_id, indicador_ambiental_id, origem, data,
                        valor - COALESCE(LAG(valor) OVER (PARTITION BY cidade_id, indicador_ambiental_id ORDER BY data, historico_indicador_id), 0) AS delta
                   FROM historico_indicador_cidade) x
          WHERE data >= ? AND delta <> 0
       GROUP BY origem, indicador_ambiental_id"
    );
    $d->execute([$desde]);
    $origens = [];
    foreach ($d as $l) {
        $origens[$l['origem']] ??= ['origem' => $l['origem'], 'total' => 0, 'indicadores' => []];
        $origens[$l['origem']]['total'] += (int) $l['soma'];
        $origens[$l['origem']]['indicadores'][INDICADOR_CHAVE[(int) $l['ind']] ?? ''] = (int) $l['soma'];
    }

    $semana = sql_semana('data');
    $sem = $pdo->prepare(
        "SELECT $semana AS semana, SUM(GREATEST(delta, 0)) AS ganhos, SUM(LEAST(delta, 0)) AS perdas
           FROM (SELECT data, valor - COALESCE(LAG(valor) OVER (PARTITION BY cidade_id, indicador_ambiental_id ORDER BY data, historico_indicador_id), 0) AS delta
                   FROM historico_indicador_cidade) x
          WHERE data >= ? GROUP BY semana ORDER BY semana"
    );
    $sem->execute([$desde]);

    $falhas = $pdo->prepare(
        "SELECT a.categoria_ambiental_id AS cat, COUNT(*) AS n
           FROM jogador_missao jm JOIN missao_atividade ma ON ma.missao_id = jm.missao_id
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ma.atividade_sustentavel_id
          WHERE jm.status = 'nao_concluida' AND jm.periodo_inicio >= ? GROUP BY a.categoria_ambiental_id"
    );
    $falhas->execute([$desde]);
    $porArea = [];
    foreach ($falhas as $l) {
        $porArea[INDICADOR_CHAVE[(int) $l['cat']] ?? ''] = (int) $l['n'];
    }

    $estr = $pdo->prepare(
        'SELECT co.nome, co.categoria, COUNT(*) AS n, COUNT(DISTINCT cc.cidade_id) AS cidades
           FROM cidade_construcao cc JOIN construcao co ON co.construcao_id = cc.construcao_id
          WHERE cc.data_construcao >= ? GROUP BY co.construcao_id, co.nome, co.categoria ORDER BY n DESC, co.nome LIMIT 10'
    );
    $estr->execute([$desde]);

    $com = $pdo->prepare(
        "SELECT cm.nome, cm.icone, COUNT(*) AS acoes, SUM(cc.pontos) AS pontos, COUNT(DISTINCT cc.jogador_id) AS jogadores,
                (SELECT COUNT(*) FROM comunidade_missao_resultado r WHERE r.comunidade_id = cm.comunidade_id AND r.periodo_inicio >= ?) AS missoes
           FROM comunidade_contribuicao cc JOIN comunidade cm ON cm.comunidade_id = cc.comunidade_id
          WHERE cc.data_registro >= ? AND cm.ativa = TRUE
       GROUP BY cm.comunidade_id, cm.nome, cm.icone ORDER BY pontos DESC LIMIT 10"
    );
    $com->execute([$desde, $desde]);

    $par = $pdo->prepare(
        "SELECT p.nome, a.nome AS atividade, a.impacto_real_unidade AS unidade, COUNT(*) AS verificacoes,
                SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)) AS impacto, COUNT(DISTINCT r.jogador_id) AS jogadores
           FROM verificacao_parceiro v JOIN parceiro p ON p.parceiro_id = v.parceiro_id
           JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
          WHERE v.data_verificacao >= ? GROUP BY p.parceiro_id, p.nome, a.atividade_sustentavel_id, a.nome, a.impacto_real_unidade
       ORDER BY verificacoes DESC"
    );
    $par->execute([$desde]);

    return [
        'origens' => array_values($origens),
        'semanas' => array_map(static fn($l) => ['semana' => $l['semana'], 'ganhos' => (int) $l['ganhos'], 'perdas' => (int) $l['perdas']], $sem->fetchAll()),
        'missoesFalhadas' => $porArea,
        'estruturas' => array_map(static fn($l) => ['nome' => $l['nome'], 'categoria' => $l['categoria'], 'n' => (int) $l['n'], 'cidades' => (int) $l['cidades']], $estr->fetchAll()),
        'comunidades' => array_map(static fn($l) => ['nome' => $l['nome'], 'icone' => $l['icone'], 'acoes' => (int) $l['acoes'], 'pontos' => (int) $l['pontos'], 'jogadores' => (int) $l['jogadores'], 'missoes' => (int) $l['missoes']], $com->fetchAll()),
        'parceiros' => array_map(static fn($l) => ['nome' => $l['nome'], 'atividade' => $l['atividade'], 'unidade' => $l['unidade'], 'verificacoes' => (int) $l['verificacoes'], 'impacto' => round((float) $l['impacto'], 2), 'jogadores' => (int) $l['jogadores']], $par->fetchAll()),
    ];
}

/* ---------------------------------------------------------------------------
   Administração — Aprenda → Pratique (associação, não causa)
   Para cada tema: dos jogadores que concluíram um quiz do tema no período, quantos registraram
   uma ação do mesmo tema nos 7 dias antes (d-7 a d-1) e nos 7 dias depois (d+1 a d+7) do quiz;
   comparado com os jogadores ativos que não fizeram quiz do tema (d+1 a d+7 do 1º acesso no período).
   Janelas do mesmo tamanho, sem o próprio dia (quem faz quiz está ativo naquele dia, o que inflaria o
   "depois"), e só eventos com as duas janelas completas: já cadastrado 7 dias antes e 7 dias já passados.
   --------------------------------------------------------------------------- */
function est_aprenda_pratique(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);
    $janela = JANELA_PRATICA_DIAS;

    $acoes = [];   // jogador → cat → [datas]
    foreach ($pdo->query("SELECT r.jogador_id, a.categoria_ambiental_id AS cat, DATE(r.data_registro) AS d
                            FROM registro_atividade r JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
                           WHERE r.status <> 'rejeitado'") as $l) {
        $acoes[(int) $l['jogador_id']][(int) $l['cat']][] = $l['d'];
    }
    $temAcao = static function (int $jog, int $cat, string $de, string $ate) use ($acoes): bool {
        foreach ($acoes[$jog][$cat] ?? [] as $d) {
            if ($d >= $de && $d <= $ate) return true;
        }
        return false;
    };

    $q = $pdo->prepare('SELECT jogador_id, categoria_ambiental_id AS cat, MIN(data) AS d
                          FROM vw_quiz_primeira_tentativa WHERE data >= ? GROUP BY jogador_id, categoria_ambiental_id');
    $q->execute([$desde]);
    $fezQuiz = [];
    foreach ($q as $l) {
        $fezQuiz[(int) $l['cat']][(int) $l['jogador_id']] = $l['d'];
    }
    $p = $pdo->prepare('SELECT jogador_id, MIN(data) AS d FROM acesso_diario WHERE data >= ? GROUP BY jogador_id');
    $p->execute([$desde]);
    $primeiroAcesso = $p->fetchAll(PDO::FETCH_KEY_PAIR);
    $cadastro = $pdo->query('SELECT jogador_id, data_criacao FROM jogador')->fetchAll(PDO::FETCH_KEY_PAIR);
    $limite = date('Y-m-d', strtotime("-$janela days"));   // a janela "depois" já terminou
    $mais = static fn(string $d, int $n): string => date('Y-m-d', strtotime("$d $n days"));

    $hoje = date('Y-m-d');
    $temas = [];
    foreach (categorias_estatistica($pdo) as $cat => $c) {
        $comQuiz = ['n' => 0, 'antes' => 0, 'depois' => 0];
        foreach ($fezQuiz[$cat] ?? [] as $jog => $d) {
            if ($d > $limite || ($cadastro[$jog] ?? $d) > $mais($d, -$janela)) continue;   // janelas incompletas
            $comQuiz['n']++;
            $comQuiz['antes'] += $temAcao($jog, $cat, $mais($d, -$janela), $mais($d, -1)) ? 1 : 0;
            $comQuiz['depois'] += $temAcao($jog, $cat, $mais($d, 1), $mais($d, $janela)) ? 1 : 0;
        }
        $semQuiz = ['n' => 0, 'depois' => 0];
        foreach ($primeiroAcesso as $jog => $d) {
            if (isset($fezQuiz[$cat][(int) $jog]) || $d > $limite) continue;
            $semQuiz['n']++;
            $semQuiz['depois'] += $temAcao((int) $jog, $cat, $mais($d, 1), $mais($d, $janela)) ? 1 : 0;
        }
        $okQ = $comQuiz['n'] >= MIN_GRUPO;
        $okS = $semQuiz['n'] >= MIN_GRUPO;
        $temas[] = [
            'categoria' => $c['chave'], 'nome' => $c['nome'],
            'comQuiz' => $comQuiz['n'], 'semQuiz' => $semQuiz['n'],
            'antes' => $okQ ? pct($comQuiz['antes'], $comQuiz['n']) : null,
            'depois' => $okQ ? pct($comQuiz['depois'], $comQuiz['n']) : null,
            'semQuizDepois' => $okS ? pct($semQuiz['depois'], $semQuiz['n']) : null,
            'poucos' => !$okQ,
        ];
    }
    return ['janela' => $janela, 'temas' => $temas];
}

/** Resumo da administração (os totais continuam em estatisticas_gerais()). */
function est_resumo(int $dias): array
{
    $pdo = db();
    $desde = inicio_periodo($dias);
    $s = $pdo->prepare('SELECT COUNT(*) AS respostas, SUM(r.correta) AS acertos, COUNT(DISTINCT t.jogador_id) AS jogadores
                          FROM resposta_quiz r JOIN vw_quiz_primeira_tentativa t ON t.tentativa_id = r.tentativa_id
                         WHERE t.data >= ?');
    $s->execute([$desde]);
    $q = $s->fetch();
    $a = $pdo->prepare("SELECT COUNT(DISTINCT jogador_id) FROM acesso_diario WHERE data >= ?");
    $a->execute([$desde]);
    return [
        'ativos' => (int) $a->fetchColumn(),
        'ativosSemana' => (int) $pdo->query('SELECT COUNT(DISTINCT jogador_id) FROM acesso_diario WHERE data >= CURDATE() - INTERVAL 6 DAY')->fetchColumn(),
        'ativosHoje' => (int) $pdo->query('SELECT COUNT(*) FROM acesso_diario WHERE data = CURDATE()')->fetchColumn(),
        'acertoPrimeira' => (int) $q['jogadores'] < MIN_GRUPO ? null : pct((int) $q['acertos'], (int) $q['respostas']),
        'jogadoresQuiz' => (int) $q['jogadores'],
    ];
}

/** Seções da administração (api/estatisticas.php). */
function estatistica_secao(string $secao, int $dias): array
{
    return match ($secao) {
        'resumo'      => est_resumo($dias),
        'aprendizado' => est_aprendizado($dias),
        'acoes'       => est_acoes($dias),
        'engajamento' => est_engajamento($dias),
        'cidade'      => est_cidade($dias),
        'pratica'     => est_aprenda_pratique($dias),
        default       => throw new RegistroInvalido('Seção inválida.'),
    };
}

/**
 * Tabelas do CSV de cada seção (RN83): só números somados, sem nome, e-mail, apelido ou código.
 * @return array<string, array{0: string[], 1: array<int, array>}> título → [cabeçalho, linhas]
 */
function estatistica_csv(string $secao, int $dias): array
{
    $d = estatistica_secao($secao, $dias);
    $v = static fn($x) => $x === null ? '' : $x;
    return match ($secao) {
        'resumo' => ['Resumo' => [['Ativos no período', 'Ativos nos últimos 7 dias', 'Ativos hoje', 'Acerto na 1ª tentativa (%)', 'Jogadores com quiz'],
            [[$d['ativos'], $d['ativosSemana'], $d['ativosHoje'], $v($d['acertoPrimeira']), $d['jogadoresQuiz']]]]],
        'aprendizado' => [
            'Acerto por tema' => [['Tema', 'Respostas', 'Jogadores', 'Acerto 1ª tentativa (%)'], array_map(static fn($t) => [$t['nome'], $t['respostas'], $t['jogadores'], $v($t['acerto'])], $d['temas'])],
            'Acerto por quiz' => [['Quiz', 'Tipo', 'Respostas', 'Jogadores', 'Acerto 1ª tentativa (%)'], array_map(static fn($q) => [$q['titulo'], $q['tipo'], $q['respostas'], $q['jogadores'], $v($q['acerto'])], $d['quizzes'])],
            'Perguntas mais erradas' => [['Pergunta', 'Quiz', 'Respostas', 'Erro (%)', 'Resposta certa', 'Errada mais escolhida', '% dos erros'],
                array_map(static fn($p) => [$p['enunciado'], $p['quiz'], $p['respostas'], $v($p['erro']), $p['correta'], $p['maisEscolhida']['texto'] ?? '', $v($p['maisEscolhida']['pct'] ?? null)], $d['perguntas'])],
            'Treino e abandono' => [['Erradas na 1ª tentativa', 'Refeitas', 'Corrigidas ao refazer (%)', 'Tentativas iniciadas', 'Abandonadas', 'Abandono (%)'],
                [[$d['treino']['erradas'], $d['treino']['refeitas'], $v($d['treino']['correcao']), $d['abandono']['iniciadas'], $d['abandono']['abandonadas'], $v($d['abandono']['pct'])]]],
        ],
        'acoes' => (static function () use ($d): array {
            $unidades = [];
            foreach ($d['semanas'] as $s) {
                foreach (array_keys($s['impacto'] ?? []) as $u) {
                    $unidades[$u] = $u;
                }
            }
            $listaUnidades = array_values($unidades);
            return [
                'Por atividade' => [['Atividade', 'Aprovadas', 'Sem foto', 'Com foto', 'Com parceiro', 'Pendentes', 'Rejeitadas', 'Jogadores', 'Impacto real', 'Unidade'],
                    array_map(static fn($a) => [$a['nome'], $a['aprovados'], $a['semFoto'], $a['comFoto'], $a['parceiro'], $a['pendentes'], $a['rejeitados'], $a['jogadores'], $a['impacto'], $a['unidade']], $d['atividades'])],
                'Por semana' => [array_merge(['Semana (segunda)', 'Ações aprovadas'], $listaUnidades),
                    array_map(static fn($s) => array_merge([$s['semana'], $s['total']], array_map(static fn($u) => $s['impacto'][$u] ?? 0, $listaUnidades)), $d['semanas'])],
                'Dia e hora' => [array_merge(['Dia'], array_map(static fn($h) => $h . 'h', range(0, 23))),
                    array_map(static fn($i, $l) => array_merge([['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'][$i]], $l), array_keys($d['calor']), $d['calor'])],
            ];
        })(),
        'engajamento' => [
            'Ativos por semana' => [['Semana (segunda)', 'Jogadores ativos'], array_map(static fn($s) => [$s['semana'], $s['n']], $d['semanal'])],
            'Novos por semana' => [['Semana (segunda)', 'Cadastros'], array_map(static fn($s) => [$s['semana'], $s['n']], $d['novos'])],
            'Retenção' => [['Semana do cadastro', 'Jogadores', 'Voltou na semana +1 (%)', '+2 (%)', '+4 (%)'], array_map(static fn($r) => [$r['semana'], $r['jogadores'], $v($r['s1']), $v($r['s2']), $v($r['s4'])], $d['retencao'])],
            'Funil' => [['Cadastrados', '1ª ação aprovada', '1º quiz concluído', '1ª missão concluída', '1ª construção'], [[$d['funil']['cadastrados'], $d['funil']['acao'], $d['funil']['quiz'], $d['funil']['missao'], $d['funil']['construcao']]]],
        ],
        'cidade' => [
            'Pontos por origem' => [['Origem', 'Total', 'Reciclagem', 'Água', 'Energia', 'Mobilidade', 'Natureza'],
                array_map(static fn($o) => array_merge([$o['origem'], $o['total']], array_map(static fn($k) => $o['indicadores'][$k] ?? 0, array_values(INDICADOR_CHAVE))), $d['origens'])],
            'Estruturas mais construídas' => [['Estrutura', 'Categoria', 'Construções', 'Cidades'], array_map(static fn($e) => [$e['nome'], $e['categoria'], $e['n'], $e['cidades']], $d['estruturas'])],
            'Comunidades' => [['Comunidade', 'Ações', 'Pontos', 'Jogadores', 'Missões concluídas'], array_map(static fn($c) => [$c['nome'], $c['acoes'], $c['pontos'], $c['jogadores'], $c['missoes']], $d['comunidades'])],
            'Parceiros' => [['Parceiro', 'Atividade', 'Verificações', 'Impacto', 'Unidade', 'Jogadores'], array_map(static fn($p) => [$p['nome'], $p['atividade'], $p['verificacoes'], $p['impacto'], $p['unidade'], $p['jogadores']], $d['parceiros'])],
        ],
        'pratica' => ['Aprenda → Pratique' => [['Tema', 'Jogadores com quiz', 'Com ação nos 7 dias antes (%)', 'Com ação nos 7 dias depois (%)', 'Jogadores sem quiz', 'Sem quiz: com ação em 7 dias (%)'],
            array_map(static fn($t) => [$t['nome'], $t['comQuiz'], $v($t['antes']), $v($t['depois']), $t['semQuiz'], $v($t['semQuizDepois'])], $d['temas'])]],
    };
}

/* ---------------------------------------------------------------------------
   Jogador — Perfil e relatório pessoal (RN84)
   --------------------------------------------------------------------------- */
function est_jogador(int $jogadorId): array
{
    $pdo = db();
    $cats = categorias_estatistica($pdo);

    // Últimas 8 semanas: ações aprovadas e XP
    $segundaAtual = new DateTimeImmutable('monday this week');
    $inicio = $segundaAtual->modify('-' . (SEMANAS_PERFIL - 1) . ' weeks')->format('Y-m-d');
    $meio = $segundaAtual->modify('-' . ((int) (SEMANAS_PERFIL / 2) - 1) . ' weeks')->format('Y-m-d'); // início das últimas 4 semanas
    $chavesSemana = [];
    $semanas = [];
    for ($i = 0; $i < SEMANAS_PERFIL; $i++) {
        $semKey = date('Y-m-d', strtotime("$inicio +$i weeks"));
        $chavesSemana[] = $semKey;
        $semanas[$semKey] = ['acoes' => 0, 'xp' => 0];
    }
    $sr = sql_semana('data_registro');
    $s = $pdo->prepare("SELECT $sr AS semana, COUNT(*) AS n FROM registro_atividade WHERE jogador_id = ? AND status = 'aprovado' AND data_registro >= ? GROUP BY semana");
    $s->execute([$jogadorId, $inicio]);
    foreach ($s as $l) {
        if (isset($semanas[$l['semana']])) $semanas[$l['semana']]['acoes'] = (int) $l['n'];
    }
    $sx = sql_semana('data');
    $s = $pdo->prepare("SELECT $sx AS semana, SUM(quantidade) AS xp FROM historico_xp WHERE jogador_id = ? AND quantidade > 0 AND data >= ? GROUP BY semana");
    $s->execute([$jogadorId, $inicio]);
    foreach ($s as $l) {
        if (isset($semanas[$l['semana']])) $semanas[$l['semana']]['xp'] = (int) $l['xp'];
    }

    $d = $pdo->prepare("SELECT DISTINCT DATE(data_registro) FROM registro_atividade WHERE jogador_id = ? AND status <> 'rejeitado'");
    $d->execute([$jogadorId]);
    $datasAcoes = $d->fetchAll(PDO::FETCH_COLUMN);
    $seq = sequencias_de_dias($datasAcoes);

    $a = $pdo->prepare('SELECT indicador_ambiental_id AS cat, acoes AS n FROM vw_impacto_jogador_categoria WHERE jogador_id = ?');
    $a->execute([$jogadorId]);
    $porCat = $a->fetchAll(PDO::FETCH_KEY_PAIR);
    $areas = [];
    foreach ($cats as $id => $c) {
        $areas[] = ['categoria' => $c['chave'], 'acoes' => (int) ($porCat[$id] ?? 0)];
    }
    $ordenadas = $areas;
    usort($ordenadas, static fn($x, $y) => $y['acoes'] <=> $x['acoes']);
    $total = array_sum(array_column($areas, 'acoes'));

    // -------------------------------------------------------------------------
    // 1. Evolução sustentável por unidade física (semanal e curva acumulada)
    // -------------------------------------------------------------------------
    $unidadesCat = $pdo->query(
        'SELECT ai.indicador_ambiental_id AS ind, a.impacto_real_unidade AS unidade, MIN(a.atividade_sustentavel_id) AS ordem
           FROM atividade_sustentavel a
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
          WHERE a.impacto_real_unidade IS NOT NULL AND (a.ativa = TRUE OR EXISTS (SELECT 1 FROM registro_atividade r WHERE r.atividade_sustentavel_id = a.atividade_sustentavel_id))
       GROUP BY ai.indicador_ambiental_id, a.impacto_real_unidade
       ORDER BY ai.indicador_ambiental_id, ordem'
    )->fetchAll();

    $impMap = [];
    foreach ($unidadesCat as $uRow) {
        $uNome = trim((string) $uRow['unidade']);
        if ($uNome === '' || isset($impMap[$uNome])) continue;
        $indId = (int) $uRow['ind'];
        $impMap[$uNome] = [
            'unidade'        => $uNome,
            'indicadorId'    => $indId,
            'categoria'      => $cats[$indId]['chave'] ?? (INDICADOR_CHAVE[$indId] ?? 'nature'),
            'nomeArea'       => $cats[$indId]['nome'] ?? '',
            'acumuladoAntes' => 0.0,
            'totalPeriodo'   => 0.0,
            'totalGeral'     => 0.0,
            'acoesGeral'     => 0,
            'porSemana'      => array_fill_keys($chavesSemana, 0.0),
        ];
    }

    $srReg = sql_semana('r.data_registro');
    $stImpSem = $pdo->prepare(
        "SELECT r.unidade,
                IF(r.data_registro < ?, 'antes', $srReg) AS recorte,
                COUNT(*) AS acoes,
                COALESCE(SUM(r.impacto_efetivo), 0) AS impacto
           FROM vw_registro_detalhado r
          WHERE r.jogador_id = ? AND r.status = 'aprovado' AND r.unidade IS NOT NULL
       GROUP BY r.unidade, recorte"
    );
    $stImpSem->execute([$inicio . ' 00:00:00', $jogadorId]);
    foreach ($stImpSem->fetchAll() as $ir) {
        $uNome = trim((string) $ir['unidade']);
        if (!isset($impMap[$uNome])) continue;
        $val = round((float) $ir['impacto'], 2);
        $ac = (int) $ir['acoes'];
        $impMap[$uNome]['totalGeral'] = round($impMap[$uNome]['totalGeral'] + $val, 2);
        $impMap[$uNome]['acoesGeral'] += $ac;
        if ($ir['recorte'] === 'antes') {
            $impMap[$uNome]['acumuladoAntes'] = round($impMap[$uNome]['acumuladoAntes'] + $val, 2);
        } elseif (isset($impMap[$uNome]['porSemana'][$ir['recorte']])) {
            $impMap[$uNome]['porSemana'][$ir['recorte']] = round($impMap[$uNome]['porSemana'][$ir['recorte']] + $val, 2);
            $impMap[$uNome]['totalPeriodo'] = round($impMap[$uNome]['totalPeriodo'] + $val, 2);
        }
    }

    $impactoSemanal = [];
    foreach ($impMap as $item) {
        $semanalArr = [];
        $acumArr = [];
        $acum = $item['acumuladoAntes'];
        foreach ($chavesSemana as $sk) {
            $vSem = round((float) ($item['porSemana'][$sk] ?? 0.0), 2);
            $acum = round($acum + $vSem, 2);
            $semanalArr[] = $vSem;
            $acumArr[] = $acum;
        }
        unset($item['porSemana']);
        $impactoSemanal[] = $item + ['semanal' => $semanalArr, 'acumulado' => $acumArr];
    }

    // -------------------------------------------------------------------------
    // 2. Comparativo de hábitos "Antes × Agora" (4 semanas anteriores vs últimas 4 semanas)
    // -------------------------------------------------------------------------
    $stHab = $pdo->prepare(
        "SELECT a.atividade_sustentavel_id AS id, a.nome, a.ativa, a.impacto_real_unidade AS unidade,
                ai.indicador_ambiental_id AS ind, ai.impacto AS impacto_ind, a.aceita_foto,
                COALESCE(SUM(r.data_registro >= ? AND r.data_registro < ?), 0) AS acoes_antes,
                COALESCE(SUM(IF(r.data_registro >= ? AND r.data_registro < ?, r.impacto_efetivo, 0)), 0) AS imp_antes,
                COALESCE(SUM(r.data_registro >= ? AND r.data_registro < ? AND r.tipo_verificacao_id IN (2, 3)), 0) AS comp_antes,
                COALESCE(SUM(IF(r.data_registro >= ? AND r.data_registro < ?, r.pontos_indicador, 0)), 0) AS pts_antes,
                COALESCE(SUM(r.data_registro >= ?), 0) AS acoes_agora,
                COALESCE(SUM(IF(r.data_registro >= ?, r.impacto_efetivo, 0)), 0) AS imp_agora,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id IN (2, 3)), 0) AS comp_agora,
                COALESCE(SUM(IF(r.data_registro >= ?, r.pontos_indicador, 0)), 0) AS pts_agora
           FROM atividade_sustentavel a
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
      LEFT JOIN vw_registro_detalhado r
             ON r.atividade_sustentavel_id = a.atividade_sustentavel_id
            AND r.jogador_id = ?
            AND r.status = 'aprovado'
            AND r.data_registro >= ?
          WHERE a.ativa = TRUE OR r.registro_atividade_id IS NOT NULL
       GROUP BY a.atividade_sustentavel_id, a.nome, a.ativa, a.impacto_real_unidade, ai.indicador_ambiental_id, ai.impacto, a.aceita_foto
       ORDER BY ai.indicador_ambiental_id, a.atividade_sustentavel_id"
    );
    $iniDt = $inicio . ' 00:00:00';
    $meioDt = $meio . ' 00:00:00';
    $stHab->execute([
        $iniDt, $meioDt, $iniDt, $meioDt, $iniDt, $meioDt, $iniDt, $meioDt,
        $meioDt, $meioDt, $meioDt, $meioDt,
        $jogadorId, $iniDt,
    ]);
    $resumoComp = [
        'acoesAntes' => 0, 'acoesAgora' => 0,
        'habitosAntes' => 0, 'habitosAgora' => 0,
        'comprovadasAntes' => 0, 'comprovadasAgora' => 0,
        'pontosAntes' => 0, 'pontosAgora' => 0,
    ];
    $listaHabitosComp = [];
    foreach ($stHab->fetchAll() as $h) {
        $acAnt = (int) $h['acoes_antes'];
        $acAgo = (int) $h['acoes_agora'];
        $impAnt = round((float) $h['imp_antes'], 2);
        $impAgo = round((float) $h['imp_agora'], 2);
        $resumoComp['acoesAntes'] += $acAnt;
        $resumoComp['acoesAgora'] += $acAgo;
        if ($acAnt > 0) $resumoComp['habitosAntes']++;
        if ($acAgo > 0) $resumoComp['habitosAgora']++;
        $resumoComp['comprovadasAntes'] += (int) $h['comp_antes'];
        $resumoComp['comprovadasAgora'] += (int) $h['comp_agora'];
        $resumoComp['pontosAntes'] += (int) $h['pts_antes'];
        $resumoComp['pontosAgora'] += (int) $h['pts_agora'];

        $tendencia = 'inativo';
        if ($acAnt === 0 && $acAgo > 0) $tendencia = 'novo';
        elseif ($acAnt > 0 && $acAgo > $acAnt) $tendencia = 'subiu';
        elseif ($acAnt > 0 && $acAgo === $acAnt) $tendencia = 'manteve';
        elseif ($acAnt > 0 && $acAgo < $acAnt) $tendencia = 'caiu';

        $indId = (int) $h['ind'];
        $listaHabitosComp[] = [
            'id'           => (int) $h['id'],
            'nome'         => $h['nome'],
            'categoria'    => $cats[$indId]['chave'] ?? (INDICADOR_CHAVE[$indId] ?? 'nature'),
            'unidade'      => $h['unidade'],
            'acoesAntes'   => $acAnt,
            'acoesAgora'   => $acAgo,
            'deltaAcoes'   => $acAgo - $acAnt,
            'impactoAntes' => $impAnt,
            'impactoAgora' => $impAgo,
            'deltaImpacto' => round($impAgo - $impAnt, 2),
            'tendencia'    => $tendencia,
        ];
    }

    // -------------------------------------------------------------------------
    // 3. Trajetória ecológica da cidade (histórico dos 5 indicadores + balanço semanal)
    // -------------------------------------------------------------------------
    $stCid = $pdo->prepare('SELECT cidade_id FROM cidade WHERE jogador_id = ?');
    $stCid->execute([$jogadorId]);
    $cidadeId = (int) $stCid->fetchColumn();
    $evolucaoCidade = ['series' => [], 'balanco' => []];
    if ($cidadeId > 0) {
        $atuaisInd = $pdo->prepare('SELECT indicador_ambiental_id, valor FROM cidade_indicador WHERE cidade_id = ?');
        $atuaisInd->execute([$cidadeId]);
        $valAtuais = array_map('intval', $atuaisInd->fetchAll(PDO::FETCH_KEY_PAIR));

        $sh = sql_semana('data');
        $stHist = $pdo->prepare(
            "SELECT indicador_ambiental_id AS ind, $sh AS semana, data, valor,
                    valor - COALESCE(LAG(valor) OVER (PARTITION BY indicador_ambiental_id ORDER BY data, historico_indicador_id), 0) AS delta
               FROM historico_indicador_cidade
              WHERE cidade_id = ?
           ORDER BY data, historico_indicador_id"
        );
        $stHist->execute([$cidadeId]);
        $ultValorSemana = [];
        $valorAntesJanela = [];
        $balancoMap = [];
        foreach ($chavesSemana as $sk) {
            $balancoMap[$sk] = ['semana' => $sk, 'ganhos' => 0, 'perdas' => 0, 'saldo' => 0];
        }
        foreach ($stHist->fetchAll() as $hr) {
            $ind = (int) $hr['ind'];
            $semH = $hr['semana'];
            $valH = (int) $hr['valor'];
            $deltaH = (int) $hr['delta'];
            if ($hr['data'] < $iniDt) {
                $valorAntesJanela[$ind] = $valH;
            } else {
                $ultValorSemana[$ind][$semH] = $valH;
                if (isset($balancoMap[$semH])) {
                    if ($deltaH > 0) $balancoMap[$semH]['ganhos'] += $deltaH;
                    elseif ($deltaH < 0) $balancoMap[$semH]['perdas'] += $deltaH;
                    $balancoMap[$semH]['saldo'] += $deltaH;
                }
            }
        }
        foreach ($cats as $indId => $cInfo) {
            $corrente = $valorAntesJanela[$indId] ?? null;
            $valoresSerie = [];
            foreach ($chavesSemana as $idxSem => $sk) {
                if (isset($ultValorSemana[$indId][$sk])) {
                    $corrente = $ultValorSemana[$indId][$sk];
                }
                if ($idxSem === count($chavesSemana) - 1 && isset($valAtuais[$indId])) {
                    $corrente = $valAtuais[$indId];
                }
                $valoresSerie[] = $corrente;
            }
            $evolucaoCidade['series'][] = [
                'indicadorId' => $indId,
                'categoria'   => $cInfo['chave'],
                'nome'        => $cInfo['nome'],
                'atual'       => $valAtuais[$indId] ?? 0,
                'valores'     => $valoresSerie,
            ];
        }
        $evolucaoCidade['balanco'] = array_values($balancoMap);
    }

    // -------------------------------------------------------------------------
    // 4. Ritmo pessoal (dia da semana e turno em que mais realiza ações)
    // -------------------------------------------------------------------------
    $stRitmo = $pdo->prepare(
        "SELECT WEEKDAY(data_registro) AS d, HOUR(data_registro) AS h, COUNT(*) AS n
           FROM registro_atividade
          WHERE jogador_id = ? AND status <> 'rejeitado'
       GROUP BY d, h"
    );
    $stRitmo->execute([$jogadorId]);
    $porDiaSem = array_fill(0, 7, 0);
    $porTurno = ['Manhã (6h–12h)' => 0, 'Tarde (12h–18h)' => 0, 'Noite (18h–24h)' => 0, 'Madrugada (0h–6h)' => 0];
    foreach ($stRitmo->fetchAll() as $rr) {
        $dIdx = (int) $rr['d'];
        $hIdx = (int) $rr['h'];
        $nReg = (int) $rr['n'];
        $porDiaSem[$dIdx] += $nReg;
        if ($hIdx >= 6 && $hIdx < 12) $porTurno['Manhã (6h–12h)'] += $nReg;
        elseif ($hIdx >= 12 && $hIdx < 18) $porTurno['Tarde (12h–18h)'] += $nReg;
        elseif ($hIdx >= 18) $porTurno['Noite (18h–24h)'] += $nReg;
        else $porTurno['Madrugada (0h–6h)'] += $nReg;
    }
    $nomesDias = ['Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'];
    $maxDiaVal = max($porDiaSem);
    $diaMaisAtivo = $maxDiaVal > 0 ? $nomesDias[(int) array_search($maxDiaVal, $porDiaSem, true)] : null;
    arsort($porTurno);
    $primeiroTurno = array_key_first($porTurno);
    $turnoMaisAtivo = ($primeiroTurno !== null && $porTurno[$primeiroTurno] > 0) ? $primeiroTurno : null;
    $ritmoPessoal = [
        'diasAtivos'        => count($datasAcoes),
        'mediaSemanalAcoes' => round($resumoComp['acoesAgora'] / 4, 1),
        'diaMaisAtivo'      => $diaMaisAtivo,
        'turnoMaisAtivo'    => $turnoMaisAtivo,
    ];

    // -------------------------------------------------------------------------
    // 5. Aprendizado: 1ª tentativa vs atual, superação no treino, histórico e Aprenda → Pratique
    // -------------------------------------------------------------------------
    $quizzesPorCat = $pdo->query('SELECT categoria_ambiental_id AS cat, COUNT(*) AS n FROM quiz WHERE ativa = TRUE GROUP BY categoria_ambiental_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $totalQuizzesAtivos = array_sum(array_map('intval', $quizzesPorCat));

    $ult = $pdo->prepare('SELECT MAX(tentativa_id) FROM tentativa_quiz WHERE jogador_id = ? AND acertos IS NOT NULL GROUP BY quiz_id');
    $ult->execute([$jogadorId]);
    $ultimas = array_map('intval', $ult->fetchAll(PDO::FETCH_COLUMN));

    $prim = $pdo->prepare('SELECT MIN(tentativa_id) FROM tentativa_quiz WHERE jogador_id = ? AND acertos IS NOT NULL GROUP BY quiz_id');
    $prim->execute([$jogadorId]);
    $primeiras = array_map('intval', $prim->fetchAll(PDO::FETCH_COLUMN));

    $temas = [];
    $erradas = [];
    $porTemaUlt = [];
    $porTemaPrim = [];
    $erradasPorCat = [];

    if ($ultimas) {
        $idsUlt = implode(',', $ultimas);
        $porTemaUlt = $pdo->query(
            "SELECT q.categoria_ambiental_id AS cat, COUNT(*) AS respostas, SUM(r.correta) AS acertos, COUNT(DISTINCT t.quiz_id) AS quizzes
               FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN quiz q ON q.quiz_id = t.quiz_id
              WHERE t.tentativa_id IN ($idsUlt) GROUP BY q.categoria_ambiental_id"
        )->fetchAll(PDO::FETCH_UNIQUE);

        $idsPrim = implode(',', $primeiras);
        $porTemaPrim = $pdo->query(
            "SELECT q.categoria_ambiental_id AS cat, COUNT(*) AS respostas, SUM(r.correta) AS acertos
               FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN quiz q ON q.quiz_id = t.quiz_id
              WHERE t.tentativa_id IN ($idsPrim) GROUP BY q.categoria_ambiental_id"
        )->fetchAll(PDO::FETCH_UNIQUE);

        foreach ($cats as $id => $c) {
            if (!isset($porTemaUlt[$id])) continue;
            $acerto = pct((int) $porTemaUlt[$id]['acertos'], (int) $porTemaUlt[$id]['respostas']);
            $acerto1 = isset($porTemaPrim[$id]) ? pct((int) $porTemaPrim[$id]['acertos'], (int) $porTemaPrim[$id]['respostas']) : $acerto;
            $temas[] = [
                'categoria'      => $c['chave'],
                'nome'           => $c['nome'],
                'quizzes'        => (int) $porTemaUlt[$id]['quizzes'],
                'quizzesTotal'   => (int) ($quizzesPorCat[$id] ?? 0),
                'respostas'      => (int) $porTemaUlt[$id]['respostas'],
                'acerto'         => $acerto,
                'acertoPrimeira' => $acerto1,
                'evolucao'       => ($acerto !== null && $acerto1 !== null) ? round($acerto - $acerto1, 1) : 0.0,
                'revisar'        => $acerto < QUIZ_NOTA_MINIMA,
            ];
        }

        $erradasRows = $pdo->query(
            "SELECT q.quiz_id, q.titulo AS quiz, q.tipo AS quiz_tipo, q.categoria_ambiental_id AS cat,
                    p.enunciado, p.fonte_titulo, p.fonte_url,
                    esc.texto AS escolhida, cor.texto AS correta, cor.explicacao
               FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN quiz q ON q.quiz_id = t.quiz_id
               JOIN pergunta p ON p.pergunta_id = r.pergunta_id
               JOIN alternativa esc ON esc.alternativa_id = r.alternativa_id
               JOIN alternativa cor ON cor.pergunta_id = r.pergunta_id AND cor.correta = TRUE
              WHERE t.tentativa_id IN ($idsUlt) AND r.correta = FALSE
           ORDER BY t.tentativa_id DESC, r.resposta_quiz_id"
        )->fetchAll();
        foreach ($erradasRows as $e) {
            $catId = (int) $e['cat'];
            $catChave = INDICADOR_CHAVE[$catId] ?? '';
            $erradasPorCat[$catId] = ($erradasPorCat[$catId] ?? 0) + 1;
            $erradas[] = [
                'quizId'      => (int) $e['quiz_id'],
                'quiz'        => $e['quiz'],
                'quizTipo'    => $e['quiz_tipo'],
                'categoria'   => $catChave,
                'enunciado'   => $e['enunciado'],
                'escolhida'   => $e['escolhida'],
                'correta'     => $e['correta'],
                'explicacao'  => $e['explicacao'],
                'fonteTitulo' => $e['fonte_titulo'] ?? null,
                'fonteUrl'    => $e['fonte_url'] ?? null,
            ];
        }
    }

    // Superação no treino individual (perguntas erradas na 1ª tentativa que foram refeitas e corrigidas)
    $linhasRespJog = respostas_concluidas($pdo, '1000-01-01', $jogadorId);
    $primRespJog = [];
    $erradas1 = 0;
    $refeitasJog = [];
    $corrigidasJog = [];
    $acertos1Total = 0;
    $resps1Total = 0;
    foreach ($linhasRespJog as $lr) {
        $pid = (int) $lr['pergunta_id'];
        $cor = (int) $lr['correta'];
        if ($lr['primeira'] !== null) {
            $primRespJog[$pid] = $cor;
            $resps1Total++;
            $acertos1Total += $cor;
            if (!$cor) $erradas1++;
        } elseif (isset($primRespJog[$pid]) && !$primRespJog[$pid]) {
            $refeitasJog[$pid] = true;
            if ($cor) $corrigidasJog[$pid] = true;
        }
    }
    $acertosUltTotal = array_sum(array_map(static fn($r) => (int) $r['acertos'], $porTemaUlt));
    $respsUltTotal = array_sum(array_map(static fn($r) => (int) $r['respostas'], $porTemaUlt));
    $pctPrimeiraGeral = pct($acertos1Total, $resps1Total);
    $pctAtualGeral = pct($acertosUltTotal, $respsUltTotal);

    // Histórico por quiz concluído (1ª nota vs melhor nota vs última nota) e evolução semanal
    $stHistQ = $pdo->prepare(
        "SELECT t.tentativa_id, t.quiz_id, t.data, t.acertos,
                q.titulo, q.tipo, q.origem, q.categoria_ambiental_id AS cat,
                (SELECT COUNT(*) FROM pergunta p WHERE p.quiz_id = q.quiz_id) AS total_perguntas
           FROM tentativa_quiz t
           JOIN quiz q ON q.quiz_id = t.quiz_id
          WHERE t.jogador_id = ? AND t.acertos IS NOT NULL
       ORDER BY t.tentativa_id"
    );
    $stHistQ->execute([$jogadorId]);
    $porQuizHist = [];
    $totalTentativas = 0;
    $semAprendMap = [];
    foreach ($chavesSemana as $sk) {
        $semAprendMap[$sk] = ['semana' => $sk, 'tentativas' => 0, 'acertos' => 0, 'perguntas' => 0];
    }
    $datasQuizPorCat = [];
    foreach ($stHistQ->fetchAll() as $tq) {
        $totalTentativas++;
        $qid = (int) $tq['quiz_id'];
        $ac = (int) $tq['acertos'];
        $totP = max(1, (int) $tq['total_perguntas']);
        $catId = (int) $tq['cat'];
        $datasQuizPorCat[$catId][] = $tq['data'];

        $semQ = date('Y-m-d', strtotime($tq['data'] . ' -' . ((int) date('N', strtotime($tq['data'])) - 1) . ' days'));
        if (isset($semAprendMap[$semQ])) {
            $semAprendMap[$semQ]['tentativas']++;
            $semAprendMap[$semQ]['acertos'] += $ac;
            $semAprendMap[$semQ]['perguntas'] += $totP;
        }

        if (!isset($porQuizHist[$qid])) {
            $porQuizHist[$qid] = [
                'id'             => $qid,
                'titulo'         => $tq['titulo'],
                'tipo'           => $tq['tipo'],
                'origem'         => $tq['origem'] ?? 'manual',
                'categoria'      => $cats[$catId]['chave'] ?? (INDICADOR_CHAVE[$catId] ?? ''),
                'tentativas'     => 1,
                'totalPerguntas' => $totP,
                'notaPrimeira'   => $ac,
                'notaMelhor'     => $ac,
                'notaUltima'     => $ac,
                'ultimaData'     => $tq['data'],
            ];
        } else {
            $porQuizHist[$qid]['tentativas']++;
            $porQuizHist[$qid]['notaMelhor'] = max($porQuizHist[$qid]['notaMelhor'], $ac);
            $porQuizHist[$qid]['notaUltima'] = $ac;
            $porQuizHist[$qid]['ultimaData'] = $tq['data'];
        }
    }
    $quizzesPerfeitos = 0;
    $historicoQuizzes = [];
    foreach ($porQuizHist as $qh) {
        if ($qh['notaMelhor'] >= $qh['totalPerguntas']) $quizzesPerfeitos++;
        $qh['pctPrimeira'] = pct($qh['notaPrimeira'], $qh['totalPerguntas']);
        $qh['pctMelhor'] = pct($qh['notaMelhor'], $qh['totalPerguntas']);
        $qh['pctUltima'] = pct($qh['notaUltima'], $qh['totalPerguntas']);
        $historicoQuizzes[] = $qh;
    }
    usort($historicoQuizzes, static fn($x, $y) => strcmp($y['ultimaData'], $x['ultimaData']) ?: ($y['id'] <=> $x['id']));

    $semanasAprendizado = array_values(array_map(static fn(array $sa): array => [
        'semana'     => $sa['semana'],
        'tentativas' => $sa['tentativas'],
        'acerto'     => $sa['perguntas'] > 0 ? pct($sa['acertos'], $sa['perguntas']) : null,
    ], $semAprendMap));

    // Conexão pessoal Aprenda → Pratique (fez quiz do tema e registrou ação do mesmo tema em até 7 dias)
    $stDatasCat = $pdo->prepare(
        "SELECT a.categoria_ambiental_id AS cat, DATE(r.data_registro) AS d
           FROM registro_atividade r
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
          WHERE r.jogador_id = ? AND r.status <> 'rejeitado'"
    );
    $stDatasCat->execute([$jogadorId]);
    $datasAcaoPorCat = [];
    foreach ($stDatasCat->fetchAll() as $dc) {
        $datasAcaoPorCat[(int) $dc['cat']][] = $dc['d'];
    }

    $catalogoTemas = [];
    $temasComQuiz = 0;
    $temasComAcao7d = 0;
    foreach ($cats as $id => $c) {
        $uTema = $porTemaUlt[$id] ?? null;
        $pTema = $porTemaPrim[$id] ?? null;
        $qFeitos = $uTema ? (int) $uTema['quizzes'] : 0;
        $qTotal = (int) ($quizzesPorCat[$id] ?? 0);
        $acAtual = $uTema ? pct((int) $uTema['acertos'], (int) $uTema['respostas']) : null;
        $acPrim = $pTema ? pct((int) $pTema['acertos'], (int) $pTema['respostas']) : null;

        $praticou7d = false;
        if (!empty($datasQuizPorCat[$id])) {
            $temasComQuiz++;
            foreach ($datasQuizPorCat[$id] as $dq) {
                $ate7 = date('Y-m-d', strtotime($dq . ' +' . JANELA_PRATICA_DIAS . ' days'));
                foreach ($datasAcaoPorCat[$id] ?? [] as $da) {
                    if ($da >= $dq && $da <= $ate7) {
                        $praticou7d = true;
                        break 2;
                    }
                }
            }
            if ($praticou7d) $temasComAcao7d++;
        }

        $dominio = 'iniciante';
        if ($qFeitos > 0) {
            if ($acAtual >= 90) $dominio = 'mestre';
            elseif ($acAtual >= QUIZ_NOTA_MINIMA) $dominio = 'bom';
            else $dominio = 'revisar';
        }

        $catalogoTemas[] = [
            'categoria'        => $c['chave'],
            'nome'             => $c['nome'],
            'quizzesFeitos'    => $qFeitos,
            'quizzesTotal'     => $qTotal,
            'acertoPrimeira'   => $acPrim,
            'acertoAtual'      => $acAtual,
            'evolucao'         => ($acAtual !== null && $acPrim !== null) ? round($acAtual - $acPrim, 1) : 0.0,
            'erradasParaRever' => (int) ($erradasPorCat[$id] ?? 0),
            'acoesPraticadas'  => (int) ($porCat[$id] ?? 0),
            'praticouAposQuiz' => $praticou7d,
            'nivelDominio'     => $dominio,
        ];
    }

    $aprendizadoResumo = [
        'quizzesDisponiveis' => $totalQuizzesAtivos,
        'quizzesConcluidos'  => count($ultimas),
        'quizzesPerfeitos'   => $quizzesPerfeitos,
        'totalTentativas'    => $totalTentativas,
        'acertoPrimeira'     => $pctPrimeiraGeral,
        'acertoAtual'        => $pctAtualGeral,
        'evolucaoPontos'     => ($pctAtualGeral !== null && $pctPrimeiraGeral !== null) ? round($pctAtualGeral - $pctPrimeiraGeral, 1) : 0.0,
        'erradasPrimeira'    => $erradas1,
        'refeitasTreino'     => count($refeitasJog),
        'corrigidasTreino'   => count($corrigidasJog),
        'temasComQuiz'       => $temasComQuiz,
        'temasComAcao7d'     => $temasComAcao7d,
    ];

    return [
        'semanas'            => array_map(static fn($k, $v) => ['semana' => $k] + $v, array_keys($semanas), $semanas),
        'sequencia'          => $seq,
        'areas'              => $areas,
        'maisAge'            => $total > 0 ? $ordenadas[0]['categoria'] : null,
        'esquecida'          => $total > 0 ? end($ordenadas)['categoria'] : null,
        'impactoSemanal'     => $impactoSemanal,
        'comparativoHabitos' => ['resumo' => $resumoComp, 'atividades' => $listaHabitosComp],
        'evolucaoCidade'     => $evolucaoCidade,
        'ritmoPessoal'       => $ritmoPessoal,
        'temas'              => $temas,
        'catalogoTemas'      => $catalogoTemas,
        'aprendizadoResumo'  => $aprendizadoResumo,
        'historicoQuizzes'   => $historicoQuizzes,
        'semanasAprendizado' => $semanasAprendizado,
        'erradas'            => $erradas,
        'comparacao'         => comparacao_anonima($pdo, $jogadorId),
    ];
}

/**
 * Comparação anônima (RN83): por área, a porcentagem dos outros jogadores ativos nos últimos 30 dias
 * que registraram menos ações aprovadas que o jogador. Só com MIN_GRUPO jogadores ativos.
 */
function comparacao_anonima(PDO $pdo, int $jogadorId): array
{
    $ativos = array_map('intval', $pdo->query('SELECT DISTINCT jogador_id FROM acesso_diario WHERE data >= CURDATE() - INTERVAL 29 DAY')->fetchAll(PDO::FETCH_COLUMN));
    $outros = array_values(array_diff($ativos, [$jogadorId]));
    if (count($outros) + 1 < MIN_GRUPO) {
        return ['disponivel' => false, 'minimo' => MIN_GRUPO, 'areas' => []];
    }
    $contagem = [];
    foreach ($pdo->query("SELECT jogador_id, categoria_ambiental_id AS cat, COUNT(*) AS n FROM vw_registro_detalhado
                           WHERE status = 'aprovado' AND data_registro >= CURDATE() - INTERVAL 29 DAY
                        GROUP BY jogador_id, categoria_ambiental_id") as $l) {
        $contagem[(int) $l['cat']][(int) $l['jogador_id']] = (int) $l['n'];
    }
    $areas = [];
    foreach (categorias_estatistica($pdo) as $cat => $c) {
        $meu = $contagem[$cat][$jogadorId] ?? 0;
        if ($meu === 0) continue;
        $menos = count(array_filter($outros, static fn($o) => ($contagem[$cat][$o] ?? 0) < $meu));
        $areas[] = ['categoria' => $c['chave'], 'acoes' => $meu, 'acimaDe' => (int) round($menos / count($outros) * 100)];
    }
    return ['disponivel' => true, 'jogadores' => count($outros) + 1, 'areas' => $areas];
}
