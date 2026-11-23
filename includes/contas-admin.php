<?php
/**
 * Gestão avançada de contas na administração (specs/site.md RN99).
 *
 *  - Lista contas com progresso, situação, trava de login e sinalização de demonstração.
 *  - Detalha visão 360° da conta (cadastro, indicadores, comunidade, contagens e histórico admin).
 *  - Permite ajustes auditáveis de créditos verdes (±), XP (+) e indicadores (±),
 *    moderação de apelido e nome da cidade (e-mail segue fixo pela RN32) e ações de segurança:
 *    bloquear/desbloquear (derrubando sessões ao bloquear), encerrar sessões, liberar trava de login,
 *    gerar link de redefinição de senha (1 h, uso único, RN47) e promover/rebaixar administrador (RN52).
 */
declare(strict_types=1);

const CONTA_ADMIN_DELTA_CREDITO_MAX = 100000;
const CONTA_ADMIN_DELTA_XP_MAX      = 100000;
const CONTA_ADMIN_MOTIVO_MIN        = 5;
const CONTA_ADMIN_MOTIVO_MAX        = 250;
const CONTA_ADMIN_HIST_LIMITE       = 20;

/** A migração 037 (tabela ajuste_conta_admin) já foi aplicada neste banco? */
function contas_admin_banco_pronto(): bool
{
    return schema_flag('contas_admin', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'ajuste_conta_admin'"
    )->fetchColumn());
}

/**
 * Confere se o e-mail está travado por excesso de senhas erradas (RN46),
 * sem misturar com o IP do administrador.
 * @return array{travadoAte: ?string, errosRecentes: int, ultimoLoginCerto: ?string}
 */
