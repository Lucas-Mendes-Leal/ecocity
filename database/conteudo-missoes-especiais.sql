-- =============================================================================
-- EcoCity — Missões especiais do calendário ambiental de 2027
-- e a conquista "Participante de Eventos" (RN75). Escritas pelo Claude e revisadas
-- em 17/11/2026. A data da Hora do Planeta 2027 ainda não foi anunciada (confirmar).
-- Pode ser executado mais de uma vez (INSERT IGNORE). Importar com utf8mb4.
--
-- Regras (specs/site.md RN33–RN36 e RN75):
--   * tipo_missao_id 2 = Especial: vale de data_inicio a data_fim (inclusive).
--   * Sem penalidade (penalidade_indicador = 0).
--   * Cada meta vai até o nº de dias do evento (1 registro por atividade por dia, RN44).
--   * Recompensa = 100% da soma das recompensas das atividades exigidas
--     (Separação 50/30 · Banho 60/40 · Luzes 40/20 · Transporte 80/50 · Plantio 70/45).
-- Atividades: 1 Separação de Resíduos · 2 Banho Consciente · 3 Luzes Apagadas
--             4 Transporte Limpo · 5 Plantio de Muda ou Horta
-- IDs 101+ para não colidir com missões criadas pela administração.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO missao (missao_id, tipo_missao_id, nome, descricao, recompensa_xp, recompensa_credito, penalidade_indicador, data_inicio, data_fim, ativa) VALUES
    (101, 2, 'Semana do Dia da Água',  'Dia Mundial da Água (22/03): tome banhos curtos em 5 dias desta semana.',                          300, 200, 0, '2027-03-20', '2027-03-26', TRUE),
    (102, 2, 'Hora do Planeta',         'Hora do Planeta (último sábado de março): apague as luzes que não estiver usando hoje.',            40,  20, 0, '2027-03-27', '2027-03-27', TRUE),
    (103, 2, 'Dia da Terra',            'Dia da Terra (22/04): pratique ações de todas as áreas em 4 dias.',                                530, 325, 0, '2027-04-22', '2027-04-25', TRUE),
    (104, 2, 'Semana do Meio Ambiente', 'Dia Mundial do Meio Ambiente (05/06): 3 ações de cada área ao longo da semana.',                    900, 555, 0, '2027-06-01', '2027-06-07', TRUE),
    (105, 2, 'Dia Mundial da Limpeza',  'Dia Mundial da Limpeza (terceiro sábado de setembro): separe os resíduos nos 3 dias da missão (sábado a segunda).', 150,  90, 0, '2027-09-18', '2027-09-20', TRUE),
    (106, 2, 'Semana da Árvore',        'Dia da Árvore (21/09): plante uma muda ou cuide de uma horta em 3 dias da semana.',                210, 135, 0, '2027-09-21', '2027-09-27', TRUE);

INSERT IGNORE INTO missao_atividade (missao_atividade_id, missao_id, atividade_sustentavel_id, quantidade_necessaria) VALUES
    (101, 101, 2, 5),
    (102, 102, 3, 1),
    (103, 103, 1, 2),
    (104, 103, 2, 2),
    (105, 103, 3, 2),
    (106, 103, 4, 2),
    (107, 103, 5, 1),
    (108, 104, 1, 3),
    (109, 104, 2, 3),
    (110, 104, 3, 3),
    (111, 104, 4, 3),
    (112, 104, 5, 3),
    (113, 105, 1, 3),
    (114, 106, 5, 3);

-- Conquista de eventos (critério missoes_especiais = missões especiais concluídas; faixa Média)
INSERT IGNORE INTO conquista (conquista_id, nome, descricao, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio) VALUES
    (19, 'Participante de Eventos', 'Conclua 3 missões especiais.', 100, 200, 'missoes_especiais', 3);
