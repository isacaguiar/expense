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
- **SMTP de produção já está configurado** — não é pendência desta feature. `backend/.env.production` **não é versionado** (`.gitignore:12`) e nunca chega ao servidor: o `deploy-backend.yml` gera o `.env` no runner a partir de GitHub Secrets (linhas 31-64), com `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD` vindo de `ENV_MAIL_*` (presentes no ambiente `PROD` desde 2025-06-13), `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls` e `MAIL_FROM_ADDRESS=no-reply@expense-api.novemax.com.br`. É o mesmo caminho que `UserInvitedMail` e o e-mail de recuperação de senha já usam em produção hoje; `PreRegisterCodeMail` usa o mailer default, então não precisa de configuração própria.

  > Uma versão anterior deste documento afirmava o contrário, lendo `.env.production` como se fosse o arquivo de produção. Era o arquivo **local** de quem desenvolve, apontando para o Mailpit em `127.0.0.1:1025`.

## 10. Endurecimento vindo da revisão de segurança (TASK-290, TASK-291)

O agent `security-reviewer` revisou o código entregue por TASK-282..285 e achou defeitos **no código desta própria feature** — não dívida pré-existente. Por isso viraram task daqui (TASK-290, TASK-291) em vez de item de backlog: a feature não pode ser promovida com eles.

### 10.1 Handle opaco amarra a confirmação a quem submeteu (achado 1)

`updateOrCreate` chaveado só por e-mail permitia *account pre-hijacking*: um terceiro submetia `POST /pre-register` com o e-mail da vítima e a senha dele, um código novo saía para a caixa da vítima, e a vítima — ao digitar o código que acabara de chegar — criava a conta dela com a senha do atacante.

`start()` passa a devolver um **handle** (32 bytes aleatórios, guardado só como hash em `handle_hash`), exigido de volta em `verify` e `resend`. Ele vive no estado do componente de duas etapas, sem rota nova e sem `?email=` — mantendo a decisão de §6. Sobrescrever o pré-cadastro invalida o handle anterior, então o atacante ainda atrapalha um cadastro em curso, mas não o sequestra.

Handle errado e e-mail inexistente devolvem **a mesma** mensagem genérica, e handle errado **não gasta tentativa** — quem não submeteu não pode queimar o saldo de quem submeteu.

### 10.2 Teto de reenvios (achado 3)

Código novo zerava `attempts` sem limite de quantas vezes — orçamento de adivinhação infinito, e um canhão de e-mail para caixa de terceiro. Coluna `resend_count` com teto de 5 por pré-cadastro.

### 10.3 Reserva atômica de tentativa (achado 2)

Ler `attempts` e só depois incrementar deixava N requisições concorrentes passarem todas pelo `Hash::check` com a mesma leitura. A tentativa passa a ser **reservada antes** da conferência, com `UPDATE ... WHERE attempts < MAX` — se afetou 0 linhas, o teto foi atingido. Efeito colateral aceito: a confirmação bem-sucedida também gasta uma tentativa.

### 10.4 Colisão de e-mail na janela de 15 min (achado 4)

O `unique:ex_users` do FormRequest vale no `start()`; até o `confirm()` cabem 15 minutos em que `POST /register` ou um convite a grupo podem criar aquele e-mail. O `save()` então estourava `QueryException` — que interpola os bindings na mensagem (`vendor/laravel/framework/src/Illuminate/Database/QueryException.php:66`), levando e-mail, telefone e o **hash da senha** para o `laravel.log`. Agora o `confirm()` checa a existência do `User` dentro da transação e responde 422 com mensagem neutra.

### 10.5 Limpeza do material de credencial no consumo (achado 6)

A linha continua existindo depois de consumida (hard delete é gate humano), mas `password`, `code_hash` e `handle_hash` são esvaziados — não faz sentido manter uma segunda cópia do hash da senha numa tabela sem camada de acesso própria. É `UPDATE`, não delete: cabe dentro do gate atual.

### 10.6 Falha de envio não prende no cooldown (achado 7)

`Mail::send` sem try/catch gravava `last_sent_at` e estourava, deixando a pessoa 60 s presa esperando um código que nunca chegou. Agora o envio restaura o `last_sent_at` anterior em caso de falha — mesmo cuidado que `InvitationController::forgotPassword` já toma.

### 10.7 O que foi para o backlog

Os achados 5 (oráculo de pré-cadastro pendente via `resend`), 8 (traits `Queueable` no Mailable que carrega o código em claro) e 9 (`$fillable`) não são exploráveis hoje na forma entregue; 9 foi estreitado junto, 5 e 8 viraram itens de `docs/backlog/`.

## 9. Ordem de execução

Há dependência real: §1 (tabela) → §2 (Service) → §3-5 (endpoints e e-mail) → §6 (página, que consome os endpoints) → §7 (ligar os botões, que só faz sentido com a página existindo). A ordem em `tasks.md` segue essa cadeia. A task de documentação é a última porque descreve o comportamento já implementado.
