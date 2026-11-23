<?php
/**
 * API do painel do parceiro (RN70, RN95). POST com sessão de parceiro aprovado e token CSRF
 * (o CSV do relatório é GET, só leitura).
 *
 * Atender (administrador e funcionário; o funcionário só vê e cancela os próprios códigos):
 *   acao=painel                              → { painel }
 *   acao=gerar     atividade_id, quantidade   → { codigo, painel }
 *   acao=cancelar  codigo_id                  → { mensagem, painel }
 *   acao=senha     atual, nova, confirmar     → troca a própria senha
 * Gestão (só o administrador do estabelecimento; funcionário recebe 403):
 *   acao=funcionarios                                   → { funcionarios }
 *   acao=funcionario_criar    nome, usuario, senha      → { mensagem, funcionarios }
 *   acao=funcionario_alterar  funcionario_id, operacao (desativar|reativar|senha), senha
 *   acao=atendimentos  funcionario, situacao, atividade, dias, pagina, por_pagina → { atendimentos }
 *   acao=relatorio     dias                             → { relatorio }
 *   GET ?acao=csv&dias=                                 → CSV do relatório
 *   acao=estabelecimento                                → { estabelecimento }
 *   acao=estabelecimento_salvar  nome, responsavel, endereco, descricao
 *   acao=atividade_remover  atividade_id  ·  acao=atividade_pedir  atividade_id
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$p = parceiro_logado();
if (!$p) json_resposta(['ok' => false, 'erro' => 'Sua sessão terminou. Entre novamente.'], 401);
if ($p['status'] !== 'aprovado') json_resposta(['ok' => false, 'erro' => 'Sua conta de parceiro ainda não foi aprovada.'], 403);
$id = (int) $p['parceiro_id'];
$admin = $p['papel'] === 'admin';
$funcionarioId = $p['funcionario_id'];

// CSV do relatório (GET, só leitura)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'csv') {
    if (!$admin) json_resposta(['ok' => false, 'erro' => 'Só o administrador do estabelecimento vê o relatório.'], 403);
    try {
        $dias = (int) ($_GET['dias'] ?? 30);
        $csv = relatorio_parceiro_csv($id, $dias);
    } catch (RegistroInvalido $ex) {
        json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ecocity-parceiro-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
if (!isset($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) $_POST['csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
if (!csrf_valido()) json_resposta(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], 403);

$acao = (string) ($_POST['acao'] ?? '');
$campo = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$gestao = ['funcionarios', 'funcionario_criar', 'funcionario_alterar', 'atendimentos', 'relatorio', 'estabelecimento',
           'estabelecimento_salvar', 'atividade_remover', 'atividade_pedir'];
if (in_array($acao, $gestao, true) && !$admin) {
    json_resposta(['ok' => false, 'erro' => 'Só o administrador do estabelecimento pode fazer isso.'], 403);
}

try {
    switch ($acao) {
        case 'painel':
            json_resposta(['ok' => true, 'painel' => painel_parceiro($id, $funcionarioId)]);
        case 'gerar':
            $c = gerar_codigo($id, (int) ($_POST['atividade_id'] ?? 0), $_POST['quantidade'] ?? null, $funcionarioId);
            json_resposta(['ok' => true, 'codigo' => $c, 'painel' => painel_parceiro($id, $funcionarioId)]);
        case 'cancelar':
            json_resposta(['ok' => true, 'mensagem' => cancelar_codigo($id, (int) ($_POST['codigo_id'] ?? 0), $funcionarioId), 'painel' => painel_parceiro($id, $funcionarioId)]);
        case 'senha':
            $msg = $admin
                ? trocar_senha_parceiro($id, $campo('atual'), $campo('nova'), $campo('confirmar'))
                : trocar_senha_funcionario($funcionarioId, $campo('atual'), $campo('nova'), $campo('confirmar'));
            json_resposta(['ok' => true, 'mensagem' => $msg]);

        case 'funcionarios':
            json_resposta(['ok' => true, 'funcionarios' => listar_funcionarios($id)]);
        case 'funcionario_criar':
            $msg = criar_funcionario($id, $campo('nome'), $campo('usuario'), $campo('senha'));
            json_resposta(['ok' => true, 'mensagem' => $msg, 'funcionarios' => listar_funcionarios($id)]);
        case 'funcionario_alterar':
            $msg = alterar_funcionario($id, (int) ($_POST['funcionario_id'] ?? 0), $campo('operacao'), $campo('senha'));
            json_resposta(['ok' => true, 'mensagem' => $msg, 'funcionarios' => listar_funcionarios($id)]);
        case 'atendimentos':
            json_resposta(['ok' => true, 'atendimentos' => atendimentos_parceiro($id, [
                'funcionario' => $campo('funcionario') ?: 'todos', 'situacao' => $campo('situacao') ?: 'todas',
                'atividade' => (int) ($_POST['atividade'] ?? 0), 'dias' => (int) ($_POST['dias'] ?? 0),
            ], (int) ($_POST['pagina'] ?? 1), (int) ($_POST['por_pagina'] ?? 20))]);
        case 'relatorio':
            json_resposta(['ok' => true, 'relatorio' => relatorio_parceiro($id, (int) ($_POST['dias'] ?? 30))]);
        case 'estabelecimento':
            json_resposta(['ok' => true, 'estabelecimento' => dados_estabelecimento($id)]);
        case 'estabelecimento_salvar':
            $msg = salvar_estabelecimento($id, $_POST);
            json_resposta(['ok' => true, 'mensagem' => $msg, 'estabelecimento' => dados_estabelecimento($id)]);
        case 'atividade_remover':
            $msg = remover_atividade_parceiro($id, (int) ($_POST['atividade_id'] ?? 0));
            json_resposta(['ok' => true, 'mensagem' => $msg, 'estabelecimento' => dados_estabelecimento($id), 'painel' => painel_parceiro($id)]);
        case 'atividade_pedir':
            $msg = pedir_atividade($id, (int) ($_POST['atividade_id'] ?? 0));
            json_resposta(['ok' => true, 'mensagem' => $msg, 'estabelecimento' => dados_estabelecimento($id)]);
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/parceiro: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}
