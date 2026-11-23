-- =============================================================================
-- EcoCity — Migração 008: valores definitivos de progressão (aprovados em 17/11/2026)
-- Importar uma única vez, depois de 007-remover-edificios.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4;

-- Queda diária dos indicadores (-1 por dia): data da última queda aplicada
ALTER TABLE cidade ADD COLUMN ultima_queda DATE NOT NULL DEFAULT (CURRENT_DATE);
UPDATE cidade SET ultima_queda = CURDATE();

-- Níveis do jogador 1 a 99: XP total = 11 x (n-1)^2,5 + 40 x (n-1)
-- (os mínimos atuais viram negativos antes, para não colidir com a chave única)
UPDATE nivel_jogador SET xp_minimo = -nivel_jogador_id;
INSERT INTO nivel_jogador (nivel_jogador_id, nivel, xp_minimo, xp_maximo) VALUES
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
    (99, 99, 1049742, 2147483647)
ON DUPLICATE KEY UPDATE nivel = VALUES(nivel), xp_minimo = VALUES(xp_minimo), xp_maximo = VALUES(xp_maximo);

-- Recalcula o nível de cada jogador pela nova tabela
UPDATE jogador j
   SET j.nivel_id = (SELECT n.nivel_jogador_id FROM nivel_jogador n WHERE n.xp_minimo <= j.xp_atual ORDER BY n.xp_minimo DESC LIMIT 1);

-- Impacto das atividades: 6 com foto (autodeclarada paga 50% = 3)
UPDATE atividade_indicador_ambiental SET impacto = 6;

-- Custos das estruturas
UPDATE construcao SET custo = 100 WHERE construcao_id = 1;
UPDATE construcao SET custo = 200 WHERE construcao_id = 2;
UPDATE construcao SET custo = 300 WHERE construcao_id = 3;
UPDATE construcao SET custo = 350 WHERE construcao_id = 4;
UPDATE construcao SET custo = 400 WHERE construcao_id = 5;
UPDATE construcao SET custo = 550 WHERE construcao_id = 6;
UPDATE construcao SET custo = 700 WHERE construcao_id = 7;
UPDATE construcao SET custo = 850 WHERE construcao_id = 8;
UPDATE construcao SET custo = 1050 WHERE construcao_id = 9;
UPDATE construcao SET custo = 1400 WHERE construcao_id = 10;

-- Quizzes: recompensa base (x1 Fácil, x1,5 Médio, x2 Difícil — aplicado no PHP)
UPDATE quiz SET recompensa_xp = 40, recompensa_credito = 25;

-- Missões: 50% da soma das recompensas base das atividades exigidas
UPDATE missao SET recompensa_xp = 90, recompensa_credito = 60 WHERE missao_id = 1;
UPDATE missao SET recompensa_xp = 75, recompensa_credito = 45 WHERE missao_id = 2;
UPDATE missao SET recompensa_xp = 60, recompensa_credito = 30 WHERE missao_id = 3;
UPDATE missao SET recompensa_xp = 80, recompensa_credito = 50 WHERE missao_id = 4;
UPDATE missao SET recompensa_xp = 35, recompensa_credito = 23 WHERE missao_id = 5;

-- Conquistas por faixa: Fácil 30/50 · Média 100/200 · Difícil 250/500 · Épica 500/1000
UPDATE conquista SET recompensa_xp = 30, recompensa_credito = 50 WHERE conquista_id = 1;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 2;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 3;
UPDATE conquista SET recompensa_xp = 250, recompensa_credito = 500 WHERE conquista_id = 4;
UPDATE conquista SET recompensa_xp = 250, recompensa_credito = 500 WHERE conquista_id = 5;
UPDATE conquista SET recompensa_xp = 250, recompensa_credito = 500 WHERE conquista_id = 6;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 7;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 8;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 9;
UPDATE conquista SET recompensa_xp = 30, recompensa_credito = 50 WHERE conquista_id = 10;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 11;
UPDATE conquista SET recompensa_xp = 100, recompensa_credito = 200 WHERE conquista_id = 12;
UPDATE conquista SET recompensa_xp = 250, recompensa_credito = 500 WHERE conquista_id = 13;
UPDATE conquista SET recompensa_xp = 250, recompensa_credito = 500 WHERE conquista_id = 14;
UPDATE conquista SET recompensa_xp = 500, recompensa_credito = 1000 WHERE conquista_id = 15;
UPDATE conquista SET recompensa_xp = 30, recompensa_credito = 50 WHERE conquista_id = 16;
UPDATE conquista SET recompensa_xp = 500, recompensa_credito = 1000 WHERE conquista_id = 17;
