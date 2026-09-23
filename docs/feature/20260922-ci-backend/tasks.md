# Tasks — CI (verificação) para o backend

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260922

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-354 | Criar `.github/workflows/ci-backend.yml` | infra | plan.md §1 | nenhum | Concluída |
| TASK-355 | Provar no PR real que o check falha quando deveria e passa quando o código está correto | infra | plan.md §2 | nenhum | Pendente |

## Critérios de aceite

- **TASK-354**: `.github/workflows/ci-backend.yml` existe com o conteúdo de `plan.md §1` — dispara em `pull_request` contra `dev` e `main`, sobe `services.mysql` (`mysql:8.0.17`, `MYSQL_DATABASE=ex-db`), gera `.env` de teste inline (sem copiar `.env.example`, sem usar `secrets.*` do GitHub — nenhum valor no arquivo é credencial real), roda `php artisan key:generate`, `php artisan migrate --force`, `./vendor/bin/pint --test` e `php artisan test`, nessa ordem. `yamllint .github/workflows/ci-backend.yml` (ou, na ausência do lint, `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci-backend.yml'))"`) confirma que o YAML é sintaticamente válido antes do push.

- **TASK-355**: depois do PR desta feature aberto contra `dev` (que já dispara `ci-backend.yml`): (1) um commit nesse PR quebra de propósito o Pint (ex.: espaçamento incorreto em uma linha de `AuthController.php`, revertido no passo seguinte) ou o PHPUnit (ex.: `$this->assertTrue(false)` num teste existente), e o check `ci-backend.yml` do PR fica vermelho — capturar o link/status do run que falhou; (2) um commit seguinte reverte a quebra, e o check fica verde — capturar o link/status do run que passou. Os dois commits ficam no histórico do PR (não é feito squash/rebase escondendo a prova). Nenhuma outra task de código desta feature é necessária para este critério — a "quebra" é só para provar o workflow, não uma mudança real de produto.
