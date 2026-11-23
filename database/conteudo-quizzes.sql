-- =============================================================================
-- EcoCity — Conteúdo inicial dos quizzes (vindo do protótipo)
-- Recompensas e dificuldades PROVISÓRIAS. A explicação educativa fica na
-- alternativa correta. Pode ser executado mais de uma vez (INSERT IGNORE).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

INSERT IGNORE INTO quiz (quiz_id, titulo, descricao, categoria_ambiental_id, recompensa_xp, recompensa_credito, dificuldade) VALUES
    (1, 'Desafio dos Resíduos e Reciclagem', 'Teste seus conhecimentos sobre separação de lixo e tempo de decomposição.', 1, 40, 25, 'Fácil'),
    (2, 'Uso Consciente da Água',            'Aprenda práticas simples para evitar o desperdício de água potável.',          2, 40, 25, 'Médio'),
    (3, 'Eficiência Energética Urbana',      'Descubra como reduzir o consumo elétrico e diminuir emissões.',               3, 40, 25, 'Fácil');

INSERT IGNORE INTO pergunta (pergunta_id, quiz_id, enunciado) VALUES
    (101, 1, 'Qual é a cor correta da lixeira de coleta seletiva para descarte de PAPEL?'),
    (102, 1, 'Aproximadamente quanto tempo uma garrafa PET leva para se decompor na natureza?'),
    (201, 2, 'Qual destas atitudes consome MAIS água potável em uma residência?'),
    (301, 3, 'O que significa o modo ''standby'' (luz vermelha) em aparelhos eletrônicos?');

INSERT IGNORE INTO alternativa (alternativa_id, pergunta_id, texto, correta, explicacao) VALUES
    (1011, 101, 'Vermelho', FALSE, NULL),
    (1012, 101, 'Azul',     TRUE,  'Na coleta seletiva padronizada, o azul é destinado ao papel/papelão, o vermelho ao plástico, o amarelo ao metal e o verde ao vidro.'),
    (1013, 101, 'Amarelo',  FALSE, NULL),
    (1014, 101, 'Verde',    FALSE, NULL),

    (1021, 102, 'Cerca de 10 anos',  FALSE, NULL),
    (1022, 102, 'Cerca de 100 anos', FALSE, NULL),
    (1023, 102, 'Mais de 400 anos',  TRUE,  'O plástico PET é muito resistente e leva mais de 400 anos para se decompor, contaminando solos e oceanos com microplásticos.'),
    (1024, 102, 'Não se decompõe',   FALSE, NULL),

    (2011, 201, 'Lavar a calçada com mangueira por 15 minutos', TRUE,  'Lavar a calçada com mangueira pode desperdiçar mais de 280 litros de água limpa em apenas 15 minutos.'),
    (2012, 201, 'Usar a máquina de lavar louça cheia',          FALSE, NULL),
    (2013, 201, 'Escovar os dentes com a torneira fechada',     FALSE, NULL),
    (2014, 201, 'Tomar banho de 5 minutos',                     FALSE, NULL),

    (3011, 301, 'O aparelho está desligado e não consome energia',        FALSE, NULL),
    (3012, 301, 'O aparelho continua consumindo energia silenciosamente', TRUE,  'O modo standby pode representar até 12% do consumo de energia de uma residência. Desligue da tomada quando não estiver usando.'),
    (3013, 301, 'O aparelho está gerando energia limpa',                  FALSE, NULL);
