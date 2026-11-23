-- =============================================================================
-- EcoCity — Catálogo de construções e conquistas de construção
-- Aprovados em 16/11/2026; tamanhos, custos e níveis PROVISÓRIOS.
-- Estruturas são decorativas (não alteram indicadores); contam para conquistas.
-- Pode ser executado mais de uma vez (INSERT IGNORE).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO construcao (construcao_id, nome, descricao, largura, profundidade, custo, nivel_cidade_minimo) VALUES
    (1,  'Lixeiras de Coleta',       'Conjunto de lixeiras de coleta seletiva.',          1, 1, 100,  2),
    (2,  'Ponto de Ônibus Elétrico', 'Abrigo para quem usa o transporte coletivo.',       1, 2, 200,  2),
    (3,  'Casa Sustentável',         'Moradia com telhado verde e painel solar.',         2, 2, 300,  2),
    (4,  'Ciclofaixa',               'Trecho de faixa exclusiva para bicicletas.',        1, 5, 350,  2),
    (5,  'Horta Comunitária',        'Canteiros cuidados pela vizinhança.',               3, 2, 400,  2),
    (6,  'Praça Arborizada',         'Praça com árvores e bancos.',                       3, 3, 550,  2),
    (7,  'Pomar',                    'Árvores frutíferas para a comunidade.',             4, 3, 700, 3),
    (8,  'Mini Usina Solar',         'Pequeno conjunto de painéis solares.',              3, 2, 850, 3),
    (9,  'Lago Artificial',          'Espelho d''água com vegetação nas margens.',        4, 4, 1050, 4),
    (10, 'Escola Verde',             'Escola com telhado verde e horta.',                 4, 3, 1400, 4);

-- Critério 'construcoes' = estruturas atualmente na cidade (includes/conquistas.php)
INSERT IGNORE INTO conquista (conquista_id, nome, descricao, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio) VALUES
    (16, 'Primeira Construção', 'Coloque sua primeira estrutura em um lote de expansão.', 30, 50, 'construcoes', 1),
    (17, 'Arquiteto Verde',     'Tenha 10 estruturas ecológicas construídas na sua cidade.', 500, 1000, 'construcoes_ecologicas', 10);

-- Estruturas de cidade e efeitos (migração 012; categorias, efeitos e valores PROVISÓRIOS)
-- Ecológicas que compensam (1 Reciclagem, 2 Água, 3 Energia, 4 Mobilidade, 5 Natureza)
UPDATE construcao SET efeito = 'compensa', indicador_ambiental_id = 1 WHERE construcao_id = 1;
UPDATE construcao SET efeito = 'compensa', indicador_ambiental_id = 4 WHERE construcao_id IN (2, 4);
UPDATE construcao SET efeito = 'compensa', indicador_ambiental_id = 3 WHERE construcao_id IN (3, 8);
UPDATE construcao SET efeito = 'compensa', indicador_ambiental_id = 5 WHERE construcao_id IN (5, 6, 7);
UPDATE construcao SET efeito = 'compensa', indicador_ambiental_id = 2 WHERE construcao_id = 9;

