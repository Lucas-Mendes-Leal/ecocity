-- =============================================================================
-- EcoCity — Migração 018: desenho das estruturas (17/11/2026)
-- `desenho`  = modelo do mapa: um dos 35 desenhos (chave) ou 'personalizado'.
-- `aparencia`= opções do prédio personalizado (JSON: parede, telhado, formato,
--              andares, toldo, icone, corPlaca, detalhes[]). Validadas no PHP
--              (includes/conteudo.php, DESENHOS_ESTRUTURA / APARENCIA_*).
-- Sem desenho (NULL) a estrutura usa o bloco verde genérico.
-- Importar com utf8mb4. Pode ser executada uma vez.
-- =============================================================================

USE ecocity;
SET NAMES utf8mb4;

ALTER TABLE construcao
    ADD COLUMN desenho   VARCHAR(30) NULL AFTER indicador_ambiental_id,
    ADD COLUMN aparencia JSON        NULL AFTER desenho;

UPDATE construcao SET desenho = ELT(construcao_id,
    'lixeiras', 'ponto_onibus', 'casa', 'ciclofaixa', 'horta', 'praca', 'pomar', 'mini_usina', 'lago', 'escola',
    'rua', 'cruzamento', 'calcada', 'estacionamento',
    'bar', 'balada', 'lanchonete', 'mercado', 'padaria', 'posto', 'lava_rapido', 'quadra', 'parquinho',
    'predio', 'hospital', 'bombeiros', 'delegacia', 'prefeitura',
    'arbusto', 'arvore', 'canteiro', 'poste', 'banco', 'cerca', 'fonte')
 WHERE construcao_id BETWEEN 1 AND 35;
