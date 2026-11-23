<?php
/**
 * Consulta controlada à tabela imutável `auditoria` para a Administração (RN101, migração 040).
 *
 * A conta do site (`ecocity_web` / `ecocity_role_app`) continua sem nenhum acesso direto
 * à tabela `auditoria`; toda leitura passa pelas procedures somente leitura (`SQL SECURITY DEFINER`)
 * `sp_resumo_auditoria`, `sp_consultar_auditoria` e `sp_auditoria_tabelas`.
 */
declare(strict_types=1);

const AUDITORIA_TAMANHOS = [10, 20, 50];
const AUDITORIA_ACOES = ['INSERT', 'UPDATE', 'DELETE'];
const AUDITORIA_ATORES = ['admin', 'usuario', 'parceiro', 'sistema', 'sql_direto'];
const AUDITORIA_DIAS = [0, 1, 7, 30, 90];

const AUDITORIA_TABELAS_ROTULO = [
    'usuario'                      => 'Usuários',
    'administrador'                => 'Administradores',
    'jogador'                      => 'Jogadores',
    'cidade'                       => 'Cidades',
    'cidade_construcao'            => 'Estruturas na cidade',
    'registro_atividade'           => 'Registros de atividade',
    'contestacao'                  => 'Contestações',
    'parceiro'                     => 'Parceiros',
    'parceiro_atividade'           => 'Atividades do parceiro',
    'parceiro_funcionario'         => 'Funcionários do parceiro',
    'parceiro_pedido_atividade'    => 'Pedidos de atividade (parceiro)',
    'comunidade'                   => 'Comunidades',
    'comunidade_missao'            => 'Missões de comunidade',
    'denuncia_mensagem'            => 'Denúncias de conversa',
    'comunidade_denuncia'          => 'Denúncias de comunidade',
    'atividade_sustentavel'        => 'Atividades sustentáveis',
    'quiz'                         => 'Quizzes',
    'pergunta'                     => 'Perguntas de quiz',
    'alternativa'                  => 'Alternativas de quiz',
    'missao'                       => 'Missões',
    'conquista'                    => 'Conquistas',
    'construcao'                   => 'Catálogo de estruturas',
    'codigo_promocional'           => 'Códigos promocionais',
    'codigo_promocional_indicador' => 'Indicadores de promoção',
    'codigo_promocional_resgate'   => 'Resgates promocionais',
    'ajuste_conta_admin'           => 'Ajustes administrativos',
    'redefinicao_senha'            => 'Redefinições de senha',
    'redefinicao_senha_parceiro'   => 'Redefinições de senha (parceiro)',
];

/** Migração 040 (procedures somente leitura) aplicada no banco atual? */
function auditoria_banco_pronto(): bool
{
    return schema_flag('auditoria_detalhada', static fn(): bool => (bool) db()->query(
        "SELECT COUNT(*) FROM information_schema.ROUTINES
          WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_consultar_auditoria'"
    )->fetchColumn());
}

/** Formata valor de campo JSON para leitura rápida no diff/CSV. */
function auditoria_formatar_valor(mixed $v): string
{
    if ($v === null) {
        return '—';
    }
    if (is_bool($v)) {
        return $v ? 'sim' : 'não';
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
    return (string) $v;
}

/** Calcula os campos criados, removidos ou alterados entre antes e depois. */
function auditoria_diff_campos(?array $antes, ?array $depois, string $acao): array
{
    $a = $antes ?? [];
    $d = $depois ?? [];
    $chaves = array_values(array_unique(array_merge(array_keys($a), array_keys($d))));
    $diffs = [];

    foreach ($chaves as $k) {
        $temAntes = array_key_exists($k, $a);
        $temDepois = array_key_exists($k, $d);
        $va = $temAntes ? $a[$k] : null;
        $vd = $temDepois ? $d[$k] : null;

        if ($acao === 'INSERT' && $temDepois) {
            $diffs[] = ['campo' => (string) $k, 'antes' => null, 'depois' => auditoria_formatar_valor($vd)];
            continue;
        }
        if ($acao === 'DELETE' && $temAntes) {
            $diffs[] = ['campo' => (string) $k, 'antes' => auditoria_formatar_valor($va), 'depois' => null];
            continue;
        }
        if ($temAntes !== $temDepois || $va !== $vd) {
            $diffs[] = [
                'campo'  => (string) $k,
                'antes'  => $temAntes ? auditoria_formatar_valor($va) : null,
                'depois' => $temDepois ? auditoria_formatar_valor($vd) : null,
            ];
        }
    }
    return $diffs;
}

/** Normaliza filtros da UI/API para chamada segura às procedures. */
function auditoria_normalizar_filtros(array $f): array
{
    $tabela = trim((string) ($f['tabela'] ?? ''));
    if ($tabela !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $tabela) !== 1) {
        $tabela = '';
    }
    $acao = strtoupper(trim((string) ($f['acao'] ?? ($f['comando'] ?? ''))));
    if (!in_array($acao, AUDITORIA_ACOES, true)) {
        $acao = '';
    }
    $ator = strtolower(trim((string) ($f['ator'] ?? '')));
    if (!in_array($ator, AUDITORIA_ATORES, true)) {
        $ator = '';
    }
    $dias = (int) ($f['dias'] ?? 30);
    if (!in_array($dias, AUDITORIA_DIAS, true)) {
        $dias = 30;
    }
    $busca = mb_substr(trim((string) ($f['busca'] ?? '')), 0, 100);
    $porPagina = (int) ($f['porPagina'] ?? ($f['por'] ?? 20));
    if (!in_array($porPagina, AUDITORIA_TAMANHOS, true)) {
        $porPagina = 20;
    }
    $pagina = max(1, (int) ($f['pagina'] ?? 1));

    return [
        'tabela'    => $tabela,
        'acao'      => $acao,
        'ator'      => $ator,
        'dias'      => $dias,
        'busca'     => $busca,
        'porPagina' => $porPagina,
        'pagina'    => $pagina,
    ];
}

