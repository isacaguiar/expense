# Plan — Limite de grupos com fonte única

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20260922

---

## 1. Backend expõe o limite em `GET /api/me` (specify §2.2)

- **Decisão**: `AuthController::me()` (`backend/app/Http/Controllers/AuthController.php:53-55`) deixa de devolver `auth('api')->user()` direto e passa a devolver o array do usuário mesclado com um campo novo:
  ```php
  public function me()
  {
      return response()->json(array_merge(
          auth('api')->user()->toArray(),
          ['max_groups_per_user' => GroupController::MAX_GROUPS_CREATED_PER_USER],
      ));
  }
  ```
  `GroupController` já está no mesmo namespace (`App\Http\Controllers`) — referencia a constante direto, sem `use` novo e sem duplicar o número.
- **Por que essa abordagem e não outra**:
  - `array_merge($user->toArray(), [...])` preserva exatamente os mesmos campos que os 7 consumidores atuais de `/api/me` já leem (`id`, `name`, `email`, `avatar_url`, `notify_whatsapp`, etc.) — nenhum deles lê o corpo de forma exaustiva/estrita, todos destructuram só os campos que usam (achado do specify §2.2). Só acrescenta uma chave; não é uma mudança de contrato, então não precisa de depreciação assistida.
  - Alternativa descartada: envolver a resposta num wrapper `{ user: {...}, max_groups_per_user: N }`. Exigiria atualizar os 7 pontos de consumo no mesmo commit para não quebrar (`res.data.id` viraria `res.data.user.id`) — custo maior sem benefício, já que o requisito é só acrescentar um valor, não reestruturar o endpoint.
  - Alternativa descartada: endpoint novo (`GET /api/config` ou similar). O specify §2.2 já aponta `/api/me` porque é chamado nas telas que precisam do valor; um endpoint novo seria uma rota, um teste e uma chamada HTTP extra no frontend para resolver o mesmo problema — abstração além do necessário (`CLAUDE.md` raiz).
  - `GroupController::MAX_GROUPS_CREATED_PER_USER` continua sendo a única constante com o valor `3` — `AuthController` só lê, não duplica.
- **Arquivos afetados**: `backend/app/Http/Controllers/AuthController.php` (método `me`).

## 2. Frontend (`Dashboard.tsx`) para de hardcodar o limite (specify §2.3)

- **Decisão**:
  - Remove `const MAX_GROUPS_CREATED_PER_USER = 3;` (`frontend/src/pages/Dashboard.tsx:47`).
  - Novo estado `const [maxGroupsPerUser, setMaxGroupsPerUser] = useState<number | null>(null);`.
  - A chamada existente a `/api/me` (`:81`) passa a tipar `{ id: number; max_groups_per_user: number }` e o `.then` também guarda `setMaxGroupsPerUser(res.data.max_groups_per_user)`.
  - `reachedCreationLimit` (`:104`) passa a ser `maxGroupsPerUser !== null && myGroupsCount >= maxGroupsPerUser`.
  - O texto do `Tooltip` (`:237`) troca `${MAX_GROUPS_CREATED_PER_USER}` por `${maxGroupsPerUser}`.
- **Por que essa abordagem e não outra**:
  - `maxGroupsPerUser: number | null` com default `null` resolve o requisito do specify §2.3 de não travar a UI antes do valor chegar: enquanto `null`, `reachedCreationLimit` é sempre `false` (não desabilita o botão por engano), e quem de fato barra a criação continua sendo o backend (`422` em `GroupController::store`) — a mudança aqui é só cosmética (desabilitar o botão antes de tentar).
  - Reaproveita a chamada a `/api/me` que a tela já faz para popular `currentUserId` — não é uma requisição HTTP a mais.
- **Arquivos afetados**: `frontend/src/pages/Dashboard.tsx`.

## 3. Ordem de execução

Item 2 depende do item 1 estar implementado (o campo `max_groups_per_user` precisa existir na resposta antes do frontend poder lê-lo) — mas não há dependência de branch/deploy: o frontend consegue rodar com o backend antigo (`maxGroupsPerUser` fica `null` para sempre, botão nunca desabilita visualmente, mas o backend ainda barra em `422`), então não há quebra se as duas tasks forem integradas fora de ordem por engano. `tasks.md` mantém a ordem 1 → 2 mesmo assim, por ser a ordem lógica de implementação e review.
