/**
 * Traduz uma notificação (`type` + payload `data` do backend) no texto que
 * aparece na lista do sino. O backend só guarda os dados brutos; o texto é
 * responsabilidade do cliente. Ver docs/feature/concluidas/202609/20260903-notificacoes-in-app/plan.md §4.
 */

type NotificationData = Record<string, unknown>;

const str = (value: unknown): string => (value == null ? '' : String(value));

const brl = (value: unknown): string => {
  const n = Number(value);
  return Number.isFinite(n)
    ? n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
    : str(value);
};

export function notificationText(type: string, data: NotificationData): string {
  switch (type) {
    case 'expense_paid':
      return `${str(data.actorName)} marcou "${str(data.expenseDescription)}" como paga`;
    case 'settlement_confirmed':
      return `${str(data.actorName)} confirmou um pagamento de ${brl(data.amount)}`;
    case 'cycle_settled':
      return `O ciclo de ${str(data.cycleLabel)} de "${str(data.groupName)}" foi quitado`;
    case 'cycle_closed':
      return `${str(data.actorName)} fechou o ciclo de ${str(data.cycleLabel)} de "${str(data.groupName)}"`;
    case 'group_member_added':
      return `${str(data.actorName)} adicionou você ao grupo "${str(data.groupName)}"`;
    case 'expense_created':
      return `${str(data.actorName)} adicionou a despesa "${str(data.expenseDescription)}"`;
    default:
      return 'Nova notificação';
  }
}

/** Tempo relativo curto em pt-BR ("há 5 minutos", "ontem", …). */
export function formatRelative(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) {
    return '';
  }

  const diffSec = Math.round((then - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });

  if (abs < 60) return rtf.format(diffSec, 'second');
  if (abs < 3600) return rtf.format(Math.round(diffSec / 60), 'minute');
  if (abs < 86_400) return rtf.format(Math.round(diffSec / 3600), 'hour');
  if (abs < 2_592_000) return rtf.format(Math.round(diffSec / 86_400), 'day');
  if (abs < 31_536_000) return rtf.format(Math.round(diffSec / 2_592_000), 'month');
  return rtf.format(Math.round(diffSec / 31_536_000), 'year');
}
