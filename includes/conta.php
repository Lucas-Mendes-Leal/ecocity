<?php
/**
 * Regras de conta: cadastro, autenticação e dados do jogador.
 */
declare(strict_types=1);

const SENHA_MIN = 6;
const SENHA_MAX = 72;   // limite do bcrypt (password_hash)

/**
 * Chave usada no frontend para cada indicador (assets/js/data/catalog.js).
 * Os IDs vêm de database/dados-estruturais.sql.
 */
const INDICADOR_CHAVE = [1 => 'recycling', 2 => 'water', 3 => 'energy', 4 => 'mobility', 5 => 'nature'];

function texto(string $campo): string
{
    $v = $_POST[$campo] ?? '';
    return is_string($v) ? trim($v) : '';
}

function tamanho(string $s): int
{
    return mb_strlen($s, 'UTF-8');
}

/**
 * Valida os dados do cadastro.
 * @return array<string, string> erros por campo (vazio = válido)
 */
function validar_cadastro(array $d): array
{
    $erros = [];
    if (tamanho($d['nome']) < 3 || tamanho($d['nome']) > 100) $erros['nome'] = 'Informe seu nome (3 a 100 caracteres).';
    if (tamanho($d['apelido']) < 3 || tamanho($d['apelido']) > 100) $erros['apelido'] = 'O apelido deve ter de 3 a 100 caracteres.';
    if (tamanho($d['cidade']) < 3 || tamanho($d['cidade']) > 100) $erros['cidade'] = 'O nome da cidade deve ter de 3 a 100 caracteres.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || tamanho($d['email']) > 100) $erros['email'] = 'Informe um e-mail válido.';
    if (strlen($d['senha']) < SENHA_MIN || strlen($d['senha']) > SENHA_MAX) $erros['senha'] = 'A senha deve ter de ' . SENHA_MIN . ' a ' . SENHA_MAX . ' caracteres.';
    if ($d['senha'] !== $d['confirmar']) $erros['confirmar-senha'] = 'As senhas não coincidem.';
    return $erros;
}

/** Código público do jogador: ECO-XXXX-XXXX, sem caracteres ambíguos (D2). */
function gerar_codigo_publico(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $parte = static function () use ($alfabeto): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return $s;
    };
    return 'ECO-' . $parte() . '-' . $parte();
}

/**
 * Cria usuário, jogador, cidade e indicadores da cidade numa única transação.
 * Novo jogador começa com 0 créditos, 0 XP e indicadores no valor mínimo.
 * @return array<string, string> erros (vazio = sucesso)
 */
function cadastrar_conta(array $d): array
{
    $pdo = db();

    $existe = $pdo->prepare('SELECT 1 FROM usuario WHERE email = ?');
    $existe->execute([$d['email']]);
    if ($existe->fetchColumn()) {
        return ['email' => 'Já existe uma conta com este e-mail.'];
    }

    $nivelInicial = $pdo->query('SELECT nivel_cidade_id FROM nivel_cidade ORDER BY nivel LIMIT 1')->fetchColumn();
    $indicadores = $pdo->query('SELECT indicador_ambiental_id, valor_minimo FROM indicador_ambiental')->fetchAll();
    if (!$nivelInicial || !$indicadores) {
        error_log('EcoCity — dados estruturais ausentes (nivel_cidade/indicador_ambiental).');
        return ['geral' => 'O cadastro está indisponível: os dados estruturais do banco ainda não foram carregados.'];
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('INSERT INTO usuario (nome, email, senha, data_cadastro) VALUES (?, ?, ?, CURDATE())')
            ->execute([$d['nome'], $d['email'], password_hash($d['senha'], PASSWORD_DEFAULT)]);
        $usuarioId = (int) $pdo->lastInsertId();

        $insereJogador = $pdo->prepare(
            'INSERT INTO jogador (apelido, numero_conta, data_criacao, nivel_id, creditos_verde, xp_atual, usuario_id)
             VALUES (?, ?, CURDATE(), (SELECT nivel_jogador_id FROM nivel_jogador ORDER BY xp_minimo LIMIT 1), 0, 0, ?)'   // começa no nível 1
        );
        for ($tentativa = 0; ; $tentativa++) {
            try {
                $insereJogador->execute([$d['apelido'], gerar_codigo_publico(), $usuarioId]);
                break;
            } catch (PDOException $ex) {
                // 23000 = código público repetido; tenta outro algumas vezes
                if ($ex->getCode() !== '23000' || $tentativa >= 4) throw $ex;
            }
        }
        $jogadorId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO cidade (nome, nivel_cidade_id, jogador_id) VALUES (?, ?, ?)')
            ->execute([$d['cidade'], $nivelInicial, $jogadorId]);
        $cidadeId = (int) $pdo->lastInsertId();

        $insereIndicador = $pdo->prepare('INSERT INTO cidade_indicador (cidade_id, indicador_ambiental_id, valor) VALUES (?, ?, ?)');
        foreach ($indicadores as $ind) {
            $insereIndicador->execute([$cidadeId, $ind['indicador_ambiental_id'], $ind['valor_minimo']]);
        }
        sincronizar_cidade($pdo, $jogadorId);   // nível inicial da cidade

        $pdo->commit();
        return [];
    } catch (PDOException $ex) {
        $pdo->rollBack();
        // Corrida rara: outro cadastro com o mesmo e-mail entre a checagem e o INSERT
        if ($ex->getCode() === '23000' && str_contains($ex->getMessage(), 'uq_usuario_email')) {
            return ['email' => 'Já existe uma conta com este e-mail.'];
        }
        error_log('EcoCity — falha no cadastro: ' . $ex->getMessage());
        return ['geral' => 'Não foi possível concluir o cadastro. Tente novamente.'];
    }
}

