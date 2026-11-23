-- =============================================================================
-- EcoCity — Migração 033: volume individual por tipo de som (RN96, 19/11/2026)
--
-- jogador.som_volume_efeitos   — 0 a 100; NULL = padrão (100)
-- jogador.som_volume_interface — 0 a 100; NULL = padrão (30)
-- jogador.som_volume_ambiente  — 0 a 100; NULL = padrão (60)
--
-- Só apresentação: nenhuma regra do jogo depende destas colunas. Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE `jogador`
  ADD COLUMN `som_volume_efeitos` tinyint unsigned DEFAULT NULL,
  ADD COLUMN `som_volume_interface` tinyint unsigned DEFAULT NULL,
  ADD COLUMN `som_volume_ambiente` tinyint unsigned DEFAULT NULL,
  ADD CONSTRAINT `ck_jogador_som_vol_efeitos` CHECK (`som_volume_efeitos` IS NULL OR `som_volume_efeitos` <= 100),
  ADD CONSTRAINT `ck_jogador_som_vol_interface` CHECK (`som_volume_interface` IS NULL OR `som_volume_interface` <= 100),
  ADD CONSTRAINT `ck_jogador_som_vol_ambiente` CHECK (`som_volume_ambiente` IS NULL OR `som_volume_ambiente` <= 100);
