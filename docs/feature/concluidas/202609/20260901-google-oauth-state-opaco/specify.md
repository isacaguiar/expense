# Specify — OAuth Google com state opaco

> Feature: troca o `state` do OAuth do Google de um blob `Crypt::encrypt` na URL por um token opaco com o contexto guardado server-side, para o callback parar de ser barrado pelo ModSecurity em produção. Bug escalado do BFF `google-vinculo-state-modsecurity` — a Triagem marcou "toca auth" (`docs/bugfix/README.md`), então segue como feature.

Versão: 1.0 · Criado em: 20260901

---

## 1. Problema

Vincular a conta Google (Minha Conta → "vincular Google") falha em produção com **`406 Not Acceptable` gerado pelo Mod_Security**.

- **Reprodução:** usuário autenticado pede a URL de consentimento em `GET /api/user/google/redirect-url`, abre a URL, consente no Google. O Google redireciona o browser para `https://expense-api.novemax.com.br/api/auth/google/callback?state=<blob>`. O ModSecurity (WAF global do Apache no HostGator compartilhado) recusa a request com 406 **antes** de ela chegar ao PHP — a tela do erro é o HTML padrão do Mod_Security, não do Laravel.
- **Esperado vs. atual:** esperado — o callback processa o vínculo e redireciona pra `{frontend}/profile?linked=success`. Atual — 406 do WAF, o `callback()` nunca roda.
- **Causa raiz:** [`backend/app/Http/Controllers/GoogleAuthController.php:21`](../../../../../backend/app/Http/Controllers/GoogleAuthController.php) — `redirectUrl()` monta o `state` como
  `Crypt::encryptString(json_encode(['intent' => 'link', 'user_id' => $user->id, 'exp' => ...]))`.
  Isso é ~200+ caracteres de base64 com `+`, `/`, `=` e uma estrutura JSON cifrada (`{"iv":...,"value":...,"mac":...}`) na query string. Alguma regra do OWASP CRS do ModSecurity lê esse padrão como payload de ataque e bloqueia. `callback()` (linha 44) recupera via `Crypt::decryptString($request->query('state'))` em `decodeState()` (linha 85).
- **Por que só apareceu agora:** a API `expense-api.novemax.com.br` só ficou de fato acessível em produção em 2026-09-01 (feature `20260831-deploy-backend-ssh`; antes o deploy FTP estava quebrado). O fluxo de vínculo nunca tinha rodado contra o WAF de produção.
- **Por que a correção é na app e não no WAF:** este cPanel não expõe gerência de ModSecurity (menu Segurança só tem SSH / IP Blocker / SSL / API Tokens / Hotlink). Desligar via `.htaccess` (`SecRuleEngine Off`) enfraqueceria a defesa da API inteira e é remendo. Além disso, `state` opaco é o que a RFC 6749 §10.12 recomenda — o valor não precisa carregar dado, só ser não-adivinhável e verificável no callback.

## 2. Requisitos

### 2.1 `state` passa a ser um token opaco

`redirectUrl()` gera `state = Str::random(40)` (só `[A-Za-z0-9]`, sem `+`/`/`/`=`) e o passa ao Socialite em `->with(['state' => $token])`. A URL de callback deixa de carregar qualquer payload cifrado — não casa com regra do WAF e fica curta.

### 2.2 Contexto do vínculo guardado server-side

O `['intent' => 'link', 'user_id' => $user->id]` que hoje vai dentro do `state` passa a ser gravado via `Cache::put("google_oauth_state:{$token}", [...], now()->addMinutes(5))` — TTL de 5 min, igual à expiração atual. `callback()` recupera com `Cache::pull(...)` (get + forget → **uso único**: um `state` já consumido não vale de novo; hoje o blob cifrado pode ser reenviado dentro da janela). Driver `file` (já ativo em produção, `storage/framework/cache/`) atende — **sem migration, sem Redis/DB novo**.

### 2.3 Comportamento e contrato preservados

- Rota, verbo e resposta de `GET /api/user/google/redirect-url` (`{ "url": "..." }`) e os redirects do `callback` (`{frontend}/profile?linked=success|error`) — **inalterados**. Nada muda pro `frontend`/`app`.
- Validações mantidas: `intent === 'link'`; `User::find(user_id)` existe; TTL. `Socialite::driver('google')->stateless()->user()` no callback — inalterado.
- `state` ausente / desconhecido / expirado / já consumido → mesmo `redirect()->away("{frontend}/profile?linked=error")` de hoje.

### 2.4 Testes de feature atualizados

Ajustar os testes do fluxo Google em `backend/tests/Feature/` para o novo mecanismo (`Cache::fake()` ou store `array`):
- `redirectUrl` grava o contexto no cache e devolve `url` cujo `state` tem 40 chars sem caractere especial;
- `callback` com token válido vincula (`google_id`/`avatar_url` gravados) e **consome** o token (2ª chamada com o mesmo → `linked=error`);
- token ausente / expirado / `intent` != `link` → comportamento atual.

### 2.5 Desbloqueio de produção

O merge + deploy desta feature já resolve o 406 (o `state` novo não trafega dado cifrado). **Não** depende de mexer no ModSecurity. Se o dono quiser destravar antes do merge, é ticket no HostGator — fora desta feature.

## 3. Fora de escopo desta feature

- Implementar **login** via Google (`intent` de login) — `callback()` já tem o stub `501`; continua como está.
- Gerenciar o ModSecurity: `.htaccess`, `SecRuleRemoveById`, ticket no HostGator — a feature remove o gatilho, não administra o WAF.
- Trocar o driver de cache do projeto (Redis/DB) — `file` serve.
- Evoluções de OAuth sem relação com o 406: PKCE, refresh token, revogação de vínculo, tela de "desvincular".
- Frontend — nenhuma mudança esperada (a tela só chama `/redirect-url` e abre a `url`).
- `config('services.frontend_url')` usado no redirect do callback (linha 42) — se estiver com valor errado em produção é item à parte, não é o 406.
