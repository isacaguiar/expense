# Plan — Detalhe da despesa: tipo, parcela e valores por pagador

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260904

---

## 1. Backend — 4 campos aditivos no `summary` (specify §3.1, §3.2, §3.4)

Arquivo único: `backend/app/Http/Controllers/ExpenseController.php`.

**`collectCycleEntries()`** passa a propagar `'quotaNumber'` na entry, aproveitando a `Quota` já resolvida em cada um dos três laços:

| Origem | Valor de `quotaNumber` |
|---|---|
| `$direct` (IN_CASH / FIXED do próprio mês) | `$quota?->number` — na prática 1, ou `null` se não há quota |
| `$installmentQuotas` (IN_INSTALLMENTS) | `$quota->number` — **é o dado que interessa** |
| `$fixedCandidates` (ocorrência FIXED projetada/materializada) | `$quota?->number` (sempre 1 quando materializada), `null` quando virtual |

**`computeCycleSummary()`** acrescenta 4 chaves ao item de `expenses`:

```php
'expenseType'       => $entry['expense']->expense_type,
'installmentNumber' => $entry['quotaNumber'],
'installmentsTotal' => $entry['expense']->installments,
'totalValue'        => (float) $entry['expense']->total_value,
```

Decisões:

- **Aditivo, nunca substitutivo** (Constitution §4.1): `isFixed` fica. Ele é consumido pelo filtro e pelo ícone de tipo de `ExpenseManager`, por `Payments` e por testes existentes — trocar por `expenseType` seria mudança breaking num endpoint em produção.
- **`installmentNumber` sem `null` mágico no cliente**: o frontend só usa o par `installmentNumber`/`installmentsTotal` quando `expenseType === 'IN_INSTALLMENTS'`. Para os outros tipos o campo vem preenchido ou `null` sem significado de negócio — não inventar "1/1".
- **Nada de campo por pagador**: `valuePerPerson` já é o valor de cada um (rateio igualitário, `round($value / max($payers->count(), 1), 2)`). Criar `participantDetails[].share` duplicaria a mesma informação e abriria divergência de arredondamento entre dois campos. Ver specify §2.3.
- **`totalValue` vem de `ex_expenses.total_value`**, não de `SUM(value_quota)`. Para `FIXED` o `total_value` é template e pode divergir das quotas materializadas — mas o campo só é exibido para `IN_INSTALLMENTS` (specify §3.2), onde `store()` já valida que a soma das quotas bate com `total_value` (tolerância 0,01).

Teste: `backend/tests/Feature/ExpenseControllerSummaryTest.php`, usando o helper `createExpense()` já existente no arquivo.

## 2. Frontend — modal "Detalhes da despesa" (specify §3.1, §3.2, §3.3)

**`frontend/src/hooks/useGroupCycle.ts`** — os 4 campos entram em `SummaryExpense` como **opcionais**, com o mesmo comentário/justificativa de `payerAvatarUrl?`/`participantDetails?`: ciclo selado é servido do snapshot congelado e não os terá (specify §2.4).

**`frontend/src/pages/ExpenseManager.tsx`**, apenas o bloco do `Dialog` "Detalhes da despesa":

- **Rótulo do tipo**: função pura local `detailTypeLabel(exp)` — `FIXED → 'Fixa'`, `IN_CASH → 'À Vista'`, `IN_INSTALLMENTS → 'Parcelada n/N'` (só monta `n/N` quando os dois números vierem), e fallback `exp.isFixed ? 'Fixa' : 'Variável'` quando `expenseType` for `undefined`. Pura e no topo do arquivo, para ser testável e não inflar o JSX.
- **Total da parcelada**: `Typography` secundária logo abaixo do valor em destaque, só quando `expenseType === 'IN_INSTALLMENTS'` e `totalValue` presente.
- **Credor**: acrescenta o valor do mês (`value`) à linha já existente de avatar + nome.
- **Pagadores**: lista (uma linha por pessoa) com `UserAvatar` + nome + `valuePerPerson`. Fonte preferencial `participantDetails`; fallback para `participants` (só nomes, sem avatar/id) quando ausente. O credor recebe a marcação `(credor)`; os demais aparecem sob o rótulo de devedores.

Decisões:

