<?php
/**
 * Gestão do estabelecimento parceiro (RN95, migração 031): funcionários, atendimentos,
 * relatório, dados do estabelecimento e pedidos de atividade nova.
 *
 *  - Só o administrador do estabelecimento (a conta de e-mail) usa estas funções; a API recusa
 *    funcionários (403). A exceção é a troca da própria senha do funcionário.
 *  - Funcionário: usuário único no sistema (3–30: a–z, 0–9, . _ -), senha de 6 a 72, até 30 ativos
 *    por estabelecimento; desativar ou redefinir a senha derruba a sessão aberta; não há exclusão.
 *  - Pedido de atividade: a administração do EcoCity aprova (a atividade passa a ser autorizada)
 *    ou recusa com motivo; um pendente por atividade.
 *  - O parceiro vê o apelido dos jogadores atendidos (como antes); o relatório e o CSV só têm números.
 */
declare(strict_types=1);

const PARCEIRO_FUNCIONARIOS_MAX = 30;      // ativos por estabelecimento (provisório)
const PARCEIRO_ATENDIMENTOS_TAMANHOS = [10, 20, 50];
const PARCEIRO_SITUACOES = ['todas', 'usado', 'aguardando', 'vencido', 'cancelado'];
const PARCEIRO_PERIODOS = [0, 7, 30, 90];

/** Situação de um código (mesma regra de fn_situacao_codigo_parceiro em SQL e no PHP, compatível com qualquer collation de conexão). */
const SQL_SITUACAO_CODIGO = "CASE WHEN k.usado_em IS NOT NULL THEN 'usado' WHEN k.cancelado THEN 'cancelado'
                                  WHEN k.expira_em <= NOW() THEN 'vencido' ELSE 'aguardando' END";

function exigir_funcionarios_pronto(): void
{
    if (!parceiro_funcionarios_pronto()) throw new RegistroInvalido('Os funcionários ainda não estão disponíveis.');
}

/** Valida a senha nova (mesmas regras do cadastro). */
function validar_senha_nova(string $senha, ?string $confirmar = null): void
{
    if (strlen($senha) < 6 || strlen($senha) > 72) throw new RegistroInvalido('A senha deve ter de 6 a 72 caracteres.');
    if ($confirmar !== null && $senha !== $confirmar) throw new RegistroInvalido('As senhas não conferem.');
}

/* ---------------------------------------------------------------------------
   Funcionários
   --------------------------------------------------------------------------- */

function listar_funcionarios(int $parceiroId): array
{
    if (!parceiro_funcionarios_pronto()) return [];
    $s = db()->prepare("SELECT f.funcionario_id, f.nome, f.usuario, f.ativo, f.criado_em, f.ultimo_acesso,
                               (SELECT COUNT(*) FROM codigo_parceiro k WHERE k.funcionario_id = f.funcionario_id
                                   AND k.usado_em >= NOW() - INTERVAL 30 DAY) AS atendimentos30
                          FROM parceiro_funcionario f WHERE f.parceiro_id = ? ORDER BY f.ativo DESC, f.nome");
    $s->execute([$parceiroId]);
    return array_map(static fn($f) => [
        'id' => (int) $f['funcionario_id'], 'nome' => $f['nome'], 'usuario' => $f['usuario'], 'ativo' => (bool) $f['ativo'],
        'criadoEm' => $f['criado_em'], 'ultimoAcesso' => $f['ultimo_acesso'], 'atendimentos30' => (int) $f['atendimentos30'],
    ], $s->fetchAll());
}

function contar_funcionarios_ativos(PDO $pdo, int $parceiroId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM parceiro_funcionario WHERE parceiro_id = ? AND ativo = TRUE');
    $s->execute([$parceiroId]);
    return (int) $s->fetchColumn();
}

function criar_funcionario(int $parceiroId, string $nome, string $usuario, string $senha): string
{
    exigir_funcionarios_pronto();
    $nome = trim($nome);
    $usuario = strtolower(trim($usuario));
    $n = mb_strlen($nome, 'UTF-8');
    if ($n < 3 || $n > 80) throw new RegistroInvalido('Nome: use de 3 a 80 caracteres.');
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $usuario)) {
        throw new RegistroInvalido('Usuário: de 3 a 30 caracteres, só letras minúsculas sem acento, números, ponto, hífen ou sublinhado.');
    }
    validar_senha_nova($senha);
    try {
        return c_transacao(static function (PDO $pdo) use ($parceiroId, $nome, $usuario, $senha): string {
            $pdo->prepare('SELECT parceiro_id FROM parceiro WHERE parceiro_id = ? FOR UPDATE')->execute([$parceiroId]);
            if (contar_funcionarios_ativos($pdo, $parceiroId) >= PARCEIRO_FUNCIONARIOS_MAX) {
                throw new RegistroInvalido('O estabelecimento já tem ' . PARCEIRO_FUNCIONARIOS_MAX . ' funcionários ativos. Desative algum antes.');
            }
            $pdo->prepare('INSERT INTO parceiro_funcionario (parceiro_id, nome, usuario, senha, ativo, criado_em) VALUES (?, ?, ?, ?, TRUE, NOW())')
                ->execute([$parceiroId, $nome, $usuario, password_hash($senha, PASSWORD_DEFAULT)]);
            return "Acesso de {$nome} criado: entra com o usuário \"{$usuario}\" e a senha que você definiu.";
        });
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') throw new RegistroInvalido('Este usuário já existe. Escolha outro.');
        throw $ex;
    }
}

