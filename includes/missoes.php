<?php
/**
 * Missões: metas de atividades dentro de um período (semanal ou especial).
 *
 * Regras (specs/site.md RN33–RN36):
 *  - Contam os registros APROVADOS feitos dentro do período (data_registro).
 *  - Concluir paga a recompensa extra da missão uma única vez por período.
 *  - Período encerrado sem conclusão: cada área cuja meta não foi atingida perde
 *    `penalidade_indicador` pontos (limitado ao mínimo do indicador). Só nas semanais:
 *    a especial é um evento opcional, sem penalidade (RN75).
 *  - O período só é encerrado PRAZO_APROVACAO_HORAS após o fim, para registros com
 *    foto feitos no fim do período poderem ser aprovados e contar.
 *  - Só são penalizados períodos que começaram depois do cadastro do jogador.
 *  - Resultado gravado em jogador_missao (único por jogador + missão + período).
 */
declare(strict_types=1);

const TIPO_MISSAO_SEMANAL = 1;
const TIPO_MISSAO_ESPECIAL = 2;
const MISSOES_RESULTADOS_RECENTES = 10;
const MISSOES_ESPECIAIS_PROXIMAS = 3;     // "Em breve" na aba Missões (RN75)

/**
 * Período que contém o instante informado: [inicio, fim] (DateTimeImmutable) ou null se fora da validade.
 */
function periodo_da_missao(array $m, DateTimeImmutable $em): ?array
{
    $validade = new DateTimeImmutable($m['data_inicio']);
    $termino = $m['data_fim'] ? (new DateTimeImmutable($m['data_fim']))->setTime(23, 59, 59) : null;

    if ((int) $m['tipo_missao_id'] === TIPO_MISSAO_SEMANAL) {
        $inicio = $em->modify('monday this week')->setTime(0, 0);
        $fim = $inicio->modify('+6 days')->setTime(23, 59, 59);
    } else {
        [$inicio, $fim] = [$validade, $termino ?? $validade->setTime(23, 59, 59)];
    }
    if ($fim < $validade || ($termino && $inicio > $termino) || $em < $inicio || $em > $fim) {
        return null;
    }
    return [$inicio, $fim];
}

/** Metas da missão com o quanto o jogador já fez no período. */
function progresso_missao(PDO $pdo, int $jogadorId, int $missaoId, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
{
    $stmt = $pdo->prepare(
        "SELECT ma.atividade_sustentavel_id AS atividade, a.nome, ma.quantidade_necessaria AS meta, ai.indicador_ambiental_id AS indicador,
                (SELECT COUNT(*) FROM registro_atividade r
                  WHERE r.jogador_id = ? AND r.atividade_sustentavel_id = ma.atividade_sustentavel_id
                    AND r.status = 'aprovado' AND r.data_registro BETWEEN ? AND ?) AS feito
           FROM missao_atividade ma
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ma.atividade_sustentavel_id
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
          WHERE ma.missao_id = ?
       ORDER BY ma.missao_atividade_id"
    );
    $stmt->execute([$jogadorId, $inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'), $missaoId]);
    return array_map(static fn(array $r): array => [
        'atividadeId' => (int) $r['atividade'],
        'nome'        => $r['nome'],
        'meta'        => (int) $r['meta'],
        'feito'       => (int) $r['feito'],
        'indicador'   => (int) $r['indicador'],
    ], $stmt->fetchAll());
}

function missoes_ativas(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM missao WHERE ativa = TRUE ORDER BY missao_id')->fetchAll();
}

/** Grava o resultado do período; devolve false se já existia (outra requisição chegou antes). */
function gravar_resultado_missao(PDO $pdo, int $jogadorId, int $missaoId, DateTimeImmutable $inicio, string $status): bool
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO jogador_missao (jogador_id, missao_id, periodo_inicio, status, data_resultado) VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$jogadorId, $missaoId, $inicio->format('Y-m-d'), $status]);
    return $stmt->rowCount() === 1;
}

/** Penaliza os indicadores das metas não atingidas (RN35; a especial não penaliza, RN75). */
function penalizar_missao(PDO $pdo, int $jogadorId, array $missao, array $metas): void
{
    if ((int) $missao['tipo_missao_id'] !== TIPO_MISSAO_SEMANAL) return;
    $pontos = (int) $missao['penalidade_indicador'];
    if ($pontos <= 0) return;

    $indicadores = array_unique(array_column(array_filter($metas, static fn($m) => $m['feito'] < $m['meta']), 'indicador'));
    foreach ($indicadores as $ind) {
        $pdo->prepare(
            'UPDATE cidade_indicador ci
               JOIN cidade c ON c.cidade_id = ci.cidade_id
               JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
                SET ci.valor = GREATEST(ia.valor_minimo, ci.valor - ?)
              WHERE c.jogador_id = ? AND ci.indicador_ambiental_id = ?'
        )->execute([$pontos, $jogadorId, $ind]);
        $pdo->prepare(
            "INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem)
             SELECT ci.cidade_id, ci.indicador_ambiental_id, ci.valor, NOW(), 'missao nao concluida'
               FROM cidade_indicador ci JOIN cidade c ON c.cidade_id = ci.cidade_id
              WHERE c.jogador_id = ? AND ci.indicador_ambiental_id = ?"
        )->execute([$jogadorId, $ind]);
    }
}

