<?php
/**
 * Administração: perfis de acesso (RN102), permissões, fila de aprovação e visão geral do sistema.
 *
 * Regras:
 *  - Administrador = usuário com linha em `administrador` e ativo = TRUE (RN20 / RN102).
 *  - Cada perfil (`master`, `moderador`, `editor`, `parceiros`, `analista`) acessa apenas as áreas
 *    respectivas à sua função, com validação estrita no PHP.
 *  - Só registros "com evidência" pendentes passam pela fila.
 *  - Rejeição exige motivo (até 250 caracteres).
 *  - Administrador não decide os próprios registros.
 */
declare(strict_types=1);

const DECISOES_TAMANHOS = [10, 20, 50];
const DECISOES_SITUACOES = ['todas', 'aprovado', 'rejeitado'];
const MOTIVO_MAX = 250;

const ADMIN_PERFIL_PADRAO = 'moderador';
const ADMIN_PERFIS = [
    'master' => [
        'nome'       => 'Admin Master',
        'resumo'     => 'Acesso total: todas as abas, códigos promocionais, gestão completa de contas, perfis de administradores e auditoria.',
        'permissoes' => ['contestacoes', 'visao_geral', 'conteudo_catalogo', 'conteudo_promocoes', 'comunidades', 'contas_suporte', 'contas_progressao', 'contas_admin', 'auditoria', 'denuncias', 'parceiros'],
    ],
    'moderador' => [
        'nome'       => 'Moderador',
        'resumo'     => 'Contestações, fila de fotos, denúncias, encerramento de comunidades e suporte a jogadores comuns (sem alterar XP/créditos nem contas admin).',
        'permissoes' => ['contestacoes', 'denuncias', 'comunidades', 'contas_suporte'],
    ],
    'editor' => [
        'nome'       => 'Editor de Conteúdo',
        'resumo'     => 'Catálogo pedagógico (atividades, quizzes, missões, conquistas, estruturas e missões de comunidade, incluindo IA) e leitura da Visão Geral.',
        'permissoes' => ['conteudo_catalogo', 'visao_geral'],
    ],
    'parceiros' => [
        'nome'       => 'Gestor de Parceiros',
        'resumo'     => 'Aprovação, bloqueio, atividades autorizadas e pedidos dos estabelecimentos parceiros.',
        'permissoes' => ['parceiros'],
    ],
    'analista' => [
        'nome'       => 'Analista / Auditor',
        'resumo'     => 'Somente leitura: Visão Geral (estatísticas agregadas + CSV) e Auditoria detalhada do banco (+ CSV).',
        'permissoes' => ['visao_geral', 'auditoria'],
    ],
];

/** Migração 041 (coluna `administrador.perfil`) já aplicada neste banco? */
function admin_perfis_banco_pronto(): bool
{
    return schema_flag('admin_perfis', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'administrador' AND COLUMN_NAME = 'perfil'"
    )->fetchColumn());
}

function admin_perfil_valido(string $perfil): bool
{
    return isset(ADMIN_PERFIS[$perfil]);
}

function admin_perfil_nome(?string $perfil): string
{
    return $perfil !== null && isset(ADMIN_PERFIS[$perfil]) ? ADMIN_PERFIS[$perfil]['nome'] : 'Administrador';
}

/** Lista ordenada de perfis para selects e quadro informativo na UI. */
function admin_catalogo_perfis(): array
{
    $out = [];
    foreach (ADMIN_PERFIS as $chave => $info) {
        $out[] = ['chave' => $chave, 'nome' => $info['nome'], 'resumo' => $info['resumo']];
    }
    return $out;
}

/**
 * Dados do administrador ativo para `$usuarioId` (ou null).
 * @return array{usuarioId: int, adminId: int, perfil: string, perfilNome: string, perfilResumo: string, permissoes: string[]}|null
 */
function admin_atual(int $usuarioId): ?array
{
    if ($usuarioId <= 0) {
        return null;
    }
    $comPerfil = admin_perfis_banco_pronto();
    $sql = $comPerfil
        ? 'SELECT administrador_id, perfil FROM administrador WHERE usuario_id = ? AND ativo = TRUE LIMIT 1'
        : 'SELECT administrador_id FROM administrador WHERE usuario_id = ? AND ativo = TRUE LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$usuarioId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $perfil = $comPerfil ? (string) ($row['perfil'] ?? ADMIN_PERFIL_PADRAO) : 'master';
    if (!isset(ADMIN_PERFIS[$perfil])) {
        $perfil = ADMIN_PERFIL_PADRAO;
    }
    $info = ADMIN_PERFIS[$perfil];
    return [
        'usuarioId'    => $usuarioId,
        'adminId'      => (int) $row['administrador_id'],
        'perfil'       => $perfil,
        'perfilNome'   => $info['nome'],
        'perfilResumo' => $info['resumo'],
        'permissoes'   => $info['permissoes'],
    ];
}

