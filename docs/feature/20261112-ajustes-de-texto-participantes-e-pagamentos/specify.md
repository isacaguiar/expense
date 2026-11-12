# Specify — Ajustes de texto em Participantes e Pagamentos

> Feature: tirar o que se repete na tela em dois lugares, os selos "Paga por X" da linha própria em **Participantes** e a frase "X deve pagar R$ N a Y" das abas de **Pagamentos**. Origem: pedido dele em 2026-11-12, ao responder as duas decisões que ficaram abertas na feature `docs/feature/concluidas/202611/20261110-quem-paga-se-declara-e-credor-recusa/` (TASK-607 e 608); é só interface, sem defeito e sem mudança de backend.

Versão: 1.1 · Criado em: 20261112

**v1.1 (2026-11-12):** aprovado por ele ("sim"). **D1 a D4 e D6 são dele; a D5 ficou na recomendação A** (o Painel mantém a linha de hoje): a pergunta era "A ou B" e ele respondeu "sim", então registrei a recomendação, que é a mais conservadora; se ele queria a B, corrige no Plan, onde a D5 volta a aparecer.

---

## 1. Problema

Depois da feature "quem paga se declara", duas telas passaram a dizer a mesma coisa duas vezes:

- Em **Participantes**, a linha do próprio usuário mostra o texto "Você paga por: Bruno, Davi" e, logo abaixo, um selo "Paga por Bruno" e outro "Paga por Davi" (os selos são da feature anterior, o texto é da TASK-607).
- Em **Pagamentos**, cada linha de acerto começa com "Ana deve pagar R$ 100,00 a Carla", mas a aba já se chama "Valores a pagar" (e a outra "Valores pagos"): "deve pagar" e "a" só repetem o título.

Ele decidiu (2026-11-12): tirar os selos da linha própria; tirar "deve pagar" e "a" mantendo pagador, valor e credor, num arranjo novo (os nomes com a seta no meio e o valor numa linha abaixo, D6); e **manter** o comprovante aguardando aceite em "Valores pagos".

### 1.1 Verificação prévia

- Os dois pontos eram as decisões (a) e (b) deixadas em aberto no PR #274, já mergeado em `dev`. Esta feature parte da `dev` (`dc66fdefd4`).
- Nenhum arquivo de backend muda: os dados que as telas usam já existem (`members[].responsibles`, `settlements[]`).

---

## 2. Achados confirmados

Cada item foi conferido no código da `dev` (`dc66fdefd4`).

### 2.1 Participantes: o texto e os selos dizem o mesmo na linha própria
`GroupMembersForm.tsx`, `renderPaymentLinks` (`:284-311`): na linha do usuário logado mostra `Você paga por: A, B` (`:297-301`, só quando `user.id === currentUserId`) e, para **qualquer** linha com dependentes, um `Chip` "Paga por {nome}" por dependente (`:302-308`). Na linha própria os dois aparecem juntos. Nas linhas dos outros membros só há os selos, e eles são a única indicação de quem aquele membro paga.

### 2.2 Pagamentos: a frase repete o título da aba
`PayableSettlementList.tsx:142-148` monta `<strong>{pagador}</strong> deve pagar R$ {valor} a <strong>{credor}</strong>`, com o valor em vermelho (`color="error.main"`). `PaymentSettlementsPanel.tsx` desenha as duas abas ("Valores a pagar (n)", `:91`, e "Valores pagos (n)", `:93`) e renderiza **o mesmo** `PayableSettlementList` nas duas (`:97-111`). Hoje a linha é `[avatar do pagador] [frase] [seta] [avatar do credor]`: o avatar de cada lado e a seta ficam fora da frase (`:139` e `:151-153`), e a seta é um `ArrowForwardIcon` solto antes do avatar do credor.

### 2.3 A mesma lista também serve o Painel
`GroupPositionCard.tsx:216-224` usa `PayableSettlementList` sob o título "Você paga" (`:208-210`), com o usuário logado como devedor de tudo. Os testes do Painel (`GroupPositionCard.test.tsx`, `MyPanel.test.tsx`) **não** citam a frase. Mudar o componente sem cuidado muda o Painel, que ele não citou (D5).

