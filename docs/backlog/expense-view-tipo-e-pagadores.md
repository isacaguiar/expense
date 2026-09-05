# Página da despesa (`ExpenseView`) rotula Parcelada como "Variável" e não lista pagadores

ID: 039
Origem: docs/feature/20260904-detalhe-despesa-tipo-parcela-valores/specify.md §4
Criado em: 2026-09-05
Prioridade: MEDIA
Status: Aberto

## Descrição

A página `/groups/:id/expenses/:expenseId` (`frontend/src/pages/ExpenseView.tsx`) tem os mesmos dois problemas que a feature `20260904-detalhe-despesa-tipo-parcela-valores` corrigiu no modal "Detalhes da despesa", mas fora do escopo dela (o usuário escolheu tratar só o modal):

1. `typeLabel` mapeia `IN_CASH` **e** `IN_INSTALLMENTS` para `'Variável'` — uma despesa parcelada não se distingue de uma à vista no chip.
2. O modo de visualização não lista os pagadores: `payers` só aparece no modo de edição, como checkboxes. Não há valor por pessoa.

Diferente do modal, esta tela **não tem noção de competência** (a rota não recebe `cycles_ago`), então "qual parcela está sendo paga neste mês" não é derivável direto — seria preciso escolher a quota por `date_expected` do mês corrente, ou passar o ciclo pela navegação vinda de `ExpenseManager`. O `GET /api/expenses/{id}` já devolve `expense_type`, `installments`, `payers[]` e `quotas[]` com `number`/`value_quota`, então o dado existe; falta a decisão de qual quota exibir.

## Por que importa

Duas telas do mesmo produto passam a discordar sobre a mesma despesa: o modal diz "Parcelada 3/6" e a página de detalhe diz "Variável". Além de confundir, isso corrói a confiança no número mostrado — que é justamente o que a feature do modal quis consertar.

Tipo sugerido: frontend
