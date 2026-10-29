import { describe, expect, it } from 'vitest';
import {
  buildExpenseBody,
  EXPENSE_SAVE_TIMEOUT_MS,
  PROOF_MAX_BYTES,
  proofFileProblem,
  type ExpensePayload,
} from './expenseRequest';

const payload = (overrides: Partial<ExpensePayload> = {}): ExpensePayload => ({
  date_payment: '2026-08-10',
  description: 'Geladeira',
  expense_type: 'IN_INSTALLMENTS',
  installments: 2,
  total_value: 300,
  group_id: 7,
  user_creator_id: 1,
  user_payer_id: 1,
  payers: [1, 2],
  quotas: [
    { number: 1, date_expected: '2026-08-10', paid: false, value_quota: 150 },
    { number: 2, date_expected: '2026-09-10', paid: false, value_quota: 150 },
  ],
  client_request_id: '11111111-2222-4333-8444-555555555555',
  ...overrides,
});

const photo = () => new File(['conteudo'], 'cartao.jpg', { type: 'image/jpeg' });

describe('buildExpenseBody', () => {
  it('keeps the JSON body as it was, plus the request key, when there is no photo', () => {
    const body = buildExpenseBody(payload(), null);

    expect(body).not.toBeInstanceOf(FormData);
    expect(body).toEqual(payload());
    expect((body as ExpensePayload).client_request_id).toBe('11111111-2222-4333-8444-555555555555');
  });

  it('keeps descricao_pagamento in the JSON body when only the note is filled', () => {
    const body = buildExpenseBody(payload({ descricao_pagamento: 'Cartão final 1234' }), null);

    expect((body as ExpensePayload).descricao_pagamento).toBe('Cartão final 1234');
  });

  it('builds multipart with the photo, the scalar fields, payers[] and quotas[i][field]', () => {
    const body = buildExpenseBody(payload({ descricao_pagamento: 'Cartão' }), photo()) as FormData;

    expect(body).toBeInstanceOf(FormData);
    expect((body.get('comprovante') as File).name).toBe('cartao.jpg');
    expect(body.get('date_payment')).toBe('2026-08-10');
    expect(body.get('description')).toBe('Geladeira');
    expect(body.get('expense_type')).toBe('IN_INSTALLMENTS');
    expect(body.get('installments')).toBe('2');
    expect(body.get('total_value')).toBe('300');
    expect(body.get('group_id')).toBe('7');
    expect(body.get('user_creator_id')).toBe('1');
    expect(body.get('user_payer_id')).toBe('1');
    expect(body.get('descricao_pagamento')).toBe('Cartão');
    expect(body.get('client_request_id')).toBe('11111111-2222-4333-8444-555555555555');

    expect(body.getAll('payers[]')).toEqual(['1', '2']);

    expect(body.get('quotas[0][number]')).toBe('1');
    expect(body.get('quotas[0][date_expected]')).toBe('2026-08-10');
    expect(body.get('quotas[0][value_quota]')).toBe('150');
    expect(body.get('quotas[1][number]')).toBe('2');
    expect(body.get('quotas[1][date_expected]')).toBe('2026-09-10');
  });

  it('sends a single payer as payers[] as well', () => {
    const body = buildExpenseBody(payload({ payers: [5] }), photo()) as FormData;

    expect(body.getAll('payers[]')).toEqual(['5']);
  });

  it('writes the quota flag as 1 or 0, never as the string "true"', () => {
    const body = buildExpenseBody(
      payload({ quotas: [{ number: 1, date_expected: '2026-08-10', paid: true, value_quota: 300 }] }),
      photo()
    ) as FormData;

    expect(body.get('quotas[0][paid]')).toBe('1');
  });

  it('leaves out an absent note and a missing creator id', () => {
    const body = buildExpenseBody(payload({ user_creator_id: null }), photo()) as FormData;

    expect(body.has('descricao_pagamento')).toBe(false);
    expect(body.has('user_creator_id')).toBe(false);
  });

  it('always carries the request key, in JSON and in multipart', () => {
    const json = buildExpenseBody(payload(), null) as ExpensePayload;
    const form = buildExpenseBody(payload(), photo()) as FormData;

    expect(json.client_request_id).toBeTruthy();
    expect(form.get('client_request_id')).toBe(json.client_request_id);
  });
});

describe('proofFileProblem', () => {
  const file = (type: string, size: number) => {
    const chosen = new File(['x'], 'comprovante', { type });
    Object.defineProperty(chosen, 'size', { value: size });
    return chosen;
  };

  it('accepts an image of any size up to 5 MB, the limit the server enforces (max:5120 KB)', () => {
    expect(PROOF_MAX_BYTES).toBe(5 * 1024 * 1024);
    expect(proofFileProblem(file('image/jpeg', 1))).toBeNull();
    expect(proofFileProblem(file('image/png', PROOF_MAX_BYTES))).toBeNull();
  });

  it('warns about a photo over 5 MB', () => {
    expect(proofFileProblem(file('image/jpeg', PROOF_MAX_BYTES + 1))).toBe(
      'A foto passa de 5 MB. Escolha uma menor ou reduza a qualidade.'
    );
  });

  it('warns about a file that is not an image', () => {
    expect(proofFileProblem(file('application/pdf', 10))).toBe(
      'Escolha uma imagem (JPG, PNG ou semelhante) como comprovante.'
    );
    expect(proofFileProblem(file('', 10))).toBe('Escolha uma imagem (JPG, PNG ou semelhante) como comprovante.');
  });

  it('reports the type problem first when a file is both too big and not an image', () => {
    expect(proofFileProblem(file('video/mp4', PROOF_MAX_BYTES * 3))).toBe(
      'Escolha uma imagem (JPG, PNG ou semelhante) como comprovante.'
    );
  });
});

describe('EXPENSE_SAVE_TIMEOUT_MS', () => {
  it('gives a 5 MB photo on a mobile network room to arrive', () => {
    expect(EXPENSE_SAVE_TIMEOUT_MS).toBe(60000);
  });
});
