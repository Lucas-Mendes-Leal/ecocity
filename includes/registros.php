<?php
/**
 * Registro e aprovação de atividades sustentáveis.
 *
 * Regras (specs/site.md §7):
 *  - Autodeclarada (tipo 1): aprovada na hora, paga PERCENTUAL_AUTODECLARADA% da recompensa.
 *  - Com evidência (tipo 2): foto obrigatória; fica pendente até um administrador aprovar
 *    ou é aprovada automaticamente após PRAZO_APROVACAO_HORAS. Paga 100%.
 *  - Verificada por parceiro (tipo 3): criada só pelo código do parceiro (includes/parceiros.php),
 *    aprovada na hora, paga PERCENTUAL_PARCEIRO% e guarda a quantidade em impacto_real (RN69–RN73).
 *  - A recompensa é concedida uma única vez, na transição pendente → aprovado (RN05).
 */
declare(strict_types=1);

const TIPO_AUTODECLARADA = 1;
const TIPO_EVIDENCIA = 2;
const TIPO_PARCEIRO = 3;
const TIPOS_DISPONIVEIS = [TIPO_AUTODECLARADA, TIPO_EVIDENCIA];   // do formulário do jogador; o 3 vem só do código do parceiro
const PERCENTUAL_AUTODECLARADA = 50;   // aprovado em 17/11/2026
const PERCENTUAL_SEM_FOTO = 100;       // atividade sem como ter foto: valor cheio (RN92, 18/11/2026)
const PERCENTUAL_PARCEIRO = 150;       // RN72 (provisório)
const REGISTROS_POR_ATIVIDADE_DIA = 1; // RN44: pendentes e aprovados ocupam a vaga; rejeitado libera
const PRAZO_APROVACAO_HORAS = 24;
const DESCRICAO_MAX = 200;

const FOTO_MAX_BYTES = 5 * 1024 * 1024;
const FOTO_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const PASTA_EVIDENCIAS = ECOCITY_ROOT . '/uploads/evidencias';

/** Erro de validação com mensagem para o usuário. */
final class RegistroInvalido extends RuntimeException {}

/* ---------------------------------------------------------------------------
   Leituras
   --------------------------------------------------------------------------- */

function jogador_do_usuario(int $usuarioId): ?array
{
    $stmt = db()->prepare(
        'SELECT j.jogador_id, c.cidade_id FROM jogador j JOIN cidade c ON c.jogador_id = j.jogador_id WHERE j.usuario_id = ?'
    );
    $stmt->execute([$usuarioId]);
    return $stmt->fetch() ?: null;
}

/** Atividades ativas com impacto e chave do indicador usada no frontend. */
function listar_atividades(): array
{
    $rows = db()->query(
        'SELECT a.atividade_sustentavel_id AS id, a.nome, a.descricao, a.recompensa_xp, a.recompensa_credito, a.aceita_foto,
                ai.indicador_ambiental_id, ai.impacto
           FROM atividade_sustentavel a
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
          WHERE a.ativa = TRUE
       ORDER BY a.atividade_sustentavel_id'
    )->fetchAll();

    return array_map(static fn(array $r): array => [
        'id'                => (int) $r['id'],
        'categoria'         => INDICADOR_CHAVE[(int) $r['indicador_ambiental_id']] ?? null,
        'nome'              => $r['nome'],
        'descricao'         => $r['descricao'],
        'aceitaFoto'        => (bool) $r['aceita_foto'],   // false: só sem foto, valor cheio (RN92)
        'recompensas'       => [
            TIPO_EVIDENCIA     => recompensa_por_tipo($r, TIPO_EVIDENCIA),
            TIPO_AUTODECLARADA => recompensa_por_tipo($r, TIPO_AUTODECLARADA),
            TIPO_PARCEIRO      => recompensa_por_tipo($r, TIPO_PARCEIRO),
        ],
    ], $rows);
}

