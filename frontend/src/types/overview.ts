/**
 * Tipos de `GET /api/me/overview` (`OverviewController` → `PersonalOverviewService`),
 * a visão pessoal do Painel: por grupo do usuário, o ciclo em foco, o que ele
 * deve e a quem, o que tem a receber e de quem, os itens dele e as flags de
 * ação. Contrato em snake_case, igual para o web e o app Flutter.
 *
 * Tudo aqui vem calculado do servidor (valores, `cycles_ago`, `can_*`): o
 * cliente só exibe e manda de volta o que recebeu.
 *
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §1.1
 */
import type { CycleStatus } from '../hooks/useGroupCycle';

/** O ciclo em foco do grupo. `cycles_ago` é o que as ações devem mandar de volta. */
export type OverviewCycle = {
  start: string;
  end: string;
  closes_at: string;
  status: CycleStatus;
  settled: boolean;
  /** A fronteira já passou, mas o ciclo ainda está `open` (carência até `closes_at`). */
  in_grace: boolean;
  cycles_ago: number;
};

/** Soma dos acertos: `confirmed` já tem comprovante; `open` é o que falta. */
export type OverviewTotals = {
  total: number;
  confirmed: number;
  open: number;
};

/**
 * O credor a quem o usuário paga. `email` e `pix` são nulos quando o credor
 * saiu do grupo (aí `name` vem "Desconhecido").
 */
export type OverviewCreditor = {
  id: number;
  name: string;
  email: string | null;
  pix: string | null;
  avatar_url: string | null;
};

/** O devedor que paga ao usuário: sem contato. */
export type OverviewDebtor = {
  id: number;
  name: string;
  avatar_url: string | null;
};

/** Acerto líquido em que o usuário é devedor. */
export type OverviewPayable = {
  to_user: OverviewCreditor;
  amount: number;
  confirmed_at: string | null;
  confirmed_proof_url: string | null;
  /** Ciclo fechado (por data ou manualmente) e não selado: pode enviar comprovante. */
  can_confirm: boolean;
};

/** Acerto líquido em que o usuário é credor. */
export type OverviewReceivable = {
  from_user: OverviewDebtor;
  amount: number;
  confirmed_at: string | null;
  confirmed_proof_url: string | null;
};

export type OverviewItemRole = 'debtor' | 'creditor';

/** Despesa do ciclo em que o usuário participa ou que ele pagou. */
export type OverviewItem = {
  expense_id: number;
  description: string;
  date: string;
  value: number;
  /** A parte do usuário (valor por pessoa); 0 quando ele só pagou, sem participar. */
  my_share: number;
  paid: boolean;
  role: OverviewItemRole;
  /** `id` e `name` nulos só em snapshot antigo sem esses dados. */
  creditor: { id: number | null; name: string | null };
  /** Só em despesa parcelada. */
  installment: { number: number; total: number } | null;
  /** Só o credor marca e desmarca. */
  can_pay: boolean;
  can_unpay: boolean;
};

export type OverviewGroup = {
  group: { id: number; name: string };
  cycle: OverviewCycle;
  owe: OverviewTotals;
  receive: OverviewTotals;
  to_pay: OverviewPayable[];
  to_receive: OverviewReceivable[];
  my_items: OverviewItem[];
  pending_items_count: number;
  has_open: boolean;
};

export type MyOverview = {
  user: { id: number; name: string };
  /** Já ordenados pelo servidor: com algo em aberto primeiro, depois por nome. */
  groups: OverviewGroup[];
  /** O usuário participa de mais de 50 grupos e só os 50 mais antigos vieram. */
  truncated: boolean;
};
