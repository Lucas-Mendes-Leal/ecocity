-- =============================================================================
-- EcoCity — 08. Segurança: perfis de usuário (roles) e permissões (DCL)
-- MySQL 8: CREATE ROLE / CREATE USER / GRANT / REVOKE / SET DEFAULT ROLE.
-- Princípio do menor privilégio: cada conta só tem o que a sua função exige.
-- As senhas abaixo são marcadores (${SENHA_...}) trocados na hora de aplicar pelas senhas
-- do arquivo de segredos (docker/.env); nunca ficam gravadas neste arquivo.
--
-- Perfis
--   ecocity_role_dba       Administrador do banco: tudo no schema, inclusive conceder permissões.
--   ecocity_role_app       Operador (o site PHP): ler e gravar os dados do jogo; sem DDL;
--                          históricos só recebem INSERT; auditoria inacessível.
--   ecocity_role_analista  Gerente / analista: só as views de relatório e 2 procedures de relatório.
--   ecocity_role_backup    Rotina de backup: leitura consistente, binlogs e definições.
-- Contas (uma por perfil): ecocity_dba (só local), ecocity_web, ecocity_analista, ecocity_backup (só local).
-- =============================================================================

-- 1. Perfis -------------------------------------------------------------------
CREATE ROLE IF NOT EXISTS 'ecocity_role_dba', 'ecocity_role_app', 'ecocity_role_analista', 'ecocity_role_backup';

-- 1.1 Administrador do banco
GRANT ALL PRIVILEGES ON ecocity.* TO 'ecocity_role_dba' WITH GRANT OPTION;

-- 1.2 Operador (aplicação): permissões tabela a tabela (não por schema), para poder restringir algumas
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`acesso_diario` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`administrador` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`alternativa` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`amizade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`atividade_indicador_ambiental` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`atividade_sustentavel` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`bloqueio` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`categoria_ambiental` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`cidade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`cidade_construcao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`cidade_indicador` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`codigo_parceiro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_contribuicao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_denuncia` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_membro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_mensagem` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_missao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_missao_resultado` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_pedido` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_presenca` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`comunidade_topico` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`conquista` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`construcao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`denuncia_mensagem` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`evidencia_atividade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`historico_credito_verde` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`historico_indicador_cidade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`historico_xp` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`indicador_ambiental` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`jogador` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`jogador_conquista` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`jogador_missao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`mensagem` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`missao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`missao_atividade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`nivel_cidade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`nivel_jogador` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`parceiro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`parceiro_atividade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`pergunta` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`quiz` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`redefinicao_senha` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`redefinicao_senha_parceiro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`regiao_cidade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`registro_atividade` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`resposta_quiz` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`sincronia` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`sugestao_dispensada` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`tentativa_codigo_parceiro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`tentativa_login` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`tentativa_quiz` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`tipo_missao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`tipo_verificacao` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`usuario` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`verificacao_parceiro` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE ON ecocity.`analise_evidencia` TO 'ecocity_role_app';   -- IA (RN91): sem DELETE (base rotulada)
GRANT SELECT, INSERT, UPDATE ON ecocity.`contestacao` TO 'ecocity_role_app';   -- RN94: sem DELETE (histórico)
GRANT SELECT, INSERT, UPDATE ON ecocity.`parceiro_funcionario` TO 'ecocity_role_app';   -- RN95: sem DELETE (atendimentos ligados à pessoa)
GRANT SELECT, INSERT, UPDATE ON ecocity.`parceiro_pedido_atividade` TO 'ecocity_role_app';   -- RN95: sem DELETE (histórico)
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`noticia_ia` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE ON ecocity.`chat_ia_mensagem` TO 'ecocity_role_app';   -- RN97: sem DELETE (preserva cota diária)
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`codigo_promocional` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON ecocity.`codigo_promocional_indicador` TO 'ecocity_role_app';
GRANT SELECT, INSERT ON ecocity.`codigo_promocional_resgate` TO 'ecocity_role_app';   -- RN98: sem UPDATE/DELETE (imutável)
GRANT SELECT, INSERT, DELETE ON ecocity.`tentativa_codigo_promocional` TO 'ecocity_role_app';
GRANT SELECT, INSERT ON ecocity.`ajuste_conta_admin` TO 'ecocity_role_app';   -- RN99: sem UPDATE/DELETE (imutável)
GRANT SELECT, INSERT, DELETE ON ecocity.`tentativa_acao_ip` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE ON ecocity.`configuracao_sistema` TO 'ecocity_role_app';
-- Views (somente leitura) e procedures
GRANT SELECT ON ecocity.`vw_acerto_pergunta` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_atividade_resumo` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_cidade_progresso` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_extrato` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_fila_aprovacao` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_impacto_jogador_categoria` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_impacto_jogador_unidade` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_impacto_parceiro` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_jogador_resumo` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_quiz_primeira_tentativa` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_ranking_comunidade_geral` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_ranking_comunidade_semana` TO 'ecocity_role_app';
GRANT SELECT ON ecocity.`vw_registro_detalhado` TO 'ecocity_role_app';
GRANT EXECUTE ON ecocity.* TO 'ecocity_role_app';

-- 1.3 REVOKE: o site nunca altera nem apaga históricos de XP e créditos (só acrescenta),
--     e as versões da sincronização são escritas só pelos triggers (que rodam com o dono deles).
REVOKE UPDATE, DELETE ON ecocity.historico_xp FROM 'ecocity_role_app';
REVOKE UPDATE, DELETE ON ecocity.historico_credito_verde FROM 'ecocity_role_app';
REVOKE INSERT, UPDATE, DELETE ON ecocity.sincronia FROM 'ecocity_role_app';
-- A tabela auditoria não recebe acesso direto (SELECT/INSERT/UPDATE/DELETE) do site:
-- a gravação continua restrita aos triggers e a leitura na Administração usa apenas
-- EXECUTE nas 3 procedures somente leitura (`SQL SECURITY DEFINER`, RN101 / migração 040).
GRANT EXECUTE ON PROCEDURE ecocity.sp_consultar_auditoria TO 'ecocity_role_app';
GRANT EXECUTE ON PROCEDURE ecocity.sp_resumo_auditoria TO 'ecocity_role_app';
GRANT EXECUTE ON PROCEDURE ecocity.sp_auditoria_tabelas TO 'ecocity_role_app';

-- 1.4 Gerente / analista: só relatórios (as views não expõem e-mail, senha nem nome real)
GRANT SELECT ON ecocity.`vw_acerto_pergunta` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_atividade_resumo` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_cidade_progresso` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_extrato` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_fila_aprovacao` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_impacto_jogador_categoria` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_impacto_jogador_unidade` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_impacto_parceiro` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_jogador_resumo` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_quiz_primeira_tentativa` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_ranking_comunidade_geral` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_ranking_comunidade_semana` TO 'ecocity_role_analista';
GRANT SELECT ON ecocity.`vw_registro_detalhado` TO 'ecocity_role_analista';
GRANT EXECUTE ON PROCEDURE ecocity.sp_relatorio_impacto TO 'ecocity_role_analista';
GRANT EXECUTE ON PROCEDURE ecocity.sp_ranking_comunidades TO 'ecocity_role_analista';

