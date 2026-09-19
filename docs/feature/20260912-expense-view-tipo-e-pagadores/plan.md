# Plan — Página da despesa: tipo, cronograma de parcelas e pagadores

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20260912 · Atualizado em: 20260912 (§6 decidido no gate: opção (a))

Arquivo central: `frontend/src/pages/ExpenseView.tsx` (§1–§3). Nenhuma mudança no backend (`specify.md` §3.5).

---

## 1. Rótulo de tipo correto no chip (specify §3.1)

- **Trocar o `Record` por função.** `typeLabel` hoje é `Record<ExpenseType, string>` (`ExpenseView.tsx:79-83`) — não dá conta de `IN_INSTALLMENTS`, que precisa de `installments` para montar `Parcelada (6x)`. Vira `typeLabel(expense: ExpenseDetail): string`, no mesmo lugar do arquivo, espelhando `detailTypeLabel()` do modal (`ExpenseManager.tsx:83-94`): `FIXED` → `Fixa`, `IN_CASH` → `À Vista`, `IN_INSTALLMENTS` → `` `Parcelada (${expense.installments}x)` ``.
- **Por que não importar `detailTypeLabel` do `ExpenseManager`.** Ela opera sobre `SummaryExpense` (`specify.md` §2.7): campos camelCase, com a parcela do ciclo já escolhida pelo backend. Importá-la exigiria montar um objeto `SummaryExpense` falso a partir do `ExpenseDetail` e criaria dependência página → página (o `ExpenseManager` é uma página, não um componente compartilhado). O que impede as duas telas de divergirem de novo é o **texto** dos rótulos ser o mesmo, e isso fica fixado por teste nas duas (§5).
- **Sem fallback.** Diferente do modal, `expense_type` vem sempre do `show` (nunca de snapshot congelado), então não há o caso "campo ausente" que obrigou o modal a manter o rótulo antigo.

## 2. Cronograma completo das parcelas (specify §3.2)

- **Tipo.** `ExpenseQuota` (`ExpenseView.tsx:33-38`) ganha `value_quota: string | number` — o cast `decimal:2` do backend (`Quota.php:29-32`) serializa número como **string**, exatamente como o `total_value` já tipado assim em `ExpenseDetail` (`:43`). Todo consumo passa por `Number()`.
- **Parse de data — o ponto de risco desta feature.** `show()` devolve o model cru (`ExpenseController.php:116-123`); ele **não** formata datas. Todo lugar do controller que entrega data ao frontend chama `->toDateString()` explicitamente (`:46`, `:97`, `:1126`), justamente porque a serialização padrão do cast `date` no Laravel 10 é ISO-8601 com hora e `Z`. Nenhum teste do backend fixa esse formato (`ExpenseControllerShowUpdateDestroyTest.php:58-71` só assere estrutura), então a decisão é **não depender de qual dos dois formatos chega**: o helper corta os 10 primeiros caracteres (`'2026-05-10'`) e monta `new Date(ano, mes-1, dia)`.
  - Esse é o padrão já repetido em 7 arquivos do projeto (`ExpenseManager.tsx:61-63`, `GroupSummary.tsx:13-15`, `GroupReports.tsx:11-13`, `CycleDetailPanel.tsx:12-14`, `GroupGrossDebtsPanel.tsx:13-15`, `Payments.tsx:36-38`, `CycleClosingAlert.tsx:18`) e existe por causa do bug do item 013 (`new Date('YYYY-MM-DD')` é meia-noite UTC e cai no dia anterior em fusos negativos — corrigido pela TASK-133). Cortar em 10 caracteres cobre os dois formatos com o mesmo código.
