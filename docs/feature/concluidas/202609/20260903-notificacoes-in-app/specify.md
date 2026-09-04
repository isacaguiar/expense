# Specify — Notificações in-app (e foto de perfil)

> Feature: dar função ao sino decorativo do cabeçalho — notificações in-app dos eventos do grupo, com contador de não-lidas — e, junto, permitir que o usuário envie a própria foto de perfil. Contexto de origem: promoção dos itens de backlog **020** (`docs/backlog/sistema-notificacoes-frontend.md`) e **021** (`docs/backlog/avatar-foto-usuario.md`) via `/promover-backlog 020`, agrupados por decisão do usuário.

Versão: 1.0 · Criado em: 20260903

---

## 1. Problema

O redesenho da tela de Resumo (`docs/feature/concluidas/202608/20260819-novo-layout-tela-entrada/`) introduziu no cabeçalho do grupo dois elementos que ficaram só visuais:

- Um **ícone de sino decorativo**, sem `onClick`, sem badge, sem estado — `frontend/src/layouts/group/GroupHeader.tsx:80-82`. Registrado como achado §2.4 / R3 daquela feature e como ideia de backlog **020**.
- Um **Avatar só com iniciais** do nome — `frontend/src/layouts/group/GroupHeader.tsx:84-100`. O mockup previa foto real. Registrado como achado §2.5 / R3 e ideia de backlog **021**.

Hoje não existe **nenhum** conceito de notificação no projeto (nem tabela, nem endpoint, nem contagem de não-lidas, nem evento de domínio — ver §2.1), e não existe **nenhum** fluxo de upload de imagem pelo usuário (a coluna `ex_users.avatar_url` só é preenchida pelo login Google — ver §2.5). O objetivo desta feature é fechar os dois débitos: sino funcional e foto de perfil enviável.

Restrições de stack relevantes (herdadas, não mudam aqui): `QUEUE_CONNECTION=sync` e `BROADCAST_DRIVER=log` (`backend/.env.example`) — sem worker de fila e sem websocket. A entrega das notificações ao cliente é por **polling**, não tempo real.

## 2. Requisitos

### 2.1 Persistência de notificações — não existe hoje

Varredura do backend (`backend/`, fora de `vendor/`) confirma ausência total:

- Sem migration de tabela de notificações; sem model; sem controller; sem rota em `backend/routes/api.php`.
- O trait `Illuminate\Notifications\Notifiable` está em `backend/app/Models/User.php:8,15` por scaffolding padrão do Laravel, mas **não há nenhuma chamada `->notify(...)`** nem uso de `DatabaseNotification` em `backend/app/`.
- Sem eventos/listeners de domínio (`backend/app/Providers/EventServiceProvider.php:19` só mapeia o `Registered`→`SendEmailVerificationNotification` padrão; `shouldDiscoverEvents()` é `false`; não existem `app/Events/` nem `app/Listeners/`).
- O único mecanismo "notificação-like" existente é o `WhatsAppNotifier` (`backend/app/Support/WhatsApp/WhatsAppNotifier.php`), desligado por padrão (`WHATSAPP_ENABLED=false`), chamado via `dispatch(fn)->afterResponse()` em `ExpenseController.php:866-876` e `:1014-1022` — serve de **padrão de referência** (chamada inline no ponto de evento, best-effort), não de dependência.

**Requisito:** uma tabela nova `ex_notifications` (prefixo `ex_`, Constitution §1.4), com **uma linha por destinatário** (fan-out no momento da escrita), guardando `type`, `group_id` (nullable), um payload `data` (JSON) suficiente para o cliente montar o texto e o link de destino, e `read_at` (nullable). Model `App\Models\Notification` (`$table = 'ex_notifications'`), com namespace explícito para não colidir com `Illuminate\Notifications\Notification`.

### 2.2 Eventos que geram notificação

Os pontos de escrita são chamadas **inline** nos controllers (não há barramento de eventos — introduzir um está fora de escopo, §3). Cada evento grava N linhas em `ex_notifications`, uma por destinatário. Pontos já localizados no código:

