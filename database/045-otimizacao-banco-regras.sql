-- =============================================================================
-- EcoCity — Migração 045: Otimização do banco (Functions, Views, Triggers,
--                         Procedures/Event e Índices compostos)
-- (22/11/2026)
--
-- 1. Stored Functions determinísticas (regras de negócio centralizadas no MySQL):
--    - `fn_fator_verificacao(p_tipo_verificacao, p_aceita_foto)` -> 50 / 100 / 150 (RN04/RN72/RN92)
--    - `fn_estagio_indicador(p_valor)` -> 1..4 (RN18)
--    - `fn_situacao_codigo_parceiro(p_usado_em, p_cancelado, p_expira_em, p_agora)` (RN70/RN95)
-- 2. Views:
--    - `vw_registro_detalhado`: adiciona `indicador_ambiental_id`, `aceita_foto`,
--      `aceita_parceiro` e `pontos_indicador` calculado com `fn_fator_verificacao`.
--    - `vw_impacto_jogador_unidade`: nova view multi-unidade por jogador, área e
--      unidade física (com GRANT SELECT para `ecocity_role_app` e `ecocity_role_analista`).
-- 3. Índices compostos de desempenho (confirmados via EXPLAIN):
--    - `ix_registro_jogador_status_data` em `registro_atividade (jogador_id, status, data_registro)`
--    - `ix_tentativa_jogador_data` em `tentativa_quiz (jogador_id, data)`
--    - `ix_codigo_parceiro_usado` em `codigo_parceiro (parceiro_id, usado_em)`
-- 4. Triggers (segunda barreira RN86 e sincronia em tempo real RN87):
--    - `trg_regra_pedido_atividade_parceiro` (`BEFORE INSERT ON parceiro_pedido_atividade`, RN69/RN95)
--    - `trg_regra_codigo_promo_usado_upd` e `trg_regra_codigo_promo_usado_del` (`BEFORE UPDATE/DELETE ON codigo_promocional`, RN49/RN98)
--    - Sincronia adicionada em `trg_aud_codigo_promo_resgate_ins`, `trg_aud_ajuste_admin_ins`,
--      `trg_aud_parceiro_atividade_ins`, `trg_aud_parceiro_atividade_del` e `trg_aud_administrador_*`.
-- 5. Stored Procedures e Manutenção Diária (`ev_manutencao_diaria`):
--    - `sp_resumo_jogador`: passa a consultar `vw_impacto_jogador_unidade`.
--    - `sp_limpar_tentativas_abandonadas`: amplia a faxina diária para `tentativa_codigo_parceiro`,
--      `tentativa_codigo_promocional`, `tentativa_login` com `sucesso = FALSE` (> 7 dias) e tokens
--      expirados há > 7 dias em `redefinicao_senha` / `redefinicao_senha_parceiro`.
--    - `sp_verificar_integridade`: ampliada de 6 para 10 verificações automáticas.
--
-- Aplicar em utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Stored Functions determinísticas -----------------------------------------
DELIMITER $$

DROP FUNCTION IF EXISTS fn_fator_verificacao$$
CREATE FUNCTION fn_fator_verificacao(p_tipo_verificacao INT, p_aceita_foto TINYINT(1))
RETURNS INT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_tipo_verificacao = 3 THEN 150
        WHEN p_tipo_verificacao = 1 AND p_aceita_foto = 1 THEN 50
        ELSE 100
    END;
END$$

DROP FUNCTION IF EXISTS fn_estagio_indicador$$
CREATE FUNCTION fn_estagio_indicador(p_valor INT)
RETURNS TINYINT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_valor >= 75 THEN 4
        WHEN p_valor >= 50 THEN 3
        WHEN p_valor >= 25 THEN 2
        ELSE 1
    END;
END$$

DROP FUNCTION IF EXISTS fn_situacao_codigo_parceiro$$
CREATE FUNCTION fn_situacao_codigo_parceiro(
    p_usado_em DATETIME,
    p_cancelado TINYINT(1),
    p_expira_em DATETIME,
    p_agora DATETIME
)
RETURNS VARCHAR(15) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_usado_em IS NOT NULL THEN 'usado'
        WHEN p_cancelado = 1 THEN 'cancelado'
        WHEN p_expira_em <= p_agora THEN 'vencido'
        ELSE 'aguardando'
    END;
