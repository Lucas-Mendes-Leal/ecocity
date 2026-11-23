-- =============================================================================
-- EcoCity — 05. Stored procedures e event de manutenção
--   marcar_sincronia / registrar_auditoria  usadas pelos triggers (04)
--   sp_limpar_tentativas_abandonadas(dias)   manutenção (event diário às 4h)
--   sp_resumo_jogador(id)                    relatório de um jogador
--   sp_relatorio_impacto(inicio, fim)        impacto real por atividade no período
--   sp_ranking_comunidades('semana'|'geral') ranking das comunidades
--   sp_verificar_integridade()               conferência usada no plano de recuperação
--   sp_apagar_demonstracao(manter)           apaga os dados de demonstração
-- As regras de pagamento (XP, créditos) ficam no PHP; aqui só relatórios e manutenção.
-- =============================================================================
USE ecocity;
DELIMITER $$

CREATE PROCEDURE marcar_sincronia(IN p_escopo VARCHAR(10), IN p_id INT, IN p_dominio VARCHAR(20))
BEGIN
    IF p_id IS NOT NULL THEN
        INSERT INTO sincronia (escopo, escopo_id, dominio, versao, atualizado_em)
        VALUES (p_escopo, p_id, p_dominio, 1, NOW(3))
        ON DUPLICATE KEY UPDATE versao = versao + 1, atualizado_em = NOW(3);
    END IF;
END$$


-- Grava uma linha de auditoria (chamada pelos triggers). Em manutenção (@ecocity_manutencao = 1) não grava.
CREATE PROCEDURE registrar_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_id VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_antes JSON,
    IN p_depois JSON
)
SQL SECURITY DEFINER
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        INSERT INTO auditoria (
            tabela, registro_id, acao, dados_antes, dados_depois,
            usuario_app, parceiro_app, funcionario_app, usuario_banco, origem, ip, data
        ) VALUES (
            p_tabela,
            p_id,
            p_acao,
            p_antes,
            p_depois,
            @app_usuario_id,
            @app_parceiro_id,
            @app_funcionario_id,
            USER(),
            LEFT(NULLIF(TRIM(CONVERT(@app_origem USING utf8mb4) COLLATE utf8mb4_unicode_ci), _utf8mb4'' COLLATE utf8mb4_unicode_ci), 120),
            LEFT(NULLIF(TRIM(CONVERT(@app_ip USING utf8mb4) COLLATE utf8mb4_unicode_ci), _utf8mb4'' COLLATE utf8mb4_unicode_ci), 45),
            NOW(3)
        );
    END IF;
END$$

-- Tabelas presentes no log de auditoria e totais (consulta controlada pela Administração, RN101)
CREATE PROCEDURE sp_auditoria_tabelas()
SQL SECURITY DEFINER
BEGIN
    SELECT a.tabela, COUNT(*) AS total
      FROM auditoria a
  GROUP BY a.tabela
  ORDER BY total DESC, a.tabela ASC;
END$$

