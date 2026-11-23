<?php
/**
 * Sincronização em tempo real (RN85).
 *
 * Os triggers da migração 024 aumentam `sincronia.versao` (por jogador, comunidade ou global e por domínio)
 * sempre que os dados mudam. O jogo envia as versões que conhece a cada 4 s (api/sincronizar.php) e recebe
 * só os dados dos domínios que mudaram. dados_jogador() também monta os dados iniciais de jogo.php.
 */
declare(strict_types=1);

const SINC_AVALIAR_SEGUNDOS = 60;   // missões e conquistas reavaliadas no máximo 1 vez por minuto na consulta
const SINC_SEM_TABELA_SEGUNDOS = 30; // banco sem a migração 024: tudo é atualizado a cada 30 s

/** Campos de dados de cada domínio (os nomes são os de state.js). */
const SINC_CAMPOS = [
    'jogador'    => ['jogador', 'perfil'],
    'registros'  => ['registros', 'jogador', 'perfil'],
    'missoes'    => ['missoes'],
    'conquistas' => ['conquistas', 'perfil'],
    'quizzes'    => ['quizzes', 'rotacaoQuiz', 'tentativas', 'perfil'],
    'construcao' => ['construcao', 'perfil'],
    'social'     => ['social'],
    'comunidade' => ['comunidade', 'avisosComunidade'],
    'conteudo'   => ['atividades', 'quizzes', 'rotacaoQuiz', 'missoes', 'construcao', 'conquistas'],
    'dia'        => ['jogador', 'quizzes', 'rotacaoQuiz', 'missoes', 'construcao'],
];

/**
 * Dados do jogo do jogador, só os campos pedidos (null = todos).
 * @param string[]|null $campos
 */
function dados_jogador(int $usuarioId, int $jogadorId, ?array $campos = null): array
{
    $quer = static fn(string $c): bool => $campos === null || in_array($c, $campos, true);
    $d = [];
    if ($quer('jogador'))      $d['jogador'] = carregar_jogador($usuarioId);
    if ($quer('atividades'))   $d['atividades'] = listar_atividades();
    if ($quer('registros'))    $d['registros'] = listar_registros($jogadorId);
    if ($quer('quizzes') || $quer('rotacaoQuiz')) {
        $q = quizzes_do_jogador($jogadorId);
        $d['quizzes'] = $q['quizzes'];
        $d['rotacaoQuiz'] = $q['rotacao'];
    }
    if ($quer('tentativas'))   $d['tentativas'] = listar_tentativas($jogadorId);
    if ($quer('conquistas'))   $d['conquistas'] = listar_conquistas($jogadorId);
    if ($quer('perfil'))       $d['perfil'] = perfil_completo($usuarioId, $jogadorId);
    if ($quer('missoes'))      $d['missoes'] = listar_missoes($jogadorId);
    if ($quer('construcao'))   $d['construcao'] = dados_construcao($jogadorId);
    if ($quer('social'))       $d['social'] = resumo_social($jogadorId);
    if ($quer('comunidade'))   $d['comunidade'] = resumo_comunidade($jogadorId);
    if ($quer('avisosComunidade')) $d['avisosComunidade'] = avisos_missoes_comunidade($jogadorId);
    return $d;
}

