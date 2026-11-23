-- =============================================================================
-- EcoCity — Revisão do conteúdo de exemplo (17/11/2026)
-- Ajustes de texto pedidos na revisão dos quizzes e das missões especiais.
-- Só textos: quizzes já respondidos podem mudar textos (RN50). Executar uma vez, com utf8mb4.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

UPDATE pergunta SET enunciado = 'Qual destes aparelhos costuma consumir mais energia numa casa brasileira?' WHERE pergunta_id = 1206;
UPDATE pergunta SET enunciado = 'No mundo, o que mais intensifica o efeito estufa?' WHERE pergunta_id = 1208;
UPDATE alternativa SET explicacao = 'No mundo, queimar carvão, petróleo e gás libera gás carbônico, que retém calor na atmosfera. No Brasil, a maior fonte de emissões é o desmatamento.' WHERE alternativa_id = 12082;

UPDATE missao SET nome = 'Semana do Dia da Água' WHERE missao_id = 101;
UPDATE missao SET descricao = 'Dia Mundial da Limpeza (terceiro sábado de setembro): separe os resíduos nos 3 dias da missão (sábado a segunda).' WHERE missao_id = 105;
