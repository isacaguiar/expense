# Specify — Limite de grupos com fonte única

> Feature: elimina a duplicação de risco do limite de grupos por usuário — hoje o número `3` está hardcoded de forma independente no backend e no frontend, sem nenhum endpoint que permita ao segundo ler do primeiro. Origem: item de backlog 053, gerado durante `docs/feature/concluidas/202609/20260920-site-conteudo-e-precos/`.

Versão: 1.0 · Criado em: 20260922

---

## 1. Problema

Desde a feature `site-conteudo-e-precos`, o limite de grupos por usuário deixou de ser só uma regra anti-abuso interna: ele é o **limite anunciado do plano Gratuito** na página de Preços do site. Isso eleva o custo de a constante estar duplicada sem nenhuma fonte de verdade acessível: se o valor mudar no backend (a única cópia que de fato é aplicada) sem que as outras mudem junto, o produto passa a anunciar um número que não corresponde ao que o sistema aplica — o mesmo tipo de defeito ("Grupos ilimitados" vs. teto de 3) que aquela feature já teve que corrigir uma vez.

Hoje o backend não expõe esse valor por nenhum endpoint, então o frontend (React) não tem como lê-lo — cada lado mantém sua própria cópia do número.

## 2. Achados confirmados e requisitos

### 2.1 Três cópias independentes do mesmo número (verificado em código)

- `backend/app/Http/Controllers/GroupController.php:39` — `public const MAX_GROUPS_CREATED_PER_USER = 3;`. Única cópia efetivamente aplicada: `GroupController::store()` (`:55`) recusa criação de grupo (`422`) quando o usuário já atingiu esse total.
- `frontend/src/pages/Dashboard.tsx:47` — `const MAX_GROUPS_CREATED_PER_USER = 3;`, usada só para desabilitar visualmente o botão "Criar grupo" (`:104`, `:237`) antes de bater no backend.
- `site/src/config.php:85` — `'free_groups_limit' => 3`, consumida em 5 pontos do site estático (`index.php`, `manual.php`, `precos.php` em 4 lugares, `recursos.php`) para anunciar o plano Gratuito.

Nenhuma rota do backend devolve esse valor hoje — nem `GET /api/me` (`AuthController::me()`, `backend/app/Http/Controllers/AuthController.php:53-55`, hoje só serializa o `User` autenticado) nem qualquer outra.

### 2.2 Requisito: backend continua sendo a única fonte aplicada, e passa a ser lível

- O valor aplicado (`GroupController::MAX_GROUPS_CREATED_PER_USER`) não muda de lugar nem de comportamento — continua sendo a regra de negócio real.
- O backend passa a expor esse valor em uma resposta que o frontend já consome — `GET /api/me` é chamado em pelo menos 7 telas/layouts do frontend (`Dashboard`, `ExpenseManager`, `ExpenseForm`, `Profile`, `Payments`, `GroupShellLayout`, `SimpleShellLayout`) e hoje devolve o `User` serializado direto (`response()->json(auth('api')->user())`), sem wrapper. A forma exata de acrescentar o campo sem quebrar quem já lê esse endpoint (ex.: `res.data.id`, `res.data.name`) é decisão do Tech Plan.

### 2.3 Requisito: frontend deixa de hardcodar o número

- `Dashboard.tsx` passa a ler o limite da resposta do backend (via o mesmo endpoint que já chama) em vez da constante local `MAX_GROUPS_CREATED_PER_USER = 3` (`:47`).
- Enquanto a resposta não chegar (ou falhar), o frontend não pode travar a UI achando que o limite é `0` — precisa de um estado inicial que não bloqueie a criação de grupo antes de saber o valor real (o backend segue sendo quem recusa de verdade em `422`, então isso é só UX, não é a barreira de segurança).

### 2.4 Site mantém cópia própria, mas documentada — não é duplicação a eliminar

O `site/` é um deploy estático separado (PHP, sem acesso a runtime do backend) e já mantém `free_groups_limit` como cópia intencional, com comentário (`site/src/config.php:81-84`) apontando explicitamente para a constante do backend que ela espelha. Isso já é o "com apenas uma duplicação, documentada" que o próprio item de backlog 053 propõe como resultado aceitável — não há requisito de fazer o site consumir a API em runtime.

## 3. Fora de escopo desta feature

- Fazer o site estático (`site/`) consumir a API do backend em runtime — mantém a cópia documentada em `config.php` (ver §2.4).
- Mudar o valor do limite (continua `3`) ou a regra de negócio de quem pode criar quantos grupos — só a forma como o número é publicado/consumido.
- Qualquer endpoint de configuração pública mais amplo além deste valor específico — se o Tech Plan decidir por um endpoint novo em vez de estender `/me`, o escopo dele fica limitado a resolver este item.