END$$

DELIMITER ;

-- 2. Views e permissões -------------------------------------------------------
CREATE OR REPLACE VIEW vw_registro_detalhado AS
SELECT r.registro_atividade_id, r.jogador_id, r.atividade_sustentavel_id, a.nome AS atividade,
       a.categoria_ambiental_id, ai.indicador_ambiental_id, a.aceita_foto, a.aceita_parceiro,
       r.tipo_verificacao_id, tv.nome AS tipo_verificacao, r.status,
       r.data_registro, DATE(r.data_registro) AS dia, r.data_aprovacao, r.aprovado_por_administrador_id,
       r.motivo_rejeicao, r.missao_atividade_id,
       COALESCE(r.impacto_real, a.impacto_real_quantidade) AS impacto_efetivo, a.impacto_real_unidade AS unidade,
       FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN tipo_verificacao tv ON tv.tipo_verificacao_id = r.tipo_verificacao_id;

CREATE OR REPLACE VIEW vw_impacto_jogador_unidade AS
SELECT r.jogador_id,
       ai.indicador_ambiental_id,
       ia.nome AS categoria,
       a.impacto_real_unidade AS unidade,
       COUNT(*) AS acoes,
       COALESCE(SUM(r.tipo_verificacao_id = 1), 0) AS sem_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 2), 0) AS com_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 3), 0) AS com_parceiro,
       COALESCE(SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)), 0) AS impacto,
       COALESCE(SUM(FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100)), 0) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
 WHERE r.status = 'aprovado'
 GROUP BY r.jogador_id, ai.indicador_ambiental_id, ia.nome, a.impacto_real_unidade;

GRANT SELECT ON ecocity.`vw_impacto_jogador_unidade` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_impacto_jogador_unidade` TO 'ecocity_role_analista';

-- 3. Índices compostos --------------------------------------------------------
DELIMITER $$
DROP PROCEDURE IF EXISTS sp_criar_indice$$
CREATE PROCEDURE sp_criar_indice(IN p_tabela VARCHAR(64), IN p_indice VARCHAR(64), IN p_colunas VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = p_tabela AND index_name = p_indice
    ) THEN
        SET @sql = CONCAT('CREATE INDEX ', p_indice, ' ON ', p_tabela, ' (', p_colunas, ')');
        PREPARE s FROM @sql;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

CALL sp_criar_indice('registro_atividade', 'ix_registro_jogador_status_data', 'jogador_id, status, data_registro');
CALL sp_criar_indice('tentativa_quiz',     'ix_tentativa_jogador_data',       'jogador_id, data');
CALL sp_criar_indice('codigo_parceiro',    'ix_codigo_parceiro_usado',        'parceiro_id, usado_em');

DROP PROCEDURE sp_criar_indice;

-- 4. Triggers de regra de negócio e sincronia ---------------------------------
DELIMITER $$

-- RN69 / RN95: pedido de atividade nova por parceiro só aceita atividade elegível com impacto real
DROP TRIGGER IF EXISTS `trg_regra_pedido_atividade_parceiro`$$
CREATE TRIGGER `trg_regra_pedido_atividade_parceiro` BEFORE INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF COALESCE((SELECT aceita_parceiro = 1 AND impacto_real_quantidade > 0
                   FROM atividade_sustentavel
                  WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id), 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$

-- RN49 / RN98: código promocional já resgatado não pode mudar código/recompensas/início, reduzir limite abaixo do usado nem ser excluído
DROP TRIGGER IF EXISTS `trg_regra_codigo_promo_usado_upd`$$
CREATE TRIGGER `trg_regra_codigo_promo_usado_upd` BEFORE UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    DECLARE v_resgates INT DEFAULT 0;
    SELECT COUNT(*) INTO v_resgates
      FROM codigo_promocional_resgate
     WHERE codigo_promocional_id = OLD.codigo_promocional_id;
    IF v_resgates > 0 THEN
        IF NOT (OLD.codigo <=> NEW.codigo)
           OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
           OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já foi resgatado por jogadores: o código e as recompensas não podem mais mudar.';
        END IF;
        IF NOT (OLD.data_inicio <=> NEW.data_inicio) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já possui resgates: a data de início não pode mais mudar (ajuste a data de término).';
        END IF;
        IF NEW.limite_usos IS NOT NULL AND NEW.limite_usos < v_resgates THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O limite de resgates não pode ser menor que o total já resgatado.';
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_regra_codigo_promo_usado_del`$$
CREATE TRIGGER `trg_regra_codigo_promo_usado_del` BEFORE DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM codigo_promocional_resgate WHERE codigo_promocional_id = OLD.codigo_promocional_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código promocional já possui resgates e não pode ser excluído (desative-o).';
    END IF;
