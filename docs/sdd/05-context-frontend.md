# Contexto de execução — Frontend

> Documento **portátil** (markdown puro, sem nada específico de ferramenta): o que qualquer assistente de IA (ou dev) deve carregar antes de mexer na UI. Contrato de portabilidade e relação com as skills: `README.md`, "Skills e portabilidade".

Versão: 1.5 · Última atualização: 2026-10-08

---

Este documento cobre o **frontend web** (`frontend/`): React 18 + TypeScript + Vite + MUI + `react-router-dom` + `axios`. O app móvel, em Flutter (`app/`, `decisions/ADR-010`), tem contexto próprio em `07-context-mobile.md`. Os dois consomem a mesma API Laravel via JWT Bearer.

## Referência visual (mockups)

Antes de criar ou alterar o **layout** de uma tela (estrutura, hierarquia visual, componentes usados, espaçamento, estados), confira o mockup correspondente:

- `assets/images/screen/movel.png` — telas mobile do app "Shared Expense" (referência de layout para o app móvel, `app/`, Flutter — ver `07-context-mobile.md`): onboarding, login (com "Continuar com Google" e link de cadastro — nota: o `expense/frontend` web atual removeu o link de cadastro por não ter página de registro, ver `docs/backlog/`), dashboard do grupo, lista de grupos, listagem de despesas, nova despesa, detalhe da despesa (divisão/pagamento), configurações do grupo e perfil do usuário.
- `assets/images/screen/desktop.png` — telas desktop do CRUD de despesas (referência para `expense/frontend`, web): listagem, visualização, criação, edição, confirmação de exclusão, exclusão concluída, listagem vazia e filtros/busca — com navegação lateral fixa (Resumo, Despesas, Participantes, Pagamentos, Relatórios, Configurações).
- `E:\Projetos\Controle de Despesas\assets\images\01.png` a `09.png` (fora do repositório — pasta irmã de `expense/`, não versionada com o código) — os mesmos 8 estados de `desktop.png` recortados um por arquivo (`site-full.png`, na mesma pasta, é idêntico byte-a-byte a `desktop.png`, não traz tela nova). Útil como referência **de padrão**, não só do CRUD de Despesas: o formato "listagem → visualizar/criar/editar → confirmar exclusão → exclusão concluída → lista vazia → filtros/busca" é o modelo a repetir ao modernizar qualquer outra tela de listagem/cadastro do `frontend/` (Grupos, Participantes, etc.) que ainda não tenha mockup próprio — ver `docs/feature/concluidas/202608/20260820-atualizacao-layout-paginas/`.

Esses mockups são **referência de layout e fluxo**, não a implementação atual — nem tudo que aparece neles já existe em código (ex.: navegação lateral desktop, tela de perfil completa, relatórios). Ao portar uma tela existente ou criar uma nova, comece pelo mockup do dispositivo alvo (mobile → `movel.png`, web/desktop → `desktop.png`/`01-09.png`) e só depois ajuste para os componentes reais disponíveis (MUI no web, Material 3 no app Flutter) e para o contrato de API existente — não implemente campo/tela que o mockup sugere mas a API não suporta sem antes tratar isso como mudança de API (backend, ver `docs/backlog/` itens `023`-`026` para os gaps já identificados no mockup de Despesas).

## Antes de codar

1. **Confirme que o alvo é o web**: este documento é só do `frontend/`. Se a tarefa é do app móvel (caminho em `app/`, Flutter/Dart), carregue `07-context-mobile.md` em vez deste. Normalmente dá para inferir pelo caminho de arquivo citado ou pelo texto da task; se estiver ambíguo, pergunte antes de criar arquivo em pasta errada.
2. **Carregue o contexto abaixo se ele ainda não estiver na conversa** (não releia o que já foi lido na mesma sessão):
   - O mockup relevante em `assets/images/screen/` (ver "Referência visual" acima) — layout/hierarquia visual da tela.
   - `01-specify.md` §2-3 — glossário de domínio (User, Group, Expense, Quota, payers) e fluxos, para nomear campos/telas de forma consistente com o backend.
   - O arquivo de padrão mais próximo já existente no frontend alvo (ex.: `frontend/src/api.ts`, `frontend/src/theme.ts`, ou uma tela irmã em `frontend/src/pages/`) — use como referência de estilo antes de inventar um padrão novo.
   - `docs/analytics/README.md` — **só se a tarefa dispara evento de analytics** (`trackEvent`, `gtag`), mexe em UTM/campanha ou no consentimento de cookies: lista os eventos medidos, o que nunca pode sair para o Google e a ordem para acrescentar um evento novo. Evita que um evento nasça fora da camada `frontend/src/analytics/`.

## Convenções fixas

- TypeScript em modo estrito, sem `any` não justificado.
- O cliente só consome a API — não duplique regra de negócio (cálculo de divisão de despesa, apuração de saldo) que já existe no backend.
- O web e o app móvel devem consumir o mesmo contrato de API sem divergir — se uma tela precisar de um campo novo, isso é mudança de API (backend), não workaround no cliente.
- O `frontend/` continua sendo o cliente web em produção; o app móvel não o substitui (`decisions/ADR-010`).

## Gates human-in-the-loop

Fronteira de autonomia: `00-constitution.md` §5.2 (tabela normativa) e `agent-architecture.md` §5 (desenho). Nunca assuma aprovação de merge/deploy/publicação do app na Google Play como implícita.
