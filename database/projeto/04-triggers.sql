-- =============================================================================
-- EcoCity — 04. Triggers
--   a) Sincronização em tempo real (RN85): marcam a versão do domínio que mudou (tabela sincronia).
--   b) Auditoria: gravam quem mudou o quê (tabela auditoria) nas tabelas sensíveis.
--   c) Regras de negócio: recusam com SIGNAL SQLSTATE '45000' o que nunca pode acontecer
--      (segunda barreira; o PHP já valida antes).
-- Chamam as procedures marcar_sincronia e registrar_auditoria (05-procedures.sql).
-- =============================================================================
USE ecocity;
DELIMITER $$

-- a) Sincronização ----------------------------------------------------------------
-- jogador
CREATE TRIGGER trg_sinc_jogador_upd AFTER UPDATE ON jogador FOR EACH ROW
BEGIN
    IF NOT (OLD.creditos_verde <=> NEW.creditos_verde AND OLD.xp_atual <=> NEW.xp_atual AND OLD.nivel_id <=> NEW.nivel_id AND OLD.apelido <=> NEW.apelido) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
END$$

-- cidade
CREATE TRIGGER trg_sinc_cidade_upd AFTER UPDATE ON cidade FOR EACH ROW
BEGIN
    IF NOT (OLD.nivel_cidade_id <=> NEW.nivel_cidade_id AND OLD.nome <=> NEW.nome) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
END$$

-- cidade_indicador
CREATE TRIGGER trg_sinc_cidade_indicador_upd AFTER UPDATE ON cidade_indicador FOR EACH ROW
BEGIN
    IF NOT (OLD.valor <=> NEW.valor) THEN
        CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'jogador');
    END IF;
END$$

-- cidade_construcao
CREATE TRIGGER trg_sinc_cidade_construcao_ins AFTER INSERT ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'construcao');
END$$
CREATE TRIGGER trg_sinc_cidade_construcao_upd AFTER UPDATE ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = NEW.cidade_id), 'construcao');
END$$
CREATE TRIGGER trg_sinc_cidade_construcao_del AFTER DELETE ON cidade_construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', (SELECT c.jogador_id FROM cidade c WHERE c.cidade_id = OLD.cidade_id), 'construcao');
END$$

-- registro_atividade
CREATE TRIGGER trg_sinc_registro_atividade_ins AFTER INSERT ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    IF NEW.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$
-- IA (RN91): análise nova avisa o jogador e a administração
CREATE TRIGGER `trg_sinc_analise_evidencia_ins` AFTER INSERT ON `analise_evidencia` FOR EACH ROW
BEGIN
    DECLARE v_jogador INT;
    SELECT jogador_id INTO v_jogador FROM registro_atividade WHERE registro_atividade_id = NEW.registro_atividade_id;
    CALL marcar_sincronia('jogador', v_jogador, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_registro_atividade_upd AFTER UPDATE ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    IF NEW.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$
CREATE TRIGGER trg_sinc_registro_atividade_del AFTER DELETE ON registro_atividade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'registros');
    IF OLD.tipo_verificacao_id = 2 THEN CALL marcar_sincronia('global', 0, 'admin'); END IF;
END$$

-- jogador_missao
CREATE TRIGGER trg_sinc_jogador_missao_ins AFTER INSERT ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'missoes');
END$$
CREATE TRIGGER trg_sinc_jogador_missao_upd AFTER UPDATE ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'missoes');
END$$
CREATE TRIGGER trg_sinc_jogador_missao_del AFTER DELETE ON jogador_missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'missoes');
END$$

-- jogador_conquista
CREATE TRIGGER trg_sinc_jogador_conquista_ins AFTER INSERT ON jogador_conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'conquistas');
END$$
CREATE TRIGGER trg_sinc_jogador_conquista_del AFTER DELETE ON jogador_conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'conquistas');
END$$

-- tentativa_quiz
CREATE TRIGGER trg_sinc_tentativa_quiz_ins AFTER INSERT ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'quizzes');
END$$
CREATE TRIGGER trg_sinc_tentativa_quiz_upd AFTER UPDATE ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'quizzes');
END$$
CREATE TRIGGER trg_sinc_tentativa_quiz_del AFTER DELETE ON tentativa_quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'quizzes');
END$$

-- mensagem
CREATE TRIGGER trg_sinc_mensagem_ins AFTER INSERT ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.remetente_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_mensagem_upd AFTER UPDATE ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.remetente_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_mensagem_del AFTER DELETE ON mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.remetente_id, 'social'); CALL marcar_sincronia('jogador', OLD.destinatario_id, 'social');
END$$

