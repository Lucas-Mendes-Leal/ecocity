<?php
/**
 * Nível da cidade e manutenção dos indicadores.
 *
 *  - Nível da cidade = faixa da média dos indicadores (RN22).
 *  - Queda diária (RN43): cada indicador perde QUEDA_DIARIA ponto por dia, sem passar do mínimo.
 *    Sem agendador: os dias pendentes (desde cidade.ultima_queda) são aplicados quando o
 *    jogador abre o jogo ou usa uma API.
 *  - Poluição (RN54): a cada 2 dias (dias de número par), cada indicador perde mais
 *    min(3, poluidoras − compensadoras) pontos, contando só as estruturas dos lotes disponíveis.
 */
declare(strict_types=1);

const QUEDA_DIARIA = 1;
const POLUICAO_INTERVALO_DIAS = 2;
const POLUICAO_MAXIMA = 3;   // teto de pontos perdidos por indicador a cada intervalo

/** Número do dia (fuso do projeto) desde 01/01/1970. */
function numero_do_dia(DateTimeImmutable $data): int
{
    $d = $data->setTime(0, 0);
    return intdiv($d->getTimestamp() + $d->getOffset(), 86400);
}

/**
 * Poluição por indicador: estruturas que poluem e que compensam nos lotes disponíveis.
 * @return array<int, array{polui:int, compensa:int, queda:int}> indexado pelo indicador
 */
function poluicao_cidade(PDO $pdo, int $cidadeId): array
{
    $stmt = $pdo->prepare(
        "SELECT c.indicador_ambiental_id AS ind, SUM(c.efeito = 'polui') AS polui, SUM(c.efeito = 'compensa') AS compensa
           FROM cidade_construcao cc
           JOIN construcao c ON c.construcao_id = cc.construcao_id
           JOIN cidade ci ON ci.cidade_id = cc.cidade_id
           JOIN nivel_cidade n ON n.nivel_cidade_id = ci.nivel_cidade_id
          WHERE cc.cidade_id = ? AND c.efeito IS NOT NULL AND cc.lote <= n.nivel
       GROUP BY c.indicador_ambiental_id"
    );
    $stmt->execute([$cidadeId]);
    $r = [];
    foreach ($stmt->fetchAll() as $l) {
        $p = (int) $l['polui'];
        $c = (int) $l['compensa'];
        $r[(int) $l['ind']] = ['polui' => $p, 'compensa' => $c, 'queda' => min(POLUICAO_MAXIMA, max(0, $p - $c))];
    }
    return $r;
}

/** Quantos dias de poluição (número par) há no intervalo (desde, até]. */
function dias_de_poluicao(DateTimeImmutable $desde, DateTimeImmutable $ate): int
{
    $n = 0;
    for ($d = numero_do_dia($desde) + 1, $fim = numero_do_dia($ate); $d <= $fim; $d++) {
        if ($d % POLUICAO_INTERVALO_DIAS === 0) $n++;
    }
    return $n;
}

/**
 * Recalcula o nível da cidade pela média dos indicadores (RN22).
 * Pode rodar dentro ou fora de uma transação.
 */
function sincronizar_cidade(PDO $pdo, int $jogadorId): void
{
    $pdo->prepare(
        'UPDATE cidade c
            SET c.nivel_cidade_id = (
                SELECT n.nivel_cidade_id FROM nivel_cidade n
                 WHERE n.pontuacao_minima <= (SELECT ROUND(AVG(ci.valor)) FROM cidade_indicador ci WHERE ci.cidade_id = c.cidade_id)
              ORDER BY n.nivel DESC LIMIT 1)
          WHERE c.jogador_id = ?'
    )->execute([$jogadorId]);
}

