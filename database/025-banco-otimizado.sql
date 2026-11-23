-- =============================================================================
-- EcoCity — Banco otimizado (18/11/2026)
-- Constraints novas, índices, views de relatório, auditoria, triggers de regras de negócio,
-- procedures e event de manutenção, e a marcação de contas de demonstração.
-- Gerado por script; aplicar uma vez, com utf8mb4. O bloco final usa DELIMITER $$.
-- Antes de aplicar: conferir que nenhum dado viola as CHECKs novas (script de conferência).
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. Contas de demonstração (carga para a banca; apagadas por sp_apagar_demonstracao)
-- -----------------------------------------------------------------------------
ALTER TABLE usuario
    ADD COLUMN demonstracao BOOLEAN NOT NULL DEFAULT FALSE AFTER status,
    ADD KEY ix_usuario_demonstracao (demonstracao);

-- -----------------------------------------------------------------------------
-- 2. NOT NULL onde o código sempre grava valor (conferido: nenhuma linha com NULL)
--    Colunas de chave estrangeira só mudam de nulidade com a checagem de FK desligada.
-- -----------------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE jogador   MODIFY nivel_id INT NOT NULL;
ALTER TABLE quiz      MODIFY categoria_ambiental_id INT NOT NULL,
                      MODIFY dificuldade VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL;
ALTER TABLE missao    MODIFY tipo_missao_id INT NOT NULL;
ALTER TABLE conquista MODIFY tipo_criterio VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
                      MODIFY valor_criterio INT NOT NULL;
ALTER TABLE usuario   MODIFY data_cadastro DATE NOT NULL;
ALTER TABLE parceiro  MODIFY email VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
                      MODIFY senha VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                      MODIFY responsavel VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
                      MODIFY data_cadastro DATETIME NOT NULL;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- 3. CHECK constraints (regras de domínio garantidas pelo próprio banco)
-- -----------------------------------------------------------------------------
ALTER TABLE usuario ADD CONSTRAINT ck_usuario_email CHECK (email LIKE '%_@_%._%');
ALTER TABLE jogador ADD CONSTRAINT ck_jogador_xp CHECK (xp_atual >= 0);
ALTER TABLE cidade_indicador ADD CONSTRAINT ck_cidade_indicador_valor CHECK (valor BETWEEN 0 AND 100);
ALTER TABLE indicador_ambiental ADD CONSTRAINT ck_indicador_faixa CHECK (valor_minimo < valor_maximo);
ALTER TABLE nivel_cidade ADD CONSTRAINT ck_nivel_cidade_pontuacao CHECK (pontuacao_minima BETWEEN 0 AND 100);
ALTER TABLE nivel_jogador ADD CONSTRAINT ck_nivel_jogador_faixa CHECK (xp_maximo >= xp_minimo);
ALTER TABLE tipo_verificacao ADD CONSTRAINT ck_tipo_verificacao_confiabilidade CHECK (nivel_confiabilidade BETWEEN 1 AND 3);
ALTER TABLE atividade_sustentavel
    ADD CONSTRAINT ck_atividade_recompensa CHECK (recompensa_xp >= 0 AND recompensa_credito >= 0),
    ADD CONSTRAINT ck_atividade_impacto_real CHECK ((impacto_real_quantidade IS NULL AND impacto_real_unidade IS NULL)
                                                    OR (impacto_real_quantidade > 0 AND impacto_real_unidade IS NOT NULL));
ALTER TABLE atividade_indicador_ambiental ADD CONSTRAINT ck_atividade_indicador_impacto CHECK (impacto > 0);
ALTER TABLE quiz
    ADD CONSTRAINT ck_quiz_recompensa CHECK (recompensa_xp >= 0 AND recompensa_credito >= 0),
    ADD CONSTRAINT ck_quiz_dificuldade CHECK (dificuldade IN ('Fácil', 'Médio', 'Difícil'));
ALTER TABLE missao
    ADD CONSTRAINT ck_missao_recompensa CHECK (recompensa_xp >= 0 AND recompensa_credito >= 0 AND penalidade_indicador >= 0),
    ADD CONSTRAINT ck_missao_periodo CHECK (data_fim IS NULL OR data_fim >= data_inicio);
ALTER TABLE missao_atividade ADD CONSTRAINT ck_missao_atividade_quantidade CHECK (quantidade_necessaria > 0);
ALTER TABLE conquista ADD CONSTRAINT ck_conquista_valores CHECK (recompensa_xp >= 0 AND recompensa_credito >= 0 AND valor_criterio > 0);
ALTER TABLE registro_atividade
    ADD CONSTRAINT ck_registro_aprovado_data CHECK (status <> 'aprovado' OR data_aprovacao IS NOT NULL),
    ADD CONSTRAINT ck_registro_rejeitado_motivo CHECK (status <> 'rejeitado' OR (motivo_rejeicao IS NOT NULL AND motivo_rejeicao <> '')),
    ADD CONSTRAINT ck_registro_impacto_real CHECK (impacto_real IS NULL OR impacto_real > 0);
ALTER TABLE tentativa_quiz ADD CONSTRAINT ck_tentativa_resultado CHECK ((acertos IS NULL OR acertos >= 0) AND (pontuacao IS NULL OR pontuacao >= 0));
ALTER TABLE historico_xp ADD CONSTRAINT ck_historico_xp_quantidade CHECK (quantidade <> 0);
ALTER TABLE historico_credito_verde ADD CONSTRAINT ck_historico_credito_quantidade CHECK (quantidade <> 0);
ALTER TABLE comunidade_contribuicao ADD CONSTRAINT ck_contribuicao_pontos CHECK (pontos >= 0);
ALTER TABLE comunidade_missao
    ADD CONSTRAINT ck_cmissao_recompensa CHECK (recompensa_xp >= 0 AND recompensa_credito >= 0 AND pontos >= 0),
    ADD CONSTRAINT ck_cmissao_periodo CHECK (data_fim IS NULL OR data_fim >= data_inicio);
