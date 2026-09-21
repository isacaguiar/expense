import React, { useEffect, useState } from 'react';
import { Box, Button, Link, Paper, Snackbar, Typography } from '@mui/material';
import {
  CONSENT_REOPEN_EVENT,
  getConsent,
  setConsent,
  type ConsentDecision,
} from './consent';

/**
 * Pede o consentimento de cookies de analytics e aplica a escolha na hora.
 *
 * Não é modal de propósito: ancorado embaixo, sem backdrop, não impede usar a
 * tela de login por baixo. Bloquear a entrada no produto para exigir uma
 * escolha cobraria caro em conversão, e a LGPD exige escolha livre, não
 * bloqueio.
 *
 * Aparece sozinho quando não existe decisão gravada no cookie `scd_consent` —
 * que é o mesmo cookie do site institucional, na mesma origem: quem já
 * escolheu lá não é perguntado de novo aqui (ver `consent.ts`). Também
 * reabre sob demanda, pelo evento disparado em `Profile`.
 */
const ConsentBanner: React.FC = () => {
  const [decisao, setDecisao] = useState<ConsentDecision | null>(() => getConsent());
  const [open, setOpen] = useState(() => getConsent() === null);

  useEffect(() => {
    const reabrir = () => {
      // Relê o cookie: a escolha pode ter mudado no site, na mesma origem.
      setDecisao(getConsent());
      setOpen(true);
    };

    window.addEventListener(CONSENT_REOPEN_EVENT, reabrir);

    return () => window.removeEventListener(CONSENT_REOPEN_EVENT, reabrir);
  }, []);

  const escolher = (decision: ConsentDecision) => () => {
    setConsent(decision);
    setDecisao(decision);
    setOpen(false);
  };

  return (
    <Snackbar
      open={open}
      anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      // Sem `autoHideDuration`: sumir sozinho registraria uma não-escolha como
      // se fosse escolha, e o banner voltaria no próximo carregamento.
      sx={{ maxWidth: 560, width: { xs: '100%', sm: 'auto' } }}
    >
      <Paper elevation={6} sx={{ p: 2, display: 'flex', flexDirection: 'column', gap: 1.5 }}>
        <Typography variant="body2" color="text.secondary">
          Usamos cookies do Google Analytics para entender como o app é usado. Eles só são
          ativados se você aceitar, e a escolha pode ser mudada depois no seu perfil.{' '}
          <Link href="/privacidade.php" target="_blank" rel="noopener">
            Política de Privacidade
          </Link>
          .
        </Typography>

        {decisao && (
          <Typography variant="caption" color="text.secondary">
            Escolha atual:{' '}
            <strong>{decisao === 'granted' ? 'analytics ativado' : 'analytics desativado'}</strong>.
          </Typography>
        )}

        <Box sx={{ display: 'flex', gap: 1, justifyContent: 'flex-end' }}>
          <Button size="small" onClick={escolher('denied')}>
            Recusar
          </Button>
          <Button size="small" variant="contained" onClick={escolher('granted')}>
            Aceitar
          </Button>
        </Box>
      </Paper>
    </Snackbar>
  );
};

export default ConsentBanner;
