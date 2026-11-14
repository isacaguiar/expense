# Plan — Ajustes de texto em Participantes e Pagamentos

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.1 · Criado em: 20261112

**v1.1 (2026-11-12):** aprovado por ele ("sim"), **com a D5-A** (o Painel mantém a linha de hoje; ele respondeu "sim" à pergunta "A ou B" e vale a recomendação).

**Resumo.** *Objetivo:* tirar dois textos repetidos da interface (os selos "Paga por X" da linha própria em Participantes e a frase "deve pagar … a" das abas de Pagamentos) e dar à linha de acerto o arranjo que ele desenhou. *Arquitetura:* só frontend. Em `GroupMembersForm` o selo ganha uma condição (`user.id !== currentUserId`); em `PayableSettlementList` entra uma propriedade opcional `rowLayout` (`'sentence'`, a linha de hoje e o **padrão**; `'route'`, o arranjo novo) que só o painel de abas de Pagamentos liga, e o Painel e os testes do próprio componente seguem como estão. Os testes que lêem a ordem das linhas pela frase passam a ler por âncoras estáveis (`data-testid`) através de um helper compartilhado. *Escopo:* specify v1.1 (D1 a D6).

> **O que este plano ajusta ou acrescenta ao Specify** (nada aqui muda uma decisão aprovada):
>
> 1. **Padrão `'sentence'`, não `'route'`.** O specify §2.7 contou 44 ocorrências de "deve pagar" em 5 arquivos de teste. Com o padrão antigo e só o painel de abas ligando o arranjo novo, as 3 de `PayableSettlementList.test.tsx` (que renderizam o componente puro) **seguem verdes sem edição**; mudam **41 ocorrências em 4 arquivos** (`PaymentSettlementsPanel.test.tsx` 13, `PaymentSettlementsPanel.responsible.test.tsx` 7, `Payments.test.tsx` 15 e `Payments.responsible.test.tsx` 6). É a D5-A (o Painel mantém a linha de hoje) sem nenhum teste a mais para mexer.
> 2. **A âncora da ordem é `data-testid`, num helper de teste.** Hoje 4 arquivos repetem `getAllByText(/deve pagar/).map(...)` para ler a ordem das linhas. Em vez de reescrever quatro cópias, um helper novo (`settlementLines`) lê `settlement-card`, `settlement-payer`, `settlement-payee` e `settlement-amount` e devolve `"Carla → Ana R$ 60,00"`; as 4 cópias passam a chamá-lo. É o que mantém a **ordem** verificada (RK3).
> 3. **Os `data-testid` só existem no arranjo `'route'`.** O arranjo `'sentence'` não muda uma linha (inclusive no DOM), para o Painel ficar idêntico a hoje.

## Restrições globais

Valem para todas as tasks.

- **Só frontend**: nenhuma rota, campo de API, tipo de resposta, migration ou regra de negócio muda (Specify R5). TypeScript estrito, sem `any` não justificado (Constitution §2.3).
- **Nenhuma dependência nova.**
- **Testes antigos só são editados onde o texto mudou de propósito** (Specify §2.7): os 41 pontos em 4 arquivos e o teste de selos de `GroupMembersForm.responsible.test.tsx:146-159`. Todo teste novo entra em arquivo novo. `GroupMembersForm.paysFor.test.tsx`, `PayableSettlementList*.test.tsx`, os testes do Painel e os de `SettlementList` seguem verdes **sem edição** (CA-2, CA-6).
- **Arquivos do frontend são CRLF**: editar com a ferramenta de edição, não com `sed -i`, que neste Git Bash tira os CRs; scripts de mutante substituem `\n` por `\r\n` na âncora.
- **Suíte inteira do front só no fim e sozinha**: `npx vitest run --maxWorkers=1` com **nenhuma outra suíte rodando** (outra suíte `vitest` ao mesmo tempo, com pouca memória livre, deu 26 timeouts de 15 s na suíte da feature anterior em 2026-11-12, `docs/feature/concluidas/202611/20261110-quem-paga-se-declara-e-credor-recusa/implementation.md` §3); antes de rodar, conferir a RAM livre e os processos `vitest` em andamento. Por task: `npx tsc --noEmit` e `npx vitest related <arquivos tocados> --run`.
- **Layout medido no navegador** (375, 768, 1024, 1280 e 1440 px), porque o jsdom não mede: `getBoundingClientRect` e captura, em stack isolada com dados de teste (MySQL de teste em contêiner na porta 3307, `backend/.env.testing` ignorado pelo git, backend na 8001 e Vite na 3001; a stack em 3000/8000 não é tocada), desmontada no fim.
- Um PR único contra `dev`; validação local dele antes do push; merge, promoção e tag são dele.

## Foco de revisão

