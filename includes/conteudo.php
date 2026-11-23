<?php
/**
 * Gestão de conteúdo pela administração (specs/site.md RN49–RN52).
 *
 *  - Atividades, quizzes (perguntas e alternativas), missões (metas), conquistas e estruturas.
 *  - Conteúdo já usado por jogadores não é apagado: só desativado (some do jogo, histórico intacto).
 *    Exclusão de verdade só para itens nunca usados.
 *  - Quiz já respondido: só textos podem mudar (perguntas, alternativas e a correta ficam fixas).
 *  - Tipo do quiz (diário/semanal/temático) define os limites de perguntas (RN53); vale ao criar ou mudar perguntas.
 *  - Atividade usada não muda de categoria; estrutura construída não muda de tamanho.
 *  - Contas: bloquear/desbloquear (usuario.status) e promover/remover administrador;
 *    ninguém bloqueia nem rebaixa a própria conta.
 */
declare(strict_types=1);

const CONTEUDO_TIPOS = ['atividade', 'quiz', 'missao', 'conquista', 'construcao', 'cmissao', 'promocao'];
/** Critérios de conquista (RN31); true = exige escolher a área (indicador); 'atividade' = exige escolher uma atividade com impacto real. */
const CRITERIOS_CONQUISTA = [
    'atividades_aprovadas' => false, 'atividades_categoria' => true, 'impacto_real' => true, 'impacto_atividade' => 'atividade', 'indicador' => true,
    'registros_evidencia' => false, 'verificacoes_parceiro' => false, 'quizzes_concluidos' => false, 'quizzes_perfeitos' => false,
    'nivel_cidade' => false, 'nivel_jogador' => false, 'construcoes' => false, 'construcoes_ecologicas' => false,
    'missoes_especiais' => false,
];
const RECOMPENSA_MAX = 100000;

/* ---------------------------------------------------------------------------
   Validação
   --------------------------------------------------------------------------- */

function c_texto(array $d, string $campo, string $rotulo, int $min, int $max, bool $obrigatorio = true): ?string
{
    $v = trim((string) ($d[$campo] ?? ''));
    if ($v === '' && !$obrigatorio) return null;
    $n = mb_strlen($v, 'UTF-8');
    if ($n < $min || $n > $max) {
        throw new RegistroInvalido("{$rotulo}: use de {$min} a {$max} caracteres.");
    }
    return $v;
}

function c_inteiro(array $d, string $campo, string $rotulo, int $min, int $max): int
{
    $v = $d[$campo] ?? null;
    if (!is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
        throw new RegistroInvalido("{$rotulo}: informe um número inteiro de {$min} a {$max}.");
    }
    return (int) $v;
}

function c_bool(array $d, string $campo): bool
{
    return filter_var($d[$campo] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function c_data(array $d, string $campo, string $rotulo, bool $obrigatorio = true): ?string
{
    $v = trim((string) ($d[$campo] ?? ''));
    if ($v === '' && !$obrigatorio) return null;
    if ($v === '') throw new RegistroInvalido("{$rotulo}: informe a data.");
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) throw new RegistroInvalido("{$rotulo}: data inválida.");
    return $v;
}

/** Indicador da categoria (relação 1:1, D4). */
function indicador_da_categoria(PDO $pdo, int $categoriaId): int
{
    $s = $pdo->prepare('SELECT indicador_ambiental_id FROM indicador_ambiental WHERE categoria_ambiental_id = ?');
    $s->execute([$categoriaId]);
    $id = $s->fetchColumn();
    if ($id === false) throw new RegistroInvalido('Categoria não encontrada.');
    return (int) $id;
}

function c_existe(PDO $pdo, string $sql, array $params): bool
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return (bool) $s->fetchColumn();
}

/** Conteúdo já usado por algum jogador? */
function conteudo_usado(PDO $pdo, string $tipo, int $id): bool
{
    $sql = [
        'atividade'  => 'SELECT (EXISTS (SELECT 1 FROM registro_atividade WHERE atividade_sustentavel_id = ?)
                                OR EXISTS (SELECT 1 FROM missao_atividade WHERE atividade_sustentavel_id = ?)
                                OR EXISTS (SELECT 1 FROM comunidade_missao WHERE atividade_sustentavel_id = ?))',
        'quiz'       => 'SELECT EXISTS (SELECT 1 FROM tentativa_quiz WHERE quiz_id = ?)',
        'missao'     => 'SELECT (EXISTS (SELECT 1 FROM jogador_missao WHERE missao_id = ?)
                                OR EXISTS (SELECT 1 FROM historico_xp WHERE missao_id = ?))',
        'conquista'  => 'SELECT EXISTS (SELECT 1 FROM jogador_conquista WHERE conquista_id = ?)',
        'construcao' => 'SELECT (EXISTS (SELECT 1 FROM cidade_construcao WHERE construcao_id = ?)
                                OR EXISTS (SELECT 1 FROM historico_credito_verde WHERE construcao_id = ?))',
        'cmissao'    => 'SELECT (EXISTS (SELECT 1 FROM comunidade_missao_resultado WHERE comunidade_missao_id = ?)
                                OR EXISTS (SELECT 1 FROM historico_xp WHERE comunidade_missao_id = ?))',
        'promocao'   => 'SELECT EXISTS (SELECT 1 FROM codigo_promocional_resgate WHERE codigo_promocional_id = ?)',
    ][$tipo];
    return c_existe($pdo, $sql, array_fill(0, substr_count($sql, '?'), $id));
}

/** Converte violação de chave única em mensagem amigável. */
function c_unico(Throwable $ex, string $mensagem): never
{
    if ($ex instanceof PDOException && $ex->getCode() === '23000' && str_contains($ex->getMessage(), 'Duplicate')) {
        throw new RegistroInvalido($mensagem);
    }
    throw $ex;
}

/** Executa $fn numa transação (com rollback em erro). */
function c_transacao(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $regra = erro_regra_banco($ex);   // trigger de regra de negócio (migração 025)
        throw $regra !== null ? new RegistroInvalido($regra) : $ex;
    }
}

/* ---------------------------------------------------------------------------
   Listagem
   --------------------------------------------------------------------------- */

