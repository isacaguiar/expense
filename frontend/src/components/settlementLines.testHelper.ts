import { within } from '@testing-library/react';

/**
 * Helper de teste da feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-611). Nome sem
 * `.test.` de propósito: o Vitest não o roda como teste.
 *
 * Lê, na ordem do DOM, os acertos desenhados no arranjo `rowLayout="route"` de `PayableSettlementList`
 * (os cartões com `data-testid="settlement-card"`) e devolve uma string por cartão, "Pagador → Credor R$ N",
 * por exemplo `"Carla → Ana R$ 60,00"`. Existe para os testes de Pagamentos verificarem a **ordem** das
 * linhas sem depender da frase "deve pagar", que saiu desse arranjo.
 */
export function settlementLines(root: HTMLElement = document.body): string[] {
  return within(root)
    .queryAllByTestId('settlement-card')
    .map(card => {
      const text = (testId: string): string =>
        (within(card).getByTestId(testId).textContent ?? '').replace(/\s+/g, ' ').trim();

      return `${text('settlement-payer')} → ${text('settlement-payee')} ${text('settlement-amount')}`;
    });
}
