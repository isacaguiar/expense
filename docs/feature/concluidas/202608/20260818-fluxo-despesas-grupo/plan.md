# Plan — Fluxo de Despesas do Grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260818

---

## 1. Corrigir parâmetro de rota do `ExpenseManager` (specify §2.2, R2)

- Trocar `useParams<{ groupId: string }>()` por `useParams<{ id: string }>()` em `frontend/src/pages/ExpenseManager.tsx:38`, e renomear os usos internos de `groupId` para `id` (ou desestruturar `{ id: groupId }` para minimizar o diff — decisão de implementação, não muda comportamento). Fix isolado, sem dependência de nenhum outro item — feito primeiro porque desbloqueia testar os outros itens manualmente na mesma tela.

## 2. Endpoint de listagem de despesas por grupo/mês (specify §2.3, §2.5, R3)

- Novo método `ExpenseController::indexByGroup($groupId, Request $request)` em `backend/app/Http/Controllers/ExpenseController.php`, registrado em `backend/routes/api.php` como `GET /groups/{groupId}/expenses` (mesmo prefixo/middleware `jwt.auth` das rotas vizinhas, linha ~37) — método novo, não reaproveita `getMonthlyExpenses` (que continua existindo sem alteração, para não quebrar quem já o consome).
- Query: `Expense::where('group_id', $groupId)->where('deleted', false)->whereYear('date_payment', $request->query('year'))->whereMonth('date_payment', $request->query('month'))->with('payers')->get()` — mesmos parâmetros `year`/`month` que `ExpenseManager.tsx` já envia hoje como query string (`ExpenseManager.tsx:84-87`), sem mudar o formato de chamada do frontend nesse ponto.
- Serialização: mapear cada `Expense` para `{ id, description, date: date_payment, value: total_value, payerName: <nomes de payers() unidos por ", "> }` — bate com o tipo `Expense` já declarado em `ExpenseManager.tsx:29-35`, então a tabela existente (linhas 210-238) não precisa mudar de estrutura, só a origem dos dados.
- **Checagem de membership (regra de `CLAUDE.md` — nenhuma rota nova que expõe dado financeiro sem checar que o usuário pertence ao recurso)**: antes de montar a query, verificar que o usuário autenticado é membro do grupo `$groupId` — devolver `404` (não `403`) se não for, mesmo padrão já usado em `GroupController::authorizeMembership` (evita confirmar a existência de um grupo a quem não é membro). Extraído para `Controller::authorizeGroupMembership`, compartilhado com o item 3. Rotas vizinhas (`getMonthlyExpenses`, `reportByGroupAndYear`) hoje não fazem essa checagem (achado conhecido, não desta feature) — não expandir o fix para elas aqui, mas não repetir a lacuna na rota nova. *(Ajustado na execução da TASK-034 após revisão do agent `security-reviewer` — decisão original do plan previa `403`.)*
- Ajustar `ExpenseManager.tsx:82` para chamar `GET /api/groups/${id}/expenses` (a rota nova, não mais a inexistente que já existia no código) — texto da URL já estava certo no frontend, só faltava o backend existir.

## 3. Alinhar criação de despesa ao payload real (specify §2.4, §2.5, R4)

