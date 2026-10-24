# Plan — Checagem de membro na lista de membros do grupo

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261023

---

## 1. specify §2.1, §2.2 e regras R1, R3, R9 — `index()` passa a autorizar

- **Onde:** `backend/app/Http/Controllers/GroupMemberController.php`, método `index()` (linhas 17-26). Nenhum outro arquivo de produção muda.
- **O que muda:** o método deixa de usar `Group::with('members')->findOrFail($groupId)` e passa a:
  1. buscar o grupo **sem lançar exceção** (`Group::find($groupId)`);
  2. responder `404` se ele não existe (`abort_if($group === null, 404)`);
  3. chamar o `$this->authorizeMembership($group)` que a classe já tem (R9, decisão D3 = A);
  4. devolver `response()->json($group->members)`, **o mesmo payload de hoje** (R3, D2 = A).
- **Por que `find` e não `findOrFail`:** é o que permite a R2 (§2 abaixo). Com `findOrFail`, o grupo inexistente cai no handler de `ModelNotFoundException` e o grupo alheio cai no `abort_unless` do helper: são dois caminhos de erro diferentes. Com `find` + `abort_if`, os dois saem do **mesmo** `abort(404)`.
- **Por que reusar o helper existente e não o `with('members')` com checagem em memória** (`$group->members->contains(...)`): custaria uma consulta a menos, mas duplicaria a regra de acesso em vez de usar a que o `store()` já usa, e a decisão D3 foi usar o helper. Custo: 3 consultas por chamada (grupo, `exists`, membros) em vez de 2. Rota de baixo volume (4 telas, uma chamada por abertura); sem otimização.
- **Por que não `403`:** convenção do `store()` e de todo o backend (R1): `404` para não confirmar a existência do grupo.
- **O que não muda:** o `store()`, as rotas (`routes/api.php:54-58`), o `Controller::authorizeGroupMembership` herdado e a cópia de `GroupController`. Não há comentário a limpar além do bloco morto (`// $group = ...`, linhas 19-22), que sai junto, porque o método é reescrito.

## 2. specify R2 — grupo inexistente e grupo alheio indistinguíveis

- **Hipótese a provar, não presumir:** no handler padrão do Laravel, `findOrFail` vira um `NotFoundHttpException` com a mensagem do model (`No query results for model [App\Models\Group] N`), enquanto `abort(404)` vira `404` com mensagem vazia. Se for assim, mesmo uma checagem correta continuaria **confirmando a existência** do grupo pelo corpo da resposta. Não foi testado ainda. A TASK-476 já usa `find` + `abort_if`, então o teste de corpo idêntico (TASK-477) nasce verde, e a hipótese é respondida pela **mutação** que volta para `findOrFail` (ver os critérios da TASK-477).
- **Decisão:** o teste compara, para o mesmo usuário autenticado, `GET` num grupo **inexistente** e num grupo **existente do qual ele não é membro**, e exige **mesmo status e mesmo corpo** (`getContent()`). `config(['app.debug' => false])` é fixado **dentro do teste**: o `phpunit.xml` não define `APP_DEBUG`, e em modo debug o corpo traz `file`, `line` e `trace`, que diferem por construção. Em produção o valor esperado é `false`; isso é premissa registrada, não verificada aqui (o `.env` de produção não é lido).
- **Duas possibilidades** (detalhadas nos critérios da TASK-477): se a hipótese for **confirmada**, a mutação que volta para `findOrFail` derruba o teste e nasce o achado do §8; se for **refutada** (corpos iguais mesmo com `findOrFail`), essa mutação não derruba, o teste segue como trava, e uma segunda mutação (corpo diferente só no caminho do grupo alheio) prova que ele não é vazio, como foi feito na TASK-474 do Painel.
- **Por que no controller e não no handler global:** mudar `App\Exceptions\Handler` para renderizar `ModelNotFoundException` de forma genérica corrigiria **todos** os endpoints de grupo de uma vez, mas muda o corpo de `404` de rotas que esta feature não toca e que o web pode estar lendo. Fica como achado tangencial (§8), com decisão própria.

## 3. specify R7 (D1 = B) e R3 — travar o contrato de quem é membro

- **Nada de produção muda aqui.** São testes que **travam** o que a feature promete não alterar:
  - membro de grupo **excluído logicamente** (`deleted = true`) continua recebendo `200` com a lista (R7);
  - o payload de membro mantém os campos que o web consome e que o item 085 documenta: `id`, `name`, `email`, `pix`, `whatsapp`, `avatar_url`; e **não** traz `password`, `remember_token`, `google_id` nem `photo_path` (`$hidden`) (R3, R8).
- **Mutações:** (a) acrescentar `abort_if($group->deleted, 404)` no `index` derruba o teste do R7; (b) trocar o retorno por `$group->members->map->only(['id', 'name'])` derruba o teste do payload. Sem as duas, os testes não valem.
- **Por que o payload não é um snapshot completo:** um `assertExactJson` quebraria com qualquer coluna nova de `ex_users`. Trava-se o que os consumidores usam e o que **não** pode sair.

## 4. specify R4 — testes de regressão e mutação

Todos em `backend/tests/Feature/GroupMemberControllerTest.php` (padrão da classe: `DatabaseTransactions`, `tokenFor()`, `Group::create` + `members()->attach`):

