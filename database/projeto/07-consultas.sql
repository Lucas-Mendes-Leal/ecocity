-- =============================================================================
-- EcoCity — 07. Consultas para extrair informações (SELECT com JOINs, filtros, agrupamentos e subconsultas)
-- Cada consulta responde a uma pergunta de negócio (comentário acima). Rodam em qualquer banco do EcoCity;
-- com os dados de demonstração (06) todas devolvem linhas. Técnicas usadas em cada uma entre colchetes.
-- =============================================================================
USE ecocity;

-- -----------------------------------------------------------------------------
-- A. Jogadores e progresso
-- -----------------------------------------------------------------------------

-- 1. Quem são os 10 jogadores com mais XP, com nível, cidade e nível da cidade?  [INNER JOIN, LEFT JOIN, ORDER BY, LIMIT]
SELECT j.apelido, j.xp_atual, nj.nivel AS nivel_jogador, c.nome AS cidade, nc.nome AS estagio_cidade
  FROM jogador j
  JOIN cidade c ON c.jogador_id = j.jogador_id
  LEFT JOIN nivel_jogador nj ON nj.nivel_jogador_id = j.nivel_id
  LEFT JOIN nivel_cidade nc ON nc.nivel_cidade_id = c.nivel_cidade_id
 ORDER BY j.xp_atual DESC
 LIMIT 10;

-- 2. Quantas cidades há em cada estágio ecológico (inclusive estágios sem nenhuma cidade)?  [LEFT JOIN, GROUP BY, COUNT, view]
SELECT n.nivel, n.nome, COUNT(p.cidade_id) AS cidades
  FROM nivel_cidade n
  LEFT JOIN vw_cidade_progresso p ON p.nivel_calculado = n.nivel
 GROUP BY n.nivel, n.nome
 ORDER BY n.nivel;

-- 3. Qual a média, o mínimo e o máximo de cada indicador ambiental entre todas as cidades?  [JOIN, GROUP BY, AVG/MIN/MAX]
SELECT ia.nome AS indicador, ROUND(AVG(ci.valor), 1) AS media, MIN(ci.valor) AS minimo, MAX(ci.valor) AS maximo
  FROM indicador_ambiental ia
  JOIN cidade_indicador ci ON ci.indicador_ambiental_id = ia.indicador_ambiental_id
 GROUP BY ia.indicador_ambiental_id, ia.nome
 ORDER BY media;

-- 4. Quais jogadores têm saldo acima da média de todos os jogadores?  [subconsulta escalar, WHERE]
SELECT apelido, creditos_verde
  FROM jogador
 WHERE creditos_verde > (SELECT AVG(creditos_verde) FROM jogador)
 ORDER BY creditos_verde DESC;

-- 5. Quem se cadastrou e nunca teve uma ação aprovada (precisa de incentivo)?  [NOT EXISTS, subconsulta correlacionada]
SELECT j.apelido, j.data_criacao
  FROM jogador j
 WHERE NOT EXISTS (SELECT 1 FROM registro_atividade r WHERE r.jogador_id = j.jogador_id AND r.status = 'aprovado')
 ORDER BY j.data_criacao;

-- 6. Ranking de XP com a posição de cada jogador e a diferença para o anterior.  [função de janela RANK e LAG]
SELECT apelido, xp_atual,
       RANK() OVER (ORDER BY xp_atual DESC) AS posicao,
       LAG(xp_atual) OVER (ORDER BY xp_atual DESC) - xp_atual AS atras_do_anterior
  FROM jogador
 ORDER BY posicao
 LIMIT 15;

-- -----------------------------------------------------------------------------
-- B. Ações sustentáveis e impacto real
-- -----------------------------------------------------------------------------

-- 7. Impacto real total por atividade (só ações aprovadas), com a unidade.  [JOIN, GROUP BY, SUM, COALESCE]
SELECT a.nome AS atividade, COUNT(*) AS acoes, SUM(COALESCE(r.impacto_real, a.impacto_real_quantidade)) AS impacto, a.impacto_real_unidade AS unidade
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
 WHERE r.status = 'aprovado'
 GROUP BY a.atividade_sustentavel_id, a.nome, a.impacto_real_unidade
 ORDER BY acoes DESC;