- `ExpenseManager.tsx` passa a montar o payload validado por `ExpenseController::store` (`backend/app/Http/Controllers/ExpenseController.php:15-31`): `date_payment` (= `newDate` já existente), `description` (= `newDescription`), `expense_type: 'IN_CASH'` (fixo — parcelamento fica fora de escopo, specify §4), `installments: 1` (fixo), `total_value` (= `newValue` convertido, já existe essa conversão em `handleSaveExpense`), `group_id` (= `id` da rota), `user_creator_id` (= usuário autenticado, via novo fetch de `GET /api/me`), `user_payer_id` e `payers: [user_payer_id]` (= seleção do usuário no formulário), `quotas: [{ date_expected: date_payment, number: 1, paid: true, value_quota: total_value }]` (única quota cobrindo o valor total, à vista).
- Novo campo no modal "Nova Despesa": seletor de pagador (`<TextField select>` do MUI, já usado em outros formulários do projeto), populado por `GET /api/groups/${id}/members` (endpoint já existente, `GroupMemberController@index`) — sem esse campo não dá para preencher `user_payer_id`/`payers` com um valor real.
- `POST /api/expenses` (não `/api/groups/{id}/expenses`) é o endpoint correto — `ExpenseManager.tsx:140` muda a URL de destino.
- **Guarda de autorização em `ExpenseController::store` (mesma regra de `CLAUDE.md` citada no item 2)**: hoje esse endpoint aceita qualquer `group_id`/`user_creator_id`/`user_payer_id` no payload sem checar que o usuário autenticado pertence ao grupo informado — como esta feature é o que passa a acionar essa rota de verdade pela primeira vez a partir do frontend, reutilizar `Controller::authorizeGroupMembership` (criado na TASK-034, devolve `404`) e forçar `user_creator_id = auth()->id()` no servidor (ignorar valor vindo do payload) antes do `Expense::create(...)`, para não permitir criar despesa em nome de outro usuário. Escopo mínimo: não adiciona checagem de que `user_payer_id`/`payers` também sejam membros do grupo — fica registrado como ideia futura de backlog, não bloqueia esta feature.

## 4. Rota `/expenses` com seleção/redirect automático de grupo (specify §2.1, R1)

- Nova página `frontend/src/pages/ExpensesEntry.tsx`: no `useEffect`, chama `GET /api/groups` (mesma chamada que `GroupList.tsx:42` já faz); se vier exatamente 1 grupo, `navigate('/groups/{id}/expenses', { replace: true })`; se vier mais de 1, renderiza uma lista simples de grupos (nome + link para `/groups/{id}/expenses`, reaproveitando o estilo de cartão de `GroupList.tsx` sem precisar duplicar toda a tela); se vier 0, mensagem "Você ainda não participa de nenhum grupo.".
- Nova rota em `frontend/src/App.tsx`, dentro do mesmo grupo protegido (`RequireAuth` + `InternalLayout`) das demais: `<Route path="/expenses" element={<ExpensesEntry />} />`.
- Sem mudança em `Navbar.tsx` — o link `to="/expenses"` (linha 22) já aponta pro lugar certo, só faltava a rota existir.

## 6. Schema — migration `FIXED` + coluna de corte de recorrência (specify §R5/R6)

Nova migration `backend/database/migrations/2026_08_18_XXXXXX_add_fixed_type_to_ex_expenses_table.php`, alterando a tabela de `backend/database/migrations/2025_06_12_013849_create_ex_expenses_table.php:20`:

```php
public function up(): void
{
    Schema::table('ex_expenses', function (Blueprint $table) {
        $table->date('fixed_recurrence_ends_at')->nullable()->after('installments');
    });
    // doctrine/dbal não está instalado — Schema::table()->change() não funciona em enum.
    DB::statement("ALTER TABLE ex_expenses MODIFY expense_type ENUM('IN_CASH','IN_INSTALLMENTS','FIXED') NOT NULL");
}

public function down(): void
{
    DB::statement("ALTER TABLE ex_expenses MODIFY expense_type ENUM('IN_CASH','IN_INSTALLMENTS') NOT NULL");
    Schema::table('ex_expenses', function (Blueprint $table) {
        $table->dropColumn('fixed_recurrence_ends_at');
    });
}
```

`fixed_recurrence_ends_at DATE NULLABLE`: "primeiro dia do primeiro mês em que a despesa Fixa deixa de aparecer". `NULL` = ainda recorrendo. Só relevante para `expense_type=FIXED`. `down()` falha se já existir linha `FIXED` (dado não migrável de volta pro enum de 2 valores) — aceitável só para rollback local antes de popular dado de teste.

**Gate**: `MODIFY COLUMN` num enum é "alterar tipo de coluna" pela Constitution (`00-constitution.md` §2.2) — destrutiva, exige aprovação humana explícita antes de rodar em ambiente compartilhado/produção (`00-constitution.md`, tabela de Governança). Rodar localmente é autônomo.