-- amizade
CREATE TRIGGER trg_sinc_amizade_ins AFTER INSERT ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.solicitante_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_amizade_upd AFTER UPDATE ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.solicitante_id, 'social'); CALL marcar_sincronia('jogador', NEW.destinatario_id, 'social');
END$$
CREATE TRIGGER trg_sinc_amizade_del AFTER DELETE ON amizade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.solicitante_id, 'social'); CALL marcar_sincronia('jogador', OLD.destinatario_id, 'social');
END$$

-- bloqueio
CREATE TRIGGER trg_sinc_bloqueio_ins AFTER INSERT ON bloqueio FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.bloqueador_id, 'social'); CALL marcar_sincronia('jogador', NEW.bloqueado_id, 'social');
END$$
CREATE TRIGGER trg_sinc_bloqueio_del AFTER DELETE ON bloqueio FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.bloqueador_id, 'social'); CALL marcar_sincronia('jogador', OLD.bloqueado_id, 'social');
END$$

-- comunidade
CREATE TRIGGER trg_sinc_comunidade_upd AFTER UPDATE ON comunidade FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$

-- comunidade_mensagem
CREATE TRIGGER trg_sinc_comunidade_mensagem_ins AFTER INSERT ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_mensagem_upd AFTER UPDATE ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_mensagem_del AFTER DELETE ON comunidade_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_topico
CREATE TRIGGER trg_sinc_comunidade_topico_ins AFTER INSERT ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_topico_upd AFTER UPDATE ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_topico_del AFTER DELETE ON comunidade_topico FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_presenca
CREATE TRIGGER trg_sinc_comunidade_presenca_ins AFTER INSERT ON comunidade_presenca FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', (SELECT t.comunidade_id FROM comunidade_topico t WHERE t.comunidade_topico_id = NEW.comunidade_topico_id), 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_presenca_del AFTER DELETE ON comunidade_presenca FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', (SELECT t.comunidade_id FROM comunidade_topico t WHERE t.comunidade_topico_id = OLD.comunidade_topico_id), 'comunidade');
END$$

-- comunidade_contribuicao
CREATE TRIGGER trg_sinc_comunidade_contribuicao_ins AFTER INSERT ON comunidade_contribuicao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_contribuicao_del AFTER DELETE ON comunidade_contribuicao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_missao_resultado
CREATE TRIGGER trg_sinc_comunidade_missao_resultado_ins AFTER INSERT ON comunidade_missao_resultado FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_resultado_del AFTER DELETE ON comunidade_missao_resultado FOR EACH ROW
BEGIN
    CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_missao
CREATE TRIGGER trg_sinc_comunidade_missao_ins AFTER INSERT ON comunidade_missao FOR EACH ROW
BEGIN
    IF NEW.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); END IF;
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_upd AFTER UPDATE ON comunidade_missao FOR EACH ROW
BEGIN
    IF NEW.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); END IF;
END$$
CREATE TRIGGER trg_sinc_comunidade_missao_del AFTER DELETE ON comunidade_missao FOR EACH ROW
BEGIN
    IF OLD.comunidade_id IS NULL THEN CALL marcar_sincronia('global', 0, 'conteudo'); ELSE CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade'); END IF;
END$$

-- comunidade_pedido
CREATE TRIGGER trg_sinc_comunidade_pedido_ins AFTER INSERT ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_pedido_upd AFTER UPDATE ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_pedido_del AFTER DELETE ON comunidade_pedido FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_membro
CREATE TRIGGER trg_sinc_comunidade_membro_ins AFTER INSERT ON comunidade_membro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade');
END$$
CREATE TRIGGER trg_sinc_comunidade_membro_del AFTER DELETE ON comunidade_membro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', OLD.jogador_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade');
END$$

-- comunidade_membro
CREATE TRIGGER trg_sinc_comunidade_membro_upd AFTER UPDATE ON comunidade_membro FOR EACH ROW
BEGIN
    IF NOT (OLD.papel <=> NEW.papel AND OLD.comunidade_id <=> NEW.comunidade_id) THEN CALL marcar_sincronia('comunidade', NEW.comunidade_id, 'comunidade'); CALL marcar_sincronia('comunidade', OLD.comunidade_id, 'comunidade'); END IF; CALL marcar_sincronia('jogador', NEW.jogador_id, 'comunidade');
END$$