- **Nada de novo componente**: o padrão "pessoa + valor" já existe em `components/GroupGrossDebtsPanel.tsx` (nome como primário, `R$` como secundário) — o modal segue esse layout em vez de criar um terceiro dialeto visual.
- **Reuso**: `UserAvatar` (`frontend/src/components/UserAvatar.tsx`) e `formatValue`, o helper de moeda local do próprio `ExpenseManager.tsx`. Não extrair `formatMoney` para util compartilhado nesta feature — a duplicação em 8 arquivos é débito conhecido e vira item de backlog, não escopo daqui.
- **Sem chamada de API nova**: tudo vem do `summary` que a tela já carrega via `useGroupCycle`.

Teste: `frontend/src/pages/ExpenseManager.test.tsx`.

## 3. Produção — antecipar um mês em 8658/8659 (specify §3.5)

Entregável: `fix-prod-8658-8659-antecipa-mes.sql` nesta pasta. **Nenhum código.** Mesmo formato do `fix-prod-3878.sql` da feature anterior, que já funcionou: diagnóstico → backup → `UPDATE` em transação com `SELECT` antes/depois → verificação por API → rollback documentado.

Sequência:

| # | Ação | Por quê |
|---|---|---|
| 0 | `SELECT` das quotas de 8658/8659 (`number`, `date_expected`, `paid`, `born_paid`, `paid_at`, `paid_by`, `value_quota`), do `date_payment` das duas despesas, e dos `ex_group_cycle_snapshots` do grupo 3878 entre `2026-05-01` e `2026-09-01` | O estado real manda; se divergir da tabela de specify §2.5, **parar** e reavaliar antes de gravar |
| 1 | Backup em `_bkp_ex_quotas_20260904b`, `_bkp_ex_expenses_20260904b`, `_bkp_ex_group_cycle_snapshots_20260904b` | Rollback sem depender de dump |
| 2 | `date_expected = DATE_SUB(date_expected, INTERVAL 1 MONTH)` nas quotas de 8658/8659 | A antecipação em si; preserva `number` 1..N, quantidade e total |
| 3 | `date_payment = DATE_SUB(date_payment, INTERVAL 1 MONTH)` nas duas despesas | `indexByGroup()` usa mês calendário de `date_payment`; deixar em junho descolaria da 1ª parcela |
| 4 | Na quota que passou a cair em **julho/2026**: `paid = 1`, `born_paid = 1`, `paid_by = 5573`, `paid_at = NOW()` | Era agosto (pendente) e virou julho, mês já pago. `born_paid = 1` é o que evita o settlement fantasma (regra da feature `20260904-parcela-retroativa-contabilizacao`) |
| 5 | `settled_at = NULL` nos ciclos de mai–jul/2026 que o passo 0 mostrar selados | Ciclo selado é servido do snapshot congelado; sem desselar, a parcela de maio não aparece |
| 6 | Verificação **por API** (GET autenticado), não por SQL | É o comportamento observável que interessa: mai/jun/jul com linha `paid: true` e `settlements: []`; ago com pendência cobrável; `focus-cycle` apontando para agosto |
| 7 | Rollback a partir das tabelas do passo 1 | — |

**Gate humano** (Constitution §5.2 — banco compartilhado/produção): a IA escreve e revisa o script; **quem executa é o usuário**, passo a passo, reportando o resultado de cada `SELECT`/`UPDATE`. O resultado confirmado é registrado em `implementation.md`. Nenhuma credencial é digitada pela IA e o cPanel não é aberto por ela.

Ordem em relação ao código: **independente**. As mudanças de TASK-001/002 são aditivas de exibição e não alteram o significado de `date_expected`/`paid`/`born_paid`; o script pode rodar antes ou depois do deploy.

## 4. Ordem de execução

TASK-001 (backend) → TASK-002 (frontend) têm dependência técnica real: o rótulo `Parcelada n/N` e o total da parcelada dependem dos campos novos do `summary`. TASK-002 pode ser escrita contra o contrato acordado, mas só é verificável ponta a ponta depois de TASK-001.

TASK-003 (dados de produção) é **independente das outras duas** — não compartilha arquivo, contrato nem branch de código. Fica por último em `tasks.md` só porque depende de interação com o usuário (execução manual), não porque precise esperar o código.
