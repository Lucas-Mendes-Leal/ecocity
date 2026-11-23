-- =============================================================================
-- EcoCity — 03. Views para simplificar relatórios
-- Usadas pelo PHP (Perfil, administração, estatísticas, ranking, fila de aprovação) e pela conta de analista
-- (ecocity_role_analista só lê views: nenhuma expõe e-mail, senha ou nome real).
-- =============================================================================
USE ecocity;

-- Funções determinísticas de regra de negócio usadas pelas views e pelo PHP (migração 045)
DELIMITER $$

DROP FUNCTION IF EXISTS fn_fator_verificacao$$
CREATE FUNCTION fn_fator_verificacao(p_tipo_verificacao INT, p_aceita_foto TINYINT(1))
RETURNS INT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_tipo_verificacao = 3 THEN 150
        WHEN p_tipo_verificacao = 1 AND p_aceita_foto = 1 THEN 50
        ELSE 100
    END;
END$$

DROP FUNCTION IF EXISTS fn_estagio_indicador$$
CREATE FUNCTION fn_estagio_indicador(p_valor INT)
RETURNS TINYINT
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_valor >= 75 THEN 4
        WHEN p_valor >= 50 THEN 3
        WHEN p_valor >= 25 THEN 2
        ELSE 1
    END;
END$$

DROP FUNCTION IF EXISTS fn_situacao_codigo_parceiro$$
CREATE FUNCTION fn_situacao_codigo_parceiro(
    p_usado_em DATETIME,
    p_cancelado TINYINT(1),
    p_expira_em DATETIME,
    p_agora DATETIME
)
RETURNS VARCHAR(15) CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci
DETERMINISTIC
NO SQL
BEGIN
    RETURN CASE
        WHEN p_usado_em IS NOT NULL THEN 'usado'
        WHEN p_cancelado = 1 THEN 'cancelado'
        WHEN p_expira_em <= p_agora THEN 'vencido'
        ELSE 'aguardando'
    END;
END$$

DELIMITER ;

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

-- Registro de atividade com atividade, área, tipo, impacto real efetivo e pontos no indicador (RN04/RN26/RN73/RN92, migração 045)
CREATE OR REPLACE VIEW vw_registro_detalhado AS
SELECT r.registro_atividade_id, r.jogador_id, r.atividade_sustentavel_id, a.nome AS atividade,
       a.categoria_ambiental_id, ai.indicador_ambiental_id, a.aceita_foto, a.aceita_parceiro,
       r.tipo_verificacao_id, tv.nome AS tipo_verificacao, r.status,
       r.data_registro, DATE(r.data_registro) AS dia, r.data_aprovacao, r.aprovado_por_administrador_id,
       r.motivo_rejeicao, r.missao_atividade_id,
       COALESCE(r.impacto_real, a.impacto_real_quantidade) AS impacto_efetivo, a.impacto_real_unidade AS unidade,
       FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN tipo_verificacao tv ON tv.tipo_verificacao_id = r.tipo_verificacao_id;

-- Impacto real multi-unidade e pontos no indicador por jogador, área e unidade física (migração 045)
CREATE OR REPLACE VIEW vw_impacto_jogador_unidade AS
SELECT r.jogador_id,
       ai.indicador_ambiental_id,
       ia.nome AS categoria,
       a.impacto_real_unidade AS unidade,
       COUNT(*) AS acoes,
       COALESCE(SUM(r.tipo_verificacao_id = 1), 0) AS sem_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 2), 0) AS com_foto,
       COALESCE(SUM(r.tipo_verificacao_id = 3), 0) AS com_parceiro,
       COALESCE(SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)), 0) AS impacto,
       COALESCE(SUM(FLOOR(ai.impacto * fn_fator_verificacao(r.tipo_verificacao_id, a.aceita_foto) / 100)), 0) AS pontos_indicador
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = a.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
 WHERE r.status = 'aprovado'
 GROUP BY r.jogador_id, ai.indicador_ambiental_id, ia.nome, a.impacto_real_unidade;

