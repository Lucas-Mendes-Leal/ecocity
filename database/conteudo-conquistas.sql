-- =============================================================================
-- EcoCity — Conquistas iniciais (aprovadas em 16/11/2026, recompensas PROVISÓRIAS)
-- Pode ser executado mais de uma vez (INSERT IGNORE).
--
-- tipo_criterio (avaliado em includes/conquistas.php):
--   atividades_aprovadas        total de registros aprovados
--   atividades_categoria:{ind}  registros aprovados de atividades do indicador {ind}
--   impacto_real:{ind}          soma do impacto real estimado (RN26) das atividades do indicador {ind}
--   indicador:{ind}             valor atual do indicador {ind} da cidade
--   registros_evidencia         registros com foto aprovados
--   quizzes_concluidos          quizzes diferentes concluídos
--   quizzes_perfeitos           quizzes diferentes concluídos com 100% de acertos
--   nivel_cidade / nivel_jogador / construcoes
-- Indicadores: 1 Reciclagem · 2 Água · 3 Energia · 4 Mobilidade · 5 Natureza
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO conquista (conquista_id, nome, descricao, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio) VALUES
    (1,  'Primeiro Passo',         'Tenha sua primeira atividade sustentável aprovada.',            30, 50,  'atividades_aprovadas',     1),
    (2,  'Hábito Verde',           'Tenha 10 atividades sustentáveis aprovadas.',                  100, 200,  'atividades_aprovadas',     10),
    (3,  'Separador Exemplar',     'Tenha 5 atividades de Reciclagem e Resíduos aprovadas.',       100, 200,  'atividades_categoria:1',   5),
    (4,  'Guardião das Águas',     'Economize 10.000 litros de água com suas atividades.',         250, 500, 'impacto_real:2',           10000),
    (5,  'Lago Limpo',             'Leve o indicador de Economia de Água da sua cidade a 75.',     250, 500,  'indicador:2',              75),
    (6,  'Energia Consciente',     'Economize 50 kWh de energia com suas atividades.',             250, 500, 'impacto_real:3',           50),
    (7,  'Pedal Limpo',            'Evite 5 kg de CO₂ com deslocamentos sustentáveis.',            100, 200,  'impacto_real:4',           5),
    (8,  'Mãos na Terra',          'Plante 10 mudas.',                                             100, 200,  'impacto_real:5',           10),
    (9,  'Provas Reais',           'Tenha 5 registros com foto aprovados.',                        100, 200,  'registros_evidencia',      5),
    (10, 'Curioso Ambiental',      'Conclua seu primeiro quiz.',                                   30, 50,  'quizzes_concluidos',       1),
    (11, 'Mestre do Quiz',         'Acerte todas as perguntas de 3 quizzes diferentes.',           100, 200,  'quizzes_perfeitos',        3),
    (12, 'Cidade em Recuperação',  'Leve sua cidade ao nível 2.',                                  100, 200,  'nivel_cidade',             2),
    (13, 'Cidade Modelo',          'Leve sua cidade ao nível 4.',                                  250, 500, 'nivel_cidade',             4),
    (14, 'Construtor Sustentável', 'Construa 5 estruturas ecológicas.',                           250, 500, 'construcoes_ecologicas',   5),
    (15, 'Veterano Verde',         'Alcance o nível 10 de jogador.',                               500, 1000, 'nivel_jogador',            10),
    (18, 'Cliente Verde',          'Tenha 5 atividades verificadas por estabelecimentos parceiros.', 100, 200, 'verificacoes_parceiro', 5);   -- migração 016
