# Plan — Storage e download de comprovante de pagamento

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260828

---

## 1. Armazenamento por grupo em disco privado (`specify.md` §2.1)

- **Disco:** usar o disco `local` já configurado em `config/filesystems.php` (`root => storage_path('app')`). Não criar disco novo.
- **Helper de path:** um método privado em `ExpenseController` (ou um pequeno `ProofStorage` invocável) que recebe `UploadedFile $file, int $groupId` e retorna o path gravado: `$file->storeAs("comprovantes/{$groupId}", Str::uuid().'.'.$file->getClientOriginalExtension(), 'local')`. Decisão: extrair para uma classe `App\Support\ProofStorage` com `store()`, `resolveDisk(string $path)` e `delete(string $path)` — os três métodos são compartilhados por `pay`, `unpay` e `confirmSettlement`, e `resolveDisk` também é usado pelo controller de download (§3). Evita repetir a regra "novo vs. legado" em quatro lugares.
- **`pay()`** (`ExpenseController` ~linha 740): trocar `->store('comprovantes', 'public')` por `ProofStorage::store($request->file('comprovante'), $expense->group_id)`.
- **`confirmSettlement()`** (~linha 834): trocar `->store('comprovantes-settlements', 'public')` por `ProofStorage::store($request->file('comprovante'), (int) $groupId)`; antes do `updateOrCreate`, se já existir `SettlementConfirmation` para a mesma chave com `proof_path`, `ProofStorage::delete()` no path antigo.
- **Por que não manter o disco `public` + `storage:link` no deploy:** o symlink não sobrevive ao deploy FTP e mantém o comprovante financeiro acessível sem auth. Servir por rota (§2) elimina as duas coisas de uma vez. Escolha registrada no brainstorming de 2026-08-28.
- **Sem migration:** as colunas já são varchar e já guardam path. Confirmado em `2026_08_22_200000_add_payment_proof_path_to_ex_quotas_table.php` e `2026_08_25_220000_create_ex_settlement_confirmations_table.php`.

## 2. Rota de download autenticada por URL assinada (`specify.md` §2.2)

- **Rota** em `routes/api.php`, **fora** do grupo `->middleware('jwt.auth')`, com `->middleware('signed')` e nome `proofs.show`:
  `Route::get('/groups/{groupId}/proofs/{type}/{id}', [ProofDownloadController::class, 'show'])->middleware('signed')->name('proofs.show');`
- **`ProofDownloadController@show($groupId, $type, $id)`** (controller novo, fino):
  1. `$type` deve ser `quota` ou `settlement` — senão 404.
  2. `quota`: `Quota::with('expense')->findOrFail($id)`; abortar 404 se `$quota->expense->group_id !== (int) $groupId`. `settlement`: `SettlementConfirmation::findOrFail($id)`; abortar 404 se `$confirmation->group_id !== (int) $groupId`.
  3. `$path = $quota->payment_proof_path` (ou `$confirmation->proof_path`); 404 se vazio.
  4. `$disk = ProofStorage::resolveDisk($path)` — regra: se `preg_match('#^comprovantes/\d+/#', $path)` → `local`; senão (`comprovantes/...` ou `comprovantes-settlements/...` sem id de grupo) → `public`.
  5. `abort_unless(Storage::disk($disk)->exists($path), 404)`; `return Storage::disk($disk)->download($path)`.
- **Sem `auth()` aqui:** a rota está fora do `jwt.auth` por necessidade (aba de browser não manda Bearer). A autorização é: assinatura válida (middleware `signed`) + assinatura só emitível dentro de contexto autenticado com membership check (§3) + revalidação recurso×grupo no passo 2. Documentado no ADR-005 (§5).
- **`type` como enum informal:** `{quota, settlement}` como string na URL; sem `Route::pattern`, validação inline no controller. Simples, dois casos só.

## 3. URL assinada temporária nos accessors (`specify.md` §2.3)

- **`Quota::getPaymentProofUrlAttribute()`**:
  ```php
  if (! $this->payment_proof_path) return null;
  return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
      'groupId' => $this->expense->group_id, 'type' => 'quota', 'id' => $this->id,
  ]);
  ```