function administrador_id(int $usuarioId): ?int
{
    return admin_atual($usuarioId)['adminId'] ?? null;
}

function admin_tem_permissao(int|array $adminOuUsuarioId, string $permissao): bool
{
    $admin = is_array($adminOuUsuarioId) ? $adminOuUsuarioId : admin_atual($adminOuUsuarioId);
    return $admin !== null && in_array($permissao, $admin['permissoes'] ?? [], true);
}

/** Subabas ordenadas da aba Conteúdo liberadas para este administrador. */
function admin_secoes_conteudo_permitidas(array $admin): array
{
    $perms = $admin['permissoes'] ?? [];
    $tem = static fn(string $p): bool => in_array($p, $perms, true);
    $sec = [];
    if ($tem('conteudo_catalogo')) {
        array_push($sec, 'atividade', 'quiz', 'missao', 'conquista', 'construcao', 'cmissao');
    }
    if ($tem('conteudo_promocoes')) {
        $sec[] = 'promocao';
    }
    if ($tem('comunidades')) {
        $sec[] = 'comunidade';
    }
    if ($tem('contas_suporte') || $tem('contas_progressao') || $tem('contas_admin')) {
        $sec[] = 'conta';
    }
    if ($tem('auditoria')) {
        $sec[] = 'auditoria';
    }
    return $sec;
}

/** Abas principais ordenadas da página `admin.php` liberadas para este administrador. */
function admin_abas_permitidas(array $admin): array
{
    $perms = $admin['permissoes'] ?? [];
    $tem = static fn(string $p): bool => in_array($p, $perms, true);
    $abas = [];
    if ($tem('visao_geral')) {
        $abas[] = 'visao';
    }
    if ($tem('contestacoes')) {
        $abas[] = 'aprovacoes';
    }
    if ($tem('denuncias')) {
        $abas[] = 'denuncias';
    }
    if ($tem('parceiros')) {
        $abas[] = 'parceiros';
    }
    if ($tem('comunidades')) {
        $abas[] = 'comunidade';
    }
    if ($tem('conteudo_catalogo')) {
        $abas[] = 'catalogo';
    }
    if ($tem('conteudo_promocoes')) {
        $abas[] = 'promocao';
    }
    if ($tem('contas_suporte') || $tem('contas_progressao') || $tem('contas_admin')) {
        $abas[] = 'conta';
    }
    if ($tem('auditoria')) {
        $abas[] = 'auditoria';
    }
    return $abas;
}

/** Exige login de administrador ativo (e permissão opcional); caso contrário, 403. */
function exigir_admin(?string $permissao = null): array
{
    $usuarioId = exigir_login();
    $admin = admin_atual($usuarioId);
    if ($admin === null) {
        http_response_code(403);
        exit('Acesso restrito a administradores.');
    }
    if ($permissao !== null && !admin_tem_permissao($admin, $permissao)) {
        http_response_code(403);
        exit('Seu perfil administrativo não tem permissão para esta área.');
    }
    return $admin;
}

/** Exige sessão e perfil de administrador ativo em endpoints JSON; retorna 401 ou 403. */
function exigir_admin_api(?string $permissao = null): array
{
    $usuarioId = usuario_id();
    if ($usuarioId === null) {
        json_resposta(['ok' => false, 'erro' => 'Sua sessão terminou. Entre novamente.'], 401);
    }
    $admin = admin_atual($usuarioId);
    if ($admin === null) {
        json_resposta(['ok' => false, 'erro' => 'Acesso restrito a administradores.'], 403);
    }
    if ($permissao !== null && !admin_tem_permissao($admin, $permissao)) {
        json_resposta([
            'ok'   => false,
            'erro' => 'Seu perfil administrativo (' . $admin['perfilNome'] . ') não tem permissão para esta operação.',
        ], 403);
    }
    return $admin;
}

/* ---------------------------------------------------------------------------
   Fila de aprovação
   --------------------------------------------------------------------------- */