-- 1.5 Backup: dump consistente (--single-transaction), definições (views, triggers, rotinas, events) e binlogs
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON ecocity.* TO 'ecocity_role_backup';
GRANT PROCESS, RELOAD, REPLICATION CLIENT ON *.* TO 'ecocity_role_backup';
GRANT SHOW_ROUTINE ON *.* TO 'ecocity_role_backup';

-- 2. Contas -------------------------------------------------------------------
-- Senha forte obrigatória; DBA e analista travam por 1 dia após 5 senhas erradas.
CREATE USER IF NOT EXISTS 'ecocity_dba'@'localhost' IDENTIFIED BY '${SENHA_DBA}'
    FAILED_LOGIN_ATTEMPTS 5 PASSWORD_LOCK_TIME 1;
CREATE USER IF NOT EXISTS 'ecocity_web'@'${HOST_APP}' IDENTIFIED BY '${SENHA_WEB}';
CREATE USER IF NOT EXISTS 'ecocity_analista'@'${HOST_APP}' IDENTIFIED BY '${SENHA_ANALISTA}'
    FAILED_LOGIN_ATTEMPTS 5 PASSWORD_LOCK_TIME 1;
CREATE USER IF NOT EXISTS 'ecocity_backup'@'localhost' IDENTIFIED BY '${SENHA_BACKUP}';

GRANT 'ecocity_role_dba' TO 'ecocity_dba'@'localhost';
GRANT 'ecocity_role_app' TO 'ecocity_web'@'${HOST_APP}';
GRANT 'ecocity_role_analista' TO 'ecocity_analista'@'${HOST_APP}';
GRANT 'ecocity_role_backup' TO 'ecocity_backup'@'localhost';

-- O perfil vale assim que a conta entra
SET DEFAULT ROLE 'ecocity_role_dba' TO 'ecocity_dba'@'localhost';
SET DEFAULT ROLE 'ecocity_role_app' TO 'ecocity_web'@'${HOST_APP}';
SET DEFAULT ROLE 'ecocity_role_analista' TO 'ecocity_analista'@'${HOST_APP}';
SET DEFAULT ROLE 'ecocity_role_backup' TO 'ecocity_backup'@'localhost';

-- 3. Conferência ----------------------------------------------------------------
SHOW GRANTS FOR 'ecocity_web'@'${HOST_APP}' USING 'ecocity_role_app';
SHOW GRANTS FOR 'ecocity_analista'@'${HOST_APP}' USING 'ecocity_role_analista';

-- 4. Exemplos de administração (comentados) -------------------------------------
-- Trocar a senha de uma conta:          ALTER USER 'ecocity_web'@'%' IDENTIFIED BY 'nova-senha-forte';
-- Bloquear uma conta suspeita:          ALTER USER 'ecocity_analista'@'%' ACCOUNT LOCK;
-- Tirar um perfil de alguém:            REVOKE 'ecocity_role_analista' FROM 'ecocity_analista'@'%';
-- Remover uma conta:                    DROP USER 'ecocity_analista'@'%';
-- Tabela nova no futuro: conceder ao ecocity_role_app (e ao analista, se for view de relatório).
