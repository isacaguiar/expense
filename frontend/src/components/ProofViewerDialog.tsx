import React, { useState } from 'react';
import { Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, useMediaQuery } from '@mui/material';
import { useTheme } from '@mui/material/styles';

type ProofViewerDialogProps = {
  open: boolean;
  // A URL assinada do comprovante (ADR-005, vale 30 minutos). `null` enquanto nada foi aberto.
  url: string | null;
  title?: string;
  onClose: () => void;
};

export const PROOF_LOAD_ERROR = 'Não foi possível carregar o comprovante. Atualize a página.';

/**
 * A imagem num componente à parte, com `key` pela URL: cada URL nova começa em "carregando" e sem erro, sem
 * precisar de efeito para zerar o estado.
 */
const ProofImage: React.FC<{ url: string }> = ({ url }) => {
  const [status, setStatus] = useState<'loading' | 'loaded' | 'error'>('loading');

  if (status === 'error') {
    return <Alert severity="warning">{PROOF_LOAD_ERROR}</Alert>;
  }

  return (
    <Box sx={{ position: 'relative', display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: 120 }}>
      {status === 'loading' && <CircularProgress aria-label="Carregando o comprovante" sx={{ position: 'absolute' }} />}
      <Box
        component="img"
        src={url}
        alt="Comprovante de pagamento"
        // Imagem já no cache do navegador pode ter carregado antes de o React ligar o `onLoad`.
        ref={(element: HTMLImageElement | null) => {
          if (element?.complete && element.naturalWidth > 0) setStatus('loaded');
        }}
        onLoad={() => setStatus('loaded')}
        onError={() => setStatus('error')}
        sx={{
          display: 'block',
          maxWidth: '100%',
          maxHeight: 'calc(100dvh - 180px)',
          objectFit: 'contain',
          opacity: status === 'loaded' ? 1 : 0
        }}
      />
    </Box>
  );
};

/**
 * Mostra o comprovante num diálogo, sem tirar a pessoa da tela (feature 20261120-ver-comprovante-em-modal,
 * plan §1). O comprovante é sempre imagem; dentro de `<img>` um SVG antigo não executa script. Tela cheia
 * abaixo de `sm`. "Abrir em nova aba" (D2-A) fica para quem quer ampliar ou salvar a foto.
 */
const ProofViewerDialog: React.FC<ProofViewerDialogProps> = ({ open, url, title = 'Comprovante', onClose }) => {
  const theme = useTheme();
  const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md" fullScreen={fullScreen}>
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>{url && <ProofImage key={url} url={url} />}</DialogContent>
      <DialogActions>
        {url && (
          <Button component="a" href={url} target="_blank" rel="noreferrer">
            Abrir em nova aba
          </Button>
        )}
        <Button onClick={onClose}>Fechar</Button>
      </DialogActions>
    </Dialog>
  );
};

export default ProofViewerDialog;
