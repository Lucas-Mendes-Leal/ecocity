<?php
/**
 * Amigos, mensagens, bloqueios, denúncias e visita à cidade (specs/site.md RN55–RN59).
 *
 *  - Busca por apelido (3+ letras) ou código público; nunca expõe e-mail nem nome real.
 *  - Amizade: pedido → aceito/recusado/cancelado; pedido cruzado aceita na hora.
 *  - Mensagens só entre amigos, sem bloqueio, 1–500 caracteres, limite por minuto.
 *  - Bloqueio desfaz a amizade e esconde o jogador na busca do bloqueado.
 *  - Denúncia de mensagem recebida vai para a administração.
 *  - Visita: só amigos; mostra mapa e progresso, sem créditos.
 *  - Sugestões (RN60): amigos de amigos primeiro, completadas com jogadores aleatórios; "Agora não" dispensa.
 */
declare(strict_types=1);

const BUSCA_MIN_LETRAS = 3;
const BUSCA_MAX_RESULTADOS = 10;
const PEDIDOS_POR_DIA = 20;
const AMIGOS_MAX = 200;
const MENSAGEM_MAX = 500;
const MENSAGENS_POR_MINUTO = 20;
const CONVERSA_LIMITE = 50;
const DENUNCIA_MOTIVO_MAX = 250;
const DENUNCIA_CONTEXTO = 5;
const SUGESTOES_MAX = 6;
const DISPENSAS_POR_DIA = 50;
const PREVIA_MAX = 60;

/* ---------------------------------------------------------------------------
   Relações
   --------------------------------------------------------------------------- */

function existe_bloqueio(PDO $pdo, int $a, int $b): bool
{
    $s = $pdo->prepare('SELECT 1 FROM bloqueio WHERE (bloqueador_id = ? AND bloqueado_id = ?) OR (bloqueador_id = ? AND bloqueado_id = ?)');
    $s->execute([$a, $b, $b, $a]);
    return (bool) $s->fetchColumn();
}

/** Linha de amizade entre dois jogadores (qualquer sentido) ou null. */
function amizade_entre(PDO $pdo, int $a, int $b, bool $bloquear = false): ?array
{
    $s = $pdo->prepare('SELECT * FROM amizade WHERE jogador_menor = LEAST(?, ?) AND jogador_maior = GREATEST(?, ?)' . ($bloquear ? ' FOR UPDATE' : ''));
    $s->execute([$a, $b, $a, $b]);
    return $s->fetch() ?: null;
}

/** 'amigo' | 'enviado' | 'recebido' | 'nenhuma' do ponto de vista de $eu. */
function relacao_entre(PDO $pdo, int $eu, int $outro): string
{
    $a = amizade_entre($pdo, $eu, $outro);
    if (!$a) return 'nenhuma';
    if ($a['status'] === 'aceita') return 'amigo';
    return (int) $a['solicitante_id'] === $eu ? 'enviado' : 'recebido';
}

function sao_amigos(PDO $pdo, int $a, int $b): bool
{
    return relacao_entre($pdo, $a, $b) === 'amigo' && !existe_bloqueio($pdo, $a, $b);
}

/** Jogador com conta ativa (ou erro). */
function jogador_ativo(PDO $pdo, int $jogadorId): array
{
    $s = $pdo->prepare('SELECT j.jogador_id, j.apelido, u.status FROM jogador j JOIN usuario u ON u.usuario_id = j.usuario_id WHERE j.jogador_id = ?');
    $s->execute([$jogadorId]);
    $j = $s->fetch();
    if (!$j || !$j['status']) throw new RegistroInvalido('Jogador não encontrado.');
    return $j;
}

/** Dados públicos de um jogador (sem e-mail, nome real ou créditos). */
const SQL_PUBLICO = 'SELECT j.jogador_id, j.apelido, j.numero_conta, c.nome AS cidade, COALESCE(nj.nivel, 1) AS nivel,
                            j.avatar_icone, j.avatar_cor, j.aceita_pedidos, j.permite_visita
                       FROM jogador j
                       JOIN usuario u ON u.usuario_id = j.usuario_id
                       JOIN cidade c ON c.jogador_id = j.jogador_id
                  LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id';

function publico(array $r): array
{
    return ['id' => (int) $r['jogador_id'], 'apelido' => $r['apelido'], 'codigo' => $r['numero_conta'], 'cidade' => $r['cidade'], 'nivel' => (int) $r['nivel'],
            'avatar' => avatar_publico($r), 'aceitaPedidos' => (bool) ($r['aceita_pedidos'] ?? true), 'permiteVisita' => (bool) ($r['permite_visita'] ?? true)];
}

