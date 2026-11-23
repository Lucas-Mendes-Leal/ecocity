-- =============================================================================
-- EcoCity — 09. Monitoramento de desempenho e otimização
-- Rodar como ecocity_dba (ou root). Usa o performance_schema e o schema sys do MySQL 8.
-- O log de consultas lentas está ligado no servidor do site (docker-compose.yml:
-- --slow-query-log=ON --long-query-time=0.5): toda consulta acima de 0,5 s fica registrada.
-- =============================================================================
USE ecocity;

-- 1. Configuração atual de monitoramento --------------------------------------
SELECT @@slow_query_log AS log_lento, @@long_query_time AS limite_segundos, @@slow_query_log_file AS arquivo,
       @@event_scheduler AS agendador_eventos, @@binlog_expire_logs_seconds / 86400 AS dias_de_binlog;

-- 2. As 10 consultas que mais tempo gastaram no total (desde que o servidor ligou)
SELECT LEFT(query, 120) AS consulta, exec_count AS execucoes, total_latency AS tempo_total, avg_latency AS tempo_medio,
       rows_examined_avg AS linhas_lidas_em_media, full_scan AS sem_indice
  FROM sys.statement_analysis
 WHERE db = 'ecocity'
 ORDER BY total_latency DESC
 LIMIT 10;

-- 3. Consultas que leem a tabela inteira (candidatas a um índice novo)
SELECT LEFT(query, 120) AS consulta, exec_count AS execucoes, no_index_used_count AS vezes_sem_indice
  FROM sys.statements_with_full_table_scans
 WHERE db = 'ecocity'
 ORDER BY no_index_used_count DESC
 LIMIT 10;

-- 4. Índices que nunca foram usados desde que o servidor ligou (candidatos a remover; conferir antes)
SELECT object_name AS tabela, index_name AS indice
  FROM sys.schema_unused_indexes
 WHERE object_schema = 'ecocity';

-- 5. Tamanho de cada tabela (dados + índices) e quantidade aproximada de linhas
SELECT table_name AS tabela, table_rows AS linhas_aprox,
       ROUND(data_length / 1024) AS dados_kb, ROUND(index_length / 1024) AS indices_kb
  FROM information_schema.tables
 WHERE table_schema = 'ecocity' AND table_type = 'BASE TABLE'
 ORDER BY data_length + index_length DESC
 LIMIT 15;

-- 6. Conexões abertas agora, por conta (o site deve aparecer como ecocity_web)
SELECT user AS conta, COUNT(*) AS conexoes, SUM(command <> 'Sleep') AS trabalhando
  FROM information_schema.processlist
 GROUP BY user;

-- 7. Antes × depois dos índices compostos (02-indices.sql) ---------------------
-- Em cada par, o primeiro EXPLAIN força o MySQL a ignorar o índice novo (como era antes)
-- e o segundo usa o índice. Comparar as colunas type, key e rows.

-- 7.1 Extrato do Perfil: históricos de um jogador em ordem de data
EXPLAIN SELECT data, quantidade FROM historico_xp IGNORE INDEX (ix_hist_xp_jogador_data)
         WHERE jogador_id = (SELECT MIN(jogador_id) FROM jogador) ORDER BY data DESC LIMIT 20;
EXPLAIN SELECT data, quantidade FROM historico_xp
         WHERE jogador_id = (SELECT MIN(jogador_id) FROM jogador) ORDER BY data DESC LIMIT 20;

-- 7.2 Limite diário (RN44): já registrou esta atividade hoje?
EXPLAIN SELECT 1 FROM registro_atividade IGNORE INDEX (ix_registro_jogador_atividade_data)
         WHERE jogador_id = 1 AND atividade_sustentavel_id = 2 AND data_registro >= CURDATE() AND status <> 'rejeitado';
EXPLAIN SELECT 1 FROM registro_atividade
         WHERE jogador_id = 1 AND atividade_sustentavel_id = 2 AND data_registro >= CURDATE() AND status <> 'rejeitado';

