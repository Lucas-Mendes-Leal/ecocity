-- =============================================================================
-- EcoCity — Migração 009: segurança da conta (17/11/2026)
--  - tentativa_login: limite de 5 erros em 15 min por e-mail (e 20 por IP) → bloqueio de 15 min
--  - redefinicao_senha: links de redefinição por e-mail (1 hora, uso único; só o hash do token)
--  - usuario.status: TRUE = ativa, FALSE = bloqueada (não entra)
-- Importar uma única vez, depois de 008-valores.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

CREATE TABLE tentativa_login (
    tentativa_login_id INT          NOT NULL AUTO_INCREMENT,
    email              VARCHAR(100) NOT NULL,
    ip                 VARCHAR(45)  NOT NULL,
    data               DATETIME     NOT NULL,
    sucesso            BOOLEAN      NOT NULL,
    PRIMARY KEY (tentativa_login_id),
    KEY ix_tentativa_login_email (email, data),
    KEY ix_tentativa_login_ip (ip, data)
) ENGINE=InnoDB;

CREATE TABLE redefinicao_senha (
    redefinicao_senha_id INT      NOT NULL AUTO_INCREMENT,
    usuario_id           INT      NOT NULL,
    token_hash           CHAR(64) NOT NULL,      -- SHA-256 do token enviado por e-mail
    data_criacao         DATETIME NOT NULL,
    data_expiracao       DATETIME NOT NULL,
    data_uso             DATETIME NULL,          -- preenchido ao redefinir (uso único)
    PRIMARY KEY (redefinicao_senha_id),
    UNIQUE KEY uq_redefinicao_token (token_hash),
    KEY ix_redefinicao_usuario (usuario_id, data_criacao),
    CONSTRAINT fk_redefinicao_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuario (usuario_id)
) ENGINE=InnoDB;

-- Contas existentes ficam ativas; novas contas nascem ativas
UPDATE usuario SET status = TRUE WHERE status IS NULL;
ALTER TABLE usuario MODIFY status BOOLEAN NOT NULL DEFAULT TRUE;
