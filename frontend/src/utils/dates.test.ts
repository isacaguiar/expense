import { describe, expect, it } from 'vitest';
import { formatShortDate } from './dates';

// O fuso da suíte é America/Sao_Paulo (vite.config.js, guarda em
// suiteTimezone.test.ts): negativo, que é a condição do bug de data que este
// helper evita. `new Date('2026-10-03')` é meia-noite UTC e viraria 02/10 aqui.
describe('formatShortDate', () => {
  it('mostra o dia com dois dígitos e o mês abreviado em pt-BR', () => {
    expect(formatShortDate('2026-10-03')).toMatch(/^03\b.*out/i);
  });

  it('mantém o dia da data recebida em fuso negativo, sem recuar para o dia anterior', () => {
    expect(formatShortDate('2026-10-03')).toMatch(/^03\b/);
    expect(formatShortDate('2026-01-01')).toMatch(/^01\b.*jan/i);
    expect(formatShortDate('2026-09-30')).toMatch(/^30\b.*set/i);
  });
});
