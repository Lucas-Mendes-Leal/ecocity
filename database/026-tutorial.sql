-- =============================================================================
-- EcoCity — Migração 026: tutorial de boas-vindas (RN90, 18/11/2026)
-- jogador.tutorial_visto: quando o jogador concluiu ou pulou o tutorial (NULL = ainda não viu).
-- As contas que já existem ficam como "já viu": o tutorial aparece só para contas novas.
-- Aplicar com --default-character-set=utf8mb4.
-- =============================================================================
USE ecocity;

ALTER TABLE jogador ADD COLUMN tutorial_visto DATETIME NULL AFTER saiu_comunidade_em;

UPDATE jogador SET tutorial_visto = NOW() WHERE tutorial_visto IS NULL;
