-- =============================================================================
-- EcoCity — Sincronização em tempo real (18/11/2026, RN85)
-- Tabela de versões por escopo (jogador, comunidade, global) e domínio, uma procedure e
-- triggers que aumentam a versão quando os dados mudam. O jogo consulta api/sincronizar.php
-- a cada 4 s e busca só o que mudou. Gerado por script; aplicar uma vez, com utf8mb4.
-- No phpMyAdmin/linha de comando o bloco usa DELIMITER $$.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

CREATE TABLE sincronia (
    escopo        ENUM('jogador', 'comunidade', 'global') NOT NULL,
    escopo_id     INT          NOT NULL,              -- 0 no escopo global
    dominio       VARCHAR(20)  NOT NULL,
    versao        BIGINT UNSIGNED NOT NULL DEFAULT 1,
    atualizado_em DATETIME(3)  NOT NULL,
    PRIMARY KEY (escopo, escopo_id, dominio)
) ENGINE=InnoDB;

DELIMITER $$

CREATE PROCEDURE marcar_sincronia(IN p_escopo VARCHAR(10), IN p_id INT, IN p_dominio VARCHAR(20))
BEGIN
    IF p_id IS NOT NULL THEN
        INSERT INTO sincronia (escopo, escopo_id, dominio, versao, atualizado_em)
        VALUES (p_escopo, p_id, p_dominio, 1, NOW(3))
        ON DUPLICATE KEY UPDATE versao = versao + 1, atualizado_em = NOW(3);
    END IF;
END$$


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

DELIMITER ;
