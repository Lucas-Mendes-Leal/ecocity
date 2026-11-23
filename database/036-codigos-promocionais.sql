-- =============================================================================
-- EcoCity — Migração 036: Códigos promocionais (RN98, 19/11/2026)
--
-- 1. Tabelas codigo_promocional, codigo_promocional_indicador,
--    codigo_promocional_resgate (imutável) e tentativa_codigo_promocional.
-- 2. Coluna codigo_promocional_id em historico_xp e historico_credito_verde +
--    atualização da view vw_extrato.
-- 3. Triggers de sincronia, auditoria e imutabilidade dos resgates + atualização
--    de sp_apagar_demonstracao.
-- 4. Permissões do perfil ecocity_role_app e cadastro do código inicial BEMVINDO2026.
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Novas tabelas ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `codigo_promocional` (
  `codigo_promocional_id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descricao` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recompensa_xp` int NOT NULL DEFAULT '0',
  `recompensa_credito` int NOT NULL DEFAULT '0',
  `data_inicio` date NOT NULL,
  `data_fim` date NOT NULL,
  `limite_usos` int DEFAULT NULL,
  `somente_novos` tinyint(1) NOT NULL DEFAULT '0',
  `ativa` tinyint(1) NOT NULL DEFAULT '1',
  `criado_por` int DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`codigo_promocional_id`),
  UNIQUE KEY `uq_codigo_promo_codigo` (`codigo`),
  KEY `ix_codigo_promo_periodo` (`ativa`, `data_inicio`, `data_fim`),
  KEY `fk_codigo_promo_admin` (`criado_por`),
  CONSTRAINT `fk_codigo_promo_admin` FOREIGN KEY (`criado_por`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_codigo_promo_codigo` CHECK ((CHAR_LENGTH(TRIM(`codigo`)) >= 4)),
  CONSTRAINT `ck_codigo_promo_recompensa` CHECK (((`recompensa_xp` >= 0) AND (`recompensa_credito` >= 0))),
  CONSTRAINT `ck_codigo_promo_periodo` CHECK ((`data_fim` >= `data_inicio`)),
  CONSTRAINT `ck_codigo_promo_limite` CHECK (((`limite_usos` IS NULL) OR (`limite_usos` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `codigo_promocional_indicador` (
  `codigo_promocional_id` int NOT NULL,
  `indicador_ambiental_id` int NOT NULL,
  `pontos` int NOT NULL,
  PRIMARY KEY (`codigo_promocional_id`, `indicador_ambiental_id`),
  KEY `fk_promo_ind_indicador` (`indicador_ambiental_id`),
  CONSTRAINT `fk_promo_ind_codigo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_promo_ind_indicador` FOREIGN KEY (`indicador_ambiental_id`) REFERENCES `indicador_ambiental` (`indicador_ambiental_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_promo_ind_pontos` CHECK ((`pontos` BETWEEN 1 AND 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `codigo_promocional_resgate` (
  `resgate_id` int NOT NULL AUTO_INCREMENT,
  `codigo_promocional_id` int NOT NULL,
  `jogador_id` int NOT NULL,
  `xp_recebido` int NOT NULL DEFAULT '0',
  `credito_recebido` int NOT NULL DEFAULT '0',
  `indicadores_json` json DEFAULT NULL,
  `resgatado_em` datetime NOT NULL,
  PRIMARY KEY (`resgate_id`),
  UNIQUE KEY `uq_promo_resgate_jogador` (`codigo_promocional_id`, `jogador_id`),
  KEY `ix_promo_resgate_jogador` (`jogador_id`, `resgatado_em`),
  CONSTRAINT `fk_promo_resgate_codigo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_promo_resgate_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_promo_resgate_valores` CHECK (((`xp_recebido` >= 0) AND (`credito_recebido` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tentativa_codigo_promocional` (
  `tentativa_id` int NOT NULL AUTO_INCREMENT,
  `jogador_id` int NOT NULL,
  `data` datetime NOT NULL,
  PRIMARY KEY (`tentativa_id`),
  KEY `ix_tentativa_promo_jogador` (`jogador_id`, `data`),
  CONSTRAINT `fk_tentativa_promo_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Rastreabilidade nos históricos de XP e créditos -------------------------
ALTER TABLE `historico_xp`
  ADD COLUMN `codigo_promocional_id` int DEFAULT NULL AFTER `comunidade_missao_id`,
  ADD KEY `fk_hist_xp_promo` (`codigo_promocional_id`),
  ADD CONSTRAINT `fk_hist_xp_promo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `historico_credito_verde`
  ADD COLUMN `codigo_promocional_id` int DEFAULT NULL AFTER `comunidade_id`,
  ADD KEY `fk_hist_cred_promo` (`codigo_promocional_id`),
  ADD CONSTRAINT `fk_hist_cred_promo` FOREIGN KEY (`codigo_promocional_id`) REFERENCES `codigo_promocional` (`codigo_promocional_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- 3. View de extrato (inclui códigos promocionais com XP+créditos ou só créditos)
CREATE OR REPLACE VIEW `vw_extrato` AS
SELECT hx.jogador_id, hx.data, hx.origem, hx.quantidade AS xp,
       (SELECT hc.quantidade FROM historico_credito_verde hc
         WHERE hc.jogador_id = hx.jogador_id AND hc.origem = hx.origem
           AND hc.registro_atividade_id <=> hx.registro_atividade_id AND hc.tentativa_id <=> hx.tentativa_id
           AND hc.conquista_id <=> hx.conquista_id AND hc.missao_id <=> hx.missao_id
           AND hc.comunidade_missao_id <=> hx.comunidade_missao_id
           AND hc.codigo_promocional_id <=> hx.codigo_promocional_id
           AND (hx.codigo_promocional_id IS NOT NULL OR hc.data = hx.data)
         LIMIT 1) AS credito,
       COALESCE(a.nome, q.titulo, cq.nome, m.nome, cm.nome, cp.nome) AS referencia,
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
UNION ALL
SELECT hc.jogador_id, hc.data, hc.origem, 0 AS xp, hc.quantidade AS credito,
       COALESCE(co.nome, cmd.nome, cp2.nome) AS referencia, hc.historico_credito_verde_id AS ordem
  FROM historico_credito_verde hc
  LEFT JOIN construcao co ON co.construcao_id = hc.construcao_id
  LEFT JOIN comunidade cmd ON cmd.comunidade_id = hc.comunidade_id
  LEFT JOIN codigo_promocional cp2 ON cp2.codigo_promocional_id = hc.codigo_promocional_id
 WHERE hc.construcao_id IS NOT NULL OR hc.comunidade_id IS NOT NULL
    OR (hc.codigo_promocional_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM historico_xp hx2
         WHERE hx2.jogador_id = hc.jogador_id AND hx2.codigo_promocional_id = hc.codigo_promocional_id
    ));

-- 4. Triggers de sincronia, auditoria e imutabilidade + procedure -------------
DROP TRIGGER IF EXISTS `trg_sinc_codigo_promo_ins`;
DROP TRIGGER IF EXISTS `trg_sinc_codigo_promo_upd`;
DROP TRIGGER IF EXISTS `trg_sinc_codigo_promo_del`;
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_ins`;
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_upd`;
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_del`;
DROP TRIGGER IF EXISTS `trg_aud_codigo_promo_resgate_ins`;
DROP TRIGGER IF EXISTS `trg_regra_promo_resgate_upd`;
DROP TRIGGER IF EXISTS `trg_regra_promo_resgate_del`;
DROP PROCEDURE IF EXISTS `sp_apagar_demonstracao`;

DELIMITER $$

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

CREATE PROCEDURE `sp_apagar_demonstracao`(IN p_manter_apresentacao BOOLEAN)
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
    DELETE ci FROM chat_ia_mensagem ci JOIN tmp_demo d ON d.jogador_id = ci.jogador_id;
    DELETE cpr FROM codigo_promocional_resgate cpr JOIN tmp_demo d ON d.jogador_id = cpr.jogador_id;
    DELETE tcp FROM tentativa_codigo_promocional tcp JOIN tmp_demo d ON d.jogador_id = tcp.jogador_id;
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

DELIMITER ;

-- 5. Permissões do site (ecocity_role_app) ----------------------------------
GRANT SELECT, INSERT, UPDATE, DELETE ON `codigo_promocional` TO 'ecocity_role_app';
GRANT SELECT, INSERT, UPDATE, DELETE ON `codigo_promocional_indicador` TO 'ecocity_role_app';
GRANT SELECT, INSERT ON `codigo_promocional_resgate` TO 'ecocity_role_app';
GRANT SELECT, INSERT, DELETE ON `tentativa_codigo_promocional` TO 'ecocity_role_app';

-- 6. Código promocional de boas-vindas de novembro/2026 -----------------------
INSERT IGNORE INTO `codigo_promocional` (
  `codigo`, `nome`, `descricao`, `recompensa_xp`, `recompensa_credito`,
  `data_inicio`, `data_fim`, `limite_usos`, `somente_novos`, `ativa`, `criado_por`, `criado_em`
) VALUES (
  'BEMVINDO2026',
  'Boas-vindas ao EcoCity',
  'Pacote especial de boas-vindas de novembro/2026: cidade nível 2, nível 5 para criar comunidade e 2.000 créditos verdes.',
  520,
  2000,
  '2026-11-01',
  '2026-11-30',
  NULL,
  0,
  1,
  NULL,
  NOW()
);

INSERT IGNORE INTO `codigo_promocional_indicador` (`codigo_promocional_id`, `indicador_ambiental_id`, `pontos`)
SELECT cp.codigo_promocional_id, ia.indicador_ambiental_id, 30
  FROM `codigo_promocional` cp
  CROSS JOIN `indicador_ambiental` ia
 WHERE cp.codigo = 'BEMVINDO2026';
