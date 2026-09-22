# Plan — E-mail verificado obrigatório

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. `POST /register` legado deixa de criar conta sem verificação (specify §2.1)

- **Decisão**: depreciar o endpoint. `AuthController::register()` (`backend/app/Http/Controllers/AuthController.php:13-29`) passa a responder `410 Gone` com corpo `{"message": "Este endpoint foi descontinuado. Use /api/pre-register."}`, **sem** chamar `User::create()`.
- **Por que essa abordagem e não outra**:
  - Reaproveitar a lógica do `register()` para criar um `UserPreCreate` e disparar o código de verificação (em vez de só bloquear) foi descartado: isso ainda seria uma mudança de contrato de resposta (deixa de devolver `201` com usuário pronto para devolver "confira seu e-mail"), custa mais código para um endpoint **sem cliente conhecido** hoje (achado do próprio item de backlog 042), e violaria "não adicionar abstração além do necessário" do `CLAUDE.md` raiz.
  - `410 Gone` (em vez de simplesmente devolver `403`/`404` genérico) é a resposta HTTP correta para "endpoint existiu e foi descontinuado de propósito" — é a parte "assistida" da depreciação exigida por `00-constitution.md` §4.1: quem ainda chamar a rota recebe um erro explícito com a rota de substituição, não um 500 nem um comportamento silenciosamente diferente.
  - Não é preciso remover a rota de `routes/api.php:18` nem o método `register()` do controller — o método é reescrito para responder o 410, mantendo o histórico de onde a rota está registrada.
- **Arquivos afetados**: `backend/app/Http/Controllers/AuthController.php` (método `register`).

## 2. `login()` recusa conta com `email_verified_at` nulo (specify §2.2 + decisão de §2.4)

- **Decisão**: em `AuthController::login()` (`backend/app/Http/Controllers/AuthController.php:31-55`), depois de `Auth::guard('api')->attempt($credentials)` ter sucesso, checar `Auth::guard('api')->user()->email_verified_at`. Se nulo:
  1. Invalidar o token recém-emitido com `Auth::guard('api')->logout()` (guard `api` é `jwt`, `config/auth.php:15-18` — `logout()` no guard JWT invalida o token atual; evita deixar um token válido emitido para uma sessão que a resposta está recusando).
  2. Logar com `Log::warning` (mesmo padrão das outras falhas de login já existentes, `:44`), incluindo o e-mail.
  3. Responder `403` com `{"error": "E-mail não verificado. Use \"Esqueci minha senha\" para confirmar seu e-mail e definir uma nova senha."}`.
- **Por que essa abordagem e não outra**:
  - Checar **depois** do `attempt()` (não antes, buscando o usuário por e-mail cru) evita criar um oráculo de enumeração de e-mail diferente do que já existe — a mensagem de "e-mail não verificado" só é exibida para quem **já provou saber a senha correta**, igual ao raciocínio do specify §2.3.
  - Mensagem aponta explicitamente para "esqueci minha senha" (decisão do specify §2.4) em vez de para um reenvio de código de verificação novo — não é preciso nenhuma rota nova: o fluxo de recuperação de senha já passa por `InvitationController::verify()` (`:29-41`), que já seta `email_verified_at = email_verified_at ?? now()` ao definir a nova senha.
  - `403` (não `401`) porque a credencial está correta — é autorização (conta não habilitada), não autenticação, que falhou.
- **Arquivos afetados**: `backend/app/Http/Controllers/AuthController.php` (método `login`).

## 3. Ordem de execução

Sem dependência técnica entre os itens 1 e 2 — tocam o mesmo arquivo mas métodos diferentes e não compartilham lógica nova. `tasks.md` ordena por item de backlog (042 antes de 043), só para manter rastreabilidade 1:1 com a ordem em que os itens foram descobertos.
