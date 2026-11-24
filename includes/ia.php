<?php
/**
 * IA local (RN91): cliente do Ollama e validação das fotos de evidência.
 *
 * Regras (RN04/RN91, atualizadas pela RN94 em 18/11/2026):
 *  - Registro "com evidência" pendente é analisado uma única vez pela IA.
 *  - Veredito "aprovar" com confiança ≥ confianca_minima → aprovado na hora (sem administrador).
 *  - Qualquer outro resultado → rejeitado na hora com o motivo da IA; o jogador pode contestar
 *    (includes/contestacoes.php) e um administrador decide. Sem a migração 030, as duvidosas
 *    continuam pendentes para o administrador e se aprovam sozinhas 72 h após o envio.
 *  - Sem IA (desligada ou fora do ar) vale a regra anterior (administrador ou 24 h).
 *  - Cada análise fica guardada (analise_evidencia) e, com a decisão final do registro, forma a base
 *    rotulada para um ajuste fino futuro do modelo.
 */
declare(strict_types=1);

const IA_PRAZO_DUVIDA_HORAS = 72;
const IA_VEREDITOS = ['aprovar', 'duvida', 'nao_mostra'];
const IA_FOTO_LADO_MAX = 1024;
const IA_CTX_PADRAO = 8192;

/** Configuração da IA com os valores padrão (config 'ia'). */
function ia_config(): array
{
    // Sem o bloco 'ia' no config (ex.: Wamp antigo): ligada no Ollama deste computador (porta publicada só em 127.0.0.1)
    $c = config('ia') ?? [];
    return [
        'ativo'             => (bool) ($c['ativo'] ?? true),
        'url'               => rtrim((string) ($c['url'] ?? 'http://127.0.0.1:11434'), '/'),
        'modelo'            => (string) ($c['modelo'] ?? 'qwen3-vl:8b-instruct'),
        'modelo_estruturas' => (string) ($c['modelo_estruturas'] ?? 'qwen2.5-coder:14b'),
        'confianca_minima'  => (float) ($c['confianca_minima'] ?? 0.8),
        'tempo_limite'      => (int) ($c['tempo_limite'] ?? 90),
    ];
}

/** Banco com a migração 027 (sem ela a IA fica desligada e vale a regra anterior). */
function ia_banco_pronto(): bool
{
    return schema_flag('ia_banco', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'registro_atividade' AND column_name = 'prazo_aprovacao'"
    )->fetchColumn()
        && (bool) db()->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'analise_evidencia'"
        )->fetchColumn());
}

/** Lista de modelos instalados no Ollama (guardada por 30 s em memória/arquivo temporário). */
function ia_modelos_instalados(): array
{
    $c = ia_config();
    if (!$c['ativo']) return [];
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) @getmyuid();
    $cache = sys_get_temp_dir() . '/ecocity-ia-tags-' . md5($c['url']) . '-u' . $uid . '.json';
    $guardado = @file_get_contents($cache);
    if ($guardado !== false) {
        $j = json_decode($guardado, true);
        if (is_array($j) && time() - (int) ($j['t'] ?? 0) < 30 && is_array($j['m'] ?? null)) {
            return $j['m'];
        }
    }
    $nomes = [];
    $ch = curl_init($c['url'] . '/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4]);
    $resp = curl_exec($ch);
    if ($resp !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $nomes = array_values(array_filter(array_map('strval', array_column(json_decode($resp, true)['models'] ?? [], 'name'))));
    }
    curl_close($ch);
    if ($nomes) {
        $tmp = $cache . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['t' => time(), 'm' => $nomes])) !== false) {
            if (!@rename($tmp, $cache)) {
                @unlink($tmp);
            }
        }
    }
    return $nomes;
}

/** Ollama ligado e com o modelo? (resultado guardado por 30 s) */
function ia_disponivel(): bool
{
    $c = ia_config();
    if (!$c['ativo'] || !ia_banco_pronto()) return false;
    $nomes = ia_modelos_instalados();
    return in_array($c['modelo'], $nomes, true) || in_array($c['modelo_estruturas'], $nomes, true);
}

