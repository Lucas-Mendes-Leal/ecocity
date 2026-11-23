<?php
/**
 * Contestação de registros rejeitados (RN94, migração 030).
 *
 * A IA aprova as fotos que comprovam a ação e rejeita as demais com o motivo (RN91).
 * O jogador pode contestar uma rejeição de registro com foto: uma vez, até 7 dias depois,
 * com uma explicação e, se quiser, outra foto. Um administrador (não o dono) aceita —
 * o registro passa a aprovado e paga 100%, uma vez — ou mantém a rejeição com uma resposta.
 * Enquanto a contestação está aberta, ela ocupa a vaga do dia daquela atividade (RN44).
 */
declare(strict_types=1);

const CONTESTACAO_PRAZO_DIAS = 7;
const CONTESTACAO_TEXTO_MAX = 500;
const CONTESTACAO_RESPOSTA_MAX = 250;

/** O banco tem a migração 030? (sem ela, ninguém contesta e tudo segue como antes) */
function contestacao_banco_pronto(): bool
{
    return schema_flag('contestacao', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'contestacao'"
    )->fetchColumn());
}

/**
 * O jogador contesta a rejeição de um registro com foto.
 * @throws RegistroInvalido
 */
function contestar_registro(int $jogadorId, int $registroId, string $texto, ?array $foto): string
{
    if (!contestacao_banco_pronto()) throw new RegistroInvalido('A contestação ainda não está disponível.');
    $texto = trim($texto);
    if ($texto === '') throw new RegistroInvalido('Explique por que a sua ação deve ser aprovada.');
    if (mb_strlen($texto, 'UTF-8') > CONTESTACAO_TEXTO_MAX) {
        throw new RegistroInvalido('A explicação deve ter no máximo ' . CONTESTACAO_TEXTO_MAX . ' caracteres.');
    }

    $pdo = db();
    $s = $pdo->prepare(
        "SELECT r.status, r.tipo_verificacao_id, r.atividade_sustentavel_id, r.data_registro, r.data_aprovacao, r.motivo_rejeicao,
                e.arquivo, (SELECT 1 FROM contestacao c WHERE c.registro_atividade_id = r.registro_atividade_id) AS ja_contestado
           FROM registro_atividade r
      LEFT JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
          WHERE r.registro_atividade_id = ? AND r.jogador_id = ?"
    );
    $s->execute([$registroId, $jogadorId]);
    $r = $s->fetch();
    if (!$r) throw new RegistroInvalido('Registro não encontrado.');
    if ($r['ja_contestado']) throw new RegistroInvalido('Este registro já foi contestado.');
    if ($r['status'] !== 'rejeitado' || (int) $r['tipo_verificacao_id'] !== TIPO_EVIDENCIA || !$r['arquivo']) {
        throw new RegistroInvalido('Só é possível contestar um registro com foto que foi rejeitado.');
    }
    if (strtotime($r['data_aprovacao'] . ' +' . CONTESTACAO_PRAZO_DIAS . ' days') < time()) {
        throw new RegistroInvalido('O prazo para contestar (' . CONTESTACAO_PRAZO_DIAS . ' dias depois da rejeição) acabou.');
    }

    // Foto nova opcional, com as mesmas regras do registro
    $arquivo = ($foto && ($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) ? salvar_foto($foto) : null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$jogadorId]);
        // A vaga do dia (RN44): outro registro da mesma atividade no mesmo dia impede a contestação
        $vaga = $pdo->prepare(
            "SELECT COUNT(*) FROM registro_atividade r
              WHERE r.jogador_id = ? AND r.atividade_sustentavel_id = ? AND r.registro_atividade_id <> ?
                AND DATE(r.data_registro) = DATE(?)
                AND (r.status <> 'rejeitado' OR EXISTS (SELECT 1 FROM contestacao c
                         WHERE c.registro_atividade_id = r.registro_atividade_id AND c.status = 'aberta'))"
        );
        $vaga->execute([$jogadorId, $r['atividade_sustentavel_id'], $registroId, $r['data_registro']]);
        if ((int) $vaga->fetchColumn() > 0) {
            throw new RegistroInvalido('Você já tem outro registro desta atividade no mesmo dia, então este não pode ser contestado.');
        }
        $pdo->prepare('INSERT INTO contestacao (registro_atividade_id, jogador_id, texto, arquivo, motivo_rejeicao_original, status, data)
                       VALUES (?, ?, ?, ?, ?, \'aberta\', NOW())')
            ->execute([$registroId, $jogadorId, $texto, $arquivo, $r['motivo_rejeicao']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        if ($arquivo) @unlink(PASTA_EVIDENCIAS . '/' . $arquivo);
        if ($ex instanceof PDOException && $ex->getCode() === '23000') throw new RegistroInvalido('Este registro já foi contestado.');
        throw $ex;
    }
    return 'Contestação enviada! Um administrador vai conferir a sua ação.';
}

/** Contestações dos registros do jogador: [registroId => [...]] */
function contestacoes_por_registro(array $registroIds): array
{
    $ids = array_values(array_filter(array_map('intval', $registroIds)));
    if (!$ids || !contestacao_banco_pronto()) return [];
    $s = db()->prepare('SELECT registro_atividade_id, status, resposta, data, data_decisao, arquivo IS NOT NULL AS tem_foto
                          FROM contestacao WHERE registro_atividade_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $s->execute($ids);
    $r = [];
    foreach ($s->fetchAll() as $c) {
        $r[(int) $c['registro_atividade_id']] = [
            'status'    => $c['status'],
            'resposta'  => $c['resposta'],
            'temFoto'   => (bool) $c['tem_foto'],
            'data'      => date(DATE_ATOM, strtotime($c['data'])),
            'decididaEm' => $c['data_decisao'] ? date(DATE_ATOM, strtotime($c['data_decisao'])) : null,
        ];
    }
    return $r;
}

/** Contestações para a administração (abertas primeiro, das mais antigas; depois as decididas recentes). */
function listar_contestacoes(int $usuarioAdmin, int $decididas = 100): array
{
    if (!contestacao_banco_pronto()) return [];
    $sql = "SELECT c.contestacao_id AS id, c.registro_atividade_id, c.texto, c.arquivo IS NOT NULL AS tem_foto_nova,
                   c.motivo_rejeicao_original, c.status, c.resposta, c.data, c.data_decisao,
                   r.data_registro, a.nome AS atividade, ia.indicador_ambiental_id, ca.nome AS categoria_nome,
                   j.apelido, ci.nome AS cidade, j.usuario_id, ev.descricao, un.nome AS admin_nome
              FROM contestacao c
              JOIN registro_atividade r ON r.registro_atividade_id = c.registro_atividade_id
              JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
              JOIN categoria_ambiental ca ON ca.categoria_ambiental_id = a.categoria_ambiental_id
              JOIN indicador_ambiental ia ON ia.categoria_ambiental_id = a.categoria_ambiental_id
              JOIN jogador j ON j.jogador_id = c.jogador_id
              JOIN cidade ci ON ci.jogador_id = j.jogador_id
         LEFT JOIN evidencia_atividade ev ON ev.registro_atividade_id = r.registro_atividade_id
         LEFT JOIN administrador ad ON ad.administrador_id = c.decidida_por
         LEFT JOIN usuario un ON un.usuario_id = ad.usuario_id";
    $abertas = db()->query("$sql WHERE c.status = 'aberta' ORDER BY c.data ASC")->fetchAll();
    $outras = db()->query("$sql WHERE c.status <> 'aberta' ORDER BY c.data_decisao DESC LIMIT " . max(0, $decididas))->fetchAll();
    $linhas = array_merge($abertas, $outras);
    $ia = ia_banco_pronto() ? ia_pareceres(array_column($linhas, 'registro_atividade_id')) : [];

    return array_map(static fn(array $c): array => [
        'id'          => (int) $c['id'],
        'registroId'  => (int) $c['registro_atividade_id'],
        'atividade'   => $c['atividade'],
        'categoria'   => INDICADOR_CHAVE[(int) $c['indicador_ambiental_id']] ?? '',
        'categoriaNome' => $c['categoria_nome'],
        'jogador'     => $c['apelido'],
        'cidade'      => $c['cidade'],
        'descricao'   => $c['descricao'],
        'texto'       => $c['texto'],
        'temFotoNova' => (bool) $c['tem_foto_nova'],
        'motivoOriginal' => $c['motivo_rejeicao_original'],
        'status'      => $c['status'],
        'resposta'    => $c['resposta'],
        'admin'       => $c['admin_nome'],
        'registradoEm' => strtotime($c['data_registro']),
        'contestadoEm' => strtotime($c['data']),
        'decididaEm'  => $c['data_decisao'] ? strtotime($c['data_decisao']) : null,
        'proprio'     => (int) $c['usuario_id'] === $usuarioAdmin,
        'ia'          => $ia[(int) $c['registro_atividade_id']] ?? null,
    ], $linhas);
}

/** Quantas contestações aguardam decisão. */
function contestacoes_abertas(): int
{
    return contestacao_banco_pronto()
        ? (int) db()->query("SELECT COUNT(*) FROM contestacao WHERE status = 'aberta'")->fetchColumn()
        : 0;
}

/**
 * O administrador aceita (aprova o registro, 100%) ou nega (mantém a rejeição, com resposta).
 * @throws RegistroInvalido
 */
function decidir_contestacao(int $adminId, int $usuarioAdmin, int $contestacaoId, string $acao, string $resposta): string
{
    if (!in_array($acao, ['aceitar', 'negar'], true)) throw new RegistroInvalido('Ação inválida.');
    if (!contestacao_banco_pronto()) throw new RegistroInvalido('A contestação ainda não está disponível.');
    $resposta = trim($resposta);
    if ($acao === 'negar' && $resposta === '') throw new RegistroInvalido('Escreva a resposta para o jogador.');
    if (mb_strlen($resposta, 'UTF-8') > CONTESTACAO_RESPOSTA_MAX) {
        throw new RegistroInvalido('A resposta deve ter no máximo ' . CONTESTACAO_RESPOSTA_MAX . ' caracteres.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT c.registro_atividade_id, c.status, j.usuario_id FROM contestacao c
                              JOIN jogador j ON j.jogador_id = c.jogador_id
                             WHERE c.contestacao_id = ? FOR UPDATE');
        $s->execute([$contestacaoId]);
        $c = $s->fetch();
        if (!$c) throw new RegistroInvalido('Contestação não encontrada.');
        if ((int) $c['usuario_id'] === $usuarioAdmin) throw new RegistroInvalido('Você não pode decidir sobre as suas próprias contestações.');
        if ($c['status'] !== 'aberta') { $pdo->rollBack(); return 'Esta contestação já tinha sido decidida.'; }

        if ($acao === 'aceitar') {
            $pdo->exec('SET @ecocity_contestacao = 1');   // única passagem de rejeitado para aprovado (trigger da 030)
            try {
                $ok = aprovar_registro($pdo, (int) $c['registro_atividade_id'], $adminId, true);
            } finally {
                $pdo->exec('SET @ecocity_contestacao = 0');
            }
            if (!$ok) throw new RegistroInvalido('O registro não está mais rejeitado.');
        }
        $pdo->prepare('UPDATE contestacao SET status = ?, resposta = ?, decidida_por = ?, data_decisao = NOW() WHERE contestacao_id = ?')
            ->execute([$acao === 'aceitar' ? 'aceita' : 'negada', $resposta !== '' ? $resposta : null, $adminId, $contestacaoId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    return $acao === 'aceitar' ? 'Contestação aceita: o registro foi aprovado.' : 'Rejeição mantida.';
}
