<?php
/**
 * Códigos promocionais (specs/site.md RN98, migração 036).
 *
 *  - Jogador resgata em Meu Progresso › Resumo (api/perfil.php acao=resgatar_codigo).
 *  - Concede XP, créditos verdes (com histórico rastreável) e/ou pontos nos
 *    indicadores ambientais da cidade; recalcula níveis e avalia conquistas.
 *  - Uso único por conta por código, janela de início/fim, limite global opcional,
 *    opção de restringir a contas criadas no período e bloqueio anti-adivinhação.
 *  - Administração gerencia em Administração › Conteúdo › Códigos promocionais.
 */
declare(strict_types=1);

const PROMO_CODIGO_MIN = 4;
const PROMO_CODIGO_MAX = 30;
const PROMO_LIMITE_USOS_MAX = 1000000;
const PROMO_ERROS_LIMITE = 5;
const PROMO_ERROS_JANELA_MIN = 15;
const PROMO_ORIGEM = 'codigo promocional';

const PROMO_AREAS_NOMES = [
    1 => 'Reciclagem',
    2 => 'Água',
    3 => 'Energia',
    4 => 'Mobilidade',
    5 => 'Natureza',
];

/** O banco tem a migração 036? */
function promocoes_banco_pronto(): bool
{
    return schema_flag('promocoes', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'codigo_promocional'"
    )->fetchColumn());
}

/** Normaliza e valida o formato do código promocional (4–30 caracteres [A-Z0-9_-]). */
function normalizar_codigo_promocional(string $codigo): string
{
    $c = mb_strtoupper(trim($codigo), 'UTF-8');
    if (!preg_match('/^[A-Z0-9_-]{' . PROMO_CODIGO_MIN . ',' . PROMO_CODIGO_MAX . '}$/', $c)) {
        throw new RegistroInvalido(
            'Código inválido: use de ' . PROMO_CODIGO_MIN . ' a ' . PROMO_CODIGO_MAX . ' letras, números, hífen (-) ou sublinhado (_).'
        );
    }
    return $c;
}

/** Histórico de códigos promocionais resgatados pelo jogador (mais recentes primeiro). */
function resgates_promocionais_jogador(int $jogadorId): array
{
    if (!promocoes_banco_pronto()) {
        return [];
    }
    $s = db()->prepare(
        'SELECT r.resgate_id, p.codigo, p.nome, r.xp_recebido, r.credito_recebido, r.indicadores_json, r.resgatado_em
           FROM codigo_promocional_resgate r
           JOIN codigo_promocional p ON p.codigo_promocional_id = r.codigo_promocional_id
          WHERE r.jogador_id = ?
       ORDER BY r.resgatado_em DESC, r.resgate_id DESC'
    );
    $s->execute([$jogadorId]);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $inds = $r['indicadores_json'] !== null ? json_decode((string) $r['indicadores_json'], true) : [];
        $out[] = [
            'id'          => (int) $r['resgate_id'],
            'codigo'      => $r['codigo'],
            'nome'        => $r['nome'],
            'xp'          => (int) $r['xp_recebido'],
            'credito'     => (int) $r['credito_recebido'],
            'indicadores' => is_array($inds) ? $inds : [],
            'resgatadoEm' => date(DATE_ATOM, strtotime($r['resgatado_em'])),
        ];
    }
    return $out;
}

/** O jogador atingiu o limite de tentativas inválidas de código promocional? */
function promo_bloqueado(PDO $pdo, int $jogadorId): bool
{
    $s = $pdo->prepare(
        'SELECT COUNT(*) FROM tentativa_codigo_promocional
          WHERE jogador_id = ? AND data > NOW() - INTERVAL ' . PROMO_ERROS_JANELA_MIN . ' MINUTE'
    );
    $s->execute([$jogadorId]);
    return (int) $s->fetchColumn() >= PROMO_ERROS_LIMITE;
}

function registrar_erro_promo(PDO $pdo, int $jogadorId): void
{
    $pdo->prepare('INSERT INTO tentativa_codigo_promocional (jogador_id, data) VALUES (?, NOW())')->execute([$jogadorId]);
}

