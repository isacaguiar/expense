# Plan — Recuperação de Senha não Trava Login

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260821

---

## 1. Parar de sobrescrever a senha antes do e-mail ser enviado (specify §2.1)

- **Decisão**: remover completamente as linhas `$user->password = Hash::make($token); $user->save();` (`InvitationController.php:106-108`) de `forgotPassword`. A senha real do usuário só é trocada em `verify()` (linha 70), quando ele efetivamente confirma o token e informa a nova senha — nunca antes disso.
- **Por que é seguro remover, e não substituir por outra coluna**: em `verify()`, a validação do token de reset já não depende de `$user->password` ter sido alterado — `isResetTokenValid` (`verify()`, linha 63) compara `$request->token` contra o valor guardado em `Cache::get('password-reset-token:'.$user->email)`, que já é gravado em `forgotPassword` (linha 112) independentemente da senha. A checagem `isInvitationToken = Hash::check($request->token, $user->password)` (linha 62) existe para o fluxo *diferente* de convite (`invite()`, que já grava a senha temporária como hash na criação do usuário) e continua funcionando sem alteração. Ou seja: sobrescrever a senha em `forgotPassword` nunca foi necessário para a validação funcionar — era só o efeito colateral que quebra a conta. Isso evita migration nova (nenhuma coluna extra de "reset token" é necessária, o `Cache` já cumpre esse papel).
- **Mail::send em try/catch com log**: envolver o bloco `Mail::send(...)` (linhas 117-123) em `try/catch`. Em caso de exceção, registrar `Log::error('Falha ao enviar e-mail de recuperação de senha', ['email' => $user->email, 'exception' => $e->getMessage()])` e retornar `response()->json(['message' => 'Não foi possível enviar o e-mail de recuperação agora. Tente novamente em instantes.'], 500)` em vez da mensagem de sucesso.
- **Rate limit só depois do envio confirmado**: `Cache::put($cacheKeyRateLimit, ...)` (linha 113) hoje roda antes do `Mail::send`. Mover esse `Cache::put` para depois do `Mail::send` ter sucesso (dentro do mesmo bloco que retorna a resposta de sucesso) — senão uma falha de envio também bloquearia o usuário de tentar de novo por 15 minutos, reintroduzindo o mesmo tipo de trava que este plano corrige. `Cache::put($cacheKeyToken, ...)` (linha 112, o token em si) continua sendo gravado antes do envio, pois o e-mail precisa do link com o token pronto — isso é inofensivo porque, sem a senha sobrescrita (item acima), um token gravado e nunca usado não tem efeito colateral nenhum.
- **Arquivo afetado**: `backend/app/Http/Controllers/InvitationController.php`, método `forgotPassword` (linhas 89-126). Nenhuma migration, nenhuma mudança de rota.

## 2. Expiração do token de 60 minutos (specify §2.2)

- **Decisão**: nenhuma mudança de código adicional além do item 1. Hoje o problema de expiração só é grave *porque* a senha já foi destruída (specify §2.1) — sem esse efeito colateral, um token expirado simplesmente exige que o usuário clique em "esqueci minha senha" de novo, o que já funciona (respeitando o rate limit de 15 min existente, `forgotPassword` linhas 98-103). Documentado aqui para deixar explícito que a causa raiz (item 1) resolve este achado também, sem precisar de uma task própria.

## 3. Ambiente local sem `mailpit` (specify §2.3)

- **Decisão**: adicionar serviço `mailpit` ao `docker-compose.yml`, usando a imagem oficial `axllent/mailpit` (leve, sem dependências, compatível com o `MAIL_HOST=mailpit` / `MAIL_PORT=1025` já configurados em `backend/.env`). Expor porta `1025` (SMTP, usada pelo Laravel) e `8025` (UI web para inspecionar e-mails capturados), no mesmo `ex-network` dos demais serviços — segue o padrão já usado por `mysql-db`/`admin` no arquivo.
- **Por que agora e não backlog à parte**: sem isso, qualquer teste manual da correção do item 1 continua reproduzindo silenciosamente um mailer quebrado (specify §2.3), mesmo depois do código corrigido — a correção ficaria impossível de validar localmente de ponta a ponta.
- **Arquivo afetado**: `docker-compose.yml` (raiz do projeto).

