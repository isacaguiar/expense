import React, { useEffect, useState } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle } from '@mui/material';
import PaymentProofFields from './PaymentProofFields';

type SettlementComplementDialogProps = {
  open: boolean;
  // O nome de quem deve (do acerto), para a explicação.
  debtorName: string;
  busy: boolean;
  error?: string | null;
  onCancel: () => void;
  onSubmit: (proof: File, justification: string) => void;
};

/**
 * "Adicionar justificativa" (feature 20261114-historico-de-comprovantes-e-justificativa, plan §11): quem paga
 * pelo devedor anexa uma foto e escreve a justificativa sobre o comprovante que o **próprio devedor** enviou.
 * O comprovante atual continua valendo (R3). "Enviar" só habilita com a foto **e** a justificativa não vazia
 * (até 500 caracteres, o mesmo limite do servidor); a justificativa é sempre exibida como texto puro.
 */
const SettlementComplementDialog: React.FC<SettlementComplementDialogProps> = ({
  open,
  debtorName,
  busy,
  error = null,
  onCancel,
  onSubmit
}) => {
  const [file, setFile] = useState<File | null>(null);
  const [justification, setJustification] = useState<string>('');

  // Cada abertura começa vazia; só `open` dispara, para uma atualização do pai não apagar o que se digita.
  useEffect(() => {
    if (open) {
      setFile(null);
      setJustification('');
    }
  }, [open]);

  const canSend = !busy && file !== null && justification.trim() !== '';

  return (
    <Dialog open={open} onClose={busy ? undefined : onCancel} fullWidth maxWidth="xs">
      <DialogTitle>Adicionar justificativa</DialogTitle>
      <DialogContent>
        <DialogContentText mb={2}>
          {`Use quando o comprovante enviado por ${debtorName} está errado ou incompleto. O comprovante atual continua valendo; o credor e ${debtorName} são avisados.`}
        </DialogContentText>

        <PaymentProofFields
          file={file}
          onFileChange={setFile}
          note={justification}
          onNoteChange={setJustification}
          photoLabel="Escolher foto do comprovante"
          noteLabel="Justificativa"
          disabled={busy}
        />

        {error && (
          <Alert severity="error" sx={{ mt: 1 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel} disabled={busy}>
          Cancelar
        </Button>
        <Button
          variant="contained"
          disabled={!canSend}
          onClick={() => {
            if (canSend && file) onSubmit(file, justification.trim());
          }}
        >
          Enviar
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default SettlementComplementDialog;
