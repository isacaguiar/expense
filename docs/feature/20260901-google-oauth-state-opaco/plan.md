# Plan — OAuth Google com state opaco

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260901

---

## 1. `redirectUrl()` — `state` opaco + contexto no cache (`specify.md` §2.1, §2.2)

`backend/app/Http/Controllers/GoogleAuthController.php`, método `redirectUrl()`:

- Duas constantes privadas na classe: `STATE_CACHE_PREFIX = 'google_oauth_state:'`, `STATE_TTL_MINUTES = 5`.
- Trocar o bloco `Crypt::encryptString(json_encode([...]))` por:
  ```php
  $token = Str::random(40);

  Cache::put(
      self::STATE_CACHE_PREFIX.$token,
      ['intent' => 'link', 'user_id' => $user->id],
      now()->addMinutes(self::STATE_TTL_MINUTES),
  );
  ```
  — sem `exp` no payload: o TTL do cache **é** a expiração.
- `Socialite::driver('google')->stateless()->with(['state' => $token])->redirect()->getTargetUrl()` — só o valor de `state` muda.
- Resposta `response()->json(['url' => $url])` — inalterada.
- **Por que `Str::random(40)`**: só `[A-Za-z0-9]`, sem `+`/`/`/`=` → não casa com regra do ModSecurity, URL curta. 40 chars ≈ 238 bits — colisão desprezível, sem loop de checagem.
- **Por que `Cache` e não tabela**: sem migration; `Cache::pull` (§2) dá uso único de graça; driver `file` já ativo em produção (`storage/framework/cache/`). Contrapartida aceita: `cache:clear`/`optimize:clear` (o deploy roda) invalida `state` em voo — o usuário refaz o "vincular". Auditar tentativas de vínculo não é requisito.

## 2. `callback()` — recupera pelo token e consome (`specify.md` §2.2, §2.3)

Mesmo arquivo, método `callback()`:

- `$token = $request->query('state');` → se vazio, `redirect()->away("{$frontendUrl}/profile?linked=error")` (guard atual de `decodeState`).
- `$state = Cache::pull(self::STATE_CACHE_PREFIX.$token);` — **get + forget**: token consumido não vale de novo (hoje o blob cifrado pode ser reenviado dentro dos 5 min).
- `if ($state === null) → redirect .../profile?linked=error`.
- `if (($state['intent'] ?? null) !== 'link') → response()->json(['message' => 'Login via Google ainda não implementado.'], 501);` — **inalterado**.
- `$user = User::find($state['user_id'] ?? null);` → sem user, `linked=error`.
- Do `Socialite::driver('google')->stateless()->user()` em diante (set `google_id`/`avatar_url`, `save()` com `catch (QueryException) → linked=error`, sucesso → `linked=success`) — **inalterado**. A colisão de `google_id` já linkado a outro usuário continua sendo pega pelo `QueryException` do unique constraint (é o que o teste `..._already_linked_to_another_user` exercita).
- Remover o método privado `decodeState()` (sem uso).
- Imports: `-use Illuminate\Support\Facades\Crypt;`, `+use Illuminate\Support\Facades\Cache;`, `+use Illuminate\Support\Str;`.

## 3. Testes (`specify.md` §2.4)

`backend/tests/Feature/GoogleAuthControllerTest.php` (o `phpunit.xml` já roda `CACHE_DRIVER=array`, isolado por execução):

- `setUp()`: `parent::setUp(); Cache::flush();` — evita vazamento de `state` entre métodos.
- Trocar o helper `signedLinkState()` por:
  ```php
  private function linkState(int $userId): string
  {
      $token = Str::random(40);
      Cache::put("google_oauth_state:{$token}", ['intent' => 'link', 'user_id' => $userId], now()->addMinutes(5));
      return $token;
  }
  ```
- `test_redirect_url_returns_a_google_authorize_url_with_signed_state` → renomear para `..._with_opaque_state`; trocar a asserção de decrypt por:
  ```php
  $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $query['state']);
  $cached = Cache::get("google_oauth_state:{$query['state']}");
  $this->assertSame('link', $cached['intent']);
  $this->assertSame($user->id, $cached['user_id']);
  ```
- `test_callback_links_google_account_to_user_from_valid_state` → usa `linkState($user->id)`; resto igual.
- **Novo** `test_callback_state_is_single_use`: `linkState`, 1ª chamada → `linked=success`; 2ª chamada com o **mesmo** token → `linked=error` e `google_id` não muda de novo.
- `test_callback_redirects_with_error_on_expired_state` → renomear para `..._on_unknown_state`, chamar com `?state=`.Str::random(40) que nunca foi gravado (cobre expirado + ausente + adulterado num caso). Manter `test_callback_redirects_with_error_on_tampered_state` (string com caractere fora do alfabeto) se quiser explicitar.
- `test_callback_redirects_with_error_when_google_id_already_linked_to_another_user` → usa `linkState`; resto igual.
- `test_callback_returns_501_when_intent_is_not_link` → `$token = Str::random(40); Cache::put("google_oauth_state:{$token}", ['intent' => 'login'], now()->addMinutes(5));` e chamar `?state={$token}`.
- `test_redirect_url_requires_authentication` — inalterado.

## 4. Config / infra — nada muda

- Sem migration, sem alteração em `config/`, sem `.env` novo. Produção já roda cache `file` (default do `config/cache.php`; o `deploy-backend.yml` não seta `CACHE_DRIVER`). *(Opcional, fora do escopo: o workflow passar a gravar `CACHE_DRIVER=file` explícito no `.env` — clareza, não necessidade.)*
- `frontend`/`app` — nenhuma mudança (só chamam `/redirect-url` e abrem a `url`).

## 5. Ordem de execução

Sem dependência entre itens — §1 e §2 são o mesmo arquivo, §3 acompanha (TDD: teste primeiro, ver `docs/sdd/04-implementation.md`). Uma task de backend só; um PR contra `dev`.

Gate: `merge em dev` = humano (revisão do PR — toca auth, aciona `security-reviewer`). O `merge em main` + deploy é o que resolve o 406 em produção.