/* ---------------------------------------------------------------------------
   Busca e pedidos
   --------------------------------------------------------------------------- */

function buscar_jogadores(int $eu, string $termo): array
{
    $termo = trim($termo);
    $codigo = strtoupper($termo);
    $porCodigo = (bool) preg_match('/^ECO-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo);
    if (!$porCodigo && mb_strlen($termo) < BUSCA_MIN_LETRAS) {
        throw new RegistroInvalido('Digite pelo menos ' . BUSCA_MIN_LETRAS . ' letras do apelido ou o código ECO-XXXX-XXXX.');
    }
    $filtro = $porCodigo ? 'j.numero_conta = ?' : "j.apelido LIKE ? ESCAPE '\\\\'";
    $valor = $porCodigo ? $codigo : '%' . addcslashes($termo, '%_\\') . '%';
    $pdo = db();
    $s = $pdo->prepare(SQL_PUBLICO . "
          WHERE {$filtro} AND j.jogador_id <> ? AND u.status = TRUE
            AND NOT EXISTS (SELECT 1 FROM bloqueio b WHERE (b.bloqueador_id = j.jogador_id AND b.bloqueado_id = ?) OR (b.bloqueador_id = ? AND b.bloqueado_id = j.jogador_id))
       ORDER BY j.apelido LIMIT " . BUSCA_MAX_RESULTADOS);
    $s->execute([$valor, $eu, $eu, $eu]);
    return array_map(static fn(array $r): array => publico($r) + ['relacao' => relacao_entre($pdo, $eu, (int) $r['jogador_id'])], $s->fetchAll());
}

function contar_amigos(PDO $pdo, int $j): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM amizade WHERE status = 'aceita' AND (solicitante_id = ? OR destinatario_id = ?)");
    $s->execute([$j, $j]);
    return (int) $s->fetchColumn();
}

function pedir_amizade(int $eu, int $outro): string
{
    if ($eu === $outro) throw new RegistroInvalido('Você não pode adicionar a si mesmo.');
    return c_transacao(static function (PDO $pdo) use ($eu, $outro): string {
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$eu]);
        jogador_ativo($pdo, $outro);
        if (existe_bloqueio($pdo, $eu, $outro)) throw new RegistroInvalido('Não é possível enviar pedido para este jogador.');
        $a = amizade_entre($pdo, $eu, $outro, true);
        if ($a && $a['status'] === 'aceita') throw new RegistroInvalido('Vocês já são amigos.');
        if ($a && (int) $a['solicitante_id'] === $eu) throw new RegistroInvalido('Você já enviou um pedido para este jogador.');
        if (contar_amigos($pdo, $eu) >= AMIGOS_MAX) throw new RegistroInvalido('Você atingiu o limite de ' . AMIGOS_MAX . ' amigos.');
        if (!$a) {   // privacidade (RN93): quem desligou não recebe pedidos novos
            $p = $pdo->prepare('SELECT aceita_pedidos FROM jogador WHERE jogador_id = ?');
            $p->execute([$outro]);
            if (!(bool) $p->fetchColumn()) throw new RegistroInvalido('Este jogador não está recebendo pedidos de amizade.');
        }
        if ($a) {   // o outro já tinha pedido: aceita na hora
            if (contar_amigos($pdo, $outro) >= AMIGOS_MAX) throw new RegistroInvalido('Este jogador atingiu o limite de amigos.');
            $pdo->prepare("UPDATE amizade SET status = 'aceita', data_resposta = NOW() WHERE amizade_id = ?")->execute([$a['amizade_id']]);
            return 'Vocês agora são amigos!';
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM amizade WHERE solicitante_id = ? AND data_pedido >= NOW() - INTERVAL 1 DAY');
        $s->execute([$eu]);
        if ((int) $s->fetchColumn() >= PEDIDOS_POR_DIA) throw new RegistroInvalido('Limite de ' . PEDIDOS_POR_DIA . ' pedidos por dia atingido. Tente amanhã.');
        $pdo->prepare('INSERT INTO amizade (solicitante_id, destinatario_id, data_pedido) VALUES (?, ?, NOW())')->execute([$eu, $outro]);
        return 'Pedido de amizade enviado.';
    });
}