ALTER TABLE codigo_parceiro ADD CONSTRAINT ck_codigo_validade CHECK (expira_em > criado_em);
ALTER TABLE construcao ADD CONSTRAINT ck_construcao_renda_comercio CHECK (renda_diaria = 0 OR categoria = 'comercio');

-- -----------------------------------------------------------------------------
-- 4. Índices compostos para as consultas mais usadas (substituem os índices simples das FKs)
-- -----------------------------------------------------------------------------
-- Extrato do jogador e estatísticas semanais: WHERE jogador_id = ? ORDER BY data
ALTER TABLE historico_xp ADD KEY ix_hist_xp_jogador_data (jogador_id, data), DROP KEY fk_hist_xp_jogador;
ALTER TABLE historico_credito_verde ADD KEY ix_hist_cred_jogador_data (jogador_id, data), DROP KEY fk_hist_cred_jogador;
-- 1ª tentativa e rotação dos quizzes: WHERE jogador_id = ? AND quiz_id = ? AND acertos IS NOT NULL
ALTER TABLE tentativa_quiz ADD KEY ix_tentativa_jogador_quiz (jogador_id, quiz_id, acertos), DROP KEY fk_tentativa_jogador;
-- Limite diário (RN44) e registros do jogador: WHERE jogador_id = ? AND atividade_sustentavel_id = ? AND data_registro …
ALTER TABLE registro_atividade
    ADD KEY ix_registro_jogador_atividade_data (jogador_id, atividade_sustentavel_id, data_registro),
    DROP KEY fk_registro_jogador,
-- Fila de aprovação e aprovação automática: WHERE tipo_verificacao_id = 2 AND status = 'pendente' ORDER BY data_registro
    ADD KEY ix_registro_tipo_status_data (tipo_verificacao_id, status, data_registro),
    DROP KEY fk_registro_tipo_verificacao;
-- Variação dos indicadores (LAG por cidade e indicador, em ordem de data)
ALTER TABLE historico_indicador_cidade
    ADD KEY ix_hist_ind_cidade_data (cidade_id, indicador_ambiental_id, data, historico_indicador_id),
    DROP KEY fk_hist_indicador_cidade_indicador;
-- Conquistas do jogador: WHERE jogador_id = ?
ALTER TABLE jogador_conquista ADD KEY ix_jogador_conquista_data (jogador_id, data_conquista), DROP KEY fk_jogador_conquista_jogador;

-- -----------------------------------------------------------------------------
-- 5. Auditoria (quem mudou o quê e quando; não pode ser alterada nem apagada)
-- -----------------------------------------------------------------------------
CREATE TABLE auditoria (
    auditoria_id  BIGINT       NOT NULL AUTO_INCREMENT,
    tabela        VARCHAR(40)  NOT NULL,
    registro_id   VARCHAR(40)  NOT NULL,
    acao          ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    dados_antes   JSON         NULL,
    dados_depois  JSON         NULL,
    usuario_app   INT          NULL,               -- usuario_id logado no site (@app_usuario_id); NULL = script/console
    usuario_banco VARCHAR(100) NOT NULL,           -- conta do MySQL que executou
    data          DATETIME(3)  NOT NULL,
    PRIMARY KEY (auditoria_id),
    KEY ix_auditoria_tabela (tabela, registro_id),
    KEY ix_auditoria_data (data),
    KEY ix_auditoria_usuario (usuario_app, data)
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- 6. Views de relatório (usadas pelo PHP e pela administração)
-- -----------------------------------------------------------------------------
-- Progresso ecológico (média dos indicadores) e nível calculado de cada cidade (RN17/RN22)
CREATE OR REPLACE VIEW vw_cidade_progresso AS
SELECT x.cidade_id, x.jogador_id, x.progresso,
       (SELECT MAX(n.nivel) FROM nivel_cidade n WHERE n.pontuacao_minima <= x.progresso) AS nivel_calculado
  FROM (SELECT c.cidade_id, c.jogador_id, ROUND(AVG(ci.valor)) AS progresso
          FROM cidade c JOIN cidade_indicador ci ON ci.cidade_id = c.cidade_id
      GROUP BY c.cidade_id, c.jogador_id) x;

-- Jogador com conta, nível, cidade e progresso (sem senha nem e-mail)
CREATE OR REPLACE VIEW vw_jogador_resumo AS
SELECT j.jogador_id, j.usuario_id, j.apelido, j.numero_conta, j.creditos_verde, j.xp_atual, nj.nivel,
       j.data_criacao, c.cidade_id, c.nome AS cidade, nc.nivel AS nivel_cidade, cp.progresso,
       u.status AS conta_ativa, u.demonstracao
  FROM jogador j
  JOIN usuario u ON u.usuario_id = j.usuario_id
  JOIN cidade c ON c.jogador_id = j.jogador_id
  LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
  LEFT JOIN nivel_cidade nc ON nc.nivel_cidade_id = c.nivel_cidade_id
  LEFT JOIN vw_cidade_progresso cp ON cp.cidade_id = c.cidade_id;

-- Registro de atividade com atividade, área, tipo e impacto real efetivo (RN26/RN73)
CREATE OR REPLACE VIEW vw_registro_detalhado AS
SELECT r.registro_atividade_id, r.jogador_id, r.atividade_sustentavel_id, a.nome AS atividade,
       a.categoria_ambiental_id, r.tipo_verificacao_id, tv.nome AS tipo_verificacao, r.status,
       r.data_registro, DATE(r.data_registro) AS dia, r.data_aprovacao, r.aprovado_por_administrador_id,
       r.motivo_rejeicao, r.missao_atividade_id,
       COALESCE(r.impacto_real, a.impacto_real_quantidade) AS impacto_efetivo, a.impacto_real_unidade AS unidade
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN tipo_verificacao tv ON tv.tipo_verificacao_id = r.tipo_verificacao_id;

-- Ações aprovadas e impacto real por jogador e área (Perfil, conquistas de impacto, comparação)
CREATE OR REPLACE VIEW vw_impacto_jogador_categoria AS
SELECT r.jogador_id, ai.indicador_ambiental_id, COUNT(*) AS acoes,
       COALESCE(SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)), 0) AS impacto
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
 WHERE r.status = 'aprovado'
 GROUP BY r.jogador_id, ai.indicador_ambiental_id;

