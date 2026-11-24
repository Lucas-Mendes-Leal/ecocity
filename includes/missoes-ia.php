<?php
/**
 * Geração semanal automática de missões e missões oficiais de comunidade via IA local
 * (RN49 / RN67 / RN75 / RN91, 19/11/2026).
 *
 *  - Não busca notícias na internet: combina apenas as atividades sustentáveis cadastradas no banco.
 *  - Gera 2 missões semanais extras + 1 missão oficial de comunidade para a semana atual (segunda a domingo),
 *    sempre DESATIVADAS (rascunho, `origem = 'ia'`) para revisão humana.
 *  - Todas as recompensas, penalidades e limites são calculados e validados no PHP (`salvar_missao`, `salvar_cmissao`).
 */
declare(strict_types=1);

const IA_MISSOES_SEMANAIS_QTD = 2;
const IA_MISSOES_COMUNIDADE_QTD = 1;
const IA_MISSOES_PENALIDADE = 5;
const IA_CMISSAO_XP = 60;
const IA_CMISSAO_CREDITO = 40;
const IA_CMISSAO_PONTOS = 100;

const IA_ESQUEMA_MISSOES_SEMANAIS = [
    'type' => 'object',
    'properties' => [
        'missoes' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'nome'      => ['type' => 'string'],
                    'descricao' => ['type' => 'string'],
                    'metas'     => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'atividade_id' => ['type' => 'integer'],
                                'quantidade'   => ['type' => 'integer'],
                            ],
                            'required' => ['atividade_id', 'quantidade'],
                        ],
                    ],
                ],
                'required' => ['nome', 'descricao', 'metas'],
            ],
        ],
    ],
    'required' => ['missoes'],
];

const IA_ESQUEMA_MISSOES_COMUNIDADE = [
    'type' => 'object',
    'properties' => [
        'missoes' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'nome'         => ['type' => 'string'],
                    'descricao'    => ['type' => 'string'],
                    'atividade_id' => ['type' => 'integer'],
                    'meta'         => ['type' => 'number'],
                ],
                'required' => ['nome', 'descricao', 'atividade_id', 'meta'],
            ],
        ],
    ],
    'required' => ['missoes'],
];

/** Migração 034 aplicada (`origem` em `missao` e `comunidade_missao`)? */
function missao_ia_banco_pronto(): bool
{
    return schema_flag('missao_ia', static fn(): bool => (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND (
            (table_name = 'missao' AND column_name = 'origem') OR
            (table_name = 'comunidade_missao' AND column_name = 'origem')
        )"
    )->fetchColumn() === 2);
}

/** Garante um nome único (3..100 caracteres) frente aos já existentes no banco e no lote atual. */
function ia_nome_unico_missao(string $bruto, array &$usados, DateTimeImmutable $segunda): string
{
    $nome = trim(preg_replace('/\s+/u', ' ', $bruto) ?? '');
    if (mb_strlen($nome, 'UTF-8') < 3) {
        $nome = 'Desafio Sustentável';
    }
    $nome = mb_substr($nome, 0, 100);
    $chave = mb_strtolower($nome, 'UTF-8');
    if (isset($usados[$chave])) {
        $sufixo = ' (' . $segunda->format('d/m') . ')';
        $candidato = mb_substr($nome, 0, 100 - mb_strlen($sufixo, 'UTF-8')) . $sufixo;
        $n = 2;
        while (isset($usados[mb_strtolower($candidato, 'UTF-8')])) {
            $sufN = " ({$segunda->format('d/m')} #{$n})";
            $candidato = mb_substr($nome, 0, 100 - mb_strlen($sufN, 'UTF-8')) . $sufN;
            $n++;
        }
        $nome = $candidato;
        $chave = mb_strtolower($nome, 'UTF-8');
    }
    $usados[$chave] = true;
    return $nome;
}

