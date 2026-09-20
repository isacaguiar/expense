import { isValidWhatsapp } from '../../utils/phone';
import type { RegisterFormErrors, RegisterFormValues } from './RegisterFormCard';

/**
 * Validação de UX do formulário de cadastro — evita uma ida ao servidor para
 * erro óbvio. Não substitui a validação do backend (`PreRegisterRequest`), que
 * continua sendo a autoridade: `00-constitution.md` §1.1.
 */
export function validateRegisterForm(values: RegisterFormValues): RegisterFormErrors {
  const errors: RegisterFormErrors = {};

  if (!values.name.trim()) {
    errors.name = 'Informe seu nome.';
  }

  if (!values.email.trim()) {
    errors.email = 'Informe seu e-mail.';
  } else if (!/^\S+@\S+\.\S+$/.test(values.email)) {
    errors.email = 'Informe um e-mail válido.';
  }

  if (values.emailConfirmation !== values.email) {
    errors.emailConfirmation = 'Os e-mails não conferem.';
  }

  if (!isValidWhatsapp(values.whatsapp)) {
    errors.whatsapp = 'Informe o telefone no formato (11) 91234-5678.';
  }

  if (values.password.length < 6) {
    errors.password = 'A senha precisa ter ao menos 6 caracteres.';
  }

  if (values.passwordConfirmation !== values.password) {
    errors.passwordConfirmation = 'As senhas não conferem.';
  }

  return errors;
}

/**
 * Traduz o `errors` 422 do Laravel (chaves snake_case do payload) para as
 * chaves do formulário. O que não casar com campo nenhum vira erro geral.
 */
export function mapApiErrors(apiErrors: Record<string, string[]>): {
  fieldErrors: RegisterFormErrors;
  generalError: string | null;
} {
  const byField: Record<string, keyof RegisterFormValues> = {
    name: 'name',
    email: 'email',
    email_confirmation: 'emailConfirmation',
    whatsapp: 'whatsapp',
    password: 'password',
    password_confirmation: 'passwordConfirmation',
  };

  const fieldErrors: RegisterFormErrors = {};
  const leftovers: string[] = [];

  Object.entries(apiErrors).forEach(([key, messages]) => {
    const field = byField[key];
    if (field) {
      fieldErrors[field] = messages[0];
    } else {
      leftovers.push(...messages);
    }
  });

  return {
    fieldErrors,
    generalError: leftovers.length > 0 ? leftovers.join(' ') : null,
  };
}
