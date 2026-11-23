-- =============================================================================
-- EcoCity — Migração 041: Perfis administrativos com segregação de funções (RN102)
--
-- 1. Adiciona `administrador.perfil` ('master','moderador','editor','parceiros','analista')
--    com CHECK e padrão 'moderador'.
-- 2. Promove a conta principal admin.teste@ecocity.test para 'master' (e garante ao
--    menos um 'master' ativo no sistema).
-- 3. Atualiza `trg_aud_administrador_ins/upd/del` para registrar `perfil` em `auditoria`.
-- 4. Cria triggers `trg_regra_administrador_master_upd/del` impedindo desativar,
--    remover o perfil 'master' ou excluir o último Admin Master ativo (salvo manutenção).
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Coluna e restrição CHECK na tabela `administrador`
ALTER TABLE `administrador`
  ADD COLUMN `perfil` VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moderador' AFTER `ativo`,
  ADD CONSTRAINT `ck_administrador_perfil` CHECK (`perfil` IN ('master', 'moderador', 'editor', 'parceiros', 'analista'));

-- 2. Promove a conta principal para 'master' (e garante fallback se houver outra base)
UPDATE `administrador` a
  JOIN `usuario` u ON u.usuario_id = a.usuario_id
   SET a.perfil = 'master'
 WHERE u.email = 'admin.teste@ecocity.test';

UPDATE `administrador` a
  JOIN (
      SELECT MIN(administrador_id) AS id
        FROM administrador
       WHERE ativo = TRUE
         AND NOT EXISTS (SELECT 1 FROM administrador m WHERE m.ativo = TRUE AND m.perfil = 'master')
  ) fallback ON fallback.id = a.administrador_id
   SET a.perfil = 'master';

-- 3. Atualiza triggers de auditoria e cria trava do último Admin Master ativo
DROP TRIGGER IF EXISTS `trg_aud_administrador_ins`;
DROP TRIGGER IF EXISTS `trg_aud_administrador_upd`;
DROP TRIGGER IF EXISTS `trg_aud_administrador_del`;
DROP TRIGGER IF EXISTS `trg_regra_administrador_master_upd`;
DROP TRIGGER IF EXISTS `trg_regra_administrador_master_del`;

DELIMITER $$
CREATE TRIGGER `trg_aud_administrador_ins` AFTER INSERT ON `administrador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', NEW.administrador_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
END$$

CREATE TRIGGER `trg_aud_administrador_upd` AFTER UPDATE ON `administrador` FOR EACH ROW
BEGIN
    IF NOT (OLD.usuario_id <=> NEW.usuario_id) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.perfil <=> NEW.perfil) THEN
        CALL registrar_auditoria('administrador', NEW.administrador_id, 'UPDATE',
            JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil),
            JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
    END IF;
END$$

CREATE TRIGGER `trg_aud_administrador_del` AFTER DELETE ON `administrador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', OLD.administrador_id, 'DELETE',
        JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), NULL);
END$$

CREATE TRIGGER `trg_regra_administrador_master_upd` BEFORE UPDATE ON `administrador` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0
       AND OLD.ativo = TRUE
       AND OLD.perfil = 'master'
       AND (NEW.ativo = FALSE OR NEW.perfil <> 'master')
       AND NOT EXISTS (
           SELECT 1
             FROM administrador
            WHERE ativo = TRUE AND perfil = 'master' AND administrador_id <> OLD.administrador_id
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'É obrigatório manter pelo menos um Admin Master ativo no sistema.';
    END IF;
END$$

CREATE TRIGGER `trg_regra_administrador_master_del` BEFORE DELETE ON `administrador` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0
       AND OLD.ativo = TRUE
       AND OLD.perfil = 'master'
       AND NOT EXISTS (
           SELECT 1
             FROM administrador
            WHERE ativo = TRUE AND perfil = 'master' AND administrador_id <> OLD.administrador_id
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'É obrigatório manter pelo menos um Admin Master ativo no sistema.';
    END IF;
END$$
DELIMITER ;
