# Specify — Recuperação de Acesso (tela "esqueci minha senha" + marca correta nos e-mails)

> Feature: completa o fluxo de autoatendimento de recuperação de senha / confirmação de e-mail, criando a tela que falta no frontend e corrigindo a marca errada ("Novemax") exibida nos e-mails do sistema. Origem: pedido direto do usuário nesta sessão, depois de precisar atualizar `email_verified_at` manualmente no banco para liberar acesso — e, ao investigar o envio de e-mail, a correção da marca escalou do BFF (`docs/bugfix/20260930-logo-email-marca-novemax.md`) por tocar `InvitationController.php` (controller de autenticação).

Versão: 1.0 · Criado em: 20260930

---

## 1. Problema

O usuário não tem como se autoatender quando precisa confirmar o e-mail ou recuperar a senha: o link "Esqueci minha senha" na tela de login é morto, não existe nenhuma tela pública que peça só o e-mail e dispare o envio do link de recuperação, e os e-mails que o backend já sabe enviar carregam a marca errada ("Novemax", de outro projeto/cliente, não "Shared Expense"). Resultado prático: usuários com `email_verified_at` nulo ficam bloqueados no login sem conseguir se desbloquear sozinhos, e quem recebe os e-mails do sistema vê uma marca que não é a do produto.

## 2. Achados confirmados

### 2.1 Link "Esqueci minha senha" morto e etapa 1 do fluxo inexistente

`frontend/src/pages/login/LoginFormCard.tsx:151-153` tem `<Link href="#">Esqueci minha senha</Link>`, sem destino. A rota `/recuperar-senha` (`frontend/src/App.tsx`, via `AcceptInvitePage mode="reset"`) já existe mas cobre só a ETAPA 2 do fluxo (definir nova senha a partir de `email`+`token` recebidos por query string). Não existe tela pública para a ETAPA 1 (pedir só o e-mail e disparar o envio). O backend já tem o endpoint pronto e funcional: `POST /api/forgot-password` (`backend/app/Http/Controllers/InvitationController.php:62-109`) — valida e-mail, aplica rate limit de 15 min via `Cache`, gera token, monta o link e envia o e-mail — mas não é chamado de nenhum lugar do frontend hoje.

### 2.2 A mesma tela cobre "reenviar verificação de e-mail"

`AuthController::login` (`backend/app/Http/Controllers/AuthController.php:36-43`) bloqueia login com 403 quando `email_verified_at` é nulo, orientando "Use Esqueci minha senha para confirmar seu e-mail". `InvitationController::verify` (linha 41) já marca `email_verified_at = $user->email_verified_at ?? now()` ao definir a nova senha a partir do token. Ou seja, o mesmo fluxo de "esqueci minha senha" já resolve "confirmar e-mail não verificado" — não é necessária nenhuma tela ou endpoint adicional dedicado a reenvio de verificação.

### 2.3 Marca "Novemax" hardcoded em todos os e-mails do sistema

Os 4 templates Blade de e-mail (`backend/resources/views/email/pre-register-code.blade.php`, `password-reset.blade.php`, `invitation.blade.php`, `activation-confirmation.blade.php`) referenciam `<img src="https://novemax.com.br/assets/images/logo.png" alt="Novemax">` e textos "Novemax"/"Novemax Soluções em Informática" no título da aba, no corpo e no rodapé — e, em `password-reset`, também no assunto do e-mail (`InvitationController.php:92`, `'Recuperação de senha - Novemax'`). É resquício de outro projeto/cliente; a marca correta do produto é "Shared Expense", mesmo wordmark usado em `frontend/src/pages/login/LoginBrandingPanel.tsx` e `frontend/src/layouts/BrandWordmark.tsx`.

### 2.4 Correção da marca escala para feature (não é BFF)

Registrado em `docs/bugfix/20260930-logo-email-marca-novemax.md`: a correção do assunto do e-mail de recuperação de senha exige editar `InvitationController.php`, controller de autenticação — a Triagem do BFF marca a caixa "Auth/autorização/dado sensível" (`docs/bugfix/README.md`), escalando a correção para o fluxo de feature completo. Como a tela da etapa 1 (§2.1) também não tem task aplicável e precisaria de feature própria, as duas frentes foram unificadas aqui por decisão do usuário.

## 3. Fora de escopo desta feature

- Corrigir a enumeração de e-mail em `/api/forgot-password` (resposta 422 em inglês para e-mail inexistente vs. 200 em português para existente) — vira item de `docs/backlog/` (**item 119**). **Risco aceito nesta feature (revisão de segurança, 2026-10-30):** a tela trata o 422 como sucesso genérico, mas o **429** ("já solicitou recentemente") e o **500** só existem para conta existente; quem pede duas vezes seguidas ainda descobre, pela tela, que a conta existe. A API já vazava isso por status e corpo.
- Adicionar rate limit por IP (hoje o limite é só por e-mail-alvo, contornável trocando o e-mail a cada request) — vira item de `docs/backlog/` (**item 118**).
- Trocar a comparação de token `===` por `hash_equals` (timing attack de baixo risco prático) — vira item de `docs/backlog/` (**item 120**).
- Login social (Google/Microsoft) — já coberto por outras features, não é tocado aqui.
- Refatorar os 2 envios de e-mail diretos (`Mail::send()` cru em `InvitationController`) para classes `Mailable` dedicadas — não é necessário para a correção da logo (ver `plan.md` §1); fica como possível tech-debt isolado, não entra nesta feature.