/** Modelo preferencial para geração de estruturas 3D (qwen2.5-coder:14b se instalado; senão o modelo padrão). */
function ia_modelo_estruturas(): string
{
    $c = ia_config();
    $nomes = ia_modelos_instalados();
    if (in_array($c['modelo_estruturas'], $nomes, true)) {
        return $c['modelo_estruturas'];
    }
    return $c['modelo'];
}

/** Falha de comunicação com a IA (servidor fora do ar, tempo esgotado): tenta de novo depois. */
class IaIndisponivel extends RuntimeException {}

/** Faz a chamada HTTP ao /api/chat do Ollama e decodifica o JSON estruturado da resposta. */
function ia_requisitar(array $corpo, int $tempoLimite): array
{
    $c = ia_config();
    $json = json_encode($corpo, JSON_UNESCAPED_UNICODE);
    $inicio = microtime(true);
    $ch = curl_init($c['url'] . '/api/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $tempoLimite,
    ]);
    $resp = curl_exec($ch);
    $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    $tempoMs = (int) round((microtime(true) - $inicio) * 1000);
    if ($resp === false || $codigo !== 200) {
        throw new IaIndisponivel('IA sem resposta: ' . ($erro ?: "HTTP {$codigo}"));
    }
    $bruto = (string) (json_decode($resp, true)['message']['content'] ?? '');
    $dados = json_decode($bruto, true);
    return ['dados' => is_array($dados) ? $dados : null, 'bruto' => $bruto, 'tempoMs' => $tempoMs];
}

/**
 * Conversa com o modelo e devolve o JSON da resposta (validado pelo esquema no Ollama).
 * @param string[] $imagens conteúdo binário das imagens
 * @return array{dados: ?array, bruto: string, tempoMs: int}
 */
function ia_chamar(
    string $sistema,
    string $pedido,
    array $imagens,
    array $esquema,
    ?int $tempoLimite = null,
    ?string $modelo = null,
    int $numCtx = IA_CTX_PADRAO
): array {
    $c = ia_config();
    $mensagem = ['role' => 'user', 'content' => $pedido];
    if ($imagens) $mensagem['images'] = array_map('base64_encode', $imagens);
    return ia_requisitar([
        'model'    => $modelo ?? $c['modelo'],
        'messages' => [['role' => 'system', 'content' => $sistema], $mensagem],
        'stream'   => false,
        // Sem 'think' => false: o Qwen3-VL pensa assim mesmo e, com o limite de contexto, a resposta saía vazia
        'format'   => $esquema,
        'options'  => ['temperature' => 0.2, 'num_ctx' => $numCtx],
    ], $tempoLimite ?? $c['tempo_limite']);
}

/**
 * Conversa com histórico de mensagens (chatbot EcoGuia, RN97) usando o modelo leve aquecido na VRAM.
 * @param array<int,array{role:string,content:string}> $mensagens
 * @return array{dados: ?array, bruto: string, tempoMs: int}
 */
function ia_chamar_chat(
    array $mensagens,
    array $esquema,
    int $numCtx = IA_CTX_PADRAO,
    int $numPredict = 700,
    ?int $tempoLimite = null
): array {
    $c = ia_config();
    return ia_requisitar([
        'model'    => $c['modelo'],
        'messages' => $mensagens,
        'stream'   => false,
        'format'   => $esquema,
        'options'  => ['temperature' => 0.3, 'num_ctx' => $numCtx, 'num_predict' => $numPredict],
    ], $tempoLimite ?? $c['tempo_limite']);
}

