# Specify — Recuperação de Senha não Trava Login

> Feature: corrige o fluxo de "esqueci minha senha" para que uma falha no envio do e-mail nunca deixe a conta do usuário inacessível. Origem: `docs/backlog/recuperacao-senha-quebra-login-backend.md` (item 011, promovido nesta feature).

Versão: 1.0 · Criado em: 20260821

---

## 1. Problema

Hoje, qualquer clique real (ou de teste) em "esqueci minha senha" pode deixar a conta inacessível se o e-mail de recuperação não for entregue. Isso acontece porque não há: (1) infraestrutura de mailer local funcional por padrão (falta o serviço `mailpit` no `docker-compose.yml`), (2) log de erro quando `Mail::send` falha, nem (3) qualquer forma de a pessoa reobter o token depois de expirado a não ser mexendo direto no banco. Em produção, uma falha de SMTP (fila cheia, credencial expirada, etc.) teria o mesmo efeito: usuário perde acesso à própria conta com um único clique, sem fallback.

Esse cenário já aconteceu nesta sessão de trabalho: o login de `isac@email.com` travou porque `forgotPassword` sobrescreveu a senha, o e-mail de recuperação não chegou (sem log do motivo) e não havia forma de recuperar o token — foi contornado manualmente via `tinker`, fora do fluxo normal do produto.

## 2. Achados confirmados

### 2.1 Senha é sobrescrita antes do e-mail ser enviado, sem tratamento de erro

`InvitationController::forgotPassword` (`backend/app/Http/Controllers/InvitationController.php:89-126`):
- Gera um token aleatório e já sobrescreve `$user->password` com o hash desse token, salvando imediatamente (`$user->password = Hash::make($token); $user->save();`, linhas 106-108) — **antes** de qualquer tentativa de envio do e-mail.
- O envio do e-mail (`Mail::send(...)`, linhas 117-123) não está envolvido em `try/catch`. Se falhar, a exceção sobe sem nenhum log (`InvitationController` não usa `Log::` em nenhum método) e a resposta HTTP de sucesso (`'Link de recuperação enviado para seu e-mail.'`, linha 125) ainda seria retornada caso o erro fosse silenciado — hoje uma exceção não tratada interromperia a resposta, mas sem deixar rastro do motivo.
- Resultado: a senha antiga do usuário já foi destruída no banco no momento em que o e-mail é despachado. Se o envio falhar por qualquer motivo, não há como o usuário recuperar acesso pelo fluxo normal.

### 2.2 Token de reset expira em 60 minutos sem forma de reemissão segura

O token gerado é armazenado em `Cache` por 60 minutos (`Cache::put($cacheKeyToken, $token, now()->addMinutes(60));`, linha 112). Como a senha antiga já foi sobrescrita no passo 2.1, se o token expirar antes do usuário conseguir usá-lo (por exemplo, por causa do atraso/falha do e-mail), a única forma de recuperar acesso é intervenção manual no banco — não há endpoint ou fluxo de produto para isso.

### 2.3 Ambiente local não tem serviço de mailer funcional

`docker-compose.yml` sobe apenas `mysql-db` e `admin`/adminer — não existe container `mailpit`, apesar de `.env` apontar `MAIL_MAILER=smtp` / `MAIL_HOST=mailpit`. Ou seja: hoje, testar `/api/forgot-password` localmente já reproduz o problema descrito em 2.1 de forma consistente, não é um cenário raro de produção.

## 3. Fora de escopo desta feature

- Qualquer alteração no fluxo de convite (`InvitationController::invite`) ou de verificação/ativação (`InvitationController::verify`) além do necessário para a correção de `forgotPassword` — esses dois métodos têm padrões próprios (ex.: `invite` também não loga falha de `Mail::send`, mas não sobrescreve senha de conta já ativa) e não fazem parte do achado 011.
- Regras de força de senha, 2FA, ou qualquer política de segurança de conta além da correção do fluxo de reset (já fora de escopo também no item de backlog original).
- Rate limiting do endpoint (`Cache::has($cacheKeyRateLimit)`, linha 99) — já existe e não foi identificado como parte do problema.
- Qualquer outro item do backlog (esta feature não agrupa itens; é só o 011).