/** XP, créditos e impacto de uma atividade conforme o tipo de verificação. */
function recompensa_por_tipo(array $atividade, int $tipo): array
{
    $semFoto = array_key_exists('aceita_foto', $atividade) && !(bool) $atividade['aceita_foto'];
    $pct = match ($tipo) {
        TIPO_AUTODECLARADA => $semFoto ? PERCENTUAL_SEM_FOTO : PERCENTUAL_AUTODECLARADA,
        TIPO_PARCEIRO => PERCENTUAL_PARCEIRO,
        default => 100,
    };
    $calc = static fn($v): int => intdiv((int) $v * $pct, 100);
    return [
        'xp'      => $calc($atividade['recompensa_xp']),
        'credito' => $calc($atividade['recompensa_credito']),
        'impacto' => $calc($atividade['impacto']),
    ];
}

function niveis_jogador(): array
{
    return array_map(
        static fn(array $r): array => ['nivel' => (int) $r['nivel'], 'min' => (int) $r['xp_minimo'], 'max' => (int) $r['xp_maximo']],
        db()->query('SELECT nivel, xp_minimo, xp_maximo FROM nivel_jogador ORDER BY nivel')->fetchAll()
    );
}

/** Registros do jogador (mais recentes primeiro). */
function listar_registros(int $jogadorId): array
{
    $stmt = db()->prepare(
        'SELECT r.registro_atividade_id AS id, r.atividade_sustentavel_id, r.tipo_verificacao_id, r.status,
                r.data_registro, r.prazo_aprovacao, r.data_aprovacao, r.aprovado_por_administrador_id, r.motivo_rejeicao,
                e.descricao, e.arquivo, r.impacto_real, a.impacto_real_unidade, p.nome AS parceiro,
                (SELECT COALESCE(SUM(x.quantidade), 0) FROM historico_xp x WHERE x.registro_atividade_id = r.registro_atividade_id) AS ganho_xp,
                (SELECT COALESCE(SUM(c.quantidade), 0) FROM historico_credito_verde c WHERE c.registro_atividade_id = r.registro_atividade_id) AS ganho_credito
           FROM registro_atividade r
      LEFT JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
      LEFT JOIN verificacao_parceiro vp ON vp.registro_atividade_id = r.registro_atividade_id
      LEFT JOIN parceiro p ON p.parceiro_id = vp.parceiro_id
          WHERE r.jogador_id = ?
       ORDER BY r.data_registro DESC, r.registro_atividade_id DESC'
    );
    $stmt->execute([$jogadorId]);
    $linhas = $stmt->fetchAll();
    $ia = ia_banco_pronto() ? ia_pareceres(array_column($linhas, 'id')) : [];
    $contestacoes = contestacoes_por_registro(array_column($linhas, 'id'));
    $contestavel = contestacao_banco_pronto();

    return array_map(static fn(array $r): array => [
        'id'              => (int) $r['id'],
        'atividadeId'     => (int) $r['atividade_sustentavel_id'],
        'tipo'            => (int) $r['tipo_verificacao_id'],
        'status'          => $r['status'],
        'data'            => date(DATE_ATOM, strtotime($r['data_registro'])),
        'aprovadoEm'      => $r['data_aprovacao'] ? date(DATE_ATOM, strtotime($r['data_aprovacao'])) : null,
        'aprovacaoAuto'   => $r['status'] === 'aprovado' && $r['aprovado_por_administrador_id'] === null,
        'descricao'       => $r['descricao'],
        'temFoto'         => $r['arquivo'] !== null,
        'motivoRejeicao'  => $r['motivo_rejeicao'],
        'ganhoXp'         => (int) $r['ganho_xp'],        // o que foi realmente pago (históricos)
        'ganhoCredito'    => (int) $r['ganho_credito'],
        'parceiro'        => $r['parceiro'],                // verificada por parceiro (tipo 3)
        'quantidade'      => $r['impacto_real'] !== null ? (float) $r['impacto_real'] : null,
        'unidade'         => $r['impacto_real_unidade'],
        'prazo'           => date(DATE_ATOM, strtotime($r['prazo_aprovacao'] ?? $r['data_registro'] . ' +' . PRAZO_APROVACAO_HORAS . ' hours')),
        'ia'              => isset($ia[(int) $r['id']]) ? array_intersect_key($ia[(int) $r['id']], array_flip(['acao', 'motivo', 'veredito'])) : null,
        // Contestação (RN94): a que existe ou até quando dá para contestar
        'contestacao'     => $contestacoes[(int) $r['id']] ?? null,
        'contestarAte'    => $contestavel && !isset($contestacoes[(int) $r['id']]) && $r['status'] === 'rejeitado'
                             && (int) $r['tipo_verificacao_id'] === TIPO_EVIDENCIA && $r['arquivo'] !== null && $r['data_aprovacao']
                             && strtotime($r['data_aprovacao'] . ' +' . CONTESTACAO_PRAZO_DIAS . ' days') > time()
                             ? date(DATE_ATOM, strtotime($r['data_aprovacao'] . ' +' . CONTESTACAO_PRAZO_DIAS . ' days')) : null,
    ], $linhas);
}

