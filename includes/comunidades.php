<?php
/**
 * Comunidades: grupo de jogadores com mural, conversa em grupo, missões coletivas e ranking (specs/site.md RN61–RN68).
 *
 *  - 1 comunidade por jogador (comunidade_membro.jogador_id é a PK); até 30 membros; líder, até 3 moderadores e membros.
 *  - Criar exige nível do jogador e créditos (débito com histórico "comunidade").
 *  - Entrada aberta (direto) ou com aprovação (pedido); convite só para amigos. Quem sai espera 24 h para entrar em outra.
 *  - Conversa em grupo com os limites das mensagens entre amigos; mural só de líder e moderadores.
 *  - Contribuição: cada registro aprovado de um membro vale impacto real e pontos para a comunidade em que ele está.
 *  - Missões oficiais (administração, semanais) pagam XP/créditos a quem contribuiu e dão pontos; internas só acompanham.
 *  - Ranking da semana e geral (contribuições + missões oficiais concluídas), só comunidades ativas.
 */
declare(strict_types=1);

const COMUNIDADE_NIVEL_MIN = 5;
const COMUNIDADE_CUSTO = 500;
const COMUNIDADE_MAX_MEMBROS = 30;
const COMUNIDADE_MAX_MODERADORES = 3;
const COMUNIDADE_ESPERA_HORAS = 24;
const COMUNIDADE_ICONES = ['recycling', 'water', 'energy', 'mobility', 'nature'];
const COMUNIDADE_TOPICOS_DIA = 10;
const COMUNIDADE_FIXADOS_MAX = 3;
const COMUNIDADE_TOPICOS_LISTA = 30;
const COMUNIDADE_METAS_INTERNAS = 3;
const COMUNIDADE_META_DIAS = 30;
const COMUNIDADE_PONTOS_FOTO = 10;
const COMUNIDADE_PONTOS_RAPIDO = 5;
const COMUNIDADE_RANKING_TOP = 20;
const COMUNIDADE_BUSCA_MAX = 20;
const COMUNIDADE_CONVITES_DIA = 20;

/* ---------------------------------------------------------------------------
   Base
   --------------------------------------------------------------------------- */

/** Linha de participação do jogador (com dados da comunidade) ou null. */
function membro_de(PDO $pdo, int $jogadorId, bool $bloquear = false): ?array
{
    $s = $pdo->prepare(
        'SELECT m.*, c.nome, c.descricao, c.icone, c.entrada, c.data_criacao
           FROM comunidade_membro m JOIN comunidade c ON c.comunidade_id = m.comunidade_id
          WHERE m.jogador_id = ? AND c.ativa = TRUE' . ($bloquear ? ' FOR UPDATE' : '')
    );
    $s->execute([$jogadorId]);
    return $s->fetch() ?: null;
}

/** Participação obrigatória, opcionalmente com papel mínimo ('moderador' aceita líder também). */
function exigir_membro(PDO $pdo, int $eu, string $papel = 'membro'): array
{
    $m = membro_de($pdo, $eu, true);
    if (!$m) throw new RegistroInvalido('Você não participa de uma comunidade.');
    $ordem = ['membro' => 0, 'moderador' => 1, 'lider' => 2];
    if ($ordem[$m['papel']] < $ordem[$papel]) {
        throw new RegistroInvalido($papel === 'lider' ? 'Só o líder pode fazer isso.' : 'Só o líder e os moderadores podem fazer isso.');
    }
    return $m;
}

/** Bloqueia a comunidade (serializa entradas, papéis e conclusões de missão). */
function trancar_comunidade(PDO $pdo, int $comunidadeId): array
{
    $s = $pdo->prepare('SELECT * FROM comunidade WHERE comunidade_id = ? AND ativa = TRUE FOR UPDATE');
    $s->execute([$comunidadeId]);
    $c = $s->fetch();
    if (!$c) throw new RegistroInvalido('Comunidade não encontrada.');
    return $c;
}

function contar_membros(PDO $pdo, int $comunidadeId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_membro WHERE comunidade_id = ?');
    $s->execute([$comunidadeId]);
    return (int) $s->fetchColumn();
}

/** Pode entrar numa comunidade agora? (não participa de outra e já passou a espera após sair). */
function verificar_pode_entrar(PDO $pdo, int $eu): void
{
    $s = $pdo->prepare('SELECT saiu_comunidade_em FROM jogador WHERE jogador_id = ? FOR UPDATE');
    $s->execute([$eu]);
    $saiu = $s->fetchColumn();
    if (membro_de($pdo, $eu)) throw new RegistroInvalido('Você já participa de uma comunidade. Saia dela antes.');
    if ($saiu && strtotime((string) $saiu) > time() - COMUNIDADE_ESPERA_HORAS * 3600) {
        $falta = (int) ceil((strtotime((string) $saiu) + COMUNIDADE_ESPERA_HORAS * 3600 - time()) / 3600);
        throw new RegistroInvalido("Você saiu de uma comunidade há pouco. Espere {$falta} h para entrar em outra.");
    }
}

/** Põe o jogador na comunidade (vagas conferidas) e apaga os outros pedidos e convites dele. */
function adicionar_membro(PDO $pdo, int $comunidadeId, int $jogadorId, string $papel = 'membro'): void
{
    if (contar_membros($pdo, $comunidadeId) >= COMUNIDADE_MAX_MEMBROS) {
        throw new RegistroInvalido('A comunidade está cheia (' . COMUNIDADE_MAX_MEMBROS . ' membros).');
    }
    $ultima = $pdo->prepare('SELECT COALESCE(MAX(comunidade_mensagem_id), 0) FROM comunidade_mensagem WHERE comunidade_id = ?');
    $ultima->execute([$comunidadeId]);
    $pdo->prepare('INSERT INTO comunidade_membro (jogador_id, comunidade_id, papel, data_entrada, ultima_lida_id) VALUES (?, ?, ?, NOW(), ?)')
        ->execute([$jogadorId, $comunidadeId, $papel, (int) $ultima->fetchColumn()]);
    $pdo->prepare('DELETE FROM comunidade_pedido WHERE jogador_id = ?')->execute([$jogadorId]);
}

