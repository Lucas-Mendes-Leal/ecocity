<?php
/**
 * EcoCity — gerador dos dados de teste (database/projeto/06-dados-teste.sql).
 *
 * Simula, de forma determinística (mesma semente = mesmos dados), ~120 jogadores de DEMONSTRAÇÃO
 * usando o jogo por 8 semanas: acessos com retenção decrescente, registros nos tipos sem foto e com foto
 * (aprovados, rejeitados e pendentes), quizzes (1ª tentativa, treino e abandonadas), queda diária e impacto
 * nos indicadores, conquistas, missões da semana atual, construções, amizades, mensagens e 4 comunidades.
 * Saldo e XP saem sempre dos históricos (sp_verificar_integridade() fica sem divergências).
 * As 10 primeiras são contas de APRESENTAÇÃO (apresentacao01..10@demo.ecocity.test) com senha própria,
 * gravada em database/contas-demo.md; as demais têm senha aleatória descartada (não entram pelo login).
 *
 * Os dados são fictícios e marcados (usuario.demonstracao = TRUE, e-mails @demo.ecocity.test).
 * Não foram desenhados para provar nenhuma conclusão (ex.: Aprenda → Pratique é aleatório).
 * Apagar: CALL sp_apagar_demonstracao(TRUE) — mantém as 10 contas de apresentação; FALSE apaga todas.
 *
 * Uso: php database/projeto/gerar-dados-teste.php   (lê o conteúdo oficial do banco local)
 */
declare(strict_types=1);

require __DIR__ . '/../../includes/bootstrap.php';

const SEMENTE = 2026;
const JOGADORES = 120;
const APRESENTACAO = 10;
const INICIO = '2026-09-21';             // segunda-feira, 8 semanas antes da geração
const SEMANA_MISSOES = '2026-11-16';     // missões semanais e oficiais existem a partir desta semana

$pdo = db();
mt_srand(SEMENTE);
$agora = new DateTimeImmutable();
$hoje = $agora->format('Y-m-d');
$rnd = static fn(): float => mt_rand() / mt_getrandmax();
$escolhe = static fn(array $a) => $a[mt_rand(0, count($a) - 1)];