/**
 * Processa as missões do jogador: conclui as do período atual e encerra os períodos vencidos.
 * Usa a transação do chamador ou abre uma própria.
 * @return array{concluidas: string[], naoConcluidas: string[]}
 */
function avaliar_missoes(PDO $pdo, int $jogadorId): array
{
    $propria = !$pdo->inTransaction();
    if ($propria) $pdo->beginTransaction();

    try {
        $resultado = ['concluidas' => [], 'naoConcluidas' => []];
        $agora = new DateTimeImmutable();

        $criado = $pdo->prepare('SELECT data_criacao FROM jogador WHERE jogador_id = ? FOR UPDATE');
        $criado->execute([$jogadorId]);
        $cadastro = new DateTimeImmutable((string) $criado->fetchColumn());

        $jaTem = $pdo->prepare('SELECT 1 FROM jogador_missao WHERE jogador_id = ? AND missao_id = ? AND periodo_inicio = ?');

        foreach (missoes_ativas($pdo) as $m) {
            $id = (int) $m['missao_id'];

            // 1) Período atual: conclui se todas as metas foram atingidas
            if ($periodo = periodo_da_missao($m, $agora)) {
                [$inicio, $fim] = $periodo;
                $jaTem->execute([$jogadorId, $id, $inicio->format('Y-m-d')]);
                if (!$jaTem->fetchColumn()) {
                    $metas = progresso_missao($pdo, $jogadorId, $id, $inicio, $fim);
                    $completa = $metas && !array_filter($metas, static fn($x) => $x['feito'] < $x['meta']);
                    if ($completa && gravar_resultado_missao($pdo, $jogadorId, $id, $inicio, 'concluida')) {
                        creditar_jogador($pdo, $jogadorId, (int) $m['recompensa_xp'], (int) $m['recompensa_credito'], 'missao', 'missao_id', $id);
                        $resultado['concluidas'][] = $m['nome'];
                    }
                }
            }

            // 2) Períodos encerrados (fim + prazo de aprovação já passou), começados depois do cadastro
            $limite = $agora->modify('-' . PRAZO_APROVACAO_HORAS . ' hours');
            $cursor = max(new DateTimeImmutable($m['data_inicio']), $cadastro->modify('+1 day'));
            for ($i = 0; $i < 520; $i++) {                       // trava de segurança (~10 anos de semanas)
                $periodo = periodo_da_missao($m, $cursor);
                if (!$periodo) {
                    if ((int) $m['tipo_missao_id'] !== TIPO_MISSAO_SEMANAL) break;
                    $cursor = $cursor->modify('next monday');      // antes do início de validade
                    if ($cursor > $agora) break;
                    continue;
                }
                [$inicio, $fim] = $periodo;
                if ($fim > $limite) break;                        // ainda não encerrado
                if ($inicio <= $cadastro) {                       // começou antes/no dia do cadastro: não penaliza
                    $cursor = $fim->modify('+1 second');
                    if ((int) $m['tipo_missao_id'] !== TIPO_MISSAO_SEMANAL) break;
                    continue;
                }
                $jaTem->execute([$jogadorId, $id, $inicio->format('Y-m-d')]);
                if (!$jaTem->fetchColumn()) {
                    $metas = progresso_missao($pdo, $jogadorId, $id, $inicio, $fim);
                    $completa = $metas && !array_filter($metas, static fn($x) => $x['feito'] < $x['meta']);
                    if ($completa) {
                        // Atingiu a meta, mas a última aprovação veio depois do período: ainda conta como concluída
                        if (gravar_resultado_missao($pdo, $jogadorId, $id, $inicio, 'concluida')) {
                            creditar_jogador($pdo, $jogadorId, (int) $m['recompensa_xp'], (int) $m['recompensa_credito'], 'missao', 'missao_id', $id);
                            $resultado['concluidas'][] = $m['nome'];
                        }
                    } elseif (gravar_resultado_missao($pdo, $jogadorId, $id, $inicio, 'nao_concluida')) {
                        penalizar_missao($pdo, $jogadorId, $m, $metas);
                        $resultado['naoConcluidas'][] = $m['nome'];
                    }
                }
                if ((int) $m['tipo_missao_id'] !== TIPO_MISSAO_SEMANAL) break;
                $cursor = $fim->modify('+1 second');
            }
        }

        if ($resultado['concluidas'] || $resultado['naoConcluidas']) {
            sincronizar_cidade($pdo, $jogadorId);
            avaliar_conquistas($pdo, $jogadorId);
        }
        if ($propria) $pdo->commit();
        return $resultado;
    } catch (Throwable $ex) {
        if ($propria) $pdo->rollBack();
        throw $ex;
    }
}

