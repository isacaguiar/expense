# O corpo do `404` de `Group::findOrFail` confirma a existência do grupo e vaza o nome da classe do model

ID: 098
Origem: docs/feature/concluidas/202610/20261023-members-index-checagem-de-membro/implementation.md §5 (TASK-477, hipótese confirmada por mutação e sonda) e §7; `backend/app/Exceptions/Handler.php`; 17 chamadas de `findOrFail` em `GroupController`, `GroupMemberController` e `ExpenseController`
Criado em: 2026-10-23
Prioridade: MEDIA
Status: Aberto

## Descrição
O backend adota a convenção "`404`, não `403`, para não confirmar a existência do grupo a quem não é membro" (comentário de `GroupMemberController::authorizeMembership`, `Controller::authorizeGroupMembership`). Ela é **anulada pela mensagem do erro**: quase todo endpoint de grupo faz `Group::findOrFail($id)` (os relatórios fazem `Group::find` com mensagem própria) e **depois** a checagem de membro, e os dois caminhos de `404` têm corpos diferentes.

- `findOrFail` lança `ModelNotFoundException`, que o handler padrão do Laravel converte em `NotFoundHttpException` com a mensagem do model: **`No query results for model [App\Models\Group] N`**.
- A checagem de membro usa `abort_unless(…, 404)`, que devolve mensagem **vazia**.

**Medido em 2026-10-23** (sondas descartáveis na TASK-477, `app.debug = false`, mesmo usuário não membro nos dois pedidos):

| Endpoint | Grupo inexistente | Grupo existente, não membro |
|---|---|---|
| `GET /api/groups/{id}` (`GroupController::show`) | `404 "No query results for model [App\\Models\\Group] 2400"` | `404 ""` |
| `GET /api/groups/{id}/expenses/summary` | idem | idem |
| `GET /api/groups/{id}/expenses/gross-debts` | idem | idem |
| `POST /api/groups/{id}/members` (`store`) | idem | idem |

**Inferido por leitura, não medido:** os demais pontos com o mesmo par `findOrFail` + checagem: `GroupController::update` e `destroy`, e em `ExpenseController` os métodos nas linhas 27, 434, 609, 638, 659, 734, 753, 826, 870, 909 e 1289 (em 2026-10-23). Também `findExpenseForMember` (`ExpenseController.php:1530-1531`), que faz `Expense::where('deleted', false)->findOrFail($id)` e depois `Group::findOrFail`: um id de **despesa** inexistente devolveria `[App\Models\Expense]`, e um de outro grupo devolveria mensagem vazia, o que confirmaria a existência de despesas alheias.

**Variante com mensagem escrita à mão (por leitura, não medido):** os dois relatórios (`GroupExpenseReportController::reportByGroupAndYear`, linhas 16-21, e `reportByGroupAndYearMonthlySettlement`, linhas 110-113) **não** usam `findOrFail`: fazem `Group::find` e, para grupo inexistente, devolvem `404 {"message": "Grupo não encontrado"}`, contra a mensagem vazia do `abort_unless` para grupo alheio. Também distinguíveis, por outro motivo, e **o handler global não os corrige**: precisam de ajuste manual nesses dois pontos.

**Já protegido:** `GET /api/groups/{id}/members` (`index`), desde a feature `20261023-members-index-checagem-de-membro` (item 085): ele usa `Group::find` + `abort_if` e tem teste de corpo idêntico (`test_unknown_group_and_foreign_group_are_indistinguishable`).

Consequências:
1. **Qualquer usuário autenticado descobre se um grupo (ou despesa) existe**, varrendo ids sequenciais. É dado de baixa sensibilidade, mas é exatamente o que a convenção `404` quer evitar.
2. **A resposta expõe o nome da classe interna** (`App\Models\Group`) e o id. É vazamento de detalhe de implementação, mesmo com `APP_DEBUG=false`.

Premissa: a igualdade de corpos só vale com `APP_DEBUG=false`, que é o que o `deploy-backend.yml` escreve (linha 36). Com `true` o corpo traz `file`, `line` e `trace` e há vazamento maior, fora do escopo deste item.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Continua `404` (não `403`), como hoje. |
| R2 | Para quem não é membro, **grupo inexistente e grupo alheio** devem ser **indistinguíveis em status e corpo**, em todos os endpoints de grupo. O mesmo vale para despesa inexistente e despesa de outro grupo. |
| R3 | Nada muda para quem **é** membro (status e payload). |
| R4 | A prova é teste de regressão por endpoint comparando os dois corpos, com `app.debug = false`; a **mutação** (desfazer a correção) precisa derrubá-lo. Revisão do `security-reviewer`. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Onde corrigir | A) no handler global: renderizar `ModelNotFoundException` com o **mesmo** corpo do `abort(404)` (`{"message": ""}`), em `App\Exceptions\Handler::register`<br>B) em cada endpoint: trocar `findOrFail` por `find` + `abort_if` (17 pontos)<br>C) Route Model Binding com escopo de membro (refatoração grande) | **A** | Um só lugar, cobre grupo **e** despesa e tudo que for criado depois. **Não cobre os dois relatórios** (mensagem escrita à mão): esses 2 pontos levam o ajuste manual junto. B espalha a mudança por 17 pontos e esquece o próximo `findOrFail` que alguém escrever. C é correto, mas é refatoração de rotas inteira. |
| D2 | Corpo do `404` | A) mensagem vazia, como o `abort(404)` de hoje<br>B) uma mensagem genérica única ("Not Found.") para **todos** os `404` | **A** | Não muda o contrato dos `abort(404)` já existentes e dos testes que os comparam. B uniformiza, mas muda o corpo de rotas que a feature não precisa tocar. |
| D3 | Impacto em quem lê o texto do `404` | A) conferir antes que o frontend não depende do texto `No query results…`<br>B) assumir que não depende | **A** | `data.message` é lido em 6 arquivos do `frontend/src` (`PixPaymentDialog`, `RegisterPage`, `validateRegisterForm`, `AcceptInvitePage`, `GroupForm`, `Dashboard`); **não conferi** se algum usa o texto do `404`. É uma checagem de minutos. |
| D4 | Teste | A) um teste parametrizado (data provider) que percorre todos os endpoints de grupo e de despesa<br>B) um teste por controller | **A** | Quem criar um endpoint novo com `findOrFail` entra na lista e é pego. |

## Por que importa
A convenção de `404` existe para não revelar o que o usuário não pode ver, e hoje ela falha em quase todo endpoint. O custo de corrigir no handler é baixo. O app Flutter (item 074) vai consumir esse contrato; melhor fechar antes.

Relacionado: 085 (fechou o `index` de membros e originou este achado), 080 (`POST /api/expenses` devolve a mensagem da exceção no `500`), 045 (enumeração no pré-cadastro), 091 (oráculo do `GET /pix/generate`).

Tipo sugerido: backend