-- denuncia_mensagem
CREATE TRIGGER trg_sinc_denuncia_mensagem_ins AFTER INSERT ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_denuncia_mensagem_upd AFTER UPDATE ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_denuncia_mensagem_del AFTER DELETE ON denuncia_mensagem FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- comunidade_denuncia
CREATE TRIGGER trg_sinc_comunidade_denuncia_ins AFTER INSERT ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_comunidade_denuncia_upd AFTER UPDATE ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_comunidade_denuncia_del AFTER DELETE ON comunidade_denuncia FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- parceiro
CREATE TRIGGER trg_sinc_parceiro_ins AFTER INSERT ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_parceiro_upd AFTER UPDATE ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_sinc_parceiro_del AFTER DELETE ON parceiro FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$

-- atividade_sustentavel
CREATE TRIGGER trg_sinc_atividade_sustentavel_ins AFTER INSERT ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_atividade_sustentavel_upd AFTER UPDATE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_atividade_sustentavel_del AFTER DELETE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- quiz
CREATE TRIGGER trg_sinc_quiz_ins AFTER INSERT ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_quiz_upd AFTER UPDATE ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_quiz_del AFTER DELETE ON quiz FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- missao
CREATE TRIGGER trg_sinc_missao_ins AFTER INSERT ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_missao_upd AFTER UPDATE ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_missao_del AFTER DELETE ON missao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- conquista
CREATE TRIGGER trg_sinc_conquista_ins AFTER INSERT ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_conquista_upd AFTER UPDATE ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_conquista_del AFTER DELETE ON conquista FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$

-- construcao
CREATE TRIGGER trg_sinc_construcao_ins AFTER INSERT ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_construcao_upd AFTER UPDATE ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$
CREATE TRIGGER trg_sinc_construcao_del AFTER DELETE ON construcao FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'conteudo');
END$$


-- b) e c) Auditoria e regras de negócio -------------------------------------------
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
    CALL registrar_auditoria('administrador', NEW.administrador_id, 'INSERT', NULL, JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_aud_administrador_upd AFTER UPDATE ON administrador FOR EACH ROW
BEGIN
    IF NOT (OLD.usuario_id <=> NEW.usuario_id) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.perfil <=> NEW.perfil) THEN
        CALL registrar_auditoria('administrador', NEW.administrador_id, 'UPDATE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), JSON_OBJECT('usuario_id', NEW.usuario_id, 'ativo', NEW.ativo, 'perfil', NEW.perfil));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
CREATE TRIGGER trg_aud_administrador_del AFTER DELETE ON administrador FOR EACH ROW
BEGIN
    CALL registrar_auditoria('administrador', OLD.administrador_id, 'DELETE', JSON_OBJECT('usuario_id', OLD.usuario_id, 'ativo', OLD.ativo, 'perfil', OLD.perfil), NULL);
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER trg_regra_administrador_master_upd BEFORE UPDATE ON administrador FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0
       AND OLD.ativo = TRUE
       AND OLD.perfil = 'master'
       AND (NEW.ativo = FALSE OR NEW.perfil <> 'master')
       AND NOT EXISTS (
           SELECT 1
             FROM administrador
            WHERE ativo = TRUE AND perfil = 'master' AND administrador_id <> OLD.administrador_id
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'É obrigatório manter pelo menos um Admin Master ativo no sistema.';
    END IF;
END$$
CREATE TRIGGER trg_regra_administrador_master_del BEFORE DELETE ON administrador FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0
       AND OLD.ativo = TRUE
       AND OLD.perfil = 'master'
       AND NOT EXISTS (
           SELECT 1
             FROM administrador
            WHERE ativo = TRUE AND perfil = 'master' AND administrador_id <> OLD.administrador_id
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'É obrigatório manter pelo menos um Admin Master ativo no sistema.';
    END IF;
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
    CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'INSERT', NULL, JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'aceita_foto', NEW.aceita_foto, 'aceita_parceiro', NEW.aceita_parceiro, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_upd AFTER UPDATE ON atividade_sustentavel FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp) OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.ativa <=> NEW.ativa) OR NOT (OLD.aceita_foto <=> NEW.aceita_foto) OR NOT (OLD.aceita_parceiro <=> NEW.aceita_parceiro) OR NOT (OLD.impacto_real_quantidade <=> NEW.impacto_real_quantidade) THEN
        CALL registrar_auditoria('atividade_sustentavel', NEW.atividade_sustentavel_id, 'UPDATE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'aceita_foto', OLD.aceita_foto, 'aceita_parceiro', OLD.aceita_parceiro, 'impacto_real_quantidade', OLD.impacto_real_quantidade), JSON_OBJECT('nome', NEW.nome, 'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito, 'ativa', NEW.ativa, 'aceita_foto', NEW.aceita_foto, 'aceita_parceiro', NEW.aceita_parceiro, 'impacto_real_quantidade', NEW.impacto_real_quantidade));
    END IF;
