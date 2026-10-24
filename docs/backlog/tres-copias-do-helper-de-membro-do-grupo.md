# Três cópias do helper que confere se o usuário é membro do grupo

ID: 099
Origem: docs/feature/20261023-members-index-checagem-de-membro/specify.md §2.2 e decisão D3 (fora de escopo); `backend/app/Http/Controllers/Controller.php:17`, `GroupController.php:136`, `GroupMemberController.php:96`
Criado em: 2026-10-23
Prioridade: BAIXA
Status: Aberto

## Descrição
A checagem "o usuário autenticado é membro deste grupo, senão `404`" existe em **três cópias** com o mesmo corpo (`$group->members()->where('user_id', auth()->id())->exists()` e `abort_unless($isMember, 404)`):

| Onde | Visibilidade | Quem usa |
|---|---|---|
| `Controller::authorizeGroupMembership` (`Controller.php:17`) | `protected`, herdada | `ExpenseController`, `GroupExpenseReportController` |
| `GroupController::authorizeMembership` (`GroupController.php:136`) | `private` | `show`, `update`, `destroy` do próprio controller |
| `GroupMemberController::authorizeMembership` (`GroupMemberController.php:96`) | `private` | `store` e, desde o item 085, `index` |

As três são idênticas **hoje**. O risco é a divergência: alguém endurece uma (por exemplo, acrescenta a checagem de `deleted`, ou muda o código de resposta) e as outras duas ficam para trás sem aviso. A feature do item 085 enfrentou isso: reusou a cópia privada de `GroupMemberController` (decisão D3 = A) para não tocar `store()` e deixou a unificação para depois.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Refatoração **pura**: nenhum status, corpo nem ordem de consultas muda. |
| R2 | Os testes existentes devem passar **sem edição**: `GroupControllerTest` (não membro em `show`, `update` e `destroy`), `GroupMemberControllerTest` (não membro em `store` e `index`), `ExpenseControllerSummaryTest`, `ExpenseControllerIndexByGroupTest` e os de relatório. São a prova de que nada mudou. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como unificar | A) apagar as duas cópias privadas e chamar o `authorizeGroupMembership` herdado (os dois controllers já estendem `Controller`)<br>B) manter e só documentar | **A** | Mudança mínima e segura: mesmo corpo, mesma herança. Fica uma única regra de acesso a grupo para endurecer. |
| D2 | Junto do item 098 | A) fazer antes, como passo preparatório<br>B) separado | **B** | O 098 muda o **corpo** do `404` e vive no handler; este não. Misturar dificultaria provar que um não mudou comportamento. |

## Por que importa
Manutenção, sem prazo natural: reduz o custo de qualquer mudança futura na regra de acesso a grupo e o risco de uma rota ficar com a versão antiga.

Tipo sugerido: backend
