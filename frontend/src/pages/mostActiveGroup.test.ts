import { describe, expect, it } from 'vitest';
import { mostActiveGroup } from './mostActiveGroup';

describe('mostActiveGroup', () => {
  it('returns null for an empty list', () => {
    expect(mostActiveGroup([])).toBeNull();
  });

  it('returns the only group when there is just one', () => {
    const groups = [{ id: 1, create_date: '2026-01-01', expenses_max_date_payment: null }];
    expect(mostActiveGroup(groups)?.id).toBe(1);
  });

  it('picks the group with the most recent expense', () => {
    const groups = [
      { id: 1, create_date: '2026-01-01', expenses_max_date_payment: '2026-02-10' },
      { id: 2, create_date: '2026-01-01', expenses_max_date_payment: '2026-05-01' },
      { id: 3, create_date: '2026-01-01', expenses_max_date_payment: '2026-03-15' },
    ];
    expect(mostActiveGroup(groups)?.id).toBe(2);
  });

  it('prefers a group with any expense over a group with none, regardless of create_date', () => {
    const groups = [
      { id: 1, create_date: '2026-06-01', expenses_max_date_payment: null },
      { id: 2, create_date: '2026-01-01', expenses_max_date_payment: '2026-01-15' },
    ];
    expect(mostActiveGroup(groups)?.id).toBe(2);
  });

  it('falls back to the most recently created group when none has an expense', () => {
    const groups = [
      { id: 1, create_date: '2026-01-01', expenses_max_date_payment: null },
      { id: 2, create_date: '2026-03-01', expenses_max_date_payment: null },
    ];
    expect(mostActiveGroup(groups)?.id).toBe(2);
  });
});
