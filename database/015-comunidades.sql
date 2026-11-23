-- =============================================================================
-- EcoCity — Migração 015: comunidades (17/11/2026, RN61–RN68)
--  - comunidade / comunidade_membro: grupo com líder, moderadores e membros (1 por jogador)
--  - comunidade_pedido: pedidos de entrada e convites
--  - comunidade_mensagem: conversa em grupo (última lida em comunidade_membro)
--  - comunidade_topico / comunidade_presenca: mural com data, local e "Vou participar"
--  - comunidade_missao / comunidade_missao_resultado: missões oficiais (semanais) e internas
--  - comunidade_contribuicao: registros aprovados dos membros (impacto real + pontos de ranking)
--  - comunidade_denuncia: denúncias de mensagens e tópicos
--  - jogador.saiu_comunidade_em e origens novas nos históricos
-- Importar uma única vez, depois de 014-sugestoes.sql.
-- Importe pelo phpMyAdmin ou com --default-character-set=utf8mb4.
-- =============================================================================

use ecocity;

SET NAMES utf8mb4;

CREATE TABLE comunidade (
    comunidade_id   INT          NOT NULL AUTO_INCREMENT,
    nome            VARCHAR(40)  NOT NULL,
    descricao       VARCHAR(250) NULL,
    icone           VARCHAR(20)  NOT NULL,
    entrada         VARCHAR(10)  NOT NULL DEFAULT 'aberta',
    data_criacao    DATETIME     NOT NULL,
    ativa           BOOLEAN      NOT NULL DEFAULT TRUE,
    data_dissolucao DATETIME     NULL,
    nome_ativo      VARCHAR(40) AS (IF(ativa, nome, NULL)) STORED,
    PRIMARY KEY (comunidade_id),
    UNIQUE KEY uq_comunidade_nome_ativo (nome_ativo),
    CONSTRAINT ck_comunidade_entrada CHECK (entrada IN ('aberta', 'aprovacao')),
    CONSTRAINT ck_comunidade_icone CHECK (icone IN ('recycling', 'water', 'energy', 'mobility', 'nature'))
) ENGINE=InnoDB;