function nivel_do_jogador(PDO $pdo, int $jogadorId): array
{
    $s = $pdo->prepare('SELECT COALESCE(nj.nivel, 1) AS nivel, j.creditos_verde, j.saiu_comunidade_em
                          FROM jogador j LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id WHERE j.jogador_id = ?');
    $s->execute([$jogadorId]);
    return $s->fetch();
}

/** Valida nome, descrição, emblema e entrada. */
function dados_comunidade_validos(array $d): array
{
    $nome = c_texto($d, 'nome', 'Nome', 3, 40);
    $nome = preg_replace('/\s+/u', ' ', $nome);
    $icone = (string) ($d['icone'] ?? '');
    if (!in_array($icone, COMUNIDADE_ICONES, true)) throw new RegistroInvalido('Escolha um emblema.');
    $entrada = (string) ($d['entrada'] ?? '');
    if (!in_array($entrada, ['aberta', 'aprovacao'], true)) throw new RegistroInvalido('Escolha como os jogadores entram.');
    return ['nome' => $nome, 'descricao' => c_texto($d, 'descricao', 'Descrição', 0, 250, false), 'icone' => $icone, 'entrada' => $entrada];
}

/* ---------------------------------------------------------------------------
   Criar, entrar, sair
   --------------------------------------------------------------------------- */

function criar_comunidade(int $eu, array $d): string
{
    $v = dados_comunidade_validos($d);
    return c_transacao(static function (PDO $pdo) use ($eu, $v): string {
        verificar_pode_entrar($pdo, $eu);
        $j = nivel_do_jogador($pdo, $eu);
        if ((int) $j['nivel'] < COMUNIDADE_NIVEL_MIN) throw new RegistroInvalido('Criar uma comunidade exige o nível ' . COMUNIDADE_NIVEL_MIN . '.');
        if ((int) $j['creditos_verde'] < COMUNIDADE_CUSTO) throw new RegistroInvalido('Créditos verdes insuficientes (' . COMUNIDADE_CUSTO . ' necessários).');
        try {
            $pdo->prepare('INSERT INTO comunidade (nome, descricao, icone, entrada, data_criacao) VALUES (?, ?, ?, ?, NOW())')
                ->execute([$v['nome'], $v['descricao'], $v['icone'], $v['entrada']]);
        } catch (PDOException $ex) {
            c_unico($ex, 'Já existe uma comunidade com esse nome.');
        }
        $id = (int) $pdo->lastInsertId();
        adicionar_membro($pdo, $id, $eu, 'lider');
        $pdo->prepare('UPDATE jogador SET creditos_verde = creditos_verde - ? WHERE jogador_id = ?')->execute([COMUNIDADE_CUSTO, $eu]);
        $pdo->prepare("INSERT INTO historico_credito_verde (jogador_id, quantidade, origem, data, comunidade_id) VALUES (?, ?, 'comunidade', NOW(), ?)")
            ->execute([$eu, -COMUNIDADE_CUSTO, $id]);
        return "Comunidade {$v['nome']} criada! Convide seus amigos.";
    });
}

function editar_comunidade(int $eu, array $d): string
{
    $v = dados_comunidade_validos($d);
    return c_transacao(static function (PDO $pdo) use ($eu, $v): string {
        $m = exigir_membro($pdo, $eu, 'lider');
        try {
            $pdo->prepare('UPDATE comunidade SET nome = ?, descricao = ?, icone = ?, entrada = ? WHERE comunidade_id = ?')
                ->execute([$v['nome'], $v['descricao'], $v['icone'], $v['entrada'], $m['comunidade_id']]);
        } catch (PDOException $ex) {
            c_unico($ex, 'Já existe uma comunidade com esse nome.');
        }
        return 'Comunidade atualizada.';
    });
}

/** Entrar (aberta ou com convite) ou pedir para entrar (com aprovação). */
function entrar_comunidade(int $eu, int $comunidadeId): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $comunidadeId): string {
        verificar_pode_entrar($pdo, $eu);
        $c = trancar_comunidade($pdo, $comunidadeId);
        $p = $pdo->prepare('SELECT tipo FROM comunidade_pedido WHERE comunidade_id = ? AND jogador_id = ?');
        $p->execute([$comunidadeId, $eu]);
        $tipo = $p->fetchColumn();
        if ($c['entrada'] === 'aberta' || $tipo === 'convite') {
            adicionar_membro($pdo, $comunidadeId, $eu);
            return "Você entrou na comunidade {$c['nome']}!";
        }
        if ($tipo === 'pedido') throw new RegistroInvalido('Você já pediu para entrar. Aguarde a resposta.');
        if (contar_membros($pdo, $comunidadeId) >= COMUNIDADE_MAX_MEMBROS) throw new RegistroInvalido('A comunidade está cheia.');
        $pdo->prepare("INSERT INTO comunidade_pedido (comunidade_id, jogador_id, tipo, data) VALUES (?, ?, 'pedido', NOW())")
            ->execute([$comunidadeId, $eu]);
        return 'Pedido enviado. O líder ou um moderador vai responder.';
    });
}

/** Cancela o próprio pedido ou recusa um convite recebido. */
function cancelar_pedido_comunidade(int $eu, int $comunidadeId): string
{
    $s = db()->prepare('DELETE FROM comunidade_pedido WHERE comunidade_id = ? AND jogador_id = ?');
    $s->execute([$comunidadeId, $eu]);
    if (!$s->rowCount()) throw new RegistroInvalido('Pedido ou convite não encontrado.');
    return 'Pronto.';
}

/** Líder/moderador aceita ou recusa o pedido de entrada. */
function responder_pedido_comunidade(int $eu, int $jogadorId, bool $aceitar): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $jogadorId, $aceitar): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $cid = (int) $m['comunidade_id'];
        trancar_comunidade($pdo, $cid);
        $p = $pdo->prepare("SELECT 1 FROM comunidade_pedido WHERE comunidade_id = ? AND jogador_id = ? AND tipo = 'pedido'");
        $p->execute([$cid, $jogadorId]);
        if (!$p->fetchColumn()) throw new RegistroInvalido('Pedido não encontrado.');
        if (!$aceitar) {
            $pdo->prepare('DELETE FROM comunidade_pedido WHERE comunidade_id = ? AND jogador_id = ?')->execute([$cid, $jogadorId]);
            return 'Pedido recusado.';
        }
        jogador_ativo($pdo, $jogadorId);
        if (membro_de($pdo, $jogadorId)) {
            $pdo->prepare('DELETE FROM comunidade_pedido WHERE jogador_id = ?')->execute([$jogadorId]);
            throw new RegistroInvalido('Este jogador já entrou em outra comunidade.');
        }
        adicionar_membro($pdo, $cid, $jogadorId);
        return 'Pedido aceito. Boas-vindas ao novo membro!';
    });
}

/** Membro convida um amigo (que aceita depois). */
function convidar_para_comunidade(int $eu, int $amigo): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $amigo): string {
        $m = exigir_membro($pdo, $eu);
        $cid = (int) $m['comunidade_id'];
        if (!sao_amigos($pdo, $eu, $amigo)) throw new RegistroInvalido('Você só pode convidar amigos.');
        jogador_ativo($pdo, $amigo);
        if (membro_de($pdo, $amigo)) throw new RegistroInvalido('Este amigo já participa de uma comunidade.');
        if (contar_membros($pdo, $cid) >= COMUNIDADE_MAX_MEMBROS) throw new RegistroInvalido('A comunidade está cheia.');
        $s = $pdo->prepare("SELECT COUNT(*) FROM comunidade_pedido WHERE convidado_por = ? AND data >= NOW() - INTERVAL 1 DAY");
        $s->execute([$eu]);
        if ((int) $s->fetchColumn() >= COMUNIDADE_CONVITES_DIA) throw new RegistroInvalido('Limite de ' . COMUNIDADE_CONVITES_DIA . ' convites por dia.');
        $p = $pdo->prepare('SELECT tipo FROM comunidade_pedido WHERE comunidade_id = ? AND jogador_id = ? FOR UPDATE');
        $p->execute([$cid, $amigo]);
        $tipo = $p->fetchColumn();
        if ($tipo === 'convite') throw new RegistroInvalido('Este amigo já foi convidado.');
        if ($tipo === 'pedido') {
            if ($m['papel'] === 'membro') throw new RegistroInvalido('Este amigo já pediu para entrar; o líder ou um moderador vai responder.');
            adicionar_membro($pdo, $cid, $amigo);
            return 'Este amigo já tinha pedido para entrar e agora é membro.';
        }
        $pdo->prepare("INSERT INTO comunidade_pedido (comunidade_id, jogador_id, tipo, convidado_por, data) VALUES (?, ?, 'convite', ?, NOW())")
            ->execute([$cid, $amigo, $eu]);
        return 'Convite enviado.';
    });
}

/** Sai da comunidade. Líder passa a liderança (moderador mais antigo, senão membro mais antigo); sozinho, dissolve. */
function sair_comunidade(int $eu): string
{
    return c_transacao(static function (PDO $pdo) use ($eu): string {
        $m = exigir_membro($pdo, $eu);
        $cid = (int) $m['comunidade_id'];
        trancar_comunidade($pdo, $cid);
        $msg = 'Você saiu da comunidade.';
        if ($m['papel'] === 'lider') {
            $s = $pdo->prepare("SELECT jogador_id FROM comunidade_membro WHERE comunidade_id = ? AND jogador_id <> ?
                              ORDER BY papel = 'moderador' DESC, data_entrada LIMIT 1");
            $s->execute([$cid, $eu]);
            $novo = $s->fetchColumn();
            if ($novo === false) {
                dissolver($pdo, $cid);
                $pdo->prepare('UPDATE jogador SET saiu_comunidade_em = NOW() WHERE jogador_id = ?')->execute([$eu]);
                return 'Você saiu e a comunidade, sem outros membros, foi encerrada.';
            }
            $pdo->prepare("UPDATE comunidade_membro SET papel = 'lider' WHERE jogador_id = ?")->execute([(int) $novo]);
            $msg = 'Você saiu da comunidade e a liderança foi passada.';
        }
        $pdo->prepare('DELETE FROM comunidade_membro WHERE jogador_id = ?')->execute([$eu]);
        $pdo->prepare('UPDATE jogador SET saiu_comunidade_em = NOW() WHERE jogador_id = ?')->execute([$eu]);
        return $msg;
    });
}

function remover_membro(int $eu, int $alvo): string
{
    if ($eu === $alvo) throw new RegistroInvalido('Para sair, use "Sair da comunidade".');
    return c_transacao(static function (PDO $pdo) use ($eu, $alvo): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $a = membro_de($pdo, $alvo, true);
        if (!$a || (int) $a['comunidade_id'] !== (int) $m['comunidade_id']) throw new RegistroInvalido('Membro não encontrado.');
        if ($a['papel'] === 'lider' || ($m['papel'] === 'moderador' && $a['papel'] === 'moderador')) {
            throw new RegistroInvalido('Você não pode remover este membro.');
        }
        $pdo->prepare('DELETE FROM comunidade_membro WHERE jogador_id = ?')->execute([$alvo]);
        return 'Membro removido da comunidade.';
    });
}