| Teste | Prova | Mutação que o derruba |
|---|---|---|
| `test_non_member_cannot_list_members` | `404` e o corpo **não** contém e-mail, Pix nem WhatsApp de nenhum membro | remover a chamada de `authorizeMembership` do `index` |
| `test_member_can_list_members` | `200`, lista com os membros esperados e os campos do §3 | (cobertura positiva: pega o caso de a checagem bloquear membro) |
| `test_unauthenticated_cannot_list_members` | `401` sem token | (cobertura do `jwt.auth`) |
| `test_unknown_group_and_foreign_group_are_indistinguishable` | R2: mesmo status e mesmo corpo, com `app.debug = false` | voltar para `Group::findOrFail` |
| `test_member_of_deleted_group_still_lists_members` | R7: `200` | acrescentar `abort_if($group->deleted, 404)` |
| `test_member_payload_keeps_the_fields_the_web_reads` | R3/R8: campos presentes e sensíveis ausentes | reduzir o retorno a `id` e `name` |

- Cada teste novo nasce **RED** quando o conserto ainda não existe (o do não membro) ou é provado por mutação quando já passa (os de trava). Resultado dos dois, com a saída real, vai para o `implementation.md`.
- **Comandos** (padrão do projeto): `cd backend && php artisan test --filter=GroupMemberControllerTest`, depois a suíte inteira; `vendor/bin/pint --test`. Sem mudança de frontend, então `tsc` e `vitest` não entram (R6); roda-se a suíte do backend inteira, porque a rota é compartilhada com `store()` e com os testes de convite.

## 5. specify R5 — revisão de segurança e checklist pré-PR

- Agent `security-reviewer` sobre o diff de `GroupMemberController` e das rotas (`.claude/agents/`; Constitution §5.2), com o que ele deve confirmar: (a) nenhuma outra rota de `groups/{groupId}/members` ficou sem checagem; (b) o `404` não distingue inexistente de alheio; (c) o payload de membro não ganhou nem perdeu campo. Os achados são registrados como foram, mesmo os que não bloqueiam.
- Agent `pr-readiness-checker` na branch da feature integrada, antes do PR único contra `dev` (`docs/sdd/04-implementation.md` §1). O merge em `dev` é gate humano.

## 6. Ambiente de teste (desvio a registrar no `implementation.md` §1)

- O worktree **não tem mais** banco de teste: o contêiner `mysql-painel-test` e o `backend/.env.testing` da feature anterior foram removidos no encerramento dela, de propósito.
- **Proposta:** um contêiner novo e próprio, **`mysql-members-test`** (`mysql:8.0.34`, porta **3309**, banco `ex-db`), mais um `backend/.env.testing` descartável apontando para ele, e `php artisan migrate --env=testing`. Não reusar o `mysql-expense-test` (3307), que está no ar e pode ser de outra sessão (a feature `editar-comprovante-depois-de-paga` roda testes de backend). O `backend/vendor` do worktree já existe.
- O `.env.testing` **não é ignorado pelo git**: nunca entra em commit (`git add` por caminho, nunca `-A`). **Limpeza obrigatória no fim:** `docker rm -f mysql-members-test` e apagar o `.env.testing`.

## 7. Compatibilidade e entrega

- **Contrato:** para quem é membro, **nada muda** (mesmo status, mesmo payload). Para quem não é, o endpoint passa de `200` com dados para `404`, que é o objetivo. Não é mudança aditiva no sentido estrito da Constitution §4.1 (ela restringe acesso indevido), mas **não quebra nenhum consumidor legítimo**: os quatro do web chamam como membro (specify §2.4) e o app Flutter ainda não existe.
- **Sem migration, sem frontend, sem analytics.**
- **Rollback:** reverter o commit restaura o comportamento anterior, sem estado a desfazer.
- **Deploy:** o merge em `main` dispara o deploy do backend (`deploy-backend.yml`); vale lembrar no PR que isso é passo à parte do merge em `dev`. Como o dado **já está exposto em produção**, a ordem recomendada é promover esta feature para `main` assim que validada em `dev`, sem juntar a outras.

## 8. Achados tangenciais (não bloqueiam; viram `docs/backlog/` no fim da feature)

- **Possível confirmação de existência pelo corpo do `404` em todos os endpoints de grupo** (`show`, `update`, `destroy`, despesas, relatórios, `store`): todos usam `Group::findOrFail` seguido de `authorizeGroupMembership`. Depende da hipótese do §2; só vira item de backlog se a RED a **confirmar**. Se confirmar, a correção natural é no handler global (decisão própria, com `security-reviewer`).
- **Três cópias do helper de membro** (`Controller.php:17`, `GroupMemberController.php:94`, `GroupController.php:136`): refatoração sem mudança de comportamento (já fora de escopo no specify §5).
- **A Constitution (§5 item 3) e o `01-specify.md` (§3.3, linha 52) descrevem achados já resolvidos** pela feature `20260817-seguranca-api` (`GroupController@show/update/destroy`). É doc defasada, não código; editar a Constitution é gate humano, então só se registra.

## 9. Ordem de execução

- **Dependência técnica:** as tasks de §1 e §2 mexem no **mesmo método**; a de §2 depende da de §1. A de §3 são só testes e roda depois delas, para travar o estado final. Revisão (§5) e backlog (§8) vêm por último.
- **Critério de ordenação:** primeiro o que fecha o vazamento (severidade ALTA), depois o que o torna indistinguível, depois as travas, depois revisão e registro.
- **Fluxo de branch** (`docs/sdd/04-implementation.md` §1): a primeira task é feita direto na branch da feature `backend/20261023-members-index-checagem-de-membro`; as seguintes saem de branch de task e voltam por `git merge --no-ff`, sem PR por task. Um único PR da feature contra `dev` no fim, com merge humano.