END$$
CREATE TRIGGER trg_aud_atividade_sustentavel_del AFTER DELETE ON atividade_sustentavel FOR EACH ROW
BEGIN
    CALL registrar_auditoria('atividade_sustentavel', OLD.atividade_sustentavel_id, 'DELETE', JSON_OBJECT('nome', OLD.nome, 'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito, 'ativa', OLD.ativa, 'aceita_foto', OLD.aceita_foto, 'aceita_parceiro', OLD.aceita_parceiro, 'impacto_real_quantidade', OLD.impacto_real_quantidade), NULL);
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
-- RN94 (migração 030): rejeitado → aprovado só pela contestação aceita
CREATE TRIGGER `trg_regra_registro_decidido` BEFORE UPDATE ON `registro_atividade` FOR EACH ROW
BEGIN
    IF OLD.status <> 'pendente' AND NOT (OLD.status <=> NEW.status) THEN
        IF NOT (OLD.status = 'rejeitado' AND NEW.status = 'aprovado' AND IFNULL(@ecocity_contestacao, 0) = 1
                AND EXISTS (SELECT 1 FROM contestacao c
                             WHERE c.registro_atividade_id = OLD.registro_atividade_id AND c.status = 'aberta')) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro já decidido não pode mudar de situação (recompensa única).';
        END IF;
    END IF;
END$$
-- RN92 (migração 028): atividade sem como ter foto não aceita registro com foto
CREATE TRIGGER `trg_regra_registro_foto` BEFORE INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    IF NEW.tipo_verificacao_id = 2
       AND (SELECT aceita_foto FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não tem como ser comprovada por foto: registre sem foto.';
    END IF;
END$$
-- RN69 (migração 043): atividade doméstica/não elegível não pode ser vinculada nem verificada por parceiros
CREATE TRIGGER `trg_regra_parceiro_atividade` BEFORE INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    IF (SELECT aceita_parceiro FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
CREATE TRIGGER `trg_regra_codigo_parceiro` BEFORE INSERT ON `codigo_parceiro` FOR EACH ROW
BEGIN
    IF (SELECT aceita_parceiro FROM atividade_sustentavel WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
-- RN69 / RN95 (migração 045): pedido de atividade nova por parceiro só aceita atividade elegível com impacto real
CREATE TRIGGER `trg_regra_pedido_atividade_parceiro` BEFORE INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF COALESCE((SELECT aceita_parceiro = 1 AND impacto_real_quantidade > 0
                   FROM atividade_sustentavel
                  WHERE atividade_sustentavel_id = NEW.atividade_sustentavel_id), 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Esta atividade não pode ser verificada por parceiros.';
    END IF;
END$$
-- RN49 / RN98 (migração 045): código promocional já resgatado não pode mudar código/recompensas/início, reduzir limite abaixo do usado nem ser excluído
CREATE TRIGGER `trg_regra_codigo_promo_usado_upd` BEFORE UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    DECLARE v_resgates INT DEFAULT 0;
    SELECT COUNT(*) INTO v_resgates
      FROM codigo_promocional_resgate
     WHERE codigo_promocional_id = OLD.codigo_promocional_id;
    IF v_resgates > 0 THEN
        IF NOT (OLD.codigo <=> NEW.codigo)
           OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
           OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já foi resgatado por jogadores: o código e as recompensas não podem mais mudar.';
        END IF;
        IF NOT (OLD.data_inicio <=> NEW.data_inicio) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código já possui resgates: a data de início não pode mais mudar (ajuste a data de término).';
        END IF;
        IF NEW.limite_usos IS NOT NULL AND NEW.limite_usos < v_resgates THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O limite de resgates não pode ser menor que o total já resgatado.';
        END IF;
    END IF;
END$$
CREATE TRIGGER `trg_regra_codigo_promo_usado_del` BEFORE DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM codigo_promocional_resgate WHERE codigo_promocional_id = OLD.codigo_promocional_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Este código promocional já possui resgates e não pode ser excluído (desative-o).';
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
CREATE TRIGGER trg_regra_comunidade_mensagem_texto BEFORE UPDATE ON comunidade_mensagem FOR EACH ROW
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

-- RN94 (migração 030): contestação — sincronização e auditoria
CREATE TRIGGER `trg_sinc_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'registros');
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
CREATE TRIGGER `trg_aud_contestacao_ins` AFTER INSERT ON `contestacao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'INSERT', NULL,
        JSON_OBJECT('registro_atividade_id', NEW.registro_atividade_id, 'jogador_id', NEW.jogador_id, 'status', NEW.status));
END$$
CREATE TRIGGER `trg_aud_contestacao_upd` AFTER UPDATE ON `contestacao` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resposta <=> NEW.resposta) OR NOT (OLD.decidida_por <=> NEW.decidida_por) THEN
        CALL registrar_auditoria('contestacao', NEW.contestacao_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resposta', OLD.resposta, 'decidida_por', OLD.decidida_por),
            JSON_OBJECT('status', NEW.status, 'resposta', NEW.resposta, 'decidida_por', NEW.decidida_por));
    END IF;
END$$
-- RN95 (migração 031): funcionários e pedidos de atividade do parceiro — auditoria e sincronização
CREATE TRIGGER `trg_aud_parceiro_funcionario_ins` AFTER INSERT ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'nome', NEW.nome, 'usuario', NEW.usuario, 'ativo', NEW.ativo));
END$$
CREATE TRIGGER `trg_aud_parceiro_funcionario_upd` AFTER UPDATE ON `parceiro_funcionario` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.ativo <=> NEW.ativo) OR NOT (OLD.senha <=> NEW.senha) THEN
        CALL registrar_auditoria('parceiro_funcionario', NEW.funcionario_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'ativo', OLD.ativo),
            JSON_OBJECT('nome', NEW.nome, 'ativo', NEW.ativo, 'senha_trocada', NOT (OLD.senha <=> NEW.senha)));
    END IF;
