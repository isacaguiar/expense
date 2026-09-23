# Plan — Login social via Google

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. Backend — iniciar o login e resolver o intent `login` no callback (specify §2.1, §2.2, §2.3)

- **Rota nova, pública: `GET /api/auth/google/login`** (`backend/routes/api.php`, ao lado de `GET /auth/google/callback`, fora do grupo `jwt.auth`). Método novo `GoogleAuthController::loginRedirect()`:
  ```php
  $token = Str::random(40);
  Cache::put(self::STATE_CACHE_PREFIX.$token, ['intent' => 'login'], now()->addMinutes(self::STATE_TTL_MINUTES));
  $url = Socialite::driver('google')->stateless()->with(['state' => $token])->redirect()->getTargetUrl();
  return redirect()->away($url);
  ```
  Diferença central em relação a `redirectUrl()` (o método existente para vínculo): este devolve um **redirect HTTP direto** (302), não JSON — porque não há usuário autenticado nem chamada XHR prévia; o botão do frontend pode ser um `<a href>` puro navegando direto para esta rota. `redirectUrl()` continua como está (autenticado, devolve JSON), sem mudança.

- **`GoogleAuthController::callback()` passa a resolver dois intents.** Refatoração: o fetch do usuário Google (`Socialite::driver('google')->stateless()->user()`) sai do bloco `if (intent === 'link')` e vira uma chamada única logo após validar o `state`, com o `try/catch` de hoje (mesmo tratamento de erro, mas cobrindo os dois intents). Depois do fetch, branch por `$state['intent']`:
  - `'link'` → lógica atual, inalterada (carrega `User::find($state['user_id'])`, seta `google_id`/`avatar_url`, salva, redireciona para `/profile?linked=success|error`).
  - `'login'` → novo método privado `handleLoginCallback(User $googleUser, string $frontendUrl)` (ver §2.3 do specify): procura usuário por `google_id` e, se não achar, por `email` (auto-vínculo); se nenhum existir, cria um novo (`password: null`, `email_verified_at: now()`, `google_id`, `avatar_url`, `name`, `email`). Em qualquer um dos três casos, garante `google_id`/`avatar_url` atualizados e `email_verified_at` preenchido antes de salvar (usuário achado por e-mail pode não ter esses campos ainda). Emite o JWT (`auth('api')->login($user)`) e delega a entrega do token ao mecanismo do §2.
  - Nem `'link'` nem `'login'` (ou ausente) → mantém o 501 atual, inalterado (cobre o teste existente `test_callback_returns_501_when_intent_is_not_link`... **esse teste precisa ser atualizado**, porque hoje ele grava `intent: 'login'` esperando 501 — com esta feature, `intent: 'login'` passa a ser válido. Ajustar o teste para gravar um intent realmente desconhecido, ex. `'bogus'`).
  - Corrida (`QueryException` em unique constraint de `email`/`google_id`, dois callbacks quase simultâneos criando a mesma conta nova) tratada como no fluxo `'link'` hoje: log + redirect de erro, sem 500.

- **Falha do Socialite ou de state para o intent `'login'`** segue o mesmo padrão de log já usado (`[google-login]` em vez de `[google-link]` no prefixo das mensagens), redirecionando para `"{$frontendUrl}/login?google_error=1"` em vez de `/profile?linked=error`.

- **Limitação aceita, documentada aqui em vez de resolvida:** se o `state` estiver ausente/expirado/adulterado, o método não sabe mais qual era o intent (a informação morre junto com o state) — o fallback continua indo para `/profile?linked=error` (comportamento de hoje, zero mudança nos testes existentes desse caminho: `test_callback_redirects_with_error_on_unknown_state`, `test_callback_redirects_with_error_on_garbage_state`). Na prática só acontece se o usuário demorar >5 min no consentimento do Google ou reenviar uma URL de callback já usada — não vale a complexidade de um segundo canal (ex. registrar 2 redirect URIs distintos no Google Cloud Console, um por intent) só para esse caso raro.

## 2. Backend — endpoint de troca do código pelo token (specify §2.1, §2.3)

Em vez de devolver o JWT cru na query string do redirect (exposição em histórico do navegador, `Referer`, logs de acesso — vedado no specify §2.1), `handleLoginCallback` gera um **código de uso único**, de vida curta, análogo ao `state` que o fluxo já usa:

