import React, { useEffect, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  FormControlLabel,
  Link as MuiLink,
  TextField
} from '@mui/material';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';

/** Limite da descrição; o servidor valida o mesmo valor (`max:500`). */
export const NOTE_MAX_LENGTH = 500;

type CanConfirmInput = {
  requirePhoto: boolean;
  hasFile: boolean;
  note: string;
  busy: boolean;
  /** Só na edição: a despesa já tem uma foto gravada. */
  hasCurrentProof?: boolean;
  /** Só na edição: o usuário marcou "Remover a foto atual". */
  removeProof?: boolean;
  /** Só na edição: `false` quando nada difere do que já está gravado. */
  changed?: boolean;
};

/**
 * Regra de habilitação do botão Confirmar (docs/feature/concluidas/202610/20261021-comprovante-ao-marcar-paga,
 * specify R1/R2): com a foto opcional, basta foto OU descrição não vazia; com
 * a foto obrigatória (acerto do devedor), só a foto conta e o texto é um
 * complemento. Nunca confirma enquanto `busy`.
 *
 * Na edição de uma despesa já paga (docs/feature/20261022-editar-comprovante-depois-de-paga,
 * plan §4) a regra vale sobre o **estado final**: a foto atual conta enquanto
 * não for removida, e uma nova a substitui. Remover a foto sem deixar descrição
 * é recusado, porque o backend recusa (422) um pagamento sem foto e sem texto.
 * `changed === false` desabilita o botão: não há o que salvar. Os três campos
 * novos são opcionais e, ausentes, a regra é a de sempre.
 */
export const canConfirmPayment = ({
  requirePhoto,
  hasFile,
  note,
  busy,
  hasCurrentProof = false,
  removeProof = false,
  changed
}: CanConfirmInput): boolean => {
  if (busy) return false;
  if (changed === false) return false;
  const hasFinalPhoto = hasFile || (hasCurrentProof && !removeProof);
  if (requirePhoto) return hasFinalPhoto;
  return hasFinalPhoto || note.trim() !== '';
};

/**
 * `removeProof` só vem preenchido no modo de edição, para os usos antigos
 * continuarem recebendo exatamente `{ file, note }`.
 */
export type PaymentProofValue = { file: File | null; note: string; removeProof?: boolean };

interface PaymentProofDialogProps {
  open: boolean;
  title: string;
  /** Texto de ajuda acima dos campos. */
  description: string;
  /** Texto do botão de foto enquanto nenhuma foi escolhida. */
  photoLabel: string;
  /** `true`: a foto é obrigatória e a descrição é só complemento. */
  requirePhoto: boolean;
  /** Texto do botão de confirmar. Padrão: "Confirmar". */
  confirmLabel?: string;
  /**
   * Descrição já gravada, para **editar** um pagamento existente. Passar esta
   * prop ou `currentProofUrl` (mesmo vazia/`null`) liga o modo de edição: a
   * descrição abre preenchida, aparece "Remover a foto atual" quando há foto, e
   * o botão só habilita se algo mudar. Uma despesa paga sem foto nem descrição
   * (paga antes do popup existir) passa `initialNote=""` e `currentProofUrl={null}`.
   */
  initialNote?: string;
  /** URL assinada da foto já gravada (`null` = não há). Liga o modo de edição. */
  currentProofUrl?: string | null;
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onConfirm: (value: PaymentProofValue) => void;
}

/**
 * Popup único de comprovante de pagamento: seletor de foto + descrição em
 * texto livre. Usado por Despesas (✓ "Marcar como paga"), por Pagamentos
 * (credor) e pelo acerto do devedor, e, no modo de edição, por "Editar
 * comprovante" nessas duas telas. Quem usa decide se fecha o popup ao
 * confirmar (credor) ou só depois do sucesso (devedor, com `busy`/`error`).
 * A descrição vira texto puro na tela — nunca HTML.
 */
const PaymentProofDialog: React.FC<PaymentProofDialogProps> = ({
  open,
  title,
  description,
  photoLabel,
  requirePhoto,
  confirmLabel = 'Confirmar',
  initialNote,
  currentProofUrl,
  busy = false,
  error = null,
  onClose,
  onConfirm
}) => {
  const editing = initialNote !== undefined || currentProofUrl !== undefined;
  const hasCurrentProof = !!currentProofUrl;

  const [file, setFile] = useState<File | null>(null);
  const [note, setNote] = useState<string>(initialNote ?? '');
  const [removeProof, setRemoveProof] = useState<boolean>(false);
  // Muda quando a foto escolhida é descartada pelo checkbox: recria o <input>
  // nativo, que senão guardaria o arquivo e não dispararia `change` ao escolher
  // o mesmo arquivo de novo.
  const [inputKey, setInputKey] = useState<number>(0);

  // Cada abertura começa do estado gravado (em branco, fora da edição). Só `open`
  // dispara: se o pai recarregar a lista com o popup aberto, o que o usuário está
  // digitando não é sobrescrito.
  useEffect(() => {
    if (open) {
      setFile(null);
      setNote(initialNote ?? '');
      setRemoveProof(false);
    }
  }, [open]);

  const changed = editing
    ? file !== null || removeProof || note.trim() !== (initialNote ?? '').trim()
    : undefined;

  const canConfirm = canConfirmPayment({
    requirePhoto,
    hasFile: file !== null,
    note,
    busy,
    hasCurrentProof,
    removeProof,
    changed
  });

  const handleConfirm = () => {
    if (!canConfirm) return;
    onConfirm({ file, note: note.trim(), ...(editing ? { removeProof } : {}) });
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files?.[0] ?? null;
    setFile(chosen);
    // Escolher uma foto nova e remover a atual não se combinam: o backend recusa.
    if (chosen) setRemoveProof(false);
  };

  const handleRemoveToggle = (checked: boolean) => {
    setRemoveProof(checked);
    if (checked) {
      setFile(null);
      setInputKey(k => k + 1);
    }
  };

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>{title}</DialogTitle>
      <DialogContent>
        <DialogContentText mb={2}>{description}</DialogContentText>

        {hasCurrentProof && (
          <Box mb={2}>
            <MuiLink href={currentProofUrl ?? undefined} target="_blank" rel="noreferrer" variant="body2">
              Ver foto atual
            </MuiLink>
            <FormControlLabel
              sx={{ display: 'block' }}
              control={
                <Checkbox
                  checked={removeProof}
                  disabled={busy}
                  onChange={e => handleRemoveToggle(e.target.checked)}
                />
              }
              label="Remover a foto atual"
            />
          </Box>
        )}

        <Button variant="outlined" component="label" fullWidth startIcon={<PhotoCameraOutlinedIcon />}>
          {file ? file.name : photoLabel}
          <input
            key={inputKey}
            type="file"
            accept="image/*"
            capture="environment"
            hidden
            onChange={handleFileChange}
          />
        </Button>

        <TextField
          label={requirePhoto ? 'Descrição (opcional)' : 'Descrição'}
          value={note}
          onChange={e => setNote(e.target.value)}
          multiline
          minRows={2}
          maxRows={5}
          fullWidth
          margin="normal"
          inputProps={{ maxLength: NOTE_MAX_LENGTH }}
          helperText={`${note.length}/${NOTE_MAX_LENGTH}`}
        />

        {error && (
          <Alert severity="error" sx={{ mt: 1 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={busy}>
          Cancelar
        </Button>
        <Button variant="contained" disabled={!canConfirm} onClick={handleConfirm}>
          {confirmLabel}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default PaymentProofDialog;
