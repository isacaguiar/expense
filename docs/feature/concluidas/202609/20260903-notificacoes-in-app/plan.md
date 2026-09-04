# Plan — Notificações in-app (e foto de perfil)

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260903

---

## 1. Tabela `ex_notifications` + model (specify §2.1)

- **Migration** `backend/database/migrations/2026_09_03_000000_create_ex_notifications_table.php` (classe anônima `return new class extends Migration`, timestamp arredondado como as demais de 2026 — ordena depois de `2026_09_02_000000_...`). Colunas, no padrão de `2026_08_25_220000_create_ex_settlement_confirmations_table.php`:
  - `$table->id();`
  - `$table->unsignedBigInteger('user_id');` + `$table->foreign('user_id')->references('id')->on('ex_users')->onDelete('cascade');` — destinatário.
  - `$table->string('type');`
  - `$table->unsignedBigInteger('group_id')->nullable();` + `$table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');`
  - `$table->json('data')->nullable();`
  - `$table->timestamp('read_at')->nullable();`
  - `$table->timestamps();`
  - `$table->index(['user_id', 'read_at']);` (contagem de não-lidas) e `$table->index(['user_id', 'created_at']);` (lista paginada).
  - `down()` faz `Schema::dropIfExists('ex_notifications');`.
- **Model** `backend/app/Models/Notification.php`: `namespace App\Models;`, `extends Illuminate\Database\Eloquent\Model`, `protected $table = 'ex_notifications';`, `protected $fillable = ['user_id', 'type', 'group_id', 'data', 'read_at'];`, `protected $casts = ['data' => 'array', 'read_at' => 'datetime'];`, relações `user()` (`belongsTo(User::class)`) e `group()` (`belongsTo(Group::class)`).
- **Por quê essa abordagem e não outra**: uma linha por destinatário (fan-out na escrita) mantém a leitura trivial — `where('user_id', auth()->id())`, sem JOIN nem tabela-pivô de "quem já leu". Tabela `ex_`-prefixada e model com `$table`/`$fillable`/`$casts` explícitos é o padrão de todo model do projeto (`SettlementConfirmation`, `GroupCycleSnapshot`). **Não** usar a stack nativa `illuminate/notifications` (`notifications` + `DatabaseNotification` + `->notify()`): o projeto não usa `JsonResource`, canais de notificação nem eventos de framework em nenhum lugar; adotar essa stack só aqui seria inconsistente e maior, sem ganho (a entrega é polling simples, não multi-canal). Namespace `App\Models\Notification` não colide com `Illuminate\Notifications\Notification` (esta última não é referenciada em `app/`). `onDelete('cascade')` (e não a coluna lógica `deleted` das entidades de negócio): notificação não é registro de negócio — não há requisito de soft-delete/auditoria dela (specify §3), e sumir junto com o usuário/grupo é o comportamento desejado.

## 2. Endpoints de leitura e marcação (specify §2.3)

- **Controller** `backend/app/Http/Controllers/NotificationController.php` (fino, `extends Controller`, padrão de `ProofDownloadController`):
  - `index(Request $request)` → `Notification::where('user_id', auth()->id())->orderByDesc('created_at')->paginate(15)` — envelope `LengthAwarePaginator` cru, igual a `ExpenseController::cycleHistory` (`frontend/src/hooks/useGroupCycleHistory.ts` já consome esse formato).
  - `unreadCount()` → `response()->json(['count' => Notification::where('user_id', auth()->id())->whereNull('read_at')->count()])`.
  - `markRead(Request $request)` → `$data = $request->validate(['id' => 'nullable|integer']);` base query `Notification::where('user_id', auth()->id())->whereNull('read_at')`; se `id` veio, primeiro `abort_unless(Notification::where('user_id', auth()->id())->where('id', $data['id'])->exists(), 404)` e restringe a query a esse `id`; `->update(['read_at' => now()])`; devolve `response()->json(['message' => 'ok'])`.
- **Rotas** em `backend/routes/api.php`, dentro do grupo `jwt.auth`, junto das rotas soltas do usuário (após `/pix/generate`, linha 29). `use App\Http\Controllers\NotificationController;` no topo.
  - `Route::get('/notifications', [NotificationController::class, 'index']);`
  - `Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);`
  - `Route::post('/notifications/read', [NotificationController::class, 'markRead']);`
