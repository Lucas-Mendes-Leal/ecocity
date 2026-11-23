-- =============================================================================
-- EcoCity — Migração 019 (17/11/2026)
-- 1) Estruturas giram nas 4 direções: `cidade_construcao.rotacao` (0 = frente para a
--    face y+d, 1 = 90°, 2 = 180°, 3 = 270°) substitui `girada` (sim/não). As já
--    construídas mantêm a posição (girada → 1).
-- 2) Peças de avenida 4×4 no tamanho da avenida do centro (2 células de asfalto
--    + calçada), que encaixam na saída das avenidas dos lotes 2 e 3 e entre si.
--    Custos PROVISÓRIOS.
-- Importar com utf8mb4. Executar uma vez.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

ALTER TABLE cidade_construcao ADD COLUMN rotacao TINYINT NOT NULL DEFAULT 0 AFTER girada;
UPDATE cidade_construcao SET rotacao = IF(girada, 1, 0);
ALTER TABLE cidade_construcao DROP COLUMN girada;
ALTER TABLE cidade_construcao ADD CONSTRAINT ck_cidade_construcao_rotacao CHECK (rotacao BETWEEN 0 AND 3);

INSERT IGNORE INTO construcao (construcao_id, nome, descricao, largura, profundidade, custo, nivel_cidade_minimo, categoria, desenho) VALUES
    (36, 'Avenida',               'Trecho reto de avenida, igual às avenidas do centro.',             4, 4, 150, 2, 'vias', 'avenida'),
    (37, 'Cruzamento de Avenida', 'Cruzamento de duas avenidas, com faixas de pedestres.',           4, 4, 200, 2, 'vias', 'avenida_cruzamento'),
    (38, 'Curva de Avenida',      'Curva de 90° para mudar a direção da avenida.',                    4, 4, 150, 2, 'vias', 'avenida_curva'),
    (39, 'Avenida em T',          'Encontro de três trechos de avenida.',                             4, 4, 180, 2, 'vias', 'avenida_t');
