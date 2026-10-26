// Datas curtas da interface. docs/feature/20261026-organizacao-despesas-pagamentos/plan.md §2.

/**
 * 'YYYY-MM-DD' → dia com dois dígitos + mês abreviado em pt-BR ("03 de out.").
 *
 * `new Date('YYYY-MM-DD')` interpreta a string como UTC-meia-noite, o que desloca
 * a data em 1 dia para trás em fusos negativos (ex.: America/Sao_Paulo) —
 * construímos a partir dos componentes locais para evitar isso.
 */
export const formatShortDate = (dateStr: string): string => {
  const [year, month, day] = dateStr.split('-').map(Number);
  return new Date(year, month - 1, day).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' });
};
