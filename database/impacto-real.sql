-- =============================================================================
-- EcoCity — Impacto real estimado por atividade aprovada (16/11/2026)
-- ESTIMATIVAS PROVISÓRIAS aprovadas pelo usuário, com base em fontes secundárias.
-- Fontes e premissas registradas em memoria.md e specs/site.md (RN26).
-- Pode ser executado mais de uma vez.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

-- Separação de Resíduos: ~1,05 kg/pessoa/dia (Abrema 2025) × ~30% recicláveis secos (premissa)
UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.30, impacto_real_unidade = 'kg de resíduos reciclados'
 WHERE atividade_sustentavel_id = 1;

-- Banho Consciente: banho de 15 → 5 min com ducha economiza ~90 L (Sabesp, citada por terceiros)
UPDATE atividade_sustentavel SET impacto_real_quantidade = 90.00, impacto_real_unidade = 'litros de água'
 WHERE atividade_sustentavel_id = 2;

-- Luzes Apagadas: standby até 12% do consumo residencial (Procel) × ~182 kWh/mês (EPE) ÷ 30 dias
UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.70, impacto_real_unidade = 'kWh de energia'
 WHERE atividade_sustentavel_id = 3;

-- Transporte Limpo: 122,48 g CO2/km (carro a gasolina, base EPE) × 5 km por deslocamento (premissa)
UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.60, impacto_real_unidade = 'kg de CO₂ evitado'
 WHERE atividade_sustentavel_id = 4;

-- Plantio de Muda ou Horta: 1 muda por atividade
UPDATE atividade_sustentavel SET impacto_real_quantidade = 1.00, impacto_real_unidade = 'mudas plantadas'
 WHERE atividade_sustentavel_id = 5;

-- Migração 042 (22/11/2026): 10 novas atividades (IDs 6..15)
UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.50, impacto_real_unidade = 'kg de resíduos reciclados'
 WHERE atividade_sustentavel_id = 6;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.15, impacto_real_unidade = 'kg de resíduos evitados'
 WHERE atividade_sustentavel_id = 7;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 50.00, impacto_real_unidade = 'litros de água'
 WHERE atividade_sustentavel_id = 8;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 30.00, impacto_real_unidade = 'litros de água'
 WHERE atividade_sustentavel_id = 9;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.50, impacto_real_unidade = 'kWh de energia'
 WHERE atividade_sustentavel_id = 10;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 1.20, impacto_real_unidade = 'kWh de energia'
 WHERE atividade_sustentavel_id = 11;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.35, impacto_real_unidade = 'kg de CO₂ evitado'
 WHERE atividade_sustentavel_id = 12;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.80, impacto_real_unidade = 'kg de CO₂ evitado'
 WHERE atividade_sustentavel_id = 13;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.40, impacto_real_unidade = 'kg de orgânicos compostados'
 WHERE atividade_sustentavel_id = 14;

UPDATE atividade_sustentavel SET impacto_real_quantidade = 0.50, impacto_real_unidade = 'kg de resíduos coletados'
 WHERE atividade_sustentavel_id = 15;
