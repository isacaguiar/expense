# Implementation — Tela de Pagamentos

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260822

---

## 1. Desvios do fluxo padrão (se houver)

Tasks do backend (TASK-001 a TASK-004) implementadas numa única sessão contínua, direto na branch da feature (`backend/20260822-criacao-tela-pagamentos`, criada a partir de `dev`), sem sub-branch por task a partir da TASK-002 como `ADR-003` prevê — mesma justificativa já usada em `docs/feature/20260822-acerto-de-contas-ciclo/implementation.md`: tasks pequenas e sequenciais, revisadas/testadas uma a uma antes de seguir. Por terem sido implementadas e só depois commitadas, TASK-002/TASK-003 (mesmo arquivo, `ExpenseController.php`) foram commitadas juntas num único commit de implementação, e TASK-004 (testes de ambas) num commit separado — não dá pra separar por task de forma limpa sem `git add -p` (interativo, fora de uso). Granularidade de task fica preservada no `tasks.md`/neste log, não 1:1 nos commits.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 20260822 | Claude (IA) | Criada migration `2026_08_22_200000_add_payment_proof_path_to_ex_quotas_table.php` (coluna `payment_proof_path`, string, nullable). `php artisan migrate --path=...`: aplicada em 442ms sem erro. `php artisan storage:link`: symlink `backend/public/storage` criado. `Quota` model: `payment_proof_path` em `$fillable`, accessor `payment_proof_url` (`$appends`). Verificado via tinker: `Quota::first()->payment_proof_url` retorna `null` sem path. | — |
| TASK-002 | Concluída | 20260822 | Claude (IA) | `pay()` passa a receber `Request`, valida `comprovante` como `nullable\|image\|max:5120`, salva via `store('comprovantes', 'public')` quando enviado. `unpay()` apaga o arquivo (`Storage::disk('public')->delete(...)`) e limpa `payment_proof_path`. Decisão registrada em `plan.md` §2: campo opcional na API pra não quebrar `ExpenseManager.tsx` (que chama `/pay` sem corpo). | — |
| TASK-003 | Concluída | 20260822 | Claude (IA) | `computeCycleSummary()`: `$expenses` ganha `valuePerPerson` (mesma fórmula já usada no cálculo de `$balances`) e `paymentProofUrl`. `collectCycleEntries()`: os 3 pontos que montam `$entries` (direta, parcela `IN_INSTALLMENTS`, ocorrência `FIXED`) passam a incluir `paymentProofUrl` a partir de `$quota->payment_proof_url`. | — |
| TASK-004 | Concluída | 20260822 | Claude (IA) | 4 testes novos em `ExpenseControllerPayTest.php` (pay sem foto mantém `payment_proof_path` nulo; pay com foto salva e expõe URL, via `Storage::fake('public')`; pay rejeita arquivo não-imagem — 422, precisou `Accept: application/json` explícito pro teste não cair em redirect 302; unpay apaga o arquivo do disco e limpa o path) + 2 em `ExpenseControllerSummaryTest.php` (`valuePerPerson` calculado corretamente; `paymentProofUrl` exposto quando a quota tem `payment_proof_path`). `php artisan test` (suíte completa): 204 passed (626 assertions) — sem regressão. `./vendor/bin/pint --test` nos arquivos tocados: PASS (o `pint` sem `--test`/sem escopo tinha corrigido 8 arquivos não relacionados a esta feature — revertidos com `git checkout --` antes do commit, por não serem desta task). | — |