function conta_admin_trava_login(PDO $pdo, string $email): array
{
    $stmtUltimo = $pdo->prepare('SELECT MAX(data) FROM tentativa_login WHERE email = ? AND sucesso = TRUE');
    $stmtUltimo->execute([$email]);
    $ultimoSucesso = $stmtUltimo->fetchColumn() ?: null;

    $stmtErros = $pdo->prepare(
        'SELECT COUNT(*) AS erros, MAX(data) AS ultimo_erro
           FROM tentativa_login
          WHERE email = ? AND sucesso = FALSE AND data > NOW() - INTERVAL ? MINUTE
            AND data > COALESCE(?, \'1000-01-01\')'
    );
    $stmtErros->execute([$email, LOGIN_JANELA_MIN, $ultimoSucesso]);
    $row = $stmtErros->fetch();

    $erros = (int) ($row['erros'] ?? 0);
    $travadoAte = null;
    if ($erros >= LOGIN_MAX_ERROS_EMAIL && !empty($row['ultimo_erro'])) {
        $fim = (new DateTimeImmutable($row['ultimo_erro']))->modify('+' . LOGIN_BLOQUEIO_MIN . ' minutes');
        if ($fim > new DateTimeImmutable()) {
            $travadoAte = $fim->format(DATE_ATOM);
        }
    }

    return [
        'travadoAte'       => $travadoAte,
        'errosRecentes'    => $erros,
        'ultimoLoginCerto' => $ultimoSucesso ? date(DATE_ATOM, strtotime($ultimoSucesso)) : null,
    ];
}

/** Resolve os dados do administrador chamador (ou consulta por `$adminId`). */
function conta_admin_resolver_chamador(int $adminId, ?array $adminAtual = null): array
{
    if ($adminAtual !== null) {
        return $adminAtual;
    }
    $stmt = db()->prepare('SELECT usuario_id FROM administrador WHERE administrador_id = ? AND ativo = TRUE LIMIT 1');
    $stmt->execute([$adminId]);
    $uid = $stmt->fetchColumn();
    $admin = $uid !== false ? admin_atual((int) $uid) : null;
    if ($admin === null) {
        throw new RegistroInvalido('Acesso restrito a administradores.');
    }
    return $admin;
}

/** Retorna vínculo administrativo da conta alvo (ativo ou inativo), ou null. */
function conta_admin_alvo_info(PDO $pdo, int $usuarioId): ?array
{
    $comPerfil = admin_perfis_banco_pronto();
    $sql = $comPerfil
        ? 'SELECT administrador_id, ativo, perfil FROM administrador WHERE usuario_id = ? LIMIT 1'
        : 'SELECT administrador_id, ativo FROM administrador WHERE usuario_id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $perfil = $comPerfil ? (string) ($row['perfil'] ?? ADMIN_PERFIL_PADRAO) : 'master';
    return [
        'administradorId' => (int) $row['administrador_id'],
        'ativo'           => (bool) $row['ativo'],
        'perfil'          => admin_perfil_valido($perfil) ? $perfil : ADMIN_PERFIL_PADRAO,
    ];
}

/** Lista resumida das contas para Administração › Conteúdo › Contas. */
function listar_contas_admin(): array
{
    $pdo = db();
    $comPerfil = admin_perfis_banco_pronto();

    $travadosStmt = $pdo->prepare(
        "SELECT t.email
           FROM tentativa_login t
          WHERE t.sucesso = FALSE
            AND t.data > NOW() - INTERVAL ? MINUTE
            AND t.data > COALESCE(
                  (SELECT MAX(s.data) FROM tentativa_login s WHERE s.email = t.email AND s.sucesso = TRUE),
                  '1000-01-01'
                )
          GROUP BY t.email
         HAVING COUNT(*) >= ?
            AND TIMESTAMPADD(MINUTE, ?, MAX(t.data)) > NOW()"
    );
    $travadosStmt->execute([LOGIN_JANELA_MIN, LOGIN_MAX_ERROS_EMAIL, LOGIN_BLOQUEIO_MIN]);
    $emailsTravados = array_flip($travadosStmt->fetchAll(PDO::FETCH_COLUMN));

    $colsAdmin = $comPerfil ? 'a.administrador_id IS NOT NULL AS admin, a.perfil AS admin_perfil' : 'a.administrador_id IS NOT NULL AS admin, \'master\' AS admin_perfil';
    $linhas = $pdo->query(
        "SELECT u.usuario_id, u.nome, u.email, u.data_cadastro, u.status, u.desativada_em, u.demonstracao,
                j.jogador_id, j.apelido, j.numero_conta, j.xp_atual, j.creditos_verde,
                nj.nivel AS nivel_jogador, c.nome AS cidade, nc.nivel AS nivel_cidade, cp.progresso,
                {$colsAdmin}
           FROM usuario u
      LEFT JOIN administrador a ON a.usuario_id = u.usuario_id AND a.ativo = TRUE
      LEFT JOIN jogador j ON j.usuario_id = u.usuario_id
      LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
      LEFT JOIN cidade c ON c.jogador_id = j.jogador_id
      LEFT JOIN nivel_cidade nc ON nc.nivel_cidade_id = c.nivel_cidade_id
      LEFT JOIN vw_cidade_progresso cp ON cp.cidade_id = c.cidade_id
       ORDER BY u.usuario_id"
    )->fetchAll();

    return array_map(static function (array $u) use ($emailsTravados): array {
        $ativa = (bool) $u['status'];
        $temJogador = $u['jogador_id'] !== null;
        $ehAdmin = (bool) $u['admin'];
        $perfil = $ehAdmin ? (string) ($u['admin_perfil'] ?? ADMIN_PERFIL_PADRAO) : null;
        return [
            'id'                    => (int) $u['usuario_id'],
            'nome'                  => $u['nome'],
            'email'                 => $u['email'],
            'apelido'               => $u['apelido'],
            'codigoPublico'         => $u['numero_conta'],
            'cadastro'              => $u['data_cadastro'],
            'ativa'                 => $ativa,
            'admin'                 => $ehAdmin,
            'adminPerfil'           => $perfil,
            'adminPerfilNome'       => $ehAdmin ? admin_perfil_nome($perfil) : null,
            'demonstracao'          => (bool) $u['demonstracao'],
            'desativadaPeloJogador' => !$ativa && $u['desativada_em'] !== null,
            'loginTravado'          => isset($emailsTravados[$u['email']]),
            'temJogador'            => $temJogador,
            'nivelJogador'          => $temJogador ? (int) ($u['nivel_jogador'] ?? 1) : null,
            'xpAtual'               => $temJogador ? (int) $u['xp_atual'] : null,
            'creditosVerde'         => $temJogador ? (int) $u['creditos_verde'] : null,
            'cidade'                => $u['cidade'],
            'nivelCidade'           => $u['nivel_cidade'] !== null ? (int) $u['nivel_cidade'] : null,
            'progresso'             => $u['progresso'] !== null ? (int) $u['progresso'] : null,
        ];
    }, $linhas);
}

/** Visão 360° de uma conta para o painel "Gerenciar conta". */
function detalhar_conta_admin(int $usuarioId, ?array $adminAtual = null): array
{
    $pdo = db();
    $comPerfil = admin_perfis_banco_pronto();
    $colsAdmin = $comPerfil ? 'a.administrador_id IS NOT NULL AS admin, a.perfil AS admin_perfil' : 'a.administrador_id IS NOT NULL AS admin, \'master\' AS admin_perfil';
    $stmt = $pdo->prepare(
        "SELECT u.usuario_id, u.nome, u.email, u.data_cadastro, u.status, u.desativada_em, u.demonstracao, u.sessao_versao,
                j.jogador_id, j.apelido, j.numero_conta, j.xp_atual, j.creditos_verde, j.data_criacao AS jogador_cadastro,
                nj.nivel AS nivel_jogador, c.cidade_id, c.nome AS cidade, nc.nivel AS nivel_cidade, nc.nome AS nivel_cidade_nome,
                cp.progresso,
                {$colsAdmin}
           FROM usuario u
      LEFT JOIN administrador a ON a.usuario_id = u.usuario_id AND a.ativo = TRUE
      LEFT JOIN jogador j ON j.usuario_id = u.usuario_id
      LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
      LEFT JOIN cidade c ON c.jogador_id = j.jogador_id
      LEFT JOIN nivel_cidade nc ON nc.nivel_cidade_id = c.nivel_cidade_id
      LEFT JOIN vw_cidade_progresso cp ON cp.cidade_id = c.cidade_id
          WHERE u.usuario_id = ?"
    );
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch();
    if (!$u) {
        throw new RegistroInvalido('Conta não encontrada.');
    }

    require_once ECOCITY_ROOT . '/includes/email.php';

    $caller = $adminAtual ?? (($uidSess = usuario_id()) !== null ? admin_atual($uidSess) : null);
    $ehAdmin = (bool) $u['admin'];
    $perfilAlvo = $ehAdmin ? (string) ($u['admin_perfil'] ?? ADMIN_PERFIL_PADRAO) : null;
    $temProg = $caller !== null && admin_tem_permissao($caller, 'contas_progressao');
    $temSuporte = $caller !== null && admin_tem_permissao($caller, 'contas_suporte');
    $temAdmin = $caller !== null && admin_tem_permissao($caller, 'contas_admin');
    $alvoProtegido = $ehAdmin && !$temAdmin;

    $trava = conta_admin_trava_login($pdo, $u['email']);
    $ativa = (bool) $u['status'];
    $temJogador = $u['jogador_id'] !== null;
    $jogadorId = $temJogador ? (int) $u['jogador_id'] : null;

    $indicadores = [];
    $comunidade = null;
    $resumo = null;
    $impactoConta = null;

    if ($temJogador) {
        $impactoDet = function_exists('impacto_pessoal_detalhado') ? impacto_pessoal_detalhado($pdo, $jogadorId) : null;
        if ($impactoDet) {
            $tudoRes = $impactoDet['resumo']['periodos']['tudo'] ?? [];
            $evJog = function_exists('est_jogador') ? est_jogador($jogadorId) : null;
            $impactoConta = [
                'unidades'           => array_values(array_filter($tudoRes['unidades'] ?? [], static fn(array $u): bool => $u['quantidade'] > 0)),
                'pontosCidade'       => (int) ($tudoRes['pontosCidade'] ?? 0),
                'habitosPraticados'  => (int) ($tudoRes['habitosPraticados'] ?? 0),
                'totalCatalogo'      => (int) ($impactoDet['resumo']['totalAtividadesCatalogo'] ?? 15),
                'semFoto'            => (int) ($tudoRes['semFoto'] ?? 0),
                'comFoto'            => (int) ($tudoRes['comFoto'] ?? 0),
                'parceiro'           => (int) ($tudoRes['parceiro'] ?? 0),
                'parceirosDistintos' => (int) ($impactoDet['resumo']['parceirosDistintos'] ?? 0),
                'aprendizado'        => $evJog['aprendizadoResumo'] ?? null,
                'ritmo4Semanas'      => $evJog['comparativoHabitos']['resumo'] ?? null,
            ];
        }
        $indStmt = $pdo->prepare(
            'SELECT ia.indicador_ambiental_id AS id, ia.nome, ia.valor_minimo AS min, ia.valor_maximo AS max,
                    COALESCE(ci.valor, ia.valor_minimo) AS valor
               FROM indicador_ambiental ia
          LEFT JOIN cidade_indicador ci ON ci.indicador_ambiental_id = ia.indicador_ambiental_id AND ci.cidade_id = ?
           ORDER BY ia.indicador_ambiental_id'
        );
        $indStmt->execute([(int) $u['cidade_id']]);
        $indicadores = array_map(static fn(array $r): array => [
            'id'    => (int) $r['id'],
            'chave' => INDICADOR_CHAVE[(int) $r['id']] ?? '',
            'nome'  => $r['nome'],
            'valor' => (int) $r['valor'],
            'min'   => (int) $r['min'],
            'max'   => (int) $r['max'],
        ], $indStmt->fetchAll());

        $comStmt = $pdo->prepare(
            'SELECT cm.papel, c.nome, c.comunidade_id
               FROM comunidade_membro cm
               JOIN comunidade c ON c.comunidade_id = cm.comunidade_id
              WHERE cm.jogador_id = ?'
        );
        $comStmt->execute([$jogadorId]);
        if ($com = $comStmt->fetch()) {
            $comunidade = ['id' => (int) $com['comunidade_id'], 'nome' => $com['nome'], 'papel' => $com['papel']];
        }

        $ultimoAcesso = $pdo->prepare('SELECT MAX(data) FROM acesso_diario WHERE jogador_id = ?');
        $ultimoAcesso->execute([$jogadorId]);
        $ultAcessoVal = $ultimoAcesso->fetchColumn() ?: null;

        $regStmt = $pdo->prepare('SELECT status, COUNT(*) FROM registro_atividade WHERE jogador_id = ? GROUP BY status');
        $regStmt->execute([$jogadorId]);
        $porStatus = $regStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $conqStmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM jogador_conquista WHERE jogador_id = ?), (SELECT COUNT(*) FROM conquista)');
        $conqStmt->execute([$jogadorId]);
        [$conqObtidas, $conqTotal] = $conqStmt->fetch(PDO::FETCH_NUM);

        $constStmt = $pdo->prepare('SELECT COUNT(*) FROM cidade_construcao WHERE cidade_id = ?');
        $constStmt->execute([(int) $u['cidade_id']]);
        $construcoes = (int) $constStmt->fetchColumn();

        $denStmt = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM denuncia_mensagem dm JOIN mensagem m ON m.mensagem_id = dm.mensagem_id WHERE m.remetente_id = ?)
                  + (SELECT COUNT(*) FROM comunidade_denuncia cd JOIN comunidade_mensagem cm ON cm.comunidade_mensagem_id = cd.alvo_id
                      WHERE cd.tipo = 'mensagem' AND cm.jogador_id = ?)
                  + (SELECT COUNT(*) FROM comunidade_denuncia cd JOIN comunidade_topico ct ON ct.comunidade_topico_id = cd.alvo_id
                      WHERE cd.tipo = 'topico' AND ct.autor_id = ?)"
        );
        $denStmt->execute([$jogadorId, $jogadorId, $jogadorId]);
        $denunciasContra = (int) $denStmt->fetchColumn();

        $resumo = [
            'ultimoAcesso'        => $ultAcessoVal,
            'registrosAprovados'  => (int) ($porStatus['aprovado'] ?? 0),
            'registrosPendentes'  => (int) ($porStatus['pendente'] ?? 0),
            'registrosRejeitados' => (int) ($porStatus['rejeitado'] ?? 0),
            'conquistas'          => (int) $conqObtidas,
            'totalConquistas'     => (int) $conqTotal,
            'construcoes'         => $construcoes,
            'denunciasContra'     => $denunciasContra,
        ];
    }

    $historico = [];
    if (contas_admin_banco_pronto()) {
        $histStmt = $pdo->prepare(
            'SELECT a.ajuste_admin_id, a.tipo, a.delta_xp, a.delta_credito, a.indicadores_json,
                    a.detalhes_json, a.motivo, a.criado_em, u.nome AS admin_nome
               FROM ajuste_conta_admin a
               JOIN administrador ad ON ad.administrador_id = a.administrador_id
               JOIN usuario u ON u.usuario_id = ad.usuario_id
              WHERE a.usuario_id = ?
           ORDER BY a.criado_em DESC, a.ajuste_admin_id DESC
              LIMIT ' . CONTA_ADMIN_HIST_LIMITE
        );
        $histStmt->execute([$usuarioId]);
        $historico = array_map(static fn(array $h): array => [
            'id'           => (int) $h['ajuste_admin_id'],
            'tipo'         => $h['tipo'],
            'deltaXp'      => (int) $h['delta_xp'],
            'deltaCredito' => (int) $h['delta_credito'],
            'indicadores'  => $h['indicadores_json'] ? json_decode((string) $h['indicadores_json'], true) : null,
            'detalhes'     => $h['detalhes_json'] ? json_decode((string) $h['detalhes_json'], true) : null,
            'motivo'       => $h['motivo'],
            'criadoEm'     => date(DATE_ATOM, strtotime($h['criado_em'])),
            'adminNome'    => $h['admin_nome'],
        ], $histStmt->fetchAll());
    }

    return [
        'id'                    => (int) $u['usuario_id'],
        'nome'                  => $u['nome'],
        'email'                 => $u['email'],
        'cadastro'              => $u['data_cadastro'],
        'ativa'                 => $ativa,
        'desativadaPeloJogador' => !$ativa && $u['desativada_em'] !== null,
        'admin'                 => $ehAdmin,
        'adminPerfil'           => $perfilAlvo,
        'adminPerfilNome'       => $ehAdmin ? admin_perfil_nome($perfilAlvo) : null,
        'podeAjustarProgressao' => $temProg && (!$ehAdmin || $temAdmin),
        'podeModerarPerfil'     => $temSuporte && !$alvoProtegido,
        'podeSeguranca'         => $temSuporte && !$alvoProtegido,
        'podeGerenciarAdmin'    => $temAdmin,
        'alvoProtegido'         => $alvoProtegido,
        'demonstracao'          => (bool) $u['demonstracao'],
        'sessaoVersao'          => (int) $u['sessao_versao'],
        'travaLogin'            => $trava,
        'temJogador'            => $temJogador,
        'jogadorId'             => $jogadorId,
        'apelido'               => $u['apelido'],
        'codigoPublico'         => $u['numero_conta'],
        'nivelJogador'          => $temJogador ? (int) ($u['nivel_jogador'] ?? 1) : null,
        'xpAtual'               => $temJogador ? (int) $u['xp_atual'] : null,
        'creditosVerde'         => $temJogador ? (int) $u['creditos_verde'] : null,
        'cidade'                => $u['cidade'],
        'nivelCidade'           => $u['nivel_cidade'] !== null ? (int) $u['nivel_cidade'] : null,
        'nivelCidadeNome'       => $u['nivel_cidade_nome'],
        'progresso'             => $u['progresso'] !== null ? (int) $u['progresso'] : null,
        'indicadores'           => $indicadores,
        'impacto'               => $impactoConta,
        'comunidade'            => $comunidade,
        'resumo'                => $resumo,
        'historico'             => $historico,
        'smtpConfigurado'       => smtp_configurado(),
        'tabelaPronta'          => contas_admin_banco_pronto(),
    ];
}

