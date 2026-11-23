-- =============================================================================
-- EcoCity — Migração 016: verificação por parceiros (17/11/2026, RN69–RN74)
--  - parceiro: conta própria (e-mail, senha, responsável) e situação (pendente/aprovado/recusado/bloqueado)
--  - parceiro_atividade: atividades que cada parceiro pode verificar
--  - codigo_parceiro: código de 6 dígitos + token do QR, uso único, válido por 30 min
--  - tentativa_codigo_parceiro: erros ao digitar código (limite contra adivinhação)
--  - registro_atividade.impacto_real: quantidade informada pelo parceiro (RN73)
--  - verificacao_parceiro.data_verificacao passa a DATETIME
--  - conquista "Cliente Verde" (critério verificacoes_parceiro)
-- Importar uma única vez, depois de 015-comunidades.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

ALTER TABLE parceiro
    ADD COLUMN email          VARCHAR(100) NULL AFTER nome,
    ADD COLUMN senha          VARCHAR(255) NULL AFTER email,
    ADD COLUMN responsavel    VARCHAR(100) NULL AFTER senha,
    ADD COLUMN status         VARCHAR(10)  NOT NULL DEFAULT 'pendente',
    ADD COLUMN motivo_recusa  VARCHAR(250) NULL,
    ADD COLUMN data_cadastro  DATETIME     NULL,
    ADD COLUMN aprovado_por   INT          NULL,
    ADD COLUMN data_aprovacao DATETIME     NULL,
    MODIFY COLUMN ativo BOOLEAN NOT NULL DEFAULT FALSE,
    ADD UNIQUE KEY uq_parceiro_email (email),
    ADD CONSTRAINT ck_parceiro_status CHECK (status IN ('pendente', 'aprovado', 'recusado', 'bloqueado')),
    ADD CONSTRAINT fk_parceiro_admin FOREIGN KEY (aprovado_por) REFERENCES administrador (administrador_id);

CREATE TABLE parceiro_atividade (
    parceiro_id              INT NOT NULL,
    atividade_sustentavel_id INT NOT NULL,
    PRIMARY KEY (parceiro_id, atividade_sustentavel_id),
    CONSTRAINT fk_patv_parceiro  FOREIGN KEY (parceiro_id)              REFERENCES parceiro (parceiro_id),
    CONSTRAINT fk_patv_atividade FOREIGN KEY (atividade_sustentavel_id) REFERENCES atividade_sustentavel (atividade_sustentavel_id)
) ENGINE=InnoDB;

ALTER TABLE registro_atividade
    ADD COLUMN impacto_real DECIMAL(10,2) NULL;

CREATE TABLE codigo_parceiro (
    codigo_parceiro_id       INT           NOT NULL AUTO_INCREMENT,
    parceiro_id              INT           NOT NULL,
    atividade_sustentavel_id INT           NOT NULL,
    quantidade               DECIMAL(10,2) NOT NULL,
    codigo                   CHAR(6)       NOT NULL,
    token                    CHAR(32)      NOT NULL,
    criado_em                DATETIME      NOT NULL,
    expira_em                DATETIME      NOT NULL,
    cancelado                BOOLEAN       NOT NULL DEFAULT FALSE,
    usado_em                 DATETIME      NULL,
    jogador_id               INT           NULL,
    registro_atividade_id    INT           NULL,
    PRIMARY KEY (codigo_parceiro_id),
    UNIQUE KEY uq_codigo_token (token),
    KEY ix_codigo_codigo (codigo, expira_em),
    KEY ix_codigo_parceiro (parceiro_id, criado_em),
    CONSTRAINT ck_codigo_quantidade CHECK (quantidade > 0),
    CONSTRAINT fk_codigo_parceiro  FOREIGN KEY (parceiro_id)              REFERENCES parceiro (parceiro_id),
    CONSTRAINT fk_codigo_atividade FOREIGN KEY (atividade_sustentavel_id) REFERENCES atividade_sustentavel (atividade_sustentavel_id),
    CONSTRAINT fk_codigo_jogador   FOREIGN KEY (jogador_id)               REFERENCES jogador (jogador_id),
    CONSTRAINT fk_codigo_registro  FOREIGN KEY (registro_atividade_id)    REFERENCES registro_atividade (registro_atividade_id)
) ENGINE=InnoDB;

CREATE TABLE tentativa_codigo_parceiro (
    tentativa_codigo_id INT      NOT NULL AUTO_INCREMENT,
    jogador_id          INT      NOT NULL,
    data                DATETIME NOT NULL,
    PRIMARY KEY (tentativa_codigo_id),
    KEY ix_tentativa_codigo (jogador_id, data),
    CONSTRAINT fk_tentativa_codigo_jogador FOREIGN KEY (jogador_id) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

ALTER TABLE verificacao_parceiro
    MODIFY COLUMN data_verificacao DATETIME NOT NULL;

INSERT IGNORE INTO conquista (conquista_id, nome, descricao, recompensa_xp, recompensa_credito, tipo_criterio, valor_criterio) VALUES
    (18, 'Cliente Verde', 'Tenha 5 atividades verificadas por estabelecimentos parceiros.', 100, 200, 'verificacoes_parceiro', 5);
