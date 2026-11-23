<?php
/**
 * API de registros de atividade do jogador logado.
 *
 * POST acao=criar     atividade_id, tipo (1|2), descricao, foto (obrigatória no tipo 2)
 * POST acao=analisar  registro_id   — IA analisa a foto do próprio registro pendente (RN91)
 * POST acao=contestar registro_id, texto, foto (opcional) — contesta um registro com foto rejeitado (RN94)
 * POST acao=codigo    codigo        — código de 6 dígitos do parceiro (RN71): registro tipo 3 aprovado
 * POST acao=aprovar   registro_id   — SOMENTE TESTE: exige 'debug' => true no config
 * POST acao=rejeitar  registro_id   — SOMENTE TESTE: idem
 *
 * Todas exigem sessão e o token CSRF (campo "csrf" ou cabeçalho X-CSRF-Token).
 * Resposta: { ok, mensagem, jogador, registros } ou { ok: false, erro }.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

// Upload maior que post_max_size chega sem nenhum campo (nem o token CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_resposta(['ok' => false, 'erro' => 'A foto deve ter no máximo 5 MB.'], 413);
}
['usuarioId' => $usuarioId, 'jogadorId' => $jogadorId] = api_jogador();
$acao = (string) ($_POST['acao'] ?? '');

try {
    switch ($acao) {
        case 'criar':
            $r = criar_registro(
                $jogadorId,
                (int) ($_POST['atividade_id'] ?? 0),
                (int) ($_POST['tipo'] ?? 0),
                trim((string) ($_POST['descricao'] ?? '')),
                $_FILES['foto'] ?? null
            );
            $analisar = $r['status'] === 'pendente' && ia_disponivel();
            $mensagem = $r['status'] === 'aprovado'
                ? 'Atividade registrada e aprovada!'
                : ($analisar ? 'Foto enviada! A IA está analisando, só um instante…'
                             : 'Registro enviado com foto. Ele será aprovado por um administrador ou automaticamente em até 1 dia.');
            $extra = ['registroId' => $r['registroId'], 'analisar' => $analisar];
            break;

        case 'analisar':
            $registroId = (int) ($_POST['registro_id'] ?? 0);
            $dono = db()->prepare('SELECT 1 FROM registro_atividade WHERE registro_atividade_id = ? AND jogador_id = ?');
            $dono->execute([$registroId, $jogadorId]);
            if (!$dono->fetchColumn()) {
                json_resposta(['ok' => false, 'erro' => 'Registro não encontrado.'], 404);
            }
            session_write_close();   // a análise leva alguns segundos: não trava as outras requisições
            $parecer = analisar_evidencia($registroId);
            $mensagem = $parecer === null ? null
                : ($parecer['acao'] === 'aprovou' ? 'A IA confirmou a sua foto: atividade aprovada!'
                : ($parecer['acao'] === 'rejeitou' ? 'A IA não confirmou a sua foto. Se você fez a ação, conteste em Meus registros.'
                                                   : 'A IA não teve certeza pela foto. Um administrador vai conferir (até 3 dias).'));
            $extra = ['parecer' => $parecer];
            break;

        case 'contestar':
            $mensagem = contestar_registro($jogadorId, (int) ($_POST['registro_id'] ?? 0), (string) ($_POST['texto'] ?? ''), $_FILES['foto'] ?? null);
            break;

        case 'codigo':
            $v = validar_codigo($jogadorId, (string) ($_POST['codigo'] ?? ''));
            $qtd = number_format($v['quantidade'], floor($v['quantidade']) == $v['quantidade'] ? 0 : 2, ',', '.');
            $mensagem = "Atividade confirmada por {$v['parceiro']}: {$v['atividade']} · {$qtd} {$v['unidade']}!";
            break;

        case 'aprovar':
        case 'rejeitar':
            if (!config('debug')) {
                json_resposta(['ok' => false, 'erro' => 'Ação disponível apenas em ambiente de teste.'], 403);
            }
            $registroId = (int) ($_POST['registro_id'] ?? 0);
            $dono = db()->prepare('SELECT 1 FROM registro_atividade WHERE registro_atividade_id = ? AND jogador_id = ?');
            $dono->execute([$registroId, $jogadorId]);
            if (!$dono->fetchColumn()) {
                json_resposta(['ok' => false, 'erro' => 'Registro não encontrado.'], 404);
            }
            $pdo = db();
            $pdo->beginTransaction();
            $feito = $acao === 'aprovar'
                ? aprovar_registro($pdo, $registroId, null)
                : rejeitar_registro($pdo, $registroId, 'Rejeitado no teste.', null);
            $pdo->commit();
            $mensagem = !$feito ? 'Este registro não está mais pendente.'
                : ($acao === 'aprovar' ? 'Registro aprovado (teste).' : 'Registro rejeitado (teste).');
            break;

        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('EcoCity — api/registros: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}

aprovar_vencidos($jogadorId);
json_resposta(($extra ?? []) + [
    'ok'        => true,
    'mensagem'  => $mensagem,
    'jogador'   => carregar_jogador($usuarioId),
    'registros' => listar_registros($jogadorId),
    'conquistas' => listar_conquistas($jogadorId),
    'perfil'     => perfil_completo($usuarioId, $jogadorId),
    'missoes'    => listar_missoes($jogadorId),
    'construcao' => dados_construcao($jogadorId),
    'avisosComunidade' => avisos_missoes_comunidade($jogadorId),   // missão da comunidade concluída por este registro
]);
