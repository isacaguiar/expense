# Implementation — Melhoria do Menu, Tela de Grupos e Perfil

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260821

---

## 1. Desvios do fluxo padrão (se houver)

<Deixe vazio/apague esta seção se a feature segue `04-implementation.md` sem exceção.>

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-111 | Concluída | 20260821 | IA | `npx vitest run src/layouts/SimpleShellLayout.test.tsx src/layouts/GroupShellLayout.test.tsx` — 10 passed (2 files). `npx tsc --noEmit` — limpo. `npx vitest run` (suíte completa) — 63 passed (14 files). | `accountSettingsNavItems.ts` novo, compartilhado pelos 2 arquivos de navegação. Rótulo do filho "Grupos" virou "Meus Grupos" (pedido do usuário: "Configurações > Meus Grupos, Minha Conta, Alterar Senha") — ajustou testes que checavam o rótulo antigo. `Configurações` do menu com grupo selecionado deixou de linkar direto para `/groups/:id/edit`; esse acesso só volta a existir via ícone de editar na listagem de grupos (TASK-116/117, ainda não implementadas). |
