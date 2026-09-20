/**
 * Máscara de celular brasileiro no formato que o backend valida em
 * `PreRegisterRequest` e `UserController@updateProfile`: `(11) 91234-5678`.
 *
 * Aplicada a cada tecla, então precisa funcionar com a string parcial — por
 * isso monta o resultado por faixa de dígitos em vez de casar um regex final.
 */
export function formatWhatsapp(raw: string): string {
  const digits = raw.replace(/\D/g, '').slice(0, 11);

  if (digits.length === 0) return '';
  if (digits.length <= 2) return `(${digits}`;
  if (digits.length <= 7) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;

  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
}

/** O formato completo que o backend aceita. Vazio é válido: o campo é opcional. */
export function isValidWhatsapp(value: string): boolean {
  return value === '' || /^\(\d{2}\) 9\d{4}-\d{4}$/.test(value);
}