/** Foto reduzida para o lado maior de 1024 px (JPEG) quando o PHP tem GD; senão, o arquivo original. */
function ia_preparar_foto(string $caminho): string
{
    $original = (string) file_get_contents($caminho);
    if (!function_exists('imagecreatefromstring')) return $original;
    $img = @imagecreatefromstring($original);
    if (!$img) return $original;
    [$w, $h] = [imagesx($img), imagesy($img)];
    $escala = min(1, IA_FOTO_LADO_MAX / max($w, $h));
    if ($escala < 1) {
        $nova = imagecreatetruecolor((int) round($w * $escala), (int) round($h * $escala));
        imagecopyresampled($nova, $img, 0, 0, 0, 0, imagesx($nova), imagesy($nova), $w, $h);
        $img = $nova;
    }
    ob_start();
    imagejpeg($img, null, 85);
    return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------------
   Validação das fotos
   --------------------------------------------------------------------------- */

const IA_ESQUEMA_EVIDENCIA = [
    'type' => 'object',
    'properties' => [
        'descricao_foto' => ['type' => 'string'],
        'veredito'       => ['type' => 'string', 'enum' => IA_VEREDITOS],
        'confianca'      => ['type' => 'number'],
        'motivo'         => ['type' => 'string'],
    ],
    'required' => ['descricao_foto', 'veredito', 'confianca', 'motivo'],
];

function ia_prompt_evidencia(array $r): array
{
    $sistema = 'Você é o verificador de evidências do EcoCity, um jogo de educação ambiental. Jogadores enviam UMA foto '
        . 'para comprovar uma ação sustentável real. Analise a foto com cuidado e responda SEMPRE em português do Brasil, em JSON.';
    $pedido = "Atividade declarada: \"{$r['atividade']}\".\n"
        . 'O que a atividade significa: ' . ($r['atividade_descricao'] ?: '—') . "\n"
        . 'Comentário do jogador: ' . ($r['descricao'] !== null && $r['descricao'] !== '' ? '"' . $r['descricao'] . '"' : '(nenhum)') . "\n\n"
        . "Responda:\n"
        . "- descricao_foto: o que aparece na foto, em até 2 frases.\n"
        . "- veredito: \"aprovar\" se a foto mostra claramente a atividade sendo feita ou o seu resultado direto "
        . "(ex.: lixo separado em recipientes; muda plantada na terra; bicicleta ou transporte público em uso); "
        . "\"nao_mostra\" se a foto não tem relação com a atividade; \"duvida\" se ela tem alguma relação mas não comprova "
        . "(escura, borrada, cortada, só um objeto parado, captura de tela, imagem que parece tirada da internet).\n"
        . "- confianca: de 0 a 1, o quanto você tem certeza do veredito.\n"
        . "- motivo: uma frase curta e educada explicando o veredito para o jogador.\n"
        . 'Não aprove por causa do comentário: só a foto comprova.';
    return [$sistema, $pedido];
}

/** Próximo registro com foto pendente e sem análise (do jogador ou de qualquer um). */
function ia_proximo_para_analisar(?int $jogadorId = null, int $minIdadeSeg = 0): ?int
{
    $sql = "SELECT r.registro_atividade_id FROM registro_atividade r
              JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id AND e.arquivo IS NOT NULL
         LEFT JOIN analise_evidencia a ON a.registro_atividade_id = r.registro_atividade_id
             WHERE r.status = 'pendente' AND r.tipo_verificacao_id = ? AND a.analise_evidencia_id IS NULL";
    $params = [TIPO_EVIDENCIA];
    if ($jogadorId !== null) { $sql .= ' AND r.jogador_id = ?'; $params[] = $jogadorId; }
    if ($minIdadeSeg > 0) $sql .= ' AND r.data_registro <= NOW() - INTERVAL ' . $minIdadeSeg . ' SECOND';
    $s = db()->prepare($sql . ' ORDER BY r.data_registro LIMIT 1');
    $s->execute($params);
    $id = $s->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * Analisa a foto de um registro pendente e aplica o resultado. Uma vez por registro.
 * @return array|null o parecer gravado; null quando a IA não está disponível ou o registro não precisa de análise
 */
function analisar_evidencia(int $registroId): ?array
{
    if (!ia_disponivel()) return null;
    $pdo = db();
    $trava = 'ecocity_ia_' . $registroId;
    if ((int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($trava) . ', 0)')->fetchColumn() !== 1) return null;   // outro processo já está analisando

    try {
        $s = $pdo->prepare(
            "SELECT r.registro_atividade_id, r.status, a.nome AS atividade, a.descricao AS atividade_descricao, e.arquivo, e.descricao,
                    (SELECT 1 FROM analise_evidencia x WHERE x.registro_atividade_id = r.registro_atividade_id) AS ja_analisado
               FROM registro_atividade r
               JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
               JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
              WHERE r.registro_atividade_id = ? AND r.tipo_verificacao_id = ?"
        );
        $s->execute([$registroId, TIPO_EVIDENCIA]);
        $r = $s->fetch();
        if (!$r || $r['status'] !== 'pendente' || $r['ja_analisado'] || !$r['arquivo']) return null;
        $caminho = PASTA_EVIDENCIAS . '/' . basename($r['arquivo']);
        if (!is_file($caminho)) return null;

        [$sistema, $pedido] = ia_prompt_evidencia($r);
        try {
            $resp = ia_chamar($sistema, $pedido, [ia_preparar_foto($caminho)], IA_ESQUEMA_EVIDENCIA);
        } catch (IaIndisponivel $ex) {
            error_log('EcoCity — IA (registro ' . $registroId . '): ' . $ex->getMessage());
            return null;   // tenta de novo na próxima oportunidade
        }

        $d = $resp['dados'];
        $valido = is_array($d) && in_array($d['veredito'] ?? null, IA_VEREDITOS, true) && is_numeric($d['confianca'] ?? null);
        $parecer = [
            'veredito'      => $valido ? $d['veredito'] : 'erro',
            'confianca'     => $valido ? max(0, min(1, round((float) $d['confianca'], 2))) : 0.0,
            'motivo'        => mb_substr(trim((string) ($valido ? ($d['motivo'] ?? '') : 'A IA não conseguiu analisar a foto.')) ?: 'Sem explicação.', 0, 250),
            'descricaoFoto' => $valido ? mb_substr(trim((string) ($d['descricao_foto'] ?? '')), 0, 500) : null,
            'tempoMs'       => $resp['tempoMs'],
        ];
        $aprova = $parecer['veredito'] === 'aprovar' && $parecer['confianca'] >= ia_config()['confianca_minima'];
        // RN94 (migração 030): sem certeza, a IA rejeita com o motivo e o jogador pode contestar;
        // sem a migração, as duvidosas continuam indo à fila do administrador (72 h)
        $rejeita = !$aprova && contestacao_banco_pronto();
        $parecer['acao'] = $aprova ? 'aprovou' : ($rejeita ? 'rejeitou' : 'fila');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO analise_evidencia (registro_atividade_id, modelo, veredito, confianca, motivo, descricao_foto, acao, tempo_ms, data)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute([$registroId, ia_config()['modelo'], $parecer['veredito'], $parecer['confianca'], $parecer['motivo'],
                           $parecer['descricaoFoto'], $parecer['acao'], $parecer['tempoMs']]);
            if ($aprova) {
                aprovar_registro($pdo, $registroId, null);
            } elseif ($rejeita) {
                rejeitar_registro($pdo, $registroId, $parecer['motivo'], null);
            } else {
                $pdo->prepare("UPDATE registro_atividade SET prazo_aprovacao = data_registro + INTERVAL " . IA_PRAZO_DUVIDA_HORAS . " HOUR
                                WHERE registro_atividade_id = ? AND status = 'pendente'")->execute([$registroId]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            if ($ex instanceof PDOException && $ex->getCode() === '23000') return null;   // outra análise chegou antes (UNIQUE)
            throw $ex;
        }
        return $parecer;   // aprovar_registro() já avalia missões, conquistas, cidade e comunidade
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($trava) . ')');
    }
}

/** Analisa no máximo um registro da fila (usado pela sincronização, sem agendador). */
function ia_analisar_fila(?int $jogadorId = null, int $minIdadeSeg = 60): ?array
{
    if (!ia_disponivel()) return null;
    $id = ia_proximo_para_analisar($jogadorId, $minIdadeSeg);
    return $id !== null ? analisar_evidencia($id) : null;
}

/** Pareceres da IA por registro: [registroId => [...]] */
function ia_pareceres(array $registroIds): array
{
    $ids = array_values(array_filter(array_map('intval', $registroIds)));
    if (!$ids) return [];
    $s = db()->prepare('SELECT registro_atividade_id, veredito, confianca, motivo, descricao_foto, acao, data FROM analise_evidencia
                         WHERE registro_atividade_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $s->execute($ids);
    $out = [];
    foreach ($s as $a) {
        $out[(int) $a['registro_atividade_id']] = [
            'veredito' => $a['veredito'], 'confianca' => (float) $a['confianca'], 'motivo' => $a['motivo'],
            'descricaoFoto' => $a['descricao_foto'], 'acao' => $a['acao'], 'data' => $a['data'],
        ];
    }
    return $out;
}

/** Normaliza e valida um tema livre opcional informado pelo administrador para geração de conteúdo com IA. */
function ia_normalizar_tema(string $bruto): string
{
    $tema = trim(preg_replace('/\s+/u', ' ', $bruto) ?? '');
    if ($tema !== '') {
        $len = mb_strlen($tema, 'UTF-8');
        if ($len < 3 || $len > 150) {
            throw new RegistroInvalido('Informe um tema entre 3 e 150 caracteres.');
        }
    }
    return $tema;
}

/* ---------------------------------------------------------------------------
   Política de publicação dos conteúdos gerados por IA (migração 046 / RN91)
   --------------------------------------------------------------------------- */

const IA_PUB_TIPOS = ['quiz', 'missao', 'cmissao'];
const IA_PUB_MODOS = ['manual', 'imediato', 'agendado'];
const IA_PUB_HORAS = [6, 12, 24, 48, 72];

/** Migração 046 aplicada (`configuracao_sistema` e `ativar_em`)? */
function ia_config_pub_banco_pronto(): bool
{
    return schema_flag('ia_config_pub', static fn(): bool => (int) db()->query(
        "SELECT
            (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'configuracao_sistema')
          + (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'quiz' AND column_name = 'ativar_em')"
    )->fetchColumn() === 2);
}

/** Política configurada por tipo ('quiz', 'missao', 'cmissao'): ['modo' => string, 'horas' => int]. */
function ia_config_publicacao(): array
{
    $padrao = [];
    foreach (IA_PUB_TIPOS as $t) {
        $padrao[$t] = ['modo' => 'manual', 'horas' => 24];
    }
    if (!ia_config_pub_banco_pronto()) {
        return $padrao;
    }
    $linhas = db()->query("SELECT chave, valor FROM configuracao_sistema WHERE chave LIKE 'ia_pub_%'")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (IA_PUB_TIPOS as $t) {
        $modo = (string) ($linhas["ia_pub_{$t}_modo"] ?? 'manual');
        if (in_array($modo, IA_PUB_MODOS, true)) {
            $padrao[$t]['modo'] = $modo;
        }
        $horas = (int) ($linhas["ia_pub_{$t}_horas"] ?? 24);
        if (in_array($horas, IA_PUB_HORAS, true)) {
            $padrao[$t]['horas'] = $horas;
        }
    }
    return $padrao;
}

/** Valida e grava a política de publicação da IA para um tipo de conteúdo. */
function ia_salvar_config_publicacao(string $tipo, string $modo, int $horas, ?int $adminId = null): array
{
    if (!in_array($tipo, IA_PUB_TIPOS, true)) {
        throw new RegistroInvalido('Tipo de conteúdo inválido para política da IA.');
    }
    if (!in_array($modo, IA_PUB_MODOS, true)) {
        throw new RegistroInvalido('Modo de publicação inválido.');
    }
    if (!in_array($horas, IA_PUB_HORAS, true)) {
        throw new RegistroInvalido('Prazo de carência inválido.');
    }
    if (!ia_config_pub_banco_pronto()) {
        throw new RegistroInvalido('O banco ainda não possui a tabela de configurações. Aplique a migração 046 antes.');
    }
    $pdo = db();
    $stmt = $pdo->prepare(
        'INSERT INTO configuracao_sistema (chave, valor, atualizado_por) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por)'
    );
    $stmt->execute(["ia_pub_{$tipo}_modo", $modo, $adminId]);
    $stmt->execute(["ia_pub_{$tipo}_horas", (string) $horas, $adminId]);
    return ia_config_publicacao();
}

/** Aplica a política configurada ao conteúdo recém-criado pela IA (`origem = 'ia'`). */
function ia_aplicar_politica_publicacao(PDO $pdo, string $tipo, int $id): array
{
    $mapa = [
        'quiz'    => ['quiz', 'quiz_id'],
        'missao'  => ['missao', 'missao_id'],
        'cmissao' => ['comunidade_missao', 'comunidade_missao_id'],
    ];
    if (!isset($mapa[$tipo])) {
        throw new RegistroInvalido('Tipo de conteúdo inválido.');
    }
    [$tabela, $chave] = $mapa[$tipo];
    $cfg = ia_config_publicacao()[$tipo];
    $modo = $cfg['modo'];
    $horas = $cfg['horas'];

    if (!ia_config_pub_banco_pronto()) {
        $pdo->prepare("UPDATE {$tabela} SET origem = 'ia', gerado_em = NOW() WHERE {$chave} = ?")->execute([$id]);
        return ['modo' => 'manual', 'horas' => 24, 'ativa' => false];
    }

    if ($modo === 'imediato') {
        $pdo->prepare("UPDATE {$tabela} SET origem = 'ia', gerado_em = NOW(), ativa = TRUE, ativar_em = NULL WHERE {$chave} = ?")
            ->execute([$id]);
    } elseif ($modo === 'agendado') {
        $stmt = $pdo->prepare("UPDATE {$tabela} SET origem = 'ia', gerado_em = NOW(), ativa = FALSE, ativar_em = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE {$chave} = ?");
        $stmt->bindValue(1, $horas, PDO::PARAM_INT);
        $stmt->bindValue(2, $id, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $pdo->prepare("UPDATE {$tabela} SET origem = 'ia', gerado_em = NOW(), ativa = FALSE, ativar_em = NULL WHERE {$chave} = ?")
            ->execute([$id]);
    }
    return ['modo' => $modo, 'horas' => $horas, 'ativa' => $modo === 'imediato'];
}

/** Ativa automaticamente quizzes e missões gerados pela IA cujo prazo de carência (`ativar_em`) já venceu. */
function ativar_conteudo_ia_vencido(): int
{
    if (!ia_config_pub_banco_pronto()) {
        return 0;
    }
    $pdo = db();
    $total = 0;
    $total += $pdo->exec('UPDATE quiz SET ativa = TRUE, ativar_em = NULL WHERE ativa = FALSE AND ativar_em IS NOT NULL AND ativar_em <= NOW()');
    $total += $pdo->exec('UPDATE missao SET ativa = TRUE, ativar_em = NULL WHERE ativa = FALSE AND ativar_em IS NOT NULL AND ativar_em <= NOW()');
    $total += $pdo->exec('UPDATE comunidade_missao SET ativa = TRUE, ativar_em = NULL WHERE comunidade_id IS NULL AND ativa = FALSE AND ativar_em IS NOT NULL AND ativar_em <= NOW()');
    return $total;
}
