import React, { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Divider,
  List,
  ListItemButton,
  ListItemText,
  Menu,
  Snackbar,
  Typography
} from '@mui/material';
import { API_BASE_URL } from '../config';
import { formatRelative, notificationText } from './notificationText';

type NotificationItem = {
  id: number;
  type: string;
  group_id: number | null;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
};

interface NotificationsMenuProps {
  anchorEl: HTMLElement | null;
  open: boolean;
  onClose: () => void;
  /** Recarrega o contador de não-lidas do sino após marcar algo como lido. */
  onRead: () => void;
}

/**
 * Lista de notificações aberta pelo sino do cabeçalho. Busca a 1ª página de
 * `GET /api/notifications` só quando abre; permite marcar todas como lidas e,
 * ao clicar num item, navega para o grupo dele e marca aquele item como lido.
 * Ver docs/feature/20260903-notificacoes-in-app/plan.md §4.
 */
const NotificationsMenu: React.FC<NotificationsMenuProps> = ({ anchorEl, open, onClose, onRead }) => {
  const navigate = useNavigate();

  const [items, setItems] = useState<NotificationItem[]>([]);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [snack, setSnack] = useState<string | null>(null);

  const authHeader = () => {
    const token = localStorage.getItem('accessToken');
    return { Authorization: token ? `Bearer ${token}` : '' };
  };

  const fetchList = useCallback(() => {
    setLoading(true);
    setError(null);

    axios
      .get<{ data: NotificationItem[] }>(`${API_BASE_URL}/api/notifications`, {
        headers: authHeader(),
        params: { page: 1 }
      })
      .then(res => setItems(res.data.data))
      .catch(() => setError('Não foi possível carregar as notificações.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (open) {
      fetchList();
    }
  }, [open, fetchList]);

  const hasUnread = items.some(item => item.read_at === null);

  const handleMarkAllRead = () => {
    axios
      .post(`${API_BASE_URL}/api/notifications/read`, null, { headers: authHeader() })
      .then(() => {
        onRead();
        fetchList();
      })
      .catch(() => setSnack('Não foi possível marcar as notificações como lidas.'));
  };

  const handleItemClick = (item: NotificationItem) => {
    axios
      .post(`${API_BASE_URL}/api/notifications/read`, { id: item.id }, { headers: authHeader() })
      .then(() => onRead())
      .catch(() => {
        // best-effort: navegar é o que importa no clique
      });

    const groupId = item.data?.groupId ?? item.group_id;
    if (groupId != null) {
      navigate(`/groups/${groupId}/summary`);
    }
    onClose();
  };

  return (
    <>
      <Menu
        anchorEl={anchorEl}
        open={open}
        onClose={onClose}
        slotProps={{ paper: { sx: { width: 360, maxWidth: '100vw' } } }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1 }}>
          <Typography variant="subtitle1" sx={{ fontWeight: 'bold' }}>
            Notificações
          </Typography>
          <Button size="small" onClick={handleMarkAllRead} disabled={!hasUnread}>
            Marcar todas como lidas
          </Button>
        </Box>

        <Divider />

        {loading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
            <CircularProgress size={22} />
          </Box>
        ) : error ? (
          <Alert severity="error" sx={{ m: 2 }}>
            {error}
          </Alert>
        ) : items.length === 0 ? (
          <Typography sx={{ px: 2, py: 3, color: 'text.secondary' }}>Nenhuma notificação.</Typography>
        ) : (
          <List disablePadding sx={{ maxHeight: 400, overflowY: 'auto' }}>
            {items.map(item => (
              <ListItemButton
                key={item.id}
                divider
                onClick={() => handleItemClick(item)}
                sx={{ bgcolor: item.read_at ? 'transparent' : 'action.hover' }}
              >
                <ListItemText
                  primary={notificationText(item.type, item.data ?? {})}
                  secondary={formatRelative(item.created_at)}
                  slotProps={{
                    primary: { sx: { fontSize: '0.9rem', fontWeight: item.read_at ? 'normal' : 'bold' } }
                  }}
                />
              </ListItemButton>
            ))}
          </List>
        )}
      </Menu>

      <Snackbar open={Boolean(snack)} autoHideDuration={4000} onClose={() => setSnack(null)}>
        <Alert severity="error" variant="filled" onClose={() => setSnack(null)}>
          {snack}
        </Alert>
      </Snackbar>
    </>
  );
};

export default NotificationsMenu;
