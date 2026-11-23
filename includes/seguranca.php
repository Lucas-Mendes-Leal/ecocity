<?php
/**
 * Segurança da conta (specs/site.md RN46–RN48).
 *
 *  - Limite de login: 5 senhas erradas para o mesmo e-mail dentro de 15 minutos (contadas desde o
 *    último login certo) bloqueiam novas tentativas por 15 minutos a partir do último erro.
 *    Por IP o limite é 20, para não travar redes compartilhadas (ex.: escola).
 *  - Redefinição de senha por e-mail: token aleatório de uso único, válido por 1 hora; no banco
 *    fica só o SHA-256. No máximo 3 pedidos por hora por conta. A resposta é sempre a mesma,
 *    exista ou não o e-mail.
 *  - Conta bloqueada (usuario.status = FALSE) não entra e perde a sessão aberta.
 */
declare(strict_types=1);

const LOGIN_MAX_ERROS_EMAIL = 5;
const LOGIN_MAX_ERROS_IP = 20;
const LOGIN_JANELA_MIN = 15;
const LOGIN_BLOQUEIO_MIN = 15;
const REDEFINICAO_VALIDADE_MIN = 60;
const REDEFINICAO_MAX_POR_HORA = 3;
const ACAO_IP_LIMITES = [
    'cadastro'          => [5, 60],   // 5 cadastros de conta por IP a cada 60 min
    'parceiro_cadastro' => [3, 60],   // 3 cadastros de parceiro por IP a cada 60 min
    'redefinicao'       => [10, 60],  // 10 pedidos de redefinição por IP a cada 60 min
];

/**
 * Contas que podem redefinir a senha por e-mail (RN47): jogador (usuario) e parceiro (RN69).
 * tabela/chave = links de redefinição; conta/id = tabela da conta; ativa = condição para poder redefinir;
 * nome = coluna do nome no e-mail; login = prefixo do e-mail em tentativa_login.
 */
const CONTAS_REDEFINICAO = [
    'jogador'  => ['tabela' => 'redefinicao_senha', 'chave' => 'redefinicao_senha_id', 'conta' => 'usuario', 'id' => 'usuario_id',
                   'ativa' => 'c.status = TRUE', 'nome' => 'nome', 'login' => ''],
    'parceiro' => ['tabela' => 'redefinicao_senha_parceiro', 'chave' => 'redefinicao_senha_parceiro_id', 'conta' => 'parceiro', 'id' => 'parceiro_id',
                   'ativa' => "c.status <> 'bloqueado' AND c.senha IS NOT NULL", 'nome' => 'responsavel', 'login' => 'parceiro:'],
];

function ip_cliente(): string
{
    // Atrás do túnel, todos chegam pelo IP do container: o do visitante vem da Cloudflare
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if (atras_do_proxy() && filter_var($cf, FILTER_VALIDATE_IP) !== false) {
        return $cf;
    }
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/**
 * Até quando o login está bloqueado para este e-mail/IP (null = liberado).
 */
function login_bloqueado_ate(string $email): ?DateTimeImmutable
{
    $pdo = db();
    $porEmail = $pdo->prepare(
        'SELECT COUNT(*) AS erros, MAX(data) AS ultimo FROM tentativa_login
          WHERE email = ? AND sucesso = FALSE AND data > NOW() - INTERVAL ? MINUTE
            AND data > COALESCE((SELECT MAX(s.data) FROM tentativa_login s WHERE s.email = ? AND s.sucesso = TRUE), \'1000-01-01\')'
    );
    $porEmail->execute([$email, LOGIN_JANELA_MIN, $email]);
    $e = $porEmail->fetch();

    $porIp = $pdo->prepare(
        'SELECT COUNT(*) AS erros, MAX(data) AS ultimo FROM tentativa_login
          WHERE ip = ? AND sucesso = FALSE AND data > NOW() - INTERVAL ? MINUTE'
    );
    $porIp->execute([ip_cliente(), LOGIN_JANELA_MIN]);
    $i = $porIp->fetch();

    $ate = null;
    foreach ([[$e, LOGIN_MAX_ERROS_EMAIL], [$i, LOGIN_MAX_ERROS_IP]] as [$r, $max]) {
        if ((int) $r['erros'] >= $max) {
            $fim = (new DateTimeImmutable($r['ultimo']))->modify('+' . LOGIN_BLOQUEIO_MIN . ' minutes');
            if ($fim > new DateTimeImmutable() && ($ate === null || $fim > $ate)) $ate = $fim;
        }
    }
    return $ate;
}

function registrar_tentativa_login(string $email, bool $sucesso): void
{
    db()->prepare('INSERT INTO tentativa_login (email, ip, data, sucesso) VALUES (?, ?, NOW(), ?)')
        ->execute([mb_substr($email, 0, 100), ip_cliente(), (int) $sucesso]);
}

/** Banco já possui a tabela tentativa_acao_ip (migração 038)? */
function seguranca_acao_ip_pronta(): bool
{
    return schema_flag('acao_ip', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'tentativa_acao_ip'"
    )->fetchColumn());
}

/** Banco já possui a coluna parceiro.sessao_versao (migração 038)? */
function parceiro_sessao_versao_pronta(): bool
{
    return schema_flag('parceiro_sessao_v', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'parceiro' AND column_name = 'sessao_versao'"
    )->fetchColumn());
}