/** Texto amigável resumindo os ganhos de um resgate promocional. */
function resumo_recompensa_promocao(int $xp, int $credito, array $porInd): string
{
    $partes = [];
    if ($xp > 0) {
        $partes[] = '+' . number_format($xp, 0, ',', '.') . ' XP';
    }
    if ($credito > 0) {
        $partes[] = '+' . number_format($credito, 0, ',', '.') . ' créditos verdes';
    }
    if ($porInd) {
        $vals = array_values($porInd);
        if (count($porInd) === count(INDICADOR_CHAVE) && min($vals) === max($vals)) {
            $partes[] = '+' . $vals[0] . ' pontos em todos os 5 indicadores';
        } else {
            $areas = [];
            foreach ($porInd as $indId => $pts) {
                $areas[] = '+' . $pts . ' em ' . (PROMO_AREAS_NOMES[$indId] ?? "Indicador {$indId}");
            }
            $partes[] = implode(', ', $areas);
        }
    }
    return implode(' · ', $partes);
}

/**
 * Resgata um código promocional para o jogador logado.
 * Aplica XP, créditos verdes, pontos nos indicadores, níveis e conquistas numa única transação.
 * @return array{mensagem: string, resgate: array, novasConquistas: string[]}
 */
function resgatar_codigo_promocional(int $jogadorId, string $codigoEntrada): array
{
    if (!promocoes_banco_pronto()) {
        throw new RegistroInvalido('Os códigos promocionais ainda não estão disponíveis neste servidor.');
    }

    $pdo = db();
    if (promo_bloqueado($pdo, $jogadorId)) {
        throw new RegistroInvalido(
            'Muitas tentativas inválidas seguidas. Tente novamente em ' . PROMO_ERROS_JANELA_MIN . ' minutos.'
        );
    }

    try {
        $codigo = normalizar_codigo_promocional($codigoEntrada);
    } catch (RegistroInvalido $ex) {
        registrar_erro_promo($pdo, $jogadorId);
        throw $ex;
    }

    $registrarErro = false;
    $res = null;
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare('SELECT * FROM codigo_promocional WHERE codigo = ? FOR UPDATE');
        $s->execute([$codigo]);
        $promo = $s->fetch();
        if (!$promo) {
            $registrarErro = true;
            throw new RegistroInvalido('Código promocional não encontrado. Confira se digitou corretamente.');
        }

        $promoId = (int) $promo['codigo_promocional_id'];
        if (!(bool) $promo['ativa']) {
            throw new RegistroInvalido('Este código promocional está desativado no momento.');
        }

        $hoje = date('Y-m-d');
        if ($hoje < $promo['data_inicio']) {
            throw new RegistroInvalido('Este código promocional entra em vigor em ' . date('d/m/Y', strtotime($promo['data_inicio'])) . '.');
        }
        if ($hoje > $promo['data_fim']) {
            throw new RegistroInvalido('Este código promocional expirou em ' . date('d/m/Y', strtotime($promo['data_fim'])) . '.');
        }

        // O lock FOR UPDATE na linha do código serializa o resgate sem exigir UPDATE em codigo_promocional_resgate (RN98)
        $ja = $pdo->prepare(
            'SELECT 1 FROM codigo_promocional_resgate WHERE codigo_promocional_id = ? AND jogador_id = ?'
        );
        $ja->execute([$promoId, $jogadorId]);
        if ($ja->fetchColumn()) {
            throw new RegistroInvalido('Você já resgatou este código promocional.');
        }

        if ($promo['limite_usos'] !== null) {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM codigo_promocional_resgate WHERE codigo_promocional_id = ?');
            $cnt->execute([$promoId]);
            if ((int) $cnt->fetchColumn() >= (int) $promo['limite_usos']) {
                throw new RegistroInvalido('Este código promocional atingiu o limite máximo de resgates.');
            }
        }

        if ((bool) $promo['somente_novos']) {
            $cadStmt = $pdo->prepare(
                'SELECT u.data_cadastro FROM jogador j JOIN usuario u ON u.usuario_id = j.usuario_id WHERE j.jogador_id = ?'
            );
            $cadStmt->execute([$jogadorId]);
            $dataCad = (string) $cadStmt->fetchColumn();
            if ($dataCad === '' || $dataCad < $promo['data_inicio'] || $dataCad > $promo['data_fim']) {
                throw new RegistroInvalido(
                    'Este código promocional é válido apenas para contas cadastradas entre '
                    . date('d/m/Y', strtotime($promo['data_inicio'])) . ' e ' . date('d/m/Y', strtotime($promo['data_fim'])) . '.'
                );
            }
        }

        $indStmt = $pdo->prepare(
            'SELECT indicador_ambiental_id, pontos
               FROM codigo_promocional_indicador
              WHERE codigo_promocional_id = ? AND pontos > 0
           ORDER BY indicador_ambiental_id'
        );
        $indStmt->execute([$promoId]);
        $porInd = [];
        $porChave = [];
        foreach ($indStmt->fetchAll() as $l) {
            $indId = (int) $l['indicador_ambiental_id'];
            $pts = (int) $l['pontos'];
            $porInd[$indId] = $pts;
            $chave = INDICADOR_CHAVE[$indId] ?? null;
            if ($chave !== null) {
                $porChave[$chave] = $pts;
            }
        }

        $xp = (int) $promo['recompensa_xp'];
        $credito = (int) $promo['recompensa_credito'];
        $jsonInds = $porChave ? json_encode($porChave, JSON_UNESCAPED_UNICODE) : null;

        try {
            $pdo->prepare(
                'INSERT INTO codigo_promocional_resgate (codigo_promocional_id, jogador_id, xp_recebido, credito_recebido, indicadores_json, resgatado_em)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$promoId, $jogadorId, $xp, $credito, $jsonInds]);
        } catch (PDOException $ex) {
            if (($ex->errorInfo[0] ?? '') === '23000' && (int) ($ex->errorInfo[1] ?? 0) === 1062) {
                throw new RegistroInvalido('Você já resgatou este código promocional.');
            }
            throw $ex;
        }
        $resgateId = (int) $pdo->lastInsertId();

        if ($xp > 0 || $credito > 0) {
            creditar_jogador($pdo, $jogadorId, $xp, $credito, PROMO_ORIGEM, 'codigo_promocional_id', $promoId);
        }

        if ($porInd) {
            $cidStmt = $pdo->prepare('SELECT cidade_id FROM cidade WHERE jogador_id = ? FOR UPDATE');
            $cidStmt->execute([$jogadorId]);
            $cidadeId = (int) $cidStmt->fetchColumn();
            if ($cidadeId > 0) {
                $updInd = $pdo->prepare(
                    'UPDATE cidade_indicador ci
                       JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
                        SET ci.valor = LEAST(ia.valor_maximo, GREATEST(ia.valor_minimo, ci.valor + ?))
                      WHERE ci.cidade_id = ? AND ci.indicador_ambiental_id = ?'
                );
                $histInd = $pdo->prepare(
                    "INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem)
                     SELECT cidade_id, indicador_ambiental_id, valor, NOW(), ? FROM cidade_indicador
                      WHERE cidade_id = ? AND indicador_ambiental_id = ?"
                );
                foreach ($porInd as $indId => $pts) {
                    $updInd->execute([$pts, $cidadeId, $indId]);
                    $histInd->execute([PROMO_ORIGEM, $cidadeId, $indId]);
                }
                sincronizar_cidade($pdo, $jogadorId);
            }
        }

        $novasConquistas = avaliar_conquistas($pdo, $jogadorId);
        $pdo->prepare('DELETE FROM tentativa_codigo_promocional WHERE jogador_id = ?')->execute([$jogadorId]);

        $pdo->commit();

        $res = [
            'mensagem' => "Código {$promo['codigo']} resgatado! Você recebeu: " . resumo_recompensa_promocao($xp, $credito, $porInd) . '.',
            'resgate'  => [
                'id'          => $resgateId,
                'codigo'      => $promo['codigo'],
                'nome'        => $promo['nome'],
                'xp'          => $xp,
                'credito'     => $credito,
                'indicadores' => $porChave,
                'resgatadoEm' => date(DATE_ATOM),
            ],
            'novasConquistas' => $novasConquistas,
        ];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($registrarErro) {
            registrar_erro_promo($pdo, $jogadorId);
        }
        throw $ex;
    }

    return $res;
}

