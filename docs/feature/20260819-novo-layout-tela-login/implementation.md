# Implementation — Novo Layout da Tela de Login

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20260819

---

## 1. Desvios do fluxo padrão (se houver)

A partir da `TASK-071`, as tasks são commitadas diretamente na branch da feature (`frontend/20260819-novo-layout-tela-login`), um commit por task, sem sub-branch `-TASK-0xx` própria — diferente do passo 1 de `04-implementation.md` (que pede sub-branch a partir da 2ª task em diante). Motivo: a `TASK-070` já nasceu direto na branch da feature (primeira task, conforme o fluxo padrão); criar sub-branches retroativamente para as tasks seguintes só pra depois fazer merge `--no-ff` de volta na mesma branch não muda o resultado final (mesma sequência linear de commits) e adiciona overhead sem ganho de rastreabilidade nesta feature pequena. Cada task continua sendo um commit individual, revertível e citado no log abaixo — a rastreabilidade por task é mantida, só o mecanismo de branch/merge é simplificado.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-070 | Concluída | 2026-08-19 | IA (Claude) | `cd frontend && npx tsc --noEmit` — exit 0. `npx vitest run` — 21/21 testes passando (6 arquivos), sem regressão. `read_page`/`read_console_messages` no preview (`localhost:3000`) confirmam: seletor de idioma, card de login (form intacto) e rodapé com links `href="#"` renderizados, sem erro de console. | `node_modules` do frontend estava corrompido (dist truncado de `react-router-dom`) — reinstalado via `rm -rf node_modules && npm ci` antes de validar; não é regressão desta task, é o problema já registrado no backlog `005`. Screenshot visual não disponível neste ambiente (Browser pane não exibido), validação feita via árvore de acessibilidade. |
| TASK-071 | Concluída | 2026-08-19 | IA (Claude) | `npx tsc --noEmit` — exit 0. `npx vitest run` — 21/21 testes passando. `read_page` confirma título "Bem-vindo de volta!", campos com placeholder/ícone, "Lembrar de mim", link "Esqueci minha senha" (`href="#"`) e botão "Entrar"; clique no botão de mostrar senha (`ref_10`) alterna `input#password` de `type="password"` para `type="text"` (`javascript_tool`). | Após o `npm ci` da TASK-070, o cache do Vite (`node_modules/.vite`) ficou dessincronizado e causou erro de "Invalid hook call" só no console (React duplicado no bundle antigo) — a página renderizava e funcionava normalmente (confirmado pelo teste de clique acima). Resolvido limpando `node_modules/.vite` e reiniciando o dev server; não é bug do código desta task. |
