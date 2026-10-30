# Limites de entrada que o rateio gerado pelo servidor ainda não impõe

ID: 079
Origem: docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/ (achados A5 e A6 da 2ª rodada da revisão de segurança da TASK-403, mais os informativos já conhecidos); `backend/app/Support/InstallmentSchedule.php` e `backend/app/Http/Controllers/ExpenseController.php`
Criado em: 2026-10-09
Prioridade: BAIXA
Status: Aberto

## Descrição
A feature `rateio-parcelas-no-backend` fechou o ano 9999 (`InstallmentSchedule::fits`), o teto de 120 parcelas e a transação do `update()`. A revisão de segurança deixou, como informativos, entradas extremas que ainda passam. Todas exigem um payload montado à mão, que a UI nunca envia, e só o criador ou o pagador da própria despesa as dispara: não há dado de terceiro exposto nem escalada de acesso.

- **Data abaixo do limite (A5).** `fits()` só confere `year <= 9999`. A regra `date` do Laravel aceita modificadores relativos (`2026-10-09 -3000 years`, estouro de inteiro com `+300000000000 years`), e `build()` gera datas de ano negativo. O que o MySQL faz com isso não foi verificado: pode ser erro 1292 em modo strict (500 com rollback limpo, graças à TASK-405) ou conversão silenciosa, como no A1. Verificar no banco descartável antes de decidir.
- **Troca para À Vista sem a guarda (A5).** No `update()`, a troca para À Vista monta a quota única à mão, com a string crua de `date_payment`, sem passar por `generateQuotas()`. É o único gerador sem `fits()`; ficou assim de propósito no plan §3 (código que já funcionava).
- **`total_value` sem teto (A6).** `build()` faz `(int) round(round((float) $total, 2) * 100)`. Acima de cerca de 9,2e16 o valor volta com módulo (centavos negativos); entre 9e13 e 9,2e16 perde centavos por precisão de `float`. A Parcelada barra pela conferência de soma (422 com mensagem enganosa); À Vista e Fixa não têm essa conferência, então o `store()` sem `quotas` grava `value_quota` errado ou negativo.
- **Tetos de entrada que o servidor não impõe.** `installments` em despesa não parcelada (gravável pelo `update()` com `quotas` presente) e o tamanho do array `quotas` enviado pelo cliente. A decisão D2/R7 do specify manteve o teto só para o que o servidor gera; este item é a chance de rever.

O mesmo vazamento de datas e valores já existia antes da feature para `date_payment` e `quotas.*` enviados pelo cliente, porque a regra `date` não tem limites.

## Regras adotadas
Não dependem de decisão.

| # | Regra |
|---|---|
| R1 | Contrato aditivo (Constitution §4.1): só passam a dar 422 entradas que a UI nunca envia. |
| R2 | Cada correção começa por um teste vermelho com o payload extremo reproduzido no banco descartável. |
| R3 | O 422 sai antes de qualquer escrita, como o da guarda do ano 9999. |

## Decisões em aberto
A fechar no specify.

| # | Decisão | Opções | Recomendação | Por quê |
|---|---|---|---|---|
| D1 | Limite inferior de data | A) `MIN_YEAR` (1000, o limite documentado do MySQL) em `fits()`, e a troca para À Vista passando por `generateQuotas()`<br>B) `date_format:Y-m-d` em `date_payment`, na raiz | **A** | B pode quebrar clientes que reenviam `2026-08-01T00:00:00.000000Z`, formato que a API devolve. |
| D2 | Teto de `total_value` | A) regra de campo (`max:`) em `store` e `update`<br>B) guarda em `build()` que lança `InvalidArgumentException` | **A** | A dá 422 claro; B viraria 500 genérico. O valor do teto é decisão de produto. |
| D3 | Tetos de `quotas` e de `installments` não parcelado | A) impor um teto maior que 120 (ex.: 600) ao array `quotas`, e `installments` = 1 fora da Parcelada<br>B) manter como está | **A** | Fecha a amplificação por payload pequeno com `quotas` grande; o web nunca passa de 120. O número exato é decisão de produto. |

## Por que importa
São bordas que hoje só um payload artesanal alcança, mas todas gravam dado financeiro errado em silêncio ou dependem de o MySQL falhar por sorte. Fechar agora evita descobrir uma delas pelo cliente Flutter (074), que nasce falando direto com esse contrato.

Tipo sugerido: backend