/** Classifica o ator humano/origem técnica de uma linha da auditoria. */
function auditoria_ator_info(array $r): array
{
    if (!empty($r['parceiro_app'])) {
        $func = !empty($r['funcionario_app']);
        $nomeParc = (string) ($r['parceiro_nome'] ?: ('Parceiro #' . (int) $r['parceiro_app']));
        if ($func) {
            $nomeFunc = (string) ($r['funcionario_nome'] ?: ('Funcionário #' . (int) $r['funcionario_app']));
            $loginFunc = !empty($r['funcionario_usuario']) ? " ({$r['funcionario_usuario']})" : '';
            return ['tipo' => 'parceiro', 'rotulo' => 'Funcionário', 'descricao' => "{$nomeFunc}{$loginFunc} · {$nomeParc}"];
        }
        return ['tipo' => 'parceiro', 'rotulo' => 'Parceiro', 'descricao' => $nomeParc];
    }

    if (!empty($r['usuario_app'])) {
        $ehAdmin = !empty($r['eh_admin']);
        $nome = (string) ($r['usuario_nome'] ?: ('Usuário #' . (int) $r['usuario_app']));
        $apelido = !empty($r['jogador_apelido']) ? " ({$r['jogador_apelido']})" : '';
        return [
            'tipo'      => $ehAdmin ? 'admin' : 'usuario',
            'rotulo'    => $ehAdmin ? 'Admin' : 'Jogador',
            'descricao' => "{$nome}{$apelido}",
        ];
    }

    if (($r['origem'] ?? null) !== null && $r['origem'] !== '') {
        return ['tipo' => 'sistema', 'rotulo' => 'Sistema/Público', 'descricao' => (string) $r['origem']];
    }

    return ['tipo' => 'sql_direto', 'rotulo' => 'SQL direto', 'descricao' => (string) ($r['usuario_banco'] ?? 'MySQL')];
}

/** Converte linha bruta retornada por `sp_consultar_auditoria` para o formato da API/UI. */
function auditoria_mapear_linha(array $r): array
{
    $antes = isset($r['dados_antes']) && is_string($r['dados_antes']) ? json_decode($r['dados_antes'], true) : null;
    $depois = isset($r['dados_depois']) && is_string($r['dados_depois']) ? json_decode($r['dados_depois'], true) : null;
    $antesArr = is_array($antes) ? $antes : null;
    $depoisArr = is_array($depois) ? $depois : null;
    $ator = auditoria_ator_info($r);
    $tabela = (string) $r['tabela'];

    return [
        'id'           => (int) $r['auditoria_id'],
        'tabela'       => $tabela,
        'tabelaRotulo' => AUDITORIA_TABELAS_ROTULO[$tabela] ?? $tabela,
        'registroId'   => (string) $r['registro_id'],
        'acao'         => (string) $r['acao'],
        'data'         => (string) $r['data'],
        'usuarioBanco' => (string) $r['usuario_banco'],
        'origem'       => $r['origem'] !== null ? (string) $r['origem'] : null,
        'ip'           => $r['ip'] !== null ? (string) $r['ip'] : null,
        'ator'         => $ator,
        'antes'        => $antesArr,
        'depois'       => $depoisArr,
        'diffs'        => auditoria_diff_campos($antesArr, $depoisArr, (string) $r['acao']),
    ];
}

