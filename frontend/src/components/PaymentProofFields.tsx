import React from 'react';
import { Button, TextField } from '@mui/material';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';

/** Limite da descrição; o servidor valida o mesmo valor (`max:500`). */
export const NOTE_MAX_LENGTH = 500;

interface PaymentProofFieldsProps {
  file: File | null;
  onFileChange: (file: File | null) => void;
  note: string;
  onNoteChange: (note: string) => void;
  /** Texto do botão de foto enquanto nenhuma foi escolhida. */
  photoLabel: string;
  noteLabel: string;
  /**
   * Muda quando quem usa descarta a foto por fora (ex.: "Remover a foto atual"): recria o
   * <input> nativo, que senão guardaria o arquivo e não dispararia `change` ao escolher o
   * mesmo arquivo de novo.
   */
  inputKey?: number | string;
  disabled?: boolean;
}

/**
 * Seletor de foto + descrição do pagamento, usados pelo popup de comprovante
 * (`PaymentProofDialog`) e pela seção "Pagamento (opcional)" do cadastro de despesa
 * (docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa, plan §6). Só apresentação:
 * a regra de quando o pagamento vale (foto OU descrição não vazia) fica com quem usa.
 */
const PaymentProofFields: React.FC<PaymentProofFieldsProps> = ({
  file,
  onFileChange,
  note,
  onNoteChange,
  photoLabel,
  noteLabel,
  inputKey = 0,
  disabled = false
}) => (
  <>
    <Button variant="outlined" component="label" fullWidth disabled={disabled} startIcon={<PhotoCameraOutlinedIcon />}>
      {file ? file.name : photoLabel}
      {/* Sem `capture`: no Safari do iOS ele abre só a câmera, sem biblioteca de fotos nem
          arquivos (docs/bugfix/concluidos/202610/20261027-comprovante-iphone-so-camera.md). */}
      <input
        key={inputKey}
        type="file"
        accept="image/*"
        hidden
        disabled={disabled}
        onChange={e => onFileChange(e.target.files?.[0] ?? null)}
      />
    </Button>

    <TextField
      label={noteLabel}
      value={note}
      onChange={e => onNoteChange(e.target.value)}
      multiline
      minRows={2}
      maxRows={5}
      fullWidth
      margin="normal"
      disabled={disabled}
      inputProps={{ maxLength: NOTE_MAX_LENGTH }}
      helperText={`${note.length}/${NOTE_MAX_LENGTH}`}
    />
  </>
);

export default PaymentProofFields;
