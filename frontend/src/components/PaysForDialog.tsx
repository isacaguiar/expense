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

type PaysForDialogProps = {
  open: boolean;
  groupId: string;
  /** Id do usuário logado: é quem se declara, e só mexe na própria lista (a rota não tem `userId`). */
  currentUserId: number;
  /** Todos os membros do grupo; o próprio usuário é excluído das opções aqui. */
  members: GroupMember[];
  /** Por quem o usuário já paga neste grupo (os membros cuja lista de `responsibles` o inclui). */
  current: MemberResponsible[];
  onClose: () => void;
  /** Chamado depois de salvar, com o aviso de sucesso a mostrar ("Escolha salva."). */
  onChanged: (message: string) => void;
};

/**
 * "Eu pago por…": o usuário declara por quais pessoas do grupo ele paga, sem o aceite delas (o caso de quem
 * não usa o aplicativo). Um único `PUT .../me/pays-for` com a lista inteira; esvaziar o seletor e salvar larga
 * todas. O credor ainda decide sobre cada comprovante enviado por outra pessoa.
 * docs/feature/20261110-quem-paga-se-declara-e-credor-recusa/plan.md §10
 */
const PaysForDialog: React.FC<PaysForDialogProps> = ({
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
    const currentIds = new Set(current.map(person => person.id));
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

    try {
      await axios.put(
        `${API_BASE_URL}/api/groups/${groupId}/me/pays-for`,
        { debtor_user_ids: selected.map(option => option.id) },
        { headers: { Authorization: token ? `Bearer ${token}` : '' } }
      );
    } catch {
      setError(SAVE_ERROR);
      setSaving(false);
      return;
    }

    setSaving(false);
    onChanged('Escolha salva.');
    onClose();
  };

  const handleClose = () => {
    if (!saving) onClose();
  };

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="sm">
      <DialogTitle>Por quem você paga</DialogTitle>
      <DialogContent>
        {options.length === 0 ? (
          <DialogContentText>
            Não há outros membros neste grupo ainda. Convide alguém para poder escolher.
          </DialogContentText>
        ) : (
          <>
            <DialogContentText sx={{ mb: 1 }}>
              Você poderá enviar o comprovante dos pagamentos das pessoas escolhidas neste grupo, mesmo que elas não
              usem o aplicativo. Elas são avisadas e podem desfazer isso em "Quem paga por mim".
            </DialogContentText>
            <DialogContentText sx={{ mb: 2 }}>
              O credor continua decidindo: ele precisa aceitar o comprovante que você enviar por elas.
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
              renderInput={params => <TextField {...params} label="Eu pago por" />}
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

export default PaysForDialog;