- **`SettlementConfirmation::getProofUrlAttribute()`**: igual, com `'groupId' => $this->group_id, 'type' => 'settlement'`.
- **Eager loading:** localizar onde `Quota` é serializada com o comprovante — `ExpenseController@collectCycleEntries`/`decorateWithConfirmations` (~linhas 1094/1116/1152) e `ExpenseView` (`GET /expenses/{id}` → `ExpenseController@show` carrega `quotas`). Garantir `->with('expense')` ou `->load('expense')` nesses pontos para o accessor não disparar N+1. `SettlementConfirmation` não precisa (usa coluna própria).
- **TTL 30 min:** constante local no accessor; se expirar, a próxima resposta da tela traz URL nova. Sem refresh automático no cliente.

## 4. Frontend (`specify.md` §2.6)

- **Código de app:** nenhuma mudança esperada. Verificar que `Payments.tsx:252`, `ExpenseManager.tsx:469/616`, `ExpenseView.tsx:394`, `PayableSettlementList.tsx:89` só fazem `href={campo}` sem concatenar base URL nem reescrever o valor.
- **Testes:** revisar `Payments.test.tsx`, `ExpenseView.test.tsx`, `ExpenseManager.test.tsx` — se algum mock devolve `payment_proof_url: '/storage/...'` e o teste assere esse formato, trocar por um valor opaco (o teste não deve depender do formato da URL, só da presença do link).

## 5. Governança: ADR-005 + ajuste no §5.5

- **`docs/sdd/decisions/ADR-005-download-arquivo-signed-url.md`** (novo): Contexto (404 do comprovante; §5.5 exige `jwt.auth`; aba de browser não manda Bearer). Decisão (rota de download de arquivo pode ficar fora do `jwt.auth` se: servida só via `URL::temporarySignedRoute` de curta duração; a URL só é emitida dentro de contexto autenticado + membership check; o controller revalida que o recurso pertence ao grupo da URL; nenhum dado além do arquivo é exposto). Consequências. Alternativa descartada: frontend faz `fetch` com Bearer + `response.blob()` + `URL.createObjectURL` — rejeitada por empurrar manuseio de arquivo binário e de token para todos os pontos de link do cliente, sem ganho de segurança real sobre a signed URL curta.
- **`docs/sdd/00-constitution.md` §5.5** (gate humano — diff aprovado antes de aplicar): parágrafo novo permitindo rota de **download de arquivo** fora do `jwt.auth` sob as condições do ADR-005; bump `Versão: 1.2 → 1.3` e data. Referência cruzada para ADR-005.
- **`docs/sdd/decisions/README.md`**: indexar ADR-005.
- **Sem gate de migration** (nenhuma alteração de schema em nenhum ambiente).

## 6. Testes (backend — `tests/Feature`)

- `Storage::fake('local')` + `Storage::fake('public')`.
- `pay()` grava em `comprovantes/{groupId}/*.ext` no disco `local`; resposta traz `payment_proof_url` batendo em `route('proofs.show', ...)` com querystring de assinatura (`URL::hasValidSignature`).
- `confirmSettlement()` idem para `settlement`; reenvio apaga o arquivo anterior (assert `Storage::disk('local')->missing($pathAntigo)`).
- `unpay()` apaga o arquivo (novo e legado) e zera a coluna.
- download: 200 + bytes corretos com assinatura válida; 403 sem assinatura / assinatura adulterada / expirada; 404 quando `id` é de recurso de outro grupo; 404 quando path vazio; path legado (gravado à mão em `public`) resolve 200.
- `type` inválido → 404.

## 7. Ordem de execução

Há dependência técnica: a rota + controller de download (§2) e o `ProofStorage` (§1) precisam existir antes dos accessors (§3) apontarem para eles, e antes dos testes (§6). Frontend (§4) e governança (§5) são independentes entre si mas §5 (ADR + §5.5) deve estar aprovada antes do merge do PR da feature. Ordem sugerida em `tasks.md`: ProofStorage + rota/controller de download → mudanças de upload/delete em `pay`/`unpay`/`confirmSettlement` → accessors + eager loading → testes de feature → verificação/ajuste do frontend → ADR-005 + diff do §5.5.