/** A migração 024 está no banco? (o código é o mesmo do Wamp e do Docker) */
function sincronia_disponivel(PDO $pdo): bool
{
    return schema_flag('sincronia', static fn(): bool => (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sincronia'")->fetchColumn());
}

/**
 * Versão atual de cada domínio para o jogador (texto comparável; muda quando algo muda).
 * @return array<string, string>
 */
function versoes_jogador(int $jogadorId): array
{
    $pdo = db();
    $dia = date('Y-m-d');
    if (!sincronia_disponivel($pdo)) {
        $t = 't' . intdiv(time(), SINC_SEM_TABELA_SEGUNDOS);
        return array_merge(array_fill_keys(array_keys(SINC_CAMPOS), $t), ['dia' => $dia]);
    }
    $com = $pdo->prepare('SELECT comunidade_id FROM comunidade_membro WHERE jogador_id = ?');
    $com->execute([$jogadorId]);
    $comunidadeId = (int) $com->fetchColumn();

    $s = $pdo->prepare(
        "SELECT escopo, dominio, versao FROM sincronia
          WHERE (escopo = 'jogador' AND escopo_id = ?) OR (escopo = 'comunidade' AND escopo_id = ?) OR (escopo = 'global' AND dominio = 'conteudo')"
    );
    $s->execute([$jogadorId, $comunidadeId]);
    $v = [];
    foreach ($s as $l) {
        $v[$l['escopo'] . '.' . $l['dominio']] = (int) $l['versao'];
    }
    $versoes = [];
    foreach (['jogador', 'registros', 'missoes', 'conquistas', 'quizzes', 'construcao', 'social'] as $dom) {
        $versoes[$dom] = (string) ($v['jogador.' . $dom] ?? 0);
    }
    // Comunidade: a do jogador (entrou, saiu, convites, não lidas) + a da comunidade em que ele está
    $versoes['comunidade'] = $comunidadeId . ':' . ($v['comunidade.comunidade'] ?? 0) . ':' . ($v['jogador.comunidade'] ?? 0);
    $versoes['conteudo'] = (string) ($v['global.conteudo'] ?? 0);
    $versoes['dia'] = $dia;
    return $versoes;
}

/**
 * Domínios que mudaram em relação às versões conhecidas pelo navegador e os dados deles.
 * @param array<string, string> $conhecidas
 * @return array{versoes: array<string, string>, mudou: string[], dados: array}
 */
function sincronizar_jogador(int $usuarioId, int $jogadorId, array $conhecidas): array
{
    $versoes = versoes_jogador($jogadorId);
    $mudou = [];
    foreach ($versoes as $dom => $v) {
        if ((string) ($conhecidas[$dom] ?? '') !== $v) $mudou[] = $dom;
    }
    $campos = array_values(array_unique(array_merge([], ...array_map(static fn($d) => SINC_CAMPOS[$d] ?? [], $mudou))));
    return ['versoes' => $versoes, 'mudou' => $mudou, 'dados' => $campos ? dados_jogador($usuarioId, $jogadorId, $campos) : []];
}

/** Novidades da administração: versões globais e contagens para os selos das abas. */
function sincronia_admin(?array $admin = null): array
{
    $pdo = db();
    $pode = static fn(string $p): bool => $admin === null || admin_tem_permissao($admin, $p);
    $r = [];
    if ($pode('contestacoes')) {
        $r['pendentes'] = (int) $pdo->query("SELECT COUNT(*) FROM registro_atividade WHERE status = 'pendente' AND tipo_verificacao_id = 2")->fetchColumn();
        $r['contestacoes'] = contestacoes_abertas();   // RN94
    }
    if ($pode('denuncias')) {
        $r['denuncias'] = (int) $pdo->query("SELECT (SELECT COUNT(*) FROM denuncia_mensagem WHERE status = 'aberta') + (SELECT COUNT(*) FROM comunidade_denuncia WHERE status = 'aberta')")->fetchColumn();
    }
    if ($pode('parceiros')) {
        $r['parceiros'] = (int) $pdo->query("SELECT COUNT(*) FROM parceiro WHERE status = 'pendente'")->fetchColumn() + pedidos_atividade_pendentes();   // + pedidos de atividade (RN95)
    }

    $usaAdmin = $pode('contestacoes') || $pode('denuncias') || $pode('parceiros');
    $usaConteudo = $admin === null || $pode('visao_geral') || admin_secoes_conteudo_permitidas($admin) !== [];

    if (sincronia_disponivel($pdo)) {
        $v = $pdo->query("SELECT dominio, versao FROM sincronia WHERE escopo = 'global' AND escopo_id = 0")->fetchAll(PDO::FETCH_KEY_PAIR);
        $r['versao'] = ($usaAdmin ? ($v['admin'] ?? 0) : '0') . ':' . ($usaConteudo ? ($v['conteudo'] ?? 0) : '0');
    } else {
        $r['versao'] = 'c' . ($r['pendentes'] ?? 0) . ':' . ($r['contestacoes'] ?? 0) . ':' . ($r['denuncias'] ?? 0) . ':' . ($r['parceiros'] ?? 0);   // sem a migração 024: pelas contagens
    }
    return ['versao' => $r['versao']] + $r;
}
