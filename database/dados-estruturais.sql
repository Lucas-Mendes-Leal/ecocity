-- =============================================================================
-- EcoCity — Dados estruturais (não são dados de jogador)
-- Categorias, indicadores, níveis da cidade e regiões, iguais aos usados no
-- frontend (assets/js/data/catalog.js). Os IDs dos indicadores são fixos e
-- usados pelo PHP (includes/conta.php → INDICADOR_CHAVE).
--
-- Valores PROVISÓRIOS: faixas de pontuação dos níveis (0/25/50/75) e
-- limites dos indicadores (0–100).
-- Pode ser executado mais de uma vez (INSERT IGNORE).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO categoria_ambiental (categoria_ambiental_id, nome, descricao) VALUES
    (1, 'Reciclagem e Resíduos',   'Limpeza das ruas, descarte adequado de lixo e engajamento da comunidade na coleta seletiva.'),
    (2, 'Economia de Água',        'Qualidade da água da cidade, despoluição do lago e uso consciente do abastecimento doméstico.'),
    (3, 'Economia de Energia',     'Emissão de gases poluentes, consumo elétrico racional e uso de energia limpa.'),
    (4, 'Mobilidade Sustentável',  'Qualidade do transporte público, presença de ciclovias e escolha por caminhadas.'),
    (5, 'Preservação da Natureza', 'Saúde da vegetação, conservação da biodiversidade e cobertura de árvores da cidade.');

-- Um indicador por categoria (D4)
INSERT IGNORE INTO indicador_ambiental (indicador_ambiental_id, nome, descricao, valor_maximo, valor_minimo, categoria_ambiental_id) VALUES
    (1, 'Reciclagem e Resíduos',   NULL, 100, 0, 1),
    (2, 'Economia de Água',        NULL, 100, 0, 2),
    (3, 'Economia de Energia',     NULL, 100, 0, 3),
    (4, 'Mobilidade Sustentável',  NULL, 100, 0, 4),
    (5, 'Preservação da Natureza', NULL, 100, 0, 5);

-- Níveis da cidade pela pontuação ecológica geral (D3a / RN22)
INSERT IGNORE INTO nivel_cidade (nivel_cidade_id, nivel, nome, pontuacao_minima) VALUES
    (1, 1, 'Cidade Degradada',      0),
    (2, 2, 'Cidade em Recuperação', 25),
    (3, 3, 'Cidade Sustentável',    50),
    (4, 4, 'Cidade Modelo',         75);

-- Tipos de verificação. 1 = aprovação automática; 2 = foto obrigatória,
-- aprovada por administrador ou automaticamente após 1 dia; 3 = parceiro (ainda não usado).
INSERT IGNORE INTO tipo_verificacao (tipo_verificacao_id, nome, nivel_confiabilidade) VALUES
    (1, 'Autodeclarada',           1),
    (2, 'Com evidência',           2),
    (3, 'Verificada por parceiro', 3);