## 4. Testes

- Não há testes automatizados hoje para `InvitationController::forgotPassword`/`verify` (`backend/tests/Feature` não cobre o controller). A task do item 1 usa `Mail::fake()` (padrão Laravel) para: (a) confirmar que `$user->password` **não muda** no banco após `POST /api/forgot-password` mesmo simulando falha de envio (`Mail::shouldReceive`/exception forçada), e (b) confirmar que o fluxo de sucesso ainda deixa o e-mail "enviado" (`Mail::assertSent`) e a senha inalterada até `verify()`. Critérios de aceite detalhados ficam em `tasks.md`.

## 6. Achado durante a execução da TASK-122: nome de tabela errado nas validações do `InvitationController` (bloqueia a testabilidade do item 1)

- **Descoberto ao rodar o teste automatizado do item 1** (`tests/Feature/InvitationControllerForgotPasswordTest.php`): `POST /api/forgot-password` retorna 500 **antes** de qualquer código deste plano ser executado, por `QueryException: Table 'ex-db.users' doesn't exist`. Causa: as 3 regras de validação do controller usam o nome de tabela literal `users` (`InvitationController.php:19` — `unique:users,email`; linha 49 — `exists:users,email` em `verify()`; linha 91 — `exists:users,email` em `forgotPassword()`), mas o model `User` aponta para `ex_users` (`app/Models/User.php:17`) e é essa a única tabela criada pela migration (`database/migrations/2014_10_12_000000_create_users_table.php:14`). Não existe (e nunca existiu neste schema) uma tabela literal `users` — é um nome de tabela errado nas 3 regras, nunca detectado porque não havia teste nenhum para este controller antes desta feature.
- **Decisão**: corrigir os 3 usos para `unique:ex_users,email` / `exists:ex_users,email`, mesmo padrão já usado em `UserController` (`unique:ex_users,email,{id}`, ver `docs/feature/20260821-melhoria-menu-tela-grupos-perfil/implementation.md`, TASK-118). Corrige os 3 métodos (`invite`, `verify`, `forgotPassword`) juntos, não só `forgotPassword` — é o mesmo bug nos 3 lugares do mesmo arquivo, e `verify()` também é exercitado pelo teste de ponta a ponta do item 1 (specify §2.1 depende do fluxo completo `forgotPassword` → `verify`).
- **Por que vira task nesta feature e não backlog**: bloqueia diretamente a verificação da TASK-122 (specify §2.1) — sem essa correção, os critérios de aceite da TASK-122 não são verificáveis por teste automatizado. Regra do `CLAUDE.md` raiz: achado que já bloqueia algo hoje vira task direto, não backlog.
- **Arquivo afetado**: `backend/app/Http/Controllers/InvitationController.php`, linhas 19, 49 e 91 (só o nome da tabela nas regras de validação — nenhuma mudança de comportamento além de fazer a validação `exists`/`unique` realmente funcionar contra o banco real).

## 5. Ordem de execução

Sem dependência técnica forte entre os itens 1 e 3 (um é backend/PHP, outro é infra/Docker) — mas o item 3 (mailpit) deve vir **depois** do item 1 na ordem das tasks, porque só faz sentido validar manualmente o fluxo corrigido (item 1) já com um mailer funcional disponível (item 3). O item 2 não gera task própria (ver §2 acima). O item 6 é pré-requisito de **verificação** do item 1 (a task do item 6 precisa estar integrada antes que os testes automatizados da task do item 1 consigam passar), mesmo sem dependência de código entre os dois.