/**
 * Gera missões semanais e/ou oficiais de comunidade como rascunhos desativados para a semana atual (segunda a domingo).
 * @param string $escopo 'missao' | 'cmissao' | 'todas'
 * @param bool $pularSeJaGerou quando true (agendador), não repete se a semana atual já tiver os rascunhos da IA
 */
function gerar_missoes_ia(string $escopo = 'todas', bool $pularSeJaGerou = false): array
{
    if (!in_array($escopo, ['missao', 'cmissao', 'todas'], true)) {
        throw new RegistroInvalido('Escopo inválido para geração de missões.');
    }
    if (!ia_disponivel()) {
        throw new RegistroInvalido('A IA não está disponível no momento. Tente novamente em instantes.');
    }

    $pdo = db();
    if ((int) $pdo->query("SELECT GET_LOCK('ecocity_missoes_ia', 0)")->fetchColumn() !== 1) {
        throw new RegistroInvalido('Já existe uma geração de missões pela IA em andamento.');
    }

    try {
        $segundaObj = (new DateTimeImmutable('monday this week'))->setTime(0, 0);
        $domingoObj = $segundaObj->modify('+6 days');
        $segunda = $segundaObj->format('Y-m-d');
        $domingo = $domingoObj->format('Y-m-d');
        $bancoPronto = missao_ia_banco_pronto();
        $modelo = ia_config()['modelo'];
        $criadas = [];
        $erros = [];

        // 1) Missões semanais (2 rascunhos para segunda..domingo)
        if ($escopo === 'missao' || $escopo === 'todas') {
            $jaTemSemanais = false;
            if ($pularSeJaGerou && $bancoPronto) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM missao WHERE origem = 'ia' AND data_inicio = ? AND data_fim = ?");
                $q->execute([$segunda, $domingo]);
                $jaTemSemanais = ((int) $q->fetchColumn() >= IA_MISSOES_SEMANAIS_QTD);
            }

            if (!$jaTemSemanais) {
                $atividades = $pdo->query(
                    'SELECT a.atividade_sustentavel_id AS id, a.nome, a.descricao, a.recompensa_xp, a.recompensa_credito, c.nome AS area
                       FROM atividade_sustentavel a
                       JOIN categoria_ambiental c ON c.categoria_ambiental_id = a.categoria_ambiental_id
                      WHERE a.ativa = TRUE
                   ORDER BY a.atividade_sustentavel_id'
                )->fetchAll();

                if (!$atividades) {
                    $erros[] = 'Nenhuma atividade sustentável ativa encontrada.';
                } else {
                    $mapaAtividades = [];
                    $linhasAtividades = [];
                    foreach ($atividades as $a) {
                        $aid = (int) $a['id'];
                        $mapaAtividades[$aid] = $a;
                        $linhasAtividades[] = "- ID {$aid}: {$a['nome']} (área: {$a['area']}, XP base: {$a['recompensa_xp']}, cr base: {$a['recompensa_credito']})";
                    }

                    $nomesExistentes = [];
                    foreach ($pdo->query('SELECT nome FROM missao')->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                        $nomesExistentes[mb_strtolower((string) $nm, 'UTF-8')] = true;
                    }

                    $recentes = $pdo->query(
                        "SELECT m.nome, GROUP_CONCAT(CONCAT(a.nome, ' x', ma.quantidade_necessaria) SEPARATOR ' + ') AS metas
                           FROM missao m
                      LEFT JOIN missao_atividade ma ON ma.missao_id = m.missao_id
                      LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ma.atividade_sustentavel_id
                       GROUP BY m.missao_id
                       ORDER BY m.missao_id DESC
                          LIMIT 20"
                    )->fetchAll();
                    $linhasRecentes = array_map(
                        static fn(array $r): string => "- \"{$r['nome']}\"" . ($r['metas'] ? " [{$r['metas']}]" : ''),
                        $recentes
                    );

                    $sistema = 'Você é o designer de missões do EcoCity, um jogo educativo ambiental. '
                        . 'Crie missões semanais variadas, envolventes e alcançáveis usando apenas as atividades do catálogo informado. '
                        . 'Responda SEMPRE em português do Brasil, em JSON válido.';
                    $pedido = "Semana da missão: {$segundaObj->format('d/m/Y')} a {$domingoObj->format('d/m/Y')} (7 dias).\n\n"
                        . "Atividades disponíveis:\n" . implode("\n", $linhasAtividades) . "\n\n"
                        . "Missões recentes ou fixas (NÃO repita nomes nem copie exatamente as mesmas metas):\n"
                        . ($linhasRecentes ? implode("\n", $linhasRecentes) : '(nenhuma)') . "\n\n"
                        . 'Crie EXATAMENTE ' . IA_MISSOES_SEMANAIS_QTD . " missões semanais extras:\n"
                        . "- nome: título curto, criativo e claro em PT-BR (5 a 60 caracteres), diferente dos listados acima.\n"
                        . "- descricao: 1 frase curta incentivando o jogador (15 a 180 caracteres).\n"
                        . "- metas: 1 ou 2 itens por missão (sem repetir atividade na mesma missão), combinando 2 ou 3 ações por missão no total "
                        . "(ex.: 2 de uma atividade, 1 de uma + 1 de outra, ou 2 de uma + 1 de outra), para que as 2 missões juntas somem cerca de 150 XP.\n"
                        . '- quantidade: inteiro de 1 a 3 por meta (nunca maior que 7).';

                    try {
                        $resp = ia_chamar($sistema, $pedido, [], IA_ESQUEMA_MISSOES_SEMANAIS, 90, $modelo, 8192);
                        $lista = is_array($resp['dados']['missoes'] ?? null) ? array_slice($resp['dados']['missoes'], 0, IA_MISSOES_SEMANAIS_QTD) : [];
                        if (!$lista) {
                            $erros[] = 'A IA não retornou missões semanais válidas.';
                        }
                        foreach ($lista as $item) {
                            $metasValidas = [];
                            $vistas = [];
                            $somaXp = 0;
                            $somaCr = 0;
                            foreach (is_array($item['metas'] ?? null) ? array_slice($item['metas'], 0, 3) : [] as $mt) {
                                $aid = (int) ($mt['atividade_id'] ?? 0);
                                if (!isset($mapaAtividades[$aid]) || isset($vistas[$aid])) continue;
                                $vistas[$aid] = true;
                                $qtd = max(1, min(4, (int) ($mt['quantidade'] ?? 1)));
                                $metasValidas[] = ['atividade' => $aid, 'quantidade' => $qtd];
                                $somaXp += ((int) $mapaAtividades[$aid]['recompensa_xp']) * $qtd;
                                $somaCr += ((int) $mapaAtividades[$aid]['recompensa_credito']) * $qtd;
                            }
                            if (!$metasValidas) {
                                $erros[] = 'Missão ignorada por não conter atividades válidas.';
                                continue;
                            }

                            $nome = ia_nome_unico_missao((string) ($item['nome'] ?? ''), $nomesExistentes, $segundaObj);
                            $desc = trim(preg_replace('/\s+/u', ' ', (string) ($item['descricao'] ?? '')) ?? '');
                            if (mb_strlen($desc, 'UTF-8') < 10) {
                                $desc = 'Complete as metas ecológicas propostas ao longo desta semana.';
                            }
                            $desc = mb_substr($desc, 0, 250);
                            $xp = (int) floor($somaXp * 0.5);
                            $cr = (int) floor($somaCr * 0.5);

                            salvar_missao([
                                'tipo'       => TIPO_MISSAO_SEMANAL,
                                'nome'       => $nome,
                                'descricao'  => $desc,
                                'xp'         => $xp,
                                'credito'    => $cr,
                                'penalidade' => IA_MISSOES_PENALIDADE,
                                'dataInicio' => $segunda,
                                'dataFim'    => $domingo,
                                'ativa'      => false,
                                'metas'      => $metasValidas,
                            ]);

                            $mid = $pdo->prepare('SELECT missao_id FROM missao WHERE nome = ? LIMIT 1');
                            $mid->execute([$nome]);
                            $idCriado = (int) $mid->fetchColumn();
                            if ($bancoPronto && $idCriado > 0) {
                                $pdo->prepare("UPDATE missao SET origem = 'ia', gerado_em = NOW() WHERE missao_id = ?")->execute([$idCriado]);
                            }
                            $criadas[] = ['escopo' => 'missao', 'id' => $idCriado, 'nome' => $nome, 'xp' => $xp, 'credito' => $cr];
                        }
                    } catch (Throwable $ex) {
                        $erros[] = 'Missões semanais: ' . $ex->getMessage();
                    }
                }
            }
        }

        // 2) Missão oficial de comunidade (1 rascunho semanal para segunda..domingo)
        if ($escopo === 'cmissao' || $escopo === 'todas') {
            $jaTemComunidade = false;
            if ($pularSeJaGerou && $bancoPronto) {
                $q = $pdo->prepare("SELECT COUNT(*) FROM comunidade_missao WHERE comunidade_id IS NULL AND origem = 'ia' AND data_inicio = ? AND data_fim = ?");
                $q->execute([$segunda, $domingo]);
                $jaTemComunidade = ((int) $q->fetchColumn() >= IA_MISSOES_COMUNIDADE_QTD);
            }

            if (!$jaTemComunidade) {
                $atividadesCm = $pdo->query(
                    'SELECT a.atividade_sustentavel_id AS id, a.nome, a.impacto_real_quantidade AS imp, a.impacto_real_unidade AS un, c.nome AS area
                       FROM atividade_sustentavel a
                       JOIN categoria_ambiental c ON c.categoria_ambiental_id = a.categoria_ambiental_id
                      WHERE a.ativa = TRUE AND a.impacto_real_quantidade IS NOT NULL AND a.impacto_real_quantidade > 0
                   ORDER BY a.atividade_sustentavel_id'
                )->fetchAll();

                if (!$atividadesCm) {
                    $erros[] = 'Nenhuma atividade ativa com impacto real definido para missão de comunidade.';
                } else {
                    $mapaCm = [];
                    $linhasCm = [];
                    foreach ($atividadesCm as $a) {
                        $aid = (int) $a['id'];
                        $imp = (float) $a['imp'];
                        $min = round($imp * 15, 2);
                        $max = round($imp * 70, 2);
                        $mapaCm[$aid] = ['min' => $min, 'max' => $max, 'un' => $a['un'], 'nome' => $a['nome']];
                        $linhasCm[] = "- ID {$aid}: {$a['nome']} (unidade: {$a['un']}, meta recomendada entre {$min} e {$max})";
                    }

                    $nomesCm = [];
                    foreach ($pdo->query('SELECT nome FROM comunidade_missao WHERE comunidade_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                        $nomesCm[mb_strtolower((string) $nm, 'UTF-8')] = true;
                    }

                    $recentesCm = $pdo->query(
                        'SELECT cm.nome, a.nome AS atividade, cm.meta
                           FROM comunidade_missao cm
                           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = cm.atividade_sustentavel_id
                          WHERE cm.comunidade_id IS NULL
                       ORDER BY cm.comunidade_missao_id DESC
                          LIMIT 12'
                    )->fetchAll();
                    $linhasRecCm = array_map(
                        static fn(array $r): string => "- \"{$r['nome']}\" ({$r['atividade']}: {$r['meta']})",
                        $recentesCm
                    );

                    $sistema = 'Você é o designer de desafios coletivos do EcoCity. '
                        . 'Crie uma missão semanal oficial para comunidades de até 30 jogadores. '
                        . 'Responda SEMPRE em português do Brasil, em JSON válido.';
                    $pedido = "Semana da missão coletiva: {$segundaObj->format('d/m/Y')} a {$domingoObj->format('d/m/Y')}.\n\n"
                        . "Atividades elegíveis e faixas realistas para 30 jogadores numa semana:\n" . implode("\n", $linhasCm) . "\n\n"
                        . "Missões coletivas recentes (varie o tema/atividade e não repita nomes):\n"
                        . ($linhasRecCm ? implode("\n", $linhasRecCm) : '(nenhuma)') . "\n\n"
                        . 'Crie EXATAMENTE ' . IA_MISSOES_COMUNIDADE_QTD . " missão oficial de comunidade:\n"
                        . "- nome: nome inspirador e diferente dos anteriores (5 a 60 caracteres).\n"
                        . "- descricao: 1 frase curta explicando a meta coletiva em impacto real (15 a 180 caracteres).\n"
                        . "- atividade_id: um dos IDs acima (de preferência um tema diferente dos mais recentes).\n"
                        . '- meta: número redondo dentro da faixa recomendada da atividade escolhida.';

                    try {
                        $resp = ia_chamar($sistema, $pedido, [], IA_ESQUEMA_MISSOES_COMUNIDADE, 90, $modelo, 8192);
                        $lista = is_array($resp['dados']['missoes'] ?? null) ? array_slice($resp['dados']['missoes'], 0, IA_MISSOES_COMUNIDADE_QTD) : [];
                        if (!$lista) {
                            $erros[] = 'A IA não retornou missão de comunidade válida.';
                        }
                        foreach ($lista as $item) {
                            $aid = (int) ($item['atividade_id'] ?? 0);
                            if (!isset($mapaCm[$aid])) {
                                $aid = (int) array_key_first($mapaCm);
                            }
                            $faixa = $mapaCm[$aid];
                            $metaBruta = is_numeric($item['meta'] ?? null) ? (float) $item['meta'] : (($faixa['min'] + $faixa['max']) / 2);
                            $meta = max($faixa['min'], min($faixa['max'], $metaBruta));
                            $meta = $meta >= 10 ? round($meta) : round($meta, 1);

                            $nome = ia_nome_unico_missao((string) ($item['nome'] ?? ''), $nomesCm, $segundaObj);
                            $desc = trim(preg_replace('/\s+/u', ' ', (string) ($item['descricao'] ?? '')) ?? '');
                            if (mb_strlen($desc, 'UTF-8') < 10) {
                                $desc = "Unam forças na comunidade para alcançar {$meta} {$faixa['un']} nesta semana.";
                            }
                            $desc = mb_substr($desc, 0, 250);

                            salvar_cmissao([
                                'nome'       => $nome,
                                'descricao'  => $desc,
                                'atividade'  => $aid,
                                'meta'       => $meta,
                                'xp'         => IA_CMISSAO_XP,
                                'credito'    => IA_CMISSAO_CREDITO,
                                'pontos'     => IA_CMISSAO_PONTOS,
                                'dataInicio' => $segunda,
                                'dataFim'    => $domingo,
                                'ativa'      => false,
                            ]);

                            $cid = $pdo->prepare('SELECT comunidade_missao_id FROM comunidade_missao WHERE comunidade_id IS NULL AND nome = ? ORDER BY comunidade_missao_id DESC LIMIT 1');
                            $cid->execute([$nome]);
                            $idCriado = (int) $cid->fetchColumn();
                            if ($bancoPronto && $idCriado > 0) {
                                $pdo->prepare("UPDATE comunidade_missao SET origem = 'ia', gerado_em = NOW() WHERE comunidade_missao_id = ?")->execute([$idCriado]);
                            }
                            $criadas[] = ['escopo' => 'cmissao', 'id' => $idCriado, 'nome' => $nome, 'meta' => $meta, 'unidade' => $faixa['un']];
                        }
                    } catch (Throwable $ex) {
                        $erros[] = 'Missão de comunidade: ' . $ex->getMessage();
                    }
                }
            }
        }

        return ['criadas' => $criadas, 'erros' => $erros, 'semana' => "{$segundaObj->format('d/m')} a {$domingoObj->format('d/m')}"];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ecocity_missoes_ia')");
    }
}
