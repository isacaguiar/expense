# Tasks — Filtros de Credor, Devedores e Status nas Despesas

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261010

IDs a partir de TASK-406: o maior em qualquer branch do repositório é TASK-405 (feature `20261008-rateio-parcelas-no-backend`). Reconfirmar a numeração se outra feature em andamento reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-406 | Criar o helper puro `expenseFilters.ts` (opções de Credor/Devedores e `filterExpenses`) com testes unitários | frontend | plan.md §1 e §4 | nenhum | Concluída |
| TASK-407 | Acrescentar os filtros Credor, Devedores e Status ao `ExpenseManager` usando o helper, com testes de tela | frontend | plan.md §2, §3 e §4 | nenhum | Pendente |
| TASK-408 | Validar os filtros localmente na stack isolada e registrar o resultado | frontend | plan.md §5 | antes do merge (o usuário valida localmente antes do push e do PR) | Pendente |

Dependência: TASK-407 usa o helper de TASK-406; TASK-408 roda sobre a branch da feature já com as duas integradas (plan.md §6). Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: TASK-406 direto em `frontend/20261010-filtros-credor-devedores-status-despesas`; TASK-407 em `…-TASK-407`, integrada na branch da feature com `git merge --no-ff`, sem PR.

## Critérios de aceite

- **TASK-406**:
  - `cd frontend && npx vitest run src/utils/expenseFilters.test.ts` verde, com casos que provam: (a) `debtorsOf` devolve chave `id:<id>` quando há `participantDetails` e `name:<nome>` quando só há `participants`, e **inclui o credor** (R1); (b) `buildCreditorOptions` e `buildDebtorOptions` deduplicam por chave e ordenam em pt-BR (ex.: "Álvaro" antes de "Bruno"); (c) `filterExpenses` com todos os critérios vazios devolve a lista inteira; (d) **OU** dentro de Credor e dentro de Devedores, **E** entre os critérios; (e) Status `paid` e `pending` separam pelo campo `paid`; (f) busca e tipo (`fixed`/`variable`) mantêm o comportamento anterior.
  - `npx tsc --noEmit` sem erro; o arquivo não importa React e não usa `any`.
  - `ExpenseManager.tsx` ainda não foi alterado nesta task.
- **TASK-407**:
  - `cd frontend && npx vitest run src/pages/ExpenseManager.test.tsx` verde, com os 7 casos do `plan.md` §4: Credor (único e múltiplo), Devedores incluindo o credor, Status Pagas/Pendentes/Todas, combinação com a busca, nenhuma chamada extra a `/expenses/summary`, ciclo selado só com `participants` e poda ao trocar de competência. Os testes que já existiam (busca e tipo, `:173-223`) passam **sem edição**.
  - `npm test` (suíte completa) verde e `npx tsc --noEmit` sem erro.
  - Nenhum warning do MUI no console de teste sobre `value` fora das `options` do `Autocomplete` (caso da poda).
  - No `git diff`, `filteredExpenses` delega a `filterExpenses` e não sobra lógica de filtragem inline; os textos do `Autocomplete` estão em português; os dois `ToggleButtonGroup` têm `aria-label` ("Tipo" e "Status").
- **TASK-408**:
  - Roteiro do `plan.md` §5 executado na stack isolada: cada filtro sozinho e combinados; trocar de competência com filtro ativo; largura de 375px sem scroll horizontal; competência fechada/selada; modo cartões e modo tabela.
  - Resultado de cada item do roteiro registrado no `implementation.md`, com o que foi observado e não só "ok".
  - Stack isolada encerrada ao final (processos e banco de validação derrubados, portas liberadas).
  - O usuário confirma por escrito que validou localmente. Sem essa confirmação, **não há push nem PR**.