/** Missões do período atual com progresso + resultados recentes. */
function listar_missoes(int $jogadorId): array
{
    $pdo = db();
    $agora = new DateTimeImmutable();
    $atuais = [];
    $resultadoPeriodo = $pdo->prepare('SELECT status FROM jogador_missao WHERE jogador_id = ? AND missao_id = ? AND periodo_inicio = ?');

    foreach (missoes_ativas($pdo) as $m) {
        $periodo = periodo_da_missao($m, $agora);
        if (!$periodo) continue;
        [$inicio, $fim] = $periodo;
        $resultadoPeriodo->execute([$jogadorId, $m['missao_id'], $inicio->format('Y-m-d')]);
        $atuais[] = [
            'id'                => (int) $m['missao_id'],
            'nome'              => $m['nome'],
            'descricao'         => $m['descricao'],
            'tipo'              => (int) $m['tipo_missao_id'] === TIPO_MISSAO_SEMANAL ? 'Semanal' : 'Especial',
            'inicio'            => $inicio->format(DATE_ATOM),
            'fim'               => $fim->format(DATE_ATOM),
            'recompensaXp'      => (int) $m['recompensa_xp'],
            'recompensaCredito' => (int) $m['recompensa_credito'],
            'penalidade'        => (int) $m['tipo_missao_id'] === TIPO_MISSAO_SEMANAL ? (int) $m['penalidade_indicador'] : 0,
            'metas'             => progresso_missao($pdo, $jogadorId, (int) $m['missao_id'], $inicio, $fim),
            'concluida'         => $resultadoPeriodo->fetchColumn() === 'concluida',
        ];
    }

    $hist = $pdo->prepare(
        'SELECT m.nome, m.tipo_missao_id, jm.periodo_inicio, jm.status, jm.data_resultado, m.penalidade_indicador
           FROM jogador_missao jm JOIN missao m ON m.missao_id = jm.missao_id
          WHERE jm.jogador_id = ?
       ORDER BY jm.data_resultado DESC, jm.jogador_missao_id DESC
          LIMIT ' . MISSOES_RESULTADOS_RECENTES
    );
    $hist->execute([$jogadorId]);

    // Próximas especiais (ainda não começaram), para o "Em breve"
    $prox = $pdo->prepare('SELECT * FROM missao WHERE ativa = TRUE AND tipo_missao_id = ? AND data_inicio > CURDATE()
                            ORDER BY data_inicio, missao_id LIMIT ' . MISSOES_ESPECIAIS_PROXIMAS);
    $prox->execute([TIPO_MISSAO_ESPECIAL]);
    $proximas = [];
    foreach ($prox->fetchAll() as $m) {
        $inicio = new DateTimeImmutable($m['data_inicio']);
        $fim = (new DateTimeImmutable($m['data_fim'] ?? $m['data_inicio']))->setTime(23, 59, 59);
        $proximas[] = [
            'id'                => (int) $m['missao_id'],
            'nome'              => $m['nome'],
            'descricao'         => $m['descricao'],
            'inicio'            => $inicio->format(DATE_ATOM),
            'fim'               => $fim->format(DATE_ATOM),
            'recompensaXp'      => (int) $m['recompensa_xp'],
            'recompensaCredito' => (int) $m['recompensa_credito'],
            'metas'             => progresso_missao($pdo, $jogadorId, (int) $m['missao_id'], $inicio, $fim),
        ];
    }

    return [
        'atuais'     => $atuais,
        'proximas'   => $proximas,
        'resultados' => array_map(static fn(array $r): array => [
            'nome'       => $r['nome'],
            'periodo'    => $r['periodo_inicio'],
            'status'     => $r['status'],
            'em'         => date(DATE_ATOM, strtotime($r['data_resultado'])),
            'especial'   => (int) $r['tipo_missao_id'] !== TIPO_MISSAO_SEMANAL,
            'penalidade' => (int) $r['tipo_missao_id'] === TIPO_MISSAO_SEMANAL ? (int) $r['penalidade_indicador'] : 0,
        ], $hist->fetchAll()),
    ];
}
