import type { LoginResponse } from '../types/auth';

/**
 * Grava a sessão autenticada no `localStorage`, no mesmo formato que o
 * `RequireAuth` e os hooks de dados já esperam (`accessToken`).
 *
 * Existe para que o cadastro possa logar direto ao confirmar o código sem
 * duplicar a sequência de `LoginPage`. O `LoginPage` ainda grava a sessão
 * inline — migrá-lo é item de backlog, não escopo desta feature.
 */
export function setSession(data: LoginResponse): void {
  localStorage.setItem('accessToken', data.access_token);
}