`backend/app/Models/Expense.php:14-25,27-32`: adicionar `'fixed_recurrence_ends_at'` a `$fillable` e cast `'date'`.

## 7. Contrato de API (specify §R5/R6)

### 7.1 `POST /api/expenses` (`ExpenseController::store`, `backend/app/Http/Controllers/ExpenseController.php:43-103`)

- `expense_type` (linha 48): `required|in:IN_CASH,IN_INSTALLMENTS,FIXED`.
- Checagem cruzada nova, antes do `DB::beginTransaction()`: se `expense_type==='FIXED'`, exige `installments===1` e `count(quotas)===1`, senão `422`. Não muda mais nada no método — o loop de criação de quotas (linhas 86-93) já persiste o que vier, sem tratamento especial por tipo.

### 7.2 Endpoint novo — `POST /api/expenses/{expenseId}/stop-recurrence`

Registrado em `backend/routes/api.php`, dentro do grupo `jwt.auth` (perto da linha 38), sem colidir com `Route::apiResource('expenses', ExpenseController::class)` (linha 35 — segmento de rota diferente).

- Payload: `{ year: int, month: int (1-12) }`.
- `ExpenseController::stopRecurrence($expenseId, Request $request)`: `Expense::findOrFail` (404 se não existir) → `Group::findOrFail` + `authorizeGroupMembership` (`Controller.php:17-22`, 404 se não-membro) → `422` se `expense_type !== 'FIXED'` → `422` se `cutoff < date_payment` (mês de criação) → `update(['fixed_recurrence_ends_at' => cutoff])` → `200` com o registro atualizado.
- Reenvio sobrescreve o cutoff anterior (sem bloqueio de segunda chamada).

## 8. `indexByGroup` projeta despesas Fixa (`backend/app/Http/Controllers/ExpenseController.php:14-41`, specify §R6)

Duas queries Eloquent (não `UNION` — precisa de `with('payers')` em ambas pra montar `payerName`) mescladas em PHP:

1. **Direta** (comportamento atual, sem mudança): `where('group_id')->where('deleted',false)->whereYear/whereMonth('date_payment', ...)`.
2. **Fixa projetada** (nova): `where('expense_type','FIXED')->where('deleted',false)->where('date_payment','<', inicioDoMesPedido)->where(fn($q) => $q->whereNull('fixed_recurrence_ends_at')->orWhere('fixed_recurrence_ends_at','>', inicioDoMesPedido))`.

Cada linha projetada usa o mesmo dia do mês de criação (clamp pra meses mais curtos, ex.: dia 31 em fevereiro vira dia 28/29). Resposta ganha `isFixed: boolean` em todo item (direto e projetado) — o frontend usa isso pra mostrar a ação "Remover" só nas despesas Fixa. Resultado ordenado por `date`.

## 9. `getMonthlyExpenses` — decisão: não mexer nesta feature (specify §4)

Não projetar Fixa em `GET /groups/{groupId}/expenses/monthly` (`ExpenseController::getMonthlyExpenses`, linhas 105-121): confirmado por busca em `frontend/src` que **nenhuma tela chama esse endpoint hoje**. O método também agrega todos os meses de uma vez, sem filtro de intervalo — projetar recorrência indefinida ali exigiria um limite arbitrário (decisão de produto não pedida). Registrar como item novo em `docs/backlog/` para quando houver consumidor real.

## 10. Frontend (`frontend/src/pages/ExpenseManager.tsx`, specify §R5/R6/R7)

### 10.1 Tipo de despesa
- Tipo `Expense` (linhas 30-36) ganha `isFixed: boolean`.
- Novo estado `newExpenseType: 'IN_CASH' | 'IN_INSTALLMENTS' | 'FIXED'` (default `IN_CASH`) e `newInstallmentsCount: string`.
- `TextField select` novo "Tipo de despesa" no modal, mesmo padrão do seletor de pagador (linhas 311-323).

### 10.2 Campo de data por tipo
- Campo de data existente (`newDate`) muda de label conforme o tipo: "Data" (à vista) / "Mês de início das parcelas" (parcelada) / "Data de início" (fixa) — mesmo componente, sem novo date-picker. Default já é a data atual.