/* ---------------------------------------------------------------- conteúdo oficial (lido do banco) */
$atividades = [];
foreach ($pdo->query('SELECT a.atividade_sustentavel_id id, a.nome, a.recompensa_xp xp, a.recompensa_credito cr, ai.impacto, ai.indicador_ambiental_id ind, a.impacto_real_quantidade qreal
                        FROM atividade_sustentavel a JOIN atividade_indicador_ambiental ai USING(atividade_sustentavel_id) WHERE a.ativa ORDER BY 1') as $a) {
    $atividades[(int) $a['id']] = ['xp' => (int) $a['xp'], 'cr' => (int) $a['cr'], 'impacto' => (int) $a['impacto'], 'ind' => (int) $a['ind'], 'real' => (float) $a['qreal']];
}
$quizzes = [];
foreach ($pdo->query('SELECT quiz_id, tipo, dificuldade, recompensa_xp, recompensa_credito FROM quiz WHERE ativa') as $q) {
    $quizzes[(int) $q['quiz_id']] = $q + ['recompensa' => recompensa_quiz($q), 'perguntas' => []];
}
foreach ($pdo->query('SELECT p.quiz_id, p.pergunta_id, a.alternativa_id, a.correta FROM pergunta p JOIN alternativa a USING(pergunta_id) ORDER BY p.pergunta_id, a.alternativa_id') as $r) {
    $q = (int) $r['quiz_id'];
    if (!isset($quizzes[$q])) continue;
    $p = (int) $r['pergunta_id'];
    $quizzes[$q]['perguntas'][$p] ??= ['certa' => 0, 'erradas' => []];
    if ($r['correta']) $quizzes[$q]['perguntas'][$p]['certa'] = (int) $r['alternativa_id'];
    else $quizzes[$q]['perguntas'][$p]['erradas'][] = (int) $r['alternativa_id'];
}
// Dificuldade de cada pergunta (fixa) e a "pegadinha" (a errada mais escolhida)
foreach ($quizzes as &$q) foreach ($q['perguntas'] as &$pp) { $pp['dif'] = 0.05 + $rnd() * 0.45; $pp['pegadinha'] = $escolhe($pp['erradas']); }
unset($q, $pp);
$porTipo = [];
foreach ($pdo->query('SELECT q.quiz_id, q.tipo FROM quiz q WHERE q.ativa AND EXISTS (SELECT 1 FROM pergunta p WHERE p.quiz_id = q.quiz_id) ORDER BY q.quiz_id') as $r) $porTipo[$r['tipo']][] = (int) $r['quiz_id'];
$conquistas = $pdo->query('SELECT conquista_id id, tipo_criterio crit, valor_criterio valor, recompensa_xp xp, recompensa_credito cr FROM conquista WHERE ativa')->fetchAll();
$niveisJog = $pdo->query('SELECT nivel_jogador_id id, nivel, xp_minimo FROM nivel_jogador ORDER BY xp_minimo')->fetchAll();
$niveisCid = $pdo->query('SELECT nivel_cidade_id id, nivel, pontuacao_minima pm FROM nivel_cidade ORDER BY pontuacao_minima')->fetchAll();
$missoes = [];
foreach ($pdo->query("SELECT m.missao_id, m.recompensa_xp xp, m.recompensa_credito cr, ma.atividade_sustentavel_id a, ma.quantidade_necessaria n
                        FROM missao m JOIN missao_atividade ma USING(missao_id) WHERE m.ativa AND m.tipo_missao_id = 1") as $r) {
    $missoes[(int) $r['missao_id']] ??= ['xp' => (int) $r['xp'], 'cr' => (int) $r['cr'], 'metas' => []];
    $missoes[(int) $r['missao_id']]['metas'][(int) $r['a']] = (int) $r['n'];
}
$missoesCom = $pdo->query('SELECT comunidade_missao_id id, atividade_sustentavel_id a, meta, recompensa_xp xp, recompensa_credito cr, pontos FROM comunidade_missao WHERE comunidade_id IS NULL AND ativa')->fetchAll();
$estruturas = $pdo->query("SELECT construcao_id id, largura w, profundidade d, custo, nivel_cidade_minimo nivel, categoria FROM construcao
                            WHERE ativa AND largura <= 4 AND profundidade <= 4 AND categoria IN ('ecologica', 'decoracao', 'servicos', 'comercio') ORDER BY custo")->fetchAll();
$adminId = (int) $pdo->query('SELECT administrador_id FROM administrador WHERE ativo ORDER BY administrador_id LIMIT 1')->fetchColumn() ?: null;
$nivelPorXp = static function (int $xp) use ($niveisJog): array { $n = $niveisJog[0]; foreach ($niveisJog as $x) if ($xp >= (int) $x['xp_minimo']) $n = $x; return $n; };
$nivelCidade = static function (array $ind) use ($niveisCid): array { $m = (int) round(array_sum($ind) / count($ind)); $n = $niveisCid[0]; foreach ($niveisCid as $x) if ($m >= (int) $x['pm']) $n = $x; return $n; };
// Vagas do lote 2 sem as células candidatas a lixo (blocos 4×4)
$lixo = array_map(static fn($c) => $c['x'] . ',' . $c['y'], celulas_lixo_candidatas(2));
$vagas = [];
for ($x = 0; $x <= 16; $x += 4) for ($y = 0; $y <= 16; $y += 4) {
    $livre = true;
    for ($i = 0; $i < 4 && $livre; $i++) for ($k = 0; $k < 4; $k++) if (in_array(($x + $i) . ',' . ($y + $k), $lixo, true)) { $livre = false; break; }
    if ($livre) $vagas[] = [$x, $y];
}

/* ---------------------------------------------------------------- perfis */
$nomes = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elisa', 'Fábio', 'Gabi', 'Hugo', 'Iara', 'João', 'Lara', 'Marcos', 'Nina', 'Otávio', 'Paula', 'Rafael', 'Sofia', 'Tiago', 'Úrsula', 'Vitor', 'Yasmin', 'Caio', 'Bia', 'Davi', 'Luna', 'Mateus', 'Clara', 'Enzo', 'Helena', 'Igor', 'Júlia', 'Leo', 'Maya', 'Noah', 'Olívia', 'Pedro', 'Rita', 'Samuel', 'Tânia', 'Vera'];
$sobrenomes = ['Verde', 'Folha', 'Rio', 'Sol', 'Mata', 'Brisa', 'Semente', 'Lago', 'Flor', 'Raiz', 'Pedal', 'Horta', 'Ipê', 'Jatobá', 'Arara', 'Garça'];
$cidades = ['Vila Esperança', 'Nova Aurora', 'Recanto Verde', 'Porto Limpo', 'Serra Azul', 'Campo Florido', 'Vale do Ipê', 'Bela Vista', 'Monte Claro', 'Lagoa Serena', 'Jardim do Sol', 'Bosque Alto'];
$apresentacao = [   // nome, apelido, cidade, perfil
    ['Ana Verde', 'AnaVerde', 'Ecovila Ana', ['inicio' => 0, 'eng' => 0.95, 'tau' => 9999, 'quiz' => 0.55, 'foto' => 0.35, 'constroi' => 0.9]],
    ['Bruno Pedal', 'BrunoPedal', 'Cidade das Bicicletas', ['inicio' => 2, 'eng' => 0.85, 'tau' => 9999, 'quiz' => 0.35, 'foto' => 0.3, 'pref' => 4]],
    ['Carla Iniciante', 'CarlaNova', 'Começo Verde', ['inicio' => -2, 'eng' => 0.9, 'tau' => 9999, 'quiz' => 0.6, 'foto' => 0.2]],
    ['Diego Modelo', 'DiegoModelo', 'Cidade Modelo', ['inicio' => 0, 'eng' => 1.0, 'tau' => 9999, 'quiz' => 0.3, 'foto' => 0.25, 'todas' => true, 'constroi' => 0.8]],
    ['Elisa Quiz', 'ElisaQuiz', 'Vila do Saber', ['inicio' => 3, 'eng' => 0.7, 'tau' => 9999, 'quiz' => 0.95, 'foto' => 0.2, 'habil' => 0.97]],
    ['Fábio Construtor', 'FabioObras', 'Bairro Novo', ['inicio' => 1, 'eng' => 0.85, 'tau' => 9999, 'quiz' => 0.3, 'foto' => 0.3, 'constroi' => 1.0]],
    ['Gabi Comunidade', 'GabiJunto', 'Vila Unida', ['inicio' => 5, 'eng' => 0.8, 'tau' => 9999, 'quiz' => 0.4, 'foto' => 0.4]],
    ['Hugo Ausente', 'HugoSumiu', 'Cidade Parada', ['inicio' => 0, 'eng' => 0.8, 'tau' => 9999, 'quiz' => 0.3, 'foto' => 0.2, 'para' => 21]],
    ['Iara Foto', 'IaraFoto', 'Lente Verde', ['inicio' => 4, 'eng' => 0.8, 'tau' => 9999, 'quiz' => 0.3, 'foto' => 0.95]],
    ['João Equilíbrio', 'JoaoMeio', 'Meio do Caminho', ['inicio' => 7, 'eng' => 0.6, 'tau' => 60, 'quiz' => 0.4, 'foto' => 0.3]],
];
$dias = (int) ((new DateTimeImmutable(INICIO))->diff(new DateTimeImmutable($hoje))->days);
$jogadores = [];
$codigos = [];
$alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
for ($n = 1; $n <= JOGADORES; $n++) {
    do {
        $cod = 'ECO-';
        for ($i = 0; $i < 9; $i++) $cod .= $i === 4 ? '-' : $alfabeto[mt_rand(0, 31)];
    } while (isset($codigos[$cod]));
    $codigos[$cod] = true;
    if ($n <= APRESENTACAO) {
        [$nome, $apelido, $cidade, $perf] = $apresentacao[$n - 1];
        $inicio = $perf['inicio'] < 0 ? $dias + $perf['inicio'] : $perf['inicio'];
        $email = sprintf('apresentacao%02d@demo.ecocity.test', $n);
    } else {
        $nome = $escolhe($nomes) . ' ' . $escolhe($sobrenomes);
        $apelido = str_replace(['á', 'é', 'í', 'ó', 'ú', 'â', 'ê', 'ô', 'ã', 'Ú', 'É'], ['a', 'e', 'i', 'o', 'u', 'a', 'e', 'o', 'a', 'U', 'E'], explode(' ', $nome)[0]) . mt_rand(10, 99);
        $cidade = $escolhe($cidades);
        // Mais cadastros no começo, alguns recentes
        $inicio = (int) floor(($rnd() ** 1.6) * ($dias - 1));
        $perf = ['eng' => 0.15 + $rnd() * 0.8, 'tau' => $rnd() < 0.25 ? 9999 : 6 + $rnd() * 40, 'quiz' => 0.1 + $rnd() * 0.5, 'foto' => 0.1 + $rnd() * 0.4];
        $email = sprintf('demo%03d@demo.ecocity.test', $n);
    }
    $pref = [];
    foreach ($atividades as $id => $a) $pref[$id] = 0.25 + $rnd() * 0.75;
    if (isset($perf['pref'])) $pref[$perf['pref']] = 1.0;
    $jogadores[$n] = ['n' => $n, 'nome' => $nome, 'apelido' => $apelido, 'cidade' => $cidade, 'email' => $email, 'codigo' => $cod,
        'inicio' => $inicio, 'perf' => $perf + ['habil' => 0.55 + $rnd() * 0.4, 'constroi' => $rnd() * 0.6, 'todas' => false, 'para' => null], 'pref' => $pref];
}

/* ---------------------------------------------------------------- comunidades (líderes e membros definidos antes) */
$comunidadesDef = [
    ['nome' => 'Guardiões do Ipê', 'icone' => 'nature', 'lider' => 1, 'descricao' => 'Plantio e cuidado das áreas verdes do bairro.'],
    ['nome' => 'Pedal Coletivo', 'icone' => 'mobility', 'lider' => 2, 'descricao' => 'Quem troca o carro pela bicicleta.'],
    ['nome' => 'Recicla Junto', 'icone' => 'recycling', 'lider' => 4, 'descricao' => 'Separação de resíduos e mutirões.'],
    ['nome' => 'Água Viva', 'icone' => 'water', 'lider' => 6, 'descricao' => 'Banhos curtos e reúso de água.'],
];
$membroDe = [];   // n do jogador → índice da comunidade
foreach ($comunidadesDef as $i => $c) $membroDe[$c['lider']] = $i;
$membroDe[7] = 0;   // Gabi na comunidade da Ana
foreach (range(APRESENTACAO + 1, JOGADORES) as $n) if ($rnd() < 0.45) $membroDe[$n] = mt_rand(0, 3);

/* ---------------------------------------------------------------- simulação */
$saida = [
    'usuario' => [], 'jogador' => [], 'cidade' => [], 'cidade_indicador' => [], 'acesso_diario' => [], 'registro_atividade' => [],
    'evidencia_atividade' => [], 'historico_xp' => [], 'historico_credito_verde' => [], 'historico_indicador_cidade' => [],
    'tentativa_quiz' => [], 'resposta_quiz' => [], 'jogador_conquista' => [], 'jogador_missao' => [], 'cidade_construcao' => [],
    'amizade' => [], 'mensagem' => [], 'comunidade' => [], 'comunidade_membro' => [], 'comunidade_contribuicao' => [],
    'comunidade_mensagem' => [], 'comunidade_topico' => [], 'comunidade_missao_resultado' => [],
];
$senhaDescartada = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$senhasApresentacao = [];
$nr = 0; $nt = 0;   // contadores de registros e tentativas (ids = base + contador)
$comCriacao = [];   // índice da comunidade → data e hora de criação
$contribuicoes = []; // índice da comunidade → lista [n, atividade, impacto, data, registro]
$motivos = ['A foto não mostra a ação descrita.', 'Foto repetida de outro registro.', 'Imagem muito escura, não dá para conferir.', 'A foto não tem relação com a atividade.'];
$descricoes = ['Fiz hoje cedo.', 'Separei tudo antes da coleta.', 'Consegui reduzir bastante!', 'Com a família toda.', 'Primeira vez fazendo isso.', 'Mais um dia de hábito verde.'];
$horario = static function () use ($rnd): int { $r = $rnd(); return $r < 0.25 ? mt_rand(7, 9) : ($r < 0.45 ? mt_rand(11, 13) : ($r < 0.9 ? mt_rand(18, 22) : mt_rand(14, 17))); };
$dt = static fn(string $dia, int $h, int $m, int $s = 0): string => sprintf('%s %02d:%02d:%02d', $dia, $h, $m, $s);

foreach ($jogadores as $n => &$j) {
    $perf = $j['perf'];
    $diaCad = (new DateTimeImmutable(INICIO))->modify("+{$j['inicio']} days")->format('Y-m-d');
    $j['data_cadastro'] = $diaCad;
    $xp = 0; $cr = 0; $ind = array_fill_keys(array_unique(array_column($atividades, 'ind')), 0);
    $prog = ['aprovadas' => 0, 'cat' => [], 'real' => [], 'evid' => 0, 'quizConc' => [], 'quizPerf' => [], 'constr' => 0, 'eco' => 0];
    $ganhas = []; $missoesFeitas = []; $quizFeitos = []; $construidas = []; $ultimaQueda = $diaCad; $ultimoAcesso = $diaCad;
    $pendentes = [];   // aprovações com foto que acontecem depois
    $histInd = function (string $quando, int $i, string $origem) use (&$saida, &$ind, $n) {
        $saida['historico_indicador_cidade'][] = [$n, $i, $ind[$i], $quando, $origem];
    };
    $credita = function (string $quando, int $dxp, int $dcr, string $origem, array $ref) use (&$saida, &$xp, &$cr, $n) {
        if ($dxp) { $saida['historico_xp'][] = [$n, $dxp, $origem, $quando, $ref]; $xp += $dxp; }
        if ($dcr) { $saida['historico_credito_verde'][] = [$n, $dcr, $origem, $quando, $ref]; $cr += $dcr; }
    };
    // Conquistas atingidas até agora (avaliadas depois de cada evento, como avaliar_conquistas())
    $avaliar = function (string $quando) use (&$ganhas, &$prog, &$xp, &$ind, $conquistas, $credita, $nivelPorXp, $nivelCidade, $n, &$saida) {
        do {
            $nova = false;
            foreach ($conquistas as $c) {
                if (isset($ganhas[$c['id']])) continue;
                [$tipo, $arg] = array_pad(explode(':', $c['crit'], 2), 2, null);
                $v = match ($tipo) {
                    'atividades_aprovadas' => $prog['aprovadas'],
                    'atividades_categoria' => $prog['cat'][(int) $arg] ?? 0,
                    'impacto_real' => $prog['real'][(int) $arg] ?? 0,
                    'indicador' => $ind[(int) $arg] ?? 0,
                    'registros_evidencia' => $prog['evid'],
                    'quizzes_concluidos' => count($prog['quizConc']),
                    'quizzes_perfeitos' => count($prog['quizPerf']),
                    'nivel_cidade' => (int) $nivelCidade($ind)['nivel'],
                    'nivel_jogador' => (int) $nivelPorXp($xp)['nivel'],
                    'construcoes' => $prog['constr'],
                    'construcoes_ecologicas' => $prog['eco'],
                    default => 0,
                };
                if ($v >= (int) $c['valor']) {
                    $ganhas[$c['id']] = $quando;
                    $saida['jogador_conquista'][] = [$n, (int) $c['id'], substr($quando, 0, 10)];
                    $credita($quando, (int) $c['xp'], (int) $c['cr'], 'conquista', ['conquista_id' => (int) $c['id']]);
                    $nova = true;
                }
            }
        } while ($nova);
    };
    // Aprovação de um registro (paga, soma impacto, comunidade, missões e conquistas)
    $aprovar = function (array $r, string $quando) use (&$prog, &$ind, $atividades, $credita, $histInd, $avaliar, &$missoesFeitas, $missoes, &$saida, $n, &$contribuicoes, &$membroDe, &$comCriacao, &$j) {
        $a = $atividades[$r['atividade']];
        $f = $r['tipo'] === 1 ? 0.5 : 1.0;
        $credita($quando, (int) floor($a['xp'] * $f), (int) floor($a['cr'] * $f), 'atividade', ['registro' => $r['k']]);
        $ind[$a['ind']] = min(100, $ind[$a['ind']] + (int) floor($a['impacto'] * $f));
        $histInd($quando, $a['ind'], 'atividade');
        $prog['aprovadas']++;
        $prog['cat'][$a['ind']] = ($prog['cat'][$a['ind']] ?? 0) + 1;
        $prog['real'][$a['ind']] = ($prog['real'][$a['ind']] ?? 0) + $a['real'];
        if ($r['tipo'] === 2) $prog['evid']++;
        // Contribuição para a comunidade em que o jogador está no momento da aprovação (RN66)
        if (isset($membroDe[$n], $j['entrada_comunidade']) && $quando >= $j['entrada_comunidade']) {
            $contribuicoes[$membroDe[$n]][] = [$n, $r['atividade'], $a['real'], $r['data'], $r['k'], $r['tipo'] === 1 ? 5 : 10, $quando];
        }
        // Missões semanais (só a semana atual tem missões)
        if ($r['data'] >= SEMANA_MISSOES) {
            $j['semana'][$r['atividade']] = ($j['semana'][$r['atividade']] ?? 0) + 1;
            foreach ($missoes as $mid => $m) {
                if (isset($missoesFeitas[$mid])) continue;
                $ok = true;
                foreach ($m['metas'] as $at => $qtd) if (($j['semana'][$at] ?? 0) < $qtd) { $ok = false; break; }
                if ($ok) {
                    $missoesFeitas[$mid] = true;
                    $saida['jogador_missao'][] = [$n, $mid, SEMANA_MISSOES, $quando];
                    $credita($quando, $m['xp'], $m['cr'], 'missao', ['missao_id' => $mid]);
                }
            }
        }
        $avaliar($quando);
    };

    for ($d = $j['inicio']; $d <= $dias; $d++) {
        $dia = (new DateTimeImmutable(INICIO))->modify("+$d days")->format('Y-m-d');
        $t = $d - $j['inicio'];
        // Aprovações com foto que vencem até o fim deste dia (administrador ou automática em 24 h)
        foreach ($pendentes as $i => $pp) {
            if (substr($pp['decisao'], 0, 10) <= $dia && $pp['decisao'] <= $agora->format('Y-m-d H:i:s')) {
                if ($pp['resultado'] === 'aprovado') $aprovar($pp, $pp['decisao']);
                unset($pendentes[$i]);
            }
        }
        $ativo = $t === 0 ? true : ($perf['para'] !== null && $t > $perf['para'] ? false : $rnd() < $perf['eng'] * exp(-$t / $perf['tau']));
        if (!$ativo) continue;
        $h0 = $horario(); $m0 = mt_rand(0, 50);
        $primeiro = $dt($dia, $h0, $m0);
        if ($dia === $hoje && $primeiro > $agora->format('Y-m-d H:i:s')) continue;
        $saida['acesso_diario'][] = [$n, $dia];
        // Queda diária pendente desde o último acesso (RN43)
        $diasQueda = (int) (new DateTimeImmutable($ultimaQueda))->diff(new DateTimeImmutable($dia))->days;
        if ($diasQueda > 0) {
            foreach ($ind as $i => $v) { $ind[$i] = max(0, $v - $diasQueda); $histInd($primeiro, $i, 'queda diaria'); }
            $ultimaQueda = $dia;
        }
        $ultimoAcesso = $dia;
        // Entrada na comunidade (no primeiro acesso depois da criação)
        if (isset($membroDe[$n]) && !isset($j['entrada_comunidade']) && isset($comCriacao[$membroDe[$n]]) && $primeiro > $comCriacao[$membroDe[$n]]) {
            $j['entrada_comunidade'] = $primeiro;
        }
        // Ações do dia (cada atividade no máximo 1 vez por dia — RN44)
        $min = $m0;
        foreach ($atividades as $aid => $a) {
            $p = $perf['todas'] ? 0.97 : $j['pref'][$aid] * (0.35 + $perf['eng'] * 0.45);
            if ($rnd() >= $p) continue;
            $min += mt_rand(2, 25);
            $hora = $dt($dia, min(23, $h0 + intdiv($min, 60)), $min % 60, mt_rand(0, 59));
            if ($hora > $agora->format('Y-m-d H:i:s')) continue;
            $tipo = $rnd() < $perf['foto'] ? 2 : 1;
            $k = ++$nr;
            $r = ['k' => $k, 'atividade' => $aid, 'tipo' => $tipo, 'data' => $hora];
            if ($tipo === 1) {
                $saida['registro_atividade'][] = [$k, $n, $aid, 1, $hora, 'aprovado', null, null, $hora];
                $aprovar($r, $hora);
            } else {
                $limite = (new DateTimeImmutable($hora))->modify('+24 hours')->format('Y-m-d H:i:s');
                $x = $rnd();
                if ($limite > $agora->format('Y-m-d H:i:s') && $x < 0.6) {
                    $saida['registro_atividade'][] = [$k, $n, $aid, 2, $hora, 'pendente', null, null, null];   // ainda na fila
                } elseif ($x < 0.85 || $adminId === null) {
                    $dec = (new DateTimeImmutable($hora))->modify('+' . mt_rand(40, 1200) . ' minutes')->format('Y-m-d H:i:s');
                    if ($dec > $agora->format('Y-m-d H:i:s')) $dec = $agora->modify('-5 minutes')->format('Y-m-d H:i:s');
                    $saida['registro_atividade'][] = [$k, $n, $aid, 2, $hora, 'aprovado', null, '@adm', $dec];
                    $pendentes[] = $r + ['decisao' => $dec, 'resultado' => 'aprovado'];
                } elseif ($x < 0.93 || $limite > $agora->format('Y-m-d H:i:s')) {
                    $dec = (new DateTimeImmutable($hora))->modify('+' . mt_rand(60, 900) . ' minutes')->format('Y-m-d H:i:s');
                    if ($dec > $agora->format('Y-m-d H:i:s')) $dec = $agora->modify('-5 minutes')->format('Y-m-d H:i:s');
                    $saida['registro_atividade'][] = [$k, $n, $aid, 2, $hora, 'rejeitado', $escolhe($motivos), '@adm', $dec];
                } else {   // ninguém decidiu: aprovação automática em 24 h
                    $saida['registro_atividade'][] = [$k, $n, $aid, 2, $hora, 'aprovado', null, null, $limite];
                    $pendentes[] = $r + ['decisao' => $limite, 'resultado' => 'aprovado'];
                }
                $saida['evidencia_atividade'][] = [$k, substr($hora, 0, 10), $escolhe($descricoes)];
            }
        }
        // Quiz do dia, da semana ou temático da rotação deste dia (RN53)
        if ($rnd() < $perf['quiz']) {
            $rot = rotacao_quizzes($porTipo, new DateTimeImmutable($dia));
            $opcoes = array_values(array_filter(array_merge([$rot['dia'] ?? null, $rot['semana'] ?? null], $rot['temas'] ?? []), static fn($q) => $q && isset($quizzes[$q])));
            if ($opcoes) {
                $qid = $escolhe($opcoes);
                $treino = isset($quizFeitos[$qid]);
                $q = $quizzes[$qid];
                $hq = $dt($dia, min(23, $h0 + 1), mt_rand(0, 59), mt_rand(0, 59));
                if ($hq <= $agora->format('Y-m-d H:i:s')) {
                    $k = ++$nt;
                    $abandona = !$treino && $rnd() < 0.06;
                    $acertos = 0; $resps = [];
                    foreach ($q['perguntas'] as $pid => $pp) {
                        $chance = ($perf['habil'] + ($treino ? 0.15 : 0)) - $pp['dif'];
                        $certa = $rnd() < $chance;
                        $alt = $certa ? $pp['certa'] : ($rnd() < 0.65 ? $pp['pegadinha'] : $escolhe($pp['erradas']));
                        $resps[] = [$k, $pid, $alt, (int) $certa];
                        $acertos += (int) $certa;
                        if ($abandona && count($resps) >= mt_rand(1, 2)) break;
                    }
                    foreach ($resps as $rr) $saida['resposta_quiz'][] = $rr;
                    if ($abandona) {
                        $saida['tentativa_quiz'][] = [$k, $n, $qid, $dia, null, null];
                    } else {
                        $saida['tentativa_quiz'][] = [$k, $n, $qid, $dia, $acertos, $acertos * PONTOS_POR_ACERTO];
                        $prog['quizConc'][$qid] = true;
                        if ($acertos === count($q['perguntas'])) $prog['quizPerf'][$qid] = true;
                        if (!$treino && $acertos / count($q['perguntas']) * 100 >= QUIZ_NOTA_MINIMA) {
                            $credita($hq, $q['recompensa']['xp'], $q['recompensa']['credito'], 'quiz', ['tentativa' => $k]);
                        }
                        $quizFeitos[$qid] = true;
                        $avaliar($hq);
                    }
                }
            }
        }
        // Criação da comunidade pelo líder (nível 5 e 500 créditos — RN62)
        foreach ($comunidadesDef as $ci => $cdef) {
            if ($cdef['lider'] === $n && !isset($comCriacao[$ci]) && $cr >= 500 && (int) $nivelPorXp($xp)['nivel'] >= 5) {
                $hc = $dt($dia, min(23, $h0 + 2), mt_rand(0, 59));
                if ($hc > $agora->format('Y-m-d H:i:s')) break;
                $comCriacao[$ci] = $hc;
                $credita($hc, 0, -500, 'comunidade', ['comunidade' => $ci]);
                $j['entrada_comunidade'] = $hc;
            }
        }
        // Construções no lote 2 quando a cidade chega ao nível 2 (RN37–RN39)
        $nc = (int) $nivelCidade($ind)['nivel'];
        if ($nc >= 2 && count($construidas) < count($vagas) && $rnd() < $perf['constroi'] * 0.35) {
            $possiveis = array_values(array_filter($estruturas, static fn($e) => (int) $e['nivel'] <= $nc && (int) $e['custo'] <= $cr - 100));
            if ($possiveis) {
                $e = $escolhe($possiveis);
                [$vx, $vy] = $vagas[count($construidas)];
                $hb = $dt($dia, min(23, $h0 + 2), mt_rand(0, 59), mt_rand(0, 59));
                if ($hb <= $agora->format('Y-m-d H:i:s')) {
                    $construidas[] = true;
                    $saida['cidade_construcao'][] = [$n, (int) $e['id'], 2, $vx, $vy, mt_rand(0, 3), $hb];
                    $credita($hb, 0, -(int) $e['custo'], 'construcao', ['construcao_id' => (int) $e['id']]);
                    $prog['constr']++;
                    if ($e['categoria'] === 'ecologica') $prog['eco']++;
                    $avaliar($hb);
                }
            }
        }
    }
    // Aprovações com foto que ficaram para depois do último acesso (o administrador decide mesmo sem o jogador entrar)
    foreach ($pendentes as $pp) if ($pp['resultado'] === 'aprovado') $aprovar($pp, $pp['decisao']);

    $j['xp'] = $xp; $j['cr'] = $cr; $j['ind'] = $ind; $j['ultima_queda'] = $ultimaQueda; $j['ultimo_acesso'] = $ultimoAcesso;
    if ($n <= APRESENTACAO) $senhasApresentacao[$n] = bin2hex(random_bytes(5));
}
unset($j);

/* ---------------------------------------------------------------- comunidades: membros, contribuições, missões da semana */
foreach ($comunidadesDef as $ci => $c) {
    if (!isset($comCriacao[$ci])) continue;
    $saida['comunidade'][] = [$ci, $c['nome'], $c['descricao'], $c['icone'], $comCriacao[$ci]];
    foreach ($jogadores as $n => $j) {
        if (($membroDe[$n] ?? null) === $ci && isset($j['entrada_comunidade'])) {
            $saida['comunidade_membro'][] = [$n, $ci, $n === $c['lider'] ? 'lider' : 'membro', $j['entrada_comunidade']];
        }
    }
    foreach ($contribuicoes[$ci] ?? [] as [$n, $at, $real, $data, $k, $pontos]) $saida['comunidade_contribuicao'][] = [$k, $ci, $n, $at, $real, $pontos, $data];
    // Missões oficiais desta semana concluídas pela soma do impacto (RN67): pontos + recompensa a quem contribuiu
    foreach ($missoesCom as $m) {
        $soma = 0; $quem = []; $quando = null;
        $lista = array_values(array_filter($contribuicoes[$ci] ?? [], static fn($x) => $x[1] === (int) $m['a'] && $x[3] >= SEMANA_MISSOES));
        usort($lista, static fn($a, $b) => strcmp($a[6], $b[6]));
        foreach ($lista as $x) { $soma += $x[2]; $quem[$x[0]] = true; if ($soma >= (float) $m['meta'] && $quando === null) $quando = $x[6]; }
        if ($quando !== null) {
            $saida['comunidade_missao_resultado'][] = [(int) $m['id'], $ci, SEMANA_MISSOES, (int) $m['pontos'], $quando];
            foreach (array_keys($quem) as $n) {
                $saida['historico_xp'][] = [$n, (int) $m['xp'], 'missao comunidade', $quando, ['comunidade_missao_id' => (int) $m['id']]];
                $saida['historico_credito_verde'][] = [$n, (int) $m['cr'], 'missao comunidade', $quando, ['comunidade_missao_id' => (int) $m['id']]];
                $jogadores[$n]['xp'] += (int) $m['xp']; $jogadores[$n]['cr'] += (int) $m['cr'];
            }
        }
    }
    $frases = ['Bom dia, pessoal! Quem vai fazer a missão hoje?', 'Fiz minha parte ontem 💚', 'Alguém topa um mutirão no sábado?', 'Bora bater a meta da semana!', 'Consegui reduzir meu banho para 5 minutos.', 'Plantei duas mudas no quintal.', 'Hoje fui de bicicleta para a escola.'];
    $membros = array_values(array_filter($saida['comunidade_membro'], static fn($m) => $m[1] === $ci));
    for ($i = 0; $i < min(25, count($membros) * 2); $i++) {
        $m = $escolhe($membros);
        $quando = (new DateTimeImmutable($m[3]))->modify('+' . mt_rand(1, 20) . ' hours')->format('Y-m-d H:i:s');
        if ($quando < $agora->format('Y-m-d H:i:s')) $saida['comunidade_mensagem'][] = [$ci, $m[0], $escolhe($frases), $quando];
    }
    $evento = $agora->modify('+' . mt_rand(2, 9) . ' days')->setTime(9, 0)->format('Y-m-d H:i:s');
    $saida['comunidade_topico'][] = [$ci, $c['lider'], 'Mutirão de ' . ['plantio', 'pedal', 'reciclagem', 'economia de água'][$ci], 'Encontro aberto para todos os membros. Traga um amigo!', $evento, 'Praça central', 1, $comCriacao[$ci]];
}

/* ---------------------------------------------------------------- amizades e mensagens */
$pares = [];
$falas = ['Oi! Vi sua cidade, está linda!', 'Bora fazer a missão da semana juntos?', 'Como você chegou no nível 4?', 'Valeu pela dica do banho curto!', 'Amanhã vou de bicicleta também.', 'Parabéns pela conquista!', 'Qual quiz você fez hoje?', 'Plantei uma muda hoje 🌱'];
for ($i = 0; $i < 260; $i++) {
    $a = mt_rand(1, JOGADORES); $b = mt_rand(1, JOGADORES);
    if ($a === $b) continue;
    $par = min($a, $b) . '-' . max($a, $b);
    if (isset($pares[$par])) continue;
    $pares[$par] = true;
    $ini = max($jogadores[$a]['data_cadastro'], $jogadores[$b]['data_cadastro']);
    $d = (new DateTimeImmutable($ini))->modify('+' . mt_rand(0, 10) . ' days')->setTime(mt_rand(8, 22), mt_rand(0, 59));
    if ($d > $agora) $d = $agora->modify('-2 hours');
    $aceita = $rnd() < 0.85;
    $resp = $aceita ? $d->modify('+' . mt_rand(5, 600) . ' minutes') : null;
    if ($resp && $resp > $agora) $resp = $agora->modify('-1 hour');
    $saida['amizade'][] = [$a, $b, $aceita ? 'aceita' : 'pendente', $d->format('Y-m-d H:i:s'), $resp?->format('Y-m-d H:i:s')];
    if ($aceita && $rnd() < 0.5) {
        $t = $resp;
        for ($k = 0, $q = mt_rand(1, 6); $k < $q; $k++) {
            $t = $t->modify('+' . mt_rand(2, 900) . ' minutes');
            if ($t > $agora) break;
            [$de, $para] = $k % 2 ? [$b, $a] : [$a, $b];
            $lida = $t < $agora->modify('-3 hours') ? $t->modify('+' . mt_rand(1, 120) . ' minutes')->format('Y-m-d H:i:s') : null;
            $saida['mensagem'][] = [$de, $para, $escolhe($falas), $t->format('Y-m-d H:i:s'), $lida];
        }
    }
}

/* ---------------------------------------------------------------- SQL */
$q = static fn($v): string => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
$out = [];
$out[] = "-- =============================================================================
-- EcoCity — 06. Dados de teste (INSERT)
-- Parte 1: conteúdo oficial do jogo (INSERT IGNORE: não duplica num banco que já tem).
-- Parte 2: dados de DEMONSTRAÇÃO gerados por database/projeto/gerar-dados-teste.php (semente " . SEMENTE . ",
--          " . JOGADORES . " jogadores, " . ($dias + 1) . " dias até {$hoje}). Fictícios e marcados: usuario.demonstracao = TRUE,
--          e-mails @demo.ecocity.test. Apagar: CALL sp_apagar_demonstracao(TRUE) (mantém as 10 de apresentação).
-- Os ids da demonstração são somados ao maior id existente (@base_*), então o arquivo carrega num banco
-- que já tem jogadores reais sem conflito. Triggers de auditoria e regras ficam em manutenção durante a carga.
-- =============================================================================
USE ecocity;
SET NAMES utf8mb4;
SET @ecocity_manutencao = 1;
SET @adm = (SELECT MIN(administrador_id) FROM administrador WHERE ativo);   -- quem aprovou os registros com foto (NULL num banco novo)
";
// Parte 1: conteúdo oficial
$out[] = "-- ---------------------------------------------------------------- Parte 1: conteúdo oficial";
foreach (['categoria_ambiental', 'indicador_ambiental', 'nivel_cidade', 'nivel_jogador', 'regiao_cidade', 'tipo_verificacao', 'tipo_missao',
          'atividade_sustentavel', 'atividade_indicador_ambiental', 'quiz', 'pergunta', 'alternativa', 'missao', 'missao_atividade', 'conquista', 'construcao'] as $t) {
    $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND extra NOT LIKE '%GENERATED%' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    $linhas = $pdo->query("SELECT `" . implode('`,`', $cols) . "` FROM `$t`", PDO::FETCH_NUM)->fetchAll();
    $out[] = "INSERT IGNORE INTO `$t` (`" . implode('`, `', $cols) . "`) VALUES\n" . implode(",\n", array_map(static fn($l) => '(' . implode(', ', array_map($q, $l)) . ')', $linhas)) . ';';
}
$out[] = "INSERT IGNORE INTO comunidade_missao (comunidade_missao_id, comunidade_id, nome, descricao, atividade_sustentavel_id, meta, recompensa_xp, recompensa_credito, pontos, data_inicio, data_fim, ativa, criada_por) VALUES\n"
    . implode(",\n", array_map(static fn($l) => '(' . implode(', ', array_map($q, $l)) . ')', $pdo->query('SELECT comunidade_missao_id, comunidade_id, nome, descricao, atividade_sustentavel_id, meta, recompensa_xp, recompensa_credito, pontos, data_inicio, data_fim, ativa, criada_por FROM comunidade_missao WHERE comunidade_id IS NULL', PDO::FETCH_NUM)->fetchAll())) . ';';

// Parte 2: demonstração
$out[] = "\n-- ---------------------------------------------------------------- Parte 2: demonstração";
$out[] = "SET @bu = (SELECT COALESCE(MAX(usuario_id), 0) FROM usuario), @bj = (SELECT COALESCE(MAX(jogador_id), 0) FROM jogador),
    @bc = (SELECT COALESCE(MAX(cidade_id), 0) FROM cidade), @br = (SELECT COALESCE(MAX(registro_atividade_id), 0) FROM registro_atividade),
    @bt = (SELECT COALESCE(MAX(tentativa_id), 0) FROM tentativa_quiz), @bm = (SELECT COALESCE(MAX(comunidade_id), 0) FROM comunidade),
    @nivel_cidade_1 = (SELECT nivel_cidade_id FROM nivel_cidade ORDER BY pontuacao_minima LIMIT 1);";
$lote = static function (string $tabela, string $colunas, array $linhas) use (&$out): void {
    foreach (array_chunk($linhas, 400) as $c) $out[] = "INSERT INTO $tabela ($colunas) VALUES\n" . implode(",\n", $c) . ';';
};
$J = static fn(int $n): string => "@bj + $n";
$U = static fn(int $n): string => "@bu + $n";
$C = static fn(int $n): string => "@bc + $n";
$R = static fn(int $k): string => "@br + $k";
$T = static fn(int $k): string => "@bt + $k";
$M = static fn(int $i): string => '@bm + ' . ($i + 1);

$lote('usuario', 'usuario_id, nome, email, senha, data_cadastro, status, demonstracao', array_map(static fn($j) =>
    '(' . $U($j['n']) . ", {$q($j['nome'])}, {$q($j['email'])}, " . $q(isset($senhasApresentacao[$j['n']]) ? password_hash($senhasApresentacao[$j['n']], PASSWORD_DEFAULT) : $senhaDescartada) . ", {$q($j['data_cadastro'])}, TRUE, TRUE)", $jogadores));
$lote('jogador', 'jogador_id, apelido, numero_conta, data_criacao, nivel_id, creditos_verde, xp_atual, usuario_id', array_map(static fn($j) =>
    '(' . $J($j['n']) . ", {$q($j['apelido'])}, {$q($j['codigo'])}, {$q($j['data_cadastro'])}, " . (int) $nivelPorXp($j['xp'])['id'] . ", {$j['cr']}, {$j['xp']}, " . $U($j['n']) . ')', $jogadores));
$lote('cidade', 'cidade_id, nome, nivel_cidade_id, jogador_id, ultima_queda', array_map(static fn($j) =>
    '(' . $C($j['n']) . ", {$q($j['cidade'])}, " . (int) $nivelCidade($j['ind'])['id'] . ', ' . $J($j['n']) . ", {$q($j['ultima_queda'])})", $jogadores));
$ci = [];
foreach ($jogadores as $j) foreach ($j['ind'] as $i => $v) $ci[] = '(' . $C($j['n']) . ", $i, $v)";
$lote('cidade_indicador', 'cidade_id, indicador_ambiental_id, valor', $ci);
$lote('acesso_diario', 'jogador_id, data', array_map(static fn($a) => '(' . $J($a[0]) . ", {$q($a[1])})", $saida['acesso_diario']));
$lote('registro_atividade', 'registro_atividade_id, jogador_id, atividade_sustentavel_id, tipo_verificacao_id, data_registro, status, motivo_rejeicao, aprovado_por_administrador_id, data_aprovacao',
    array_map(static fn($r) => '(' . $R($r[0]) . ', ' . $J($r[1]) . ", {$r[2]}, {$r[3]}, {$q($r[4])}, {$q($r[5])}, {$q($r[6])}, " . ($r[7] === '@adm' ? '@adm' : $q($r[7])) . ", {$q($r[8])})", $saida['registro_atividade']));
$lote('evidencia_atividade', 'registro_atividade_id, arquivo, data_envio, descricao', array_map(static fn($e) => '(' . $R($e[0]) . ", NULL, {$q($e[1])}, {$q($e[2])})", $saida['evidencia_atividade']));
$lote('tentativa_quiz', 'tentativa_id, jogador_id, quiz_id, data, acertos, pontuacao', array_map(static fn($t) => '(' . $T($t[0]) . ', ' . $J($t[1]) . ", {$t[2]}, {$q($t[3])}, {$q($t[4])}, {$q($t[5])})", $saida['tentativa_quiz']));
$lote('resposta_quiz', 'tentativa_id, pergunta_id, alternativa_id, correta', array_map(static fn($r) => '(' . $T($r[0]) . ", {$r[1]}, {$r[2]}, {$r[3]})", $saida['resposta_quiz']));
$refs = static function (array $ref) use ($R, $T, $M): array {
    return [
        'registro_atividade_id' => isset($ref['registro']) ? $R($ref['registro']) : 'NULL',
        'tentativa_id' => isset($ref['tentativa']) ? $T($ref['tentativa']) : 'NULL',
        'conquista_id' => (string) ($ref['conquista_id'] ?? 'NULL'),
        'missao_id' => (string) ($ref['missao_id'] ?? 'NULL'),
        'comunidade_missao_id' => (string) ($ref['comunidade_missao_id'] ?? 'NULL'),
        'construcao_id' => (string) ($ref['construcao_id'] ?? 'NULL'),
        'comunidade_id' => isset($ref['comunidade']) ? $M($ref['comunidade']) : 'NULL',
    ];
};
$lote('historico_xp', 'jogador_id, quantidade, origem, data, registro_atividade_id, tentativa_id, conquista_id, missao_id, comunidade_missao_id',
    array_map(static function ($h) use ($J, $q, $refs) { $r = $refs($h[4]); return '(' . $J($h[0]) . ", {$h[1]}, {$q($h[2])}, {$q($h[3])}, {$r['registro_atividade_id']}, {$r['tentativa_id']}, {$r['conquista_id']}, {$r['missao_id']}, {$r['comunidade_missao_id']})"; }, $saida['historico_xp']));
// Comunidades antes dos históricos de crédito (o custo de criação referencia a comunidade)
$lote('comunidade', 'comunidade_id, nome, descricao, icone, entrada, data_criacao, ativa', array_map(static fn($c) => '(' . $M($c[0]) . ", {$q($c[1])}, {$q($c[2])}, {$q($c[3])}, 'aberta', {$q($c[4])}, TRUE)", $saida['comunidade']));
$lote('historico_credito_verde', 'jogador_id, quantidade, origem, data, registro_atividade_id, tentativa_id, conquista_id, missao_id, comunidade_missao_id, construcao_id, comunidade_id',
    array_map(static function ($h) use ($J, $q, $refs) { $r = $refs($h[4]); return '(' . $J($h[0]) . ", {$h[1]}, {$q($h[2])}, {$q($h[3])}, {$r['registro_atividade_id']}, {$r['tentativa_id']}, {$r['conquista_id']}, {$r['missao_id']}, {$r['comunidade_missao_id']}, {$r['construcao_id']}, {$r['comunidade_id']})"; }, $saida['historico_credito_verde']));
$lote('historico_indicador_cidade', 'cidade_id, indicador_ambiental_id, valor, data, origem', array_map(static fn($h) => '(' . $C($h[0]) . ", {$h[1]}, {$h[2]}, {$q($h[3])}, {$q($h[4])})", $saida['historico_indicador_cidade']));
$lote('jogador_conquista', 'jogador_id, conquista_id, data_conquista', array_map(static fn($c) => '(' . $J($c[0]) . ", {$c[1]}, {$q($c[2])})", $saida['jogador_conquista']));
$lote('jogador_missao', 'jogador_id, missao_id, periodo_inicio, status, data_resultado', array_map(static fn($m) => '(' . $J($m[0]) . ", {$m[1]}, {$q($m[2])}, 'concluida', {$q($m[3])})", $saida['jogador_missao']));
$lote('cidade_construcao', 'cidade_id, construcao_id, lote, x, y, rotacao, data_construcao, renda_coletada_em', array_map(static fn($c) => '(' . $C($c[0]) . ", {$c[1]}, {$c[2]}, {$c[3]}, {$c[4]}, {$c[5]}, {$q($c[6])}, {$q($c[6])})", $saida['cidade_construcao']));
$lote('amizade', 'solicitante_id, destinatario_id, status, data_pedido, data_resposta', array_map(static fn($a) => '(' . $J($a[0]) . ', ' . $J($a[1]) . ", {$q($a[2])}, {$q($a[3])}, {$q($a[4])})", $saida['amizade']));
$lote('mensagem', 'remetente_id, destinatario_id, texto, data_envio, data_leitura', array_map(static fn($m) => '(' . $J($m[0]) . ', ' . $J($m[1]) . ", {$q($m[2])}, {$q($m[3])}, {$q($m[4])})", $saida['mensagem']));
$lote('comunidade_membro', 'jogador_id, comunidade_id, papel, data_entrada, ultima_lida_id', array_map(static fn($m) => '(' . $J($m[0]) . ', ' . $M($m[1]) . ", {$q($m[2])}, {$q($m[3])}, 0)", $saida['comunidade_membro']));
$lote('comunidade_contribuicao', 'registro_atividade_id, comunidade_id, jogador_id, atividade_sustentavel_id, impacto_real, pontos, data_registro', array_map(static fn($c) => '(' . $R($c[0]) . ', ' . $M($c[1]) . ', ' . $J($c[2]) . ", {$c[3]}, {$c[4]}, {$c[5]}, {$q($c[6])})", $saida['comunidade_contribuicao']));
$lote('comunidade_missao_resultado', 'comunidade_missao_id, comunidade_id, periodo_inicio, pontos, data', array_map(static fn($r) => "({$r[0]}, " . $M($r[1]) . ", {$q($r[2])}, {$r[3]}, {$q($r[4])})", $saida['comunidade_missao_resultado']));
$lote('comunidade_mensagem', 'comunidade_id, jogador_id, texto, data_envio', array_map(static fn($m) => '(' . $M($m[0]) . ', ' . $J($m[1]) . ", {$q($m[2])}, {$q($m[3])})", $saida['comunidade_mensagem']));
$lote('comunidade_topico', 'comunidade_id, autor_id, titulo, texto, data_evento, local, fixado, removido, data', array_map(static fn($t) => '(' . $M($t[0]) . ', ' . $J($t[1]) . ", {$q($t[2])}, {$q($t[3])}, {$q($t[4])}, {$q($t[5])}, {$t[6]}, FALSE, {$q($t[7])})", $saida['comunidade_topico']));
$out[] = "SET @ecocity_manutencao = 0;";
file_put_contents(__DIR__ . '/06-dados-teste.sql', implode("\n", $out) . "\n");

// Contas de apresentação (senhas) — pasta database/ é bloqueada na web
$md = "# Contas de demonstração para a apresentação\n\n> Geradas em {$hoje} por `database/projeto/gerar-dados-teste.php`. Dados **fictícios** (usuario.demonstracao = TRUE).\n> Somente para apresentação; a pasta `database/` é bloqueada na web. Gerar de novo troca as senhas.\n\n| # | Nome | Apelido | E-mail | Senha | Perfil |\n|---|---|---|---|---|---|\n";
$perfis = ['dedicada, líder de comunidade, constrói', 'líder de comunidade (Mobilidade)', 'iniciante (cadastro há 2 dias)', 'joga todo dia (cidade alta)', 'mestre dos quizzes', 'construtor', 'membro ativo de comunidade', 'parou de jogar há semanas', 'muitas ações com foto (pendentes e rejeitadas)', 'moderado'];
foreach (range(1, APRESENTACAO) as $n) $md .= "| $n | {$jogadores[$n]['nome']} | {$jogadores[$n]['apelido']} | {$jogadores[$n]['email']} | {$senhasApresentacao[$n]} | {$perfis[$n - 1]} |\n";
file_put_contents(__DIR__ . '/../contas-demo.md', $md);

printf("%d jogadores · %d registros · %d tentativas · %d conquistas · %d construções · %d comunidades · %d amizades · %d mensagens · %d KB\n",
    JOGADORES, count($saida['registro_atividade']), count($saida['tentativa_quiz']), count($saida['jogador_conquista']), count($saida['cidade_construcao']),
    count($saida['comunidade']), count($saida['amizade']), count($saida['mensagem']), (int) (filesize(__DIR__ . '/06-dados-teste.sql') / 1024));
