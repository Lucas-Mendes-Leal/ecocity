-- =============================================================================
-- EcoCity — 01. Criação do banco e de todas as tabelas (estado atual, 18/11/2026, até a migração 032)
-- 57 tabelas em 11 módulos, com chaves primárias, estrangeiras (ON DELETE/ON UPDATE explícitos),
-- NOT NULL, UNIQUE, DEFAULT e CHECK. InnoDB e utf8mb4 (acentos do PT-BR).
-- Ordem de execução: 01 → 02 (índices) → 03 (views) → 04 (triggers) → 05 (procedures) → 06 (dados) → 08 (segurança).
-- As chaves estrangeiras usam RESTRICT: nada é apagado em cascata; o PHP (e sp_apagar_demonstracao)
-- apaga os dependentes antes, o que impede perder históricos por engano.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS ecocity CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ecocity;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;   -- as tabelas se referenciam entre módulos; as FKs valem a partir do fim do script


-- -----------------------------------------------------------------------------
-- Conta, segurança e administração
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuario` (
  `usuario_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_cadastro` date NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `demonstracao` tinyint(1) NOT NULL DEFAULT '0',
  `sessao_versao` int unsigned NOT NULL DEFAULT '0',
  `desativada_em` datetime DEFAULT NULL,
  PRIMARY KEY (`usuario_id`),
  UNIQUE KEY `uq_usuario_email` (`email`),
  KEY `ix_usuario_demonstracao` (`demonstracao`),
  CONSTRAINT `ck_usuario_email` CHECK ((`email` like _utf8mb4'%_@_%._%'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `administrador` (
  `administrador_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `data_cadastro` date NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `perfil` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moderador',
  PRIMARY KEY (`administrador_id`),
  UNIQUE KEY `uq_administrador_usuario` (`usuario_id`),
  CONSTRAINT `fk_administrador_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`usuario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_administrador_perfil` CHECK (`perfil` IN ('master','moderador','editor','parceiros','analista'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tentativa_login` (
  `tentativa_login_id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` datetime NOT NULL,
  `sucesso` tinyint(1) NOT NULL,
  PRIMARY KEY (`tentativa_login_id`),
  KEY `ix_tentativa_login_email` (`email`,`data`),
  KEY `ix_tentativa_login_ip` (`ip`,`data`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tentativa_acao_ip` (
  `tentativa_acao_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `acao` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`tentativa_acao_id`),
  KEY `ix_tentativa_acao_ip_data` (`acao`,`ip`,`data`),
  KEY `ix_tentativa_acao_data` (`data`),
  CONSTRAINT `ck_tentativa_acao_tipo` CHECK ((`acao` in (_utf8mb4'cadastro',_utf8mb4'parceiro_cadastro',_utf8mb4'redefinicao')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `redefinicao_senha` (
  `redefinicao_senha_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_criacao` datetime NOT NULL,
  `data_expiracao` datetime NOT NULL,
  `data_uso` datetime DEFAULT NULL,
  PRIMARY KEY (`redefinicao_senha_id`),
  UNIQUE KEY `uq_redefinicao_token` (`token_hash`),
  KEY `ix_redefinicao_usuario` (`usuario_id`,`data_criacao`),
  CONSTRAINT `fk_redefinicao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`usuario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `acesso_diario` (
  `jogador_id` int NOT NULL,
  `data` date NOT NULL,
  PRIMARY KEY (`jogador_id`,`data`),
  KEY `ix_acesso_data` (`data`),
  CONSTRAINT `fk_acesso_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Jogador e progressão
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nivel_jogador` (
  `nivel_jogador_id` int NOT NULL AUTO_INCREMENT,
  `nivel` int NOT NULL,
  `xp_minimo` int NOT NULL,
  `xp_maximo` int NOT NULL,
  PRIMARY KEY (`nivel_jogador_id`),
  UNIQUE KEY `uq_nivel_jogador_xp_minimo` (`xp_minimo`),
  CONSTRAINT `ck_nivel_jogador_faixa` CHECK ((`xp_maximo` >= `xp_minimo`))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `jogador` (
  `jogador_id` int NOT NULL AUTO_INCREMENT,
  `apelido` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `numero_conta` varchar(18) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_criacao` date NOT NULL,
  `nivel_id` int NOT NULL,
  `creditos_verde` int NOT NULL,
  `xp_atual` int NOT NULL,
  `usuario_id` int NOT NULL,
  `saiu_comunidade_em` datetime DEFAULT NULL,
  `tutorial_visto` datetime DEFAULT NULL,
  `avatar_icone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_cor` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aparece_sugestoes` tinyint(1) NOT NULL DEFAULT '1',
  `aceita_pedidos` tinyint(1) NOT NULL DEFAULT '1',
  `permite_visita` tinyint(1) NOT NULL DEFAULT '1',
  `som_mudo` tinyint(1) NOT NULL DEFAULT '0',
  `som_efeitos` tinyint(1) NOT NULL DEFAULT '1',
  `som_interface` tinyint(1) NOT NULL DEFAULT '1',
  `som_ambiente` tinyint(1) NOT NULL DEFAULT '0',
  `som_volume` tinyint unsigned DEFAULT NULL,
  `som_volume_efeitos` tinyint unsigned DEFAULT NULL,
  `som_volume_interface` tinyint unsigned DEFAULT NULL,
  `som_volume_ambiente` tinyint unsigned DEFAULT NULL,
  PRIMARY KEY (`jogador_id`),
  UNIQUE KEY `uq_jogador_numero_conta` (`numero_conta`),
  UNIQUE KEY `uq_jogador_usuario` (`usuario_id`),
  KEY `fk_jogador_nivel` (`nivel_id`),
  CONSTRAINT `fk_jogador_nivel` FOREIGN KEY (`nivel_id`) REFERENCES `nivel_jogador` (`nivel_jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_jogador_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`usuario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_jogador_creditos_nao_negativos` CHECK ((`creditos_verde` >= 0)),
  CONSTRAINT `ck_jogador_xp` CHECK ((`xp_atual` >= 0)),
  CONSTRAINT `ck_jogador_som_volume` CHECK (`som_volume` IS NULL OR `som_volume` <= 100),
  CONSTRAINT `ck_jogador_som_vol_efeitos` CHECK (`som_volume_efeitos` IS NULL OR `som_volume_efeitos` <= 100),
  CONSTRAINT `ck_jogador_som_vol_interface` CHECK (`som_volume_interface` IS NULL OR `som_volume_interface` <= 100),
  CONSTRAINT `ck_jogador_som_vol_ambiente` CHECK (`som_volume_ambiente` IS NULL OR `som_volume_ambiente` <= 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `historico_xp` (
  `historico_xp_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `quantidade` int NOT NULL,
  `origem` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` datetime NOT NULL,
  `registro_atividade_id` int DEFAULT NULL,
  `tentativa_id` int DEFAULT NULL,
  `conquista_id` int DEFAULT NULL,
  `missao_id` int DEFAULT NULL,
  `comunidade_missao_id` int DEFAULT NULL,
  `codigo_promocional_id` int DEFAULT NULL,
  `ajuste_admin_id` int DEFAULT NULL,
  PRIMARY KEY (`historico_xp_id`),
  KEY `fk_hist_xp_registro` (`registro_atividade_id`),
  KEY `fk_hist_xp_tentativa` (`tentativa_id`),
  KEY `fk_hist_xp_conquista` (`conquista_id`),
  KEY `fk_hist_xp_missao` (`missao_id`),
  KEY `fk_hist_xp_cmissao` (`comunidade_missao_id`),
  KEY `fk_hist_xp_promo` (`codigo_promocional_id`),
  KEY `fk_hist_xp_ajuste_admin` (`ajuste_admin_id`),
  CONSTRAINT `fk_hist_xp_ajuste_admin` FOREIGN KEY (`ajuste_admin_id`) REFERENCES `ajuste_conta_admin` (`ajuste_admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_cmissao` FOREIGN KEY (`comunidade_missao_id`) REFERENCES `comunidade_missao` (`comunidade_missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_conquista` FOREIGN KEY (`conquista_id`) REFERENCES `conquista` (`conquista_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_missao` FOREIGN KEY (`missao_id`) REFERENCES `missao` (`missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_promo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_xp_tentativa` FOREIGN KEY (`tentativa_id`) REFERENCES `tentativa_quiz` (`tentativa_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_historico_xp_quantidade` CHECK ((`quantidade` <> 0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `historico_credito_verde` (
  `historico_credito_verde_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `quantidade` int NOT NULL,
  `origem` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` datetime NOT NULL,
  `registro_atividade_id` int DEFAULT NULL,
  `tentativa_id` int DEFAULT NULL,
  `conquista_id` int DEFAULT NULL,
  `missao_id` int DEFAULT NULL,
  `construcao_id` int DEFAULT NULL,
  `comunidade_missao_id` int DEFAULT NULL,
  `comunidade_id` int DEFAULT NULL,
  `codigo_promocional_id` int DEFAULT NULL,
  `ajuste_admin_id` int DEFAULT NULL,
  PRIMARY KEY (`historico_credito_verde_id`),
  KEY `fk_hist_cred_registro` (`registro_atividade_id`),
  KEY `fk_hist_cred_tentativa` (`tentativa_id`),
  KEY `fk_hist_cred_conquista` (`conquista_id`),
  KEY `fk_hist_cred_missao` (`missao_id`),
  KEY `fk_hist_cred_construcao` (`construcao_id`),
  KEY `fk_hist_cred_cmissao` (`comunidade_missao_id`),
  KEY `fk_hist_cred_comunidade` (`comunidade_id`),
  KEY `fk_hist_cred_promo` (`codigo_promocional_id`),
  KEY `fk_hist_cred_ajuste_admin` (`ajuste_admin_id`),
  CONSTRAINT `fk_hist_cred_ajuste_admin` FOREIGN KEY (`ajuste_admin_id`) REFERENCES `ajuste_conta_admin` (`ajuste_admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_cmissao` FOREIGN KEY (`comunidade_missao_id`) REFERENCES `comunidade_missao` (`comunidade_missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_conquista` FOREIGN KEY (`conquista_id`) REFERENCES `conquista` (`conquista_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_construcao` FOREIGN KEY (`construcao_id`) REFERENCES `construcao` (`construcao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_missao` FOREIGN KEY (`missao_id`) REFERENCES `missao` (`missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_promo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_hist_cred_tentativa` FOREIGN KEY (`tentativa_id`) REFERENCES `tentativa_quiz` (`tentativa_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_historico_credito_quantidade` CHECK ((`quantidade` <> 0))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Cidade e indicadores ambientais
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categoria_ambiental` (
  `categoria_ambiental_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`categoria_ambiental_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `indicador_ambiental` (
  `indicador_ambiental_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `valor_maximo` int NOT NULL,
  `valor_minimo` int NOT NULL,
  `categoria_ambiental_id` int NOT NULL,
  PRIMARY KEY (`indicador_ambiental_id`),
  UNIQUE KEY `uq_indicador_categoria` (`categoria_ambiental_id`),
  CONSTRAINT `fk_indicador_categoria` FOREIGN KEY (`categoria_ambiental_id`) REFERENCES `categoria_ambiental` (`categoria_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_indicador_faixa` CHECK ((`valor_minimo` < `valor_maximo`))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `nivel_cidade` (
  `nivel_cidade_id` int NOT NULL AUTO_INCREMENT,
  `nivel` int NOT NULL,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pontuacao_minima` int NOT NULL,
  PRIMARY KEY (`nivel_cidade_id`),
  CONSTRAINT `ck_nivel_cidade_pontuacao` CHECK ((`pontuacao_minima` between 0 and 100))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `regiao_cidade` (
  `regiao_cidade_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `prefixo_imagem` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`regiao_cidade_id`),
  KEY `fk_regiao_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_regiao_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cidade` (
  `cidade_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nivel_cidade_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `ultima_queda` date NOT NULL DEFAULT (curdate()),
  PRIMARY KEY (`cidade_id`),
  UNIQUE KEY `uq_cidade_jogador` (`jogador_id`),
  KEY `fk_cidade_nivel` (`nivel_cidade_id`),
  CONSTRAINT `fk_cidade_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cidade_nivel` FOREIGN KEY (`nivel_cidade_id`) REFERENCES `nivel_cidade` (`nivel_cidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cidade_indicador` (
  `cidade_id` int NOT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `valor` int NOT NULL,
  PRIMARY KEY (`cidade_id`,`indicador_ambiental_id`),
  KEY `fk_cidade_indicador_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_cidade_indicador_cidade` FOREIGN KEY (`cidade_id`) REFERENCES `cidade` (`cidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cidade_indicador_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_cidade_indicador_valor` CHECK ((`valor` between 0 and 100))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `historico_indicador_cidade` (
  `historico_indicador_id` int NOT NULL AUTO_INCREMENT,
  `cidade_id` int NOT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `valor` int NOT NULL,
  `data` datetime NOT NULL,
  `origem` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`historico_indicador_id`),
  CONSTRAINT `fk_hist_indicador_cidade_indicador` FOREIGN KEY (`cidade_id`, `indicador_ambiental_id`) REFERENCES `cidade_indicador` (`cidade_id`, `indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Atividades sustentáveis, registros e evidências
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tipo_verificacao` (
  `tipo_verificacao_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nivel_confiabilidade` int NOT NULL,
  PRIMARY KEY (`tipo_verificacao_id`),
  CONSTRAINT `ck_tipo_verificacao_confiabilidade` CHECK ((`nivel_confiabilidade` between 1 and 3))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `atividade_sustentavel` (
  `atividade_sustentavel_id` int NOT NULL AUTO_INCREMENT,
  `categoria_ambiental_id` int NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recompensa_xp` int NOT NULL,
  `recompensa_credito` int NOT NULL,
  `impacto_real_quantidade` decimal(10,2) DEFAULT NULL,
  `impacto_real_unidade` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `aceita_foto` tinyint(1) NOT NULL DEFAULT '1',
  `aceita_parceiro` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`atividade_sustentavel_id`),
  UNIQUE KEY `uq_atividade_nome` (`nome`),
  KEY `fk_atividade_categoria` (`categoria_ambiental_id`),
  CONSTRAINT `fk_atividade_categoria` FOREIGN KEY (`categoria_ambiental_id`) REFERENCES `categoria_ambiental` (`categoria_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_atividade_impacto_real` CHECK ((((`impacto_real_quantidade` is null) and (`impacto_real_unidade` is null)) or ((`impacto_real_quantidade` > 0) and (`impacto_real_unidade` is not null)))),
  CONSTRAINT `ck_atividade_recompensa` CHECK (((`recompensa_xp` >= 0) and (`recompensa_credito` >= 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `atividade_indicador_ambiental` (
  `atividade_sustentavel_id` int NOT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `impacto` int NOT NULL,
  PRIMARY KEY (`atividade_sustentavel_id`,`indicador_ambiental_id`),
  KEY `fk_ativ_ind_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_ativ_ind_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ativ_ind_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_atividade_indicador_impacto` CHECK ((`impacto` > 0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tipo_missao` (
  `tipo_missao_id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`tipo_missao_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `missao` (
  `missao_id` int NOT NULL AUTO_INCREMENT,
  `tipo_missao_id` int NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recompensa_xp` int NOT NULL,
  `recompensa_credito` int NOT NULL,
  `penalidade_indicador` int NOT NULL DEFAULT '0',
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `gerado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`missao_id`),
  UNIQUE KEY `uq_missao_nome` (`nome`),
  KEY `fk_missao_tipo` (`tipo_missao_id`),
  CONSTRAINT `fk_missao_tipo` FOREIGN KEY (`tipo_missao_id`) REFERENCES `tipo_missao` (`tipo_missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_missao_origem` CHECK ((`origem` in (_utf8mb4'manual',_utf8mb4'ia'))),
  CONSTRAINT `ck_missao_periodo` CHECK (((`data_fim` is null) or (`data_fim` >= `data_inicio`))),
  CONSTRAINT `ck_missao_recompensa` CHECK (((`recompensa_xp` >= 0) and (`recompensa_credito` >= 0) and (`penalidade_indicador` >= 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `missao_atividade` (
  `missao_atividade_id` int NOT NULL AUTO_INCREMENT,
  `missao_id` int NOT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `quantidade_necessaria` int NOT NULL,
  PRIMARY KEY (`missao_atividade_id`),
  KEY `fk_missao_atividade_missao` (`missao_id`),
  KEY `fk_missao_atividade_atividade` (`atividade_sustentavel_id`),
  CONSTRAINT `fk_missao_atividade_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_missao_atividade_missao` FOREIGN KEY (`missao_id`) REFERENCES `missao` (`missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_missao_atividade_quantidade` CHECK ((`quantidade_necessaria` > 0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `registro_atividade` (
  `registro_atividade_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `missao_atividade_id` int DEFAULT NULL,
  `tipo_verificacao_id` int NOT NULL,
  `data_registro` datetime NOT NULL,
  `prazo_aprovacao` datetime DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `motivo_rejeicao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aprovado_por_administrador_id` int DEFAULT NULL,
  `data_aprovacao` datetime DEFAULT NULL,
  `impacto_real` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`registro_atividade_id`),
  KEY `fk_registro_atividade` (`atividade_sustentavel_id`),
  KEY `fk_registro_missao_atividade` (`missao_atividade_id`),
  KEY `fk_registro_administrador` (`aprovado_por_administrador_id`),
  KEY `ix_registro_status_data` (`status`,`data_registro`),
  CONSTRAINT `fk_registro_administrador` FOREIGN KEY (`aprovado_por_administrador_id`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_registro_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_registro_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_registro_missao_atividade` FOREIGN KEY (`missao_atividade_id`) REFERENCES `missao_atividade` (`missao_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_registro_tipo_verificacao` FOREIGN KEY (`tipo_verificacao_id`) REFERENCES `tipo_verificacao` (`tipo_verificacao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_registro_aprovado_data` CHECK (((`status` <> _utf8mb4'aprovado') or (`data_aprovacao` is not null))),
  CONSTRAINT `ck_registro_impacto_real` CHECK (((`impacto_real` is null) or (`impacto_real` > 0))),
  CONSTRAINT `ck_registro_rejeitado_motivo` CHECK (((`status` <> _utf8mb4'rejeitado') or ((`motivo_rejeicao` is not null) and (`motivo_rejeicao` <> _utf8mb4'')))),
  CONSTRAINT `ck_registro_status` CHECK ((`status` in (_utf8mb4'pendente',_utf8mb4'aprovado',_utf8mb4'rejeitado')))
) ENGINE=InnoDB;

-- Parecer da IA sobre a foto (RN91, migração 027): base rotulada para ajuste fino futuro
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
  CONSTRAINT `ck_analise_acao` CHECK (`acao` IN ('aprovou', 'rejeitou', 'fila')),
  CONSTRAINT `ck_analise_tempo` CHECK (`tempo_ms` >= 0)
)  ENGINE=InnoDB;

-- RN94 (migração 030): contestação de registro rejeitado
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
)  ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `evidencia_atividade` (
  `evidencia_atividade_id` int NOT NULL AUTO_INCREMENT,
  `registro_atividade_id` int NOT NULL,
  `arquivo` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_envio` date NOT NULL,
  `descricao` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`evidencia_atividade_id`),
  KEY `fk_evidencia_registro` (`registro_atividade_id`),
  CONSTRAINT `fk_evidencia_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `jogador_missao` (
  `jogador_missao_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `missao_id` int NOT NULL,
  `periodo_inicio` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_resultado` datetime NOT NULL,
  PRIMARY KEY (`jogador_missao_id`),
  UNIQUE KEY `uq_jogador_missao_periodo` (`jogador_id`,`missao_id`,`periodo_inicio`),
  KEY `fk_jogador_missao_missao` (`missao_id`),
  CONSTRAINT `fk_jogador_missao_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_jogador_missao_missao` FOREIGN KEY (`missao_id`) REFERENCES `missao` (`missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_jogador_missao_status` CHECK ((`status` in (_cp850'concluida',_cp850'nao_concluida')))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Quizzes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `quiz` (
  `quiz_id` int NOT NULL AUTO_INCREMENT,
  `titulo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria_ambiental_id` int NOT NULL,
  `recompensa_xp` int NOT NULL,
  `recompensa_credito` int NOT NULL,
  `dificuldade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `tipo` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tematico',
  `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `gerado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`quiz_id`),
  KEY `fk_quiz_categoria` (`categoria_ambiental_id`),
  CONSTRAINT `fk_quiz_categoria` FOREIGN KEY (`categoria_ambiental_id`) REFERENCES `categoria_ambiental` (`categoria_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_quiz_tipo` CHECK ((`tipo` in (_utf8mb4'diario',_utf8mb4'semanal',_utf8mb4'tematico'))),
  CONSTRAINT `ck_quiz_dificuldade` CHECK ((`dificuldade` in (_utf8mb4'Fácil',_utf8mb4'Médio',_utf8mb4'Difícil'))),
  CONSTRAINT `ck_quiz_recompensa` CHECK (((`recompensa_xp` >= 0) and (`recompensa_credito` >= 0))),
  CONSTRAINT `ck_quiz_origem` CHECK ((`origem` in (_utf8mb4'manual',_utf8mb4'ia')))
) ENGINE=InnoDB;

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
)  ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `pergunta` (
  `pergunta_id` int NOT NULL AUTO_INCREMENT,
  `quiz_id` int NOT NULL,
  `enunciado` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fonte_titulo` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fonte_url` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`pergunta_id`),
  KEY `fk_pergunta_quiz` (`quiz_id`),
  CONSTRAINT `fk_pergunta_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quiz` (`quiz_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `alternativa` (
  `alternativa_id` int NOT NULL AUTO_INCREMENT,
  `pergunta_id` int NOT NULL,
  `texto` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `correta` tinyint(1) NOT NULL DEFAULT '0',
  `explicacao` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`alternativa_id`),
  KEY `fk_alternativa_pergunta` (`pergunta_id`),
  CONSTRAINT `fk_alternativa_pergunta` FOREIGN KEY (`pergunta_id`) REFERENCES `pergunta` (`pergunta_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tentativa_quiz` (
  `tentativa_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `quiz_id` int NOT NULL,
  `data` date NOT NULL,
  `acertos` int DEFAULT NULL,
  `pontuacao` int DEFAULT NULL,
  PRIMARY KEY (`tentativa_id`),
  KEY `fk_tentativa_quiz` (`quiz_id`),
  CONSTRAINT `fk_tentativa_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_tentativa_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quiz` (`quiz_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_tentativa_resultado` CHECK ((((`acertos` is null) or (`acertos` >= 0)) and ((`pontuacao` is null) or (`pontuacao` >= 0))))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `resposta_quiz` (
  `resposta_quiz_id` int NOT NULL AUTO_INCREMENT,
  `tentativa_id` int NOT NULL,
  `pergunta_id` int NOT NULL,
  `alternativa_id` int NOT NULL,
  `correta` tinyint(1) NOT NULL,
  PRIMARY KEY (`resposta_quiz_id`),
  UNIQUE KEY `uq_resposta_tentativa_pergunta` (`tentativa_id`, `pergunta_id`),
  KEY `fk_resposta_tentativa` (`tentativa_id`),
  KEY `fk_resposta_pergunta` (`pergunta_id`),
  KEY `fk_resposta_alternativa` (`alternativa_id`),
  CONSTRAINT `fk_resposta_alternativa` FOREIGN KEY (`alternativa_id`) REFERENCES `alternativa` (`alternativa_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_resposta_pergunta` FOREIGN KEY (`pergunta_id`) REFERENCES `pergunta` (`pergunta_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_resposta_tentativa` FOREIGN KEY (`tentativa_id`) REFERENCES `tentativa_quiz` (`tentativa_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Conquistas
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conquista` (
  `conquista_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recompensa_xp` int NOT NULL,
  `recompensa_credito` int NOT NULL,
  `tipo_criterio` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor_criterio` int NOT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`conquista_id`),
  CONSTRAINT `ck_conquista_valores` CHECK (((`recompensa_xp` >= 0) and (`recompensa_credito` >= 0) and (`valor_criterio` > 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `jogador_conquista` (
  `conquista_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `data_conquista` date NOT NULL,
  PRIMARY KEY (`conquista_id`,`jogador_id`),
  CONSTRAINT `fk_jogador_conquista_conquista` FOREIGN KEY (`conquista_id`) REFERENCES `conquista` (`conquista_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_jogador_conquista_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Construção nos lotes
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `construcao` (
  `construcao_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `largura` int NOT NULL,
  `profundidade` int NOT NULL,
  `custo` int NOT NULL,
  `nivel_cidade_minimo` int NOT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `categoria` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ecologica',
  `efeito` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indicador_ambiental_id` int DEFAULT NULL,
  `desenho` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aparencia` json DEFAULT NULL,
  `moradores` tinyint unsigned NOT NULL DEFAULT '0',
  `carros` tinyint unsigned NOT NULL DEFAULT '0',
  `bicicletas` tinyint unsigned NOT NULL DEFAULT '0',
  `onibus` tinyint unsigned NOT NULL DEFAULT '0',
  `renda_diaria` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`construcao_id`),
  KEY `fk_construcao_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_construcao_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_construcao_categoria` CHECK ((`categoria` in (_utf8mb4'ecologica',_utf8mb4'vias',_utf8mb4'comercio',_utf8mb4'servicos',_utf8mb4'decoracao'))),
  CONSTRAINT `ck_construcao_custo` CHECK ((`custo` >= 0)),
  CONSTRAINT `ck_construcao_efeito` CHECK ((((`efeito` is null) and (`indicador_ambiental_id` is null)) or ((`efeito` in (_utf8mb4'polui',_utf8mb4'compensa')) and (`indicador_ambiental_id` is not null)))),
  CONSTRAINT `ck_construcao_efeitos` CHECK (((`moradores` <= 20) and (`carros` <= 5) and (`bicicletas` <= 5) and (`onibus` <= 5) and (`renda_diaria` <= 20))),
  CONSTRAINT `ck_construcao_renda_comercio` CHECK (((`renda_diaria` = 0) or (`categoria` = _utf8mb4'comercio'))),
  CONSTRAINT `ck_construcao_tamanho` CHECK (((`largura` between 1 and 20) and (`profundidade` between 1 and 20)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `cidade_construcao` (
  `cidade_construcao_id` int NOT NULL AUTO_INCREMENT,
  `cidade_id` int NOT NULL,
  `construcao_id` int NOT NULL,
  `lote` int NOT NULL,
  `x` int NOT NULL,
  `y` int NOT NULL,
  `rotacao` tinyint NOT NULL DEFAULT '0',
  `data_construcao` datetime NOT NULL,
  `renda_coletada_em` datetime NOT NULL,
  PRIMARY KEY (`cidade_construcao_id`),
  KEY `ix_cidade_construcao_lote` (`cidade_id`,`lote`),
  KEY `fk_cidade_construcao_construcao` (`construcao_id`),
  CONSTRAINT `fk_cidade_construcao_cidade` FOREIGN KEY (`cidade_id`) REFERENCES `cidade` (`cidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cidade_construcao_construcao` FOREIGN KEY (`construcao_id`) REFERENCES `construcao` (`construcao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_cidade_construcao_lote` CHECK ((`lote` between 2 and 4)),
  CONSTRAINT `ck_cidade_construcao_pos` CHECK (((`x` between 0 and 19) and (`y` between 0 and 19))),
  CONSTRAINT `ck_cidade_construcao_rotacao` CHECK ((`rotacao` between 0 and 3))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Amigos e mensagens
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `amizade` (
  `amizade_id` int NOT NULL AUTO_INCREMENT,
  `solicitante_id` int NOT NULL,
  `destinatario_id` int NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `data_pedido` datetime NOT NULL,
  `data_resposta` datetime DEFAULT NULL,
  `jogador_menor` int GENERATED ALWAYS AS (least(`solicitante_id`,`destinatario_id`)) STORED,
  `jogador_maior` int GENERATED ALWAYS AS (greatest(`solicitante_id`,`destinatario_id`)) STORED,
  PRIMARY KEY (`amizade_id`),
  UNIQUE KEY `uq_amizade_par` (`jogador_menor`,`jogador_maior`),
  KEY `ix_amizade_destinatario` (`destinatario_id`,`status`),
  KEY `fk_amizade_solicitante` (`solicitante_id`),
  CONSTRAINT `fk_amizade_destinatario` FOREIGN KEY (`destinatario_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_amizade_solicitante` FOREIGN KEY (`solicitante_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_amizade_si_mesmo` CHECK ((`solicitante_id` <> `destinatario_id`)),
  CONSTRAINT `ck_amizade_status` CHECK ((`status` in (_utf8mb4'pendente',_utf8mb4'aceita')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `bloqueio` (
  `bloqueador_id` int NOT NULL,
  `bloqueado_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`bloqueador_id`,`bloqueado_id`),
  KEY `ix_bloqueio_bloqueado` (`bloqueado_id`),
  CONSTRAINT `fk_bloqueio_bloqueado` FOREIGN KEY (`bloqueado_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bloqueio_bloqueador` FOREIGN KEY (`bloqueador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_bloqueio_si_mesmo` CHECK ((`bloqueador_id` <> `bloqueado_id`))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `mensagem` (
  `mensagem_id` int NOT NULL AUTO_INCREMENT,
  `remetente_id` int NOT NULL,
  `destinatario_id` int NOT NULL,
  `texto` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_envio` datetime NOT NULL,
  `data_leitura` datetime DEFAULT NULL,
  `removida` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`mensagem_id`),
  KEY `ix_mensagem_nao_lidas` (`destinatario_id`,`data_leitura`),
  KEY `ix_mensagem_conversa` (`remetente_id`,`destinatario_id`,`mensagem_id`),
  CONSTRAINT `fk_mensagem_destinatario` FOREIGN KEY (`destinatario_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_mensagem_remetente` FOREIGN KEY (`remetente_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `denuncia_mensagem` (
  `denuncia_id` int NOT NULL AUTO_INCREMENT,
  `mensagem_id` int NOT NULL,
  `denunciante_id` int NOT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` datetime NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `resolvida_por` int DEFAULT NULL,
  `data_resolucao` datetime DEFAULT NULL,
  `ia_categoria` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_confianca` decimal(3,2) DEFAULT NULL,
  `ia_motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_modelo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_acao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_analisada_em` datetime DEFAULT NULL,
  `decidida_por_ia` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`denuncia_id`),
  UNIQUE KEY `uq_denuncia` (`mensagem_id`,`denunciante_id`),
  KEY `ix_denuncia_status` (`status`),
  KEY `fk_denuncia_denunciante` (`denunciante_id`),
  KEY `fk_denuncia_admin` (`resolvida_por`),
  CONSTRAINT `fk_denuncia_admin` FOREIGN KEY (`resolvida_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_denuncia_denunciante` FOREIGN KEY (`denunciante_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_denuncia_mensagem` FOREIGN KEY (`mensagem_id`) REFERENCES `mensagem` (`mensagem_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_denuncia_ia_acao` CHECK (((`ia_acao` is null) or (`ia_acao` in (_utf8mb4'ocultou',_utf8mb4'arquivou',_utf8mb4'fila',_utf8mb4'erro')))),
  CONSTRAINT `ck_denuncia_ia_categoria` CHECK (((`ia_categoria` is null) or (`ia_categoria` in (_utf8mb4'ofensa',_utf8mb4'odio',_utf8mb4'assedio',_utf8mb4'sexual',_utf8mb4'spam',_utf8mb4'nao_abusiva',_utf8mb4'duvida',_utf8mb4'erro')))),
  CONSTRAINT `ck_denuncia_ia_confianca` CHECK (((`ia_confianca` is null) or (`ia_confianca` between 0 and 1))),
  CONSTRAINT `ck_denuncia_status` CHECK ((`status` in (_utf8mb4'aberta',_utf8mb4'resolvida')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `sugestao_dispensada` (
  `jogador_id` int NOT NULL,
  `sugerido_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`jogador_id`,`sugerido_id`),
  KEY `ix_sugestao_sugerido` (`sugerido_id`),
  CONSTRAINT `fk_sugestao_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sugestao_sugerido` FOREIGN KEY (`sugerido_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_sugestao_si_mesmo` CHECK ((`jogador_id` <> `sugerido_id`))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Comunidades
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `comunidade` (
  `comunidade_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entrada` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `data_criacao` datetime NOT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `data_dissolucao` datetime DEFAULT NULL,
  `nome_ativo` varchar(40) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if(`ativa`,`nome`,NULL)) STORED,
  PRIMARY KEY (`comunidade_id`),
  UNIQUE KEY `uq_comunidade_nome_ativo` (`nome_ativo`),
  CONSTRAINT `ck_comunidade_entrada` CHECK ((`entrada` in (_utf8mb4'aberta',_utf8mb4'aprovacao'))),
  CONSTRAINT `ck_comunidade_icone` CHECK ((`icone` in (_utf8mb4'recycling',_utf8mb4'water',_utf8mb4'energy',_utf8mb4'mobility',_utf8mb4'nature')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_membro` (
  `jogador_id` int NOT NULL,
  `comunidade_id` int NOT NULL,
  `papel` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'membro',
  `data_entrada` datetime NOT NULL,
  `ultima_lida_id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`jogador_id`),
  KEY `ix_membro_comunidade` (`comunidade_id`,`papel`),
  CONSTRAINT `fk_membro_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membro_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_membro_papel` CHECK ((`papel` in (_utf8mb4'lider',_utf8mb4'moderador',_utf8mb4'membro')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_pedido` (
  `comunidade_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `tipo` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `convidado_por` int DEFAULT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`comunidade_id`,`jogador_id`),
  KEY `ix_pedido_jogador` (`jogador_id`,`tipo`),
  KEY `fk_pedido_convidou` (`convidado_por`),
  CONSTRAINT `fk_pedido_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pedido_convidou` FOREIGN KEY (`convidado_por`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pedido_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_pedido_tipo` CHECK ((`tipo` in (_utf8mb4'pedido',_utf8mb4'convite')))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_mensagem` (
  `comunidade_mensagem_id` int NOT NULL AUTO_INCREMENT,
  `comunidade_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `texto` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_envio` datetime NOT NULL,
  `removida` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`comunidade_mensagem_id`),
  KEY `ix_cmsg_comunidade` (`comunidade_id`,`comunidade_mensagem_id`),
  KEY `ix_cmsg_jogador` (`jogador_id`,`data_envio`),
  CONSTRAINT `fk_cmsg_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cmsg_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_topico` (
  `comunidade_topico_id` int NOT NULL AUTO_INCREMENT,
  `comunidade_id` int NOT NULL,
  `autor_id` int NOT NULL,
  `titulo` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `texto` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_evento` datetime DEFAULT NULL,
  `local` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fixado` tinyint(1) NOT NULL DEFAULT '0',
  `removido` tinyint(1) NOT NULL DEFAULT '0',
  `data` datetime NOT NULL,
  PRIMARY KEY (`comunidade_topico_id`),
  KEY `ix_topico_comunidade` (`comunidade_id`,`removido`,`fixado`),
  KEY `fk_topico_autor` (`autor_id`),
  CONSTRAINT `fk_topico_autor` FOREIGN KEY (`autor_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_topico_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_presenca` (
  `comunidade_topico_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`comunidade_topico_id`,`jogador_id`),
  KEY `fk_presenca_jogador` (`jogador_id`),
  CONSTRAINT `fk_presenca_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_presenca_topico` FOREIGN KEY (`comunidade_topico_id`) REFERENCES `comunidade_topico` (`comunidade_topico_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_missao` (
  `comunidade_missao_id` int NOT NULL AUTO_INCREMENT,
  `comunidade_id` int DEFAULT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `meta` decimal(10,2) NOT NULL,
  `recompensa_xp` int NOT NULL DEFAULT '0',
  `recompensa_credito` int NOT NULL DEFAULT '0',
  `pontos` int NOT NULL DEFAULT '0',
  `data_inicio` date NOT NULL,
  `data_fim` date DEFAULT NULL,
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `origem` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `gerado_em` datetime DEFAULT NULL,
  `criada_por` int DEFAULT NULL,
  PRIMARY KEY (`comunidade_missao_id`),
  KEY `ix_cmissao_comunidade` (`comunidade_id`,`ativa`),
  KEY `fk_cmissao_atividade` (`atividade_sustentavel_id`),
  KEY `fk_cmissao_criador` (`criada_por`),
  CONSTRAINT `fk_cmissao_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cmissao_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cmissao_criador` FOREIGN KEY (`criada_por`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_cmissao_meta` CHECK ((`meta` > 0)),
  CONSTRAINT `ck_cmissao_origem` CHECK ((`origem` in (_utf8mb4'manual',_utf8mb4'ia'))),
  CONSTRAINT `ck_cmissao_periodo` CHECK (((`data_fim` is null) or (`data_fim` >= `data_inicio`))),
  CONSTRAINT `ck_cmissao_recompensa` CHECK (((`recompensa_xp` >= 0) and (`recompensa_credito` >= 0) and (`pontos` >= 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_missao_resultado` (
  `comunidade_missao_id` int NOT NULL,
  `comunidade_id` int NOT NULL,
  `periodo_inicio` date NOT NULL,
  `pontos` int NOT NULL DEFAULT '0',
  `data` datetime NOT NULL,
  PRIMARY KEY (`comunidade_missao_id`,`comunidade_id`,`periodo_inicio`),
  KEY `ix_cresultado_comunidade` (`comunidade_id`,`data`),
  CONSTRAINT `fk_cresultado_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cresultado_missao` FOREIGN KEY (`comunidade_missao_id`) REFERENCES `comunidade_missao` (`comunidade_missao_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_contribuicao` (
  `registro_atividade_id` int NOT NULL,
  `comunidade_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `impacto_real` decimal(10,2) NOT NULL DEFAULT '0.00',
  `pontos` int NOT NULL,
  `data_registro` datetime NOT NULL,
  PRIMARY KEY (`registro_atividade_id`),
  KEY `ix_contrib_comunidade` (`comunidade_id`,`data_registro`),
  KEY `ix_contrib_jogador` (`jogador_id`,`comunidade_id`),
  KEY `fk_contrib_atividade` (`atividade_sustentavel_id`),
  CONSTRAINT `fk_contrib_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contrib_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contrib_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contrib_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_contribuicao_pontos` CHECK ((`pontos` >= 0))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `comunidade_denuncia` (
  `comunidade_denuncia_id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alvo_id` int NOT NULL,
  `comunidade_id` int NOT NULL,
  `denunciante_id` int NOT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` datetime NOT NULL,
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'aberta',
  `resolvida_por` int DEFAULT NULL,
  `data_resolucao` datetime DEFAULT NULL,
  `ia_categoria` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_confianca` decimal(3,2) DEFAULT NULL,
  `ia_motivo` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_modelo` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_acao` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ia_analisada_em` datetime DEFAULT NULL,
  `decidida_por_ia` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`comunidade_denuncia_id`),
  UNIQUE KEY `uq_cdenuncia` (`tipo`,`alvo_id`,`denunciante_id`),
  KEY `ix_cdenuncia_status` (`status`),
  KEY `fk_cdenuncia_comunidade` (`comunidade_id`),
  KEY `fk_cdenuncia_denunciante` (`denunciante_id`),
  KEY `fk_cdenuncia_admin` (`resolvida_por`),
  CONSTRAINT `fk_cdenuncia_admin` FOREIGN KEY (`resolvida_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cdenuncia_comunidade` FOREIGN KEY (`comunidade_id`) REFERENCES `comunidade` (`comunidade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_cdenuncia_denunciante` FOREIGN KEY (`denunciante_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_cdenuncia_ia_acao` CHECK (((`ia_acao` is null) or (`ia_acao` in (_utf8mb4'ocultou',_utf8mb4'arquivou',_utf8mb4'fila',_utf8mb4'erro')))),
  CONSTRAINT `ck_cdenuncia_ia_categoria` CHECK (((`ia_categoria` is null) or (`ia_categoria` in (_utf8mb4'ofensa',_utf8mb4'odio',_utf8mb4'assedio',_utf8mb4'sexual',_utf8mb4'spam',_utf8mb4'nao_abusiva',_utf8mb4'duvida',_utf8mb4'erro')))),
  CONSTRAINT `ck_cdenuncia_ia_confianca` CHECK (((`ia_confianca` is null) or (`ia_confianca` between 0 and 1))),
  CONSTRAINT `ck_cdenuncia_status` CHECK ((`status` in (_utf8mb4'aberta',_utf8mb4'resolvida'))),
  CONSTRAINT `ck_cdenuncia_tipo` CHECK ((`tipo` in (_utf8mb4'mensagem',_utf8mb4'topico')))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Parceiros
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `parceiro` (
  `parceiro_id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `senha` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsavel` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endereco` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
  `motivo_recusa` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_cadastro` datetime NOT NULL,
  `aprovado_por` int DEFAULT NULL,
  `data_aprovacao` datetime DEFAULT NULL,
  `sessao_versao` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`parceiro_id`),
  UNIQUE KEY `uq_parceiro_email` (`email`),
  KEY `fk_parceiro_admin` (`aprovado_por`),
  CONSTRAINT `fk_parceiro_admin` FOREIGN KEY (`aprovado_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_parceiro_status` CHECK ((`status` in (_utf8mb4'pendente',_utf8mb4'aprovado',_utf8mb4'recusado',_utf8mb4'bloqueado')))
) ENGINE=InnoDB;

-- RN95 (migração 031): funcionários do parceiro e pedidos de atividade
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
)  ENGINE=InnoDB;

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
)  ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `parceiro_atividade` (
  `parceiro_id` int NOT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  PRIMARY KEY (`parceiro_id`,`atividade_sustentavel_id`),
  KEY `fk_patv_atividade` (`atividade_sustentavel_id`),
  CONSTRAINT `fk_patv_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_patv_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `codigo_parceiro` (
  `codigo_parceiro_id` int NOT NULL AUTO_INCREMENT,
  `parceiro_id` int NOT NULL,
  `funcionario_id` int DEFAULT NULL,
  `atividade_sustentavel_id` int NOT NULL,
  `quantidade` decimal(10,2) NOT NULL,
  `codigo` char(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `criado_em` datetime NOT NULL,
  `expira_em` datetime NOT NULL,
  `cancelado` tinyint(1) NOT NULL DEFAULT '0',
  `usado_em` datetime DEFAULT NULL,
  `jogador_id` int DEFAULT NULL,
  `registro_atividade_id` int DEFAULT NULL,
  PRIMARY KEY (`codigo_parceiro_id`),
  UNIQUE KEY `uq_codigo_token` (`token`),
  KEY `ix_codigo_codigo` (`codigo`,`expira_em`),
  KEY `ix_codigo_parceiro` (`parceiro_id`,`criado_em`),
  KEY `ix_codigo_funcionario` (`funcionario_id`,`criado_em`),
  KEY `fk_codigo_atividade` (`atividade_sustentavel_id`),
  KEY `fk_codigo_jogador` (`jogador_id`),
  KEY `fk_codigo_registro` (`registro_atividade_id`),
  CONSTRAINT `fk_codigo_atividade` FOREIGN KEY (`atividade_sustentavel_id`) REFERENCES `atividade_sustentavel` (`atividade_sustentavel_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_codigo_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_codigo_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_codigo_funcionario` FOREIGN KEY (`funcionario_id`) REFERENCES `parceiro_funcionario` (`funcionario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_codigo_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_codigo_quantidade` CHECK ((`quantidade` > 0)),
  CONSTRAINT `ck_codigo_validade` CHECK ((`expira_em` > `criado_em`))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tentativa_codigo_parceiro` (
  `tentativa_codigo_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`tentativa_codigo_id`),
  KEY `ix_tentativa_codigo` (`jogador_id`,`data`),
  CONSTRAINT `fk_tentativa_codigo_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `verificacao_parceiro` (
  `verificacao_parceiro_id` int NOT NULL AUTO_INCREMENT,
  `registro_atividade_id` int NOT NULL,
  `parceiro_id` int NOT NULL,
  `codigo_verificacao` bigint NOT NULL,
  `data_verificacao` datetime NOT NULL,
  PRIMARY KEY (`verificacao_parceiro_id`),
  UNIQUE KEY `uq_verificacao_registro` (`registro_atividade_id`),
  KEY `fk_verificacao_parceiro` (`parceiro_id`),
  CONSTRAINT `fk_verificacao_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_verificacao_registro` FOREIGN KEY (`registro_atividade_id`) REFERENCES `registro_atividade` (`registro_atividade_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `redefinicao_senha_parceiro` (
  `redefinicao_senha_parceiro_id` int NOT NULL AUTO_INCREMENT,
  `parceiro_id` int NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_criacao` datetime NOT NULL,
  `data_expiracao` datetime NOT NULL,
  `data_uso` datetime DEFAULT NULL,
  PRIMARY KEY (`redefinicao_senha_parceiro_id`),
  UNIQUE KEY `uq_redefinicao_parceiro_token` (`token_hash`),
  KEY `ix_redefinicao_parceiro` (`parceiro_id`,`data_criacao`),
  CONSTRAINT `fk_redefinicao_parceiro` FOREIGN KEY (`parceiro_id`) REFERENCES `parceiro` (`parceiro_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Chatbot EcoGuia (RN97)
-- -----------------------------------------------------------------------------
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
  KEY `ix_chat_ia_jogador_data` (`jogador_id`,`papel`,`criado_em`),
  KEY `ix_chat_ia_jogador_visivel` (`jogador_id`,`oculta`,`chat_ia_mensagem_id`),
  KEY `ix_chat_ia_global_data` (`papel`,`criado_em`),
  CONSTRAINT `fk_chat_ia_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_chat_ia_papel` CHECK ((`papel` in (_utf8mb4'jogador',_utf8mb4'assistente'))),
  CONSTRAINT `ck_chat_ia_categoria` CHECK (((`categoria` is null) or (`categoria` in (_utf8mb4'sustentabilidade',_utf8mb4'tendencias',_utf8mb4'pontos_coleta',_utf8mb4'ecocity_uso',_utf8mb4'fora_do_escopo',_utf8mb4'tentativa_seguranca',_utf8mb4'erro')))),
  CONSTRAINT `ck_chat_ia_tempo` CHECK (((`tempo_ms` is null) or (`tempo_ms` >= 0)))
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Códigos promocionais (RN98, migração 036)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `codigo_promocional` (
  `codigo_promocional_id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recompensa_xp` int NOT NULL DEFAULT '0',
  `recompensa_credito` int NOT NULL DEFAULT '0',
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `limite_usos` int DEFAULT NULL,
  `somente_novos` tinyint(1) NOT NULL DEFAULT '0',
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `criado_por` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`codigo_promocional_id`),
  UNIQUE KEY `uq_codigo_promo_codigo` (`codigo`),
  KEY `ix_codigo_promo_periodo` (`ativa`,`data_inicio`,`data_fim`),
  KEY `fk_codigo_promo_admin` (`criado_por`),
  CONSTRAINT `fk_codigo_promo_admin` FOREIGN KEY (`criado_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_codigo_promo_codigo` CHECK ((CHAR_LENGTH(TRIM(`codigo`)) >= 4)),
  CONSTRAINT `ck_codigo_promo_recompensa` CHECK (((`recompensa_xp` >= 0) AND (`recompensa_credito` >= 0))),
  CONSTRAINT `ck_codigo_promo_periodo` CHECK ((`data_fim` >= `data_inicio`)),
  CONSTRAINT `ck_codigo_promo_limite` CHECK (((`limite_usos` IS NULL) OR (`limite_usos` > 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `codigo_promocional_indicador` (
  `codigo_promocional_id` int NOT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `pontos` int NOT NULL,
  PRIMARY KEY (`codigo_promocional_id`,`indicador_ambiental_id`),
  KEY `fk_promo_ind_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_promo_ind_codigo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_promo_ind_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_promo_ind_pontos` CHECK ((`pontos` BETWEEN 1 AND 100))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `codigo_promocional_resgate` (
  `resgate_id` int NOT NULL AUTO_INCREMENT,
  `codigo_promocional_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `xp_recebido` int NOT NULL DEFAULT '0',
  `credito_recebido` int NOT NULL DEFAULT '0',
  `indicadores_json` json DEFAULT NULL,
  `resgatado_em` datetime NOT NULL,
  PRIMARY KEY (`resgate_id`),
  UNIQUE KEY `uq_promo_resgate_jogador` (`codigo_promocional_id`,`jogador_id`),
  KEY `ix_promo_resgate_jogador` (`jogador_id`,`resgatado_em`),
  CONSTRAINT `fk_promo_resgate_codigo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_promo_resgate_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_promo_resgate_valores` CHECK (((`xp_recebido` >= 0) AND (`credito_recebido` >= 0)))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `tentativa_codigo_promocional` (
  `tentativa_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`tentativa_id`),
  KEY `ix_tentativa_promo_jogador` (`jogador_id`,`data`),
  CONSTRAINT `fk_tentativa_promo_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `ajuste_conta_admin` (
  `ajuste_admin_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `jogador_id` int DEFAULT NULL,
  `administrador_id` int NOT NULL,
  `tipo` enum('progressao','perfil','seguranca') COLLATE utf8mb4_unicode_ci NOT NULL,
  `delta_xp` int NOT NULL DEFAULT '0',
  `delta_credito` int NOT NULL DEFAULT '0',
  `indicadores_json` json DEFAULT NULL,
  `detalhes_json` json DEFAULT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ajuste_admin_id`),
  KEY `ix_ajuste_admin_usuario` (`usuario_id`, `criado_em`),
  KEY `ix_ajuste_admin_jogador` (`jogador_id`, `criado_em`),
  KEY `fk_ajuste_admin_admin` (`administrador_id`),
  CONSTRAINT `fk_ajuste_admin_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`usuario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ajuste_admin_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ajuste_admin_admin` FOREIGN KEY (`administrador_id`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_ajuste_admin_xp` CHECK ((`delta_xp` >= 0)),
  CONSTRAINT `ck_ajuste_admin_motivo` CHECK ((CHAR_LENGTH(TRIM(`motivo`)) >= 5))
) ENGINE=InnoDB;


-- -----------------------------------------------------------------------------
-- Sincronização em tempo real e auditoria
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sincronia` (
  `escopo` enum('jogador','comunidade','global') COLLATE utf8mb4_unicode_ci NOT NULL,
  `escopo_id` int NOT NULL,
  `dominio` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `versao` bigint unsigned NOT NULL DEFAULT '1',
  `atualizado_em` datetime(3) NOT NULL,
  PRIMARY KEY (`escopo`,`escopo_id`,`dominio`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `auditoria` (
  `auditoria_id` bigint NOT NULL AUTO_INCREMENT,
  `tabela` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `registro_id` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `acao` enum('INSERT','UPDATE','DELETE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `dados_antes` json DEFAULT NULL,
  `dados_depois` json DEFAULT NULL,
  `usuario_app` int DEFAULT NULL,
  `parceiro_app` int DEFAULT NULL,
  `funcionario_app` int DEFAULT NULL,
  `usuario_banco` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origem` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` datetime(3) NOT NULL,
  PRIMARY KEY (`auditoria_id`),
  KEY `ix_auditoria_tabela` (`tabela`,`registro_id`),
  KEY `ix_auditoria_data` (`data`),
  KEY `ix_auditoria_usuario` (`usuario_app`,`data`),
  KEY `ix_auditoria_tabela_data` (`tabela`,`data`),
  KEY `ix_auditoria_acao_data` (`acao`,`data`),
  KEY `ix_auditoria_parceiro` (`parceiro_app`,`data`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
