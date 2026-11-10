import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  TextField,
  Typography
} from '@mui/material';
import axios from 'axios';
import { API_BASE_URL } from '../config';
import type { GroupMember, MemberResponsible } from '../types/group';

// O projeto não configura o locale do MUI; sem estes textos o Autocomplete aparece em inglês.
const AUTOCOMPLETE_TEXTS = {
  noOptionsText: 'Nenhuma opção',
  clearText: 'Limpar',
  openText: 'Abrir',
  closeText: 'Fechar'
};

const SAVE_ERROR = 'Não foi possível salvar agora. Tente novamente.';

type PaymentResponsibleDialogProps = {
  open: boolean;
  groupId: string;
  /** Id do usuário logado: é o devedor, e só ele escolhe quem paga por ele. */
  currentUserId: number;
  /** Todos os membros do grupo; o próprio usuário é excluído das opções aqui. */
  members: GroupMember[];
  /** Quem já paga pelo usuário neste grupo (`responsibles` da linha dele em `GET /members`). */
  current: MemberResponsible[];
  onClose: () => void;
  /** Chamado depois de salvar, com o aviso de sucesso a mostrar ("Escolha salva." ou "Escolha desfeita."). */
  onChanged: (message: string) => void;
};

/**
 * "Quem pode pagar por você": o usuário escolhe nenhuma, uma ou várias pessoas do grupo que também
 * podem enviar o comprovante dos acertos dele. Um único `PUT .../responsibles` com a lista inteira;
 * não há botão de desfazer: esvaziar o seletor e salvar desfaz tudo.
 * docs/feature/concluidas/202611/20261109-responsavel-do-devedor/plan.md §9
 */
const PaymentResponsibleDialog: React.FC<PaymentResponsibleDialogProps> = ({
  open,
  groupId,
  currentUserId,
  members,
  current,
  onClose,
  onChanged
}) => {
  const options = useMemo(() => members.filter(member => member.id !== currentUserId), [members, currentUserId]);

  const initialSelection = () => {
    const currentIds = new Set(current.map(responsible => responsible.id));
    return options.filter(option => currentIds.has(option.id));
  };

  const [selected, setSelected] = useState<GroupMember[]>(initialSelection);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Cada abertura parte do que o servidor tem agora: descarta escolha não salva e erro anterior.
  useEffect(() => {
    if (!open) return;
    setSelected(initialSelection());
    setSaving(false);
    setError(null);
  }, [open]);

  const handleSave = async () => {
    if (saving) return;

    setSaving(true);
    setError(null);
    const token = localStorage.getItem('accessToken');
    const ids = selected.map(option => option.id);

    try {
      await axios.put(
        `${API_BASE_URL}/api/groups/${groupId}/members/${currentUserId}/responsibles`,
        { responsible_user_ids: ids },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      );
    } catch {
      setError(SAVE_ERROR);
      setSaving(false);
      return;
    }

    setSaving(false);
    onChanged(ids.length > 0 ? 'Escolha salva.' : 'Escolha desfeita.');
    onClose();
  };

  const handleClose = () => {
    if (!saving) onClose();
  };

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="sm">
      <DialogTitle>Quem pode pagar por você</DialogTitle>
      <DialogContent>
        {options.length === 0 ? (
          <DialogContentText>
            Não há outros membros neste grupo ainda. Convide alguém para poder escolher.
          </DialogContentText>
        ) : (
          <>
            <DialogContentText sx={{ mb: 2 }}>
              As pessoas escolhidas poderão enviar o comprovante dos seus pagamentos neste grupo, além de você. Elas
              não passam a ver nada que não vejam hoje como membros.
            </DialogContentText>
            <Autocomplete
              multiple
              disableCloseOnSelect
              {...AUTOCOMPLETE_TEXTS}
              options={options}
              value={selected}
              disabled={saving}
              getOptionLabel={option => option.name}
              isOptionEqualToValue={(option, value) => option.id === value.id}
              onChange={(_, values) => setSelected(values)}
              renderOption={({ key, ...props }, option) => (
                <li key={key} {...props}>
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="body2">{option.name}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                      {option.email}
                    </Typography>
                  </Box>
                </li>
              )}
              renderInput={params => <TextField {...params} label="Quem paga por você" />}
            />
          </>
        )}
        {error && (
          <Alert severity="error" sx={{ mt: 2 }}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={handleClose} disabled={saving}>
          Cancelar
        </Button>
        <Button onClick={handleSave} variant="contained" disabled={saving || options.length === 0}>
          Salvar
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default PaymentResponsibleDialog;
