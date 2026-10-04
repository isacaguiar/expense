# Implementation — Métricas de aquisição, cadastro e ativação por canal

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261004

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio. A feature segue `04-implementation.md`, com duas particularidades de organização:

- Os documentos da feature (`specify`, `plan`, `tasks`) e os itens 061 a 067 do backlog entraram num commit de documentação antes da primeira task de código (`0c3d97d4bf`), porque estavam sem commit na branch de outra feature quando o trabalho começou.
- O item 066 do backlog só vai para `docs/backlog/concluidos/` depois da TASK-396, que só pode ser feita após o deploy. Decisão do usuário em 2026-10-04.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-383 | Concluída | 2026-10-04 | Claude Sonnet 5.5 | **RED**: `cd frontend && npx vitest run src/analytics/consent.test.ts` → 2 falhas, 5 passam. Teste de revogação: `expected true to be false` em `isAnalyticsActive()`. Teste de re-aceite: `expected [ [ 'consent', 'update', { …(1) } ] ] to have a length of 2 but got 1`. **GREEN**: o mesmo comando após a mudança, e `npx vitest run src/analytics` → 4 arquivos, 19 testes passando. `npx tsc --noEmit` → `exit=0`, sem saída. Suíte inteira do frontend `npx vitest run` → 43 arquivos, 293 testes passando. `git diff --stat` → só `consent.ts` (+19/−3) e `consent.test.ts` (+51). | `isAnalyticsActive()` passa a exigir `SCD_GA_LOADED` **e** `getConsent() === 'granted'`; `setConsent('granted')` emite `consent update granted` quando o script já está na página, em vez de sair cedo. Os dois testes novos nomeiam a quebra que pegam: o gate que só olha "script carregado" (revogar não muda nada) e o aceite que sai cedo (GA fica negado até recarregar). Mutação conferida de cabeça: voltar `isAnalyticsActive` à versão antiga derruba o primeiro teste; remover o ramo do `update` derruba o segundo; trocar `'granted'` por `'denied'` nesse `update` também derruba o segundo. Ficou como está o `loadAnalytics` do primeiro aceite (já emitia o `update`), então o comportamento de quem aceita pela primeira vez não muda. |
