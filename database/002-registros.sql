-- =============================================================================
-- EcoCity — Migração 002: registro de atividades (16/11/2026)
-- Para bancos criados com a versão anterior de ecocity.sql.
-- Depois desta migração, execute novamente dados-estruturais.sql.
-- =============================================================================

USE ecocity;

-- Evidência só com descrição (autodeclarada) não tem arquivo
ALTER TABLE evidencia_atividade MODIFY arquivo VARCHAR(150) NULL;

-- Precisão de hora para a aprovação automática após 1 dia
ALTER TABLE registro_atividade MODIFY data_registro DATETIME NOT NULL;
