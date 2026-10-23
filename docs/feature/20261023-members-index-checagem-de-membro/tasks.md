# Tasks — Checagem de membro na lista de membros do grupo

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20261023

IDs a partir de TASK-476: o maior em qualquer lugar do projeto é TASK-475 (`origin/dev`, feature `20261010-painel-financeiro-pessoal`); a feature local `20261022-editar-comprovante-depois-de-paga` vai até TASK-464, abaixo. Conferido em 2026-10-23 em `origin/dev`, em `docs/sdd/03-tasks.md` (maior: TASK-193) e na branch e no checkout principal da outra feature. Reconfirmar a numeração se outra feature reservar IDs antes do merge.

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-476 | Fazer `GroupMemberController::index` recusar com `404` quem não é membro do grupo, com testes PHPUnit | backend | plan.md §1 e §4 | nenhum | Concluída (`implementation.md` §4) |
| TASK-477 | Travar por teste que o `404` de grupo inexistente e o de grupo alheio são indistinguíveis no `index`, e provar por mutação a hipótese do `findOrFail` | backend | plan.md §2 | nenhum | Concluída (`implementation.md` §5; hipótese **confirmada**) |
| TASK-478 | Travar por teste o contrato de quem é membro: grupo excluído continua `200` e o payload mantém os campos | backend | plan.md §3 | nenhum | Pendente |
| TASK-479 | Revisar o diff com o `security-reviewer`, rodar o checklist pré-PR na branch integrada e registrar os resultados | backend | plan.md §5 | antes do merge | Pendente |
| TASK-480 | Registrar em `docs/backlog/` os achados tangenciais da feature | doc | plan.md §8 | nenhum | Pendente |

Ordem: TASK-476 → 477 → 478 → 479 → 480. A 477 depende da 476 (mexem no **mesmo método**). A 478 vem depois, para travar o estado final. A 479 revisa o conjunto. A 480 fica por último porque o resultado da hipótese testada na 477 decide se um dos achados vira item de backlog. Se a revisão ou a validação apontar defeito, ele vira task nova, não amplia a task em andamento.

O ambiente de teste (contêiner `mysql-members-test` na 3309, `backend/.env.testing`, `migrate`) **não é uma task**: é pré-condição da TASK-476 e fica registrado como desvio no `implementation.md` §1, com a limpeza obrigatória no fim (plan.md §6), como na feature do Painel.

## Critérios de aceite

- **TASK-476** (feita **direto na branch da feature**, sem sub-branch, por ser a primeira):
  - **Ambiente pronto, com evidência no `implementation.md` §1:** contêiner `mysql-members-test` (`mysql:8.0.34`, porta 3309, banco `ex-db`) no ar, `backend/.env.testing` descartável criado (**fora de qualquer commit**), `php artisan migrate --env=testing` sem migração pendente, e o comando que prova que a suíte conecta nele (`php artisan test --filter=GroupMemberControllerTest` verde **antes** de qualquer mudança: os 3 testes de `store()` existentes).
  - **RED:** o teste `test_non_member_cannot_list_members` é escrito **antes** do conserto e falha pelo motivo certo (recebe `200` com e-mail, Pix e WhatsApp dos membros, em vez de `404`). A saída real da falha vai para o log.
  - Depois do conserto, em `GroupMemberControllerTest`: `test_non_member_cannot_list_members` (`404`; o corpo **não** contém o e-mail, o Pix nem o WhatsApp de nenhum membro), `test_member_can_list_members` (`200`, com os membros esperados) e `test_unauthenticated_cannot_list_members` (`401`).
  - O `index()` usa `Group::find`, `abort_if($group === null, 404)` e o `authorizeMembership` que a classe já tem, e devolve `response()->json($group->members)`; o bloco comentado morto sai. `git diff -- app` mostra **só** o método `index` (o `store()` e as rotas não mudam).
  - **Mutação:** remover a chamada de `authorizeMembership` do `index` derruba `test_non_member_cannot_list_members`; reverter e conferir o arquivo antes de seguir.
  - `vendor/bin/pint --test` limpo e **suíte inteira do backend verde**, com o número real de testes.
