# Plan — Links gerados pelo backend sem o prefixo /app do frontend

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260930

---

## 1. Config único para "URL da SPA" — sem tocar em `FRONTEND_URL`/CORS (specify §2.1, §2.2, §2.3)

- **Decisão:** adicionar uma chave nova em `backend/config/services.php`, ao lado de `frontend_url` (linha 40): `'frontend_app_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/').'/app'`. Essa chave vira a única fonte para "URL base da SPA, já com o prefixo de deploy" — o mesmo `/app` fixo que `frontend/vite.config.js:18` (`base: '/app/'`) já usa do lado do frontend.
- **Por que essa abordagem e não outra:**
  - Mudar o valor da env `FRONTEND_URL` em si para já incluir `/app` foi descartado: `backend/config/cors.php:11` usa `env('FRONTEND_URL')` direto em `allowed_origins`, que o Laravel compara contra o header `Origin` do navegador — e `Origin` nunca inclui path. Um `FRONTEND_URL` com `/app` pararia de bater com o `Origin` real (`https://expense.novemax.com.br`, sem path) e quebraria CORS silenciosamente. Duas responsabilidades (origem para CORS vs. base de link clicável) não podem compartilhar a mesma env.
  - Reaproveitar a env existente (em vez de criar uma `FRONTEND_APP_URL` nova) evita mexer em `.github/workflows/deploy-backend.yml`/secrets — o prefixo `/app` é um detalhe de topologia de deploy fixo (mesmo valor em todo ambiente, local ou produção; `vite.config.js` não condiciona `base` por ambiente), não uma config que varia e precisa de secret próprio.
  - `rtrim(..., '/')` é só defesa contra a env vir com `/` no final (ex.: `https://expense.novemax.com.br/`) e gerar `//app`; não é validação de input de usuário, é normalização de um valor controlado pelo operador do deploy.
- **Arquivos:** `backend/config/services.php`.

## 2. Trocar os 2 call sites de e-mail para `services.frontend_app_url` (specify §2.1, §2.2)

- **Decisão:** `InvitationController::forgotPassword` (`backend/app/Http/Controllers/InvitationController.php:84`, variável `$resetLink`) e `UserInvitedMail` (`backend/app/Mail/UserInvitedMail.php:33`, variável `$activationLink`) trocam `config('services.frontend_url')` por `config('services.frontend_app_url')`. Os paths (`/recuperar-senha`, `/aceitar-convite`) já estão corretos — só a base muda.
- **Por que essa abordagem e não outra:** é a mudança mínima que resolve o defeito confirmado em §2.1/§2.2 do specify sem tocar em mais nada do fluxo de e-mail (geração de token, rate limit, envio) — nenhum dos dois já tinha bug além da URL.
- **Arquivos:** `backend/app/Http/Controllers/InvitationController.php`, `backend/app/Mail/UserInvitedMail.php`.

## 3. GoogleAuthController: prefixo + path `/login` inexistente (specify §2.3)

- **Decisão:**
  1. `$frontendUrl = config('services.frontend_url')` (`backend/app/Http/Controllers/GoogleAuthController.php:74`) passa a ler `config('services.frontend_app_url')`.
  2. Os 4 redirects para `"{$frontendUrl}/profile?linked=..."` (linhas 81, 101, 112, 128, 133) só precisam da base corrigida — `/profile` já existe como rota (`frontend/src/App.tsx:72`), path não muda.
  3. Os 4 redirects para `"{$frontendUrl}/login?google_..."` (linhas 171, 187, 216, 226) perdem o segmento `/login`, que não existe como rota do frontend — viram `"{$frontendUrl}?google_error=1"` / `"{$frontendUrl}?google_code={$code}"`. Quem lê esses query params é `frontend/src/pages/LoginPage.tsx:18-40`, montado na rota raiz (`frontend/src/App.tsx:43`, `path="/"`), não em `/login`.
- **Por que essa abordagem e não outra:**
  - Alternativa descartada: criar uma rota `/login` no frontend (alias para `/`) em vez de corrigir o backend. Isso resolveria o sintoma mas deixaria duas URLs válidas para a mesma tela sem motivo de produto — a `LoginPage` já está corretamente montada na raiz; o path errado está só no literal do backend. Corrigir o ponto único e errado é a mudança menor (`CLAUDE.md` raiz, "não adicionar abstração além do necessário").
  - Este item é aditivo ao bloqueio do ModSecurity já em investigação (`docs/feature/20260923-google-callback-modsecurity-iss/`, specify §3 desta feature): mesmo com o WAF liberado, sem esta correção o redirect de volta continuaria caindo em 404.
- **Arquivos:** `backend/app/Http/Controllers/GoogleAuthController.php`.

## 4. Testes existentes que fixam a URL antiga precisam ser atualizados (specify §2.1-§2.3)

- **Decisão:** não é um teste de regressão novo isolado — são testes já existentes que hardcodeiam a forma antiga da URL (sem `/app`, ou com `/login`) e vão falhar assim que os itens 2 e 3 forem aplicados, porque passam a esperar literalmente a URL errada. Cada um precisa ser ajustado para fixar `config(['services.frontend_app_url' => 'http://localhost:3000/app'])` (em vez de só `frontend_url`) e para esperar `/app` no path (e, nos casos de retorno de login, sem `/login`):
  - `backend/tests/Feature/InvitationControllerForgotPasswordTest.php` (asserção em `resetLink`, hoje checa só `str_starts_with($data['resetLink'], config('services.frontend_url'))`).
  - `backend/tests/Feature/GroupMemberInvitationMailTest.php` (asserção equivalente em `$activationLink`, linha ~77).
  - `backend/tests/Feature/GoogleAuthControllerTest.php` (todas as 9 ocorrências de `config(['services.frontend_url' => 'http://localhost:3000'])` nos testes de `callback`/`handleLoginCallback`, mais as asserções de URL de redirect que hoje esperam `/profile`/`/login` sem prefixo).
- **Por que registrar como item do plan em vez de só mencionar em tasks:** o critério de aceite de cada task em `tasks.md` referencia qual teste existente muda de expectativa — importante não confundir "teste quebrou porque a correção está certa" com regressão real durante a execução.
- **Arquivos:** os 3 arquivos de teste acima.

## N. Ordem de execução

Sem dependência técnica forte entre os itens 2 e 3 (arquivos de produção diferentes, controllers/mailable independentes) — mas ambos dependem do item 1 (o config novo) existir primeiro, e o item 4 depende de 2 e 3 estarem feitos para saber exatamente o que corrigir em cada teste. Ordem sugerida para `tasks.md`:

1. Item 1 — criar `services.frontend_app_url`.
2. Item 2 — `InvitationController` + `UserInvitedMail` (menor escopo, 1 call site cada) + ajuste dos 2 testes correspondentes do item 4.
3. Item 3 — `GoogleAuthController` (mais call sites) + ajuste do teste correspondente do item 4.
4. `php artisan test` completo ao final, para pegar qualquer teste não listado em §4 que também hardcode a URL antiga.
