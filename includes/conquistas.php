<?php
/**
 * Conquistas: progresso do jogador, avaliação automática e recompensa única.
 *
 * Regras:
 *  - Cada conquista tem tipo_criterio + valor_criterio (ver database/conteudo-conquistas.sql).
 *  - É concedida automaticamente quando o progresso atinge a meta; gravada uma única vez em
 *    jogador_conquista (PK conquista+jogador) e paga XP/créditos uma única vez (creditar_jogador).
 *  - O XP de uma conquista pode completar outra (ex.: nível do jogador): reavalia até estabilizar.
 *  - Conquistas não são revogadas.
 */
declare(strict_types=1);

const CONQUISTAS_MAX_RODADAS = 5;

/** Valor atual de cada critério para o jogador: [ 'codigo' => número ]. */
function progresso_criterios(PDO $pdo, int $jogadorId): array
{
    $v = [];

    $stReg = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(tipo_verificacao_id = ?), 0) AS evid,
                COALESCE(SUM(tipo_verificacao_id = ?), 0) AS parc
           FROM registro_atividade
          WHERE jogador_id = ? AND status = 'aprovado'"
    );
    $stReg->execute([TIPO_EVIDENCIA, TIPO_PARCEIRO, $jogadorId]);
    $reg = $stReg->fetch();
    $v['atividades_aprovadas'] = (int) ($reg['total'] ?? 0);
    $v['registros_evidencia'] = (int) ($reg['evid'] ?? 0);
    $v['verificacoes_parceiro'] = (int) ($reg['parc'] ?? 0);

    // Por indicador: quantidade de atividades aprovadas e impacto real acumulado da unidade principal (RN26; quantidade do parceiro quando houver, RN73)
    $s = $pdo->prepare(   // view vw_impacto_jogador_categoria (migrações 025 e 044)
        'SELECT indicador_ambiental_id AS ind, acoes AS qtd, impacto FROM vw_impacto_jogador_categoria WHERE jogador_id = ?'
    );
    $s->execute([$jogadorId]);
    foreach ($s->fetchAll() as $r) {
        $v['atividades_categoria:' . $r['ind']] = (int) $r['qtd'];
        $v['impacto_real:' . $r['ind']] = (float) $r['impacto'];
    }

    // Por atividade específica: impacto real acumulado na unidade daquela atividade (para unidades distintas na mesma área)
    $stAtiv = $pdo->prepare(
        "SELECT r.atividade_sustentavel_id AS aid,
                COALESCE(SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)), 0) AS impacto
           FROM registro_atividade r
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
          WHERE r.jogador_id = ? AND r.status = 'aprovado'
       GROUP BY r.atividade_sustentavel_id"
    );
    $stAtiv->execute([$jogadorId]);
    foreach ($stAtiv->fetchAll() as $ra) {
        $v['impacto_atividade:' . $ra['aid']] = (float) $ra['impacto'];
    }

    $s = $pdo->prepare('SELECT ci.indicador_ambiental_id, ci.valor FROM cidade_indicador ci JOIN cidade c ON c.cidade_id = ci.cidade_id WHERE c.jogador_id = ?');
    $s->execute([$jogadorId]);
    foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $ind => $valor) {
        $v['indicador:' . $ind] = (int) $valor;
    }

    $stQuiz = $pdo->prepare(
        'SELECT COUNT(DISTINCT t.quiz_id) AS concluidos,
                COUNT(DISTINCT CASE WHEN t.acertos = (SELECT COUNT(*) FROM pergunta p WHERE p.quiz_id = t.quiz_id) THEN t.quiz_id END) AS perfeitos
           FROM tentativa_quiz t
          WHERE t.jogador_id = ? AND t.pontuacao IS NOT NULL'
    );
    $stQuiz->execute([$jogadorId]);
    $qz = $stQuiz->fetch();
    $v['quizzes_concluidos'] = (int) ($qz['concluidos'] ?? 0);
    $v['quizzes_perfeitos'] = (int) ($qz['perfeitos'] ?? 0);

    $stEsc = $pdo->prepare(
        "SELECT (SELECT COUNT(*) FROM jogador_missao jm JOIN missao m ON m.missao_id = jm.missao_id
                  WHERE jm.jogador_id = ? AND jm.status = 'concluida' AND m.tipo_missao_id = ?) AS missoes_especiais,
                (SELECT n.nivel FROM cidade c JOIN nivel_cidade n ON n.nivel_cidade_id = c.nivel_cidade_id
                  WHERE c.jogador_id = ?) AS nivel_cidade,
                (SELECT COALESCE(nj.nivel, 1) FROM jogador j LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
                  WHERE j.jogador_id = ?) AS nivel_jogador,
                (SELECT COUNT(*) FROM cidade_construcao cc JOIN cidade c ON c.cidade_id = cc.cidade_id
                  WHERE c.jogador_id = ?) AS construcoes,
                (SELECT COUNT(*) FROM cidade_construcao cc JOIN cidade c ON c.cidade_id = cc.cidade_id
                    JOIN construcao k ON k.construcao_id = cc.construcao_id
                  WHERE c.jogador_id = ? AND k.categoria = 'ecologica') AS construcoes_ecologicas"
    );
    $stEsc->execute([$jogadorId, TIPO_MISSAO_ESPECIAL, $jogadorId, $jogadorId, $jogadorId, $jogadorId]);
    $esc = $stEsc->fetch();
    $v['missoes_especiais'] = (int) ($esc['missoes_especiais'] ?? 0);
    $v['nivel_cidade'] = (int) ($esc['nivel_cidade'] ?? 1);
    $v['nivel_jogador'] = (int) ($esc['nivel_jogador'] ?: 1);
    $v['construcoes'] = (int) ($esc['construcoes'] ?? 0);
    $v['construcoes_ecologicas'] = (int) ($esc['construcoes_ecologicas'] ?? 0);

    return $v;
}

