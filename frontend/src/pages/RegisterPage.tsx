import React, { useEffect, useState } from 'react';
import Box from '@mui/material/Box';
import { useNavigate } from 'react-router-dom';
import { API_BASE_URL } from '../config';
import { setSession } from '../auth/session';
import { formatWhatsapp } from '../utils/phone';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import LoginPageFooter from './login/LoginPageFooter';
import ConfirmCodeCard from './register/ConfirmCodeCard';
import RegisterFormCard from './register/RegisterFormCard';
import type { RegisterFormErrors, RegisterFormValues } from './register/RegisterFormCard';
import { mapApiErrors, validateRegisterForm } from './register/validateRegisterForm';
import type { ApiErrorBody, LoginResponse, PreRegisterResponse } from '../types/auth';

const EMPTY_FORM: RegisterFormValues = {
  name: '',
  email: '',
  emailConfirmation: '',
  whatsapp: '',
  password: '',
  passwordConfirmation: '',
};

/** Pré-cadastro em curso: o handle amarra a confirmação a esta submissão. */
type PendingRegistration = {
  email: string;
  handle: string;
};

/**
 * Auto-cadastro em duas etapas na mesma rota: formulário e, depois, o código
 * de 6 dígitos que confirma o e-mail. A conta só existe depois do código, e
 * já nasce autenticada.
 *
 * Espelha o split de `LoginPage` de propósito — quem vem do "Cadastre-se"
 * precisa reconhecer a mesma tela.
 *
 * docs/feature/20260919-cadastro-de-usuarios/plan.md §6 e §10
 */
export default function RegisterPage() {
  const navigate = useNavigate();

  const [values, setValues] = useState<RegisterFormValues>(EMPTY_FORM);
  const [errors, setErrors] = useState<RegisterFormErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const [pending, setPending] = useState<PendingRegistration | null>(null);
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [resending, setResending] = useState(false);
  const [resendCountdown, setResendCountdown] = useState(0);

  // Cadeia de timeouts em vez de um intervalo: cada tick agenda o próximo, o
  // cleanup cancela o pendente, e não há intervalo sobrevivendo à desmontagem.
  useEffect(() => {
    if (resendCountdown <= 0) return;

    const timer = setTimeout(() => setResendCountdown((current) => current - 1), 1000);

    return () => clearTimeout(timer);
  }, [resendCountdown]);

  const handleChange = (field: keyof RegisterFormValues, value: string) => {
    setValues((current) => ({
      ...current,
      [field]: field === 'whatsapp' ? formatWhatsapp(value) : value,
    }));
    // Erro do campo some assim que a pessoa mexe nele — manter o erro antigo
    // visível enquanto ela corrige é ruído.
    setErrors((current) => ({ ...current, [field]: undefined }));
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setGeneralError(null);

    const validationErrors = validateRegisterForm(values);
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    setErrors({});
    setSubmitting(true);

    try {
      const res = await fetch(`${API_BASE_URL}/api/pre-register`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: values.name,
          email: values.email,
          email_confirmation: values.emailConfirmation,
          whatsapp: values.whatsapp === '' ? null : values.whatsapp,
          password: values.password,
          password_confirmation: values.passwordConfirmation,
        }),
      });

      if (!res.ok) {
        const body: ApiErrorBody = await res.json().catch(() => ({}));

        if (body.errors) {
          const { fieldErrors, generalError: leftover } = mapApiErrors(body.errors);
          setErrors(fieldErrors);
          setGeneralError(leftover);
        } else {
          setGeneralError(body.message ?? 'Não foi possível iniciar o cadastro. Tente novamente em instantes.');
        }
        return;
      }

      const data: PreRegisterResponse & { handle: string } = await res.json();
      setPending({ email: values.email, handle: data.handle });
      setCode('');
      setCodeError(null);
      setNotice(null);
      setResendCountdown(data.resend_available_in);
    } catch (err) {
      console.error('Falha no pré-cadastro:', err);
      setGeneralError('Não foi possível iniciar o cadastro. Verifique sua conexão e tente novamente.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleConfirm = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (pending === null) return;

    setCodeError(null);
    setNotice(null);
    setSubmitting(true);

    try {
      const res = await fetch(`${API_BASE_URL}/api/pre-register/verify`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: pending.email, handle: pending.handle, code }),
      });

      if (!res.ok) {
        const body: ApiErrorBody = await res.json().catch(() => ({}));
        const fieldMessage = body.errors ? Object.values(body.errors).flat()[0] : undefined;

        setCodeError(fieldMessage ?? body.message ?? 'Não foi possível confirmar o código. Tente novamente.');

        if (typeof body.retry_after === 'number') {
          setResendCountdown(body.retry_after);
        }
        return;
      }

      const data: LoginResponse = await res.json();
      setSession(data);
      navigate('/meus-grupos');
    } catch (err) {
      console.error('Falha na confirmação do código:', err);
      setCodeError('Não foi possível confirmar o código. Verifique sua conexão e tente novamente.');
    } finally {
      setSubmitting(false);
    }
  };

  const handleResend = async () => {
    if (pending === null) return;

    setCodeError(null);
    setNotice(null);
    setResending(true);

    try {
      const res = await fetch(`${API_BASE_URL}/api/pre-register/resend`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: pending.email, handle: pending.handle }),
      });

      const body = await res.json().catch(() => ({}));

      if (!res.ok) {
        const errorBody = body as ApiErrorBody;
        setCodeError(errorBody.message ?? 'Não foi possível reenviar o código.');
        if (typeof errorBody.retry_after === 'number') {
          setResendCountdown(errorBody.retry_after);
        }
        return;
      }

      const okBody = body as PreRegisterResponse;
      setNotice('Enviamos um novo código para o seu e-mail.');
      setCode('');
      setResendCountdown(okBody.resend_available_in);
    } catch (err) {
      console.error('Falha ao reenviar o código:', err);
      setCodeError('Não foi possível reenviar o código. Verifique sua conexão e tente novamente.');
    } finally {
      setResending(false);
    }
  };

  /**
   * Voltar descarta o handle: o pré-cadastro anterior continua na base, mas
   * a próxima submissão o regrava e emite um handle novo.
   */
  const handleBack = () => {
    setPending(null);
    setCode('');
    setCodeError(null);
    setNotice(null);
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ display: 'flex', flex: 1, position: 'relative' }}>
        <LoginBrandingPanel
          headline="Crie sua conta"
          highlight="em menos de um minuto."
          description="Cadastre-se para criar grupos, registrar despesas compartilhadas e acompanhar quem deve a quem, mês a mês."
        />

        <Box
          sx={{
            flex: 1,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            p: 3,
          }}
        >
          {pending === null ? (
            <RegisterFormCard
              values={values}
              errors={errors}
              generalError={generalError}
              submitting={submitting}
              onChange={handleChange}
              onSubmit={handleSubmit}
            />
          ) : (
            <ConfirmCodeCard
              email={pending.email}
              code={code}
              error={codeError}
              notice={notice}
              submitting={submitting}
              resending={resending}
              resendCountdown={resendCountdown}
              onCodeChange={(value) => setCode(value.replace(/\D/g, '').slice(0, 6))}
              onSubmit={handleConfirm}
              onResend={handleResend}
              onBack={handleBack}
            />
          )}
        </Box>
      </Box>

      <LoginPageFooter />
    </Box>
  );
}
