# Specify — E-mail verificado obrigatório

> Feature: fecha duas lacunas que deixaram "e-mail verificado" sem valor de garantia real — o endpoint legado `POST /register` continua criando contas sem confirmar e-mail, e `AuthController::login` autentica qualquer conta independente de `email_verified_at`. Origem: itens de backlog 042 e 043, ambos gerados durante `docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/` mas deixados fora daquele escopo.

Versão: 1.0 · Criado em: 20260922

---

## 1. Problema

A feature de auto-cadastro (`cadastro-de-usuarios`) introduziu confirmação de e-mail por código para o fluxo novo (`/pre-register` → `/pre-register/verify`), mas dois pontos anteriores a ela ficaram de fora:

1. `POST /api/register` (rota legada, sem cliente conhecido hoje) continua público e cria `User` na hora, sem `password_confirmation`, sem telefone e **sem exigir confirmação de e-mail** — exatamente o que o fluxo novo existe para evitar. Não foi alterado na feature original porque mudar contrato de rota existente sem depreciação assistida é proibido por `00-constitution.md` §4.1.
2. `AuthController::login` nunca checou `email_verified_at` — nem antes nem depois da feature de auto-cadastro. Ligar essa checagem foi deixado de fora daquela feature porque bloquearia contas antigas com o campo nulo sem um plano de transição.

Enquanto (1) existir, a confirmação por código do fluxo novo é contornável por quem chama a API diretamente. Enquanto (2) não existir, "e-mail verificado" não é uma garantia que o resto do sistema possa assumir.

## 2. Achados confirmados e requisitos

### 2.1 `POST /register` cria conta sem verificação (item de backlog 042)

- Rota: `backend/routes/api.php:18` (`Route::post('/register', [AuthController::class, 'register'])`), pública, sem middleware de throttle.
- Handler: `AuthController::register()` (`backend/app/Http/Controllers/AuthController.php:13-29`) valida `name`/`email`/`password`/`role` e chama `User::create()` direto — não seta `email_verified_at`, então nasce `null` (default da coluna, `create_users_table.php:18`).
- **Requisito**: `register()` deixa de poder criar uma conta autenticável sem verificação de e-mail. A forma exata (bloquear com erro orientando a usar `/pre-register`, redirecionar a lógica interna para o fluxo de pré-cadastro, ou outra abordagem que respeite a política de depreciação assistida de `00-constitution.md` §4.1) é decisão do Tech Plan — aqui só fixamos que o comportamento atual (criar conta verificável direto) não pode continuar.

### 2.2 `login` não confere `email_verified_at` (item de backlog 043)

- Handler: `AuthController::login()` (`backend/app/Http/Controllers/AuthController.php:31-55`) autentica via `Auth::guard('api')->attempt($credentials)` e devolve token sempre que a credencial bate, sem olhar `email_verified_at`.
- **Requisito**: login deve recusar autenticação para conta com `email_verified_at` nulo, mesmo com credenciais corretas, com mensagem clara orientando o usuário a confirmar o e-mail via **recuperação de senha** (ver §2.4 — decisão de backfill confirmada com o usuário: reverificação assistida, não backfill em massa).

### 2.3 Quem hoje tem `email_verified_at` nulo e conseguiria logar (levantamento de código)

Verificado nos três outros pontos que escrevem `User` ou tocam esse campo:

- `PreRegistrationService.php:190` seta `email_verified_at = now()` no momento em que o `User` real nasce a partir de `UserPreCreate` — fluxo novo já nasce verificado.
- `GroupMemberController.php:53` (convite) cria um `User` placeholder com senha aleatória desconhecida (`Str::random(10)`) e **sem** `email_verified_at` — mas essa conta não consegue logar até `InvitationController::verify()` (`:41`) rodar, porque ninguém sabe a senha gerada. `verify()` já seta `email_verified_at = email_verified_at ?? now()` ao mesmo tempo que define a senha real. **Ou seja: convite nunca produz uma conta null-e-logável.**
- `InvitationController::verify()` (`:41`) é também a rota usada por "esqueci minha senha" (mesmo endpoint, dupla checagem de token via `password-reset-token:` ou `invitation-token:` no cache, `:29-37`) — resetar senha já marca o e-mail como verificado.

Conclusão: o único caminho hoje que produz uma conta com `email_verified_at` nulo **e** senha conhecida pelo próprio usuário (logo, logável) é o `POST /register` legado (item 042) — mais qualquer linha anterior à existência desse controle, criada por outro meio fora do código atual (não verificável por leitura de código; ver §3).

### 2.4 Tratamento de contas antigas com o campo nulo (decisão confirmada com o usuário)

Sem backfill em massa. Login de conta com `email_verified_at` nulo é recusado (ver §2.2); o usuário é orientado a usar "Esqueci minha senha", fluxo que já passa por `InvitationController::verify()` e já marca `email_verified_at` ao definir a nova senha — nenhuma rota nova é necessária, só a barreira em `login()` e a orientação na mensagem/tela de erro.

## 3. Fora de escopo desta feature

- Login social (Google/Microsoft) — backlog 014/015, ainda não implementado (`GoogleAuthController` hoje só faz vínculo de conta já autenticada, não login).
- Levantamento exato de quantas contas em produção têm `email_verified_at` nulo hoje — não bloqueia a decisão de §2.4 (reverificação via "esqueci senha" funciona para qualquer volume de contas afetadas), fica como acompanhamento pós-deploy se o time quiser dimensionar o impacto.
- Decidir remover/depreciar `POST /register` por completo (ex.: `410 Gone`, sunset header) além de fechar a brecha de segurança — é uma discussão de contrato de API separada; aqui a rota pode continuar existindo, só não pode mais criar conta sem verificação.
- Qualquer mudança no fluxo de convite ou pré-cadastro — ambos já verificam corretamente (§2.3).
- Outros itens de backlog (038, 040) — promovidos como features separadas.