Casos que o specify implica mas que ninguém lembraria de testar, do mais provável ao menos. Cada um tem o teste na task dona.

1. **A linha própria só perde os selos; o texto fica.** Com dependentes, a linha da logada mostra `Você paga por: A, B` e **nenhum** `Paga por …`; sem dependentes, nem um nem outro. Teste em TASK-610.
2. **A linha de outro membro mantém os selos**, inclusive quando a logada **também** paga por alguém (as duas linhas na mesma tela). Teste em TASK-610.
3. **A ordem das linhas continua verificada** nas duas abas, depois do helper: uma troca de ordem no código ainda derruba o teste (mutante de ordem). Testes em TASK-611 e 612.
4. **O Painel não muda**: `GroupPositionCard` renderiza `PayableSettlementList` sem `rowLayout` e ainda mostra "deve pagar" (teste de caracterização novo). TASK-611.
5. **Nome longo e tela estreita**: pagador e credor de 30 a 40 caracteres em 375 px quebram sem estourar o cartão nem empurrar o valor para fora; o valor continua abaixo do bloco dos nomes. Medição em TASK-612.
6. **Cada selo e botão do cartão segue onde estava** (Pix, Enviar comprovante, Aceitar, Recusar, Excluir, "Você paga por {nome}", "Deve pagar a você", "Pagou a você"): o arranjo novo mexe só no topo do cartão. Os testes antigos de papel × estado seguem verdes (TASK-612).
7. **O comprovante aguardando aceite continua em "Valores pagos"** (D2): um teste de caracterização fixa a aba. TASK-612.
8. **A seta não aparece duas vezes**: no `'route'` há uma seta (entre os nomes) e nenhuma seta solta antes do avatar do credor. TASK-611.

---

## 1. Participantes: selos só nas linhas dos outros (Specify R1, D1, CA-1, CA-2)

- **TASK-610**, `frontend/src/pages/GroupMembersForm.tsx`, `renderPaymentLinks` (`:284-311`): a condição do bloco de selos (`:302`) passa de `dependents.length > 0` para `dependents.length > 0 && user.id !== currentUserId`. O texto `Você paga por: …` (`:297-301`) **não muda**. Nada mais no componente muda.
- **Teste:** arquivo novo `GroupMembersForm.badges.test.tsx` com três casos (linha própria: texto e nenhum selo; linha de outro: selos; as duas na mesma tela). Em `GroupMembersForm.responsible.test.tsx`, só o teste de `:146-159` muda (hoje espera os selos na linha da Ana, a logada): vira "a linha própria não tem selo e mostra `Você paga por`", mantendo as asserções das linhas de outros membros (`:157-158`). `GroupMembersForm.paysFor.test.tsx:161` segue verde sem edição.
- **Layout medido** em Participantes (cinco larguras), com a linha própria no conteúdo máximo (dois "Quem paga por você", dois "Você paga por", três botões): sem os selos a linha fica **menor**, então é conferência de que nada piorou.

## 2. Pagamentos: o arranjo novo da linha (Specify R2, D3, D5, D6, CA-3, CA-6)

- **TASK-611**, `frontend/src/components/PayableSettlementList.tsx`: propriedade opcional `rowLayout?: 'sentence' | 'route'`, padrão `'sentence'` (o arranjo de hoje, **sem mudar uma linha**, para o Painel e para os testes do componente). Em `'route'`, o topo do cartão (`:138-154`) vira:

  ```
  <Box display="flex" alignItems="center" gap={1.5}>
    <UserAvatar … pagador />
    <Box flexGrow={1} minWidth={0}>
      <Box display="flex" flexWrap="wrap" alignItems="center" columnGap={0.75}>
        <Box display="inline-flex" alignItems="center" columnGap={0.75} …>   // pagador + seta, não se separam
          <Typography variant="body2" fontWeight={700} data-testid="settlement-payer">{pagador}</Typography>
          <ArrowForwardIcon fontSize="small" aria-hidden … />
        </Box>
        <Typography variant="body2" fontWeight={700} data-testid="settlement-payee">{credor}</Typography>
      </Box>
      <Typography variant="body2" fontWeight={700} color="error.main" data-testid="settlement-amount">R$ {valor}</Typography>
    </Box>
    <UserAvatar … credor />
  </Box>
  ```

  A seta solta de `:151` **não existe** no `'route'`; a seta fica entre os nomes e **não** vai com `titleAccess`/texto escondido (RK1). O `Card` ganha `data-testid="settlement-card"` **só no `'route'`**. Nota, selos e botões (`:156` em diante) **não mudam**.
