type GroupActivity = {
  id: number;
  create_date: string;
  expenses_max_date_payment: string | null;
};

export function mostActiveGroup<T extends GroupActivity>(groups: T[]): T | null {
  if (groups.length === 0) {
    return null;
  }

  return [...groups].sort((a, b) => {
    if (a.expenses_max_date_payment && b.expenses_max_date_payment) {
      return b.expenses_max_date_payment.localeCompare(a.expenses_max_date_payment);
    }
    if (a.expenses_max_date_payment) {
      return -1;
    }
    if (b.expenses_max_date_payment) {
      return 1;
    }
    return b.create_date.localeCompare(a.create_date);
  })[0];
}
