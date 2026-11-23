-- =============================================================================
-- EcoCity — Migração 039: Integridade e concorrência em quizzes (20/11/2026)
--
-- 1. Remove eventuais duplicatas antigas de resposta_quiz (mantendo a 1ª por
--    (tentativa_id, pergunta_id)) para permitir a criação da chave única.
-- 2. Cria UNIQUE KEY uq_resposta_tentativa_pergunta (tentativa_id, pergunta_id)
--    para blindar o responder_pergunta contra inserção duplicada em paralelo.
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity
-- após backup prévio da tabela resposta_quiz.
-- =============================================================================
USE ecocity;

SET NAMES utf8mb4;

DELETE r1
  FROM `resposta_quiz` r1
  JOIN `resposta_quiz` r2
    ON r1.tentativa_id = r2.tentativa_id
   AND r1.pergunta_id = r2.pergunta_id
   AND r1.resposta_quiz_id > r2.resposta_quiz_id;

ALTER TABLE `resposta_quiz`
  ADD UNIQUE KEY `uq_resposta_tentativa_pergunta` (`tentativa_id`, `pergunta_id`);