/* ---------------------------------------------------------------------------
   Foto de evidência
   --------------------------------------------------------------------------- */

/** Regrava a imagem via GD para remover metadados EXIF/GPS e chunks inválidos. */
function regravar_foto_sem_metadados(string $origem, string $destino, string $mime): void
{
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($origem),
        'image/png'  => @imagecreatefrompng($origem),
        'image/webp' => @imagecreatefromwebp($origem),
        default      => false,
    };
    if (!$img instanceof GdImage) {
        throw new RegistroInvalido('A foto está corrompida ou não é uma imagem válida.');
    }

    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($origem);
        $angulo = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angulo !== 0) {
            $rot = @imagerotate($img, $angulo, 0);
            if ($rot instanceof GdImage) {
                imagedestroy($img);
                $img = $rot;
            }
        }
    } elseif ($mime === 'image/png' || $mime === 'image/webp') {
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    $ok = match ($mime) {
        'image/jpeg' => @imagejpeg($img, $destino, 88),
        'image/png'  => @imagepng($img, $destino, 6),
        'image/webp' => @imagewebp($img, $destino, 85),
        default      => false,
    };
    imagedestroy($img);

    if (!$ok || !is_file($destino) || filesize($destino) === 0) {
        @unlink($destino);
        throw new RuntimeException('Falha ao gravar a foto.');
    }
    @chmod($destino, 0640);
}

/** Valida e grava a foto enviada. Retorna o nome do arquivo salvo. */
function salvar_foto(?array $arquivo): string
{
    if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RegistroInvalido('Envie uma foto como evidência.');
    }
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE || $arquivo['size'] > FOTO_MAX_BYTES) {
        throw new RegistroInvalido('A foto deve ter no máximo 5 MB.');
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
        throw new RegistroInvalido('Não foi possível receber a foto. Tente novamente.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
    $info = @getimagesize($arquivo['tmp_name']);
    if (!isset(FOTO_TIPOS[$mime]) || $info === false) {
        throw new RegistroInvalido('A foto deve ser JPG, PNG ou WebP.');
    }
    if ($info[0] <= 0 || $info[1] <= 0 || $info[0] > 8000 || $info[1] > 8000 || ($info[0] * $info[1]) > 36000000) {
        throw new RegistroInvalido('A foto tem dimensões inválidas ou excessivas.');
    }

    if (!is_dir(PASTA_EVIDENCIAS) && !mkdir(PASTA_EVIDENCIAS, 0750, true)) {
        throw new RuntimeException('Pasta de evidências indisponível.');
    }
    $nome = bin2hex(random_bytes(16)) . '.' . FOTO_TIPOS[$mime];
    $destino = PASTA_EVIDENCIAS . '/' . $nome;

    $gdPronto = match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
        'image/png'  => function_exists('imagecreatefrompng') && function_exists('imagepng'),
        'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
        default      => false,
    };
    if ($gdPronto) {
        regravar_foto_sem_metadados($arquivo['tmp_name'], $destino, $mime);
    } else {
        if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
            throw new RuntimeException('Falha ao gravar a foto.');
        }
        @chmod($destino, 0640);
    }
    return $nome;
}

