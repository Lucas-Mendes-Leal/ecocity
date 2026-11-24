<?php
/**
 * API de gestão de conteúdo (somente administradores ativos).
 *
 * POST acao=listar
 * POST acao=salvar    tipo (atividade|quiz|missao|conquista|construcao|cmissao), dados (JSON; sem id = novo)
 * POST acao=ativo     tipo, id, ativo (1|0)
 * POST acao=excluir   tipo, id            — só itens nunca usados
 * POST acao=conta     usuario_id, operacao (bloquear|desbloquear|promover|rebaixar)
 * POST acao=dissolver comunidade_id   — encerra uma comunidade (RN63)
 * POST acao=gerar_ia                 — a IA cria quizzes (desativados) a partir de notícias recentes (RN91)
 * POST acao=gerar_estrutura_ia       — a IA gera ou refina uma estrutura 3D isométrica por prompt (RN76/RN91)
 *
 * Todas exigem sessão de administrador e o token CSRF (cabeçalho X-CSRF-Token).
 * Resposta: { ok, mensagem, conteudo } ou { ok: false, erro }.
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_resposta(['ok' => false, 'erro' => 'Método não permitido.'], 405);
}
$admin = exigir_admin_api();
$usuarioId = $admin['usuarioId'];
$adminId = $admin['adminId'];

if (!isset($_POST['csrf']) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $_POST['csrf'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
}
if (!csrf_valido()) {
    json_resposta(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], 403);
}

$exigirPerm = static function (string $perm) use ($admin): void {
    if (!admin_tem_permissao($admin, $perm)) {
        json_resposta([
            'ok'   => false,
            'erro' => 'Seu perfil administrativo (' . $admin['perfilNome'] . ') não tem permissão para esta operação.',
        ], 403);
    }
};

$tipo = (string) ($_POST['tipo'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

try {
    switch ((string) ($_POST['acao'] ?? '')) {
        case 'listar':
            if (admin_secoes_conteudo_permitidas($admin) === []) {
                $exigirPerm('conteudo_catalogo');
            }
            $mensagem = null;
            break;
        case 'salvar':
            $exigirPerm($tipo === 'promocao' ? 'conteudo_promocoes' : 'conteudo_catalogo');
            $dados = json_decode((string) ($_POST['dados'] ?? ''), true);
            if (!is_array($dados)) throw new RegistroInvalido('Dados inválidos.');
            $mensagem = salvar_conteudo($tipo, $dados, $adminId);
            break;
        case 'ativo':
            $exigirPerm($tipo === 'promocao' ? 'conteudo_promocoes' : 'conteudo_catalogo');
            $mensagem = alterar_ativo_conteudo($tipo, $id, ($_POST['ativo'] ?? '') === '1');
            break;
        case 'excluir':
            $exigirPerm($tipo === 'promocao' ? 'conteudo_promocoes' : 'conteudo_catalogo');
            $mensagem = excluir_conteudo($tipo, $id);
            break;
        case 'conta_detalhe':
            $exigirPerm('contas_suporte');
            $alvoId = (int) ($_POST['usuario_id'] ?? 0);
            json_resposta(['ok' => true, 'conta' => detalhar_conta_admin($alvoId, $admin)]);
        case 'conta_ajustar':
            $exigirPerm('contas_progressao');
            $alvoId = (int) ($_POST['usuario_id'] ?? 0);
            $dados = json_decode((string) ($_POST['dados'] ?? ''), true);
            if (!is_array($dados)) throw new RegistroInvalido('Dados inválidos.');
            $mensagem = ajustar_progressao_conta_admin($adminId, $alvoId, $dados, $admin);
            json_resposta([
                'ok'       => true,
                'mensagem' => $mensagem,
                'conta'    => detalhar_conta_admin($alvoId, $admin),
                'conteudo' => listar_conteudo($admin),
            ]);
        case 'conta_moderar':
            $exigirPerm('contas_suporte');
            $alvoId = (int) ($_POST['usuario_id'] ?? 0);
            $dados = json_decode((string) ($_POST['dados'] ?? ''), true);
            if (!is_array($dados)) throw new RegistroInvalido('Dados inválidos.');
            $mensagem = moderar_perfil_conta_admin($adminId, $alvoId, $dados, $admin);
            json_resposta([
                'ok'       => true,
                'mensagem' => $mensagem,
                'conta'    => detalhar_conta_admin($alvoId, $admin),
                'conteudo' => listar_conteudo($admin),
            ]);
        case 'conta':
            $alvoId = (int) ($_POST['usuario_id'] ?? 0);
            $opConta = (string) ($_POST['operacao'] ?? '');
            $exigirPerm(in_array($opConta, ['promover', 'rebaixar', 'alterar_perfil'], true) ? 'contas_admin' : 'contas_suporte');
            $res = acao_seguranca_conta_admin(
                $adminId,
                $usuarioId,
                $alvoId,
                $opConta,
                (string) ($_POST['motivo'] ?? ''),
                trim((string) ($_POST['perfil'] ?? ''))
            );
            json_resposta([
                'ok'          => true,
                'mensagem'    => $res['mensagem'],
                'redefinicao' => $res['redefinicao'] ?? null,
                'conta'       => detalhar_conta_admin($alvoId, $admin),
                'conteudo'    => listar_conteudo($admin),
            ]);
        case 'gerar_ia':
            $exigirPerm('conteudo_catalogo');
            $temaQuiz = ia_normalizar_tema((string) ($_POST['tema'] ?? ''));
            $areaRaw = trim((string) ($_POST['area'] ?? ''));
            $areaQuiz = ($temaQuiz !== '' && $areaRaw !== '') ? (int) $areaRaw : null;
            if ($areaQuiz !== null && !isset(ia_areas()[$areaQuiz])) {
                throw new RegistroInvalido('Área ambiental inválida.');
            }
            // Leva alguns minutos (mais do que o túnel espera): responde já e continua em segundo plano.
            if (!ia_disponivel()) throw new RegistroInvalido('A IA não está disponível no momento. Tente novamente em instantes.');
            if ((int) db()->query("SELECT IS_FREE_LOCK('ecocity_quizzes_ia')")->fetchColumn() !== 1) {
                throw new RegistroInvalido('Já existe uma geração de quizzes em andamento.');
            }
            session_write_close();
            $msgInicio = $temaQuiz !== ''
                ? "A IA começou a gerar o quiz sobre \"{$temaQuiz}\" (leva alguns minutos). O rascunho aparece aqui quando ficar pronto."
                : 'A IA começou a ler as notícias e gerar os quizzes (leva de 5 a 15 minutos). Os rascunhos aparecem aqui quando ficarem prontos.';
            $corpo = json_encode(['ok' => true, 'conteudo' => listar_conteudo($admin), 'mensagem' => $msgInicio], JSON_UNESCAPED_UNICODE);
            ignore_user_abort(true);
            set_time_limit(1800);
            if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($corpo));
            header('Connection: close');
            echo $corpo;
            while (ob_get_level() > 0) ob_end_flush();
            flush();
            try {
                $g = gerar_quizzes_ia($temaQuiz, $areaQuiz);
                error_log('EcoCity — quizzes da IA: ' . count($g['criados']) . ' rascunho(s) ' . implode(' | ', $g['erros']));
            } catch (Throwable $ex) {
                error_log('EcoCity — quizzes da IA: ' . $ex->getMessage());
            }
            exit;
        case 'dissolver':
            $exigirPerm('comunidades');
            $mensagem = dissolver_comunidade_admin((int) ($_POST['comunidade_id'] ?? 0));
            break;
        case 'gerar_missoes_ia':
            $exigirPerm('conteudo_catalogo');
            $escopo = (string) ($_POST['escopo'] ?? 'todas');
            if (!in_array($escopo, ['missao', 'cmissao', 'todas'], true)) $escopo = 'todas';
            $temaMissao = ia_normalizar_tema((string) ($_POST['tema'] ?? ''));
            session_write_close();
            set_time_limit(180);
            $g = gerar_missoes_ia($escopo, false, $temaMissao);
            $qtd = count($g['criadas']);
            if ($qtd === 0 && $g['erros']) {
                throw new RegistroInvalido(implode(' ', $g['erros']));
            }
            json_resposta([
                'ok'        => true,
                'mensagem'  => $qtd === 1
                    ? "1 rascunho criado pela IA para a semana ({$g['semana']}). Revise e ative."
                    : "{$qtd} rascunhos criados pela IA para a semana ({$g['semana']}). Revise e ative.",
                'resultado' => $g,
                'conteudo'  => listar_conteudo($admin),
            ]);
        case 'gerar_estrutura_ia':
            $exigirPerm('conteudo_catalogo');
            $promptEst = trim((string) ($_POST['prompt'] ?? ''));
            $modoEst = ((string) ($_POST['modo'] ?? 'tudo')) === 'desenho' ? 'desenho' : 'tudo';
            $ctx = json_decode((string) ($_POST['contexto'] ?? '{}'), true);
            $ctxArr = is_array($ctx) ? $ctx : [];

            if (($_POST['assincrono'] ?? '') === '1') {
                $temFotoUp = isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $lenPrompt = mb_strlen($promptEst, 'UTF-8');
                if (($lenPrompt < 3 && !$temFotoUp) || $lenPrompt > 400) {
                    throw new RegistroInvalido('Descreva a estrutura que deseja gerar (de 3 a 400 caracteres) ou envie uma imagem de referência.');
                }
                if (!ia_disponivel()) {
                    throw new RegistroInvalido('A IA não está disponível no momento. Tente novamente em instantes.');
                }

                // Copia uploads antes de encerrar a resposta HTTP; is_file() continua válido em segundo plano
                $tmpUploads = [];
                $copiarUpload = static function (?array $arq) use (&$tmpUploads): ?array {
                    if (!$arq || ($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
                    if (($arq['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($arq['tmp_name']) || !is_file($arq['tmp_name'])) {
                        throw new RegistroInvalido('Não foi possível ler a imagem enviada.');
                    }
                    if (($arq['size'] ?? 0) > IA_FOTO_ESTRUTURA_MAX_BYTES) {
                        throw new RegistroInvalido('A imagem de referência deve ter no máximo 5 MB.');
                    }
                    $dest = sys_get_temp_dir() . '/ecocity-est-up-' . bin2hex(random_bytes(8));
                    if (!@copy($arq['tmp_name'], $dest)) {
                        throw new RegistroInvalido('Não foi possível preparar a imagem para processamento.');
                    }
                    $tmpUploads[] = $dest;
                    $arq['tmp_name'] = $dest;
                    return $arq;
                };

                try {
                    $fotoBg = $copiarUpload($_FILES['foto'] ?? null);
                    $previaBg = $copiarUpload($_FILES['previa_atual'] ?? null);
                } catch (Throwable $ex) {
                    foreach ($tmpUploads as $u) @unlink($u);
                    throw $ex;
                }

                $jobId = bin2hex(random_bytes(16));
                $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) @getmyuid();
                $jobDir = sys_get_temp_dir();
                $jobArq = "{$jobDir}/ecocity-est-job-u{$uid}-{$jobId}.json";
                $gravarJob = static function (array $payload) use ($jobArq): void {
                    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        return;
                    }
                    $tmp = $jobArq . '.' . bin2hex(random_bytes(4));
                    if (@file_put_contents($tmp, $json) !== false) {
                        if (!@rename($tmp, $jobArq)) {
                            @file_put_contents($jobArq, $json, LOCK_EX);
                            @unlink($tmp);
                        }
                    }
                };
                $gravarJob(['status' => 'processando', 'usuarioId' => $usuarioId, 'criadoEm' => time()]);

                responder_e_continuar(['ok' => true, 'assincrono' => true, 'jobId' => $jobId], 300);
                try {
                    $res = gerar_estrutura_ia($promptEst, $modoEst, $ctxArr, $fotoBg, $previaBg);
                    $msgEst = $res['modo'] === 'desenho'
                        ? 'Desenho atualizado pela IA.'
                        : 'Estrutura e desenho gerados pela IA. Revise os campos e salve.';
                    $gravarJob([
                        'status'    => 'pronto',
                        'usuarioId' => $usuarioId,
                        'criadoEm'  => time(),
                        'mensagem'  => $msgEst,
                        'resultado' => $res,
                    ]);
                } catch (Throwable $ex) {
                    $erroBg = $ex instanceof RegistroInvalido
                        ? $ex->getMessage()
                        : 'Não foi possível concluir a geração pela IA. Tente novamente.';
                    if (!($ex instanceof RegistroInvalido)) {
                        error_log('EcoCity — api/conteudo async: ' . $ex->getMessage());
                    }
                    $gravarJob(['status' => 'erro', 'usuarioId' => $usuarioId, 'criadoEm' => time(), 'erro' => $erroBg]);
                } finally {
                    foreach ($tmpUploads as $u) @unlink($u);
                }
                exit;
            }

            session_write_close();
            set_time_limit(300);
            $res = gerar_estrutura_ia(
                $promptEst,
                $modoEst,
                $ctxArr,
                $_FILES['foto'] ?? null,
                $_FILES['previa_atual'] ?? null
            );
            json_resposta([
                'ok'        => true,
                'mensagem'  => $res['modo'] === 'desenho' ? 'Desenho atualizado pela IA.' : 'Estrutura e desenho gerados pela IA. Revise os campos e salve.',
                'resultado' => $res,
                'conteudo'  => listar_conteudo($admin),
            ]);
        case 'gerar_estrutura_ia_status':
            $exigirPerm('conteudo_catalogo');
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) @getmyuid();
            $jobDir = sys_get_temp_dir();
            foreach (glob("{$jobDir}/ecocity-est-job-u{$uid}-*.json") ?: [] as $arqAntigo) {
                if (@filemtime($arqAntigo) !== false && time() - (int) @filemtime($arqAntigo) > 900) {
                    @unlink($arqAntigo);
                }
            }
            $jobId = (string) ($_POST['job_id'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/', $jobId) !== 1) {
                throw new RegistroInvalido('Identificador da geração inválido.');
            }
            $jobArq = "{$jobDir}/ecocity-est-job-u{$uid}-{$jobId}.json";
            $bruto = @file_get_contents($jobArq);
            $job = $bruto !== false ? json_decode($bruto, true) : null;
            if (!is_array($job) || (int) ($job['usuarioId'] ?? 0) !== $usuarioId) {
                throw new RegistroInvalido('Geração de estrutura não encontrada ou expirada.');
            }
            $stJob = (string) ($job['status'] ?? '');
            if ($stJob === 'processando') {
                json_resposta(['ok' => true, 'status' => 'processando']);
            }
            @unlink($jobArq);
            if ($stJob === 'erro') {
                throw new RegistroInvalido((string) ($job['erro'] ?? 'Não foi possível gerar a estrutura.'));
            }
            json_resposta([
                'ok'        => true,
                'status'    => 'pronto',
                'mensagem'  => (string) ($job['mensagem'] ?? 'Estrutura gerada pela IA.'),
                'resultado' => $job['resultado'] ?? null,
                'conteudo'  => listar_conteudo($admin),
            ]);
        default:
            json_resposta(['ok' => false, 'erro' => 'Ação inválida.'], 400);
    }
} catch (RegistroInvalido $ex) {
    json_resposta(['ok' => false, 'erro' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('EcoCity — api/conteudo: ' . $ex->getMessage());
    json_resposta(['ok' => false, 'erro' => 'Não foi possível concluir. Tente novamente.'], 500);
}

json_resposta(['ok' => true, 'mensagem' => $mensagem, 'conteudo' => listar_conteudo($admin)]);