/** operacao: desativar | reativar | senha (redefine; derruba a sessão aberta). */
function alterar_funcionario(int $parceiroId, int $funcionarioId, string $operacao, string $senha = ''): string
{
    exigir_funcionarios_pronto();
    return c_transacao(static function (PDO $pdo) use ($parceiroId, $funcionarioId, $operacao, $senha): string {
        $s = $pdo->prepare('SELECT nome, ativo FROM parceiro_funcionario WHERE funcionario_id = ? AND parceiro_id = ? FOR UPDATE');
        $s->execute([$funcionarioId, $parceiroId]);
        $f = $s->fetch();
        if (!$f) throw new RegistroInvalido('Funcionário não encontrado.');
        switch ($operacao) {
            case 'desativar':
                if (!$f['ativo']) throw new RegistroInvalido('Este funcionário já está desativado.');
                $pdo->prepare('UPDATE parceiro_funcionario SET ativo = FALSE, sessao_versao = sessao_versao + 1 WHERE funcionario_id = ?')->execute([$funcionarioId]);
                return "Acesso de {$f['nome']} desativado: a pessoa sai na hora e não consegue mais entrar.";
            case 'reativar':
                if ($f['ativo']) throw new RegistroInvalido('Este funcionário já está ativo.');
                if (contar_funcionarios_ativos($pdo, $parceiroId) >= PARCEIRO_FUNCIONARIOS_MAX) {
                    throw new RegistroInvalido('O estabelecimento já tem ' . PARCEIRO_FUNCIONARIOS_MAX . ' funcionários ativos.');
                }
                $pdo->prepare('UPDATE parceiro_funcionario SET ativo = TRUE WHERE funcionario_id = ?')->execute([$funcionarioId]);
                return "Acesso de {$f['nome']} reativado.";
            case 'senha':
                validar_senha_nova($senha);
                $pdo->prepare('UPDATE parceiro_funcionario SET senha = ?, sessao_versao = sessao_versao + 1 WHERE funcionario_id = ?')
                    ->execute([password_hash($senha, PASSWORD_DEFAULT), $funcionarioId]);
                return "Senha de {$f['nome']} redefinida. Passe a nova senha para a pessoa.";
            default:
                throw new RegistroInvalido('Ação inválida.');
        }
    });
}