/**
 * Concede as conquistas atingidas e paga a recompensa de cada uma uma única vez.
 * Usa a transação do chamador ou abre uma própria.
 * @return string[] nomes das conquistas obtidas agora
 */
function avaliar_conquistas(PDO $pdo, int $jogadorId): array
{
    $propria = !$pdo->inTransaction();
    if ($propria) $pdo->beginTransaction();

    try {
        $obtidas = [];
        $pendentes = $pdo->prepare(
            'SELECT conquista_id, nome, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio
               FROM conquista c
              WHERE c.tipo_criterio IS NOT NULL AND c.ativa = TRUE
                AND NOT EXISTS (SELECT 1 FROM jogador_conquista jc WHERE jc.conquista_id = c.conquista_id AND jc.jogador_id = ?)'
        );
        $grava = $pdo->prepare('INSERT IGNORE INTO jogador_conquista (conquista_id, jogador_id, data_conquista) VALUES (?, ?, CURDATE())');

        for ($rodada = 0; $rodada < CONQUISTAS_MAX_RODADAS; $rodada++) {
            $progresso = progresso_criterios($pdo, $jogadorId);
            $pendentes->execute([$jogadorId]);
            $novas = 0;
            foreach ($pendentes->fetchAll() as $c) {
                if (($progresso[$c['tipo_criterio']] ?? 0) < (float) $c['valor_criterio']) continue;
                $grava->execute([$c['conquista_id'], $jogadorId]);
                if ($grava->rowCount() !== 1) continue;   // já concedida em paralelo
                creditar_jogador($pdo, $jogadorId, (int) $c['recompensa_xp'], (int) $c['recompensa_credito'], 'conquista', 'conquista_id', (int) $c['conquista_id']);
                $obtidas[] = $c['nome'];
                $novas++;
            }
            if ($novas === 0) break;   // nada mudou: XP das recompensas não completou outra conquista
        }

        if ($propria) $pdo->commit();
        return $obtidas;
    } catch (Throwable $ex) {
        if ($propria) $pdo->rollBack();
        throw $ex;
    }
}