/** Valida e normaliza deltas de progressão informados no painel de conta. */
function validar_ajuste_progressao_admin(array $d): array
{
    $motivo = c_texto($d, 'motivo', 'Motivo do ajuste', CONTA_ADMIN_MOTIVO_MIN, CONTA_ADMIN_MOTIVO_MAX);
    $rawCred = trim((string) ($d['deltaCredito'] ?? '0'));
    $rawXp   = trim((string) ($d['deltaXp'] ?? '0'));
    if ($rawCred === '') $rawCred = '0';
    if ($rawXp === '')   $rawXp = '0';

    $deltaCredito = c_inteiro(['deltaCredito' => $rawCred], 'deltaCredito', 'Créditos verdes', -CONTA_ADMIN_DELTA_CREDITO_MAX, CONTA_ADMIN_DELTA_CREDITO_MAX);
    $deltaXp = c_inteiro(['deltaXp' => $rawXp], 'deltaXp', 'XP', 0, CONTA_ADMIN_DELTA_XP_MAX);

    $rawInd = is_array($d['indicadores'] ?? null) ? $d['indicadores'] : [];
    $deltasInd = [];
    $validos = array_flip(array_keys(INDICADOR_CHAVE));
    foreach ($rawInd as $indId => $val) {
        $id = (int) $indId;
        if (!isset($validos[$id])) {
            throw new RegistroInvalido('Indicador ambiental inválido.');
        }
        $s = trim((string) $val);
        if ($s === '' || $s === '0') continue;
        $delta = c_inteiro(['v' => $s], 'v', 'Ajuste do indicador', -100, 100);
        if ($delta !== 0) {
            $deltasInd[$id] = $delta;
        }
    }

    if ($deltaCredito === 0 && $deltaXp === 0 && !$deltasInd) {
        throw new RegistroInvalido('Informe pelo menos uma alteração em créditos, XP ou indicadores.');
    }

    return [
        'motivo'       => $motivo,
        'deltaCredito' => $deltaCredito,
        'deltaXp'      => $deltaXp,
        'indicadores'  => $deltasInd,
    ];
}

