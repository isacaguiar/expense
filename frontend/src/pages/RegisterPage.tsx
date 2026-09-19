import React, { useState } from 'react';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';
import { API_BASE_URL } from '../config';
import { formatWhatsapp } from '../utils/phone';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import LoginPageFooter from './login/LoginPageFooter';
import RegisterFormCard from './register/RegisterFormCard';
import type { RegisterFormErrors, RegisterFormValues } from './register/RegisterFormCard';
import { mapApiErrors, validateRegisterForm } from './register/validateRegisterForm';
import type { ApiErrorBody } from '../types/auth';

const EMPTY_FORM: RegisterFormValues = {
  name: '',
  email: '',
  emailConfirmation: '',
  whatsapp: '',
  password: '',
  passwordConfirmation: '',
};

/**
 * Auto-cadastro em duas etapas na mesma rota: formulário e, depois, o código
 * de 6 dígitos que confirma o e-mail. A conta só existe depois do código.
 *
 * Espelha o split de `LoginPage` de propósito — quem vem do "Cadastre-se"
 * precisa reconhecer a mesma tela.
 *
 * docs/feature/20260919-cadastro-de-usuarios/plan.md §6
 */
export default function RegisterPage() {
  const [values, setValues] = useState<RegisterFormValues>(EMPTY_FORM);
  const [errors, setErrors] = useState<RegisterFormErrors>({});
  const [generalError, setGeneralError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [codeSentTo, setCodeSentTo] = useState<string | null>(null);

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

      setCodeSentTo(values.email);
    } catch (err) {
      console.error('Falha no pré-cadastro:', err);
      setGeneralError('Não foi possível iniciar o cadastro. Verifique sua conexão e tente novamente.');
    } finally {
      setSubmitting(false);
    }
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
          {codeSentTo === null ? (
            <RegisterFormCard
              values={values}
              errors={errors}
              generalError={generalError}
              submitting={submitting}
              onChange={handleChange}
              onSubmit={handleSubmit}
            />
          ) : (
            <Box sx={{ width: '100%', maxWidth: 440 }}>
              <Alert severity="success">
                <Typography sx={{ fontSize: '0.875rem' }}>
                  Enviamos um código de confirmação para <strong>{codeSentTo}</strong>.
                </Typography>
              </Alert>
            </Box>
          )}
        </Box>
      </Box>

      <LoginPageFooter />
    </Box>
  );
}
