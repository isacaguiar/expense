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
- **Checagem de membership (regra de `CLAUDE.md` — nenhuma rota nova que expõe dado financeiro sem checar que o usuário pertence ao recurso)**: antes de montar a query, verificar `auth('api')->user()` é membro do grupo `$groupId` (`Group::findOrFail($groupId)->members()->where('user_id', auth('api')->id())->exists()`, mesmo padrão de dado já usado em `GroupMemberController@store:63`) — devolver `403` se não for. Rotas vizinhas (`getMonthlyExpenses`, `reportByGroupAndYear`) hoje não fazem essa checagem (achado conhecido, não desta feature) — não expandir o fix para elas aqui, mas não repetir a lacuna na rota nova.
- Ajustar `ExpenseManager.tsx:82` para chamar `GET /api/groups/${id}/expenses` (a rota nova, não mais a inexistente que já existia no código) — texto da URL já estava certo no frontend, só faltava o backend existir.

## 3. Alinhar criação de despesa ao payload real (specify §2.4, §2.5, R4)

- `ExpenseManager.tsx` passa a montar o payload validado por `ExpenseController::store` (`backend/app/Http/Controllers/ExpenseController.php:15-31`): `date_payment` (= `newDate` já existente), `description` (= `newDescription`), `expense_type: 'IN_CASH'` (fixo — parcelamento fica fora de escopo, specify §4), `installments: 1` (fixo), `total_value` (= `newValue` convertido, já existe essa conversão em `handleSaveExpense`), `group_id` (= `id` da rota), `user_creator_id` (= usuário autenticado, via novo fetch de `GET /api/me`), `user_payer_id` e `payers: [user_payer_id]` (= seleção do usuário no formulário), `quotas: [{ date_expected: date_payment, number: 1, paid: true, value_quota: total_value }]` (única quota cobrindo o valor total, à vista).
- Novo campo no modal "Nova Despesa": seletor de pagador (`<TextField select>` do MUI, já usado em outros formulários do projeto), populado por `GET /api/groups/${id}/members` (endpoint já existente, `GroupMemberController@index`) — sem esse campo não dá para preencher `user_payer_id`/`payers` com um valor real.
- `POST /api/expenses` (não `/api/groups/{id}/expenses`) é o endpoint correto — `ExpenseManager.tsx:140` muda a URL de destino.
- **Guarda de autorização em `ExpenseController::store` (mesma regra de `CLAUDE.md` citada no item 2)**: hoje esse endpoint aceita qualquer `group_id`/`user_creator_id`/`user_payer_id` no payload sem checar que o usuário autenticado pertence ao grupo informado — como esta feature é o que passa a acionar essa rota de verdade pela primeira vez a partir do frontend, adicionar a mesma checagem de membership do item 2 (`403` se o usuário autenticado não for membro de `group_id`) e forçar `user_creator_id = auth('api')->id()` no servidor (ignorar valor vindo do payload) antes do `Expense::create(...)`, para não permitir criar despesa em nome de outro usuário. Escopo mínimo: não adiciona checagem de que `user_payer_id`/`payers` também sejam membros do grupo — fica registrado como ideia futura de backlog, não bloqueia esta feature.

## 4. Rota `/expenses` com seleção/redirect automático de grupo (specify §2.1, R1)

- Nova página `frontend/src/pages/ExpensesEntry.tsx`: no `useEffect`, chama `GET /api/groups` (mesma chamada que `GroupList.tsx:42` já faz); se vier exatamente 1 grupo, `navigate('/groups/{id}/expenses', { replace: true })`; se vier mais de 1, renderiza uma lista simples de grupos (nome + link para `/groups/{id}/expenses`, reaproveitando o estilo de cartão de `GroupList.tsx` sem precisar duplicar toda a tela); se vier 0, mensagem "Você ainda não participa de nenhum grupo.".
- Nova rota em `frontend/src/App.tsx`, dentro do mesmo grupo protegido (`RequireAuth` + `InternalLayout`) das demais: `<Route path="/expenses" element={<ExpensesEntry />} />`.
- Sem mudança em `Navbar.tsx` — o link `to="/expenses"` (linha 22) já aponta pro lugar certo, só faltava a rota existir.

## 5. Ordem de execução

Item 1 (fix do parâmetro) não depende de nada — primeiro, mais simples, desbloqueia validação manual dos demais. Item 2 (endpoint de listagem) precisa existir antes do frontend consumir (parte final do item 2) — backend antes do fetch equivalente. Item 3 (criação) é independente de 1/2 tecnicamente, mas faz mais sentido validar depois que a listagem já funciona (dá pra ver a despesa criada aparecer na tabela). Item 4 (`/expenses` + seleção de grupo) é independente dos demais (usa `GET /api/groups`, já existente) — pode ser feito em paralelo, ordenado por último em `tasks.md` só por ser a mais isolada das quatro (a rota direta `/groups/:id/expenses` já funciona sozinha depois do item 1).
