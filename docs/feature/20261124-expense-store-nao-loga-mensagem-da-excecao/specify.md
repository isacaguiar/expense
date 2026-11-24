# Specify — `ExpenseController::store` não loga a mensagem da exceção pós-commit

> Feature: o passo pós-commit do `store` (`afterCommit`) deixa de gravar `$e->getMessage()` no log, porque uma `QueryException` traz o SQL com os bindings (valores da despesa, nomes de usuário e do grupo). Origem: item 163 do backlog (`docs/backlog/expense-controller-store-nao-loga-mensagem-da-excecao-pos-commit.md`), registrado na feature `20261124-notifier-nao-loga-sql-com-bindings` (D2-A, TASK-677), que corrigiu o mesmo padrão no `Notifier::guard`.

Versão: 1.0 · Criado em: 20261124

---

## 1. Problema

Depois do commit, o `store` roda passos best-effort (avisos, WhatsApp do comprovante) dentro de `afterCommit`, que captura qualquer `\Throwable`, registra um `Log::warning` e deixa a resposta de sucesso seguir. O registro grava `$e->getMessage()`. Numa `QueryException` essa mensagem inclui o SQL com os valores ligados: valores financeiros da despesa e nomes de pessoas e de grupo vão parar no `laravel.log` sem necessidade. Para diagnosticar basta saber qual passo falhou (já está na mensagem do log) e qual foi a exceção. Nada disso é exploração hoje; é higiene de log, a mesma já feita no `Notifier::guard`.

## 2. Requisitos

Decisões dele (2026-11-24): só o item 163; slug `expense-store-nao-loga-mensagem-da-excecao`.

### 2.1 O log de `afterCommit` não carrega a mensagem da exceção

Conferido em `backend/app/Http/Controllers/ExpenseController.php:902-909`: `afterCommit(string $step, callable $fn): void` captura `\Throwable $e` e chama `Log::warning("store: falha no passo pós-commit ({$step})", ['exception' => $e->getMessage()])`. Há 5 chamadas (linhas 787, 792, 829, 830 e 833: aviso de despesa criada, de parcela já paga, de despesa paga, ao credor e o WhatsApp do comprovante). Nenhum teste afirma hoje o conteúdo desse log (a busca por `falha no passo pós-commit` em `backend/` só acha o código).

- O contexto do log passa a ter `exception` com **a classe da exceção** (`$e::class`), e **não** a mensagem nem o SQL. A mensagem do log, com o nome do passo, não muda.
- O comportamento fora do log não muda: a exceção continua sem subir e a resposta de sucesso do `store` segue como hoje.
- Sem mudança de API, de rota, de contrato, de migration ou de front.

### 2.2 Decisões a aprovar

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar da exceção | A) só a classe (`$e::class`)<br>B) a classe e o código (`$e->getCode()`) | **A** | Mesmo critério do `Notifier::guard`: basta para saber o tipo de falha sem risco de vazamento; o passo já está na mensagem. |
| D2 | Outros pontos com `getMessage()` em log | A) só este<br>B) varrer `app/` e corrigir todos agora | **A**, e os outros pontos viram um item de backlog novo (165) | A varredura achou mais 8 pontos: `WhatsAppNotifier.php:146` (já é o item 164), `GroupInvitationService.php:254`, `InvitationController.php:97`, `PreRegistrationService.php:288` e 4 no `GoogleAuthController.php` (linhas 116, 132, 180, 228). Alguns também logam e-mail, o que pede decisão própria. |
| D3 | Registro da regra no SDD | A) nada novo: a convenção da v1.4 do `06-context-backend.md` já vale<br>B) acrescentar este ponto à convenção | **A** | A convenção já diz "ao registrar uma exceção capturada em log, prefira a classe e o contexto próprio". Só o item 163 e o README do backlog mudam. |

## 3. Fora de escopo desta feature

- Os outros 8 pontos de log com `getMessage()` (D2-A): vão para o item 165 do backlog, que esta feature cria (o 164, do `WhatsAppNotifier`, já existe).
- Mascarar bindings ou reescrever a mensagem de log; qualquer mudança de contrato, de front ou de migration.