-- Resumo por atividade (tabela da Visão Geral da administração)
CREATE OR REPLACE VIEW vw_atividade_resumo AS
SELECT a.atividade_sustentavel_id, a.nome, ai.indicador_ambiental_id, a.impacto_real_quantidade, a.impacto_real_unidade,
       COALESCE(SUM(r.status = 'aprovado'), 0) AS aprovados,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 1), 0) AS autodeclarados,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 2), 0) AS com_evidencia,
       COALESCE(SUM(r.status = 'aprovado' AND r.tipo_verificacao_id = 3), 0) AS com_parceiro,
       COALESCE(SUM(IF(r.status = 'aprovado', COALESCE(r.impacto_real, a.impacto_real_quantidade), 0)), 0) AS impacto_soma
  FROM atividade_sustentavel a
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  LEFT JOIN registro_atividade r ON r.atividade_sustentavel_id = a.atividade_sustentavel_id
 GROUP BY a.atividade_sustentavel_id, a.nome, ai.indicador_ambiental_id, a.impacto_real_quantidade, a.impacto_real_unidade;

-- Fila de aprovação: registros com foto pendentes, com jogador, cidade e evidência
CREATE OR REPLACE VIEW vw_fila_aprovacao AS
SELECT r.registro_atividade_id AS id, r.data_registro, a.nome AS atividade, ai.indicador_ambiental_id,
       ia.nome AS categoria_nome, j.apelido, j.usuario_id, c.nome AS cidade, e.descricao, e.arquivo
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
  JOIN jogador j ON j.jogador_id = r.jogador_id
  JOIN cidade c ON c.jogador_id = j.jogador_id
  LEFT JOIN evidencia_atividade e ON e.registro_atividade_id = r.registro_atividade_id
 WHERE r.status = 'pendente' AND r.tipo_verificacao_id = 2;

-- Extrato: ganhos de XP (com os créditos do mesmo evento) + créditos sem XP (construções, renda, comunidade)
CREATE OR REPLACE VIEW vw_extrato AS
SELECT hx.jogador_id, hx.data, hx.origem, hx.quantidade AS xp,
       (SELECT hc.quantidade FROM historico_credito_verde hc
         WHERE hc.jogador_id = hx.jogador_id AND hc.origem = hx.origem AND hc.data = hx.data
           AND hc.registro_atividade_id <=> hx.registro_atividade_id AND hc.tentativa_id <=> hx.tentativa_id
           AND hc.conquista_id <=> hx.conquista_id AND hc.missao_id <=> hx.missao_id
           AND hc.comunidade_missao_id <=> hx.comunidade_missao_id
         LIMIT 1) AS credito,
       COALESCE(a.nome, q.titulo, cq.nome, m.nome, cm.nome) AS referencia,
       hx.historico_xp_id AS ordem
  FROM historico_xp hx
  LEFT JOIN registro_atividade r ON r.registro_atividade_id = hx.registro_atividade_id
  LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  LEFT JOIN tentativa_quiz t ON t.tentativa_id = hx.tentativa_id
  LEFT JOIN quiz q ON q.quiz_id = t.quiz_id
  LEFT JOIN conquista cq ON cq.conquista_id = hx.conquista_id
  LEFT JOIN missao m ON m.missao_id = hx.missao_id
  LEFT JOIN comunidade_missao cm ON cm.comunidade_missao_id = hx.comunidade_missao_id
UNION ALL
SELECT hc.jogador_id, hc.data, hc.origem, 0 AS xp, hc.quantidade AS credito,
       COALESCE(co.nome, cmd.nome) AS referencia, hc.historico_credito_verde_id AS ordem
  FROM historico_credito_verde hc
  LEFT JOIN construcao co ON co.construcao_id = hc.construcao_id
  LEFT JOIN comunidade cmd ON cmd.comunidade_id = hc.comunidade_id
 WHERE hc.construcao_id IS NOT NULL OR hc.comunidade_id IS NOT NULL;

-- 1ª tentativa concluída de cada jogador em cada quiz (a que vale para o acerto, RN82)
CREATE OR REPLACE VIEW vw_quiz_primeira_tentativa AS
SELECT t.tentativa_id, t.jogador_id, t.quiz_id, q.categoria_ambiental_id, t.data, t.acertos, t.pontuacao
  FROM tentativa_quiz t
  JOIN quiz q ON q.quiz_id = t.quiz_id
 WHERE t.acertos IS NOT NULL
   AND t.tentativa_id = (SELECT MIN(t2.tentativa_id) FROM tentativa_quiz t2
                          WHERE t2.jogador_id = t.jogador_id AND t2.quiz_id = t.quiz_id AND t2.acertos IS NOT NULL);

