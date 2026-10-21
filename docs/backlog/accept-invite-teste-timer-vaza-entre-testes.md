# Teste do AcceptInvitePage falha de vez em quando: o redirecionamento de 2s vaza para o teste seguinte

ID: 081
Origem: docs/feature/concluidas/202610/20261010-filtros-credor-devedores-status-despesas/ (TASK-407; achado ao rodar a suíte completa do frontend); `frontend/src/pages/AcceptInvitePage.tsx:85` e `frontend/src/pages/AcceptInvitePage.test.tsx`
Criado em: 2026-10-10
Prioridade: MEDIA
Status: Promovido para TASK-409 (docs/feature/20261021-estabilizar-teste-accept-invite/; a proposta 2, limpar o timer no componente, ficou fora dessa feature)

## Descrição
`AcceptInvitePage.tsx:85` agenda `setTimeout(() => navigate('/'), 2000)` depois do sucesso. O teste `submits email/token/password to POST /api/invitations/verify and shows a success message` (linha 96) termina assim que a mensagem de sucesso aparece e deixa esse timer pendurado. O teste seguinte, `shows the backend error message when the token is invalid or expired` (linha 118), termina com `expect(mockNavigate).not.toHaveBeenCalled()`. Se ele demora mais de 2s, o timer do teste anterior dispara dentro dele e a asserção falha com `expected "vi.fn()" to not be called at all, but actually been called 1 times`.

O teste da linha 53 já trata o mesmo problema (espera o `navigate` com `vi.waitFor`, com um comentário explicando). O da linha 96 ficou sem o tratamento.

Evidência (2026-10-10, suíte completa `cd frontend && npm test` na branch da TASK-407): 2 falhas em 3 execuções, sempre nesse teste, que levou 2795 ms sob a carga da suíte inteira. Isolado (`npx vitest run src/pages/AcceptInvitePage.test.tsx`), passa 3 de 3. O arquivo não tem relação com o `ExpenseManager`.

## Por que importa
Falha intermitente em CI e na suíte local: ninguém sabe se o vermelho é regressão real ou azar de tempo, e cada rerodada custa mais de 2 minutos. Pior, ele aparece justamente quando a máquina está carregada, como no CI.

## Proposta
1. No teste da linha 96, esperar o redirecionamento como o da linha 53: `await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 })`. Ou usar `vi.useFakeTimers()` nos testes de sucesso e avançar o relógio, o que também corta 2s de cada um.
2. Opcional, no componente: guardar o id do `setTimeout` e limpá-lo no cleanup de um `useEffect`, para não navegar depois de a página sair da tela.

Tipo sugerido: frontend