END$$
CREATE TRIGGER `trg_aud_parceiro_pedido_ins` AFTER INSERT ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'status', NEW.status));
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_parceiro_pedido_upd` AFTER UPDATE ON `parceiro_pedido_atividade` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) THEN
        CALL registrar_auditoria('parceiro_pedido_atividade', NEW.pedido_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status), JSON_OBJECT('status', NEW.status, 'motivo', NEW.motivo, 'decidido_por', NEW.decidido_por));
        CALL marcar_sincronia('global', 0, 'admin');
    END IF;
END$$
-- RN98 (migração 036): códigos promocionais — sincronização, auditoria e imutabilidade dos resgates
CREATE TRIGGER `trg_sinc_codigo_promo_ins` AFTER INSERT ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_codigo_promo_upd` AFTER UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_sinc_codigo_promo_del` AFTER DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ins` AFTER INSERT ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional', NEW.codigo_promocional_id, 'INSERT', NULL,
        JSON_OBJECT('codigo', NEW.codigo, 'nome', NEW.nome, 'xp', NEW.recompensa_xp, 'credito', NEW.recompensa_credito,
                    'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'limite_usos', NEW.limite_usos,
                    'somente_novos', NEW.somente_novos, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER `trg_aud_codigo_promo_upd` AFTER UPDATE ON `codigo_promocional` FOR EACH ROW
BEGIN
    IF NOT (OLD.codigo <=> NEW.codigo) OR NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
       OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.data_inicio <=> NEW.data_inicio)
       OR NOT (OLD.data_fim <=> NEW.data_fim) OR NOT (OLD.limite_usos <=> NEW.limite_usos)
       OR NOT (OLD.somente_novos <=> NEW.somente_novos) OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('codigo_promocional', NEW.codigo_promocional_id, 'UPDATE',
            JSON_OBJECT('codigo', OLD.codigo, 'nome', OLD.nome, 'xp', OLD.recompensa_xp, 'credito', OLD.recompensa_credito,
                        'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'limite_usos', OLD.limite_usos,
                        'somente_novos', OLD.somente_novos, 'ativa', OLD.ativa),
            JSON_OBJECT('codigo', NEW.codigo, 'nome', NEW.nome, 'xp', NEW.recompensa_xp, 'credito', NEW.recompensa_credito,
                        'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim, 'limite_usos', NEW.limite_usos,
                        'somente_novos', NEW.somente_novos, 'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER `trg_aud_codigo_promo_del` AFTER DELETE ON `codigo_promocional` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional', OLD.codigo_promocional_id, 'DELETE',
        JSON_OBJECT('codigo', OLD.codigo, 'nome', OLD.nome, 'xp', OLD.recompensa_xp, 'credito', OLD.recompensa_credito,
                    'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim, 'limite_usos', OLD.limite_usos,
                    'somente_novos', OLD.somente_novos, 'ativa', OLD.ativa), NULL);
END$$
CREATE TRIGGER `trg_aud_codigo_promo_resgate_ins` AFTER INSERT ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_resgate', NEW.resgate_id, 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id, 'jogador_id', NEW.jogador_id,
                    'xp_recebido', NEW.xp_recebido, 'credito_recebido', NEW.credito_recebido));
    CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_regra_promo_resgate_upd` BEFORE UPDATE ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de resgate promocional não pode ser alterado.';
END$$
CREATE TRIGGER `trg_regra_promo_resgate_del` BEFORE DELETE ON `codigo_promocional_resgate` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de resgate promocional não pode ser apagado.';
    END IF;
END$$
-- RN99 (migração 037): gestão avançada de contas na administração — auditoria, sincronia e imutabilidade
CREATE TRIGGER `trg_aud_ajuste_admin_ins` AFTER INSERT ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('ajuste_conta_admin', NEW.ajuste_admin_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'jogador_id', NEW.jogador_id, 'administrador_id', NEW.administrador_id,
                    'tipo', NEW.tipo, 'delta_xp', NEW.delta_xp, 'delta_credito', NEW.delta_credito, 'motivo', NEW.motivo));
    IF NEW.jogador_id IS NOT NULL THEN
        CALL marcar_sincronia('jogador', NEW.jogador_id, 'jogador');
    END IF;
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_regra_ajuste_admin_upd` BEFORE UPDATE ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O registro de ajuste administrativo não pode ser alterado.';
END$$
CREATE TRIGGER `trg_regra_ajuste_admin_del` BEFORE DELETE ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    IF IFNULL(@ecocity_manutencao, 0) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'O histórico de ajustes administrativos não pode ser apagado.';
    END IF;