/** Líder promove a moderador ou rebaixa a membro. */
function mudar_papel(int $eu, int $alvo, string $papel): string
{
    if (!in_array($papel, ['moderador', 'membro'], true)) throw new RegistroInvalido('Papel inválido.');
    return c_transacao(static function (PDO $pdo) use ($eu, $alvo, $papel): string {
        $m = exigir_membro($pdo, $eu, 'lider');
        $cid = (int) $m['comunidade_id'];
        $a = membro_de($pdo, $alvo, true);
        if (!$a || (int) $a['comunidade_id'] !== $cid || $a['papel'] === 'lider') throw new RegistroInvalido('Membro não encontrado.');
        if ($papel === 'moderador' && $a['papel'] !== 'moderador') {
            $s = $pdo->prepare("SELECT COUNT(*) FROM comunidade_membro WHERE comunidade_id = ? AND papel = 'moderador'");
            $s->execute([$cid]);
            if ((int) $s->fetchColumn() >= COMUNIDADE_MAX_MODERADORES) throw new RegistroInvalido('A comunidade já tem ' . COMUNIDADE_MAX_MODERADORES . ' moderadores.');
        }
        $pdo->prepare('UPDATE comunidade_membro SET papel = ? WHERE jogador_id = ?')->execute([$papel, $alvo]);
        return $papel === 'moderador' ? 'Membro promovido a moderador.' : 'Moderador voltou a ser membro.';
    });
}

function passar_lideranca(int $eu, int $alvo): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $alvo): string {
        $m = exigir_membro($pdo, $eu, 'lider');
        $cid = (int) $m['comunidade_id'];
        $a = membro_de($pdo, $alvo, true);
        if (!$a || (int) $a['comunidade_id'] !== $cid || $alvo === $eu) throw new RegistroInvalido('Membro não encontrado.');
        jogador_ativo($pdo, $alvo);
        $s = $pdo->prepare("SELECT COUNT(*) FROM comunidade_membro WHERE comunidade_id = ? AND papel = 'moderador' AND jogador_id <> ?");
        $s->execute([$cid, $alvo]);
        $meuPapel = (int) $s->fetchColumn() < COMUNIDADE_MAX_MODERADORES ? 'moderador' : 'membro';
        $pdo->prepare("UPDATE comunidade_membro SET papel = 'lider' WHERE jogador_id = ?")->execute([$alvo]);
        $pdo->prepare('UPDATE comunidade_membro SET papel = ? WHERE jogador_id = ?')->execute([$meuPapel, $eu]);
        return 'Liderança passada.';
    });
}

/** Encerra a comunidade (sai do ranking; histórico fica). Deve rodar numa transação. */
function dissolver(PDO $pdo, int $comunidadeId): void
{
    $pdo->prepare('UPDATE comunidade SET ativa = FALSE, data_dissolucao = NOW() WHERE comunidade_id = ?')->execute([$comunidadeId]);
    $pdo->prepare('DELETE FROM comunidade_membro WHERE comunidade_id = ?')->execute([$comunidadeId]);
    $pdo->prepare('DELETE FROM comunidade_pedido WHERE comunidade_id = ?')->execute([$comunidadeId]);
    $pdo->prepare('UPDATE comunidade_missao SET ativa = FALSE WHERE comunidade_id = ?')->execute([$comunidadeId]);
}

function dissolver_comunidade(int $eu): string
{
    return c_transacao(static function (PDO $pdo) use ($eu): string {
        $m = exigir_membro($pdo, $eu, 'lider');
        trancar_comunidade($pdo, (int) $m['comunidade_id']);
        dissolver($pdo, (int) $m['comunidade_id']);
        return 'Comunidade encerrada.';
    });
}

/* ---------------------------------------------------------------------------
   Conversa em grupo (mesmos limites das mensagens entre amigos)
   --------------------------------------------------------------------------- */

function formatar_mensagem_comunidade(array $m, int $eu): array
{
    $removida = !empty($m['removida']);
    return [
        'id' => (int) $m['comunidade_mensagem_id'], 'texto' => $removida ? '' : $m['texto'],
        'minha' => (int) $m['jogador_id'] === $eu, 'removida' => $removida,
        'autorId' => (int) $m['jogador_id'], 'autor' => $m['apelido'], 'data' => $m['data_envio'],
    ];
}

function conversa_comunidade(int $eu, int $desdeId = 0): array
{
    $pdo = db();
    $m = membro_de($pdo, $eu);
    if (!$m) throw new RegistroInvalido('Você não participa de uma comunidade.');
    $cid = (int) $m['comunidade_id'];
    $s = $pdo->prepare(
        'SELECT * FROM (
            SELECT cm.*, j.apelido FROM comunidade_mensagem cm JOIN jogador j ON j.jogador_id = cm.jogador_id
             WHERE cm.comunidade_id = ? AND cm.comunidade_mensagem_id > ?
          ORDER BY cm.comunidade_mensagem_id DESC LIMIT ' . CONVERSA_LIMITE . ') t ORDER BY comunidade_mensagem_id'
    );
    $s->execute([$cid, $desdeId]);
    $msgs = $s->fetchAll();
    if ($msgs) {
        $pdo->prepare('UPDATE comunidade_membro SET ultima_lida_id = GREATEST(ultima_lida_id, ?) WHERE jogador_id = ?')
            ->execute([(int) end($msgs)['comunidade_mensagem_id'], $eu]);
    }
    return array_map(static fn($x) => formatar_mensagem_comunidade($x, $eu), $msgs);
}

function enviar_mensagem_comunidade(int $eu, string $texto): array
{
    $texto = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '');
    if ($texto === '') throw new RegistroInvalido('Escreva uma mensagem.');
    if (mb_strlen($texto) > MENSAGEM_MAX) throw new RegistroInvalido('A mensagem pode ter até ' . MENSAGEM_MAX . ' caracteres.');
    return c_transacao(static function (PDO $pdo) use ($eu, $texto): array {
        $m = exigir_membro($pdo, $eu);
        $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_mensagem WHERE jogador_id = ? AND data_envio >= NOW() - INTERVAL 1 MINUTE');
        $s->execute([$eu]);
        if ((int) $s->fetchColumn() >= MENSAGENS_POR_MINUTO) throw new RegistroInvalido('Você está enviando mensagens rápido demais. Espere um pouco.');
        $pdo->prepare('INSERT INTO comunidade_mensagem (comunidade_id, jogador_id, texto, data_envio) VALUES (?, ?, ?, NOW())')
            ->execute([$m['comunidade_id'], $eu, $texto]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE comunidade_membro SET ultima_lida_id = ? WHERE jogador_id = ?')->execute([$id, $eu]);
        $r = $pdo->prepare('SELECT cm.*, j.apelido FROM comunidade_mensagem cm JOIN jogador j ON j.jogador_id = cm.jogador_id WHERE cm.comunidade_mensagem_id = ?');
        $r->execute([$id]);
        return formatar_mensagem_comunidade($r->fetch(), $eu);
    });
}