-- 8. Em que dia da semana e horário as pessoas mais registram ações?  [funções de data, GROUP BY com duas colunas, LIMIT]
SELECT ELT(WEEKDAY(data_registro) + 1, 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom') AS dia, HOUR(data_registro) AS hora, COUNT(*) AS acoes
  FROM registro_atividade
 WHERE status <> 'rejeitado'
 GROUP BY WEEKDAY(data_registro), HOUR(data_registro)
 ORDER BY acoes DESC
 LIMIT 10;

-- 9. Ações aprovadas por semana e o total acumulado.  [CTE, GROUP BY, SUM OVER (acumulado)]
WITH semanal AS (
    SELECT DATE_SUB(DATE(data_registro), INTERVAL WEEKDAY(data_registro) DAY) AS semana, COUNT(*) AS acoes
      FROM registro_atividade WHERE status = 'aprovado'
     GROUP BY semana
)
SELECT semana, acoes, SUM(acoes) OVER (ORDER BY semana) AS acumulado
  FROM semanal
 ORDER BY semana;

-- 10. Taxa de aprovação das ações com foto por atividade (só atividades com 20 decisões ou mais).  [agregação condicional, HAVING]
SELECT a.nome AS atividade,
       SUM(r.status = 'aprovado') AS aprovadas, SUM(r.status = 'rejeitado') AS rejeitadas,
       ROUND(100 * SUM(r.status = 'aprovado') / COUNT(*), 1) AS pct_aprovacao
  FROM registro_atividade r
  JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = r.atividade_sustentavel_id
 WHERE r.tipo_verificacao_id = 2 AND r.status IN ('aprovado', 'rejeitado')
 GROUP BY a.atividade_sustentavel_id, a.nome
HAVING COUNT(*) >= 20
 ORDER BY pct_aprovacao;

-- 11. Motivos de rejeição mais comuns.  [GROUP BY em texto, ORDER BY]
SELECT motivo_rejeicao, COUNT(*) AS vezes
  FROM registro_atividade
 WHERE status = 'rejeitado'
 GROUP BY motivo_rejeicao
 ORDER BY vezes DESC;

-- 12. Tempo médio (em horas) até a decisão das ações com foto: administrador × automática.  [CASE, TIMESTAMPDIFF, AVG]
SELECT CASE WHEN aprovado_por_administrador_id IS NULL THEN 'automática (24 h)' ELSE 'administrador' END AS quem_decidiu,
       COUNT(*) AS decisoes, ROUND(AVG(TIMESTAMPDIFF(MINUTE, data_registro, data_aprovacao)) / 60, 1) AS horas_em_media
  FROM registro_atividade
 WHERE tipo_verificacao_id = 2 AND status IN ('aprovado', 'rejeitado')
 GROUP BY quem_decidiu;

-- 13. Para cada jogador, a área em que mais age (empate: a de menor id).  [tabela derivada, ROW_NUMBER, PARTITION BY]
SELECT apelido, area, acoes
  FROM (SELECT j.apelido, ia.nome AS area, v.acoes,
               ROW_NUMBER() OVER (PARTITION BY v.jogador_id ORDER BY v.acoes DESC, v.indicador_ambiental_id) AS ordem
          FROM vw_impacto_jogador_categoria v
          JOIN jogador j ON j.jogador_id = v.jogador_id
          JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = v.indicador_ambiental_id) x
 WHERE ordem = 1
 ORDER BY acoes DESC
 LIMIT 15;

-- -----------------------------------------------------------------------------
-- C. Aprendizado (quizzes)
-- -----------------------------------------------------------------------------

-- 14. Acerto na 1ª tentativa por tema (só a 1ª tentativa de cada jogador em cada quiz vale).  [view, JOIN, agregação]
SELECT ca.nome AS tema, COUNT(DISTINCT pt.jogador_id) AS jogadores, COUNT(*) AS respostas,
       ROUND(100 * SUM(r.correta) / COUNT(*), 1) AS pct_acerto
  FROM vw_quiz_primeira_tentativa pt
  JOIN resposta_quiz r ON r.tentativa_id = pt.tentativa_id
  JOIN categoria_ambiental ca ON ca.categoria_ambiental_id = pt.categoria_ambiental_id
 GROUP BY ca.categoria_ambiental_id, ca.nome
 ORDER BY pct_acerto;

-- 15. As 10 perguntas mais erradas (com 5 jogadores ou mais) e a alternativa errada mais escolhida.  [view, subconsulta correlacionada com LIMIT]
SELECT ap.enunciado, ap.respostas, ROUND(100 * (ap.respostas - ap.acertos) / ap.respostas, 1) AS pct_erro,
       (SELECT al.texto FROM resposta_quiz r JOIN alternativa al ON al.alternativa_id = r.alternativa_id
         WHERE r.pergunta_id = ap.pergunta_id AND r.correta = FALSE
         GROUP BY al.alternativa_id, al.texto ORDER BY COUNT(*) DESC LIMIT 1) AS errada_mais_escolhida
  FROM vw_acerto_pergunta ap
 WHERE ap.jogadores >= 5 AND ap.acertos < ap.respostas
 ORDER BY pct_erro DESC
 LIMIT 10;

-- 16. Quizzes mais feitos e quantos jogadores atingiram a nota mínima (70%) na 1ª tentativa.  [JOIN, subconsulta escalar, agregação condicional]
SELECT q.titulo, q.tipo, COUNT(*) AS primeiras_tentativas,
       SUM(pt.acertos * 100 >= 70 * (SELECT COUNT(*) FROM pergunta p WHERE p.quiz_id = q.quiz_id)) AS atingiram_70
  FROM vw_quiz_primeira_tentativa pt
  JOIN quiz q ON q.quiz_id = pt.quiz_id
 GROUP BY q.quiz_id, q.titulo, q.tipo
 ORDER BY primeiras_tentativas DESC;

-- 17. Tentativas abandonadas (iniciadas e não concluídas) por quiz.  [LEFT JOIN, filtro de NULL, HAVING]
SELECT q.titulo, COUNT(t.tentativa_id) AS iniciadas, SUM(t.acertos IS NULL) AS abandonadas
  FROM quiz q
  LEFT JOIN tentativa_quiz t ON t.quiz_id = q.quiz_id
 GROUP BY q.quiz_id, q.titulo
HAVING abandonadas > 0
 ORDER BY abandonadas DESC;

-- -----------------------------------------------------------------------------
-- D. Engajamento
-- -----------------------------------------------------------------------------

-- 18. Jogadores ativos por semana (pelo menos um acesso na semana).  [GROUP BY por semana, COUNT DISTINCT]
SELECT DATE_SUB(data, INTERVAL WEEKDAY(data) DAY) AS semana, COUNT(DISTINCT jogador_id) AS ativos
  FROM acesso_diario
 GROUP BY semana
 ORDER BY semana;

-- 19. Retenção: dos cadastrados em cada semana, quantos voltaram na semana seguinte.  [CTE, LEFT JOIN, EXISTS]
WITH coorte AS (
    SELECT jogador_id, DATE_SUB(data_criacao, INTERVAL WEEKDAY(data_criacao) DAY) AS semana FROM jogador
)
SELECT c.semana, COUNT(*) AS cadastrados,
       SUM(EXISTS (SELECT 1 FROM acesso_diario a WHERE a.jogador_id = c.jogador_id
                    AND a.data BETWEEN c.semana + INTERVAL 7 DAY AND c.semana + INTERVAL 13 DAY)) AS voltaram,
       ROUND(100 * SUM(EXISTS (SELECT 1 FROM acesso_diario a WHERE a.jogador_id = c.jogador_id
                    AND a.data BETWEEN c.semana + INTERVAL 7 DAY AND c.semana + INTERVAL 13 DAY)) / COUNT(*), 1) AS pct
  FROM coorte c
 WHERE c.semana + INTERVAL 13 DAY < CURDATE()
 GROUP BY c.semana
 ORDER BY c.semana;

-- 20. Quem não usa o jogo há 14 dias ou mais (para uma campanha de retorno).  [JOIN com tabela derivada, MAX, filtro de data]
SELECT j.apelido, ua.ultimo_acesso, DATEDIFF(CURDATE(), ua.ultimo_acesso) AS dias_sem_jogar
  FROM jogador j
  JOIN (SELECT jogador_id, MAX(data) AS ultimo_acesso FROM acesso_diario GROUP BY jogador_id) ua ON ua.jogador_id = j.jogador_id
 WHERE ua.ultimo_acesso <= CURDATE() - INTERVAL 14 DAY
 ORDER BY dias_sem_jogar DESC;

-- 21. Conquistas mais e menos alcançadas, em % dos jogadores.  [LEFT JOIN, subconsulta escalar no SELECT]
SELECT c.nome, COUNT(jc.jogador_id) AS jogadores,
       ROUND(100 * COUNT(jc.jogador_id) / (SELECT COUNT(*) FROM jogador), 1) AS pct_jogadores
  FROM conquista c
  LEFT JOIN jogador_conquista jc ON jc.conquista_id = c.conquista_id
 GROUP BY c.conquista_id, c.nome
 ORDER BY jogadores DESC;

-- 22. De onde vêm os créditos verdes ganhos e para onde vão os gastos.  [GROUP BY com CASE, SUM]
SELECT origem, CASE WHEN quantidade > 0 THEN 'ganho' ELSE 'gasto' END AS tipo, COUNT(*) AS lancamentos, SUM(quantidade) AS total
  FROM historico_credito_verde
 GROUP BY origem, tipo
 ORDER BY total DESC;

-- -----------------------------------------------------------------------------
-- E. Social, comunidades e construções
-- -----------------------------------------------------------------------------

-- 23. Ranking das comunidades (geral) com membros e pontos.  [view com ROW_NUMBER]
SELECT posicao, nome, membros, pontos FROM vw_ranking_comunidade_geral ORDER BY posicao;

-- 24. Contribuição de cada comunidade por área: ações e impacto real.  [JOIN de 3 tabelas, GROUP BY, ROLLUP]
SELECT COALESCE(cm.nome, 'TOTAL') AS comunidade, COALESCE(ia.nome, '(todas as áreas)') AS area, COUNT(*) AS acoes, SUM(cc.pontos) AS pontos
  FROM comunidade_contribuicao cc
  JOIN comunidade cm ON cm.comunidade_id = cc.comunidade_id
  JOIN atividade_indicador_ambiental ai ON ai.atividade_sustentavel_id = cc.atividade_sustentavel_id
  JOIN indicador_ambiental ia ON ia.indicador_ambiental_id = ai.indicador_ambiental_id
 GROUP BY cm.nome, ia.nome WITH ROLLUP;

-- 25. Pares de amigos que mais conversam.  [autojunção da tabela jogador, LEAST/GREATEST, GROUP BY]
SELECT a.apelido AS jogador_1, b.apelido AS jogador_2, COUNT(*) AS mensagens
  FROM mensagem m
  JOIN jogador a ON a.jogador_id = LEAST(m.remetente_id, m.destinatario_id)
  JOIN jogador b ON b.jogador_id = GREATEST(m.remetente_id, m.destinatario_id)
 GROUP BY a.jogador_id, b.jogador_id, a.apelido, b.apelido
 ORDER BY mensagens DESC
 LIMIT 10;

-- 26. Estruturas mais construídas e quantos créditos foram gastos nelas.  [JOIN, GROUP BY, subconsulta IN]
SELECT co.nome, co.categoria, COUNT(*) AS construidas, COUNT(*) * co.custo AS creditos_gastos
  FROM cidade_construcao cc
  JOIN construcao co ON co.construcao_id = cc.construcao_id
 WHERE cc.cidade_id IN (SELECT cidade_id FROM cidade)
 GROUP BY co.construcao_id, co.nome, co.categoria, co.custo
 ORDER BY construidas DESC;

-- 27. Auditoria: últimas mudanças detalhadas (usuário, parceiro, rota, IP e conta MySQL).  [LEFT JOIN, JSON_EXTRACT]
SELECT a.data, a.tabela, a.registro_id, a.acao,
       COALESCE(u.nome, p.nome, 'SQL direto / sistema') AS feito_por,
       pf.nome AS atendente,
       a.origem,
       a.ip,
       a.usuario_banco,
       JSON_EXTRACT(a.dados_antes, '$.status') AS status_antes, JSON_EXTRACT(a.dados_depois, '$.status') AS status_depois
  FROM auditoria a
  LEFT JOIN usuario u ON u.usuario_id = a.usuario_app
  LEFT JOIN parceiro p ON p.parceiro_id = a.parceiro_app
  LEFT JOIN parceiro_funcionario pf ON pf.funcionario_id = a.funcionario_app
 ORDER BY a.auditoria_id DESC
 LIMIT 20;
