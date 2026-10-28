import type { ExpenseType } from '../types/expense';
import type { InstallmentQuota } from './installments';

/**
 * Tempo máximo do `POST /api/expenses`. Uma foto de até 5 MB numa rede móvel precisa de
 * folga, e estourar o prazo é seguro porque o reenvio é idempotente (chave estável do
 * formulário). Sem prazo, a tela ficava em "Salvando…" até o usuário recarregar e
 * tentar de novo (docs/feature/20261027-pagamento-no-cadastro-despesa, R23).
 */
export const EXPENSE_SAVE_TIMEOUT_MS = 60_000;

/** Corpo de `POST /api/expenses`: o de sempre mais os campos opcionais do pagamento no cadastro. */
export type ExpensePayload = {
  date_payment: string;
  description: string;
  expense_type: ExpenseType;
  installments: number;
  total_value: number;
  group_id: number;
  user_creator_id: number | null;
  user_payer_id: number;
  payers: number[];
  quotas: InstallmentQuota[];
  /** Descrição do pagamento: campo distinto da descrição da despesa. Só vai se não estiver vazia. */
  descricao_pagamento?: string;
  /** Chave de idempotência do formulário; sempre enviada. */
  client_request_id: string;
};

/**
 * Monta o corpo do cadastro. Sem foto, é o JSON de sempre. Com foto, é `multipart`: o
 * servidor lê `payers[]` e `quotas[i][campo]` como campos de formulário, e o arquivo vai em
 * `comprovante`. A chave de idempotência acompanha nos dois formatos.
 */
export function buildExpenseBody(payload: ExpensePayload, proof: File | null): ExpensePayload | FormData {
  if (proof === null) {
    return payload;
  }

  const form = new FormData();

  form.append('date_payment', payload.date_payment);
  form.append('description', payload.description);
  form.append('expense_type', payload.expense_type);
  form.append('installments', String(payload.installments));
  form.append('total_value', String(payload.total_value));
  form.append('group_id', String(payload.group_id));
  if (payload.user_creator_id !== null) form.append('user_creator_id', String(payload.user_creator_id));
  form.append('user_payer_id', String(payload.user_payer_id));

  payload.payers.forEach(id => form.append('payers[]', String(id)));

  payload.quotas.forEach((quota, index) => {
    form.append(`quotas[${index}][number]`, String(quota.number));
    form.append(`quotas[${index}][date_expected]`, quota.date_expected);
    // O `boolean` do Laravel não aceita a string "true"; '1' e '0' são aceitos.
    form.append(`quotas[${index}][paid]`, quota.paid ? '1' : '0');
    form.append(`quotas[${index}][value_quota]`, String(quota.value_quota));
  });

  if (payload.descricao_pagamento !== undefined) {
    form.append('descricao_pagamento', payload.descricao_pagamento);
  }

  form.append('client_request_id', payload.client_request_id);
  form.append('comprovante', proof);

  return form;
}
