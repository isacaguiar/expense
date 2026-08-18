# Cache e logs do Laravel versionados no repositório

ID: 007
Origem: docs/feature/20260817-config-url-api-frontend/implementation.md (achado ao rodar o backend localmente para validar a TASK-029)
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Aberto

## Descrição

`backend/storage/framework/cache/data/**` e `backend/storage/logs/laravel.log` estão rastreados pelo git, mas `backend/.gitignore` não lista `storage/framework/cache/` nem `storage/logs/` (só ignora `/storage/*.key`). Rodar `php artisan serve` localmente já é suficiente para gerar diffs nesses arquivos.

## Por que importa

Mesmo problema do item 005 (node_modules do frontend): infla o histórico do repositório e cria risco de alguém commitar acidentalmente cache/log gerado localmente — como quase aconteceu ao validar a TASK-029 (revertido com `git restore` antes do commit).

Tipo sugerido: infra