function listar_pendentes(int $usuarioAdmin): array
{
    // View vw_fila_aprovacao (migração 025): registros com foto pendentes, com jogador, cidade e evidência
    $stmt = db()->query('SELECT * FROM vw_fila_aprovacao ORDER BY data_registro ASC');
    $linhas = $stmt->fetchAll();
    $ia = ia_banco_pronto() ? ia_pareceres(array_column($linhas, 'id')) : [];
    $prazos = [];
    if ($ia) {
        $ids = array_keys($ia);
        $p = db()->prepare('SELECT registro_atividade_id, prazo_aprovacao FROM registro_atividade WHERE registro_atividade_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $p->execute($ids);
        $prazos = $p->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    return array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'atividade'   => $r['atividade'],
        'categoria'   => INDICADOR_CHAVE[(int) $r['indicador_ambiental_id']] ?? '',
        'categoriaNome' => $r['categoria_nome'],
        'jogador'     => $r['apelido'],
        'cidade'      => $r['cidade'],
        'descricao'   => $r['descricao'],
        'temFoto'     => $r['arquivo'] !== null,
        'enviadoEm'   => strtotime($r['data_registro']),
        'prazo'       => !empty($prazos[(int) $r['id']]) ? strtotime($prazos[(int) $r['id']]) : strtotime($r['data_registro']) + PRAZO_APROVACAO_HORAS * 3600,
        'proprio'     => (int) $r['usuario_id'] === $usuarioAdmin,
        'ia'          => $ia[(int) $r['id']] ?? null,   // parecer da IA (RN91)
    ], $linhas);
}

/**
 * Histórico de decisões (registros com foto já decididos), do mais recente ao mais antigo.
 * @return array{itens: array, total: int, pagina: int, porPagina: int, situacao: string, contagem: array}
 */