| `type` | Onde gravar | Destinatários |
|---|---|---|
| `expense_paid` | `backend/app/Http/Controllers/ExpenseController.php:859` — `pay()`, logo após `$quota->update($update)` | devedores da despesa: `$expense->payers` menos o credor (`user_payer_id`) |
| `settlement_confirmed` | `ExpenseController.php:999` — `confirmSettlement()`, após o `SettlementConfirmation::updateOrCreate` | o credor do acerto (`to_user_id` validado no método) |
| `cycle_settled` | `ExpenseController.php:1232` — `sealCycleIfSettled()`, somente quando o método retorna `true` (selou de fato) | todos os membros do grupo (`$group->members`) |
| `cycle_closed` | `ExpenseController.php:606` — `close()`, após o `GroupCycleSnapshot::updateOrCreate` | todos os membros do grupo, exceto quem fechou (`auth()->id()`) |
| `group_member_added` | `backend/app/Http/Controllers/GroupMemberController.php:77` — `store()`, após `$group->members()->attach($user->id)` | o usuário recém-adicionado (`$user->id`) |
| `expense_created` | `ExpenseController::store()` — após persistir a despesa (linha exata confirmada na task) | participantes da despesa, exceto o criador (`user_creator_id`) |

Regras comuns verificáveis:
- Uma notificação **nunca** é gravada para o próprio ator da ação (quem pagou, quem fechou, quem criou, quem convidou).
- Falha na gravação da notificação **não** pode quebrar a ação principal (pagamento, fechamento, convite continuam funcionando) — best-effort, com log em caso de erro, à imagem do `WhatsAppNotifier`.
- "Ciclo fechou automaticamente por data" **não** entra: não há caminho de código para isso (o status é calculado por data em `backend/app/Support/BillingCycle.php:67-74`, sem cron, sem transição persistida). Só `close()` manual e `sealCycleIfSettled()` são observáveis no servidor.

### 2.3 Consulta e marcação de lidas

Endpoints novos, dentro do grupo `jwt.auth` de `backend/routes/api.php`, num controller fino `App\Http\Controllers\NotificationController` (padrão dos controllers dedicados, ex.: `ProofDownloadController`). Escopo por **usuário destinatário** (`user_id === auth()->id()`), não por grupo — o filtro de autorização é o próprio destinatário, não há `authorizeGroupMembership` aqui.

- `GET /api/notifications` — lista paginada do usuário autenticado, mais recente primeiro, no envelope `LengthAwarePaginator` cru (mesmo formato de `ExpenseController::cycleHistory`, consumido por `frontend/src/hooks/useGroupCycleHistory.ts`).
- `GET /api/notifications/unread-count` — `{ "count": <int> }` (`where user_id`, `whereNull read_at`). Endpoint barato, alvo do polling.
- `POST /api/notifications/read` — marca **todas** as não-lidas do usuário como lidas; com body opcional `{ "id": <int> }` marca só aquela. Uma linha que não é do `auth()->id()` responde 404 (não confirma existência de notificação alheia).

### 2.4 Sino funcional no cabeçalho

- Novo hook `frontend/src/hooks/useUnreadNotificationsCount.ts`: faz polling de `GET /api/notifications/unread-count` a cada ~60s (`setInterval`, limpo no unmount — **é o primeiro polling do projeto**; hoje não há `setInterval`/`refetchInterval` em `frontend/src/`), header `Authorization: Bearer` via `localStorage.getItem('accessToken')` (padrão repetido no app). Expõe `{ count, refetch }`.
- `frontend/src/layouts/GroupShellLayout.tsx` chama o hook e passa `unreadCount` + um callback de "recarregar contagem" para `GroupHeader` — mesma mecânica com que `userName` é buscado (`:36-44`) e repassado (`:63-70`).
- `frontend/src/layouts/group/GroupHeader.tsx`: o `NotificationsNoneOutlinedIcon` (`:80-82`) passa a ficar dentro de um `<Badge badgeContent={unreadCount} color="error">`, e o `IconButton` ganha `onClick` que abre o menu de 2.5. Nova prop em `GroupHeaderProps` (`:19-26`). Como o header vive no shell, o sino funcional aparece nas 6 telas do grupo — comportamento aceito (não é exclusivo da tela de Resumo).
- Novo componente `frontend/src/components/NotificationsMenu.tsx`: `Menu`/`Popover` ancorado no sino; ao abrir busca `GET /api/notifications` (1ª página); renderiza `List` de itens (ícone por `type`, texto montado a partir de `data`, tempo relativo); ação "marcar todas como lidas" → `POST /api/notifications/read` (e zera o badge); clicar num item navega para a tela do grupo relevante (`data.groupId`) e marca aquele item como lido; estado vazio ("Nenhuma notificação."). Não há precedente de `Menu` no cabeçalho — referência de estilo frouxa: `frontend/src/layouts/NavList.tsx` e o `Select` em `GroupHeader.tsx:66-77`; feedback via `Snackbar`+`Alert` como `frontend/src/pages/Payments.tsx:382-435`.

