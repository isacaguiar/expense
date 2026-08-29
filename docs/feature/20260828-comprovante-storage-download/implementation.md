# Implementation — Storage e download de comprovante de pagamento

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260828

---

## 1. Desvios do fluxo padrão (se houver)

Sem desvio de branch/PR. Ponto de atenção específico: TASK-224 edita `00-constitution.md` §5.5 — o diff precisa de aprovação humana explícita antes do merge do PR da feature (gate de "Editar a Constitution", `00-constitution.md` §5.2). O restante das tasks é autônomo até o PR.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-218 | Concluída | 2026-08-28 | IA (feature branch) | `./vendor/bin/pint app/Support/ProofStorage.php tests/Unit/ProofStorageTest.php` limpo · `php artisan test --filter=ProofStorageTest` → 3 passed (8 assertions) | `App\Support\ProofStorage` com `store()` (grava `comprovantes/<groupId>/<uuid>.<ext>` no disco `local`), `resolveDisk()` (regex `^comprovantes/\d+/` → `local`, senão `public`), `delete()` (silencioso p/ path vazio). |
| TASK-219 | Concluída | 2026-08-28 | IA (branch `backend/...-TASK-219`) | `./vendor/bin/pint` limpo · `php artisan test --filter=ProofDownloadControllerTest` → 6 passed (8 assertions) | Rota `proofs.show` (`GET /api/groups/{groupId}/proofs/{type}/{id}`, fora do `jwt.auth`, middleware `signed`) + `ProofDownloadController@show`: revalida recurso×grupo (404 p/ type inválido, recurso de outro grupo, sem comprovante), resolve disco via `ProofStorage::resolveDisk`, serve via `Storage::disk()->response()` (inline, em vez de `download()` do plan — melhor p/ "Ver comprovante"). |
| TASK-220 | Concluída | 2026-08-28 | IA (branch `backend/...-TASK-220`) | `./vendor/bin/pint --test` limpo · `php artisan test` → 237 passed (717 assertions) | `pay()`/`confirmSettlement()` gravam via `ProofStorage::store($file, $groupId)` (disco `local`, `comprovantes/<groupId>/`); `unpay()` e o reenvio de `confirmSettlement` apagam o arquivo anterior via `ProofStorage::delete` (resolve novo/legado). Testes existentes de `ExpenseControllerPayTest`/`SettlementConfirmationControllerTest` migrados p/ disco `local` + assert de layout por grupo + assert de remoção do comprovante anterior no reenvio — cobre a maior parte do escopo da TASK-222. |
| TASK-221 | Concluída | 2026-08-28 | IA (branch `backend/...-TASK-221`) | `./vendor/bin/pint --test` limpo nos arquivos da feature · `php artisan test` → 237 passed (720 assertions) | Accessors `Quota::payment_proof_url` e `SettlementConfirmation::proof_url` agora emitem `URL::temporarySignedRoute('proofs.show', +30min, ...)`. `Quota` ganhou `$hidden = ['expense']` (o accessor precisa de `expense->group_id`, mas serializar a relação causava recursão Expense→quotas→expense). Eager-load da relação via `setRelation` em `show()`/`update()` (helper `hydrateQuotaExpense`) e nos loops de `collectCycleEntries` (`$direct`, `$fixedCandidates`); `pay()` retorna `$quota->fresh()->load('expense')`. Ajustado `ExpenseControllerSummaryTest` (URL agora é signed route, não `/storage/...`). |
| TASK-222 | Concluída | 2026-08-28 | IA (com TASK-220/221) | `php artisan test` → 237 passed (720) · `ProofStorageTest` 3 · `ProofDownloadControllerTest` 6 | Cobertura consolidada: gravação por grupo no disco privado, download com assinatura válida, 403 sem/assinatura adulterada, 404 type inválido / grupo errado, resolução de path legado no disco `public`, `unpay` apaga arquivo, reenvio de settlement apaga o anterior, e end-to-end upload→URL assinada da resposta→GET→200 (pay e confirmSettlement). Sem arquivo de teste novo além dos de TASK-218/219. |