function responder_pedido(int $eu, int $outro, bool $aceitar): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $outro, $aceitar): string {
        $a = amizade_entre($pdo, $eu, $outro, true);
        if (!$a || $a['status'] !== 'pendente' || (int) $a['destinatario_id'] !== $eu) throw new RegistroInvalido('Pedido não encontrado.');
        if (!$aceitar) {
            $pdo->prepare('DELETE FROM amizade WHERE amizade_id = ?')->execute([$a['amizade_id']]);
            return 'Pedido recusado.';
        }
        if (existe_bloqueio($pdo, $eu, $outro)) throw new RegistroInvalido('Não é possível aceitar este pedido.');
        if (contar_amigos($pdo, $eu) >= AMIGOS_MAX || contar_amigos($pdo, $outro) >= AMIGOS_MAX) throw new RegistroInvalido('Limite de ' . AMIGOS_MAX . ' amigos atingido.');
        $pdo->prepare("UPDATE amizade SET status = 'aceita', data_resposta = NOW() WHERE amizade_id = ?")->execute([$a['amizade_id']]);
        return 'Pedido aceito. Vocês agora são amigos!';
    });
}

function cancelar_pedido(int $eu, int $outro): string
{
    $n = db()->prepare("DELETE FROM amizade WHERE solicitante_id = ? AND destinatario_id = ? AND status = 'pendente'");
    $n->execute([$eu, $outro]);
    if (!$n->rowCount()) throw new RegistroInvalido('Pedido não encontrado.');
    return 'Pedido cancelado.';
}

function desfazer_amizade(int $eu, int $outro): string
{
    $n = db()->prepare("DELETE FROM amizade WHERE jogador_menor = LEAST(?, ?) AND jogador_maior = GREATEST(?, ?) AND status = 'aceita'");
    $n->execute([$eu, $outro, $eu, $outro]);
    if (!$n->rowCount()) throw new RegistroInvalido('Vocês não são amigos.');
    return 'Amizade desfeita.';
}

function bloquear_jogador(int $eu, int $outro): string
{
    if ($eu === $outro) throw new RegistroInvalido('Você não pode bloquear a si mesmo.');
    return c_transacao(static function (PDO $pdo) use ($eu, $outro): string {
        jogador_ativo($pdo, $outro);
        $pdo->prepare('DELETE FROM amizade WHERE jogador_menor = LEAST(?, ?) AND jogador_maior = GREATEST(?, ?)')->execute([$eu, $outro, $eu, $outro]);
        $pdo->prepare('INSERT IGNORE INTO bloqueio (bloqueador_id, bloqueado_id, data) VALUES (?, ?, NOW())')->execute([$eu, $outro]);
        return 'Jogador bloqueado. Ele não pode mais encontrar você, mandar pedidos nem mensagens.';
    });
}

function desbloquear_jogador(int $eu, int $outro): string
{
    $n = db()->prepare('DELETE FROM bloqueio WHERE bloqueador_id = ? AND bloqueado_id = ?');
    $n->execute([$eu, $outro]);
    if (!$n->rowCount()) throw new RegistroInvalido('Este jogador não está bloqueado.');
    return 'Jogador desbloqueado.';
}

/** Amigos (com não lidas), pedidos recebidos e enviados e bloqueados. */
function listar_amigos(int $eu): array
{
    $pdo = db();
    $naoLidas = $pdo->prepare('SELECT remetente_id, COUNT(*) FROM mensagem WHERE destinatario_id = ? AND data_leitura IS NULL GROUP BY remetente_id');
    $naoLidas->execute([$eu]);
    $nl = $naoLidas->fetchAll(PDO::FETCH_KEY_PAIR);
    $lista = static function (string $join) use ($pdo, $eu): array {
        $s = $pdo->prepare(SQL_PUBLICO . " {$join} WHERE u.status = TRUE ORDER BY j.apelido");
        $s->execute(array_fill(0, substr_count($join, '?'), $eu));
        return $s->fetchAll();
    };
    $linhas = $lista("JOIN amizade a ON a.status = 'aceita' AND a.jogador_menor = LEAST(j.jogador_id, ?) AND a.jogador_maior = GREATEST(j.jogador_id, ?)");
    $extras = extras_amigos($pdo, $eu, array_map(static fn($r) => (int) $r['jogador_id'], $linhas));
    $amigos = array_map(static fn($r) => publico($r) + ['naoLidas' => (int) ($nl[$r['jogador_id']] ?? 0)] + $extras[(int) $r['jogador_id']], $linhas);
    $recebidos = array_map('publico', $lista("JOIN amizade a ON a.status = 'pendente' AND a.solicitante_id = j.jogador_id AND a.destinatario_id = ?"));
    $enviados = array_map('publico', $lista("JOIN amizade a ON a.status = 'pendente' AND a.destinatario_id = j.jogador_id AND a.solicitante_id = ?"));
    $blq = $pdo->prepare(SQL_PUBLICO . ' JOIN bloqueio b ON b.bloqueado_id = j.jogador_id AND b.bloqueador_id = ? ORDER BY j.apelido');
    $blq->execute([$eu]);
    return ['amigos' => $amigos, 'recebidos' => $recebidos, 'enviados' => $enviados, 'bloqueados' => array_map('publico', $blq->fetchAll())];
}