/** Ajusta créditos verdes, XP e/ou indicadores ambientais de uma conta com motivo e auditoria. */
function ajustar_progressao_conta_admin(int $adminId, int $usuarioId, array $d, ?array $adminAtual = null): string
{
    $caller = conta_admin_resolver_chamador($adminId, $adminAtual);
    if (!admin_tem_permissao($caller, 'contas_progressao')) {
        throw new RegistroInvalido('Seu perfil administrativo (' . $caller['perfilNome'] . ') não tem permissão para ajustar créditos, XP ou indicadores.');
    }
    if (!contas_admin_banco_pronto()) {
        throw new RegistroInvalido('Os ajustes avançados de conta exigem a migração 037 no banco de dados.');
    }
    $v = validar_ajuste_progressao_admin($d);

    return c_transacao(static function (PDO $pdo) use ($adminId, $usuarioId, $v): string {
        $stmt = $pdo->prepare(
            'SELECT j.jogador_id, j.creditos_verde, j.xp_atual, c.cidade_id
               FROM usuario u
          LEFT JOIN jogador j ON j.usuario_id = u.usuario_id
          LEFT JOIN cidade c ON c.jogador_id = j.jogador_id
              WHERE u.usuario_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$usuarioId]);
        $alvo = $stmt->fetch();
        if (!$alvo) {
            throw new RegistroInvalido('Conta não encontrada.');
        }
        if ($alvo['jogador_id'] === null) {
            throw new RegistroInvalido('Esta conta não possui perfil de jogador.');
        }

        $jogadorId = (int) $alvo['jogador_id'];
        $cidadeId  = (int) $alvo['cidade_id'];
        $saldoAtual = (int) $alvo['creditos_verde'];
        $xpAtual    = (int) $alvo['xp_atual'];

        if ($saldoAtual + $v['deltaCredito'] < 0) {
            throw new RegistroInvalido('O saldo de créditos verdes não pode ficar negativo (saldo atual: ' . number_format($saldoAtual, 0, ',', '.') . ').');
        }

        $indEfetivos = [];
        if ($v['indicadores']) {
            $indStmt = $pdo->prepare(
                'SELECT ci.indicador_ambiental_id, ci.valor, ia.valor_minimo, ia.valor_maximo
                   FROM cidade_indicador ci
                   JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
                  WHERE ci.cidade_id = ?
                  FOR UPDATE'
            );
            $indStmt->execute([$cidadeId]);
            $atuaisInd = [];
            foreach ($indStmt->fetchAll() as $row) {
                $atuaisInd[(int) $row['indicador_ambiental_id']] = [
                    'valor' => (int) $row['valor'],
                    'min'   => (int) $row['valor_minimo'],
                    'max'   => (int) $row['valor_maximo'],
                ];
            }
            foreach ($v['indicadores'] as $indId => $delta) {
                if (!isset($atuaisInd[$indId])) continue;
                $info = $atuaisInd[$indId];
                $novo = max($info['min'], min($info['max'], $info['valor'] + $delta));
                $efetivo = $novo - $info['valor'];
                if ($efetivo !== 0) {
                    $indEfetivos[$indId] = [
                        'antes'  => $info['valor'],
                        'depois' => $novo,
                        'delta'  => $efetivo,
                    ];
                }
            }
        }

        if ($v['deltaCredito'] === 0 && $v['deltaXp'] === 0 && !$indEfetivos) {
            throw new RegistroInvalido('Nenhum valor seria alterado: os indicadores informados já estão no limite.');
        }

        $detalhes = [
            'saldo_antes'  => $saldoAtual,
            'saldo_depois' => $saldoAtual + $v['deltaCredito'],
            'xp_antes'     => $xpAtual,
            'xp_depois'    => $xpAtual + $v['deltaXp'],
        ];
        $indJsonMap = [];
        foreach ($indEfetivos as $id => $dadosInd) {
            $indJsonMap[(string) $id] = $dadosInd['delta'];
        }

        $pdo->prepare(
            "INSERT INTO ajuste_conta_admin (usuario_id, jogador_id, administrador_id, tipo, delta_xp, delta_credito, indicadores_json, detalhes_json, motivo, criado_em)
             VALUES (?, ?, ?, 'progressao', ?, ?, ?, ?, ?, NOW())"
        )->execute([
            $usuarioId,
            $jogadorId,
            $adminId,
            $v['deltaXp'],
            $v['deltaCredito'],
            $indJsonMap ? json_encode($indJsonMap, JSON_UNESCAPED_UNICODE) : null,
            json_encode($detalhes, JSON_UNESCAPED_UNICODE),
            $v['motivo'],
        ]);
        $ajusteId = (int) $pdo->lastInsertId();

        if ($v['deltaXp'] !== 0 || $v['deltaCredito'] !== 0) {
            creditar_jogador($pdo, $jogadorId, $v['deltaXp'], $v['deltaCredito'], 'ajuste admin', 'ajuste_admin_id', $ajusteId);
        }

        if ($indEfetivos) {
            $updInd = $pdo->prepare('UPDATE cidade_indicador SET valor = ? WHERE cidade_id = ? AND indicador_ambiental_id = ?');
            $insHist = $pdo->prepare("INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem) VALUES (?, ?, ?, NOW(), 'ajuste admin')");
            foreach ($indEfetivos as $indId => $dadosInd) {
                $updInd->execute([$dadosInd['depois'], $cidadeId, $indId]);
                $insHist->execute([$cidadeId, $indId, $dadosInd['depois']]);
            }
        }

        sincronizar_cidade($pdo, $jogadorId);
        avaliar_conquistas($pdo, $jogadorId);

        $partes = [];
        if ($v['deltaCredito'] !== 0) {
            $partes[] = ($v['deltaCredito'] > 0 ? '+' : '−') . number_format(abs($v['deltaCredito']), 0, ',', '.') . ' créditos';
        }
        if ($v['deltaXp'] !== 0) {
            $partes[] = '+' . number_format($v['deltaXp'], 0, ',', '.') . ' XP';
        }
        if ($indEfetivos) {
            $partes[] = count($indEfetivos) . (count($indEfetivos) === 1 ? ' indicador' : ' indicadores');
        }
        return 'Ajuste aplicado (' . implode(', ', $partes) . ').';
    });
}

