# EcoCity — Banco de Dados: Implementação, Segurança e Administração

> Documento da entrega acadêmica do banco (18/11/2026; estendido nas migrações 034–045 até 22/11/2026 para **69 tabelas**, **10 índices compostos**, **3 functions determinísticas**, **13 views**, **165 triggers** e **11 procedures**, mantendo os scripts sincronizados em `database/projeto/` e `database/ecocity.sql`). Scripts em `database/projeto/`, executados nesta ordem:
> `01-criar-banco.sql` → `02-indices.sql` → `03-views.sql` → `04-triggers.sql` → `05-procedures.sql` → `06-dados-teste.sql` → `08-seguranca.sql`.
> `07-consultas.sql` (consultas de análise) e `09-monitoramento.sql` (desempenho) rodam a qualquer momento.
> Conferido: os scripts constroem do zero, num MySQL 8.0.40 vazio, um banco **idêntico** ao do site (colunas, índices, CHECKs, chaves estrangeiras, triggers, functions, views, procedures e event), com os dados de teste carregados e `sp_verificar_integridade()` sem divergências (10 verificações = 0 problemas).

---

## 1. Implementação do banco

| Tópico | Onde | O que tem |
|---|---|---|
| CREATE DATABASE / CREATE TABLE | `01-criar-banco.sql` | 57 tabelas originais (69 tabelas após as migrações 030–040) em 11 módulos (conta e segurança; jogador e progressão; cidade e indicadores; atividades e registros; quizzes; conquistas; construção; amigos; comunidades; parceiros; sincronização e auditoria). InnoDB, utf8mb4. |
| Chaves e constraints | `01-criar-banco.sql` | PK em todas as tabelas (simples ou composta); chaves estrangeiras com `ON DELETE RESTRICT ON UPDATE RESTRICT` (nada é apagado em cascata, o que protege os históricos); NOT NULL onde o valor é obrigatório; UNIQUE (e-mail, código público, nome de comunidade ativa, par de amizade, uma cidade por jogador, resposta única por tentativa/pergunta…); CHECKs (saldo ≥ 0, XP ≥ 0, indicador 0–100, recompensas ≥ 0, período de missão válido, registro aprovado com data e rejeitado com motivo, renda só no comércio, e-mail com formato mínimo, situações permitidas, perfis administrativos RN102…). |
| Índices | `02-indices.sql` | 10 índices compostos para as consultas mais usadas (extrato, limite diário, fila de aprovação, 1ª tentativa do quiz, variação dos indicadores, conquistas, evolução/impacto por jogador e status `ix_registro_jogador_status_data`, histórico recente de quizzes `ix_tentativa_jogador_data` e histórico/relatórios de códigos utilizados por parceiro `ix_codigo_parceiro_usado`), cada um com a consulta que atende; além dos UNIQUE e dos índices de apoio do `01`. |
| Functions e Views | `03-views.sql` | 3 functions determinísticas de regra de negócio (`fn_fator_verificacao`, `fn_estagio_indicador`, `fn_situacao_codigo_parceiro`) e 13 views de relatório: progresso da cidade, resumo do jogador, registro detalhado com impacto efetivo e `pontos_indicador`, impacto por jogador e área, impacto multi-unidade por jogador/área/unidade (`vw_impacto_jogador_unidade`), resumo por atividade, fila de aprovação, extrato, 1ª tentativa do quiz, acerto por pergunta, ranking das comunidades (semana e geral), impacto por parceiro. |
| Triggers | `04-triggers.sql` | 165 triggers no total: sincronização em tempo real (marcam a versão do que mudou em `sincronia`), **auditoria** (quem mudou o quê nas 28 tabelas críticas — RN86 + RN101) e **regras de negócio** (recusam com `SIGNAL SQLSTATE '45000'`). |
| Stored procedures e Event | `05-procedures.sql` | 11 procedures (2 usadas pelos triggers; 3 de consulta controlada da auditoria; relatórios; manutenção diária ampliada; integridade com 10 verificações; limpeza da demonstração) e 1 event diário (`ev_manutencao_diaria`). |
| INSERT de dados de teste | `06-dados-teste.sql` | Conteúdo oficial (categorias, atividades, quizzes, missões, conquistas, estruturas) + **demonstração**: 120 jogadores fictícios, 8 semanas de uso, 3.453 registros, 556 tentativas de quiz, 491 conquistas, 42 construções, 4 comunidades, 256 amizades e 340 mensagens; 10 contas de apresentação. Gerado por `gerar-dados-teste.php` (semente fixa, reproduzível). |
| Consultas | `07-consultas.sql` | 27 consultas comentadas pela pergunta que respondem: INNER/LEFT JOIN, autojunção, filtros, GROUP BY/HAVING, ROLLUP, subconsultas (escalar, IN, EXISTS/NOT EXISTS, correlacionada, derivada), CTE e funções de janela (RANK, ROW_NUMBER, LAG, soma acumulada). |

