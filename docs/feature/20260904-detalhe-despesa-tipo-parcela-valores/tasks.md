# Tasks — Detalhe da despesa: tipo, parcela e valores por pagador

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260904

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-001 | Expor tipo, número da parcela, total de parcelas e valor total da despesa no `summary` | backend | plan.md §1 | nenhum | Pendente |
| TASK-002 | Exibir tipo/parcela, total da parcelada e valor por pagador no modal "Detalhes da despesa" | frontend | plan.md §2 | nenhum | Pendente |
| TASK-003 | Antecipar um mês nas parcelas das despesas 8658/8659 em produção | infra | plan.md §3 | antes do deploy/migration em produção | Pendente |
| TASK-004 | Recolocar as parcelas de agosto de 8658/8659 no acerto do grupo 3878 (remover `born_paid`) | infra | — (dado de produção, sem código) | execução em produção | Executada e conferida em 2026-09-06 |
| TASK-005 | Fechar a competência de agosto/2026 do grupo 3878 em produção (lado credor + lado pagador) | infra | — (dado de produção, sem código) | execução em produção | Executada e conferida em 2026-09-06 |

## Critérios de aceite

- **TASK-001**: `GET /api/groups/{id}/expenses/summary?cycles_ago=N` devolve, em cada item de `expenses`, as chaves `expenseType`, `installmentNumber`, `installmentsTotal` e `totalValue`; para uma despesa `IN_INSTALLMENTS` de 3 parcelas consultada em 3 ciclos consecutivos, `installmentNumber` vale 1, 2 e 3 respectivamente. `isFixed` e todas as demais chaves continuam presentes e inalteradas. Verificável por `php artisan test --filter=ExpenseControllerSummaryTest` verde, com os asserts novos.

- **TASK-002**: no modal "Detalhes da despesa" da tela `/groups/{id}/expenses`: (a) uma despesa parcelada mostra o chip `Parcelada n/N` com o `n` do ciclo aberto, uma À Vista mostra `À Vista` e uma Fixa mostra `Fixa`; (b) a parcelada mostra a linha com o total da despesa e a quantidade de parcelas; (c) o credor aparece com o valor do mês e cada pagador aparece em sua própria linha com avatar, nome e o valor individual; (d) uma despesa vinda de ciclo selado (sem os campos novos) continua abrindo o modal sem erro, com o rótulo antigo. Verificável por `npx vitest run src/pages/ExpenseManager.test.tsx` verde e por navegação no app com screenshot.

- **TASK-003**: no app em produção, grupo 3878 — as despesas Adestrador e Construção aparecem como linha "Paga" em maio, junho, julho **e agosto**/2026, sem gerar cobrança para ninguém nesses meses; a primeira pendência real das duas passa a ser setembro; e não existe mais parcela em novembro (8658) nem em outubro (8659). `focus-cycle` deixa de apontar para agosto. Verificável pelas chamadas de API listadas no passo 5 do script e pela conferência do usuário no app. Execução do SQL é do usuário — gate humano.

- **TASK-004**: no app em produção, grupo 3878, competência 01/08–31/08 — na aba "À pagar", cada um dos 5 devedores (Isac, ngaguiar, mateus.davi.10, Natália, gabriel) deve a naumel67 **R$ 139,23 a mais** do que hoje (R$ 48,73 do Adestrador + R$ 90,50 da Construção), incluindo uma linha nova `ngaguiar → naumel67 R$ 139,23` que hoje não existe; os cards do topo (Total R$ 14.004,87 / Pago R$ 4.546,67 / A pagar R$ 9.458,20) permanecem **inalterados**, porque `paid` não muda. Verificável pela tabela de valores esperados do passo 4 do script, conferida linha a linha na tela. Execução do SQL é do usuário — gate humano. O script é `fix-prod-3878-agosto-cobrar-parcelas.sql`.

- **TASK-005**: no app em produção, grupo 3878 — a competência de agosto/2026 aparece com `cycle.settled = true`, `totals.pending = 0`, toda despesa como "Paga" e todos os acertos do mês confirmados (nenhum card "deve pagar" em aberto); `focus-cycle` deixa de apontar para agosto. Verificável pelas queries de conferência dos passos 2/3/6/8 do script e por `GET /api/groups/3878/expenses/summary?cycles_ago=1`. Execução do SQL é do usuário — gate humano. O script é `fix-prod-3878-fechar-agosto.sql`. **Só rodar depois de a TASK-004 estar conferida**: fechar antes congelaria o acerto sem as duas parcelas.