<?php
/**
 * Quizzes: rotação (Quiz do Dia, Quiz da Semana, temas da semana), tentativas e respostas.
 *
 * Regras:
 *  - O cliente nunca recebe qual alternativa é correta antes de responder.
 *  - Cada pergunta é respondida uma vez por tentativa.
 *  - Recompensa só na PRIMEIRA tentativa concluída de cada quiz e só com nota mínima de 70% (RN21);
 *    depois disso o jogador pode refazer apenas para treinar. Quizzes não alteram indicadores.
 *  - Recompensa = base × dificuldade (Fácil ×1, Médio ×1,5, Difícil ×2) × tipo (semanal ×3) (RN45, RN53).
 *  - Rotação (RN27, RN53), igual para todos, entre os quizzes ativos com perguntas, em ordem de id:
 *    Quiz do Dia troca todo dia (tipo diário); Quiz da Semana e os 3 temas trocam toda segunda.
 *  - Só inicia quiz que está na rotação ou que o jogador já concluiu (treino em "Já feitos").
 */
declare(strict_types=1);

const PONTOS_POR_ACERTO = 50;
const TENTATIVAS_POR_PAGINA = 10;   // histórico de tentativas, paginado
const QUIZ_NOTA_MINIMA = 70;   // % de acertos na 1ª tentativa para receber a recompensa
const QUIZ_MULTIPLICADOR = ['Fácil' => 1.0, 'Médio' => 1.5, 'Difícil' => 2.0];
/** Tipos de quiz: limites de perguntas e multiplicador da recompensa (RN53). */
const QUIZ_TIPOS = [
    'diario'   => ['nome' => 'Diário',   'min' => 3,  'max' => 5,  'multiplicador' => 1],
    'semanal'  => ['nome' => 'Semanal',  'min' => 10, 'max' => 20, 'multiplicador' => 3],
    'tematico' => ['nome' => 'Temático', 'min' => 3,  'max' => 20, 'multiplicador' => 1],
];
const QUIZ_TEMAS_POR_SEMANA = 3;

/** XP e créditos do quiz já com os multiplicadores da dificuldade e do tipo. */
function recompensa_quiz(array $q): array
{
    $m = (QUIZ_MULTIPLICADOR[$q['dificuldade'] ?? ''] ?? 1.0) * (QUIZ_TIPOS[$q['tipo'] ?? '']['multiplicador'] ?? 1);
    return ['xp' => (int) round((int) $q['recompensa_xp'] * $m), 'credito' => (int) round((int) $q['recompensa_credito'] * $m)];
}

/**
 * Rotação do dia/semana a partir dos ids ativos de cada tipo (em ordem).
 * @param array<string,int[]> $porTipo
 */
