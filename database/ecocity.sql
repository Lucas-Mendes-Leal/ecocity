-- =============================================================================
-- EcoCity — estrutura completa do banco (gerada de database/projeto/01 a 05).
-- Para instalar do zero: este arquivo (ou 01→05) + 06-dados-teste.sql + 08-seguranca.sql.
-- As migrações 002–044 ficam como histórico das mudanças.
-- =============================================================================

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

-- =============================================================================
-- EcoCity — 02. Índices para otimização de consultas
-- Cada índice composto atende uma consulta real do jogo (comentário acima de cada um) e substitui o índice
-- simples que o MySQL cria para a chave estrangeira (a FK passa a usar o composto, pelo prefixo).
-- Além destes, 01-criar-banco.sql já traz: PKs, UNIQUE (e-mail, código público, nome de comunidade ativa,
-- pares de amizade…) e índices de apoio (fila de mensagens não lidas, contribuições por comunidade e semana etc.).
-- Medição: database/projeto/09-monitoramento.sql (EXPLAIN com e sem o índice).
-- =============================================================================
USE ecocity;
-- -----------------------------------------------------------------------------
-- 4. Índices compostos para as consultas mais usadas (substituem os índices simples das FKs)
-- -----------------------------------------------------------------------------
-- Extrato do jogador e estatísticas semanais: WHERE jogador_id = ? ORDER BY data
ALTER TABLE historico_xp ADD KEY ix_hist_xp_jogador_data (jogador_id, data), DROP KEY fk_hist_xp_jogador;
ALTER TABLE historico_credito_verde ADD KEY ix_hist_cred_jogador_data (jogador_id, data), DROP KEY fk_hist_cred_jogador;
-- 1ª tentativa e rotação dos quizzes: WHERE jogador_id = ? AND quiz_id = ? AND acertos IS NOT NULL
ALTER TABLE tentativa_quiz ADD KEY ix_tentativa_jogador_quiz (jogador_id, quiz_id, acertos), DROP KEY fk_tentativa_jogador;
-- Limite diário (RN44) e registros do jogador: WHERE jogador_id = ? AND atividade_sustentavel_id = ? AND data_registro …
ALTER TABLE registro_atividade
    ADD KEY ix_registro_jogador_atividade_data (jogador_id, atividade_sustentavel_id, data_registro),
    DROP KEY fk_registro_jogador,
-- Fila de aprovação e aprovação automática: WHERE tipo_verificacao_id = 2 AND status = 'pendente' ORDER BY data_registro
    ADD KEY ix_registro_tipo_status_data (tipo_verificacao_id, status, data_registro),
    DROP KEY fk_registro_tipo_verificacao;
-- Variação dos indicadores (LAG por cidade e indicador, em ordem de data)
ALTER TABLE historico_indicador_cidade
    ADD KEY ix_hist_ind_cidade_data (cidade_id, indicador_ambiental_id, data, historico_indicador_id),
    DROP KEY fk_hist_indicador_cidade_indicador;
-- Conquistas do jogador: WHERE jogador_id = ?
ALTER TABLE jogador_conquista ADD KEY ix_jogador_conquista_data (jogador_id, data_conquista), DROP KEY fk_jogador_conquista_jogador;
-- Evolução sustentável, Meu impacto e contagem por status do jogador (migração 045): WHERE jogador_id = ? AND status = 'aprovado' ORDER BY data_registro DESC
ALTER TABLE registro_atividade ADD KEY ix_registro_jogador_status_data (jogador_id, status, data_registro);
-- Histórico recente de quizzes do jogador (migração 045): WHERE jogador_id = ? AND acertos IS NOT NULL ORDER BY data DESC
ALTER TABLE tentativa_quiz ADD KEY ix_tentativa_jogador_data (jogador_id, data);
-- Histórico e relatórios de códigos utilizados por parceiro (migração 045): WHERE parceiro_id = ? AND usado_em IS NOT NULL ORDER BY usado_em DESC
ALTER TABLE codigo_parceiro ADD KEY ix_codigo_parceiro_usado (parceiro_id, usado_em);

-- =============================================================================
-- EcoCity — 03. Views para simplificar relatórios
-- Usadas pelo PHP (Perfil, administração, estatísticas, ranking, fila de aprovação) e pela conta de analista
-- (ecocity_role_analista só lê views: nenhuma expõe e-mail, senha ou nome real).
-- =============================================================================
USE ecocity;

-- Funções determinísticas de regra de negócio usadas pelas views e pelo PHP (migração 045)
DELIMITER $$

DROP FUNCTION IF EXISTS fn_fator_verificacao$$
CREATE FUNCTION fn_fator_verificacao(p_tipo_verificacao INT, p_aceita_foto TINYINT(1))
RETURNS INT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_tipo_verificacao = 3 THEN 150
        WHEN p_tipo_verificacao = 1 AND p_aceita_foto = 1 THEN 50
        ELSE 100
    END;
END$$

DROP FUNCTION IF EXISTS fn_estagio_indicador$$
CREATE FUNCTION fn_estagio_indicador(p_valor INT)
RETURNS TINYINT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_valor >= 75 THEN 4
        WHEN p_valor >= 50 THEN 3
        WHEN p_valor >= 25 THEN 2
        ELSE 1
    END;
END$$

DROP FUNCTION IF EXISTS fn_situacao_codigo_parceiro$$
CREATE FUNCTION fn_situacao_codigo_parceiro(
    p_usado_em DATETIME,
    p_cancelado TINYINT(1),
    p_expira_em DATETIME,
    p_agora DATETIME
)
RETURNS VARCHAR(15) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_usado_em IS NOT NULL THEN 'usado'
        WHEN p_cancelado = 1 THEN 'cancelado'
        WHEN p_expira_em <= p_agora THEN 'vencido'
        ELSE 'aguardando'
    END;
END$$

DELIMITER ;

-- Progresso ecológico (média dos indicadores) e nível calculado de cada cidade (RN17/RN22)
CREATE OR REPLACE VIEW vw_cidade_progresso AS
SELECT x.cidade_id, x.jogador_id, x.progresso,
       (SELECT MAX(n.nivel) FROM nivel_cidade n WHERE n.pontuacao_minima <= x.progresso) AS nivel_calculado
  FROM (SELECT c.cidade_id, c.jogador_id, ROUND(AVG(ci.valor)) AS progresso
          FROM cidade c JOIN cidade_indicador ci ON ci.cidade_id = c.cidade_id
      GROUP BY c.cidade_id, c.jogador_id) x;

-- Jogador com conta, nível, cidade e progresso (sem senha nem e-mail)
CREATE OR REPLACE VIEW vw_jogador_resumo AS
SELECT j.jogador_id, j.usuario_id, j.apelido, j.numero_conta, j.creditos_verde, j.xp_atual, nj.nivel,
       j.data_criacao, c.cidade_id, c.nome AS cidade, nc.nivel AS nivel_cidade, cp.progresso,
       u.status AS conta_ativa, u.demonstracao
  FROM jogador j
  JOIN usuario u ON u.usuario_id = j.usuario_id
  JOIN cidade c ON c.jogador_id = j.jogador_id
  LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
  LEFT JOIN nivel_cidade nc ON nc.nivel_cidade_id = c.nivel_cidade_id
  LEFT JOIN vw_cidade_progresso cp ON cp.cidade_id = c.cidade_id;

-- Registro de atividade com atividade, área, tipo, impacto real efetivo e pontos no indicador (RN04/RN26/RN73/RN92, migração 045)
CREATE OR REPLACE VIEW vw_registro_detalhado AS
SELECT r.registro_atividade_id, r.jogador_id, r.atividade_sustentavel_id, a.nome AS atividade,
       a.categoria_ambiental_id, ai.indicador_ambiental_id, a.aceita_foto, a.aceita_parceiro,
       r.tipo_verificacao_id, tv.nome AS tipo_verificacao, r.status,
       r.data_registro, DATE(r.data_registro) AS dia, r.data_aprovacao, r.aprovado_por_administrador_id,
       r.motivo_rejeicao, r.missao_atividade_id,
       COALESCE(r.impacto_real, a.impacto_real_quantidade) AS impacto_efetivo, a.impacto_real_unidade AS unidade,
       FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN tipo_verificacao tv ON tv.tipo_verificacao_id = r.tipo_verificacao_id;

-- Impacto real multi-unidade e pontos no indicador por jogador, área e unidade física (migração 045)
CREATE OR REPLACE VIEW vw_impacto_jogador_unidade AS
SELECT r.jogador_id,
       ai.indicador_ambiental_id,
       ia.nome AS categoria,
       a.impacto_real_unidade AS unidade,
       COUNT(*) AS acoes,
       COALESCE(SUM(r.tipo_verificacao_id = 1), 0) AS sem_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 2), 0) AS com_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 3), 0) AS com_parceiro,
       COALESCE(SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)), 0) AS impacto,
       COALESCE(SUM(FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100)), 0) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
 WHERE r.status = 'aprovado'
 GROUP BY r.jogador_id, ai.indicador_ambiental_id, ia.nome, a.impacto_real_unidade;

-- Ações aprovadas e impacto real (da unidade principal da área) por jogador e área (conquistas de impacto, estatísticas)
CREATE OR REPLACE VIEW vw_impacto_jogador_categoria AS
SELECT r.jogador_id,
       ai.indicador_ambiental_id,
       COUNT(*) AS acoes,
       COALESCE(SUM(
           CASE
               WHEN a.impacto_real_unidade = (
                   SELECT a0.impacto_real_unidade
                     FROM atividade_indicador_ambiental ai0
                     JOIN atividade_sustentavel a0 ON a0.atividade_sustentavel_id = ai0.atividade_sustentavel_id
                    WHERE ai0.indicador_ambiental_id = ai.indicador_ambiental_id
                      AND a0.impacto_real_unidade IS NOT NULL
                    ORDER BY a0.atividade_sustentavel_id
                    LIMIT 1
               )
               THEN COALESCE(r.impacto_real, a.impacto_real_quantidade)
               ELSE 0
           END
       ), 0) AS impacto
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
 WHERE r.status = 'aprovado'
 GROUP BY r.jogador_id, ai.indicador_ambiental_id;

