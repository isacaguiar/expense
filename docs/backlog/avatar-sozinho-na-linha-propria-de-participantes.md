# Em Participantes, o avatar da linha própria fica sozinho numa linha quando os textos secundários são longos

ID: 151
Origem: TASK-610 (medição de layout) da feature `docs/feature/concluidas/202611/20261112-ajustes-de-texto-participantes-e-pagamentos/`; `frontend/src/pages/GroupMembersForm.tsx` (o `flexWrap: 'wrap'` da linha do próprio usuário e os textos de `renderPaymentLinks`)
Criado em: 2026-11-13
Prioridade: BAIXA
Status: Aberto

## Descrição
Na linha do próprio usuário em Participantes, o avatar fica numa linha só dele e o bloco de texto (e-mail, "Quem paga por você: …", "Você paga por: …") desce para baixo dele. Foi medido na TASK-610, com a linha no conteúdo máximo (dois "Quem paga por você", dois "Você paga por", os três botões) e nomes de 34 a 40 caracteres: acontece em **1440, 1280, 1024 e 375 px**, **antes e depois** de tirar os selos "Paga por X"; só em 768 px o avatar fica ao lado do texto. Ou seja, não vem dos selos: vem de os textos secundários serem longos e de a linha própria ter `flexWrap: 'wrap'`, que joga o bloco de texto para baixo do avatar quando ele não cabe ao lado.

## Por que importa
Só visual: a linha própria fica mais alta e com o avatar solto no topo, diferente das linhas dos outros membros (avatar ao lado do texto). Nada quebra: sem rolagem horizontal, e-mail em 1 linha e botões dentro do card em todas as larguras medidas. O `flexWrap` da linha própria existe de propósito (V1 da feature `20261109-responsavel-do-devedor`: os botões vão numa linha própria para a coluna do e-mail não encolher), então mexer nele pode piorar o que ele resolveu.

## Decisão a tomar na triagem

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Corrigir o avatar solto? | A) deixar como está<br>B) manter o avatar ao lado do texto e deixar só os botões quebrarem para baixo (sem o `flexWrap` na linha toda)<br>C) mover os botões para uma linha própria dentro do bloco de texto | **A**, a menos que ele queira mexer | É só estético e depende de textos longos; B e C refazem o layout que a V1 estabilizou e exigem medir de novo as cinco larguras e os testes de `GroupMembersForm*`. Se ele quiser, vira uma feature enxuta (B ou C) |

Tipo sugerido: frontend (layout de uma linha), com medição em 375, 768, 1024, 1280 e 1440 px e dados de nomes longos.
