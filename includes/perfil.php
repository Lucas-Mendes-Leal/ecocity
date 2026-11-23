<?php
/**
 * Perfil do jogador: dados da conta, impacto pessoal, estatísticas, extrato
 * e edição (apelido, nome da cidade e senha).
 */
declare(strict_types=1);

const EXTRATO_TAMANHOS = [10, 20, 30];   // "Mostrar N por página"; outro valor vira 10
/** Filtro de origem do extrato → origens gravadas nos históricos (vw_extrato). */
const EXTRATO_ORIGENS = [
    'acoes' => ['atividade'], 'quizzes' => ['quiz'], 'missoes' => ['missao'], 'conquistas' => ['conquista'],
    'construcoes' => ['construcao'], 'renda' => ['renda'], 'comunidade' => ['comunidade', 'missao comunidade'],
    'promocoes' => ['codigo promocional'], 'ajuste_admin' => ['ajuste admin'],
];

/** Avatar personalizado (RN93): ícones e cores permitidos (cores = áreas do jogo + dourado). */
const AVATAR_ICONES = ['leaf', 'recycle', 'droplet', 'bolt', 'bike', 'tree', 'sun', 'star'];
const AVATAR_CORES = ['recycling', 'water', 'energy', 'mobility', 'nature', 'gold'];
/** Privacidade (RN93): opção na tela → coluna de jogador. */
const PRIVACIDADE = ['apareceSugestoes' => 'aparece_sugestoes', 'aceitaPedidos' => 'aceita_pedidos', 'permiteVisita' => 'permite_visita'];
const MEUS_DADOS_POR_HORA = 3;
/** Preferências de som (RN96, migrações 032 e 033): chave da tela → coluna de jogador. */
const SOM_OPCOES = ['mudo' => 'som_mudo', 'efeitos' => 'som_efeitos', 'interface' => 'som_interface', 'ambiente' => 'som_ambiente'];
const SOM_VOLUMES = [
    'volume'          => 'som_volume',
    'volumeEfeitos'   => 'som_volume_efeitos',
    'volumeInterface' => 'som_volume_interface',
    'volumeAmbiente'  => 'som_volume_ambiente',
];

/** O banco tem a migração 032? */
function som_banco_pronto(): bool
{
    return schema_flag('som', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'jogador' AND column_name = 'som_volume'"
    )->fetchColumn());
}

/** O banco tem a migração 033 (volumes individuais por tipo de som)? */
function som_volumes_pronto(): bool
{
    return schema_flag('som_volumes', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'jogador' AND column_name = 'som_volume_efeitos'"
    )->fetchColumn());
}

/** Preferências de som do jogador (padrão quando o banco não tem a 032/033). Volumes null = padrão do canal/aparelho. */
function preferencias_som(int $jogadorId): array
{
    $padrao = [
        'mudo' => false, 'efeitos' => true, 'interface' => true, 'ambiente' => false,
        'volume' => null, 'volumeEfeitos' => null, 'volumeInterface' => null, 'volumeAmbiente' => null,
    ];
    if (!som_banco_pronto()) return $padrao;
    $cols = 'som_mudo, som_efeitos, som_interface, som_ambiente, som_volume'
        . (som_volumes_pronto() ? ', som_volume_efeitos, som_volume_interface, som_volume_ambiente' : '');
    $s = db()->prepare("SELECT {$cols} FROM jogador WHERE jogador_id = ?");
    $s->execute([$jogadorId]);
    $r = $s->fetch();
    if (!$r) return $padrao;
    $intOuNull = static fn($v): ?int => $v === null ? null : (int) $v;
    return [
        'mudo'            => (bool) $r['som_mudo'],
        'efeitos'         => (bool) $r['som_efeitos'],
        'interface'       => (bool) $r['som_interface'],
        'ambiente'        => (bool) $r['som_ambiente'],
        'volume'          => $intOuNull($r['som_volume']),
        'volumeEfeitos'   => $intOuNull($r['som_volume_efeitos'] ?? null),
        'volumeInterface' => $intOuNull($r['som_volume_interface'] ?? null),
        'volumeAmbiente'  => $intOuNull($r['som_volume_ambiente'] ?? null),
    ];
}

/** Grava uma preferência de som: opcao em SOM_OPCOES com valor 0/1, ou em SOM_VOLUMES com 0–100. */
function salvar_som(int $jogadorId, string $opcao, string $valor): void
{
    if (!som_banco_pronto()) throw new RegistroInvalido('As preferências de som ainda não estão disponíveis.');
    if (isset(SOM_VOLUMES[$opcao])) {
        if ($opcao !== 'volume' && !som_volumes_pronto()) {
            throw new RegistroInvalido('O volume por tipo de som ainda não está disponível.');
        }
        if (!ctype_digit($valor) || (int) $valor > 100) throw new RegistroInvalido('Volume inválido (0 a 100).');
        db()->prepare('UPDATE jogador SET ' . SOM_VOLUMES[$opcao] . ' = ? WHERE jogador_id = ?')->execute([(int) $valor, $jogadorId]);
        return;
    }
    if (!isset(SOM_OPCOES[$opcao])) throw new RegistroInvalido('Opção de som inválida.');
    if ($valor !== '0' && $valor !== '1') throw new RegistroInvalido('Valor inválido.');
    db()->prepare('UPDATE jogador SET ' . SOM_OPCOES[$opcao] . ' = ? WHERE jogador_id = ?')->execute([(int) $valor, $jogadorId]);
}