- **Autorização**: o filtro é sempre `user_id === auth()->id()` — não há `authorizeGroupMembership` (a notificação é do destinatário, não do grupo). Uma linha de outro usuário nunca é lida nem marcada (404 no caso de `id` alheio).
- **Teste** `backend/tests/Feature/NotificationControllerTest.php` (`DatabaseTransactions`, `auth('api')->login()`): `index` só devolve linhas do caller, paginado, mais recente primeiro; `unread-count` conta só não-lidas do caller; `markRead` sem `id` marca todas as do caller e não toca nas de outro usuário; `markRead` com `id` marca só aquela; `markRead` com `id` de outro usuário → 404.
- **Por quê**: 3 rotas explícitas (não `apiResource`) porque só 3 verbos fazem sentido e `unread-count` não é REST-ish; `paginate(15)` reaproveita o envelope que o front já sabe ler; marcar-todas num único `UPDATE ... WHERE read_at IS NULL` é barato e cobre o caso comum (abrir o sino e "limpar").

## 3. Serviço `Notifier` e gatilhos inline (specify §2.2)

- **Serviço** `backend/app/Support/Notifier.php` — métodos estáticos, forma espelhada de `App\Support\WhatsApp\WhatsAppNotifier`. Cada método: (a) resolve o conjunto de destinatários, (b) monta `data` (`['actorName' => ..., 'groupId' => ..., 'groupName' => ..., 'expenseDescription' => ..., 'amount' => ..., 'cycleLabel' => 'setembro/2026']` — só as chaves relevantes do tipo), (c) grava uma linha por destinatário via `Notification::create([...])` num loop (N pequeno — membros de um grupo), (d) tudo dentro de `try { ... } catch (\Throwable $e) { Log::warning('Notifier falhou', ['type' => ..., 'e' => $e->getMessage()]); }` — falha **nunca** propaga.
  - `expensePaid(Expense $expense, string $cycleLabel)` — destinatários: `$expense->payers` (relação `belongsToMany` via `ex_expenses_payers`) menos `$expense->user_payer_id`. `type = 'expense_paid'`.
  - `settlementConfirmed(Group $group, int $creditorId, int $debtorId, string $amount, string $cycleLabel)` — destinatário: `$creditorId`. `type = 'settlement_confirmed'`.
  - `cycleSettled(Group $group, string $cycleLabel)` — destinatários: `$group->members` (todos). `type = 'cycle_settled'`.
  - `cycleClosed(Group $group, string $cycleLabel, int $actorId)` — destinatários: `$group->members` menos `$actorId`. `type = 'cycle_closed'`.
  - `groupMemberAdded(Group $group, int $addedUserId, string $actorName)` — destinatário: `$addedUserId`. `type = 'group_member_added'`.
  - `expenseCreated(Expense $expense)` — destinatários: `$expense->payers` menos `$expense->user_creator_id`. `type = 'expense_created'`.
  - Helper interno de rótulo de competência a partir de `Carbon` do `cycle['start']` (`->locale('pt_BR')->isoFormat('MMMM/YYYY')` ou `translatedFormat('F/Y')`).
