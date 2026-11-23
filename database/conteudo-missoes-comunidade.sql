-- =============================================================================
-- EcoCity — Missões oficiais de comunidade (17/11/2026; valores aprovados e revisados no mesmo dia)
-- Semanais (segunda a domingo) a partir de 16/11/2026, iguais para todas as comunidades.
-- Metas em impacto real (RN26). Referência: 30 membros fazem no máximo 7 registros
-- de cada atividade por semana (RN44) → Separação 63 kg, Banho 18.900 L, Luzes 147 kWh,
-- Transporte 126 kg CO₂, Plantio 210 mudas. O Mutirão (15 kg = 50 ações) é de propósito a mais difícil.
-- Importar depois de 015-comunidades.sql. Pode ser executado mais de uma vez (INSERT IGNORE).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO comunidade_missao
    (comunidade_missao_id, comunidade_id, nome, descricao, atividade_sustentavel_id, meta, recompensa_xp, recompensa_credito, pontos, data_inicio, data_fim, ativa) VALUES
    (1, NULL, 'Mutirão da Reciclagem', 'Juntos, separem 15 kg de resíduos recicláveis nesta semana.', 1,   15.00, 60, 40, 100, '2026-11-16', NULL, TRUE),
    (2, NULL, 'Água que Sobra',        'Economizem juntos 2.000 litros de água com banhos conscientes.', 2, 2000.00, 60, 40, 100, '2026-11-16', NULL, TRUE),
    (3, NULL, 'Apagão do Bem',         'Economizem 15 kWh deixando luzes e aparelhos desligados.',     3,   15.00, 60, 40, 100, '2026-11-16', NULL, TRUE),
    (4, NULL, 'Rota Limpa',            'Evitem 12 kg de CO₂ com deslocamentos sustentáveis.',          4,   12.00, 60, 40, 100, '2026-11-16', NULL, TRUE),
    (5, NULL, 'Floresta Coletiva',     'Plantem juntos 10 mudas ou cuidem de hortas.',                 5,   10.00, 60, 40, 100, '2026-11-16', NULL, TRUE);
