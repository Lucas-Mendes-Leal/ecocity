-- =============================================================================
-- EcoCity — Migração 035: Chatbot EcoGuia (histórico, cota e guardrails, 19/11/2026)
--
-- 1. Nova tabela chat_ia_mensagem para persistir as conversas do jogador com o
--    assistente EcoGuia, com ocultamento lógico (`oculta`) sem apagar a cota diária.
-- 2. Permissão do perfil do site (`ecocity_role_app`): apenas SELECT, INSERT, UPDATE
--    (sem DELETE, impedindo apagar mensagens para burlar a cota).
--
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_ia_mensagem` (
  `chat_ia_mensagem_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `papel` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `texto` varchar(2000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bloqueada` tinyint(1) NOT NULL DEFAULT '0',
  `oculta` tinyint(1) NOT NULL DEFAULT '0',
  `modelo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tempo_ms` int DEFAULT NULL,
  `criado_em` datetime NOT NULL,
  PRIMARY KEY (`chat_ia_mensagem_id`),
  KEY `ix_chat_ia_jogador_data` (`jogador_id`, `papel`, `criado_em`),
  KEY `ix_chat_ia_jogador_visivel` (`jogador_id`, `oculta`, `chat_ia_mensagem_id`),
  KEY `ix_chat_ia_global_data` (`papel`, `criado_em`),
  CONSTRAINT `fk_chat_ia_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_chat_ia_papel` CHECK (`papel` IN ('jogador', 'assistente')),
  CONSTRAINT `ck_chat_ia_categoria` CHECK (`categoria` IS NULL OR `categoria` IN ('sustentabilidade', 'tendencias', 'pontos_coleta', 'ecocity_uso', 'fora_do_escopo', 'tentativa_seguranca', 'erro')),
  CONSTRAINT `ck_chat_ia_tempo` CHECK (`tempo_ms` IS NULL OR `tempo_ms` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE ON `chat_ia_mensagem` TO 'ecocity_role_app';
