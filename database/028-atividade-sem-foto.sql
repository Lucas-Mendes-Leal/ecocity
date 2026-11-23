-- =============================================================================
-- EcoCity — Migração 028: atividades que não têm como ter foto (RN92, 18/11/2026)
--
-- 1. atividade_sustentavel.aceita_foto (padrão TRUE). FALSE = a ação não pode ser comprovada por foto
--    (ex.: Banho Consciente — a foto não prova a duração do banho): só é registrada sem foto e paga
--    100% (valor cheio, aprovada na hora), em vez dos 50% da autodeclarada comum.
-- 2. Banho Consciente (id 2) passa a ser "sem foto" (escolha do usuário). Os registros antigos ficam
--    como estão (histórico; nada é recalculado).
-- 3. Segunda barreira no banco: não aceita registro com foto (tipo 2) para atividade sem foto.
--
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 ---------------------------------------------------------------------------
ALTER TABLE `atividade_sustentavel`
  ADD COLUMN `aceita_foto` tinyint(1) NOT NULL DEFAULT '1' AFTER `ativa`;

-- 2 ---------------------------------------------------------------------------
UPDATE `atividade_sustentavel` SET `aceita_foto` = 0 WHERE `atividade_sustentavel_id` = 2;   -- Banho Consciente

-- 3 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_regra_registro_foto`;
DELIMITER $$
CREATE TRIGGER `trg_regra_registro_foto` BEFORE INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    IF NEW.tipo_verificacao_id = 2
       AND (SELECT aceita_foto FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não tem como ser comprovada por foto: registre sem foto.';
    END IF;
END$$
DELIMITER ;
