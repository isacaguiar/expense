# Plan — Recuperação de Acesso

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260930

---

## 1. Corrigir marca "Novemax" → "Shared Expense" nos e-mails (specify §2.3, §2.4)

- **Decisão técnica**: usar CID embedding (`$message->embed($path)`) nas 4 views Blade, em vez de hospedar a logo via URL pública. O Laravel injeta a variável `$message` em `$data` **antes** de renderizar a view (confirmado em `vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:298-320`), então ela já está disponível nas 4 views sem precisar transformar os 2 envios diretos (`Mail::send()` cru em `InvitationController`) em classes `Mailable`. Funciona offline (necessário para validar com Mailpit), funciona em clientes de e-mail que bloqueiam imagem remota por padrão, e não depende de `FRONTEND_URL`/`APP_URL` estarem corretos.
- Copiar `frontend/src/assets/images/logo-expense.png` para `backend/resources/images/mail/logo-expense.png` (fora de `public/`, pois a imagem só é lida do disco pelo `embed()`, nunca servida por HTTP).
- Em cada um dos 4 templates, trocar `<img src="https://novemax.com.br/assets/images/logo.png" alt="Novemax">` por `<img src="{{ $message->embed(resource_path('images/mail/logo-expense.png')) }}" alt="Shared Expense">`, e todo texto "Novemax"/"Novemax Soluções em Informática" por "Shared Expense" (título da aba, rodapé/copyright, h2/texto do convite).
- `backend/app/Http/Controllers/InvitationController.php:92` — assunto `'Recuperação de senha - Novemax'` → `'Recuperação de senha - Shared Expense'`.

## 2. Tela "Esqueci minha senha" — etapa 1 (specify §2.1, §2.2)

- Backend não muda — `POST /api/forgot-password` já atende (só e-mail, rate limit de 15 min, retorna 429 sem `retry_after`).
- Nova rota pública `/recuperar-acesso` em `frontend/src/App.tsx` (nome escolhido para não colidir com `/recuperar-senha` já existente, e por cobrir também o caso de reenvio de verificação de e-mail — specify §2.2).
- Novo componente `frontend/src/pages/forgot-password/ForgotPasswordFormCard.tsx` — card só com campo e-mail + botão, espelhando `frontend/src/pages/login/LoginFormCard.tsx` (MUI `TextField`/`Alert`/`Button`, sem react-hook-form — o projeto não usa essa lib).
- Nova página `frontend/src/pages/ForgotPasswordPage.tsx` — estado `email`/`submitting`/`error`/`success`, chamando `axios.post` direto com `API_BASE_URL` (`frontend/src/config.ts`), espelhando `frontend/src/pages/LoginPage.tsx`. Trata 200 e 422 (e-mail não encontrado) com a MESMA mensagem de sucesso genérica (não diferenciar na UI, para não piorar a enumeração que já existe no backend); 429 mostra a mensagem vinda do backend; demais erros, mensagem genérica. "Reenviar" = permitir novo submit do mesmo form (sem lógica extra de cooldown no cliente, já que o backend não devolve `retry_after`).
- Atualizar o link morto em `frontend/src/pages/login/LoginFormCard.tsx:151-153` para `<Link component={RouterLink} to="/recuperar-acesso">` (`RouterLink` já importado nesse arquivo).

## 3. Testes e validação end-to-end (specify §2.1-2.4)

- Backend: `backend/tests/Feature/EmailBrandingTest.php` (novo) — renderiza as 4 views e garante ausência da string "Novemax" + presença de `cid:` no `src` da logo. Rodar também as suítes existentes que já resolvem essas views de verdade (`InvitationControllerMailViewsTest.php`, `GroupMemberInvitationMailTest.php`, `PreRegisterCodeMailTest.php`) para confirmar que o `embed()` não quebra nada.
- Frontend: `frontend/src/pages/ForgotPasswordPage.test.tsx` (novo, espelha `LoginPage.test.tsx`/`AcceptInvitePage.test.tsx`); atualizar `frontend/src/pages/LoginPage.test.tsx:193` (asserção do `href` do link "Esqueci minha senha", hoje espera `'#'`, senão quebra).
- Validação manual local via Mailpit (`docker-compose.yml`, SMTP :1025, UI :8025): subir `mailpit`+`mysql-db`, backend (`php artisan serve`) e frontend (`npm run dev`), com `FRONTEND_URL=http://localhost:3000` temporário no `.env` local (não commitar — hoje aponta para produção, `https://expense-app.novemax.com.br`). Fluxo completo: login → "Esqueci minha senha" → `/recuperar-acesso` → enviar → conferir mensagem de sucesso e e-mail recebido no Mailpit (marca correta, sem "Novemax") → clicar no link → completar troca de senha em `/recuperar-senha` → conferir segundo e-mail ("Conta ativada com sucesso", também com marca correta) → repetir envio imediato para validar que a tela trata o 429 do rate limit corretamente.

## N. Ordem de execução

Item 1 (marca nos e-mails) deve ser concluído antes do item 3 (validação), já que a validação depende da marca já estar correta para ter algo a conferir visualmente. Item 2 (tela nova) é tecnicamente independente do item 1 — não compartilham arquivo —, mas ambos são pré-requisito do item 3. Ordem de execução: 1 → 2 → 3.