### 2.5 Foto de perfil do usuário (item de backlog 021)

Estado atual verificado:
- Existe a coluna `ex_users.avatar_url` (migration `backend/database/migrations/2026_08_22_194810_add_avatar_url_to_users_table.php`), mas ela **só** é escrita pelo login Google — `backend/app/Http/Controllers/GoogleAuthController.php:103` (`$user->avatar_url = $googleUser->getAvatar()`, uma URL externa).
- `AuthController::me()` (`backend/app/Http/Controllers/AuthController.php:57-60`) devolve o model `User` cru, então `avatar_url` já sai no payload; `frontend/src/pages/Profile.tsx:42,61,69,148` já lê `avatar_url` e já renderiza `<Avatar src={avatarUrl ?? undefined}>` com fallback de iniciais.
- **Não existe** upload de imagem em nenhum fluxo do app. `frontend/src/layouts/group/GroupHeader.tsx` **não** usa `avatar_url` — o Avatar do cabeçalho é só iniciais.

Requisitos:
- **Backend**: `POST /api/user/photo` (multipart, campo `foto`, `image|max:5120`) e `DELETE /api/user/photo`, no `UserController` (validação `Validator::make`, como o resto do controller). O arquivo vai para disco **privado** (`local`) em `avatares/<userId>/<uuid>.<ext>`, via helper novo `App\Support\AvatarStorage` espelhando `App\Support\ProofStorage`. O path fica numa coluna nova `ex_users.photo_path` (migration aditiva) — **não** sobrescreve `avatar_url` (a foto do Google continua válida como fallback). `me()` passa a devolver `avatar_url` **resolvido** por precedência: foto enviada (`photo_path`, servida por rota de download assinada no estilo do `ProofDownloadController` / ADR-005) > `avatar_url` do Google > `null`. Implementado como accessor `getAvatarUrlAttribute` no `User` + `$appends` (padrão de `Quota::getPaymentProofUrlAttribute`).
- **Frontend**: `frontend/src/pages/Profile.tsx` ganha um controle de upload (input de arquivo + preview local + submit para `POST /api/user/photo`) e um botão "remover foto" (`DELETE`). `frontend/src/layouts/GroupShellLayout.tsx` passa a buscar também `avatar_url` no `/api/me` e a repassá-lo para `GroupHeader`, cujo `Avatar` (`:86-97`) passa a usar `src={avatarUrl}` com o mesmo fallback `getInitials`.

## 3. Fora de escopo desta feature

- **Notificação em tempo real** (websocket, SSE, push do browser) — a entrega é só por polling. Migrar `BROADCAST_DRIVER`/adicionar Reverb/Echo não faz parte.
- **Barramento de eventos de domínio** (`app/Events`/`app/Listeners`, `shouldDiscoverEvents`) — os gatilhos são chamadas inline, como o `WhatsAppNotifier` já faz.
- **Notificação de "ciclo fechado automaticamente por data"** e qualquer **agendador/cron** (`Console\Kernel::schedule` continua vazio) — sem hook no servidor hoje.
- **Notificação por e-mail ou WhatsApp** desses mesmos eventos — esta feature é só in-app. O `WhatsAppNotifier` existente não é alterado.
- **Preferências por usuário** de quais notificações receber (mute por tipo, por grupo) — todos os destinatários recebem todos os tipos do MVP.
- **Marcar como não-lida**, arquivar, excluir notificação, e retenção/expurgo de linhas antigas de `ex_notifications`.
- **Avatar de outros usuários** em listas (membros, devedores, pagadores) — 021 aqui cobre só a própria foto, na tela Minha Conta e no Avatar do cabeçalho.
- **Recorte/redimensionamento da imagem** no upload de foto (client ou server) — grava o arquivo enviado, validando só tipo e tamanho.
- Backlog **021** fica agrupado aqui; nenhum outro item de backlog entra.
