-- =============================================================================
-- EcoCity — Estatísticas e relatórios (17/11/2026, RN81–RN84)
-- 1. acesso_diario: um registro por jogador e dia em que abriu o jogo ou usou uma API
--    (mede jogadores ativos e retenção; antes não havia como saber quem só olhou a cidade).
-- 2. Índice para as consultas por período dos registros.
-- 3. Preenche acesso_diario com os dias que já se sabe que cada jogador usou o jogo
--    (registros, quizzes e logins certos). Executar uma vez, com utf8mb4.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

CREATE TABLE acesso_diario (
    jogador_id INT  NOT NULL,
    data       DATE NOT NULL,
    PRIMARY KEY (jogador_id, data),
    KEY ix_acesso_data (data),
    CONSTRAINT fk_acesso_jogador FOREIGN KEY (jogador_id) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

ALTER TABLE registro_atividade ADD KEY ix_registro_status_data (status, data_registro);

INSERT IGNORE INTO acesso_diario (jogador_id, data)
SELECT jogador_id, DATE(data_registro) FROM registro_atividade;

INSERT IGNORE INTO acesso_diario (jogador_id, data)
SELECT jogador_id, data FROM tentativa_quiz;

INSERT IGNORE INTO acesso_diario (jogador_id, data)
SELECT j.jogador_id, DATE(t.data)
  FROM tentativa_login t JOIN usuario u ON u.email = t.email JOIN jogador j ON j.usuario_id = u.usuario_id
 WHERE t.sucesso = TRUE;
