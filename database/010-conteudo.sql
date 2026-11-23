-- =============================================================================
-- EcoCity — Migração 010: gestão de conteúdo pela administração (17/11/2026)
--  - quiz.ativa e conquista.ativa (conteúdo usado é desativado, não apagado)
--  - ativa/ativo passam a NOT NULL DEFAULT TRUE nas tabelas gerenciadas
-- Importar uma única vez, depois de 009-seguranca.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

ALTER TABLE quiz ADD COLUMN ativa BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE conquista ADD COLUMN ativa BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE atividade_sustentavel SET ativa = TRUE WHERE ativa IS NULL;
ALTER TABLE atividade_sustentavel MODIFY ativa BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE missao SET ativa = TRUE WHERE ativa IS NULL;
ALTER TABLE missao MODIFY ativa BOOLEAN NOT NULL DEFAULT TRUE;

UPDATE administrador SET ativo = TRUE WHERE ativo IS NULL;
ALTER TABLE administrador MODIFY ativo BOOLEAN NOT NULL DEFAULT TRUE;
