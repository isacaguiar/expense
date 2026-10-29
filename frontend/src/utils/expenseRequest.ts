import type { ExpenseType } from '../types/expense';
import type { InstallmentQuota } from './installments';

/**
 * Tempo máximo do `POST /api/expenses`. Uma foto de até 5 MB numa rede móvel precisa de
 * folga, e estourar o prazo é seguro porque o reenvio é idempotente (chave estável do
 * formulário). Sem prazo, a tela ficava em "Salvando…" até o usuário recarregar e
 * tentar de novo (docs/feature/20261027-pagamento-no-cadastro-despesa, R23).
 */
export const EXPENSE_SAVE_TIMEOUT_MS = 60_000;

const CHECK_THE_LIST =
  'Confira a lista de despesas antes de tentar de novo; se a despesa não aparecer, pode salvar outra vez, sem risco de duplicar.';

/** O que `axios` devolve numa falha, só nos campos que a mensagem usa (o teste não depende da classe). */
type SaveErrorLike = {
  code?: string;
  response?: {
    status?: number;
    data?: unknown;
  };
};

/**
 * Texto do alerta quando o cadastro falha. O motivo que a API deu vale sempre (as regras de
 * domínio são do servidor); o cliente só acrescenta o que a API não pode dizer: 413 (o corpo
 * é da página do servidor web), e a falta de resposta (timeout, rede, 502 a 504), em que a
 * despesa pode ter sido criada e o reenvio, com a mesma chave, não duplica (R23).
 */
export function describeSaveError(err: unknown): string {
  const { code, response } = (err ?? {}) as SaveErrorLike;
  const status = response?.status;
  const data = response && typeof response.data === 'object' && response.data !== null ? (response.data as Record<string, unknown>) : null;

  if (code === 'ECONNABORTED' || code === 'ETIMEDOUT') {
    return `O envio demorou mais do que o esperado. ${CHECK_THE_LIST}`;
  }

  if (!response) {
    return `Falha ao salvar despesa. Não foi possível confirmar se o envio chegou. ${CHECK_THE_LIST}`;
  }

  if (status === 413) {
    return 'A foto é maior do que o servidor aceita. Escolha uma foto menor ou reduza a qualidade.';
  }

  if (status === 502 || status === 503 || status === 504) {
    return `Falha ao salvar despesa. O servidor não respondeu a tempo. ${CHECK_THE_LIST}`;
  }

  if (typeof data?.error === 'string' && data.error !== '') {
    return data.error;
  }

  if (status === 409) {
    return `Este cadastro já foi processado com outros dados. ${CHECK_THE_LIST}`;
  }

  const validation = data?.errors as Record<string, unknown> | undefined;
  const firstMessage = validation ? Object.values(validation).flat().find(item => typeof item === 'string') : undefined;

  return typeof firstMessage === 'string' ? firstMessage : 'Falha ao salvar despesa.';
}

/** Tamanho máximo da foto do comprovante; o servidor valida o mesmo valor (`max:5120`, em KB). */
export const PROOF_MAX_BYTES = 5 * 1024 * 1024;

/**
 * Aviso de UX antes de enviar: o motivo de uma foto que o servidor recusaria, ou `null` se
 * ela passa. O servidor segue sendo a autoridade (CA-F10); aqui só se evita subir 5 MB
 * para receber um 422.
 */
export function proofFileProblem(file: File): string | null {
  if (!file.type.startsWith('image/')) {
    return 'Escolha uma imagem (JPG, PNG ou semelhante) como comprovante.';
  }

  if (file.size > PROOF_MAX_BYTES) {
    return 'A foto passa de 5 MB. Escolha uma menor ou reduza a qualidade.';
  }

  return null;
}

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
