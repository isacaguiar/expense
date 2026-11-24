# Implementation — `ExpenseController::store` não loga a mensagem da exceção pós-commit

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261124

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum até aqui.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-678 | Concluída | 2026-11-24 | Claude | RED: 2 testes novos em `ExpenseControllerStorePaymentTest` (`test_after_commit_logs_the_exception_class_and_not_its_message` e `test_after_commit_does_not_leak_sql_bindings_into_the_log`, chamando `afterCommit` por `ReflectionMethod`, D4-A) falham com "warning called 0 times" (o contexto ainda tinha a mensagem). GREEN: `afterCommit` (`ExpenseController.php`) passa a logar `['exception' => $e::class]` e o docblock explica a razão; os 2 testes passam (2 testes, 2 asserções). Mutantes (backup `n678.bak` e `cmp`): A (`'exception' => $e->getMessage()`) aplicado, 2 erros; B (`$e::class` somado a `'error' => $e->getMessage()`) aplicado, 2 erros (derrubado pelo `array_keys($context) === ['exception']`); arquivo restaurado, `cmp` igual. `./vendor/bin/phpunit tests/Feature/ExpenseControllerStorePaymentTest.php`: OK (49 testes, 237 asserções). `./vendor/bin/pint --test`: PASS, 275 arquivos. | Teste 2 provoca a `QueryException` com `Notification::create` de `user_id` inexistente (FK). |
| TASK-679 | Concluída | 2026-11-24 | Claude | Pontos conferidos no código com `grep -n "getMessage()"` nos 4 arquivos (linhas 116, 132, 180, 228 do `GoogleAuthController`; 97 do `InvitationController`; 254 do `GroupInvitationService`; 288 do `PreRegistrationService`), todos iguais ao registrado. Criado `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` (ID 165, BAIXA, Aberto, tabela dos 7 pontos e decisões D1 a D4, o 164 apontado como mesmo tema) e a linha 165 no `docs/backlog/README.md`. `git diff` sem edição em `docs/sdd/` (D3-A). | Os `getMessage()` de resposta 422 e o de `isDuplicateRequestKey` ficam de fora, explicado no item. |

## 3. Fechamento

| Item | Resultado |
|---|---|
| Suíte cheia do backend (`./vendor/bin/phpunit`, MySQL de teste 3307) | OK: 1746 testes, 9430 asserções, 5 min 04 s |
| `./vendor/bin/pint --test` | PASS, 275 arquivos |
| `security-reviewer` (delta `origin/dev...HEAD -- backend`) | 0 crítico, 0 alto, 1 médio, 1 informativo. `afterCommit` correto; o best-effort foi preservado (5 chamadores sem erro para o cliente). |
| Médio | `ExpenseController.php:780` (`Log::error('Erro ao criar despesa', ['exception' => $e])`) loga a exceção inteira, com SQL e bindings numa `QueryException`: conferido no código, a busca por exceção inteira em log em `backend/app` só acha esse ponto. Fora do escopo (decisão consciente do item 080, D10 da feature de pagamento no cadastro): registrado no item 165 do backlog, MÉDIA, como o primeiro a tratar. |
| Informativo | Testes por reflexão dependem da FK de `ex_notifications.user_id`; se ela mudar, o teste falha (não passa em falso). Sem ação. |