### 1.1 Triggers de regras de negócio (segunda barreira)
O PHP já valida tudo; os triggers garantem a regra mesmo se alguém mexer no banco por fora.

| Trigger | Regra |
|---|---|
| `trg_regra_registro_decidido` | Registro aprovado ou rejeitado não muda mais de situação (recompensa única, RN05). |
| `trg_regra_jogador_saldo` | Saldo nunca negativo; XP nunca diminui. |
| `trg_regra_hist_xp_imutavel`, `trg_regra_hist_credito_imutavel` | Históricos de XP e créditos não podem ser alterados. |
| `trg_regra_mensagem_texto` | Mensagem enviada não pode ser editada. |
| `trg_regra_conquista_permanente` | Conquista concedida não é revogada (RN31). |
| `trg_regra_auditoria_upd`, `trg_regra_auditoria_del` | A auditoria não pode ser alterada nem apagada. |
| `trg_regra_registro_foto` | Atividade com `aceita_foto = 0` recusa registro com foto (RN92). |
| `trg_regra_parceiro_atividade`, `trg_regra_codigo_parceiro`, `trg_regra_pedido_atividade_parceiro` | Atividade com `aceita_parceiro = 0` (ou sem impacto real) não pode ser vinculada, gerar código nem ser solicitada por parceiro (RN69, RN95, migrações 043 e 045). |
| `trg_regra_codigo_promo_usado_upd`, `trg_regra_codigo_promo_usado_del`, `trg_regra_promo_resgate_upd/del` | Código promocional já resgatado não pode mudar código/recompensas/início, reduzir limite abaixo do usado nem ser excluído; resgates são imutáveis (RN49, RN98, migrações 036 e 045). |
| `trg_regra_ajuste_admin_upd`, `trg_regra_ajuste_admin_del` | Ajustes administrativos de conta são imutáveis (RN99, migração 037). |
| `trg_regra_administrador_master_upd`, `trg_regra_administrador_master_del` | O sistema nunca pode ficar sem pelo menos um Administrador Master ativo (RN102, migração 041). |

Manutenção autorizada (scripts de teste e `sp_apagar_demonstracao`) usa `SET @ecocity_manutencao = 1`, que libera desfazer XP e conquistas e não grava auditoria. O PHP converte o erro `45000` em mensagem para o usuário (`erro_regra_banco()` em `includes/db.php`).

### 1.2 Auditoria
Tabela `auditoria` (tabela, id do registro, ação `INSERT`/`UPDATE`/`DELETE`, dados antes e depois em JSON, `usuario_app`, `parceiro_app`, `funcionario_app`, `usuario_banco` = conta do MySQL, `origem` = rota/operação PHP ou `NULL` em SQL direto, `ip` e `data` com milissegundos; protegida pelos triggers `trg_regra_auditoria_upd`/`del`). O PHP informa o contexto completo na conexão via `sincronizar_contexto_auditoria()` (`SET @app_usuario_id, @app_parceiro_id, @app_funcionario_id, @app_ip, @app_origem`). Desde a migração `040` (**RN101**, 20/11/2026), são **67 triggers** cobrindo **28 tabelas críticas**: `usuario` (status e o fato de a senha ter mudado, nunca o hash), `administrador`, `jogador`, `cidade`, `cidade_construcao`, `registro_atividade`, `contestacao`, `parceiro`, `parceiro_atividade`, `parceiro_funcionario`, `parceiro_pedido_atividade`, `comunidade`, `comunidade_missao`, `denuncia_mensagem`, `comunidade_denuncia`, conteúdo (`atividade_sustentavel`, `quiz`, `pergunta`, `alternativa`, `missao`, `conquista`, `construcao`), `codigo_promocional`, `codigo_promocional_indicador`, `codigo_promocional_resgate`, `ajuste_conta_admin`, `redefinicao_senha` e `redefinicao_senha_parceiro` (nunca registra `token_hash`).

