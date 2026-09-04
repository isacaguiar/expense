# Cache e logs do Laravel versionados no repositório

ID: 007
Origem: docs/feature/concluidas/202608/20260817-config-url-api-frontend/implementation.md (achado ao rodar o backend localmente para validar a TASK-029)
Criado em: 2026-08-17
Prioridade: BAIXA
Status: Resolvido (fora do fluxo SDD)

## Descrição

`backend/storage/framework/cache/data/**` e `backend/storage/logs/laravel.log` estão rastreados pelo git, mas `backend/.gitignore` não lista `storage/framework/cache/` nem `storage/logs/` (só ignora `/storage/*.key`). Rodar `php artisan serve` localmente já é suficiente para gerar diffs nesses arquivos.

## Por que importa

Mesmo problema do item 005 (node_modules do frontend): infla o histórico do repositório e cria risco de alguém commitar acidentalmente cache/log gerado localmente — como quase aconteceu ao validar a TASK-029 (revertido com `git restore` antes do commit).

Tipo sugerido: infra

## Resolução

Concluído em: 2026-08-21 (constatado ao verificar o estado atual antes de promover este item — a correção em si é anterior e não passou pelo fluxo SDD)
Commit: `ec81e9cfd chore: remove log e cache do Laravel do controle de versão` (já em `dev`)
Verificação: `git ls-files backend/storage/framework/cache backend/storage/logs` só retorna os `.gitignore` placeholder de cada pasta, nenhum dado gerado (`laravel.log`, `cache/data/*`).

Achado relacionado, não coberto por este item: o mesmo problema persiste em `backend/storage/framework/sessions/` e `backend/storage/framework/views/` — registrado como item [030](storage-sessions-views-compilados-versionados-backend.md) do backlog.
