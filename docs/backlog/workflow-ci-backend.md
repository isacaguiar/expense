# Workflow de CI (verificação) para o backend

ID: 034
Origem: docs/feature/20260827-ci-frontend/specify.md §3 (achado ao promover o item de backlog 008 — CI do frontend)
Criado em: 2026-08-27
Prioridade: MEDIA
Status: Aberto

## Descrição

`.github/workflows/deploy-backend.yml` é só um workflow de **deploy** — dispara em `push` para `main` e builda/publica via FTP, sem rodar `./vendor/bin/pint --test` nem `php artisan test` em nenhum momento. Não existe hoje nenhum workflow de CI (verificação) para o backend equivalente ao que a feature `ci-frontend` (item de backlog 008) cria para o frontend — um novo `.github/workflows/ci-backend.yml` disparando em Pull Request contra `dev`/`main`, rodando Pint (`--test`) e PHPUnit (`php artisan test`), precisaria decidir como provisionar banco de dados de teste no runner (SQLite em memória vs. MySQL via serviço do GitHub Actions, dado que os testes atuais usam `DatabaseTransactions`).

## Por que importa

Sem isso, um PR do backend pode ser mergeado com Pint sujo ou PHPUnit quebrado sem nenhuma checagem automática do GitHub — mesma lacuna que motivou o item 008 para o frontend, só que do lado do backend.

Tipo sugerido: backend/infra
