import { describe, expect, it } from 'vitest';

// Guarda do fuso da suíte (docs/feature/concluidas/202609/20260912-expense-view-tipo-e-pagadores/plan.md §5).
//
// O CI roda em ubuntu-latest, ou seja em UTC — e a classe de bug do item de
// backlog 013 (`new Date('YYYY-MM-DD')` é meia-noite UTC e cai no dia anterior
// em fuso negativo) é *invisível* em UTC: lá a implementação errada acerta por
// acidente. Sem fixar o fuso, um teste de regressão de data passa em CI mesmo
// com o código errado.
//
// `TZ` é fixado em `vite.config.js` (`test.env`). O assert decisivo é o da zona
// resolvida, e não o offset nem `getDate()`: a máquina de desenvolvimento deste
// projeto fica em America/Bahia (mesmo offset -03, sem horário de verão), então
// qualquer asserção baseada só no offset passaria mesmo se a config não tivesse
// efeito nenhum. Comparar a zona resolvida distingue os três casos — fuso da
// máquina, UTC do CI e o fuso fixado.
describe('fuso da suíte de testes', () => {
  it('roda sob America/Sao_Paulo, não sob o fuso da máquina nem UTC', () => {
    expect(Intl.DateTimeFormat().resolvedOptions().timeZone).toBe('America/Sao_Paulo');
  });

  it('interpreta meia-noite UTC como o dia anterior, que é a condição do bug 013', () => {
    // Em UTC este assert daria 1 — é ele que dá sentido aos testes de data das
    // TASK-278/279 quando rodam no CI.
    expect(new Date('2026-08-01T00:00:00.000000Z').getDate()).toBe(31);
  });
});