- **Gatilhos** (chamadas inline, cada uma logo após a escrita principal, no estilo dos `dispatch(...)->afterResponse()` do `WhatsAppNotifier` — mas **síncronas**, sem `afterResponse`, pois é só um `INSERT`):

  | `type` | Arquivo:linha | Chamada | Idempotência |
  |---|---|---|---|
  | `expense_paid` | `ExpenseController::pay()` — após `$quota->update($update)` (`ExpenseController.php:859`) | `Notifier::expensePaid($expense, $cycleLabel)` | capturar `$wasPaid = $quota->paid;` **antes** do `update`; só notifica se `! $wasPaid` (re-`pay()` do mesmo quota não repete) |
  | `settlement_confirmed` | `ExpenseController::confirmSettlement()` — após o `updateOrCreate` (`ExpenseController.php:1011`) | `Notifier::settlementConfirmed($group, (int) $data['to_user_id'], auth()->id(), $settlement['amount'], $cycleLabel)` | só notifica se `$confirmation->wasRecentlyCreated` (reenvio de comprovante não repete) |
  | `cycle_settled` | dentro de `ExpenseController::sealCycleIfSettled()` (`ExpenseController.php:1232`) | `Notifier::cycleSettled($group, $cycleLabel)` | ler o snapshot **antes** do `updateOrCreate`; `$wasSealed = $snapshot?->isSealed() ?? false`; só notifica no `updateOrCreate` que retorna `true` **e** `! $wasSealed` (transição null→selado uma única vez) |
  | `cycle_closed` | `ExpenseController::close()` — após montar `$sealed` (`ExpenseController.php:631`) | `Notifier::cycleClosed($group, $cycleLabel, auth()->id())` | ler o snapshot no topo de `close()`; `$wasManuallyClosed = $before && $before->closed_manually_at !== null`; só notifica se `! $sealed && ! $wasManuallyClosed` (re-fechar não repete; fechar um ciclo que já quita direto dispara só `cycle_settled`) |
  | `group_member_added` | `GroupMemberController::store()` — após `$group->members()->attach($user->id)` (`GroupMemberController.php:77`) | `Notifier::groupMemberAdded($group, $user->id, auth()->user()->name)` | nenhuma extra — a linha 70-74 já devolve 409 antes se o usuário **já** era membro, então chegar aqui é attach real |
  | `expense_created` | `ExpenseController::store()` — após `DB::commit()` (`ExpenseController.php:~405`), antes do `return` de 201 | `Notifier::expenseCreated($expense->load('payers'))` | nenhuma extra — `store()` sempre cria uma despesa nova |

- **Mudança nos métodos `private` `sealCycleIfSettled()` e `close()`**: além da chamada ao `Notifier`, `sealCycleIfSettled` passa a fazer um `GroupCycleSnapshot::where(...)->first()` antes do `updateOrCreate` para detectar `$wasSealed`; `close()` idem para `$wasManuallyClosed`. O comportamento de selagem/fechamento em si não muda — só ganha a detecção de transição. Consequência aceita: como `summary()` (um GET) chama `sealCycleIfSettled` de forma preguiçosa (`ExpenseController.php:516`), a notificação `cycle_settled` pode ser gravada durante um GET — é o momento real em que o ciclo transita para selado, e é idempotente.
- **`use App\Support\Notifier;`** em `ExpenseController.php` e `GroupMemberController.php`.
- **Testes** (um arquivo `backend/tests/Feature/NotifierTriggersTest.php`, ou um bloco por controller test já existente): para cada `type`, um cenário que (a) confirma que a(s) linha(s) certa(s) aparece(m) em `ex_notifications` com o `type`, `user_id` e `group_id` esperados, (b) confirma que o **ator não** recebe, (c) confirma **não-duplicação** ao repetir a ação (segundo `pay()`, reenvio de comprovante, segundo `close()`, segundo GET `summary()` num ciclo já selado). `php artisan test` verde.
- **Por quê chamada inline e não evento/listener**: é exatamente o que o `WhatsAppNotifier` já faz no mesmo `pay()`/`confirmSettlement()`; introduzir `app/Events` + `EventServiceProvider::$listen` + `shouldDiscoverEvents` seria uma mudança de arquitetura (fora de escopo, specify §3) para 6 gatilhos simples. Síncrono (sem `afterResponse`) porque é um `INSERT` local rápido e queremos a notificação já contável no próximo poll; best-effort com `try/catch` garante que um erro de escrita não derrube o pagamento.

## 4. Sino funcional: hook de polling, Badge e `NotificationsMenu` (specify §2.4)

