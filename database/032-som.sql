-- =============================================================================
-- EcoCity — Migração 032: preferências de som do jogador (RN96, 18/11/2026)
--
-- jogador.som_mudo      — botão de alto-falante do topo (silencia tudo)
-- jogador.som_efeitos   — efeitos de recompensa e ações
-- jogador.som_interface — cliques de abas e janelas
-- jogador.som_ambiente  — ambiente da cidade (pássaros, grilos, trânsito); desligado por padrão
-- jogador.som_volume    — 0 a 100; NULL = padrão (60, ou 40 com "reduzir movimento" no aparelho)
--
-- Só apresentação: nenhuma regra do jogo depende destas colunas. Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `jogador`
  ADD COLUMN `som_mudo` tinyint(1) NOT NULL DEFAULT '0',
  ADD COLUMN `som_efeitos` tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN `som_interface` tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN `som_ambiente` tinyint(1) NOT NULL DEFAULT '0',
  ADD COLUMN `som_volume` tinyint unsigned DEFAULT NULL,
  ADD CONSTRAINT `ck_jogador_som_volume` CHECK (`som_volume` IS NULL OR `som_volume` <= 100);
