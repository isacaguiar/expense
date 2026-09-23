# Workflow de CI (verificação) para o backend

ID: 034
Origem: docs/feature/concluidas/202608/20260827-ci-frontend/specify.md §3 (achado ao promover o item de backlog 008 — CI do frontend)
Criado em: 2026-08-27
Prioridade: MEDIA
Status: Promovido para TASK-354

## Descrição

`.github/workflows/deploy-backend.yml` é só um workflow de **deploy** — dispara em `push` para `main` e builda/publica via FTP, sem rodar `./vendor/bin/pint --test` nem `php artisan test` em nenhum momento. Não existe hoje nenhum workflow de CI (verificação) para o backend equivalente ao que a feature `ci-frontend` (item de backlog 008) cria para o frontend — um novo `.github/workflows/ci-backend.yml` disparando em Pull Request contra `dev`/`main`, rodando Pint (`--test`) e PHPUnit (`php artisan test`), precisaria decidir como provisionar banco de dados de teste no runner (SQLite em memória vs. MySQL via serviço do GitHub Actions, dado que os testes atuais usam `DatabaseTransactions`).

## Por que importa

Sem isso, um PR do backend pode ser mergeado com Pint sujo ou PHPUnit quebrado sem nenhuma checagem automática do GitHub — mesma lacuna que motivou o item 008 para o frontend, só que do lado do backend.

Tipo sugerido: backend/infra

## Resolução
Concluído em: 2026-09-22
Feature: docs/feature/concluidas/202609/20260922-ci-backend/ (migra para lá quando o PR mergear em `dev` — ADR-009)
Tasks: TASK-354, TASK-356, TASK-355
PRs: https://github.com/isacaguiar/expense/pull/197

Decisão de banco (SQLite vs. MySQL via serviço) resolvida em favor de MySQL 8.0.17
via serviço do GitHub Actions — mesma imagem do `docker-compose.yml` local, validada
rodando as migrations do zero antes de decidir. Rodar o workflow de verdade (não só
escrevê-lo) valeu a pena: revelou 2 problemas reais que uma leitura do YAML não
pegaria — débito de Pint pré-existente em `dev` (corrigido, TASK-356) e um `.env`
sem `APP_KEY=` para o `key:generate` substituir (`MissingAppKeyException`). A prova
de "falha quando deveria / passa quando está certo" saiu desses dois bugs reais, não
de uma quebra artificial como o plano original previa.
