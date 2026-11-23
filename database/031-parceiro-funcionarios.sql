-- =============================================================================
-- EcoCity — Migração 031: área do parceiro com administrador e funcionários (RN95, 18/11/2026)
--
-- 1. Tabela parceiro_funcionario: funcionários do estabelecimento (só atendem). Entram com
--    usuário + senha criados pelo administrador do estabelecimento (a conta de e-mail que já existe).
-- 2. codigo_parceiro.funcionario_id: quem gerou o código (NULL = o administrador do estabelecimento;
--    os códigos antigos ficam assim).
-- 3. Tabela parceiro_pedido_atividade: o estabelecimento pede uma atividade nova e a administração
--    do EcoCity aprova ou recusa com motivo (um pedido pendente por atividade).
-- 4. Auditoria (RN86) dos funcionários (sem o hash da senha) e dos pedidos; sincronização (RN85)
--    da administração quando um pedido nasce ou é decidido.
-- 5. Permissões do perfil do site (ecocity_role_app): sem DELETE (histórico).
--
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parceiro_funcionario` (
  `funcionario_id` int NOT NULL AUTO_INCREMENT,
  `parceiro_id` int NOT NULL,
  `nome` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `sessao_versao` int unsigned NOT NULL DEFAULT '0',
  `criado_em` datetime NOT NULL,
  `ultimo_acesso` datetime DEFAULT NULL,
  PRIMARY KEY (`funcionario_id`),
  UNIQUE KEY `uq_funcionario_usuario` (`usuario`),
  KEY `ix_funcionario_parceiro` (`parceiro_id`, `ativo`),
  CONSTRAINT `fk_funcionario_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_funcionario_usuario` CHECK (REGEXP_LIKE(`usuario`, '^[a-z0-9._-]{3,30}$', 'c')),
  CONSTRAINT `ck_funcionario_nome` CHECK (CHAR_LENGTH(TRIM(`nome`)) BETWEEN 3 AND 80)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ---------------------------------------------------------------------------
ALTER TABLE `codigo_parceiro`
  ADD COLUMN `funcionario_id` int DEFAULT NULL AFTER `parceiro_id`,
  ADD KEY `ix_codigo_funcionario` (`funcionario_id`, `criado_em`),
  ADD CONSTRAINT `fk_codigo_funcionario` FOREIGN KEY (`funcionario_id`) REFERENCES `parceiro_funcionario` (`funcionario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- 3 ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parceiro_pedido_atividade` (
  `pedido_id` int NOT NULL AUTO_INCREMENT,
  `parceiro_id` int NOT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` datetime NOT NULL,
  `decidido_por` int DEFAULT NULL,
  `data_decisao` datetime DEFAULT NULL,
  `pendente_chave` varchar(30) GENERATED ALWAYS AS (IF(`status` = 'pendente', CONCAT(`parceiro_id`, '-', `atividade_sustentavel_id`), NULL)) STORED,
  PRIMARY KEY (`pedido_id`),
  UNIQUE KEY `uq_pedido_pendente` (`pendente_chave`),
  KEY `ix_pedido_status` (`status`, `data`),
  KEY `fk_pedido_parceiro` (`parceiro_id`),
  KEY `fk_pedido_atividade` (`atividade_sustentavel_id`),
  KEY `fk_pedido_admin` (`decidido_por`),
  CONSTRAINT `fk_pedido_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pedido_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pedido_admin` FOREIGN KEY (`decidido_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_pedido_status` CHECK (`status` IN ('pendente', 'aprovado', 'recusado')),
  CONSTRAINT `ck_pedido_decisao` CHECK (`status` = 'pendente' OR (`data_decisao` IS NOT NULL AND `decidido_por` IS NOT NULL)),
  CONSTRAINT `ck_pedido_motivo` CHECK (`status` <> 'recusado' OR CHAR_LENGTH(TRIM(IFNULL(`motivo`, ''))) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4 ---------------------------------------------------------------------------
DELIMITER $$
DROP TRIGGER IF EXISTS `trg_aud_parceiro_funcionario_ins`$$
CREATE TRIGGER `trg_aud_parceiro_funcionario_ins` AFTER INSERT ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'nome', NEW.nome, 'usuario', NEW.usuario, 'ativo', NEW.ativo));
END$$
DROP TRIGGER IF EXISTS `trg_aud_parceiro_funcionario_upd`$$
CREATE TRIGGER `trg_aud_parceiro_funcionario_upd` AFTER UPDATE ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.senha <=> NEW.senha) THEN
        CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'ativo', OLD.ativo),
            JSON_OBJECT('nome', NEW.nome, 'ativo', NEW.ativo, 'senha_trocada', NOT (OLD.senha <=> NEW.senha)));
    END IF;
END$$
DROP TRIGGER IF EXISTS `trg_aud_parceiro_pedido_ins`$$
CREATE TRIGGER `trg_aud_parceiro_pedido_ins` AFTER INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'status', NEW.status));
    CALL marcar_sincronia('global', 0, 'admin');
END$$
DROP TRIGGER IF EXISTS `trg_aud_parceiro_pedido_upd`$$
CREATE TRIGGER `trg_aud_parceiro_pedido_upd` AFTER UPDATE ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status), JSON_OBJECT('status', NEW.status, 'motivo', NEW.motivo, 'decidido_por', NEW.decidido_por));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
DELIMITER ;

-- 5 ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE ON `parceiro_funcionario` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE ON `parceiro_pedido_atividade` TO 'ecocity_role_app';