/** Avatar público de uma linha com avatar_icone/avatar_cor (null = avatar padrão, a inicial). */
function avatar_publico(array $r): ?array
{
    $i = $r['avatar_icone'] ?? null;
    $c = $r['avatar_cor'] ?? null;
    return in_array($i, AVATAR_ICONES, true) && in_array($c, AVATAR_CORES, true) ? ['icone' => $i, 'cor' => $c] : null;
}

/** Grava o avatar; ícone e cor vazios voltam ao padrão. */
function salvar_avatar(int $jogadorId, string $icone, string $cor): void
{
    if ($icone === '' && $cor === '') {
        db()->prepare('UPDATE jogador SET avatar_icone = NULL, avatar_cor = NULL WHERE jogador_id = ?')->execute([$jogadorId]);
        return;
    }
    if (!in_array($icone, AVATAR_ICONES, true)) throw new RegistroInvalido('Escolha um ícone da lista.');
    if (!in_array($cor, AVATAR_CORES, true)) throw new RegistroInvalido('Escolha uma cor da lista.');
    db()->prepare('UPDATE jogador SET avatar_icone = ?, avatar_cor = ? WHERE jogador_id = ?')->execute([$icone, $cor, $jogadorId]);
}

/** Liga ou desliga uma opção de privacidade. */
function salvar_privacidade(int $jogadorId, string $opcao, bool $valor): void
{
    if (!isset(PRIVACIDADE[$opcao])) throw new RegistroInvalido('Opção de privacidade inválida.');
    db()->prepare('UPDATE jogador SET ' . PRIVACIDADE[$opcao] . ' = ? WHERE jogador_id = ?')->execute([(int) $valor, $jogadorId]);
}

/** "Sair de todos os aparelhos": derruba as outras sessões e mantém esta (RN93). */
function sair_outros_aparelhos(int $usuarioId): void
{
    db()->prepare('UPDATE usuario SET sessao_versao = sessao_versao + 1 WHERE usuario_id = ?')->execute([$usuarioId]);
    session_regenerate_id(true);
    $_SESSION['sessao_versao'] = situacao_conta($usuarioId, true)['versao'];
}

