# Plan — Cadastro de usuários

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260919

---

## 1. Tabela temporária `ex_user_pre_create` (specify §2.3)

Migration **aditiva** (tabela nova — `00-constitution.md` §4.2), no estilo já usado no repo (`return new class extends Migration`, `$table->id()`, `$table->timestamps()`, prefixo `ex_`):

| Coluna | Tipo | Por quê |
|---|---|---|
| `name` | string | |
| `email` | string **unique** | permite `updateOrCreate` quando a pessoa reenvia o formulário |
| `whatsapp` | string nullable | mesmo formato de `ex_users.whatsapp` |
| `password` | string | bcrypt, hasheado **já no pré-cadastro** — senha em claro nunca persiste (`00-constitution.md` §6.2) |
| `code_hash` | string | `Hash::make` do código; o código em claro nunca é gravado nem logado |
| `attempts` | unsignedTinyInteger default 0 | limite de tentativas erradas |
| `expires_at` | timestamp | TTL do código |
| `last_sent_at` | timestamp nullable | base do cooldown de reenvio |
| `consumed_at` | timestamp nullable | linha já usada |

A linha consumida é **marcada**, não apagada: hard delete exige gate humano (`00-constitution.md` §5.2). Expurgo de linhas velhas fica fora do escopo (specify §3).

Model `app/Models/UserPreCreate.php` com `$hidden = ['password', 'code_hash']` para que a linha nunca vaze por serialização acidental.

## 2. Regra de negócio em Service (specify §2.3, §2.4)

`app/Services/PreRegistrationService.php`. `00-constitution.md` §1.3 manda controller fino e regra não trivial em Service/Action, e `02-plan.md` §3-5 já define essa camada como alvo — esta é a primeira aplicação dela, não uma invenção.

- `start(array $data)` — `updateOrCreate` por e-mail, gera código, hasheia senha e código, define `expires_at`/`last_sent_at`, dispara o Mailable.
- `resend(string $email)` — respeita o cooldown lendo `last_sent_at`, gera código **novo** e zera `attempts`.
- `confirm(string $email, string $code)` — valida, nesta ordem, consumo → expiração → tentativas → `Hash::check`; em sucesso cria o `User` e marca `consumed_at`.

Parâmetros: código de **6 dígitos numéricos**, TTL **15 min**, **5** tentativas, cooldown de reenvio **60 s**. Ficam como constantes da classe.

**Dois pontos de atenção ao criar o `User`**, ambos cobertos por teste:

- a senha já vem hasheada e `User` tem `'password' => 'hashed'` no `$casts` (`app/Models/User.php:49-53`). O cast detecta bcrypt e não re-hasheia, mas isso precisa ser provado (`Hash::check($senhaOriginal, $user->password)`), não assumido;
- `whatsapp` **não está em `$fillable`** (`app/Models/User.php:25-30`), então tem de ser atribuído explicitamente.

## 3. Validação em FormRequest (specify §2.2)

`app/Http/Requests/PreRegisterRequest.php`, `PreRegisterVerifyRequest.php`, `PreRegisterResendRequest.php`. Não existe FormRequest no projeto hoje (validação é inline), mas `06-context-backend.md` prevê "via FormRequest quando a regra crescer" — aqui são 6 campos com duas confirmações e mensagens em português.

Regras do `PreRegisterRequest`: `name` obrigatório até 100 chars; `email` obrigatório, formato válido, `confirmed` (exige `email_confirmation`) e `unique:ex_users,email`; `whatsapp` nullable com o regex idêntico ao de `UserController.php:47`; `password` obrigatório, mínimo 6, `confirmed` (exige `password_confirmation`).

O `unique:ex_users` devolve 422 dizendo que o e-mail já está cadastrado — isso **enumera e-mails**, mas é exatamente o comportamento já vigente em `AuthController::register` e em `forgotPassword` (`exists:ex_users`). Manter a consistência é decisão consciente: endurecer enumeração vale para toda a superfície de auth de uma vez, não só nesta tela (backlog).

## 4. Controller e rotas (specify §2.4, §2.5)

`app/Http/Controllers/PreRegisterController.php`, três métodos finos delegando ao Service. Em `routes/api.php`, **aditivo** ao bloco público (linhas 17-21) — `POST /register` fica intacto:

- `POST /pre-register` → `store`, com `throttle:10,1`
- `POST /pre-register/verify` → `verify`, com `throttle:10,1`
- `POST /pre-register/resend` → `resend`, com `throttle:5,1`

O `throttle:` por IP é camada extra sobre o limiter global de 60/min (`app/Providers/RouteServiceProvider.php:27-29`); o cooldown por e-mail vive em `last_sent_at`.

As rotas são públicas por necessidade (quem chama ainda não tem conta), o que não conflita com `00-constitution.md` §6.5: são endpoints de **escrita** e **nenhuma resposta ecoa dado pessoal** — só `message` e contadores (`expires_in_seconds`, `resend_available_in`). O `verify` devolve o mesmo payload de `AuthController::respondWithToken` (`access_token`, `token_type`, `expires_in`), satisfazendo specify §2.5.

Códigos: `200` (store/resend), `201` (verify), `422` (validação, código inválido ou expirado), `429` (cooldown ou tentativas esgotadas).

## 5. E-mail com o código (specify §2.4)

`app/Mail/PreRegisterCodeMail.php` + `resources/views/email/pre-register-code.blade.php`, copiando `app/Mail/UserInvitedMail.php` (estilo `build()`, não Envelope/Content — Laravel 10) e `resources/views/email/invitation.blade.php` (card `.container`, logo Novemax, rodapé). O código aparece em destaque, com o prazo de 15 minutos.

Não usar `message` como chave de view — colisão conhecida com a variável do Laravel, já documentada em `docs/backlog/concluidos/invitation-invite-message-key-colisao-mail.md`.

## 6. Página `/cadastro` (specify §2.1, §2.2)

Rota pública em `frontend/src/App.tsx`, ao lado de `/aceitar-convite` e **fora** do `RequireAuth`.

Layout espelhando o login (decisão do usuário): mesmo split de `LoginPage.tsx:46-73` — `LoginBrandingPanel` à esquerda (ganha props opcionais `headline`/`subheadline` com os valores atuais como default, para não duplicar o componente) e o card à direita, com `LoginPageFooter` embaixo.

Sem biblioteca de formulário: não há react-hook-form/zod no `package.json` e adicionar uma seria decisão de stack (`00-constitution.md` §3). O padrão da casa é `useState` + MUI `TextField`. O tratamento de erro a copiar é o **por campo** de `AcceptInvitePage.tsx:49-55` (lê `response.data.errors`), não o `setError` de string única do `LoginPage`.

As duas etapas (formulário → código) vivem na mesma rota, como estado do componente. Se a pessoa fechar a aba, ela refaz o formulário — o `updateOrCreate` do Service absorve isso. Não se inventa rota `/cadastro/confirmar` nem `?email=` para retomar.

## 7. Ligar os botões (specify §2.1)

- `frontend/src/pages/login/LoginFormCard.tsx:191`: o `href="#"` vira `component={RouterLink} to="/cadastro"`.
- `frontend/src/pages/LoginPage.test.tsx:128` fixa o `href` como `#` e **vai quebrar** — passa a esperar `/cadastro` (sob `MemoryRouter` o `basename` não se aplica). Atualizar o teste faz parte da mesma task, não é conserto de passagem.
- `site/src/config.php:22`: `app_signup_url` passa a apontar para `/app/cadastro`, removendo o comentário "Não há tela de cadastro ainda". Isso liga os três CTAs do site de uma vez.

## 8. Gates e o que não dá para fechar aqui

- Migration em local/dev é autônoma; em produção ela roda sozinha no merge em `main` (`deploy-backend.yml` → `php artisan migrate --force`, `ADR-008`). É aditiva, mas o aval fica no PR de promoção `dev` → `main`.
- 🚩 `.env.production` tem `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025` e o remetente placeholder. **Nenhum e-mail de código sai em produção** até um SMTP real ser configurado — e credencial é ação 100% humana (`00-constitution.md` §5.2, §6.1). Código e testes funcionam sem isso; o fluxo em produção, não. Precisa constar como pendência explícita no PR de promoção.

## 9. Ordem de execução

Há dependência real: §1 (tabela) → §2 (Service) → §3-5 (endpoints e e-mail) → §6 (página, que consome os endpoints) → §7 (ligar os botões, que só faz sentido com a página existindo). A ordem em `tasks.md` segue essa cadeia. A task de documentação é a última porque descreve o comportamento já implementado.