function listar_decisoes(int $pagina = 1, int $porPagina = 20, string $situacao = 'todas'): array
{
    if ($pagina < 1) throw new RegistroInvalido('Página inválida.');
    if (!in_array($porPagina, DECISOES_TAMANHOS, true)) throw new RegistroInvalido('Quantidade por página inválida.');
    if (!in_array($situacao, DECISOES_SITUACOES, true)) throw new RegistroInvalido('Situação inválida.');

    $pdo = db();
    $c = $pdo->prepare("SELECT status, COUNT(*) FROM registro_atividade
                         WHERE status <> 'pendente' AND tipo_verificacao_id = ? GROUP BY status");
    $c->execute([TIPO_EVIDENCIA]);
    $por = array_map('intval', $c->fetchAll(PDO::FETCH_KEY_PAIR));
    $contagem = ['aprovado' => $por['aprovado'] ?? 0, 'rejeitado' => $por['rejeitado'] ?? 0];
    $contagem['todas'] = $contagem['aprovado'] + $contagem['rejeitado'];

    $total = $contagem[$situacao];
    $pagina = min($pagina, max(1, (int) ceil($total / $porPagina)));
    $filtro = $situacao === 'todas' ? "r.status <> 'pendente'" : 'r.status = ?';
    $params = $situacao === 'todas' ? [TIPO_EVIDENCIA] : [TIPO_EVIDENCIA, $situacao];
    $offset = ($pagina - 1) * $porPagina;

    $stmt = $pdo->prepare(
        "SELECT r.registro_atividade_id AS id, r.status, r.data_aprovacao, r.motivo_rejeicao,
                a.nome AS atividade, j.apelido, u.nome AS admin_nome, " . (ia_banco_pronto() ? 'ae.acao' : 'NULL') . " AS ia_acao
           FROM registro_atividade r
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
           JOIN jogador j ON j.jogador_id = r.jogador_id
      LEFT JOIN administrador ad ON ad.administrador_id = r.aprovado_por_administrador_id
      LEFT JOIN usuario u ON u.usuario_id = ad.usuario_id
      " . (ia_banco_pronto() ? 'LEFT JOIN analise_evidencia ae ON ae.registro_atividade_id = r.registro_atividade_id' : '') . "
          WHERE r.tipo_verificacao_id = ? AND {$filtro}
       ORDER BY r.data_aprovacao DESC, r.registro_atividade_id DESC
          LIMIT {$porPagina} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $itens = array_map(static fn(array $d): array => [
        'id'        => (int) $d['id'],
        'atividade' => $d['atividade'],
        'jogador'   => $d['apelido'],
        'status'    => $d['status'],
        'admin'     => $d['admin_nome'],
        'ia'        => $d['admin_nome'] === null && (($d['ia_acao'] === 'aprovou' && $d['status'] === 'aprovado') || ($d['ia_acao'] === 'rejeitou' && $d['status'] === 'rejeitado')),
        'quando'    => $d['data_aprovacao'] ? date('d/m/Y H:i', strtotime($d['data_aprovacao'])) : null,
        'motivo'    => $d['motivo_rejeicao'],
    ], $stmt->fetchAll());

    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'porPagina' => $porPagina, 'situacao' => $situacao, 'contagem' => $contagem];
}

/**
 * Aprova ou rejeita um registro com evidência pendente.
 * @return string mensagem de resultado
 * @throws RegistroInvalido
 */
function decidir_registro(int $adminId, int $usuarioAdmin, int $registroId, string $acao, string $motivo): string
{
    if (!in_array($acao, ['aprovar', 'rejeitar'], true)) {
        throw new RegistroInvalido('Ação inválida.');
    }
    $stmt = db()->prepare(
        'SELECT r.status, r.tipo_verificacao_id, j.usuario_id FROM registro_atividade r
           JOIN jogador j ON j.jogador_id = r.jogador_id WHERE r.registro_atividade_id = ?'
    );
    $stmt->execute([$registroId]);
    $r = $stmt->fetch();

    if (!$r || (int) $r['tipo_verificacao_id'] !== TIPO_EVIDENCIA) {
        throw new RegistroInvalido('Registro não encontrado.');
    }
    if ((int) $r['usuario_id'] === $usuarioAdmin) {
        throw new RegistroInvalido('Você não pode decidir sobre os seus próprios registros.');
    }
    if ($acao === 'rejeitar') {
        $motivo = trim($motivo);
        if ($motivo === '') throw new RegistroInvalido('Informe o motivo da rejeição.');
        if (mb_strlen($motivo, 'UTF-8') > MOTIVO_MAX) throw new RegistroInvalido('O motivo deve ter no máximo ' . MOTIVO_MAX . ' caracteres.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $feito = $acao === 'aprovar'
            ? aprovar_registro($pdo, $registroId, $adminId)
            : rejeitar_registro($pdo, $registroId, $motivo, $adminId);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    if (!$feito) return 'Este registro já tinha sido decidido.';
    return $acao === 'aprovar' ? 'Registro aprovado.' : 'Registro rejeitado.';
}

/* ---------------------------------------------------------------------------
   Visão geral do sistema
   --------------------------------------------------------------------------- */

function estatisticas_gerais(): array
{
    $pdo = db();
    $um = static fn(string $sql) => $pdo->query($sql)->fetchColumn();

    // Nível de cada cidade pela média dos indicadores (RN22)
    $cidadesPorNivel = $pdo->query(   // view vw_cidade_progresso (migração 025)
        'SELECT n.nivel, n.nome, COUNT(x.cidade_id) AS total
           FROM nivel_cidade n LEFT JOIN vw_cidade_progresso x ON x.nivel_calculado = n.nivel
       GROUP BY n.nivel, n.nome ORDER BY n.nivel'
    )->fetchAll();

    $porAtividade = $pdo->query('SELECT * FROM vw_atividade_resumo ORDER BY atividade_sustentavel_id')->fetchAll();   // view (migração 025)

    // Impacto real estimado agrupado por unidade (só atividades com valor definido)
    $impactoReal = [];
    foreach ($porAtividade as &$a) {
        $a['aprovados'] = (int) $a['aprovados'];
        $a['categoria'] = INDICADOR_CHAVE[(int) $a['indicador_ambiental_id']] ?? '';
        // Soma por registro: quantidade informada pelo parceiro ou o valor fixo da atividade (RN73)
        $a['impacto_total'] = $a['impacto_real_quantidade'] !== null ? (float) $a['impacto_soma'] : null;
        if ($a['impacto_total'] !== null && $a['impacto_real_unidade']) {
            $impactoReal[$a['impacto_real_unidade']] = ($impactoReal[$a['impacto_real_unidade']] ?? 0) + $a['impacto_total'];
        }
    }
    unset($a);

    $mediaIndicadores = $pdo->query(
        'SELECT ia.indicador_ambiental_id, ia.nome, ROUND(AVG(ci.valor)) AS media
           FROM indicador_ambiental ia LEFT JOIN cidade_indicador ci ON ci.indicador_ambiental_id = ia.indicador_ambiental_id
       GROUP BY ia.indicador_ambiental_id, ia.nome ORDER BY ia.indicador_ambiental_id'
    )->fetchAll();

    $cidadesPorNivel = array_map(static fn(array $n): array => array_merge($n, ['total' => (int) $n['total']]), $cidadesPorNivel);

    return [
        'jogadores'        => (int) $um('SELECT COUNT(*) FROM jogador'),
        'registros'        => $pdo->query("SELECT status, COUNT(*) AS total FROM registro_atividade GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR),
        'xpDistribuido'    => (int) $um('SELECT COALESCE(SUM(quantidade), 0) FROM historico_xp WHERE quantidade > 0'),
        'creditosDistrib'  => (int) $um('SELECT COALESCE(SUM(quantidade), 0) FROM historico_credito_verde WHERE quantidade > 0'),
        'cidadesPorNivel'  => $cidadesPorNivel,
        'porAtividade'     => $porAtividade,
        'impactoReal'      => $impactoReal,
        'mediaIndicadores' => $mediaIndicadores,
    ];
}
