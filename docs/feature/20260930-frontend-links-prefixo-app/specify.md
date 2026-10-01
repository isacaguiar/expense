# Specify — Links gerados pelo backend sem o prefixo /app do frontend

> Feature: várias rotas do backend montam URLs para o frontend concatenando `config('services.frontend_url')` direto com um path (`/recuperar-senha`, `/aceitar-convite`, `/login`, `/profile`), sem o prefixo `/app` sob o qual a SPA é servida em produção — o resultado é 404 do próprio host (a SPA nem chega a carregar). Origem: achado durante a investigação de `/novo-bug login-producao` (sessão de 2026-09-30) — o usuário não conseguia logar, a causa raiz real era `email_verified_at` nulo (ver `docs/bugfix/concluidos/202609/20260930-login-mensagem-email-nao-verificado.md`), e o caminho de recuperação indicado pelo próprio sistema ("Esqueci minha senha" → `POST /api/forgot-password`) também estava quebrado por este bug.

Versão: 1.0 · Criado em: 20260930

---

## 1. Problema

O frontend web (`expense/frontend`) é servido em produção sob o prefixo `/app` (`frontend/vite.config.js:18` → `base: '/app/'`; `frontend/src/main.tsx:18` → `<BrowserRouter basename="/app">`). Vários pontos do backend, porém, constroem links para o frontend concatenando `config('services.frontend_url')` (env `FRONTEND_URL`, hoje `https://expense.novemax.com.br`, sem `/app`) diretamente com um path React Router, sem considerar esse prefixo. O resultado é uma URL que não existe no servidor (404 do host, antes de qualquer JS/React rodar) — não um 404 "dentro" da SPA.

Isso quebra, hoje, pelo menos três fluxos de usuário final que dependem de um link clicável enviado por e-mail ou por redirect do navegador.

## 2. Achados confirmados

### 2.1 Recuperação de senha — reproduzido em produção (2026-09-30)

- `backend/app/Http/Controllers/InvitationController.php:84` — `$resetLink = config('services.frontend_url')."/recuperar-senha?email={$user->email}&token={$token}";`
- Reprodução real: `POST https://expense-api.novemax.com.br/api/forgot-password` com `email=isacaguiar@gmail.com` retornou 200 ("Link de recuperação enviado para seu e-mail.") e o e-mail chegou de fato (confirma que o fix de SMTP de produção, `docs/bugfix/20260921-cadastro-codigo-email-nao-chega.md`, está funcionando). O link do e-mail era `https://expense.novemax.com.br/recuperar-senha?email=isacaguiar@gmail.com&token=<token>` — abrir esse link no navegador devolve a página 404 do host ("Ops, Não encontramos essa página!"), não a tela de redefinição de senha.
- Confirmado que o fix é só o prefixo: a mesma URL com `/app` inserido manualmente (`https://expense.novemax.com.br/app/recuperar-senha?email=...&token=...`) carrega corretamente a tela "Redefinir senha" (`frontend/src/pages/AcceptInvitePage.tsx` com `mode="reset"`, rota `frontend/src/App.tsx:45`), com o e-mail já preenchido no texto da tela.

### 2.2 Convite de grupo — mesmo padrão de código, não reproduzido ao vivo nesta sessão

- `backend/app/Mail/UserInvitedMail.php:33` — `$activationLink = config('services.frontend_url')."/aceitar-convite?email={$this->user->email}&token={$this->token}";` — mesma rota de frontend (`frontend/src/App.tsx:44`), mesma falta de prefixo `/app`.

### 2.3 Retorno do login/vínculo Google — dois defeitos empilhados, nenhum ainda reproduzido ponta a ponta (bloqueado hoje pelo ModSecurity, ver §3)

- `backend/app/Http/Controllers/GoogleAuthController.php` constrói `$frontendUrl = config('services.frontend_url')` (linha 74) e usa em: `"{$frontendUrl}/profile?linked=error"` (linhas 81, 101, 112, 128), `"{$frontendUrl}/profile?linked=success"` (linha 133), `"{$frontendUrl}/login?google_error=1"` (linhas 171, 187), `"{$frontendUrl}/login?google_code={$code}"` (linha 226) — nenhum com `/app`.
- O path `/login` usado nessas quatro últimas chamadas **não existe como rota do frontend**: `frontend/src/App.tsx:43` registra a tela de login na raiz (`path="/"`), não em `/login`. `frontend/src/pages/LoginPage.tsx:18-40` é quem lê `google_code`/`google_error` da query string, e só roda montado na rota `/`. Ou seja, mesmo corrigindo só o prefixo (`/app/login?...`), o caminho ainda cairia no catch-all 404 da própria SPA (`frontend/src/App.tsx:78`) por o path estar errado, não só faltando prefixo.
- `/profile` existe como rota (`frontend/src/App.tsx:72`), então esses redirects precisam só do prefixo `/app`, sem mudança de path.

## 3. Fora de escopo desta feature

- **O bloqueio do ModSecurity no callback do Google (`iss`/`scope` na query string, 406 antes de chegar no Laravel)** — já é outra feature em andamento, `docs/feature/20260923-google-callback-modsecurity-iss/`. Esta feature aqui cobre o bug de path/prefixo do redirect *de volta* ao frontend (§2.3), que é um defeito independente e aditivo: mesmo que o ModSecurity seja resolvido, o redirect para `/login` sem `/app` ainda quebraria o retorno do login Google.
- **Construir a tela/formulário de "solicitar recuperação de senha"** (o link "Esqueci minha senha" na tela de login hoje é `href="#"`, sem nenhuma tela que chame `POST /api/forgot-password` — ver `frontend/src/pages/login/LoginFormCard.tsx:151-153` e `docs/feature/concluidas/202608/20260819-novo-layout-tela-login/specify.md:57`, que já registrou isso como propositalmente fora de escopo na época). Essa é uma tela nova que nunca existiu — desenvolvimento novo, não bug — e fica como decisão separada (backlog ou feature própria), não entra aqui. Esta feature só garante que, uma vez a pessoa tendo um link de recuperação/convite/retorno do Google em mãos (gerado via e-mail ou via chamada direta à API), ele resolva para a tela certa.
- **Qualquer mudança de UX/copy nas telas de destino** (`AcceptInvitePage`, `LoginPage`, `Profile`) — o problema é só a URL não resolver, não o conteúdo da tela quando ela resolve.