END$$

-- RN101 (migração 040): auditoria detalhada estendida (+27 triggers em 14 tabelas)
CREATE TRIGGER `trg_aud_jogador_ins` AFTER INSERT ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', NEW.jogador_id, 'INSERT', NULL,
        JSON_OBJECT('apelido', NEW.apelido, 'numero_conta', NEW.numero_conta, 'usuario_id', NEW.usuario_id,
                    'nivel_id', NEW.nivel_id, 'creditos_verde', NEW.creditos_verde, 'xp_atual', NEW.xp_atual));
END$$
CREATE TRIGGER `trg_aud_jogador_del` AFTER DELETE ON `jogador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('jogador', OLD.jogador_id, 'DELETE',
        JSON_OBJECT('apelido', OLD.apelido, 'numero_conta', OLD.numero_conta, 'usuario_id', OLD.usuario_id,
                    'nivel_id', OLD.nivel_id, 'creditos_verde', OLD.creditos_verde, 'xp_atual', OLD.xp_atual), NULL);
END$$
CREATE TRIGGER `trg_aud_cidade_upd` AFTER UPDATE ON `cidade` FOR EACH ROW
BEGIN
    IF NOT (OLD.nome <=> NEW.nome) OR NOT (OLD.nivel_cidade_id <=> NEW.nivel_cidade_id) THEN
        CALL registrar_auditoria('cidade', NEW.cidade_id, 'UPDATE',
            JSON_OBJECT('nome', OLD.nome, 'nivel_cidade_id', OLD.nivel_cidade_id, 'jogador_id', OLD.jogador_id),
            JSON_OBJECT('nome', NEW.nome, 'nivel_cidade_id', NEW.nivel_cidade_id, 'jogador_id', NEW.jogador_id));
    END IF;
END$$
CREATE TRIGGER `trg_aud_cidade_construcao_ins` AFTER INSERT ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', NEW.cidade_construcao_id, 'INSERT', NULL,
        JSON_OBJECT('cidade_id', NEW.cidade_id, 'construcao_id', NEW.construcao_id,
                    'lote', NEW.lote, 'x', NEW.x, 'y', NEW.y, 'rotacao', NEW.rotacao));
END$$
CREATE TRIGGER `trg_aud_cidade_construcao_del` AFTER DELETE ON `cidade_construcao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('cidade_construcao', OLD.cidade_construcao_id, 'DELETE',
        JSON_OBJECT('cidade_id', OLD.cidade_id, 'construcao_id', OLD.construcao_id,
                    'lote', OLD.lote, 'x', OLD.x, 'y', OLD.y, 'rotacao', OLD.rotacao), NULL);