-- 7.3 Fila de aprovação: registros com foto pendentes, mais antigos primeiro
EXPLAIN SELECT registro_atividade_id FROM registro_atividade IGNORE INDEX (ix_registro_tipo_status_data, ix_registro_status_data)
         WHERE tipo_verificacao_id = 2 AND status = 'pendente' ORDER BY data_registro;
EXPLAIN SELECT registro_atividade_id FROM registro_atividade
         WHERE tipo_verificacao_id = 2 AND status = 'pendente' ORDER BY data_registro;

-- 7.4 Rotação dos quizzes: o jogador já concluiu este quiz?
EXPLAIN SELECT MIN(tentativa_id) FROM tentativa_quiz IGNORE INDEX (ix_tentativa_jogador_quiz)
         WHERE jogador_id = 1 AND quiz_id = 7 AND acertos IS NOT NULL;
EXPLAIN SELECT MIN(tentativa_id) FROM tentativa_quiz
         WHERE jogador_id = 1 AND quiz_id = 7 AND acertos IS NOT NULL;

-- 7.5 Variação dos indicadores de uma cidade (LAG por indicador, em ordem de data)
EXPLAIN SELECT valor, data FROM historico_indicador_cidade IGNORE INDEX (ix_hist_ind_cidade_data)
         WHERE cidade_id = 1 AND indicador_ambiental_id = 2 ORDER BY data, historico_indicador_id;
EXPLAIN SELECT valor, data FROM historico_indicador_cidade
         WHERE cidade_id = 1 AND indicador_ambiental_id = 2 ORDER BY data, historico_indicador_id;

-- 7.6 Evolução sustentável e Meu impacto por jogador e status (migração 045: elimina Using filesort)
EXPLAIN SELECT registro_atividade_id, atividade_sustentavel_id, data_registro FROM registro_atividade IGNORE INDEX (ix_registro_jogador_status_data)
         WHERE jogador_id = 1 AND status = 'aprovado' ORDER BY data_registro DESC LIMIT 8;
EXPLAIN SELECT registro_atividade_id, atividade_sustentavel_id, data_registro FROM registro_atividade
         WHERE jogador_id = 1 AND status = 'aprovado' ORDER BY data_registro DESC LIMIT 8;

-- 7.7 Histórico recente de quizzes do jogador (migração 045: elimina Using filesort)
EXPLAIN SELECT tentativa_id, quiz_id, acertos, data FROM tentativa_quiz IGNORE INDEX (ix_tentativa_jogador_data)
         WHERE jogador_id = 1 AND acertos IS NOT NULL ORDER BY data DESC, tentativa_id DESC LIMIT 6;
EXPLAIN SELECT tentativa_id, quiz_id, acertos, data FROM tentativa_quiz
         WHERE jogador_id = 1 AND acertos IS NOT NULL ORDER BY data DESC, tentativa_id DESC LIMIT 6;

-- 7.8 Histórico e relatórios de códigos utilizados por parceiro (migração 045: elimina Using filesort)
EXPLAIN SELECT * FROM codigo_parceiro IGNORE INDEX (ix_codigo_parceiro_usado)
         WHERE parceiro_id = 1 AND usado_em IS NOT NULL ORDER BY usado_em DESC LIMIT 50;
EXPLAIN SELECT * FROM codigo_parceiro
         WHERE parceiro_id = 1 AND usado_em IS NOT NULL ORDER BY usado_em DESC LIMIT 50;

-- 8. Rotina de otimização (rodar de vez em quando, fora do horário de uso) ----
ANALYZE TABLE registro_atividade, historico_xp, historico_credito_verde, tentativa_quiz, resposta_quiz, historico_indicador_cidade;
-- OPTIMIZE TABLE historico_indicador_cidade;   -- reorganiza depois de muitas exclusões (bloqueia a tabela por alguns segundos)
