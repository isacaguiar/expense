# Implementation — Segurança da API

> Log de execução das tasks desta feature. Fluxo de branch/checklist/gate segue `docs/sdd/04-implementation.md` (branch por task, checklist antes de PR, merge é gate humano).

Versão: 1.1 · Última atualização: 2026-08-21

## Log

| Task ID | Status | Data | Branch | Observações |
|---|---|---|---|---|
| TASK-011 | Mergeada em `dev` via PR #1 | 2026-08-17 | `backend/TASK-011-pix-auth` | Fluxo antigo (task → PR direto contra `dev`, anterior ao ADR-003). Ver checklist abaixo. |
| TASK-012 | Mergeada em `dev` via PR #2 | 2026-08-17 | `backend/TASK-012-group-membership` | Fluxo antigo. Ver checklist abaixo. |
| TASK-015 | Mergeada em `dev` via PR #3 | 2026-08-17 | `backend/TASK-015-no-plaintext-log` | Fluxo antigo. Ver checklist abaixo. |
| TASK-016 | Implementada, aguardando PR feature → `dev` | 2026-08-21 | `backend/20260817-seguranca-api` | Achado tangencial durante `docs/feature/concluidas/202608/20260820-melhoria-tela-grupos/`; feature reaberta seguindo o fluxo atual (ADR-003). Ver checklist abaixo. |

## TASK-011 — detalhe

**Mudanças:**
- `backend/routes/api.php`: `GET /pix/generate` movida para dentro do grupo `jwt.auth`.
- `backend/app/Http/Controllers/PixController.php`: `gerarPix` agora pega o usuário autenticado (`auth()->user()`) e só gera o Pix se o alvo for o próprio usuário ou compartilhar pelo menos um grupo com ele (`compartilhaGrupo()`, mesmo padrão de `whereHas('members', ...)` usado em `GroupController@index`); caso contrário, `403`.
- `backend/tests/Feature/PixControllerTest.php` (novo): 4 casos — sem token → `401`; usuário gerando o próprio Pix → `200`; gerando o de alguém do mesmo grupo → `200`; gerando o de alguém sem grupo em comum → `403`. Usa `DatabaseTransactions` (não `RefreshDatabase`) contra o MySQL local do `docker-compose.yml` — `phpunit.xml` não tem sqlite disponível no ambiente (extensão `pdo_sqlite` ausente) e `RefreshDatabase` apagaria dados locais existentes; `DatabaseTransactions` só abre/fecha transação por teste, então não mexe no que já estava no banco local. Autenticação no teste usa token JWT real (`auth('api')->login($user)` + `withToken()`) porque o middleware `Tymon\JWTAuth\Http\Middleware\Authenticate` faz parse do token da própria requisição — `actingAs()` sozinho não é reconhecido por ele.

**Checklist (`docs/sdd/04-implementation.md` §1.3):**
- [x] `./vendor/bin/pint` limpo nos arquivos alterados (`PixController.php`, `routes/api.php`, `PixControllerTest.php`).
- [x] Teste PHPUnit novo cobrindo a regra de autorização (Constitution §2.2) — `php artisan test` roda 6/6 verde (suíte inteira, incluindo os 4 casos novos).
- [x] Critério de aceite de `tasks.md` verificado via teste automatizado (não manualmente via UI, já que não há consumidor no frontend hoje).
- [x] Nenhum segredo novo no diff.
- [x] Commit local feito (`f493b73c`), escopado só aos 3 arquivos da task — repositório tinha um outro arquivo (`frontend/src/pages/ExpenseManager.tsx`) já staged de um trabalho anterior; foi deixado de fora do commit e preservado no índice como estava.
- [ ] Abrir PR referenciando TASK-011 — aguardando decisão (push/PR fica por conta do usuário por enquanto).

**Gate humano**: merge em `main` só depois de aprovação humana — falta abrir o PR (push) quando decidido.

## TASK-012 — detalhe

**Mudanças:**
- `backend/app/Http/Controllers/GroupController.php`: `show`, `update` e `destroy` agora chamam `authorizeMembership($group)` antes de ler/alterar o grupo — `abort_unless` retorna **`404`** (não `403`, decisão registrada em `plan.md` §2: evita confirmar a existência do grupo para quem não é membro) quando o usuário autenticado não é membro.
- `backend/tests/Feature/GroupControllerTest.php` (novo): 6 casos — membro vê/edita/deleta (soft delete) o grupo normalmente; não-membro recebe `404` nos três casos e o grupo permanece inalterado no banco (`assertDatabaseHas`).

**Checklist (`docs/sdd/04-implementation.md` §1.3):**
- [x] `./vendor/bin/pint` limpo nos arquivos alterados.
- [x] Teste PHPUnit novo cobrindo a regra de autorização — `php artisan test` roda 8/8 verde (suíte inteira).
- [x] Critério de aceite de `tasks.md` verificado via teste automatizado.
- [x] Nenhum segredo novo no diff.
- [x] Commit local feito (`8dbcfa63`), escopado só aos 2 arquivos da task (mesma cautela da TASK-011).
- [ ] Abrir PR referenciando TASK-012 — aguardando decisão (push/PR fica por conta do usuário por enquanto).

