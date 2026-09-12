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
| TASK-279 | Pendente | — | — | — | — |
| TASK-280 | Pendente | — | — | — | — |
| TASK-281 | Pendente | — | — | — | — |

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