### 1.3 Procedures

| Procedure | Uso |
|---|---|
| `sp_limpar_tentativas_abandonadas(dias)` | Apaga tentativas de quiz não concluídas há mais de N dias e limpa registros antigos (> 7 dias) de `tentativa_acao_ip`, `tentativa_codigo_parceiro`, `tentativa_codigo_promocional`, `tentativa_login` com `sucesso = FALSE` (preservando logins bem-sucedidos para `ultima_entrada()`) e tokens expirados há > 7 dias em `redefinicao_senha` e `redefinicao_senha_parceiro`; o event `ev_manutencao_diaria` roda todo dia às 4h com 30 (RN82, RN100, migração 045). |
| `sp_resumo_jogador(id)` | Dados (`vw_jogador_resumo`), impacto multi-unidade por área (`vw_impacto_jogador_unidade`) e últimos registros (`vw_registro_detalhado`) de um jogador. |
| `sp_relatorio_impacto(inicio, fim)` | Impacto real por atividade num período (recusa período invertido). |
| `sp_ranking_comunidades('semana' \| 'geral')` | Ranking das comunidades. |
| `sp_consultar_auditoria(...)`, `sp_resumo_auditoria(...)`, `sp_auditoria_tabelas()` | Consulta controlada somente leitura (`SQL SECURITY DEFINER`) do log de auditoria pela Administração (RN101, migração `040`). |
| `sp_verificar_integridade()` | 10 verificações automáticas: saldo × histórico, XP × histórico, cidades com 5 indicadores, jogador sem cidade, registro aprovado sem data, pergunta sem exatamente 1 alternativa certa, vínculo de parceiro em atividade `aceita_parceiro = 0`, código de parceiro em aberto em atividade `aceita_parceiro = 0`, código promocional acima do `limite_usos` e presença de ao menos 1 Admin Master ativo. Usada depois de toda migração e restauração. |
| `sp_apagar_demonstracao(manter)` | Apaga em transação tudo dos jogadores `usuario.demonstracao = TRUE` (mantém as 10 de apresentação com `TRUE`). |
| `marcar_sincronia`, `registrar_auditoria` | Chamadas pelos triggers. |

---

## 2. Plano de segurança e administração de dados

### 2.1 Perfis de usuário (roles do MySQL 8)

| Perfil | Conta | Pode | Não pode |
|---|---|---|---|
| **Administrador do banco** (`ecocity_role_dba`) | `ecocity_dba@localhost` | Tudo no schema `ecocity`, inclusive conceder permissões. | Entrar de fora do servidor. Trava 1 dia após 5 senhas erradas. |
| **Operador / aplicação** (`ecocity_role_app`) | `ecocity_web` (o site PHP) | Ler e gravar as tabelas do jogo; ler as views; executar procedures (inclusive as 3 procedures somente leitura de auditoria desde a RN101). | Criar, alterar ou apagar tabelas; alterar ou apagar históricos de XP e créditos; escrever na sincronização; qualquer acesso direto (`SELECT`/`INSERT`/`UPDATE`/`DELETE`) à tabela `auditoria`. |
| **Gerente / analista** (`ecocity_role_analista`) | `ecocity_analista` | Ler as 12 views e rodar `sp_relatorio_impacto` e `sp_ranking_comunidades`. | Ler tabelas (nenhum e-mail, senha ou nome real); gravar qualquer coisa. Trava após 5 senhas erradas. |
| **Backup** (`ecocity_role_backup`) | `ecocity_backup@localhost` | Leitura consistente, definições (views, triggers, rotinas, events), binlogs e `FLUSH LOGS`. | Gravar dados. |

