/**
 * Fixtures de `GET /api/me/overview` para os testes do Painel. Tipadas com
 * `MyOverview`/`OverviewGroup` sem cast: se o contrato mudar, o `tsc` acusa
 * aqui antes de qualquer teste rodar.
 */
import type { MyOverview, OverviewGroup } from './overview';

/** Um grupo com setembro fechado: o usuário deve à Nádia e recebe da Norma. */
export function overviewGroupFixture(overrides: Partial<OverviewGroup> = {}): OverviewGroup {
  return {
    group: { id: 3, name: 'Residencial' },
    cycle: {
      start: '2026-09-01',
      end: '2026-09-30',
      closes_at: '2026-10-05',
      status: 'closed',
      settled: false,
      in_grace: false,
      cycles_ago: 1,
    },
    owe: { total: 439.23, confirmed: 0, open: 439.23 },
    receive: { total: 120, confirmed: 60, open: 60 },
    to_pay: [
      {
        to_user: { id: 9, name: 'Nádia', email: 'nadia@example.test', pix: 'nadia-pix', avatar_url: null },
        amount: 439.23,
        confirmed_at: null,
        confirmed_proof_url: null,
        can_confirm: true,
      },
    ],
    to_receive: [
      {
        from_user: { id: 4, name: 'Norma', avatar_url: null },
        amount: 120,
        confirmed_at: '2026-10-08T14:02:11-03:00',
        confirmed_proof_url: 'https://example.test/comprovantes/1.jpg',
      },
    ],
    my_items: [
      {
        expense_id: 51,
        description: 'Construção parede',
        date: '2026-09-10',
        value: 543,
        my_share: 135.75,
        paid: false,
        role: 'debtor',
        creditor: { id: 9, name: 'Nádia' },
        installment: { number: 2, total: 6 },
        can_pay: false,
        can_unpay: false,
      },
    ],
    pending_items_count: 1,
    has_open: true,
    ...overrides,
  };
}

export function myOverviewFixture(overrides: Partial<MyOverview> = {}): MyOverview {
  return {
    user: { id: 7, name: 'Isac' },
    groups: [overviewGroupFixture()],
    truncated: false,
    ...overrides,
  };
}