- **Hook** `frontend/src/hooks/useUnreadNotificationsCount.ts`: `count` (state, começa `0`); `fetchCount` (`useCallback`) faz `axios.get('${API_BASE_URL}/api/notifications/unread-count', { headers: { Authorization: Bearer <localStorage accessToken> } })` → `setCount(res.data.count)`, `.catch(() => {})` **silencioso** (badle é best-effort; um 401 transitório num poll de fundo **não** redireciona — diferente dos outros hooks —, a próxima navegação/ação real trata a expiração). `useEffect`: chama `fetchCount()` na montagem, `const id = setInterval(fetchCount, 60000)`, `return () => clearInterval(id)`. Retorna `{ count, refetch: fetchCount }`. É o **primeiro `setInterval` de `frontend/src/`**.
- **`frontend/src/layouts/GroupShellLayout.tsx`**: `const { count: unreadCount, refetch: refetchUnread } = useUnreadNotificationsCount();`; passa `unreadCount={unreadCount}` e `onNotificationsRead={refetchUnread}` para `<GroupHeader>` (`:63-70`).
- **`frontend/src/layouts/group/GroupHeader.tsx`**:
  - `GroupHeaderProps` ganha `unreadCount: number;` e `onNotificationsRead: () => void;` (obrigatórias — único call site é `GroupShellLayout`; testes que renderizam `<GroupHeader>` direto passam a mandar `unreadCount={0}`/`onNotificationsRead={()=>{}}`).
  - `const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null);` (o componente hoje é função pura sem state — `React`/`useState` já disponíveis).
  - o `<IconButton aria-label="Notificações">` (`:80-82`) ganha `onClick={e => setAnchorEl(e.currentTarget)}` e o ícone fica dentro de `<Badge badgeContent={unreadCount} color="error" max={99}><NotificationsNoneOutlinedIcon /></Badge>` (import `Badge` de `@mui/material/Badge`).
  - logo depois do `IconButton`: `<NotificationsMenu anchorEl={anchorEl} open={Boolean(anchorEl)} onClose={() => setAnchorEl(null)} onRead={onNotificationsRead} />`.
- **Componente** `frontend/src/components/NotificationsMenu.tsx`, props `{ anchorEl: HTMLElement | null; open: boolean; onClose: () => void; onRead: () => void }`:
  - Ao passar a `open === true` (`useEffect` em `[open]`): `axios.get('${API_BASE_URL}/api/notifications', { params: { page: 1 }, headers: Bearer })` → guarda `items` (`res.data.data`), `loading`, `error`.
  - `<Menu anchorEl={anchorEl} open={open} onClose={onClose}>` com: linha de cabeçalho ("Notificações" + botão "Marcar todas como lidas" → `POST /api/notifications/read` sem body, depois `onRead()` + refaz o fetch local); `loading` → `<CircularProgress size={20} />`; `error` → `<Alert severity="error">`; `items.length === 0` → texto "Nenhuma notificação."; senão `<List>` de `<ListItemButton divider>` — cada um mostra o texto de `notificationText(type, data)`, o tempo relativo (`formatRelative(created_at)`), e fica em destaque (fundo/negrito) quando `read_at == null`. Clique num item: `POST /api/notifications/read` com `{ id }`, `navigate('/groups/${data.groupId}/summary')`, `onClose()`, `onRead()`.
  - feedback de erro de ação via `<Snackbar><Alert>` (padrão `frontend/src/pages/Payments.tsx:382-435`).
- **Helper** `frontend/src/components/notificationText.ts`: `notificationText(type: string, data: Record<string, unknown>): string` — `switch(type)` devolvendo, por tipo, uma frase pt-BR (ex.: `` `${data.actorName} marcou "${data.expenseDescription}" como paga` ``, `` `${data.actorName} confirmou um pagamento de R$ ${data.amount}` ``, `` `O ciclo de ${data.cycleLabel} do grupo ${data.groupName} foi quitado` ``, etc.). E `formatRelative(iso: string): string` com `Intl.RelativeTimeFormat('pt-BR')` (ou reaproveita util de data de `frontend/src` se houver). Puro e testável.
- **Testes**: `frontend/src/components/NotificationsMenu.test.tsx` (render a partir do GET mockado; "marcar todas" chama o POST e some o destaque; clicar num item chama `POST {id}` e `navigate`; estado vazio); `frontend/src/hooks/useUnreadNotificationsCount.test.ts` (`vi.useFakeTimers()`: monta → 1 fetch; `advanceTimersByTime(60000)` → 2º fetch; desmonta → `clearInterval`); `frontend/src/components/notificationText.test.ts`; atualizar `GroupHeader.test.tsx` para o `Badge` (hoje o teste nem cita o sino) e qualquer teste de `GroupShellLayout` que quebre com as props novas. `npx tsc --noEmit` sem erro; `npx vitest run` verde.
- **Por quê polling de 60s no shell**: `unread-count` é uma query barata (`COUNT` com índice `(user_id, read_at)`); 60s é folgado para uma app de despesas (nada é urgente ao segundo) e evita qualquer infra de tempo real (specify §3). O menu buscar a lista só ao abrir evita puxar payload grande a cada 60s.

