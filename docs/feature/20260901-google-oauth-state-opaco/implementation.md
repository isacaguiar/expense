# Implementation — OAuth Google com state opaco

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260901

---

## 1. Desvios do fluxo padrão

**Suíte com falhas pré-existentes.** `php artisan test` na `dev` (HEAD `45a09c8d2`) já tem **15 testes falhando**, todos em `ExpenseControllerStoreTest` / `ExpenseControllerShowUpdateDestroyTest` (422 onde se espera 200/201 — drift de validação no `ExpenseController`), sem relação com esta feature. Verificado por `git stash` do controller/teste e re-rodada: baseline = 15 falhas / 243 passes; com esta feature = 15 falhas / **244** passes (o +1 é o novo `test_callback_state_is_single_use`). Encaminhado como tarefa à parte (BFF `expense-store-update-422`).

**Revisão `security-reviewer` (gate da TASK-235, antes do merge).** Rodada em 2026-09-01 sobre o diff da branch. **Sem achados bloqueantes** — o nonce opaco + uso único + TTL é estritamente melhor que o blob `Crypt` reutilizável; nenhum padrão de vulnerabilidade do repo reintroduzido; rota pública do callback continua sem retornar dado e a amarração usuário→conta continua feita na chamada autenticada de `redirectUrl()`. 3 observações INFO, todas para a futura feature de login social, nenhuma vira correção aqui. INFO-1 (`Cache::pull` não atômico no driver `file` → uso único não estrito sob concorrência) registrada em `docs/backlog/` item **035**. Revisão humana do PR segue obrigatória (§5.2).

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-235 | Concluída | 2026-09-01 | IA (branch `backend/20260901-google-oauth-state-opaco`) | TDD: reescrito `tests/Feature/GoogleAuthControllerTest.php` primeiro (`Cache`/`Str` no lugar de `Crypt`, helper `linkState()`, novo `test_callback_state_is_single_use`, "expired" → "unknown/garbage state") → `php artisan test --filter=GoogleAuthControllerTest` **RED: 4 failed / 4 passed** (state não é 40-char alfanumérico; callback não decodifica o token do cache; 302 no lugar de 501). Reescrito `app/Http/Controllers/GoogleAuthController.php`: `redirectUrl` gera `Str::random(40)` + `Cache::put('google_oauth_state:<token>', ['intent'=>'link','user_id'=>...], now()->addMinutes(5))`; `callback` usa `pullState()` → `Cache::pull` (uso único); removidos `decodeState()` e o import de `Crypt`. → **GREEN: 8 passed (23 assertions)**. `./vendor/bin/pint --test` nos 2 arquivos → PASS. `php artisan test` (suíte) → 15 failed (pré-existentes, ver §1) / 244 passed — 0 regressão. | Sem migration, sem `.env`/config, sem mudança de contrato de API. Gate: merge em `dev` (humano) + `security-reviewer` (toca auth). |