### 2.4 A outra lista, `SettlementList`, tem a mesma frase e não é de Pagamentos
`SettlementList.tsx:48` ("deve pagar") é usada em `ClosingReportPanel.tsx:205` (relatório de fechamento) e `SummarySidePanel.tsx:71` (painel lateral do resumo), só para exibir. Não é a tela de Pagamentos e fica fora desta feature.

### 2.5 O selo do credor "Deve pagar a você" é outra coisa
`PayableSettlementList.tsx:170-178`: o `Chip` do credor diz "Pagou a você" (há comprovante e ele não está aguardando o aceite: enviado pelo devedor ou aceito) ou "Deve pagar a você" (sem comprovante, recusado ou aguardando o aceite). Ele mostra o **papel** de quem olha, não repete a aba. Ele decidiu mantê-lo (D4).

### 2.6 O comprovante aguardando aceite cai em "Valores pagos"
A divisão das abas é por `confirmedAt` (`utils/cycleOrdering.ts`, `isSettlementConfirmed`), e o servidor o preenche no comprovante que aguarda o aceite. Ele decidiu **manter** assim (D2): não muda código; só passa a ser decisão em vez de "limite conhecido" no `01-specify.md`.

### 2.7 Testes que dependem dos textos
- **Selo em Participantes:** `GroupMembersForm.responsible.test.tsx:146-159` espera os selos "Paga por Bruno" e "Paga por Carla" **na linha do usuário logado** (Ana), e muda. `GroupMembersForm.paysFor.test.tsx:161` espera "Paga por Carla" na linha de **outro** membro (Bruno) e continua valendo.
- **Frase em Pagamentos:** 44 ocorrências de "deve pagar" (em minúsculas, a frase) em 5 arquivos que renderizam `PayableSettlementList`: `PayableSettlementList.test.tsx` (3), `PaymentSettlementsPanel.test.tsx` (13), `PaymentSettlementsPanel.responsible.test.tsx` (7), `Payments.test.tsx` (15) e `Payments.responsible.test.tsx` (6). Vários usam `getAllByText(/deve pagar/)` **para ler a ordem das linhas** (`PaymentSettlementsPanel.test.tsx:70-98`), então precisam de outra âncora (o Plan define). As 6 ocorrências de "Deve pagar a você" (selo do credor) continuam valendo.
- **Não citam a frase:** os testes do Painel, de `PayableSettlementList.decision`/`.responsible` (salvo o selo) e os de `SettlementList`, `ClosingReportPanel`, `SummarySidePanel`, `ExpenseManager` e `GroupSummary` (outro componente, §2.4).

---

## 3. Requisitos

### 3.1 O que a feature entrega

1. **Participantes:** a linha do **próprio usuário** deixa de mostrar os selos "Paga por X"; fica só `Você paga por: A, B`. As linhas dos **outros** membros seguem com os selos.
2. **Pagamentos:** nas duas abas, "deve pagar" e "a" saem. A linha passa a mostrar, entre os dois avatares, **o pagador, uma seta e o credor** numa linha e **o valor (em vermelho) numa linha abaixo** (D6).
3. **Sem mudança:** o comprovante aguardando aceite continua em "Valores pagos"; o selo "Deve pagar a você" e os demais selos e botões ficam; backend não muda.
4. **Documentação:** `docs/sdd/01-specify.md` descreve as duas telas como ficam.

### 3.2 Regras adotadas

| # | Regra |
|---|---|
| R1 | O selo "Paga por {nome}" aparece só na linha de **outro** membro (`user.id !== currentUserId`). Na linha própria o texto "Você paga por: …" é a única indicação. |
| R2 | A linha de acerto de `PayableSettlementList` não contém as palavras "deve pagar" nem o "a" entre o valor e o credor. Entre os avatares, a primeira linha é `pagador → credor` (a seta fica **entre os nomes** e a seta solta de antes sai) e a segunda, abaixo, é o valor, alinhado ao início do nome do pagador. O valor segue em vermelho e em negrito; pagador e credor seguem em negrito. Os dois avatares ficam nas pontas, como hoje. |
| R3 | A divisão das abas (`confirmedAt`) não muda. |
| R4 | Nenhum selo de estado muda de texto ("Aguardando seu aceite", "Aguardando aceite do credor", "Recusado por …", "Você recusou o comprovante", "Aceito pelo credor", "Pagou a você", "Deve pagar a você", "Você paga por {nome}"). |
| R5 | Nenhuma rota, campo de API, tipo ou regra de negócio muda. |