-- Acerto por pergunta na 1ª tentativa (perguntas mais erradas)
CREATE OR REPLACE VIEW vw_acerto_pergunta AS
SELECT p.pergunta_id, p.quiz_id, p.enunciado, COUNT(r.resposta_quiz_id) AS respostas,
       COALESCE(SUM(r.correta), 0) AS acertos, COUNT(DISTINCT pt.jogador_id) AS jogadores
  FROM pergunta p
  LEFT JOIN resposta_quiz r ON r.pergunta_id = p.pergunta_id
  LEFT JOIN vw_quiz_primeira_tentativa pt ON pt.tentativa_id = r.tentativa_id
 WHERE r.resposta_quiz_id IS NULL OR pt.tentativa_id IS NOT NULL
 GROUP BY p.pergunta_id, p.quiz_id, p.enunciado;

-- Ranking das comunidades ativas (RN68): geral e da semana atual (segunda a domingo)
CREATE OR REPLACE VIEW vw_ranking_comunidade_geral AS
SELECT x.*, ROW_NUMBER() OVER (ORDER BY x.pontos DESC, x.data_criacao, x.comunidade_id) AS posicao
  FROM (SELECT c.comunidade_id, c.nome, c.icone, c.entrada, c.data_criacao,
               (SELECT COUNT(*) FROM comunidade_membro m WHERE m.comunidade_id = c.comunidade_id) AS membros,
               COALESCE((SELECT SUM(pontos) FROM comunidade_contribuicao k WHERE k.comunidade_id = c.comunidade_id), 0)
             + COALESCE((SELECT SUM(pontos) FROM comunidade_missao_resultado s WHERE s.comunidade_id = c.comunidade_id), 0) AS pontos
          FROM comunidade c WHERE c.ativa = TRUE) x;

CREATE OR REPLACE VIEW vw_ranking_comunidade_semana AS
SELECT x.*, ROW_NUMBER() OVER (ORDER BY x.pontos DESC, x.data_criacao, x.comunidade_id) AS posicao
  FROM (SELECT c.comunidade_id, c.nome, c.icone, c.entrada, c.data_criacao,
               (SELECT COUNT(*) FROM comunidade_membro m WHERE m.comunidade_id = c.comunidade_id) AS membros,
               COALESCE((SELECT SUM(pontos) FROM comunidade_contribuicao k WHERE k.comunidade_id = c.comunidade_id
                           AND k.data_registro >= CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY
                           AND k.data_registro <  CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY + INTERVAL 7 DAY), 0)
             + COALESCE((SELECT SUM(pontos) FROM comunidade_missao_resultado s WHERE s.comunidade_id = c.comunidade_id
                           AND s.periodo_inicio = CURDATE() - INTERVAL WEEKDAY(CURDATE()) DAY), 0) AS pontos
          FROM comunidade c WHERE c.ativa = TRUE) x;

-- Impacto confirmado por parceiro e atividade (RN70–RN73)
CREATE OR REPLACE VIEW vw_impacto_parceiro AS
SELECT p.parceiro_id, p.nome, a.atividade_sustentavel_id, a.nome AS atividade, a.impacto_real_unidade AS unidade,
       COUNT(*) AS verificacoes, SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)) AS impacto,
       COUNT(DISTINCT r.jogador_id) AS jogadores, MAX(v.data_verificacao) AS ultima
  FROM verificacao_parceiro v
  JOIN parceiro p ON p.parceiro_id = v.parceiro_id
  JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
 GROUP BY p.parceiro_id, p.nome, a.atividade_sustentavel_id, a.nome, a.impacto_real_unidade;


-- -----------------------------------------------------------------------------
-- 7. Procedures, triggers de auditoria e de regras, event de manutenção
-- -----------------------------------------------------------------------------
DELIMITER $$

-- Grava uma linha de auditoria (chamada pelos triggers). Em manutenção (@ecocity_manutencao = 1) não grava.
CREATE PROCEDURE registrar_auditoria(IN p_tabela VARCHAR(40), IN p_id VARCHAR(40), IN p_acao VARCHAR(10), IN p_antes JSON, IN p_depois JSON)
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        INSERT INTO auditoria (tabela, registro_id, acao, dados_antes, dados_depois, usuario_app, usuario_banco, data)
        VALUES (p_tabela, p_id, p_acao, p_antes, p_depois, @app_usuario_id, USER(), NOW(3));
    END IF;
END$$

-- Auditoria
CREATE TRIGGER trg_aud_usuario_ins AFTER INSERT ON usuario FOR EACH ROW
BEGIN
    CALL registrar_auditoria('usuario', NEW.usuario_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'demonstracao', NEW.demonstracao));
END$$
CREATE TRIGGER trg_aud_usuario_upd AFTER UPDATE ON usuario FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.email <=> NEW.email) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.demonstracao <=> NEW.demonstracao) OR NOT (OLD.senha <=> NEW.senha) THEN
        CALL registrar_auditoria('usuario', NEW.usuario_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'demonstracao', OLD.demonstracao), JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'demonstracao', NEW.demonstracao, 'senha_alterada', NOT (OLD.senha <=> NEW.senha)));
    END IF;
END$$
CREATE TRIGGER trg_aud_usuario_del AFTER DELETE ON usuario FOR EACH ROW
BEGIN
    CALL registrar_auditoria('usuario', OLD.usuario_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'demonstracao', OLD.demonstracao), NULL);
