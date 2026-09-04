# Tasks — Migração automática no deploy do backend

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260903

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-271 | Emendar `ADR-008`: `SCRIPT_AFTER` passa a rodar `php artisan migrate --force`; registrar Consequências (janela código/schema, perda do gate manual, dependência de DDL, convenção anti-destrutiva) e Alternativas descartadas (guarda fail-fast, 100% manual, check destrutivo no pipeline) | doc | plan.md §4 | nenhum | Concluída (2026-09-03) |
| TASK-272 | Editar o `SCRIPT_AFTER` do `deploy-backend.yml`: inserir `php artisan migrate --force --no-interaction \|\| exit 1` entre `optimize:clear` e `config:cache`; `cd … \|\| exit 1`; `chmod … \|\| true` | infra | plan.md §1, §2 | antes do deploy (merge em `main`) | Concluída (2026-09-03) — runtime pós-deploy pendente |
| TASK-273 | Redigir em `implementation.md` §1 o texto proposto da emenda ao `00-constitution.md` §5.2 (split da linha de migração em aditiva/destrutiva) e §3 (linha "Deploy backend") — sem editar a Constitution | doc | plan.md §5 | aplicar a emenda na Constitution é humano (fora da task) | Concluída (2026-09-03) — aplicação = gate humano |
| TASK-274 | Complementar a linha de migração no checklist "antes de integrar" de `docs/sdd/04-implementation.md` §1 com a cláusula: migração destrutiva só vai para `main` com aval humano explícito registrado no PR de promoção (o deploy aplica sozinho) | doc | plan.md §3 | nenhum | Concluída (2026-09-03) |
| TASK-275 | Teste do caminho de falha: migração proposital que quebra (`up()` com erro que não altera schema) numa branch, disparar `deploy-backend.yml` por `workflow_dispatch` na branch, confirmar job vermelho no passo do `migrate`; se ficar verde, aplicar o fallback `appleboy/ssh-action` (`script_stop: true`) do plan.md §2; descartar a migração de teste | infra | plan.md §2, §6 | antes do deploy/execução em produção (dispara workflow contra o environment `PROD`) | Pendente — gate humano |

## Critérios de aceite

- **TASK-271**: `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` — a seção "Decisão" cita `php artisan migrate --force --no-interaction` no `SCRIPT_AFTER`, antes de `config:cache`/`route:cache`, com `|| exit 1`; "Consequências" cobre os cinco pontos do plan.md §4 (código+schema juntos / fim da janela do incidente 2026-09-03; perda do ponto de inspeção humana; janela código-novo+schema-velho sem rollback; convenção anti-destrutiva sem trava técnica; dependência de privilégio DDL) e a nota de que §5.2/§3 da Constitution ficam desatualizadas e a edição é gate humano à parte; "Alternativas consideradas" lista guarda fail-fast, manter 100% manual e check automático de migração destrutiva. Índice `docs/sdd/decisions/README.md` atualizado se o formato pedir data/nota. Nenhuma outra parte do ADR-008 (canal SSH, `easingthemes/ssh-deploy`, `--delete`, `storage/app`) reescrita.

- **TASK-272**: em `.github/workflows/deploy-backend.yml`, dentro do `SCRIPT_AFTER` do passo `🚀 Deploy via SSH (rsync)` —
  - a sequência fica: `cd ${{ secrets.SSH_TARGET }} || exit 1` → `mkdir -p …` → `chmod -R ug+rwX storage bootstrap/cache || true` → `php artisan optimize:clear` → `php artisan migrate --force --no-interaction || exit 1` → `php artisan config:cache` → `php artisan route:cache`;
  - `git diff` mostra alteração **só** dentro do bloco `SCRIPT_AFTER` — nenhum outro passo, input (`SOURCE`/`TARGET`/`EXCLUDE`/`ARGS`/`SSH_CMD_ARGS`), nem a action/pin tocados;
  - o YAML faz parse sem erro e sem tabs (`python -c "import yaml; yaml.safe_load(open('.github/workflows/deploy-backend.yml'))"` ou equivalente);
  - verificação de runtime (registrada no `implementation.md` §2, não bloqueia a task): no 1º deploy após merge em `main`, o log mostra o `migrate` executando (migrations aplicadas ou "Nothing to migrate"), `php` resolvido, `config:cache`/`route:cache` depois, job verde; `GET /api/groups/{id}/expenses/cycles` e `.../gross-debts` respondem 200.

- **TASK-273**: `docs/feature/concluidas/202609/20260903-deploy-backend-migracao-automatica/implementation.md` §1 contém o texto literal proposto para: (a) a substituição da linha "Rodar migration em banco compartilhado/produção" da tabela de §5.2 pelas duas linhas (aditiva = autônoma via pipeline / destrutiva = humana + cláusula do PR de promoção); (b) o novo texto da linha "Deploy backend" de §3 mencionando `migrate --force` no `SCRIPT_AFTER`. O arquivo `00-constitution.md` **não** aparece no `git diff` da feature. `implementation.md` §1 registra que a aplicação é passo humano (bump de versão + data).

- **TASK-274**: em `docs/sdd/04-implementation.md` §1, a linha do checklist que hoje diz *"Se a task envolve migration: é aditiva? Se destrutiva, o gate humano da task foi respeitado…"* passa a incluir a cláusula do PR de promoção (deploy aplica migração automaticamente ao mergear em `main`; destrutiva exige aval humano explícito registrado nesse PR). `git diff` restrito a essa linha/parágrafo; sem bump de versão do documento salvo instrução humana em contrário.

- **TASK-275**: registrado no `implementation.md` §2 —
  - identificador da branch de teste e da migração proposital (conteúdo do `up()`, que não pode conter DDL — ex.: `throw new \RuntimeException('falha proposital')` ou `DB::statement('SELECT 1/0')`);
  - link/id da execução do `deploy-backend.yml` por `workflow_dispatch` na branch e o resultado do passo do `migrate` (vermelho esperado);
  - se o job ficou **verde** apesar da migração quebrada: o passo `🗃️ Migrations (produção)` com `appleboy/ssh-action@<pin>` + `script_stop: true` foi adicionado ao workflow (plan.md §2), o `migrate` saiu do `SCRIPT_AFTER`, e uma nova execução de teste confirma job vermelho; `plan.md` §1/§2 e `ADR-008` atualizados para refletir o passo dedicado;
  - a migração de teste e a branch foram descartadas (não existem em `dev`/`main`).

> **Incidente 2026-09-03 (fora desta feature):** rodar `php artisan migrate --force` no host de produção para aplicar `2026_09_02_000000_add_settled_at_to_ex_group_cycle_snapshots_table.php` e derrubar os 500 é ação de gate humano (`plan.md` §7 passo 0, `specify.md` §2.6.1) — registrada no `implementation.md` §1, não é task deste `tasks.md`.

> **Dependências / ordem:** TASK-271, TASK-273 e TASK-274 são independentes entre si e de TASK-272 (docs). TASK-272 é o núcleo. TASK-275 depende de TASK-272 (precisa do passo novo no workflow) e roda por `workflow_dispatch` na branch da feature — **antes** do merge em `main`, porque pode obrigar a retrabalhar TASK-272 (fallback do plan.md §2). Antes do merge em `main`: TASK-272 e TASK-275 concluídas e a emenda à Constitution (texto de TASK-273) aplicada por gate humano. Antes do PR contra `dev`: `pr-readiness-checker`. `security-reviewer` não se aplica (nenhuma task toca rota/controller/middleware de auth).