/** Modera apelido e/ou nome da cidade com registro de histórico (RN32: e-mail não muda). */
function moderar_perfil_conta_admin(int $adminId, int $usuarioId, array $d, ?array $adminAtual = null): string
{
    $caller = conta_admin_resolver_chamador($adminId, $adminAtual);
    if (!admin_tem_permissao($caller, 'contas_suporte')) {
        throw new RegistroInvalido('Seu perfil administrativo (' . $caller['perfilNome'] . ') não tem permissão para moderar perfis.');
    }
    if (!contas_admin_banco_pronto()) {
        throw new RegistroInvalido('A moderação de perfil pelo painel exige a migração 037 no banco de dados.');
    }
    $apelido = c_texto($d, 'apelido', 'Apelido', 3, 100);
    $cidade  = c_texto($d, 'cidade', 'Nome da cidade', 3, 100);
    $motivo  = c_texto($d, 'motivo', 'Motivo da moderação', CONTA_ADMIN_MOTIVO_MIN, CONTA_ADMIN_MOTIVO_MAX);

    return c_transacao(static function (PDO $pdo) use ($caller, $adminId, $usuarioId, $apelido, $cidade, $motivo): string {
        $alvoAdmin = conta_admin_alvo_info($pdo, $usuarioId);
        if ($alvoAdmin && $alvoAdmin['ativo'] && !admin_tem_permissao($caller, 'contas_admin')) {
            throw new RegistroInvalido('Contas administrativas só podem ser alteradas por um Admin Master.');
        }
        $stmt = $pdo->prepare(
            'SELECT j.jogador_id, j.apelido, c.cidade_id, c.nome AS cidade
               FROM usuario u
          LEFT JOIN jogador j ON j.usuario_id = u.usuario_id
          LEFT JOIN cidade c ON c.jogador_id = j.jogador_id
              WHERE u.usuario_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$usuarioId]);
        $alvo = $stmt->fetch();
        if (!$alvo) {
            throw new RegistroInvalido('Conta não encontrada.');
        }
        if ($alvo['jogador_id'] === null) {
            throw new RegistroInvalido('Esta conta não possui perfil de jogador.');
        }

        $mudouApelido = $alvo['apelido'] !== $apelido;
        $mudouCidade  = $alvo['cidade'] !== $cidade;
        if (!$mudouApelido && !$mudouCidade) {
            throw new RegistroInvalido('Altere o apelido ou o nome da cidade para salvar.');
        }

        if ($mudouApelido) {
            $pdo->prepare('UPDATE jogador SET apelido = ? WHERE jogador_id = ?')->execute([$apelido, (int) $alvo['jogador_id']]);
        }
        if ($mudouCidade) {
            $pdo->prepare('UPDATE cidade SET nome = ? WHERE cidade_id = ?')->execute([$cidade, (int) $alvo['cidade_id']]);
        }

        $detalhes = [
            'apelido_antes'  => $mudouApelido ? $alvo['apelido'] : null,
            'apelido_depois' => $mudouApelido ? $apelido : null,
            'cidade_antes'   => $mudouCidade ? $alvo['cidade'] : null,
            'cidade_depois'  => $mudouCidade ? $cidade : null,
        ];

        $pdo->prepare(
            "INSERT INTO ajuste_conta_admin (usuario_id, jogador_id, administrador_id, tipo, delta_xp, delta_credito, detalhes_json, motivo, criado_em)
             VALUES (?, ?, ?, 'perfil', 0, 0, ?, ?, NOW())"
        )->execute([
            $usuarioId,
            (int) $alvo['jogador_id'],
            $adminId,
            json_encode($detalhes, JSON_UNESCAPED_UNICODE),
            $motivo,
        ]);

        return 'Dados do perfil atualizados e registrados no histórico.';
    });
}

