# Tasks — Atualização da Página Minha Conta

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks"). IDs a partir de `TASK-194` — maior ID já usado no projeto antes desta feature: `TASK-193` (`docs/feature/20260822-atualizacao-participantes/tasks.md`).

Versão: 1.0 · Criado em: 20260822

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-194 | Migration `add_whatsapp_to_users_table` (`whatsapp` string nullable, `notify_whatsapp` boolean default false) em `ex_users`; adicionar os 2 campos a `User::$fillable` | backend | plan.md §1 | nenhum | Concluída |
| TASK-195 | `UserController@updateProfile`: validar (`whatsapp` regex `(DD) 9XXXX-XXXX`, `notify_whatsapp` boolean) e persistir os 2 campos junto com `name`/`email`/`pix`; incluir os 2 no JSON de resposta | backend | plan.md §1 | nenhum | Concluída |
| TASK-196 | `Profile.tsx`: campo `whatsapp` com máscara `(DD) 9XXXX-XXXX` e checkbox "Receber notificações pelo WhatsApp", incluídos no estado do formulário e no `PUT /api/user/profile` já existente | frontend | plan.md §1 | nenhum | Concluída |
| TASK-197 | `composer require laravel/socialite doctrine/dbal`; adicionar bloco `'google' => [...]` em `backend/config/services.php` lendo `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI` | backend | plan.md §2.1, §2.3 | nenhum | Concluída |
| TASK-198 | 3 migrations aditivas em `ex_users`: `password` nullable (`->change()`), `google_id` (string nullable unique), `avatar_url` (string nullable); adicionar `google_id`/`avatar_url` a `User::$fillable`, `google_id` a `User::$hidden` | backend | plan.md §2.2 | nenhum | Concluída |
| TASK-199 | `GoogleAuthController@redirectUrl` — rota `GET /api/user/google/redirect-url` dentro de `jwt.auth`; monta `state` assinado (`Crypt::encryptString`, `intent=link`, `user_id`, `exp`) e devolve `{url}` de `Socialite::driver('google')->stateless()->with(['state' => ...])->redirect()` | backend | plan.md §2.4 | nenhum | Pendente |
| TASK-200 | `GoogleAuthController@callback` — rota pública `GET /api/auth/google/callback`; decodifica/valida `state`, para `intent=link` busca o usuário, seta `google_id`/`avatar_url` e redireciona para `{FRONTEND_URL}/profile?linked=success` (ou `?linked=error`); `intent` ausente/diferente retorna placeholder para a feature `login-social-google` estender depois | backend | plan.md §2.4 | nenhum | Pendente |
| TASK-201 | `Profile.tsx`: botão "Vincular conta Google" que busca `GET /api/user/google/redirect-url` e navega (`window.location.href`); ao voltar, lê `?linked=` da URL, mostra `Snackbar` e reconsulta `GET /api/me` | frontend | plan.md §2.5 | nenhum | Pendente |
| TASK-202 | `Profile.tsx`: exibir `avatar_url` (quando presente) no componente `Avatar`, com fallback para `getInitials(name)` quando ausente | frontend | plan.md §3 | nenhum | Pendente |

## Critérios de aceite

- **TASK-194**: migration roda sem erro (`php artisan migrate`); `ex_users` passa a ter colunas `whatsapp` (nullable) e `notify_whatsapp` (not null, default `false`); teste automatizado confirma os 2 campos em `User::$fillable`.
- **TASK-195**: `PUT /api/user/profile` com `whatsapp` em formato válido e `notify_whatsapp` atualiza os 2 campos (`assertDatabaseHas`); `whatsapp` fora do formato retorna 422; resposta JSON inclui os 2 campos.
- **TASK-196**: `/profile` mostra o campo WhatsApp com máscara aplicada durante a digitação e o checkbox; salvar dispara `PUT` com os 2 valores; reload mostra os valores persistidos.
- **TASK-197**: `composer show laravel/socialite doctrine/dbal` confirma as 2 dependências instaladas; `config('services.google.client_id')` (etc.) lê das env vars sem erro mesmo com valores vazios em `.env` local.
- **TASK-198**: `php artisan migrate` roda sem erro; `ex_users.password` aceita `NULL` (`assertDatabaseHas` com `password: null` num registro de teste); `google_id` é nullable e único (segundo `INSERT` com mesmo `google_id` falha); `avatar_url` aceita `NULL`.
- **TASK-199**: chamada autenticada a `GET /api/user/google/redirect-url` retorna 200 com `{url}` apontando para `accounts.google.com`; sem token retorna 401.
- **TASK-200**: `state` válido com `intent=link` e `user_id` de um usuário de teste, seguido de mock do retorno do Socialite, atualiza `google_id`/`avatar_url` daquele usuário (`assertDatabaseHas`) e responde com redirect para `.../profile?linked=success`; `state` expirado/adulterado responde com erro sem alterar nenhum usuário.
- **TASK-201**: clicar em "Vincular conta Google" dispara `GET /api/user/google/redirect-url` (`read_network_requests` confirma) e navega para a URL retornada; acessar `/profile?linked=success` mostra `Snackbar` de sucesso e reconsulta `/api/me`.
- **TASK-202**: usuário com `avatar_url` preenchido mostra a foto no `Avatar`; usuário sem `avatar_url` mostra as iniciais, sem regressão no comportamento atual.