### 3.3 Decisões

D1 a D4 e D6 são **dele** (2026-11-12). A D5 é a recomendação, aprovada com o "sim" dele ao specify.

| # | Decisão | Opções | Recomendação | Por quê | Status |
|---|---|---|---|---|---|
| D1 | Selos "Paga por X" | A) tirar só da linha própria<br>B) tirar de todas as linhas | **A** | Nas linhas dos outros os selos são a única indicação de quem o membro paga; na própria linha o texto "Você paga por" já diz | Decidida por ele: **A** |
| D2 | Comprovante aguardando aceite | A) fica em "Valores pagos"<br>B) vai para "Valores a pagar" | **A** | B mexe em `cycleOrdering` e em cerca de 8 arquivos que leem `confirmedAt` | Decidida por ele: **A** (nenhuma mudança de código) |
| D3 | Onde a frase sai em Pagamentos | A) só em "Valores a pagar"<br>B) nas duas abas | **B** | B deixa a linha igual nas duas abas e sem tratamento por aba; A obrigaria o painel de abas a ligar a mudança só em "Valores a pagar" | Decidida por ele: **B** (ele escolheu B quando perguntei, sem registrar o motivo) |
| D4 | Selo do credor "Deve pagar a você" | A) manter<br>B) retirar | **A** | Mostra o papel de quem olha, não repete a aba; combina com o "Pagou a você" da outra aba | Decidida por ele: **A** |
| D5 | O Painel (`GroupPositionCard`, título "Você paga") | A) manter a linha de hoje (a frase)<br>B) mudar junto, pela mesma lista | **A** | Ele falou da tela de Pagamentos; o Painel é outra tela e não foi pedido. **Custo de A (subiu com a D6):** como o arranjo da linha muda (não é só trocar o texto), `PayableSettlementList` passa a manter **dois arranjos**, o de hoje (Painel) e o novo (Pagamentos), escolhidos por uma propriedade opcional que só Pagamentos liga. B deixa um arranjo só e menos código, mas muda uma tela que ele não citou | **A** (recomendação, aprovada com o "sim" dele ao specify em 2026-11-12) |
| D6 | O arranjo da linha, sem as palavras | A) mesma linha de hoje, só sem as palavras (pagador · valor · credor)<br>B) o desenho dele: avatares nas pontas, `pagador → credor` numa linha e o valor abaixo, alinhado ao início do nome do pagador<br>C) o mesmo sem avatares | **B** | É o que ele desenhou; com a pergunta sobre avatares e alinhamento, ele escolheu manter os avatares nas pontas e o valor alinhado ao nome do pagador. O Plan mede a quebra de linha em 375 px | Decidida por ele: **B** (2026-11-12) |

### 3.4 Interface (web)

- **Participantes, linha própria:** o texto `Quem paga por você: …` (se houver) e `Você paga por: Bruno, Davi` ficam; os selos `Paga por Bruno` e `Paga por Davi` somem. Os botões "Quem paga por mim", "Eu pago por…" e "Sair do grupo" não mudam.
- **Participantes, linha de outro membro:** `Quem paga por {nome}: …` e os selos `Paga por {dependente}` como hoje.
- **Pagamentos, as duas abas** (desenho dele, D6):

  ```
  (AP) Ana Paula Rodrigues → Carla (C)
       R$ 100,00
  ```

  no lugar de `(AP) Ana Paula Rodrigues deve pagar R$ 100,00 a Carla → (C)`. Os dois avatares ficam nas pontas; a seta passa a ficar entre os nomes; o valor (vermelho, negrito) vai para a linha de baixo, alinhado ao início do nome do pagador. Selos, nota e botões, como hoje.