## 5. Foto de perfil — backend (specify §2.5, backend)

- **Migration** `backend/database/migrations/2026_09_03_000001_add_photo_path_to_ex_users_table.php` (aditiva): `$table->string('photo_path')->nullable()->after('avatar_url');` + `down()` `dropColumn('photo_path')`.
- **Helper** `backend/app/Support/AvatarStorage.php` — espelho de `App\Support\ProofStorage`: `const DISK = 'local';` (disco privado); `store(UploadedFile $file, int $userId): string` → `storeAs("avatares/{$userId}", Str::uuid().'.'.$ext, self::DISK)` (mesma dedução de extensão do `ProofStorage`); `delete(?string $path): void` → `Storage::disk(self::DISK)->delete($path)` se `$path` não vazio.
- **`backend/app/Models/User.php`**:
  - accessor `getAvatarUrlAttribute($value)` — se `$this->photo_path`, devolve `URL::temporarySignedRoute('user.photo', now()->addMinutes(30), ['user' => $this->id])`; senão devolve `$value` (URL do Google ou `null`). Ou seja: **foto enviada > foto do Google > null**, resolvido na serialização; `AuthController::me()` (que devolve o model cru) e o `frontend/src/pages/Profile.tsx` (que já lê `avatar_url`) não precisam mudar para *ler*.
  - `photo_path` **não** entra em `$fillable` (fica em `['name','email','password','role']`) — é setado por atribuição direta (`$user->photo_path = ...; $user->save();`), como `avatar_url`/`whatsapp`/`pix` já são.
  - `import Illuminate\Support\Facades\URL;`.
- **`backend/app/Http/Controllers/UserController.php`** (validação `Validator::make`, como o resto do controller):
  - `uploadPhoto(Request $request)` — `['foto' => 'required|image|max:5120']`; `$user = $request->user();` `AvatarStorage::delete($user->photo_path);` (remove a anterior) `$user->photo_path = AvatarStorage::store($request->file('foto'), $user->id);` `$user->save();` → `response()->json(['avatar_url' => $user->avatar_url])` (já resolvido pelo accessor).
  - `deletePhoto(Request $request)` — `AvatarStorage::delete($user->photo_path); $user->photo_path = null; $user->save();` → `response()->json(['avatar_url' => $user->avatar_url])` (volta pro Google/null).
- **Controller de entrega** `backend/app/Http/Controllers/UserPhotoController.php` (minúsculo, padrão `ProofDownloadController`): `show(User $user)` → `abort_unless($user->photo_path, 404);` `return Storage::disk('local')->response($user->photo_path);`.
- **Rotas** em `backend/routes/api.php`:
  - dentro de `jwt.auth`: `Route::post('/user/photo', [UserController::class, 'uploadPhoto']);` e `Route::delete('/user/photo', [UserController::class, 'deletePhoto']);` (junto das outras `/user/*`, linhas 25-28).
  - **fora** de `jwt.auth`, ao lado de `proofs.show` (linha 63): `Route::get('/user/{user}/photo', [UserPhotoController::class, 'show'])->middleware('signed')->name('user.photo');` — mesma justificativa do ADR-005 (a tag `<img>` não manda `Authorization: Bearer`; autoriza a URL assinada de 30 min).
  - `use App\Http\Controllers\UserPhotoController;` no topo.