- **O helper é aplicado também em `date_payment`** (`:393`), na mesma task — decisão (a) do §6, já refletida em `specify.md` §2.9/§3.6. Uma linha; o formato exibido continua `dd/mm/aaaa`.
- **Formato do mês: `mm/aaaa`** (`{ month: '2-digit', year: 'numeric' }`). `{ month: 'short' }` em pt-BR renderiza `"mai. de 2026"` — longo e com ponto no meio de uma linha de tabela. `05/2026` é curto, alinha entre linhas e não depende de abreviação de locale.
- **Ordenar por `number`, não por `date_expected`.** `number` é a identidade da parcela e é o que o rótulo `n/N` afirma. As datas podem ter sido deslocadas em massa em produção (foi o que as TASK-003/004 da feature anterior fizeram nas despesas 8658/8659); ordenar pela data deixaria a numeração fora de ordem na tela se alguma data ficar deslocada. Ordenar por `number` mantém `n/N` sempre crescente.
- **Conteúdo de cada linha:** `n/N` · `mm/aaaa` · `R$ value_quota` com o valor por pessoa como texto secundário (`Number(value_quota) / payers.length`, arredondado a 2 casas) · `Chip` `Paga`/`Pendente` com `color="success"`/`"warning"` — mesmos textos e cores do chip de status do modal (`ExpenseManager.tsx:706-710`).
- **Total acima da lista:** `Total da despesa: R$ 1.754,40 em 6x`, mesma frase do modal (`ExpenseManager.tsx:720-725`).
- **Layout: `Box` por linha, não `List`/`Table` do MUI.** O card desta página é composto só de `Box`/`Typography` (`:378-433`) e o modal resolveu a lista de pagadores do mesmo jeito (`renderDetailPayers`). Um `Table` traria cabeçalho e bordas que o card não tem em nenhum outro lugar; `List` traria `ListItem` com paddings próprios. Sem container de scroll: a lista tem `installments` linhas (tipicamente ≤ 12) e a página rola normalmente.
- **Onde entra:** depois do bloco do credor (`:394-399`) e **antes** do link de comprovante (`:400-406`), para o comprovante continuar sendo a última informação antes dos botões.
- **Quando não aparece:** `IN_CASH` (uma quota só — o valor já está no destaque do topo) e `FIXED` (`quotas[]` esparso, `specify.md` §2.6). A condição é `expense.expense_type === 'IN_INSTALLMENTS'`, não "tem mais de uma quota" — uma fixa com 3 meses pagos tem 3 quotas e não é um cronograma.

## 3. Pagadores com valor individual (specify §3.3)

- **Fonte:** `expense.payers` (já no payload, já tipado). Uma linha por pessoa, com `UserAvatar` — componente já importado no arquivo (`:27`) e já usado para o credor (`:397`), então avatar do pagador sai igual ao do credor sem import novo.
- **Valor:** `Number(expense.total_value) / expense.payers.length`, arredondado a 2 casas — a cota da pessoa no total da despesa (`specify.md` §3.3). O valor por parcela fica nas linhas do cronograma (§2), então nenhum número aparece duas vezes com base diferente.
- **`(credor)`** em quem tem `id === expense.user_payer_id`, mesmo texto e mesma hierarquia visual do modal (`ExpenseManager.tsx:338-344`).
- **Por que não reusar `renderDetailPayers`:** além da forma do dado (§1, `specify.md` §2.7), ela depende de `valuePerPerson` e `participantDetails`, que não existem neste payload; e do fallback para snapshot congelado, que aqui não tem razão de existir.
- **Onde entra:** depois do cronograma, antes do comprovante.

## 4. Módulo de tipos compartilhado (specify §3.4)

- **Ancorar o tipo no endpoint, não na união do que as páginas leem.** Descoberta do planejamento: `Dashboard.tsx` e as 5 páginas de entrada consomem **o mesmo** `GET /api/groups` (`Dashboard.tsx:98`, `ExpensesEntry.tsx:26`, `SummaryEntry.tsx:25`, `MembersEntry.tsx:25`, `PaymentsEntry.tsx:25`, `ReportsEntry.tsx:25`) e declaram formas diferentes dele; `GroupForm` e `GroupMembersForm` consomem o mesmo `GET /api/groups/{id}` (`GroupForm.tsx:41`, `GroupMembersForm.tsx:48`) e também divergem. As 4 formas de `specify.md` §2.8 são, portanto, projeções parciais de **dois** payloads, não 4 recursos.
- **Payloads reais:**
  - `GET /api/groups` → `GroupController::index()` (`:12-37`): todas as colunas de `ex_groups` + `creator:id,email` + `members:id,name,email` + `expenses_max_date_payment` (`withMax`) + `cycle_snapshots_exists` (`withExists`).
  - `GET /api/groups/{id}` → `show()` (`:75-81`): todas as colunas + `creator:id,email`.
  - `GET /api/groups/{id}/members` → `GroupMemberController::index()` (`:17-26`): models `User` completos (`$hidden` remove `password`, `remember_token`, `google_id`, `photo_path`), logo `id`, `name`, `email`, `avatar_url`.