- **Layout medido no navegador** em 375, 768, 1024, 1280 e 1440 px (a regra de largura vem da V1 da feature "responsável do devedor"), porque o jsdom não mede.

---

## 4. Riscos

| # | Risco | Impacto | Mitigação |
|---|---|---|---|
| RK1 | Tirar a frase deixa a linha menos explícita para leitor de tela e em prints fora da aba | Quem lê só a linha ouve "Ana Paula Rodrigues R$ 100,00 Carla" | Aceito por ele: o título da aba ("Valores a pagar" ou "Valores pagos") dá o contexto. Não se esconde o texto antigo no DOM (seria manter o que ele pediu para tirar) |
| RK2 | Quebra de linha em tela estreita: dois nomes longos mais a seta na mesma linha, entre os dois avatares | Em 375 px a primeira linha quebra em duas e o valor deixa de ficar alinhado ao nome do pagador | Medir no navegador (cinco larguras, com nomes de 25 a 40 caracteres) e ajustar só o espaçamento e a quebra, sem mudar o conteúdo; o valor fica abaixo do bloco dos nomes, qualquer que seja a quebra |
| RK3 | ~44 asserções antigas e a leitura de ordem por `getAllByText(/deve pagar/)` | Teste que passa por motivo errado (ordem não conferida) | O Plan define uma âncora estável para a ordem; mutation check mostra que a ordem continua sendo verificada |
| RK4 | O Painel mudar sem querer (lista compartilhada) | Tela não pedida muda | D5-A e um teste do Painel que fixa a frase de hoje |
| RK5 | "Deve pagar a você" aparece em "Valores pagos" para o credor, no comprovante aguardando aceite | Rótulo parece contradizer a aba | Já é assim hoje (escolha da TASK-608, mantida por ele em D2 e D4); fica registrado como limite conhecido, sem mudar |

---

## 5. Critérios de aceite

### 5.1 Participantes
- CA-1: na linha do usuário logado não há selo "Paga por X", e o texto "Você paga por: …" continua. Teste novo (e o de `GroupMembersForm.responsible.test.tsx:146-159` reescrito) fixa os dois lados.
- CA-2: na linha de outro membro os selos "Paga por X" continuam (`GroupMembersForm.paysFor.test.tsx:161` segue verde **sem edição**).

### 5.2 Pagamentos
- CA-3: nas duas abas, nenhuma linha contém "deve pagar" nem o "a" entre valor e credor; cada linha tem os dois avatares nas pontas, `pagador → credor` com a seta **entre os nomes** (e sem a seta solta de antes), e o valor "R$ N" numa linha abaixo, em vermelho.
- CA-4: a ordem das linhas em cada aba continua verificada por teste (outra âncora que não a frase).
- CA-5: o selo "Deve pagar a você", os demais selos, a nota e os botões não mudam; o comprovante aguardando aceite continua em "Valores pagos" (teste de caracterização).
- CA-6: o Painel segue como hoje (conforme D5).

### 5.3 Qualidade e documentação
- CA-7: `npx tsc --noEmit` limpo e suíte inteira do Vitest verde (`--maxWorkers=1`, sem outra suíte rodando junto); mutation check por regra (R1 e R2).
- CA-8: layout medido em 375, 768, 1024, 1280 e 1440 px nas duas telas.
- CA-9: `docs/sdd/01-specify.md` atualizado (glossário/§3.3: os selos só nas linhas dos outros membros e a frase das abas; o "Limite conhecido" do comprovante aguardando aceite vira decisão); a Constitution não é editada.

---

## 6. Fora de escopo desta feature

- **`SettlementList`** (relatório de fechamento e painel lateral do resumo): mantém "deve pagar".
- O **Painel** (`GroupPositionCard`), se a D5-A for aprovada.
- **Backend**, rotas e contrato da API; mover o comprovante aguardando aceite de aba (D2-A).
- O selo "Você paga por {nome}" em Pagamentos e todos os selos de estado do comprovante.
- Texto alternativo ou rótulo de leitor de tela para a linha (RK1).
- Manual e site (backlog 140).
