-- =============================================================================
-- EcoCity — Migração 038: Endurecimento de segurança (19/11/2026)
--
-- 1. Tabela tentativa_acao_ip para limite por IP (cadastro, parceiro_cadastro,
--    redefinicao de senha).
-- 2. Coluna sessao_versao em parceiro para invalidar sessões ao trocar ou
--    redefinir a senha do administrador do estabelecimento.
-- 3. Atualiza sp_limpar_tentativas_abandonadas para limpar tentativas > 7 dias.
-- 4. Permissões do perfil ecocity_role_app em tentativa_acao_ip.
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `tentativa_acao_ip` (
  `tentativa_acao_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `acao` VARCHAR(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` VARCHAR(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` DATETIME NOT NULL,
  PRIMARY KEY (`tentativa_acao_id`),
  KEY `ix_tentativa_acao_ip_data` (`acao`, `ip`, `data`),
  KEY `ix_tentativa_acao_data` (`data`),
  CONSTRAINT `ck_tentativa_acao_tipo` CHECK (`acao` IN ('cadastro', 'parceiro_cadastro', 'redefinicao'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `parceiro`
  ADD COLUMN `sessao_versao` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `data_aprovacao`;

-- Atualiza manutenção diária para limpar tentativas por IP com mais de 7 dias
DROP PROCEDURE IF EXISTS sp_limpar_tentativas_abandonadas;
DELIMITER $$
CREATE PROCEDURE sp_limpar_tentativas_abandonadas(IN p_dias INT)
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id
     WHERE t.acertos IS NULL AND t.data < CURDATE() - INTERVAL p_dias DAY;
    DELETE FROM tentativa_quiz WHERE acertos IS NULL AND data < CURDATE() - INTERVAL p_dias DAY;
    SET v_total = ROW_COUNT();
    DELETE FROM tentativa_acao_ip WHERE data < NOW() - INTERVAL 7 DAY;
    SELECT v_total AS tentativas_apagadas;
END$$
DELIMITER ;

GRANT SELECT, INSERT, DELETE ON ecocity.`tentativa_acao_ip` TO 'ecocity_role_app';