-- Resumo filtrado de eventos de auditoria (KPIs da Administração, RN101)
CREATE PROCEDURE sp_resumo_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_dias INT,
    IN p_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_dias INT DEFAULT 0;
    DECLARE v_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_like VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    SET v_tabela = NULLIF(TRIM(p_tabela), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_acao = NULLIF(UPPER(TRIM(p_acao)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_ator = NULLIF(LOWER(TRIM(p_ator)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_dias = GREATEST(IFNULL(p_dias, 0), 0);
    SET v_busca = NULLIF(TRIM(p_busca), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_like = IF(v_busca IS NULL, NULL, CONCAT(_utf8mb4'%' COLLATE utf8mb4_unicode_ci, v_busca, _utf8mb4'%' COLLATE utf8mb4_unicode_ci));

    SELECT COUNT(*) AS total,
           COALESCE(SUM(a.acao = 'INSERT'), 0) AS inserts,
           COALESCE(SUM(a.acao = 'UPDATE'), 0) AS updates,
           COALESCE(SUM(a.acao = 'DELETE'), 0) AS deletes,
           COALESCE(SUM(a.parceiro_app IS NOT NULL), 0) AS por_parceiro,
           COALESCE(SUM(a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL), 0) AS sql_direto
      FROM auditoria a
      LEFT JOIN usuario u ON u.usuario_id = a.usuario_app
      LEFT JOIN jogador j ON j.usuario_id = a.usuario_app
      LEFT JOIN parceiro p ON p.parceiro_id = a.parceiro_app
      LEFT JOIN parceiro_funcionario pf ON pf.funcionario_id = a.funcionario_app
     WHERE (v_tabela IS NULL OR a.tabela = v_tabela)
       AND (v_acao IS NULL OR a.acao = v_acao)
       AND (v_dias = 0 OR a.data >= NOW() - INTERVAL v_dias DAY)
       AND (
           v_ator IS NULL
           OR (v_ator = _utf8mb4'sql_direto' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL)
           OR (v_ator = _utf8mb4'sistema' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NOT NULL)
           OR (v_ator = _utf8mb4'parceiro' COLLATE utf8mb4_unicode_ci AND a.parceiro_app IS NOT NULL)
           OR (v_ator = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
           OR (v_ator = _utf8mb4'usuario' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
       )
       AND (
           v_like IS NULL
           OR a.tabela LIKE v_like
           OR a.registro_id LIKE v_like
           OR a.usuario_banco LIKE v_like
           OR a.origem LIKE v_like
           OR a.ip LIKE v_like
           OR u.nome LIKE v_like
           OR u.email LIKE v_like
           OR j.apelido LIKE v_like
           OR p.nome LIKE v_like
           OR pf.nome LIKE v_like
           OR pf.usuario LIKE v_like
           OR CAST(a.dados_antes AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
           OR CAST(a.dados_depois AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
       );
END$$

-- Lista paginada e filtrada da auditoria para a Administração (RN101)
CREATE PROCEDURE sp_consultar_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_dias INT,
    IN p_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_limite INT,
    IN p_offset INT
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_dias INT DEFAULT 0;
    DECLARE v_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_like VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_limite INT DEFAULT 20;
    DECLARE v_offset INT DEFAULT 0;

    SET v_tabela = NULLIF(TRIM(p_tabela), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_acao = NULLIF(UPPER(TRIM(p_acao)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_ator = NULLIF(LOWER(TRIM(p_ator)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_dias = GREATEST(IFNULL(p_dias, 0), 0);
    SET v_busca = NULLIF(TRIM(p_busca), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_like = IF(v_busca IS NULL, NULL, CONCAT(_utf8mb4'%' COLLATE utf8mb4_unicode_ci, v_busca, _utf8mb4'%' COLLATE utf8mb4_unicode_ci));
    SET v_limite = LEAST(GREATEST(IFNULL(p_limite, 20), 1), 2000);
    SET v_offset = GREATEST(IFNULL(p_offset, 0), 0);

    SELECT a.auditoria_id,
           a.tabela,
           a.registro_id,
           a.acao,
           a.dados_antes,
           a.dados_depois,
           a.usuario_app,
           a.parceiro_app,
           a.funcionario_app,
           a.usuario_banco,
           a.origem,
           a.ip,
           a.data,
           u.nome AS usuario_nome,
           u.email AS usuario_email,
           j.apelido AS jogador_apelido,
           EXISTS (
               SELECT 1 FROM administrador adm
                WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1
           ) AS eh_admin,
           p.nome AS parceiro_nome,
           p.email AS parceiro_email,
           pf.nome AS funcionario_nome,
           pf.usuario AS funcionario_usuario
      FROM auditoria a
      LEFT JOIN usuario u ON u.usuario_id = a.usuario_app
      LEFT JOIN jogador j ON j.usuario_id = a.usuario_app
      LEFT JOIN parceiro p ON p.parceiro_id = a.parceiro_app
      LEFT JOIN parceiro_funcionario pf ON pf.funcionario_id = a.funcionario_app
     WHERE (v_tabela IS NULL OR a.tabela = v_tabela)
       AND (v_acao IS NULL OR a.acao = v_acao)
       AND (v_dias = 0 OR a.data >= NOW() - INTERVAL v_dias DAY)
       AND (
           v_ator IS NULL
           OR (v_ator = _utf8mb4'sql_direto' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL)
           OR (v_ator = _utf8mb4'sistema' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NOT NULL)
           OR (v_ator = _utf8mb4'parceiro' COLLATE utf8mb4_unicode_ci AND a.parceiro_app IS NOT NULL)
           OR (v_ator = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
           OR (v_ator = _utf8mb4'usuario' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
       )
       AND (
           v_like IS NULL
           OR a.tabela LIKE v_like
           OR a.registro_id LIKE v_like
           OR a.usuario_banco LIKE v_like
           OR a.origem LIKE v_like
           OR a.ip LIKE v_like
           OR u.nome LIKE v_like
           OR u.email LIKE v_like
           OR j.apelido LIKE v_like
           OR p.nome LIKE v_like
           OR pf.nome LIKE v_like
           OR pf.usuario LIKE v_like
           OR CAST(a.dados_antes AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
           OR CAST(a.dados_depois AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
       )
  ORDER BY a.auditoria_id DESC
     LIMIT v_limite OFFSET v_offset;
END$$


-- Limpeza de tentativas de quiz abandonadas há mais de p_dias e registros antigos de rate-limit/tokens (RN82/migração 045)
CREATE PROCEDURE sp_limpar_tentativas_abandonadas(IN p_dias INT)
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id
     WHERE t.acertos IS NULL AND t.data < CURDATE() - INTERVAL p_dias DAY;
    DELETE FROM tentativa_quiz WHERE acertos IS NULL AND data < CURDATE() - INTERVAL p_dias DAY;
    SET v_total = ROW_COUNT();
    DELETE FROM tentativa_acao_ip WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_codigo_parceiro WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_codigo_promocional WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_login WHERE sucesso = FALSE AND data < NOW() - INTERVAL 7 DAY;
    DELETE FROM redefinicao_senha WHERE data_expiracao < NOW() - INTERVAL 7 DAY;
    DELETE FROM redefinicao_senha_parceiro WHERE data_expiracao < NOW() - INTERVAL 7 DAY;
    SELECT v_total AS tentativas_apagadas;
END$$

-- Resumo de um jogador: dados, impacto por área/unidade e últimos registros
CREATE PROCEDURE sp_resumo_jogador(IN p_jogador INT)
BEGIN
    SELECT * FROM vw_jogador_resumo WHERE jogador_id = p_jogador;
    SELECT ia.nome AS area,
           a.impacto_real_unidade AS unidade,
           COALESCE(viu.acoes, 0) AS acoes,
           COALESCE(viu.impacto, 0) AS impacto,
           COALESCE(viu.pontos_indicador, 0) AS pontos_indicador
      FROM indicador_ambiental ia
      JOIN atividade_indicador_ambiental ai ON ai.indicador_ambiental_id = ia.indicador_ambiental_id
      JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ai.atividade_sustentavel_id
      LEFT JOIN vw_impacto_jogador_unidade viu
             ON viu.jogador_id = p_jogador
            AND viu.indicador_ambiental_id = ia.indicador_ambiental_id
            AND viu.unidade <=> a.impacto_real_unidade
     GROUP BY ia.indicador_ambiental_id, ia.nome, a.impacto_real_unidade, viu.acoes, viu.impacto, viu.pontos_indicador
     ORDER BY ia.indicador_ambiental_id, MIN(a.atividade_sustentavel_id);
    SELECT atividade, tipo_verificacao, status, data_registro, impacto_efetivo, unidade, pontos_indicador
      FROM vw_registro_detalhado WHERE jogador_id = p_jogador ORDER BY data_registro DESC LIMIT 10;
END$$

-- Impacto real (ações aprovadas) por atividade num período
CREATE PROCEDURE sp_relatorio_impacto(IN p_inicio DATE, IN p_fim DATE)
BEGIN
    IF p_fim < p_inicio THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Período inválido: o fim é anterior ao início.';
    END IF;
    SELECT atividade, unidade, COUNT(*) AS acoes, COUNT(DISTINCT jogador_id) AS jogadores, SUM(impacto_efetivo) AS impacto
      FROM vw_registro_detalhado
     WHERE status = 'aprovado' AND dia BETWEEN p_inicio AND p_fim
     GROUP BY atividade, unidade ORDER BY acoes DESC;
END$$

-- Ranking das comunidades: 'semana' ou 'geral'
CREATE PROCEDURE sp_ranking_comunidades(IN p_periodo VARCHAR(10))
BEGIN
    IF p_periodo = 'geral' THEN
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_geral ORDER BY posicao;
    ELSE
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_semana ORDER BY posicao;
    END IF;
END$$

-- Confere a integridade: saldos × históricos, indicadores, cidades, registros e regras recentes (plano de recuperação)
CREATE PROCEDURE sp_verificar_integridade()
BEGIN
    SELECT 'saldo de créditos diferente da soma do histórico' AS verificacao, COUNT(*) AS problemas
      FROM jogador j WHERE j.creditos_verde <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_credito_verde h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'XP diferente da soma do histórico', COUNT(*)
      FROM jogador j WHERE j.xp_atual <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_xp h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'cidade sem os 5 indicadores', COUNT(*)
      FROM cidade c WHERE (SELECT COUNT(*) FROM cidade_indicador ci WHERE ci.cidade_id = c.cidade_id) <> (SELECT COUNT(*) FROM indicador_ambiental)
    UNION ALL
    SELECT 'jogador sem cidade', COUNT(*) FROM jogador j WHERE NOT EXISTS (SELECT 1 FROM cidade c WHERE c.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'registro aprovado sem data de aprovação', COUNT(*) FROM registro_atividade WHERE status = 'aprovado' AND data_aprovacao IS NULL
    UNION ALL
    SELECT 'pergunta sem exatamente 1 alternativa correta', COUNT(*)
      FROM (SELECT pergunta_id FROM alternativa GROUP BY pergunta_id HAVING SUM(correta) <> 1) x
    UNION ALL
    SELECT 'vínculo de parceiro em atividade que não aceita parceiro', COUNT(*)
      FROM parceiro_atividade pa JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = pa.atividade_sustentavel_id
     WHERE a.aceita_parceiro = 0
    UNION ALL
    SELECT 'código de parceiro em aberto em atividade que não aceita parceiro', COUNT(*)
      FROM codigo_parceiro k JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
     WHERE k.usado_em IS NULL AND k.cancelado = 0 AND a.aceita_parceiro = 0
    UNION ALL
    SELECT 'código promocional com resgates acima do limite', COUNT(*)
      FROM codigo_promocional cp
     WHERE cp.limite_usos IS NOT NULL
       AND (SELECT COUNT(*) FROM codigo_promocional_resgate r WHERE r.codigo_promocional_id = cp.codigo_promocional_id) > cp.limite_usos
    UNION ALL
    SELECT 'sistema sem nenhum Administrador Master ativo',
           IF((SELECT COUNT(*) FROM administrador WHERE ativo = 1 AND perfil = 'master') = 0, 1, 0);
END$$

-- Apaga os jogadores de demonstração (usuario.demonstracao = TRUE) e tudo o que é deles.
-- p_manter_apresentacao = TRUE mantém as contas apresentacao01..10@demo.ecocity.test.
CREATE PROCEDURE sp_apagar_demonstracao(IN p_manter_apresentacao BOOLEAN)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET @ecocity_manutencao = 0; RESIGNAL; END;
    SET @ecocity_manutencao = 1;
    START TRANSACTION;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    CREATE TEMPORARY TABLE tmp_demo (jogador_id INT PRIMARY KEY, usuario_id INT, cidade_id INT);
    INSERT INTO tmp_demo
    SELECT j.jogador_id, u.usuario_id, c.cidade_id
      FROM usuario u JOIN jogador j ON j.usuario_id = u.usuario_id JOIN cidade c ON c.jogador_id = j.jogador_id
     WHERE u.demonstracao = TRUE AND NOT (p_manter_apresentacao AND u.email LIKE 'apresentacao%@demo.ecocity.test');
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    CREATE TEMPORARY TABLE tmp_demo_com (comunidade_id INT PRIMARY KEY);
    -- Comunidades criadas por jogadores de demonstração (custo de criação no histórico)
    INSERT IGNORE INTO tmp_demo_com
    SELECT h.comunidade_id FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id
     WHERE h.origem = 'comunidade' AND h.comunidade_id IS NOT NULL;

    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE h FROM historico_xp h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE h FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE t FROM tentativa_quiz t JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE a FROM acesso_diario a JOIN tmp_demo d ON d.jogador_id = a.jogador_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo d ON d.jogador_id = x.jogador_id;
    DELETE p FROM comunidade_presenca p JOIN tmp_demo d ON d.jogador_id = p.jogador_id;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo d ON d.jogador_id = m.jogador_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo d ON d.jogador_id = cd.denunciante_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id OR d.jogador_id = cp.convidado_por;
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo d ON d.jogador_id = cm.jogador_id;
    -- Comunidades da demonstração (com quem mais estiver nelas)
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo_com k ON k.comunidade_id = cm.comunidade_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo_com k ON k.comunidade_id = x.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN tmp_demo_com k ON k.comunidade_id = s.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN comunidade_missao cmi ON cmi.comunidade_missao_id = s.comunidade_missao_id JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    DELETE cmi FROM comunidade_missao cmi JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    UPDATE comunidade_missao cmi JOIN tmp_demo d ON d.jogador_id = cmi.criada_por SET cmi.criada_por = NULL;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo_com k ON k.comunidade_id = m.comunidade_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo_com k ON k.comunidade_id = cp.comunidade_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo_com k ON k.comunidade_id = cd.comunidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo_com k ON s.escopo = 'comunidade' AND s.escopo_id = k.comunidade_id;
    DELETE c FROM comunidade c JOIN tmp_demo_com k ON k.comunidade_id = c.comunidade_id;
    -- Parceiros, registros e o resto do jogador
    DELETE v FROM verificacao_parceiro v JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE cp FROM codigo_parceiro cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id;
    DELETE tc FROM tentativa_codigo_parceiro tc JOIN tmp_demo d ON d.jogador_id = tc.jogador_id;
    DELETE e FROM evidencia_atividade e JOIN registro_atividade r ON r.registro_atividade_id = e.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE r FROM registro_atividade r JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE jm FROM jogador_missao jm JOIN tmp_demo d ON d.jogador_id = jm.jogador_id;
    DELETE jc FROM jogador_conquista jc JOIN tmp_demo d ON d.jogador_id = jc.jogador_id;
    DELETE dm FROM denuncia_mensagem dm JOIN mensagem m ON m.mensagem_id = dm.mensagem_id JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE dm FROM denuncia_mensagem dm JOIN tmp_demo d ON d.jogador_id = dm.denunciante_id;
    DELETE m FROM mensagem m JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE a FROM amizade a JOIN tmp_demo d ON d.jogador_id = a.solicitante_id OR d.jogador_id = a.destinatario_id;
    DELETE b FROM bloqueio b JOIN tmp_demo d ON d.jogador_id = b.bloqueador_id OR d.jogador_id = b.bloqueado_id;
    DELETE s FROM sugestao_dispensada s JOIN tmp_demo d ON d.jogador_id = s.jogador_id OR d.jogador_id = s.sugerido_id;
    DELETE cc FROM cidade_construcao cc JOIN tmp_demo d ON d.cidade_id = cc.cidade_id;
    DELETE h FROM historico_indicador_cidade h JOIN tmp_demo d ON d.cidade_id = h.cidade_id;
    DELETE ci FROM cidade_indicador ci JOIN tmp_demo d ON d.cidade_id = ci.cidade_id;
    DELETE c FROM cidade c JOIN tmp_demo d ON d.cidade_id = c.cidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo d ON s.escopo = 'jogador' AND s.escopo_id = d.jogador_id;
    DELETE ci FROM chat_ia_mensagem ci JOIN tmp_demo d ON d.jogador_id = ci.jogador_id;
    DELETE cpr FROM codigo_promocional_resgate cpr JOIN tmp_demo d ON d.jogador_id = cpr.jogador_id;
    DELETE tcp FROM tentativa_codigo_promocional tcp JOIN tmp_demo d ON d.jogador_id = tcp.jogador_id;
    DELETE aa FROM ajuste_conta_admin aa JOIN tmp_demo d ON d.usuario_id = aa.usuario_id;
    DELETE j FROM jogador j JOIN tmp_demo d ON d.jogador_id = j.jogador_id;
    DELETE rs FROM redefinicao_senha rs JOIN tmp_demo d ON d.usuario_id = rs.usuario_id;
    DELETE tl FROM tentativa_login tl JOIN usuario u ON u.email = tl.email JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    DELETE ad FROM administrador ad JOIN tmp_demo d ON d.usuario_id = ad.usuario_id;
    DELETE u FROM usuario u JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    SELECT COUNT(*) AS jogadores_apagados FROM tmp_demo;
    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    SET @ecocity_manutencao = 0;
END$$

-- Manutenção diária às 4h (precisa de event_scheduler = ON)
CREATE EVENT ev_manutencao_diaria
    ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 4 HOUR)
    DO CALL sp_limpar_tentativas_abandonadas(30)$$

DELIMITER ;