function rotacao_quizzes(array $porTipo, ?DateTimeImmutable $hoje = null): array
{
    $hoje = ($hoje ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $segunda = $hoje->modify('monday this week');
    $semana = intdiv(numero_do_dia($segunda), 7);

    $diarios = $porTipo['diario'] ?? [];
    $semanais = $porTipo['semanal'] ?? [];
    $tematicos = $porTipo['tematico'] ?? [];
    $temas = [];
    $n = count($tematicos);
    for ($i = 0; $i < min(QUIZ_TEMAS_POR_SEMANA, $n); $i++) {
        $temas[] = $tematicos[($semana * QUIZ_TEMAS_POR_SEMANA + $i) % $n];
    }
    return [
        'dia'       => $diarios ? $diarios[numero_do_dia($hoje) % count($diarios)] : null,
        'semana'    => $semanais ? $semanais[$semana % count($semanais)] : null,
        'temas'     => $temas,
        'fimSemana' => $segunda->modify('+6 days')->format('Y-m-d'),
    ];
}

/**
 * Quizzes que o jogador vê: os da rotação atual e os que ele já concluiu (treino).
 * Devolve ['quizzes' => [...], 'rotacao' => [...]].
 */
function quizzes_do_jogador(int $jogadorId, ?DateTimeImmutable $hoje = null): array
{
    $stmt = db()->prepare(
        'SELECT q.quiz_id, q.titulo, q.descricao, ia.indicador_ambiental_id, q.dificuldade, q.tipo,
                q.recompensa_xp, q.recompensa_credito,
                (SELECT COUNT(*) FROM pergunta p WHERE p.quiz_id = q.quiz_id) AS total_perguntas,
                EXISTS (SELECT 1 FROM tentativa_quiz t
                         WHERE t.quiz_id = q.quiz_id AND t.jogador_id = ? AND t.pontuacao IS NOT NULL) AS concluido
           FROM quiz q
      LEFT JOIN indicador_ambiental ia ON ia.categoria_ambiental_id = q.categoria_ambiental_id
          WHERE q.ativa = TRUE
       ORDER BY q.quiz_id'
    );
    $stmt->execute([$jogadorId]);
    $todos = array_filter($stmt->fetchAll(), static fn(array $q): bool => (int) $q['total_perguntas'] > 0);

    $porTipo = [];
    foreach ($todos as $q) $porTipo[$q['tipo']][] = (int) $q['quiz_id'];
    $rotacao = rotacao_quizzes($porTipo, $hoje);
    $naRotacao = array_filter([$rotacao['dia'], $rotacao['semana'], ...$rotacao['temas']]);

    $quizzes = [];
    foreach ($todos as $q) {
        $id = (int) $q['quiz_id'];
        if (!$q['concluido'] && !in_array($id, $naRotacao, true)) continue;
        $r = recompensa_quiz($q);
        $quizzes[] = [
            'id'                => $id,
            'titulo'            => $q['titulo'],
            'descricao'         => $q['descricao'],
            'categoria'         => INDICADOR_CHAVE[(int) $q['indicador_ambiental_id']] ?? '',
            'dificuldade'       => $q['dificuldade'],
            'tipo'              => $q['tipo'],
            'recompensaXp'      => $r['xp'],
            'recompensaCredito' => $r['credito'],
            'totalPerguntas'    => (int) $q['total_perguntas'],
            'concluido'         => (bool) $q['concluido'],
        ];
    }
    return ['quizzes' => $quizzes, 'rotacao' => $rotacao];
}

/**
 * Tentativas concluídas do jogador (mais recentes primeiro), uma página por vez.
 * @return array{itens: array, total: int, pagina: int, porPagina: int}
 */
function listar_tentativas(int $jogadorId, int $pagina = 1): array
{
    $total = db()->prepare('SELECT COUNT(*) FROM tentativa_quiz WHERE jogador_id = ? AND pontuacao IS NOT NULL');
    $total->execute([$jogadorId]);
    $total = (int) $total->fetchColumn();
    $paginas = max(1, (int) ceil($total / TENTATIVAS_POR_PAGINA));
    $pagina = max(1, min($pagina, $paginas));
    $stmt = db()->prepare(
        'SELECT t.tentativa_id, t.quiz_id, q.titulo, t.data, t.acertos, t.pontuacao,
                (SELECT COUNT(*) FROM pergunta p WHERE p.quiz_id = t.quiz_id) AS total
           FROM tentativa_quiz t JOIN quiz q ON q.quiz_id = t.quiz_id
          WHERE t.jogador_id = ? AND t.pontuacao IS NOT NULL
       ORDER BY t.tentativa_id DESC
          LIMIT ' . TENTATIVAS_POR_PAGINA . ' OFFSET ' . (($pagina - 1) * TENTATIVAS_POR_PAGINA)
    );
    $stmt->execute([$jogadorId]);
    $itens = array_map(static fn(array $t): array => [
        'id'        => (int) $t['tentativa_id'],
        'quizId'    => (int) $t['quiz_id'],
        'titulo'    => $t['titulo'],
        'data'      => $t['data'],
        'acertos'   => (int) $t['acertos'],
        'total'     => (int) $t['total'],
        'pontuacao' => (int) $t['pontuacao'],
    ], $stmt->fetchAll());
    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'porPagina' => TENTATIVAS_POR_PAGINA];
}

/* ---------------------------------------------------------------------------
   Tentativa
   --------------------------------------------------------------------------- */

