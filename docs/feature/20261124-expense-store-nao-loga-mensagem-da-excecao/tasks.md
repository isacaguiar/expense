# Tasks — `ExpenseController::store` não loga a mensagem da exceção pós-commit

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261124

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-678 | `ExpenseController::afterCommit` registra só a classe da exceção, sem a mensagem | backend | plan.md §1 e §2 | nenhum | Concluída |
| TASK-679 | Criar o item 165 do backlog (demais pontos com `getMessage()` em log) e a linha no README | doc | plan.md §3 | nenhum | Pendente |

Ordem: 678 primeiro (testes em RED, depois o código); 679 por último. Como a 678 mexe em log de dado pessoal, rodar o `security-reviewer` no fechamento da feature.

## Critérios de aceite

- **TASK-678**: o `Log::warning("store: falha no passo pós-commit ({$step})", ...)` de `afterCommit` passa a ter no contexto só a chave `exception` com a classe da exceção (`$e::class`), sem a mensagem; a mensagem do log com o passo, o `try/catch (\Throwable)` e a exceção sem subir não mudam; o docblock diz que a mensagem fica de fora por causa de SQL com bindings. Em `backend/tests/Feature/ExpenseControllerStorePaymentTest.php`, dois testes novos que chamam `afterCommit` por `ReflectionMethod` (D4-A) com `Log::spy()` e `Log::shouldHaveReceived('warning')->withArgs(...)`: (1) um fechamento que lança `\RuntimeException` com texto sigiloso: contexto com exatamente a chave `exception` igual a `\RuntimeException::class`, sem o texto, e a exceção não sobe; (2) um fechamento que provoca `QueryException` real (violação de FK com nome reconhecível nos bindings): `exception === QueryException::class`, e o `json_encode` do contexto sem o nome nem `insert into`. Mutantes (voltar a `'exception' => $e->getMessage()`; somar a mensagem junto com a classe) derrubados por ao menos um teste cada, aplicados com backup e `cmp`. O teste existente `test_a_failure_in_a_step_after_the_commit_keeps_the_expense_and_the_payment` e o arquivo `ExpenseControllerStorePaymentTest` inteiro continuam verdes; `./vendor/bin/pint --test` limpo.
- **TASK-679**: arquivo `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` (ID 165, BAIXA, Status Aberto, decisões em tabela) listando `GroupInvitationService.php:254`, `InvitationController.php:97`, `PreRegistrationService.php:288` e `GoogleAuthController.php:116, 132, 180, 228`, com o 164 apontado como parte do mesmo tema; linha 165 no `docs/backlog/README.md`; os números de linha conferidos no código antes de escrever; `git diff` sem edição em `docs/sdd/` (D3-A) nem em specify de feature arquivada.