/**
 * Dados dos cards de amigo: nível da cidade e indicadores (valores gravados, sem aplicar a queda pendente;
 * a visita aplica) e a última mensagem da conversa (prévia).
 * @param int[] $ids
 * @return array<int, array{nivelCidade:int, indicadores:array<string,int>, ultima:?array}>
 */
function extras_amigos(PDO $pdo, int $eu, array $ids): array
{
    $out = [];
    foreach ($ids as $id) $out[$id] = ['nivelCidade' => 1, 'indicadores' => [], 'ultima' => null];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $c = $pdo->prepare("SELECT c.jogador_id, n.nivel, ci.indicador_ambiental_id, ci.valor
                          FROM cidade c JOIN nivel_cidade n ON n.nivel_cidade_id = c.nivel_cidade_id
                          JOIN cidade_indicador ci ON ci.cidade_id = c.cidade_id
                         WHERE c.jogador_id IN ({$in})");
    $c->execute($ids);
    foreach ($c->fetchAll() as $r) {
        $id = (int) $r['jogador_id'];
        $out[$id]['nivelCidade'] = (int) $r['nivel'];
        $k = INDICADOR_CHAVE[(int) $r['indicador_ambiental_id']] ?? null;
        if ($k) $out[$id]['indicadores'][$k] = (int) $r['valor'];
    }
    $comRemovida = denuncia_ia_banco_pronto() ? ', m.removida' : '';
    $u = $pdo->prepare(
        "SELECT m.remetente_id, m.destinatario_id, m.texto, m.data_envio{$comRemovida}
           FROM mensagem m
           JOIN (SELECT IF(remetente_id = ?, destinatario_id, remetente_id) AS outro, MAX(mensagem_id) AS mid
                   FROM mensagem WHERE remetente_id = ? OR destinatario_id = ? GROUP BY outro) u ON u.mid = m.mensagem_id"
    );
    $u->execute([$eu, $eu, $eu]);
    foreach ($u->fetchAll() as $m) {
        $outro = (int) $m['remetente_id'] === $eu ? (int) $m['destinatario_id'] : (int) $m['remetente_id'];
        if (!isset($out[$outro])) continue;
        $removida = !empty($m['removida']);
        $texto = $removida ? 'Mensagem removida pela moderação' : preg_replace('/\s+/u', ' ', $m['texto']);
        $out[$outro]['ultima'] = [
            'texto' => mb_strlen($texto) > PREVIA_MAX ? mb_substr($texto, 0, PREVIA_MAX - 1) . '…' : $texto,
            'minha' => (int) $m['remetente_id'] === $eu, 'data' => $m['data_envio'], 'removida' => $removida,
        ];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
   Sugestões (RN60)
   --------------------------------------------------------------------------- */

/** Pares de amizade aceitos nos dois sentidos (eu → outro). */
const SQL_PARES = "(SELECT jogador_menor AS eu, jogador_maior AS outro FROM amizade WHERE status = 'aceita'
                    UNION ALL SELECT jogador_maior, jogador_menor FROM amizade WHERE status = 'aceita')";

/**
 * Até 6 jogadores sugeridos: amigos de amigos (por nº de amigos em comum) e, faltando, aleatórios.
 * Fora: o próprio, amigos e pedidos (qualquer sentido), bloqueios (qualquer sentido), contas bloqueadas e dispensados.
 */
function sugerir_amigos(int $eu, int $limite = SUGESTOES_MAX): array
{
    $pdo = db();
    $filtro = "j.jogador_id <> ? AND u.status = TRUE AND j.aparece_sugestoes = TRUE
        AND NOT EXISTS (SELECT 1 FROM amizade a WHERE a.jogador_menor = LEAST(j.jogador_id, ?) AND a.jogador_maior = GREATEST(j.jogador_id, ?))
        AND NOT EXISTS (SELECT 1 FROM bloqueio b WHERE (b.bloqueador_id = j.jogador_id AND b.bloqueado_id = ?) OR (b.bloqueador_id = ? AND b.bloqueado_id = j.jogador_id))
        AND NOT EXISTS (SELECT 1 FROM sugestao_dispensada d WHERE d.jogador_id = ? AND d.sugerido_id = j.jogador_id)";
    $params = array_fill(0, 6, $eu);

    $s = $pdo->prepare(SQL_PUBLICO . "
        JOIN (SELECT f2.outro AS candidato, COUNT(*) AS comuns
                FROM " . SQL_PARES . " f1 JOIN " . SQL_PARES . " f2 ON f2.eu = f1.outro
               WHERE f1.eu = ? GROUP BY f2.outro) m ON m.candidato = j.jogador_id
       WHERE {$filtro}
    ORDER BY m.comuns DESC, RAND() LIMIT {$limite}");
    $s->execute([$eu, ...$params]);
    $comuns = $s->fetchAll();

    $sugestoes = [];
    if ($comuns) {
        $ids = array_map(static fn($r) => (int) $r['jogador_id'], $comuns);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = $pdo->prepare("SELECT f2.outro AS candidato, j.apelido
                              FROM " . SQL_PARES . " f1 JOIN " . SQL_PARES . " f2 ON f2.eu = f1.outro
                              JOIN jogador j ON j.jogador_id = f1.outro JOIN usuario u ON u.usuario_id = j.usuario_id
                             WHERE f1.eu = ? AND u.status = TRUE AND f2.outro IN ({$in}) ORDER BY j.apelido");
        $n->execute([$eu, ...$ids]);
        $nomes = [];
        foreach ($n->fetchAll() as $r) $nomes[(int) $r['candidato']][] = $r['apelido'];
        foreach ($comuns as $r) {
            $todos = $nomes[(int) $r['jogador_id']] ?? [];
            $sugestoes[] = publico($r) + ['comuns' => count($todos), 'comunsNomes' => array_slice($todos, 0, 2)];
        }
    }

    $faltam = $limite - count($sugestoes);
    if ($faltam > 0) {
        $ja = array_map(static fn($x) => $x['id'], $sugestoes);
        $fora = $ja ? ' AND j.jogador_id NOT IN (' . implode(',', array_fill(0, count($ja), '?')) . ')' : '';
        $a = $pdo->prepare(SQL_PUBLICO . " WHERE {$filtro}{$fora} ORDER BY RAND() LIMIT {$faltam}");
        $a->execute([...$params, ...$ja]);
        foreach ($a->fetchAll() as $r) $sugestoes[] = publico($r) + ['comuns' => 0, 'comunsNomes' => []];
    }
    return $sugestoes;
}

/** "Agora não": esconde o jogador das sugestões (continua aparecendo na busca). */
function dispensar_sugestao(int $eu, int $outro): string
{
    if ($eu === $outro) throw new RegistroInvalido('Jogador inválido.');
    return c_transacao(static function (PDO $pdo) use ($eu, $outro): string {
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$eu]);
        $s = $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ?');
        $s->execute([$outro]);
        if (!$s->fetchColumn()) throw new RegistroInvalido('Jogador não encontrado.');
        $s = $pdo->prepare('SELECT COUNT(*) FROM sugestao_dispensada WHERE jogador_id = ? AND data >= NOW() - INTERVAL 1 DAY');
        $s->execute([$eu]);
        if ((int) $s->fetchColumn() >= DISPENSAS_POR_DIA) throw new RegistroInvalido('Limite de ' . DISPENSAS_POR_DIA . ' sugestões dispensadas por dia. Tente amanhã.');
        $pdo->prepare('INSERT IGNORE INTO sugestao_dispensada (jogador_id, sugerido_id, data) VALUES (?, ?, NOW())')->execute([$eu, $outro]);
        return 'Sugestão escondida. Você ainda pode encontrar o jogador pela busca.';
    });
}

/** Contagem para o selo da aba: mensagens não lidas de amigos + pedidos recebidos. */
function resumo_social(int $eu): array
{
    $pdo = db();
    $m = $pdo->prepare(
        "SELECT COUNT(*) FROM mensagem m
           JOIN amizade a ON a.status = 'aceita' AND a.jogador_menor = LEAST(m.remetente_id, m.destinatario_id) AND a.jogador_maior = GREATEST(m.remetente_id, m.destinatario_id)
          WHERE m.destinatario_id = ? AND m.data_leitura IS NULL"
    );
    $m->execute([$eu]);
    $p = $pdo->prepare("SELECT COUNT(*) FROM amizade a JOIN jogador j ON j.jogador_id = a.solicitante_id JOIN usuario u ON u.usuario_id = j.usuario_id WHERE a.destinatario_id = ? AND a.status = 'pendente' AND u.status = TRUE");
    $p->execute([$eu]);
    return ['naoLidas' => (int) $m->fetchColumn(), 'pedidos' => (int) $p->fetchColumn()];
}

/* ---------------------------------------------------------------------------
   Mensagens
   --------------------------------------------------------------------------- */

function formatar_mensagem(array $m, int $eu): array
{
    $removida = !empty($m['removida']);
    return [
        'id' => (int) $m['mensagem_id'], 'texto' => $removida ? '' : $m['texto'], 'minha' => (int) $m['remetente_id'] === $eu,
        'removida' => $removida, 'data' => $m['data_envio'], 'lida' => $m['data_leitura'] !== null,
    ];
}

/** Mensagens da conversa (as últimas 50, ou só as mais novas que $desdeId) e marca as recebidas como lidas. */
function conversa(int $eu, int $amigo, int $desdeId = 0): array
{
    $pdo = db();
    if (!sao_amigos($pdo, $eu, $amigo)) throw new RegistroInvalido('Vocês precisam ser amigos para conversar.');
    $s = $pdo->prepare(
        'SELECT * FROM (
            SELECT * FROM mensagem
             WHERE ((remetente_id = ? AND destinatario_id = ?) OR (remetente_id = ? AND destinatario_id = ?)) AND mensagem_id > ?
          ORDER BY mensagem_id DESC LIMIT ' . CONVERSA_LIMITE . ') t ORDER BY mensagem_id'
    );
    $s->execute([$eu, $amigo, $amigo, $eu, $desdeId]);
    $msgs = $s->fetchAll();
    $pdo->prepare('UPDATE mensagem SET data_leitura = NOW() WHERE remetente_id = ? AND destinatario_id = ? AND data_leitura IS NULL')->execute([$amigo, $eu]);
    $a = $pdo->prepare(SQL_PUBLICO . ' WHERE j.jogador_id = ?');
    $a->execute([$amigo]);
    return ['amigo' => publico($a->fetch()), 'mensagens' => array_map(static fn($m) => formatar_mensagem($m, $eu), $msgs)];
}

function enviar_mensagem(int $eu, int $amigo, string $texto): array
{
    $texto = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '');
    if ($texto === '') throw new RegistroInvalido('Escreva uma mensagem.');
    if (mb_strlen($texto) > MENSAGEM_MAX) throw new RegistroInvalido('A mensagem pode ter até ' . MENSAGEM_MAX . ' caracteres.');
    return c_transacao(static function (PDO $pdo) use ($eu, $amigo, $texto): array {
        $pdo->prepare('SELECT jogador_id FROM jogador WHERE jogador_id = ? FOR UPDATE')->execute([$eu]);
        if (!sao_amigos($pdo, $eu, $amigo)) throw new RegistroInvalido('Vocês precisam ser amigos para conversar.');
        jogador_ativo($pdo, $amigo);
        $s = $pdo->prepare('SELECT COUNT(*) FROM mensagem WHERE remetente_id = ? AND data_envio >= NOW() - INTERVAL 1 MINUTE');
        $s->execute([$eu]);
        if ((int) $s->fetchColumn() >= MENSAGENS_POR_MINUTO) throw new RegistroInvalido('Você está enviando mensagens rápido demais. Espere um pouco.');
        $pdo->prepare('INSERT INTO mensagem (remetente_id, destinatario_id, texto, data_envio) VALUES (?, ?, ?, NOW())')->execute([$eu, $amigo, $texto]);
        $m = $pdo->prepare('SELECT * FROM mensagem WHERE mensagem_id = ?');
        $m->execute([(int) $pdo->lastInsertId()]);
        return formatar_mensagem($m->fetch(), $eu);
    });
}