/* ---------------------------------------------------------------------------
   Ações
   --------------------------------------------------------------------------- */

/**
 * Limite diário (RN44), qualquer que seja o tipo. Bloqueia o jogador (FOR UPDATE) para valer
 * mesmo com envios simultâneos; deve rodar dentro de uma transação.
 */
function verificar_limite_diario(PDO $pdo, int $jogadorId, int $atividadeId): void
{
    $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$jogadorId]);
    $hoje = $pdo->prepare(
        "SELECT COUNT(*) FROM registro_atividade
          WHERE jogador_id = ? AND atividade_sustentavel_id = ?
            AND (status <> 'rejeitado'" . (contestacao_banco_pronto() ? " OR EXISTS (SELECT 1 FROM contestacao c
                 WHERE c.registro_atividade_id = registro_atividade.registro_atividade_id AND c.status = 'aberta')" : '') . ")
            AND data_registro >= CURDATE() AND data_registro < CURDATE() + INTERVAL 1 DAY"
    );
    $hoje->execute([$jogadorId, $atividadeId]);
    if ((int) $hoje->fetchColumn() >= REGISTROS_POR_ATIVIDADE_DIA) {
        throw new RegistroInvalido('Você já registrou esta atividade hoje. Volte amanhã!');
    }
}

/**
 * Cria um registro. Autodeclarada já sai aprovada; com evidência fica pendente.
 * @return array{registroId: int, status: string}
 */
