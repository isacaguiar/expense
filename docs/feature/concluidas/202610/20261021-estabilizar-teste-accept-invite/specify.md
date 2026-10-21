# Specify — Estabilizar o teste do AcceptInvitePage

> Feature: o teste do `AcceptInvitePage` que confirma o sucesso do convite deixa um redirecionamento de 2 s pendurado, e esse timer pode disparar dentro do teste seguinte e derrubá-lo. A feature faz esse teste parar de depender de tempo. Origem: item 081 de `docs/backlog/` (`accept-invite-teste-timer-vaza-entre-testes.md`), promovido com `/promover-backlog 081`; achado em 2026-10-10 ao rodar a suíte completa na TASK-407 da feature `20261010-filtros-credor-devedores-status-despesas`.

Versão: 1.0 · Criado em: 20261021

---

## 1. Problema

A suíte do frontend tem uma falha intermitente num arquivo que nada tem a ver com o que está sendo alterado. Ninguém sabe se o vermelho é regressão real ou azar de tempo, e cada rerodada custa mais de 2 minutos. A falha aparece justamente quando a máquina está carregada, que é a condição do CI.

Evidência do backlog (2026-10-10, `cd frontend && npm test`, branch da TASK-407): 2 falhas em 3 execuções, sempre no mesmo teste, que levou 2795 ms sob a carga da suíte inteira. Isolado (`npx vitest run src/pages/AcceptInvitePage.test.tsx`), passou 3 de 3. A mensagem era `expected "vi.fn()" to not be called at all, but actually been called 1 times`.

## 2. Achados confirmados

Tudo abaixo foi lido no código real desta branch (a partir de `origin/dev`, 2026-10-21).

### 2.1 O componente agenda um redirecionamento que ninguém cancela

`frontend/src/pages/AcceptInvitePage.tsx:85`: depois do sucesso, `setTimeout(() => navigate('/'), 2000)`. É o único `setTimeout` do arquivo, e o arquivo não tem `useEffect` nem `clearTimeout`. O timer dispara 2 s depois do sucesso, mesmo que o teste (ou a página) já tenha terminado.

### 2.2 O teste da linha 96 termina sem esperar esse redirecionamento

`frontend/src/pages/AcceptInvitePage.test.tsx:96-116` (`submits email/token/password to POST /api/invitations/verify and shows a success message`) acaba assim que a mensagem de sucesso aparece e não espera o `navigate`. O timer segue vivo.

O teste seguinte, `:118-132` (`shows the backend error message when the token is invalid or expired`), termina com `expect(mockNavigate).not.toHaveBeenCalled()`. O `beforeEach` faz `mockNavigate.mockReset()`, mas isso só limpa as chamadas já feitas e não cancela timer agendado. Se o teste de `:118` ainda estiver rodando quando o timer do `:96` vencer, `mockNavigate('/')` é chamado dentro dele e a asserção falha. Sob carga, digitar duas senhas com `userEvent.type` leva o teste a passar dos 2 s.

### 2.3 Dois testes já tratam o mesmo problema; dois não

| Teste | Linha | Espera o redirecionamento? |
|---|---|---|
| Convite: `mede sign_up com method invite…` | `:53-70` | Sim (`vi.waitFor(... { timeout: 3000 })`, com comentário explicando) |
| Convite: `submits email/token/password…` | `:96-116` | **Não**, é o que vaza para o `:118` |
| Reset: `não mede a redefinição de senha como cadastro` | `:153-169` | Sim (mesmo `vi.waitFor`) |
| Reset: `submits to the same POST…` | `:171-191` | **Não**; o timer vaza para `:193`, que hoje não confere `navigate` e por isso não falha |

O teste do `:171` tem o mesmo defeito latente, só não tem hoje uma asserção que o exponha (ver D1).

### 2.4 Configuração do vitest