function denunciar_mensagem(int $eu, int $mensagemId, string $motivo, ?int &$denunciaId = null): string
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) > DENUNCIA_MOTIVO_MAX) throw new RegistroInvalido('O motivo pode ter até ' . DENUNCIA_MOTIVO_MAX . ' caracteres.');
    $pdo = db();
    $colRem = denuncia_ia_banco_pronto() ? ', removida' : '';
    $s = $pdo->prepare("SELECT destinatario_id{$colRem} FROM mensagem WHERE mensagem_id = ?");
    $s->execute([$mensagemId]);
    $m = $s->fetch();
    if (!$m || (int) $m['destinatario_id'] !== $eu) throw new RegistroInvalido('Só é possível denunciar mensagens que você recebeu.');
    if (!empty($m['removida'])) throw new RegistroInvalido('Esta mensagem já foi removida pela moderação.');
    try {
        $pdo->prepare('INSERT INTO denuncia_mensagem (mensagem_id, denunciante_id, motivo, data) VALUES (?, ?, ?, NOW())')
            ->execute([$mensagemId, $eu, $motivo !== '' ? $motivo : null]);
        $denunciaId = (int) $pdo->lastInsertId();
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') throw new RegistroInvalido('Você já denunciou esta mensagem.');
        throw $ex;
    }
    return 'Denúncia enviada. A equipe vai analisar a mensagem.';
}

