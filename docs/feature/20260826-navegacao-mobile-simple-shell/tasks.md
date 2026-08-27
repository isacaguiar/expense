# Tasks — Navegação mobile do SimpleShellLayout

> Formato igual ao usado no SDD geral — ver `docs/sdd/03-tasks.md` para a definição completa do formato e a regra de atomicidade ("se a descrição tem 'e' ligando duas entregas independentes, é duas tasks").

Versão: 1.0 · Criado em: 20260826

| ID | Título | Tipo | Plan ref | Gate humano | Status |
|---|---|---|---|---|---|
| TASK-208 | Integrar `MobileNavDrawer` ao `SimpleShellLayout` | frontend | plan.md §1 | nenhum | Pendente |

## Critérios de aceite

- **TASK-208**: Em `SimpleShellLayout`, clicar no botão hambúrguer do `GroupHeader` (visível abaixo de `md`) abre o `MobileNavDrawer` com os itens de `simpleNavItems(navigate)` (Home, Despesas, Participantes, Pagamentos, Relatórios, Configurações com submenu, Sair); clicar em um item de navegação ou ação do drawer fecha o drawer. Acima de `md`, o comportamento não muda (sidebar fixa, sem drawer visível) — `SimpleShellLayout.test.tsx` cobre isso e continua passando sem alteração de asserções pré-existentes. Navegar manualmente para as rotas sob `SimpleShellLayout` (Dashboard/"Meus Grupos", Minha Conta, Alterar Senha) no browser em viewport mobile (`< md`, ex. 375px) confirma o hambúrguer presente e funcional nas três telas — validação manual registrada em `implementation.md` com print ou passo a passo, mesma justificativa da feature anterior (comportamento de layout compartilhado difícil de cobrir 100% em teste automatizado de uma tela isolada). `npx vitest run` no frontend passa.
