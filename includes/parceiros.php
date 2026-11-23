<?php
/**
 * Verificação por parceiros (specs/site.md RN69–RN74).
 *
 *  - O estabelecimento tem conta própria (fora do jogo), aprovada por um administrador,
 *    com as atividades que pode verificar.
 *  - O parceiro gera um código de 6 dígitos + token (link do QR) para uma atividade e uma quantidade;
 *    vale 30 minutos, para um jogador só, e pode ser cancelado enquanto não for usado.
 *  - O jogador usa o código (link do QR ou digitado no jogo): cria um registro tipo 3 aprovado na hora,
 *    com a quantidade em registro_atividade.impacto_real, recompensa de 150% e o limite diário da RN44.
 *  - Errar o código 5 vezes em 15 minutos bloqueia novas tentativas por 15 minutos.
 *  - RN95 (migração 031): a conta de e-mail é o administrador do estabelecimento; os funcionários
 *    entram com usuário + senha criados por ele e só atendem (veem e cancelam só os próprios códigos).
 *    Cada código grava quem o gerou (codigo_parceiro.funcionario_id; NULL = o administrador).
 */
declare(strict_types=1);

const PARCEIRO_CODIGO_MIN = 30;              // validade do código
const PARCEIRO_CODIGOS_ATIVOS = 20;          // por parceiro, ao mesmo tempo (provisório)
const PARCEIRO_QTD_MULTIPLICADOR = 50;       // quantidade máxima = 50 × valor fixo da atividade (provisório)
const PARCEIRO_ERROS_CODIGO = 5;
const PARCEIRO_ERROS_JANELA_MIN = 15;
const PARCEIRO_HISTORICO = 50;
const PARCEIRO_STATUS = ['pendente', 'aprovado', 'recusado', 'bloqueado'];

/* ---------------------------------------------------------------------------
   Conta e sessão do parceiro
   --------------------------------------------------------------------------- */

/** O banco tem a migração 043 (atividade_sustentavel.aceita_parceiro)? */
function atividade_aceita_parceiro_pronto(): bool
{
    return schema_flag('atividade_aceita_parceiro', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'atividade_sustentavel' AND column_name = 'aceita_parceiro'"
    )->fetchColumn());
}

/** Atividades ativas com impacto real definido elegíveis para verificação por parceiros (RN69). */
function atividades_verificaveis(): array
{
    $filtroParceiro = atividade_aceita_parceiro_pronto() ? ' AND a.aceita_parceiro = TRUE' : '';
    return array_map(static fn($a) => [
        'id' => (int) $a['atividade_sustentavel_id'], 'nome' => $a['nome'], 'unidade' => $a['impacto_real_unidade'],
        'fixo' => (float) $a['impacto_real_quantidade'], 'max' => round((float) $a['impacto_real_quantidade'] * PARCEIRO_QTD_MULTIPLICADOR, 2),
        'categoria' => INDICADOR_CHAVE[(int) $a['indicador_ambiental_id']] ?? '',
        'categoriaNome' => $a['categoria_nome'] ?? '',
    ], db()->query("SELECT a.atividade_sustentavel_id, a.nome, a.impacto_real_unidade, a.impacto_real_quantidade, ai.indicador_ambiental_id, c.nome AS categoria_nome
                      FROM atividade_sustentavel a
                      JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
                 LEFT JOIN categoria_ambiental c ON c.categoria_ambiental_id = a.categoria_ambiental_id
                     WHERE a.ativa = TRUE AND a.impacto_real_quantidade > 0$filtroParceiro ORDER BY a.atividade_sustentavel_id")->fetchAll());
}

/** Lista de IDs de atividade válidos (verificáveis) a partir do formulário. */
function atividades_escolhidas(array $ids): array
{
    $validas = array_column(atividades_verificaveis(), 'id');
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static fn($i) => in_array($i, $validas, true)));
    if (!$ids) throw new RegistroInvalido('Marque pelo menos uma atividade que o estabelecimento pode verificar.');
    return $ids;
}

/**
 * Cadastro do estabelecimento (fica pendente até um administrador aprovar).
 * @return array<string,string> erros por campo (vazio = sucesso)
 */
