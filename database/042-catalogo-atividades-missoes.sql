-- =============================================================================
-- EcoCity — Migração 042: Expansão do catálogo (15 atividades, 3 por área),
-- vínculos de parceiros e 5 missões semanais elaboradas (3 metas cada).
--
-- 1. Adiciona 10 novas atividades em `atividade_sustentavel` (IDs 6..15),
--    somando 15 atividades ativas (3 por categoria ambiental), todas com
--    impacto real definido (elegíveis para verificação por parceiros).
--    Marca `Torneira Fechada na Rotina` (ID 9) como aceita_foto = 0 (RN92).
-- 2. Vincula os impactos ambientais (+6 com foto / +3 autodeclarada) em
--    `atividade_indicador_ambiental`.
-- 3. Desativa as 5 missões semanais simples iniciais (IDs 1..5, preservando
--    histórico em `jogador_missao`) e adiciona 5 missões semanais elaboradas
--    (IDs 6..10) com 15 metas em `missao_atividade` (3 atividades distintas
--    por missão, recompensa extra = 50% da soma das recompensas base).
-- 4. Vincula novas atividades aos parceiros aprovados correspondentes de forma
--    segura (INSERT IGNORE ... SELECT apenas se o parceiro existir e estiver
--    aprovado).
--
-- Aplicar em utf8mb4 no banco ecocity. Idempotente (INSERT IGNORE).
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. 10 novas atividades sustentáveis (IDs 6..15 -> total 15, 3 por área)
INSERT IGNORE INTO `atividade_sustentavel`
    (`atividade_sustentavel_id`, `categoria_ambiental_id`, `nome`, `descricao`,
     `recompensa_xp`, `recompensa_credito`, `impacto_real_quantidade`, `impacto_real_unidade`,
     `aceita_foto`, `ativa`)
VALUES
    -- Categoria 1: Reciclagem e Resíduos (já tem 1: Separação de Resíduos)
    (6, 1, 'Descarte em Ecoponto ou Coleta Especial',
     'Leve óleo usado, pilhas, baterias, lâmpadas ou eletrônicos a um ecoponto ou ponto de coleta parceiro.',
     80, 50, 0.50, 'kg de resíduos reciclados', 1, 1),
    (7, 1, 'Compras sem Descartáveis',
     'Use sacola reutilizável (ecobag), garrafa ou copo retornável ao fazer compras ou sair de casa.',
     45, 25, 0.15, 'kg de resíduos evitados', 1, 1),

    -- Categoria 2: Economia de Água (já tem 2: Banho Consciente, aceita_foto = 0)
    (8, 2, 'Reuso de Água em Casa',
     'Reaproveite a água da máquina de lavar, do enxágue ou da chuva para lavar quintal, calçada ou regar plantas.',
     65, 40, 50.00, 'litros de água', 1, 1),
    (9, 2, 'Torneira Fechada na Rotina',
     'Mantenha a torneira fechada ao escovar os dentes, ensaboar a louça ou lavar alimentos durante o dia.',
     40, 25, 30.00, 'litros de água', 0, 1),

    -- Categoria 3: Economia de Energia (já tem 3: Luzes Apagadas)
    (10, 3, 'Aproveitamento de Luz Natural',
     'Abra cortinas e janelas para estudar ou trabalhar usando iluminação natural em vez de lâmpadas acesas.',
     45, 25, 0.50, 'kWh de energia', 1, 1),
    (11, 3, 'Secagem ao Ar Livre ou Carga Cheia',
     'Lave roupas juntando carga completa na máquina ou seque ao ar livre sem usar secadora elétrica.',
     55, 35, 1.20, 'kWh de energia', 1, 1),

    -- Categoria 4: Mobilidade Sustentável (já tem 4: Transporte Limpo)
    (12, 4, 'Caminhada no Lugar do Carro',
     'Faça um trajeto curto (mercado, escola, trabalho ou academia) a pé em vez de usar carro ou moto.',
     60, 35, 0.35, 'kg de CO₂ evitado', 1, 1),
    (13, 4, 'Carona Solidária ou Revisão de Bicicleta',
     'Compartilhe carona com colegas ou família, ou faça manutenção na bicicleta para manter o pedal ativo.',
     70, 45, 0.80, 'kg de CO₂ evitado', 1, 1),

    -- Categoria 5: Preservação da Natureza (já tem 5: Plantio de Muda ou Horta)
    (14, 5, 'Compostagem de Orgânicos',
     'Separe cascas, restos de frutas, verduras ou borra de café para composteira doméstica ou ponto de compostagem.',
     65, 40, 0.40, 'kg de orgânicos compostados', 1, 1),
    (15, 5, 'Limpeza de Área Verde ou Calçada',
     'Recolha lixo descartado incorretamente em praça, parque, jardim ou na calçada do seu bairro e dê o destino certo.',
     75, 50, 0.50, 'kg de resíduos coletados', 1, 1);

-- 2. Impacto nos indicadores ambientais (+6 com foto / +3 autodeclarada)
INSERT IGNORE INTO `atividade_indicador_ambiental` (`atividade_sustentavel_id`, `indicador_ambiental_id`, `impacto`) VALUES
    (6, 1, 6),
    (7, 1, 6),
    (8, 2, 6),
    (9, 2, 6),
    (10, 3, 6),
    (11, 3, 6),
    (12, 4, 6),
    (13, 4, 6),
    (14, 5, 6),
    (15, 5, 6);

