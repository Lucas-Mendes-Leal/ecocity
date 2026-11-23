-- =============================================================================
-- EcoCity — Migração 040: Auditoria detalhada no banco e consulta controlada
--
-- 1. Amplia a tabela imutável `auditoria` com:
--      - parceiro_app / funcionario_app (rastreia ações do Portal do Parceiro)
--      - origem (rota/ação PHP, CLI ou NULL quando alteração direta no banco)
--      - ip (endereço de origem da requisição PHP)
--      - índices compostos por tabela+data, ação+data e parceiro+data
-- 2. Atualiza `registrar_auditoria()` preservando a assinatura dos triggers atuais.
-- 3. Cria 3 procedures somente leitura (`SQL SECURITY DEFINER`) para a Administração:
--      - sp_consultar_auditoria()
--      - sp_resumo_auditoria()
--      - sp_auditoria_tabelas()
-- 4. Adiciona 27 triggers de auditoria em 14 tabelas (ampliando de 17 para 28
--    tabelas auditadas e de 40 para 67 triggers, sem armazenar senha ou token_hash).
-- 5. Concede apenas EXECUTE das 3 procedures para `ecocity_role_app` (nenhum acesso
--    direto SELECT/INSERT/UPDATE/DELETE à tabela `auditoria`).
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Normaliza a collation da tabela legada (ex.: utf8mb4_0900_ai_ci) para utf8mb4_unicode_ci
ALTER TABLE `auditoria` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Novas colunas e índices na tabela `auditoria`
ALTER TABLE `auditoria`
  ADD COLUMN `parceiro_app` INT DEFAULT NULL AFTER `usuario_app`,
  ADD COLUMN `funcionario_app` INT DEFAULT NULL AFTER `parceiro_app`,
  ADD COLUMN `origem` VARCHAR(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `usuario_banco`,
  ADD COLUMN `ip` VARCHAR(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `origem`,
  ADD KEY `ix_auditoria_tabela_data` (`tabela`, `data`),
  ADD KEY `ix_auditoria_acao_data` (`acao`, `data`),
  ADD KEY `ix_auditoria_parceiro` (`parceiro_app`, `data`);

-- 2. Procedure interna chamada pelos triggers (retrocompatível com os 40 triggers existentes)
DROP PROCEDURE IF EXISTS `registrar_auditoria`;
DELIMITER $$
CREATE PROCEDURE `registrar_auditoria`(
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

-- 3. Procedures somente leitura para consulta controlada na Administração
DROP PROCEDURE IF EXISTS `sp_auditoria_tabelas`$$
CREATE PROCEDURE `sp_auditoria_tabelas`()
SQL SECURITY DEFINER
BEGIN
    SELECT a.tabela, COUNT(*) AS total
      FROM auditoria a
  GROUP BY a.tabela
  ORDER BY total DESC, a.tabela ASC;
END$$

DROP PROCEDURE IF EXISTS `sp_resumo_auditoria`$$
CREATE PROCEDURE `sp_resumo_auditoria`(
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

DROP PROCEDURE IF EXISTS `sp_consultar_auditoria`$$
CREATE PROCEDURE `sp_consultar_auditoria`(
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

-- 4. Expansão dos triggers de auditoria (27 novos triggers em 14 tabelas)

-- 4.1 jogador (INSERT / DELETE; UPDATE já existe)
DROP TRIGGER IF EXISTS `trg_aud_jogador_ins`$$
CREATE TRIGGER `trg_aud_jogador_ins` AFTER INSERT ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', NEW.jogador_id, 'INSERT', NULL,
        JSON_OBJECT('apelido', NEW.apelido, 'numero_conta', NEW.numero_conta, 'usuario_id', NEW.usuario_id,
                    'nivel_id', NEW.nivel_id, 'creditos_verde', NEW.creditos_verde, 'xp_atual', NEW.xp_atual));
END$$

DROP TRIGGER IF EXISTS `trg_aud_jogador_del`$$
CREATE TRIGGER `trg_aud_jogador_del` AFTER DELETE ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', OLD.jogador_id, 'DELETE',
        JSON_OBJECT('apelido', OLD.apelido, 'numero_conta', OLD.numero_conta, 'usuario_id', OLD.usuario_id,
                    'nivel_id', OLD.nivel_id, 'creditos_verde', OLD.creditos_verde, 'xp_atual', OLD.xp_atual), NULL);
END$$

-- 4.2 cidade (UPDATE em nome e nível; ignora ultima_queda diária)
DROP TRIGGER IF EXISTS `trg_aud_cidade_upd`$$
CREATE TRIGGER `trg_aud_cidade_upd` AFTER UPDATE ON `cidade` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.nivel_cidade_id <=> NEW.nivel_cidade_id) THEN
        CALL registrar_auditoria('cidade', NEW.cidade_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'nivel_cidade_id', OLD.nivel_cidade_id, 'jogador_id', OLD.jogador_id),
            JSON_OBJECT('nome', NEW.nome, 'nivel_cidade_id', NEW.nivel_cidade_id, 'jogador_id', NEW.jogador_id));
    END IF;
END$$

-- 4.3 cidade_construcao (INSERT / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_cidade_construcao_ins`$$
CREATE TRIGGER `trg_aud_cidade_construcao_ins` AFTER INSERT ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', NEW.cidade_construcao_id, 'INSERT', NULL,
        JSON_OBJECT('cidade_id', NEW.cidade_id, 'construcao_id', NEW.construcao_id,
                    'lote', NEW.lote, 'x', NEW.x, 'y', NEW.y, 'rotacao', NEW.rotacao));
END$$

DROP TRIGGER IF EXISTS `trg_aud_cidade_construcao_del`$$
CREATE TRIGGER `trg_aud_cidade_construcao_del` AFTER DELETE ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', OLD.cidade_construcao_id, 'DELETE',
        JSON_OBJECT('cidade_id', OLD.cidade_id, 'construcao_id', OLD.construcao_id,
                    'lote', OLD.lote, 'x', OLD.x, 'y', OLD.y, 'rotacao', OLD.rotacao), NULL);
END$$

-- 4.4 registro_atividade (INSERT; UPDATE/DELETE já existem)
DROP TRIGGER IF EXISTS `trg_aud_registro_atividade_ins`$$
CREATE TRIGGER `trg_aud_registro_atividade_ins` AFTER INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('registro_atividade', NEW.registro_atividade_id, 'INSERT', NULL,
        JSON_OBJECT('jogador_id', NEW.jogador_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id,
                    'tipo_verificacao_id', NEW.tipo_verificacao_id, 'status', NEW.status,
                    'impacto_real', NEW.impacto_real));
END$$

-- 4.5 comunidade (INSERT; UPDATE/DELETE já existem)
DROP TRIGGER IF EXISTS `trg_aud_comunidade_ins`$$
CREATE TRIGGER `trg_aud_comunidade_ins` AFTER INSERT ON `comunidade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade', NEW.comunidade_id, 'INSERT', NULL,
        JSON_OBJECT('nome', NEW.nome, 'entrada', NEW.entrada, 'icone', NEW.icone, 'ativa', NEW.ativa));
END$$

-- 4.6 comunidade_missao (INSERT / UPDATE / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_comunidade_missao_ins`$$
CREATE TRIGGER `trg_aud_comunidade_missao_ins` AFTER INSERT ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'INSERT', NULL,
        JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                    'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                    'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                    'pontos', NEW.pontos, 'ativa', NEW.ativa, 'origem', NEW.origem));
END$$

DROP TRIGGER IF EXISTS `trg_aud_comunidade_missao_upd`$$
CREATE TRIGGER `trg_aud_comunidade_missao_upd` AFTER UPDATE ON `comunidade_missao` FOR EACH ROW
BEGIN
    IF NOT (OLD.comunidade_id <=> NEW.comunidade_id) OR NOT (OLD.nome <=> NEW.nome)
       OR NOT (OLD.atividade_sustentavel_id <=> NEW.atividade_sustentavel_id)
       OR NOT (OLD.meta <=> NEW.meta) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
       OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.pontos <=> NEW.pontos)
       OR NOT (OLD.data_inicio <=> NEW.data_inicio) OR NOT (OLD.data_fim <=> NEW.data_fim)
       OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'UPDATE',
            JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                        'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                        'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito,
                        'pontos', OLD.pontos, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim,
                        'ativa', OLD.ativa),
            JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                        'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                        'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                        'pontos', NEW.pontos, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim,
                        'ativa', NEW.ativa));
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_aud_comunidade_missao_del`$$
CREATE TRIGGER `trg_aud_comunidade_missao_del` AFTER DELETE ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', OLD.comunidade_missao_id, 'DELETE',
        JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                    'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                    'ativa', OLD.ativa, 'origem', OLD.origem), NULL);
END$$

-- 4.7 denuncia_mensagem (UPDATE em status/decisão/IA)
DROP TRIGGER IF EXISTS `trg_aud_denuncia_mensagem_upd`$$
CREATE TRIGGER `trg_aud_denuncia_mensagem_upd` AFTER UPDATE ON `denuncia_mensagem` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('denuncia_mensagem', NEW.denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$

-- 4.8 comunidade_denuncia (UPDATE em status/decisão/IA)
DROP TRIGGER IF EXISTS `trg_aud_comunidade_denuncia_upd`$$
CREATE TRIGGER `trg_aud_comunidade_denuncia_upd` AFTER UPDATE ON `comunidade_denuncia` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('comunidade_denuncia', NEW.comunidade_denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$

-- 4.9 pergunta (INSERT / UPDATE / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_pergunta_ins`$$
CREATE TRIGGER `trg_aud_pergunta_ins` AFTER INSERT ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'INSERT', NULL,
        JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
END$$

DROP TRIGGER IF EXISTS `trg_aud_pergunta_upd`$$
CREATE TRIGGER `trg_aud_pergunta_upd` AFTER UPDATE ON `pergunta` FOR EACH ROW
BEGIN
    IF NOT (OLD.quiz_id <=> NEW.quiz_id) OR NOT (OLD.enunciado <=> NEW.enunciado)
       OR NOT (OLD.fonte_titulo <=> NEW.fonte_titulo) OR NOT (OLD.fonte_url <=> NEW.fonte_url) THEN
        CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'UPDATE',
            JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado, 'fonte_titulo', OLD.fonte_titulo),
            JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_aud_pergunta_del`$$
CREATE TRIGGER `trg_aud_pergunta_del` AFTER DELETE ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', OLD.pergunta_id, 'DELETE',
        JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado), NULL);
END$$

-- 4.10 alternativa (INSERT / UPDATE / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_alternativa_ins`$$
CREATE TRIGGER `trg_aud_alternativa_ins` AFTER INSERT ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'INSERT', NULL,
        JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
END$$

DROP TRIGGER IF EXISTS `trg_aud_alternativa_upd`$$
CREATE TRIGGER `trg_aud_alternativa_upd` AFTER UPDATE ON `alternativa` FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) OR NOT (OLD.correta <=> NEW.correta)
       OR NOT (OLD.explicacao <=> NEW.explicacao) THEN
        CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'UPDATE',
            JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta),
            JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_aud_alternativa_del`$$
CREATE TRIGGER `trg_aud_alternativa_del` AFTER DELETE ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', OLD.alternativa_id, 'DELETE',
        JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta), NULL);
END$$

-- 4.11 parceiro_atividade (INSERT / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_parceiro_atividade_ins`$$
CREATE TRIGGER `trg_aud_parceiro_atividade_ins` AFTER INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(NEW.parceiro_id, ':', NEW.atividade_sustentavel_id), 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id));
END$$

DROP TRIGGER IF EXISTS `trg_aud_parceiro_atividade_del`$$
CREATE TRIGGER `trg_aud_parceiro_atividade_del` AFTER DELETE ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(OLD.parceiro_id, ':', OLD.atividade_sustentavel_id), 'DELETE',
        JSON_OBJECT('parceiro_id', OLD.parceiro_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id), NULL);
END$$

-- 4.12 codigo_promocional_indicador (INSERT / UPDATE / DELETE)
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_ind_ins`$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_ins` AFTER INSERT ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                    'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
END$$

DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_ind_upd`$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_upd` AFTER UPDATE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    IF NOT (OLD.pontos <=> NEW.pontos) THEN
        CALL registrar_auditoria('codigo_promocional_indicador',
            CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'UPDATE',
            JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                        'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos),
            JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                        'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_ind_del`$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_del` AFTER DELETE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(OLD.codigo_promocional_id, ':', OLD.indicador_ambiental_id), 'DELETE',
        JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                    'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos), NULL);
END$$

-- 4.13 redefinicao_senha (INSERT / UPDATE de uso; NUNCA grava token_hash)
DROP TRIGGER IF EXISTS `trg_aud_redefinicao_senha_ins`$$
CREATE TRIGGER `trg_aud_redefinicao_senha_ins` AFTER INSERT ON `redefinicao_senha` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'data_expiracao', NEW.data_expiracao));
END$$

DROP TRIGGER IF EXISTS `trg_aud_redefinicao_senha_upd`$$
CREATE TRIGGER `trg_aud_redefinicao_senha_upd` AFTER UPDATE ON `redefinicao_senha` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('usuario_id', NEW.usuario_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$

-- 4.14 redefinicao_senha_parceiro (INSERT / UPDATE de uso; NUNCA grava token_hash)
DROP TRIGGER IF EXISTS `trg_aud_redefinicao_parc_ins`$$
CREATE TRIGGER `trg_aud_redefinicao_parc_ins` AFTER INSERT ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'data_expiracao', NEW.data_expiracao));
END$$

DROP TRIGGER IF EXISTS `trg_aud_redefinicao_parc_upd`$$
CREATE TRIGGER `trg_aud_redefinicao_parc_upd` AFTER UPDATE ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$
DELIMITER ;

-- 5. Permissão mínima: ecocity_role_app só pode executar as 3 procedures de leitura controlada
--    (a tabela `auditoria` segue bloqueada contra SELECT/INSERT/UPDATE/DELETE diretos).
GRANT EXECUTE ON PROCEDURE ecocity.sp_consultar_auditoria TO 'ecocity_role_app';
GRANT EXECUTE ON PROCEDURE ecocity.sp_resumo_auditoria TO 'ecocity_role_app';
GRANT EXECUTE ON PROCEDURE ecocity.sp_auditoria_tabelas TO 'ecocity_role_app';