INSERT IGNORE INTO construcao (construcao_id, nome, descricao, largura, profundidade, custo, nivel_cidade_minimo, categoria, efeito, indicador_ambiental_id) VALUES
    -- Vias
    (11, 'Rua',                  'Trecho de rua reto; gire para mudar a direção.',              1, 1, 20,   2, 'vias',      NULL,    NULL),
    (12, 'Cruzamento',           'Encontro de duas ruas.',                                      1, 1, 25,   2, 'vias',      NULL,    NULL),
    (13, 'Calçada',              'Piso para pedestres.',                                        1, 1, 10,   2, 'vias',      NULL,    NULL),
    (14, 'Estacionamento',       'Vagas para carros. Mais carros, mais poluição.',              3, 2, 250,  2, 'vias',      'polui', 4),
    -- Comércio e lazer
    (15, 'Bar',                  'Ponto de encontro do bairro.',                                2, 2, 300,  2, 'comercio',  NULL,    NULL),
    (16, 'Balada',               'Casa noturna com luzes e som a noite toda.',                  3, 3, 600,  3, 'comercio',  'polui', 3),
    (17, 'Lanchonete',           'Lanches rápidos em embalagens descartáveis.',                 2, 2, 250,  2, 'comercio',  'polui', 1),
    (18, 'Mercado',              'Compras do dia a dia, com muitas embalagens.',                3, 2, 450,  2, 'comercio',  'polui', 1),
    (19, 'Padaria',              'Pão quentinho para a vizinhança.',                            2, 2, 250,  2, 'comercio',  NULL,    NULL),
    (20, 'Posto de Combustível', 'Abastecimento de carros a combustão.',                        3, 3, 500,  2, 'comercio',  'polui', 4),
    (21, 'Lava-rápido',          'Lavagem de carros, que gasta muita água.',                    2, 2, 300,  2, 'comercio',  'polui', 2),
    (22, 'Quadra Esportiva',     'Quadra aberta para jogos e esportes.',                        4, 3, 400,  2, 'comercio',  NULL,    NULL),
    (23, 'Parquinho',            'Brinquedos para as crianças.',                                2, 2, 200,  2, 'comercio',  NULL,    NULL),
    -- Serviços e moradia
    (24, 'Prédio Residencial',   'Prédio de apartamentos.',                                     2, 2, 500,  2, 'servicos',  NULL,    NULL),
    (25, 'Hospital',             'Atendimento de saúde para a cidade.',                         4, 3, 900,  3, 'servicos',  NULL,    NULL),
    (26, 'Corpo de Bombeiros',   'Quartel dos bombeiros.',                                      3, 3, 700,  3, 'servicos',  NULL,    NULL),
    (27, 'Delegacia',            'Segurança para os moradores.',                                3, 2, 600,  3, 'servicos',  NULL,    NULL),
    (28, 'Prefeitura',           'Sede do governo da cidade.',                                  4, 4, 1200, 4, 'servicos',  NULL,    NULL),
    -- Decoração
    (29, 'Arbusto',              'Arbusto para enfeitar o lote.',                               1, 1, 15,   2, 'decoracao', NULL,    NULL),
    (30, 'Árvore',               'Uma árvore para fazer sombra.',                               1, 1, 25,   2, 'decoracao', NULL,    NULL),
    (31, 'Canteiro de Flores',   'Flores coloridas.',                                           1, 1, 20,   2, 'decoracao', NULL,    NULL),
    (32, 'Poste de Luz',         'Iluminação para a rua.',                                      1, 1, 15,   2, 'decoracao', NULL,    NULL),
    (33, 'Banco',                'Banco para descansar.',                                       1, 1, 20,   2, 'decoracao', NULL,    NULL),
    (34, 'Cerca',                'Trecho de cerca de madeira; gire para mudar a direção.',      1, 1, 10,   2, 'decoracao', NULL,    NULL),
    (35, 'Fonte',                'Fonte de água decorativa.',                                   2, 2, 150,  2, 'decoracao', NULL,    NULL);

-- Desenho de cada estrutura no mapa (migração 018)
UPDATE construcao SET desenho = ELT(construcao_id,
    'lixeiras', 'ponto_onibus', 'casa', 'ciclofaixa', 'horta', 'praca', 'pomar', 'mini_usina', 'lago', 'escola',
    'rua', 'cruzamento', 'calcada', 'estacionamento',
    'bar', 'balada', 'lanchonete', 'mercado', 'padaria', 'posto', 'lava_rapido', 'quadra', 'parquinho',
    'predio', 'hospital', 'bombeiros', 'delegacia', 'prefeitura',
    'arbusto', 'arvore', 'canteiro', 'poste', 'banco', 'cerca', 'fonte')
 WHERE construcao_id BETWEEN 1 AND 35 AND desenho IS NULL;

-- Peças de avenida 4×4 (migração 019; custos PROVISÓRIOS)
INSERT IGNORE INTO construcao (construcao_id, nome, descricao, largura, profundidade, custo, nivel_cidade_minimo, categoria, desenho) VALUES
    (36, 'Avenida',               'Trecho reto de avenida, igual às avenidas do centro.',             4, 4, 150, 2, 'vias', 'avenida'),
    (37, 'Cruzamento de Avenida', 'Cruzamento de duas avenidas, com faixas de pedestres.',           4, 4, 200, 2, 'vias', 'avenida_cruzamento'),
    (38, 'Curva de Avenida',      'Curva de 90° para mudar a direção da avenida.',                    4, 4, 150, 2, 'vias', 'avenida_curva'),
    (39, 'Avenida em T',          'Encontro de três trechos de avenida.',                             4, 4, 180, 2, 'vias', 'avenida_t');

-- Efeitos na cidade (migrações 020 e 021; valores aprovados em 17/11/2026): moradores, veículos e renda por dia
UPDATE construcao SET moradores = 3  WHERE construcao_id = 3;
UPDATE construcao SET moradores = 10 WHERE construcao_id = 24;
UPDATE construcao SET carros = 3     WHERE construcao_id = 14;
UPDATE construcao SET bicicletas = 2 WHERE construcao_id = 4;
UPDATE construcao SET onibus = 1     WHERE construcao_id = 2;
UPDATE construcao SET renda_diaria = 5 WHERE construcao_id = 16;
UPDATE construcao SET renda_diaria = 4 WHERE construcao_id = 20;
UPDATE construcao SET renda_diaria = 3 WHERE construcao_id IN (15, 18);
UPDATE construcao SET renda_diaria = 2 WHERE construcao_id IN (17, 19, 21);   -- migração 021: renda pela metade