/* ---------------------------------------------------------------------------
   Visita
   --------------------------------------------------------------------------- */

function visitar_cidade(int $eu, int $amigo): array
{
    $pdo = db();
    if (!sao_amigos($pdo, $eu, $amigo)) throw new RegistroInvalido('Só é possível visitar a cidade de amigos.');
    jogador_ativo($pdo, $amigo);
    $pv = $pdo->prepare('SELECT permite_visita FROM jogador WHERE jogador_id = ?');
    $pv->execute([$amigo]);
    if (!(bool) $pv->fetchColumn()) throw new RegistroInvalido('Este jogador prefere não receber visitas na cidade.');   // RN93
    aplicar_queda_diaria($pdo, $amigo);   // valores atuais (queda diária e poluição pendentes)

    $q = $pdo->prepare(
        'SELECT j.apelido, j.usuario_id, c.cidade_id, c.nome AS cidade, COALESCE(nj.nivel, 1) AS nivel, n.nivel AS nivel_cidade
           FROM jogador j JOIN cidade c ON c.jogador_id = j.jogador_id
           JOIN nivel_cidade n ON n.nivel_cidade_id = c.nivel_cidade_id
      LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
          WHERE j.jogador_id = ?'
    );
    $q->execute([$amigo]);
    $j = $q->fetch();
    $ind = $pdo->prepare('SELECT indicador_ambiental_id, valor FROM cidade_indicador WHERE cidade_id = ?');
    $ind->execute([$j['cidade_id']]);
    $indicadores = [];
    foreach ($ind->fetchAll() as $r) {
        $k = INDICADOR_CHAVE[(int) $r['indicador_ambiental_id']] ?? null;
        if ($k) $indicadores[$k] = (int) $r['valor'];
    }
    $construcao = dados_construcao($amigo);
    unset($construcao['catalogo']);   // a visita só desenha; não precisa do catálogo
    // Renda a coletar é dos créditos do amigo (RN59: créditos não aparecem)
    unset($construcao['efeitos']['renda'], $construcao['efeitos']['rendaPorDia']);
    $construcao['construidas'] = array_map(static function (array $c): array { unset($c['renda']); return $c; }, $construcao['construidas']);
    $conquistas = array_values(array_map(
        static fn(array $c): array => ['id' => $c['id'], 'nome' => $c['nome'], 'descricao' => $c['descricao'], 'criterio' => $c['criterio'], 'obtidaEm' => $c['obtidaEm']],
        array_filter(listar_conquistas($amigo), static fn(array $c): bool => $c['obtida'])
    ));
    return [
        'apelido' => $j['apelido'], 'cidade' => $j['cidade'], 'nivel' => (int) $j['nivel'], 'nivelCidade' => (int) $j['nivel_cidade'],
        'indicadores' => $indicadores, 'construcao' => $construcao, 'conquistas' => $conquistas,
    ];
}