/**
 * Executa operação de segurança sobre uma conta de usuário, registrando em ajuste_conta_admin.
 * @return array{mensagem: string, redefinicao?: array{link: string, expiraEmMin: int, emailEnviado: bool}}
 */
function acao_seguranca_conta_admin(int $adminId, int $usuarioAdmin, int $usuarioId, string $operacao, string $motivo = '', string $perfilAdmin = ''): array
{
    $motivoPadrao = [
        'bloquear'         => 'Conta bloqueada pela administração.',
        'desbloquear'      => 'Conta desbloqueada pela administração.',
        'promover'         => 'Acesso de administrador concedido pela administração.',
        'alterar_perfil'   => 'Perfil administrativo alterado pela administração.',
        'rebaixar'         => 'Acesso de administrador removido pela administração.',
        'encerrar_sessoes' => 'Todas as sessões abertas foram encerradas pela administração.',
        'liberar_login'    => 'Bloqueio temporário de login liberado pela administração.',
        'redefinir_senha'  => 'Link de redefinição de senha gerado pela administração.',
    ];
    if (!isset($motivoPadrao[$operacao])) {
        throw new RegistroInvalido('Operação inválida.');
    }

    $caller = admin_atual($usuarioAdmin);
    if ($caller === null) {
        throw new RegistroInvalido('Acesso restrito a administradores.');
    }
    $opAdmin = in_array($operacao, ['promover', 'rebaixar', 'alterar_perfil'], true);
    if ($opAdmin && !admin_tem_permissao($caller, 'contas_admin')) {
        throw new RegistroInvalido('Apenas um Admin Master pode conceder, alterar ou remover acessos administrativos.');
    }
    if (!$opAdmin && !admin_tem_permissao($caller, 'contas_suporte') && !($operacao === 'bloquear' && admin_tem_permissao($caller, 'denuncias'))) {
        throw new RegistroInvalido('Seu perfil administrativo (' . $caller['perfilNome'] . ') não tem permissão para esta operação.');
    }

    if ($usuarioId === $usuarioAdmin && in_array($operacao, ['bloquear', 'rebaixar', 'alterar_perfil'], true)) {
        throw new RegistroInvalido('Você não pode bloquear, remover nem alterar o perfil administrativo da própria conta.');
    }

    $motivoLimpo = trim($motivo);
    if ($motivoLimpo === '') {
        $motivoLimpo = $motivoPadrao[$operacao];
    } else {
        $tam = mb_strlen($motivoLimpo, 'UTF-8');
        if ($tam < CONTA_ADMIN_MOTIVO_MIN || $tam > CONTA_ADMIN_MOTIVO_MAX) {
            throw new RegistroInvalido('Motivo: use de ' . CONTA_ADMIN_MOTIVO_MIN . ' a ' . CONTA_ADMIN_MOTIVO_MAX . ' caracteres (ou deixe em branco para usar o padrão).');
        }
    }

    $pdo = db();
    $comPerfil = admin_perfis_banco_pronto();
    $perfilNovo = $perfilAdmin !== '' ? trim($perfilAdmin) : ADMIN_PERFIL_PADRAO;
    if (in_array($operacao, ['promover', 'alterar_perfil'], true) && !admin_perfil_valido($perfilNovo)) {
        throw new RegistroInvalido('Perfil administrativo inválido.');
    }
    if ($operacao === 'alterar_perfil' && !$comPerfil) {
        throw new RegistroInvalido('A alteração de perfis administrativos exige a migração 041 no banco de dados.');
    }

    $stmt = $pdo->prepare(
        'SELECT u.usuario_id, u.email, u.status, j.jogador_id
           FROM usuario u
      LEFT JOIN jogador j ON j.usuario_id = u.usuario_id
          WHERE u.usuario_id = ?'
    );
    $stmt->execute([$usuarioId]);
    $u = $stmt->fetch();
    if (!$u) {
        throw new RegistroInvalido('Conta não encontrada.');
    }

    $adminAlvo = conta_admin_alvo_info($pdo, $usuarioId);
    if ($adminAlvo && $adminAlvo['ativo'] && !admin_tem_permissao($caller, 'contas_admin')) {
        throw new RegistroInvalido('Contas administrativas só podem ser gerenciadas por um Admin Master.');
    }
    if ($operacao === 'rebaixar' && (!$adminAlvo || !$adminAlvo['ativo'])) {
        throw new RegistroInvalido('Esta conta não possui acesso administrativo ativo.');
    }
    if ($operacao === 'alterar_perfil') {
        if (!$adminAlvo || !$adminAlvo['ativo']) {
            throw new RegistroInvalido('Promova a conta a administrador antes de definir um perfil.');
        }
        if ($adminAlvo['perfil'] === $perfilNovo) {
            throw new RegistroInvalido('Esta conta já utiliza o perfil ' . admin_perfil_nome($perfilNovo) . '.');
        }
    }

    $jogadorId = $u['jogador_id'] !== null ? (int) $u['jogador_id'] : null;
    $detalhes = ['operacao' => $operacao];
    $retorno = [];

    if ($operacao === 'redefinir_senha') {
        if (!$u['status']) {
            throw new RegistroInvalido('Desbloqueie a conta antes de gerar um link de redefinição de senha.');
        }
        $pedido = criar_redefinicao($u['email'], 'jogador');
        if ($pedido === null) {
            throw new RegistroInvalido('Limite de ' . REDEFINICAO_MAX_POR_HORA . ' links por hora atingido para esta conta. Aguarde antes de gerar outro.');
        }
        require_once ECOCITY_ROOT . '/includes/email.php';
        $link = url_site('redefinir-senha.php?token=' . $pedido['token']);
        $emailEnviado = enviar_email_redefinicao($pedido['email'], $pedido['nome'], $link);
        $detalhes['email_enviado'] = $emailEnviado;   // nunca gravar o token em texto puro
        $retorno['mensagem'] = $emailEnviado
            ? 'Link de redefinição criado e enviado por e-mail (válido por ' . REDEFINICAO_VALIDADE_MIN . ' min).'
            : 'Link temporário gerado abaixo (válido por ' . REDEFINICAO_VALIDADE_MIN . ' min, uso único).';
        $retorno['redefinicao'] = [
            'link'         => $link,
            'expiraEmMin'  => REDEFINICAO_VALIDADE_MIN,
            'emailEnviado' => $emailEnviado,
        ];
        if (contas_admin_banco_pronto()) {
            $pdo->prepare(
                "INSERT INTO ajuste_conta_admin (usuario_id, jogador_id, administrador_id, tipo, delta_xp, delta_credito, detalhes_json, motivo, criado_em)
                 VALUES (?, ?, ?, 'seguranca', 0, 0, ?, ?, NOW())"
            )->execute([
                $usuarioId,
                $jogadorId,
                $adminId,
                json_encode($detalhes, JSON_UNESCAPED_UNICODE),
                $motivoLimpo,
            ]);
        }
        return $retorno;
    }

    $retorno['mensagem'] = c_transacao(static function (PDO $pdo) use (
        $operacao,
        $usuarioId,
        $usuarioAdmin,
        $jogadorId,
        $adminId,
        $comPerfil,
        $perfilNovo,
        $u,
        $motivoLimpo,
        &$detalhes
    ): string {
        $alvo = conta_admin_alvo_info($pdo, $usuarioId);
        $removeMaster = $alvo && $alvo['ativo'] && $alvo['perfil'] === 'master' && (
            $operacao === 'bloquear'
            || $operacao === 'rebaixar'
            || ($operacao === 'alterar_perfil' && $perfilNovo !== 'master')
        );
        if ($comPerfil && $removeMaster) {
            $masters = $pdo->query("SELECT administrador_id FROM administrador WHERE ativo = TRUE AND perfil = 'master' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if (count($masters) <= 1) {
                throw new RegistroInvalido('É obrigatório manter pelo menos um Admin Master ativo no sistema.');
            }
        }

        switch ($operacao) {
            case 'bloquear':
                $pdo->prepare('UPDATE usuario SET status = FALSE, desativada_em = NULL, sessao_versao = sessao_versao + 1 WHERE usuario_id = ?')->execute([$usuarioId]);
                $msg = 'Conta bloqueada e sessões encerradas: não consegue mais entrar.';
                break;

            case 'desbloquear':
                $pdo->prepare('UPDATE usuario SET status = TRUE, desativada_em = NULL WHERE usuario_id = ?')->execute([$usuarioId]);
                $msg = 'Conta desbloqueada.';
                break;

            case 'promover':
                $detalhes['perfil_antes'] = ($alvo && $alvo['ativo']) ? $alvo['perfil'] : null;
                $detalhes['perfil_depois'] = $comPerfil ? $perfilNovo : 'master';
                $detalhes['perfil_nome'] = admin_perfil_nome($detalhes['perfil_depois']);
                if ($comPerfil) {
                    $pdo->prepare(
                        'INSERT INTO administrador (usuario_id, data_cadastro, ativo, perfil) VALUES (?, CURDATE(), TRUE, ?)
                         ON DUPLICATE KEY UPDATE ativo = TRUE, perfil = VALUES(perfil)'
                    )->execute([$usuarioId, $perfilNovo]);
                    $msg = 'Acesso concedido com o perfil ' . admin_perfil_nome($perfilNovo) . '.';
                } else {
                    $pdo->prepare(
                        'INSERT INTO administrador (usuario_id, data_cadastro, ativo) VALUES (?, CURDATE(), TRUE)
                         ON DUPLICATE KEY UPDATE ativo = TRUE'
                    )->execute([$usuarioId]);
                    $msg = 'Conta promovida a administrador.';
                }
                break;

            case 'alterar_perfil':
                $detalhes['perfil_antes'] = $alvo['perfil'] ?? null;
                $detalhes['perfil_depois'] = $perfilNovo;
                $detalhes['perfil_nome'] = admin_perfil_nome($perfilNovo);
                $pdo->prepare('UPDATE administrador SET perfil = ? WHERE usuario_id = ? AND ativo = TRUE')->execute([$perfilNovo, $usuarioId]);
                $msg = 'Perfil administrativo alterado para ' . admin_perfil_nome($perfilNovo) . '.';
                break;

            case 'rebaixar':
                $detalhes['perfil_antes'] = $alvo['perfil'] ?? null;
                $detalhes['perfil_depois'] = null;
                $pdo->prepare('UPDATE administrador SET ativo = FALSE WHERE usuario_id = ?')->execute([$usuarioId]);
                $msg = 'Acesso de administrador removido.';
                break;

            case 'encerrar_sessoes':
                $pdo->prepare('UPDATE usuario SET sessao_versao = sessao_versao + 1 WHERE usuario_id = ?')->execute([$usuarioId]);
                if ($usuarioId === $usuarioAdmin) {
                    session_regenerate_id(true);
                    $_SESSION['sessao_versao'] = situacao_conta($usuarioId, true)['versao'];
                    $msg = 'Outras sessões da sua conta foram encerradas (esta sessão foi mantida).';
                } else {
                    $msg = 'Sessões encerradas: a conta precisará entrar novamente em todos os aparelhos.';
                }
                break;

            case 'liberar_login':
                $pdo->prepare('DELETE FROM tentativa_login WHERE email = ? AND sucesso = FALSE AND data > NOW() - INTERVAL ? MINUTE')
                    ->execute([$u['email'], LOGIN_JANELA_MIN]);
                $msg = 'Tentativas recentes com falha removidas: o login por e-mail está liberado.';
                break;

            default:
                throw new RegistroInvalido('Operação inválida.');
        }

        if (contas_admin_banco_pronto()) {
            $pdo->prepare(
                "INSERT INTO ajuste_conta_admin (usuario_id, jogador_id, administrador_id, tipo, delta_xp, delta_credito, detalhes_json, motivo, criado_em)
                 VALUES (?, ?, ?, 'seguranca', 0, 0, ?, ?, NOW())"
            )->execute([
                $usuarioId,
                $jogadorId,
                $adminId,
                json_encode($detalhes, JSON_UNESCAPED_UNICODE),
                $motivoLimpo,
            ]);
        }
        return $msg;
    });

    return $retorno;
}