END$$
CREATE TRIGGER trg_aud_administrador_ins AFTER INSERT ON administrador FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', NEW.administrador_id, 'INSERT', NULL, JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo));
END$$
CREATE TRIGGER trg_aud_administrador_upd AFTER UPDATE ON administrador FOR EACH ROW
BEGIN
    IF NOT (OLD.usuario_id <=> NEW.usuario_id) OR NOT (OLD.ativo <=> NEW.ativo) THEN
        CALL registrar_auditoria('administrador', NEW.administrador_id, 'UPDATE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo), JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo));
    END IF;
END$$
CREATE TRIGGER trg_aud_administrador_del AFTER DELETE ON administrador FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', OLD.administrador_id, 'DELETE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo), NULL);
END$$
CREATE TRIGGER trg_aud_jogador_upd AFTER UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NOT (OLD.apelido <=> NEW.apelido) OR NOT (OLD.creditos_verde <=> NEW.creditos_verde) OR NOT (OLD.xp_atual <=> NEW.xp_atual) OR NOT (OLD.nivel_id <=> NEW.nivel_id) THEN
        CALL registrar_auditoria('jogador', NEW.jogador_id, 'UPDATE', JSON_OBJECT('apelido', OLD.apelido, 'creditos_verde', OLD.creditos_verde, 'xp_atual', OLD.xp_atual, 'nivel_id', OLD.nivel_id), JSON_OBJECT('apelido', NEW.apelido, 'creditos_verde', NEW.creditos_verde, 'xp_atual', NEW.xp_atual, 'nivel_id', NEW.nivel_id));
    END IF;
END$$
CREATE TRIGGER trg_aud_registro_atividade_upd AFTER UPDATE ON registro_atividade FOR EACH ROW
BEGIN
    IF NOT (OLD.jogador_id <=> NEW.jogador_id) OR NOT (OLD.atividade_sustentavel_id <=> NEW.atividade_sustentavel_id) OR NOT (OLD.tipo_verificacao_id <=> NEW.tipo_verificacao_id) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.motivo_rejeicao <=> NEW.motivo_rejeicao) OR NOT (OLD.aprovado_por_administrador_id <=> NEW.aprovado_por_administrador_id) THEN
        CALL registrar_auditoria('registro_atividade', NEW.registro_atividade_id, 'UPDATE', JSON_OBJECT('jogador_id', OLD.jogador_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'tipo_verificacao_id', OLD.tipo_verificacao_id, 'status', OLD.status, 'motivo_rejeicao', OLD.motivo_rejeicao, 'aprovado_por_administrador_id', OLD.aprovado_por_administrador_id), JSON_OBJECT('jogador_id', NEW.jogador_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'tipo_verificacao_id', NEW.tipo_verificacao_id, 'status', NEW.status, 'motivo_rejeicao', NEW.motivo_rejeicao, 'aprovado_por_administrador_id', NEW.aprovado_por_administrador_id));
    END IF;
END$$
CREATE TRIGGER trg_aud_registro_atividade_del AFTER DELETE ON registro_atividade FOR EACH ROW
BEGIN
    CALL registrar_auditoria('registro_atividade', OLD.registro_atividade_id, 'DELETE', JSON_OBJECT('jogador_id', OLD.jogador_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'tipo_verificacao_id', OLD.tipo_verificacao_id, 'status', OLD.status, 'motivo_rejeicao', OLD.motivo_rejeicao, 'aprovado_por_administrador_id', OLD.aprovado_por_administrador_id), NULL);
END$$
CREATE TRIGGER trg_aud_parceiro_ins AFTER INSERT ON parceiro FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro', NEW.parceiro_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'motivo_recusa', NEW.motivo_recusa, 'aprovado_por', NEW.aprovado_por));
END$$
CREATE TRIGGER trg_aud_parceiro_upd AFTER UPDATE ON parceiro FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.email <=> NEW.email) OR NOT (OLD.status <=> NEW.status) OR NOT (OLD.motivo_recusa <=> NEW.motivo_recusa) OR NOT (OLD.aprovado_por <=> NEW.aprovado_por) THEN
        CALL registrar_auditoria('parceiro', NEW.parceiro_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'motivo_recusa', OLD.motivo_recusa, 'aprovado_por', OLD.aprovado_por), JSON_OBJECT('nome', NEW.nome, 'email', NEW.email, 'status', NEW.status, 'motivo_recusa', NEW.motivo_recusa, 'aprovado_por', NEW.aprovado_por));
    END IF;
END$$
CREATE TRIGGER trg_aud_parceiro_del AFTER DELETE ON parceiro FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro', OLD.parceiro_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'email', OLD.email, 'status', OLD.status, 'motivo_recusa', OLD.motivo_recusa, 'aprovado_por', OLD.aprovado_por), NULL);
END$$
CREATE TRIGGER trg_aud_comunidade_upd AFTER UPDATE ON comunidade FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.ativa <=> NEW.ativa) OR NOT (OLD.entrada <=> NEW.entrada) THEN
        CALL registrar_auditoria('comunidade', NEW.comunidade_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'ativa', OLD.ativa, 'entrada', OLD.entrada), JSON_OBJECT('nome', NEW.nome, 'ativa', NEW.ativa, 'entrada', NEW.entrada));
    END IF;
