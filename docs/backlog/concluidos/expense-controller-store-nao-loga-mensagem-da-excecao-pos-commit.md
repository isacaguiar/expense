# `ExpenseController` registra `getMessage()` da exceção do passo pós-commit do `store`

ID: 163
Origem: feature `20261124-notifier-nao-loga-sql-com-bindings` (decisão D2-A: o padrão do `Notifier::guard` foi corrigido e este ponto, igual, ficou para item próprio); `backend/app/Http/Controllers/ExpenseController.php:907`
Criado em: 2026-11-24
Prioridade: BAIXA
Status: Concluído

## Descrição
No `store`, cada passo pós-commit roda dentro de um bloco que captura `\Throwable` e faz `Log::warning("store: falha no passo pós-commit ({$step})", ['exception' => $e->getMessage()])` (`ExpenseController.php:907`). É o mesmo padrão do `Notifier::guard` antes da TASK-676: numa `QueryException` a mensagem traz o SQL com os bindings (valores da despesa, nomes de usuário e do grupo), que vão para o `laravel.log` sem necessidade. O contexto usa a chave `exception`, mas com a mensagem, não com a classe.

Sugestão: registrar `$e::class` no lugar de `$e->getMessage()`, mantendo o `step` na mensagem do log, como no `Notifier::guard`. Antes de mexer, conferir se algum teste afirma o conteúdo desse contexto.

## Decisões em aberto
A fechar no specify, se o item for promovido.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | O que registrar | A) só a classe (`$e::class`)<br>B) a classe e o código | **A** | Mesmo critério do `Notifier::guard`: basta para saber o tipo de falha, sem risco de vazamento. |
| D2 | Outros pontos com `getMessage()` no log | A) só este<br>B) varrer `app/` e corrigir todos | **A** agora; B vira item próprio se a varredura achar mais | Escopo mínimo; a varredura é uma busca por `getMessage()` dentro de `Log::`. |

## Por que importa
Higiene de log: dado pessoal e valores financeiros em texto de SQL, sem uso para o diagnóstico. Nenhuma exploração hoje.

Tipo sugerido: backend (sem mudança de contrato nem migration)

## Resolução
Concluído em: 2026-11-24
Feature: docs/feature/concluidas/202611/20261124-expense-store-nao-loga-mensagem-da-excecao/   (a pasta migra para lá quando o PR da feature mergeia em `dev`, ADR-009)
Tasks: TASK-678, TASK-679
PRs: https://github.com/isacaguiar/expense/pull/309
Nota: a varredura da feature gerou o item 165 (demais pontos de log com a mensagem da exceção, o primeiro a tratar é `ExpenseController.php:780`).
