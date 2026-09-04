# Bugfix — Cadastro de despesa mostra erro em `window.alert()` nativo, sem o motivo

Versão: 1.0 · Criado em: 20260903 · Branch: `fix/20260903-expense-form-feedback-erro`

> Fluxo BFF — ver `docs/bugfix/README.md`. Se qualquer caixa da Triagem for marcada, este trabalho **não** é BFF: crie `docs/feature/<AAAAMMDD>-<slug>/` com `/nova-feature` e deixe aqui só um ponteiro.

## Triagem

Marque todas que se aplicam. **Qualquer marca = vai para o fluxo SDD completo, não BFF.**
Critério completo de cada caixa: `docs/bugfix/README.md`, "Quando usar o BFF".

- [ ] **Auth / autorização / dado sensível** — a mudança é só em `frontend/src/pages/ExpenseForm.tsx` (+ teste). Não toca rota, controller, middleware nem regra de autorização; só troca como um erro já recebido é exibido.
- [ ] **Migration ou contrato de API** — nenhuma; puro frontend. A resposta de erro do backend não muda — o cliente passa a ler o campo `error` que a API já devolve.
- [ ] **Causa raiz obscura / correção ampla** — causa raiz óbvia (`alert()` no `handleSave`), correção em 1 arquivo + 1 arquivo de teste, 1 módulo.
- [ ] **Decisão de produto/arquitetura** — nenhuma; adota o padrão que o form irmão `ExpenseView.tsx` já usa (`saveError` + `<Alert>`).

Nenhuma marcada → segue no BFF.

## 1. Problema

- **Sintoma:** ao cadastrar uma despesa em `Cadastrar nova despesa` e o salvamento
  falhar (validação do cliente ou erro do backend), aparece um **popup nativo do
  browser** ("expense.novemax.com.br diz: Falha ao salvar despesa." + botão OK),
  fora do design do app. A mensagem do `catch` é sempre a mesma frase genérica —
  o usuário não sabe **por que** falhou (competência fechada, soma das parcelas
  divergente, pagador não é membro do grupo…) nem **o que fazer**.
- **Reprodução:**
  1. Abrir `/groups/<id>/expenses/new` (tela "Cadastrar nova despesa").
  2. Preencher uma despesa que o backend recusa — ex.: tipo **Parcelada**, "Mês de
     início das parcelas" num mês/competência já fechado.
  3. Clicar em **Salvar**.
  4. Surge o `window.alert()` "Falha ao salvar despesa." — sem o motivo real
     (backend respondeu 422 com `{"error":"Não é possível alterar dados de uma
     competência já fechada."}`, mas o texto não é exibido).
- **Esperado vs. atual:**
  - *Esperado:* a falha aparece **dentro do formulário**, no padrão visual do app
    (mesmo `<Alert severity="error">` que o form de edição já usa), com o **motivo
    devolvido pela API**.
  - *Atual:* `window.alert()` nativo com frase fixa "Falha ao salvar despesa.";
    o mesmo vale para as 3 validações do cliente.
- **Causa raiz:** `frontend/src/pages/ExpenseForm.tsx`, função `handleSave` — usa
  `alert()` em 4 pontos e descarta o corpo da resposta de erro:
  - [ExpenseForm.tsx:81](../../frontend/src/pages/ExpenseForm.tsx#L81) —
    `alert('Preencha descrição, valor e pagador corretamente.')`
  - [ExpenseForm.tsx:86](../../frontend/src/pages/ExpenseForm.tsx#L86) —
    `alert('Selecione ao menos um participante da divisão.')`
  - [ExpenseForm.tsx:95](../../frontend/src/pages/ExpenseForm.tsx#L95) —
    `alert('Informe uma quantidade de parcelas válida ...')`
  - [ExpenseForm.tsx:128-131](../../frontend/src/pages/ExpenseForm.tsx#L128) —
    `.catch(err => { console.error(...); alert('Falha ao salvar despesa.'); })`
    — não lê `err.response?.data?.error`.
  - O form irmão `frontend/src/pages/ExpenseView.tsx` (editar despesa) **já faz o
    certo**: estado `saveError`
    ([ExpenseView.tsx:101](../../frontend/src/pages/ExpenseView.tsx#L101)),
    `<Alert severity="error" sx={{ mb: 2 }}>{saveError}</Alert>`
    ([ExpenseView.tsx:273](../../frontend/src/pages/ExpenseView.tsx#L273)) e
    `setSaveError(err.response?.data?.error ?? 'Falha ao salvar despesa.')`
    ([ExpenseView.tsx:230](../../frontend/src/pages/ExpenseView.tsx#L230)).
    `ExpenseForm.tsx` (criar) simplesmente não foi atualizado no mesmo padrão.

## 2. Correção

- **O que muda e por quê:** portar para `ExpenseForm.tsx` o padrão de erro que
  `ExpenseView.tsx` já usa — estado `saveError: string | null` + `saving: boolean`,
  `<Alert severity="error" sx={{ mb: 2 }}>{saveError}</Alert>` logo abaixo do título,
  as 4 chamadas `alert(...)` viram `setSaveError(...)`, e o `catch` passa a exibir
  `err.response?.data?.error ?? 'Falha ao salvar despesa.'`. `saveError` é limpo no
  início de `handleSave`; `Salvar`/`Cancelar` ficam `disabled` durante o request.
- **Arquivos tocados:**
  - `frontend/src/pages/ExpenseForm.tsx`
  - `frontend/src/pages/ExpenseForm.test.tsx`
- **Teste de regressão:** `ExpenseForm.test.tsx` — (a) mock de erro 422
  `{ error: '...' }` do backend → o texto do backend aparece num `<Alert>` e
  `window.alert` **não** é chamado (spy); (b) validação do cliente (sem descrição)
  → mesmo `<Alert>`, sem `window.alert`.
- **Riscos / efeitos colaterais:** nenhum no backend (não é tocado). No front, o
  fluxo de sucesso (`navigate` após salvar) é idêntico; muda só a apresentação do
  erro. Padrão já validado em produção pelo `ExpenseView.tsx`.

## 3. Implementação (log)

Uma linha por verificação. Comando real + resultado obtido — não "testado" em prosa.

| Data | Comando | Resultado |
|---|---|---|
| 2026-09-03 | `cd frontend && npx tsc --noEmit` | sem erros |
| 2026-09-03 | `cd frontend && npx vitest run src/pages/ExpenseForm.test.tsx` | 9 passed (1 arquivo) — inclui os 3 casos novos (erro do backend, validação client, fallback genérico), todos com `window.alert` **não** chamado |
| 2026-09-03 | `cd frontend && npx vitest run` (suíte completa) | 36 arquivos, 232 passed — sem regressão |
| 2026-09-03 | Browser (`/app/groups/1/expenses/new` no dev server em :3000) | rota protegida por auth → redireciona pro Login sem token; caminho vivo não exercitado (exige backend + credenciais). Comportamento coberto pelos testes de componente, que renderizam o `<Alert>` MUI real. |

## Resolução

Concluído em: 2026-09-03
Branch: fix/20260903-expense-form-feedback-erro
PR: https://github.com/isacaguiar/expense/pull/143
