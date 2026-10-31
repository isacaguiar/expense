import React, { useState } from 'react';
import axios from 'axios';
import Box from '@mui/material/Box';
import { API_BASE_URL } from '../config';
import LoginBrandingPanel from './login/LoginBrandingPanel';
import ForgotPasswordFormCard from './forgot-password/ForgotPasswordFormCard';
import LoginPageFooter from './login/LoginPageFooter';

const RATE_LIMIT_FALLBACK_MESSAGE = 'Você já solicitou recuperação recentemente. Aguarde alguns minutos.';
const GENERIC_ERROR_MESSAGE = 'Não foi possível enviar o e-mail agora. Tente novamente em instantes.';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      await axios.post(`${API_BASE_URL}/api/forgot-password`, { email });
      setSuccess(true);
    } catch (err) {
      const response = (err as { response?: { status?: number; data?: { message?: string } } })?.response;

      // 422 = e-mail não encontrado (exists:ex_users,email no backend). Tratamos
      // como sucesso genérico para não ampliar a enumeração que a resposta crua
      // do backend já tem. LIMITE CONHECIDO (revisão de segurança, 2026-10-30):
      // o 429 e o 500 só existem para conta existente, então a tela ainda
      // confirma a conta a quem pede duas vezes seguidas; risco aceito, conserto
      // no item 119 do backlog. Ver docs/feature/concluidas/202609/20260930-recuperacao-acesso/specify.md §3.
      if (response?.status === 422) {
        setSuccess(true);
      } else if (response?.status === 429) {
        setError(response?.data?.message ?? RATE_LIMIT_FALLBACK_MESSAGE);
      } else {
        setError(response?.data?.message ?? GENERIC_ERROR_MESSAGE);
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ display: 'flex', flex: 1, position: 'relative' }}>
        <LoginBrandingPanel
          headline="Recupere seu acesso,"
          highlight="em poucos passos."
          description="Informe o e-mail da sua conta e enviaremos um link para você definir uma nova senha."
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
          <ForgotPasswordFormCard
            email={email}
            submitting={submitting}
            error={error}
            success={success}
            onEmailChange={setEmail}
            onSubmit={handleSubmit}
          />
        </Box>
      </Box>

      <LoginPageFooter />
    </Box>
  );
}