/** Desativa a própria conta (senha + "DESATIVAR"); entrar de novo com a senha reativa (RN93). */
function desativar_conta(int $usuarioId, string $senha, string $confirmacao): void
{
    $stmt = db()->prepare('SELECT senha FROM usuario WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    if (!password_verify($senha, (string) $stmt->fetchColumn())) throw new RegistroInvalido('A senha está incorreta.');
    if (mb_strtoupper(trim($confirmacao)) !== 'DESATIVAR') throw new RegistroInvalido('Digite DESATIVAR para confirmar.');
    if (administrador_id($usuarioId) !== null) {
        throw new RegistroInvalido('Administradores não podem desativar a própria conta. Peça a outro administrador para remover seu acesso antes.');
    }
    db()->prepare('UPDATE usuario SET status = FALSE, desativada_em = NOW(), sessao_versao = sessao_versao + 1 WHERE usuario_id = ?')->execute([$usuarioId]);
}

/** Último login certo antes desta sessão (tentativa_login) — para "Sessão e conta". */
function ultima_entrada(string $email): ?string
{
    $s = db()->prepare('SELECT data FROM tentativa_login WHERE email = ? AND sucesso = TRUE ORDER BY data DESC LIMIT 1 OFFSET 1');
    $s->execute([$email]);
    $d = $s->fetchColumn();
    return $d ? date(DATE_ATOM, strtotime($d)) : null;
}

/**
 * Tudo o que o sistema guarda sobre o jogador (LGPD, RN93). Sem senha, sem dados de outros jogadores além do apelido
 * e só as mensagens que ele enviou. Limite de 3 por hora (contado na sessão).
 */
function meus_dados(int $usuarioId, int $jogadorId): array
{
    $agora = time();
    $_SESSION['meus_dados'] = array_values(array_filter($_SESSION['meus_dados'] ?? [], static fn($t) => $t > $agora - 3600));
    if (count($_SESSION['meus_dados']) >= MEUS_DADOS_POR_HORA) {
        throw new RegistroInvalido('Você já baixou seus dados ' . MEUS_DADOS_POR_HORA . ' vezes na última hora. Tente mais tarde.');
    }
    $_SESSION['meus_dados'][] = $agora;

    $pdo = db();
    $q = static function (string $sql, array $args) use ($pdo): array {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $j = [$jogadorId];
    return [
        'geradoEm' => date(DATE_ATOM),
        'sobre' => 'Dados guardados pelo EcoCity sobre a sua conta (LGPD). A senha não é incluída: o sistema guarda só um código (hash) dela.',
        'conta' => $q('SELECT nome, email, data_cadastro, status FROM usuario WHERE usuario_id = ?', [$usuarioId])[0] ?? null,
        'jogador' => $q('SELECT apelido, numero_conta AS codigo_publico, data_criacao, xp_atual, creditos_verde, avatar_icone, avatar_cor,
                                aparece_sugestoes, aceita_pedidos, permite_visita, tutorial_visto'
                        . (som_banco_pronto() ? ', som_mudo, som_efeitos, som_interface, som_ambiente, som_volume' : '')
                        . (som_volumes_pronto() ? ', som_volume_efeitos, som_volume_interface, som_volume_ambiente' : '')
                        . ' FROM jogador WHERE jogador_id = ?', $j)[0] ?? null,
        'cidade' => $q('SELECT c.nome, n.nivel AS nivel_cidade FROM cidade c JOIN nivel_cidade n ON n.nivel_cidade_id = c.nivel_cidade_id WHERE c.jogador_id = ?', $j)[0] ?? null,
        'indicadores' => $q('SELECT i.nome, ci.valor FROM cidade_indicador ci JOIN cidade c ON c.cidade_id = ci.cidade_id
                               JOIN indicador_ambiental i ON i.indicador_ambiental_id = ci.indicador_ambiental_id WHERE c.jogador_id = ?', $j),
        'construcoes' => $q('SELECT co.nome, cc.lote, cc.x, cc.y, cc.rotacao, cc.data_construcao FROM cidade_construcao cc JOIN cidade c ON c.cidade_id = cc.cidade_id
                               JOIN construcao co ON co.construcao_id = cc.construcao_id WHERE c.jogador_id = ? ORDER BY cc.data_construcao', $j),
        'registros' => $q('SELECT a.nome AS atividade, t.nome AS verificacao, r.data_registro, r.status, r.motivo_rejeicao, r.data_aprovacao,
                                  e.descricao, e.arquivo IS NOT NULL AS com_foto
                             FROM registro_atividade r JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
                             JOIN tipo_verificacao t ON t.tipo_verificacao_id = r.tipo_verificacao_id
                        LEFT JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
                            WHERE r.jogador_id = ? ORDER BY r.data_registro', $j),
        'quizzes' => $q('SELECT q.titulo, t.data, t.acertos, t.pontuacao FROM tentativa_quiz t JOIN quiz q ON q.quiz_id = t.quiz_id
                          WHERE t.jogador_id = ? ORDER BY t.tentativa_id', $j),
        'extrato' => $q('SELECT data, origem, xp, credito, referencia FROM vw_extrato WHERE jogador_id = ? ORDER BY data, ordem', $j),
        'conquistas' => $q('SELECT c.nome, jc.data_conquista FROM jogador_conquista jc JOIN conquista c ON c.conquista_id = jc.conquista_id
                             WHERE jc.jogador_id = ? ORDER BY jc.data_conquista', $j),
        'missoes' => $q('SELECT m.nome, jm.periodo_inicio, jm.status FROM jogador_missao jm JOIN missao m ON m.missao_id = jm.missao_id
                          WHERE jm.jogador_id = ? ORDER BY jm.periodo_inicio', $j),
        'amizades' => $q("SELECT j2.apelido, a.status, a.data_pedido, a.data_resposta FROM amizade a
                            JOIN jogador j2 ON j2.jogador_id = IF(a.solicitante_id = ?, a.destinatario_id, a.solicitante_id)
                           WHERE ? IN (a.solicitante_id, a.destinatario_id) ORDER BY a.data_pedido", [$jogadorId, $jogadorId]),
        'mensagensEnviadas' => $q('SELECT j2.apelido AS para, m.texto, m.data_envio FROM mensagem m JOIN jogador j2 ON j2.jogador_id = m.destinatario_id
                                    WHERE m.remetente_id = ? ORDER BY m.data_envio', $j),
        'comunidade' => $q('SELECT c.nome, m.papel, m.data_entrada FROM comunidade_membro m JOIN comunidade c ON c.comunidade_id = m.comunidade_id
                             WHERE m.jogador_id = ?', $j)[0] ?? null,
        'mensagensComunidade' => $q('SELECT c.nome AS comunidade, m.texto, m.data_envio FROM comunidade_mensagem m JOIN comunidade c ON c.comunidade_id = m.comunidade_id
                                      WHERE m.jogador_id = ? ORDER BY m.data_envio', $j),
        'contribuicoes' => $q('SELECT c.nome AS comunidade, cc.impacto_real, cc.pontos, cc.data_registro FROM comunidade_contribuicao cc
                                 JOIN comunidade c ON c.comunidade_id = cc.comunidade_id WHERE cc.jogador_id = ? ORDER BY cc.data_registro', $j),
        'diasDeAcesso' => array_column($q('SELECT data FROM acesso_diario WHERE jogador_id = ? ORDER BY data', $j), 'data'),
        'codigosPromocionais' => resgates_promocionais_jogador($jogadorId),
        'ajustesAdministracao' => contas_admin_banco_pronto()
            ? $q('SELECT tipo, delta_xp, delta_credito, indicadores_json, detalhes_json, motivo, criado_em
                    FROM ajuste_conta_admin WHERE usuario_id = ? ORDER BY criado_em', [$usuarioId])
            : [],
        'chatEcoGuia' => chat_ia_banco_pronto()
            ? $q('SELECT papel, texto, categoria, criado_em FROM chat_ia_mensagem WHERE jogador_id = ? ORDER BY chat_ia_mensagem_id', $j)
            : [],
        'impactoPessoal' => impacto_pessoal_detalhado($pdo, $jogadorId),
    ];
}

/**
 * Impacto ecológico pessoal detalhado e multi-unidade por área, por atividade e por período
 * ('tudo', 'dias30', 'semana'), com resumo de comprovação, balanço na cidade e contribuição na comunidade.
 * Aceita qualquer quantidade de atividades e unidades novas sem misturar grandezas físicas diferentes.
 *
 * @return array{areas: array<int, array<string, mixed>>, resumo: array<string, mixed>}
 */
function impacto_pessoal_detalhado(PDO $pdo, int $jogadorId, ?array $progresso = null): array
{
    $p = $progresso ?? progresso_criterios($pdo, $jogadorId);
    $desde30 = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $inicioSemana = (new DateTimeImmutable('today'))
        ->modify('-' . ((int) date('N') - 1) . ' days')
        ->format('Y-m-d 00:00:00');

    $indCidade = [];
    foreach (INDICADOR_CHAVE as $indId => $_) {
        $indCidade[$indId] = (int) ($p['indicador:' . $indId] ?? 0);
    }

    $st = $pdo->prepare(
        "SELECT a.atividade_sustentavel_id AS id,
                a.nome,
                a.ativa,
                a.impacto_real_quantidade AS qtd_padrao,
                a.impacto_real_unidade AS unidade,
                ai.indicador_ambiental_id AS ind,
                ai.impacto AS impacto_ind,
                COUNT(r.registro_atividade_id) AS acoes_tudo,
                COALESCE(SUM(IF(r.registro_atividade_id IS NOT NULL, r.impacto_efetivo, 0)), 0) AS imp_tudo,
                COALESCE(SUM(r.tipo_verificacao_id = 1), 0) AS sem_foto_tudo,
                COALESCE(SUM(r.tipo_verificacao_id = 2), 0) AS com_foto_tudo,
                COALESCE(SUM(r.tipo_verificacao_id = 3), 0) AS parceiro_tudo,
                COALESCE(SUM(IF(r.registro_atividade_id IS NOT NULL, r.pontos_indicador, 0)), 0) AS pts_tudo,
                COALESCE(SUM(r.data_registro >= ?), 0) AS acoes_30,
                COALESCE(SUM(IF(r.data_registro >= ?, r.impacto_efetivo, 0)), 0) AS imp_30,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 1), 0) AS sem_foto_30,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 2), 0) AS com_foto_30,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 3), 0) AS parceiro_30,
                COALESCE(SUM(IF(r.data_registro >= ?, r.pontos_indicador, 0)), 0) AS pts_30,
                COALESCE(SUM(r.data_registro >= ?), 0) AS acoes_sem,
                COALESCE(SUM(IF(r.data_registro >= ?, r.impacto_efetivo, 0)), 0) AS imp_sem,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 1), 0) AS sem_foto_sem,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 2), 0) AS com_foto_sem,
                COALESCE(SUM(r.data_registro >= ? AND r.tipo_verificacao_id = 3), 0) AS parceiro_sem,
                COALESCE(SUM(IF(r.data_registro >= ?, r.pontos_indicador, 0)), 0) AS pts_sem
           FROM atividade_sustentavel a
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
      LEFT JOIN vw_registro_detalhado r
             ON r.atividade_sustentavel_id = a.atividade_sustentavel_id
            AND r.jogador_id = ?
            AND r.status = 'aprovado'
          WHERE a.ativa = TRUE OR r.registro_atividade_id IS NOT NULL
       GROUP BY a.atividade_sustentavel_id, a.nome, a.ativa, a.impacto_real_quantidade, a.impacto_real_unidade, ai.indicador_ambiental_id, ai.impacto
       ORDER BY ai.indicador_ambiental_id, a.atividade_sustentavel_id"
    );
    $st->execute([
        $desde30, $desde30, $desde30, $desde30, $desde30, $desde30,
        $inicioSemana, $inicioSemana, $inicioSemana, $inicioSemana, $inicioSemana, $inicioSemana,
        $jogadorId,
    ]);
    $linhas = $st->fetchAll();

    $porInd = [];
    $totalCatalogoAtivo = 0;
    foreach ($linhas as $l) {
        if ((bool) $l['ativa']) $totalCatalogoAtivo++;
        $porInd[(int) $l['ind']][] = $l;
    }

    $chavesPeriodo = ['tudo' => 'tudo', 'dias30' => '30', 'semana' => 'sem'];
    $resumoPeriodos = [];
    foreach ($chavesPeriodo as $pChave => $_) {
        $resumoPeriodos[$pChave] = [
            'acoes' => 0, 'semFoto' => 0, 'comFoto' => 0, 'parceiro' => 0,
            'pontosCidade' => 0, 'areasAtivas' => 0, 'habitosPraticados' => 0, 'unidades' => [],
        ];
    }

    $areas = [];
    foreach (INDICADOR_CHAVE as $ind => $chave) {
        $ativsArea = $porInd[$ind] ?? [];
        $unidadePrincipal = unidade_criterio($pdo, 'impacto_real:' . $ind);
        $areaPeriodos = [];

        foreach ($chavesPeriodo as $pChave => $suf) {
            $unidadesMap = [];
            $habitos = [];
            $acoesArea = 0;
            $semFotoArea = 0;
            $comFotoArea = 0;
            $parceiroArea = 0;
            $ptsArea = 0;

            foreach ($ativsArea as $a) {
                $ac = (int) $a['acoes_' . $suf];
                $imp = round((float) $a['imp_' . $suf], 2);
                $sf = (int) $a['sem_foto_' . $suf];
                $cf = (int) $a['com_foto_' . $suf];
                $pa = (int) $a['parceiro_' . $suf];
                $pt = (int) $a['pts_' . $suf];
                $u = $a['unidade'] !== null ? trim((string) $a['unidade']) : '';

                $acoesArea += $ac;
                $semFotoArea += $sf;
                $comFotoArea += $cf;
                $parceiroArea += $pa;
                $ptsArea += $pt;

                if ($u !== '') {
                    if (!isset($unidadesMap[$u])) {
                        $unidadesMap[$u] = ['unidade' => $u, 'quantidade' => 0.0, 'acoes' => 0];
                    }
                    $unidadesMap[$u]['quantidade'] = round($unidadesMap[$u]['quantidade'] + $imp, 2);
                    $unidadesMap[$u]['acoes'] += $ac;
                }

                if ($ac > 0) {
                    $resumoPeriodos[$pChave]['habitosPraticados']++;
                }

                $habitos[] = [
                    'id'         => (int) $a['id'],
                    'nome'       => $a['nome'],
                    'ativa'      => (bool) $a['ativa'],
                    'unidade'    => $u !== '' ? $u : null,
                    'padrao'     => $a['qtd_padrao'] !== null ? (float) $a['qtd_padrao'] : null,
                    'acoes'      => $ac,
                    'quantidade' => $imp,
                    'semFoto'    => $sf,
                    'comFoto'    => $cf,
                    'parceiro'   => $pa,
                    'pontos'     => $pt,
                ];
            }

            $listaUnidades = array_values($unidadesMap);
            $areaPeriodos[$pChave] = [
                'atividades'   => $acoesArea,
                'pontosRegiao' => $ptsArea,
                'semFoto'      => $semFotoArea,
                'comFoto'      => $comFotoArea,
                'parceiro'     => $parceiroArea,
                'unidades'     => $listaUnidades,
                'habitos'      => $habitos,
            ];

            $resumoPeriodos[$pChave]['acoes'] += $acoesArea;
            $resumoPeriodos[$pChave]['semFoto'] += $semFotoArea;
            $resumoPeriodos[$pChave]['comFoto'] += $comFotoArea;
            $resumoPeriodos[$pChave]['parceiro'] += $parceiroArea;
            $resumoPeriodos[$pChave]['pontosCidade'] += $ptsArea;
            if ($acoesArea > 0) {
                $resumoPeriodos[$pChave]['areasAtivas']++;
            }
            foreach ($listaUnidades as $lu) {
                $resumoPeriodos[$pChave]['unidades'][] = [
                    'indicadorId' => $ind,
                    'categoria'   => $chave,
                    'unidade'     => $lu['unidade'],
                    'quantidade'  => $lu['quantidade'],
                    'acoes'       => $lu['acoes'],
                ];
            }
        }

        $valInd = $indCidade[$ind] ?? 0;
        $areas[] = [
            'indicadorId'      => $ind,
            'categoria'        => $chave,
            'atividades'       => $areaPeriodos['tudo']['atividades'],
            'quantidade'       => round((float) ($p['impacto_real:' . $ind] ?? 0), 2),
            'unidade'          => $unidadePrincipal,
            'valorIndicador'   => $valInd,
            'estagioIndicador' => $valInd >= 75 ? 4 : ($valInd >= 50 ? 3 : ($valInd >= 25 ? 2 : 1)),
            'pontosRegiao'     => $areaPeriodos['tudo']['pontosRegiao'],
            'semFoto'          => $areaPeriodos['tudo']['semFoto'],
            'comFoto'          => $areaPeriodos['tudo']['comFoto'],
            'parceiro'         => $areaPeriodos['tudo']['parceiro'],
            'unidades'         => $areaPeriodos['tudo']['unidades'],
            'habitos'          => $areaPeriodos['tudo']['habitos'],
            'periodos'         => $areaPeriodos,
        ];
    }

    // Parceiros distintos que já validaram o jogador
    $stParc = $pdo->prepare(
        "SELECT COUNT(DISTINCT v.parceiro_id)
           FROM verificacao_parceiro v
           JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id
          WHERE r.jogador_id = ? AND r.status = 'aprovado'"
    );
    $stParc->execute([$jogadorId]);
    $parceirosDistintos = (int) $stParc->fetchColumn();

    // Dias ativos com ação sustentável e sequência atual / recorde
    $stDias = $pdo->prepare("SELECT DISTINCT DATE(data_registro) FROM registro_atividade WHERE jogador_id = ? AND status <> 'rejeitado'");
    $stDias->execute([$jogadorId]);
    $datasAcoes = $stDias->fetchAll(PDO::FETCH_COLUMN);
    $seq = function_exists('sequencias_de_dias') ? sequencias_de_dias($datasAcoes) : ['atual' => 0, 'melhor' => 0];

    // Contribuição acumulada na comunidade
    $stCom = $pdo->prepare(
        'SELECT c.nome, c.icone,
                (SELECT COUNT(*) FROM comunidade_contribuicao cc WHERE cc.jogador_id = m.jogador_id AND cc.comunidade_id = c.comunidade_id) AS acoes,
                (SELECT COALESCE(SUM(cc.pontos), 0) FROM comunidade_contribuicao cc WHERE cc.jogador_id = m.jogador_id AND cc.comunidade_id = c.comunidade_id) AS pontos
           FROM comunidade_membro m
           JOIN comunidade c ON c.comunidade_id = m.comunidade_id AND c.ativa = TRUE
          WHERE m.jogador_id = ?'
    );
    $stCom->execute([$jogadorId]);
    $comRow = $stCom->fetch();
    $comunidade = $comRow ? [
        'nome'   => $comRow['nome'],
        'icone'  => $comRow['icone'],
        'acoes'  => (int) $comRow['acoes'],
        'pontos' => (int) $comRow['pontos'],
    ] : null;

    return [
        'areas'  => $areas,
        'resumo' => [
            'periodos'                => $resumoPeriodos,
            'totalAtividadesCatalogo' => $totalCatalogoAtivo,
            'parceirosDistintos'      => $parceirosDistintos,
            'diasComAcao'             => count($datasAcoes),
            'sequenciaAtual'          => (int) $seq['atual'],
            'melhorSequencia'         => (int) $seq['melhor'],
            'comunidade'              => $comunidade,
        ],
    ];
}

/** Todas as seções do perfil. Estatísticas e impacto reaproveitam progresso_criterios(). */
function perfil_completo(int $usuarioId, int $jogadorId): array
{
    $pdo = db();

    $conta = $pdo->prepare(
        'SELECT u.nome, u.email, u.data_cadastro, j.apelido, j.numero_conta, c.nome AS cidade,
                j.avatar_icone, j.avatar_cor, j.aparece_sugestoes, j.aceita_pedidos, j.permite_visita
           FROM usuario u JOIN jogador j ON j.usuario_id = u.usuario_id JOIN cidade c ON c.jogador_id = j.jogador_id
          WHERE u.usuario_id = ?'
    );
    $conta->execute([$usuarioId]);
    $c = $conta->fetch();

    $p = progresso_criterios($pdo, $jogadorId);

    // Impacto pessoal detalhado e multi-unidade por indicador (RN26)
    $impactoDetalhado = impacto_pessoal_detalhado($pdo, $jogadorId, $p);
    $impacto = $impactoDetalhado['areas'];

    $status = $pdo->prepare('SELECT status, COUNT(*) FROM registro_atividade WHERE jogador_id = ? GROUP BY status');
    $status->execute([$jogadorId]);
    $porStatus = $status->fetchAll(PDO::FETCH_KEY_PAIR);

    $conquistas = $pdo->prepare('SELECT (SELECT COUNT(*) FROM jogador_conquista WHERE jogador_id = ?), (SELECT COUNT(*) FROM conquista)');
    $conquistas->execute([$jogadorId]);
    [$obtidas, $totalConquistas] = $conquistas->fetch(PDO::FETCH_NUM);

    return [
        'conta' => [
            'nome'          => $c['nome'],
            'email'         => $c['email'],
            'apelido'       => $c['apelido'],
            'codigoPublico' => $c['numero_conta'],
            'cidade'        => $c['cidade'],
            'cadastro'      => $c['data_cadastro'],
            'avatar'        => avatar_publico($c),
            'privacidade'   => ['apareceSugestoes' => (bool) $c['aparece_sugestoes'], 'aceitaPedidos' => (bool) $c['aceita_pedidos'],
                                'permiteVisita' => (bool) $c['permite_visita']],
            'ultimaEntrada' => ultima_entrada($c['email']),
            'som'           => preferencias_som($jogadorId),   // RN96
            'administrador' => administrador_id($usuarioId) !== null,
        ],
        'impacto' => $impacto,
        'impactoResumo' => $impactoDetalhado['resumo'],
        'estatisticas' => [
            'nivelJogador'       => $p['nivel_jogador'],
            'nivelCidade'        => $p['nivel_cidade'],
            'registros'          => [
                'aprovado'  => (int) ($porStatus['aprovado'] ?? 0),
                'pendente'  => (int) ($porStatus['pendente'] ?? 0),
                'rejeitado' => (int) ($porStatus['rejeitado'] ?? 0),
            ],
            'registrosEvidencia' => $p['registros_evidencia'],
            'quizzesConcluidos'  => $p['quizzes_concluidos'],
            'quizzesPerfeitos'   => $p['quizzes_perfeitos'],
            'conquistas'         => (int) $obtidas,
            'totalConquistas'    => (int) $totalConquistas,
            'construcoes'        => $p['construcoes'],
        ],
        'extrato' => extrato_jogador($jogadorId),
        'extratoResumo' => resumo_extrato($jogadorId),
        'codigosPromocionais' => resgates_promocionais_jogador($jogadorId),
    ];
}

/** Consulta indexada equivalente a vw_extrato filtrada por jogador nos dois ramos da UNION. */
function extrato_subquery_jogador(): string
{
    return "(
        SELECT hx.jogador_id, hx.data, hx.origem, hx.quantidade AS xp,
               (SELECT hc.quantidade FROM historico_credito_verde hc
                 WHERE hc.jogador_id = hx.jogador_id AND hc.origem = hx.origem
                   AND hc.registro_atividade_id <=> hx.registro_atividade_id AND hc.tentativa_id <=> hx.tentativa_id
                   AND hc.conquista_id <=> hx.conquista_id AND hc.missao_id <=> hx.missao_id
                   AND hc.comunidade_missao_id <=> hx.comunidade_missao_id
                   AND hc.codigo_promocional_id <=> hx.codigo_promocional_id
                   AND hc.ajuste_admin_id <=> hx.ajuste_admin_id
                   AND (hx.codigo_promocional_id IS NOT NULL OR hx.ajuste_admin_id IS NOT NULL OR hc.data = hx.data)
                 LIMIT 1) AS credito,
               COALESCE(a.nome, q.titulo, cq.nome, m.nome, cm.nome, cp.nome, aa.motivo) AS referencia,
               hx.historico_xp_id AS ordem
          FROM historico_xp hx
          LEFT JOIN registro_atividade r ON r.registro_atividade_id = hx.registro_atividade_id
          LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
          LEFT JOIN tentativa_quiz t ON t.tentativa_id = hx.tentativa_id
          LEFT JOIN quiz q ON q.quiz_id = t.quiz_id
          LEFT JOIN conquista cq ON cq.conquista_id = hx.conquista_id
          LEFT JOIN missao m ON m.missao_id = hx.missao_id
          LEFT JOIN comunidade_missao cm ON cm.comunidade_missao_id = hx.comunidade_missao_id
          LEFT JOIN codigo_promocional cp ON cp.codigo_promocional_id = hx.codigo_promocional_id
          LEFT JOIN ajuste_conta_admin aa ON aa.ajuste_admin_id = hx.ajuste_admin_id
         WHERE hx.jogador_id = ?
        UNION ALL
        SELECT hc.jogador_id, hc.data, hc.origem, 0 AS xp, hc.quantidade AS credito,
               COALESCE(co.nome, cmd.nome, cp2.nome, aa2.motivo) AS referencia, hc.historico_credito_verde_id AS ordem
          FROM historico_credito_verde hc
          LEFT JOIN construcao co ON co.construcao_id = hc.construcao_id
          LEFT JOIN comunidade cmd ON cmd.comunidade_id = hc.comunidade_id
          LEFT JOIN codigo_promocional cp2 ON cp2.codigo_promocional_id = hc.codigo_promocional_id
          LEFT JOIN ajuste_conta_admin aa2 ON aa2.ajuste_admin_id = hc.ajuste_admin_id
         WHERE hc.jogador_id = ?
           AND (hc.construcao_id IS NOT NULL OR hc.comunidade_id IS NOT NULL
                OR (hc.codigo_promocional_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM historico_xp hx2
                     WHERE hx2.jogador_id = hc.jogador_id AND hx2.codigo_promocional_id = hc.codigo_promocional_id
                ))
                OR (hc.ajuste_admin_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM historico_xp hx3
                     WHERE hx3.jogador_id = hc.jogador_id AND hx3.ajuste_admin_id = hc.ajuste_admin_id
                )))
    ) x";
}

/**
 * Extrato de XP e créditos, 10, 20 ou 30 por página, do mais novo para o mais antigo.
 * $tipo: todos | ganhos (XP ou créditos positivos) | gastos (créditos negativos).
 * $origem: todas ou uma chave de EXTRATO_ORIGENS. Página fora do limite vai para a última.
 * Usa a mesma definição de vw_extrato com filtro jogador_id empurrado nos dois ramos.
 */
function extrato_jogador(int $jogadorId, int $pagina = 1, string $tipo = 'todos', string $origem = 'todas', int $porPagina = 10): array
{
    if (!in_array($porPagina, EXTRATO_TAMANHOS, true)) $porPagina = EXTRATO_TAMANHOS[0];
    $where = [];
    $args = [$jogadorId, $jogadorId];
    switch ($tipo) {
        case 'todos': break;
        case 'ganhos': $where[] = '(xp > 0 OR credito > 0)'; break;
        case 'gastos': $where[] = 'credito < 0'; break;
        default: throw new RegistroInvalido('Filtro do extrato inválido.');
    }
    if ($origem !== 'todas') {
        if (!isset(EXTRATO_ORIGENS[$origem])) throw new RegistroInvalido('Origem do extrato inválida.');
        $where[] = 'origem IN (' . implode(',', array_fill(0, count(EXTRATO_ORIGENS[$origem]), '?')) . ')';
        array_push($args, ...EXTRATO_ORIGENS[$origem]);
    }
    $filtro = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sub = extrato_subquery_jogador();
    $pdo = db();

    $total = $pdo->prepare("SELECT COUNT(*) FROM {$sub} {$filtro}");
    $total->execute($args);
    $total = (int) $total->fetchColumn();
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = max(1, min($pagina, $paginas));

    // No mesmo instante, os ganhos vêm antes dos gastos
    $stmt = $pdo->prepare("SELECT data, origem, xp, credito, referencia FROM {$sub} {$filtro}
                            ORDER BY data DESC, xp = 0, ordem DESC LIMIT " . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
    $stmt->execute($args);

    return [
        'itens' => array_map(static fn(array $r): array => [
            'data'       => date(DATE_ATOM, strtotime($r['data'])),
            'origem'     => $r['origem'],
            'referencia' => $r['referencia'],
            'xp'         => (int) $r['xp'],
            'credito'    => (int) ($r['credito'] ?? 0),
        ], $stmt->fetchAll()),
        'total'     => $total,
        'pagina'    => $pagina,
        'porPagina' => $porPagina,
        'tipo'      => $tipo,
        'origem'    => $origem,
    ];
}

/** XP ganho, créditos ganhos e créditos gastos nesta semana (desde segunda) e neste mês. */
function resumo_extrato(int $jogadorId): array
{
    $inicioSemana = (new DateTimeImmutable('today'))
        ->modify('-' . ((int) date('N') - 1) . ' days')
        ->format('Y-m-d 00:00:00');
    $inicioMes = date('Y-m-01 00:00:00');
    $desde = min($inicioSemana, $inicioMes);
    $pdo = db();

    $stXp = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN data >= ? AND quantidade > 0 THEN quantidade ELSE 0 END), 0) AS xp_semana,
                COALESCE(SUM(CASE WHEN data >= ? AND quantidade > 0 THEN quantidade ELSE 0 END), 0) AS xp_mes
           FROM historico_xp
          WHERE jogador_id = ? AND data >= ?'
    );
    $stXp->execute([$inicioSemana, $inicioMes, $jogadorId, $desde]);
    $xp = $stXp->fetch();

    $stCr = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN data >= ? AND quantidade > 0 THEN quantidade ELSE 0 END), 0) AS ganhos_semana,
                COALESCE(SUM(CASE WHEN data >= ? AND quantidade < 0 THEN -quantidade ELSE 0 END), 0) AS gastos_semana,
                COALESCE(SUM(CASE WHEN data >= ? AND quantidade > 0 THEN quantidade ELSE 0 END), 0) AS ganhos_mes,
                COALESCE(SUM(CASE WHEN data >= ? AND quantidade < 0 THEN -quantidade ELSE 0 END), 0) AS gastos_mes
           FROM historico_credito_verde
          WHERE jogador_id = ? AND data >= ?'
    );
    $stCr->execute([$inicioSemana, $inicioSemana, $inicioMes, $inicioMes, $jogadorId, $desde]);
    $cr = $stCr->fetch();

    return [
        'semana' => [
            'xp'     => (int) ($xp['xp_semana'] ?? 0),
            'ganhos' => (int) ($cr['ganhos_semana'] ?? 0),
            'gastos' => (int) ($cr['gastos_semana'] ?? 0),
        ],
        'mes' => [
            'xp'     => (int) ($xp['xp_mes'] ?? 0),
            'ganhos' => (int) ($cr['ganhos_mes'] ?? 0),
            'gastos' => (int) ($cr['gastos_mes'] ?? 0),
        ],
    ];
}

