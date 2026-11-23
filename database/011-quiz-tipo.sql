-- =============================================================================
-- EcoCity — Migração 011: tipo do quiz e rotação (17/11/2026)
--  - quiz.tipo: 'diario' (Quiz do Dia, 3 a 5 perguntas), 'semanal' (Quiz da Semana,
--    10+ perguntas, recompensa ×3) ou 'tematico' (3 por semana, 3+ perguntas).
--  - Os quizzes existentes ficam como temáticos.
-- Importar uma única vez, depois de 010-conteudo.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

ALTER TABLE quiz
    ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'tematico',
    ADD CONSTRAINT chk_quiz_tipo CHECK (tipo IN ('diario', 'semanal', 'tematico'));
