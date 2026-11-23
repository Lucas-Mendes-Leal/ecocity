-- =============================================================================
-- EcoCity — 02. Índices para otimização de consultas
-- Cada índice composto atende uma consulta real do jogo (comentário acima de cada um) e substitui o índice
-- simples que o MySQL cria para a chave estrangeira (a FK passa a usar o composto, pelo prefixo).
-- Além destes, 01-criar-banco.sql já traz: PKs, UNIQUE (e-mail, código público, nome de comunidade ativa,
-- pares de amizade…) e índices de apoio (fila de mensagens não lidas, contribuições por comunidade e semana etc.).
-- Medição: database/projeto/09-monitoramento.sql (EXPLAIN com e sem o índice).
-- =============================================================================
USE ecocity;
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
-- Evolução sustentável, Meu impacto e contagem por status do jogador (migração 045): WHERE jogador_id = ? AND status = 'aprovado' ORDER BY data_registro DESC
ALTER TABLE registro_atividade ADD KEY ix_registro_jogador_status_data (jogador_id, status, data_registro);
-- Histórico recente de quizzes do jogador (migração 045): WHERE jogador_id = ? AND acertos IS NOT NULL ORDER BY data DESC
ALTER TABLE tentativa_quiz ADD KEY ix_tentativa_jogador_data (jogador_id, data);
-- Histórico e relatórios de códigos utilizados por parceiro (migração 045): WHERE parceiro_id = ? AND usado_em IS NOT NULL ORDER BY usado_em DESC
ALTER TABLE codigo_parceiro ADD KEY ix_codigo_parceiro_usado (parceiro_id, usado_em);