END$$
CREATE TRIGGER `trg_aud_registro_atividade_ins` AFTER INSERT ON `registro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('registro_atividade', NEW.registro_atividade_id, 'INSERT', NULL,
        JSON_OBJECT('jogador_id', NEW.jogador_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id,
                    'tipo_verificacao_id', NEW.tipo_verificacao_id, 'status', NEW.status,
                    'impacto_real', NEW.impacto_real));
END$$
CREATE TRIGGER `trg_aud_comunidade_ins` AFTER INSERT ON `comunidade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade', NEW.comunidade_id, 'INSERT', NULL,
        JSON_OBJECT('nome', NEW.nome, 'entrada', NEW.entrada, 'icone', NEW.icone, 'ativa', NEW.ativa));
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_ins` AFTER INSERT ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'INSERT', NULL,
        JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                    'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                    'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                    'pontos', NEW.pontos, 'ativa', NEW.ativa, 'origem', NEW.origem));
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_upd` AFTER UPDATE ON `comunidade_missao` FOR EACH ROW
BEGIN
    IF NOT (OLD.comunidade_id <=> NEW.comunidade_id) OR NOT (OLD.nome <=> NEW.nome)
       OR NOT (OLD.atividade_sustentavel_id <=> NEW.atividade_sustentavel_id)
       OR NOT (OLD.meta <=> NEW.meta) OR NOT (OLD.recompensa_xp <=> NEW.recompensa_xp)
       OR NOT (OLD.recompensa_credito <=> NEW.recompensa_credito) OR NOT (OLD.pontos <=> NEW.pontos)
       OR NOT (OLD.data_inicio <=> NEW.data_inicio) OR NOT (OLD.data_fim <=> NEW.data_fim)
       OR NOT (OLD.ativa <=> NEW.ativa) THEN
        CALL registrar_auditoria('comunidade_missao', NEW.comunidade_missao_id, 'UPDATE',
            JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                        'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                        'recompensa_xp', OLD.recompensa_xp, 'recompensa_credito', OLD.recompensa_credito,
                        'pontos', OLD.pontos, 'data_inicio', OLD.data_inicio, 'data_fim', OLD.data_fim,
                        'ativa', OLD.ativa),
            JSON_OBJECT('comunidade_id', NEW.comunidade_id, 'nome', NEW.nome,
                        'atividade_sustentavel_id', NEW.atividade_sustentavel_id, 'meta', NEW.meta,
                        'recompensa_xp', NEW.recompensa_xp, 'recompensa_credito', NEW.recompensa_credito,
                        'pontos', NEW.pontos, 'data_inicio', NEW.data_inicio, 'data_fim', NEW.data_fim,
                        'ativa', NEW.ativa));
    END IF;
END$$
CREATE TRIGGER `trg_aud_comunidade_missao_del` AFTER DELETE ON `comunidade_missao` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('comunidade_missao', OLD.comunidade_missao_id, 'DELETE',
        JSON_OBJECT('comunidade_id', OLD.comunidade_id, 'nome', OLD.nome,
                    'atividade_sustentavel_id', OLD.atividade_sustentavel_id, 'meta', OLD.meta,
                    'ativa', OLD.ativa, 'origem', OLD.origem), NULL);
END$$
CREATE TRIGGER `trg_aud_denuncia_mensagem_upd` AFTER UPDATE ON `denuncia_mensagem` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('denuncia_mensagem', NEW.denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$
CREATE TRIGGER `trg_aud_comunidade_denuncia_upd` AFTER UPDATE ON `comunidade_denuncia` FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status) OR NOT (OLD.resolvida_por <=> NEW.resolvida_por)
       OR NOT (OLD.ia_acao <=> NEW.ia_acao) OR NOT (OLD.decidida_por_ia <=> NEW.decidida_por_ia) THEN
        CALL registrar_auditoria('comunidade_denuncia', NEW.comunidade_denuncia_id, 'UPDATE',
            JSON_OBJECT('status', OLD.status, 'resolvida_por', OLD.resolvida_por,
                        'ia_acao', OLD.ia_acao, 'decidida_por_ia', OLD.decidida_por_ia),
            JSON_OBJECT('status', NEW.status, 'resolvida_por', NEW.resolvida_por,
                        'ia_acao', NEW.ia_acao, 'decidida_por_ia', NEW.decidida_por_ia));
    END IF;
END$$
CREATE TRIGGER `trg_aud_pergunta_ins` AFTER INSERT ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'INSERT', NULL,
        JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
END$$
CREATE TRIGGER `trg_aud_pergunta_upd` AFTER UPDATE ON `pergunta` FOR EACH ROW
BEGIN
    IF NOT (OLD.quiz_id <=> NEW.quiz_id) OR NOT (OLD.enunciado <=> NEW.enunciado)
       OR NOT (OLD.fonte_titulo <=> NEW.fonte_titulo) OR NOT (OLD.fonte_url <=> NEW.fonte_url) THEN
        CALL registrar_auditoria('pergunta', NEW.pergunta_id, 'UPDATE',
            JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado, 'fonte_titulo', OLD.fonte_titulo),
            JSON_OBJECT('quiz_id', NEW.quiz_id, 'enunciado', NEW.enunciado, 'fonte_titulo', NEW.fonte_titulo));
    END IF;