`frontend/vite.config.js:34-61`: ambiente `jsdom`, `testTimeout` e `hookTimeout` de 15000 ms, e `fakeTimers` não configurado, então o relógio é real. Nenhuma outra página do projeto usa o padrão `setTimeout(() => navigate(...))`; o `AcceptInvitePage` é o único.

### 2.5 A causa vem da leitura do código, e a falha ainda não foi reproduzida de forma determinística

O mecanismo acima explica a mensagem de erro e a diferença entre rodar isolado e rodar sob carga, mas na evidência do backlog a falha só apareceu por acaso de tempo. A reprodução determinística (por exemplo, atrasar o teste do `:118` além dos 2 s) é o passo "vermelho" do `plan.md`; sem ele não dá para afirmar que a correção funciona.

## 3. Requisitos e regras adotadas

| # | Regra | Origem |
|---|---|---|
| R1 | A feature muda **só** `frontend/src/pages/AcceptInvitePage.test.tsx`. O componente `AcceptInvitePage.tsx` não é tocado. | Escolha do usuário ("só o teste"), 2026-10-21 |
| R2 | Depois da correção, nenhum teste do arquivo termina deixando um redirecionamento de 2 s pendente que possa disparar dentro de outro teste. | §2.2 |
| R3 | As asserções existentes não ficam mais fracas. Em especial, `expect(mockNavigate).not.toHaveBeenCalled()` do `:131` continua lá. | Evita "consertar" o teste apagando o que ele prova |
| R4 | A falha é reproduzida de forma determinística **antes** da correção e deixa de ocorrer **depois**, na mesma condição. | §2.5 |
| R5 | A correção não altera config global do vitest (fake timers globais, `testTimeout`, `maxWorkers`). | Mantém a mudança local e reversível |

## 4. Decisões fechadas

Pontos levantados na redação do specify. O usuário aprovou o specify em 2026-10-21 ("Siga"), aceitando as duas recomendações; D2 foi confirmada com medição no `plan.md` §2.

| # | Decisão | Opção A | Opção B | Decisão aceita | Por quê |
|---|---|---|---|---|---|
| D1 | Corrigir também o teste da linha 171 (reset), além do 96? | **A.** Sim, os dois | **B.** Só o 96, que é o que o item de backlog cita | **A** | É o mesmo defeito, no mesmo arquivo, com a mesma correção de uma linha. Sem ele, a próxima asserção de `navigate` colocada no `:193` reabre o problema. O custo é trocar uma segunda linha. |
| D2 | Técnica para não deixar o timer vazar | **A.** Esperar o `navigate` com `vi.waitFor`, como os testes `:53` e `:153` já fazem | **B.** `vi.useFakeTimers()` e avançar o relógio | **A** (confirmada com medição, `plan.md` §2) | Reaproveita o padrão que o arquivo já usa, sem infraestrutura nova. O custo é cada teste esperar até ~2 s. Fake timers cortariam isso, mas misturam relógio falso com `userEvent` e o `findByText` (precisam de `advanceTimers`), e o risco de travar o teste é maior. Decidir com a medição no `plan.md`. |

## 5. Fora de escopo desta feature

- **Corrigir o componente** (proposta 2 do item 081: guardar o id do `setTimeout` e limpá-lo no cleanup de um `useEffect`). Fica de fora por escolha do usuário (R1). Como o item 081 vai para `docs/backlog/concluidos/` no fechamento, essa proposta ganha um item de backlog próprio ali, para não se perder.
- **Outros testes instáveis.** A suíte completa também falhou uma vez em `RegisterPage.test.tsx` (um teste levou 15 s sob carga, que é o `testTimeout`). A causa parece ser carga da máquina, não timer, e nunca foi confirmada. Não entra aqui; se voltar a aparecer, vira item de backlog próprio.
- **Config global do vitest** (R5), **backend** e **app Flutter**.
- **Mudar o comportamento da página** (tempo do redirecionamento, texto da mensagem de sucesso).