/** Tentativa em andamento do jogador (ou erro). */
function tentativa_aberta(int $jogadorId, int $tentativaId): array
{
    $stmt = db()->prepare('SELECT tentativa_id, quiz_id, pontuacao FROM tentativa_quiz WHERE tentativa_id = ? AND jogador_id = ?');
    $stmt->execute([$tentativaId, $jogadorId]);
    $t = $stmt->fetch();
    if (!$t) throw new RegistroInvalido('Tentativa não encontrada.');
    if ($t['pontuacao'] !== null) throw new RegistroInvalido('Esta tentativa já foi concluída.');
    return $t;
}

/** Cria a tentativa e devolve as perguntas SEM indicar a alternativa correta. */
function iniciar_tentativa(int $jogadorId, int $quizId): array
{
    // Só quiz da rotação atual ou já concluído pelo jogador (treino) (RN53)
    if (!in_array($quizId, array_column(quizzes_do_jogador($jogadorId)['quizzes'], 'id'), true)) {
        throw new RegistroInvalido('Este quiz não está disponível agora.');
    }
    $stmt = db()->prepare(
        'SELECT p.pergunta_id, p.enunciado, a.alternativa_id, a.texto
           FROM pergunta p JOIN alternativa a ON a.pergunta_id = p.pergunta_id
           JOIN quiz q ON q.quiz_id = p.quiz_id AND q.ativa = TRUE   -- quiz desativado não inicia
          WHERE p.quiz_id = ?
       ORDER BY p.pergunta_id, a.alternativa_id'
    );
    $stmt->execute([$quizId]);

    $perguntas = [];
    foreach ($stmt->fetchAll() as $r) {
        $id = (int) $r['pergunta_id'];
        $perguntas[$id] ??= ['id' => $id, 'enunciado' => $r['enunciado'], 'alternativas' => []];
        $perguntas[$id]['alternativas'][] = ['id' => (int) $r['alternativa_id'], 'texto' => $r['texto']];
    }
    if (!$perguntas) throw new RegistroInvalido('Quiz não encontrado.');

    db()->prepare('INSERT INTO tentativa_quiz (jogador_id, quiz_id, data) VALUES (?, ?, CURDATE())')->execute([$jogadorId, $quizId]);
    return ['tentativaId' => (int) db()->lastInsertId(), 'perguntas' => array_values($perguntas)];
}

/** Grava a resposta de uma pergunta e devolve o resultado com a explicação. */
function responder_pergunta(int $jogadorId, int $tentativaId, int $perguntaId, int $alternativaId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Bloqueia a tentativa para evitar duplicidade simultânea da mesma pergunta
        $stT = $pdo->prepare('SELECT tentativa_id, quiz_id, pontuacao FROM tentativa_quiz WHERE tentativa_id = ? AND jogador_id = ? FOR UPDATE');
        $stT->execute([$tentativaId, $jogadorId]);
        $t = $stT->fetch();
        if (!$t) throw new RegistroInvalido('Tentativa não encontrada.');
        if ($t['pontuacao'] !== null) throw new RegistroInvalido('Esta tentativa já foi concluída.');

        $stmt = $pdo->prepare(
            'SELECT a.alternativa_id, a.correta, a.explicacao FROM alternativa a
               JOIN pergunta p ON p.pergunta_id = a.pergunta_id
              WHERE p.pergunta_id = ? AND p.quiz_id = ?'
        );
        $stmt->execute([$perguntaId, $t['quiz_id']]);
        $alternativas = $stmt->fetchAll();
        $escolhida = array_values(array_filter($alternativas, static fn($a) => (int) $a['alternativa_id'] === $alternativaId))[0] ?? null;
        $correta = array_values(array_filter($alternativas, static fn($a) => (bool) $a['correta']))[0] ?? null;
        if (!$escolhida || !$correta) throw new RegistroInvalido('Resposta inválida.');

        $ja = $pdo->prepare('SELECT 1 FROM resposta_quiz WHERE tentativa_id = ? AND pergunta_id = ?');
        $ja->execute([$tentativaId, $perguntaId]);
        if ($ja->fetchColumn()) throw new RegistroInvalido('Esta pergunta já foi respondida.');

        $acertou = (bool) $escolhida['correta'];
        try {
            $pdo->prepare('INSERT INTO resposta_quiz (tentativa_id, pergunta_id, alternativa_id, correta) VALUES (?, ?, ?, ?)')
                ->execute([$tentativaId, $perguntaId, $alternativaId, (int) $acertou]);
        } catch (PDOException $ex) {
            if (($ex->errorInfo[0] ?? '') === '23000' && (int) ($ex->errorInfo[1] ?? 0) === 1062) {
                throw new RegistroInvalido('Esta pergunta já foi respondida.');
            }
            throw $ex;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }

    $fonte = ['fonte_titulo' => null, 'fonte_url' => null];
    if (ia_banco_pronto()) {
        $f = $pdo->prepare('SELECT fonte_titulo, fonte_url FROM pergunta WHERE pergunta_id = ?');
        $f->execute([$perguntaId]);
        $fonte = $f->fetch() ?: $fonte;
    }
    return [
        'correta'            => $acertou,
        'alternativaCorreta' => (int) $correta['alternativa_id'],
        'explicacao'         => $correta['explicacao'],
        'fonte'              => $fonte['fonte_url'] ? ['titulo' => $fonte['fonte_titulo'] ?: 'Notícia', 'url' => $fonte['fonte_url']] : null,
    ];
}

