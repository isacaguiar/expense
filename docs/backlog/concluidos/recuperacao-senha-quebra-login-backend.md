# Fluxo de recuperação de senha sobrescreve a senha antes de garantir entrega do e-mail

ID: 011
Origem: Debug de sessão (usuário relatou não conseguir logar), 2026-08-18
Criado em: 2026-08-18
Prioridade: ALTA
Status: Promovido para TASK-122

## Descrição

`InvitationController::forgotPassword` (`backend/app/Http/Controllers/InvitationController.php:89-126`) sobrescreve `$user->password` com o hash de um token aleatório e salva (`$user->save()`, linha 108) **antes** de tentar enviar o e-mail com o link de recuperação (`Mail::send`, linha 117-123). Não há tratamento de erro em volta do `Mail::send`.

No ambiente local, `.env` define `MAIL_MAILER=smtp` / `MAIL_HOST=mailpit`, mas não existe container `mailpit` rodando (`docker-compose.yml` só sobe `mysql-db` e `admin`/adminer). Ou seja: qualquer chamada a `/api/forgot-password` local apaga a senha atual do usuário e a tentativa de envio do e-mail falha silenciosamente (sem log de erro — `InvitationController` não usa `Log::` em nenhum método) ou lança exceção sem que o usuário tenha como recuperar o token. O token também fica em `Cache` por só 60 minutos (linha 112), então mesmo destravando o mailer depois, o token expira rápido.

Foi essa sequência que travou o login de `isac@email.com` nesta sessão: `updated_at` do usuário mudou às 11:25 (mesmo dia), a senha em uso deixou de bater com qualquer valor conhecido, e não havia e-mail nem log para recuperar o token. Contornado manualmente via `tinker` (ação local, fora do fluxo normal do produto).

## Por que importa

Qualquer clique real (ou de teste) em "esqueci minha senha" deixa a conta inacessível se o e-mail não for entregue — e hoje não há: (1) infra de mailer local funcional por padrão (falta serviço `mailpit` no `docker-compose.yml`), (2) log de erro quando `Mail::send` falha, nem (3) qualquer forma de a pessoa reobter o token depois de expirado a não ser mexendo direto no banco. Em produção, uma falha de SMTP (fila cheia, credencial expirada, etc.) teria o mesmo efeito: usuário perde acesso à própria conta com um clique, sem fallback.

Correção sugerida (para quando isso virar task): só sobrescrever a senha depois de confirmar que o e-mail foi enviado (ou usar uma coluna separada de "token de reset" em vez de reaproveitar `password`), e envolver `Mail::send` em try/catch com log.

Tipo sugerido: backend

## Resolução

Concluído em: 2026-08-21
Feature: docs/feature/concluidas/202608/20260821-recuperacao-senha-login/
Tasks: TASK-122, TASK-123, TASK-124, TASK-125
PRs: https://github.com/isacaguiar/expense/pull/35

Corrigido conforme sugerido (senha só é trocada em `verify()`, nunca mais em `forgotPassword`), mais dois achados bloqueantes descobertos durante a execução: nome de tabela errado nas validações (`exists:users`→`exists:ex_users`, TASK-124) e nome de view errado nos 3 envios de e-mail (`emails.*`→`email.*`, TASK-125) — este último provavelmente a causa raiz real do "e-mail nunca chega" original. `mailpit` adicionado ao `docker-compose.yml` (TASK-123) para permitir validar o fluxo de ponta a ponta localmente. Validado manualmente: e-mail de recuperação confirmado recebido no Mailpit.