- **Helper de teste novo** `frontend/src/components/settlementLines.testHelper.ts` (sem `.test.` no nome, para o Vitest não o rodar como teste): `settlementLines(root?: HTMLElement): string[]` devolve, na ordem do DOM, `"Pagador → Credor R$ 0,00"` por `settlement-card`.
- **Testes novos** (arquivo novo `PayableSettlementList.route.test.tsx`): sem "deve pagar" e sem o "a"; os dois avatares; **uma** seta; valor num elemento **depois** da linha dos nomes; nenhum selo, nota ou botão muda; `'sentence'` (padrão) idêntico a hoje (inclusive "deve pagar"). Teste novo de caracterização do Painel (`GroupPositionCard.rowLayout.test.tsx`): continua mostrando "… deve pagar R$ … a …".

## 3. Pagamentos: ligar o arranjo e migrar os testes (Specify D2, D3, CA-3 a CA-6)

- **TASK-612**, `frontend/src/components/PaymentSettlementsPanel.tsx` (`:97`): passa `rowLayout="route"` ao `PayableSettlementList`, para as duas abas (D3-B). `Payments.tsx` não muda.
- **Migração dos 41 pontos de teste em 4 arquivos**, trocando a leitura por `settlementLines()` e a string esperada de `"Carla deve pagar R$ 60,00 a Ana"` para `"Carla → Ana R$ 60,00"`:
  - `PaymentSettlementsPanel.test.tsx` (13): o helper `shownSettlements` (`:70-74`) vira chamada ao helper compartilhado;
  - `PaymentSettlementsPanel.responsible.test.tsx` (7): `lines` (`:53`) e os `stringContaining('Ana deve pagar')` viram `stringContaining('Ana →')`;
  - `Payments.test.tsx` (15): `acertos` (`:1465`) e `shownSettlements` (`:1568`) e as listas esperadas (`:1467` a `:1862`);
  - `Payments.responsible.test.tsx` (6): `findByText(/deve pagar/)` (`:177`, `:189`) espera o cartão (`findAllByTestId('settlement-card')`) e `lines` (`:203-208`).
- **Teste de caracterização novo** (arquivo novo `PaymentSettlementsPanel.rowLayout.test.tsx`): as duas abas usam o arranjo novo; o comprovante aguardando aceite continua na aba "Valores pagos" (D2); o selo "Deve pagar a você" e os demais seguem (R4).
- **Layout medido** em Pagamentos (cinco larguras): nomes de 25 a 40 caracteres em 375 px; valor abaixo e alinhado ao início do nome do pagador (`getBoundingClientRect`: `amount.left === payer.left` quando o pagador não quebra); sem rolagem horizontal; os dois avatares nas pontas em todas as larguras; as abas "a pagar" e "pagos".

## 4. Documentação (Specify CA-9)

- **TASK-613:** `docs/sdd/01-specify.md` v1.5: §3.3, no bloco "Quem paga por quem", a frase "o selo `Paga por {dependente}`" passa a "**nas linhas dos outros membros** (na própria linha só o texto `Você paga por`)"; no bloco "Quem paga se declara e o credor decide", em "Telas", a linha de Pagamentos (o arranjo `pagador → credor` com o valor abaixo, nas duas abas) e o "Limite conhecido" do comprovante aguardando aceite passa a **decisão** ("fica em 'Valores pagos'"). A Constitution **não** é editada.

## 5. Testes e validação (Specify CA-7, CA-8)

- Cada task: **RED executado** antes do código (teste novo falhando pelo motivo certo), GREEN, `npx tsc --noEmit` e `vitest related` dos arquivos tocados; **mutation check** por regra, com os mutantes em um script gravado com a ferramenta de escrita (não heredoc) e restauração do arquivo entre um mutante e outro:
  - **R1 (TASK-610):** tirar a condição `user.id !== currentUserId`; invertê-la (selos só na linha própria); tirar o `Você paga por`.
  - **R2 (TASK-611 e 612):** voltar a palavra "deve pagar" no `'route'`; deixar a seta solta além da seta entre os nomes; colocar o valor antes dos nomes; padrão `'route'` em vez de `'sentence'`; `rowLayout="route"` esquecido no painel; trocar a ordem no helper; tirar o `data-testid` do valor.
- **Fechamento da feature:** suíte inteira do front (`--maxWorkers=1`, sozinha), `tsc` limpo, `pr-readiness-checker`, **validação local dele** (Participantes e Pagamentos) e só então push com refspec explícito e PR único contra `dev`.

## 6. Ordem de execução

"Participantes e componente primeiro, ligação e testes depois, documento por último", parando após cada task.

1. **TASK-610** (Participantes): independente das outras.
2. **TASK-611** (o arranjo `'route'`, o helper e os testes novos): sem ligar na tela ainda.
3. **TASK-612** (ligar no painel de abas, migrar os 41 pontos e medir): depende da 611.
4. **TASK-613** (documento): depois das duas telas prontas.
