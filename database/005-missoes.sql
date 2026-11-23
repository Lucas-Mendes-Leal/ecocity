-- =============================================================================
-- EcoCity — Migração 005: missões (16/11/2026)
-- =============================================================================

USE ecocity;

-- Missões recorrentes (semanais) não têm data de término
ALTER TABLE missao MODIFY data_fim DATE NULL;

-- Pontos perdidos no indicador da área quando a missão não é concluída no período
ALTER TABLE missao ADD COLUMN penalidade_indicador INT NOT NULL DEFAULT 0 AFTER recompensa_credito;

-- Resultado de cada missão por jogador e período (concluída ou não concluída)
CREATE TABLE jogador_missao (
    jogador_missao_id INT         NOT NULL AUTO_INCREMENT,
    jogador_id        INT         NOT NULL,
    missao_id         INT         NOT NULL,
    periodo_inicio    DATE        NOT NULL,
    status            VARCHAR(20) NOT NULL,
    data_resultado    DATETIME    NOT NULL,
    PRIMARY KEY (jogador_missao_id),
    UNIQUE KEY uq_jogador_missao_periodo (jogador_id, missao_id, periodo_inicio),
    CONSTRAINT ck_jogador_missao_status CHECK (status IN ('concluida', 'nao_concluida')),
    CONSTRAINT fk_jogador_missao_jogador FOREIGN KEY (jogador_id) REFERENCES jogador (jogador_id),
    CONSTRAINT fk_jogador_missao_missao  FOREIGN KEY (missao_id)  REFERENCES missao (missao_id)
) ENGINE=InnoDB;

-- Origem "missão" rastreável nos históricos (D8)
ALTER TABLE historico_xp
    ADD COLUMN missao_id INT NULL AFTER edificio_id,
    ADD CONSTRAINT fk_hist_xp_missao FOREIGN KEY (missao_id) REFERENCES missao (missao_id);
ALTER TABLE historico_credito_verde
    ADD COLUMN missao_id INT NULL AFTER edificio_id,
    ADD CONSTRAINT fk_hist_cred_missao FOREIGN KEY (missao_id) REFERENCES missao (missao_id);
