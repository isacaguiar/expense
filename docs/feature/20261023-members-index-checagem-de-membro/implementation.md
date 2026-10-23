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
