-- =============================================================================
-- EcoCity — Migração 043: Atividades realistas para verificação por parceiros
-- (RN69, 22/11/2026)
--
-- 1. Adiciona `atividade_sustentavel.aceita_parceiro` (padrão TRUE), mantendo
--    `impacto_real_quantidade` intacto nas ações domésticas para RN26.
-- 2. Desmarca como verificáveis por parceiros (aceita_parceiro = 0) as ações
--    domésticas ou sem comprovação presencial confiável em balcão:
--    2 (Banho Consciente), 3 (Luzes Apagadas), 8 (Reuso de Água em Casa),
--    9 (Torneira Fechada na Rotina), 10 (Aproveitamento de Luz Natural),
--    11 (Secagem ao Ar Livre ou Carga Cheia) e 12 (Caminhada no Lugar do Carro).
--    Permanecem verificáveis por parceiros (aceita_parceiro = 1):
--    1, 4, 5, 6, 7, 13, 14, 15 (8 atividades).
-- 3. Limpeza segura de códigos em aberto, pedidos pendentes e vínculos ativos
--    para atividades com aceita_parceiro = 0 (preservando todo histórico já usado).
-- 4. Segunda barreira no banco (RN86):
--    - `trg_regra_parceiro_atividade` e `trg_regra_codigo_parceiro`
--    - Atualização da auditoria `trg_aud_atividade_sustentavel_*` incluindo
--      `aceita_foto` e `aceita_parceiro`.
--
-- Aplicar em utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1 ---------------------------------------------------------------------------
ALTER TABLE `atividade_sustentavel`
  ADD COLUMN `aceita_parceiro` tinyint(1) NOT NULL DEFAULT '1' AFTER `aceita_foto`;

-- 2 ---------------------------------------------------------------------------
UPDATE `atividade_sustentavel`
   SET `aceita_parceiro` = 0
 WHERE `atividade_sustentavel_id` IN (2, 3, 8, 9, 10, 11, 12);

-- 3 ---------------------------------------------------------------------------
UPDATE `codigo_parceiro` k
  JOIN `atividade_sustentavel` a ON a.`atividade_sustentavel_id` = k.`atividade_sustentavel_id`
   SET k.`cancelado` = TRUE
 WHERE a.`aceita_parceiro` = 0
   AND k.`usado_em` IS NULL
   AND k.`cancelado` = FALSE;

UPDATE `parceiro_pedido_atividade` x
  JOIN `atividade_sustentavel` a ON a.`atividade_sustentavel_id` = x.`atividade_sustentavel_id`
   SET x.`status` = 'recusado',
       x.`motivo` = 'Atividade não elegível para verificação por parceiros.',
       x.`data_decisao` = NOW()
 WHERE a.`aceita_parceiro` = 0
   AND x.`status` = 'pendente';

DELETE pa FROM `parceiro_atividade` pa
  JOIN `atividade_sustentavel` a ON a.`atividade_sustentavel_id` = pa.`atividade_sustentavel_id`
 WHERE a.`aceita_parceiro` = 0;

-- 4 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_regra_parceiro_atividade`;
DROP TRIGGER IF EXISTS `trg_regra_codigo_parceiro`;
DROP TRIGGER IF EXISTS `trg_aud_atividade_sustentavel_ins`;
DROP TRIGGER IF EXISTS `trg_aud_atividade_sustentavel_upd`;
DROP TRIGGER IF EXISTS `trg_aud_atividade_sustentavel_del`;

DELIMITER $$
CREATE TRIGGER `trg_regra_parceiro_atividade` BEFORE INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    IF (SELECT `aceita_parceiro` FROM `atividade_sustentavel` WHERE `atividade_sustentavel_id` = NEW.`atividade_sustentavel_id`) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$

CREATE TRIGGER `trg_regra_codigo_parceiro` BEFORE INSERT ON `codigo_parceiro` FOR EACH ROW
BEGIN
    IF (SELECT `aceita_parceiro` FROM `atividade_sustentavel` WHERE `atividade_sustentavel_id` = NEW.`atividade_sustentavel_id`) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$

CREATE TRIGGER `trg_aud_atividade_sustentavel_ins` AFTER INSERT ON `atividade_sustentavel` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', NEW.`atividade_sustentavel_id`, 'INSERT', NULL,
        JSON_OBJECT('nome', NEW.`nome`, 'recompensa_xp', NEW.`recompensa_xp`, 'recompensa_credito', NEW.`recompensa_credito`, 'ativa', NEW.`ativa`, 'aceita_foto', NEW.`aceita_foto`, 'aceita_parceiro', NEW.`aceita_parceiro`, 'impacto_real_quantidade', NEW.`impacto_real_quantidade`));
END$$

CREATE TRIGGER `trg_aud_atividade_sustentavel_upd` AFTER UPDATE ON `atividade_sustentavel` FOR EACH ROW
BEGIN
    IF NOT (OLD.`nome` <=> NEW.`nome`)
       OR NOT (OLD.`recompensa_xp` <=> NEW.`recompensa_xp`)
       OR NOT (OLD.`recompensa_credito` <=> NEW.`recompensa_credito`)
       OR NOT (OLD.`ativa` <=> NEW.`ativa`)
       OR NOT (OLD.`aceita_foto` <=> NEW.`aceita_foto`)
       OR NOT (OLD.`aceita_parceiro` <=> NEW.`aceita_parceiro`)
       OR NOT (OLD.`impacto_real_quantidade` <=> NEW.`impacto_real_quantidade`) THEN
        CALL registrar_auditoria('atividade_sustentavel', NEW.`atividade_sustentavel_id`, 'UPDATE',
            JSON_OBJECT('nome', OLD.`nome`, 'recompensa_xp', OLD.`recompensa_xp`, 'recompensa_credito', OLD.`recompensa_credito`, 'ativa', OLD.`ativa`, 'aceita_foto', OLD.`aceita_foto`, 'aceita_parceiro', OLD.`aceita_parceiro`, 'impacto_real_quantidade', OLD.`impacto_real_quantidade`),
            JSON_OBJECT('nome', NEW.`nome`, 'recompensa_xp', NEW.`recompensa_xp`, 'recompensa_credito', NEW.`recompensa_credito`, 'ativa', NEW.`ativa`, 'aceita_foto', NEW.`aceita_foto`, 'aceita_parceiro', NEW.`aceita_parceiro`, 'impacto_real_quantidade', NEW.`impacto_real_quantidade`));
    END IF;
END$$

CREATE TRIGGER `trg_aud_atividade_sustentavel_del` AFTER DELETE ON `atividade_sustentavel` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', OLD.`atividade_sustentavel_id`, 'DELETE',
        JSON_OBJECT('nome', OLD.`nome`, 'recompensa_xp', OLD.`recompensa_xp`, 'recompensa_credito', OLD.`recompensa_credito`, 'ativa', OLD.`ativa`, 'aceita_foto', OLD.`aceita_foto`, 'aceita_parceiro', OLD.`aceita_parceiro`, 'impacto_real_quantidade', OLD.`impacto_real_quantidade`), NULL);
END$$
DELIMITER ;