/** Confere e-mail e senha (e se a conta está ativa). Retorna o usuario_id ou null. */
function autenticar(string $email, string $senha, bool &$reativada = false): ?int
{
    $stmt = db()->prepare('SELECT usuario_id, senha, status, desativada_em FROM usuario WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($senha, $u['senha'])) {
        return null;
    }
    if (!$u['status']) {
        // Desativada pelo próprio jogador: a senha certa reativa (RN93). Bloqueio da administração continua.
        if ($u['desativada_em'] === null) return null;   // mesma resposta para senha errada e conta bloqueada
        db()->prepare('UPDATE usuario SET status = TRUE, desativada_em = NULL WHERE usuario_id = ?')->execute([$u['usuario_id']]);
        situacao_conta((int) $u['usuario_id'], true);
        $reativada = true;
    }
    if (password_needs_rehash($u['senha'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE usuario SET senha = ? WHERE usuario_id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $u['usuario_id']]);
    }
    return (int) $u['usuario_id'];
}

/**
 * Dados do jogador logado para o painel do jogo.
 * @return array<string, mixed>|null
 */
/** Se o jogador já concluiu ou pulou o tutorial de boas-vindas (RN90; NULL = ainda não viu). */
function tutorial_visto(int $usuarioId): bool
{
    $s = db()->prepare('SELECT tutorial_visto IS NOT NULL FROM jogador WHERE usuario_id = ?');
    $s->execute([$usuarioId]);
    return (bool) $s->fetchColumn();
}

/** Marca o tutorial como visto (só na primeira vez; rever não muda a data). */
function marcar_tutorial_visto(int $jogadorId): void
{
    db()->prepare('UPDATE jogador SET tutorial_visto = NOW() WHERE jogador_id = ? AND tutorial_visto IS NULL')->execute([$jogadorId]);
}

function carregar_jogador(int $usuarioId): ?array
{
    $stmt = db()->prepare(
        'SELECT apelido, numero_conta, creditos_verde, xp_atual, nivel, cidade_id, cidade
           FROM vw_jogador_resumo WHERE usuario_id = ?'   // view da migração 025
    );
    $stmt->execute([$usuarioId]);
    $j = $stmt->fetch();
    if (!$j) {
        return null;
    }

    $ind = db()->prepare('SELECT indicador_ambiental_id, valor FROM cidade_indicador WHERE cidade_id = ?');
    $ind->execute([$j['cidade_id']]);
    $indicadores = [];
    foreach ($ind->fetchAll() as $row) {
        $chave = INDICADOR_CHAVE[(int) $row['indicador_ambiental_id']] ?? null;
        if ($chave) $indicadores[$chave] = (int) $row['valor'];
    }

    return [
        'apelido'       => $j['apelido'],
        'codigoPublico' => $j['numero_conta'],
        'cidade'        => $j['cidade'],
        'nivel'         => $j['nivel'] !== null ? (int) $j['nivel'] : 1,  // nivel_jogador ainda sem dados
        'xpAtual'       => (int) $j['xp_atual'],
        'creditosVerde' => (int) $j['creditos_verde'],
        'tutorialVisto' => tutorial_visto($usuarioId),
        'indicadores'   => $indicadores,
    ];
}
