-- =============================================================================
-- EcoCity — Migração 014: sugestões de amizade dispensadas (17/11/2026)
--  - sugestao_dispensada: jogadores que o jogador escondeu com "Agora não" (RN60)
-- Importar uma única vez, depois de 013-amigos.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

CREATE TABLE sugestao_dispensada (
    jogador_id  INT      NOT NULL,
    sugerido_id INT      NOT NULL,
    data        DATETIME NOT NULL,
    PRIMARY KEY (jogador_id, sugerido_id),
    KEY ix_sugestao_sugerido (sugerido_id),
    CONSTRAINT ck_sugestao_si_mesmo CHECK (jogador_id <> sugerido_id),
    CONSTRAINT fk_sugestao_jogador  FOREIGN KEY (jogador_id)  REFERENCES jogador (jogador_id),
    CONSTRAINT fk_sugestao_sugerido FOREIGN KEY (sugerido_id) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;