/* ---------------------------------------------------------------------------
   Administração: denúncias
   --------------------------------------------------------------------------- */

/** Denúncias abertas com a mensagem, o parecer da IA (se houver) e as anteriores da conversa como contexto. */
function listar_denuncias_abertas(): array
{
    $pdo = db();
    $iaPronta = denuncia_ia_banco_pronto();
    $colsIa = $iaPronta ? ', d.ia_categoria, d.ia_confianca, d.ia_motivo, d.ia_acao, d.ia_analisada_em, m.removida' : '';
    $rows = $pdo->query(
        "SELECT d.denuncia_id, d.motivo, d.data, m.mensagem_id, m.texto, m.data_envio, m.remetente_id, m.destinatario_id{$colsIa},
                jr.apelido AS autor, jr.usuario_id AS autor_usuario, ur.status AS autor_ativo, jd.apelido AS denunciante,
                EXISTS (SELECT 1 FROM administrador ad WHERE ad.usuario_id = jr.usuario_id AND ad.ativo = TRUE) AS autor_admin
           FROM denuncia_mensagem d
           JOIN mensagem m ON m.mensagem_id = d.mensagem_id
           JOIN jogador jr ON jr.jogador_id = m.remetente_id
           JOIN usuario ur ON ur.usuario_id = jr.usuario_id
           JOIN jogador jd ON jd.jogador_id = d.denunciante_id
          WHERE d.status = 'aberta'
       ORDER BY d.data"
    )->fetchAll();
    $ctx = $pdo->prepare(
        'SELECT * FROM (SELECT m.mensagem_id, m.texto, m.data_envio, j.apelido FROM mensagem m JOIN jogador j ON j.jogador_id = m.remetente_id
          WHERE ((m.remetente_id = ? AND m.destinatario_id = ?) OR (m.remetente_id = ? AND m.destinatario_id = ?)) AND m.mensagem_id < ?
       ORDER BY m.mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY mensagem_id'
    );
    return array_map(static function (array $r) use ($ctx, $iaPronta): array {
        $ctx->execute([$r['remetente_id'], $r['destinatario_id'], $r['destinatario_id'], $r['remetente_id'], $r['mensagem_id']]);
        $ia = $iaPronta && !empty($r['ia_analisada_em']) ? [
            'categoria' => $r['ia_categoria'], 'confianca' => (float) $r['ia_confianca'],
            'motivo' => $r['ia_motivo'], 'acao' => $r['ia_acao'],
            'analisadaEm' => strtotime($r['ia_analisada_em']),
        ] : null;
        return [
            'id' => (int) $r['denuncia_id'], 'motivo' => $r['motivo'], 'data' => strtotime($r['data']),
            'status' => 'aberta', 'decididaPorIa' => false,
            'texto' => $r['texto'], 'enviadaEm' => strtotime($r['data_envio']), 'autor' => $r['autor'],
            'autorUsuario' => (int) $r['autor_usuario'], 'autorAtivo' => (bool) $r['autor_ativo'],
            'autorAdmin' => !empty($r['autor_admin']),
            'denunciante' => $r['denunciante'], 'removido' => !empty($r['removida']),
            'contexto' => array_map(static fn($c) => ['apelido' => $c['apelido'], 'texto' => $c['texto'], 'data' => strtotime($c['data_envio'])], $ctx->fetchAll()),
            'ia' => $ia,
        ];
    }, $rows);
}

