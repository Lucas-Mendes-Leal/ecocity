-- =============================================================================
-- EcoCity — Migração 046: Política configurável de publicação da IA (RN91)
--
-- 1. Cria a tabela `configuracao_sistema` para armazenar configurações globais
--    da administração, iniciando com a regra independente de publicação para
--    Quizzes, Missões semanais e Missões oficiais de comunidade gerados por IA:
--      - modo:  'manual' (padrão) | 'imediato' | 'agendado'
--      - horas: 6 | 12 | 24 | 48 | 72 (carência no modo 'agendado')
-- 2. Adiciona `ativar_em` + índice `(ativa, ativar_em)` em `quiz`, `missao` e
--    `comunidade_missao` para ativação automática após o prazo de revisão.
-- 3. Concede SELECT, INSERT, UPDATE em `configuracao_sistema` para `ecocity_role_app`.
-- 4. Cria triggers de auditoria `trg_aud_configuracao_sistema_ins/upd`.
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Tabela de configurações globais da aplicação
CREATE TABLE IF NOT EXISTS `configuracao_sistema` (
  `configuracao_sistema_id` int NOT NULL AUTO_INCREMENT,
  `chave` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `atualizado_por` int DEFAULT NULL,
  PRIMARY KEY (`configuracao_sistema_id`),
  UNIQUE KEY `uq_configuracao_sistema_chave` (`chave`),
  KEY `fk_config_admin` (`atualizado_por`),
  CONSTRAINT `fk_config_admin` FOREIGN KEY (`atualizado_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `configuracao_sistema` (`chave`, `valor`) VALUES
  ('ia_pub_quiz_modo', 'manual'),
  ('ia_pub_quiz_horas', '24'),
  ('ia_pub_missao_modo', 'manual'),
  ('ia_pub_missao_horas', '24'),
  ('ia_pub_cmissao_modo', 'manual'),
  ('ia_pub_cmissao_horas', '24');

-- 2. Agendamento automático de ativação em conteúdos gerados pela IA
ALTER TABLE `quiz`
  ADD COLUMN `ativar_em` datetime DEFAULT NULL AFTER `gerado_em`,
  ADD KEY `ix_quiz_ativar_em` (`ativa`, `ativar_em`);

ALTER TABLE `missao`
  ADD COLUMN `ativar_em` datetime DEFAULT NULL AFTER `gerado_em`,
  ADD KEY `ix_missao_ativar_em` (`ativa`, `ativar_em`);

ALTER TABLE `comunidade_missao`
  ADD COLUMN `ativar_em` datetime DEFAULT NULL AFTER `gerado_em`,
  ADD KEY `ix_cmissao_ativar_em` (`ativa`, `ativar_em`);

-- 3. Permissão mínima ao perfil da aplicação PHP
GRANT SELECT, INSERT, UPDATE ON ecocity.`configuracao_sistema` TO 'ecocity_role_app';

-- 4. Triggers de auditoria da tabela `configuracao_sistema`
DROP TRIGGER IF EXISTS `trg_aud_configuracao_sistema_ins`;
DROP TRIGGER IF EXISTS `trg_aud_configuracao_sistema_upd`;

DELIMITER $$
CREATE TRIGGER `trg_aud_configuracao_sistema_ins` AFTER INSERT ON `configuracao_sistema` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('configuracao_sistema', NEW.configuracao_sistema_id, 'INSERT', NULL,
        JSON_OBJECT('chave', NEW.chave, 'valor', NEW.valor, 'atualizado_por', NEW.atualizado_por));
END$$

CREATE TRIGGER `trg_aud_configuracao_sistema_upd` AFTER UPDATE ON `configuracao_sistema` FOR EACH ROW
BEGIN
    IF NOT (OLD.chave <=> NEW.chave) OR NOT (OLD.valor <=> NEW.valor) OR NOT (OLD.atualizado_por <=> NEW.atualizado_por) THEN
        CALL registrar_auditoria('configuracao_sistema', NEW.configuracao_sistema_id, 'UPDATE',
            JSON_OBJECT('chave', OLD.chave, 'valor', OLD.valor, 'atualizado_por', OLD.atualizado_por),
            JSON_OBJECT('chave', NEW.chave, 'valor', NEW.valor, 'atualizado_por', NEW.atualizado_por));
    END IF;
END$$
DELIMITER ;
