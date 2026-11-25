# Specify — Log sem SQL nos pontos de `QueryException`

> Feature: parar de gravar no log o SQL com bindings que uma `QueryException` carrega, nos três pontos em que isso acontece. Origem: backlog 165, escopo A (só os pontos de `QueryException`), continuação das features 155 e 163.

Versão: 1.0 · Criado em: 20261124

---

## 1. Problema

A mensagem de uma `QueryException` traz o SQL com os bindings (`... values (?, ?) (Joana, 120.50)`). Gravada no log, ela espalha valores de despesa, descrição, nomes e e-mails de usuário em texto livre, sem uso para o diagnóstico (a classe da exceção e o contexto já bastam). O `Notifier::guard` (TASK-676) e o `ExpenseController::afterCommit` (TASK-678) já foram corrigidos. Restam três pontos com o mesmo vazamento, os mais prováveis de disparar. Nenhuma exploração hoje; é higiene de log (`00-constitution.md`, regras de dado pessoal).

## 2. Achados confirmados

Verificados no código real em 2026-11-24.

### 2.1 `ExpenseController::store`, `catch (\Throwable $e)` do commit (linha 780)

`Log::error('Erro ao criar despesa', ['exception' => $e])` grava a exceção inteira: o log do Laravel escreve mensagem e stack trace. Dispara em qualquer falha de gravação no commit; numa `QueryException` a mensagem traz o SQL com os valores da despesa, descrição e nomes. É o ponto mais provável de todos (prioridade MÉDIA no item). O comentário das linhas 778-779 ("a mensagem da exceção fica no log e nunca na resposta", item 080, D10 da feature `20261027-pagamento-no-cadastro-despesa`) foi uma decisão consciente e **precisa ser revista**: a resposta ao cliente continua sem mensagem; o log passa a levar só a classe.

### 2.2 `GoogleAuthController`, vínculo (linha 132)

`catch (QueryException $e)` ao salvar `google_id` e `avatar_url`: `Log::warning(..., ['user_id' => ..., 'exception' => $e->getMessage()])`. A mensagem traz o SQL com os bindings (id do Google, URL do avatar).

### 2.3 `GoogleAuthController`, login (linha 228)

`catch (QueryException $e)` ao criar ou atualizar o usuário: `Log::warning(..., ['exception' => $e->getMessage()])`. A mensagem traz e-mail e nome do usuário Google.

### 2.4 Requisito comum

Nos três pontos o log passa a registrar só a classe da exceção (`$e::class`), mantendo as demais chaves de contexto (`user_id` no 2.2) e a mensagem de log. A resposta ao cliente e o fluxo (redirect de erro, 500 genérico) **não mudam**. Sem mudança de contrato nem migration.

### 2.5 Decisões

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o código (`getCode()`) | **A** | Mesmo critério das features 155 e 163. O `getCode()` (SQLSTATE) é útil, mas a classe já distingue o caso e o código vira outro dado a manter. |
| D2 | O ponto 2.1 | A) classe só (`'exception' => $e::class`)<br>B) manter a exceção inteira só no ambiente local | **A** | B cria ramo por ambiente e teste que não roda em produção. O diagnóstico de produção segue pelo contexto da requisição e pela reprodução. |
| D3 | Teste dos pontos do Google | A) um teste por ponto, forçando a `QueryException` e afirmando que o log não traz o SQL<br>B) só o teste do `ExpenseController` | **A** | Os três pontos têm o mesmo risco; sem teste, voltam por refatoração. |
| D4 | O comentário das linhas 778-779 | A) reescrever dizendo que o log leva só a classe e por quê<br>B) apagar | **A** | Registra a decisão que reverte o item 080/D10, para o próximo leitor não a desfazer. |

## 3. Fora de escopo desta feature

- Os outros 5 pontos do backlog 165 (`GroupInvitationService.php:254`, `InvitationController.php:97`, `PreRegistrationService.php:288`, `GoogleAuthController.php:116` e `:180`): falha de e-mail e do Socialite, sem SQL; o item 165 continua aberto com eles.
- `WhatsAppNotifier.php:143-147` (backlog 164).
- Trocar o e-mail em claro do `InvitationController.php:97` por `user_id` (D3 do item 165).
- Os `getMessage()` devolvidos na resposta 422 de exceções de domínio.
- O item 155 (pontos 1, 3 e 4), 162 e 097.
