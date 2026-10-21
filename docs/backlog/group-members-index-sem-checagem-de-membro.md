# `GET /api/groups/{groupId}/members` não confere se quem chama é membro do grupo

ID: 082
Origem: docs/feature/20261010-painel-financeiro-pessoal/implementation.md §8 (achado X1 da revisão de segurança da TASK-414, fora do diff revisado); `backend/app/Http/Controllers/GroupMemberController.php` (`index()`); `backend/routes/api.php:55`
Criado em: 2026-10-21
Prioridade: ALTA
Status: Aberto

## Descrição
`GroupMemberController::index()` faz só `Group::with('members')->findOrFail($groupId)` e devolve `$group->members`. Não há `authorizeMembership`, que o `store()` da mesma classe já usa. Qualquer usuário autenticado recebe `200` com os dados de cada membro de **qualquer** grupo existente: `name`, `email`, `pix`, `whatsapp`, `role`, `invited_by` e demais campos do model `User` que não estão em `$hidden`.

Reproduzido em 2026-10-14 com uma sonda descartável (já removida, nada versionado): um usuário que não é membro do grupo `G` chama `GET /api/groups/{G}/members` com o próprio token e recebe `200` com o e-mail, o Pix e o WhatsApp de todos os membros. O `id` do grupo é numérico e sequencial, então enumerar é trivial. Um `id` que não existe devolve `404` e um que existe devolve `200`, o que também confirma a existência de grupos a quem não pertence a eles.

A lacuna é só no `index`. O `store()` já responde `404` a não membro e o `GroupController` foi corrigido na feature `20260817-seguranca-api`. Não há teste de `index` em `GroupMemberControllerTest.php` (só os três de `store`).

Os quatro consumidores do `index` no web (`ExpenseForm`, `GroupMembersForm`, `Payments`, `ExpenseView`) só o chamam em telas de um grupo em que o usuário já navegou como membro; conferir na Triagem que nenhum chama como não membro (por exemplo, a tela de convite).

Caminho provável: é defeito em comportamento existente. A Triagem do `/novo-bug` marca o gatilho 1 (autorização e dado sensível) e **escala para feature** (`docs/bugfix/README.md`), com `security-reviewer` na rota.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Mesma convenção do `store()`: `authorizeMembership` e **404** (não 403) para não confirmar a existência do grupo a quem não é membro. |
| R2 | O formato do payload para quem é membro **não muda** (Constitution §4.1: contrato aditivo; o web e o futuro app consomem). Só muda quem recebe. |
| R3 | Teste de regressão: não membro recebe 404; membro recebe 200 com a lista; usuário que saiu do grupo deixa de receber. Mutação: remover a checagem derruba o teste do não membro. |

## Decisões em aberto
A fechar na Triagem/Specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Grupo excluído logicamente (`deleted = 1`) no `index` | A) 404, como o `store()`<br>B) segue devolvendo aos membros | **A** | Mesma regra de escrita e leitura: o grupo excluído some para a API. Confirmar que nenhuma tela lista participantes de grupo excluído. |
| D2 | Campos devolvidos a quem é membro | A) manter como hoje (inclui `email`, `pix`, `whatsapp`)<br>B) devolver só o necessário a cada tela | **A** | B muda contrato e exige varrer os quatro consumidores. A minimização pode vir depois, junto do S6 (e-mail e Pix do credor no `/me/overview`). |

## Por que importa
É vazamento de dado pessoal e de dado de cobrança (Pix) entre contas, com a exploração mais simples possível: um token válido e um `id` de grupo. O risco cresce com a base de usuários, e o backend já está em produção. Enquanto este item estiver aberto, qualquer pessoa cadastrada pode montar uma lista de e-mails, Pix e WhatsApp dos participantes de todos os grupos.

Relacionado: 083 (inclusão de membro sem consentimento e sem como sair), que agrava a exposição, e 060 (log do `User` inteiro em `GroupController::store`).

Tipo sugerido: backend