/** Atualiza apelido e nome da cidade. */
function atualizar_perfil(int $jogadorId, string $apelido, string $cidade): void
{
    $erros = [];
    if (mb_strlen($apelido, 'UTF-8') < 3 || mb_strlen($apelido, 'UTF-8') > 100) $erros[] = 'O apelido deve ter de 3 a 100 caracteres.';
    if (mb_strlen($cidade, 'UTF-8') < 3 || mb_strlen($cidade, 'UTF-8') > 100) $erros[] = 'O nome da cidade deve ter de 3 a 100 caracteres.';
    if ($erros) throw new RegistroInvalido(implode(' ', $erros));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE jogador SET apelido = ? WHERE jogador_id = ?')->execute([$apelido, $jogadorId]);
        $pdo->prepare('UPDATE cidade SET nome = ? WHERE jogador_id = ?')->execute([$cidade, $jogadorId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

/** Troca a senha: exige a senha atual e aplica as mesmas regras do cadastro. */
function trocar_senha(int $usuarioId, string $atual, string $nova, string $confirmar): void
{
    $stmt = db()->prepare('SELECT senha FROM usuario WHERE usuario_id = ?');
    $stmt->execute([$usuarioId]);
    if (!password_verify($atual, (string) $stmt->fetchColumn())) {
        throw new RegistroInvalido('A senha atual está incorreta.');
    }
    if (strlen($nova) < SENHA_MIN || strlen($nova) > SENHA_MAX) {
        throw new RegistroInvalido('A nova senha deve ter de ' . SENHA_MIN . ' a ' . SENHA_MAX . ' caracteres.');
    }
    if ($nova !== $confirmar) {
        throw new RegistroInvalido('As senhas novas não coincidem.');
    }
    if ($nova === $atual) {
        throw new RegistroInvalido('A nova senha deve ser diferente da atual.');
    }
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    try {
        db()->prepare('UPDATE usuario SET senha = ?, sessao_versao = sessao_versao + 1 WHERE usuario_id = ?')
            ->execute([$hash, $usuarioId]);
        situacao_conta($usuarioId, true);
        $_SESSION['sessao_versao'] = versao_sessao($usuarioId);
    } catch (PDOException) {
        db()->prepare('UPDATE usuario SET senha = ? WHERE usuario_id = ?')->execute([$hash, $usuarioId]);
    }
    session_regenerate_id(true);   // nova sessão após trocar a credencial
}
