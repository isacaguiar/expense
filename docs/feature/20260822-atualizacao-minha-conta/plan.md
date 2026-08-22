# Plan — Atualização da Página Minha Conta

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260822

---

## 1. Campo WhatsApp e opt-in de notificação (specify §2.1)

- Migration aditiva `add_whatsapp_to_users_table.php`: duas colunas em `ex_users` — `whatsapp` (string, nullable, após `pix`) e `notify_whatsapp` (boolean, `default(false)`, `not null`, após `whatsapp`). Duas colunas em vez de uma só para manter o opt-in independente de o campo estar preenchido (usuário pode preencher o número e deixar a notificação desligada).
- `User::$fillable` ganha `whatsapp` e `notify_whatsapp`.
- `UserController::updateProfile` (`backend/app/Http/Controllers/UserController.php`) ganha validação `whatsapp` (`nullable`, `string`, `regex:/^\(\d{2}\) 9\d{4}-\d{4}$/`) e `notify_whatsapp` (`boolean`), e passa a atualizar os dois campos junto com `name`/`email`/`pix` já existentes. Resposta do endpoint (hoje `{message, name, email, pix}`) ganha `whatsapp`/`notify_whatsapp`.
- `frontend/src/pages/Profile.tsx`: novo `TextField` com máscara `(DD) 9XXXX-XXXX` (mesma abordagem de máscara simples já usada no projeto — sem lib nova) e um `Checkbox`/`FormControlLabel` "Receber notificações pelo WhatsApp", ambos no mesmo formulário e `PUT` já existentes. Tipo local do formulário (hoje `{ name, email, pix }`) ganha os 2 campos novos.

## 2. Vínculo de conta Google — infraestrutura compartilhada (specify §2.2)

Esta feature constrói toda a infraestrutura Google OAuth (decisão registrada em `specify.md` §2.2 e em `docs/feature/20260821-login-social-google/specify.md` §2.7). O fluxo de *login* em si (criar/logar usuário via Google) não é implementado aqui — só o *vínculo* de um usuário já autenticado. A estrutura do callback fica pronta para a feature `login-social-google` estender depois, sem recriar nada.

### 2.1 Dependência e pacotes

- `composer require laravel/socialite`.
- `composer require doctrine/dbal` — necessário porque a migration de `password` (abaixo) usa `Blueprint::change()`, que o Laravel 10 exige `doctrine/dbal` para alterar uma coluna existente; hoje não está em `composer.json`.

### 2.2 Schema (`ex_users`), uma migration aditiva por coluna, mesmo padrão de `add_pix_to_users_table.php`

- `make_password_nullable_on_users_table.php`: `$table->string('password')->nullable()->change();`
- `add_google_id_to_users_table.php`: `$table->string('google_id')->nullable()->unique()->after('email');`
- `add_avatar_url_to_users_table.php`: `$table->string('avatar_url')->nullable()->after('google_id');`

`User::$fillable` ganha `google_id` e `avatar_url`. `google_id` entra em `$hidden` (identificador interno, não precisa vazar em `GET /api/me`); `avatar_url` fica visível (consumido pelo frontend, §3).

### 2.3 Configuração

- `backend/config/services.php` ganha bloco `'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect' => env('GOOGLE_REDIRECT_URI')]`.
- Novo env var `FRONTEND_URL` (default `http://localhost:3000` se ausente) para montar a URL de retorno ao frontend após o callback — mesmo padrão já usado para origem de CORS em `backend/config/cors.php:9-12` (`FRONTEND_NETWORK_URL`), mas com propósito distinto (URL de redirect, não whitelist de origem).
- Credenciais reais (`GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`) são criadas pelo usuário no Google Cloud Console e fornecidas via `.env` local quando a implementação chegar nesse ponto — mesma decisão já registrada em `docs/feature/20260821-login-social-google/specify.md` §2.2. Nunca reaproveitar as credenciais vazadas citadas em `00-constitution.md` §5.3.

### 2.4 Backend — `GoogleAuthController`

Controller novo, dois métodos:

