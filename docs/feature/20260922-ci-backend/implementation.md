# Implementation — CI (verificação) para o backend

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260922

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum. Branch da feature `infra/20260922-ci-backend`, criada a partir de `dev` atualizada (`origin/dev` já estava em dia no momento da criação — PR #196 da feature anterior ainda não tinha sido mergeado).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-354 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | Validação de sintaxe YAML via `js-yaml` (instalado ad-hoc no scratchpad, projeto não tem parser YAML): `node -e "yaml.load(fs.readFileSync('.github/workflows/ci-backend.yml'))"` — parseou sem erro, `jobs: ['test']`, 8 steps na ordem esperada | Conteúdo idêntico ao decidido em `plan.md §1`; validação real de execução (roda de verdade no runner do GitHub) fica para TASK-355, via PR |
| — | — | 2026-09-22 | Claude | PR #197 aberto contra `dev` — primeiro run real de `ci-backend.yml` (`gh pr checks 197`): `Pint + PHPUnit (backend)` `fail` em 45s, passo `🎨 Pint (estilo)`: "162 files, 8 style issues" nos mesmos 8 arquivos que já apareciam no checklist final da feature `limite-grupos-fonte-unica` | Achado documentado em `plan.md §1.1`; virou TASK-356 (decisão confirmada com o usuário: corrigir, não excluir nem deixar vermelho) |
| TASK-356 | Concluída | 2026-09-22 | Claude (via `/promover-backlog`) | `./vendor/bin/pint app/Helpers/PixPayload.php app/Models/Expense.php app/Models/User.php <5 migrations>` — "8 files, 8 style issues fixed"; `./vendor/bin/pint --test` (repo inteiro) — PASS, 162 files; `php artisan test` — 372 passed (1227 assertions, branch não inclui o teste novo da feature `limite-grupos-fonte-unica`, ainda não mergeada — sem regressão real) | Diff só formatação (`git diff --stat`: 8 files changed, 28(+)/22(-)), nenhuma linha de lógica tocada |
| — | — | 2026-09-22 | Claude | Push do merge de TASK-356 disparou novo run real do PR #197: Pint passou (baseline limpa confirmada), mas `🧪 PHPUnit` falhou — `Tests\Feature\ExampleTest > the application returns a successful response`, `MissingAppKeyException`. Causa: o `.env` gerado no workflow não tinha nenhuma linha `APP_KEY=` para `php artisan key:generate` substituir, então a chave nunca era setada de verdade | Bug real no `ci-backend.yml` (TASK-354), não no código do app — corrigido adicionando `echo "APP_KEY=" >> .env` antes do `key:generate`. Reproduzido e confirmado localmente antes do push: com um container MySQL novo replicando exatamente o `.env` do workflow, `php artisan key:generate` — "Application key set successfully", `grep APP_KEY .env` mostra a chave preenchida; `php artisan test` — suíte completa roda até o fim sem `MissingAppKeyException`, incluindo `Tests\Feature\ExampleTest` e `Tests\Unit\ExampleTest` (que antes nem chegavam a rodar) |
