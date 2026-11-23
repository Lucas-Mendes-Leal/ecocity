<?php
/**
 * Moderação automática de denúncias por IA local (RN58 / RN91, 19/11/2026).
 *
 *  - Analisa denúncias de conversa entre amigos, conversa de comunidade e tópico do mural
 *    usando `qwen3-vl:8b-instruct` (com contexto das últimas mensagens).
 *  - Abusiva com certeza >= 0,80 -> resolve e oculta o conteúdo (`removida = 1` / `removido = 1`),
 *    mantendo o texto original intacto no banco como prova; bloqueio de conta continua só com o admin.
 *  - Não abusiva com certeza >= 0,80 -> arquiva sozinha como improcedente.
 *  - Dúvida (< 0,80, categoria 'duvida' ou IA fora do ar) -> fica aberta para o administrador.
 *  - O administrador pode consultar as decisões da IA e reabrir qualquer uma (`reabrir_denuncia_ia`).
 */
declare(strict_types=1);

const IA_DENUNCIA_CONFIANCA_MIN = 0.8;
const IA_DENUNCIA_CATEGORIAS = ['ofensa', 'odio', 'assedio', 'sexual', 'spam', 'nao_abusiva', 'duvida'];
const IA_DENUNCIA_ABUSIVAS = ['ofensa', 'odio', 'assedio', 'sexual', 'spam'];
const IA_DENUNCIA_ROTULOS = [
    'ofensa'      => 'Ofensa / xingamento',
    'odio'        => 'Discurso de ódio / preconceito',
    'assedio'     => 'Assédio / ameaça',
    'sexual'      => 'Conteúdo sexual impróprio',
    'spam'        => 'Spam / golpe',
    'nao_abusiva' => 'Não abusiva',
    'duvida'      => 'Dúvida',
    'erro'        => 'Erro na análise',
];

const IA_ESQUEMA_DENUNCIA = [
    'type' => 'object',
    'properties' => [
        'categoria' => ['type' => 'string', 'enum' => IA_DENUNCIA_CATEGORIAS],
        'abusiva'   => ['type' => 'boolean'],
        'confianca' => ['type' => 'number'],
        'motivo'    => ['type' => 'string'],
    ],
    'required' => ['categoria', 'abusiva', 'confianca', 'motivo'],
];

/** Migração 034 aplicada (colunas da IA em denúncias e `removida` em mensagens)? */
function denuncia_ia_banco_pronto(): bool
{
    return schema_flag('denuncia_ia', static fn(): bool => (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND (
            (table_name = 'denuncia_mensagem' AND column_name = 'ia_categoria') OR
            (table_name = 'comunidade_denuncia' AND column_name = 'ia_categoria') OR
            (table_name = 'mensagem' AND column_name = 'removida') OR
            (table_name = 'comunidade_mensagem' AND column_name = 'removida')
        )"
    )->fetchColumn() === 4);
}

/** Rótulo legível da categoria retornada pela IA. */
function ia_denuncia_rotulo(?string $cat): string
{
    return IA_DENUNCIA_ROTULOS[(string) $cat] ?? (string) $cat;
}