O usuário antigo do site (`ecocity`, com todas as permissões) foi **removido** do servidor publicado em 18/11/2026; o site passou a usar `ecocity_web`. Em 19/11/2026 (RN100), também foi removida a conta `'root'@'%'` do MySQL do Docker (mantendo apenas `'root'@'localhost'` via socket local) e o container `db` foi isolado na rede Docker `db-net` (`internal: true`, sem internet e inacessível ao container `tunnel`).

### 2.2 Comandos DCL (`08-seguranca.sql`)
- `CREATE ROLE` para os 4 perfis; `GRANT` tabela a tabela para o operador (permissões por schema não permitiriam restringir algumas tabelas).
- `REVOKE UPDATE, DELETE` nos históricos e `REVOKE INSERT, UPDATE, DELETE` na sincronização para o operador.
- `CREATE USER … FAILED_LOGIN_ATTEMPTS 5 PASSWORD_LOCK_TIME 1`, `GRANT <perfil> TO <conta>`, `SET DEFAULT ROLE`.
- `SHOW GRANTS … USING` para conferir; exemplos comentados de `ALTER USER … IDENTIFIED BY`, `ACCOUNT LOCK`, `REVOKE <perfil>` e `DROP USER`.
- As senhas no arquivo são marcadores (`${SENHA_WEB}` etc.) trocados na hora de aplicar pelas senhas do arquivo de segredos (`docker/.env` no servidor; `config/usuarios-banco.local.env` no Wamp). Nunca ficam no script nem aparecem na tela.

**Testado:** `ecocity_web` lê e grava o jogo; os triggers gravam auditoria e sincronização com o dono deles; o usuário do site recebe "command denied" ao alterar ou apagar histórico, ler auditoria, criar tabela, `DROP` e escrever na sincronização; o analista lê as views, roda o relatório e não lê `usuario` nem apaga nada; a auditoria registrou a conta do site (`app=2`, `ecocity_web`).

### 2.3 Estratégia de backup (`docker/backup.ps1`)

| Tipo | O que copia | Quando (Agendador do Windows) | Retenção |
|---|---|---|---|
| **Full** | `mysqldump --single-transaction --routines --triggers --events --flush-logs --source-data=2` (sem travar o site) + as fotos de evidência | Domingo, 3h | 4 últimos |
| **Diferencial** | Todos os binlogs desde o último full | Todo dia, 3h30 | 14 dias |
| **Incremental** | Só os binlogs desde o último backup de qualquer tipo | A cada hora | 14 dias |

- Destino: `%USERPROFILE%\EcoCity-Backups` (`full/`, `binlog/`, `estado.json` com o ponto de partida, `backup.log`).
- Binlogs mantidos no servidor por 14 dias (`--binlog-expire-logs-seconds=1209600`).
- **Cópia fora do PC**: copiar a pasta para um HD externo ou nuvem pelo menos 1 vez por semana (proteção contra perda, roubo ou dano do computador).
- Os backups usam a conta `ecocity_backup` (só leitura). O primeiro full foi feito em 18/11/2026 pelo próprio Agendador.

### 2.4 Plano de recuperação de desastres (`docker/restaurar.ps1`)
**Objetivos:** perda máxima de dados (RPO) de **1 hora** (incremental de hora em hora); site de volta (RTO) em até **2 horas**.

Restauração (testada em 18/11/2026):
1. `restaurar.ps1 -Ate "AAAA-MM-DD HH:MM:SS"` sobe um MySQL separado (`ecocity-restore`, porta local 3317), importa o último full e reaplica os binlogs até o horário pedido (recuperação em um ponto no tempo). Sem `-Ate`, aplica tudo.
2. Roda `sp_verificar_integridade()`.
3. Conferido o resultado, colocar no ar: parar o site (`docker compose stop web tunnel`), gerar um dump do `ecocity-restore` e importar no container `db`, recriar as contas com `08-seguranca.sql` (contas e perfis ficam fora do dump do schema) e subir de novo (`docker compose --profile tunel up -d`).