```php
private const LOGIN_CODE_CACHE_PREFIX = 'google_login_code:';
private const LOGIN_CODE_TTL_MINUTES = 1;
```

- Emite o JWT, guarda `Cache::put(self::LOGIN_CODE_CACHE_PREFIX.$code, $jwt, now()->addMinutes(self::LOGIN_CODE_TTL_MINUTES))` (`$code = Str::random(40)`), redireciona para `"{$frontendUrl}/login?google_code={$code}"`.
- **Rota nova, pública: `GET /api/auth/google/exchange`** → `GoogleAuthController::exchangeLoginCode(Request $request)`: `Cache::pull(self::LOGIN_CODE_CACHE_PREFIX.$request->query('code'))` (uso único, mesmo padrão de `pullState`); se vazio/ausente, `401` (`{"message": "Código inválido ou expirado."}`); se válido, devolve o mesmo shape que `AuthController::respondWithToken` já usa hoje (`access_token`, `token_type`, `expires_in` — `auth('api')->factory()->getTTL() * 60`). Não reaproveita `respondWithToken` diretamente (é `protected` em `AuthController`, controller diferente) — replica as 3 linhas em vez de criar uma dependência entre os dois controllers só por isso.
- Prefixo de cache **diferente** do `google_oauth_state:` usado pelo `state` (namespaces distintos: um é o token de ida para o Google, o outro é o código de volta para o frontend — nunca se cruzam, mas nomes diferentes deixam isso óbvio em log/inspeção de cache).

## 3. Backend — `UserController::changePassword` não quebra sem senha local (specify §2.4)

- `changePassword` (`backend/app/Http/Controllers/UserController.php:114-137`) ganha uma checagem antes do `Hash::check`:
  ```php
  if ($user->password === null) {
      return response()->json([
          'errors' => ['current_password' => ['Esta conta não tem senha local definida.']],
      ], 422);
  }
  ```
  Mesmo formato de erro 422 que o `current_password` incorreto já usa hoje (`:127-131`) — o frontend de "Trocar senha" já sabe renderizar esse shape, nenhuma mudança necessária em `ChangePassword.tsx` para este caso.

## 4. Frontend — botão e retorno do fluxo (specify §2.5)

- **`LoginFormCard.tsx`**: importa `API_BASE_URL` de `../../config` (mesmo import que `LoginPage.tsx` já faz) e troca `href="#"` do botão "Google" (linha 179) por `` href={`${API_BASE_URL}/api/auth/google/login`} ``. MUI `Button` com `href` já renderiza como `<a>` — nenhuma outra mudança no componente.
- **`LoginPage.tsx`**: novo `useEffect` (padrão idêntico ao de `Profile.tsx:87-100` para `linked=`) usando `useSearchParams` de `react-router-dom`:
  - `google_code` presente → limpa a query string (`setSearchParams({}, { replace: true })`), chama `GET {API_BASE_URL}/api/auth/google/exchange?code=...`; sucesso: `setSession(data)` (de `../auth/session`, reaproveitando o helper já existente em vez de duplicar a gravação inline de `handleSubmit`) e `navigate('/meus-grupos')`; falha: `setError('Não foi possível concluir o login com o Google. Tente novamente.')` (mesmo estado `error` que o formulário de e-mail/senha já usa, mesma UI).
  - `google_error` presente → limpa a query string, mesma mensagem de erro.
  - Nenhum dos dois presentes → não faz nada (comportamento atual do login por e-mail/senha inalterado).
- Tipo de retorno do `exchange` é o `LoginResponse` já existente em `frontend/src/types/auth.ts` — nenhum tipo novo.

## N. Ordem de execução

- **§1 → §2** têm dependência técnica direta (o redirect do §1 não tem para onde mandar o usuário sem o código gerado em §2; na prática ambos são implementados juntos, no mesmo método `handleLoginCallback`).
- **§3** (guard de `changePassword`) é independente — pode ser feito em qualquer ordem, inclusive em paralelo.
- **§4** (frontend) depende de §1/§2 já estarem integrados na branch da feature para o `google_code`/`google_error` fazerem sentido, mas o teste do botão "Google" (asserção de `href`) pode ser escrito assim que a URL final for decidida, sem esperar o backend rodar de verdade.
- Critério de ordenação em `tasks.md`: backend primeiro (§1 → §2 → §3, nessa ordem — §3 é o menor risco, cabe intercalado), frontend por último (§4), consistente com o padrão já usado nas features anteriores deste projeto.