CREATE TABLE comunidade_membro (
    jogador_id     INT         NOT NULL,
    comunidade_id  INT         NOT NULL,
    papel          VARCHAR(10) NOT NULL DEFAULT 'membro',
    data_entrada   DATETIME    NOT NULL,
    ultima_lida_id INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (jogador_id),
    KEY ix_membro_comunidade (comunidade_id, papel),
    CONSTRAINT ck_membro_papel CHECK (papel IN ('lider', 'moderador', 'membro')),
    CONSTRAINT fk_membro_jogador    FOREIGN KEY (jogador_id)    REFERENCES jogador (jogador_id),
    CONSTRAINT fk_membro_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidade (comunidade_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_pedido (
    comunidade_id INT         NOT NULL,
    jogador_id    INT         NOT NULL,
    tipo          VARCHAR(10) NOT NULL,
    convidado_por INT         NULL,
    data          DATETIME    NOT NULL,
    PRIMARY KEY (comunidade_id, jogador_id),
    KEY ix_pedido_jogador (jogador_id, tipo),
    CONSTRAINT ck_pedido_tipo CHECK (tipo IN ('pedido', 'convite')),
    CONSTRAINT fk_pedido_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_pedido_jogador    FOREIGN KEY (jogador_id)    REFERENCES jogador (jogador_id),
    CONSTRAINT fk_pedido_convidou   FOREIGN KEY (convidado_por) REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_mensagem (
    comunidade_mensagem_id INT          NOT NULL AUTO_INCREMENT,
    comunidade_id          INT          NOT NULL,
    jogador_id             INT          NOT NULL,
    texto                  VARCHAR(500) NOT NULL,
    data_envio             DATETIME     NOT NULL,
    PRIMARY KEY (comunidade_mensagem_id),
    KEY ix_cmsg_comunidade (comunidade_id, comunidade_mensagem_id),
    KEY ix_cmsg_jogador (jogador_id, data_envio),
    CONSTRAINT fk_cmsg_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_cmsg_jogador    FOREIGN KEY (jogador_id)    REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_topico (
    comunidade_topico_id INT           NOT NULL AUTO_INCREMENT,
    comunidade_id        INT           NOT NULL,
    autor_id             INT           NOT NULL,
    titulo               VARCHAR(100)  NOT NULL,
    texto                VARCHAR(1000) NULL,
    data_evento          DATETIME      NULL,
    local                VARCHAR(150)  NULL,
    fixado               BOOLEAN       NOT NULL DEFAULT FALSE,
    removido             BOOLEAN       NOT NULL DEFAULT FALSE,
    data                 DATETIME      NOT NULL,
    PRIMARY KEY (comunidade_topico_id),
    KEY ix_topico_comunidade (comunidade_id, removido, fixado),
    CONSTRAINT fk_topico_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_topico_autor      FOREIGN KEY (autor_id)      REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_presenca (
    comunidade_topico_id INT      NOT NULL,
    jogador_id           INT      NOT NULL,
    data                 DATETIME NOT NULL,
    PRIMARY KEY (comunidade_topico_id, jogador_id),
    CONSTRAINT fk_presenca_topico  FOREIGN KEY (comunidade_topico_id) REFERENCES comunidade_topico (comunidade_topico_id),
    CONSTRAINT fk_presenca_jogador FOREIGN KEY (jogador_id)           REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

-- Oficial: comunidade_id NULL, semanal (segunda a domingo) a partir de data_inicio.
-- Interna: comunidade_id da comunidade, de data_inicio a data_fim, sem recompensa nem pontos.
CREATE TABLE comunidade_missao (
    comunidade_missao_id     INT           NOT NULL AUTO_INCREMENT,
    comunidade_id            INT           NULL,
    nome                     VARCHAR(100)  NOT NULL,
    descricao                VARCHAR(250)  NULL,
    atividade_sustentavel_id INT           NOT NULL,
    meta                     DECIMAL(10,2) NOT NULL,
    recompensa_xp            INT           NOT NULL DEFAULT 0,
    recompensa_credito       INT           NOT NULL DEFAULT 0,
    pontos                   INT           NOT NULL DEFAULT 0,
    data_inicio              DATE          NOT NULL,
    data_fim                 DATE          NULL,
    ativa                    BOOLEAN       NOT NULL DEFAULT TRUE,
    criada_por               INT           NULL,
    PRIMARY KEY (comunidade_missao_id),
    KEY ix_cmissao_comunidade (comunidade_id, ativa),
    CONSTRAINT ck_cmissao_meta CHECK (meta > 0),
    CONSTRAINT fk_cmissao_comunidade FOREIGN KEY (comunidade_id)            REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_cmissao_atividade  FOREIGN KEY (atividade_sustentavel_id) REFERENCES atividade_sustentavel (atividade_sustentavel_id),
    CONSTRAINT fk_cmissao_criador    FOREIGN KEY (criada_por)               REFERENCES jogador (jogador_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_missao_resultado (
    comunidade_missao_id INT      NOT NULL,
    comunidade_id        INT      NOT NULL,
    periodo_inicio       DATE     NOT NULL,
    pontos               INT      NOT NULL DEFAULT 0,
    data                 DATETIME NOT NULL,
    PRIMARY KEY (comunidade_missao_id, comunidade_id, periodo_inicio),
    KEY ix_cresultado_comunidade (comunidade_id, data),
    CONSTRAINT fk_cresultado_missao     FOREIGN KEY (comunidade_missao_id) REFERENCES comunidade_missao (comunidade_missao_id),
    CONSTRAINT fk_cresultado_comunidade FOREIGN KEY (comunidade_id)        REFERENCES comunidade (comunidade_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_contribuicao (
    registro_atividade_id    INT           NOT NULL,
    comunidade_id            INT           NOT NULL,
    jogador_id               INT           NOT NULL,
    atividade_sustentavel_id INT           NOT NULL,
    impacto_real             DECIMAL(10,2) NOT NULL DEFAULT 0,
    pontos                   INT           NOT NULL,
    data_registro            DATETIME      NOT NULL,
    PRIMARY KEY (registro_atividade_id),
    KEY ix_contrib_comunidade (comunidade_id, data_registro),
    KEY ix_contrib_jogador (jogador_id, comunidade_id),
    CONSTRAINT fk_contrib_registro   FOREIGN KEY (registro_atividade_id)    REFERENCES registro_atividade (registro_atividade_id),
    CONSTRAINT fk_contrib_comunidade FOREIGN KEY (comunidade_id)            REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_contrib_jogador    FOREIGN KEY (jogador_id)               REFERENCES jogador (jogador_id),
    CONSTRAINT fk_contrib_atividade  FOREIGN KEY (atividade_sustentavel_id) REFERENCES atividade_sustentavel (atividade_sustentavel_id)
) ENGINE=InnoDB;

CREATE TABLE comunidade_denuncia (
    comunidade_denuncia_id INT          NOT NULL AUTO_INCREMENT,
    tipo                   VARCHAR(10)  NOT NULL,
    alvo_id                INT          NOT NULL,
    comunidade_id          INT          NOT NULL,
    denunciante_id         INT          NOT NULL,
    motivo                 VARCHAR(250) NULL,
    data                   DATETIME     NOT NULL,
    status                 VARCHAR(10)  NOT NULL DEFAULT 'aberta',
    resolvida_por          INT          NULL,
    data_resolucao         DATETIME     NULL,
    PRIMARY KEY (comunidade_denuncia_id),
    UNIQUE KEY uq_cdenuncia (tipo, alvo_id, denunciante_id),
    KEY ix_cdenuncia_status (status),
    CONSTRAINT ck_cdenuncia_tipo CHECK (tipo IN ('mensagem', 'topico')),
    CONSTRAINT ck_cdenuncia_status CHECK (status IN ('aberta', 'resolvida')),
    CONSTRAINT fk_cdenuncia_comunidade  FOREIGN KEY (comunidade_id)  REFERENCES comunidade (comunidade_id),
    CONSTRAINT fk_cdenuncia_denunciante FOREIGN KEY (denunciante_id) REFERENCES jogador (jogador_id),
    CONSTRAINT fk_cdenuncia_admin       FOREIGN KEY (resolvida_por)  REFERENCES administrador (administrador_id)
) ENGINE=InnoDB;

ALTER TABLE jogador
    ADD COLUMN saiu_comunidade_em DATETIME NULL;

ALTER TABLE historico_xp
    ADD COLUMN comunidade_missao_id INT NULL,
    ADD CONSTRAINT fk_hist_xp_cmissao FOREIGN KEY (comunidade_missao_id) REFERENCES comunidade_missao (comunidade_missao_id);

ALTER TABLE historico_credito_verde
    ADD COLUMN comunidade_missao_id INT NULL,
    ADD COLUMN comunidade_id INT NULL,
    ADD CONSTRAINT fk_hist_cred_cmissao FOREIGN KEY (comunidade_missao_id) REFERENCES comunidade_missao (comunidade_missao_id),
    ADD CONSTRAINT fk_hist_cred_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidade (comunidade_id);