- **Testes** `backend/tests/Feature/UserPhotoTest.php` (`Storage::fake('local')`): upload grava em `avatares/<id>/...` e `GET /api/me` passa a trazer `avatar_url` = URL assinada; segunda foto apaga a primeira do disco; `DELETE` limpa `photo_path` e `me()` cai no `avatar_url` do Google (setar um valor de Google no fixture) ou `null`; `foto` não-imagem → 422; `foto` > 5 MB → 422; a rota `user.photo` sem assinatura → 403, com assinatura válida → 200 e bytes do arquivo. `./vendor/bin/pint --test` limpo; `php artisan test` verde.
- **Por quê disco privado + rota assinada e não disco `public`**: foto de perfil é dado pessoal; o projeto moveu comprovantes de propósito para o disco `local` privado e criou o padrão ADR-005 (rota assinada curta) exatamente para servir binário pessoal a uma tag `<img>`. Reusar esse padrão é consistente e não expõe um diretório estático de rostos. Coluna nova `photo_path` (e não sobrescrever `avatar_url`): preserva a foto do Google como fallback e mantém a origem de cada uma rastreável.

## 6. Foto de perfil — frontend (specify §2.5, frontend)

- **`frontend/src/pages/Profile.tsx`**:
  - `<input type="file" accept="image/*" hidden ref={fileInputRef} onChange={handlePhotoChange} />` disparado por um botão "Alterar foto" abaixo do `<Avatar>` (`:147-151`).
  - `handlePhotoChange`: monta `FormData` com `foto`, `POST ${API_BASE_URL}/api/user/photo` (header Bearer; `Content-Type` multipart automático), `setAvatarUrl(res.data.avatar_url)`, Snackbar de sucesso; enquanto sobe, `<CircularProgress>` sobre o Avatar. Erro → `setError`/Snackbar.
  - botão "Remover foto" (só quando `avatarUrl`): `DELETE ${API_BASE_URL}/api/user/photo` → `setAvatarUrl(res.data.avatar_url)`.
- **`frontend/src/layouts/GroupShellLayout.tsx`**: o `useEffect` de `/api/me` (`:36-44`) passa a tipar `avatar_url: string | null` na resposta e a fazer `setAvatarUrl(res.data.avatar_url ?? null)` (novo state `avatarUrl`); passa `avatarUrl={avatarUrl}` para `<GroupHeader>`.
- **`frontend/src/layouts/group/GroupHeader.tsx`**: `GroupHeaderProps` ganha `avatarUrl: string | null;`; o `<Avatar>` (`:86-97`) recebe `src={avatarUrl ?? undefined}` e mantém `{getInitials(userName)}` como filho (fallback quando `src` falha/ausente — comportamento nativo do `Avatar` do MUI).
- **Testes**: `frontend/src/pages/Profile.test.tsx` — fluxo de upload (mock `POST`, `avatar` passa a ter `src`), fluxo de remoção; `frontend/src/layouts/group/GroupHeader.test.tsx` — `Avatar` com `src` quando `avatarUrl` é passado, iniciais quando `null`. `npx tsc --noEmit` sem erro; `npx vitest run` (suíte completa) verde.
- **Por quê mínimo no front**: `Profile.tsx` já renderiza `<Avatar src={avatarUrl}>` — falta só o controle de envio; o cabeçalho só precisa receber e repassar mais um campo do `/api/me` que o shell já busca.

## N. Ordem de execução

Dependências técnicas:

1. **§1 (tabela + model)** — base de todo o backend de notificação; nada antes.
2. **§2 (endpoints)** — depende só de §1 (não depende de §3; os testes criam linhas direto). Vem antes de §2-gatilhos para o front ter o que consumir cedo.
3. **§3 (Notifier + gatilhos)** — depende de §1. Quebrado em 4 tasks por atomicidade (Notifier + gatilhos de pagamento; gatilhos de ciclo; membro adicionado; despesa criada) — ver `tasks.md`.
4. **§4 (sino no front)** — depende de §2 (consome os endpoints). Independe de §3 para compilar/testar (mocks), mas só entrega valor real com §3 gravando linhas.
5. **§5 (foto backend)** — **independente** de §1-§4; pode ser feito a qualquer momento. Fica depois só por hábito da série (backend→frontend, um assunto por vez).
6. **§6 (foto frontend)** — depende de §5.

Ordem final das tasks em `tasks.md`: §1 → §2 → §3 (×4) → §4 (×2) → §5 → §6 (×2), sequencial. TASK de §5 pode ser antecipada se o usuário preferir destravar a foto antes.
