# Tasks — Recuperação de Senha não Trava Login

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-122` — maior ID já usado no projeto antes desta feature: `TASK-121` (`docs/feature/concluidas/202608/20260821-melhoria-menu-tela-grupos-perfil/tasks.md`).

Versão: 1.0 · Criado em: 20260821

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-122 | `InvitationController::forgotPassword`: parar de sobrescrever `password` antes do e-mail, envolver `Mail::send` em try/catch com log, e só gravar o rate-limit após envio confirmado | backend | plan.md §1 | nenhum | Concluída |
| TASK-123 | Adicionar serviço `mailpit` (`axllent/mailpit`) ao `docker-compose.yml`, portas 1025 (SMTP) e 8025 (UI) | infra | plan.md §3 | nenhum | Concluída |
| TASK-124 | `InvitationController`: corrigir `unique:users`/`exists:users` para `unique:ex_users`/`exists:ex_users` em `invite`/`verify`/`forgotPassword` (nome de tabela errado, bloqueava a verificação da TASK-122) | backend | plan.md §6 | nenhum | Concluída |
| TASK-125 | `InvitationController`: corrigir `Mail::send('emails.*', ...)` para `'email.*'` nos 3 envios (`invite`/`verify`/`forgotPassword`) — nome de view errado, bloqueava o envio real de qualquer e-mail deste controller | backend | plan.md §7 | nenhum | Concluída |

## Critérios de aceite

- **TASK-122**:
  - Com `Mail::send` forçado a lançar exceção (mock/fake), `POST /api/forgot-password` com e-mail existente retorna 500 e a resposta não é a mensagem de sucesso; `assertDatabaseHas('users', ['id' => $user->id, 'password' => $senhaHashOriginal])` confirma que a senha **não mudou**; a falha fica registrada via `Log::error` (verificável com `Log::shouldReceive('error')->once()` ou `Log::spy()`); e uma nova chamada imediata a `POST /api/forgot-password` para o mesmo e-mail **não** é bloqueada por rate limit (o usuário pode tentar de novo sem esperar 15 min).
  - Com `Mail::fake()` (envio simulado com sucesso), `POST /api/forgot-password` retorna 200, `Mail::assertSent(...)` confirma o disparo, e a senha do usuário no banco continua igual à anterior (a troca real só acontece em `verify()`); uma segunda chamada imediata ao mesmo e-mail agora retorna 429 (rate limit ativo).
  - Fluxo completo: após `POST /api/forgot-password` bem-sucedido, `POST /api/invitations/verify` com o token correto (via `Cache`) e nova senha atualiza `password` no banco (`assertDatabaseHas` com o novo hash) — login com a senha antiga passa a falhar, com a nova passa a funcionar.
- **TASK-123**: `docker compose up mailpit` (ou `docker compose up -d`) sobe o container sem erro; `backend/.env` já aponta `MAIL_HOST=mailpit`/`MAIL_PORT=1025` sem alteração; disparar `POST /api/forgot-password` localmente com o backend rodando faz o e-mail aparecer na UI do Mailpit em `http://localhost:8025`.
- **TASK-124**: `php artisan test --filter=InvitationController` (suíte da TASK-122 incluída) passa sem `QueryException`; `POST /api/invitations` com e-mail já cadastrado continua retornando 422 (não 500); `POST /api/forgot-password`/`POST /api/invitations/verify` com e-mail inexistente continuam retornando 422 (não 500) — a correção só troca o nome da tabela consultada, não o comportamento esperado de validação.
- **TASK-125**: com Mailpit no ar (TASK-123) e `MAIL_HOST` local correto, `POST /api/forgot-password` para um e-mail real retorna 200 (não 500) e o e-mail aparece em `http://localhost:8025`; teste automatizado com `Mail::fake()` (que resolve a view de verdade, diferente de `Mail::shouldReceive`) confirma `Mail::assertSent` sem lançar `ViewNotFoundException` para os 3 métodos (`invite`, `verify`, `forgotPassword`).
