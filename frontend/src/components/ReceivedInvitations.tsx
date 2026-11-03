import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import { Alert, Box, Button, Card, CardContent, Divider, Snackbar, Stack, Typography } from '@mui/material';
import { API_BASE_URL } from '../config';
import type { ReceivedGroupInvitation } from '../types/group';

type Notice = { message: string; severity: 'success' | 'info' | 'error' };

const UNAVAILABLE = 'Este convite não está mais disponível.';
const GENERIC_ERROR = 'Não foi possível responder ao convite agora. Tente novamente.';

/** Só o que a tela precisa para desenhar o convite: descarta o que não tem essa forma. */
const isReceivedInvitation = (item: unknown): item is ReceivedGroupInvitation => {
  const candidate = item as Partial<ReceivedGroupInvitation> | null;
  return (
    typeof candidate === 'object' &&
    candidate !== null &&
    typeof candidate.id === 'number' &&
    typeof candidate.group === 'object' &&
    candidate.group !== null &&
    typeof candidate.group.id === 'number' &&
    typeof candidate.group.name === 'string'
  );
};

/**
 * Convites que o usuário recebeu para entrar em grupos, em Meus Grupos (feature
 * 20261103-convite-pendente-no-grupo, plan §10). Autocontido: busca, lista e responde.
 *
 * - **Aceitar** faz o `POST` e leva ao grupo, onde a pessoa já é membro.
 * - **Recusar** faz o `POST` e tira o convite da lista.
 * - Um 404 em qualquer dos dois (vencido, cancelado, grupo excluído, já respondido) avisa
 *   "Este convite não está mais disponível." e tira o convite da lista; qualquer outro erro
 *   avisa e mantém o convite, com os botões de volta.
 *
 * Best effort: se a busca falhar, vier algo que não seja uma lista ou a lista for vazia, não
 * mostra nada. Um problema aqui não pode quebrar Meus Grupos.
 */
const ReceivedInvitations: React.FC = () => {
  const navigate = useNavigate();

  const [invitations, setInvitations] = useState<ReceivedGroupInvitation[]>([]);
  const [busyIds, setBusyIds] = useState<Set<number>>(new Set());
  const [notice, setNotice] = useState<Notice | null>(null);

  const authHeaders = () => {
    const token = localStorage.getItem('accessToken');
    return { Authorization: token ? `Bearer ${token}` : '' };
  };

  useEffect(() => {
    let active = true;

    axios
      .get<unknown>(`${API_BASE_URL}/api/me/invitations`, { headers: authHeaders() })
      .then(res => {
        if (active && Array.isArray(res.data)) {
          setInvitations(res.data.filter(isReceivedInvitation));
        }
      })
      .catch(() => {
        // best effort: sem a lista, Meus Grupos segue como sempre foi
      });

    return () => {
      active = false;
    };
  }, []);

  const setBusy = (id: number, busy: boolean) =>
    setBusyIds(prev => {
      const next = new Set(prev);
      if (busy) {
        next.add(id);
      } else {
        next.delete(id);
      }
      return next;
    });

  const removeInvitation = (id: number) => setInvitations(prev => prev.filter(invitation => invitation.id !== id));

  const respond = async (invitation: ReceivedGroupInvitation, action: 'accept' | 'decline') => {
    if (busyIds.has(invitation.id)) return;

    setBusy(invitation.id, true);
    try {
      await axios.post(`${API_BASE_URL}/api/me/invitations/${invitation.id}/${action}`, null, { headers: authHeaders() });
    } catch (err) {
      const status = (err as { response?: { status?: number } } | null)?.response?.status;
      if (status === 404) {
        removeInvitation(invitation.id);
        setNotice({ message: UNAVAILABLE, severity: 'info' });
      } else {
        setNotice({ message: GENERIC_ERROR, severity: 'error' });
      }
      setBusy(invitation.id, false);
      return;
    }

    if (action === 'accept') {
      navigate(`/groups/${invitation.group.id}/summary`);
      return;
    }

    removeInvitation(invitation.id);
    setBusy(invitation.id, false);
    setNotice({ message: 'Convite recusado.', severity: 'success' });
  };

  return (
    <>
      {invitations.length > 0 && (
        <Card variant="outlined" sx={{ mb: 3, borderRadius: 2 }}>
          <CardContent>
            <Typography variant="subtitle1" sx={{ fontWeight: 'bold', mb: 1.5 }}>
              Convites recebidos
            </Typography>
            <Stack spacing={1.5} divider={<Divider flexItem />}>
              {invitations.map(invitation => {
                const busy = busyIds.has(invitation.id);
                const groupName = invitation.group.name;

                return (
                  <Box
                    key={invitation.id}
                    display="flex"
                    alignItems="center"
                    justifyContent="space-between"
                    gap={2}
                    flexWrap="wrap"
                  >
                    <Typography variant="body2" sx={{ minWidth: 0, overflowWrap: 'anywhere' }}>
                      {`${invitation.invited_by?.name ?? 'Alguém'} convidou você para o grupo "${groupName}"`}
                    </Typography>
                    <Box display="flex" gap={1} sx={{ flexShrink: 0 }}>
                      <Button
                        size="small"
                        variant="contained"
                        disabled={busy}
                        onClick={() => respond(invitation, 'accept')}
                        aria-label={`Aceitar convite do grupo ${groupName}`}
                      >
                        Aceitar
                      </Button>
                      <Button
                        size="small"
                        variant="outlined"
                        disabled={busy}
                        onClick={() => respond(invitation, 'decline')}
                        aria-label={`Recusar convite do grupo ${groupName}`}
                      >
                        Recusar
                      </Button>
                    </Box>
                  </Box>
                );
              })}
            </Stack>
          </CardContent>
        </Card>
      )}

      <Snackbar
        open={notice !== null}
        autoHideDuration={4000}
        onClose={() => setNotice(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert onClose={() => setNotice(null)} severity={notice?.severity ?? 'info'} variant="filled">
          {notice?.message}
        </Alert>
      </Snackbar>
    </>
  );
};

export default ReceivedInvitations;