END$$
CREATE TRIGGER `trg_aud_pergunta_del` AFTER DELETE ON `pergunta` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('pergunta', OLD.pergunta_id, 'DELETE',
        JSON_OBJECT('quiz_id', OLD.quiz_id, 'enunciado', OLD.enunciado), NULL);
END$$
CREATE TRIGGER `trg_aud_alternativa_ins` AFTER INSERT ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'INSERT', NULL,
        JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
END$$
CREATE TRIGGER `trg_aud_alternativa_upd` AFTER UPDATE ON `alternativa` FOR EACH ROW
BEGIN
    IF NOT (OLD.texto <=> NEW.texto) OR NOT (OLD.correta <=> NEW.correta)
       OR NOT (OLD.explicacao <=> NEW.explicacao) THEN
        CALL registrar_auditoria('alternativa', NEW.alternativa_id, 'UPDATE',
            JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta),
            JSON_OBJECT('pergunta_id', NEW.pergunta_id, 'texto', NEW.texto, 'correta', NEW.correta));
    END IF;
END$$
CREATE TRIGGER `trg_aud_alternativa_del` AFTER DELETE ON `alternativa` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('alternativa', OLD.alternativa_id, 'DELETE',
        JSON_OBJECT('pergunta_id', OLD.pergunta_id, 'texto', OLD.texto, 'correta', OLD.correta), NULL);
END$$
CREATE TRIGGER `trg_aud_parceiro_atividade_ins` AFTER INSERT ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(NEW.parceiro_id, ':', NEW.atividade_sustentavel_id), 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'atividade_sustentavel_id', NEW.atividade_sustentavel_id));
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_parceiro_atividade_del` AFTER DELETE ON `parceiro_atividade` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('parceiro_atividade', CONCAT(OLD.parceiro_id, ':', OLD.atividade_sustentavel_id), 'DELETE',
        JSON_OBJECT('parceiro_id', OLD.parceiro_id, 'atividade_sustentavel_id', OLD.atividade_sustentavel_id), NULL);
    CALL marcar_sincronia('global', 0, 'conteudo');
    CALL marcar_sincronia('global', 0, 'admin');
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_ins` AFTER INSERT ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'INSERT', NULL,
        JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                    'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_upd` AFTER UPDATE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    IF NOT (OLD.pontos <=> NEW.pontos) THEN
        CALL registrar_auditoria('codigo_promocional_indicador',
            CONCAT(NEW.codigo_promocional_id, ':', NEW.indicador_ambiental_id), 'UPDATE',
            JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                        'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos),
            JSON_OBJECT('codigo_promocional_id', NEW.codigo_promocional_id,
                        'indicador_ambiental_id', NEW.indicador_ambiental_id, 'pontos', NEW.pontos));
    END IF;
END$$
CREATE TRIGGER `trg_aud_codigo_promo_ind_del` AFTER DELETE ON `codigo_promocional_indicador` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('codigo_promocional_indicador',
        CONCAT(OLD.codigo_promocional_id, ':', OLD.indicador_ambiental_id), 'DELETE',
        JSON_OBJECT('codigo_promocional_id', OLD.codigo_promocional_id,
                    'indicador_ambiental_id', OLD.indicador_ambiental_id, 'pontos', OLD.pontos), NULL);
END$$
CREATE TRIGGER `trg_aud_redefinicao_senha_ins` AFTER INSERT ON `redefinicao_senha` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'data_expiracao', NEW.data_expiracao));
END$$
CREATE TRIGGER `trg_aud_redefinicao_senha_upd` AFTER UPDATE ON `redefinicao_senha` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha', NEW.redefinicao_senha_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('usuario_id', NEW.usuario_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$
CREATE TRIGGER `trg_aud_redefinicao_parc_ins` AFTER INSERT ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'INSERT', NULL,
        JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'data_expiracao', NEW.data_expiracao));
END$$
CREATE TRIGGER `trg_aud_redefinicao_parc_upd` AFTER UPDATE ON `redefinicao_senha_parceiro` FOR EACH ROW
BEGIN
    IF NOT (OLD.data_uso <=> NEW.data_uso) THEN
        CALL registrar_auditoria('redefinicao_senha_parceiro', NEW.redefinicao_senha_parceiro_id, 'UPDATE',
            JSON_OBJECT('usado', OLD.data_uso IS NOT NULL),
            JSON_OBJECT('parceiro_id', NEW.parceiro_id, 'usado', NEW.data_uso IS NOT NULL, 'data_uso', NEW.data_uso));
    END IF;
END$$
DELIMITER ;