/** Conclui a tentativa: calcula acertos/pontuação e recompensa se for a primeira concluída. */
function finalizar_tentativa(int $jogadorId, int $tentativaId): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Bloqueia o jogador: evita duas "primeiras" conclusões simultâneas
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$jogadorId]);
        $t = tentativa_aberta($jogadorId, $tentativaId);

        $stmt = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM pergunta WHERE quiz_id = ?) AS total,
                    COUNT(r.resposta_quiz_id) AS respondidas, COALESCE(SUM(r.correta), 0) AS acertos
               FROM resposta_quiz r WHERE r.tentativa_id = ?'
        );
        $stmt->execute([$t['quiz_id'], $tentativaId]);
        $c = $stmt->fetch();
        if ((int) $c['respondidas'] < (int) $c['total']) {
            throw new RegistroInvalido('Responda todas as perguntas antes de concluir.');
        }

        $acertos = (int) $c['acertos'];
        $pontuacao = $acertos * PONTOS_POR_ACERTO;

        $anterior = $pdo->prepare('SELECT 1 FROM tentativa_quiz WHERE jogador_id = ? AND quiz_id = ? AND pontuacao IS NOT NULL');
        $anterior->execute([$jogadorId, $t['quiz_id']]);
        $primeira = !$anterior->fetchColumn();

        $pdo->prepare('UPDATE tentativa_quiz SET acertos = ?, pontuacao = ? WHERE tentativa_id = ?')
            ->execute([$acertos, $pontuacao, $tentativaId]);

        $recompensa = ['xp' => 0, 'credito' => 0];
        $aprovado = $acertos * 100 >= (int) $c['total'] * QUIZ_NOTA_MINIMA;
        if ($primeira && $aprovado) {
            $q = $pdo->prepare('SELECT recompensa_xp, recompensa_credito, dificuldade, tipo FROM quiz WHERE quiz_id = ?');
            $q->execute([$t['quiz_id']]);
            $recompensa = recompensa_quiz($q->fetch());
            creditar_jogador($pdo, $jogadorId, $recompensa['xp'], $recompensa['credito'], 'quiz', 'tentativa_id', $tentativaId);
        }
        avaliar_conquistas($pdo, $jogadorId);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return [
        'acertos'     => $acertos,
        'total'       => (int) $c['total'],
        'pontuacao'   => $pontuacao,
        'recompensado' => $primeira && $aprovado,
        'primeira'    => $primeira,
        'aprovado'    => $aprovado,
        'notaMinima'  => QUIZ_NOTA_MINIMA,
        'recompensa'  => $recompensa,
    ];
}