### 10.3 Participantes da divisão (specify §R7)
- Novo estado `participantIds: number[]`, inicializado com todos os `members` carregados (default: todos marcados) sempre que o modal abre/`members` é populado.
- UI: lista de `Checkbox` (um por membro do grupo, reaproveitando a lista já buscada em `GET /groups/{groupId}/members`), rótulo "Quem participa desta despesa?".
- Validação: bloquear salvar com `participantIds.length === 0`.
- Payload: `payers: participantIds` (substitui o `payers: [newPayerId]` fixo de hoje). `user_payer_id` continua sendo o seletor único existente (quem pagou de fato, pode ou não estar entre os participantes).

### 10.4 Montagem de payload por tipo em `handleSaveExpense` (linhas 147-192)
- **À Vista**: inalterado, exceto `payers` agora vem de `participantIds` (§10.3).
- **Parcelada**: `N = parseInt(newInstallmentsCount)`, validar `N >= 2` inteiro. Cálculo em centavos: `totalCents = Math.round(valueNumber*100)`, `baseCents = Math.floor(totalCents/N)`, resto absorvido pela última parcela. `quotas[i] = { number: i+1, date_expected: addMonths(newDate, i) (clamp fim de mês), paid: false, value_quota: (baseCents + (i===N-1 ? resto : 0))/100 }`. `expense_type:'IN_INSTALLMENTS'`, `installments:N`.
- **Fixa**: `expense_type:'FIXED'`, `installments:1`, `quotas:[{number:1, date_expected:newDate, paid:false, value_quota:valueNumber}]`.

### 10.5 Ação "Remover" em despesas Fixa (specify §R6)
- Célula nova na tabela (linhas 251-280): `IconButton` visível só quando `exp.isFixed`, abre `Dialog` de confirmação com 2 opções ("a partir deste mês" / "a partir do mês que vem").
- Cálculo do `{year,month}` enviado a partir do `currentDate`/`year`/`month` já existentes (linhas 68-69,76-82): "deste mês" = mês atualmente visualizado; "mês que vem" = mês seguinte, mesmo cálculo de `changeMonth`.
- `POST /api/expenses/${exp.id}/stop-recurrence`; sucesso fecha o diálogo e chama `loadExpenses()` (já existe).
- Reaproveita padrões existentes do arquivo (axios direto, token via `localStorage`, `Dialog`/`DialogActions` MUI) — sem introduzir `api.ts` centralizado.

## 11. Ordem de execução (tasks TASK-042 a TASK-052)

Backend antes de frontend nas partes que se comunicam (migration → store → indexByGroup → stop-recurrence, todos independentes entre si depois da migration, podem ser feitos em qualquer ordem — só dependem da coluna existir). Frontend: tipo de despesa (10.1) e participantes (10.3) são independentes entre si e podem ser feitos em paralelo; parcelada (10.4) e fixa (10.4) dependem do seletor de tipo (10.1) existir; remoção (10.5) depende de `isFixed` estar vindo do backend (TASK-045) e de existir pelo menos uma despesa Fixa criável (TASK-050). Testes (backend TASK-046, frontend TASK-052) fecham cada lado depois do respectivo código.

## 5. Ordem de execução (feature original, TASK-033 a TASK-038)

Item 1 (fix do parâmetro) não depende de nada — primeiro, mais simples, desbloqueia validação manual dos demais. Item 2 (endpoint de listagem) precisa existir antes do frontend consumir (parte final do item 2) — backend antes do fetch equivalente. Item 3 (criação) é independente de 1/2 tecnicamente, mas faz mais sentido validar depois que a listagem já funciona (dá pra ver a despesa criada aparecer na tabela). Item 4 (`/expenses` + seleção de grupo) é independente dos demais (usa `GET /api/groups`, já existente) — pode ser feito em paralelo, ordenado por último em `tasks.md` só por ser a mais isolada das quatro (a rota direta `/groups/:id/expenses` já funciona sozinha depois do item 1).
