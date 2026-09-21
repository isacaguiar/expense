# Implementation — Página da despesa: tipo, cronograma de parcelas e pagadores

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260912

Branch da feature: `frontend/20260912-expense-view-tipo-e-pagadores`, criada a partir de `dev` atualizada (`git pull origin dev` trouxe até `47bac5ca8a`, o merge do PR #159). Documentos do SDD commitados em `d5f906a237`.

---

## 1. Desvios do fluxo padrão

Nenhum. Segue `docs/sdd/04-implementation.md` §1: primeira task direto na branch da feature, tasks seguintes em sub-branch com merge local `--no-ff`, um PR único contra `dev` no fim.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-276 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `cd frontend && npx vitest run src/pages/ExpenseView.test.tsx` — **antes** da correção: `Tests 2 failed \| 13 passed (15)`; **depois**: `Tests 15 passed (15)`. `npx tsc --noEmit` — exit 0, sem saída. `npx vitest run` (suíte completa) — `Test Files 37 passed (37)`, `Tests 241 passed (241)` | Commit `a05520279f`. 1ª task da feature → direto na branch da feature, sem sub-branch (`04-implementation.md` §1.1) |
| TASK-277 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `cd frontend && npx vitest run src/suiteTimezone.test.ts` — **antes** da config: `Tests 1 failed \| 1 passed (2)`; **depois**: `Tests 2 passed (2)`. Com a linha `env:` comentada de propósito: falha com `Expected "America/Sao_Paulo" / Received "America/Bahia"`; restaurada: verde. `npx tsc --noEmit` — exit 0. `npx vitest run` (suíte completa, estado final) — `Test Files 38 passed (38)`, `Tests 243 passed (243)` | Commit `109b6d4604`, merge `4df675e639` (`--no-ff`, branch de task descartada). Critério emendado durante a execução — ver abaixo |
| TASK-278 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `php artisan tinker --execute="…(new Expense(['date_payment' => '2026-08-01']))->toJson()…"` — devolveu `{"date_payment":"2026-08-01T00:00:00.000000Z","total_value":"1754.40"}` e `{"date_expected":"2026-05-10T00:00:00.000000Z",…,"value_quota":"292.40"}`. `npx vitest run src/pages/ExpenseView.test.tsx` — **antes**: `Tests 2 failed \| 15 passed (17)`; **depois**: `Tests 17 passed (17)`. `npx tsc --noEmit` — exit 0. `npx vitest run` — `38 passed (38)`, `Tests 245 passed (245)` | Commit `04563730bc`, merge `214acdf1de`. Gerou o item de backlog 040 |
| TASK-279 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `node -e` conferindo os formatos antes de codar: `mm/aaaa` → `05/2026`, `{month:'short'}` → `mai. de 2026`, `292.40/2` → `146,20`. `npx vitest run src/pages/ExpenseView.test.tsx` — **antes**: `Tests 1 failed \| 19 passed (20)`; **depois**: `Tests 20 passed (20)`. `npx tsc --noEmit` — exit 0. `npx vitest run` — `38 passed (38)`, `Tests 248 passed (248)` | Commit `426049ff3d`, merge `5959717e6b` |
| TASK-280 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `npx vitest run src/pages/ExpenseView.test.tsx` — **antes**: `Tests 2 failed \| 20 passed (22)`; **depois**: `Tests 22 passed (22)`. `npx tsc --noEmit` — exit 0. `npx vitest run` — `38 passed (38)`, `Tests 250 passed (250)` | Commit `c06bd88d34`, merge `76e4c5956d`. Fecha o item de backlog 039 em código |
| TASK-281 | Integrada na branch da feature | 2026-09-12 | IA (Claude Opus 5) | `npx tsc --noEmit` — exit 0. Prova de que a correção do `description` era necessária: removendo o `?? ''`, `tsc` reprova com `GroupForm.tsx(38,26): error TS2345: Argument of type 'string \| null' is not assignable to parameter of type 'SetStateAction<string>'`; restaurado, exit 0. `npx vitest run` — `38 passed (38)`, `Tests 250 passed (250)`. `git status --short` — 13 arquivos de código alterados, **nenhum de teste** | Commit `382a9eb539`, merge `17be73da2b` |

### TASK-276 — detalhe

Ordem seguida: os dois casos de teste foram escritos **antes** da correção e rodados para confirmar que falhavam (o `findByText('À Vista')` estourou timeout com o DOM mostrando o chip antigo). Só então `typeLabel` deixou de ser `Record<ExpenseType, string>` e virou função `typeLabel(expense: ExpenseDetail)` — a mudança de forma é necessária porque `Parcelada (Nx)` depende de `expense.installments`, que um `Record` indexado por `expense_type` não alcança.

Arquivos tocados: `frontend/src/pages/ExpenseView.tsx` (`typeLabel` + a chamada no `Chip`) e `frontend/src/pages/ExpenseView.test.tsx` (2 casos novos). O caso existente da fixture `FIXED` (espera `Fixa`) **não** foi alterado, como exigia o critério de aceite.

Checklist de `04-implementation.md` §1.3: critério de aceite verificado por teste; frontend com `tsc --noEmit` limpo e sem `any`; nenhuma migration envolvida; `git diff` revisado antes do commit — 62 inserções, 5 remoções, nenhum segredo.

### TASK-277 — detalhe

Branch de task `frontend/20260912-expense-view-tipo-e-pagadores-TASK-277`, criada a partir da branch da feature, integrada por `git merge --no-ff` e descartada (`04-implementation.md` §1.1/§1.4). Sem PR e sem gate: a branch da feature não é `dev`.

**O critério de aceite aprovado estava insuficiente, e isso só apareceu na execução.** Ele pedia `new Date('2026-08-01T00:00:00.000000Z').getDate() === 31` como prova de que o fuso pegou. Só que a máquina de desenvolvimento está em **`America/Bahia`** — também `-03`, sem horário de verão — então esse assert passa localmente mesmo que a config não tenha efeito nenhum (confirmado antes de escrever o teste: `node -e "…"` fora do Vitest já devolvia `tz: America/Bahia … getDate: 31`). Um critério que passa nos dois estados não prova nada.

Prova adotada: `Intl.DateTimeFormat().resolvedOptions().timeZone === 'America/Sao_Paulo'`, que distingue os três casos possíveis (fuso da máquina, UTC do CI, fuso fixado). Verificado nos dois estados, não por raciocínio: comentando a linha `env:` o teste falha com `Received: "America/Bahia"`; restaurando, passa. `tasks.md` foi emendado (versão 1.1) com esse registro.

Um assert de `process.env.TZ` também foi escrito e **descartado**: `tsc --noEmit` reprova com `TS2591: Cannot find name 'process'` — o `tsconfig` do frontend não inclui `@types/node`. Instalar a dependência para sustentar um assert redundante (o do `Intl` prova mais, porque mostra que `Date`/`Intl` de fato honram a variável) ficaria fora do escopo da task.

Risco declarado em `plan.md` §5 — o fuso fixado vale para **toda** a suíte — não se materializou: 38 arquivos e 243 testes verdes, nenhum teste existente alterado. Fazia sentido: o fuso anterior da máquina tem o mesmo offset, e os testes de data do projeto já usam parse por partes, indiferente a fuso.

Arquivos: `frontend/vite.config.js` (`test.env.TZ`) e `frontend/src/suiteTimezone.test.ts` (novo).

### TASK-278 — detalhe

Branch de task `frontend/20260912-expense-view-tipo-e-pagadores-TASK-278`, integrada por `git merge --no-ff` e descartada.

**A dúvida que `plan.md` §2 deixou aberta ficou resolvida por medição, não por dedução.** O plano dizia que o formato serializado de `date_expected`/`date_payment` não estava fixado por nenhum teste do backend e que o helper seria robusto aos dois formatos. Rodando o cast do model direto (sem banco), o formato é **ISO-8601 com Z**: `"2026-08-01T00:00:00.000000Z"`. Na mesma medição confirmou-se `value_quota` como **string** (`"292.40"`), premissa que a TASK-279 vai consumir.

Consequência para os testes: os **dois** casos novos falhavam antes da correção — inclusive o do formato curto `'2026-08-01'`, porque `new Date('2026-08-01')` também é meia-noite UTC. O bug não dependia do formato ISO; ele já estava lá na forma clássica do item 013.

**Achado fora de escopo, registrado em vez de corrigido:** o mesmo `date_payment` cru alimenta o `<TextField type="date">` do modo de edição (`:192` → `:348`), e `input[type=date]` só aceita `YYYY-MM-DD` — o campo renderiza **vazio**. `specify.md` §4 mantém o modo de edição fora de escopo, então isso virou `docs/backlog/expense-view-edicao-campo-data-vazio.md` (item **040**, MEDIA), com a medição que comprova o formato e a análise de dano (a data é preservada por acaso ao salvar sem tocar no campo; o risco é o usuário preencher o vazio com um palpite). Não foi corrigido aqui para não expandir a task — `04-implementation.md` §1.2.

Arquivos: `frontend/src/pages/ExpenseView.tsx` (`parseLocalDate()` + a chamada na data em destaque) e `frontend/src/pages/ExpenseView.test.tsx` (2 casos novos).

### TASK-279 — detalhe

Branch de task `…-TASK-279`, integrada por `git merge --no-ff` e descartada.

Formatos conferidos com `node -e` **antes** de escrever o teste, para não fixar expectativa errada: `{ month: '2-digit', year: 'numeric' }` dá `05/2026`, e `{ month: 'short' }` em pt-BR dá `mai. de 2026` — confirmando a escolha de `plan.md` §2 por `mm/aaaa`.

Decisões que apareceram só na implementação:

- **Fixture com as quotas fora de ordem** (3, 1, 6, 2, 5, 4), e o teste compara a sequência renderizada com `['1/6' … '6/6']`. Um teste com a fixture já ordenada passaria mesmo sem o `sort()` — não provaria a decisão de `plan.md` §2 de ordenar por `number`.
- **`perPersonValue()` replica o `max($payers->count(), 1)` do backend**, evitando divisão por zero numa despesa sem pagador; o valor sai por parcela, porque a última parcela absorve o arredondamento (`specify.md` §2.5).
- **O `mb` do bloco do credor era condicional** (`proofUrl ? 1 : 3`) — a lógica existente era "aperta o espaço se o link de comprovante vem logo em seguida". Com a seção de parcelas no meio, virou `proofUrl && !showInstallments ? 1 : 3`, e a seção nova herdou o `proofUrl ? 1 : 3`. Intenção visual preservada, em vez de fixar `mb: 3` e mudar o espaçamento das outras despesas de passagem.
- **Os dois testes negativos passam trivialmente antes da implementação** (a seção não existia) — registrado aqui para não dar a impressão de que os três casos estavam vermelhos: só o positivo estava.

Verificação visual no navegador **não** foi feita: exigiria backend local, sessão autenticada e uma despesa parcelada no banco. O critério de aceite aprovado é por teste; os testes cobrem conteúdo e ordem, não layout.

Arquivos: `frontend/src/pages/ExpenseView.tsx` (tipo `ExpenseQuota` com `value_quota`, `formatMonth()`, `perPersonValue()`, seção "Parcelas") e `frontend/src/pages/ExpenseView.test.tsx` (3 casos novos).

### TASK-280 — detalhe

Branch de task `…-TASK-280`, integrada por `git merge --no-ff` e descartada. **Com ela o item de backlog 039 está resolvido em código** (rótulo do tipo, cronograma e pagadores); resta só a TASK-281, que é o item 003.

Decisões da implementação:

- **Asserções escopadas na seção.** "Isac" aparece duas vezes na tela (linha do credor, no topo, e linha de pagador), então os testes usam `within((await screen.findByText('Pagadores')).parentElement)`. Um `getByText('Isac')` global passaria por acidente mesmo se a seção não renderizasse o nome.
- **Segundo caso com 3 pagadores** (`900,00` → `300,00` cada). Com só o caso de 2 pagadores, um bug de divisor fixo (`/ 2`) passaria — e o rateio por número de pagadores é exatamente o que a seção afirma.
- **Nome e `(credor)` no mesmo `Typography`**, com o marcador num `component="span"` — igual ao modal. Isso é o que permite `getByText('Isac')` continuar casando (a testing-library junta só os nós de texto **diretos** do elemento) enquanto `(credor)` é consultável à parte.
- **O `mb` condicional do bloco do credor virou fixo (`mb: 3`).** A seção de pagadores sempre vem depois dele, então o `proofUrl ? 1 : 3` ali não tinha mais sentido; o espaçamento apertado antes do link de comprovante migrou para a seção de pagadores, que agora é a última antes dele. A intenção visual original ("aperta o espaço se o comprovante vem logo a seguir") ficou preservada, só mudou de elemento.
- **Sem estado vazio para `payers`:** a API valida `payers` com `required|array|min:1` no `store()` e no `update()`, então lista vazia é inalcançável — não inventei um ramo de UI (nem um teste) para um estado que o backend não produz. `perPersonValue()` já protege a divisão.

Arquivos: `frontend/src/pages/ExpenseView.tsx` (seção "Pagadores" + ajuste dos `mb`) e `frontend/src/pages/ExpenseView.test.tsx` (2 casos novos, `within` importado).

### TASK-281 — detalhe

Branch de task `…-TASK-281`, integrada por `git merge --no-ff` e descartada.

**O desenho de `plan.md` §4 se confirmou ao inspecionar os consumidores.** `Dashboard` e as 5 páginas de entrada chamam o **mesmo** `GET /api/groups` (`Dashboard.tsx:98`, `ExpensesEntry.tsx:26`, e as outras quatro), e `GroupForm` e `GroupMembersForm` chamam o **mesmo** `GET /api/groups/{id}`. As 4 formas de `specify.md` §2.8 eram projeções parciais de dois payloads, não 4 recursos — daí `GroupListItem` / `GroupDetail`, tipados pelo endpoint.

**A correção do `description` era real, e verifiquei em vez de assumir.** Com `description: string | null` (o que a tabela realmente permite — `2025_06_07_033033_create_ex_groups_table.php:18`), removi o `?? ''` de propósito e rodei `tsc`: `GroupForm.tsx(38,26): error TS2345: Argument of type 'string | null' is not assignable to parameter of type 'SetStateAction<string>'`. Ou seja, em grupo sem descrição a tela colocava `null` dentro de um `TextField`. Restaurado o `?? ''`, exit 0.

**Execução por script com asserção, não por sed cego.** As trocas em 13 arquivos foram feitas por um script que exige que cada bloco literal apareça exatamente uma vez e falha alto caso contrário (`scratchpad/swap_types.py`, fora do repo) — a alternativa (substituição textual silenciosa em 13 arquivos) esconderia um bloco que não casou. O único trecho por regex foi `\bGroup\b` no `Dashboard`, conferido no diff: `GroupGrossDebtsPanel` e `AvatarGroup` ficaram intactos, como o `\b` garante.

`mostActiveGroup<T extends GroupActivity>` (`pages/mostActiveGroup.ts`) **não foi tocada**: `GroupListItem` satisfaz a constraint estrutural. Ela é o precedente do projeto para compartilhar forma sem tipo nominal.

Arquivos novos: `frontend/src/types/group.ts`, `frontend/src/types/expense.ts`. Alterados: `Dashboard`, as 5 `*Entry`, `GroupForm`, `GroupMembersForm`, `ExpenseForm`, `ExpenseView`, `GroupShellLayout`, `layouts/group/GroupHeader`, `hooks/useGroupCycle`.

## 3. Checklist final na branch da feature integrada (`04-implementation.md` §1.5)

Rodado com as 6 tasks já integradas, para pegar problema de integração entre elas:

| Verificação | Comando | Resultado |
|---|---|---|
| Type-check | `cd frontend && npx tsc --noEmit` | exit 0, sem saída |
| Suíte completa | `cd frontend && npx vitest run` | `Test Files 38 passed (38)`, `Tests 250 passed (250)` |
| Build de produção | `npx vite build --outDir <scratchpad>/build-check --emptyOutDir` | `✓ built in 6.12s`, exit 0 |
| Segredos no diff | `git diff` revisado task a task | nenhum |

O build foi direcionado para fora do repositório de propósito: `frontend/dist/` é versionado (item de backlog **037**), e um `npm run build` normal sujaria arquivos rastreados no meio da feature. O aviso de chunk > 500 kB é pré-existente e não tem relação com estas tasks.

## 4. Gates pendentes

- **Push da branch e PR único contra `dev`**: não feitos — aguardando decisão humana (a branch só existe local).
- **Merge do PR em `dev`**: gate humano por feature (`00-constitution.md` §5.2).
- **Promoção `dev` → `main`** (que dispara deploy): passo à parte, só depois de validado em `dev`.
