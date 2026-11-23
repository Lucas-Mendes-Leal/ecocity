-- =============================================================================
-- EcoCity — Missões iniciais (aprovadas em 16/11/2026, valores PROVISÓRIOS)
-- Semanais: período de segunda a domingo, a partir de 16/11/2026, sem data de término.
-- Pode ser executado mais de uma vez (INSERT IGNORE).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO tipo_missao (tipo_missao_id, tipo) VALUES
    (1, 'Semanal'),
    (2, 'Especial');

INSERT IGNORE INTO missao (missao_id, tipo_missao_id, nome, descricao, recompensa_xp, recompensa_credito, penalidade_indicador, data_inicio, data_fim, ativa) VALUES
    (1, 1, 'Semana da Água',             'Faça 3 banhos conscientes nesta semana.',                                    90, 60, 5, '2026-11-16', NULL, FALSE),
    (2, 1, 'Separação em Dia',           'Separe os resíduos da sua casa 3 vezes nesta semana.',                        75, 45, 5, '2026-11-16', NULL, FALSE),
    (3, 1, 'Energia em Foco',            'Mantenha luzes e aparelhos desligados 3 vezes nesta semana.',                 60, 30, 5, '2026-11-16', NULL, FALSE),
    (4, 1, 'Mobilidade Ativa',           'Faça 2 deslocamentos sustentáveis nesta semana.',                             80, 50, 5, '2026-11-16', NULL, FALSE),
    (5, 1, 'Verde na Semana',            'Plante uma muda ou cuide de uma horta nesta semana.',                         35, 23, 5, '2026-11-16', NULL, FALSE),
    (6, 1, 'Casa Econômica',             'Combine economia de água e energia na rotina de casa nesta semana.',         140, 85, 5, '2026-11-22', NULL, TRUE),
    (7, 1, 'Rotina Lixo Zero',           'Reduza descartáveis, separe recicláveis e dê destino certo a resíduos especiais.', 113, 68, 5, '2026-11-22', NULL, TRUE),
    (8, 1, 'Energia & Água Inteligente', 'Aproveite luz natural, seque ao ar livre e reaproveite água em casa.',       105, 63, 5, '2026-11-22', NULL, TRUE),
    (9, 1, 'Cidade em Movimento',        'Troque o carro por caminhada, transporte limpo, carona ou bicicleta nesta semana.', 135, 83, 5, '2026-11-22', NULL, TRUE),
    (10, 1, 'Guardião do Bairro',        'Cuide do verde, transforme orgânicos em composto e ajude a manter o bairro limpo.', 105, 68, 5, '2026-11-22', NULL, TRUE);

INSERT IGNORE INTO missao_atividade (missao_atividade_id, missao_id, atividade_sustentavel_id, quantidade_necessaria) VALUES
    (1, 1, 2, 3),
    (2, 2, 1, 3),
    (3, 3, 3, 3),
    (4, 4, 4, 2),
    (5, 5, 5, 1),
    (6, 6, 2, 2),
    (7, 6, 9, 2),
    (8, 6, 3, 2),
    (9, 7, 1, 2),
    (10, 7, 7, 1),
    (11, 7, 6, 1),
    (12, 8, 10, 2),
    (13, 8, 11, 1),
    (14, 8, 8, 1),
    (15, 9, 12, 2),
    (16, 9, 4, 1),
    (17, 9, 13, 1),
    (18, 10, 5, 1),
    (19, 10, 14, 1),
    (20, 10, 15, 1);
