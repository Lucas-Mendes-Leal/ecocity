# EcoCity — scripts do banco de dados (entrega)

Ordem de execução (MySQL 8, cliente com `--default-character-set=utf8mb4`):

| # | Arquivo | Conteúdo |
|---|---|---|
| 1 | `01-criar-banco.sql` | CREATE DATABASE e as 69 tabelas com PK, FK, NOT NULL, UNIQUE, DEFAULT e CHECK |
| 2 | `02-indices.sql` | 10 índices compostos (com a consulta que cada um atende) |
| 3 | `03-views.sql` | 3 functions determinísticas e 13 views de relatório |
| 4 | `04-triggers.sql` | 165 triggers: sincronização, auditoria e regras de negócio |
| 5 | `05-procedures.sql` | 11 procedures e o event de manutenção diária |
| 6 | `06-dados-teste.sql` | INSERT do conteúdo oficial + dados de demonstração (gerados por `gerar-dados-teste.php`) |
| 7 | `07-consultas.sql` | 27 consultas de análise (JOINs, filtros, agrupamentos, subconsultas, CTE, janelas) |
| 8 | `08-seguranca.sql` | Perfis e contas (CREATE ROLE/USER, GRANT, REVOKE); senhas como marcadores `${SENHA_...}` |
| 9 | `09-monitoramento.sql` | Consultas de desempenho (sys/performance_schema) e EXPLAIN antes × depois dos índices |

Exemplo (num MySQL vazio):

```bash
for f in 01-criar-banco.sql 02-indices.sql 03-views.sql 04-triggers.sql 05-procedures.sql 06-dados-teste.sql; do mysql -uroot -p --default-character-set=utf8mb4 < "$f"; done
```

O `08-seguranca.sql` precisa das senhas: troque os marcadores `${SENHA_DBA}`, `${SENHA_WEB}`, `${SENHA_ANALISTA}`, `${SENHA_BACKUP}` e `${HOST_APP}` antes de rodar (nunca grave senhas reais neste arquivo).

Plano de segurança, backup, recuperação e monitoramento: `specs/banco-de-dados.md`. Backups e restauração: `docker/backup.ps1` e `docker/restaurar.ps1`.
