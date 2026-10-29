# `grossDebts` ignora quota paga e diverge de `settlements`

ID: 111
Origem: docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/specify.md §2.13 e §3.4 (D13); `backend/app/Http/Controllers/ExpenseController.php:983-1023` (`grossDebts`, `:987`), `backend/app/Services/CycleSummaryService.php:238-314` (`compute`)
Criado em: 2026-10-27
Prioridade: MEDIA
Status: Aberto

## Descrição
`grossDebts` (a árvore credor→devedores do Dashboard) **pula** a quota com `paid=true` (`if ($entry['paid']) continue;`, `:987`). `compute()` (`balances` e `settlements`) **ignora** `paid` e só tira a dívida da conta quando a quota é `born_paid` (`:246-248`), de propósito: o devedor segue devendo ao credor até confirmar o acerto. Resultado: marcada a quota como paga, o Dashboard deixa de listar a dívida bruta enquanto "Valores a pagar" (Pagamentos) ainda a cobra.

A divergência existe hoje com o ✓ por parcela. Com a feature `20261027-pagamento-no-cadastro-despesa`, a Parcelada nasce inteira paga, então o Dashboard passa a mostrar "sem devedores" para toda compra no cartão. A decisão D13 dessa feature foi **manter** `grossDebts` como está e fixar o comportamento por teste (NR-07), para não mudar um cálculo que funciona.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Qualquer correção começa por um teste vermelho que reproduz a divergência com os mesmos dados nos dois lados. |
| R2 | Contrato aditivo (Constitution §4 item 1): se mudar o que `grossDebts` devolve, é por campo novo ou documentado. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Qual é a fonte da verdade da "dívida" | A) `grossDebts` passa a ignorar `paid`, como `settlements`<br>B) manter, e rotular a árvore como "ainda não pago pelo credor"<br>C) tirar a quota paga também de `settlements` | **A** | `settlements` é o que fecha o ciclo e reconcilia com o Painel; C mudaria o acerto, que está correto. |

## Por que importa
Duas telas do mesmo grupo dando valores diferentes para a mesma dívida confundem quem paga. A frequência sobe quando a Parcelada passa a nascer paga.

Tipo sugerido: backend
