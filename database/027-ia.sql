-- =============================================================================
-- EcoCity — Migração 027: IA local (validação das fotos e quizzes a partir de notícias)
-- 18/11/2026 · RN04 (atualizada) e RN91
--
-- 1. analise_evidencia: parecer da IA sobre a foto de cada registro "com evidência"
--    (um por registro) e o que foi feito com ele (acao: 'aprovou' ou 'fila' do administrador). Junto com a decisão final do registro (status/aprovador),
--    forma a base rotulada para um ajuste fino futuro do modelo.
-- 2. registro_atividade.prazo_aprovacao: quando preenchido, substitui as 24 h da
--    aprovação automática (fotos que a IA achou duvidosas: envio + 72 h).
-- 3. quiz.origem / quiz.gerado_em: quizzes criados pela IA (ficam desativados até
--    o administrador revisar e ativar).
-- 4. pergunta.fonte_titulo / pergunta.fonte_url: notícia usada em cada pergunta.
-- 5. noticia_ia: notícias (RSS) já lidas e usadas, para não repetir temas.
-- 6. Sincronização (RN85): uma análise nova avisa o jogador e a administração.
-- 7. Permissões do perfil do site (ecocity_role_app) nas tabelas novas.
--
-- Aplicar como root/DBA com utf8mb4. Não altera dados existentes.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analise_evidencia` (
  `analise_evidencia_id` int NOT NULL AUTO_INCREMENT,
  `registro_atividade_id` int NOT NULL,
  `modelo` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `veredito` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `confianca` decimal(3,2) NOT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao_foto` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acao` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tempo_ms` int NOT NULL DEFAULT 0,
  `data` datetime NOT NULL,
  PRIMARY KEY (`analise_evidencia_id`),
  UNIQUE KEY `uq_analise_registro` (`registro_atividade_id`),
  KEY `ix_analise_veredito` (`veredito`, `data`),
  CONSTRAINT `fk_analise_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_analise_veredito` CHECK (`veredito` IN ('aprovar', 'duvida', 'nao_mostra', 'erro')),
  CONSTRAINT `ck_analise_confianca` CHECK (`confianca` BETWEEN 0 AND 1),
  CONSTRAINT `ck_analise_acao` CHECK (`acao` IN ('aprovou', 'fila')),
  CONSTRAINT `ck_analise_tempo` CHECK (`tempo_ms` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2 ---------------------------------------------------------------------------
ALTER TABLE `registro_atividade`
  ADD COLUMN `prazo_aprovacao` datetime DEFAULT NULL AFTER `data_registro`;

-- 3 e 4 -----------------------------------------------------------------------
ALTER TABLE `quiz`
  ADD COLUMN `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' AFTER `tipo`,
  ADD COLUMN `gerado_em` datetime DEFAULT NULL AFTER `origem`,
  ADD CONSTRAINT `ck_quiz_origem` CHECK (`origem` IN ('manual', 'ia'));

ALTER TABLE `pergunta`
  ADD COLUMN `fonte_titulo` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `enunciado`,
  ADD COLUMN `fonte_url` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `fonte_titulo`;

-- 5 ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `noticia_ia` (
  `noticia_ia_id` int NOT NULL AUTO_INCREMENT,
  `url` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fonte` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `publicada_em` datetime DEFAULT NULL,
  `lida_em` datetime NOT NULL,
  `usada_em` datetime DEFAULT NULL,
  `quiz_id` int DEFAULT NULL,
  PRIMARY KEY (`noticia_ia_id`),
  UNIQUE KEY `uq_noticia_url` (`url`),
  KEY `ix_noticia_usada` (`usada_em`, `publicada_em`),
  KEY `fk_noticia_quiz` (`quiz_id`),
  CONSTRAINT `fk_noticia_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quiz` (`quiz_id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6 ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_sinc_analise_evidencia_ins`;
DELIMITER $$
CREATE TRIGGER `trg_sinc_analise_evidencia_ins` AFTER INSERT ON `analise_evidencia` FOR EACH ROW
BEGIN
    DECLARE v_jogador INT;
    SELECT jogador_id INTO v_jogador FROM registro_atividade WHERE registro_atividade_id = NEW.registro_atividade_id;
    CALL marcar_sincronia('jogador', v_jogador, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
DELIMITER ;

-- 7 ---------------------------------------------------------------------------
GRANT SELECT, INSERT, UPDATE ON `analise_evidencia` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON `noticia_ia` TO 'ecocity_role_app';
