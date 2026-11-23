-- =============================================================================
-- EcoCity — Migração 029: Minha conta mais completa (RN93, 18/11/2026)
--
-- 1. Avatar personalizado: ícone e cor escolhidos pelo jogador (NULL = avatar padrão de hoje).
--    As listas de ícones e cores permitidos ficam no PHP (includes/perfil.php).
-- 2. Privacidade (padrão: tudo ligado, como hoje):
--    aparece_sugestoes — aparece nas sugestões de amizade dos outros;
--    aceita_pedidos    — recebe pedidos de amizade;
--    permite_visita    — amigos podem visitar a cidade.
-- 3. Sessão e conta:
--    usuario.sessao_versao — "Sair de todos os aparelhos" soma 1 e derruba as sessões antigas;
--    usuario.desativada_em — conta desativada pelo próprio jogador (status = FALSE). Entrar de novo com a
--    senha certa reativa; o bloqueio feito pela administração (desativada_em NULL) continua impedindo.
--
-- Só colunas novas, com valor padrão: nada muda para as contas existentes.
-- Os GRANTs do site (ecocity_web) são por tabela, então já cobrem as colunas.
-- Aplicar como root/DBA com utf8mb4.
-- =============================================================================

SET NAMES utf8mb4;

-- 1 e 2 -----------------------------------------------------------------------
ALTER TABLE `jogador`
  ADD COLUMN `avatar_icone`      varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN `avatar_cor`        varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ADD COLUMN `aparece_sugestoes` tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN `aceita_pedidos`    tinyint(1) NOT NULL DEFAULT '1',
  ADD COLUMN `permite_visita`    tinyint(1) NOT NULL DEFAULT '1';

-- 3 ---------------------------------------------------------------------------
ALTER TABLE `usuario`
  ADD COLUMN `sessao_versao` int unsigned NOT NULL DEFAULT '0',
  ADD COLUMN `desativada_em` datetime DEFAULT NULL;