/** Denuncia mensagem ou tópico de outro membro da própria comunidade. */
function denunciar_comunidade(int $eu, string $tipo, int $alvoId, string $motivo, ?int &$denunciaId = null): string
{
    $motivo = trim($motivo);
    if (mb_strlen($motivo) > DENUNCIA_MOTIVO_MAX) throw new RegistroInvalido('O motivo pode ter até ' . DENUNCIA_MOTIVO_MAX . ' caracteres.');
    $msgSql = denuncia_ia_banco_pronto()
        ? 'SELECT comunidade_id, jogador_id, removida FROM comunidade_mensagem WHERE comunidade_mensagem_id = ?'
        : 'SELECT comunidade_id, jogador_id, 0 AS removida FROM comunidade_mensagem WHERE comunidade_mensagem_id = ?';
    $sql = [
        'mensagem' => $msgSql,
        'topico'   => 'SELECT comunidade_id, autor_id, 0 AS removida FROM comunidade_topico WHERE comunidade_topico_id = ? AND removido = FALSE',
    ][$tipo] ?? null;
    if (!$sql) throw new RegistroInvalido('Denúncia inválida.');
    $pdo = db();
    $m = membro_de($pdo, $eu);
    $s = $pdo->prepare($sql);
    $s->execute([$alvoId]);
    $alvo = $s->fetch(PDO::FETCH_NUM);
    if (!$m || !$alvo || (int) $alvo[0] !== (int) $m['comunidade_id']) throw new RegistroInvalido('Item não encontrado.');
    if ((int) $alvo[1] === $eu) throw new RegistroInvalido('Você não pode denunciar o que você mesmo publicou.');
    if (!empty($alvo[2])) throw new RegistroInvalido('Esta mensagem já foi removida pela moderação.');
    try {
        $pdo->prepare('INSERT INTO comunidade_denuncia (tipo, alvo_id, comunidade_id, denunciante_id, motivo, data) VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([$tipo, $alvoId, $alvo[0], $eu, $motivo !== '' ? $motivo : null]);
        $denunciaId = (int) $pdo->lastInsertId();
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') throw new RegistroInvalido('Você já denunciou isto.');
        throw $ex;
    }
    return 'Denúncia enviada. A equipe vai analisar.';
}

/* ---------------------------------------------------------------------------
   Mural
   --------------------------------------------------------------------------- */

function listar_topicos(PDO $pdo, int $comunidadeId, int $eu): array
{
    $s = $pdo->prepare(
        'SELECT t.*, j.apelido FROM comunidade_topico t JOIN jogador j ON j.jogador_id = t.autor_id
          WHERE t.comunidade_id = ? AND t.removido = FALSE
       ORDER BY t.fixado DESC, t.data DESC LIMIT ' . COMUNIDADE_TOPICOS_LISTA
    );
    $s->execute([$comunidadeId]);
    $topicos = $s->fetchAll();
    $pres = [];
    if ($topicos) {
        $ids = array_map(static fn($t) => (int) $t['comunidade_topico_id'], $topicos);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $p = $pdo->prepare("SELECT p.comunidade_topico_id, p.jogador_id, j.apelido FROM comunidade_presenca p
                              JOIN jogador j ON j.jogador_id = p.jogador_id WHERE p.comunidade_topico_id IN ({$in}) ORDER BY p.data");
        $p->execute($ids);
        foreach ($p->fetchAll() as $r) $pres[(int) $r['comunidade_topico_id']][] = $r;
    }
    return array_map(static function (array $t) use ($pres, $eu): array {
        $quem = $pres[(int) $t['comunidade_topico_id']] ?? [];
        return [
            'id' => (int) $t['comunidade_topico_id'], 'titulo' => $t['titulo'], 'texto' => $t['texto'],
            'dataEvento' => $t['data_evento'], 'local' => $t['local'], 'fixado' => (bool) $t['fixado'],
            'autorId' => (int) $t['autor_id'], 'autor' => $t['apelido'], 'data' => $t['data'],
            'vao' => count($quem), 'vaoNomes' => array_slice(array_column($quem, 'apelido'), 0, 5),
            'euVou' => in_array($eu, array_map('intval', array_column($quem, 'jogador_id')), true),
            'aberto' => $t['data_evento'] !== null && strtotime($t['data_evento']) > time(),
        ];
    }, $topicos);
}

function publicar_topico(int $eu, array $d): string
{
    $titulo = c_texto($d, 'titulo', 'Título', 3, 100);
    $texto = c_texto($d, 'texto', 'Texto', 0, 1000, false);
    $local = c_texto($d, 'local', 'Local', 0, 150, false);
    $evento = null;
    $bruto = trim((string) ($d['data_evento'] ?? ''));
    if ($bruto !== '') {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $bruto);
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $bruto) throw new RegistroInvalido('Data do encontro inválida.');
        if ($dt <= new DateTimeImmutable()) throw new RegistroInvalido('A data do encontro precisa ser no futuro.');
        $evento = $dt->format('Y-m-d H:i:s');
    }
    $fixar = c_bool($d, 'fixado');
    return c_transacao(static function (PDO $pdo) use ($eu, $titulo, $texto, $local, $evento, $fixar): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $cid = (int) $m['comunidade_id'];
        trancar_comunidade($pdo, $cid);
        $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_topico WHERE comunidade_id = ? AND data >= NOW() - INTERVAL 1 DAY');
        $s->execute([$cid]);
        if ((int) $s->fetchColumn() >= COMUNIDADE_TOPICOS_DIA) throw new RegistroInvalido('Limite de ' . COMUNIDADE_TOPICOS_DIA . ' tópicos por dia.');
        if ($fixar) verificar_vaga_fixado($pdo, $cid);
        $pdo->prepare('INSERT INTO comunidade_topico (comunidade_id, autor_id, titulo, texto, data_evento, local, fixado, data) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$cid, $eu, $titulo, $texto, $evento, $local, (int) $fixar]);
        return 'Tópico publicado no mural.';
    });
}

function verificar_vaga_fixado(PDO $pdo, int $cid): void
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_topico WHERE comunidade_id = ? AND fixado = TRUE AND removido = FALSE');
    $s->execute([$cid]);
    if ((int) $s->fetchColumn() >= COMUNIDADE_FIXADOS_MAX) throw new RegistroInvalido('Já há ' . COMUNIDADE_FIXADOS_MAX . ' tópicos fixados. Desafixe um antes.');
}

/** Tópico da comunidade do jogador (não removido). */
function topico_da_comunidade(PDO $pdo, int $cid, int $topicoId): array
{
    $s = $pdo->prepare('SELECT * FROM comunidade_topico WHERE comunidade_topico_id = ? AND comunidade_id = ? AND removido = FALSE');
    $s->execute([$topicoId, $cid]);
    $t = $s->fetch();
    if (!$t) throw new RegistroInvalido('Tópico não encontrado.');
    return $t;
}

function fixar_topico(int $eu, int $topicoId, bool $fixar): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $topicoId, $fixar): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $cid = (int) $m['comunidade_id'];
        trancar_comunidade($pdo, $cid);
        $t = topico_da_comunidade($pdo, $cid, $topicoId);
        if ($fixar && !$t['fixado']) verificar_vaga_fixado($pdo, $cid);
        $pdo->prepare('UPDATE comunidade_topico SET fixado = ? WHERE comunidade_topico_id = ?')->execute([(int) $fixar, $topicoId]);
        return $fixar ? 'Tópico fixado no topo.' : 'Tópico desafixado.';
    });
}

function remover_topico(int $eu, int $topicoId): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $topicoId): string {
        $m = exigir_membro($pdo, $eu);
        $t = topico_da_comunidade($pdo, (int) $m['comunidade_id'], $topicoId);
        if ((int) $t['autor_id'] !== $eu && $m['papel'] === 'membro') throw new RegistroInvalido('Só o autor, o líder e os moderadores podem apagar.');
        $pdo->prepare('UPDATE comunidade_topico SET removido = TRUE, fixado = FALSE WHERE comunidade_topico_id = ?')->execute([$topicoId]);
        return 'Tópico apagado.';
    });
}

function marcar_presenca(int $eu, int $topicoId, bool $vou): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $topicoId, $vou): string {
        $m = exigir_membro($pdo, $eu);
        $t = topico_da_comunidade($pdo, (int) $m['comunidade_id'], $topicoId);
        if ($t['data_evento'] === null || strtotime($t['data_evento']) <= time()) throw new RegistroInvalido('Este tópico não tem encontro marcado em aberto.');
        if ($vou) {
            $pdo->prepare('INSERT IGNORE INTO comunidade_presenca (comunidade_topico_id, jogador_id, data) VALUES (?, ?, NOW())')->execute([$topicoId, $eu]);
            return 'Presença confirmada!';
        }
        $pdo->prepare('DELETE FROM comunidade_presenca WHERE comunidade_topico_id = ? AND jogador_id = ?')->execute([$topicoId, $eu]);
        return 'Presença cancelada.';
    });
}

/* ---------------------------------------------------------------------------
   Contribuições e missões
   --------------------------------------------------------------------------- */

/** Semana (segunda 00:00 a domingo 23:59:59) que contém o instante. */
function semana_de(DateTimeImmutable $em): array
{
    $inicio = $em->modify('monday this week')->setTime(0, 0);
    return [$inicio, $inicio->modify('+6 days')->setTime(23, 59, 59)];
}

/**
 * Chamado na aprovação de um registro (na mesma transação): grava a contribuição para a comunidade
 * em que o jogador está agora e avalia as missões do período do registro.
 */