/** Unidade exibida no progresso de cada critério. */
function unidade_criterio(PDO $pdo, string $criterio): string
{
    static $impactoUnidades = [];
    [$tipo, $ind] = array_pad(explode(':', $criterio, 2), 2, null);
    if ($tipo === 'impacto_real' && $ind !== null) {
        $indId = (int) $ind;
        if (!array_key_exists($indId, $impactoUnidades)) {
            $s = $pdo->prepare(
                'SELECT a.impacto_real_unidade FROM atividade_sustentavel a
                   JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
                  WHERE ai.indicador_ambiental_id = ? AND a.impacto_real_unidade IS NOT NULL
               ORDER BY a.atividade_sustentavel_id LIMIT 1'
            );
            $s->execute([$indId]);
            $impactoUnidades[$indId] = (string) ($s->fetchColumn() ?: '');
        }
        return $impactoUnidades[$indId];
    }
    if ($tipo === 'impacto_atividade' && $ind !== null) {
        $aid = (int) $ind;
        $chave = 'a:' . $aid;
        if (!array_key_exists($chave, $impactoUnidades)) {
            $s = $pdo->prepare('SELECT impacto_real_unidade FROM atividade_sustentavel WHERE atividade_sustentavel_id = ?');
            $s->execute([$aid]);
            $impactoUnidades[$chave] = (string) ($s->fetchColumn() ?: 'unidades');
        }
        return $impactoUnidades[$chave];
    }
    return [
        'atividades_aprovadas' => 'atividades', 'atividades_categoria' => 'atividades', 'indicador' => 'pontos',
        'registros_evidencia' => 'registros', 'verificacoes_parceiro' => 'verificações', 'quizzes_concluidos' => 'quizzes', 'quizzes_perfeitos' => 'quizzes',
        'nivel_cidade' => 'nível', 'nivel_jogador' => 'nível', 'construcoes' => 'estruturas', 'construcoes_ecologicas' => 'estruturas ecológicas',
        'missoes_especiais' => 'missões especiais',
    ][$tipo] ?? '';
}

/** Todas as conquistas com progresso e situação para o jogador. */
function listar_conquistas(int $jogadorId): array
{
    $pdo = db();
    $progresso = progresso_criterios($pdo, $jogadorId);
    $stmt = $pdo->prepare(
        'SELECT c.conquista_id, c.nome, c.descricao, c.recompensa_xp, c.recompensa_credito, c.tipo_criterio, c.valor_criterio,
                jc.data_conquista
           FROM conquista c
      LEFT JOIN jogador_conquista jc ON jc.conquista_id = c.conquista_id AND jc.jogador_id = ?
          WHERE c.ativa = TRUE OR jc.data_conquista IS NOT NULL   -- desativada some, mas quem ganhou continua vendo
       ORDER BY c.conquista_id'
    );
    $stmt->execute([$jogadorId]);

    return array_map(static fn(array $c): array => [
        'id'                => (int) $c['conquista_id'],
        'nome'              => $c['nome'],
        'descricao'         => $c['descricao'],
        'recompensaXp'      => (int) $c['recompensa_xp'],
        'recompensaCredito' => (int) $c['recompensa_credito'],
        'criterio'          => $c['tipo_criterio'],
        'meta'              => (float) $c['valor_criterio'],
        'progresso'         => (float) min($progresso[$c['tipo_criterio']] ?? 0, (float) $c['valor_criterio']),
        'unidade'           => unidade_criterio($pdo, (string) $c['tipo_criterio']),
        'obtida'            => $c['data_conquista'] !== null,
        'obtidaEm'          => $c['data_conquista'],
    ], $stmt->fetchAll());
}
