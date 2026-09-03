# Specify — Migração automática no deploy do backend

> Feature: o `deploy-backend.yml` passa a rodar `php artisan migrate --force` no host depois de publicar o código, com o deploy falhando se a migration falhar. Hoje aplicar migration em produção é um passo manual fora do pipeline, e o pipeline sobe código que pode depender de uma migration ainda não aplicada. Origem: escalada da Triagem do fluxo BFF (slug `deploy-backend-migracao-automatica`, 2026-09-03) — nenhum arquivo criado em `docs/bugfix/`; a Triagem marcou a caixa "Decisão de produto/arquitetura" porque automatizar `migrate --force` remove o gate humano de `00-constitution.md` §5.2 e altera o desenho de deploy do `ADR-008`.

Versão: 1.0 · Criado em: 20260903

---

## 1. Problema

O `deploy-backend.yml` dispara a cada `push` em `main` (`00-constitution.md` §5.2, linha "Deploy") e publica o Laravel via rsync sobre SSH (`ADR-008`). O passo pós-deploy — `SCRIPT_AFTER` em `.github/workflows/deploy-backend.yml:97-103` — roda só:

```
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Não há `php artisan migrate`. Aplicar migration em produção é hoje ação manual por SSH e **gate humano** (`00-constitution.md` §5.2, linha "Rodar migration em banco compartilhado/produção" → exige aprovação humana). Foi feito uma única vez, à mão, em 2026-09-01 (primeiro deploy SSH do backend — registrado na memória de infra do projeto).

Consequência concreta — incidente de produção em 2026-09-03: a feature `20260902-pagamento-ciclo-fechado` (PRs #129 / #131 / #134, mergeadas em `main` em 2026-09-02) subiu código que lê a coluna `ex_group_cycle_snapshots.settled_at`:

- `ExpenseController::cycleHistory()` — `backend/app/Http/Controllers/ExpenseController.php:705` — `->whereNotNull('settled_at')`
- `ExpenseController::grossDebts()` — `backend/app/Http/Controllers/ExpenseController.php:747` — idem
- `ExpenseController::sealCycleIfSettled()` — `backend/app/Http/Controllers/ExpenseController.php:1256` — `updateOrCreate([... 'settled_at' => Carbon::now()])`

A coluna é criada pela migration `backend/database/migrations/2026_09_02_000000_add_settled_at_to_ex_group_cycle_snapshots_table.php`, que **não rodou** em produção (o pipeline não roda migration; ninguém rodou à mão depois do merge). Resultado: `SELECT ... WHERE settled_at IS NOT NULL` → MySQL 1054 "Unknown column 'settled_at'" → `QueryException` → HTTP 500 em `GET /api/groups/{id}/expenses/cycles` e `.../gross-debts` (tela Relatórios → "Histórico de ciclos fechados", grupo 3878; e a árvore credor→devedores do Dashboard).

O padrão do projeto — "merge em `main` já dispara o deploy" (§5.2) — não tem nenhum ponto em que a migration correspondente entra. Enquanto a aplicação da migration for um passo humano fora do pipeline, existe sempre uma janela em que o código de produção referencia schema que o banco de produção não tem.

## 2. Requisitos

### 2.1 `deploy-backend.yml` roda `php artisan migrate --force` após publicar

No `SCRIPT_AFTER` do passo de deploy (`.github/workflows/deploy-backend.yml:97-103`), acrescentar `php artisan migrate --force` **antes** de `config:cache`/`route:cache` — se a migration falhar, o deploy para antes de cachear config/rota (§2.2). `--force` é obrigatório: com `APP_ENV=production` o `migrate` pede confirmação interativa, que não existe num shell de deploy. Uso de `--no-interaction` e ordem fina em relação a `optimize:clear` ficam para o `plan.md`.

### 2.2 Falha da migration falha o deploy (sem "meio subir")

Se `php artisan migrate --force` sair com código ≠ 0, o job do GitHub Actions tem que ficar vermelho e o deploy ser considerado falho — não pode passar batido como hoje, em que o `SCRIPT_AFTER` roda comandos em sequência sem checagem de status. O `plan.md` decide o mecanismo (`set -e` no script remoto, encadear com `&&`, verificação explícita de `$?`) e o que fazer com o código **já publicado pelo rsync** quando a migration falha: o rsync roda antes do `SCRIPT_AFTER`, então nesse cenário o host fica com código novo + schema velho até intervenção manual (ver §2.4, consequências).

### 2.3 Migration destrutiva continua barrada por gate humano — via convenção

`00-constitution.md` §4.2 (migrations aditivas por padrão) e §5.2 (linha "Migration destrutiva (drop/rename/alterar tipo) em qualquer ambiente além do local" → exige aprovação humana) **não mudam** por conta desta feature. Um `migrate --force` automático no CI não pode aplicar `->drop*()` / `->renameColumn()` / `->change()` sem sinal verde humano.

O mecanismo escolhido é **convenção humana, sem check no pipeline**: fica documentado (no ADR de §2.4, na emenda à Constitution de §2.5, e no `docs/sdd/04-implementation.md` / checklist pré-PR) que migration destrutiva não entra em `main` sem aprovação humana explícita registrada no PR de promoção. O reviewer do PR `dev` → `main` é responsável por barrar. Descartado o check automático de migrations pendentes (grep por `->drop`/`->change`/etc.): mais peça no pipeline, detecção heurística com falso positivo/negativo, e o volume/ritmo atual de migrations não justifica. Se no futuro passar batido uma vez, revisita-se (candidato a backlog).

### 2.4 Emenda ao ADR-008

Acrescentar ao `docs/sdd/decisions/ADR-008-deploy-backend-ssh-rsync.md` (mesma área — o que o `deploy-backend.yml` faz no host via `SCRIPT_AFTER`; o ADR já tem seção "Consequências" tratando desse passo) a decisão: o pipeline de deploy do backend passa a aplicar migrations automaticamente (`php artisan migrate --force` no `SCRIPT_AFTER`). Deve conter — por que essa abordagem e não as alternativas descartadas:

- **Guarda fail-fast**: o CI só roda um check read-only (`php artisan migrate:status`) e **aborta** o deploy se houver migration pendente, mantendo o `migrate` manual e o gate humano. (Foi a opção B da Triagem, não escolhida.)
- **Manter 100% manual** (status quo): rejeitada — é a causa do incidente de 2026-09-03.
- **Check automático de migration destrutiva no pipeline**: rejeitada em favor de convenção humana (§2.3).

E as consequências: perde-se o ponto de inspeção humana antes de cada `migrate` em produção; ganha-se a garantia de que código e schema sobem juntos; surge a janela de risco de "código novo + schema velho" se a migration falhar depois do rsync (§2.2); dependência de o usuário de deploy ter privilégio de DDL (`ALTER TABLE`) no banco; a barra contra migration destrutiva passa a depender do reviewer do PR de promoção (§2.3).

### 2.5 Emenda à Constitution (gate humano — não executada pela feature)

Com esta feature, o `migrate` de produção passa a ser executado pelo pipeline sem aprovação por-deploy — o que contradiz a linha "Rodar migration em banco compartilhado/produção" da tabela de §5.2, e possivelmente a linha "Deploy backend" de §3. **Editar a Constitution é gate humano sempre** (§5.2). A feature registra a necessidade em `implementation.md` §1 e no ADR (§2.4); a edição em si — texto da linha de §5.2, bump de versão + data — é um passo humano à parte, como já foi feito para o §3 no `ADR-008`.

### 2.6 Pendências de gate humano (documentadas, não executadas pela feature)

Registrar em `implementation.md` §1 e avisar o dono:

1. Rodar `php artisan migrate --force` no host de produção **agora**, para encerrar o incidente de 2026-09-03 (a migration `2026_09_02_000000_add_settled_at_to_ex_group_cycle_snapshots_table.php` está pendente e há três endpoints em 500). Independe do resto da feature.
2. Aplicar a emenda à Constitution §5.2 / §3 (§2.5).
3. Confirmar que o usuário do banco usado em produção (`isacag00_expense`) tem privilégio de DDL para o `migrate` automático rodar `ALTER TABLE` — o `migrate` manual de 2026-09-01 rodou com esse usuário, então é provável, mas confirmar antes de ligar o passo automático.

## 3. Fora de escopo desta feature

- **Correção do incidente de 2026-09-03** — rodar `migrate --force` no host para criar `settled_at` é ação de gate humano (§2.6.1), não entregável da feature.
- **Workflow de CI (verificação) do backend** — Pint `--test` + PHPUnit em Pull Request. É o item de backlog 034 (`docs/backlog/workflow-ci-backend.md`), separado; esta feature só toca o `SCRIPT_AFTER` do `deploy-backend.yml`, não cria `ci-backend.yml`.
- **Rollback automático de migration / de deploy** — se a migration passar e o deploy falhar depois, ou o inverso, reverter o schema automaticamente. Resposta continua manual.
- **Backfill / reprocessamento de dados** disparado por migration ou depois dela.
- **`deploy-site.yml` / `deploy-frontend.yml`** — não têm banco, não são afetados.
- **Zero-downtime / migrations expand-contract** (compatibilidade schema-código durante a troca) — desejável a longo prazo, não é o problema aqui.
- **Trocar o mecanismo de deploy** (`ADR-008`, rsync/SSH, `easingthemes/ssh-deploy`) — inalterado; só o conteúdo do `SCRIPT_AFTER` muda.