No teste: três registros marcados em 01:24:59, 01:25:04 e 01:25:10, corte em 01:25:07; a restauração trouxe os dois primeiros e não o terceiro, com integridade sem divergências.

| Cenário | Ação | Responsável |
|---|---|---|
| Exclusão ou alteração acidental de dados | Restaurar até um minuto antes do erro (passo 1), conferir e copiar só o que se perdeu, ou trocar o banco inteiro (passo 3). | Administrador do banco |
| Volume do Docker ou disco corrompido | Recriar os containers (`docker compose up -d --build`) e restaurar o último full + binlogs. | Administrador do banco |
| PC queimado, roubado ou formatado | Instalar Docker num computador novo, copiar o projeto e a cópia externa dos backups, criar o `docker/.env` (senhas novas), restaurar e religar o túnel na Cloudflare. | Responsável pelo projeto |
| Invasão ou senha vazada | Tirar o túnel do ar; `ALTER USER … ACCOUNT LOCK` na conta afetada; trocar todas as senhas do `.env` e reaplicar `08`; ler a `auditoria` para ver o que foi mudado e por quem; restaurar até antes do incidente se houver dano. | Administrador do banco |
| Erro numa migração | Toda migração é aplicada com backup antes; se falhar, restaurar o backup do momento anterior. | Administrador do banco |

Teste de restauração: **uma vez por mês**, num container separado, sem tocar no site.

### 2.5 Monitoramento e otimização (`09-monitoramento.sql`)
- **Consultas lentas:** log ligado no servidor (`--slow-query-log=ON`, acima de 0,5 s).
- **Performance schema / sys:** consultas que mais gastam tempo, consultas sem índice (leitura da tabela inteira), índices nunca usados, tamanho das tabelas, conexões por conta.
- **EXPLAIN antes × depois dos índices compostos** (dados de demonstração):

| Consulta | Sem o índice novo | Com o índice |
|---|---|---|
| Extrato do jogador | varredura completa, 3.953 linhas | `ix_hist_xp_jogador_data`, 174 |
| Limite diário (RN44) | 730 linhas | `ix_registro_jogador_atividade_data`, 1 |
| Fila de aprovação | varredura completa, 3.453 linhas | `ix_registro_tipo_status_data`, 21 |
| 1ª tentativa do quiz | 12 linhas | `ix_tentativa_jogador_quiz`, 1 |
| Variação dos indicadores | varredura completa, 10.506 linhas | `ix_hist_ind_cidade_data`, 94 |

- **Rotina:** `ANALYZE TABLE` nas tabelas grandes de vez em quando; `OPTIMIZE TABLE` depois de muitas exclusões; acompanhar o teste de carga (pendência) com estas consultas.
- A sincronização em tempo real foi medida em 0,8 ms por consulta sem mudanças.

### 2.6 Mecanismos de integridade

| Camada | Exemplos |
|---|---|
| Constraints | PK, 98 FKs com RESTRICT, UNIQUE, NOT NULL, 53 CHECKs (seção 1). |
| Triggers | Regras de negócio (1.1) e auditoria (1.2). |
| Transações | Toda operação com várias tabelas (aprovação, construção, comunidade, demonstração) roda em transação; se algo falha, nada fica pela metade (os triggers rodam na mesma transação). |
| Validações no PHP | Regras de negócio, CSRF, limites, permissões (primeira barreira). |
| Conferência | `sp_verificar_integridade()` depois de restaurações e cargas. |
| Menor privilégio | O site não consegue apagar históricos nem mudar a estrutura (2.1). |

---

## 3. Dados de demonstração
- Fictícios e marcados: `usuario.demonstracao = TRUE`, e-mails `@demo.ecocity.test`. Não foram desenhados para provar nenhuma conclusão (o indicador "Aprenda → Pratique" sai neutro com eles).
- Contas de apresentação: `database/contas-demo.md` (pasta bloqueada na web). Gerar os dados de novo troca as senhas.
- Apagar: `CALL sp_apagar_demonstracao(TRUE)` mantém as 10 de apresentação; `FALSE` apaga todas. As contas reais não são tocadas.
