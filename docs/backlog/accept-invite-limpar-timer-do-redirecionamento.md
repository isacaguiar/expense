# AcceptInvitePage não cancela o redirecionamento de 2s quando a página sai da tela

ID: 082
Origem: docs/feature/20261021-estabilizar-teste-accept-invite/specify.md §5 (proposta 2 do item 081, deixada de fora); `frontend/src/pages/AcceptInvitePage.tsx:85`
Criado em: 2026-10-21
Prioridade: BAIXA
Status: Aberto

## Descrição
Depois do sucesso, `AcceptInvitePage.tsx:85` faz `setTimeout(() => navigate('/'), 2000)` sem guardar o id do timer e sem nenhum cleanup (o arquivo não tem `useEffect` nem `clearTimeout`). Se o componente desmontar antes dos 2 s, o timer continua vivo e chama `navigate('/')` mesmo assim.

Duas consequências, ambas lidas no código e não vistas no navegador:
- **No uso real:** se a pessoa trocar de rota durante os 2 s da mensagem "Redirecionando para o login...", o timer a leva a `/` de qualquer jeito.
- **Nos testes:** o teste de sucesso termina, o Testing Library desmonta o componente, e o timer continua e dispara dentro do teste seguinte. É a origem do vazamento que o item 081 tratou só do lado do teste (esperando o `navigate` com `vi.waitFor`).

## Por que importa
O impacto no uso é pequeno (janela de 2 s depois de um sucesso). O ganho principal é tirar a causa na origem: com o cleanup, o desmonte automático que o Testing Library faz entre os testes cancelaria o timer, e o vazamento passaria a ser impossível sem depender de cada teste lembrar de esperar. Isso é inferência do código, ainda não testada.

## Proposta
1. Guardar o id do `setTimeout` (um `useRef`) e limpá-lo no cleanup de um `useEffect`, ou agendar o redirecionamento dentro de um `useEffect` que depende de `success`, devolvendo o `clearTimeout`.
2. Teste novo: renderizar, concluir com sucesso, desmontar antes dos 2 s, avançar o relógio e conferir que `mockNavigate` não foi chamado. Fake timers servem bem aqui.
3. Depois disso, os `vi.waitFor` que o item 081 acrescentou deixam de ser necessários para isolar os testes, mas continuam válidos para provar que o redirecionamento acontece. Decidir no `plan.md` se ficam.

É mudança em código de produção de uma página do fluxo de convite e de redefinição de senha, então merece revisão cuidadosa, mesmo sendo pequena.

Tipo sugerido: frontend
