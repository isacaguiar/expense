# Plan — Filtros de Credor, Devedores e Status nas Despesas

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261010

---

Escopo técnico: **só `frontend/`**. O `summary` já traz os campos necessários (`specify.md` §2.1), então não há mudança de API nem de backend. Convenções que valem aqui (`docs/sdd/05-context-frontend.md`): TypeScript estrito, sem `any` não justificado, e sem duplicar regra de negócio do backend (filtrar uma lista já calculada não é regra de negócio).

## 1. Helper puro de filtragem (specify §2.2, §2.3, §3.4, R2, R3, R6, R7)

Novo arquivo `frontend/src/utils/expenseFilters.ts`, no padrão de `utils/installments.ts` e `utils/phone.ts`: funções puras, sem React, importando só o tipo `SummaryExpense` de `hooks/useGroupCycle`. Hoje a filtragem está inline em `ExpenseManager.tsx:271-277`; ela sai de lá e ganha os dois critérios novos.

**Tipos exportados**

```ts
export type TypeFilter = 'all' | 'fixed' | 'variable';   // hoje inline em ExpenseManager.tsx:115
export type StatusFilter = 'all' | 'paid' | 'pending';
export type FilterOption = { key: string; name: string };
export type ExpenseFilters = {
  search: string;
  type: TypeFilter;
  status: StatusFilter;
  creditorKeys: string[];
  debtorKeys: string[];
};
```

**Funções exportadas**

| Função | Comportamento |
|---|---|
| `creditorOf(exp)` | `{ key: 'id:<userPayerId>', name: exp.payerName \|\| 'Sem nome' }` |
| `debtorsOf(exp)` | Com `participantDetails`: `{ key: 'id:<id>', name }` por item. Sem ele (snapshot de ciclo selado): `{ key: 'name:<nome>', name }` a partir de `participants`. Usa `exp.participantDetails ?? …`, o mesmo critério de `renderDetailPayers` (`ExpenseManager.tsx:322`). O credor **não** é excluído (R1). |
| `buildCreditorOptions(expenses)` / `buildDebtorOptions(expenses)` | Deduplica por `key` (a primeira ocorrência vence) e ordena por `name` com `localeCompare(…, 'pt-BR')`. |
| `filterExpenses(expenses, filters)` | Aplica os cinco critérios com **E** entre eles. |

**Regras dentro de `filterExpenses`**

- Busca: `description.toLowerCase().includes(search.toLowerCase())`, igual a hoje. Tipo: `fixed` → `Boolean(isFixed)`, `variable` → `!isFixed`, igual a hoje.
- Status: `paid` → `exp.paid`, `pending` → `!exp.paid`.
- Credor: lista vazia = sem restrição; senão, passa se `creditorOf(exp).key` está em `creditorKeys` (R2).
- Devedores: lista vazia = sem restrição; senão, passa se **algum** item de `debtorsOf(exp)` está em `debtorKeys` (R2).
- Usar `Set` para as chaves, evitando `includes` em laço aninhado.

**Por que prefixar a chave (`id:` / `name:`)**: o filtro de Devedores precisa funcionar nos dois formatos de payload (§2.3). Sem prefixo, um nome numérico colidiria com um `id`. É barato e elimina a classe de bug.

## 2. Estado e opções no `ExpenseManager` (specify §3.1, §3.2, §3.3, D1, D2)

Em `frontend/src/pages/ExpenseManager.tsx`, no bloco de estado de `:113-115`:

