// Rateio de parcelas compartilhado entre ExpenseForm.tsx (criação) e
// ExpenseView.tsx (edição — docs/feature/concluidas/202608/20260826-editar-tipo-despesa/plan.md
// §3) — o backend só valida quantidade/soma (ExpenseController::store()/
// update()), quem monta as quotas é sempre o client.

export type InstallmentQuota = { number: number; date_expected: string; paid: boolean; value_quota: number };

const pad = (n: number): string => String(n).padStart(2, '0');

// Soma `months` meses a uma data 'YYYY-MM-DD', preservando o dia (com clamp pro fim do mês).
export const addMonthsClamped = (dateStr: string, months: number): string => {
  const [y, m, d] = dateStr.split('-').map(Number);
  const totalMonths = (y * 12) + (m - 1) + months;
  const targetYear = Math.floor(totalMonths / 12);
  const targetMonthIndex = ((totalMonths % 12) + 12) % 12;
  const lastDayOfTargetMonth = new Date(targetYear, targetMonthIndex + 1, 0).getDate();
  const day = Math.min(d, lastDayOfTargetMonth);
  return `${targetYear}-${pad(targetMonthIndex + 1)}-${pad(day)}`;
};

// Divide o valor total em N parcelas mensais iguais, arredondamento absorvido na última.
export const buildInstallmentQuotas = (
  totalValue: number,
  installmentsCount: number,
  startDate: string
): InstallmentQuota[] => {
  const totalCents = Math.round(totalValue * 100);
  const baseCents = Math.floor(totalCents / installmentsCount);
  const remainderCents = totalCents - (baseCents * installmentsCount);

  return Array.from({ length: installmentsCount }, (_, i) => {
    const cents = baseCents + (i === installmentsCount - 1 ? remainderCents : 0);
    return {
      number: i + 1,
      date_expected: addMonthsClamped(startDate, i),
      paid: false,
      value_quota: cents / 100
    };
  });
};
