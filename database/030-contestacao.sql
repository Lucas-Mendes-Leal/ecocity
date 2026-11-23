-- =============================================================================
-- EcoCity — Migração 030: a IA aprova e rejeita; o jogador contesta (RN94, 18/11/2026)
--
-- 1. Tabela contestacao: uma por registro rejeitado com foto, com a explicação do jogador,
--    a nova foto (opcional), o motivo original da rejeição e a decisão do administrador.
-- 2. analise_evidencia.acao passa a aceitar 'rejeitou' (a IA rejeita quando não tem certeza).
-- 3. trg_regra_registro_decidido: a única mudança permitida num registro já decidido é
--    rejeitado → aprovado, quando há uma contestação aberta dele e @ecocity_contestacao = 1
--    (ligada só na transação da decisão do administrador). Recompensa continua única.
-- 4. Sincronização (RN85): contestação nova ou decidida avisa o jogador e a administração.
-- 5. Auditoria (RN86) da contestação.
-- 6. Permissão do perfil do site (ecocity_role_app): sem DELETE (histórico).
--
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contestacao` (
  `contestacao_id` int NOT NULL AUTO_INCREMENT,
  `registro_atividade_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `texto` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `arquivo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `motivo_rejeicao_original` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `resposta` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decidida_por` int DEFAULT NULL,
  `data` datetime NOT NULL,
  `data_decisao` datetime DEFAULT NULL,
  PRIMARY KEY (`contestacao_id`),
  UNIQUE KEY `uq_contestacao_registro` (`registro_atividade_id`),
  KEY `ix_contestacao_status` (`status`, `data`),
  KEY `ix_contestacao_jogador` (`jogador_id`),
  KEY `ix_contestacao_admin` (`decidida_por`),
  CONSTRAINT `fk_contestacao_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contestacao_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contestacao_admin` FOREIGN KEY (`decidida_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_contestacao_status` CHECK (`status` IN ('aberta', 'aceita', 'negada')),
  CONSTRAINT `ck_contestacao_texto` CHECK (CHAR_LENGTH(TRIM(`texto`)) > 0),
  CONSTRAINT `ck_contestacao_decisao` CHECK (`status` = 'aberta' OR (`data_decisao` IS NOT NULL AND `decidida_por` IS NOT NULL)),
  CONSTRAINT `ck_contestacao_resposta` CHECK (`status` <> 'negada' OR CHAR_LENGTH(TRIM(IFNULL(`resposta`, ''))) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ---------------------------------------------------------------------------
ALTER TABLE `analise_evidencia` DROP CHECK `ck_analise_acao`;
ALTER TABLE `analise_evidencia` ADD CONSTRAINT `ck_analise_acao` CHECK (`acao` IN ('aprovou', 'rejeitou', 'fila'));

-- 3 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_regra_registro_decidido`;
DELIMITER $$
CREATE TRIGGER `trg_regra_registro_decidido` BEFORE UPDATE ON `registro_atividade` FOR EACH ROW
BEGIN
    IF OLD.status <> 'pendente' AND NOT (OLD.status <=> NEW.status) THEN
        IF NOT (OLD.status = 'rejeitado' AND NEW.status = 'aprovado' AND IFNULL(@ecocity_contestacao, 0) = 1
                AND EXISTS (SELECT 1 FROM contestacao c
                             WHERE c.registro_atividade_id = OLD.registro_atividade_id AND c.status = 'aberta')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro já decidido não pode mudar de situação (recompensa única).';
        END IF;
    END IF;
END$$

-- 4 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_sinc_contestacao_ins`$$
CREATE TRIGGER `trg_sinc_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
DROP TRIGGER IF EXISTS `trg_sinc_contestacao_upd`$$
CREATE TRIGGER `trg_sinc_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$

-- 5 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_aud_contestacao_ins`$$
CREATE TRIGGER `trg_aud_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'INSERT', NULL,
        JSON_OBJECT('registro_atividade_id', NEW.registro_atividade_id, 'jogador_id', NEW.jogador_id, 'status', NEW.status));
END$$
DROP TRIGGER IF EXISTS `trg_aud_contestacao_upd`$$
CREATE TRIGGER `trg_aud_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resposta <=> NEW.resposta) OR NOT (OLD.decidida_por <=> NEW.decidida_por) THEN
        CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resposta', OLD.resposta, 'decidida_por', OLD.decidida_por),
            JSON_OBJECT('status', NEW.status, 'resposta', NEW.resposta, 'decidida_por', NEW.decidida_por));
    END IF;
END$$
DELIMITER ;

-- 6 ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE ON `contestacao` TO 'ecocity_role_app';
