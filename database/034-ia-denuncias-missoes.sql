-- =============================================================================
-- EcoCity — Migração 034: IA nas denúncias e geração de missões (RN58, RN67, RN91, 19/11/2026)
--
-- 1. Ocultar mensagens moderadas (`removida`) mantendo o texto original como prova.
--    Trigger `trg_regra_comunidade_mensagem_texto` protege `comunidade_mensagem.texto`.
-- 2. Parecer e decisão automática da IA em `denuncia_mensagem` e `comunidade_denuncia`.
-- 3. Origem (`manual` | `ia`) e `gerado_em` em `missao` e `comunidade_missao`.
--
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 ---------------------------------------------------------------------------
ALTER TABLE `mensagem`
  ADD COLUMN `removida` tinyint(1) NOT NULL DEFAULT '0' AFTER `data_leitura`;

ALTER TABLE `comunidade_mensagem`
  ADD COLUMN `removida` tinyint(1) NOT NULL DEFAULT '0' AFTER `data_envio`;

DROP TRIGGER IF EXISTS `trg_regra_comunidade_mensagem_texto`;
DELIMITER $$
CREATE TRIGGER `trg_regra_comunidade_mensagem_texto` BEFORE UPDATE ON `comunidade_mensagem` FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mensagem enviada não pode ser editada.';
    END IF;
END$$
DELIMITER ;

-- 2 ---------------------------------------------------------------------------
ALTER TABLE `denuncia_mensagem`
  ADD COLUMN `ia_categoria` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `data_resolucao`,
  ADD COLUMN `ia_confianca` decimal(3,2) DEFAULT NULL AFTER `ia_categoria`,
  ADD COLUMN `ia_motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_confianca`,
  ADD COLUMN `ia_modelo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_motivo`,
  ADD COLUMN `ia_acao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_modelo`,
  ADD COLUMN `ia_analisada_em` datetime DEFAULT NULL AFTER `ia_acao`,
  ADD COLUMN `decidida_por_ia` tinyint(1) NOT NULL DEFAULT '0' AFTER `ia_analisada_em`,
  ADD CONSTRAINT `ck_denuncia_ia_categoria` CHECK (`ia_categoria` IS NULL OR `ia_categoria` IN ('ofensa', 'odio', 'assedio', 'sexual', 'spam', 'nao_abusiva', 'duvida', 'erro')),
  ADD CONSTRAINT `ck_denuncia_ia_confianca` CHECK (`ia_confianca` IS NULL OR (`ia_confianca` BETWEEN 0 AND 1)),
  ADD CONSTRAINT `ck_denuncia_ia_acao` CHECK (`ia_acao` IS NULL OR `ia_acao` IN ('ocultou', 'arquivou', 'fila', 'erro'));

ALTER TABLE `comunidade_denuncia`
  ADD COLUMN `ia_categoria` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `data_resolucao`,
  ADD COLUMN `ia_confianca` decimal(3,2) DEFAULT NULL AFTER `ia_categoria`,
  ADD COLUMN `ia_motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_confianca`,
  ADD COLUMN `ia_modelo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_motivo`,
  ADD COLUMN `ia_acao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ia_modelo`,
  ADD COLUMN `ia_analisada_em` datetime DEFAULT NULL AFTER `ia_acao`,
  ADD COLUMN `decidida_por_ia` tinyint(1) NOT NULL DEFAULT '0' AFTER `ia_analisada_em`,
  ADD CONSTRAINT `ck_cdenuncia_ia_categoria` CHECK (`ia_categoria` IS NULL OR `ia_categoria` IN ('ofensa', 'odio', 'assedio', 'sexual', 'spam', 'nao_abusiva', 'duvida', 'erro')),
  ADD CONSTRAINT `ck_cdenuncia_ia_confianca` CHECK (`ia_confianca` IS NULL OR (`ia_confianca` BETWEEN 0 AND 1)),
  ADD CONSTRAINT `ck_cdenuncia_ia_acao` CHECK (`ia_acao` IS NULL OR `ia_acao` IN ('ocultou', 'arquivou', 'fila', 'erro'));

-- 3 ---------------------------------------------------------------------------
ALTER TABLE `missao`
  ADD COLUMN `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' AFTER `ativa`,
  ADD COLUMN `gerado_em` datetime DEFAULT NULL AFTER `origem`,
  ADD CONSTRAINT `ck_missao_origem` CHECK (`origem` IN ('manual', 'ia'));

ALTER TABLE `comunidade_missao`
  ADD COLUMN `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' AFTER `ativa`,
  ADD COLUMN `gerado_em` datetime DEFAULT NULL AFTER `origem`,
  ADD CONSTRAINT `ck_cmissao_origem` CHECK (`origem` IN ('manual', 'ia'));