-- Ações aprovadas e impacto real (da unidade principal da área) por jogador e área (conquistas de impacto, estatísticas)
CREATE OR REPLACE VIEW vw_impacto_jogador_categoria AS
SELECT r.jogador_id,
       ai.indicador_ambiental_id,
       COUNT(*) AS acoes,
       COALESCE(SUM(
           CASE
               WHEN a.impacto_real_unidade = (
                   SELECT a0.impacto_real_unidade
                     FROM atividade_indicador_ambiental ai0
                     JOIN atividade_sustentavel a0 ON a0.atividade_sustentavel_id = ai0.atividade_sustentavel_id
                    WHERE ai0.indicador_ambiental_id = ai.indicador_ambiental_id
                      AND a0.impacto_real_unidade IS NOT NULL
                    ORDER BY a0.atividade_sustentavel_id
                    LIMIT 1
               )
               THEN COALESCE(r.impacto_real, a.impacto_real_quantidade)
               ELSE 0
           END
       ), 0) AS impacto
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

-- Extrato: ganhos de XP (com os créditos do mesmo evento) + créditos sem XP (construções, renda, comunidade, promoções, ajustes)
CREATE OR REPLACE VIEW vw_extrato AS
SELECT hx.jogador_id, hx.data, hx.origem, hx.quantidade AS xp,
       (SELECT hc.quantidade FROM historico_credito_verde hc
         WHERE hc.jogador_id = hx.jogador_id AND hc.origem = hx.origem
           AND hc.registro_atividade_id <=> hx.registro_atividade_id AND hc.tentativa_id <=> hx.tentativa_id
           AND hc.conquista_id <=> hx.conquista_id AND hc.missao_id <=> hx.missao_id
           AND hc.comunidade_missao_id <=> hx.comunidade_missao_id
           AND hc.codigo_promocional_id <=> hx.codigo_promocional_id
           AND hc.ajuste_admin_id <=> hx.ajuste_admin_id
           AND (hx.codigo_promocional_id IS NOT NULL OR hx.ajuste_admin_id IS NOT NULL OR hc.data = hx.data)
         LIMIT 1) AS credito,
       COALESCE(a.nome, q.titulo, cq.nome, m.nome, cm.nome, cp.nome, aa.motivo) AS referencia,
       hx.historico_xp_id AS ordem
  FROM historico_xp hx
  LEFT JOIN registro_atividade r ON r.registro_atividade_id = hx.registro_atividade_id
  LEFT JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
  LEFT JOIN tentativa_quiz t ON t.tentativa_id = hx.tentativa_id
  LEFT JOIN quiz q ON q.quiz_id = t.quiz_id
  LEFT JOIN conquista cq ON cq.conquista_id = hx.conquista_id
  LEFT JOIN missao m ON m.missao_id = hx.missao_id
  LEFT JOIN comunidade_missao cm ON cm.comunidade_missao_id = hx.comunidade_missao_id
  LEFT JOIN codigo_promocional cp ON cp.codigo_promocional_id = hx.codigo_promocional_id
  LEFT JOIN ajuste_conta_admin aa ON aa.ajuste_admin_id = hx.ajuste_admin_id
UNION ALL
SELECT hc.jogador_id, hc.data, hc.origem, 0 AS xp, hc.quantidade AS credito,
       COALESCE(co.nome, cmd.nome, cp2.nome, aa2.motivo) AS referencia, hc.historico_credito_verde_id AS ordem
  FROM historico_credito_verde hc
  LEFT JOIN construcao co ON co.construcao_id = hc.construcao_id
  LEFT JOIN comunidade cmd ON cmd.comunidade_id = hc.comunidade_id
  LEFT JOIN codigo_promocional cp2 ON cp2.codigo_promocional_id = hc.codigo_promocional_id
  LEFT JOIN ajuste_conta_admin aa2 ON aa2.ajuste_admin_id = hc.ajuste_admin_id
 WHERE hc.construcao_id IS NOT NULL OR hc.comunidade_id IS NOT NULL
    OR (hc.codigo_promocional_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM historico_xp hx2
         WHERE hx2.jogador_id = hc.jogador_id AND hx2.codigo_promocional_id = hc.codigo_promocional_id
    ))
    OR (hc.ajuste_admin_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM historico_xp hx3
         WHERE hx3.jogador_id = hc.jogador_id AND hx3.ajuste_admin_id = hc.ajuste_admin_id
    ));

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


