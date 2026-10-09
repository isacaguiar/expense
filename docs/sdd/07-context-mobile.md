# Contexto de execução — App móvel (Flutter)

> Documento **portátil** (markdown puro, sem nada específico de ferramenta): o que qualquer assistente de IA (ou dev) deve carregar antes de mexer no app móvel. Contrato de portabilidade e relação com as skills: `README.md`, "Skills e portabilidade".

Versão: 1.0 · Última atualização: 2026-10-08

---

O app móvel do Shared Expense é em **Flutter (Dart)** com Material 3, no projeto `app/`, **Android primeiro** (`decisions/ADR-010`). **O projeto ainda não existe**: o trabalho está no item 074 do backlog (`docs/backlog/app-flutter-google-play.md`) e só começa depois que os itens 070 (rateio de parcelas no backend) e 071 (web usando o contrato novo) estiverem em produção. O app consome a mesma API Laravel do web, via JWT Bearer.

## O que já está decidido (ADR-010)

- Flutter/Dart, Material 3, projeto `app/` na raiz, Android primeiro; iOS fica fora da decisão.
- O `frontend/` (React web) continua sendo o cliente web em produção; o app não o substitui e **não há código compartilhado** entre os dois.
- O backend não muda de contrato por causa do app: só mudanças aditivas (`00-constitution.md` §4.1 e §4.3).
- **Regra de negócio fica no backend** (`00-constitution.md` §1 item 1). Em particular, o app **não** monta parcelas de despesa: isso é o item 070.
- Sem atualização OTA: toda correção passa pela loja. A versão mínima da API (atualização forçada) é o item 073.
- **Escolhas de biblioteca não estão decididas** (gerência de estado, rotas, cliente HTTP, armazenamento seguro, deep links, etc.): serão definidas no `plan.md` da feature do app. Até lá, não escolha por conta própria.

## Antes de codar

1. **Confirme o alvo**: este documento é só do `app/` (Dart). Web → `05-context-frontend.md`; API → `06-context-backend.md`.
2. **Se ainda não existe pasta da feature do app em `docs/feature/`**, pare e siga "Quando não houver task aplicável" do `CLAUDE.md`: o caminho é promover o item 074 com `/promover-backlog 074`, não improvisar o scaffold.
3. **Carregue o contexto abaixo se ele ainda não estiver na conversa** (não releia o que já foi lido na mesma sessão):
   - `decisions/ADR-010-app-movel-em-flutter.md` — o porquê e o que já está decidido.
   - `specify.md`, `plan.md` e `tasks.md` da feature do app, quando existirem (incluem as escolhas de biblioteca).
   - `06-context-backend.md` e `backend/routes/api.php` — o contrato da API que o app consome.
   - `01-specify.md` §2-3 — glossário de domínio (User, Group, Expense, Quota, payers) para nomear campos e telas de forma consistente com o backend.
   - `assets/images/screen/movel.png` — referência de **layout** das telas mobile (não é a implementação atual).
   - `frontend/src/pages/` — referência de **fluxo e comportamento** de cada tela no web (não de código: a linguagem é outra).
   - Disfagia APP, projeto Flutter do mantenedor fora deste repositório — referência de configuração de build Android, assinatura, versionamento e publicação.

## Convenções fixas

- Dart/Flutter com análise estática limpa e testes passando antes de commit; a configuração exata (lints, CI) é definida no `plan.md` da feature do app.
- O app só consome a API: não reimplemente regra de negócio (divisão de despesa, saldo, acerto, parcelas, ciclos). Se uma tela precisa de campo ou regra nova, isso é mudança de API (backend), não workaround no cliente.
- Mudança de contrato é só aditiva, porque versões antigas do app ficam instaladas até a pessoa atualizar.
- Segredos nunca no repositório nem no chat: chave de upload (keystore), credenciais do Play Console e contas de serviço são do mantenedor.
- Requisitos da Play que precisam estar prontos antes do release estão na descrição do item 074: exclusão de conta (item 072), versão mínima da API (item 073), `targetSdk` mínimo exigido pela Play (36 para apps novos desde 31/08/2026; conferir a política vigente na hora de publicar) e as declarações do Console.

## Gates human-in-the-loop

Fronteira de autonomia: `00-constitution.md` §5.2 (tabela normativa) e `agent-architecture.md` §5 (desenho). **Publicar o app na Google Play (primeira versão e releases) é gate humano.** Nunca assuma aprovação de merge, deploy ou publicação como implícita.
