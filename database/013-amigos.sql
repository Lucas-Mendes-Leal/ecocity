-- =============================================================================
-- EcoCity — Migração 013: amigos, mensagens, bloqueios e denúncias (17/11/2026)
--  - amizade: pedido (pendente) ou amizade (aceita); um só par em qualquer sentido
--  - bloqueio: quem bloqueou quem (RN57)
--  - mensagem: texto até 500 caracteres entre amigos (RN56)
--  - denuncia_mensagem: denúncias de mensagens recebidas, tratadas pela administração (RN58)
-- Importar uma única vez, depois de 012-estruturas-cidade.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

CREATE TABLE amizade (
    amizade_id      INT         NOT NULL AUTO_INCREMENT,
    solicitante_id  INT         NOT NULL,
    destinatario_id INT         NOT NULL,
    status          VARCHAR(10) NOT NULL DEFAULT 'pendente',
    data_pedido     DATETIME    NOT NULL,
    data_resposta   DATETIME    NULL,
    jogador_menor   INT AS (LEAST(solicitante_id, destinatario_id)) STORED,
    jogador_maior   INT AS (GREATEST(solicitante_id, destinatario_id)) STORED,
    PRIMARY KEY (amizade_id),
    UNIQUE KEY uq_amizade_par (jogador_menor, jogador_maior),
    KEY ix_amizade_destinatario (destinatario_id, status),
    CONSTRAINT ck_amizade_status CHECK (status IN ('pendente', 'aceita')),
    CONSTRAINT ck_amizade_si_mesmo CHECK (solicitante_id <> destinatario_id),
    CONSTRAINT fk_amizade_solicitante  FOREIGN KEY (solicitante_id)  REFERENCES jogador (jogador_id),
    CONSTRAINT fk_amizade_destinatario FOREIGN KEY (destinatario_id) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE bloqueio (
    bloqueador_id INT      NOT NULL,
    bloqueado_id  INT      NOT NULL,
    data          DATETIME NOT NULL,
    PRIMARY KEY (bloqueador_id, bloqueado_id),
    KEY ix_bloqueio_bloqueado (bloqueado_id),
    CONSTRAINT ck_bloqueio_si_mesmo CHECK (bloqueador_id <> bloqueado_id),
    CONSTRAINT fk_bloqueio_bloqueador FOREIGN KEY (bloqueador_id) REFERENCES jogador (jogador_id),
    CONSTRAINT fk_bloqueio_bloqueado  FOREIGN KEY (bloqueado_id)  REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE mensagem (
    mensagem_id     INT          NOT NULL AUTO_INCREMENT,
    remetente_id    INT          NOT NULL,
    destinatario_id INT          NOT NULL,
    texto           VARCHAR(500) NOT NULL,
    data_envio      DATETIME     NOT NULL,
    data_leitura    DATETIME     NULL,
    PRIMARY KEY (mensagem_id),
    KEY ix_mensagem_nao_lidas (destinatario_id, data_leitura),
    KEY ix_mensagem_conversa (remetente_id, destinatario_id, mensagem_id),
    CONSTRAINT fk_mensagem_remetente    FOREIGN KEY (remetente_id)    REFERENCES jogador (jogador_id),
    CONSTRAINT fk_mensagem_destinatario FOREIGN KEY (destinatario_id) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE denuncia_mensagem (
    denuncia_id    INT          NOT NULL AUTO_INCREMENT,
    mensagem_id    INT          NOT NULL,
    denunciante_id INT          NOT NULL,
    motivo         VARCHAR(250) NULL,
    data           DATETIME     NOT NULL,
    status         VARCHAR(10)  NOT NULL DEFAULT 'aberta',
    resolvida_por  INT          NULL,
    data_resolucao DATETIME     NULL,
    PRIMARY KEY (denuncia_id),
    UNIQUE KEY uq_denuncia (mensagem_id, denunciante_id),
    KEY ix_denuncia_status (status),
    CONSTRAINT ck_denuncia_status CHECK (status IN ('aberta', 'resolvida')),
    CONSTRAINT fk_denuncia_mensagem    FOREIGN KEY (mensagem_id)    REFERENCES mensagem (mensagem_id),
    CONSTRAINT fk_denuncia_denunciante FOREIGN KEY (denunciante_id) REFERENCES jogador (jogador_id),
    CONSTRAINT fk_denuncia_admin       FOREIGN KEY (resolvida_por)  REFERENCES administrador (administrador_id)
) ENGINE=InnoDB;
