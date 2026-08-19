# Contexto de execução — Frontend

> Documento **portátil**, sem nada específico de ferramenta: é o conteúdo que qualquer assistente de IA (ou desenvolvedor) deve carregar antes de mexer na UI do projeto. Hoje é referenciado pela skill `expense-frontend` do Claude Code (`expense/.claude/skills/expense-frontend/SKILL.md`), mas não depende dela — se o projeto trocar de ferramenta de IA, este arquivo continua valendo, só muda o adaptador que aponta pra ele.

Versão: 1.1 · Última atualização: 2026-08-19

---

Este projeto tem (ou terá) dois frontends consumindo a mesma API Laravel via JWT Bearer:

- `expense/frontend` — React web atual: Vite + TypeScript + MUI + `react-router-dom` + `axios`.
- `expense/app` — app Expo/React Native em migração (ver `TASK-001` em `03-tasks.md`): `react-native-paper` + `expo-router` + `react-native-web`.

## Referência visual (mockups)

Antes de criar ou alterar o **layout** de uma tela (estrutura, hierarquia visual, componentes usados, espaçamento, estados), confira o mockup correspondente:

- `assets/images/screen/movel.png` — telas mobile do app "Shared Expense" (referência para `expense/app`, Expo/React Native): onboarding, login (com "Continuar com Google" e link de cadastro — nota: o `expense/frontend` web atual removeu o link de cadastro por não ter página de registro, ver `docs/backlog/`), dashboard do grupo, lista de grupos, listagem de despesas, nova despesa, detalhe da despesa (divisão/pagamento), configurações do grupo e perfil do usuário.
- `assets/images/screen/desktop.png` — telas desktop do CRUD de despesas (referência para `expense/frontend`, web): listagem, visualização, criação, edição, confirmação de exclusão, exclusão concluída, listagem vazia e filtros/busca — com navegação lateral fixa (Resumo, Despesas, Participantes, Pagamentos, Relatórios, Configurações).

Esses mockups são **referência de layout e fluxo**, não a implementação atual — nem tudo que aparece neles já existe em código (ex.: navegação lateral desktop, tela de perfil completa, relatórios). Ao portar uma tela existente ou criar uma nova, comece pelo mockup do dispositivo alvo (mobile → `movel.png`, web/desktop → `desktop.png`) e só depois ajuste para os componentes reais disponíveis (MUI no web, `react-native-paper` no Expo) e para o contrato de API existente — não implemente campo/tela que o mockup sugere mas a API não suporta sem antes tratar isso como mudança de API (backend).

## Antes de codar

1. **Identifique o alvo**: qual dos dois frontends a tarefa afeta? Normalmente dá para inferir pelo caminho de arquivo citado ou pelo texto da task (ex.: referências a `TASK-00x` do Épico A em `03-tasks.md` são sempre `expense/app`). Se estiver ambíguo, pergunte antes de criar arquivo em pasta errada.
2. **Carregue o contexto abaixo se ele ainda não estiver na conversa** (não releia o que já foi lido na mesma sessão):
   - O mockup relevante em `assets/images/screen/` (ver "Referência visual" acima) — layout/hierarquia visual da tela.
   - `02-plan.md` §2 — mapeamento de peças da migração (`react-router-dom` → Expo Router, MUI → `react-native-paper`, `localStorage` → `expo-secure-store`) e quais telas faltam portar.
   - `01-specify.md` §2-3 — glossário de domínio (User, Group, Expense, Quota, payers) e fluxos, para nomear campos/telas de forma consistente com o backend.
   - O arquivo de padrão mais próximo já existente no frontend alvo (ex.: `frontend/src/api.ts`, `frontend/src/theme.ts`, ou uma tela irmã em `frontend/src/pages/` ou `app/`) — use como referência de estilo antes de inventar um padrão novo.

## Convenções fixas

- TypeScript em modo estrito, sem `any` não justificado.
- O cliente só consome a API — não duplique regra de negócio (cálculo de divisão de despesa, apuração de saldo) que já existe no backend.
- Os dois frontends devem consumir o mesmo contrato de API sem divergir — se uma tela precisar de um campo novo, isso é mudança de API (backend), não workaround no cliente.
- `expense/frontend` não é alterado nem descontinuado por causa da migração — ele continua em produção normalmente até o corte.

## Gates human-in-the-loop (de `00-constitution.md` §5.2)

- Livre para codar, testar e abrir PR em branch.
- **Exige aprovação humana**: merge em `main`, deploy/publish (inclusive build EAS do Expo), e o corte de produção de `expense/app` substituindo `expense/frontend` (`TASK-010`). Nunca assuma essa aprovação como implícita.
