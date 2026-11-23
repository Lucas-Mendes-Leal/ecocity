<?php
/**
 * API do perfil do jogador logado.
 *
 * POST acao=atualizar  apelido, cidade
 * POST acao=senha      senha_atual, senha_nova, senha_confirmar
 * POST acao=evolucao   evolução, aprendizado e comparação anônima (RN84) → { ok, evolucao }
 * POST acao=tutorial   marca o tutorial de boas-vindas como visto (RN90) → { ok }
 * POST acao=avatar     icone, cor (vazios = padrão)                                      (RN93)
 * POST acao=som        opcao (mudo|efeitos|interface|ambiente|volume|volumeEfeitos|volumeInterface|volumeAmbiente), valor (0|1 ou 0–100) (RN96)
 * POST acao=privacidade opcao (apareceSugestoes|aceitaPedidos|permiteVisita), valor (0|1)
 * POST acao=meus_dados → { ok, dados } (LGPD; 3 por hora)
 * POST acao=sair_todos  derruba as outras sessões da conta
 * POST acao=desativar   senha, confirmacao ("DESATIVAR") → encerra a sessão
 * POST acao=extrato    pagina, tipo (todos|ganhos|gastos), origem (todas|acoes|quizzes|…), porPagina (10|20|30) → { ok, extrato }
 * POST acao=resgatar_codigo codigo → resgata código promocional (RN98)
 *
 * Exige sessão e token CSRF. Resposta: { ok, mensagem, jogador, perfil } ou { ok: false, erro }.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

['usuarioId' => $usuarioId, 'jogadorId' => $jogadorId] = api_jogador();
$texto = static fn(string $campo): string => is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';

try {
    switch ($_POST['acao'] ?? '') {
        case 'evolucao':
            json_resposta(['ok' => true, 'evolucao' => est_jogador($jogadorId)]);

        case 'tutorial':
            marcar_tutorial_visto($jogadorId);
            json_resposta(['ok' => true]);

        case 'extrato':
            json_resposta(['ok' => true, 'extrato' => extrato_jogador($jogadorId, (int) ($_POST['pagina'] ?? 1),
                $texto('tipo') ?: 'todos', $texto('origem') ?: 'todas', (int) ($_POST['porPagina'] ?? 10))]);

        case 'meus_dados':
            json_resposta(['ok' => true, 'dados' => meus_dados($usuarioId, $jogadorId)]);

        case 'desativar':
            desativar_conta($usuarioId, $texto('senha'), $texto('confirmacao'));
            sair();
            json_resposta(['ok' => true, 'mensagem' => 'Conta desativada.', 'redirecionar' => 'login.php']);

        case 'avatar':
            salvar_avatar($jogadorId, trim($texto('icone')), trim($texto('cor')));
            $mensagem = 'Avatar atualizado.';
            break;

        case 'som':
            salvar_som($jogadorId, $texto('opcao'), trim($texto('valor')));
            $mensagem = null;   // salva em silêncio (sem aviso a cada ajuste)
            break;

        case 'privacidade':
            salvar_privacidade($jogadorId, $texto('opcao'), $texto('valor') === '1');
            $mensagem = 'Privacidade atualizada.';
            break;

        case 'sair_todos':
            sair_outros_aparelhos($usuarioId);
            $mensagem = 'Pronto: as outras sessões foram encerradas. Esta continua aberta.';
            break;

        case 'atualizar':
            atualizar_perfil($jogadorId, trim($texto('apelido')), trim($texto('cidade')));
            $mensagem = 'Perfil atualizado.';
            break;

        case 'senha':
            trocar_senha($usuarioId, $texto('senha_atual'), $texto('senha_nova'), $texto('senha_confirmar'));
            $mensagem = 'Senha alterada com sucesso.';
            break;

        case 'resgatar_codigo':
            $res = resgatar_codigo_promocional($jogadorId, $texto('codigo'));
            json_resposta([
                'ok'         => true,
                'mensagem'   => $res['mensagem'],
                'resgate'    => $res['resgate'],
                'jogador'    => carregar_jogador($usuarioId),
                'perfil'     => perfil_completo($usuarioId, $jogadorId),
                'conquistas' => listar_conquistas($jogadorId),
                'construcao' => dados_construcao($jogadorId),
            ]);

        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/perfil: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}

json_resposta([
    'ok'       => true,
    'mensagem' => $mensagem,
    'jogador'  => carregar_jogador($usuarioId),
    'perfil'   => perfil_completo($usuarioId, $jogadorId),
]);