- **TASK-477** (branch de task a partir da branch da feature):
  - `test_unknown_group_and_foreign_group_are_indistinguishable`: um usuário autenticado chama `GET /api/groups/{id}/members` para um grupo que **não existe** e para um grupo que **existe e do qual ele não é membro**, com `config(['app.debug' => false])` fixado **dentro do teste**. Exige o mesmo status (`404`) e o **mesmo corpo** (`getContent()`).
  - **Esta task é só de teste: nenhuma linha de produção muda** (`git diff --stat -- app` vazio). O conserto do R2 já está na TASK-476 (`find` + `abort_if`, um único caminho de `404`), então o teste **nasce verde**, e o que o prova não vazio é a mutação.
  - **A hipótese do plan.md §2 é respondida pela mutação.** Voltar o `index` para `Group::findOrFail($groupId)` e rodar o teste:
    - **se o teste cai:** a hipótese está **confirmada** (o `findOrFail` entrega a existência pelo corpo do `404`). Registra-se no `implementation.md` os **dois corpos reais** (o do grupo inexistente e o do grupo alheio) e a TASK-480 abre o item de backlog do handler global;
    - **se o teste não cai** (os corpos já são iguais mesmo com `findOrFail`): a hipótese está **refutada**, não há item de backlog, e o teste segue válido como trava. Para provar que ele não é vazio, usa-se uma **segunda mutação**: fazer só o caminho do grupo alheio responder `abort(404, 'x')`, que **precisa** derrubar o teste.
  - Em qualquer dos dois casos, o resultado da primeira execução e o de cada mutação vão para o log com a saída real. Mutação revertida e trecho relido antes de restaurar. Se nenhuma mutação derrubar o teste, ele é refeito (lição da TASK-474 do Painel).
  - Pint limpo e suíte inteira do backend verde.
- **TASK-478** (branch de task):
  - `test_member_of_deleted_group_still_lists_members`: grupo com `deleted = true` e o usuário como membro recebe `200` com a lista (R7).
  - `test_member_payload_keeps_the_fields_the_web_reads`: a resposta traz `id`, `name`, `email`, `pix`, `whatsapp` e `avatar_url` e **não** traz `password`, `remember_token`, `google_id` nem `photo_path` (R3 e R8).
  - **Nenhuma linha de produção muda:** `git diff --stat -- app` vazio.
  - **Duas mutações, cada uma revertida com o trecho relido antes de restaurar:** acrescentar `abort_if($group->deleted, 404)` no `index` derruba o teste do R7; trocar o retorno por `$group->members->map->only(['id', 'name'])` derruba o teste do payload. Se alguma mutação não derrubar o teste dela, o teste é refeito.
  - Pint limpo e suíte inteira verde.
- **TASK-479** (branch de task; **gate humano antes do merge**):
  - Agent `security-reviewer` rodado sobre o diff, confirmando ou refutando: (a) nenhuma outra rota de `groups/{groupId}/members` ficou sem checagem; (b) o `404` não distingue inexistente de alheio; (c) o payload de membro não ganhou nem perdeu campo. Achados registrados **como vieram**, com a minha verificação de cada um e a decisão tomada com o usuário.
  - Agent `pr-readiness-checker` rodado na **branch da feature já integrada**. Comandos reais e resultados no `implementation.md`: `php artisan test`, `vendor/bin/pint --test`, `git diff origin/dev --stat` (sem segredo, sem migration, arquivos só de `GroupMemberController`, de testes e de docs).
  - Descrição do PR lista as tasks 476 a 480 e referencia a feature e o item 085.
  - **O PR não é aberto nem enviado sem o usuário confirmar por escrito que validou** o que precisar validar localmente (preferência registrada). Merge em `dev` é gate humano.
- **TASK-480** (branch de task):
  - Um arquivo por achado em `docs/backlog/`, no formato do `README.md` (ID sequencial livre **conferido na hora** em `origin/dev` e nas branches locais; hoje o maior é 095; Origem, Criado em, Prioridade, Status, Descrição) e, quando houver decisão, `## Regras adotadas` e `## Decisões em aberto` em tabela, com recomendação e motivo:
    - **a corrigir só se a TASK-477 confirmar:** a mensagem do `404` do `findOrFail` entrega a existência de grupo em **todos** os endpoints de grupo (candidata à correção no handler global);
    - as **três cópias do helper de membro** (`Controller.php`, `GroupMemberController.php`, `GroupController.php`) (BAIXA);
    - a Constitution (§5 item 3) e o `01-specify.md` (§3.3) descrevem como abertos achados já resolvidos (BAIXA, doc; editar a Constitution é gate humano).
  - `docs/backlog/README.md` com o índice atualizado.
