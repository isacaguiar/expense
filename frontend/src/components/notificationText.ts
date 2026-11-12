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
    case 'settlement_confirmed': {
      // `paidByName` só existe quando outra pessoa pagou por quem deve (`actorName`); sem ele, o texto de sempre.
      // O comprovante enviado por outra pessoa só vale depois que o credor (quem lê este aviso) o aceita.
      const paidBy = str(data.paidByName);
      return paidBy
        ? `${paidBy} confirmou um pagamento de ${brl(data.amount)} por ${str(data.actorName)}. Aguarda o seu aceite.`
        : `${str(data.actorName)} confirmou um pagamento de ${brl(data.amount)}`;
    }
    case 'payment_responsible_assigned':
      return `Você agora pode pagar por ${str(data.actorName)} no grupo "${str(data.groupName)}"`;
    case 'payment_responsible_self_declared':
      return `${str(data.actorName)} agora paga por você no grupo "${str(data.groupName)}"`;
    case 'settlement_refused':
      return `${str(data.actorName)} recusou o comprovante de ${str(data.debtorName)} de ${brl(data.amount)}`;
    case 'settlement_accepted':
      return `${str(data.actorName)} aceitou o comprovante de ${brl(data.amount)}`;
    case 'cycle_settled':
      return `O ciclo de ${str(data.cycleLabel)} de "${str(data.groupName)}" foi quitado`;
    case 'cycle_closed':
      return `${str(data.actorName)} fechou o ciclo de ${str(data.cycleLabel)} de "${str(data.groupName)}"`;
    case 'group_member_added':
      return `${str(data.actorName)} adicionou você ao grupo "${str(data.groupName)}"`;
    case 'group_member_removed':
      return `${str(data.actorName)} removeu você do grupo "${str(data.groupName)}"`;
    case 'group_invitation':
      return `${str(data.actorName)} convidou você para o grupo "${str(data.groupName)}"`;
    case 'group_invitation_accepted':
      return `${str(data.actorName)} aceitou o convite para o grupo "${str(data.groupName)}"`;
    case 'expense_created':
      return `${str(data.actorName)} adicionou a despesa "${str(data.expenseDescription)}"`;
    case 'expense_born_paid': {
      const count = Number(data.quotasCount) || 0;
      const parcela = count === 1 ? 'parcela' : 'parcelas';
      const paga = count === 1 ? 'já paga' : 'já pagas';
      return `${str(data.actorName)} registrou "${str(data.expenseDescription)}" com ${count} ${parcela} ${paga} em seu nome`;
    }
    case 'expense_paid_on_behalf':
      return `${str(data.actorName)} registrou o pagamento de "${str(data.expenseDescription)}" em seu nome`;
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