- Mantém `search` e `typeFilter` (agora tipado com `TypeFilter` do helper).
- Acrescenta `creditorKeys: string[]`, `debtorKeys: string[]` e `statusFilter: StatusFilter`, todos iniciando em `[]` / `'all'`.
- `creditorOptions` e `debtorOptions` em `useMemo([expenses])`, vindos de `buildCreditorOptions` e `buildDebtorOptions` (**D1: derivadas da competência carregada**, sem chamada nova).
- **Poda (D2)**: o valor que o `Autocomplete` recebe e o que vai para o `filterExpenses` são derivados na renderização, não guardados:

  ```ts
  const selectedCreditors = useMemo(() => creditorOptions.filter(o => creditorKeys.includes(o.key)), [creditorOptions, creditorKeys]);
  ```

  Assim, ao navegar para uma competência em que a pessoa não aparece, ela some do campo e do critério na hora, e o `Autocomplete` nunca recebe valor fora das opções (o que deixaria na tela o chip de uma pessoa que não existe na competência; o MUI 7.2.0 desta base não emite warning para isso, então o teste olha o chip e não o console). O `onChange` regrava o estado só com as chaves visíveis, então uma chave "órfã" sobrevive no máximo até o usuário mexer no controle.
- `filteredExpenses` passa a ser `filterExpenses(expenses, { search, type: typeFilter, status: statusFilter, creditorKeys: selectedCreditors.map(o => o.key), debtorKeys: selectedDebtors.map(o => o.key) })`.
- Nenhum `useEffect` novo, nenhuma chamada nova de rede (R5). Os hooks entram junto dos outros estados: o componente não tem retorno antecipado antes deles.

## 3. Barra de filtros (specify §3.1, §3.2, §3.3, §3.5, R4)

Na barra de `ExpenseManager.tsx:559-581` (`Box` com `flexWrap="wrap"`), a ordem fica: **Busca → Credor → Devedores → Tipo → Status**.

**Credor e Devedores**: `Autocomplete` do MUI com `multiple`, `size="small"`, `limitTags={1}`, `disableCloseOnSelect`, `options` do §2, `getOptionLabel={o => o.name}`, `isOptionEqualToValue={(a, b) => a.key === b.key}`, `value` = selecionados do §2, `onChange={(_, values) => set…Keys(values.map(v => v.key))}` e `renderInput` com `TextField` de `label="Credor"` / `label="Devedores"`. Largura `{ xs: '100%', sm: 240 }`: em tela estreita (modo `compact`, `< sm`) cada select ocupa a linha inteira, em vez de espremer chips ao lado da busca.

**Textos em português**: o projeto não configura locale do MUI (nenhum `ptBR` em `theme/`), então os textos padrão do `Autocomplete` saem em inglês. Um objeto constante, espalhado nos dois, define `noOptionsText="Nenhuma opção"`, `clearText="Limpar"`, `openText="Abrir"` e `closeText="Fechar"`. Não vale mudar o tema inteiro por isso: seria uma mudança ampla, fora do escopo.

**Status**: `ToggleButtonGroup` `exclusive size="small"` com `aria-label="Status"` e botões `Todas` / `Pagas` / `Pendentes` (valores `all` / `paid` / `pending`), com o mesmo `onChange={(_, v) => v && set…(v)}` do filtro de tipo, que impede desmarcar tudo. O grupo de tipo ganha `aria-label="Tipo"` (§3.5). Como os dois têm um botão "Todas", os `aria-label`s é que os distinguem para leitor de tela e para os testes.

A barra continua visível mesmo com a competência sem despesas (comportamento de hoje). Com zero despesas as opções ficam vazias e o `Autocomplete` mostra "Nenhuma opção". O estado vazio "Nenhuma despesa encontrada para esse filtro." (`:617`) já cobre combinação sem resultado, sem mudança. O modo `compact` (cartões) e o modo tabela consomem o mesmo `filteredExpenses`, então os dois ganham os filtros sem tocar na renderização.

## 4. Testes (specify §3.4, R2, R3, R5, R6, D2)

**`frontend/src/utils/expenseFilters.test.ts` (novo)**, funções puras:

- `debtorsOf` com `participantDetails` (chave `id:`), sem ele (chave `name:`), e incluindo o credor (R1).
- `buildCreditorOptions` / `buildDebtorOptions`: deduplica e ordena em pt-BR (ex.: "Álvaro" antes de "Bruno").
- `filterExpenses`: filtro vazio não restringe; **OU** dentro de Credor e dentro de Devedores; **E** entre filtros; Status pagas/pendentes; busca e tipo mantêm o comportamento anterior.

