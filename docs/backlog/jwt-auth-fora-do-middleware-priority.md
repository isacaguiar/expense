# `jwt.auth` (Tymon) fora do `middlewarePriority`: throttle e bindings rodam antes da autenticação

ID: 092
Origem: docs/feature/20261010-painel-financeiro-pessoal/implementation.md (TASK-429, "A premissa que o teste derrubou"); `backend/app/Http/Kernel.php` (alias `jwt.auth`, linha 69)
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
O alias `jwt.auth` aponta para `Tymon\JWTAuth\Http\Middleware\Authenticate`. O `Kernel` da aplicação não declara `$middlewarePriority`, e a lista padrão do framework contém `ThrottleRequests` e `SubstituteBindings`, mas **não** o middleware do Tymon (ele não implementa `AuthenticatesRequests`). Resultado: em toda rota protegida, o throttle e os bindings sobem para **antes** da autenticação, qualquer que seja a ordem declarada na rota.

Medido na TASK-429 com `app('router')->gatherRouteMiddleware($route)` para `GET /api/me/overview`: `ThrottleRequests:api` → `ThrottleRequests:overview` → `SubstituteBindings` → `Tymon\JWTAuth\Http\Middleware\Authenticate`. O critério original ("throttle depois do `jwt.auth`") fez o teste de chamadas sem token falhar (a 31ª recebia 429 em vez de 401).

**Hoje não causa dano.** Não há binding implícito de model nas rotas (só `{groupId}` cru), e o limiter `overview` foi escrito para resolver o usuário do token por conta própria e devolver `Limit::none()` quando ele não existe. O throttle global `api` (por IP) antes da autenticação é até desejável.

**O que herda o problema no futuro:** (a) um `throttle:` chaveado por usuário que não resolva o usuário sozinho (cairia no IP); (b) um **binding implícito de model**, que resolveria o model antes de saber se há usuário, e responderia `404`/`200` a quem nem está autenticado, o que revela a existência do registro.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Enquanto não houver tratamento, vale a convenção: **limiter por usuário resolve o usuário do token por conta própria** (como o `overview`) e **não se usa binding implícito de model** em rota sob `jwt.auth`. |
| R2 | Qualquer mudança de ordem de middleware roda a suíte inteira de rotas protegidas: ela altera a ordem em todas. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Como tratar | A) declarar `$middlewarePriority` no `Kernel` com o Tymon entre `ThrottleRequests` e `SubstituteBindings`<br>B) só documentar a convenção R1 em `06-context-backend.md` e esperar o primeiro caso real<br>C) trocar o middleware por um que implemente `AuthenticatesRequests` | **B agora; A quando surgir o primeiro binding implícito ou throttle por usuário novo** | Hoje não há dano, e A muda a ordem de todas as rotas protegidas (risco sem benefício imediato). C depende de código do pacote. |
| D2 | Onde registrar a convenção | A) `docs/sdd/06-context-backend.md`<br>B) comentário no `Kernel`<br>C) os dois | **C** | O documento é lido antes de codar; o comentário aparece onde se mexe na ordem. |

## Por que importa
Armadilha silenciosa: quem escrever o próximo limiter por usuário ou ligar um binding implícito herda a ordem errada sem aviso, e o teste que pega isso só existe se alguém lembrar de escrevê-lo.

Relacionado: 085 (rota sem checagem de membro), 086.

Tipo sugerido: backend / doc