END$$
CREATE TRIGGER trg_aud_comunidade_del AFTER DELETE ON comunidade FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade', OLD.comunidade_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'ativa', OLD.ativa, 'entrada', OLD.entrada), NULL);
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_ins AFTER INSERT ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_upd AFTER UPDATE ON atividade_sustentavel FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) OR NOT (OLD.impacto_real_quantidade <=> NEW.impacto_real_quantidade) THEN
        CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'impacto_real_quantidade', OLD.impacto_real_quantidade), JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
    END IF;
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_del AFTER DELETE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', OLD.atividade_sustentavel_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'impacto_real_quantidade', OLD.impacto_real_quantidade), NULL);
END$$
CREATE TRIGGER trg_aud_quiz_ins AFTER INSERT ON quiz FOR EACH ROW
BEGIN
    CALL registrar_auditoria('quiz', NEW.quiz_id, 'INSERT', NULL, JSON_OBJECT('titulo', NEW.titulo, 'tipo', NEW.tipo, 'dificuldade', NEW.dificuldade, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_quiz_upd AFTER UPDATE ON quiz FOR EACH ROW
BEGIN
    IF NOT (OLD.titulo <=> NEW.titulo) OR NOT (OLD.tipo <=> NEW.tipo) OR NOT (OLD.dificuldade <=> NEW.dificuldade) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('quiz', NEW.quiz_id, 'UPDATE', JSON_OBJECT('titulo', OLD.titulo, 'tipo', OLD.tipo, 'dificuldade', OLD.dificuldade, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), JSON_OBJECT('titulo', NEW.titulo, 'tipo', NEW.tipo, 'dificuldade', NEW.dificuldade, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_quiz_del AFTER DELETE ON quiz FOR EACH ROW
BEGIN
    CALL registrar_auditoria('quiz', OLD.quiz_id, 'DELETE', JSON_OBJECT('titulo', OLD.titulo, 'tipo', OLD.tipo, 'dificuldade', OLD.dificuldade, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_missao_ins AFTER INSERT ON missao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('missao', NEW.missao_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'tipo_missao_id', NEW.tipo_missao_id, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_missao_upd AFTER UPDATE ON missao FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.tipo_missao_id <=> NEW.tipo_missao_id) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.data_inicio <=> NEW.data_inicio) OR NOT (OLD.data_fim <=> NEW.data_fim) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('missao', NEW.missao_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'tipo_missao_id', OLD.tipo_missao_id, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'tipo_missao_id', NEW.tipo_missao_id, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_missao_del AFTER DELETE ON missao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('missao', OLD.missao_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'tipo_missao_id', OLD.tipo_missao_id, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_conquista_ins AFTER INSERT ON conquista FOR EACH ROW
BEGIN
    CALL registrar_auditoria('conquista', NEW.conquista_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'tipo_criterio', NEW.tipo_criterio, 'valor_criterio', NEW.valor_criterio, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_conquista_upd AFTER UPDATE ON conquista FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.tipo_criterio <=> NEW.tipo_criterio) OR NOT (OLD.valor_criterio <=> NEW.valor_criterio) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('conquista', NEW.conquista_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'tipo_criterio', OLD.tipo_criterio, 'valor_criterio', OLD.valor_criterio, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'tipo_criterio', NEW.tipo_criterio, 'valor_criterio', NEW.valor_criterio, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_conquista_del AFTER DELETE ON conquista FOR EACH ROW
BEGIN
    CALL registrar_auditoria('conquista', OLD.conquista_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'tipo_criterio', OLD.tipo_criterio, 'valor_criterio', OLD.valor_criterio, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER trg_aud_construcao_ins AFTER INSERT ON construcao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('construcao', NEW.construcao_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'custo', NEW.custo, 'categoria', NEW.categoria, 'renda_diaria', NEW.renda_diaria, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER trg_aud_construcao_upd AFTER UPDATE ON construcao FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.custo <=> NEW.custo) OR NOT (OLD.categoria <=> NEW.categoria) OR NOT (OLD.renda_diaria <=> NEW.renda_diaria) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('construcao', NEW.construcao_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'custo', OLD.custo, 'categoria', OLD.categoria, 'renda_diaria', OLD.renda_diaria, 'ativa', OLD.ativa), JSON_OBJECT('nome', NEW.nome, 'custo', NEW.custo, 'categoria', NEW.categoria, 'renda_diaria', NEW.renda_diaria, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER trg_aud_construcao_del AFTER DELETE ON construcao FOR EACH ROW
BEGIN
    CALL registrar_auditoria('construcao', OLD.construcao_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'custo', OLD.custo, 'categoria', OLD.categoria, 'renda_diaria', OLD.renda_diaria, 'ativa', OLD.ativa), NULL);
END$$

-- Regras de negócio (segunda barreira; o PHP já valida antes)
CREATE TRIGGER trg_regra_registro_decidido BEFORE UPDATE ON registro_atividade FOR EACH ROW
BEGIN
    IF OLD.status <> 'pendente' AND NOT (OLD.status <=> NEW.status) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro já decidido não pode mudar de situação (recompensa única).';
    END IF;
END$$
CREATE TRIGGER trg_regra_jogador_saldo BEFORE UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NEW.creditos_verde < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Créditos verdes insuficientes.';
    END IF;
    IF NEW.xp_atual < OLD.xp_atual AND IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O XP do jogador não pode diminuir.';
    END IF;
END$$
CREATE TRIGGER trg_regra_hist_xp_imutavel BEFORE UPDATE ON historico_xp FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de XP não pode ser alterado.';
END$$
CREATE TRIGGER trg_regra_hist_credito_imutavel BEFORE UPDATE ON historico_credito_verde FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de créditos não pode ser alterado.';
END$$
CREATE TRIGGER trg_regra_mensagem_texto BEFORE UPDATE ON mensagem FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mensagem enviada não pode ser editada.';
    END IF;
END$$
CREATE TRIGGER trg_regra_conquista_permanente BEFORE DELETE ON jogador_conquista FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conquista concedida não pode ser revogada.';
    END IF;
END$$
CREATE TRIGGER trg_regra_auditoria_upd BEFORE UPDATE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A auditoria não pode ser alterada.';
END$$
CREATE TRIGGER trg_regra_auditoria_del BEFORE DELETE ON auditoria FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A auditoria não pode ser apagada.';
END$$

-- Limpeza de tentativas de quiz abandonadas há mais de p_dias (RN82: mantém 30 dias para a taxa de abandono)
CREATE PROCEDURE sp_limpar_tentativas_abandonadas(IN p_dias INT)
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id
     WHERE t.acertos IS NULL AND t.data < CURDATE() - INTERVAL p_dias DAY;
    DELETE FROM tentativa_quiz WHERE acertos IS NULL AND data < CURDATE() - INTERVAL p_dias DAY;
    SET v_total = ROW_COUNT();
    SELECT v_total AS tentativas_apagadas;
END$$

-- Resumo de um jogador: dados, impacto por área e últimos registros
CREATE PROCEDURE sp_resumo_jogador(IN p_jogador INT)
BEGIN
    SELECT * FROM vw_jogador_resumo WHERE jogador_id = p_jogador;
    SELECT ia.nome AS area, COALESCE(v.acoes, 0) AS acoes, COALESCE(v.impacto, 0) AS impacto, a.impacto_real_unidade AS unidade
      FROM indicador_ambiental ia
      LEFT JOIN vw_impacto_jogador_categoria v ON v.indicador_ambiental_id = ia.indicador_ambiental_id AND v.jogador_id = p_jogador
      LEFT JOIN atividade_indicador_ambiental ai ON ai.indicador_ambiental_id = ia.indicador_ambiental_id
      LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ai.atividade_sustentavel_id
     ORDER BY ia.indicador_ambiental_id;
    SELECT atividade, tipo_verificacao, status, data_registro, impacto_efetivo, unidade
      FROM vw_registro_detalhado WHERE jogador_id = p_jogador ORDER BY data_registro DESC LIMIT 10;
END$$

-- Impacto real (ações aprovadas) por atividade num período
CREATE PROCEDURE sp_relatorio_impacto(IN p_inicio DATE, IN p_fim DATE)
BEGIN
    IF p_fim < p_inicio THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Período inválido: o fim é anterior ao início.';
    END IF;
    SELECT atividade, unidade, COUNT(*) AS acoes, COUNT(DISTINCT jogador_id) AS jogadores, SUM(impacto_efetivo) AS impacto
      FROM vw_registro_detalhado
     WHERE status = 'aprovado' AND dia BETWEEN p_inicio AND p_fim
     GROUP BY atividade, unidade ORDER BY acoes DESC;
END$$

-- Ranking das comunidades: 'semana' ou 'geral'
CREATE PROCEDURE sp_ranking_comunidades(IN p_periodo VARCHAR(10))
BEGIN
    IF p_periodo = 'geral' THEN
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_geral ORDER BY posicao;
    ELSE
        SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_semana ORDER BY posicao;
    END IF;
END$$

-- Confere a integridade: saldos × históricos, indicadores, cidades e registros (plano de recuperação)
CREATE PROCEDURE sp_verificar_integridade()
BEGIN
    SELECT 'saldo de créditos diferente da soma do histórico' AS verificacao, COUNT(*) AS problemas
      FROM jogador j WHERE j.creditos_verde <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_credito_verde h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'XP diferente da soma do histórico', COUNT(*)
      FROM jogador j WHERE j.xp_atual <> (SELECT COALESCE(SUM(quantidade), 0) FROM historico_xp h WHERE h.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'cidade sem os 5 indicadores', COUNT(*)
      FROM cidade c WHERE (SELECT COUNT(*) FROM cidade_indicador ci WHERE ci.cidade_id = c.cidade_id) <> (SELECT COUNT(*) FROM indicador_ambiental)
    UNION ALL
    SELECT 'jogador sem cidade', COUNT(*) FROM jogador j WHERE NOT EXISTS (SELECT 1 FROM cidade c WHERE c.jogador_id = j.jogador_id)
    UNION ALL
    SELECT 'registro aprovado sem data de aprovação', COUNT(*) FROM registro_atividade WHERE status = 'aprovado' AND data_aprovacao IS NULL
    UNION ALL
    SELECT 'pergunta sem exatamente 1 alternativa correta', COUNT(*)
      FROM (SELECT pergunta_id FROM alternativa GROUP BY pergunta_id HAVING SUM(correta) <> 1) x;
END$$

-- Apaga os jogadores de demonstração (usuario.demonstracao = TRUE) e tudo o que é deles.
-- p_manter_apresentacao = TRUE mantém as contas apresentacao01..10@demo.ecocity.test.
CREATE PROCEDURE sp_apagar_demonstracao(IN p_manter_apresentacao BOOLEAN)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET @ecocity_manutencao = 0; RESIGNAL; END;
    SET @ecocity_manutencao = 1;
    START TRANSACTION;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    CREATE TEMPORARY TABLE tmp_demo (jogador_id INT PRIMARY KEY, usuario_id INT, cidade_id INT);
    INSERT INTO tmp_demo
    SELECT j.jogador_id, u.usuario_id, c.cidade_id
      FROM usuario u JOIN jogador j ON j.usuario_id = u.usuario_id JOIN cidade c ON c.jogador_id = j.jogador_id
     WHERE u.demonstracao = TRUE AND NOT (p_manter_apresentacao AND u.email LIKE 'apresentacao%@demo.ecocity.test');
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    CREATE TEMPORARY TABLE tmp_demo_com (comunidade_id INT PRIMARY KEY);
    -- Comunidades criadas por jogadores de demonstração (custo de criação no histórico)
    INSERT IGNORE INTO tmp_demo_com
    SELECT h.comunidade_id FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id
     WHERE h.origem = 'comunidade' AND h.comunidade_id IS NOT NULL;

    DELETE r FROM resposta_quiz r JOIN tentativa_quiz t ON t.tentativa_id = r.tentativa_id JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE h FROM historico_xp h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE h FROM historico_credito_verde h JOIN tmp_demo d ON d.jogador_id = h.jogador_id;
    DELETE t FROM tentativa_quiz t JOIN tmp_demo d ON d.jogador_id = t.jogador_id;
    DELETE a FROM acesso_diario a JOIN tmp_demo d ON d.jogador_id = a.jogador_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo d ON d.jogador_id = x.jogador_id;
    DELETE p FROM comunidade_presenca p JOIN tmp_demo d ON d.jogador_id = p.jogador_id;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo d ON d.jogador_id = m.jogador_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo d ON d.jogador_id = cd.denunciante_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo d ON d.jogador_id = tp.autor_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id OR d.jogador_id = cp.convidado_por;
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo d ON d.jogador_id = cm.jogador_id;
    -- Comunidades da demonstração (com quem mais estiver nelas)
    DELETE cm FROM comunidade_membro cm JOIN tmp_demo_com k ON k.comunidade_id = cm.comunidade_id;
    DELETE x FROM comunidade_contribuicao x JOIN tmp_demo_com k ON k.comunidade_id = x.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN tmp_demo_com k ON k.comunidade_id = s.comunidade_id;
    DELETE s FROM comunidade_missao_resultado s JOIN comunidade_missao cmi ON cmi.comunidade_missao_id = s.comunidade_missao_id JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    DELETE cmi FROM comunidade_missao cmi JOIN tmp_demo_com k ON k.comunidade_id = cmi.comunidade_id;
    UPDATE comunidade_missao cmi JOIN tmp_demo d ON d.jogador_id = cmi.criada_por SET cmi.criada_por = NULL;
    DELETE m FROM comunidade_mensagem m JOIN tmp_demo_com k ON k.comunidade_id = m.comunidade_id;
    DELETE p FROM comunidade_presenca p JOIN comunidade_topico tp ON tp.comunidade_topico_id = p.comunidade_topico_id JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE tp FROM comunidade_topico tp JOIN tmp_demo_com k ON k.comunidade_id = tp.comunidade_id;
    DELETE cp FROM comunidade_pedido cp JOIN tmp_demo_com k ON k.comunidade_id = cp.comunidade_id;
    DELETE cd FROM comunidade_denuncia cd JOIN tmp_demo_com k ON k.comunidade_id = cd.comunidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo_com k ON s.escopo = 'comunidade' AND s.escopo_id = k.comunidade_id;
    DELETE c FROM comunidade c JOIN tmp_demo_com k ON k.comunidade_id = c.comunidade_id;
    -- Parceiros, registros e o resto do jogador
    DELETE v FROM verificacao_parceiro v JOIN registro_atividade r ON r.registro_atividade_id = v.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE cp FROM codigo_parceiro cp JOIN tmp_demo d ON d.jogador_id = cp.jogador_id;
    DELETE tc FROM tentativa_codigo_parceiro tc JOIN tmp_demo d ON d.jogador_id = tc.jogador_id;
    DELETE e FROM evidencia_atividade e JOIN registro_atividade r ON r.registro_atividade_id = e.registro_atividade_id JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE r FROM registro_atividade r JOIN tmp_demo d ON d.jogador_id = r.jogador_id;
    DELETE jm FROM jogador_missao jm JOIN tmp_demo d ON d.jogador_id = jm.jogador_id;
    DELETE jc FROM jogador_conquista jc JOIN tmp_demo d ON d.jogador_id = jc.jogador_id;
    DELETE dm FROM denuncia_mensagem dm JOIN mensagem m ON m.mensagem_id = dm.mensagem_id JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE dm FROM denuncia_mensagem dm JOIN tmp_demo d ON d.jogador_id = dm.denunciante_id;
    DELETE m FROM mensagem m JOIN tmp_demo d ON d.jogador_id = m.remetente_id OR d.jogador_id = m.destinatario_id;
    DELETE a FROM amizade a JOIN tmp_demo d ON d.jogador_id = a.solicitante_id OR d.jogador_id = a.destinatario_id;
    DELETE b FROM bloqueio b JOIN tmp_demo d ON d.jogador_id = b.bloqueador_id OR d.jogador_id = b.bloqueado_id;
    DELETE s FROM sugestao_dispensada s JOIN tmp_demo d ON d.jogador_id = s.jogador_id OR d.jogador_id = s.sugerido_id;
    DELETE cc FROM cidade_construcao cc JOIN tmp_demo d ON d.cidade_id = cc.cidade_id;
    DELETE h FROM historico_indicador_cidade h JOIN tmp_demo d ON d.cidade_id = h.cidade_id;
    DELETE ci FROM cidade_indicador ci JOIN tmp_demo d ON d.cidade_id = ci.cidade_id;
    DELETE c FROM cidade c JOIN tmp_demo d ON d.cidade_id = c.cidade_id;
    DELETE s FROM sincronia s JOIN tmp_demo d ON s.escopo = 'jogador' AND s.escopo_id = d.jogador_id;
    DELETE j FROM jogador j JOIN tmp_demo d ON d.jogador_id = j.jogador_id;
    DELETE rs FROM redefinicao_senha rs JOIN tmp_demo d ON d.usuario_id = rs.usuario_id;
    DELETE tl FROM tentativa_login tl JOIN usuario u ON u.email = tl.email JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    DELETE ad FROM administrador ad JOIN tmp_demo d ON d.usuario_id = ad.usuario_id;
    DELETE u FROM usuario u JOIN tmp_demo d ON d.usuario_id = u.usuario_id;
    SELECT COUNT(*) AS jogadores_apagados FROM tmp_demo;
    COMMIT;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo;
    DROP TEMPORARY TABLE IF EXISTS tmp_demo_com;
    SET @ecocity_manutencao = 0;
END$$

-- Manutenção diária às 4h (precisa de event_scheduler = ON)
CREATE EVENT ev_manutencao_diaria
    ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 4 HOUR)
    DO CALL sp_limpar_tentativas_abandonadas(30)$$

DELIMITER ;