function criar_registro(int $jogadorId, int $atividadeId, int $tipo, string $descricao, ?array $foto): array
{
    if (!in_array($tipo, TIPOS_DISPONIVEIS, true)) {
        throw new RegistroInvalido('Escolha como deseja comprovar a atividade.');
    }
    if (mb_strlen($descricao, 'UTF-8') > DESCRICAO_MAX) {
        throw new RegistroInvalido('A descrição deve ter no máximo ' . DESCRICAO_MAX . ' caracteres.');
    }
    $ativa = db()->prepare('SELECT aceita_foto FROM atividade_sustentavel WHERE atividade_sustentavel_id = ? AND ativa = TRUE');
    $ativa->execute([$atividadeId]);
    $aceitaFoto = $ativa->fetchColumn();
    if ($aceitaFoto === false) {
        throw new RegistroInvalido('Atividade não encontrada.');
    }
    if ($tipo === TIPO_EVIDENCIA && !(bool) $aceitaFoto) {
        throw new RegistroInvalido('Esta atividade não tem como ser comprovada por foto: registre sem foto (vale o valor cheio).');
    }

    $arquivo = $tipo === TIPO_EVIDENCIA ? salvar_foto($foto) : null;
    $pdo = db();

    try {
        $pdo->beginTransaction();
        verificar_limite_diario($pdo, $jogadorId, $atividadeId);
        $pdo->prepare(
            "INSERT INTO registro_atividade (jogador_id, atividade_sustentavel_id, missao_atividade_id, tipo_verificacao_id, data_registro, status)
             VALUES (?, ?, NULL, ?, NOW(), 'pendente')"
        )->execute([$jogadorId, $atividadeId, $tipo]);
        $registroId = (int) $pdo->lastInsertId();

        if ($arquivo !== null || $descricao !== '') {
            $pdo->prepare('INSERT INTO evidencia_atividade (registro_atividade_id, arquivo, data_envio, descricao) VALUES (?, ?, CURDATE(), ?)')
                ->execute([$registroId, $arquivo, $descricao !== '' ? $descricao : null]);
        }

        if ($tipo === TIPO_AUTODECLARADA) {
            aprovar_registro($pdo, $registroId, null);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($arquivo !== null) @unlink(PASTA_EVIDENCIAS . '/' . $arquivo);
        throw $ex;
    }

    return ['registroId' => $registroId, 'status' => $tipo === TIPO_AUTODECLARADA ? 'aprovado' : 'pendente'];
}

/**
 * Aprova um registro pendente e aplica a recompensa. Deve rodar dentro de uma transação.
 * O bloqueio FOR UPDATE + checagem de status impede recompensa em dobro.
 * @return bool true se aprovou agora; false se já não estava pendente
 */
function aprovar_registro(PDO $pdo, int $registroId, ?int $administradorId, bool $deContestacao = false): bool
{
    // Contestação aceita (RN94): o único caso em que um registro rejeitado passa a aprovado
    $situacao = $deContestacao ? 'rejeitado' : 'pendente';
    $stmt = $pdo->prepare(
        "SELECT r.jogador_id, r.tipo_verificacao_id, a.recompensa_xp, a.recompensa_credito, a.aceita_foto,
                ai.indicador_ambiental_id, ai.impacto, c.cidade_id
           FROM registro_atividade r
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
           JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
           JOIN cidade c ON c.jogador_id = r.jogador_id
          WHERE r.registro_atividade_id = ? AND r.status = ?
          FOR UPDATE"
    );
    $stmt->execute([$registroId, $situacao]);
    $r = $stmt->fetch();
    if (!$r) {
        return false;
    }

    $rec = recompensa_por_tipo($r, (int) $r['tipo_verificacao_id']);
    $jogadorId = (int) $r['jogador_id'];

    $pdo->prepare(
        "UPDATE registro_atividade SET status = 'aprovado', data_aprovacao = NOW(), aprovado_por_administrador_id = ?, motivo_rejeicao = NULL
          WHERE registro_atividade_id = ?"
    )->execute([$administradorId, $registroId]);

    creditar_jogador($pdo, $jogadorId, $rec['xp'], $rec['credito'], 'atividade', 'registro_atividade_id', $registroId);

    // Impacto no indicador, limitado ao mínimo/máximo (RN16)
    $pdo->prepare(
        'UPDATE cidade_indicador ci
           JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ci.indicador_ambiental_id
            SET ci.valor = LEAST(ia.valor_maximo, GREATEST(ia.valor_minimo, ci.valor + ?))
          WHERE ci.cidade_id = ? AND ci.indicador_ambiental_id = ?'
    )->execute([$rec['impacto'], $r['cidade_id'], $r['indicador_ambiental_id']]);

    $pdo->prepare(
        "INSERT INTO historico_indicador_cidade (cidade_id, indicador_ambiental_id, valor, data, origem)
         SELECT cidade_id, indicador_ambiental_id, valor, NOW(), 'atividade' FROM cidade_indicador
          WHERE cidade_id = ? AND indicador_ambiental_id = ?"
    )->execute([$r['cidade_id'], $r['indicador_ambiental_id']]);

    // Novo nível da cidade, edifícios liberados (RN22) e conquistas atingidas
    sincronizar_cidade($pdo, $jogadorId);
    avaliar_missoes($pdo, $jogadorId);
    avaliar_conquistas($pdo, $jogadorId);
    registrar_contribuicao($pdo, $registroId);   // comunidade do jogador (RN66–RN67)

    return true;
}

/**
 * Soma XP (total) e créditos ao jogador, recalcula o nível pela faixa de
 * nivel_jogador e grava os históricos com a origem rastreável (D8).
 * Deve rodar dentro de uma transação.
 * @param string $colunaOrigem  'registro_atividade_id' | 'tentativa_id' | 'conquista_id' | 'missao_id' | 'comunidade_missao_id'
 *                              | 'construcao_id' (renda do comércio: só créditos, o histórico de XP não tem essa coluna)
 *                              | 'codigo_promocional_id' | 'ajuste_admin_id'
 * Lançamentos de valor 0 não geram histórico.
 */
function creditar_jogador(PDO $pdo, int $jogadorId, int $xp, int $credito, string $origem, string $colunaOrigem, int $idOrigem): void
{
    if (!in_array($colunaOrigem, ['registro_atividade_id', 'tentativa_id', 'conquista_id', 'missao_id', 'comunidade_missao_id', 'construcao_id', 'codigo_promocional_id', 'ajuste_admin_id'], true)) {
        throw new InvalidArgumentException('Origem inválida.');
    }
    if ($colunaOrigem === 'construcao_id' && $xp !== 0) throw new InvalidArgumentException('Renda de estrutura não dá XP.');
    $pdo->prepare('UPDATE jogador SET xp_atual = xp_atual + ?, creditos_verde = creditos_verde + ? WHERE jogador_id = ?')
        ->execute([$xp, $credito, $jogadorId]);
    $pdo->prepare(
        'UPDATE jogador j
            SET j.nivel_id = (SELECT nj.nivel_jogador_id FROM nivel_jogador nj
                               WHERE j.xp_atual BETWEEN nj.xp_minimo AND nj.xp_maximo LIMIT 1)
          WHERE j.jogador_id = ?'
    )->execute([$jogadorId]);
    if ($xp !== 0) {
        $pdo->prepare("INSERT INTO historico_xp (jogador_id, quantidade, origem, data, $colunaOrigem) VALUES (?, ?, ?, NOW(), ?)")
            ->execute([$jogadorId, $xp, $origem, $idOrigem]);
    }
    if ($credito !== 0) {
        $pdo->prepare("INSERT INTO historico_credito_verde (jogador_id, quantidade, origem, data, $colunaOrigem) VALUES (?, ?, ?, NOW(), ?)")
            ->execute([$jogadorId, $credito, $origem, $idOrigem]);
    }
}

function rejeitar_registro(PDO $pdo, int $registroId, string $motivo, ?int $administradorId): bool
{
    $stmt = $pdo->prepare(
        "UPDATE registro_atividade SET status = 'rejeitado', motivo_rejeicao = ?, aprovado_por_administrador_id = ?, data_aprovacao = NOW()
          WHERE registro_atividade_id = ? AND status = 'pendente'"
    );
    $stmt->execute([mb_substr($motivo, 0, 250, 'UTF-8'), $administradorId, $registroId]);
    return $stmt->rowCount() === 1;
}

/**
 * Aprova automaticamente os registros com evidência pendentes há mais de 1 dia.
 * Executado quando o jogador abre o jogo/usa a API (só os dele) e quando um
 * administrador abre a administração (todos) — não há agendador.
 * @param int|null $jogadorId null = todos os jogadores
 */
function aprovar_vencidos(?int $jogadorId): int
{
    $sql = "SELECT registro_atividade_id FROM registro_atividade
             WHERE status = 'pendente' AND tipo_verificacao_id = ?
               AND COALESCE(prazo_aprovacao, data_registro + INTERVAL " . PRAZO_APROVACAO_HORAS . ' HOUR) <= NOW()';   // 72 h nas duvidosas da IA (RN91)
    $params = [TIPO_EVIDENCIA];
    if ($jogadorId !== null) {
        $sql .= ' AND jogador_id = ?';
        $params[] = $jogadorId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $aprovados = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $aprovados += aprovar_registro($pdo, (int) $id, null) ? 1 : 0;
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log('EcoCity — falha na aprovação automática do registro ' . $id . ': ' . $ex->getMessage());
        }
    }
    return $aprovados;
}