**Gate humano**: merge em `main` só depois de aprovação humana — falta abrir o PR (push) quando decidido.

## TASK-016 — detalhe

**Mudanças:**
- `backend/app/Http/Controllers/GroupMemberController.php`: `store` agora chama `authorizeMembership($group)` (mesmo padrão privado de `GroupController::authorizeMembership`, duplicado aqui — não extraído para um trait compartilhado, ver `plan.md` §6) logo após buscar o grupo e antes de tocar em usuário/e-mail/pivot. `abort_unless` retorna **`404`** (não `403`, mesma justificativa do `GroupController`) quando o usuário autenticado não é membro do grupo.
- `backend/tests/Feature/GroupMemberControllerTest.php` (novo): 2 casos — membro do grupo adiciona outro usuário existente normalmente (`201`, pivot criado); não-membro tentando adicionar um e-mail novo recebe `404`, nenhum `ex_users` novo é criado, nenhum membro é associado ao grupo, e nenhum e-mail é disparado (`Mail::fake()` + `Mail::assertNothingSent()`).
- Rodei `./vendor/bin/pint` no arquivo do controller sem `--test` (a run inicial com `--test` apontou `class_attributes_separation`/`indentation_type`/`single_line_comment` **pré-existentes** no arquivo, não introduzidos por esta mudança) — o fix normalizou indentação/imports/vírgulas do arquivo inteiro, sem alterar lógica.
- Nesta sessão (worktree isolado), `backend/vendor` e `backend/.env` não existiam (gitignorados, não versionados); copiados do checkout principal do repo só para rodar `pint`/`php artisan test` localmente — nenhum dos dois entra no commit.

**Checklist (`docs/sdd/04-implementation.md` §1.3):**
- [x] `./vendor/bin/pint` limpo nos arquivos alterados (`GroupMemberController.php`, `GroupMemberControllerTest.php`).
- [x] Teste PHPUnit novo cobrindo a regra de autorização (Constitution §2.2/§6.5) — `php artisan test` roda **62/62 verde** (suíte inteira, incluindo os 2 casos novos).
- [x] Critério de aceite de `tasks.md` verificado via teste automatizado (`404`, nenhum membro/usuário/e-mail criado para não-membro; `201` para membro legítimo).
- [x] Nenhum segredo novo no diff.
- [ ] Commit local + merge na branch da feature (`backend/20260817-seguranca-api`) — próximo passo desta sessão.
- [ ] Abrir PR da branch da feature contra `dev` — aguardando decisão (push/PR fica por conta do usuário).

**Gate humano**: merge em `dev` só depois de aprovação humana do PR da feature (Constitution, tabela de Governança).

## TASK-015 — detalhe

**Mudanças:**
- `backend/app/Http/Controllers/AuthController.php`: removida a linha `Log::debug('Credenciais extraídas', $credentials)` (rodava em toda tentativa de login, sucesso ou falha); os dois `Log::warning('Falha no login: credenciais inválidas', $credentials)` (o ativo e o que estava no bloco comentado) agora logam só `['email' => ...]`, nunca o array `$credentials` bruto.
- `backend/tests/Feature/AuthControllerLoginLogTest.php` (novo): usa `Log::spy()` para travar a regra — login com senha errada não deve chamar `Log::debug` e o `Log::warning` não pode receber `password` no contexto; login com senha certa também não deve chamar `Log::debug`.

**Observação:** ao trocar de branch entre as tasks deste épico, percebi que vários arquivos do `backend/` (incluindo os que cada task altera) não estão commitados em nenhum branch além da própria branch da task — `git checkout` removeu `GroupController.php`/`GroupControllerTest.php` do disco ao sair da branch da TASK-012 (restaurados via `git show <branch>:<path>`). Isso é sintoma de um problema maior no repositório (parte de `backend/` nunca foi adicionada ao git), não algo introduzido por esta feature — vale uma conversa à parte sobre commitar o baseline do `backend/` inteiro.

**Checklist (`docs/sdd/04-implementation.md` §1.3):**
- [x] `./vendor/bin/pint` limpo nos arquivos alterados.
- [x] Teste PHPUnit novo cobrindo a regra (Constitution §2.2) — `php artisan test` roda 10/10 verde (suíte inteira nesta branch).
- [x] Critério de aceite de `tasks.md` verificado via teste automatizado.
- [x] Nenhum segredo novo no diff.
- [x] Commit local feito (`85574859`), escopado só aos 2 arquivos da task.
- [ ] Abrir PR referenciando TASK-015 — aguardando decisão (push/PR fica por conta do usuário por enquanto).

**Gate humano**: merge em `main` só depois de aprovação humana — falta abrir o PR (push) quando decidido.