/** Lista entradas de auditoria paginadas com resumo e tabelas disponíveis. */
function consultar_auditoria(array $filtrosEntrada = []): array
{
    $f = auditoria_normalizar_filtros($filtrosEntrada);
    if (!auditoria_banco_pronto()) {
        return [
            'pronto'    => false,
            'filtros'   => $f,
            'resumo'    => ['total' => 0, 'inserts' => 0, 'updates' => 0, 'deletes' => 0, 'porParceiro' => 0, 'sqlDireto' => 0],
            'tabelas'   => [],
            'itens'     => [],
            'pagina'    => 1,
            'porPagina' => $f['porPagina'],
            'total'     => 0,
            'paginas'   => 1,
        ];
    }

    $pdo = db();
    $stRes = $pdo->prepare('CALL sp_resumo_auditoria(?, ?, ?, ?, ?)');
    $stRes->execute([$f['tabela'], $f['acao'], $f['ator'], $f['dias'], $f['busca']]);
    $res = $stRes->fetch() ?: [];
    $stRes->closeCursor();

    $total = (int) ($res['total'] ?? 0);
    $paginas = max(1, (int) ceil($total / $f['porPagina']));
    $pagina = min($f['pagina'], $paginas);
    $offset = ($pagina - 1) * $f['porPagina'];
    $f['pagina'] = $pagina;

    $stLista = $pdo->prepare('CALL sp_consultar_auditoria(?, ?, ?, ?, ?, ?, ?)');
    $stLista->execute([$f['tabela'], $f['acao'], $f['ator'], $f['dias'], $f['busca'], $f['porPagina'], $offset]);
    $linhas = $stLista->fetchAll();
    $stLista->closeCursor();

    $stTabs = $pdo->query('CALL sp_auditoria_tabelas()');
    $tabsRaw = $stTabs->fetchAll();
    $stTabs->closeCursor();

    $tabelas = array_map(static fn(array $t): array => [
        'nome'   => (string) $t['tabela'],
        'rotulo' => AUDITORIA_TABELAS_ROTULO[(string) $t['tabela']] ?? (string) $t['tabela'],
        'total'  => (int) $t['total'],
    ], $tabsRaw);

    return [
        'pronto'  => true,
        'filtros' => $f,
        'resumo'  => [
            'total'       => $total,
            'inserts'     => (int) ($res['inserts'] ?? 0),
            'updates'     => (int) ($res['updates'] ?? 0),
            'deletes'     => (int) ($res['deletes'] ?? 0),
            'porParceiro' => (int) ($res['por_parceiro'] ?? 0),
            'sqlDireto'   => (int) ($res['sql_direto'] ?? 0),
        ],
        'tabelas'   => $tabelas,
        'itens'     => array_map('auditoria_mapear_linha', $linhas),
        'pagina'    => $pagina,
        'porPagina' => $f['porPagina'],
        'total'     => $total,
        'paginas'   => $paginas,
    ];
}

/** Exporta até 2.000 registros filtrados em CSV UTF-8 com proteção contra injeção de fórmulas. */
function csv_auditoria(array $filtrosEntrada = []): string
{
    $f = auditoria_normalizar_filtros($filtrosEntrada);
    $out = fopen('php://temp', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'ID', 'Data/Hora', 'Comando', 'Tabela', 'Registro ID',
        'Ator', 'Quem fez', 'Conta MySQL', 'Origem', 'IP',
        'Campos alterados', 'Antes (JSON)', 'Depois (JSON)',
    ], ';');

    if (auditoria_banco_pronto()) {
        $st = db()->prepare('CALL sp_consultar_auditoria(?, ?, ?, ?, ?, 2000, 0)');
        $st->execute([$f['tabela'], $f['acao'], $f['ator'], $f['dias'], $f['busca']]);
        $linhas = $st->fetchAll();
        $st->closeCursor();

        foreach ($linhas as $raw) {
            $item = auditoria_mapear_linha($raw);
            $resumoDiff = implode(' | ', array_map(static function (array $d): string {
                if ($d['antes'] === null) return "{$d['campo']}: +{$d['depois']}";
                if ($d['depois'] === null) return "{$d['campo']}: -{$d['antes']}";
                return "{$d['campo']}: {$d['antes']} -> {$d['depois']}";
            }, $item['diffs']));
            $antesJson = $item['antes'] !== null ? json_encode($item['antes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $depoisJson = $item['depois'] !== null ? json_encode($item['depois'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

            fputcsv($out, array_map('csv_celula_segura', [
                $item['id'],
                $item['data'],
                $item['acao'],
                $item['tabela'],
                $item['registroId'],
                $item['ator']['rotulo'],
                $item['ator']['descricao'],
                $item['usuarioBanco'],
                $item['origem'] ?? 'SQL direto',
                $item['ip'] ?? '',
                $resumoDiff,
                $antesJson,
                $depoisJson,
            ]), ';');
        }
    }

    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    return $csv;
}
