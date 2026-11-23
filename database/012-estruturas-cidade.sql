-- =============================================================================
-- EcoCity — Migração 012: estruturas de cidade e poluição (17/11/2026)
--  - construcao.categoria: ecologica / vias / comercio / servicos / decoracao
--  - construcao.efeito + indicador_ambiental_id: 'polui' (−1 no indicador a cada 2 dias)
--    ou 'compensa' (anula uma poluidora no mesmo indicador) (RN54)
--  - 29 estruturas novas (IDs 11–39; tamanhos, custos e níveis PROVISÓRIOS)
--  - Conquistas 14 e 17 passam a contar só estruturas ecológicas
-- Importar uma única vez, depois de 011-quiz-tipo.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

ALTER TABLE construcao
    ADD COLUMN categoria VARCHAR(20) NOT NULL DEFAULT 'ecologica',
    ADD COLUMN efeito VARCHAR(10) NULL,
    ADD COLUMN indicador_ambiental_id INT NULL,
    ADD CONSTRAINT ck_construcao_categoria CHECK (categoria IN ('ecologica', 'vias', 'comercio', 'servicos', 'decoracao')),
    ADD CONSTRAINT ck_construcao_efeito CHECK (
        (efeito IS NULL AND indicador_ambiental_id IS NULL)
        OR (efeito IN ('polui', 'compensa') AND indicador_ambiental_id IS NOT NULL)),
    ADD CONSTRAINT fk_construcao_indicador FOREIGN KEY (indicador_ambiental_id) REFERENCES indicador_ambiental (indicador_ambiental_id);

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

-- Conquistas 14 (Construtor Sustentável) e 17 (Arquiteto Verde): só estruturas ecológicas
UPDATE conquista SET tipo_criterio = 'construcoes_ecologicas', descricao = 'Construa 5 estruturas ecológicas.' WHERE conquista_id = 14;
UPDATE conquista SET tipo_criterio = 'construcoes_ecologicas', descricao = 'Tenha 10 estruturas ecológicas construídas na sua cidade.' WHERE conquista_id = 17;
