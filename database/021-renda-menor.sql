-- =============================================================================
-- EcoCity — Migração 021 (17/11/2026)
-- Renda do comércio pela metade (decisão do usuário): Balada 5, Posto 4,
-- Bar 3, Mercado 3, Lanchonete 2, Padaria 2, Lava-rápido 2 créditos por dia.
-- O teto de 15 por dia fica no PHP (RENDA_TETO_DIA). Executar uma vez.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

UPDATE construcao SET renda_diaria = 5 WHERE construcao_id = 16;
UPDATE construcao SET renda_diaria = 4 WHERE construcao_id = 20;
UPDATE construcao SET renda_diaria = 3 WHERE construcao_id IN (15, 18);
UPDATE construcao SET renda_diaria = 2 WHERE construcao_id IN (17, 19, 21);