-- Níveis do jogador 1 a 99 por XP total: 11 x (n-1)^2,5 + 40 x (n-1) (aprovado em 17/11/2026)
INSERT IGNORE INTO nivel_jogador (nivel_jogador_id, nivel, xp_minimo, xp_maximo) VALUES
    (1, 1, 0, 50),
    (2, 2, 51, 141),
    (3, 3, 142, 290),
    (4, 4, 291, 511),
    (5, 5, 512, 814),
    (6, 6, 815, 1209),
    (7, 7, 1210, 1705),
    (8, 8, 1706, 2310),
    (9, 9, 2311, 3032),
    (10, 10, 3033, 3878),
    (11, 11, 3879, 4853),
    (12, 12, 4854, 5966),
    (13, 13, 5967, 7222),
    (14, 14, 7223, 8626),
    (15, 15, 8627, 10185),
    (16, 16, 10186, 11903),
    (17, 17, 11904, 13786),
    (18, 18, 13787, 15840),
    (19, 19, 15841, 18068),
    (20, 20, 18069, 20476),
    (21, 21, 20477, 23069),
    (22, 22, 23070, 25851),
    (23, 23, 25852, 28826),
    (24, 24, 28827, 31999),
    (25, 25, 32000, 35374),
    (26, 26, 35375, 38955),
    (27, 27, 38956, 42747),
    (28, 28, 42748, 46753),
    (29, 29, 46754, 50977),
    (30, 30, 50978, 55424),
    (31, 31, 55425, 60096),
    (32, 32, 60097, 64998),
    (33, 33, 64999, 70133),
    (34, 34, 70134, 75505),
    (35, 35, 75506, 81118),
    (36, 36, 81119, 86975),
    (37, 37, 86976, 93079),
    (38, 38, 93080, 99435),
    (39, 39, 99436, 106044),
    (40, 40, 106045, 112911),
    (41, 41, 112912, 120039),
    (42, 42, 120040, 127431),
    (43, 43, 127432, 135091),
    (44, 44, 135092, 143021),
    (45, 45, 143022, 151224),
    (46, 46, 151225, 159705),
    (47, 47, 159706, 168465),
    (48, 48, 168466, 177507),
    (49, 49, 177508, 186836),
    (50, 50, 186837, 196453),
    (51, 51, 196454, 206362),
    (52, 52, 206363, 216566),
    (53, 53, 216567, 227067),
    (54, 54, 227068, 237868),
    (55, 55, 237869, 248973),
    (56, 56, 248974, 260383),
    (57, 57, 260384, 272103),
    (58, 58, 272104, 284133),
    (59, 59, 284134, 296478),
    (60, 60, 296479, 309139),
    (61, 61, 309140, 322120),
    (62, 62, 322121, 335424),
    (63, 63, 335425, 349052),
    (64, 64, 349053, 363007),
    (65, 65, 363008, 377292),
    (66, 66, 377293, 391910),
    (67, 67, 391911, 406864),
    (68, 68, 406865, 422154),
    (69, 69, 422155, 437785),
    (70, 70, 437786, 453759),
    (71, 71, 453760, 470077),
    (72, 72, 470078, 486744),
    (73, 73, 486745, 503760),
    (74, 74, 503761, 521129),
    (75, 75, 521130, 538852),
    (76, 76, 538853, 556933),
    (77, 77, 556934, 575373),
    (78, 78, 575374, 594176),
    (79, 79, 594177, 613342),
    (80, 80, 613343, 632876),
    (81, 81, 632877, 652778),
    (82, 82, 652779, 673052),
    (83, 83, 673053, 693699),
    (84, 84, 693700, 714721),
    (85, 85, 714722, 736122),
    (86, 86, 736123, 757904),
    (87, 87, 757905, 780067),
    (88, 88, 780068, 802616),
    (89, 89, 802617, 825551),
    (90, 90, 825552, 848876),
    (91, 91, 848877, 872592),
    (92, 92, 872593, 896701),
    (93, 93, 896702, 921206),
    (94, 94, 921207, 946109),
    (95, 95, 946110, 971412),
    (96, 96, 971413, 997117),
    (97, 97, 997118, 1023226),
    (98, 98, 1023227, 1049741),
    (99, 99, 1049742, 2147483647);

-- Jogadores criados antes desta tabela ter dados
UPDATE jogador SET nivel_id = 1 WHERE nivel_id IS NULL;

