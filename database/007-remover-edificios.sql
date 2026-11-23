-- =============================================================================
-- EcoCity — Migração 007: remoção dos edifícios do catálogo (16/11/2026)
-- O nível da cidade passa a liberar lotes e estruturas do modo Construir
-- (tabela construcao). Edifícios, desbloqueios e liberações são apagados.
-- O Bairro Residencial continua no mapa apenas como desenho fixo (sem banco).
--
-- Importar uma única vez, depois de 006-construcoes.sql.
-- =============================================================================

-- Históricos: origem "edifício" deixa de existir (nenhum edifício cobrava créditos, RN23)

USE ecocity;
    
ALTER TABLE historico_xp
    DROP FOREIGN KEY fk_hist_xp_edificio,
    DROP COLUMN edificio_id;

ALTER TABLE historico_credito_verde
    DROP FOREIGN KEY fk_hist_cred_edificio,
    DROP COLUMN edificio_id;

-- Tabelas de edifícios e desbloqueios (dependentes primeiro)
DROP TABLE IF EXISTS desbloqueio_edificio;
DROP TABLE IF EXISTS desbloqueio_jogador;
DROP TABLE IF EXISTS pre_requisito_desbloqueio;
DROP TABLE IF EXISTS desbloqueio;
DROP TABLE IF EXISTS edificio;

-- Conquista 14 passa a contar estruturas construídas (quem já ganhou mantém)
UPDATE conquista
   SET descricao = 'Construa 5 estruturas nos lotes de expansão.',
       tipo_criterio = 'construcoes',
       valor_criterio = 5
 WHERE conquista_id = 14;
