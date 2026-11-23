-- =============================================================================
-- EcoCity — Migração 006: construção limitada nos lotes de expansão (16/11/2026)
-- Lotes 20×20: 2 = direita, 3 = esquerda, 4 = frente do centro; cada um aparece
-- quando a cidade está no nível correspondente (acompanha o nível atual).
-- =============================================================================

USE ecocity;

-- Catálogo de estruturas que o jogador pode comprar
CREATE TABLE construcao (
    construcao_id       INT          NOT NULL AUTO_INCREMENT,
    nome                VARCHAR(100) NOT NULL,
    descricao           VARCHAR(250) NULL,
    largura             INT          NOT NULL,   -- células no eixo x (sem girar)
    profundidade        INT          NOT NULL,   -- células no eixo y (sem girar)
    custo               INT          NOT NULL,   -- créditos verdes
    nivel_cidade_minimo INT          NOT NULL,
    ativa               BOOLEAN      NOT NULL DEFAULT TRUE,
    PRIMARY KEY (construcao_id),
    CONSTRAINT ck_construcao_tamanho CHECK (largura BETWEEN 1 AND 20 AND profundidade BETWEEN 1 AND 20),
    CONSTRAINT ck_construcao_custo   CHECK (custo >= 0)
) ENGINE=InnoDB;

-- Estruturas colocadas na cidade (posição local dentro do lote, 0–19)
CREATE TABLE cidade_construcao (
    cidade_construcao_id INT      NOT NULL AUTO_INCREMENT,
    cidade_id            INT      NOT NULL,
    construcao_id        INT      NOT NULL,
    lote                 INT      NOT NULL,
    x                    INT      NOT NULL,
    y                    INT      NOT NULL,
    girada               BOOLEAN  NOT NULL DEFAULT FALSE,
    data_construcao      DATETIME NOT NULL,
    PRIMARY KEY (cidade_construcao_id),
    KEY ix_cidade_construcao_lote (cidade_id, lote),
    CONSTRAINT ck_cidade_construcao_lote CHECK (lote BETWEEN 2 AND 4),
    CONSTRAINT ck_cidade_construcao_pos  CHECK (x BETWEEN 0 AND 19 AND y BETWEEN 0 AND 19),
    CONSTRAINT fk_cidade_construcao_cidade     FOREIGN KEY (cidade_id)     REFERENCES cidade (cidade_id),
    CONSTRAINT fk_cidade_construcao_construcao FOREIGN KEY (construcao_id) REFERENCES construcao (construcao_id)
) ENGINE=InnoDB;

-- Gasto de créditos com origem rastreável (D8)
ALTER TABLE historico_credito_verde
    ADD COLUMN construcao_id INT NULL AFTER missao_id,
    ADD CONSTRAINT fk_hist_cred_construcao FOREIGN KEY (construcao_id) REFERENCES construcao (construcao_id);