/** O IP atual atingiu o limite horário da ação pública ('cadastro'|'parceiro_cadastro'|'redefinicao')? */
function acao_ip_bloqueada(string $acao): bool
{
    if (!isset(ACAO_IP_LIMITES[$acao]) || !seguranca_acao_ip_pronta()) {
        return false;
    }
    [$max, $minutos] = ACAO_IP_LIMITES[$acao];
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM tentativa_acao_ip
          WHERE acao = ? AND ip = ? AND data > NOW() - INTERVAL ? MINUTE'
    );
    $stmt->execute([$acao, ip_cliente(), $minutos]);
    return (int) $stmt->fetchColumn() >= $max;
}

/** Registra uma tentativa pública por IP para controle de abuso e spam. */
function registrar_acao_ip(string $acao): void
{
    if (!isset(ACAO_IP_LIMITES[$acao]) || !seguranca_acao_ip_pronta()) {
        return;
    }
    db()->prepare('INSERT INTO tentativa_acao_ip (acao, ip, data) VALUES (?, ?, NOW())')
        ->execute([$acao, ip_cliente()]);
}

/** Neutraliza fórmulas em células CSV sem alterar números negativos legítimos. */
function csv_celula_segura(mixed $valor): mixed
{
    if (is_int($valor) || is_float($valor) || $valor === null) {
        return $valor;
    }
    $s = (string) $valor;
    if ($s !== '' && preg_match('/^[\t\r\n]*[=+\-@]|^[\t\r]/', $s) === 1
        && preg_match('/^-[0-9]+([.,][0-9]+)?%?$/', $s) !== 1
    ) {
        return "'" . $s;
    }
    return $s;
}

/** Situação e versão da sessão da conta (uma consulta por pedido). */
function situacao_conta(int $usuarioId, bool $recarregar = false): array
{
    static $cache = [];
    if ($recarregar || !array_key_exists($usuarioId, $cache)) {
        try {
            $stmt = db()->prepare('SELECT status, sessao_versao FROM usuario WHERE usuario_id = ?');
            $stmt->execute([$usuarioId]);
        } catch (PDOException) {   // banco sem a migração 029
            $stmt = db()->prepare('SELECT status, 0 AS sessao_versao FROM usuario WHERE usuario_id = ?');
            $stmt->execute([$usuarioId]);
        }
        $u = $stmt->fetch();
        $cache[$usuarioId] = ['ativa' => $u !== false && (bool) $u['status'], 'versao' => $u ? (int) $u['sessao_versao'] : 0];
    }
    return $cache[$usuarioId];
}

/** Conta ativa? (usado para derrubar a sessão de contas bloqueadas) */
function conta_ativa(int $usuarioId): bool
{
    return situacao_conta($usuarioId)['ativa'];
}

/** Versão atual das sessões da conta (RN93). */
function versao_sessao(int $usuarioId): int
{
    return situacao_conta($usuarioId)['versao'];
}

/* ---------------------------------------------------------------------------
   Redefinição de senha
   --------------------------------------------------------------------------- */

/**
 * Cria um link de redefinição para o e-mail, se a conta existir e puder redefinir.
 * @param string $tipo 'jogador' | 'parceiro'
 * @return array{token: string, nome: string, email: string}|null  null quando não há o que enviar
 */
