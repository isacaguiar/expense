# Implementation — Estabilizar o teste do AcceptInvitePage

> Como as tasks de `tasks.md` viram código. O fluxo (branch, checklist pré-PR, gates) é o de `docs/sdd/04-implementation.md` — só documente aqui um desvio específico desta feature, se houver.

Versão: 1.0 · Criado em: 20261021

---

## 1. Desvios do fluxo padrão (se houver)

Nenhum desvio: a feature segue `04-implementation.md` sem exceção.

## 2. Log de implementação

Preenchido conforme as tasks de `tasks.md` são executadas. Uma linha por task. Cite o comando real executado e o resultado obtido — não basta escrever "testado"/"validado" em prosa.

| Task ID | Status | Data | Responsável | Comandos executados / resultado | Observações |
|---|---|---|---|---|---|
| TASK-409 | Concluída | 2026-10-21 | Claude (sessão com o mantenedor) | **RED.** Arquivo original + sonda temporária `await new Promise(resolve => setTimeout(resolve, 2300))` no começo do teste `:118`. `cd frontend && npx vitest run src/pages/AcceptInvitePage.test.tsx` → `1 failed \| 8 passed (9)`; falhou só `shows the backend error message when the token is invalid or expired` (2963 ms) com `AssertionError: expected "vi.fn()" to not be called at all, but actually been called 1 times`. **GREEN.** Acrescentado ao fim do teste `:96` o `await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 })` com comentário; mesmo comando, sonda ainda no lugar → `9 passed (9)` (`:96` 2604 ms, `:118` 2865 ms). **Estado final.** Sonda removida (`grep -n "SONDA\|2300"` sem ocorrência); `git diff --stat` → 1 arquivo, 4 inserções (comentário de 2 linhas, o `waitFor` e uma linha em branco), nenhuma remoção; `cd frontend && npx vitest run src/pages/AcceptInvitePage.test.tsx` em 3 rodadas seguidas → `9 passed (9)` nas três; `cd frontend && npx tsc --noEmit` → exit 0. | **Mutação:** o estado "teste `:96` sem a espera + sonda" é, byte a byte, o do RED acima, então a rodada vermelha vale como a checagem de mutação e não repeti a execução. **Confirmação do achado do plan §0:** `não mede a redefinição de senha como cadastro` (`:153`) levava 1306 ms antes e 2634 ms depois, ou seja, passou a esperar o próprio timer em vez do vazado do `:96`. O `expect(mockNavigate).not.toHaveBeenCalled()` do teste `:118` continua (agora na linha 135, deslocado pelas 4 linhas). Só `AcceptInvitePage.test.tsx` mudou; o componente não foi tocado (R1). Sem segredo no diff. Sem push: a branch só sobe no PR da feature. |