function registrar_contribuicao(PDO $pdo, int $registroId): void
{
    $s = $pdo->prepare(
        'SELECT r.jogador_id, r.atividade_sustentavel_id, r.tipo_verificacao_id, r.data_registro,
                COALESCE(r.impacto_real, a.impacto_real_quantidade) AS impacto_real_quantidade, m.comunidade_id
           FROM registro_atividade r
           JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
           JOIN comunidade_membro m ON m.jogador_id = r.jogador_id
           JOIN comunidade c ON c.comunidade_id = m.comunidade_id AND c.ativa = TRUE
          WHERE r.registro_atividade_id = ? AND r.status = \'aprovado\''
    );
    $s->execute([$registroId]);
    $r = $s->fetch();
    if (!$r) return;
    $pontos = (int) $r['tipo_verificacao_id'] === TIPO_AUTODECLARADA ? COMUNIDADE_PONTOS_RAPIDO : COMUNIDADE_PONTOS_FOTO;   // foto ou parceiro
    $ins = $pdo->prepare('INSERT IGNORE INTO comunidade_contribuicao
        (registro_atividade_id, comunidade_id, jogador_id, atividade_sustentavel_id, impacto_real, pontos, data_registro) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$registroId, $r['comunidade_id'], $r['jogador_id'], $r['atividade_sustentavel_id'], (float) ($r['impacto_real_quantidade'] ?? 0), $pontos, $r['data_registro']]);
    if ($ins->rowCount()) avaliar_missoes_comunidade($pdo, (int) $r['comunidade_id'], new DateTimeImmutable($r['data_registro']));
}