-- Atividades sustentáveis (catálogo base: 15 atividades, 3 por área).
-- Valores = registro "Com evidência"; "Autodeclarada" paga 50% (includes/registros.php).
INSERT IGNORE INTO atividade_sustentavel (atividade_sustentavel_id, categoria_ambiental_id, nome, descricao, recompensa_xp, recompensa_credito, ativa) VALUES
    (1, 1, 'Separação de Resíduos',    'Separe plástico, papel e metal do lixo orgânico na sua casa durante o dia.', 50, 30, TRUE),
    (2, 2, 'Banho Consciente',         'Reduza o tempo de banho para no máximo 5 minutos hoje.', 60, 40, TRUE),
    (3, 3, 'Luzes Apagadas',           'Mantenha aparelhos fora da tomada e luzes apagadas em ambientes vazios.', 40, 20, TRUE),
    (4, 4, 'Transporte Limpo',         'Caminhe, vá de bicicleta ou use transporte coletivo para um deslocamento.', 80, 50, TRUE),
    (5, 5, 'Plantio de Muda ou Horta', 'Cuide de uma planta, árvore ou horta comunitária na sua região.', 70, 45, TRUE),
    (6, 1, 'Descarte em Ecoponto ou Coleta Especial', 'Leve óleo usado, pilhas, baterias, lâmpadas ou eletrônicos a um ecoponto ou ponto de coleta parceiro.', 80, 50, TRUE),
    (7, 1, 'Compras sem Descartáveis', 'Use sacola reutilizável (ecobag), garrafa ou copo retornável ao fazer compras ou sair de casa.', 45, 25, TRUE),
    (8, 2, 'Reuso de Água em Casa',    'Reaproveite a água da máquina de lavar, do enxágue ou da chuva para lavar quintal, calçada ou regar plantas.', 65, 40, TRUE),
    (9, 2, 'Torneira Fechada na Rotina', 'Mantenha a torneira fechada ao escovar os dentes, ensaboar a louça ou lavar alimentos durante o dia.', 40, 25, TRUE),
    (10, 3, 'Aproveitamento de Luz Natural', 'Abra cortinas e janelas para estudar ou trabalhar usando iluminação natural em vez de lâmpadas acesas.', 45, 25, TRUE),
    (11, 3, 'Secagem ao Ar Livre ou Carga Cheia', 'Lave roupas juntando carga completa na máquina ou seque ao ar livre sem usar secadora elétrica.', 55, 35, TRUE),
    (12, 4, 'Caminhada no Lugar do Carro', 'Faça um trajeto curto (mercado, escola, trabalho ou academia) a pé em vez de usar carro ou moto.', 60, 35, TRUE),
    (13, 4, 'Carona Solidária ou Revisão de Bicicleta', 'Compartilhe carona com colegas ou família, ou faça manutenção na bicicleta para manter o pedal ativo.', 70, 45, TRUE),
    (14, 5, 'Compostagem de Orgânicos', 'Separe cascas, restos de frutas, verduras ou borra de café para composteira doméstica ou ponto de compostagem.', 65, 40, TRUE),
    (15, 5, 'Limpeza de Área Verde ou Calçada', 'Recolha lixo descartado incorretamente em praça, parque, jardim ou na calçada do seu bairro e dê o destino certo.', 75, 50, TRUE);
-- RN92 (migrações 028 e 042): Banho Consciente e Torneira Fechada na Rotina não têm como ser comprovados por foto
UPDATE atividade_sustentavel SET aceita_foto = 0 WHERE atividade_sustentavel_id IN (2, 9);
-- RN69 (migração 043): apenas atividades confirmáveis presencialmente podem ser verificadas por parceiros
UPDATE atividade_sustentavel SET aceita_parceiro = 0 WHERE atividade_sustentavel_id IN (2, 3, 8, 9, 10, 11, 12);

-- Impacto no indicador: 6 com foto, 3 autodeclarada (aprovado em 17/11/2026)
INSERT IGNORE INTO atividade_indicador_ambiental (atividade_sustentavel_id, indicador_ambiental_id, impacto) VALUES
    (1, 1, 6), (2, 2, 6), (3, 3, 6), (4, 4, 6), (5, 5, 6),
    (6, 1, 6), (7, 1, 6), (8, 2, 6), (9, 2, 6), (10, 3, 6),
    (11, 3, 6), (12, 4, 6), (13, 4, 6), (14, 5, 6), (15, 5, 6);

-- Regiões fixas da cidade (D5); imagens: assets/img/locais/{prefixo_imagem}-estagio-{1..4}.png
INSERT IGNORE INTO regiao_cidade (regiao_cidade_id, nome, descricao, indicador_ambiental_id, prefixo_imagem) VALUES
    (1, 'Bairro e Gestão de Resíduos',       'Mede a limpeza das ruas, o descarte adequado de lixo e o engajamento da comunidade na coleta seletiva.', 1, 'reciclagem'),
    (2, 'Lago Urbano e Recursos Hídricos',   'Representa a qualidade da água da cidade, a despoluição do lago e o uso consciente do abastecimento doméstico.', 2, 'lago'),
    (3, 'Atmosfera e Matriz Energética',     'Indica a emissão de gases poluentes, o consumo elétrico racional e o uso de energia limpa.', 3, 'energia'),
    (4, 'Vias e Mobilidade Urbana',          'Mede a qualidade do transporte público, a presença de ciclovias e a escolha por caminhadas.', 4, 'mobilidade'),
    (5, 'Parque Natural e Área Verde',     'Representa a saúde da vegetação, a conservação da biodiversidade e a cobertura de árvores da cidade.', 5, 'parque');
