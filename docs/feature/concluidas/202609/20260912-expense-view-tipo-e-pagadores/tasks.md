# Tasks — Página da despesa: tipo, cronograma de parcelas e pagadores

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.1 · Criado em: 20260912 · Atualizado em: 20260912 (emenda no critério da TASK-277, descoberta na execução)

Numeração a partir de `TASK-276` (maior ID já usado no projeto: `TASK-275`, em `docs/feature/concluidas/202609/20260903-notificacoes-in-app/`). Todas as tasks são de frontend; nenhuma toca o backend (`specify.md` §3.5) e nenhuma tem gate humano próprio — o único gate da feature é o merge do PR único em `dev` (`00-constitution.md` §5.2).

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-276 | Corrigir o rótulo de tipo do chip na página da despesa | frontend | plan.md §1 | nenhum | Integrada na branch da feature |
| TASK-277 | Fixar o fuso `America/Sao_Paulo` na suíte de testes do frontend | frontend | plan.md §5 | nenhum | Integrada na branch da feature |
| TASK-278 | Corrigir o parse de `date_payment` na página da despesa | frontend | plan.md §2, §6 | nenhum | Integrada na branch da feature |
| TASK-279 | Exibir o cronograma de parcelas na página da despesa | frontend | plan.md §2 | nenhum | Integrada na branch da feature |
| TASK-280 | Listar os pagadores com valor individual na página da despesa | frontend | plan.md §3 | nenhum | Integrada na branch da feature |
| TASK-281 | Extrair os tipos duplicados do frontend para `src/types/` | frontend | plan.md §4 | nenhum | Integrada na branch da feature |

Dependências entre tasks (`plan.md` §7): TASK-277 antes de TASK-278 (é o fuso que faz o teste guardar o bug); TASK-278 antes de TASK-279 (é ela que introduz o helper de parse que o cronograma consome). TASK-276, TASK-280 e TASK-281 não dependem de nenhuma — a ordem delas na tabela é só de valor entregue.

## Critérios de aceite

- **TASK-276**: `cd frontend && npx vitest run src/pages/ExpenseView.test.tsx` verde, com casos novos provando que uma despesa `IN_INSTALLMENTS` com `installments: 6` renderiza o chip `Parcelada (6x)` e uma `IN_CASH` renderiza `À Vista`. O caso existente da fixture `FIXED` (`ExpenseView.test.tsx:57-70`, espera `Fixa`) continua passando **sem ser alterado**.

- **TASK-277**: `frontend/vite.config.js` declara `test.env: { TZ: 'America/Sao_Paulo' }`; `cd frontend && npx vitest run` verde na suíte **completa**. Prova de que o fuso pegou: um teste novo assere `new Date('2026-08-01T00:00:00.000000Z').getDate() === 31` (em `America/Sao_Paulo`, meia-noite UTC é 31/07 local) — esse mesmo assert falha em UTC, ou seja, ele só passa se o fuso estiver aplicado. Se algum teste existente quebrar, a task para e o resultado é reportado no checkpoint (`plan.md` §5): consertar teste de outra tela não entra nesta task.
  - **Emenda durante a execução (2026-09-12):** o assert acima é necessário mas **não suficiente**, e isso só apareceu ao executar — a máquina de desenvolvimento está em `America/Bahia`, também `-03`, então `getDate() === 31` passa localmente mesmo sem a config surtir efeito (verificado: `node -e` fora do Vitest já devolvia `31`). O critério passa a exigir também `Intl.DateTimeFormat().resolvedOptions().timeZone === 'America/Sao_Paulo'`, que distingue os três casos (fuso da máquina, UTC do CI, fuso fixado). Verificação executada nos dois estados: com a linha `env:` comentada o teste falha com `Received: "America/Bahia"`; com ela, passa.

- **TASK-278**: caso novo em `ExpenseView.test.tsx` com `date_payment: '2026-08-01T00:00:00.000000Z'` encontra `01/08/2026` na tela (e **não** `31/07/2026`), rodando sob o fuso fixado pela TASK-277. O caso existente com `date_payment: '2026-08-01'` (formato curto) continua verde — o mesmo helper atende os dois formatos (`plan.md` §2).

- **TASK-279**: caso novo com `expense_type: 'IN_INSTALLMENTS'`, `installments: 6` e 6 quotas (a #1 e a #2 com `paid: true`) renderiza as 6 linhas `1/6`…`6/6`, cada uma com o mês em `mm/aaaa`, o valor da parcela, o valor por pessoa (`value_quota / payers.length`) e o chip `Paga`/`Pendente` conforme `paid`; acima delas, `Total da despesa: R$ 1.754,40 em 6x`. Dois casos negativos: `IN_CASH` e `FIXED` **não** renderizam a seção "Parcelas". Um caso de fuso: quota com `date_expected: '2026-05-01T00:00:00.000000Z'` renderiza `05/2026`, não `04/2026`.

- **TASK-280**: caso novo com 2 pagadores (`user_payer_id` igual ao `id` do primeiro) renderiza os dois nomes na seção "Pagadores", cada um com a cota do total (`total_value / 2`), e o `(credor)` aparece **só** na linha de quem é `user_payer_id`.

- **TASK-281**: `frontend/src/types/group.ts` e `frontend/src/types/expense.ts` criados; `grep -rn "^type Group = \|^type GroupOption = \|^type ExpenseType = \|^type GroupMember = " frontend/src --include=*.tsx --include=*.ts` sem nenhuma ocorrência fora de `src/types/` (excluindo `*.test.tsx`, que podem ter fixtures próprias); `cd frontend && npx tsc --noEmit` sem erro e `npx vitest run` verde **sem nenhum arquivo de teste modificado** nesta task — um teste que precise mudar é sinal de que a extração mudou comportamento e passou do escopo (`plan.md` §4).