function cadastrar_parceiro(array $d): array
{
    $erros = [];
    $campo = static function (string $k, string $rotulo, int $min, int $max) use ($d, &$erros): string {
        $v = trim((string) ($d[$k] ?? ''));
        $n = mb_strlen($v, 'UTF-8');
        if ($n < $min || $n > $max) $erros[$k] = "{$rotulo}: use de {$min} a {$max} caracteres.";
        return $v;
    };
    $nome = $campo('nome', 'Nome do estabelecimento', 3, 100);
    $responsavel = $campo('responsavel', 'Responsável', 3, 100);
    $endereco = $campo('endereco', 'Endereço', 5, 200);
    $descricao = $campo('descricao', 'Descrição', 10, 250);
    $email = strtolower(trim((string) ($d['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $erros['email'] = 'Informe um e-mail válido.';
    $senha = is_string($d['senha'] ?? null) ? $d['senha'] : '';
    if (strlen($senha) < 6 || strlen($senha) > 72) $erros['senha'] = 'A senha deve ter de 6 a 72 caracteres.';
    if ($senha !== ($d['confirmar'] ?? null)) $erros['confirmar'] = 'As senhas não conferem.';
    try {
        $atividades = atividades_escolhidas(is_array($d['atividades'] ?? null) ? $d['atividades'] : []);
    } catch (RegistroInvalido $ex) {
        $erros['atividades'] = $ex->getMessage();
    }
    if ($erros) return $erros;

    try {
        c_transacao(static function (PDO $pdo) use ($nome, $responsavel, $endereco, $descricao, $email, $senha, $atividades): void {
            $pdo->prepare("INSERT INTO parceiro (nome, email, senha, responsavel, descricao, endereco, ativo, status, data_cadastro)
                           VALUES (?, ?, ?, ?, ?, ?, FALSE, 'pendente', NOW())")
                ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $responsavel, $descricao, $endereco]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO parceiro_atividade (parceiro_id, atividade_sustentavel_id) VALUES (?, ?)');
            foreach ($atividades as $a) $ins->execute([$id, $a]);
        });
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') return ['email' => 'Já existe um parceiro com este e-mail.'];
        throw $ex;
    }
    return [];
}

/** O banco tem a migração 031 (funcionários)? */
function parceiro_funcionarios_pronto(): bool
{
    return schema_flag('parceiro_func', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'parceiro_funcionario'"
    )->fetchColumn());
}

/**
 * Confere o login da área do parceiro. Com "@": e-mail do administrador do estabelecimento
 * (qualquer situação menos bloqueado; o painel mostra pendente/recusado). Sem "@": usuário de um
 * funcionário ativo de um estabelecimento aprovado (RN95).
 * @return array{parceiroId: int, funcionarioId: ?int, versao: int}|null
 */
function autenticar_parceiro(string $login, string $senha): ?array
{
    $login = strtolower(trim($login));
    if (str_contains($login, '@')) {
        $sql = parceiro_sessao_versao_pronta()
            ? 'SELECT parceiro_id, senha, status, sessao_versao FROM parceiro WHERE email = ?'
            : 'SELECT parceiro_id, senha, status, 0 AS sessao_versao FROM parceiro WHERE email = ?';
        $s = db()->prepare($sql);
        $s->execute([$login]);
        $p = $s->fetch();
        if (!$p || !$p['senha'] || !password_verify($senha, $p['senha']) || $p['status'] === 'bloqueado') return null;
        return ['parceiroId' => (int) $p['parceiro_id'], 'funcionarioId' => null, 'versao' => (int) $p['sessao_versao']];
    }
    if (!parceiro_funcionarios_pronto()) return null;
    $s = db()->prepare("SELECT f.funcionario_id, f.parceiro_id, f.senha, f.ativo, f.sessao_versao, p.status
                          FROM parceiro_funcionario f JOIN parceiro p ON p.parceiro_id = f.parceiro_id WHERE f.usuario = ?");
    $s->execute([$login]);
    $f = $s->fetch();
    if (!$f || !password_verify($senha, $f['senha']) || !$f['ativo'] || $f['status'] !== 'aprovado') return null;
    db()->prepare('UPDATE parceiro_funcionario SET ultimo_acesso = NOW() WHERE funcionario_id = ?')->execute([$f['funcionario_id']]);
    return ['parceiroId' => (int) $f['parceiro_id'], 'funcionarioId' => (int) $f['funcionario_id'], 'versao' => (int) $f['sessao_versao']];
}

function entrar_parceiro(int $parceiroId, ?int $funcionarioId = null, int $versao = 0): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['parceiro_id'] = $parceiroId;
    $_SESSION['parceiro_funcionario_id'] = $funcionarioId;
    $_SESSION['parceiro_versao'] = $versao;
    unset($_SESSION['csrf']);
    sincronizar_contexto_auditoria(false);
}

function sair_parceiro(): void
{
    unset($_SESSION['parceiro_id'], $_SESSION['parceiro_funcionario_id'], $_SESSION['parceiro_versao']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    sincronizar_contexto_auditoria(false);
}

/**
 * Parceiro logado, com o papel ('admin' ou 'funcionario'). Derruba a sessão se o estabelecimento
 * foi bloqueado ou, para funcionário, se ele foi desativado, teve a senha redefinida ou o
 * estabelecimento deixou de estar aprovado.
 */
function parceiro_logado(): ?array
{
    if (!isset($_SESSION['parceiro_id'])) return null;
    $s = db()->prepare('SELECT * FROM parceiro WHERE parceiro_id = ?');
    $s->execute([(int) $_SESSION['parceiro_id']]);
    $p = $s->fetch();
    if (!$p || $p['status'] === 'bloqueado') {
        sair_parceiro();
        return null;
    }
    $fid = $_SESSION['parceiro_funcionario_id'] ?? null;
    if ($fid === null) {
        if (array_key_exists('sessao_versao', $p) && (int) $p['sessao_versao'] !== (int) ($_SESSION['parceiro_versao'] ?? 0)) {
            sair_parceiro();
            return null;
        }
        return $p + ['papel' => 'admin', 'funcionario_id' => null, 'funcionario_nome' => null];
    }

    $f = null;
    if (parceiro_funcionarios_pronto()) {
        $s = db()->prepare('SELECT nome, ativo, sessao_versao FROM parceiro_funcionario WHERE funcionario_id = ? AND parceiro_id = ?');
        $s->execute([(int) $fid, (int) $p['parceiro_id']]);
        $f = $s->fetch();
    }
    if (!$f || !$f['ativo'] || (int) $f['sessao_versao'] !== (int) ($_SESSION['parceiro_versao'] ?? -1) || $p['status'] !== 'aprovado') {
        sair_parceiro();
        return null;
    }
    return $p + ['papel' => 'funcionario', 'funcionario_id' => (int) $fid, 'funcionario_nome' => $f['nome']];
}

function exigir_parceiro(): array
{
    $p = parceiro_logado();
    if (!$p) redirect('parceiro-entrar.php');
    return $p;
}

/** Atividades autorizadas de um parceiro. */
function atividades_do_parceiro(PDO $pdo, int $parceiroId): array
{
    $s = $pdo->prepare('SELECT atividade_sustentavel_id FROM parceiro_atividade WHERE parceiro_id = ? ORDER BY atividade_sustentavel_id');
    $s->execute([$parceiroId]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------------------------------------------------------------------------
   Códigos (painel do parceiro)
   --------------------------------------------------------------------------- */

function formatar_codigo(array $c): array
{
    return [
        'id' => (int) $c['codigo_parceiro_id'], 'codigo' => $c['codigo'], 'token' => $c['token'],
        'link' => url_site('validar.php?c=' . $c['token']),
        'atividade' => $c['atividade'], 'atividadeId' => (int) $c['atividade_sustentavel_id'],
        'quantidade' => (float) $c['quantidade'], 'unidade' => $c['impacto_real_unidade'],
        'criadoEm' => $c['criado_em'], 'expiraEm' => $c['expira_em'], 'expiraTs' => strtotime($c['expira_em']),
        'usadoEm' => $c['usado_em'], 'jogador' => $c['apelido'] ?? null, 'cancelado' => (bool) $c['cancelado'],
        'atendenteId' => isset($c['funcionario_id']) ? (int) $c['funcionario_id'] : null,
        'atendente' => $c['atendente'] ?? null,   // null = administrador do estabelecimento (RN95)
    ];
}

/** Consulta dos códigos com a atividade, o jogador e (com a migração 031) quem atendeu. */
function sql_codigo(): string
{
    return parceiro_funcionarios_pronto()
        ? 'SELECT k.*, a.nome AS atividade, a.impacto_real_unidade, j.apelido, f.nome AS atendente
             FROM codigo_parceiro k
             JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
        LEFT JOIN jogador j ON j.jogador_id = k.jogador_id
        LEFT JOIN parceiro_funcionario f ON f.funcionario_id = k.funcionario_id'
        : 'SELECT k.*, a.nome AS atividade, a.impacto_real_unidade, j.apelido
             FROM codigo_parceiro k
             JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
        LEFT JOIN jogador j ON j.jogador_id = k.jogador_id';
}

/** Gera um código para uma atividade autorizada e uma quantidade. $funcionarioId: quem atende (null = administrador). */
function gerar_codigo(int $parceiroId, int $atividadeId, $quantidade, ?int $funcionarioId = null): array
{
    if (!is_numeric($quantidade) || (float) $quantidade <= 0) throw new RegistroInvalido('Informe a quantidade entregue.');
    $qtd = round((float) $quantidade, 2);
    return c_transacao(static function (PDO $pdo) use ($parceiroId, $atividadeId, $qtd, $funcionarioId): array {
        $s = $pdo->prepare('SELECT status FROM parceiro WHERE parceiro_id = ? FOR UPDATE');
        $s->execute([$parceiroId]);
        if ($s->fetchColumn() !== 'aprovado') throw new RegistroInvalido('Sua conta de parceiro ainda não foi aprovada.');
        if (!in_array($atividadeId, atividades_do_parceiro($pdo, $parceiroId), true)) throw new RegistroInvalido('Seu estabelecimento não verifica esta atividade.');
        $a = array_values(array_filter(atividades_verificaveis(), static fn($x) => $x['id'] === $atividadeId))[0] ?? null;
        if (!$a) throw new RegistroInvalido('Atividade indisponível.');
        if ($qtd < 0.01 || $qtd > $a['max']) {
            throw new RegistroInvalido('Quantidade: de 0,01 a ' . number_format($a['max'], $a['max'] == floor($a['max']) ? 0 : 2, ',', '.') . " {$a['unidade']} por código.");
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM codigo_parceiro WHERE parceiro_id = ? AND usado_em IS NULL AND cancelado = FALSE AND expira_em > NOW()');
        $s->execute([$parceiroId]);
        if ((int) $s->fetchColumn() >= PARCEIRO_CODIGOS_ATIVOS) throw new RegistroInvalido('Você já tem ' . PARCEIRO_CODIGOS_ATIVOS . ' códigos ativos. Cancele algum ou espere vencerem.');
        // 6 dígitos únicos entre os códigos ainda válidos
        $existe = $pdo->prepare('SELECT 1 FROM codigo_parceiro WHERE codigo = ? AND usado_em IS NULL AND cancelado = FALSE AND expira_em > NOW()');
        for ($i = 0; ; $i++) {
            $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $existe->execute([$codigo]);
            if (!$existe->fetchColumn()) break;
            if ($i > 20) throw new RuntimeException('Não foi possível gerar um código único.');
        }
        if ($funcionarioId !== null) {
            $pdo->prepare('INSERT INTO codigo_parceiro (parceiro_id, funcionario_id, atividade_sustentavel_id, quantidade, codigo, token, criado_em, expira_em)
                           VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . PARCEIRO_CODIGO_MIN . ' MINUTE)')
                ->execute([$parceiroId, $funcionarioId, $atividadeId, $qtd, $codigo, bin2hex(random_bytes(16))]);
        } else {
            $pdo->prepare('INSERT INTO codigo_parceiro (parceiro_id, atividade_sustentavel_id, quantidade, codigo, token, criado_em, expira_em)
                           VALUES (?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . PARCEIRO_CODIGO_MIN . ' MINUTE)')
                ->execute([$parceiroId, $atividadeId, $qtd, $codigo, bin2hex(random_bytes(16))]);
        }
        $novoId = (int) $pdo->lastInsertId();
        $c = $pdo->prepare(sql_codigo() . ' WHERE k.codigo_parceiro_id = ?');
        $c->execute([$novoId]);
        return formatar_codigo($c->fetch());
    });
}

/** Cancela um código ainda não usado. Funcionário (RN95) só cancela os próprios; o administrador, qualquer um. */
function cancelar_codigo(int $parceiroId, int $codigoId, ?int $funcionarioId = null): string
{
    $proprio = $funcionarioId !== null ? ' AND funcionario_id = ' . (int) $funcionarioId : '';
    $s = db()->prepare('UPDATE codigo_parceiro SET cancelado = TRUE WHERE codigo_parceiro_id = ? AND parceiro_id = ? AND usado_em IS NULL AND cancelado = FALSE' . $proprio);
    $s->execute([$codigoId, $parceiroId]);
    if (!$s->rowCount()) throw new RegistroInvalido('Código não encontrado ou já usado.');
    return 'Código cancelado.';
}

/**
 * Painel: atividades autorizadas, códigos ativos e recentes, histórico e totais.
 * Funcionário (RN95): só os próprios códigos, e o histórico e os totais de hoje.
 */
function painel_parceiro(int $parceiroId, ?int $funcionarioId = null): array
{
    $pdo = db();
    $minhas = atividades_do_parceiro($pdo, $parceiroId);
    $meus = $funcionarioId !== null ? ' AND k.funcionario_id = ' . (int) $funcionarioId : '';
    $hoje = $funcionarioId !== null ? ' AND k.usado_em >= CURDATE()' : '';
    $ativos = $pdo->prepare(sql_codigo() . ' WHERE k.parceiro_id = ?' . $meus . ' AND k.cancelado = FALSE AND (k.usado_em IS NULL AND k.expira_em > NOW()
                                                 OR k.usado_em > NOW() - INTERVAL 1 HOUR) ORDER BY k.criado_em DESC');
    $ativos->execute([$parceiroId]);
    $hist = $pdo->prepare(sql_codigo() . ' WHERE k.parceiro_id = ?' . $meus . $hoje . ' AND k.usado_em IS NOT NULL ORDER BY k.usado_em DESC LIMIT ' . PARCEIRO_HISTORICO);
    $hist->execute([$parceiroId]);
    $totSql = $funcionarioId === null
        ? 'SELECT atividade AS nome, unidade, verificacoes, impacto AS total
             FROM vw_impacto_parceiro
            WHERE parceiro_id = ?
         ORDER BY atividade_sustentavel_id'
        : 'SELECT a.nome, a.impacto_real_unidade AS unidade, COUNT(*) AS verificacoes, SUM(k.quantidade) AS total
             FROM codigo_parceiro k JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
            WHERE k.parceiro_id = ?' . $meus . $hoje . ' AND k.usado_em IS NOT NULL GROUP BY a.atividade_sustentavel_id ORDER BY a.atividade_sustentavel_id';
    $tot = $pdo->prepare($totSql);
    $tot->execute([$parceiroId]);
    $todas = atividades_verificaveis();
    return [
        'atividades' => array_values(array_filter($todas, static fn($a) => in_array($a['id'], $minhas, true))),
        'atividadesTodas' => $todas,
        'codigos' => array_map('formatar_codigo', $ativos->fetchAll()),
        'historico' => array_map('formatar_codigo', $hist->fetchAll()),
        'totais' => array_map(static fn($t) => ['atividade' => $t['nome'], 'unidade' => $t['unidade'], 'verificacoes' => (int) $t['verificacoes'], 'total' => (float) $t['total']], $tot->fetchAll()),
        'validadeMin' => PARCEIRO_CODIGO_MIN, 'agora' => time(),
    ];
}

/* ---------------------------------------------------------------------------
   Validação pelo jogador
   --------------------------------------------------------------------------- */

/** Código pelo token do QR (para a página de confirmação); null se não existir. */
function codigo_por_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $s = db()->prepare('SELECT k.*, a.nome AS atividade, a.impacto_real_unidade, j.apelido,
                               p.nome AS parceiro, p.endereco AS parceiro_endereco, p.status
                          FROM codigo_parceiro k
                          JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
                          JOIN parceiro p ON p.parceiro_id = k.parceiro_id
                     LEFT JOIN jogador j ON j.jogador_id = k.jogador_id
                         WHERE k.token = ?');
    $s->execute([$token]);
    $c = $s->fetch();
    if (!$c) return null;
    $out = formatar_codigo($c) + ['parceiro' => $c['parceiro'], 'parceiroEndereco' => $c['parceiro_endereco'], 'parceiroStatus' => $c['status']];
    $out['situacao'] = situacao_codigo($c);
    return $out;
}

/** 'valido' | 'usado' | 'cancelado' | 'vencido' | 'parceiro'. */
function situacao_codigo(array $c): string
{
    if ($c['usado_em'] !== null) return 'usado';
    if ($c['cancelado']) return 'cancelado';
    if (strtotime($c['expira_em']) <= time()) return 'vencido';
    if (($c['status'] ?? 'aprovado') !== 'aprovado') return 'parceiro';
    return 'valido';
}

const MENSAGEM_SITUACAO = [
    'usado' => 'Este código já foi usado.', 'cancelado' => 'Este código foi cancelado pelo parceiro.',
    'vencido' => 'Este código venceu. Peça um novo ao parceiro.', 'parceiro' => 'Este parceiro não está autorizado no momento.',
];

/** Erros recentes de código digitado (limite contra adivinhação). */
function codigo_bloqueado(PDO $pdo, int $jogadorId): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM tentativa_codigo_parceiro WHERE jogador_id = ? AND data > NOW() - INTERVAL ' . PARCEIRO_ERROS_JANELA_MIN . ' MINUTE');
    $s->execute([$jogadorId]);
    return (int) $s->fetchColumn() >= PARCEIRO_ERROS_CODIGO;
}

/**
 * Usa o código (6 dígitos ou token do QR): registro tipo 3 aprovado na hora.
 * @return array{atividade:string, parceiro:string, quantidade:float, unidade:string, registroId:int}
 */
function validar_codigo(int $jogadorId, string $valor): array
{
    $valor = trim($valor);
    $porToken = (bool) preg_match('/^[0-9a-f]{32}$/', $valor);
    $digitos = preg_replace('/\D/', '', $valor);
    if (!$porToken && strlen($digitos) !== 6) throw new RegistroInvalido('Digite os 6 números do código do parceiro.');

    $pdo = db();
    if (!$porToken && codigo_bloqueado($pdo, $jogadorId)) {
        throw new RegistroInvalido('Muitos códigos errados. Tente de novo em ' . PARCEIRO_ERROS_JANELA_MIN . ' minutos.');
    }
    $erro = null;
    $colParceiro = atividade_aceita_parceiro_pronto() ? 'a.aceita_parceiro' : '1 AS aceita_parceiro';
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT k.*, p.nome AS parceiro, p.status, a.nome AS atividade, a.impacto_real_unidade, a.ativa AS atividade_ativa, $colParceiro
                              FROM codigo_parceiro k JOIN parceiro p ON p.parceiro_id = k.parceiro_id
                              JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
                             WHERE " . ($porToken ? 'k.token = ?' : 'k.codigo = ? AND k.usado_em IS NULL AND k.cancelado = FALSE AND k.expira_em > NOW()') . '
                             ORDER BY k.criado_em DESC LIMIT 1 FOR UPDATE');
        $s->execute([$porToken ? $valor : $digitos]);
        $c = $s->fetch();
        if (!$c) {
            $erro = 'Código não encontrado. Confira os números ou peça um novo ao parceiro.';
            throw new RegistroInvalido($erro);
        }
        $sit = situacao_codigo($c);
        if ($sit !== 'valido') throw new RegistroInvalido(MENSAGEM_SITUACAO[$sit]);
        if (!$c['atividade_ativa'] || !$c['aceita_parceiro']) throw new RegistroInvalido('Esta atividade não está mais disponível para verificação por parceiros.');
        $atividade = (int) $c['atividade_sustentavel_id'];
        if (!in_array($atividade, atividades_do_parceiro($pdo, (int) $c['parceiro_id']), true)) throw new RegistroInvalido(MENSAGEM_SITUACAO['parceiro']);
        verificar_limite_diario($pdo, $jogadorId, $atividade);

        $pdo->prepare("INSERT INTO registro_atividade (jogador_id, atividade_sustentavel_id, missao_atividade_id, tipo_verificacao_id, data_registro, status, impacto_real)
                       VALUES (?, ?, NULL, ?, NOW(), 'pendente', ?)")->execute([$jogadorId, $atividade, TIPO_PARCEIRO, $c['quantidade']]);
        $registroId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO verificacao_parceiro (registro_atividade_id, parceiro_id, codigo_verificacao, data_verificacao) VALUES (?, ?, ?, NOW())')
            ->execute([$registroId, $c['parceiro_id'], (int) $c['codigo']]);
        $pdo->prepare('UPDATE codigo_parceiro SET usado_em = NOW(), jogador_id = ?, registro_atividade_id = ? WHERE codigo_parceiro_id = ?')
            ->execute([$jogadorId, $registroId, $c['codigo_parceiro_id']]);
        aprovar_registro($pdo, $registroId, null);
        $pdo->commit();
        return ['atividade' => $c['atividade'], 'parceiro' => $c['parceiro'], 'quantidade' => (float) $c['quantidade'],
                'unidade' => $c['impacto_real_unidade'], 'registroId' => $registroId];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($erro !== null) {   // código digitado inexistente conta como erro (fora da transação desfeita)
            $pdo->prepare('INSERT INTO tentativa_codigo_parceiro (jogador_id, data) VALUES (?, NOW())')->execute([$jogadorId]);
        }
        throw $ex;
    }
}

/* ---------------------------------------------------------------------------
   Jogo e administração
   --------------------------------------------------------------------------- */

/** Parceiros aprovados para o jogador saber onde ir. */
function listar_parceiros_publicos(): array
{
    $pdo = db();
    $rows = $pdo->query("SELECT parceiro_id, nome, descricao, endereco FROM parceiro WHERE status = 'aprovado' ORDER BY nome")->fetchAll();
    $filtroParceiro = atividade_aceita_parceiro_pronto() ? ' AND a.aceita_parceiro = TRUE' : '';
    $atv = $pdo->query("SELECT pa.parceiro_id, pa.atividade_sustentavel_id FROM parceiro_atividade pa
                          JOIN parceiro p ON p.parceiro_id = pa.parceiro_id AND p.status = 'aprovado'
                          JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = pa.atividade_sustentavel_id AND a.ativa = TRUE$filtroParceiro
                      ORDER BY pa.parceiro_id, pa.atividade_sustentavel_id")->fetchAll();
    $por = [];
    foreach ($atv as $r) $por[(int) $r['parceiro_id']][] = (int) $r['atividade_sustentavel_id'];
    return array_map(static fn($p) => ['id' => (int) $p['parceiro_id'], 'nome' => $p['nome'], 'descricao' => $p['descricao'],
        'endereco' => $p['endereco'], 'atividades' => $por[(int) $p['parceiro_id']] ?? []], $rows);
}

function listar_parceiros_admin(): array
{
    $pdo = db();
    $func = parceiro_funcionarios_pronto()
        ? '(SELECT COUNT(*) FROM parceiro_funcionario f WHERE f.parceiro_id = p.parceiro_id AND f.ativo = TRUE)' : '0';
    $rows = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM codigo_parceiro k WHERE k.parceiro_id = p.parceiro_id AND k.usado_em IS NOT NULL) AS verificacoes,
                                $func AS funcionarios,
                                (SELECT MAX(k.usado_em) FROM codigo_parceiro k WHERE k.parceiro_id = p.parceiro_id) AS ultimo_atendimento
                           FROM parceiro p ORDER BY FIELD(p.status, 'pendente', 'aprovado', 'bloqueado', 'recusado'), p.data_cadastro DESC")->fetchAll();
    $atv = $pdo->query('SELECT parceiro_id, atividade_sustentavel_id FROM parceiro_atividade')->fetchAll();
    $por = [];
    foreach ($atv as $r) $por[(int) $r['parceiro_id']][] = (int) $r['atividade_sustentavel_id'];
    return array_map(static fn($p) => [
        'id' => (int) $p['parceiro_id'], 'nome' => $p['nome'], 'responsavel' => $p['responsavel'], 'email' => $p['email'],
        'endereco' => $p['endereco'], 'descricao' => $p['descricao'], 'status' => $p['status'], 'motivo' => $p['motivo_recusa'],
        'cadastro' => $p['data_cadastro'], 'verificacoes' => (int) $p['verificacoes'], 'atividades' => $por[(int) $p['parceiro_id']] ?? [],
        'funcionarios' => (int) $p['funcionarios'], 'ultimoAtendimento' => $p['ultimo_atendimento'],
    ], $rows);
}

/**
 * operacao: aprovar (com atividades) | recusar (com motivo) | bloquear | desbloquear | atividades.
 */
function decidir_parceiro(int $adminId, int $parceiroId, string $operacao, array $atividades = [], string $motivo = ''): string
{
    return c_transacao(static function (PDO $pdo) use ($adminId, $parceiroId, $operacao, $atividades, $motivo): string {
        $s = $pdo->prepare('SELECT status, nome FROM parceiro WHERE parceiro_id = ? FOR UPDATE');
        $s->execute([$parceiroId]);
        $p = $s->fetch();
        if (!$p) throw new RegistroInvalido('Parceiro não encontrado.');
        $setAtividades = static function () use ($pdo, $parceiroId, $atividades): void {
            $ids = atividades_escolhidas($atividades);
            $pdo->prepare('DELETE FROM parceiro_atividade WHERE parceiro_id = ?')->execute([$parceiroId]);
            $ins = $pdo->prepare('INSERT INTO parceiro_atividade (parceiro_id, atividade_sustentavel_id) VALUES (?, ?)');
            foreach ($ids as $a) $ins->execute([$parceiroId, $a]);
        };
        switch ($operacao) {
            case 'aprovar':
                if (!in_array($p['status'], ['pendente', 'recusado'], true)) throw new RegistroInvalido('Este parceiro não está aguardando aprovação.');
                $setAtividades();
                $pdo->prepare("UPDATE parceiro SET status = 'aprovado', ativo = TRUE, motivo_recusa = NULL, aprovado_por = ?, data_aprovacao = NOW() WHERE parceiro_id = ?")
                    ->execute([$adminId, $parceiroId]);
                return "Parceiro {$p['nome']} aprovado.";
            case 'recusar':
                if ($p['status'] !== 'pendente') throw new RegistroInvalido('Só cadastros pendentes podem ser recusados.');
                $motivo = trim($motivo);
                if ($motivo === '' || mb_strlen($motivo) > 250) throw new RegistroInvalido('Informe o motivo da recusa (até 250 caracteres).');
                $pdo->prepare("UPDATE parceiro SET status = 'recusado', ativo = FALSE, motivo_recusa = ? WHERE parceiro_id = ?")->execute([$motivo, $parceiroId]);
                return 'Cadastro recusado.';
            case 'bloquear':
                if ($p['status'] !== 'aprovado') throw new RegistroInvalido('Só parceiros aprovados podem ser bloqueados.');
                $pdo->prepare("UPDATE parceiro SET status = 'bloqueado', ativo = FALSE WHERE parceiro_id = ?")->execute([$parceiroId]);
                $pdo->prepare('UPDATE codigo_parceiro SET cancelado = TRUE WHERE parceiro_id = ? AND usado_em IS NULL')->execute([$parceiroId]);
                return "Parceiro {$p['nome']} bloqueado; os códigos em aberto foram cancelados.";
            case 'desbloquear':
                if ($p['status'] !== 'bloqueado') throw new RegistroInvalido('Este parceiro não está bloqueado.');
                $pdo->prepare("UPDATE parceiro SET status = 'aprovado', ativo = TRUE WHERE parceiro_id = ?")->execute([$parceiroId]);
                return "Parceiro {$p['nome']} desbloqueado.";
            case 'atividades':
                $setAtividades();
                return 'Atividades do parceiro atualizadas.';
            default:
                throw new RegistroInvalido('Ação inválida.');
        }
    });
}
