# `00-constitution.md` §5 item 3 cita `Log::debug` de credenciais que não existe mais no código

ID: 059
Origem: docs/feature/20260922-email-verificado-obrigatorio/ (achado do `security-reviewer` na TASK-340)
Criado em: 2026-09-22
Prioridade: BAIXA
Status: Aberto

## Descrição

`docs/sdd/00-constitution.md:92` lista como achado pendente: "`AuthController::login` loga `$credentials` inteiro via `Log::debug`, incluindo a senha em claro no log". O `AuthController.php` atual (revisado na TASK-340 desta feature) não tem mais nenhum `Log::debug` nem log de `$credentials` inteiro — só `Log::warning`/`Log::info` com `['email' => ...]`, já coberto por `AuthControllerLoginLogTest`. O item parece ter sido corrigido em algum commit anterior sem que a Constitution fosse atualizada.

## Atualização (2026-10-23, feature `20261023-members-index-checagem-de-membro`, TASK-480)
Ao revisar o item 085 conferi o resto do mesmo §5 item 3 da Constitution e o `01-specify.md`. **Outros cinco trechos descrevem como abertos achados já resolvidos**; a correção é a mesma (atualizar o documento), então este item os absorve em vez de abrir outro:

| Onde | O que diz | O que é hoje (verificado em 2026-10-23) |
|---|---|---|
| `00-constitution.md:90` (§5 item 3) | `GET /pix/generate` está **fora** do grupo `jwt.auth` | Está **dentro** (`backend/routes/api.php:46`) e `PixControllerTest::test_generate_pix_requires_authentication` espera `401` |
| `00-constitution.md:91` | `GroupController@show/update/destroy` não verificam se o usuário é membro | Verificam (`GroupController.php:78`, `:86`, `:109`), com os testes `test_non_member_cannot_view_group`, `test_non_member_cannot_update_group` e `test_non_member_cannot_delete_group` |
| `00-constitution.md:23` (§2 item 4) | `GET/PUT/PATCH/DELETE /expenses/{id}` apontam para métodos que não existem | `show`, `update` e `destroy` existem (`ExpenseController.php:111`, `:155`, `:360`). **Só `GET /api/expenses` (`index`) segue sem método**: medido `500 "Server Error"` em 2026-10-23, e a TASK-016 do `03-tasks.md` o cobre. `DELETE /groups/{id}/members/{userId}` continua sem `destroy` (item 086) |
| `01-specify.md:52` (§3.3) | `show/update/destroy` de grupo não verificam membro | O mesmo do `:91` acima |
| `01-specify.md:58` (§3.4) | `index`, `show`, `update` e `destroy` de `expense` não estão implementados | Só o `index` falta |

Editar a Constitution é **gate humano** (`00-constitution.md` §5.2); o `01-specify.md` é o baseline. Ao promover o item, decidir se o texto é atualizado no lugar ou ganha uma nota de data.

## Por que importa

A Constitution é documento normativo vivo (não um snapshot arquivado) — um item de "achado que exige decisão humana" que já foi resolvido, mas continua listado como pendente, pode levar alguém a re-investigar ou re-priorizar um problema que não existe mais.

Tipo sugerido: doc
