# Plan — Log sem SQL nos pontos de `QueryException`

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261124

---

## 1. `ExpenseController::store`, log do commit (specify §2.1, D1-A, D2-A, D4-A)

- **Onde:** `backend/app/Http/Controllers/ExpenseController.php:778-780`, no `catch (\Throwable $e)` do commit. Troca `Log::error('Erro ao criar despesa', ['exception' => $e])` por `Log::error('Erro ao criar despesa', ['exception' => $e::class])`. A mensagem do log, o `rollBack`, o `ProofStorage::delete`, o tratamento de chave duplicada e a resposta 500 genérica ficam como estão.
- **Comentário (D4-A):** reescrever as linhas 778-779 para dizer que o log leva só a classe da exceção, porque a mensagem de uma `QueryException` traz o SQL com os bindings (valores, descrição, nomes), e que a resposta continua sem mensagem. Cita que isso reverte a premissa do item 080/D10 (feature `20261027-pagamento-no-cadastro-despesa`).
- **Por que assim e não outra:** mesmo formato do `Notifier::guard` (TASK-676) e do `afterCommit` (TASK-678). Manter a exceção inteira só no ambiente local (opção B da D2) criaria ramo por ambiente e um teste que não roda em produção. O stack trace perdido não faz falta: a classe, a mensagem do log e o contexto da requisição bastam para reproduzir.

## 2. `GoogleAuthController`, vínculo e login (specify §2.2, §2.3, D1-A)

- **Onde:** `backend/app/Http/Controllers/GoogleAuthController.php`.
  - Linha 132 (`catch (QueryException $e)` do vínculo): `'exception' => $e->getMessage()` vira `'exception' => $e::class`, mantendo `'user_id' => $user->id`.
  - Linha 228 (`catch (QueryException $e)` do login): `'exception' => $e->getMessage()` vira `'exception' => $e::class`.
  - Mensagens de log (`[google-link] save falhou ...`, `[google-login] save falhou ...`) e os redirects de erro não mudam.
- **Fora daqui:** as linhas 116 e 180 (falha do Socialite, sem SQL) ficam no backlog 165.

## 3. Testes (specify §2.1 a §2.3, D3-A)

- **Como forçar a falha, sem banco:** o teste lança uma `QueryException` montada à mão, `new QueryException('mysql', 'insert into ex_users (name) values (?)', ['Joana Sigilosa'], new \Exception('falha forçada'))`, cuja mensagem já traz o SQL com o binding. Isso é determinístico e não depende de FK ou índice. O lançamento vem de um listener de modelo: `Event::listen('eloquent.creating: '.Quota::class, ...)` no `store` (o mesmo gancho do teste existente `test_a_database_failure_after_the_file_was_written_rolls_back_removes_the_file_and_hides_details`) e `Event::listen('eloquent.saving: '.User::class, ...)` nos dois pontos do Google (registrado depois de criar o usuário da factory, no vínculo).
- **Afirmações comuns** (com `Log::spy()` e `Log::shouldHaveReceived(<nível>)->withArgs(...)`): o contexto tem a chave `exception` igual a `QueryException::class`; `json_encode` do contexto não contém `Joana Sigilosa` nem `insert into`; a resposta/redirect é o de sempre.
- **Onde:**
  - `store`: teste novo em `backend/tests/Feature/ExpenseControllerStorePaymentTest.php`, ao lado do teste existente do 500, que já afirma `Log::shouldHaveReceived('error')->once()` sem olhar o conteúdo (continua válido).
  - Google: dois testes novos em `backend/tests/Feature/GoogleAuthControllerTest.php`, um para o vínculo (afirma também `user_id` no contexto e o redirect `linked=error`) e um para o login (redirect `?google_error=1`). Reaproveitam `linkState`, `loginState` e `fakeGoogleUser`.
- **Mutantes (como na 676 e 678):** (A) voltar a `'exception' => $e` / `$e->getMessage()` no ponto; (B) `$e::class` somado à mensagem. Cada ponto precisa derrubar ao menos um teste; aplicar com backup e `cmp`, conferindo "mutante aplicado".
- **Verificação:** os testes novos em RED antes do código, depois `ExpenseControllerStorePaymentTest` e `GoogleAuthControllerTest` inteiros, a suíte cheia e `./vendor/bin/pint --test`.

## 4. Backlog 165 (specify §3)

- **Onde:** `docs/backlog/log-sem-mensagem-da-excecao-nos-demais-pontos.md` e a linha no `docs/backlog/README.md`. O item continua **aberto**: tira da tabela os 3 pontos resolvidos (780, 132, 228), mantém os 5 restantes (254, 97, 288, 116, 180) e registra a feature como origem da redução. Os pontos já resolvidos ficam citados numa nota curta, para o histórico.
- O item não vai para `concluidos/` (só fecha quando os 5 restantes saírem). Na aprovação das tasks ele passa a "Parcialmente promovido para TASK-0xx" em vez de "Promovido".

## 5. Ordem de execução

Uma task de código por área, cada uma com teste em RED primeiro: (1) `ExpenseController` (seção 1 e o teste do `store`), (2) `GoogleAuthController` (seção 2 e os dois testes). Depois o fechamento com a suíte cheia e os dois checkers, e por último o backlog (seção 4), código antes de doc. Sem dependência entre as duas de código; a do `store` vem primeiro por ser a MÉDIA.