/** Monta sistema + pedido para o modelo classificar uma denúncia. */
function ia_prompt_denuncia(array $d): array
{
    $sistema = 'Você é o moderador de conteúdo do EcoCity, um jogo educativo ambiental para jovens (13+) e adultos. '
        . 'Analise uma denúncia feita por um jogador considerando o contexto e responda SEMPRE em português do Brasil, em JSON.';

    $contextoTxt = '';
    if (!empty($d['contexto'])) {
        $linhas = array_map(
            static fn(array $c): string => "- {$c['apelido']}: \"{$c['texto']}\"",
            $d['contexto']
        );
        $contextoTxt = "Mensagens anteriores na conversa (em ordem cronológica):\n" . implode("\n", $linhas) . "\n\n";
    }

    $alvoTxt = ($d['titulo'] ?? null) !== null && $d['titulo'] !== ''
        ? "Título: \"{$d['titulo']}\"\nTexto: \"{$d['texto']}\""
        : "\"{$d['texto']}\"";

    $pedido = "Origem: {$d['origemRotulo']}.\n"
        . "Autor do conteúdo denunciado: {$d['autor']}.\n"
        . "Denunciante: {$d['denunciante']}.\n"
        . 'Motivo informado pelo denunciante: ' . ($d['motivo'] ?: '(nenhum informado)') . "\n\n"
        . $contextoTxt
        . "Conteúdo denunciado:\n{$alvoTxt}\n\n"
        . "Classifique em JSON:\n"
        . "- categoria:\n"
        . "  * \"ofensa\": xingamento, palavrão dirigido a alguém, humilhação ou agressão verbal;\n"
        . "  * \"odio\": racismo, homofobia, machismo, intolerância religiosa ou qualquer discriminação;\n"
        . "  * \"assedio\": ameaça, perseguição, intimidação ou pedido insistente indesejado;\n"
        . "  * \"sexual\": conteúdo sexual explícito ou insinuação sexual imprópria;\n"
        . "  * \"spam\": propaganda comercial, golpe, phishing ou flood repetitivo sem sentido;\n"
        . "  * \"nao_abusiva\": conversa normal, brincadeira amigável inofensiva, opinião educada ou discordância respeitosa sobre o jogo;\n"
        . "  * \"duvida\": ambígua, sarcasmo difícil de interpretar sem mais contexto, ou código/gíria incerta.\n"
        . "- abusiva: true somente para ofensa, odio, assedio, sexual ou spam; false para nao_abusiva ou duvida.\n"
        . "- confianca: número de 0 a 1 representando sua certeza.\n"
        . "- motivo: 1 frase curta em português explicando a avaliação para os administradores.";

    return [$sistema, $pedido];
}

/**
 * Analisa uma denúncia aberta e sem análise prévia via IA local. Uma vez por denúncia.
 * @return array|null resultado aplicado; null quando IA indisponível ou já analisada
 */