-- Resumo por atividade (tabela da Visão Geral da administração)
CREATE OR REPLACE VIEW vw_atividade_resumo AS
SELECT a.atividade_sustentavel_id, a.nome, ai.indicador_ambiental_id, a.impacto_real_quantidade, a.impacto_real_unidade,
       COALESCE(SUM(r.status = 'aprovado'), 0) AS aprovados,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 1), 0) AS autodeclarados,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 2), 0) AS com_evidencia,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 3), 0) AS com_parceiro,
       COALESCE(SUM(IF(r.status = 'aprovado', COALESCE(r.impacto_real, a.impacto_real_quantidade), 0)), 0) AS impacto_soma
  FROM atividade_sustentavel a
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  LEFT JOIN registro_atividade r ON r.atividade_sustentavel_id = a.atividade_sustentavel_id
 GROUP BY a.atividade_sustentavel_id, a.nome, ai.indicador_ambiental_id, a.impacto_real_quantidade, a.impacto_real_unidade;

-- Fila de aprovação: registros com foto pendentes, com jogador, cidade e evidência
CREATE OR REPLACE VIEW vw_fila_aprovacao AS
SELECT r.registro_atividade_id AS id, r.data_registro, a.nome AS atividade, ai.indicador_ambiental_id,
       ia.nome AS categoria_nome, j.apelido, j.usuario_id, c.nome AS cidade, e.descricao, e.arquivo
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
  JOIN jogador j ON j.jogador_id = r.jogador_id
  JOIN cidade c ON c.jogador_id = j.jogador_id
  LEFT JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
 WHERE r.status = 'pendente' AND r.tipo_verificacao_id = 2;

-- Extrato: ganhos de XP (com os créditos do mesmo evento) + créditos sem XP (construções, renda, comunidade, promoções, ajustes)
CREATE OR REPLACE VIEW vw_extrato AS
SELECT hx.jogador_id, hx.data, hx.origem, hx.quantidade AS xp,
       (SELECT hc.quantidade FROM historico_credito_verde hc
         WHERE hc.jogador_id = hx.jogador_id AND hc.origem = hx.origem
           AND hc.registro_atividade_id <=> hx.registro_atividade_id AND hc.tentativa_id <=> hx.tentativa_id
           AND hc.conquista_id <=> hx.conquista_id AND hc.missao_id <=> hx.missao_id
           AND hc.comunidade_missao_id <=> hx.comunidade_missao_id
           AND hc.codigo_promocional_id <=> hx.codigo_promocional_id
           AND hc.ajuste_admin_id <=> hx.ajuste_admin_id
           AND (hx.codigo_promocional_id IS NOT NULL OR hx.ajuste_admin_id IS NOT NULL OR hc.data = hx.data)
         LIMIT 1) AS credito,
       COALESCE(a.nome, q.titulo, cq.nome, m.nome, cm.nome, cp.nome, aa.motivo) AS referencia,
       hx.historico_xp_id AS ordem
  FROM historico_xp hx
  LEFT JOIN registro_atividade r ON r.registro_atividade_id = hx.registro_atividade_id
  LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  LEFT JOIN tentativa_quiz t ON t.tentativa_id = hx.tentativa_id
  LEFT JOIN quiz q ON q.quiz_id = t.quiz_id
  LEFT JOIN conquista cq ON cq.conquista_id = hx.conquista_id
  LEFT JOIN missao m ON m.missao_id = hx.missao_id
  LEFT JOIN comunidade_missao cm ON cm.comunidade_missao_id = hx.comunidade_missao_id
  LEFT JOIN codigo_promocional cp ON cp.codigo_promocional_id = hx.codigo_promocional_id
  LEFT JOIN ajuste_conta_admin aa ON aa.ajuste_admin_id = hx.ajuste_admin_id
UNION ALL
SELECT hc.jogador_id, hc.data, hc.origem, 0 AS xp, hc.quantidade AS credito,
       COALESCE(co.nome, cmd.nome, cp2.nome, aa2.motivo) AS referencia, hc.historico_credito_verde_id AS ordem
  FROM historico_credito_verde hc
  LEFT JOIN construcao co ON co.construcao_id = hc.construcao_id
  LEFT JOIN comunidade cmd ON cmd.comunidade_id = hc.comunidade_id
  LEFT JOIN codigo_promocional cp2 ON cp2.codigo_promocional_id = hc.codigo_promocional_id
  LEFT JOIN ajuste_conta_admin aa2 ON aa2.ajuste_admin_id = hc.ajuste_admin_id
 WHERE hc.construcao_id IS NOT NULL OR hc.comunidade_id IS NOT NULL
    OR (hc.codigo_promocional_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM historico_xp hx2
         WHERE hx2.jogador_id = hc.jogador_id AND hx2.codigo_promocional_id = hc.codigo_promocional_id
    ))
    OR (hc.ajuste_admin_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM historico_xp hx3
         WHERE hx3.jogador_id = hc.jogador_id AND hx3.ajuste_admin_id = hc.ajuste_admin_id
    ));

-- 1ª tentativa concluída de cada jogador em cada quiz (a que vale para o acerto, RN82)
CREATE OR REPLACE VIEW vw_quiz_primeira_tentativa AS
SELECT t.tentativa_id, t.jogador_id, t.quiz_id, q.categoria_ambiental_id, t.data, t.acertos, t.pontuacao
  FROM tentativa_quiz t
  JOIN quiz q ON q.quiz_id = t.quiz_id
 WHERE t.acertos IS NOT NULL
   AND t.tentativa_id = (SELECT MIN(t2.tentativa_id) FROM tentativa_quiz t2
                          WHERE t2.jogador_id = t.jogador_id AND t2.quiz_id = t.quiz_id AND t2.acertos IS NOT NULL);

-- Acerto por pergunta na 1ª tentativa (perguntas mais erradas)
CREATE OR REPLACE VIEW vw_acerto_pergunta AS
SELECT p.pergunta_id, p.quiz_id, p.enunciado, COUNT(r.resposta_quiz_id) AS respostas,
       COALESCE(SUM(r.correta), 0) AS acertos, COUNT(DISTINCT pt.jogador_id) AS jogadores
  FROM pergunta p
  LEFT JOIN resposta_quiz r ON r.pergunta_id = p.pergunta_id
  LEFT JOIN vw_quiz_primeira_tentativa pt ON pt.tentativa_id = r.tentativa_id
 WHERE r.resposta_quiz_id IS NULL OR pt.tentativa_id IS NOT NULL
 GROUP BY p.pergunta_id, p.quiz_id, p.enunciado;

-- Ranking das comunidades ativas (RN68): geral e da semana atual (segunda a domingo)
CREATE OR REPLACE VIEW vw_ranking_comunidade_geral AS
SELECT x.*, ROW_NUMBER() OVER (ORDER BY x.pontos DESC, x.data_criacao, x.comunidade_id) AS posicao
  FROM (SELECT c.comunidade_id, c.nome, c.icone, c.entrada, c.data_criacao,
               (SELECT COUNT(*) FROM comunidade_membro m WHERE m.comunidade_id = c.comunidade_id) AS membros,
               COALESCE((SELECT SUM(pontos) FROM comunidade_contribuicao k WHERE k.comunidade_id = c.comunidade_id), 0)
             + COALESCE((SELECT SUM(pontos) FROM comunidade_missao_resultado s WHERE s.comunidade_id = c.comunidade_id), 0) AS pontos
          FROM comunidade c WHERE c.ativa = TRUE) x;

CREATE OR REPLACE VIEW vw_ranking_comunidade_semana AS
SELECT x.*, ROW_NUMBER() OVER (ORDER BY x.pontos DESC, x.data_criacao, x.comunidade_id) AS posicao
  FROM (SELECT c.comunidade_id, c.nome, c.icone, c.entrada, c.data_criacao,
               (SELECT COUNT(*) FROM comunidade_membro m WHERE m.comunidade_id = c.comunidade_id) AS membros,
               COALESCE((SELECT SUM(pontos) FROM comunidade_contribuicao k WHERE k.comunidade_id = c.comunidade_id
                           AND k.data_registro >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY
                           AND k.data_registro <  CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY + INTERVAL 7 DAY), 0)
             + COALESCE((SELECT SUM(pontos) FROM comunidade_missao_resultado s WHERE s.comunidade_id = c.comunidade_id
                           AND s.periodo_inicio = CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY), 0) AS pontos
          FROM comunidade c WHERE c.ativa = TRUE) x;

-- Impacto confirmado por parceiro e atividade (RN70–RN73)
CREATE OR REPLACE VIEW vw_impacto_parceiro AS
SELECT p.parceiro_id, p.nome, a.atividade_sustentavel_id, a.nome AS atividade, a.impacto_real_unidade AS unidade,
       COUNT(*) AS verificacoes, SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)) AS impacto,
       COUNT(DISTINCT r.jogador_id) AS jogadores, MAX(v.data_verificacao) AS ultima
  FROM verificacao_parceiro v
  JOIN parceiro p ON p.parceiro_id = v.parceiro_id
  JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
 GROUP BY p.parceiro_id, p.nome, a.atividade_sustentavel_id, a.nome, a.impacto_real_unidade;

-- =============================================================================
-- EcoCity — 04. Triggers
--   a) Sincronização em tempo real (RN85): marcam a versão do domínio que mudou (tabela sincronia).
--   b) Auditoria: gravam quem mudou o quê (tabela auditoria) nas tabelas sensíveis.
--   c) Regras de negócio: recusam com SIGNAL SQLSTATE '45000' o que nunca pode acontecer
--      (segunda barreira; o PHP já valida antes).
-- Chamam as procedures marcar_sincronia e registrar_auditoria (05-procedures.sql).
-- =============================================================================
USE ecocity;
DELIMITER $$

