/**
 * Tipos dos payloads de autenticação, ancorados no que **cada endpoint
 * devolve ou recebe** — mesma convenção de `group.ts`.
 *
 * docs/feature/20260919-cadastro-de-usuarios/plan.md §4
 */

/** Resposta de `POST /api/login` e de `POST /api/pre-register/verify`. */
export type LoginResponse = {
  access_token: string;
  token_type: string;
  /** Segundos até o token expirar. */
  expires_in: number;
};

/** Corpo de `POST /api/pre-register` — o formulário de `/cadastro` inteiro. */
export type PreRegisterPayload = {
  name: string;
  email: string;
  email_confirmation: string;
  /** `(11) 91234-5678` ou `null` — o telefone é opcional. */
  whatsapp: string | null;
  password: string;
  password_confirmation: string;
};

/** Resposta de `POST /api/pre-register` e `/resend`. */
export type PreRegisterResponse = {
  message: string;
  expires_in_seconds: number;
  resend_available_in: number;
};

/**
 * Corpo de erro da API. `errors` é o shape 422 do Laravel (um array de
 * mensagens por campo); `retry_after` só vem nos 429 do pré-cadastro.
 */
export type ApiErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
  retry_after?: number;
};
