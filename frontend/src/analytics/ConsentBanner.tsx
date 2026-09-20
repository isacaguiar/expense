import React, { useState } from 'react';
import { Box, Button, Link, Paper, Snackbar, Typography } from '@mui/material';
import { getConsent, setConsent, type ConsentDecision } from './consent';

/**
 * Pede o consentimento de cookies de analytics e aplica a escolha na hora.
 *
 * Não é modal de propósito: ancorado embaixo, sem backdrop, não impede usar a
 * tela de login por baixo. Bloquear a entrada no produto para exigir uma
 * escolha cobraria caro em conversão, e a LGPD exige escolha livre, não
 * bloqueio.
 *
 * Só aparece quando não existe decisão gravada no cookie `scd_consent` — que é
 * o mesmo cookie do site institucional, na mesma origem: quem já escolheu lá
 * não é perguntado de novo aqui (ver `consent.ts`).
 */
const ConsentBanner: React.FC = () => {
  const [open, setOpen] = useState(() => getConsent() === null);

  const escolher = (decision: ConsentDecision) => () => {
    setConsent(decision);
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