/** O próprio funcionário troca a senha (exige a atual); a sessão atual continua. */
function trocar_senha_funcionario(int $funcionarioId, string $atual, string $nova, string $confirmar): string
{
    exigir_funcionarios_pronto();
    $s = db()->prepare('SELECT senha FROM parceiro_funcionario WHERE funcionario_id = ?');
    $s->execute([$funcionarioId]);
    $hash = $s->fetchColumn();
    if (!$hash || !password_verify($atual, $hash)) throw new RegistroInvalido('A senha atual está incorreta.');
    validar_senha_nova($nova, $confirmar);
    if (password_verify($nova, $hash)) throw new RegistroInvalido('A nova senha deve ser diferente da atual.');
    db()->prepare('UPDATE parceiro_funcionario SET senha = ? WHERE funcionario_id = ?')->execute([password_hash($nova, PASSWORD_DEFAULT), $funcionarioId]);
    return 'Senha alterada.';
}

/* ---------------------------------------------------------------------------
   Atendimentos (histórico completo, paginado no servidor)
   --------------------------------------------------------------------------- */

/**
 * @param array{funcionario?: string, situacao?: string, atividade?: int, dias?: int} $f
 *        funcionario: 'todos' | 'admin' | id do funcionário
 */
function atendimentos_parceiro(int $parceiroId, array $f, int $pagina = 1, int $porPagina = 20): array
{
    if ($pagina < 1) throw new RegistroInvalido('Página inválida.');
    if (!in_array($porPagina, PARCEIRO_ATENDIMENTOS_TAMANHOS, true)) throw new RegistroInvalido('Quantidade por página inválida.');
    $situacao = (string) ($f['situacao'] ?? 'todas');
    if (!in_array($situacao, PARCEIRO_SITUACOES, true)) throw new RegistroInvalido('Situação inválida.');
    $dias = (int) ($f['dias'] ?? 0);
    if (!in_array($dias, PARCEIRO_PERIODOS, true)) throw new RegistroInvalido('Período inválido.');
    $funcionario = (string) ($f['funcionario'] ?? 'todos');
    $atividade = (int) ($f['atividade'] ?? 0);

    $onde = ['k.parceiro_id = ?'];
    $params = [$parceiroId];
    if ($funcionario === 'admin') {
        $onde[] = 'k.funcionario_id IS NULL';
    } elseif ($funcionario !== 'todos') {
        if (!ctype_digit($funcionario)) throw new RegistroInvalido('Funcionário inválido.');
        $onde[] = 'k.funcionario_id = ?';
        $params[] = (int) $funcionario;
    }
    if ($atividade > 0) { $onde[] = 'k.atividade_sustentavel_id = ?'; $params[] = $atividade; }
    if ($dias > 0) $onde[] = 'k.criado_em >= CURDATE() - INTERVAL ' . ($dias - 1) . ' DAY';
    $base = implode(' AND ', $onde);

    $pdo = db();
    $c = $pdo->prepare('SELECT ' . SQL_SITUACAO_CODIGO . " AS sit, COUNT(*) FROM codigo_parceiro k WHERE $base GROUP BY sit");
    $c->execute($params);
    $por = array_map('intval', $c->fetchAll(PDO::FETCH_KEY_PAIR));
    $contagem = ['todas' => array_sum($por)] + array_fill_keys(array_slice(PARCEIRO_SITUACOES, 1), 0);
    foreach ($por as $k => $v) $contagem[$k] = $v;

    $total = $contagem[$situacao];
    $pagina = min($pagina, max(1, (int) ceil($total / $porPagina)));
    $filtro = $situacao === 'todas' ? '' : ' AND ' . SQL_SITUACAO_CODIGO . ' = ' . $pdo->quote($situacao);
    $offset = ($pagina - 1) * $porPagina;
    $s = $pdo->prepare(sql_codigo() . " WHERE $base$filtro ORDER BY k.criado_em DESC, k.codigo_parceiro_id DESC LIMIT $porPagina OFFSET $offset");
    $s->execute($params);
    $itens = array_map(static fn($r) => formatar_codigo($r) + ['situacao' => situacao_codigo_parceiro($r)], $s->fetchAll());

    return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'porPagina' => $porPagina, 'situacao' => $situacao, 'contagem' => $contagem];
}

