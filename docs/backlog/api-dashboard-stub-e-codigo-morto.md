# `GET /api/dashboard` é um stub com nome enganoso, e há código morto de navegação

ID: 089
Origem: docs/feature/concluidas/202610/20261010-painel-financeiro-pessoal/plan.md §9 e tasks.md (TASK-425); `backend/app/Http/Controllers/AuthController.php` (`dashboard()`), `backend/routes/api.php:39`, `frontend/src/components/Navbar.tsx`, `frontend/src/layouts/InternalLayout.tsx`
Criado em: 2026-10-22
Prioridade: BAIXA
Status: Aberto

## Descrição
Três restos que ficaram em evidência quando se procurou, para o Painel, "onde está o dashboard do usuário":

1. **`GET /api/dashboard`** (`routes/api.php:39`, sob `jwt.auth`) cai em `AuthController::dashboard()`, que devolve `{"message": "Dashboard carregado com sucesso", "user": <model User>}`. Não calcula nada. O nome sugere a visão financeira do usuário, que na verdade são `GET /api/groups` (lista) e, agora, `GET /api/me/overview`. Nenhum arquivo de `frontend/src` chama `/api/dashboard`, e nenhum teste do backend cobre a rota.
2. **`frontend/src/components/Navbar.tsx`**: componente com links para `/dashboard` e botão "Dashboard". Nenhum outro arquivo de `frontend/src` o importa.
3. **`frontend/src/layouts/InternalLayout.tsx`**: layout que nenhuma rota usa (os shells em uso, em `App.tsx`, são `GroupShellLayout` e `SimpleShellLayout`).

`/dashboard` no web é só um redirecionamento legado para `/meus-grupos` (`App.tsx:67`, com teste em `App.test.tsx`), e deve continuar assim.

Isso confunde quem lê o código à procura do dado financeiro do usuário (aconteceu na análise do Painel) e deixa uma rota autenticada e inútil devolvendo o model `User` inteiro.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | O redirecionamento web `/dashboard` → `/meus-grupos` e seu teste **ficam** (links antigos e favoritos). |
| R2 | Remover código sem consumidor é refatoração sem mudança de comportamento: suíte inteira verde sem edição de teste existente. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | `Navbar.tsx` e `InternalLayout.tsx` | A) apagar<br>B) manter | **A** | Sem importação, sem teste, sem rota. Não há contrato envolvido. Confirmar com `grep` no momento da task. |
| D2 | A rota `GET /api/dashboard` e o método | A) remover<br>B) manter e documentar como legado<br>C) transformá-la no endpoint do Painel | **A**, depois de conferir nos logs de acesso que ninguém a chama | A Constitution §4.1 pede contrato aditivo, e remover rota é uma quebra, mas não há consumidor no repositório nem app publicado. C duplicaria `/me/overview`. B deixa o nome enganoso. |

## Por que importa
Baixo risco, baixo custo. O ganho é de clareza: um único lugar (`/me/overview`) para a visão financeira pessoal, sem uma rota homônima que parece ser isso e não é.

Relacionado: 074 (o app Flutter vai consumir o contrato; melhor que ele não encontre um `/dashboard` vazio na documentação da API).

Tipo sugerido: backend + frontend
