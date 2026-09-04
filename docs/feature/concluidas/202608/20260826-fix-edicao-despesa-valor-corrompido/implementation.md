# Implementation — Corrigir Corrupção de `total_value` ao Editar Despesa

Versão: 1.0 · Criado em: 20260826

---

## 1. Desvios do fluxo padrão

Hotfix isolado numa branch própria (`frontend/20260826-fix-edicao-despesa-valor-corrompido`, a partir de `dev`), fora de `docs/feature/concluidas/202608/20260826-editar-tipo-despesa/` (onde o bug foi descoberto) — pedido explícito do usuário, pra não misturar um bug crítico com a feature nova em andamento.

## 2. Log de implementação

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-001 | Concluída | 20260826 | IA | `ExpenseView.tsx::startEditing()`: `setValue(...)` agora formata `total_value` em pt-BR antes de preencher o campo. `ExpenseView.test.tsx`: teste existente (`'enters edit mode... saves via PUT'`) ganhou asserção `total_value: 1200` no `toMatchObject`; novo teste `'does not corrupt total_value when saving without touching the Valor field'` (fixture `total_value: '1234.56'`, confirma o campo mostra `"1.234,56"` e o PUT manda `total_value: 1234.56`). `cd frontend && npx tsc --noEmit` → sem erro. `npx vitest run src/pages/ExpenseView.test.tsx` → **9/9 verde**. | Bug já estava em produção — toda edição salva por essa tela (mesmo só mudando descrição/data/credor) multiplicava `total_value` por ~100, sem erro visível. Achado durante a implementação de `docs/feature/concluidas/202608/20260826-editar-tipo-despesa/` (teste novo daquela feature expôs o problema). Não investigado/corrigido: dado histórico já corrompido em produção por edições anteriores — decisão do usuário, fora do escopo desta correção (specify §4). |