function criar_redefinicao(string $email, string $tipo = 'jogador'): ?array
{
    ['tabela' => $tab, 'conta' => $conta, 'id' => $id, 'ativa' => $ativa, 'nome' => $nome] = CONTAS_REDEFINICAO[$tipo];
    $pdo = db();
    $stmt = $pdo->prepare("SELECT c.{$id} AS id, c.{$nome} AS nome, c.email FROM {$conta} c WHERE c.email = ? AND {$ativa}");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) return null;

    $recentes = $pdo->prepare("SELECT COUNT(*) FROM {$tab} WHERE {$id} = ? AND data_criacao > NOW() - INTERVAL 1 HOUR");
    $recentes->execute([$u['id']]);
    if ((int) $recentes->fetchColumn() >= REDEFINICAO_MAX_POR_HORA) return null;

    $token = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        // Só o link mais recente vale
        $pdo->prepare("UPDATE {$tab} SET data_expiracao = NOW() WHERE {$id} = ? AND data_uso IS NULL AND data_expiracao > NOW()")
            ->execute([$u['id']]);
        $pdo->prepare("INSERT INTO {$tab} ({$id}, token_hash, data_criacao, data_expiracao) VALUES (?, ?, NOW(), NOW() + INTERVAL ? MINUTE)")
            ->execute([$u['id'], hash('sha256', $token), REDEFINICAO_VALIDADE_MIN]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['token' => $token, 'nome' => (string) ($u['nome'] ?? ''), 'email' => $u['email']];
}

/** Redefinição válida (não usada, não expirada, conta que pode redefinir) para o token, ou null. */
function redefinicao_valida(string $token, bool $bloquear = false, string $tipo = 'jogador'): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    ['tabela' => $tab, 'chave' => $chave, 'conta' => $conta, 'id' => $id, 'ativa' => $ativa] = CONTAS_REDEFINICAO[$tipo];
    $stmt = db()->prepare(
        "SELECT r.{$chave} AS redefinicao_id, r.{$id} AS conta_id, c.email FROM {$tab} r
           JOIN {$conta} c ON c.{$id} = r.{$id}
          WHERE r.token_hash = ? AND r.data_uso IS NULL AND r.data_expiracao > NOW() AND {$ativa}"
        . ($bloquear ? ' FOR UPDATE' : '')
    );
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch() ?: null;
}

/**
 * Define a nova senha a partir do token. Retorna os erros por campo ([] = sucesso).
 * @param string $tipo 'jogador' | 'parceiro'
 */
function redefinir_senha(string $token, string $nova, string $confirmar, string $tipo = 'jogador'): array
{
    ['tabela' => $tab, 'chave' => $chave, 'conta' => $conta, 'id' => $id, 'login' => $prefixo] = CONTAS_REDEFINICAO[$tipo];
    if (strlen($nova) < SENHA_MIN || strlen($nova) > SENHA_MAX) {
        return ['senha' => 'A senha deve ter de ' . SENHA_MIN . ' a ' . SENHA_MAX . ' caracteres.'];
    }
    if ($nova !== $confirmar) {
        return ['confirmar-senha' => 'As senhas não coincidem.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = redefinicao_valida($token, true, $tipo);
        if (!$r) {
            $pdo->rollBack();
            return ['geral' => 'Este link expirou ou já foi usado. Peça um novo.'];
        }
        $hash = password_hash($nova, PASSWORD_DEFAULT);
        if ($tipo === 'jogador') {
            try {
                $pdo->prepare("UPDATE {$conta} SET senha = ?, sessao_versao = sessao_versao + 1 WHERE {$id} = ?")
                    ->execute([$hash, $r['conta_id']]);
            } catch (PDOException) {
                $pdo->prepare("UPDATE {$conta} SET senha = ? WHERE {$id} = ?")->execute([$hash, $r['conta_id']]);
            }
        } elseif ($tipo === 'parceiro' && parceiro_sessao_versao_pronta()) {
            $pdo->prepare("UPDATE {$conta} SET senha = ?, sessao_versao = sessao_versao + 1 WHERE {$id} = ?")
                ->execute([$hash, $r['conta_id']]);
        } else {
            $pdo->prepare("UPDATE {$conta} SET senha = ? WHERE {$id} = ?")->execute([$hash, $r['conta_id']]);
        }
        $pdo->prepare("UPDATE {$tab} SET data_uso = NOW() WHERE {$chave} = ?")->execute([$r['redefinicao_id']]);
        $pdo->prepare("UPDATE {$tab} SET data_expiracao = NOW() WHERE {$id} = ? AND data_uso IS NULL")->execute([$r['conta_id']]);
        // Libera o login bloqueado por erros anteriores
        $pdo->prepare('INSERT INTO tentativa_login (email, ip, data, sucesso) VALUES (?, ?, NOW(), TRUE)')
            ->execute([mb_substr($prefixo . $r['email'], 0, 100), ip_cliente()]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return [];
}

/** Endereço público do site, usado nos links enviados por e-mail (config 'url_base'). */
function url_site(string $caminho): string
{
    return rtrim((string) (config('url_base') ?: 'http://localhost/ecocity2'), '/') . '/' . ltrim($caminho, '/');
}