-- a) Sincronização ----------------------------------------------------------------
-- jogador
CREATE TRIGGER trg_sinc_jogador_upd AFTER UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NOT (OLD.creditos_verde <=> NEW.creditos_verde AND OLD.xp_atual <=> NEW.xp_atual AND OLD.nivel_id <=> NEW.nivel_id AND OLD.apelido <=> NEW.apelido) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
END$$

-- cidade
CREATE TRIGGER trg_sinc_cidade_upd AFTER UPDATE ON cidade FOR EACH ROW
BEGIN
    IF NOT (OLD.nivel_cidade_id <=> NEW.nivel_cidade_id AND OLD.nome <=> NEW.nome) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
END$$

-- cidade_indicador
CREATE TRIGGER trg_sinc_cidade_indicador_upd AFTER UPDATE ON cidade_indicador FOR EACH ROW
BEGIN
    IF NOT (OLD.valor <=> NEW.valor) THEN
        CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'jogador');
    END IF;
END$$

-- cidade_construcao
CREATE TRIGGER trg_sinc_cidade_construcao_ins AFTER INSERT ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'construcao');
END$$
CREATE TRIGGER trg_sinc_cidade_construcao_upd AFTER UPDATE ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'construcao');
END$$
CREATE TRIGGER trg_sinc_cidade_construcao_del AFTER DELETE ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = OLD.cidade_id), 'construcao');
END$$

-- registro_atividade
CREATE TRIGGER trg_sinc_registro_atividade_ins AFTER INSERT ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    IF NEW.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$
-- IA (RN91): análise nova avisa o jogador e a administração
CREATE TRIGGER `trg_sinc_analise_evidencia_ins` AFTER INSERT ON `analise_evidencia` FOR EACH ROW
BEGIN
    DECLARE v_jogador INT;
    SELECT jogador_id INTO v_jogador FROM registro_atividade WHERE registro_atividade_id = NEW.registro_atividade_id;
    CALL marcar_sincronia('jogador', v_jogador, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_registro_atividade_upd AFTER UPDATE ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    IF NEW.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$
CREATE TRIGGER trg_sinc_registro_atividade_del AFTER DELETE ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'registros');
    IF OLD.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$

-- jogador_missao
CREATE TRIGGER trg_sinc_jogador_missao_ins AFTER INSERT ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'missoes');
END$$
CREATE TRIGGER trg_sinc_jogador_missao_upd AFTER UPDATE ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'missoes');
END$$
CREATE TRIGGER trg_sinc_jogador_missao_del AFTER DELETE ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'missoes');
END$$

-- jogador_conquista
CREATE TRIGGER trg_sinc_jogador_conquista_ins AFTER INSERT ON jogador_conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'conquistas');
END$$
CREATE TRIGGER trg_sinc_jogador_conquista_del AFTER DELETE ON jogador_conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'conquistas');
END$$

-- tentativa_quiz
CREATE TRIGGER trg_sinc_tentativa_quiz_ins AFTER INSERT ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'quizzes');
END$$
CREATE TRIGGER trg_sinc_tentativa_quiz_upd AFTER UPDATE ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'quizzes');
END$$
CREATE TRIGGER trg_sinc_tentativa_quiz_del AFTER DELETE ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'quizzes');
END$$

-- mensagem
CREATE TRIGGER trg_sinc_mensagem_ins AFTER INSERT ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.remetente_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_mensagem_upd AFTER UPDATE ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.remetente_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_mensagem_del AFTER DELETE ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.remetente_id, 'social'); CALL marcar_sincronia('jogador', OLD.destinatario_id, 'social');
END$$

-- amizade
CREATE TRIGGER trg_sinc_amizade_ins AFTER INSERT ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.solicitante_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_amizade_upd AFTER UPDATE ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.solicitante_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_amizade_del AFTER DELETE ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.solicitante_id, 'social'); CALL marcar_sincronia('jogador', OLD.destinatario_id, 'social');
END$$

-- bloqueio
CREATE TRIGGER trg_sinc_bloqueio_ins AFTER INSERT ON bloqueio FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.bloqueador_id, 'social'); CALL marcar_sincronia('jogador', NEW.bloqueado_id, 'social');
END$$
CREATE TRIGGER trg_sinc_bloqueio_del AFTER DELETE ON bloqueio FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.bloqueador_id, 'social'); CALL marcar_sincronia('jogador', OLD.bloqueado_id, 'social');
END$$

-- comunidade
CREATE TRIGGER trg_sinc_comunidade_upd AFTER UPDATE ON comunidade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$

-- comunidade_mensagem
CREATE TRIGGER trg_sinc_comunidade_mensagem_ins AFTER INSERT ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_mensagem_upd AFTER UPDATE ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_mensagem_del AFTER DELETE ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_topico
CREATE TRIGGER trg_sinc_comunidade_topico_ins AFTER INSERT ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_topico_upd AFTER UPDATE ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_topico_del AFTER DELETE ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_presenca
CREATE TRIGGER trg_sinc_comunidade_presenca_ins AFTER INSERT ON comunidade_presenca FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', (SELECT t.comunidade_id FROM comunidade_topico t WHERE t.comunidade_topico_id = NEW.comunidade_topico_id), 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_presenca_del AFTER DELETE ON comunidade_presenca FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', (SELECT t.comunidade_id FROM comunidade_topico t WHERE t.comunidade_topico_id = OLD.comunidade_topico_id), 'comunidade');
END$$

-- comunidade_contribuicao
CREATE TRIGGER trg_sinc_comunidade_contribuicao_ins AFTER INSERT ON comunidade_contribuicao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_contribuicao_del AFTER DELETE ON comunidade_contribuicao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_missao_resultado
CREATE TRIGGER trg_sinc_comunidade_missao_resultado_ins AFTER INSERT ON comunidade_missao_resultado FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_resultado_del AFTER DELETE ON comunidade_missao_resultado FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_missao
CREATE TRIGGER trg_sinc_comunidade_missao_ins AFTER INSERT ON comunidade_missao FOR EACH ROW
BEGIN
    IF NEW.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); END IF;
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_upd AFTER UPDATE ON comunidade_missao FOR EACH ROW
BEGIN
    IF NEW.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); END IF;
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_del AFTER DELETE ON comunidade_missao FOR EACH ROW
BEGIN
    IF OLD.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade'); END IF;
END$$

-- comunidade_pedido
CREATE TRIGGER trg_sinc_comunidade_pedido_ins AFTER INSERT ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_pedido_upd AFTER UPDATE ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_pedido_del AFTER DELETE ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_membro
CREATE TRIGGER trg_sinc_comunidade_membro_ins AFTER INSERT ON comunidade_membro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_membro_del AFTER DELETE ON comunidade_membro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_membro
CREATE TRIGGER trg_sinc_comunidade_membro_upd AFTER UPDATE ON comunidade_membro FOR EACH ROW
BEGIN
    IF NOT (OLD.papel <=> NEW.papel AND OLD.comunidade_id <=> NEW.comunidade_id) THEN CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade'); END IF; CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade');
END$$

-- denuncia_mensagem
CREATE TRIGGER trg_sinc_denuncia_mensagem_ins AFTER INSERT ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_denuncia_mensagem_upd AFTER UPDATE ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_denuncia_mensagem_del AFTER DELETE ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- comunidade_denuncia
CREATE TRIGGER trg_sinc_comunidade_denuncia_ins AFTER INSERT ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_comunidade_denuncia_upd AFTER UPDATE ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_comunidade_denuncia_del AFTER DELETE ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- parceiro
CREATE TRIGGER trg_sinc_parceiro_ins AFTER INSERT ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_parceiro_upd AFTER UPDATE ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_parceiro_del AFTER DELETE ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- atividade_sustentavel
CREATE TRIGGER trg_sinc_atividade_sustentavel_ins AFTER INSERT ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_atividade_sustentavel_upd AFTER UPDATE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_atividade_sustentavel_del AFTER DELETE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- quiz
CREATE TRIGGER trg_sinc_quiz_ins AFTER INSERT ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_quiz_upd AFTER UPDATE ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_quiz_del AFTER DELETE ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- missao
CREATE TRIGGER trg_sinc_missao_ins AFTER INSERT ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_missao_upd AFTER UPDATE ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_missao_del AFTER DELETE ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- conquista
CREATE TRIGGER trg_sinc_conquista_ins AFTER INSERT ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_conquista_upd AFTER UPDATE ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_conquista_del AFTER DELETE ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- construcao
CREATE TRIGGER trg_sinc_construcao_ins AFTER INSERT ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_construcao_upd AFTER UPDATE ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_construcao_del AFTER DELETE ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$


-- b) e c) Auditoria e regras de negócio -------------------------------------------
-- Auditoria
CREATE TRIGGER trg_aud_usuario_ins AFTER INSERT ON usuario FOR EACH ROW
BEGIN
    CALL registrar_auditoria('usuario', NEW.usuario_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'demonstracao', NEW.demonstracao));
END$$
CREATE TRIGGER trg_aud_usuario_upd AFTER UPDATE ON usuario FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.email <=> NEW.email) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.demonstracao <=> NEW.demonstracao) OR NOT (OLD.senha <=> NEW.senha) THEN
        CALL registrar_auditoria('usuario', NEW.usuario_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'demonstracao', OLD.demonstracao), JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'demonstracao', NEW.demonstracao, 'senha_alterada', NOT (OLD.senha <=> NEW.senha)));
    END IF;
END$$
CREATE TRIGGER trg_aud_usuario_del AFTER DELETE ON usuario FOR EACH ROW
BEGIN
    CALL registrar_auditoria('usuario', OLD.usuario_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'demonstracao', OLD.demonstracao), NULL);