**`frontend/src/pages/ExpenseManager.test.tsx` (existente)**, novo `describe` ao lado de "listagem em cards" (`:111`), reaproveitando `mockGetResponses` e o padrão de `:173-223`. Fixture de três despesas:

| Despesa | Tipo | Credor | Participantes | Paga |
|---|---|---|---|---|
| Aluguel | fixa | Isac (500) | Isac, João, Maria | sim |
| Mercado | variável | João (501) | João, Maria | não |
| Internet | variável | Isac (500) | Isac, João | não |

Casos (selecionar com `getByRole('combobox', { name: 'Credor' })` e `getByRole('option', { name })`; grupos via `within(getByRole('group', { name: 'Status' }))`):

1. Credor = João → só Mercado. Credor = João + Isac → as três (OU).
2. Devedores = Maria → Aluguel e Mercado. Devedores = Isac → Aluguel e Internet, ou seja, o credor conta como devedor (R1).
3. Status Pagas → Aluguel; Pendentes → Mercado e Internet; "Todas" volta às três.
4. Combinação: Credor = Isac + Pendentes → Internet; somar a busca "net" mantém Internet; busca "alu" zera e mostra "Nenhuma despesa encontrada para esse filtro."
5. Nenhum dos filtros novos refaz GET em `/expenses/summary` (mesma asserção de `:187`).
6. **Ciclo selado (R6)**: payload só com `participants` (sem `participantDetails`) → as opções de Devedores saem por nome e o filtro funciona.
7. **Poda ao trocar de competência (D2)**: `mockImplementation` lendo `config.params.cycles_ago` para devolver um `summary` diferente por competência. Com uma pessoa selecionada, clicar em "Competência anterior" para uma competência em que ela não existe → o chip some do campo e a lista mostra tudo; e, para quem existe nas duas, o filtro continua valendo. Cobre Credor e Devedores, e a asserção olha os chips do campo.

Não-flakiness: usar `await screen.findByRole('option', …)` (o popper do `Autocomplete` monta de forma assíncrona) e asserções por descrição de despesa, não por nome de pessoa (o nome aparece ao mesmo tempo na coluna Credor, no chip e na opção).

## 5. Validação e registro (specify §3.4; `docs/sdd/04-implementation.md` §1 itens 3 e 5)

- Comandos do checklist pré-PR: `cd frontend && npx vitest run src/utils/expenseFilters.test.ts src/pages/ExpenseManager.test.tsx`, `npm test` (suíte completa) e `npx tsc --noEmit` (o `vite build` sozinho não type-checa). Cada resultado vai para `implementation.md` com o comando real.
- **Validação manual local** na stack isolada da máquina do usuário (a porta 3306 é de outro projeto), com a limpeza ao final. Roteiro: grupo com ao menos três membros e despesas com credores e participantes variados; cada filtro sozinho e combinados; trocar de competência com filtro ativo; largura de 375px (a barra quebra em linhas sem scroll horizontal); competência fechada/selada (fallback sem `participantDetails`); modo cartões e modo tabela.
- Gate humano: **push e PR só depois que o usuário validar localmente.** O merge do PR em `dev` é gate humano (`00-constitution.md` §5.2).

## 6. Ordem de execução

Há uma dependência técnica: a UI (§2, §3) usa o helper (§1), então o helper vem primeiro. Os testes de cada parte entram na task da parte (§1 → testes unitários; §2-§3 → testes de tela). A Constitution §2.2 só exige teste para regra de negócio de backend (PHPUnit); aqui o teste vem do padrão que o `frontend/` já segue (`ExpenseManager.test.tsx`, `Dashboard.test.tsx`) e do critério de aceite verificável do template de tasks. Não há paralelismo útil: o trabalho é um arquivo novo e uma edição no componente que o consome.

Fluxo de branch conforme `04-implementation.md` §1: a primeira task roda direto na branch da feature (`frontend/20261010-filtros-credor-devedores-status-despesas`); a seguinte, numa sub-branch `…-TASK-0xx` integrada na da feature com `git merge --no-ff`, sem PR. No fim, um único PR contra `dev`.