- **Arquivos novos:**
  - `frontend/src/types/group.ts` — `Group` (colunas de `ex_groups`: `id`, `name`, `description: string | null`, `create_date`, `created_by: number | null`, `closing_day: number | null`, `deleted: boolean`), `GroupCreator` (`{ id; email }`), `GroupDetail = Group & { creator: GroupCreator | null }`, `GroupListItem = GroupDetail & { members: GroupMemberBasic[]; expenses_max_date_payment: string | null; cycle_snapshots_exists: boolean }`, `GroupMember` (`{ id; name; email; avatar_url: string | null }`), `GroupMemberBasic = Omit<GroupMember, 'avatar_url'>`, `GroupOption = Pick<Group, 'id' | 'name'>`.
  - `frontend/src/types/expense.ts` — `ExpenseType`.
- **Trocas, arquivo por arquivo:** as 5 páginas de entrada e `Dashboard.tsx` passam a importar `GroupListItem` (e `Dashboard` descarta seu `Member`, `:46`); `GroupForm.tsx` e `GroupMembersForm.tsx` importam `GroupDetail` (e `GroupMembersForm` descarta seu `User`, `:23`); `ExpenseForm.tsx` e `ExpenseView.tsx` importam `ExpenseType` e `GroupMember` (`:23`/`:25` e `:31`/`:29`); `GroupShellLayout.tsx:13` e `layouts/group/GroupHeader.tsx:16` importam `GroupOption`; `useGroupCycle.ts` perde `SummaryExpenseType` (`:30`) e usa `ExpenseType` no único ponto que a consumia (`:54`).
- **Duas correções que o tipo real força — e são o ganho da task:**
  - `description` é `nullable` na tabela (`2025_06_07_033033_create_ex_groups_table.php:18`) e todas as páginas a tipam como `string`. Com `string | null`, `GroupForm.tsx:46` (`setDescription(res.data.description)`) para de compilar — corrige com `?? ''`. Hoje, um grupo sem descrição coloca `null` dentro de um `TextField`.
  - `mostActiveGroup<T extends GroupActivity>` (`pages/mostActiveGroup.ts`) continua **intacta**: `GroupListItem` satisfaz a constraint estrutural (`id`, `create_date`, `expenses_max_date_payment`). Essa função é o precedente do projeto para compartilhar forma sem tipo nominal, e não há motivo para mexer nela.
- **Fora:** as projeções que não são duplicação (`Dashboard`/`GroupForm`/`GroupMembersForm` não viram um tipo só com campos opcionais — `specify.md` §3.4/§4), e qualquer `type ...Props` de componente.
- **Prova de que não mudou comportamento:** `npx tsc --noEmit` sem erro + suíte de testes do frontend verde, sem alterar nenhum teste por causa desta task (se um teste precisar mudar, é sinal de que a troca mudou comportamento e a task passou do escopo).

## 5. Testes

`frontend/src/pages/ExpenseView.test.tsx` já existe (fixture `expenseDetail` com uma despesa `FIXED`, axios mockado, `:22-45`). Cada uma das tasks de §1–§3 entra com seu próprio caso, estendendo a fixture com `installments`, `quotas[]` (com `value_quota`) e `payers` com `avatar_url`:

- §1: `IN_INSTALLMENTS` de 6 parcelas renderiza `Parcelada (6x)`; `IN_CASH` renderiza `À Vista`; a fixture `FIXED` atual continua renderizando `Fixa` (o teste `:57-70` já cobre isso e não deve mudar).
- §2: as 6 linhas aparecem com `1/6`…`6/6`, com mês `mm/aaaa`, valor da parcela, valor por pessoa e o chip `Paga`/`Pendente` conforme `paid`; `IN_CASH` e `FIXED` **não** renderizam a seção.
- §2 (fuso): uma quota com `date_expected` em ISO-8601 (`'2026-05-01T00:00:00.000000Z'`) renderiza `05/2026`, não `04/2026`; e `date_payment` em ISO-8601 (`'2026-08-01T00:00:00.000000Z'`) renderiza `01/08/2026`, não `31/07/2026` (`specify.md` §3.6). São os dois testes que travam o bug do item 013 nesta tela.
- §3: cada pagador aparece com nome e cota do total, e só o `user_payer_id` traz `(credor)`.