- **`redirectUrl` — `GET /api/user/google/redirect-url`** (dentro do grupo `jwt.auth`, `backend/routes/api.php`): recebe o usuário autenticado via `auth('api')->user()`, monta um `state` assinado (`Crypt::encryptString(json_encode(['intent' => 'link', 'user_id' => $user->id, 'exp' => now()->addMinutes(5)->timestamp]))`) e devolve JSON `{url}` com `Socialite::driver('google')->stateless()->with(['state' => $state])->redirect()->getTargetUrl()`. É uma chamada autenticada via XHR (não navegação) — o token em `localStorage` funciona normalmente aqui, diferente do callback abaixo.
- **`callback` — `GET /api/auth/google/callback`** (rota pública, fora de `jwt.auth` — é o Google quem bate nela, nunca carrega o header `Authorization`): usa `Socialite::driver('google')->stateless()->user()`; decodifica e valida o `state` (`Crypt::decryptString`, checa `exp`); se `intent === 'link'`, carrega `User::findOrFail($state['user_id'])`, seta `google_id`/`avatar_url` a partir do usuário Google retornado (`$googleUser->getId()`, `$googleUser->getAvatar()`) e salva; redireciona (`redirect()->away(...)`) para `"{$frontendUrl}/profile?linked=success"` (ou `?linked=error` em caso de e-mail/`google_id` já vinculado a outro usuário — tratar como erro de validação, não exception não tratada). Se `intent` for ausente ou diferente de `'link'`, retorna 501/placeholder — esse branch (`intent === 'login'`, criar/logar usuário) é implementado pela feature `login-social-google`, que deve **editar este mesmo método**, não criar um segundo endpoint.

Ambas as rotas registradas em `backend/routes/api.php`: `redirectUrl` dentro do grupo `jwt.auth` existente; `callback` fora dele, como rota pública nova (mesmo nível de `register`/`login`).

### 2.5 Frontend — botão "Vincular conta Google" em Minha Conta

- `Profile.tsx` ganha um botão "Vincular conta Google": ao clicar, faz `GET /api/user/google/redirect-url` (mesmo padrão de axios + header manual já usado no arquivo), depois `window.location.href = data.url` — navegação de página inteira, o que é necessário para o consentimento do Google.
- Ao voltar do backend (`?linked=success`/`?linked=error` na query string de `/profile`), `Profile.tsx` lê o parâmetro no `useEffect` de montagem, mostra `Snackbar` de sucesso/erro e limpa o parâmetro da URL, e reconsulta `GET /api/me` para obter o `avatar_url` atualizado.

## 3. Avatar via foto do Google (specify §2.3)

- `GET /api/me` já retorna `avatar_url` automaticamente assim que a coluna existir e estiver em `$fillable`/fora de `$hidden` (nenhuma mudança em `AuthController::me`).
- `Profile.tsx`: tipo local da resposta (`{ name, email, pix }`) ganha `avatar_url: string | null`; troca a exibição de texto/iniciais atual por `<Avatar src={avatarUrl ?? undefined}>{getInitials(name)}</Avatar>` (MUI `Avatar` cai para `children` quando `src` é vazio/undefined — sem dependência nova), ao lado do formulário.
- Nenhum outro componente que usa `getInitials()` (`GroupMembersForm.tsx`, `Dashboard.tsx`, `GroupHeader.tsx`, etc.) é alterado — fora de escopo por `specify.md` §3.

## N. Ordem de execução

- **§1 (WhatsApp)** não depende de nada em §2/§3 — pode ser feito em qualquer ordem/paralelo.
- **§2** tem dependência técnica linear: pacotes (2.1) → migrations (2.2, precisa de `doctrine/dbal` de 2.1) → config (2.3) → controller/rotas (2.4) → botão no frontend (2.5).
- **§3** depende de §2.2 (coluna `avatar_url` já existir) e §2.4 (fluxo de vínculo já popular a coluna) para ter dado real para mostrar, mas a troca de UI (`Avatar` com `src`) pode ser escrita em paralelo usando um valor mockado até a integração real estar pronta.
- Critério de ordenação em `tasks.md`: primeiro a infraestrutura compartilhada (§2, por ser o item mais arriscado/com mais dependência técnica), depois §3 (consome §2), com §1 podendo ser intercalado em qualquer ponto.
