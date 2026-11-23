-- =============================================================================
-- EcoCity — Migração 044: Impacto real multi-unidade (22/11/2026)
--
-- 1. Atualiza `vw_impacto_jogador_categoria` para que `acoes` continue contando
--    todos os registros aprovados da área, mas `impacto` some apenas as atividades
--    da unidade principal da área (menor `atividade_sustentavel_id` com unidade
--    definida), evitando somar grandezas físicas incompatíveis (ex.: mudas + kg)
--    nas conquistas `impacto_real:{1..5}`.
-- 2. Atualiza `sp_resumo_jogador` para agrupar o impacto real do jogador por
--    `(indicador_ambiental_id, impacto_real_unidade)` sem triplicar as linhas da
--    área quando há múltiplas atividades por indicador.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

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

DROP PROCEDURE IF EXISTS sp_resumo_jogador;
DELIMITER $$
CREATE PROCEDURE sp_resumo_jogador(IN p_jogador INT)
BEGIN
    SELECT * FROM vw_jogador_resumo WHERE jogador_id = p_jogador;
    SELECT ia.nome AS area,
           a.impacto_real_unidade AS unidade,
           COUNT(r.registro_atividade_id) AS acoes,
           COALESCE(SUM(IF(r.registro_atividade_id IS NOT NULL, COALESCE(r.impacto_real, a.impacto_real_quantidade), 0)), 0) AS impacto
      FROM indicador_ambiental ia
      JOIN atividade_indicador_ambiental ai ON ai.indicador_ambiental_id = ia.indicador_ambiental_id
      JOIN atividade_sustentavel a ON a.atividade_sustentavel_id = ai.atividade_sustentavel_id
      LEFT JOIN registro_atividade r
             ON r.atividade_sustentavel_id = a.atividade_sustentavel_id
            AND r.jogador_id = p_jogador
            AND r.status = 'aprovado'
     GROUP BY ia.indicador_ambiental_id, ia.nome, a.impacto_real_unidade
     ORDER BY ia.indicador_ambiental_id, MIN(a.atividade_sustentavel_id);
    SELECT atividade, tipo_verificacao, status, data_registro, impacto_efetivo, unidade
      FROM vw_registro_detalhado WHERE jogador_id = p_jogador ORDER BY data_registro DESC LIMIT 10;
END$$
DELIMITER ;
