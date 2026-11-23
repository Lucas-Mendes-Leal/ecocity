-- =============================================================================
-- EcoCity — Migração 004: edifícios e desbloqueios (16/11/2026)
-- =============================================================================

USE ecocity;

-- Descrição e tipo ainda não definidos para todos os edifícios
ALTER TABLE edificio MODIFY descricao VARCHAR(250) NULL;
ALTER TABLE edificio MODIFY tipo      VARCHAR(50)  NULL;

-- Requisito de nível da cidade fica no desbloqueio (NULL = sem exigência de nível)
ALTER TABLE desbloqueio
    ADD COLUMN nivel_cidade_id INT NULL AFTER descricao,
    ADD CONSTRAINT fk_desbloqueio_nivel_cidade FOREIGN KEY (nivel_cidade_id) REFERENCES nivel_cidade (nivel_cidade_id);
