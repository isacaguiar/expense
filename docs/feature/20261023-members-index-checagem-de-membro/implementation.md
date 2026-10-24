# Implementation — Checagem de membro na lista de membros do grupo

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261023

---

## 1. Desvios do fluxo padrão

| # | Desvio | Por quê | O que fazer ao encerrar |
|---|---|---|---|
| 1 | Banco de teste: contêiner próprio **`mysql-members-test`**, `mysql:8.0.34`, porta **3309**, banco `ex-db`, no mesmo worktree `.claude/worktrees/painel` | O worktree não tinha banco (o contêiner da feature do Painel foi removido no encerramento dela, de propósito). **Não** reusei o `mysql-expense-test` (3307), que está no ar e pode ser de outra sessão | `docker rm -f mysql-members-test` no fim |
| 2 | `backend/.env.testing` criado **dentro do worktree**, com senha, `APP_KEY` e `JWT_SECRET` descartáveis gerados na hora (nunca impressos) | Necessário para `php artisan test` (a `phpunit.xml` usa `APP_ENV=testing` e não há `.env`) | O arquivo **não é ignorado pelo git**: nunca entra em commit (`git add` por caminho, nunca `-A`) e é apagado no fim |
| 3 | A branch da feature nasceu de `origin/dev` e **herdou o upstream `origin/dev`** | Criada com `git checkout -b … origin/dev` | **Todo push com o nome da branch explícito** (`git push -u origin backend/20261023-members-index-checagem-de-membro`), nunca `git push` solto, para não mirar na `dev` |
| 4 | Integração da `dev` na branch **antes** da TASK-476 (41 commits atrás) | A feature `editar-comprovante-depois-de-paga` entrou na `dev` (PR #227) depois da minha base | Nada. Conflito só no índice do backlog (linha do 085 e linha do 084 movida para Concluídos), resolvido mantendo o 085 como "Promovido para TASK-476" |

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-476 | Concluída (direto na branch da feature `backend/20261023-members-index-checagem-de-membro`, por ser a primeira task) | 2026-10-23 | Claude (IA), com aprovação do usuário (specify, plan e tasks aprovados) | Ver §4 | RED reproduziu o vazamento (`200` para não membro); conserto só no `index`; a mutação de remover `authorizeMembership` derruba só o teste do não membro; suíte 604 → **607** |
| TASK-477 | Concluída (branch `backend/20261023-members-index-checagem-de-membro-TASK-477`; só teste) | 2026-10-23 | Claude (IA), com aprovação do usuário | Ver §5 | O teste de corpo idêntico nasceu verde; a mutação para `findOrFail` o derrubou e **confirmou a hipótese**: o `findOrFail` devolve `"No query results for model [App\\Models\\Group] N"` e o grupo alheio devolve `""`. Sonda em 4 endpoints: o padrão é **sistêmico**. Suíte 607 → **608** |
| TASK-478 | Concluída (branch `backend/20261023-members-index-checagem-de-membro-TASK-478`; só testes) | 2026-10-23 | Claude (IA), com aprovação do usuário | Ver §6 | Duas travas, ambas nasceram verdes. **Três** mutações (duas do plano + uma extra para a asserção negativa), cada uma derrubando o teste certo. Suíte 608 → **610** |
| TASK-479 | **Concluída** (revisão feita; **validação local confirmada por escrito pelo usuário em 2026-10-23**, §8) (branch `backend/20261023-members-index-checagem-de-membro-TASK-479`; só docs) | 2026-10-23 | Claude (IA), com aprovação do usuário para iniciar | Ver §7 | `security-reviewer`: **aprovado**, sem achado alto ou médio, itens (a), (b) e (c) confirmados; 4 observações informativas. `pr-readiness-checker`: **pronto com pendências** (610 passed, Pint, 8 arquivos, sem segredo nem migração). Três sondas descartáveis minhas medem o que o revisor só leu |

## 3. Ambiente de teste (pré-condição da TASK-476)

| Verificação | Como | Resultado |
|---|---|---|
| Nome e porta livres | `docker ps -a --filter name=mysql-members-test`; `Get-NetTCPConnection -LocalPort 3309` | Nenhum contêiner com o nome; porta 3309 livre |
| Contêiner | `docker run -d --name mysql-members-test … -p 127.0.0.1:3309:3306 mysql:8.0.34 --default-authentication-plugin=mysql_native_password --innodb_use_native_aio=0` | No ar; `mysqladmin ping` respondeu na 4ª tentativa |
| **Alvo conferido antes de migrar** | Script que carrega o app com `APP_ENV=testing` e imprime host, porta, banco e usuário, sem a senha | `host=127.0.0.1 \| porta=3309 \| banco=ex-db \| usuario=root`; conexão ok, MySQL 8.0.34; **0 tabelas** |
| Migrações | `php artisan migrate --env=testing --force`; `migrate:status` | **32 aplicadas, 0 pendentes** (nenhuma migração nova desde a feature do Painel) |
| Baseline do arquivo de teste | `php artisan test --filter=GroupMemberControllerTest` **antes de qualquer mudança** | **3 passed (10 asserções)**: os 3 testes de `store()` |
| Baseline da suíte inteira | `php artisan test` na branch já integrada com a `dev` (PR #227 incluído), antes de qualquer mudança | **604 passed (3726 asserções)**, 80,72 s |

## 4. Evidências da TASK-476 (`index()` recusa quem não é membro)

Branch `backend/20261023-members-index-checagem-de-membro`, **direto nela** (primeira task), a partir de `8ac77ff226`.

**O que mudou** (`backend/app/Http/Controllers/GroupMemberController.php`, só o método `index`): `Group::with('members')->findOrFail($groupId)` deu lugar a `Group::find($groupId)`, `abort_if($group === null, 404)`, `$this->authorizeMembership($group)` (o helper que a classe já tem, decisão D3) e `response()->json($group->members)` (payload igual, R3). O bloco comentado morto saiu junto. O `store()` e as rotas não mudaram.

**Testes novos** em `GroupMemberControllerTest`: `test_non_member_cannot_list_members` (`404`, e o corpo não contém o e-mail, o Pix nem o WhatsApp de um membro), `test_member_can_list_members` (`200`, `assertJsonCount(2)` e os dois membros com e-mail) e `test_unauthenticated_cannot_list_members` (`401`).

| Verificação | Como | Resultado |
|---|---|---|
| **RED** (testes novos, `index` ainda sem checagem) | `php artisan test --filter=GroupMemberControllerTest` | **1 failed, 5 passed.** `non member cannot list members`: "Expected response status code [404] but received 200." Reproduz o item 085 por teste: um usuário que não é membro lê a lista. Os outros dois passam, como previsto (membro lista; sem token dá `401`) |
| **GREEN** (depois do conserto) | mesmo comando | **6 passed (21 asserções)** |
| **Mutação** | Removi `$this->authorizeMembership($group);` do `index` e rodei de novo | **1 failed, 5 passed**: cai **só** `non member cannot list members` ("Expected … [404] but received 200"). Restaurei e **reli o trecho** (linhas 16-28) antes de seguir |
| Escopo do diff de produção | `git diff --stat -- app` | **1 arquivo**, só o `index` (8 inserções, 6 remoções); o `store()` não aparece |
| Estilo | `php vendor/bin/pint --test` | `PASS` 176 arquivos |
| **Suíte inteira** | `php artisan test` | **607 passed (3737 asserções)**, 76,55 s (604 + 3) |

**O que esta task NÃO prova (fica para a TASK-477):** que o corpo do `404` do grupo inexistente seja igual ao do grupo alheio (R2). O código já usa `find` + `abort_if` para isso, mas **nenhum teste ainda compara os dois corpos**. Por isso reescrevi o comentário do `index` depois de escrevê-lo: a primeira versão dizia que o `findOrFail` "cairia no handler de ModelNotFoundException com outra mensagem", como fato. Isso é a **hipótese** da TASK-477, e o comentário agora diz "pode devolver outra mensagem".

## 5. Evidências da TASK-477 (404 indistinguível; hipótese do `findOrFail`)

Branch `backend/20261023-members-index-checagem-de-membro-TASK-477`, a partir de `fe2a372629`. **Só teste: nenhuma linha de produção mudou** (`git diff backend/20261023-members-index-checagem-de-membro --stat -- backend/app` vazio).

**O teste:** `test_unknown_group_and_foreign_group_are_indistinguishable`, em `GroupMemberControllerTest`. Um usuário autenticado chama `GET /api/groups/{id}/members` num grupo que **não existe** (`Group::max('id') + 1000`) e num grupo **existente do qual não é membro**, com `config(['app.debug' => false])` fixado **dentro do teste** (o `phpunit.xml` não define `APP_DEBUG`, e em modo debug o corpo traz `file`, `line` e `trace`). Exige o mesmo status (`404`) e o **mesmo corpo** (`getContent()`).

| Verificação | Como | Resultado |
|---|---|---|
| **Primeira execução** (código da TASK-476) | `php artisan test --filter=GroupMemberControllerTest` | **7 passed (24 asserções)**. Nasceu **verde**, como previsto no plano: é uma trava, não um RED |
| **Mutação 1**: voltar o `index` para `Group::findOrFail($groupId)` | `php artisan test --filter=test_unknown_group_and_foreign_group_are_indistinguishable` | **Falhou: a hipótese do plan.md §2 está CONFIRMADA.** Grupo inexistente: `{"message": "No query results for model [App\\Models\\Group] 2392"}`. Grupo alheio: `{"message": ""}`. Mesmo status, **corpos diferentes**: o corpo confirma a existência do grupo |
| Mutação 2 (corpo diferente só no caminho do grupo alheio) | — | **Não foi necessária**: a mutação 1 já derrubou o teste, o que prova que ele não é vazio (o plano só a exigia se a hipótese fosse refutada) |
| Restauração | Reli `GroupMemberController.php` linhas 16-28 e comparei com a branch da feature | Idêntico; `git diff … -- backend/app` vazio |
| Estilo | `php vendor/bin/pint --test` | `PASS` 176 arquivos |
| **Suíte inteira** | `php artisan test` | **608 passed (3740 asserções)**, 73,83 s (607 + 1) |

### Achado novo: o vazamento de existência é sistêmico (alimenta a TASK-480)

Sonda descartável (um teste temporário, **apagado** antes do commit, nada versionado), com o mesmo usuário não membro, `app.debug = false`, no grupo inexistente e no grupo alheio:

| Endpoint | Grupo inexistente | Grupo alheio |
|---|---|---|
| `GET /api/groups/{id}` (`GroupController::show`) | `404 "No query results for model [App\\Models\\Group] 2400"` | `404 ""` |
| `GET /api/groups/{id}/expenses/summary` | idem | idem |
| `GET /api/groups/{id}/expenses/gross-debts` | idem | idem |
| `POST /api/groups/{id}/members` (`store`, **a mesma classe**) | idem | idem |

O padrão é o mesmo em todos: `Group::findOrFail` seguido de `authorizeGroupMembership`. Consequências, **medidas** nos 4 endpoints acima e **inferidas por leitura** nos demais que usam o mesmo par (`update`, `destroy`, `indexByGroup`, `getMonthlyExpenses`, `close`, `reopen`, os relatórios):

1. **Qualquer usuário autenticado descobre se um grupo existe** pelo corpo da resposta, em vez de pelo status. A convenção "`404`, não `403`, para não confirmar a existência" do backend inteiro é anulada pela mensagem.
2. **A mensagem vaza o nome da classe interna do model** (`App\Models\Group`) e o id.

Isto **não** é corrigido por esta feature: o `index` (R2) já está protegido, e o resto exige decisão própria (a correção natural é no handler global `App\Exceptions\Handler`, que muda o corpo de `404` de rotas que o web pode ler). Vira item de backlog na TASK-480, com tabela de decisão.

**O comentário do `index` ficou como está** ("pode devolver outra mensagem"): agora está **provado**, mas reescrevê-lo mexeria em produção, e esta task é só de teste.

## 6. Evidências da TASK-478 (trava do contrato de quem é membro)

Branch `backend/20261023-members-index-checagem-de-membro-TASK-478`, a partir de `a222960e8f`. **Só testes: nenhuma linha de produção mudou** (`git diff backend/20261023-members-index-checagem-de-membro --stat -- backend/app` vazio).

**Os dois testes novos** em `GroupMemberControllerTest`:
- `test_member_of_deleted_group_still_lists_members` (R7, decisão D1 = B): grupo com `deleted = true` e o usuário como membro recebe `200` com a lista (`assertJsonCount(1)` e o membro com e-mail).
- `test_member_payload_keeps_the_fields_the_web_reads` (R3 e R8): o usuário de teste tem `pix`, `whatsapp`, `google_id` e `photo_path` **preenchidos** (para a asserção negativa valer). A resposta traz `id`, `name`, `email`, `pix`, `whatsapp` e `avatar_url` e **não** traz `password`, `remember_token`, `google_id` nem `photo_path`. Não é um snapshot exato: uma coluna nova em `ex_users` não o quebra.

| Verificação | Como | Resultado |
|---|---|---|
| **Primeira execução** | `php artisan test --filter=GroupMemberControllerTest` | **9 passed (42 asserções)**. As duas **nasceram verdes**, como previsto: são travas do que a feature promete não mudar |
| **Mutação A** (a do plano) | `abort_if($group->deleted, 404);` depois de `authorizeMembership` no `index` | **1 failed, 8 passed**: cai **só** `member of deleted group still lists members` ("Expected … [200] but received 404") |
| **Mutação B** (a do plano) | retorno trocado por `$group->members->map->only(['id', 'name'])` | **3 failed**: `member payload keeps the fields the web reads` ("Failed asserting that an array has the key 'email'") e, por também lerem o e-mail, `member can list members` e `member of deleted group still lists members` |
| **Mutação C** (extra, minha) | retorno trocado por `$group->members->makeVisible(['google_id', 'photo_path'])` | **1 failed, 8 passed**: cai **só** o teste do payload ("o campo google_id não pode sair na lista de membros"). Prova que a asserção **negativa** não é vazia, o que as duas do plano não provavam |
| Restauração | Reli `GroupMemberController.php` linhas 16-28 depois de cada volta; `git diff … -- backend/app` vazio ao final | Idêntico à branch da feature |
| Estilo | `php vendor/bin/pint --test` | `PASS` 176 arquivos |
| **Suíte inteira** | `php artisan test` | **610 passed (3758 asserções)**, 70,15 s (608 + 2) |

**Por que a Mutação C existe:** o plano pedia duas mutações, e as duas só provavam a parte *positiva* do teste do payload (campos que **devem** sair). A parte negativa (campos que **não** podem sair) só falharia se alguém passasse a expô-los, e nenhuma mutação do plano fazia isso. Sem a C, essa metade do teste poderia estar vazia sem ninguém notar (a mesma lição da TASK-474 da feature do Painel, cujo primeiro teste era vazio).

## 7. Evidências da TASK-479 (revisão de segurança e checklist pré-PR)

Branch `backend/20261023-members-index-checagem-de-membro-TASK-479`, a partir de `ba1eea7ff7`. **Só documentação**: nenhuma linha de produção ou de teste muda nesta task.

**Antes de revisar, integrei a `dev` de novo** (a branch estava 2 commits atrás: o PR #228, de arquivamento da feature `editar-comprovante-depois-de-paga`; nenhum arquivo em comum comigo). Depois disso: **0 atrás, 8 à frente** de `origin/dev`, e `git diff origin/dev --name-only` lista só os 8 arquivos desta feature (2 de backend, 2 em `docs/backlog`, 4 em `docs/feature/20261023-members-index-checagem-de-membro`).

### 7.1 `security-reviewer`: aprovado, sem achado alto ou médio

Resultado **como veio**. Incidentes de execução, registrados porque limitam o que ele mediu: (1) a primeira execução caiu por **limite de uso (HTTP 429)** no meio do trabalho, e eu a **retomei** com `SendMessage` no mesmo agent (o trabalho parcial estava salvo); (2) uma sonda que ele tentou rodar foi **negada pelo classificador de permissões** ("Modify Shared Resources", porque criaria registros mesmo em transação), e ele **respeitou a negativa e não contornou**. Por isso o que ele afirma sobre IDs estranhos e sobre `500` de rotas sem método vinha de **leitura**, e eu medi depois (§7.3).

**Os três itens pedidos pelo plano §5:**

| Item | Veredito dele | Evidência dele |
|---|---|---|
| (a) nenhuma outra rota de `groups/{groupId}/members` ficou sem checagem | **Confirmado** | `routes/api.php:54-58`: `GET index` agora confere; `POST store` já conferia (`findOrFail` + `authorizeMembership` + `abort_if(deleted)`); `DELETE /{userId}` aponta para `destroy` que não existe (item 086) e não abre brecha (não lê dado, não consulta o grupo, está atrás de `jwt.auth`). Auditou também `ExpenseController` (grupo e despesa), `GroupExpenseReportController`, `GroupController`, `PixController`, `NotificationController` e `OverviewController`: todos conferem acesso |
| (b) o `404` é indistinguível no `index` | **Confirmado, com a premissa `APP_DEBUG=false`** | Os dois casos viram `HttpException` `404` com mensagem vazia (`abort_if` linha 24 e `abort_unless` linha 100); `find()` não lança `ModelNotFoundException`; `Handler.php` do app não altera `404`; middlewares e limiter não dependem do grupo. O teste existente passou na execução dele |
| (c) payload e `$hidden` | **Confirmado** | Mesma relação `belongsToMany`, mesmo `pivot`, mesmos atributos; `$hidden` (`User.php:36-41`) íntegro. `avatar_url` sai como URL assinada de 30 min, como antes |

**As 4 observações dele, com a minha verificação e a decisão proposta:**

| # | Sev. | O que diz | Minha verificação | Decisão proposta |
|---|---|---|---|---|
| 1 | Informativa | Canal de **tempo** teórico: inexistente faz 1 consulta (`find`), alheio 2 (`find` + `exists`), membro 3. Diferença sub-milissegundo, abaixo do jitter de rede. Endurecimento opcional: uma consulta só, `Group::whereHas('members', …)->find()` + `abort_if`, como `GroupController@index:19-21`; custo: abrir mão do helper (decisão D3 = A) | Conferi `GroupController.php:19-21`: o padrão existe. **Não medi tempo** (seria ruído) | **Não fazer agora** (recomendação): ganho teórico, e reabriria a D3 que o usuário aprovou. Fica registrado aqui. Se o usuário preferir, vira uma task própria |
| 2 | Informativa | A igualdade dos corpos de `404` só vale com `APP_DEBUG=false`; com `true` o corpo traz `file`, `line`, `trace` | **Conferi:** `deploy-backend.yml:36` escreve `APP_DEBUG=false`; `config/app.php:45` tem padrão `false`; `ci-backend.yml:49` usa `true` (CI), por isso o teste fixa `config(['app.debug' => false])`. Não li o `.env` real de produção, só o que o deploy escreve | Nada a fazer. É a premissa que o plano §2 já registrou |
| 3 | Informativa (pré-existente, fora do diff) | `DELETE /groups/{id}/members/{userId}` e `GET /api/expenses` apontam para métodos que não existem | **Medi** (§7.3): as duas dão `500 "Server Error"` (genérico, sem vazamento). `GET /api/expenses` **já é conhecido**: TASK-016 do `03-tasks.md`, `01-specify.md:58` e Constitution §2 item 4 (a TASK-016 pedia `index/show/update/destroy`; só o `index` ficou). A rota `DELETE` é o item 086 | **Nenhum item novo**: ambos já têm dono. Anoto na TASK-480 só como referência cruzada |
| 4 | Informativa | O teste de corpo idêntico não compara **cabeçalhos**; um cabeçalho condicional futuro passaria | Hoje são idênticos. Comparar cabeçalhos exigiria excluir `Date` e `X-RateLimit-Remaining` (que mudam de uma chamada para a outra) | **Não fazer** (recomendação): frágil, e nenhum cabeçalho condicional existe. Fica registrado |

### 7.2 `pr-readiness-checker`: pronto com pendências (pendências não são de código)

Rodado na branch integrada (`ba1eea7ff7`). Comandos reais e resultados dele, que eu **reconferi no git** (HEAD, `0 8` atrás e à frente, 8 arquivos, +452 −8, IDs de backlog):

| Item | Resultado |
|---|---|
| `php artisan test` | **610 passed (3758 asserções)**, 90,96 s (mais lento porque rodou o Pint em paralelo) |
| `php vendor/bin/pint --test` | `PASS` 176 arquivos |
| `npx tsc` e `vitest` | N/A: o diff não toca `frontend/` |
| `git diff origin/dev --stat` | 8 arquivos; **0 migrações, 0 apagados**, nada fora do escopo |
| Segredos nas linhas adicionadas | 21 linhas casaram, **todas benignas** (`tokenFor()`, `withToken()`, `$hidden`, menções por nome a `APP_KEY` e `JWT_SECRET` sem valor); dados de teste fictícios |
| `.env.testing` | fora do diff, não rastreado (e **não** está no `.gitignore`: por isso `git add` só por caminho) |
| Termos proibidos (`dd(`, `var_dump`, `console.log`, TODO…) | 0 ocorrências |
| Log e `tasks.md` | uma linha e uma seção com comando e resultado reais para 476, 477 e 478; contagem 604 → 607 → 608 → 610 fecha |
| Item 085 no backlog | "Promovido para TASK-476" no índice e no arquivo |
| Commits | todos terminam com `Co-Authored-By`; os de conteúdo seguem `tipo(escopo): descrição (TASK-xxx)` |

**Um erro meu que ele apontou, corrigido:** o critério da TASK-480 dizia "hoje o maior é 095", mas o backlog já tem **096 e 097** (da outra feature). O próximo livre é **098**, a conferir na hora (`tasks.md` ajustado).

### 7.3 Três sondas minhas, descartáveis (nada versionado; arquivos apagados)

| Sonda | Resultado |
|---|---|
| `GET /api/expenses` e `DELETE /api/groups/{id}/members/{userId}`, usuário membro, `app.debug = false` | **`500 "Server Error"`** nas duas: confirma a leitura do revisor e que não há vazamento no corpo |
| `GET /api/groups/{x}/members` com `x` = `abc`, `-1`, `0`, `99999999999999999999`, `1e999`, `{id}abc`, `{id}.0`, `%20{id}` | Para o **não membro**: `404` **com corpo idêntico** ao do grupo inexistente, **em todos os 8**. Para o **membro**: `404` nos 5 valores que não viram um id, e `200` em `{id}abc`, `{id}.0` e `%20{id}` (o MySQL converte para o id real: é o **mesmo grupo** pedido por outro nome, sem bypass: o não membro continua em `404`) |
| (as da TASK-477, já no §5) | corpo do `404` do `findOrFail` em 4 endpoints |

### 7.4 Integração final com a `dev` e reconferência do estado final

Enquanto os agents rodavam, o usuário mergeou na `dev` o **PR #226** (arquivamento da feature do Painel: move a pasta e reescreve caminhos em comentários de código e nos itens de backlog). Integrei de novo (`git merge origin/dev`): **sem conflito**; só o item 085 estava nas duas pontas (a `dev` trocou a linha `Origem`, eu a linha `Status`: hunks separados). Estado final: **0 atrás, 11 à frente** de `origin/dev`.

Como a integração tocou comentários de backend, **refiz no estado final**: `php vendor/bin/pint --test` → `PASS` 176 arquivos; `php artisan test` → **610 passed (3758 asserções)**, 78,65 s; `git diff origin/dev --name-only` → os mesmos **8 arquivos** (2 de backend, 2 de `docs/backlog`, 4 da feature); `git status --short` → só `?? backend/.env.testing`. Ou seja, as conclusões do `pr-readiness-checker` (feitas no commit anterior) valem também para o commit final.

**Pendências que não são de código, e o que está decidido ou não:**

1. ~~**Confirmação por escrito do usuário** de que validou o que precisa localmente.~~ **Recebida em 2026-10-23** (§8). A TASK-479 fecha.
2. **TASK-480** (backlog) ainda não feita.
3. ~~As observações #1 e #4 do revisor.~~ **Decidido pelo usuário em 2026-10-23: não fazer; ficam só registradas neste log** (não viram task nem item de backlog).

## 8. Validação local do usuário e encerramento da stack (2026-10-23)

**Stack de validação** (montada depois do aval do usuário para validar no web): backend `php artisan serve --env=testing --port=8002` e Vite na 3002, num banco descartável **`members_validacao`** dentro do contêiner `mysql-members-test` (separado do `ex-db`, que é o do PHPUnit). Alvo conferido antes de migrar (`127.0.0.1:3309`, `members_validacao`, 0 tabelas); 32 migrações, 0 pendentes. Semeado por script com trava de alvo (recusa rodar em outro banco ou porta): 5 usuários `@members.test` e 3 grupos (Casa da Praia com Ana, Beto e Caio; Grupo Alheio com Dora e Eva; Grupo Excluído com `deleted = 1`, com Ana e Beto) e uma despesa. As portas 3000 e 8000 **não foram tocadas**.

| Verificação (minha, antes de entregar ao usuário) | Resultado |
|---|---|
| Por HTTP real, contra o backend da 8002 (`check_members.php`) | **7 de 7 como esperado**: Ana (membro) lista os 3 membros da Casa da Praia sem `password` nem `google_id`; Ana recebe `404` no Grupo Alheio; Dora (não membro) recebe `404` na Casa da Praia e no grupo `9999`, com o **mesmo corpo** `{"message":""}`; Ana segue com `200` no Grupo Excluído (D1 = B); sem token `401` |
| No Chrome do usuário, logada como Ana | Tela **Participantes** da Casa da Praia mostra os 3 membros (Ana, Beto, Caio). A URL de um grupo alheio (`/app/groups/2/members`) mostra "Erro ao carregar informações do grupo." e "Nenhum membro cadastrado.", sem nenhum dado |

**Confirmação do usuário, por escrito, em chat:** "validei, pode seguir". Antes dela deixei a aplicação **aberta e logada no Chrome dele**, na tela certa, e indiquei onde está a senha padrão (arquivo da `UserFactory`, sem repetir o valor), como a memória do projeto pede.

**Encerramento da stack** (só depois da confirmação):

| Passo | Como | Resultado |
|---|---|---|
| Processos | `TaskStop` nos dois shells em segundo plano e `Get-NetTCPConnection` em seguida | Portas **8002 e 3002 livres**; 8000 e 3000 também livres, nunca tocadas |
| Banco | `SHOW DATABASES`, `DROP DATABASE members_validacao`, `SHOW DATABASES` (senha lida do `.env.testing` e passada por variável de ambiente, sem imprimir) | Antes: `ex-db` e `members_validacao`. Depois: só **`ex-db`** |
| Aba do Chrome | `tabs_close_mcp` | **Não consegui fechar**: a aba já não pertencia ao grupo de abas desta sessão. É só uma aba local de uma stack que já caiu |

**Mantidos até o fim da feature:** o contêiner `mysql-members-test` (o PHPUnit usa o `ex-db` dele) e o `backend/.env.testing` (não rastreado; **nunca** `git add -A`). Ao fim (depois do merge do PR): `docker rm -f mysql-members-test` e apagar o `.env.testing`.