END$$
CREATE TRIGGER trg_aud_administrador_ins AFTER INSERT ON administrador FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', NEW.administrador_id, 'INSERT', NULL, JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_aud_administrador_upd AFTER UPDATE ON administrador FOR EACH ROW
BEGIN
    IF NOT (OLD.usuario_id <=> NEW.usuario_id) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.perfil <=> NEW.perfil) THEN
        CALL registrar_auditoria('administrador', NEW.administrador_id, 'UPDATE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
CREATE TRIGGER trg_aud_administrador_del AFTER DELETE ON administrador FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', OLD.administrador_id, 'DELETE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), NULL);
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_regra_administrador_master_upd BEFORE UPDATE ON administrador FOR EACH ROW
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
CREATE TRIGGER trg_regra_administrador_master_del BEFORE DELETE ON administrador FOR EACH ROW
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
CREATE TRIGGER trg_aud_jogador_upd AFTER UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NOT (OLD.apelido <=> NEW.apelido) OR NOT (OLD.creditos_verde <=> NEW.creditos_verde) OR NOT (OLD.xp_atual <=> NEW.xp_atual) OR NOT (OLD.nivel_id <=> NEW.nivel_id) THEN
        CALL registrar_auditoria('jogador', NEW.jogador_id, 'UPDATE', JSON_OBJECT('apelido', OLD.apelido, 'creditos_verde', OLD.creditos_verde, 'xp_atual', OLD.xp_atual, 'nivel_id', OLD.nivel_id), JSON_OBJECT('apelido', NEW.apelido, 'creditos_verde', NEW.creditos_verde, 'xp_atual', NEW.xp_atual, 'nivel_id', NEW.nivel_id));
    END IF;
END$$
CREATE TRIGGER trg_aud_registro_atividade_upd AFTER UPDATE ON registro_atividade FOR EACH ROW
BEGIN
    IF NOT (OLD.jogador_id <=> NEW.jogador_id) OR NOT (OLD.atividade_sustentavel_id <=> NEW.atividade_sustentavel_id) OR NOT (OLD.tipo_verificacao_id <=> NEW.tipo_verificacao_id) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.motivo_rejeicao <=> NEW.motivo_rejeicao) OR NOT (OLD.aprovado_por_administrador_id <=> NEW.aprovado_por_administrador_id) THEN
        CALL registrar_auditoria('registro_atividade', NEW.registro_atividade_id, 'UPDATE', JSON_OBJECT('jogador_id', OLD.jogador_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'tipo_verificacao_id', OLD.tipo_verificacao_id, 'status', OLD.status, 'motivo_rejeicao', OLD.motivo_rejeicao, 'aprovado_por_administrador_id', OLD.aprovado_por_administrador_id), JSON_OBJECT('jogador_id', NEW.jogador_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'tipo_verificacao_id', NEW.tipo_verificacao_id, 'status', NEW.status, 'motivo_rejeicao', NEW.motivo_rejeicao, 'aprovado_por_administrador_id', NEW.aprovado_por_administrador_id));
    END IF;
END$$
CREATE TRIGGER trg_aud_registro_atividade_del AFTER DELETE ON registro_atividade FOR EACH ROW
BEGIN
    CALL registrar_auditoria('registro_atividade', OLD.registro_atividade_id, 'DELETE', JSON_OBJECT('jogador_id', OLD.jogador_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'tipo_verificacao_id', OLD.tipo_verificacao_id, 'status', OLD.status, 'motivo_rejeicao', OLD.motivo_rejeicao, 'aprovado_por_administrador_id', OLD.aprovado_por_administrador_id), NULL);
END$$
CREATE TRIGGER trg_aud_parceiro_ins AFTER INSERT ON parceiro FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro', NEW.parceiro_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'motivo_recusa', NEW.motivo_recusa, 'aprovado_por', NEW.aprovado_por));
END$$
CREATE TRIGGER trg_aud_parceiro_upd AFTER UPDATE ON parceiro FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.email <=> NEW.email) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.motivo_recusa <=> NEW.motivo_recusa) OR NOT (OLD.aprovado_por <=> NEW.aprovado_por) THEN
        CALL registrar_auditoria('parceiro', NEW.parceiro_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'motivo_recusa', OLD.motivo_recusa, 'aprovado_por', OLD.aprovado_por), JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'motivo_recusa', NEW.motivo_recusa, 'aprovado_por', NEW.aprovado_por));
    END IF;
END$$
CREATE TRIGGER trg_aud_parceiro_del AFTER DELETE ON parceiro FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro', OLD.parceiro_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'motivo_recusa', OLD.motivo_recusa, 'aprovado_por', OLD.aprovado_por), NULL);
END$$
CREATE TRIGGER trg_aud_comunidade_upd AFTER UPDATE ON comunidade FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.ativa <=> NEW.ativa) OR NOT (OLD.entrada <=> NEW.entrada) THEN
        CALL registrar_auditoria('comunidade', NEW.comunidade_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'ativa', OLD.ativa, 'entrada', OLD.entrada), JSON_OBJECT('nome', NEW.nome, 'ativa', NEW.ativa, 'entrada', NEW.entrada));
    END IF;
