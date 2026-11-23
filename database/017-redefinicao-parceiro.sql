-- =============================================================================
-- EcoCity — Migração 017: recuperação de senha do parceiro (17/11/2026, RN69)
--  - redefinicao_senha_parceiro: links de redefinição por e-mail do estabelecimento parceiro
--    (mesmas regras da RN47: 1 hora, uso único, só o hash do token, 3 pedidos por hora)
-- Importar uma única vez, depois de 016-parceiros.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

CREATE TABLE redefinicao_senha_parceiro (
    redefinicao_senha_parceiro_id INT      NOT NULL AUTO_INCREMENT,
    parceiro_id                   INT      NOT NULL,
    token_hash                    CHAR(64) NOT NULL,      -- SHA-256 do token enviado por e-mail
    data_criacao                  DATETIME NOT NULL,
    data_expiracao                DATETIME NOT NULL,
    data_uso                      DATETIME NULL,
    PRIMARY KEY (redefinicao_senha_parceiro_id),
    UNIQUE KEY uq_redefinicao_parceiro_token (token_hash),
    KEY ix_redefinicao_parceiro (parceiro_id, data_criacao),
    CONSTRAINT fk_redefinicao_parceiro
        FOREIGN KEY (parceiro_id) REFERENCES parceiro (parceiro_id)
) ENGINE=InnoDB;
