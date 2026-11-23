-- =============================================================================
-- EcoCity — Migração 003: impacto real estimado das atividades (16/11/2026)
-- Usado na aba "Visão geral" da administração (ex.: litros de água, kWh).
-- Valores ficam NULL até serem definidos pelo responsável pelo projeto.
-- =============================================================================

USE ecocity;

ALTER TABLE atividade_sustentavel
    ADD COLUMN impacto_real_quantidade DECIMAL(10,2) NULL AFTER recompensa_credito,
    ADD COLUMN impacto_real_unidade    VARCHAR(30)   NULL AFTER impacto_real_quantidade;

-- Exemplo para preencher depois (valores NÃO definidos ainda):
-- UPDATE atividade_sustentavel SET impacto_real_quantidade = ?, impacto_real_unidade = 'litros de água'
--  WHERE atividade_sustentavel_id = 2;