- §4: nenhum teste novo e nenhum teste alterado (a prova é `tsc` + suíte verde).

Comando: `cd frontend && npx vitest run src/pages/ExpenseView.test.tsx` por task, e `npx vitest run` + `npx tsc --noEmit` antes de integrar (checklist de `04-implementation.md` §1.3).

**Fixar o fuso da suíte — sem isso o teste de regressão de data é teatro.** O CI roda em `ubuntu-latest` (`.github/workflows/ci-frontend.yml`), ou seja em **UTC**, e o bug do item 013 é invisível em UTC: meia-noite UTC cai no mesmo dia, então `new Date('2026-08-01T00:00:00.000000Z')` acerta por acidente. Um teste de regressão para §2/§3.6 passaria em CI com a implementação errada. A config de teste não tem nada de fuso hoje (`frontend/vite.config.js:33-52`); ganha `test.env: { TZ: 'America/Sao_Paulo' }` — o fuso real dos usuários e das máquinas de desenvolvimento, e um fuso negativo, que é a condição do bug.

Risco declarado: isso muda o fuso de **toda** a suíte. Os testes de data existentes já usam o parse por partes (`ExpenseManager.test.tsx:206` assere `16/07/2026` para `'2026-07-16'`) e são indiferentes ao fuso, então a expectativa é suíte verde; se algum teste hoje TZ-sensível quebrar, é achado para reportar no checkpoint da task — não para expandir a task consertando teste alheio.

## 6. Achado do planejamento: a mesma tela já mostra `date_payment` com um dia a menos

`ExpenseView.tsx:393` faz `new Date(expense.date_payment).toLocaleDateString('pt-BR')` — o padrão exato que o item 013 documentou como bug e que os outros 7 arquivos já abandonaram (§2). Como `show()` não formata datas, `date_payment` chega no formato que o cast produz, e em `America/Sao_Paulo` a data exibida no topo do card cai um dia para trás.

Isso é **pré-existente** e não está em nenhum dos dois itens de backlog promovidos. Mas fica a 8 linhas de onde o cronograma entra, e a consequência de deixar como está é específica: a partir desta feature a tela mostra `31/07/2026` no topo e `08/2026` na parcela, com critérios de parse diferentes — e quem vê atribui a inconsistência à feature nova.

**Decisão do usuário no gate deste plano (2026-09-12): opção (a) — corrigir de passagem**, na mesma task do cronograma, usando o helper que ela já introduz. Uma linha, sem mudança de layout nem de formato exibido.

Consequência documental, já aplicada: `specify.md` virou **versão 1.1**, com o achado em §2.9 e o requisito em §3.6. A alternativa descartada era registrar o bug como item novo de `docs/backlog/` e não tocar na linha — rejeitada porque deixaria duas leituras de data diferentes no mesmo card, atribuídas a esta feature.

## 7. Ordem de execução

Há uma dependência real: §1, §2 e §3 mexem no mesmo arquivo e no mesmo bloco de render, então são sequenciais entre si (evita conflito de merge com elas mesmas). §4 não depende de nenhuma delas — `ExpenseView` funciona com os tipos locais e com os importados.

Ordem escolhida: **§1 → §5 (fuso) → §2 (parse) → §2 (cronograma) → §3 → §4**. Critério: entregar primeiro o que resolve o item 039 (o problema visível, razão da feature), deixando o refactor de tipos (item 003, invisível para o usuário) por último — assim, se a execução parar num checkpoint, o que ficou pronto é o que tem valor de produto. Custo aceito: `ExpenseView.tsx` é tocado de novo na §4, para trocar `ExpenseType`/`GroupMember` locais pelos importados.

O fuso da suíte (§5) vem antes das duas tasks de data porque é o que faz o teste delas guardar o bug de fato; o parse (`date_payment`, §2/§6) vem antes do cronograma porque é ele que introduz o helper que o cronograma consome. Fora dessas duas dependências, a ordem é só de valor entregue.

Mapeamento task → seção fica em `tasks.md`: §2 gera **duas** tasks (o helper de parse e o cronograma), porque são entregas verificáveis separadamente e a regra de atomicidade de `03-tasks.md` não admite as duas numa só.