/* ---------------------------------------------------------------------------
   Administração (aba Conteúdo › Códigos promocionais)
   --------------------------------------------------------------------------- */

/** Situação efetiva do código promocional para exibição e filtros na administração. */
function situacao_promocao(array $p, int $resgates): string
{
    if (!(bool) $p['ativa']) return 'desativada';
    if ($p['limite_usos'] !== null && $resgates >= (int) $p['limite_usos']) return 'esgotada';
    $hoje = date('Y-m-d');
    if ($hoje < $p['data_inicio']) return 'agendada';
    if ($hoje > $p['data_fim']) return 'expirada';
    return 'ativa';
}

/** Lista todos os códigos promocionais com os pontos por indicador e total de resgates. */
function listar_promocoes_admin(): array
{
    if (!promocoes_banco_pronto()) {
        return [];
    }
    $pdo = db();
    $inds = [];
    foreach ($pdo->query('SELECT codigo_promocional_id, indicador_ambiental_id, pontos FROM codigo_promocional_indicador') as $r) {
        $inds[(int) $r['codigo_promocional_id']][(int) $r['indicador_ambiental_id']] = (int) $r['pontos'];
    }
    $rows = $pdo->query(
        'SELECT cp.*,
                (SELECT COUNT(*) FROM codigo_promocional_resgate r WHERE r.codigo_promocional_id = cp.codigo_promocional_id) AS resgates
           FROM codigo_promocional cp
       ORDER BY cp.codigo_promocional_id DESC'
    )->fetchAll();

    return array_map(static function (array $p) use ($inds): array {
        $id = (int) $p['codigo_promocional_id'];
        $resgates = (int) $p['resgates'];
        $mapaInds = [];
        foreach (array_keys(INDICADOR_CHAVE) as $indId) {
            $mapaInds[$indId] = $inds[$id][$indId] ?? 0;
        }
        return [
            'id'           => $id,
            'codigo'       => $p['codigo'],
            'nome'         => $p['nome'],
            'descricao'    => $p['descricao'],
            'xp'           => (int) $p['recompensa_xp'],
            'credito'      => (int) $p['recompensa_credito'],
            'indicadores'  => $mapaInds,
            'dataInicio'   => $p['data_inicio'],
            'dataFim'      => $p['data_fim'],
            'limiteUsos'   => $p['limite_usos'] !== null ? (int) $p['limite_usos'] : null,
            'somenteNovos' => (bool) $p['somente_novos'],
            'ativa'        => (bool) $p['ativa'],
            'resgates'     => $resgates,
            'usado'        => $resgates > 0,
            'situacao'     => situacao_promocao($p, $resgates),
        ];
    }, $rows);
}