/** Estágio (1–4) de um indicador da cidade, pelas mesmas faixas do nível da cidade. */
function estagio_indicador(PDO $pdo, int $cidadeId, int $indicadorId): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(n.nivel), 1) FROM nivel_cidade n
          WHERE n.pontuacao_minima <= (SELECT valor FROM cidade_indicador WHERE cidade_id = ? AND indicador_ambiental_id = ?)'
    );
    $stmt->execute([$cidadeId, $indicadorId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Aplica a queda diária dos indicadores pelos dias desde a última aplicação.
 * Usa a transação do chamador ou abre uma própria. Retorna os dias aplicados.
 */
function aplicar_queda_diaria(PDO $pdo, int $jogadorId): int
{
    $hoje = date('Y-m-d');
    $chaveSessao = $hoje . ':' . $jogadorId;
    if (!$pdo->inTransaction() && session_status() === PHP_SESSION_ACTIVE && ($_SESSION['queda_aplicada'] ?? '') === $chaveSessao) {
        return 0;
    }

    $propria = !$pdo->inTransaction();
    if ($propria) {
        $leitura = $pdo->prepare('SELECT DATEDIFF(CURDATE(), ultima_queda) FROM cidade WHERE jogador_id = ?');
        $leitura->execute([$jogadorId]);
        $diasPendentes = $leitura->fetchColumn();
        if ($diasPendentes !== false && (int) $diasPendentes <= 0) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['queda_aplicada'] = $chaveSessao;
            }
            return 0;
        }
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT cidade_id, ultima_queda, DATEDIFF(CURDATE(), ultima_queda) AS dias FROM cidade WHERE jogador_id = ? FOR UPDATE');
        $stmt->execute([$jogadorId]);
        $c = $stmt->fetch();
        $dias = $c ? (int) $c['dias'] : 0;
        if ($dias > 0) {
            // Poluição das estruturas (RN54), calculada antes da queda mudar o nível da cidade
            $poluicao = poluicao_cidade($pdo, (int) $c['cidade_id']);
            $diasPoluicao = dias_de_poluicao(new DateTimeImmutable($c['ultima_queda']), new DateTimeImmutable('today'));
            $pdo->prepare(
                'UPDATE cidade_indicador ci
                   JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
                    SET ci.valor = GREATEST(ia.valor_minimo, ci.valor - ?)
                  WHERE ci.cidade_id = ?'
            )->execute([$dias * QUEDA_DIARIA, $c['cidade_id']]);
            $pdo->prepare(
                "INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem)
                 SELECT cidade_id, indicador_ambiental_id, valor, NOW(), 'queda diaria' FROM cidade_indicador WHERE cidade_id = ?"
            )->execute([$c['cidade_id']]);
            if ($diasPoluicao > 0) {
                $upd = $pdo->prepare(
                    'UPDATE cidade_indicador ci
                       JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
                        SET ci.valor = GREATEST(ia.valor_minimo, ci.valor - ?)
                      WHERE ci.cidade_id = ? AND ci.indicador_ambiental_id = ?'
                );
                $hist = $pdo->prepare(
                    "INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem)
                     SELECT cidade_id, indicador_ambiental_id, valor, NOW(), 'poluicao' FROM cidade_indicador
                      WHERE cidade_id = ? AND indicador_ambiental_id = ?"
                );
                foreach ($poluicao as $ind => $p) {
                    if ($p['queda'] === 0) continue;
                    $upd->execute([$p['queda'] * $diasPoluicao, $c['cidade_id'], $ind]);
                    $hist->execute([$c['cidade_id'], $ind]);
                }
            }
            $pdo->prepare('UPDATE cidade SET ultima_queda = CURDATE() WHERE cidade_id = ?')->execute([$c['cidade_id']]);
            sincronizar_cidade($pdo, $jogadorId);
        }
        if ($c) zerar_renda_lotes_escondidos($pdo, (int) $c['cidade_id']);   // RN79: lote escondido não acumula renda
        if ($propria) {
            $pdo->commit();
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['queda_aplicada'] = $chaveSessao;
            }
        }
        return $dias > 0 ? $dias : 0;
    } catch (Throwable $ex) {
        if ($propria) $pdo->rollBack();
        throw $ex;
    }
}
