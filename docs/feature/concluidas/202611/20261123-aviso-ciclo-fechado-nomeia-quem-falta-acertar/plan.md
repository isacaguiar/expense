# Plan — Aviso de ciclo fechado nomeia quem falta acertar

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261123

---

## 1. Nova mensagem pós-fechamento (specify §2.2, D1-B, D2-B, D5-A, D6-A)

Só `frontend/src/components/CycleClosingAlert.tsx`; nenhum arquivo de backend, rota ou hook muda.

- **Troca de `pendingDebtorNames` por `pendingSettlementLines`**: devolve `{ key, debtor, creditor }[]` com um item por `settlement` sem `confirmedAt`, na ordem de `summary.settlements`. Os nomes vêm de `balances` por `from_user_id` e `to_user_id`, com "Desconhecido" de reserva (mesmo `Map` que já existe). Sem deduplicar: quem deve a dois credores gera duas linhas (specify §2.2). A `key` é `${from_user_id}-${to_user_id}`, único por par.
- **Por que não agrupar por devedor** ("Ana ainda não pagou o acerto a João e a Carla"): complica o texto e a concordância sem ganho; uma linha por acerto é o que ele aprovou (D1-B).
- **Render**: continua um único `<Alert severity="warning" sx={{ mb: 2 }}>`. Dentro dele um título "O ciclo fechou e ainda falta acertar:" e uma lista (`<Box component="ul">` sem marcador, ou `<ul>` com `margin: 0`) com um `<li>` por linha: `{debtor} ainda não pagou o acerto a {creditor}.`. Sem valor (D5-A) e sem corte (D6-A).
- **Condição de aparecer** inalterada: `cycleClosed && !cycle.settled && linhas.length > 0`. O ramo `info` do pré-fechamento não muda (D4-A).
- **Por que lista e não uma frase corrida**: com N acertos, N linhas curtas leem melhor do que "Ana deve a João, Bruno deve a Carla..." e cada linha é um nó de texto testável.
- **Comentário JSDoc** do componente: trocar "ainda há acerto sem confirmação" para descrever as linhas "devedor → credor" e citar esta feature.

## 2. Testes que a mudança quebra (specify §2.2)

A troca do texto quebra asserções de "falta acertar: Ana, Bruno." em dois arquivos; entram na mesma task do código para não deixar a suíte vermelha entre tasks (lição da feature 160).

- `frontend/src/components/CycleClosingAlert.test.tsx`: os cenários das linhas 66-200 (`lists the debtors...`, `drops a debtor...`, circular, credor líquido, devedor com vários credores, "Desconhecido", ciclo `closed_manually`) passam a afirmar as linhas "X ainda não pagou o acerto a Y." Casos novos: devedor com dois credores gera duas linhas; credor ausente em `balances` vira "Desconhecido"; linhas na ordem de `settlements`; acerto confirmado não gera linha. Os de "renders nothing" (102, 203, 219, 228) ficam como estão.
- `frontend/src/pages/ExpenseManager.test.tsx:2551-2579`: troca as regexes `/falta acertar: Ana, Bruno\./` etc. pelas linhas novas; o `queryByText(/falta acertar/)` da linha 2579 segue válido (o título mantém "falta acertar").
- Mutantes: (a) usar `from_user_id` também no credor, (b) voltar a deduplicar por devedor, (c) trocar `!confirmedAt` por `confirmedAt` — cada um derruba ao menos um teste.

## 3. Documentação (specify §2.3)

- `docs/sdd/05-context-frontend.md`: acrescentar, junto às regras de apresentação de ciclo, que o aviso de ciclo fechado nomeia "devedor → credor" por acerto pendente (versão sobe).
- `docs/sdd/01-specify.md` §3.3: um bullet curto com a regra e versão subida. Sem mudança de Constitution.

## 4. Conferência na stack de validação

Com MySQL 3307, backend 8001 e Vite 3001 (receita de memória), um grupo com ciclo fechado e acertos pendentes, incluindo um devedor com dois credores: conferir o aviso na Home e em Despesas, e que ele some quando o último acerto é confirmado. Resultado, com os comandos reais, em `implementation.md`.

## 5. Ordem de execução

Há dependência: 1 e 2 andam juntas (o código e os testes que ele quebra); 3 e 4 vêm depois. Um PR único contra `dev` no fim.