/** Cria ou edita um código promocional pela administração (RN49/RN50/RN98). */
function salvar_promocao(array $d, ?int $adminId = null): string
{
    if (!promocoes_banco_pronto()) {
        throw new RegistroInvalido('A tabela de códigos promocionais ainda não existe no banco.');
    }

    $id = (int) ($d['id'] ?? 0);
    $codigo = normalizar_codigo_promocional((string) ($d['codigo'] ?? ''));
    $nome = c_texto($d, 'nome', 'Nome da campanha', 3, 100);
    $descricao = c_texto($d, 'descricao', 'Descrição', 1, 250, false);
    $xp = c_inteiro($d, 'xp', 'XP', 0, RECOMPENSA_MAX);
    $credito = c_inteiro($d, 'credito', 'Créditos verdes', 0, RECOMPENSA_MAX);
    $inicio = c_data($d, 'dataInicio', 'Data de início');
    $fim = c_data($d, 'dataFim', 'Data de término');
    if ($fim < $inicio) {
        throw new RegistroInvalido('A data de término deve ser igual ou posterior à data de início.');
    }

    $limRaw = trim((string) ($d['limiteUsos'] ?? ''));
    $limiteUsos = $limRaw === '' ? null : c_inteiro(['limiteUsos' => $limRaw], 'limiteUsos', 'Limite de resgates', 1, PROMO_LIMITE_USOS_MAX);
    $somenteNovos = c_bool($d, 'somenteNovos');
    $ativa = c_bool($d, 'ativa');

    $indEntrada = is_array($d['indicadores'] ?? null) ? $d['indicadores'] : [];
    $indicadores = [];
    foreach (array_keys(INDICADOR_CHAVE) as $indId) {
        $raw = trim((string) ($indEntrada[$indId] ?? $indEntrada[(string) $indId] ?? '0'));
        if ($raw === '') $raw = '0';
        $pts = c_inteiro(['p' => $raw], 'p', 'Pontos de ' . (PROMO_AREAS_NOMES[$indId] ?? "indicador {$indId}"), 0, 100);
        if ($pts > 0) {
            $indicadores[$indId] = $pts;
        }
    }

    if ($xp === 0 && $credito === 0 && !$indicadores) {
        throw new RegistroInvalido('Defina pelo menos uma recompensa maior que zero (XP, créditos verdes ou pontos em indicadores).');
    }

    return c_transacao(static function (PDO $pdo) use ($id, $codigo, $nome, $descricao, $xp, $credito, $inicio, $fim, $limiteUsos, $somenteNovos, $ativa, $indicadores, $adminId) {
        try {
            if ($id === 0) {
                $pdo->prepare(
                    'INSERT INTO codigo_promocional (codigo, nome, descricao, recompensa_xp, recompensa_credito, data_inicio, data_fim, limite_usos, somente_novos, ativa, criado_por)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$codigo, $nome, $descricao, $xp, $credito, $inicio, $fim, $limiteUsos, (int) $somenteNovos, (int) $ativa, $adminId]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $s = $pdo->prepare('SELECT * FROM codigo_promocional WHERE codigo_promocional_id = ? FOR UPDATE');
                $s->execute([$id]);
                $atual = $s->fetch();
                if (!$atual) {
                    throw new RegistroInvalido('Código promocional não encontrado.');
                }

                $rStmt = $pdo->prepare('SELECT COUNT(*) FROM codigo_promocional_resgate WHERE codigo_promocional_id = ?');
                $rStmt->execute([$id]);
                $resgates = (int) $rStmt->fetchColumn();

                if ($resgates > 0) {
                    $indAtuais = [];
                    $qi = $pdo->prepare('SELECT indicador_ambiental_id, pontos FROM codigo_promocional_indicador WHERE codigo_promocional_id = ? ORDER BY indicador_ambiental_id');
                    $qi->execute([$id]);
                    foreach ($qi->fetchAll() as $li) {
                        $indAtuais[(int) $li['indicador_ambiental_id']] = (int) $li['pontos'];
                    }
                    ksort($indicadores);
                    if (
                        $atual['codigo'] !== $codigo
                        || (int) $atual['recompensa_xp'] !== $xp
                        || (int) $atual['recompensa_credito'] !== $credito
                        || $indAtuais !== $indicadores
                    ) {
                        throw new RegistroInvalido('Este código já foi resgatado por jogadores: o código e as recompensas não podem mais mudar.');
                    }
                    if ($atual['data_inicio'] !== $inicio) {
                        throw new RegistroInvalido('Este código já possui resgates: a data de início não pode mais mudar (ajuste a data de término).');
                    }
                    if ($limiteUsos !== null && $limiteUsos < $resgates) {
                        throw new RegistroInvalido("O limite de resgates não pode ser menor que {$resgates} (já resgatados).");
                    }
                    $pdo->prepare(
                        'UPDATE codigo_promocional
                            SET nome = ?, descricao = ?, data_fim = ?, limite_usos = ?, somente_novos = ?, ativa = ?
                          WHERE codigo_promocional_id = ?'
                    )->execute([$nome, $descricao, $fim, $limiteUsos, (int) $somenteNovos, (int) $ativa, $id]);
                    return 'Código promocional salvo.';
                }

                $pdo->prepare(
                    'UPDATE codigo_promocional
                        SET codigo = ?, nome = ?, descricao = ?, recompensa_xp = ?, recompensa_credito = ?,
                            data_inicio = ?, data_fim = ?, limite_usos = ?, somente_novos = ?, ativa = ?
                      WHERE codigo_promocional_id = ?'
                )->execute([$codigo, $nome, $descricao, $xp, $credito, $inicio, $fim, $limiteUsos, (int) $somenteNovos, (int) $ativa, $id]);
            }

            $pdo->prepare('DELETE FROM codigo_promocional_indicador WHERE codigo_promocional_id = ?')->execute([$id]);
            if ($indicadores) {
                $insInd = $pdo->prepare('INSERT INTO codigo_promocional_indicador (codigo_promocional_id, indicador_ambiental_id, pontos) VALUES (?, ?, ?)');
                foreach ($indicadores as $indId => $pts) {
                    $insInd->execute([$id, $indId, $pts]);
                }
            }
            return 'Código promocional salvo.';
        } catch (Throwable $ex) {
            c_unico($ex, 'Já existe um código promocional com esse texto.');
        }
    });
}