/**
 * Resolve a denúncia de mensagem entre amigos.
 * @param string|bool $operacao 'resolver' | 'ocultar' | 'bloquear' (ou bool para compatibilidade)
 */
function resolver_denuncia(int $adminId, int $usuarioAdmin, int $denunciaId, string|bool $operacao = 'resolver'): string
{
    if (is_bool($operacao)) {
        $operacao = $operacao ? 'bloquear' : 'resolver';
    }
    if (!in_array($operacao, ['resolver', 'ocultar', 'bloquear'], true)) {
        throw new RegistroInvalido('Ação inválida.');
    }
    $pdo = db();
    $s = $pdo->prepare(
        "SELECT d.denuncia_id, d.mensagem_id, jr.usuario_id FROM denuncia_mensagem d JOIN mensagem m ON m.mensagem_id = d.mensagem_id
           JOIN jogador jr ON jr.jogador_id = m.remetente_id WHERE d.denuncia_id = ? AND d.status = 'aberta'"
    );
    $s->execute([$denunciaId]);
    $d = $s->fetch();
    if (!$d) throw new RegistroInvalido('Denúncia não encontrada ou já resolvida.');
    $extra = '';
    if ($operacao === 'ocultar' && denuncia_ia_banco_pronto()) {
        $pdo->prepare('UPDATE mensagem SET removida = 1 WHERE mensagem_id = ?')->execute([$d['mensagem_id']]);
        $extra = ' Mensagem ocultada.';
    } elseif ($operacao === 'bloquear') {
        acao_conta($usuarioAdmin, (int) $d['usuario_id'], 'bloquear');
        if (denuncia_ia_banco_pronto()) {
            $pdo->prepare('UPDATE mensagem SET removida = 1 WHERE mensagem_id = ?')->execute([$d['mensagem_id']]);
        }
        $extra = ' Conta do autor bloqueada.';
    }
    $pdo->prepare("UPDATE denuncia_mensagem SET status = 'resolvida', resolvida_por = ?, data_resolucao = NOW()"
        . (denuncia_ia_banco_pronto() ? ', decidida_por_ia = 0' : '') . ' WHERE denuncia_id = ?')
        ->execute([$adminId, $denunciaId]);
    return 'Denúncia resolvida.' . $extra;
}