function situacao_codigo_parceiro(array $c): string
{
    if ($c['usado_em'] !== null) return 'usado';
    if ($c['cancelado']) return 'cancelado';
    return strtotime($c['expira_em']) <= time() ? 'vencido' : 'aguardando';
}

/* ---------------------------------------------------------------------------
   Relatório
   --------------------------------------------------------------------------- */

function relatorio_parceiro(int $parceiroId, int $dias): array
{
    if (!in_array($dias, PARCEIRO_PERIODOS, true)) throw new RegistroInvalido('Período inválido.');
    $pdo = db();
    $periodo = $dias > 0 ? ' AND k.criado_em >= CURDATE() - INTERVAL ' . ($dias - 1) . ' DAY' : '';
    $uso = $dias > 0 ? ' AND k.usado_em >= CURDATE() - INTERVAL ' . ($dias - 1) . ' DAY' : '';
    $q = static function (string $sql) use ($pdo, $parceiroId) { $s = $pdo->prepare($sql); $s->execute([$parceiroId]); return $s; };

    $r = $q('SELECT COUNT(*) AS gerados, SUM(' . SQL_SITUACAO_CODIGO . " = 'usado') AS usados,
                    SUM(" . SQL_SITUACAO_CODIGO . " = 'vencido') AS vencidos, SUM(" . SQL_SITUACAO_CODIGO . " = 'cancelado') AS cancelados
               FROM codigo_parceiro k WHERE k.parceiro_id = ?$periodo")->fetch();
    $jog = $q("SELECT COUNT(*) AS jogadores, SUM(n >= 2) AS voltaram FROM
                 (SELECT k.jogador_id, COUNT(*) AS n FROM codigo_parceiro k WHERE k.parceiro_id = ? AND k.usado_em IS NOT NULL$uso GROUP BY k.jogador_id) x")->fetch();
    $resumo = [
        'gerados' => (int) $r['gerados'], 'usados' => (int) $r['usados'], 'vencidos' => (int) $r['vencidos'], 'cancelados' => (int) $r['cancelados'],
        'taxaUso' => (int) $r['gerados'] ? round(100 * (int) $r['usados'] / (int) $r['gerados'], 1) : null,
        'jogadores' => (int) $jog['jogadores'], 'voltaram' => (int) $jog['voltaram'],
    ];
    $anterior = null;
    if ($dias > 0) {
        $iniAnt = 2 * $dias - 1;
        $fimAnt = $dias - 1;
        $perAnt = " AND k.criado_em >= CURDATE() - INTERVAL $iniAnt DAY AND k.criado_em < CURDATE() - INTERVAL $fimAnt DAY";
        $usoAnt = " AND k.usado_em >= CURDATE() - INTERVAL $iniAnt DAY AND k.usado_em < CURDATE() - INTERVAL $fimAnt DAY";
        $rAnt = $q('SELECT COUNT(*) AS gerados, SUM(' . SQL_SITUACAO_CODIGO . " = 'usado') AS usados,
                           SUM(" . SQL_SITUACAO_CODIGO . " = 'vencido') AS vencidos, SUM(" . SQL_SITUACAO_CODIGO . " = 'cancelado') AS cancelados
                      FROM codigo_parceiro k WHERE k.parceiro_id = ?$perAnt")->fetch();
        $jogAnt = $q("SELECT COUNT(DISTINCT k.jogador_id) FROM codigo_parceiro k WHERE k.parceiro_id = ? AND k.usado_em IS NOT NULL$usoAnt")->fetchColumn();
        $anterior = [
            'gerados' => (int) $rAnt['gerados'], 'usados' => (int) $rAnt['usados'],
            'vencidos' => (int) $rAnt['vencidos'], 'cancelados' => (int) $rAnt['cancelados'],
            'taxaUso' => (int) $rAnt['gerados'] ? round(100 * (int) $rAnt['usados'] / (int) $rAnt['gerados'], 1) : null,
            'jogadores' => (int) $jogAnt,
        ];
    }
    $porAtividade = array_map(static fn($t) => ['atividade' => $t['nome'], 'unidade' => $t['unidade'], 'verificacoes' => (int) $t['n'], 'total' => (float) $t['total']],
        $q("SELECT a.nome, a.impacto_real_unidade AS unidade, COUNT(*) AS n, SUM(k.quantidade) AS total
              FROM codigo_parceiro k JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
             WHERE k.parceiro_id = ? AND k.usado_em IS NOT NULL$uso GROUP BY a.atividade_sustentavel_id ORDER BY n DESC")->fetchAll());
    $nomeAtendente = parceiro_funcionarios_pronto() ? "COALESCE(f.nome, 'Administrador')" : "'Administrador'";
    $joinFunc = parceiro_funcionarios_pronto() ? 'LEFT JOIN parceiro_funcionario f ON f.funcionario_id = k.funcionario_id' : '';
    $porFuncionario = array_map(static fn($t) => ['atendente' => $t['atendente'], 'gerados' => (int) $t['gerados'], 'usados' => (int) $t['usados'],
        'vencidos' => (int) $t['vencidos'], 'cancelados' => (int) $t['cancelados'], 'jogadores' => (int) $t['jogadores']],
        $q("SELECT $nomeAtendente AS atendente, COUNT(*) AS gerados, SUM(" . SQL_SITUACAO_CODIGO . " = 'usado') AS usados,
                   SUM(" . SQL_SITUACAO_CODIGO . " = 'vencido') AS vencidos, SUM(" . SQL_SITUACAO_CODIGO . " = 'cancelado') AS cancelados,
                   COUNT(DISTINCT k.jogador_id) AS jogadores
              FROM codigo_parceiro k $joinFunc WHERE k.parceiro_id = ?$periodo
          GROUP BY k.funcionario_id, atendente ORDER BY usados DESC, atendente")->fetchAll());

    // Por dia (até 30 dias no gráfico) e mapa de calor dia da semana × hora
    $diasGrafico = $dias > 0 ? min($dias, 30) : 30;
    $porDiaBanco = $q('SELECT DATE(k.usado_em) AS d, COUNT(*) AS n FROM codigo_parceiro k
                        WHERE k.parceiro_id = ? AND k.usado_em >= CURDATE() - INTERVAL ' . ($diasGrafico - 1) . ' DAY GROUP BY d')->fetchAll(PDO::FETCH_KEY_PAIR);
    $porDia = [];
    for ($i = $diasGrafico - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $porDia[] = ['data' => $d, 'n' => (int) ($porDiaBanco[$d] ?? 0)];
    }
    $calor = array_fill(0, 7, array_fill(0, 24, 0));
    foreach ($q("SELECT WEEKDAY(k.usado_em) AS d, HOUR(k.usado_em) AS h, COUNT(*) AS n FROM codigo_parceiro k
                  WHERE k.parceiro_id = ? AND k.usado_em IS NOT NULL$uso GROUP BY d, h")->fetchAll() as $c) {
        $calor[(int) $c['d']][(int) $c['h']] = (int) $c['n'];
    }
    return ['dias' => $dias, 'resumo' => $resumo, 'anterior' => $anterior, 'porAtividade' => $porAtividade, 'porFuncionario' => $porFuncionario,
            'porDia' => $porDia, 'calor' => $calor, 'gerado' => date('d/m/Y H:i')];
}

/** CSV do relatório (UTF-8 com BOM, ";"): só números, sem apelidos de jogadores. */
function relatorio_parceiro_csv(int $parceiroId, int $dias): string
{
    $r = relatorio_parceiro($parceiroId, $dias);
    $num = static fn($n) => str_replace('.', ',', (string) $n);
    $linhas = [['Relatório do parceiro EcoCity'], ['Período', $dias ? "Últimos $dias dias" : 'Desde o início'], ['Gerado em', $r['gerado']], [],
        ['Resumo'], ['Códigos gerados', $r['resumo']['gerados']], ['Verificações (usados)', $r['resumo']['usados']],
        ['Vencidos sem uso', $r['resumo']['vencidos']], ['Cancelados', $r['resumo']['cancelados']],
        ['Taxa de uso (%)', $r['resumo']['taxaUso'] === null ? '' : $num($r['resumo']['taxaUso'])],
        ['Jogadores atendidos', $r['resumo']['jogadores']], ['Jogadores que voltaram (2+ verificações)', $r['resumo']['voltaram']], [],
        ['Por atividade'], ['Atividade', 'Verificações', 'Total', 'Unidade']];
    foreach ($r['porAtividade'] as $a) $linhas[] = [$a['atividade'], $a['verificacoes'], $num($a['total']), $a['unidade']];
    $linhas[] = [];
    $linhas[] = ['Por atendente'];
    $linhas[] = ['Atendente', 'Gerados', 'Usados', 'Vencidos', 'Cancelados', 'Jogadores atendidos'];
    foreach ($r['porFuncionario'] as $f) $linhas[] = [$f['atendente'], $f['gerados'], $f['usados'], $f['vencidos'], $f['cancelados'], $f['jogadores']];
    $linhas[] = [];
    $linhas[] = ['Verificações por dia'];
    $linhas[] = ['Data', 'Verificações'];
    foreach ($r['porDia'] as $d) $linhas[] = [date('d/m/Y', strtotime($d['data'])), $d['n']];

    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($linhas as $l) fputcsv($out, array_map('csv_celula_segura', $l), ';');
    rewind($out);
    return stream_get_contents($out);
}

/* ---------------------------------------------------------------------------
   Estabelecimento e pedidos de atividade
   --------------------------------------------------------------------------- */

function dados_estabelecimento(int $parceiroId): array
{
    $pdo = db();
    $s = $pdo->prepare('SELECT nome, responsavel, endereco, descricao, email FROM parceiro WHERE parceiro_id = ?');
    $s->execute([$parceiroId]);
    $p = $s->fetch();
    $minhas = atividades_do_parceiro($pdo, $parceiroId);
    $todas = atividades_verificaveis();
    $pedidos = [];
    if (parceiro_funcionarios_pronto()) {
        $q = $pdo->prepare('SELECT x.pedido_id, x.atividade_sustentavel_id, a.nome, x.status, x.motivo, x.data, x.data_decisao
                              FROM parceiro_pedido_atividade x JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = x.atividade_sustentavel_id
                             WHERE x.parceiro_id = ? ORDER BY x.data DESC, x.pedido_id DESC LIMIT 20');
        $q->execute([$parceiroId]);
        $pedidos = array_map(static fn($x) => ['id' => (int) $x['pedido_id'], 'atividadeId' => (int) $x['atividade_sustentavel_id'], 'atividade' => $x['nome'],
            'status' => $x['status'], 'motivo' => $x['motivo'], 'data' => $x['data'], 'decididoEm' => $x['data_decisao']], $q->fetchAll());
    }
    $pendentes = array_column(array_filter($pedidos, static fn($x) => $x['status'] === 'pendente'), 'atividadeId');
    return [
        'dados' => $p,
        'autorizadas' => array_values(array_filter($todas, static fn($a) => in_array($a['id'], $minhas, true))),
        'podePedir' => array_values(array_filter($todas, static fn($a) => !in_array($a['id'], $minhas, true) && !in_array($a['id'], $pendentes, true))),
        'pedidos' => $pedidos,
    ];
}

/** Atualiza nome, responsável, endereço e descrição (mesmas regras do cadastro). */
function salvar_estabelecimento(int $parceiroId, array $d): string
{
    $campos = [];
    foreach ([['nome', 'Nome do estabelecimento', 3, 100], ['responsavel', 'Responsável', 3, 100], ['endereco', 'Endereço', 5, 200], ['descricao', 'Descrição', 10, 250]] as [$k, $rotulo, $min, $max]) {
        $v = trim((string) ($d[$k] ?? ''));
        $n = mb_strlen($v, 'UTF-8');
        if ($n < $min || $n > $max) throw new RegistroInvalido("{$rotulo}: use de {$min} a {$max} caracteres.");
        $campos[$k] = $v;
    }
    db()->prepare('UPDATE parceiro SET nome = ?, responsavel = ?, endereco = ?, descricao = ? WHERE parceiro_id = ?')
        ->execute([$campos['nome'], $campos['responsavel'], $campos['endereco'], $campos['descricao'], $parceiroId]);
    return 'Dados do estabelecimento salvos.';
}

function trocar_senha_parceiro(int $parceiroId, string $atual, string $nova, string $confirmar): string
{
    $s = db()->prepare('SELECT senha FROM parceiro WHERE parceiro_id = ?');
    $s->execute([$parceiroId]);
    $hash = $s->fetchColumn();
    if (!$hash || !password_verify($atual, $hash)) throw new RegistroInvalido('A senha atual está incorreta.');
    validar_senha_nova($nova, $confirmar);
    if (password_verify($nova, $hash)) throw new RegistroInvalido('A nova senha deve ser diferente da atual.');
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    $pdo = db();
    if (parceiro_sessao_versao_pronta()) {
        $pdo->prepare('UPDATE parceiro SET senha = ?, sessao_versao = sessao_versao + 1 WHERE parceiro_id = ?')
            ->execute([$hash, $parceiroId]);
        $v = $pdo->prepare('SELECT sessao_versao FROM parceiro WHERE parceiro_id = ?');
        $v->execute([$parceiroId]);
        $_SESSION['parceiro_versao'] = (int) $v->fetchColumn();
    } else {
        $pdo->prepare('UPDATE parceiro SET senha = ? WHERE parceiro_id = ?')->execute([$hash, $parceiroId]);
    }
    session_regenerate_id(true);
    return 'Senha alterada.';
}

/** Deixa de verificar uma atividade (fica pelo menos uma); os códigos dela em aberto são cancelados. */
function remover_atividade_parceiro(int $parceiroId, int $atividadeId): string
{
    return c_transacao(static function (PDO $pdo) use ($parceiroId, $atividadeId): string {
        $pdo->prepare('SELECT parceiro_id FROM parceiro WHERE parceiro_id = ? FOR UPDATE')->execute([$parceiroId]);
        $minhas = atividades_do_parceiro($pdo, $parceiroId);
        if (!in_array($atividadeId, $minhas, true)) throw new RegistroInvalido('O estabelecimento não verifica esta atividade.');
        if (count($minhas) <= 1) throw new RegistroInvalido('O estabelecimento precisa verificar pelo menos uma atividade.');
        $pdo->prepare('DELETE FROM parceiro_atividade WHERE parceiro_id = ? AND atividade_sustentavel_id = ?')->execute([$parceiroId, $atividadeId]);
        $pdo->prepare('UPDATE codigo_parceiro SET cancelado = TRUE WHERE parceiro_id = ? AND atividade_sustentavel_id = ? AND usado_em IS NULL AND cancelado = FALSE')
            ->execute([$parceiroId, $atividadeId]);
        return 'Atividade removida. Para voltar a verificá-la, peça de novo à equipe do EcoCity.';
    });
}

function pedir_atividade(int $parceiroId, int $atividadeId): string
{
    exigir_funcionarios_pronto();
    $a = array_values(array_filter(atividades_verificaveis(), static fn($x) => $x['id'] === $atividadeId))[0] ?? null;
    if (!$a) throw new RegistroInvalido('Esta atividade não pode ser verificada por parceiros.');
    if (in_array($atividadeId, atividades_do_parceiro(db(), $parceiroId), true)) throw new RegistroInvalido('O estabelecimento já verifica esta atividade.');
    try {
        db()->prepare("INSERT INTO parceiro_pedido_atividade (parceiro_id, atividade_sustentavel_id, status, data) VALUES (?, ?, 'pendente', NOW())")
            ->execute([$parceiroId, $atividadeId]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') throw new RegistroInvalido('Já existe um pedido desta atividade aguardando a equipe do EcoCity.');
        throw $ex;
    }
    return "Pedido enviado: {$a['nome']}. A equipe do EcoCity vai analisar.";
}

/* Administração do EcoCity ---------------------------------------------------- */

function listar_pedidos_atividade(): array
{
    if (!parceiro_funcionarios_pronto()) return [];
    return array_map(static fn($x) => ['id' => (int) $x['pedido_id'], 'parceiroId' => (int) $x['parceiro_id'], 'parceiro' => $x['parceiro'],
        'statusParceiro' => $x['status_parceiro'], 'atividade' => $x['atividade'], 'unidade' => $x['impacto_real_unidade'], 'data' => $x['data']],
        db()->query("SELECT x.pedido_id, x.parceiro_id, p.nome AS parceiro, p.status AS status_parceiro, a.nome AS atividade, a.impacto_real_unidade, x.data
                       FROM parceiro_pedido_atividade x
                       JOIN parceiro p ON p.parceiro_id = x.parceiro_id
                       JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = x.atividade_sustentavel_id
                      WHERE x.status = 'pendente' ORDER BY x.data")->fetchAll());
}

function pedidos_atividade_pendentes(): int
{
    return parceiro_funcionarios_pronto()
        ? (int) db()->query("SELECT COUNT(*) FROM parceiro_pedido_atividade WHERE status = 'pendente'")->fetchColumn() : 0;
}

/** aprovar (a atividade passa a ser autorizada) | recusar (com motivo). */
function decidir_pedido_atividade(int $adminId, int $pedidoId, string $acao, string $motivo = ''): string
{
    exigir_funcionarios_pronto();
    if (!in_array($acao, ['aprovar', 'recusar'], true)) throw new RegistroInvalido('Ação inválida.');
    $motivo = trim($motivo);
    if ($acao === 'recusar' && ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 250)) throw new RegistroInvalido('Informe o motivo da recusa (até 250 caracteres).');
    $colParceiro = atividade_aceita_parceiro_pronto() ? 'a.aceita_parceiro' : '1 AS aceita_parceiro';
    return c_transacao(static function (PDO $pdo) use ($adminId, $pedidoId, $acao, $motivo, $colParceiro): string {
        $s = $pdo->prepare("SELECT x.parceiro_id, x.atividade_sustentavel_id, x.status, a.nome, a.ativa, a.impacto_real_quantidade, $colParceiro
                              FROM parceiro_pedido_atividade x JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = x.atividade_sustentavel_id
                             WHERE x.pedido_id = ? FOR UPDATE");
        $s->execute([$pedidoId]);
        $x = $s->fetch();
        if (!$x) throw new RegistroInvalido('Pedido não encontrado.');
        if ($x['status'] !== 'pendente') throw new RegistroInvalido('Este pedido já foi decidido.');
        if ($acao === 'aprovar') {
            if (!$x['ativa'] || (float) $x['impacto_real_quantidade'] <= 0 || !$x['aceita_parceiro']) throw new RegistroInvalido('Esta atividade não pode mais ser verificada por parceiros.');
            $pdo->prepare('INSERT IGNORE INTO parceiro_atividade (parceiro_id, atividade_sustentavel_id) VALUES (?, ?)')
                ->execute([$x['parceiro_id'], $x['atividade_sustentavel_id']]);
        }
        $pdo->prepare('UPDATE parceiro_pedido_atividade SET status = ?, motivo = ?, decidido_por = ?, data_decisao = NOW() WHERE pedido_id = ?')
            ->execute([$acao === 'aprovar' ? 'aprovado' : 'recusado', $acao === 'recusar' ? $motivo : null, $adminId, $pedidoId]);
        return $acao === 'aprovar' ? "Pedido aprovado: o parceiro já pode verificar {$x['nome']}." : 'Pedido recusado.';
    });
}
