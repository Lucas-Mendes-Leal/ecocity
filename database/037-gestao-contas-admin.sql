-- =============================================================================
-- EcoCity — Migração 037: Gestão avançada de contas na administração (RN99, 19/11/2026)
--
-- 1. Tabela imutável ajuste_conta_admin (progressão, moderação de perfil e segurança).
-- 2. Coluna ajuste_admin_id em historico_xp e historico_credito_verde + atualização
--    da view vw_extrato.
-- 3. Triggers de auditoria, sincronização e imutabilidade + atualização de
--    sp_apagar_demonstracao.
-- 4. Permissões do perfil ecocity_role_app (somente SELECT e INSERT).
--
-- Aplicar como root/DBA com --default-character-set=utf8mb4 no banco ecocity.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Tabela de ajustes administrativos ----------------------------------------
CREATE TABLE IF NOT EXISTS `ajuste_conta_admin` (
  `ajuste_admin_id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `jogador_id` int DEFAULT NULL,
  `administrador_id` int NOT NULL,
  `tipo` enum('progressao','perfil','seguranca') COLLATE utf8mb4_unicode_ci NOT NULL,
  `delta_xp` int NOT NULL DEFAULT '0',
  `delta_credito` int NOT NULL DEFAULT '0',
  `indicadores_json` json DEFAULT NULL,
  `detalhes_json` json DEFAULT NULL,
  `motivo` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ajuste_admin_id`),
  KEY `ix_ajuste_admin_usuario` (`usuario_id`, `criado_em`),
  KEY `ix_ajuste_admin_jogador` (`jogador_id`, `criado_em`),
  KEY `fk_ajuste_admin_admin` (`administrador_id`),
  CONSTRAINT `fk_ajuste_admin_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`usuario_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ajuste_admin_jogador` FOREIGN KEY (`jogador_id`) REFERENCES `jogador` (`jogador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ajuste_admin_admin` FOREIGN KEY (`administrador_id`) REFERENCES `administrador` (`administrador_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_ajuste_admin_xp` CHECK ((`delta_xp` >= 0)),
  CONSTRAINT `ck_ajuste_admin_motivo` CHECK ((CHAR_LENGTH(TRIM(`motivo`)) >= 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Rastreabilidade nos históricos de XP e créditos -------------------------
ALTER TABLE `historico_xp`
  ADD COLUMN `ajuste_admin_id` int DEFAULT NULL AFTER `codigo_promocional_id`,
  ADD KEY `fk_hist_xp_ajuste_admin` (`ajuste_admin_id`),
  ADD CONSTRAINT `fk_hist_xp_ajuste_admin` FOREIGN KEY (`ajuste_admin_id`) REFERENCES `ajuste_conta_admin` (`ajuste_admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `historico_credito_verde`
  ADD COLUMN `ajuste_admin_id` int DEFAULT NULL AFTER `codigo_promocional_id`,
  ADD KEY `fk_hist_cred_ajuste_admin` (`ajuste_admin_id`),
  ADD CONSTRAINT `fk_hist_cred_ajuste_admin` FOREIGN KEY (`ajuste_admin_id`) REFERENCES `ajuste_conta_admin` (`ajuste_admin_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- 3. View de extrato (inclui códigos promocionais e ajustes da administração) -
CREATE OR REPLACE VIEW `vw_extrato` AS
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

-- 4. Triggers de auditoria, sincronização e imutabilidade + procedure ---------
DROP TRIGGER IF EXISTS `trg_aud_ajuste_admin_ins`;
DROP TRIGGER IF EXISTS `trg_regra_ajuste_admin_upd`;
DROP TRIGGER IF EXISTS `trg_regra_ajuste_admin_del`;
DROP PROCEDURE IF EXISTS `sp_apagar_demonstracao`;

DELIMITER $$

CREATE TRIGGER `trg_aud_ajuste_admin_ins` AFTER INSERT ON `ajuste_conta_admin` FOR EACH ROW
BEGIN
    CALL registrar_auditoria('ajuste_conta_admin', NEW.ajuste_admin_id, 'INSERT', NULL,
        JSON_OBJECT('usuario_id', NEW.usuario_id, 'jogador_id', NEW.jogador_id, 'administrador_id', NEW.administrador_id,
                    'tipo', NEW.tipo, 'delta_xp', NEW.delta_xp, 'delta_credito', NEW.delta_credito, 'motivo', NEW.motivo));
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
    DELETE aa FROM ajuste_conta_admin aa JOIN tmp_demo d ON d.usuario_id = aa.usuario_id;
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
GRANT SELECT, INSERT ON `ajuste_conta_admin` TO 'ecocity_role_app';