/** Missões oficiais ativas e válidas no instante. */
function missoes_oficiais(PDO $pdo, DateTimeImmutable $em): array
{
    $s = $pdo->prepare('SELECT m.*, a.nome AS atividade, a.impacto_real_unidade AS unidade, a.categoria_ambiental_id
                          FROM comunidade_missao m JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = m.atividade_sustentavel_id
                         WHERE m.comunidade_id IS NULL AND m.ativa = TRUE AND m.data_inicio <= ? AND (m.data_fim IS NULL OR m.data_fim >= ?)
                      ORDER BY m.comunidade_missao_id');
    $dia = $em->format('Y-m-d');
    $s->execute([$dia, $dia]);
    return $s->fetchAll();
}

/** Soma de impacto real da comunidade numa atividade e período. */
function impacto_no_periodo(PDO $pdo, int $cid, int $atividade, string $de, string $ate, ?int $jogador = null): array
{
    $sql = 'SELECT COALESCE(SUM(impacto_real), 0) AS impacto, COUNT(*) AS acoes FROM comunidade_contribuicao
             WHERE comunidade_id = ? AND atividade_sustentavel_id = ? AND data_registro BETWEEN ? AND ?';
    $p = [$cid, $atividade, $de, $ate];
    if ($jogador !== null) { $sql .= ' AND jogador_id = ?'; $p[] = $jogador; }
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $r = $s->fetch();
    return ['impacto' => (float) $r['impacto'], 'acoes' => (int) $r['acoes']];
}

/**
 * Conclui as missões do período que contém $em: oficiais (pontos + recompensa a quem contribuiu, uma vez)
 * e internas (só marca). Oficiais só contam até 24 h após o fim da semana. Deve rodar numa transação.
 * @return string[] nomes das missões oficiais concluídas agora
 */
function avaliar_missoes_comunidade(PDO $pdo, int $cid, DateTimeImmutable $em): array
{
    $concluidas = [];
    [$inicio, $fim] = semana_de($em);
    $de = $inicio->format('Y-m-d H:i:s');
    $ate = $fim->format('Y-m-d H:i:s');
    $prazoOk = new DateTimeImmutable() <= $fim->modify('+' . PRAZO_APROVACAO_HORAS . ' hours');
    $grava = $pdo->prepare('INSERT IGNORE INTO comunidade_missao_resultado (comunidade_missao_id, comunidade_id, periodo_inicio, pontos, data) VALUES (?, ?, ?, ?, NOW())');

    if ($prazoOk) {
        foreach (missoes_oficiais($pdo, $em) as $m) {
            $feito = impacto_no_periodo($pdo, $cid, (int) $m['atividade_sustentavel_id'], $de, $ate);
            if ($feito['impacto'] + 1e-9 < (float) $m['meta']) continue;
            $grava->execute([$m['comunidade_missao_id'], $cid, $inicio->format('Y-m-d'), (int) $m['pontos']]);
            if (!$grava->rowCount()) continue;                     // já concluída nesta semana
            $quem = $pdo->prepare('SELECT DISTINCT jogador_id FROM comunidade_contribuicao
                                    WHERE comunidade_id = ? AND atividade_sustentavel_id = ? AND data_registro BETWEEN ? AND ?');
            $quem->execute([$cid, $m['atividade_sustentavel_id'], $de, $ate]);
            foreach ($quem->fetchAll(PDO::FETCH_COLUMN) as $j) {
                if ((int) $m['recompensa_xp'] || (int) $m['recompensa_credito']) {
                    creditar_jogador($pdo, (int) $j, (int) $m['recompensa_xp'], (int) $m['recompensa_credito'], 'missao comunidade', 'comunidade_missao_id', (int) $m['comunidade_missao_id']);
                    avaliar_conquistas($pdo, (int) $j);
                }
            }
            $concluidas[] = $m['nome'];
        }
    }

    // Metas internas: concluídas quando a soma no prazo atinge a meta
    $s = $pdo->prepare('SELECT * FROM comunidade_missao WHERE comunidade_id = ? AND ativa = TRUE AND data_inicio <= ? AND data_fim >= ?');
    $dia = $em->format('Y-m-d');
    $s->execute([$cid, $dia, $dia]);
    foreach ($s->fetchAll() as $m) {
        $feito = impacto_no_periodo($pdo, $cid, (int) $m['atividade_sustentavel_id'], $m['data_inicio'] . ' 00:00:00', $m['data_fim'] . ' 23:59:59');
        if ($feito['impacto'] + 1e-9 >= (float) $m['meta']) $grava->execute([$m['comunidade_missao_id'], $cid, $m['data_inicio'], 0]);
    }
    return $concluidas;
}

/** Missões oficiais da semana e metas internas com o progresso. */
function listar_missoes_comunidade(PDO $pdo, int $cid, int $eu): array
{
    $agora = new DateTimeImmutable();
    [$inicio, $fim] = semana_de($agora);
    $de = $inicio->format('Y-m-d H:i:s');
    $ate = $fim->format('Y-m-d H:i:s');
    $feitas = $pdo->prepare('SELECT comunidade_missao_id, periodo_inicio FROM comunidade_missao_resultado WHERE comunidade_id = ?');
    $feitas->execute([$cid]);
    $res = [];
    foreach ($feitas->fetchAll() as $r) $res[$r['comunidade_missao_id'] . '|' . $r['periodo_inicio']] = true;

    $fmt = static fn(array $m, array $feito, array $meu, bool $concluida): array => [
        'id' => (int) $m['comunidade_missao_id'], 'nome' => $m['nome'], 'descricao' => $m['descricao'],
        'atividadeId' => (int) $m['atividade_sustentavel_id'], 'atividade' => $m['atividade'], 'unidade' => $m['unidade'],
        'categoria' => INDICADOR_CHAVE[(int) $m['categoria_ambiental_id']] ?? '',
        'meta' => (float) $m['meta'], 'feito' => $feito['impacto'], 'acoes' => $feito['acoes'],
        'meuImpacto' => $meu['impacto'], 'minhasAcoes' => $meu['acoes'], 'concluida' => $concluida,
        'xp' => (int) $m['recompensa_xp'], 'credito' => (int) $m['recompensa_credito'], 'pontos' => (int) $m['pontos'],
        'dataInicio' => $m['data_inicio'], 'dataFim' => $m['data_fim'], 'criadaPor' => $m['criada_por'] !== null ? (int) $m['criada_por'] : null,
    ];

    $oficiais = [];
    foreach (missoes_oficiais($pdo, $agora) as $m) {
        $a = (int) $m['atividade_sustentavel_id'];
        $oficiais[] = $fmt($m, impacto_no_periodo($pdo, $cid, $a, $de, $ate), impacto_no_periodo($pdo, $cid, $a, $de, $ate, $eu),
            isset($res[$m['comunidade_missao_id'] . '|' . $inicio->format('Y-m-d')]));
    }
    $s = $pdo->prepare('SELECT m.*, a.nome AS atividade, a.impacto_real_unidade AS unidade, a.categoria_ambiental_id
                          FROM comunidade_missao m JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = m.atividade_sustentavel_id
                         WHERE m.comunidade_id = ? AND m.ativa = TRUE AND m.data_fim >= CURDATE() - INTERVAL 7 DAY
                      ORDER BY m.data_fim');
    $s->execute([$cid]);
    $internas = [];
    foreach ($s->fetchAll() as $m) {
        $a = (int) $m['atividade_sustentavel_id'];
        [$d1, $d2] = [$m['data_inicio'] . ' 00:00:00', $m['data_fim'] . ' 23:59:59'];
        $internas[] = $fmt($m, impacto_no_periodo($pdo, $cid, $a, $d1, $d2), impacto_no_periodo($pdo, $cid, $a, $d1, $d2, $eu),
            isset($res[$m['comunidade_missao_id'] . '|' . $m['data_inicio']])) + ['encerrada' => $m['data_fim'] < $agora->format('Y-m-d')];
    }
    return ['semanaInicio' => $inicio->format('Y-m-d'), 'semanaFim' => $fim->format('Y-m-d'), 'oficiais' => $oficiais, 'internas' => $internas];
}

function criar_meta_interna(int $eu, array $d): string
{
    $nome = c_texto($d, 'nome', 'Nome da meta', 3, 100);
    $atividade = c_inteiro($d, 'atividade_id', 'Atividade', 1, PHP_INT_MAX);
    $dias = c_inteiro($d, 'dias', 'Prazo', 1, COMUNIDADE_META_DIAS);
    $meta = $d['meta'] ?? null;
    if (!is_numeric($meta) || (float) $meta <= 0 || (float) $meta > 1000000) throw new RegistroInvalido('Meta: informe um número maior que zero.');
    return c_transacao(static function (PDO $pdo) use ($eu, $nome, $atividade, $dias, $meta): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $cid = (int) $m['comunidade_id'];
        trancar_comunidade($pdo, $cid);
        if (!c_existe($pdo, 'SELECT 1 FROM atividade_sustentavel WHERE atividade_sustentavel_id = ? AND ativa = TRUE', [$atividade])) {
            throw new RegistroInvalido('Atividade não encontrada.');
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_missao WHERE comunidade_id = ? AND ativa = TRUE AND data_fim >= CURDATE()');
        $s->execute([$cid]);
        if ((int) $s->fetchColumn() >= COMUNIDADE_METAS_INTERNAS) throw new RegistroInvalido('A comunidade já tem ' . COMUNIDADE_METAS_INTERNAS . ' metas em andamento.');
        $pdo->prepare('INSERT INTO comunidade_missao (comunidade_id, nome, atividade_sustentavel_id, meta, data_inicio, data_fim, criada_por)
                       VALUES (?, ?, ?, ?, CURDATE(), CURDATE() + INTERVAL ? DAY, ?)')
            ->execute([$cid, $nome, $atividade, round((float) $meta, 2), $dias - 1, $eu]);
        avaliar_missoes_comunidade($pdo, $cid, new DateTimeImmutable());
        return 'Meta criada.';
    });
}

function remover_meta_interna(int $eu, int $metaId): string
{
    return c_transacao(static function (PDO $pdo) use ($eu, $metaId): string {
        $m = exigir_membro($pdo, $eu, 'moderador');
        $s = $pdo->prepare('UPDATE comunidade_missao SET ativa = FALSE WHERE comunidade_missao_id = ? AND comunidade_id = ? AND ativa = TRUE');
        $s->execute([$metaId, $m['comunidade_id']]);
        if (!$s->rowCount()) throw new RegistroInvalido('Meta não encontrada.');
        return 'Meta removida.';
    });
}

/* ---------------------------------------------------------------------------
   Ranking
   --------------------------------------------------------------------------- */

/**
 * Pontos de todas as comunidades ativas, ordenados (semana atual ou geral).
 * @return array<int, array> posição => linha
 */
function pontuacao_comunidades(PDO $pdo, string $periodo): array
{
    // Views vw_ranking_comunidade_semana / _geral (migração 025): pontos e posição já calculados
    $view = $periodo === 'semana' ? 'vw_ranking_comunidade_semana' : 'vw_ranking_comunidade_geral';
    $out = [];
    foreach ($pdo->query("SELECT * FROM {$view} ORDER BY posicao")->fetchAll() as $r) {
        $out[] = ['posicao' => (int) $r['posicao'], 'id' => (int) $r['comunidade_id'], 'nome' => $r['nome'], 'icone' => $r['icone'],
                  'entrada' => $r['entrada'], 'membros' => (int) $r['membros'], 'pontos' => (int) $r['pontos']];
    }
    return $out;
}

/** Top 20 + a linha da comunidade indicada (se estiver fora do top). */
function ranking_comunidades(PDO $pdo, string $periodo, ?int $minha): array
{
    $todas = pontuacao_comunidades($pdo, $periodo === 'geral' ? 'geral' : 'semana');
    $minhaLinha = null;
    foreach ($todas as $l) if ($l['id'] === $minha) $minhaLinha = $l;
    return ['top' => array_slice($todas, 0, COMUNIDADE_RANKING_TOP), 'minha' => $minhaLinha, 'total' => count($todas)];
}

/* ---------------------------------------------------------------------------
   Telas
   --------------------------------------------------------------------------- */

/** Comunidades para explorar (com busca por nome), já com pontos e posição da semana. */
function explorar_comunidades(int $eu, string $termo = ''): array
{
    $pdo = db();
    $termo = trim($termo);
    $semana = pontuacao_comunidades($pdo, 'semana');
    $p = $pdo->prepare("SELECT comunidade_id, tipo FROM comunidade_pedido WHERE jogador_id = ?");
    $p->execute([$eu]);
    $meus = $p->fetchAll(PDO::FETCH_KEY_PAIR);
    $d = $pdo->query('SELECT comunidade_id, descricao FROM comunidade WHERE ativa = TRUE')->fetchAll(PDO::FETCH_KEY_PAIR);
    $lista = array_values(array_filter($semana, static fn($c) => $termo === '' || mb_stripos($c['nome'], $termo) !== false));
    return array_map(static fn($c) => $c + ['descricao' => $d[$c['id']] ?? null, 'cheia' => $c['membros'] >= COMUNIDADE_MAX_MEMBROS, 'meuPedido' => $meus[$c['id']] ?? null],
        array_slice($lista, 0, COMUNIDADE_BUSCA_MAX));
}

const COMUNIDADE_AVISO_DIAS = 7;

/**
 * Missões e metas concluídas pela comunidade do jogador nos últimos 7 dias, para o aviso no jogo.
 * Cada uma traz a recompensa que o jogador recebeu (se contribuiu). O jogo guarda as já mostradas.
 * @return array<int, array{chave:string, nome:string, oficial:bool, pontos:int, xp:int, credito:int}>
 */
function avisos_missoes_comunidade(int $eu): array
{
    $pdo = db();
    $m = membro_de($pdo, $eu);
    if (!$m) return [];
    $s = $pdo->prepare(
        'SELECT r.comunidade_missao_id, r.periodo_inicio, r.pontos, r.data, cm.nome, cm.comunidade_id IS NULL AS oficial,
                (SELECT COALESCE(SUM(hx.quantidade), 0) FROM historico_xp hx
                  WHERE hx.jogador_id = ? AND hx.comunidade_missao_id = r.comunidade_missao_id AND hx.data >= r.data - INTERVAL 1 MINUTE
                    AND hx.data <= r.data + INTERVAL 1 MINUTE) AS xp,
                (SELECT COALESCE(SUM(hc.quantidade), 0) FROM historico_credito_verde hc
                  WHERE hc.jogador_id = ? AND hc.comunidade_missao_id = r.comunidade_missao_id AND hc.data >= r.data - INTERVAL 1 MINUTE
                    AND hc.data <= r.data + INTERVAL 1 MINUTE) AS credito
           FROM comunidade_missao_resultado r
           JOIN comunidade_missao cm ON cm.comunidade_missao_id = r.comunidade_missao_id
          WHERE r.comunidade_id = ? AND r.data >= NOW() - INTERVAL ' . COMUNIDADE_AVISO_DIAS . ' DAY AND r.data >= ?
       ORDER BY r.data'
    );
    // Só o que foi concluído depois que o jogador entrou na comunidade
    $s->execute([$eu, $eu, $m['comunidade_id'], $m['data_entrada']]);
    return array_map(static fn($r) => [
        'chave' => $m['comunidade_id'] . ':' . $r['comunidade_missao_id'] . ':' . $r['periodo_inicio'],
        'nome' => $r['nome'], 'oficial' => (bool) $r['oficial'], 'pontos' => (int) $r['pontos'],
        'xp' => (int) $r['xp'], 'credito' => (int) $r['credito'],
    ], $s->fetchAll());
}

/** Selo da aba: mensagens não lidas + pedidos (líder/moderador) ou convites (sem comunidade). */
function resumo_comunidade(int $eu): array
{
    $pdo = db();
    $m = membro_de($pdo, $eu);
    if (!$m) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM comunidade_pedido p JOIN comunidade c ON c.comunidade_id = p.comunidade_id AND c.ativa = TRUE
                             WHERE p.jogador_id = ? AND p.tipo = 'convite'");
        $s->execute([$eu]);
        return ['naoLidas' => 0, 'pedidos' => 0, 'convites' => (int) $s->fetchColumn()];
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM comunidade_mensagem WHERE comunidade_id = ? AND comunidade_mensagem_id > ? AND jogador_id <> ?');
    $s->execute([$m['comunidade_id'], $m['ultima_lida_id'], $eu]);
    $nl = (int) $s->fetchColumn();
    $ped = 0;
    if ($m['papel'] !== 'membro') {
        $p = $pdo->prepare("SELECT COUNT(*) FROM comunidade_pedido WHERE comunidade_id = ? AND tipo = 'pedido'");
        $p->execute([$m['comunidade_id']]);
        $ped = (int) $p->fetchColumn();
    }
    return ['naoLidas' => $nl, 'pedidos' => $ped, 'convites' => 0];
}

/** Tudo o que a aba precisa (com ou sem comunidade). */
function dados_comunidade(int $eu): array
{
    $pdo = db();
    $m = membro_de($pdo, $eu);
    $j = nivel_do_jogador($pdo, $eu);
    $atividades = $pdo->query('SELECT atividade_sustentavel_id AS id, nome, impacto_real_unidade AS unidade FROM atividade_sustentavel WHERE ativa = TRUE ORDER BY atividade_sustentavel_id')->fetchAll();
    $base = [
        'regras' => [
            'nivelMin' => COMUNIDADE_NIVEL_MIN, 'custo' => COMUNIDADE_CUSTO, 'maxMembros' => COMUNIDADE_MAX_MEMBROS,
            'maxModeradores' => COMUNIDADE_MAX_MODERADORES, 'esperaHoras' => COMUNIDADE_ESPERA_HORAS, 'fixadosMax' => COMUNIDADE_FIXADOS_MAX,
            'metasMax' => COMUNIDADE_METAS_INTERNAS, 'metaDias' => COMUNIDADE_META_DIAS,
            'pontosFoto' => COMUNIDADE_PONTOS_FOTO, 'pontosRapido' => COMUNIDADE_PONTOS_RAPIDO,
        ],
        'eu' => ['id' => $eu, 'nivel' => (int) $j['nivel'], 'creditos' => (int) $j['creditos_verde'],
                 'esperaAte' => $j['saiu_comunidade_em'] ? date('Y-m-d H:i:s', strtotime($j['saiu_comunidade_em']) + COMUNIDADE_ESPERA_HORAS * 3600) : null],
        'atividades' => array_map(static fn($a) => ['id' => (int) $a['id'], 'nome' => $a['nome'], 'unidade' => $a['unidade']], $atividades),
        'resumo' => resumo_comunidade($eu),
    ];
    if (!$m) {
        $c = $pdo->prepare("SELECT c.comunidade_id, c.nome, c.icone, c.descricao, c.entrada, j.apelido AS convidou,
                                   (SELECT COUNT(*) FROM comunidade_membro x WHERE x.comunidade_id = c.comunidade_id) AS membros
                              FROM comunidade_pedido p JOIN comunidade c ON c.comunidade_id = p.comunidade_id AND c.ativa = TRUE
                         LEFT JOIN jogador j ON j.jogador_id = p.convidado_por
                             WHERE p.jogador_id = ? AND p.tipo = 'convite' ORDER BY p.data DESC");
        $c->execute([$eu]);
        $convites = array_map(static fn($r) => ['id' => (int) $r['comunidade_id'], 'nome' => $r['nome'], 'icone' => $r['icone'], 'descricao' => $r['descricao'],
            'entrada' => $r['entrada'], 'convidou' => $r['convidou'], 'membros' => (int) $r['membros']], $c->fetchAll());
        return $base + ['comunidade' => null, 'convites' => $convites, 'explorar' => explorar_comunidades($eu),
                        'ranking' => ranking_comunidades($pdo, 'semana', null)];
    }

    $cid = (int) $m['comunidade_id'];
    [$inicio, $fim] = semana_de(new DateTimeImmutable());
    $mb = $pdo->prepare(SQL_PUBLICO . ' JOIN comunidade_membro m ON m.jogador_id = j.jogador_id AND m.comunidade_id = ?
                         WHERE u.status = TRUE ORDER BY FIELD(m.papel, \'lider\', \'moderador\', \'membro\'), m.data_entrada');
    $mb->execute([$cid]);
    $linhas = $mb->fetchAll();
    $pts = $pdo->prepare('SELECT jogador_id, SUM(pontos) AS pontos, COUNT(*) AS acoes FROM comunidade_contribuicao
                           WHERE comunidade_id = ? AND data_registro BETWEEN ? AND ? GROUP BY jogador_id');
    $pts->execute([$cid, $inicio->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);
    $porJogador = [];
    foreach ($pts->fetchAll() as $r) $porJogador[(int) $r['jogador_id']] = $r;
    $papel = $pdo->prepare('SELECT jogador_id, papel, data_entrada FROM comunidade_membro WHERE comunidade_id = ?');
    $papel->execute([$cid]);
    $papeis = [];
    foreach ($papel->fetchAll() as $r) $papeis[(int) $r['jogador_id']] = $r;
    $membros = array_map(static function (array $r) use ($porJogador, $papeis): array {
        $id = (int) $r['jogador_id'];
        return publico($r) + ['papel' => $papeis[$id]['papel'], 'desde' => $papeis[$id]['data_entrada'],
                              'pontosSemana' => (int) ($porJogador[$id]['pontos'] ?? 0), 'acoesSemana' => (int) ($porJogador[$id]['acoes'] ?? 0)];
    }, $linhas);

    $pedidos = [];
    if ($m['papel'] !== 'membro') {
        $p = $pdo->prepare(SQL_PUBLICO . " JOIN comunidade_pedido p ON p.jogador_id = j.jogador_id AND p.comunidade_id = ? AND p.tipo = 'pedido'
                            WHERE u.status = TRUE ORDER BY p.data");
        $p->execute([$cid]);
        $pedidos = array_map('publico', $p->fetchAll());
    }
    // Amigos que podem ser convidados (sem comunidade e sem convite pendente para esta)
    $am = $pdo->prepare(SQL_PUBLICO . " JOIN amizade a ON a.status = 'aceita' AND a.jogador_menor = LEAST(j.jogador_id, ?) AND a.jogador_maior = GREATEST(j.jogador_id, ?)
                         WHERE u.status = TRUE AND NOT EXISTS (SELECT 1 FROM comunidade_membro x WHERE x.jogador_id = j.jogador_id)
                           AND NOT EXISTS (SELECT 1 FROM comunidade_pedido q WHERE q.jogador_id = j.jogador_id AND q.comunidade_id = ? AND q.tipo = 'convite')
                      ORDER BY j.apelido");
    $am->execute([$eu, $eu, $cid]);

    $semana = ranking_comunidades($pdo, 'semana', $cid);
    $geral = ranking_comunidades($pdo, 'geral', $cid);
    return $base + [
        'comunidade' => [
            'id' => $cid, 'nome' => $m['nome'], 'descricao' => $m['descricao'], 'icone' => $m['icone'], 'entrada' => $m['entrada'],
            'criadaEm' => $m['data_criacao'], 'papel' => $m['papel'], 'membros' => count($membros),
            'posicaoSemana' => $semana['minha']['posicao'] ?? null, 'pontosSemana' => $semana['minha']['pontos'] ?? 0,
            'posicaoGeral' => $geral['minha']['posicao'] ?? null, 'pontosGeral' => $geral['minha']['pontos'] ?? 0, 'total' => $semana['total'],
        ],
        'membros' => $membros, 'pedidos' => $pedidos, 'amigosConvidaveis' => array_map('publico', $am->fetchAll()),
        'topicos' => listar_topicos($pdo, $cid, $eu), 'missoes' => listar_missoes_comunidade($pdo, $cid, $eu),
        'ranking' => $semana, 'rankingGeral' => $geral,
    ];
}

/* ---------------------------------------------------------------------------
   Administração
   --------------------------------------------------------------------------- */

function listar_comunidades_admin(): array
{
    $pdo = db();
    $geral = [];
    foreach (pontuacao_comunidades($pdo, 'geral') as $l) $geral[$l['id']] = $l;
    $rows = $pdo->query("SELECT c.comunidade_id, c.nome, c.entrada, c.data_criacao, j.apelido AS lider
                           FROM comunidade c
                      LEFT JOIN comunidade_membro m ON m.comunidade_id = c.comunidade_id AND m.papel = 'lider'
                      LEFT JOIN jogador j ON j.jogador_id = m.jogador_id
                          WHERE c.ativa = TRUE ORDER BY c.nome")->fetchAll();
    return array_map(static fn($r) => [
        'id' => (int) $r['comunidade_id'], 'nome' => $r['nome'], 'entrada' => $r['entrada'], 'criadaEm' => $r['data_criacao'], 'lider' => $r['lider'],
        'membros' => $geral[(int) $r['comunidade_id']]['membros'] ?? 0, 'pontos' => $geral[(int) $r['comunidade_id']]['pontos'] ?? 0,
        'posicao' => $geral[(int) $r['comunidade_id']]['posicao'] ?? null,
    ], $rows);
}

function dissolver_comunidade_admin(int $comunidadeId): string
{
    return c_transacao(static function (PDO $pdo) use ($comunidadeId): string {
        $c = trancar_comunidade($pdo, $comunidadeId);
        dissolver($pdo, $comunidadeId);
        return "Comunidade {$c['nome']} encerrada.";
    });
}

/** Denúncias abertas de mensagens e tópicos de comunidade, com contexto e parecer da IA (se houver). */
function listar_denuncias_comunidade(): array
{
    $pdo = db();
    $iaPronta = denuncia_ia_banco_pronto();
    $rows = $pdo->query(
        "SELECT d.*, c.nome AS comunidade, jd.apelido AS denunciante FROM comunidade_denuncia d
           JOIN comunidade c ON c.comunidade_id = d.comunidade_id JOIN jogador jd ON jd.jogador_id = d.denunciante_id
          WHERE d.status = 'aberta' ORDER BY d.data, d.comunidade_denuncia_id"
    )->fetchAll();
    $msg = $pdo->prepare('SELECT cm.*, j.apelido, j.usuario_id, u.status,
                                 EXISTS (SELECT 1 FROM administrador ad WHERE ad.usuario_id = j.usuario_id AND ad.ativo = TRUE) AS autor_admin
                            FROM comunidade_mensagem cm JOIN jogador j ON j.jogador_id = cm.jogador_id
                           JOIN usuario u ON u.usuario_id = j.usuario_id WHERE cm.comunidade_mensagem_id = ?');
    $top = $pdo->prepare('SELECT t.*, j.apelido, j.usuario_id, u.status,
                                 EXISTS (SELECT 1 FROM administrador ad WHERE ad.usuario_id = j.usuario_id AND ad.ativo = TRUE) AS autor_admin
                            FROM comunidade_topico t JOIN jogador j ON j.jogador_id = t.autor_id
                           JOIN usuario u ON u.usuario_id = j.usuario_id WHERE t.comunidade_topico_id = ?');
    $ctx = $pdo->prepare('SELECT * FROM (SELECT cm.comunidade_mensagem_id, cm.texto, cm.data_envio, j.apelido FROM comunidade_mensagem cm
                            JOIN jogador j ON j.jogador_id = cm.jogador_id WHERE cm.comunidade_id = ? AND cm.comunidade_mensagem_id < ?
                        ORDER BY cm.comunidade_mensagem_id DESC LIMIT ' . DENUNCIA_CONTEXTO . ') t ORDER BY comunidade_mensagem_id');
    $out = [];
    foreach ($rows as $r) {
        $eMsg = $r['tipo'] === 'mensagem';
        $q = $eMsg ? $msg : $top;
        $q->execute([$r['alvo_id']]);
        $a = $q->fetch();
        if (!$a) continue;
        $contexto = [];
        if ($eMsg) {
            $ctx->execute([$r['comunidade_id'], $r['alvo_id']]);
            $contexto = array_map(static fn($c) => ['apelido' => $c['apelido'], 'texto' => $c['texto'], 'data' => strtotime($c['data_envio'])], $ctx->fetchAll());
        }
        $ia = $iaPronta && !empty($r['ia_analisada_em']) ? [
            'categoria' => $r['ia_categoria'], 'confianca' => (float) $r['ia_confianca'],
            'motivo' => $r['ia_motivo'], 'acao' => $r['ia_acao'],
            'analisadaEm' => strtotime($r['ia_analisada_em']),
        ] : null;
        $out[] = [
            'id' => (int) $r['comunidade_denuncia_id'], 'tipo' => $r['tipo'], 'comunidade' => $r['comunidade'], 'motivo' => $r['motivo'],
            'data' => strtotime($r['data']), 'status' => 'aberta', 'decididaPorIa' => false,
            'denunciante' => $r['denunciante'], 'autor' => $a['apelido'],
            'autorAtivo' => (bool) $a['status'], 'autorAdmin' => !empty($a['autor_admin']),
            'removido' => $eMsg ? !empty($a['removida']) : (bool) $a['removido'],
            'titulo' => $eMsg ? null : $a['titulo'], 'texto' => $eMsg ? $a['texto'] : (string) $a['texto'],
            'enviadaEm' => strtotime($eMsg ? $a['data_envio'] : $a['data']), 'contexto' => $contexto, 'ia' => $ia,
        ];
    }
    return $out;
}

/** operacao: resolver | ocultar (mensagem) | remover (tópico) | bloquear (conta do autor). */
function resolver_denuncia_comunidade(int $adminId, int $usuarioAdmin, int $denunciaId, string $operacao): string
{
    $pdo = db();
    $s = $pdo->prepare("SELECT * FROM comunidade_denuncia WHERE comunidade_denuncia_id = ? AND status = 'aberta'");
    $s->execute([$denunciaId]);
    $d = $s->fetch();
    if (!$d) throw new RegistroInvalido('Denúncia não encontrada ou já resolvida.');
    $eMsg = $d['tipo'] === 'mensagem';
    $extra = '';
    if ($operacao === 'remover') {
        if ($eMsg) throw new RegistroInvalido('Só tópicos podem ser removidos.');
        $pdo->prepare('UPDATE comunidade_topico SET removido = TRUE, fixado = FALSE WHERE comunidade_topico_id = ?')->execute([$d['alvo_id']]);
        $extra = ' Tópico removido do mural.';
    } elseif ($operacao === 'ocultar') {
        if (!$eMsg) throw new RegistroInvalido('Use remover para tópicos do mural.');
        if (denuncia_ia_banco_pronto()) {
            $pdo->prepare('UPDATE comunidade_mensagem SET removida = 1 WHERE comunidade_mensagem_id = ?')->execute([$d['alvo_id']]);
            $extra = ' Mensagem ocultada.';
        }
    } elseif ($operacao === 'bloquear') {
        $q = $pdo->prepare($eMsg
            ? 'SELECT j.usuario_id FROM comunidade_mensagem x JOIN jogador j ON j.jogador_id = x.jogador_id WHERE x.comunidade_mensagem_id = ?'
            : 'SELECT j.usuario_id FROM comunidade_topico x JOIN jogador j ON j.jogador_id = x.autor_id WHERE x.comunidade_topico_id = ?');
        $q->execute([$d['alvo_id']]);
        acao_conta($usuarioAdmin, (int) $q->fetchColumn(), 'bloquear');
        if ($eMsg && denuncia_ia_banco_pronto()) {
            $pdo->prepare('UPDATE comunidade_mensagem SET removida = 1 WHERE comunidade_mensagem_id = ?')->execute([$d['alvo_id']]);
        } elseif (!$eMsg) {
            $pdo->prepare('UPDATE comunidade_topico SET removido = TRUE, fixado = FALSE WHERE comunidade_topico_id = ?')->execute([$d['alvo_id']]);
        }
        $extra = ' Conta do autor bloqueada.';
    } elseif ($operacao !== 'resolver') {
        throw new RegistroInvalido('Ação inválida.');
    }
    $pdo->prepare("UPDATE comunidade_denuncia SET status = 'resolvida', resolvida_por = ?, data_resolucao = NOW()"
        . (denuncia_ia_banco_pronto() ? ', decidida_por_ia = 0' : '') . ' WHERE comunidade_denuncia_id = ?')
        ->execute([$adminId, $denunciaId]);
    return 'Denúncia resolvida.' . $extra;
}
