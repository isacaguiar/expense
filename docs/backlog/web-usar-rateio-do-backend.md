# Web passa a usar o contrato novo e remove o rateio de parcelas do cliente

ID: 071
Origem: item 070 (`rateio-parcelas-no-backend.md`); `frontend/src/utils/installments.ts`
Criado em: 2026-10-06
Prioridade: ALTA
Status: Aberto

## Descrição
Depois que o item 070 estiver em produção, `ExpenseForm.tsx` e `ExpenseView.tsx` deixam de montar e enviar `quotas`: passam a mandar só `expense_type`, `installments`, `total_value` e `date_payment`, e o backend gera as parcelas. Remover `frontend/src/utils/installments.ts` (`buildInstallmentQuotas`, `addMonthsClamped`) e o `perPersonValue()` de `ExpenseView.tsx:116`, passando a exibir o `value_per_person` que o `show()` devolve por parcela. Ajustar `ExpenseForm.test.tsx` e `ExpenseView.test.tsx`, que hoje cobrem o rateio por teste de componente; a cobertura da regra passa a ser a do PHPUnit do item 070.

Ordem: depende do 070 **em produção** (merge e deploy do backend primeiro, gates humanos). É a segunda etapa da trilha do app na Google Play (**070 → 071 → 074**). Abas antigas do navegador, com o bundle anterior em cache, continuam mandando `quotas` e seguem funcionando, porque o backend mantém o comportamento atual quando elas vêm.

## Por que importa
Simplifica o web e tira a regra de parcelas de dois lugares. E, principalmente, prova o contrato novo em produção com um cliente já maduro antes de o app Flutter (item 074) depender dele: se algo diverge, o problema aparece no web, que se corrige com um deploy, e não numa versão do app já distribuída pela loja.

Tipo sugerido: frontend