END$$
CREATE TRIGGER trg_aud_comunidade_del AFTER DELETE ON comunidade FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade', OLD.comunidade_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'ativa', OLD.ativa, 'entrada', OLD.entrada), NULL);
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_ins AFTER INSERT ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'aceita_foto', NEW.aceita_foto, 'aceita_parceiro', NEW.aceita_parceiro, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_upd AFTER UPDATE ON atividade_sustentavel FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) OR NOT (OLD.aceita_foto <=> NEW.aceita_foto) OR NOT (OLD.aceita_parceiro <=> NEW.aceita_parceiro) OR NOT (OLD.impacto_real_quantidade <=> NEW.impacto_real_quantidade) THEN
        CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'aceita_foto', OLD.aceita_foto, 'aceita_parceiro', OLD.aceita_parceiro, 'impacto_real_quantidade', OLD.impacto_real_quantidade), JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'aceita_foto', NEW.aceita_foto, 'aceita_parceiro', NEW.aceita_parceiro, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
    END IF;
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_del AFTER DELETE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', OLD.atividade_sustentavel_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'aceita_foto', OLD.aceita_foto, 'aceita_parceiro', OLD.aceita_parceiro, 'impacto_real_quantidade', OLD.impacto_real_quantidade), NULL);
END$$
CREATE TRIGGER trg_aud_quiz_ins AFTER INSERT ON quiz FOR EACH ROW
BEGIN
    CALL registrar_auditoria('quiz', NEW.quiz_id, 'INSERT', NULL, JSON_OBJECT('titulo', NEW.titulo, 'tipo', NEW.tipo, 'dificuldade', NEW.dificuldade, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_quiz_upd AFTER UPDATE ON quiz FOR EACH ROW
BEGIN
    IF NOT (OLD.titulo <=> NEW.titulo) OR NOT (OLD.tipo <=> NEW.tipo) OR NOT (OLD.dificuldade <=> NEW.dificuldade) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('quiz', NEW.quiz_id, 'UPDATE', JSON_OBJECT('titulo', OLD.titulo, 'tipo', OLD.tipo, 'dificuldade', OLD.dificuldade, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), JSON_OBJECT('titulo', NEW.titulo, 'tipo', NEW.tipo, 'dificuldade', NEW.dificuldade, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_quiz_del AFTER DELETE ON quiz FOR EACH ROW
BEGIN
    CALL registrar_auditoria('quiz', OLD.quiz_id, 'DELETE', JSON_OBJECT('titulo', OLD.titulo, 'tipo', OLD.tipo, 'dificuldade', OLD.dificuldade, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_missao_ins AFTER INSERT ON missao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('missao', NEW.missao_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'tipo_missao_id', NEW.tipo_missao_id, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_missao_upd AFTER UPDATE ON missao FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.tipo_missao_id <=> NEW.tipo_missao_id) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.data_inicio <=> NEW.data_inicio) OR NOT (OLD.data_fim <=> NEW.data_fim) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('missao', NEW.missao_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'tipo_missao_id', OLD.tipo_missao_id, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'tipo_missao_id', NEW.tipo_missao_id, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_missao_del AFTER DELETE ON missao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('missao', OLD.missao_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'tipo_missao_id', OLD.tipo_missao_id, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_conquista_ins AFTER INSERT ON conquista FOR EACH ROW
BEGIN
    CALL registrar_auditoria('conquista', NEW.conquista_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'tipo_criterio', NEW.tipo_criterio, 'valor_criterio', NEW.valor_criterio, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_conquista_upd AFTER UPDATE ON conquista FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.tipo_criterio <=> NEW.tipo_criterio) OR NOT (OLD.valor_criterio <=> NEW.valor_criterio) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('conquista', NEW.conquista_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'tipo_criterio', OLD.tipo_criterio, 'valor_criterio', OLD.valor_criterio, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'tipo_criterio', NEW.tipo_criterio, 'valor_criterio', NEW.valor_criterio, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_conquista_del AFTER DELETE ON conquista FOR EACH ROW
BEGIN
    CALL registrar_auditoria('conquista', OLD.conquista_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'tipo_criterio', OLD.tipo_criterio, 'valor_criterio', OLD.valor_criterio, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_construcao_ins AFTER INSERT ON construcao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('construcao', NEW.construcao_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'custo', NEW.custo, 'categoria', NEW.categoria, 'renda_diaria', NEW.renda_diaria, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_construcao_upd AFTER UPDATE ON construcao FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.custo <=> NEW.custo) OR NOT (OLD.categoria <=> NEW.categoria) OR NOT (OLD.renda_diaria <=> NEW.renda_diaria) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('construcao', NEW.construcao_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'custo', OLD.custo, 'categoria', OLD.categoria, 'renda_diaria', OLD.renda_diaria, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'custo', NEW.custo, 'categoria', NEW.categoria, 'renda_diaria', NEW.renda_diaria, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_construcao_del AFTER DELETE ON construcao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('construcao', OLD.construcao_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'custo', OLD.custo, 'categoria', OLD.categoria, 'renda_diaria', OLD.renda_diaria, 'ativa', OLD.ativa), NULL);
END$$

-- Regras de negócio (segunda barreira; o PHP já valida antes)
-- RN94 (migração 030): rejeitado → aprovado só pela contestação aceita
CREATE TRIGGER `trg_regra_registro_decidido` BEFORE UPDATE ON `registro_atividade` FOR EACH ROW
BEGIN
    IF OLD.status <> 'pendente' AND NOT (OLD.status <=> NEW.status) THEN
        IF NOT (OLD.status = 'rejeitado' AND NEW.status = 'aprovado' AND IFNULL(@ecocity_contestacao, 0) = 1
                AND EXISTS (SELECT 1 FROM contestacao c
                             WHERE c.registro_atividade_id = OLD.registro_atividade_id AND c.status = 'aberta')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro já decidido não pode mudar de situação (recompensa única).';
        END IF;
    END IF;
END$$
-- RN92 (migração 028): atividade sem como ter foto não aceita registro com foto
CREATE TRIGGER `trg_regra_registro_foto` BEFORE INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    IF NEW.tipo_verificacao_id = 2
       AND (SELECT aceita_foto FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não tem como ser comprovada por foto: registre sem foto.';
    END IF;
END$$
-- RN69 (migração 043): atividade doméstica/não elegível não pode ser vinculada nem verificada por parceiros
CREATE TRIGGER `trg_regra_parceiro_atividade` BEFORE INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    IF (SELECT aceita_parceiro FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
CREATE TRIGGER `trg_regra_codigo_parceiro` BEFORE INSERT ON `codigo_parceiro` FOR EACH ROW
BEGIN
    IF (SELECT aceita_parceiro FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
-- RN69 / RN95 (migração 045): pedido de atividade nova por parceiro só aceita atividade elegível com impacto real
CREATE TRIGGER `trg_regra_pedido_atividade_parceiro` BEFORE INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF COALESCE((SELECT aceita_parceiro = 1 AND impacto_real_quantidade > 0
                   FROM atividade_sustentavel
                  WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id), 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
-- RN49 / RN98 (migração 045): código promocional já resgatado não pode mudar código/recompensas/início, reduzir limite abaixo do usado nem ser excluído
CREATE TRIGGER `trg_regra_codigo_promo_usado_upd` BEFORE UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    DECLARE v_resgates INT DEFAULT 0;
    SELECT COUNT(*) INTO v_resgates
      FROM codigo_promocional_resgate
     WHERE codigo_promocional_id = OLD.codigo_promocional_id;
    IF v_resgates > 0 THEN
        IF NOT (OLD.codigo <=> NEW.codigo)
           OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
           OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já foi resgatado por jogadores: o código e as recompensas não podem mais mudar.';
        END IF;
        IF NOT (OLD.data_inicio <=> NEW.data_inicio) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já possui resgates: a data de início não pode mais mudar (ajuste a data de término).';
        END IF;
        IF NEW.limite_usos IS NOT NULL AND NEW.limite_usos < v_resgates THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O limite de resgates não pode ser menor que o total já resgatado.';
        END IF;
    END IF;
END$$
CREATE TRIGGER `trg_regra_codigo_promo_usado_del` BEFORE DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM codigo_promocional_resgate WHERE codigo_promocional_id = OLD.codigo_promocional_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código promocional já possui resgates e não pode ser excluído (desative-o).';
    END IF;
END$$
CREATE TRIGGER trg_regra_jogador_saldo BEFORE UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NEW.creditos_verde < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Créditos verdes insuficientes.';
    END IF;
    IF NEW.xp_atual < OLD.xp_atual AND IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O XP do jogador não pode diminuir.';
    END IF;
END$$
CREATE TRIGGER trg_regra_hist_xp_imutavel BEFORE UPDATE ON historico_xp FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de XP não pode ser alterado.';
END$$
CREATE TRIGGER trg_regra_hist_credito_imutavel BEFORE UPDATE ON historico_credito_verde FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de créditos não pode ser alterado.';
END$$
CREATE TRIGGER trg_regra_mensagem_texto BEFORE UPDATE ON mensagem FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mensagem enviada não pode ser editada.';
    END IF;
END$$
CREATE TRIGGER trg_regra_comunidade_mensagem_texto BEFORE UPDATE ON comunidade_mensagem FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mensagem enviada não pode ser editada.';
    END IF;
END$$
CREATE TRIGGER trg_regra_conquista_permanente BEFORE DELETE ON jogador_conquista FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conquista concedida não pode ser revogada.';
    END IF;
END$$
CREATE TRIGGER trg_regra_auditoria_upd BEFORE UPDATE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A auditoria não pode ser alterada.';
END$$
CREATE TRIGGER trg_regra_auditoria_del BEFORE DELETE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A auditoria não pode ser apagada.';
END$$

-- RN94 (migração 030): contestação — sincronização e auditoria
CREATE TRIGGER `trg_sinc_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
CREATE TRIGGER `trg_aud_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'INSERT', NULL,
        JSON_OBJECT('registro_atividade_id', NEW.registro_atividade_id, 'jogador_id', NEW.jogador_id, 'status', NEW.status));
END$$
CREATE TRIGGER `trg_aud_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resposta <=> NEW.resposta) OR NOT (OLD.decidida_por <=> NEW.decidida_por) THEN
        CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resposta', OLD.resposta, 'decidida_por', OLD.decidida_por),
            JSON_OBJECT('status', NEW.status, 'resposta', NEW.resposta, 'decidida_por', NEW.decidida_por));
    END IF;
END$$
-- RN95 (migração 031): funcionários e pedidos de atividade do parceiro — auditoria e sincronização
CREATE TRIGGER `trg_aud_parceiro_funcionario_ins` AFTER INSERT ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'nome', NEW.nome, 'usuario', NEW.usuario, 'ativo', NEW.ativo));
END$$
CREATE TRIGGER `trg_aud_parceiro_funcionario_upd` AFTER UPDATE ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.senha <=> NEW.senha) THEN
        CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'ativo', OLD.ativo),
            JSON_OBJECT('nome', NEW.nome, 'ativo', NEW.ativo, 'senha_trocada', NOT (OLD.senha <=> NEW.senha)));
    END IF;
END$$
CREATE TRIGGER `trg_aud_parceiro_pedido_ins` AFTER INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'status', NEW.status));
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_parceiro_pedido_upd` AFTER UPDATE ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status), JSON_OBJECT('status', NEW.status, 'motivo', NEW.motivo, 'decidido_por', NEW.decidido_por));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
-- RN98 (migração 036): códigos promocionais — sincronização, auditoria e imutabilidade dos resgates
CREATE TRIGGER `trg_sinc_codigo_promo_ins` AFTER INSERT ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_codigo_promo_upd` AFTER UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_codigo_promo_del` AFTER DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ins` AFTER INSERT ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional', NEW.codigo_promocional_id, 'INSERT', NULL,
        JSON_OBJECT('codigo', NEW.codigo, 'nome', NEW.nome, 'xp', NEW.recompensa_xp, 'credito', NEW.recompensa_credito,
                    'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'limite_usos', NEW.limite_usos,
                    'somente_novos', NEW.somente_novos, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER `trg_aud_codigo_promo_upd` AFTER UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    IF NOT (OLD.codigo <=> NEW.codigo) OR NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
       OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.data_inicio <=> NEW.data_inicio)
       OR NOT (OLD.data_fim <=> NEW.data_fim) OR NOT (OLD.limite_usos <=> NEW.limite_usos)
       OR NOT (OLD.somente_novos <=> NEW.somente_novos) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('codigo_promocional', NEW.codigo_promocional_id, 'UPDATE',
            JSON_OBJECT('codigo', OLD.codigo, 'nome', OLD.nome, 'xp', OLD.recompensa_xp, 'credito', OLD.recompensa_credito,
                        'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'limite_usos', OLD.limite_usos,
                        'somente_novos', OLD.somente_novos, 'ativa', OLD.ativa),
            JSON_OBJECT('codigo', NEW.codigo, 'nome', NEW.nome, 'xp', NEW.recompensa_xp, 'credito', NEW.recompensa_credito,
                        'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'limite_usos', NEW.limite_usos,
                        'somente_novos', NEW.somente_novos, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER `trg_aud_codigo_promo_del` AFTER DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional', OLD.codigo_promocional_id, 'DELETE',
        JSON_OBJECT('codigo', OLD.codigo, 'nome', OLD.nome, 'xp', OLD.recompensa_xp, 'credito', OLD.recompensa_credito,
                    'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'limite_usos', OLD.limite_usos,
                    'somente_novos', OLD.somente_novos, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER `trg_aud_codigo_promo_resgate_ins` AFTER INSERT ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_resgate', NEW.resgate_id, 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id, 'jogador_id', NEW.jogador_id,
                    'xp_recebido', NEW.xp_recebido, 'credito_recebido', NEW.credito_recebido));
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_regra_promo_resgate_upd` BEFORE UPDATE ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de resgate promocional não pode ser alterado.';
END$$
CREATE TRIGGER `trg_regra_promo_resgate_del` BEFORE DELETE ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de resgate promocional não pode ser apagado.';
    END IF;
END$$
-- RN99 (migração 037): gestão avançada de contas na administração — auditoria, sincronia e imutabilidade
CREATE TRIGGER `trg_aud_ajuste_admin_ins` AFTER INSERT ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('ajuste_conta_admin', NEW.ajuste_admin_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'jogador_id', NEW.jogador_id, 'administrador_id', NEW.administrador_id,
                    'tipo', NEW.tipo, 'delta_xp', NEW.delta_xp, 'delta_credito', NEW.delta_credito, 'motivo', NEW.motivo));
    IF NEW.jogador_id IS NOT NULL THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_regra_ajuste_admin_upd` BEFORE UPDATE ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de ajuste administrativo não pode ser alterado.';
END$$
CREATE TRIGGER `trg_regra_ajuste_admin_del` BEFORE DELETE ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de ajustes administrativos não pode ser apagado.';
    END IF;
END$$

-- RN101 (migração 040): auditoria detalhada estendida (+27 triggers em 14 tabelas)
CREATE TRIGGER `trg_aud_jogador_ins` AFTER INSERT ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', NEW.jogador_id, 'INSERT', NULL,
        JSON_OBJECT('apelido', NEW.apelido, 'numero_conta', NEW.numero_conta, 'usuario_id', NEW.usuario_id,
                    'nivel_id', NEW.nivel_id, 'creditos_verde', NEW.creditos_verde, 'xp_atual', NEW.xp_atual));
END$$
CREATE TRIGGER `trg_aud_jogador_del` AFTER DELETE ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', OLD.jogador_id, 'DELETE',
        JSON_OBJECT('apelido', OLD.apelido, 'numero_conta', OLD.numero_conta, 'usuario_id', OLD.usuario_id,
                    'nivel_id', OLD.nivel_id, 'creditos_verde', OLD.creditos_verde, 'xp_atual', OLD.xp_atual), NULL);
END$$
CREATE TRIGGER `trg_aud_cidade_upd` AFTER UPDATE ON `cidade` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.nivel_cidade_id <=> NEW.nivel_cidade_id) THEN
        CALL registrar_auditoria('cidade', NEW.cidade_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'nivel_cidade_id', OLD.nivel_cidade_id, 'jogador_id', OLD.jogador_id),
            JSON_OBJECT('nome', NEW.nome, 'nivel_cidade_id', NEW.nivel_cidade_id, 'jogador_id', NEW.jogador_id));
    END IF;
END$$
CREATE TRIGGER `trg_aud_cidade_construcao_ins` AFTER INSERT ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', NEW.cidade_construcao_id, 'INSERT', NULL,
        JSON_OBJECT('cidade_id', NEW.cidade_id, 'construcao_id', NEW.construcao_id,
                    'lote', NEW.lote, 'x', NEW.x, 'y', NEW.y, 'rotacao', NEW.rotacao));
END$$
CREATE TRIGGER `trg_aud_cidade_construcao_del` AFTER DELETE ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', OLD.cidade_construcao_id, 'DELETE',
        JSON_OBJECT('cidade_id', OLD.cidade_id, 'construcao_id', OLD.construcao_id,
                    'lote', OLD.lote, 'x', OLD.x, 'y', OLD.y, 'rotacao', OLD.rotacao), NULL);
END$$
CREATE TRIGGER `trg_aud_registro_atividade_ins` AFTER INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('registro_atividade', NEW.registro_atividade_id, 'INSERT', NULL,
        JSON_OBJECT('jogador_id', NEW.jogador_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id,
                    'tipo_verificacao_id', NEW.tipo_verificacao_id, 'status', NEW.status,
                    'impacto_real', NEW.impacto_real));
END$$
CREATE TRIGGER `trg_aud_comunidade_ins` AFTER INSERT ON `comunidade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade', NEW.comunidade_id, 'INSERT', NULL,
        JSON_OBJECT('nome', NEW.nome, 'entrada', NEW.entrada, 'icone', NEW.icone, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_ins` AFTER INSERT ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'INSERT', NULL,
        JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                    'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                    'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                    'pontos', NEW.pontos, 'ativa', NEW.ativa, 'origem', NEW.origem));
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_upd` AFTER UPDATE ON `comunidade_missao` FOR EACH ROW
BEGIN
    IF NOT (OLD.comunidade_id <=> NEW.comunidade_id) OR NOT (OLD.nome <=> NEW.nome)
       OR NOT (OLD.atividade_sustentavel_id <=> NEW.atividade_sustentavel_id)
       OR NOT (OLD.meta <=> NEW.meta) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
       OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.pontos <=> NEW.pontos)
       OR NOT (OLD.data_inicio <=> NEW.data_inicio) OR NOT (OLD.data_fim <=> NEW.data_fim)
       OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'UPDATE',
            JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                        'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                        'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito,
                        'pontos', OLD.pontos, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim,
                        'ativa', OLD.ativa),
            JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                        'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                        'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                        'pontos', NEW.pontos, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim,
                        'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_del` AFTER DELETE ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', OLD.comunidade_missao_id, 'DELETE',
        JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                    'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                    'ativa', OLD.ativa, 'origem', OLD.origem), NULL);
END$$
CREATE TRIGGER `trg_aud_denuncia_mensagem_upd` AFTER UPDATE ON `denuncia_mensagem` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('denuncia_mensagem', NEW.denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$
CREATE TRIGGER `trg_aud_comunidade_denuncia_upd` AFTER UPDATE ON `comunidade_denuncia` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('comunidade_denuncia', NEW.comunidade_denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$
CREATE TRIGGER `trg_aud_pergunta_ins` AFTER INSERT ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'INSERT', NULL,
        JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
END$$
CREATE TRIGGER `trg_aud_pergunta_upd` AFTER UPDATE ON `pergunta` FOR EACH ROW
BEGIN
    IF NOT (OLD.quiz_id <=> NEW.quiz_id) OR NOT (OLD.enunciado <=> NEW.enunciado)
       OR NOT (OLD.fonte_titulo <=> NEW.fonte_titulo) OR NOT (OLD.fonte_url <=> NEW.fonte_url) THEN
        CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'UPDATE',
            JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado, 'fonte_titulo', OLD.fonte_titulo),
            JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
    END IF;
END$$
CREATE TRIGGER `trg_aud_pergunta_del` AFTER DELETE ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', OLD.pergunta_id, 'DELETE',
        JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado), NULL);
END$$
CREATE TRIGGER `trg_aud_alternativa_ins` AFTER INSERT ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'INSERT', NULL,
        JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
END$$
CREATE TRIGGER `trg_aud_alternativa_upd` AFTER UPDATE ON `alternativa` FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) OR NOT (OLD.correta <=> NEW.correta)
       OR NOT (OLD.explicacao <=> NEW.explicacao) THEN
        CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'UPDATE',
            JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta),
            JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
    END IF;
END$$
CREATE TRIGGER `trg_aud_alternativa_del` AFTER DELETE ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', OLD.alternativa_id, 'DELETE',
        JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta), NULL);
END$$
CREATE TRIGGER `trg_aud_parceiro_atividade_ins` AFTER INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(NEW.parceiro_id, ':', NEW.atividade_sustentavel_id), 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id));
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_parceiro_atividade_del` AFTER DELETE ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(OLD.parceiro_id, ':', OLD.atividade_sustentavel_id), 'DELETE',
        JSON_OBJECT('parceiro_id', OLD.parceiro_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id), NULL);
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_ins` AFTER INSERT ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                    'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_upd` AFTER UPDATE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    IF NOT (OLD.pontos <=> NEW.pontos) THEN
        CALL registrar_auditoria('codigo_promocional_indicador',
            CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'UPDATE',
            JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                        'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos),
            JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                        'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
    END IF;
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_del` AFTER DELETE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(OLD.codigo_promocional_id, ':', OLD.indicador_ambiental_id), 'DELETE',
        JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                    'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos), NULL);
END$$
CREATE TRIGGER `trg_aud_redefinicao_senha_ins` AFTER INSERT ON `redefinicao_senha` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'data_expiracao', NEW.data_expiracao));
END$$
CREATE TRIGGER `trg_aud_redefinicao_senha_upd` AFTER UPDATE ON `redefinicao_senha` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('usuario_id', NEW.usuario_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$
CREATE TRIGGER `trg_aud_redefinicao_parc_ins` AFTER INSERT ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'data_expiracao', NEW.data_expiracao));
END$$
CREATE TRIGGER `trg_aud_redefinicao_parc_upd` AFTER UPDATE ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$
DELIMITER ;

-- =============================================================================
-- EcoCity — 05. Stored procedures e event de manutenção
--   marcar_sincronia / registrar_auditoria  usadas pelos triggers (04)
--   sp_limpar_tentativas_abandonadas(dias)   manutenção (event diário às 4h)
--   sp_resumo_jogador(id)                    relatório de um jogador
--   sp_relatorio_impacto(inicio, fim)        impacto real por atividade no período
--   sp_ranking_comunidades('semana'|'geral') ranking das comunidades
--   sp_verificar_integridade()               conferência usada no plano de recuperação
--   sp_apagar_demonstracao(manter)           apaga os dados de demonstração
-- As regras de pagamento (XP, créditos) ficam no PHP; aqui só relatórios e manutenção.
-- =============================================================================
USE ecocity;
DELIMITER $$

CREATE PROCEDURE marcar_sincronia(IN p_escopo VARCHAR(10), IN p_id INT, IN p_dominio VARCHAR(20))
BEGIN
    IF p_id IS NOT NULL THEN
        INSERT INTO sincronia (escopo, escopo_id, dominio, versao, atualizado_em)
        VALUES (p_escopo, p_id, p_dominio, 1, NOW(3))
        ON DUPLICATE KEY UPDATE versao = versao + 1, atualizado_em = NOW(3);
    END IF;
END$$


-- Grava uma linha de auditoria (chamada pelos triggers). Em manutenção (@ecocity_manutencao = 1) não grava.
CREATE PROCEDURE registrar_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_id VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_antes JSON,
    IN p_depois JSON
)
SQL SECURITY DEFINER
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        INSERT INTO auditoria (
            tabela, registro_id, acao, dados_antes, dados_depois,
            usuario_app, parceiro_app, funcionario_app, usuario_banco, origem, ip, data
        ) VALUES (
            p_tabela,
            p_id,
            p_acao,
            p_antes,
            p_depois,
            @app_usuario_id,
            @app_parceiro_id,
            @app_funcionario_id,
            USER(),
            LEFT(NULLIF(TRIM(CONVERT(@app_origem USING utf8mb4) COLLATE utf8mb4_unicode_ci), _utf8mb4'' COLLATE utf8mb4_unicode_ci), 120),
            LEFT(NULLIF(TRIM(CONVERT(@app_ip USING utf8mb4) COLLATE utf8mb4_unicode_ci), _utf8mb4'' COLLATE utf8mb4_unicode_ci), 45),
            NOW(3)
        );
    END IF;
END$$

-- Tabelas presentes no log de auditoria e totais (consulta controlada pela Administração, RN101)
CREATE PROCEDURE sp_auditoria_tabelas()
SQL SECURITY DEFINER
BEGIN
    SELECT a.tabela, COUNT(*) AS total
      FROM auditoria a
  GROUP BY a.tabela
  ORDER BY total DESC, a.tabela ASC;
END$$

-- Resumo filtrado de eventos de auditoria (KPIs da Administração, RN101)
CREATE PROCEDURE sp_resumo_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_dias INT,
    IN p_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_dias INT DEFAULT 0;
    DECLARE v_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_like VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    SET v_tabela = NULLIF(TRIM(p_tabela), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_acao = NULLIF(UPPER(TRIM(p_acao)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_ator = NULLIF(LOWER(TRIM(p_ator)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_dias = GREATEST(IFNULL(p_dias, 0), 0);
    SET v_busca = NULLIF(TRIM(p_busca), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_like = IF(v_busca IS NULL, NULL, CONCAT(_utf8mb4'%' COLLATE utf8mb4_unicode_ci, v_busca, _utf8mb4'%' COLLATE utf8mb4_unicode_ci));

    SELECT COUNT(*) AS total,
           COALESCE(SUM(a.acao = 'INSERT'), 0) AS inserts,
           COALESCE(SUM(a.acao = 'UPDATE'), 0) AS updates,
           COALESCE(SUM(a.acao = 'DELETE'), 0) AS deletes,
           COALESCE(SUM(a.parceiro_app IS NOT NULL), 0) AS por_parceiro,
           COALESCE(SUM(a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL), 0) AS sql_direto
      FROM auditoria a
      LEFT JOIN usuario u ON u.usuario_id = a.usuario_app
      LEFT JOIN jogador j ON j.usuario_id = a.usuario_app
      LEFT JOIN parceiro p ON p.parceiro_id = a.parceiro_app
      LEFT JOIN parceiro_funcionario pf ON pf.funcionario_id = a.funcionario_app
     WHERE (v_tabela IS NULL OR a.tabela = v_tabela)
       AND (v_acao IS NULL OR a.acao = v_acao)
       AND (v_dias = 0 OR a.data >= NOW() - INTERVAL v_dias DAY)
       AND (
           v_ator IS NULL
           OR (v_ator = _utf8mb4'sql_direto' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL)
           OR (v_ator = _utf8mb4'sistema' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NOT NULL)
           OR (v_ator = _utf8mb4'parceiro' COLLATE utf8mb4_unicode_ci AND a.parceiro_app IS NOT NULL)
           OR (v_ator = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
           OR (v_ator = _utf8mb4'usuario' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
       )
       AND (
           v_like IS NULL
           OR a.tabela LIKE v_like
           OR a.registro_id LIKE v_like
           OR a.usuario_banco LIKE v_like
           OR a.origem LIKE v_like
           OR a.ip LIKE v_like
           OR u.nome LIKE v_like
           OR u.email LIKE v_like
           OR j.apelido LIKE v_like
           OR p.nome LIKE v_like
           OR pf.nome LIKE v_like
           OR pf.usuario LIKE v_like
           OR CAST(a.dados_antes AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
           OR CAST(a.dados_depois AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
       );
END$$

-- Lista paginada e filtrada da auditoria para a Administração (RN101)
CREATE PROCEDURE sp_consultar_auditoria(
    IN p_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_dias INT,
    IN p_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_limite INT,
    IN p_offset INT
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_tabela VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_acao VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_ator VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_dias INT DEFAULT 0;
    DECLARE v_busca VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_like VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_limite INT DEFAULT 20;
    DECLARE v_offset INT DEFAULT 0;

    SET v_tabela = NULLIF(TRIM(p_tabela), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_acao = NULLIF(UPPER(TRIM(p_acao)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_ator = NULLIF(LOWER(TRIM(p_ator)), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_dias = GREATEST(IFNULL(p_dias, 0), 0);
    SET v_busca = NULLIF(TRIM(p_busca), _utf8mb4'' COLLATE utf8mb4_unicode_ci);
    SET v_like = IF(v_busca IS NULL, NULL, CONCAT(_utf8mb4'%' COLLATE utf8mb4_unicode_ci, v_busca, _utf8mb4'%' COLLATE utf8mb4_unicode_ci));
    SET v_limite = LEAST(GREATEST(IFNULL(p_limite, 20), 1), 2000);
    SET v_offset = GREATEST(IFNULL(p_offset, 0), 0);

    SELECT a.auditoria_id,
           a.tabela,
           a.registro_id,
           a.acao,
           a.dados_antes,
           a.dados_depois,
           a.usuario_app,
           a.parceiro_app,
           a.funcionario_app,
           a.usuario_banco,
           a.origem,
           a.ip,
           a.data,
           u.nome AS usuario_nome,
           u.email AS usuario_email,
           j.apelido AS jogador_apelido,
           EXISTS (
               SELECT 1 FROM administrador adm
                WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1
           ) AS eh_admin,
           p.nome AS parceiro_nome,
           p.email AS parceiro_email,
           pf.nome AS funcionario_nome,
           pf.usuario AS funcionario_usuario
      FROM auditoria a
      LEFT JOIN usuario u ON u.usuario_id = a.usuario_app
      LEFT JOIN jogador j ON j.usuario_id = a.usuario_app
      LEFT JOIN parceiro p ON p.parceiro_id = a.parceiro_app
      LEFT JOIN parceiro_funcionario pf ON pf.funcionario_id = a.funcionario_app
     WHERE (v_tabela IS NULL OR a.tabela = v_tabela)
       AND (v_acao IS NULL OR a.acao = v_acao)
       AND (v_dias = 0 OR a.data >= NOW() - INTERVAL v_dias DAY)
       AND (
           v_ator IS NULL
           OR (v_ator = _utf8mb4'sql_direto' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NULL)
           OR (v_ator = _utf8mb4'sistema' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NULL AND a.parceiro_app IS NULL AND a.origem IS NOT NULL)
           OR (v_ator = _utf8mb4'parceiro' COLLATE utf8mb4_unicode_ci AND a.parceiro_app IS NOT NULL)
           OR (v_ator = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
           OR (v_ator = _utf8mb4'usuario' COLLATE utf8mb4_unicode_ci AND a.usuario_app IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM administrador adm WHERE adm.usuario_id = a.usuario_app AND adm.ativo = 1))
       )
       AND (
           v_like IS NULL
           OR a.tabela LIKE v_like
           OR a.registro_id LIKE v_like
           OR a.usuario_banco LIKE v_like
           OR a.origem LIKE v_like
           OR a.ip LIKE v_like
           OR u.nome LIKE v_like
           OR u.email LIKE v_like
           OR j.apelido LIKE v_like
           OR p.nome LIKE v_like
           OR pf.nome LIKE v_like
           OR pf.usuario LIKE v_like
           OR CAST(a.dados_antes AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
           OR CAST(a.dados_depois AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci LIKE v_like
       )
  ORDER BY a.auditoria_id DESC
     LIMIT v_limite OFFSET v_offset;
END$$


-- Limpeza de tentativas de quiz abandonadas há mais de p_dias e registros antigos de rate-limit/tokens (RN82/migração 045)
CREATE PROCEDURE sp_limpar_tentativas_abandonadas(IN p_dias INT)
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id
     WHERE t.acertos IS NULL AND t.data < CURDATE() - INTERVAL p_dias DAY;
    DELETE FROM tentativa_quiz WHERE acertos IS NULL AND data < CURDATE() - INTERVAL p_dias DAY;
    SET v_total = ROW_COUNT();
    DELETE FROM tentativa_acao_ip WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_codigo_parceiro WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_codigo_promocional WHERE data < NOW() - INTERVAL 7 DAY;
    DELETE FROM tentativa_login WHERE sucesso = FALSE AND data < NOW() - INTERVAL 7 DAY;
    DELETE FROM redefinicao_senha WHERE data_expiracao < NOW() - INTERVAL 7 DAY;
    DELETE FROM redefinicao_senha_parceiro WHERE data_expiracao < NOW() - INTERVAL 7 DAY;
    SELECT v_total AS tentativas_apagadas;
END$$

-- Resumo de um jogador: dados, impacto por área/unidade e últimos registros
CREATE PROCEDURE sp_resumo_jogador(IN p_jogador INT)
BEGIN
    SELECT * FROM vw_jogador_resumo WHERE jogador_id = p_jogador;
    SELECT ia.nome AS area,
           a.impacto_real_unidade AS unidade,
           COALESCE(viu.acoes, 0) AS acoes,
           COALESCE(viu.impacto, 0) AS impacto,
           COALESCE(viu.pontos_indicador, 0) AS pontos_indicador
      FROM indicador_ambiental ia
      JOIN atividade_indicador_ambiental ai ON ai.indicador_ambiental_id = ia.indicador_ambiental_id
      JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ai.atividade_sustentavel_id
      LEFT JOIN vw_impacto_jogador_unidade viu
             ON viu.jogador_id = p_jogador
            AND viu.indicador_ambiental_id = ia.indicador_ambiental_id
            AND viu.unidade <=> a.impacto_real_unidade
     GROUP BY ia.indicador_ambiental_id, ia.nome, a.impacto_real_unidade, viu.acoes, viu.impacto, viu.pontos_indicador
     ORDER BY ia.indicador_ambiental_id, MIN(a.atividade_sustentavel_id);
    SELECT atividade, tipo_verificacao, status, data_registro, impacto_efetivo, unidade, pontos_indicador
      FROM vw_registro_detalhado WHERE jogador_id = p_jogador ORDER BY data_registro DESC LIMIT 10;
END$$

-- Impacto real (ações aprovadas) por atividade num período
CREATE PROCEDURE sp_relatorio_impacto(IN p_inicio DATE, IN p_fim DATE)
BEGIN
    IF p_fim < p_inicio THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Período inválido: o fim é anterior ao início.';
    END IF;
    SELECT atividade, unidade, COUNT(*) AS acoes, COUNT(DISTINCT jogador_id) AS jogadores, SUM(impacto_efetivo) AS impacto
      FROM vw_registro_detalhado
     WHERE status = 'aprovado' AND dia BETWEEN p_inicio AND p_fim
     GROUP BY atividade, unidade ORDER BY acoes DESC;
END$$

-- Ranking das comunidades: 'semana' ou 'geral'
CREATE PROCEDURE sp_ranking_comunidades(IN p_periodo VARCHAR(10))
BEGIN
    IF p_periodo = 'geral' THEN
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_geral ORDER BY posicao;
    ELSE
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_semana ORDER BY posicao;
    END IF;
END$$

-- Confere a integridade: saldos × históricos, indicadores, cidades, registros e regras recentes (plano de recuperação)
CREATE PROCEDURE sp_verificar_integridade()
BEGIN
    SELECT 'saldo de créditos diferente da soma do histórico' AS verificacao, COUNT(*) AS problemas
      FROM jogador j WHERE j.creditos_verde <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_credito_verde h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'XP diferente da soma do histórico', COUNT(*)
      FROM jogador j WHERE j.xp_atual <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_xp h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'cidade sem os 5 indicadores', COUNT(*)
      FROM cidade c WHERE (SELECT COUNT(*) FROM cidade_indicador ci WHERE ci.cidade_id = c.cidade_id) <> (SELECT COUNT(*) FROM indicador_ambiental)
    UNION ALL
    SELECT 'jogador sem cidade', COUNT(*) FROM jogador j WHERE NOT EXISTS (SELECT 1 FROM cidade c WHERE c.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'registro aprovado sem data de aprovação', COUNT(*) FROM registro_atividade WHERE status = 'aprovado' AND data_aprovacao IS NULL
    UNION ALL
    SELECT 'pergunta sem exatamente 1 alternativa correta', COUNT(*)
      FROM (SELECT pergunta_id FROM alternativa GROUP BY pergunta_id HAVING SUM(correta) <> 1) x
    UNION ALL
    SELECT 'vínculo de parceiro em atividade que não aceita parceiro', COUNT(*)
      FROM parceiro_atividade pa JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = pa.atividade_sustentavel_id
     WHERE a.aceita_parceiro = 0
    UNION ALL
    SELECT 'código de parceiro em aberto em atividade que não aceita parceiro', COUNT(*)
      FROM codigo_parceiro k JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = k.atividade_sustentavel_id
     WHERE k.usado_em IS NULL AND k.cancelado = 0 AND a.aceita_parceiro = 0
    UNION ALL
    SELECT 'código promocional com resgates acima do limite', COUNT(*)
      FROM codigo_promocional cp
     WHERE cp.limite_usos IS NOT NULL
       AND (SELECT COUNT(*) FROM codigo_promocional_resgate r WHERE r.codigo_promocional_id = cp.codigo_promocional_id) > cp.limite_usos
    UNION ALL
    SELECT 'sistema sem nenhum Administrador Master ativo',
           IF((SELECT COUNT(*) FROM administrador WHERE ativo = 1 AND perfil = 'master') = 0, 1, 0);
END$$

-- Apaga os jogadores de demonstração (usuario.demonstracao = TRUE) e tudo o que é deles.
-- p_manter_apresentacao = TRUE mantém as contas apresentacao01..10@demo.ecocity.test.
CREATE PROCEDURE sp_apagar_demonstracao(IN p_manter_apresentacao BOOLEAN)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET @ecocity_manutencao = 0; RESIGNAL; END;
    SET @ecocity_manutencao = 1;
    START TRANSACTION;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    CREATE TEMPORARY TABLE tmp_demo (jogador_id INT PRIMARY KEY, usuario_id INT, cidade_id INT);
    INSERT INTO tmp_demo
    SELECT j.jogador_id, u.usuario_id, c.cidade_id
      FROM usuario u JOIN jogador j ON j.usuario_id = u.usuario_id JOIN cidade c ON c.jogador_id = j.jogador_id
     WHERE u.demonstracao = TRUE AND NOT (p_manter_apresentacao AND u.email LIKE 'apresentacao%@demo.ecocity.test');
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    CREATE TEMPORARY TABLE tmp_demo_com (comunidade_id INT PRIMARY KEY);
    -- Comunidades criadas por jogadores de demonstração (custo de criação no histórico)
    INSERT IGNORE INTO tmp_demo_com
    SELECT h.comunidade_id FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id
     WHERE h.origem = 'comunidade' AND h.comunidade_id IS NOT NULL;

    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE h FROM historico_xp h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE h FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE t FROM tentativa_quiz t JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE a FROM acesso_diario a JOIN tmp_demo d ON d.jogador_id = a.jogador_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo d ON d.jogador_id = x.jogador_id;
    DELETE p FROM comunidade_presenca p JOIN tmp_demo d ON d.jogador_id = p.jogador_id;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo d ON d.jogador_id = m.jogador_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo d ON d.jogador_id = cd.denunciante_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id OR d.jogador_id = cp.convidado_por;
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo d ON d.jogador_id = cm.jogador_id;
    -- Comunidades da demonstração (com quem mais estiver nelas)
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo_com k ON k.comunidade_id = cm.comunidade_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo_com k ON k.comunidade_id = x.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN tmp_demo_com k ON k.comunidade_id = s.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN comunidade_missao cmi ON cmi.comunidade_missao_id = s.comunidade_missao_id JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    DELETE cmi FROM comunidade_missao cmi JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    UPDATE comunidade_missao cmi JOIN tmp_demo d ON d.jogador_id = cmi.criada_por SET cmi.criada_por = NULL;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo_com k ON k.comunidade_id = m.comunidade_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo_com k ON k.comunidade_id = cp.comunidade_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo_com k ON k.comunidade_id = cd.comunidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo_com k ON s.escopo = 'comunidade' AND s.escopo_id = k.comunidade_id;
    DELETE c FROM comunidade c JOIN tmp_demo_com k ON k.comunidade_id = c.comunidade_id;
    -- Parceiros, registros e o resto do jogador
    DELETE v FROM verificacao_parceiro v JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE cp FROM codigo_parceiro cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id;
    DELETE tc FROM tentativa_codigo_parceiro tc JOIN tmp_demo d ON d.jogador_id = tc.jogador_id;
    DELETE e FROM evidencia_atividade e JOIN registro_atividade r ON r.registro_atividade_id = e.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE r FROM registro_atividade r JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE jm FROM jogador_missao jm JOIN tmp_demo d ON d.jogador_id = jm.jogador_id;
    DELETE jc FROM jogador_conquista jc JOIN tmp_demo d ON d.jogador_id = jc.jogador_id;
    DELETE dm FROM denuncia_mensagem dm JOIN mensagem m ON m.mensagem_id = dm.mensagem_id JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE dm FROM denuncia_mensagem dm JOIN tmp_demo d ON d.jogador_id = dm.denunciante_id;
    DELETE m FROM mensagem m JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE a FROM amizade a JOIN tmp_demo d ON d.jogador_id = a.solicitante_id OR d.jogador_id = a.destinatario_id;
    DELETE b FROM bloqueio b JOIN tmp_demo d ON d.jogador_id = b.bloqueador_id OR d.jogador_id = b.bloqueado_id;
    DELETE s FROM sugestao_dispensada s JOIN tmp_demo d ON d.jogador_id = s.jogador_id OR d.jogador_id = s.sugerido_id;
    DELETE cc FROM cidade_construcao cc JOIN tmp_demo d ON d.cidade_id = cc.cidade_id;
    DELETE h FROM historico_indicador_cidade h JOIN tmp_demo d ON d.cidade_id = h.cidade_id;
    DELETE ci FROM cidade_indicador ci JOIN tmp_demo d ON d.cidade_id = ci.cidade_id;
    DELETE c FROM cidade c JOIN tmp_demo d ON d.cidade_id = c.cidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo d ON s.escopo = 'jogador' AND s.escopo_id = d.jogador_id;
    DELETE ci FROM chat_ia_mensagem ci JOIN tmp_demo d ON d.jogador_id = ci.jogador_id;
    DELETE cpr FROM codigo_promocional_resgate cpr JOIN tmp_demo d ON d.jogador_id = cpr.jogador_id;
    DELETE tcp FROM tentativa_codigo_promocional tcp JOIN tmp_demo d ON d.jogador_id = tcp.jogador_id;
    DELETE aa FROM ajuste_conta_admin aa JOIN tmp_demo d ON d.usuario_id = aa.usuario_id;
    DELETE j FROM jogador j JOIN tmp_demo d ON d.jogador_id = j.jogador_id;
    DELETE rs FROM redefinicao_senha rs JOIN tmp_demo d ON d.usuario_id = rs.usuario_id;
    DELETE tl FROM tentativa_login tl JOIN usuario u ON u.email = tl.email JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    DELETE ad FROM administrador ad JOIN tmp_demo d ON d.usuario_id = ad.usuario_id;
    DELETE u FROM usuario u JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    SELECT COUNT(*) AS jogadores_apagados FROM tmp_demo;
    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    SET @ecocity_manutencao = 0;
END$$

-- Manutenção diária às 4h (precisa de event_scheduler = ON)
CREATE EVENT ev_manutencao_diaria
    ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 4 HOUR)
    DO CALL sp_limpar_tentativas_abandonadas(30)$$

DELIMITER ;