-- 3. Desativa as 5 missões semanais simples iniciais (preservando histórico) e cria 5 elaboradas
UPDATE `missao`
   SET `ativa` = FALSE
 WHERE `missao_id` IN (1, 2, 3, 4, 5)
   AND `tipo_missao_id` = 1;

INSERT IGNORE INTO `missao`
    (`missao_id`, `tipo_missao_id`, `nome`, `descricao`,
     `recompensa_xp`, `recompensa_credito`, `penalidade_indicador`,
     `data_inicio`, `data_fim`, `ativa`, `origem`)
VALUES
    (6, 1, 'Casa Econômica',
     'Combine economia de água e energia na rotina de casa nesta semana.',
     140, 85, 5, '2026-11-22', NULL, 1, 'manual'),
    (7, 1, 'Rotina Lixo Zero',
     'Reduza descartáveis, separe recicláveis e dê destino certo a resíduos especiais.',
     113, 68, 5, '2026-11-22', NULL, 1, 'manual'),
    (8, 1, 'Energia & Água Inteligente',
     'Aproveite luz natural, seque ao ar livre e reaproveite água em casa.',
     105, 63, 5, '2026-11-22', NULL, 1, 'manual'),
    (9, 1, 'Cidade em Movimento',
     'Troque o carro por caminhada, transporte limpo, carona ou bicicleta nesta semana.',
     135, 83, 5, '2026-11-22', NULL, 1, 'manual'),
    (10, 1, 'Guardião do Bairro',
     'Cuide do verde, transforme orgânicos em composto e ajude a manter o bairro limpo.',
     105, 68, 5, '2026-11-22', NULL, 1, 'manual');

-- 4. 15 metas combinadas (3 atividades por missão semanal)
INSERT IGNORE INTO `missao_atividade` (`missao_atividade_id`, `missao_id`, `atividade_sustentavel_id`, `quantidade_necessaria`) VALUES
    -- Missão 6: Casa Econômica (2x Banho + 2x Torneira + 2x Luzes = 280 XP / 170 cr -> 140 / 85)
    (6, 6, 2, 2),
    (7, 6, 9, 2),
    (8, 6, 3, 2),
    -- Missão 7: Rotina Lixo Zero (2x Separação + 1x Compras s/ Descartáveis + 1x Ecoponto = 225 XP / 135 cr -> 113 / 68)
    (9, 7, 1, 2),
    (10, 7, 7, 1),
    (11, 7, 6, 1),
    -- Missão 8: Energia & Água Inteligente (2x Luz Natural + 1x Secagem + 1x Reuso Água = 210 XP / 125 cr -> 105 / 63)
    (12, 8, 10, 2),
    (13, 8, 11, 1),
    (14, 8, 8, 1),
    -- Missão 9: Cidade em Movimento (2x Caminhada + 1x Transporte Limpo + 1x Carona/Bike = 270 XP / 165 cr -> 135 / 83)
    (15, 9, 12, 2),
    (16, 9, 4, 1),
    (17, 9, 13, 1),
    -- Missão 10: Guardião do Bairro (1x Plantio + 1x Compostagem + 1x Limpeza = 210 XP / 135 cr -> 105 / 68)
    (18, 10, 5, 1),
    (19, 10, 14, 1),
    (20, 10, 15, 1);

-- 5. Vínculo seguro com parceiros aprovados existentes (apenas se existirem no ambiente)
INSERT IGNORE INTO `parceiro_atividade` (`parceiro_id`, `atividade_sustentavel_id`)
SELECT p.`parceiro_id`, v.`atividade_sustentavel_id`
  FROM `parceiro` p
  JOIN (
      SELECT 6 AS `atividade_sustentavel_id` UNION ALL
      SELECT 7 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15
  ) v
 WHERE p.`nome` = 'Cooperativa Teste Local' AND p.`status` = 'aprovado';

INSERT IGNORE INTO `parceiro_atividade` (`parceiro_id`, `atividade_sustentavel_id`)
SELECT p.`parceiro_id`, v.`atividade_sustentavel_id`
  FROM `parceiro` p
  JOIN (SELECT 6 AS `atividade_sustentavel_id` UNION ALL SELECT 7 UNION ALL SELECT 14 UNION ALL SELECT 15) v
 WHERE p.`nome` = 'Cooperativa Recicla Centro' AND p.`status` = 'aprovado';

INSERT IGNORE INTO `parceiro_atividade` (`parceiro_id`, `atividade_sustentavel_id`)
SELECT p.`parceiro_id`, v.`atividade_sustentavel_id`
  FROM `parceiro` p
  JOIN (SELECT 6 AS `atividade_sustentavel_id` UNION ALL SELECT 14 UNION ALL SELECT 15) v
 WHERE p.`nome` = 'Ecoponto Jardim Novo' AND p.`status` = 'aprovado';

INSERT IGNORE INTO `parceiro_atividade` (`parceiro_id`, `atividade_sustentavel_id`)
SELECT p.`parceiro_id`, v.`atividade_sustentavel_id`
  FROM `parceiro` p
  JOIN (SELECT 14 AS `atividade_sustentavel_id` UNION ALL SELECT 15) v
 WHERE p.`nome` = 'Viveiro Raiz Verde' AND p.`status` = 'aprovado';

INSERT IGNORE INTO `parceiro_atividade` (`parceiro_id`, `atividade_sustentavel_id`)
SELECT p.`parceiro_id`, 13
  FROM `parceiro` p
 WHERE p.`nome` = 'Bicicletaria Pedal Limpo' AND p.`status` = 'aprovado';