function analisar_denuncia_ia(string $origem, int $denunciaId): ?array
{
    if (!in_array($origem, ['amigos', 'comunidade'], true)) return null;
    if (!denuncia_ia_banco_pronto() || !ia_disponivel()) return null;

    $pdo = db();
    $trava = "ecocity_ia_den_{$origem}_{$denunciaId}";
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($trava) . ', 0)')->fetchColumn() !== 1) return null;

    try {
        if ($origem === 'amigos') {
            $s = $pdo->prepare(
                'SELECT d.denuncia_id, d.motivo, d.status, d.ia_analisada_em,
                        m.mensagem_id, m.texto, m.remetente_id, m.destinatario_id, m.removida,
                        jr.apelido AS autor, jd.apelido AS denunciante
                   FROM denuncia_mensagem d
                   JOIN mensagem m ON m.mensagem_id = d.mensagem_id
                   JOIN jogador jr ON jr.jogador_id = m.remetente_id
                   JOIN jogador jd ON jd.jogador_id = d.denunciante_id
                  WHERE d.denuncia_id = ?'
            );
            $s->execute([$denunciaId]);
            $r = $s->fetch();
            if (!$r || $r['status'] !== 'aberta' || $r['ia_analisada_em'] !== null) return null;

            $ctx = $pdo->prepare(
                'SELECT * FROM (SELECT m.mensagem_id, m.texto, j.apelido FROM mensagem m
                                  JOIN jogador j ON j.jogador_id = m.remetente_id
                                 WHERE ((m.remetente_id = ? AND m.destinatario_id = ?) OR (m.remetente_id = ? AND m.destinatario_id = ?))
                                   AND m.mensagem_id < ? AND m.removida = 0
                              ORDER BY m.mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY mensagem_id'
            );
            $ctx->execute([$r['remetente_id'], $r['destinatario_id'], $r['destinatario_id'], $r['remetente_id'], $r['mensagem_id']]);
            $dadosPrompt = [
                'origemRotulo' => 'Conversa privada entre amigos',
                'autor'        => $r['autor'],
                'denunciante'  => $r['denunciante'],
                'motivo'       => $r['motivo'],
                'titulo'       => null,
                'texto'        => $r['texto'],
                'contexto'     => $ctx->fetchAll(),
            ];
            $alvoId = (int) $r['mensagem_id'];
            $alvoTipo = 'mensagem';
        } else {
            $s = $pdo->prepare(
                'SELECT d.comunidade_denuncia_id, d.tipo, d.alvo_id, d.comunidade_id, d.motivo, d.status, d.ia_analisada_em,
                        c.nome AS comunidade, jd.apelido AS denunciante
                   FROM comunidade_denuncia d
                   JOIN comunidade c ON c.comunidade_id = d.comunidade_id
                   JOIN jogador jd ON jd.jogador_id = d.denunciante_id
                  WHERE d.comunidade_denuncia_id = ?'
            );
            $s->execute([$denunciaId]);
            $r = $s->fetch();
            if (!$r || $r['status'] !== 'aberta' || $r['ia_analisada_em'] !== null) return null;

            $alvoTipo = (string) $r['tipo'];
            $alvoId = (int) $r['alvo_id'];
            if ($alvoTipo === 'mensagem') {
                $q = $pdo->prepare('SELECT cm.texto, j.apelido FROM comunidade_mensagem cm JOIN jogador j ON j.jogador_id = cm.jogador_id WHERE cm.comunidade_mensagem_id = ?');
                $q->execute([$alvoId]);
                $a = $q->fetch();
                if (!$a) return null;
                $ctx = $pdo->prepare(
                    'SELECT * FROM (SELECT cm.comunidade_mensagem_id, cm.texto, j.apelido FROM comunidade_mensagem cm
                                      JOIN jogador j ON j.jogador_id = cm.jogador_id
                                     WHERE cm.comunidade_id = ? AND cm.comunidade_mensagem_id < ? AND cm.removida = 0
                                  ORDER BY cm.comunidade_mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY comunidade_mensagem_id'
                );
                $ctx->execute([$r['comunidade_id'], $alvoId]);
                $contexto = $ctx->fetchAll();
                $titulo = null;
            } else {
                $q = $pdo->prepare('SELECT t.titulo, t.texto, j.apelido FROM comunidade_topico t JOIN jogador j ON j.jogador_id = t.autor_id WHERE t.comunidade_topico_id = ?');
                $q->execute([$alvoId]);
                $a = $q->fetch();
                if (!$a) return null;
                $contexto = [];
                $titulo = $a['titulo'];
            }
            $dadosPrompt = [
                'origemRotulo' => $alvoTipo === 'mensagem' ? "Conversa da comunidade \"{$r['comunidade']}\"" : "Tópico do mural da comunidade \"{$r['comunidade']}\"",
                'autor'        => $a['apelido'],
                'denunciante'  => $r['denunciante'],
                'motivo'       => $r['motivo'],
                'titulo'       => $titulo,
                'texto'        => (string) $a['texto'],
                'contexto'     => $contexto,
            ];
        }

        [$sistema, $pedido] = ia_prompt_denuncia($dadosPrompt);
        $modelo = ia_config()['modelo'];
        try {
            $resp = ia_chamar($sistema, $pedido, [], IA_ESQUEMA_DENUNCIA, 60, $modelo, 8192);
        } catch (IaIndisponivel $ex) {
            error_log("EcoCity — IA denúncia ({$origem} #{$denunciaId}): " . $ex->getMessage());
            return null;
        }

        $d = $resp['dados'];
        $cat = is_array($d) ? (string) ($d['categoria'] ?? '') : '';
        $abusiva = is_array($d) ? (bool) ($d['abusiva'] ?? false) : false;
        $confRaw = is_array($d) ? ($d['confianca'] ?? null) : null;
        $valido = is_array($d) && in_array($cat, IA_DENUNCIA_CATEGORIAS, true) && is_numeric($confRaw);

        $conf = $valido ? max(0.0, min(1.0, round((float) $confRaw, 2))) : 0.0;
        $motivo = mb_substr(trim((string) ($valido ? ($d['motivo'] ?? '') : 'A IA não conseguiu analisar a denúncia.')) ?: 'Sem explicação.', 0, 250);

        if (!$valido) {
            $cat = 'erro';
            $acao = 'erro';
        } elseif ($abusiva && in_array($cat, IA_DENUNCIA_ABUSIVAS, true) && $conf >= IA_DENUNCIA_CONFIANCA_MIN) {
            $acao = 'ocultou';
        } elseif (!$abusiva && $cat === 'nao_abusiva' && $conf >= IA_DENUNCIA_CONFIANCA_MIN) {
            $acao = 'arquivou';
        } else {
            $acao = 'fila';
        }

        $resolvida = in_array($acao, ['ocultou', 'arquivou'], true);
        $pdo->beginTransaction();
        try {
            if ($origem === 'amigos') {
                $pdo->prepare(
                    "UPDATE denuncia_mensagem
                        SET status = ?, resolvida_por = NULL, data_resolucao = ?,
                            ia_categoria = ?, ia_confianca = ?, ia_motivo = ?, ia_modelo = ?, ia_acao = ?, ia_analisada_em = NOW(), decidida_por_ia = ?
                      WHERE denuncia_id = ? AND status = 'aberta'"
                )->execute([$resolvida ? 'resolvida' : 'aberta', $resolvida ? date('Y-m-d H:i:s') : null, $cat, $conf, $motivo, $modelo, $acao, $resolvida ? 1 : 0, $denunciaId]);
                if ($acao === 'ocultou') {
                    $pdo->prepare('UPDATE mensagem SET removida = 1 WHERE mensagem_id = ?')->execute([$alvoId]);
                }
            } else {
                $pdo->prepare(
                    "UPDATE comunidade_denuncia
                        SET status = ?, resolvida_por = NULL, data_resolucao = ?,
                            ia_categoria = ?, ia_confianca = ?, ia_motivo = ?, ia_modelo = ?, ia_acao = ?, ia_analisada_em = NOW(), decidida_por_ia = ?
                      WHERE comunidade_denuncia_id = ? AND status = 'aberta'"
                )->execute([$resolvida ? 'resolvida' : 'aberta', $resolvida ? date('Y-m-d H:i:s') : null, $cat, $conf, $motivo, $modelo, $acao, $resolvida ? 1 : 0, $denunciaId]);
                if ($acao === 'ocultou') {
                    if ($alvoTipo === 'mensagem') {
                        $pdo->prepare('UPDATE comunidade_mensagem SET removida = 1 WHERE comunidade_mensagem_id = ?')->execute([$alvoId]);
                    } else {
                        $pdo->prepare('UPDATE comunidade_topico SET removido = TRUE, fixado = FALSE WHERE comunidade_topico_id = ?')->execute([$alvoId]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $ex;
        }

        return [
            'categoria' => $cat,
            'confianca' => $conf,
            'motivo'    => $motivo,
            'acao'      => $acao,
            'resolvida' => $resolvida,
            'tempoMs'   => $resp['tempoMs'],
        ];
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($trava) . ')');
    }
}

/** Retoma no máximo 1 denúncia aberta que ficou sem análise (ex.: Ollama desligado na hora). */
function ia_analisar_fila_denuncias(): ?array
{
    if (!denuncia_ia_banco_pronto() || !ia_disponivel()) return null;
    $pdo = db();
    $idAmigos = $pdo->query("SELECT denuncia_id FROM denuncia_mensagem WHERE status = 'aberta' AND ia_analisada_em IS NULL ORDER BY data LIMIT 1")->fetchColumn();
    if ($idAmigos !== false) {
        return analisar_denuncia_ia('amigos', (int) $idAmigos);
    }
    $idCom = $pdo->query("SELECT comunidade_denuncia_id FROM comunidade_denuncia WHERE status = 'aberta' AND ia_analisada_em IS NULL ORDER BY data LIMIT 1")->fetchColumn();
    if ($idCom !== false) {
        return analisar_denuncia_ia('comunidade', (int) $idCom);
    }
    return null;
}

/**
 * Reabre uma denúncia decidida pela IA para revisão humana.
 * Preserva o parecer da IA (`ia_analisada_em` não muda para a IA não reanalisar sozinha)
 * e restaura a visibilidade do conteúdo se a IA o tiver ocultado.
 */
function reabrir_denuncia_ia(string $origem, int $denunciaId): string
{
    if (!denuncia_ia_banco_pronto()) throw new RegistroInvalido('Recurso de IA não disponível no banco.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($origem === 'amigos') {
            $s = $pdo->prepare("SELECT denuncia_id, mensagem_id, ia_acao FROM denuncia_mensagem WHERE denuncia_id = ? AND status = 'resolvida' AND decidida_por_ia = 1 FOR UPDATE");
            $s->execute([$denunciaId]);
            $d = $s->fetch();
            if (!$d) throw new RegistroInvalido('Denúncia decidida pela IA não encontrada.');
            $pdo->prepare("UPDATE denuncia_mensagem SET status = 'aberta', resolvida_por = NULL, data_resolucao = NULL, decidida_por_ia = 0 WHERE denuncia_id = ?")
                ->execute([$denunciaId]);
            $extra = '';
            if ($d['ia_acao'] === 'ocultou') {
                $pdo->prepare('UPDATE mensagem SET removida = 0 WHERE mensagem_id = ?')->execute([$d['mensagem_id']]);
                $extra = ' Visibilidade da mensagem restaurada.';
            }
        } elseif ($origem === 'comunidade') {
            $s = $pdo->prepare("SELECT comunidade_denuncia_id, tipo, alvo_id, ia_acao FROM comunidade_denuncia WHERE comunidade_denuncia_id = ? AND status = 'resolvida' AND decidida_por_ia = 1 FOR UPDATE");
            $s->execute([$denunciaId]);
            $d = $s->fetch();
            if (!$d) throw new RegistroInvalido('Denúncia decidida pela IA não encontrada.');
            $pdo->prepare("UPDATE comunidade_denuncia SET status = 'aberta', resolvida_por = NULL, data_resolucao = NULL, decidida_por_ia = 0 WHERE comunidade_denuncia_id = ?")
                ->execute([$denunciaId]);
            $extra = '';
            if ($d['ia_acao'] === 'ocultou') {
                if ($d['tipo'] === 'mensagem') {
                    $pdo->prepare('UPDATE comunidade_mensagem SET removida = 0 WHERE comunidade_mensagem_id = ?')->execute([$d['alvo_id']]);
                    $extra = ' Visibilidade da mensagem restaurada.';
                } else {
                    $pdo->prepare('UPDATE comunidade_topico SET removido = FALSE WHERE comunidade_topico_id = ?')->execute([$d['alvo_id']]);
                    $extra = ' Tópico restaurado no mural.';
                }
            }
        } else {
            throw new RegistroInvalido('Origem inválida.');
        }
        $pdo->commit();
        return 'Denúncia reaberta para revisão humana.' . $extra;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
}

/** Denúncias resolvidas recentemente pela IA (para o histórico e reabertura em admin.php). */
function listar_denuncias_resolvidas_ia(int $limite = 50): array
{
    if (!denuncia_ia_banco_pronto()) return [];
    $pdo = db();
    $limite = max(1, min(100, $limite));
    $out = [];

    // 1. Amigos
    $rowsA = $pdo->query(
        "SELECT d.*, m.mensagem_id, m.texto, m.data_envio, m.remetente_id, m.destinatario_id, m.removida,
                jr.apelido AS autor, jr.usuario_id AS autor_usuario, ur.status AS autor_ativo, jd.apelido AS denunciante
           FROM denuncia_mensagem d
           JOIN mensagem m ON m.mensagem_id = d.mensagem_id
           JOIN jogador jr ON jr.jogador_id = m.remetente_id
           JOIN usuario ur ON ur.usuario_id = jr.usuario_id
           JOIN jogador jd ON jd.jogador_id = d.denunciante_id
          WHERE d.status = 'resolvida' AND d.decidida_por_ia = 1
       ORDER BY d.data_resolucao DESC, d.denuncia_id DESC
          LIMIT {$limite}"
    )->fetchAll();
    $ctxA = $pdo->prepare(
        'SELECT * FROM (SELECT m.mensagem_id, m.texto, m.data_envio, j.apelido FROM mensagem m JOIN jogador j ON j.jogador_id = m.remetente_id
          WHERE ((m.remetente_id = ? AND m.destinatario_id = ?) OR (m.remetente_id = ? AND m.destinatario_id = ?)) AND m.mensagem_id < ?
       ORDER BY m.mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY mensagem_id'
    );
    foreach ($rowsA as $r) {
        $ctxA->execute([$r['remetente_id'], $r['destinatario_id'], $r['destinatario_id'], $r['remetente_id'], $r['mensagem_id']]);
        $out[] = [
            'id' => (int) $r['denuncia_id'], 'origem' => 'amigos', 'tipo' => 'mensagem',
            'motivo' => $r['motivo'], 'data' => strtotime($r['data']), 'status' => 'resolvida',
            'resolvidaEm' => $r['data_resolucao'] ? strtotime($r['data_resolucao']) : null,
            'decididaPorIa' => true, 'texto' => $r['texto'], 'enviadaEm' => strtotime($r['data_envio']),
            'autor' => $r['autor'], 'autorUsuario' => (int) $r['autor_usuario'], 'autorAtivo' => (bool) $r['autor_ativo'],
            'denunciante' => $r['denunciante'], 'removido' => (bool) $r['removida'],
            'contexto' => array_map(static fn($c) => ['apelido' => $c['apelido'], 'texto' => $c['texto'], 'data' => strtotime($c['data_envio'])], $ctxA->fetchAll()),
            'ia' => [
                'categoria' => $r['ia_categoria'], 'confianca' => (float) $r['ia_confianca'],
                'motivo' => $r['ia_motivo'], 'acao' => $r['ia_acao'],
                'analisadaEm' => $r['ia_analisada_em'] ? strtotime($r['ia_analisada_em']) : null,
            ],
        ];
    }

    // 2. Comunidades
    $rowsC = $pdo->query(
        "SELECT d.*, c.nome AS comunidade, jd.apelido AS denunciante FROM comunidade_denuncia d
           JOIN comunidade c ON c.comunidade_id = d.comunidade_id JOIN jogador jd ON jd.jogador_id = d.denunciante_id
          WHERE d.status = 'resolvida' AND d.decidida_por_ia = 1
       ORDER BY d.data_resolucao DESC, d.comunidade_denuncia_id DESC
          LIMIT {$limite}"
    )->fetchAll();
    $msgC = $pdo->prepare('SELECT cm.*, j.apelido, j.usuario_id, u.status FROM comunidade_mensagem cm JOIN jogador j ON j.jogador_id = cm.jogador_id
                            JOIN usuario u ON u.usuario_id = j.usuario_id WHERE cm.comunidade_mensagem_id = ?');
    $topC = $pdo->prepare('SELECT t.*, j.apelido, j.usuario_id, u.status FROM comunidade_topico t JOIN jogador j ON j.jogador_id = t.autor_id
                            JOIN usuario u ON u.usuario_id = j.usuario_id WHERE t.comunidade_topico_id = ?');
    $ctxC = $pdo->prepare('SELECT * FROM (SELECT cm.comunidade_mensagem_id, cm.texto, cm.data_envio, j.apelido FROM comunidade_mensagem cm
                             JOIN jogador j ON j.jogador_id = cm.jogador_id WHERE cm.comunidade_id = ? AND cm.comunidade_mensagem_id < ?
                         ORDER BY cm.comunidade_mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY comunidade_mensagem_id');
    foreach ($rowsC as $r) {
        $eMsg = $r['tipo'] === 'mensagem';
        $q = $eMsg ? $msgC : $topC;
        $q->execute([$r['alvo_id']]);
        $a = $q->fetch();
        if (!$a) continue;
        $contexto = [];
        if ($eMsg) {
            $ctxC->execute([$r['comunidade_id'], $r['alvo_id']]);
            $contexto = array_map(static fn($c) => ['apelido' => $c['apelido'], 'texto' => $c['texto'], 'data' => strtotime($c['data_envio'])], $ctxC->fetchAll());
        }
        $out[] = [
            'id' => (int) $r['comunidade_denuncia_id'], 'origem' => 'comunidade', 'tipo' => $r['tipo'],
            'comunidade' => $r['comunidade'], 'motivo' => $r['motivo'], 'data' => strtotime($r['data']), 'status' => 'resolvida',
            'resolvidaEm' => $r['data_resolucao'] ? strtotime($r['data_resolucao']) : null,
            'decididaPorIa' => true, 'denunciante' => $r['denunciante'], 'autor' => $a['apelido'],
            'autorAtivo' => (bool) $a['status'], 'removido' => $eMsg ? (bool) $a['removida'] : (bool) $a['removido'],
            'titulo' => $eMsg ? null : $a['titulo'], 'texto' => $eMsg ? $a['texto'] : (string) $a['texto'],
            'enviadaEm' => strtotime($eMsg ? $a['data_envio'] : $a['data']), 'contexto' => $contexto,
            'ia' => [
                'categoria' => $r['ia_categoria'], 'confianca' => (float) $r['ia_confianca'],
                'motivo' => $r['ia_motivo'], 'acao' => $r['ia_acao'],
                'analisadaEm' => $r['ia_analisada_em'] ? strtotime($r['ia_analisada_em']) : null,
            ],
        ];
    }

    usort($out, static fn($a, $b) => ($b['resolvidaEm'] ?? $b['data']) <=> ($a['resolvidaEm'] ?? $a['data']));
    return array_slice($out, 0, $limite);
}
