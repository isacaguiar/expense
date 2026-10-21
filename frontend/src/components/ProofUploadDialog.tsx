import React, { useState } from 'react';
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle
} from '@mui/material';

interface ProofUploadDialogProps {
  open: boolean;
  title: string;
  description: string;
  /** Rótulo do botão de seleção enquanto nenhum arquivo foi escolhido. */
  selectLabel: string;
  icon: React.ReactNode;
  /** Envio em andamento: trava "Cancelar" e "Confirmar". */
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onConfirm: (file: File) => void;
}

/**
 * Diálogo de foto do comprovante: escolher a imagem (câmera traseira no
 * celular) e confirmar. A foto é obrigatória aqui, do lado do cliente, mesmo
 * onde o campo é opcional na API. Quem chama decide o que fazer com o arquivo:
 * marcar a despesa como paga (credor) ou enviar o comprovante do acerto
 * (devedor). Extraído de `Payments.tsx`, que tinha as duas versões inline
 * (docs/feature/20261010-painel-financeiro-pessoal/plan.md §3).
 */
const ProofUploadDialog: React.FC<ProofUploadDialogProps> = ({ open, onClose, ...formProps }) => {
  // Cada abertura começa sem arquivo: a contagem de aberturas é a `key` do
  // formulário. Não basta o Dialog desmontar ao fechar, porque reabrir durante
  // a animação de saída reaproveita o conteúdo ainda montado.
  const [openings, setOpenings] = useState(0);
  const [wasOpen, setWasOpen] = useState(open);

  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) setOpenings(openings + 1);
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <ProofUploadForm key={openings} onClose={onClose} {...formProps} />
    </Dialog>
  );
};

const ProofUploadForm: React.FC<Omit<ProofUploadDialogProps, 'open'>> = ({
  title,
  description,
  selectLabel,
  icon,
  busy = false,
  error,
  onClose,
  onConfirm
}) => {
  const [file, setFile] = useState<File | null>(null);

  return (
    <>
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>
        <DialogContentText mb={2}>{description}</DialogContentText>
        <Button variant="outlined" component="label" fullWidth startIcon={icon}>
          {file ? file.name : selectLabel}
          <input
            type="file"
            accept="image/*"
            capture="environment"
            hidden
            onChange={e => setFile(e.target.files?.[0] ?? null)}
          />
        </Button>
        {error && (
          <Alert severity="error" sx={{ mt: 2 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>
          Cancelar
        </Button>
        <Button variant="contained" disabled={!file || busy} onClick={() => file && onConfirm(file)}>
          Confirmar
        </Button>
      </DialogActions>
    </>
  );
};

export default ProofUploadDialog;