function listar_conteudo(?array $admin = null): array
{
    $pdo = db();
    $secoes = $admin !== null
        ? admin_secoes_conteudo_permitidas($admin)
        : ['atividade', 'quiz', 'missao', 'conquista', 'construcao', 'cmissao', 'promocao', 'comunidade', 'conta', 'auditoria'];
    $tem = static fn(string $s): bool => in_array($s, $secoes, true);
    $usado = static fn(string $tipo, int $id): bool => conteudo_usado($pdo, $tipo, $id);

    $precisaCategorias = $tem('atividade') || $tem('quiz') || $tem('conquista') || $tem('construcao') || $tem('promocao') || $tem('conta');
    $categorias = $precisaCategorias ? array_map(
        static fn($r) => ['id' => (int) $r['categoria_ambiental_id'], 'nome' => $r['nome'], 'indicadorId' => (int) $r['indicador_ambiental_id']],
        $pdo->query('SELECT c.categoria_ambiental_id, c.nome, i.indicador_ambiental_id FROM categoria_ambiental c
                       JOIN indicador_ambiental i ON i.categoria_ambiental_id = c.categoria_ambiental_id ORDER BY c.categoria_ambiental_id')->fetchAll()
    ) : [];

    $precisaAtividades = $tem('atividade') || $tem('missao') || $tem('cmissao') || $tem('conquista');
    $atividades = $precisaAtividades ? array_map(static fn($a) => [
        'id' => (int) $a['atividade_sustentavel_id'], 'nome' => $a['nome'], 'descricao' => $a['descricao'],
        'categoria' => (int) $a['categoria_ambiental_id'], 'xp' => (int) $a['recompensa_xp'], 'credito' => (int) $a['recompensa_credito'],
        'impacto' => (int) $a['impacto'], 'impactoRealQuantidade' => $a['impacto_real_quantidade'] !== null ? (float) $a['impacto_real_quantidade'] : null,
        'impactoRealUnidade' => $a['impacto_real_unidade'], 'ativa' => (bool) $a['ativa'], 'aceitaFoto' => (bool) $a['aceita_foto'],
        'aceitaParceiro' => !array_key_exists('aceita_parceiro', $a) || (bool) $a['aceita_parceiro'],
        'usado' => $usado('atividade', (int) $a['atividade_sustentavel_id']),
    ], $pdo->query('SELECT a.*, COALESCE(ai.impacto, 0) AS impacto FROM atividade_sustentavel a
                 LEFT JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
                  ORDER BY a.atividade_sustentavel_id')->fetchAll()) : [];

    $quizzes = [];
    if ($tem('quiz')) {
        $alternativas = [];
        foreach ($pdo->query('SELECT * FROM alternativa ORDER BY alternativa_id') as $a) {
            $alternativas[(int) $a['pergunta_id']][] = ['id' => (int) $a['alternativa_id'], 'texto' => $a['texto'], 'correta' => (bool) $a['correta'], 'explicacao' => $a['explicacao']];
        }
        $perguntas = [];
        foreach ($pdo->query('SELECT * FROM pergunta ORDER BY pergunta_id') as $p) {
            $perguntas[(int) $p['quiz_id']][] = ['id' => (int) $p['pergunta_id'], 'enunciado' => $p['enunciado'], 'alternativas' => $alternativas[(int) $p['pergunta_id']] ?? [],
                'fonteTitulo' => $p['fonte_titulo'] ?? null, 'fonteUrl' => $p['fonte_url'] ?? null];
        }
        $quizzes = array_map(static fn($q) => [
            'id' => (int) $q['quiz_id'], 'titulo' => $q['titulo'], 'descricao' => $q['descricao'], 'categoria' => (int) $q['categoria_ambiental_id'],
            'dificuldade' => $q['dificuldade'], 'tipo' => $q['tipo'], 'xp' => (int) $q['recompensa_xp'], 'credito' => (int) $q['recompensa_credito'],
            'ativa' => (bool) $q['ativa'], 'usado' => $usado('quiz', (int) $q['quiz_id']), 'perguntas' => $perguntas[(int) $q['quiz_id']] ?? [],
            'origem' => $q['origem'] ?? 'manual', 'geradoEm' => $q['gerado_em'] ?? null,
        ], $pdo->query('SELECT * FROM quiz ORDER BY quiz_id')->fetchAll());
    }

    $missoes = [];
    if ($tem('missao')) {
        $metas = [];
        foreach ($pdo->query('SELECT * FROM missao_atividade ORDER BY missao_atividade_id') as $m) {
            $metas[(int) $m['missao_id']][] = ['atividade' => (int) $m['atividade_sustentavel_id'], 'quantidade' => (int) $m['quantidade_necessaria']];
        }
        $missoes = array_map(static fn($m) => [
            'id' => (int) $m['missao_id'], 'tipo' => (int) $m['tipo_missao_id'], 'nome' => $m['nome'], 'descricao' => $m['descricao'],
            'xp' => (int) $m['recompensa_xp'], 'credito' => (int) $m['recompensa_credito'], 'penalidade' => (int) $m['penalidade_indicador'],
            'dataInicio' => $m['data_inicio'], 'dataFim' => $m['data_fim'], 'ativa' => (bool) $m['ativa'],
            'origem' => $m['origem'] ?? 'manual', 'geradoEm' => $m['gerado_em'] ?? null,
            'usado' => $usado('missao', (int) $m['missao_id']), 'metas' => $metas[(int) $m['missao_id']] ?? [],
        ], $pdo->query('SELECT * FROM missao ORDER BY missao_id')->fetchAll());
    }

    $conquistas = $tem('conquista') ? array_map(static function ($c) use ($usado): array {
        [$tipo, $alvo] = array_pad(explode(':', (string) $c['tipo_criterio'], 2), 2, null);
        return [
            'id' => (int) $c['conquista_id'], 'nome' => $c['nome'], 'descricao' => $c['descricao'],
            'xp' => (int) $c['recompensa_xp'], 'credito' => (int) $c['recompensa_credito'],
            'criterio' => $tipo,
            'indicador' => ($alvo !== null && $tipo !== 'impacto_atividade') ? (int) $alvo : null,
            'atividade' => ($alvo !== null && $tipo === 'impacto_atividade') ? (int) $alvo : null,
            'meta' => (int) $c['valor_criterio'],
            'ativa' => (bool) $c['ativa'], 'usado' => $usado('conquista', (int) $c['conquista_id']),
        ];
    }, $pdo->query('SELECT * FROM conquista ORDER BY conquista_id')->fetchAll()) : [];

    $construcoes = $tem('construcao') ? array_map(static fn($c) => [
        'id' => (int) $c['construcao_id'], 'nome' => $c['nome'], 'descricao' => $c['descricao'],
        'largura' => (int) $c['largura'], 'profundidade' => (int) $c['profundidade'], 'custo' => (int) $c['custo'],
        'nivel' => (int) $c['nivel_cidade_minimo'], 'ativa' => (bool) $c['ativa'], 'usado' => $usado('construcao', (int) $c['construcao_id']),
        'categoria' => $c['categoria'], 'efeito' => $c['efeito'], 'area' => $c['area_categoria'] !== null ? (int) $c['area_categoria'] : null,
        'desenho' => $c['desenho'], 'aparencia' => aparencia_do_banco($c['aparencia']),
        'moradores' => (int) $c['moradores'], 'carros' => (int) $c['carros'], 'bicicletas' => (int) $c['bicicletas'],
        'onibus' => (int) $c['onibus'], 'renda_diaria' => (int) $c['renda_diaria'],
    ], $pdo->query('SELECT c.*, ia.categoria_ambiental_id AS area_categoria FROM construcao c
                 LEFT JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = c.indicador_ambiental_id
                  ORDER BY c.construcao_id')->fetchAll()) : [];

    $contas = $tem('conta') ? listar_contas_admin() : [];

    // Missões oficiais de comunidade (as internas são das próprias comunidades)
    $cmissoes = $tem('cmissao') ? array_map(static fn($m) => [
        'id' => (int) $m['comunidade_missao_id'], 'nome' => $m['nome'], 'descricao' => $m['descricao'],
        'atividade' => (int) $m['atividade_sustentavel_id'], 'meta' => (float) $m['meta'], 'unidade' => $m['impacto_real_unidade'],
        'xp' => (int) $m['recompensa_xp'], 'credito' => (int) $m['recompensa_credito'], 'pontos' => (int) $m['pontos'],
        'dataInicio' => $m['data_inicio'], 'dataFim' => $m['data_fim'], 'ativa' => (bool) $m['ativa'],
        'origem' => $m['origem'] ?? 'manual', 'geradoEm' => $m['gerado_em'] ?? null,
        'usado' => $usado('cmissao', (int) $m['comunidade_missao_id']),
    ], $pdo->query('SELECT m.*, a.impacto_real_unidade FROM comunidade_missao m
                      JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = m.atividade_sustentavel_id
                     WHERE m.comunidade_id IS NULL ORDER BY m.comunidade_missao_id')->fetchAll()) : [];

    return [
        'categorias' => $categorias, 'dificuldades' => array_keys(QUIZ_MULTIPLICADOR), 'categoriasConstrucao' => CATEGORIAS_CONSTRUCAO,
        'desenhos' => DESENHOS_ESTRUTURA, 'aparenciaPadrao' => APARENCIA_PADRAO,
        'tiposQuiz' => $tem('quiz') ? array_map(static fn(array $t) => ['nome' => $t['nome'], 'min' => $t['min'], 'max' => $t['max'], 'multiplicador' => $t['multiplicador']], QUIZ_TIPOS) : [],
        'criterios' => $tem('conquista') ? CRITERIOS_CONQUISTA : [],
        'atividades' => $atividades, 'quizzes' => $quizzes, 'missoes' => $missoes, 'conquistas' => $conquistas,
        'construcoes' => $construcoes, 'contas' => $contas,
        'cmissoes' => $cmissoes, 'comunidades' => $tem('comunidade') ? listar_comunidades_admin() : [],
        'promocoes' => $tem('promocao') ? listar_promocoes_admin() : [],
        'secoesPermitidas' => $secoes,
        'adminPerfil' => $admin['perfil'] ?? null,
        'adminPerfilNome' => $admin['perfilNome'] ?? null,
        'adminPermissoes' => $admin['permissoes'] ?? [],
        'perfisAdmin' => $tem('conta') && ($admin === null || admin_tem_permissao($admin, 'contas_admin')) ? admin_catalogo_perfis() : [],
        'perfisPronto' => admin_perfis_banco_pronto(),
        'usuarioAtualId' => $admin['usuarioId'] ?? usuario_id(),
        'iaDisponivel' => $tem('quiz') || $tem('missao') || $tem('cmissao') || $tem('construcao') ? ia_disponivel() : false,
    ];
}

/* ---------------------------------------------------------------------------
   Gravação
   --------------------------------------------------------------------------- */

function salvar_atividade(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $v = [
        'nome' => c_texto($d, 'nome', 'Nome', 3, 100),
        'descricao' => c_texto($d, 'descricao', 'Descrição', 3, 250),
        'categoria' => c_inteiro($d, 'categoria', 'Categoria', 1, 1000),
        'xp' => c_inteiro($d, 'xp', 'XP', 0, RECOMPENSA_MAX),
        'credito' => c_inteiro($d, 'credito', 'Créditos', 0, RECOMPENSA_MAX),
        'impacto' => c_inteiro($d, 'impacto', 'Impacto no indicador', 0, 100),
        'ativa' => c_bool($d, 'ativa'),
        'aceitaFoto' => !array_key_exists('aceitaFoto', $d) || c_bool($d, 'aceitaFoto'),   // RN92
        'aceitaParceiro' => !array_key_exists('aceitaParceiro', $d) || c_bool($d, 'aceitaParceiro'),   // RN69
    ];
    $qtd = trim((string) ($d['impactoRealQuantidade'] ?? ''));
    $unidade = c_texto($d, 'impactoRealUnidade', 'Unidade do impacto real', 1, 30, false);
    if ($qtd !== '' && (!is_numeric($qtd) || (float) $qtd < 0 || (float) $qtd > 99999999)) throw new RegistroInvalido('Impacto real: informe um número maior ou igual a 0.');
    if (($qtd === '') !== ($unidade === null)) throw new RegistroInvalido('Impacto real: preencha a quantidade e a unidade juntas (ou deixe as duas vazias).');
    if ($v['aceitaParceiro'] && ($qtd === '' || (float) $qtd <= 0)) {
        throw new RegistroInvalido('Para ser verificada por parceiros, preencha o impacto real (quantidade maior que zero e unidade).');
    }

    return c_transacao(static function (PDO $pdo) use ($id, $v, $qtd, $unidade) {
        $indicador = indicador_da_categoria($pdo, $v['categoria']);
        $temAceitaParceiro = atividade_aceita_parceiro_pronto();
        $params = [$v['categoria'], $v['nome'], $v['descricao'], $v['xp'], $v['credito'], $qtd === '' ? null : (float) $qtd, $unidade, (int) $v['ativa'], (int) $v['aceitaFoto']];
        if ($temAceitaParceiro) $params[] = (int) $v['aceitaParceiro'];
        try {
            if ($id === 0) {
                $cols = 'categoria_ambiental_id, nome, descricao, recompensa_xp, recompensa_credito, impacto_real_quantidade, impacto_real_unidade, ativa, aceita_foto'
                    . ($temAceitaParceiro ? ', aceita_parceiro' : '');
                $marks = implode(', ', array_fill(0, count($params), '?'));
                $pdo->prepare("INSERT INTO atividade_sustentavel ($cols) VALUES ($marks)")->execute($params);
                $id = (int) $pdo->lastInsertId();
            } else {
                $atual = $pdo->prepare('SELECT categoria_ambiental_id FROM atividade_sustentavel WHERE atividade_sustentavel_id = ? FOR UPDATE');
                $atual->execute([$id]);
                $cat = $atual->fetchColumn();
                if ($cat === false) throw new RegistroInvalido('Atividade não encontrada.');
                if ((int) $cat !== $v['categoria'] && conteudo_usado($pdo, 'atividade', $id)) {
                    throw new RegistroInvalido('Esta atividade já foi usada: a categoria não pode mudar. Crie uma nova atividade.');
                }
                $setParceiro = $temAceitaParceiro ? ', aceita_parceiro = ?' : '';
                $pdo->prepare("UPDATE atividade_sustentavel SET categoria_ambiental_id = ?, nome = ?, descricao = ?, recompensa_xp = ?, recompensa_credito = ?,
                                      impacto_real_quantidade = ?, impacto_real_unidade = ?, ativa = ?, aceita_foto = ?$setParceiro WHERE atividade_sustentavel_id = ?")
                    ->execute([...$params, $id]);
                if ($temAceitaParceiro && !$v['aceitaParceiro']) {
                    $pdo->prepare('UPDATE codigo_parceiro SET cancelado = TRUE WHERE atividade_sustentavel_id = ? AND usado_em IS NULL AND cancelado = FALSE')->execute([$id]);
                    if (parceiro_funcionarios_pronto()) {
                        $pdo->prepare("UPDATE parceiro_pedido_atividade SET status = 'recusado', motivo = 'Atividade não elegível para verificação por parceiros.', data_decisao = NOW()
                                        WHERE atividade_sustentavel_id = ? AND status = 'pendente'")->execute([$id]);
                    }
                    $pdo->prepare('DELETE FROM parceiro_atividade WHERE atividade_sustentavel_id = ?')->execute([$id]);
                }
                $pdo->prepare('DELETE FROM atividade_indicador_ambiental WHERE atividade_sustentavel_id = ?')->execute([$id]);
            }
        } catch (PDOException $ex) {
            c_unico($ex, 'Já existe uma atividade com esse nome.');
        }
        $pdo->prepare('INSERT INTO atividade_indicador_ambiental (atividade_sustentavel_id, indicador_ambiental_id, impacto) VALUES (?, ?, ?)')
            ->execute([$id, $indicador, $v['impacto']]);
        return 'Atividade salva.';
    });
}

/** @param int|null $novoId recebe o id do quiz salvo */
function salvar_quiz(array $d, ?int &$novoId = null): string
{
    $id = (int) ($d['id'] ?? 0);
    $v = [
        'titulo' => c_texto($d, 'titulo', 'Título', 3, 50),
        'descricao' => c_texto($d, 'descricao', 'Descrição', 3, 150),
        'categoria' => c_inteiro($d, 'categoria', 'Categoria', 1, 1000),
        'xp' => c_inteiro($d, 'xp', 'XP base', 0, RECOMPENSA_MAX),
        'credito' => c_inteiro($d, 'credito', 'Créditos base', 0, RECOMPENSA_MAX),
        'ativa' => c_bool($d, 'ativa'),
    ];
    $dificuldade = (string) ($d['dificuldade'] ?? '');
    if (!array_key_exists($dificuldade, QUIZ_MULTIPLICADOR)) throw new RegistroInvalido('Escolha a dificuldade.');
    $tipo = (string) ($d['tipo'] ?? '');
    if (!array_key_exists($tipo, QUIZ_TIPOS)) throw new RegistroInvalido('Escolha o tipo do quiz.');

    $perguntas = is_array($d['perguntas'] ?? null) ? array_values($d['perguntas']) : [];
    if (count($perguntas) < 1 || count($perguntas) > 20) throw new RegistroInvalido('O quiz precisa ter de 1 a 20 perguntas.');
    foreach ($perguntas as $i => &$p) {
        $n = $i + 1;
        $p['enunciado'] = c_texto($p, 'enunciado', "Pergunta {$n}", 3, 150);
        // Fonte da pergunta (quizzes da IA, RN91): título e link http(s) da notícia
        $p['fonteTitulo'] = c_texto($p, 'fonteTitulo', "Pergunta {$n}, fonte", 1, 200, false);
        $p['fonteUrl'] = trim((string) ($p['fonteUrl'] ?? '')) ?: null;
        if ($p['fonteUrl'] !== null && (mb_strlen($p['fonteUrl']) > 300 || !preg_match('#^https?://[^\s<>"]+$#i', $p['fonteUrl']))) {
            throw new RegistroInvalido("Pergunta {$n}: o link da fonte é inválido.");
        }
        $alts = is_array($p['alternativas'] ?? null) ? array_values($p['alternativas']) : [];
        if (count($alts) < 2 || count($alts) > 6) throw new RegistroInvalido("Pergunta {$n}: use de 2 a 6 alternativas.");
        $corretas = 0;
        foreach ($alts as $j => &$a) {
            $a['texto'] = c_texto($a, 'texto', "Pergunta {$n}, alternativa " . ($j + 1), 1, 100);
            $a['correta'] = c_bool($a, 'correta');
            $a['explicacao'] = c_texto($a, 'explicacao', "Pergunta {$n}, explicação", 1, 200, false);
            $corretas += (int) $a['correta'];
        }
        unset($a);
        if ($corretas !== 1) throw new RegistroInvalido("Pergunta {$n}: marque exatamente uma alternativa correta.");
        $p['alternativas'] = $alts;
    }
    unset($p);

    return c_transacao(static function (PDO $pdo) use ($id, $v, $dificuldade, $tipo, $perguntas, &$novoId) {
        indicador_da_categoria($pdo, $v['categoria']);
        $params = [$v['titulo'], $v['descricao'], $v['categoria'], $v['xp'], $v['credito'], $dificuldade, $tipo, (int) $v['ativa']];
        // Limites de perguntas do tipo: só quando as perguntas são criadas ou alteradas (quiz já usado mantém as suas)
        $usado = $id !== 0 && conteudo_usado($pdo, 'quiz', $id);
        $t = QUIZ_TIPOS[$tipo];
        if (!$usado && (count($perguntas) < $t['min'] || count($perguntas) > $t['max'])) {
            throw new RegistroInvalido(sprintf('Quiz %s precisa ter de %d a %d perguntas (tem %d).', mb_strtolower($t['nome']), $t['min'], $t['max'], count($perguntas)));
        }
        if ($id === 0) {
            $pdo->prepare('INSERT INTO quiz (titulo, descricao, categoria_ambiental_id, recompensa_xp, recompensa_credito, dificuldade, tipo, ativa) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($params);
            $id = (int) $pdo->lastInsertId();
        } else {
            if (!c_existe($pdo, 'SELECT 1 FROM quiz WHERE quiz_id = ? FOR UPDATE', [$id])) throw new RegistroInvalido('Quiz não encontrado.');
            $pdo->prepare('UPDATE quiz SET titulo = ?, descricao = ?, categoria_ambiental_id = ?, recompensa_xp = ?, recompensa_credito = ?, dificuldade = ?, tipo = ?, ativa = ? WHERE quiz_id = ?')
                ->execute([...$params, $id]);
            if ($usado) {
                atualizar_textos_quiz($pdo, $id, $perguntas);
                return 'Quiz salvo (só os textos das perguntas podem mudar, pois ele já foi respondido).';
            }
            $pdo->prepare('DELETE a FROM alternativa a JOIN pergunta p ON p.pergunta_id = a.pergunta_id WHERE p.quiz_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM pergunta WHERE quiz_id = ?')->execute([$id]);
        }
        $novoId = $id;
        $insP = $pdo->prepare('INSERT INTO pergunta (quiz_id, enunciado, fonte_titulo, fonte_url) VALUES (?, ?, ?, ?)');
        $insA = $pdo->prepare('INSERT INTO alternativa (pergunta_id, texto, correta, explicacao) VALUES (?, ?, ?, ?)');
        foreach ($perguntas as $p) {
            $insP->execute([$id, $p['enunciado'], $p['fonteTitulo'], $p['fonteUrl']]);
            $pid = (int) $pdo->lastInsertId();
            foreach ($p['alternativas'] as $a) $insA->execute([$pid, $a['texto'], (int) $a['correta'], $a['explicacao']]);
        }
        return 'Quiz salvo.';
    });
}

/** Quiz já respondido: confere que a estrutura não mudou e atualiza só os textos. */
function atualizar_textos_quiz(PDO $pdo, int $quizId, array $perguntas): void
{
    $erro = new RegistroInvalido('Este quiz já foi respondido: só os textos podem mudar. Para incluir ou remover perguntas e alternativas, ou trocar a correta, crie um novo quiz.');
    $atual = [];
    $s = $pdo->prepare('SELECT p.pergunta_id, a.alternativa_id, a.correta FROM pergunta p JOIN alternativa a ON a.pergunta_id = p.pergunta_id WHERE p.quiz_id = ?');
    $s->execute([$quizId]);
    foreach ($s->fetchAll() as $r) $atual[(int) $r['pergunta_id']][(int) $r['alternativa_id']] = (bool) $r['correta'];
    if (count($perguntas) !== count($atual)) throw $erro;

    $upP = $pdo->prepare('UPDATE pergunta SET enunciado = ? WHERE pergunta_id = ?');
    $upA = $pdo->prepare('UPDATE alternativa SET texto = ?, explicacao = ? WHERE alternativa_id = ?');
    foreach ($perguntas as $p) {
        $pid = (int) ($p['id'] ?? 0);
        if (!isset($atual[$pid]) || count($p['alternativas']) !== count($atual[$pid])) throw $erro;
        foreach ($p['alternativas'] as $a) {
            $aid = (int) ($a['id'] ?? 0);
            if (!array_key_exists($aid, $atual[$pid]) || $atual[$pid][$aid] !== $a['correta']) throw $erro;
            $upA->execute([$a['texto'], $a['explicacao'], $aid]);
        }
        $upP->execute([$p['enunciado'], $pid]);
    }
}

function salvar_missao(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $tipo = c_inteiro($d, 'tipo', 'Tipo', TIPO_MISSAO_SEMANAL, TIPO_MISSAO_ESPECIAL);
    $v = [
        'nome' => c_texto($d, 'nome', 'Nome', 3, 100),
        'descricao' => c_texto($d, 'descricao', 'Descrição', 3, 250),
        'xp' => c_inteiro($d, 'xp', 'XP', 0, RECOMPENSA_MAX),
        'credito' => c_inteiro($d, 'credito', 'Créditos', 0, RECOMPENSA_MAX),
        'penalidade' => $tipo === TIPO_MISSAO_SEMANAL ? c_inteiro($d, 'penalidade', 'Penalidade', 0, 100) : 0,   // especial não penaliza (RN75)
        'inicio' => c_data($d, 'dataInicio', 'Início'),
        'fim' => c_data($d, 'dataFim', 'Fim', $tipo === TIPO_MISSAO_ESPECIAL),
        'ativa' => c_bool($d, 'ativa'),
    ];
    if ($v['fim'] !== null && $v['fim'] < $v['inicio']) throw new RegistroInvalido('O fim deve ser igual ou depois do início.');

    // 1 registro por atividade por dia (RN44): a meta não pode passar dos dias do período
    $dias = $tipo === TIPO_MISSAO_SEMANAL ? 7
        : (int) (new DateTimeImmutable($v['inicio']))->diff(new DateTimeImmutable($v['fim']))->days + 1;
    $metas = is_array($d['metas'] ?? null) ? array_values($d['metas']) : [];
    if (count($metas) < 1 || count($metas) > 5) throw new RegistroInvalido('A missão precisa ter de 1 a 5 metas.');
    $porAtividade = [];
    foreach ($metas as $i => $m) {
        $a = c_inteiro($m, 'atividade', 'Meta ' . ($i + 1) . ' (atividade)', 1, PHP_INT_MAX);
        if (isset($porAtividade[$a])) throw new RegistroInvalido('Cada atividade só pode aparecer em uma meta.');
        $porAtividade[$a] = c_inteiro($m, 'quantidade', 'Meta ' . ($i + 1) . ' (quantidade)', 1, 100);
        if ($porAtividade[$a] > $dias) {
            throw new RegistroInvalido('Meta ' . ($i + 1) . ': cada atividade só pode ser registrada uma vez por dia, então a meta vai até '
                . $dias . ($tipo === TIPO_MISSAO_SEMANAL ? ' (a semana tem 7 dias).' : ($dias === 1 ? ' (a missão dura 1 dia).' : " (a missão dura {$dias} dias).")));
        }
    }

    return c_transacao(static function (PDO $pdo) use ($id, $tipo, $v, $porAtividade) {
        foreach (array_keys($porAtividade) as $a) {
            if (!c_existe($pdo, 'SELECT 1 FROM atividade_sustentavel WHERE atividade_sustentavel_id = ?', [$a])) throw new RegistroInvalido('Atividade da meta não encontrada.');
        }
        $params = [$tipo, $v['nome'], $v['descricao'], $v['xp'], $v['credito'], $v['penalidade'], $v['inicio'], $v['fim'], (int) $v['ativa']];
        try {
            if ($id === 0) {
                $pdo->prepare('INSERT INTO missao (tipo_missao_id, nome, descricao, recompensa_xp, recompensa_credito, penalidade_indicador, data_inicio, data_fim, ativa)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($params);
                $id = (int) $pdo->lastInsertId();
            } else {
                if (!c_existe($pdo, 'SELECT 1 FROM missao WHERE missao_id = ? FOR UPDATE', [$id])) throw new RegistroInvalido('Missão não encontrada.');
                $pdo->prepare('UPDATE missao SET tipo_missao_id = ?, nome = ?, descricao = ?, recompensa_xp = ?, recompensa_credito = ?, penalidade_indicador = ?,
                                      data_inicio = ?, data_fim = ?, ativa = ? WHERE missao_id = ?')->execute([...$params, $id]);
            }
        } catch (PDOException $ex) {
            c_unico($ex, 'Já existe uma missão com esse nome.');
        }
        // Metas: atualiza as que continuam, cria as novas e remove as que saíram
        $atuais = $pdo->prepare('SELECT missao_atividade_id, atividade_sustentavel_id FROM missao_atividade WHERE missao_id = ?');
        $atuais->execute([$id]);
        foreach ($atuais->fetchAll() as $m) {
            $a = (int) $m['atividade_sustentavel_id'];
            if (isset($porAtividade[$a])) {
                $pdo->prepare('UPDATE missao_atividade SET quantidade_necessaria = ? WHERE missao_atividade_id = ?')->execute([$porAtividade[$a], $m['missao_atividade_id']]);
                unset($porAtividade[$a]);
            } else {
                $pdo->prepare('DELETE FROM missao_atividade WHERE missao_atividade_id = ?')->execute([$m['missao_atividade_id']]);
            }
        }
        foreach ($porAtividade as $a => $q) {
            $pdo->prepare('INSERT INTO missao_atividade (missao_id, atividade_sustentavel_id, quantidade_necessaria) VALUES (?, ?, ?)')->execute([$id, $a, $q]);
        }
        return 'Missão salva.';
    });
}

function salvar_conquista(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $criterio = (string) ($d['criterio'] ?? '');
    if (!array_key_exists($criterio, CRITERIOS_CONQUISTA)) throw new RegistroInvalido('Escolha o critério.');
    $exige = CRITERIOS_CONQUISTA[$criterio];
    $atividadeAlvo = null;
    if ($exige === 'atividade') {
        $atividadeAlvo = c_inteiro($d, 'atividade', 'Atividade do critério', 1, PHP_INT_MAX);
        $tipo = $criterio . ':' . $atividadeAlvo;
    } elseif ($exige) {
        $tipo = $criterio . ':' . c_inteiro($d, 'indicador', 'Área do critério', 1, 5);
    } else {
        $tipo = $criterio;
    }
    $v = [
        c_texto($d, 'nome', 'Nome', 3, 50), c_texto($d, 'descricao', 'Descrição', 3, 250),
        c_inteiro($d, 'xp', 'XP', 0, RECOMPENSA_MAX), c_inteiro($d, 'credito', 'Créditos', 0, RECOMPENSA_MAX),
        $tipo, c_inteiro($d, 'meta', 'Meta', 1, 100000000), (int) c_bool($d, 'ativa'),
    ];
    return c_transacao(static function (PDO $pdo) use ($id, $v, $atividadeAlvo) {
        if ($atividadeAlvo !== null && !c_existe($pdo, 'SELECT 1 FROM atividade_sustentavel WHERE atividade_sustentavel_id = ? AND impacto_real_quantidade > 0', [$atividadeAlvo])) {
            throw new RegistroInvalido('Escolha uma atividade que tenha impacto real definido.');
        }
        if ($id === 0) {
            $pdo->prepare('INSERT INTO conquista (nome, descricao, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio, ativa) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($v);
        } else {
            if (!c_existe($pdo, 'SELECT 1 FROM conquista WHERE conquista_id = ?', [$id])) throw new RegistroInvalido('Conquista não encontrada.');
            $pdo->prepare('UPDATE conquista SET nome = ?, descricao = ?, recompensa_xp = ?, recompensa_credito = ?, tipo_criterio = ?, valor_criterio = ?, ativa = ? WHERE conquista_id = ?')
                ->execute([...$v, $id]);
        }
        return 'Conquista salva.';
    });
}

function salvar_construcao(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $v = [
        'nome' => c_texto($d, 'nome', 'Nome', 3, 100),
        'descricao' => c_texto($d, 'descricao', 'Descrição', 1, 250, false),
        'largura' => c_inteiro($d, 'largura', 'Largura', 1, 10),
        'profundidade' => c_inteiro($d, 'profundidade', 'Profundidade', 1, 10),
        'custo' => c_inteiro($d, 'custo', 'Custo', 0, RECOMPENSA_MAX),
        'nivel' => c_inteiro($d, 'nivel', 'Nível da cidade', 1, 4),
        'ativa' => c_bool($d, 'ativa'),
    ];
    $categoria = (string) ($d['categoria'] ?? '');
    if (!array_key_exists($categoria, CATEGORIAS_CONSTRUCAO)) throw new RegistroInvalido('Escolha a categoria.');
    $efeito = (string) ($d['efeito'] ?? '');
    if ($efeito !== '' && !in_array($efeito, EFEITOS_CONSTRUCAO, true)) throw new RegistroInvalido('Efeito inválido.');
    $area = (int) ($d['area'] ?? 0);
    if ($efeito !== '' && $area <= 0) throw new RegistroInvalido('Escolha a área afetada pelo efeito.');
    // Desenho no mapa (migração 018): um dos modelos ou o prédio personalizado com as opções
    $desenho = (string) ($d['desenho'] ?? '');
    if ($desenho !== DESENHO_PERSONALIZADO && !array_key_exists($desenho, DESENHOS_ESTRUTURA)) throw new RegistroInvalido('Desenho: escolha um modelo ou "Personalizado".');
    $aparencia = $desenho === DESENHO_PERSONALIZADO
        ? json_encode(validar_aparencia(is_string($d['aparencia'] ?? null) ? json_decode($d['aparencia'], true) : ($d['aparencia'] ?? null)), JSON_UNESCAPED_UNICODE)
        : null;
    // Efeitos na cidade (RN80): campos vazios valem 0; renda só no comércio
    $rotulos = ['moradores' => 'Moradores', 'carros' => 'Carros', 'bicicletas' => 'Bicicletas', 'onibus' => 'Ônibus', 'renda_diaria' => 'Renda por dia'];
    $ef = [];
    foreach (EFEITOS_LIMITES as $campo => $max) {
        $ef[$campo] = ($d[$campo] ?? '') === '' ? 0 : c_inteiro($d, $campo, $rotulos[$campo], 0, $max);
    }
    if ($ef['renda_diaria'] > 0 && $categoria !== 'comercio') throw new RegistroInvalido('Só estruturas de Comércio e lazer rendem créditos por dia.');
    return c_transacao(static function (PDO $pdo) use ($id, $v, $categoria, $efeito, $area, $desenho, $aparencia, $ef) {
        $indicador = $efeito !== '' ? indicador_da_categoria($pdo, $area) : null;
        $params = [$v['nome'], $v['descricao'], $v['largura'], $v['profundidade'], $v['custo'], $v['nivel'], (int) $v['ativa'],
                   $categoria, $efeito !== '' ? $efeito : null, $indicador, $desenho, $aparencia, ...array_values($ef)];
        if ($id === 0) {
            $pdo->prepare('INSERT INTO construcao (nome, descricao, largura, profundidade, custo, nivel_cidade_minimo, ativa, categoria, efeito, indicador_ambiental_id, desenho, aparencia, moradores, carros, bicicletas, onibus, renda_diaria) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($params);
        } else {
            $s = $pdo->prepare('SELECT largura, profundidade FROM construcao WHERE construcao_id = ? FOR UPDATE');
            $s->execute([$id]);
            $atual = $s->fetch();
            if (!$atual) throw new RegistroInvalido('Estrutura não encontrada.');
            if (((int) $atual['largura'] !== $v['largura'] || (int) $atual['profundidade'] !== $v['profundidade'])
                && c_existe($pdo, 'SELECT 1 FROM cidade_construcao WHERE construcao_id = ?', [$id])) {
                throw new RegistroInvalido('Esta estrutura já foi construída por jogadores: o tamanho não pode mudar. Crie uma nova estrutura.');
            }
            $pdo->prepare('UPDATE construcao SET nome = ?, descricao = ?, largura = ?, profundidade = ?, custo = ?, nivel_cidade_minimo = ?, ativa = ?, categoria = ?, efeito = ?, indicador_ambiental_id = ?, desenho = ?, aparencia = ?, moradores = ?, carros = ?, bicicletas = ?, onibus = ?, renda_diaria = ? WHERE construcao_id = ?')
                ->execute([...$params, $id]);
        }
        return 'Estrutura salva.';
    });
}

/** Missão oficial de comunidade (RN67): semanal, meta em impacto real de uma atividade. */
function salvar_cmissao(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $meta = $d['meta'] ?? null;
    if (!is_numeric($meta) || (float) $meta <= 0 || (float) $meta > 1000000) throw new RegistroInvalido('Meta: informe um número maior que zero.');
    $v = [
        'nome' => c_texto($d, 'nome', 'Nome', 3, 100),
        'descricao' => c_texto($d, 'descricao', 'Descrição', 3, 250),
        'atividade' => c_inteiro($d, 'atividade', 'Atividade', 1, PHP_INT_MAX),
        'meta' => round((float) $meta, 2),
        'xp' => c_inteiro($d, 'xp', 'XP', 0, RECOMPENSA_MAX),
        'credito' => c_inteiro($d, 'credito', 'Créditos', 0, RECOMPENSA_MAX),
        'pontos' => c_inteiro($d, 'pontos', 'Pontos', 0, RECOMPENSA_MAX),
        'inicio' => c_data($d, 'dataInicio', 'Início'),
        'fim' => c_data($d, 'dataFim', 'Fim', false),
        'ativa' => (int) c_bool($d, 'ativa'),
    ];
    if ($v['fim'] !== null && $v['fim'] < $v['inicio']) throw new RegistroInvalido('O fim deve ser igual ou depois do início.');
    return c_transacao(static function (PDO $pdo) use ($id, $v) {
        $s = $pdo->prepare('SELECT impacto_real_quantidade FROM atividade_sustentavel WHERE atividade_sustentavel_id = ?');
        $s->execute([$v['atividade']]);
        $q = $s->fetchColumn();
        if ($q === false) throw new RegistroInvalido('Atividade não encontrada.');
        if ($q === null || (float) $q <= 0) throw new RegistroInvalido('Esta atividade não tem impacto real definido; defina-o antes de usá-la numa missão de comunidade.');
        $params = [$v['nome'], $v['descricao'], $v['atividade'], $v['meta'], $v['xp'], $v['credito'], $v['pontos'], $v['inicio'], $v['fim'], $v['ativa']];
        if ($id === 0) {
            $pdo->prepare('INSERT INTO comunidade_missao (nome, descricao, atividade_sustentavel_id, meta, recompensa_xp, recompensa_credito, pontos, data_inicio, data_fim, ativa)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($params);
        } else {
            $s = $pdo->prepare('SELECT atividade_sustentavel_id FROM comunidade_missao WHERE comunidade_missao_id = ? AND comunidade_id IS NULL FOR UPDATE');
            $s->execute([$id]);
            $atual = $s->fetchColumn();
            if ($atual === false) throw new RegistroInvalido('Missão não encontrada.');
            if ((int) $atual !== $v['atividade'] && conteudo_usado($pdo, 'cmissao', $id)) {
                throw new RegistroInvalido('Esta missão já foi concluída por comunidades: a atividade não pode mudar. Crie uma nova missão.');
            }
            $pdo->prepare('UPDATE comunidade_missao SET nome = ?, descricao = ?, atividade_sustentavel_id = ?, meta = ?, recompensa_xp = ?, recompensa_credito = ?,
                                  pontos = ?, data_inicio = ?, data_fim = ?, ativa = ? WHERE comunidade_missao_id = ?')->execute([...$params, $id]);
        }
        return 'Missão de comunidade salva.';
    });
}

function salvar_conteudo(string $tipo, array $d, ?int $adminId = null): string
{
    return match ($tipo) {
        'atividade' => salvar_atividade($d),
        'quiz' => salvar_quiz($d),
        'missao' => salvar_missao($d),
        'conquista' => salvar_conquista($d),
        'construcao' => salvar_construcao($d),
        'cmissao' => salvar_cmissao($d),
        'promocao' => salvar_promocao($d, $adminId),
        default => throw new RegistroInvalido('Tipo de conteúdo inválido.'),
    };
}

/** Tabela, chave e filtro extra de cada tipo (missões de comunidade: só as oficiais). */
function tabela_conteudo(string $tipo): array
{
    return [
        'atividade' => ['atividade_sustentavel', 'atividade_sustentavel_id', ''], 'quiz' => ['quiz', 'quiz_id', ''],
        'missao' => ['missao', 'missao_id', ''], 'conquista' => ['conquista', 'conquista_id', ''], 'construcao' => ['construcao', 'construcao_id', ''],
        'cmissao' => ['comunidade_missao', 'comunidade_missao_id', ' AND comunidade_id IS NULL'],
        'promocao' => ['codigo_promocional', 'codigo_promocional_id', ''],
    ][$tipo] ?? throw new RegistroInvalido('Tipo de conteúdo inválido.');
}

function alterar_ativo_conteudo(string $tipo, int $id, bool $ativo): string
{
    [$tabela, $chave, $filtro] = tabela_conteudo($tipo);
    if (!c_existe(db(), "SELECT 1 FROM {$tabela} WHERE {$chave} = ?{$filtro}", [$id])) throw new RegistroInvalido('Item não encontrado.');
    db()->prepare("UPDATE {$tabela} SET ativa = ? WHERE {$chave} = ?")->execute([(int) $ativo, $id]);
    return $ativo ? 'Item ativado: aparece no jogo.' : 'Item desativado: não aparece mais no jogo.';
}

/** Exclui de verdade — só itens nunca usados (RN49). */
function excluir_conteudo(string $tipo, int $id): string
{
    [$tabela, $chave, $filtro] = tabela_conteudo($tipo);
    return c_transacao(static function (PDO $pdo) use ($tipo, $id, $tabela, $chave, $filtro) {
        if (!c_existe($pdo, "SELECT 1 FROM {$tabela} WHERE {$chave} = ?{$filtro} FOR UPDATE", [$id])) throw new RegistroInvalido('Item não encontrado.');
        if (conteudo_usado($pdo, $tipo, $id)) throw new RegistroInvalido('Este item está em uso (por jogadores ou em uma missão) e não pode ser excluído. Desative-o.');
        if ($tipo === 'atividade') $pdo->prepare('DELETE FROM atividade_indicador_ambiental WHERE atividade_sustentavel_id = ?')->execute([$id]);
        if ($tipo === 'missao') $pdo->prepare('DELETE FROM missao_atividade WHERE missao_id = ?')->execute([$id]);
        if ($tipo === 'promocao') $pdo->prepare('DELETE FROM codigo_promocional_indicador WHERE codigo_promocional_id = ?')->execute([$id]);
        if ($tipo === 'quiz') {
            $pdo->prepare('DELETE a FROM alternativa a JOIN pergunta p ON p.pergunta_id = a.pergunta_id WHERE p.quiz_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM pergunta WHERE quiz_id = ?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM {$tabela} WHERE {$chave} = ?")->execute([$id]);
        return 'Item excluído.';
    });
}

/* ---------------------------------------------------------------------------
   Contas
   --------------------------------------------------------------------------- */

function acao_conta(int $usuarioAdmin, int $usuarioId, string $operacao, string $motivo = ''): string
{
    $adminId = administrador_id($usuarioAdmin);
    if ($adminId === null) {
        throw new RegistroInvalido('Acesso restrito a administradores.');
    }
    return acao_seguranca_conta_admin($adminId, $usuarioAdmin, $usuarioId, $operacao, $motivo)['mensagem'];
}
