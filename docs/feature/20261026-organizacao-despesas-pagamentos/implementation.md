# Implementation — Organização de Despesas e Pagamentos

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261026

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio: a feature segue `04-implementation.md` sem exceção. Duas notas de contexto: (1) os documentos do SDD (`specify.md`, `plan.md`, `tasks.md`) e cada task só são commitados com autorização do usuário; (2) a branch da feature foi criada de `dev` sem `git pull` prévio — `git fetch origin` em 2026-10-26 mostrou `origin/dev` idêntico a `dev` (`git rev-list --count dev..origin/dev` → `0`), então não havia nada a integrar.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-499 | Concluída | 2026-10-26 | Claude (sessão com o mantenedor) | **RED:** `cd frontend && npx vitest run src/utils/cycleOrdering.test.ts` com o teste escrito e `cycleOrdering.ts` só com esqueleto (assinaturas finais, comportamento errado de propósito) → `23 failed \| 6 passed (29)`, falhas de asserção e não de import. Os 6 que já passavam são invariantes que o esqueleto cumpre por coincidência e que seguem protegendo o código real: lista vazia, não mutar a entrada (saldos e acertos), `null`/`undefined` → duas listas vazias, soma das contagens e dos valores na partição, `confirmedAt` vazio → `false`. **GREEN:** mesmo comando após implementar → `29 passed (29)`. **Checagem de mutação** com 4 defeitos reais em `cycleOrdering.ts`, um por vez, cada um seguido do mesmo `vitest run` (arquivo restaurado e conferido com `diff -q` contra a cópia): desempate de `id` invertido → `2 failed`; total do devedor = maior acerto em vez da soma → `1 failed`; saldo comparado pelo valor bruto, sem centavos → `1 failed`; nome comparado sem `localeCompare('pt-BR')` → `3 failed` — **os 4 derrubaram ao menos um teste**. **Checklist:** `npx tsc --noEmit` → exit 0, sem saída; `grep -nE "from 'react'\|\bany\b" src/utils/cycleOrdering.ts` → nenhuma ocorrência; `git status --short -- frontend/` → só `cycleOrdering.ts` e `cycleOrdering.test.ts` (ambos novos), nenhum outro arquivo de `frontend/src` tocado; `npm test` (suíte completa) → `Test Files 63 passed (63)`, `Tests 807 passed (807)`, `Duration 513.05s`. | Primeira task da feature, implementada direto na branch `feature/20261026-organizacao-despesas-pagamentos`. Sem commit: aguarda a autorização do usuário, porque o `git merge --no-ff` da TASK-500 em diante precisa de histórico. Expectativas escritas à mão a partir do specify §5.1 a §5.3, sem reaproveitar o código testado. Decisão de tipo: os parâmetros de lista são `readonly`, o que permite passar `Object.freeze` nos testes de não-mutação e aceitar arrays somente leitura nos chamadores. |
