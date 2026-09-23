# Specify — Login social via Google

> Feature: permitir que o usuário entre no sistema autenticando com sua conta Google, além do login por e-mail/senha já existente. Origem: item 014 do backlog (`docs/backlog/login-social-google.md`), promovido após decisão de produto que resolve a `TASK-021` (`docs/sdd/03-tasks.md:46`).

Versão: 2.0 · Criado em: 20260821 · Atualizado em: 20260922

---

## 1. Problema

A tela de login (`frontend/src/pages/login/LoginFormCard.tsx:178-186`) tem um botão "Google" que hoje é só um link visual (`href="#"`), sem integração — o usuário clica e nada acontece. (O botão "Microsoft" que existia ao lado foi removido por `docs/bugfix/20260830-login-remover-botao-microsoft.md`; login social Microsoft segue como ideia separada, backlog item 015, sem decisão de produto.)

A `TASK-021` registrava uma decisão de produto pendente: implementar o login social de fato, ou remover as referências. Decisão tomada em 2026-08-21 (conversa com o usuário): **implementar, apenas para Google**.

**O que mudou desde a decisão original:** a feature `docs/feature/concluidas/202608/20260822-atualizacao-minha-conta/` já construiu, para o caso de uso de **vincular** uma conta Google a partir de "Minha Conta", boa parte da infraestrutura que esta feature também precisa — `laravel/socialite` instalado e configurado, colunas `google_id`/`avatar_url` em `ex_users`, `password` nullable, e um `GoogleAuthController` com um callback público (`GET /api/auth/google/callback`). Esse callback hoje só trata `intent=link`; para qualquer outro intent (inclusive `login`) ele responde **501** de propósito — comentário no próprio código (`GoogleAuthController.php:44-46`) aponta que estender esse método para login é o trabalho desta feature. Ou seja: o escopo real não é construir a integração do zero, é **estender** o que já existe.

## 2. Achados confirmados e requisitos

### 2.1 Infraestrutura já existente (reaproveitar, não recriar)

- `backend/app/Http/Controllers/GoogleAuthController.php` — `redirectUrl()` (autenticado, gera URL de consentimento com state opaco de uso único em cache) e `callback()` (público, hoje só trata `intent=link`).
- `backend/config/services.php:34-38` — client Google já configurado via `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI` (`.env`, não versionado — nomes documentados em `.env.example`).
- `ex_users.google_id` (nullable, unique) e `ex_users.avatar_url` (nullable) — migrations `2026_08_22_194809` e `2026_08_22_194810`.
- `ex_users.password` já é nullable (migration `2026_08_22_194808`) — pré-requisito para usuário criado só via Google, sem senha local.
- Constraint técnica já documentada em `atualizacao-minha-conta/specify.md` §2.2, válida também aqui: o grupo de middleware `api` não tem `StartSession`, e o JWT do frontend vive em `localStorage` (não em cookie) — um redirect de página inteira não carrega esse token de volta sozinho; a entrega do token ao frontend após o callback precisa de um mecanismo explícito (detalhe técnico fica para `plan.md`, mas **não pode ser simplesmente devolver o JWT cru na query string** — evitar exposição em histórico do navegador, logs de acesso e `Referer`).

### 2.2 Requisito: iniciar o fluxo de login (rota nova, pública)

Diferente do vínculo (que exige usuário já autenticado, por isso `redirectUrl()` fica atrás de `auth:api` e devolve JSON para o frontend montar a navegação via XHR), o login parte de um usuário **não autenticado** — a rota de início pode ser pública e navegável direto por um `<a href>` do botão "Google", sem chamada XHR prévia. `callback()` precisa saber diferenciar os dois intents (`link` vs. `login`) a partir do mesmo state opaco já usado hoje.

### 2.3 Requisito: `callback()` resolve o intent `login`

Quando o Google devolve um e-mail:
- Se já existe um `ex_users` com esse e-mail (conta criada via e-mail/senha ou por outro meio), a conta é **vinculada automaticamente** ao `google_id` retornado (sem exigir senha nem confirmação adicional) — decisão já tomada em 2026-08-21, assumindo que o Google só retorna e-mails verificados. Mesma regra de unicidade de `google_id` que o `intent=link` já aplica (um `google_id` não pode ficar associado a duas contas — hoje isso já é coberto por `GoogleAuthControllerTest::test_callback_redirects_with_error_when_google_id_already_linked_to_another_user`).
- Se não existe, cria um novo `ex_users` sem senha local (`password = null`), com `email_verified_at` preenchido na criação (mesmo padrão já usado em `PreRegistrationService.php:190` e `InvitationController.php:41` para contas cujo e-mail já chega verificado por outro canal).
- Em ambos os casos, emite o mesmo JWT que `AuthController::login` emite hoje (`Auth::guard('api')->attempt(...)` / `auth('api')->login($user)`, mesmo padrão de `GoogleAuthControllerTest::tokenFor()`) e entrega esse token ao frontend pelo mecanismo definido em `plan.md` (ver §2.1, restrição de não vazar o JWT na URL).

### 2.4 Requisito: robustez de `UserController::changePassword` para conta sem senha local

`UserController::changePassword()` (`backend/app/Http/Controllers/UserController.php:114-137`) faz `Hash::check($request->current_password, $user->password)` — se `$user->password` for `null` (conta criada só via Google, nunca definiu senha local), isso quebra em vez de devolver um erro tratado. A `atualizacao-minha-conta/specify.md` §3 já registrou esse ajuste como responsabilidade desta feature. Escopo mínimo: `changePassword` devolve um erro claro (ex.: 422 "Esta conta não tem senha local definida") em vez de deixar `Hash::check` estourar; **não** inclui construir um fluxo de "definir senha inicial" para conta Google-only (fica como ideia de backlog separada, se necessário).

### 2.5 Requisito: frontend

O botão "Google" (`frontend/src/pages/login/LoginFormCard.tsx:178-186`) troca `href="#"` pela navegação real ao endpoint de início do fluxo (§2.2). Ao retornar, a página de login precisa concluir a sessão (gravar o token, redirecionar para `/meus-grupos`) usando o mecanismo de entrega definido em `plan.md` — reaproveitando `frontend/src/auth/session.ts::setSession()` (já existe, usado hoje só pelo fluxo de cadastro) em vez de duplicar a gravação inline que `LoginPage.tsx:36-37` já faz para o login por senha.

## 3. Fora de escopo desta feature

- Login social via Microsoft (backlog item 015, `docs/backlog/login-social-microsoft.md` — segue "Aberto", nenhuma decisão de produto tomada para esse provedor; o botão nem existe mais na UI desde `docs/bugfix/20260830-login-remover-botao-microsoft.md`).
- Fluxo de login social no app Expo/React Native em migração (`app/`) — o redirect OAuth clássico do backend não serve diretamente para mobile; fica para quando o app novo avançar.
- Rotação das credenciais Google órfãs já vazadas (`00-constitution.md` §5.3) — debt separado, gate humano à parte; as credenciais em uso hoje (`.env`) já são as novas, criadas depois do vazamento.
- Endurecer a atomicidade do `Cache::pull` do state OAuth (backlog item 035, `docs/backlog/google-oauth-state-pull-nao-atomico.md`) — pré-existente, não é esta feature quem introduz o padrão.
- Fluxo de "definir senha inicial" para conta criada só via Google — ver §2.4, o escopo aqui é só não quebrar `changePassword`, não construir esse fluxo novo.
- Aplicar avatar/nome do Google em qualquer lugar além do que `atualizacao-minha-conta` já cobre (`avatar_url` em Minha Conta) — esta feature não muda exibição de avatar.