END$$

-- Sincronia em tempo real adicionada aos triggers de auditoria
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_resgate_ins`$$
CREATE TRIGGER `trg_aud_codigo_promo_resgate_ins` AFTER INSERT ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_resgate', NEW.resgate_id, 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id, 'jogador_id', NEW.jogador_id,
                    'xp_recebido', NEW.xp_recebido, 'credito_recebido', NEW.credito_recebido));
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    CALL marcar_sincronia('global', 0, 'admin');
END$$

DROP TRIGGER IF EXISTS `trg_aud_ajuste_admin_ins`$$
CREATE TRIGGER `trg_aud_ajuste_admin_ins` AFTER INSERT ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('ajuste_conta_admin', NEW.ajuste_admin_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'jogador_id', NEW.jogador_id, 'administrador_id', NEW.administrador_id,
                    'tipo', NEW.tipo, 'delta_xp', NEW.delta_xp, 'delta_credito', NEW.delta_credito, 'motivo', NEW.motivo));
    IF NEW.jogador_id IS NOT NULL THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
    CALL marcar_sincronia('global', 0, 'admin');
END$$

DROP TRIGGER IF EXISTS `trg_aud_parceiro_atividade_ins`$$
CREATE TRIGGER `trg_aud_parceiro_atividade_ins` AFTER INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(NEW.parceiro_id, ':', NEW.atividade_sustentavel_id), 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id));
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$

DROP TRIGGER IF EXISTS `trg_aud_parceiro_atividade_del`$$
CREATE TRIGGER `trg_aud_parceiro_atividade_del` AFTER DELETE ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(OLD.parceiro_id, ':', OLD.atividade_sustentavel_id), 'DELETE',
        JSON_OBJECT('parceiro_id', OLD.parceiro_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id), NULL);
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$

DROP TRIGGER IF EXISTS `trg_aud_administrador_ins`$$
CREATE TRIGGER `trg_aud_administrador_ins` AFTER INSERT ON `administrador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', NEW.administrador_id, 'INSERT', NULL, JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
    CALL marcar_sincronia('global', 0, 'admin');
END$$

DROP TRIGGER IF EXISTS `trg_aud_administrador_upd`$$
CREATE TRIGGER `trg_aud_administrador_upd` AFTER UPDATE ON `administrador` FOR EACH ROW
BEGIN
    IF NOT (OLD.usuario_id <=> NEW.usuario_id) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.perfil <=> NEW.perfil) THEN
        CALL registrar_auditoria('administrador', NEW.administrador_id, 'UPDATE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_aud_administrador_del`$$
CREATE TRIGGER `trg_aud_administrador_del` AFTER DELETE ON `administrador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', OLD.administrador_id, 'DELETE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), NULL);
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- 5. Stored Procedures (Manutenção Diária, Resumo do Jogador e Integridade) ---
DROP PROCEDURE IF EXISTS sp_limpar_tentativas_abandonadas$$
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

DROP PROCEDURE IF EXISTS sp_resumo_jogador$$
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

DROP PROCEDURE IF EXISTS sp_verificar_integridade$$
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

DELIMITER ;
