-- =============================================================================
-- EcoCity — Migração 020 (17/11/2026)
-- Efeitos das estruturas na cidade (RN77–RN80; valores PROVISÓRIOS):
--   moradores    → capacidade de habitantes (5 + soma, até 50; só visual)
--   carros, bicicletas, onibus → veículos somados à frota da Mobilidade (até 20)
--   renda_diaria → créditos por dia do comércio, coletados pelo jogador
--                  (acumula até 3 dias; teto de 30 por dia somando tudo)
-- `cidade_construcao.renda_coletada_em`: última coleta (começa na construção).
-- Importar com utf8mb4. Executar uma vez.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

ALTER TABLE construcao
    ADD COLUMN moradores    TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER aparencia,
    ADD COLUMN carros       TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER moradores,
    ADD COLUMN bicicletas   TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER carros,
    ADD COLUMN onibus       TINYINT UNSIGNED  NOT NULL DEFAULT 0 AFTER bicicletas,
    ADD COLUMN renda_diaria SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER onibus,
    ADD CONSTRAINT ck_construcao_efeitos CHECK (moradores <= 20 AND carros <= 5 AND bicicletas <= 5 AND onibus <= 5 AND renda_diaria <= 20);

ALTER TABLE cidade_construcao ADD COLUMN renda_coletada_em DATETIME NULL AFTER data_construcao;
UPDATE cidade_construcao SET renda_coletada_em = data_construcao;
ALTER TABLE cidade_construcao MODIFY renda_coletada_em DATETIME NOT NULL;

-- Moradias
UPDATE construcao SET moradores = 3  WHERE construcao_id = 3;    -- Casa Sustentável
UPDATE construcao SET moradores = 10 WHERE construcao_id = 24;   -- Prédio Residencial
-- Veículos
UPDATE construcao SET carros = 3     WHERE construcao_id = 14;   -- Estacionamento
UPDATE construcao SET bicicletas = 2 WHERE construcao_id = 4;    -- Ciclofaixa
UPDATE construcao SET onibus = 1     WHERE construcao_id = 2;    -- Ponto de Ônibus
-- Renda do comércio (créditos por dia)
UPDATE construcao SET renda_diaria = 10 WHERE construcao_id = 16;        -- Balada
UPDATE construcao SET renda_diaria = 8  WHERE construcao_id = 20;        -- Posto
UPDATE construcao SET renda_diaria = 6  WHERE construcao_id IN (15, 18); -- Bar, Mercado
UPDATE construcao SET renda_diaria = 4  WHERE construcao_id IN (17, 19, 21); -- Lanchonete, Padaria, Lava-rápido
