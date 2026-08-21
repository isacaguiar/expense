import React, { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import {
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  TextField,
  Typography
} from '@mui/material';
import axios from 'axios';
import { API_BASE_URL } from '../config';

// Tipo do grupo conforme API
type Group = {
  id: number;
  name: string;
  description: string;
  create_date: string;
  closing_day: number | null;
};

const GroupForm: React.FC = () => {
  const { id } = useParams<{ id?: string }>();
  const isEdit = Boolean(id);
  const navigate = useNavigate();

  const [name, setName] = useState<string>('');
  const [description, setDescription] = useState<string>('');
  const [closingDay, setClosingDay] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isEdit && id) {
      setLoading(true);
      const token = localStorage.getItem('accessToken');
      axios
        .get<Group>(`${API_BASE_URL}/api/groups/${id}`, {
          headers: { Authorization: token ? `Bearer ${token}` : '' }
        })
        .then(res => {
          setName(res.data.name);
          setDescription(res.data.description);
          setClosingDay(res.data.closing_day != null ? String(res.data.closing_day) : '');
        })
        .catch(() => {
          setError('Falha ao carregar dados do grupo.');
        })
        .finally(() => {
          setLoading(false);
        });
    }
  }, [id, isEdit]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const token = localStorage.getItem('accessToken');
    const payload = {
      name,
      description,
      closing_day: closingDay === '' ? null : Number(closingDay)
    };
    const config = {
      headers: {
        Authorization: token ? `Bearer ${token}` : '',
        'Content-Type': 'application/json'
      }
    };

    try {
      let response;
      if (isEdit && id) {
        response = await axios.put(`${API_BASE_URL}/api/groups/${id}`, payload, config);
      } else {
        response = await axios.post(`${API_BASE_URL}/api/groups`, payload, config);
      }
      const groupId = (response.data as Group).id;
      navigate('/dashboard');
    } catch {
      setError('Erro ao salvar grupo. Tente novamente.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" mt={4}>
        <CircularProgress />
      </Box>
    );
  }

  return (
    <Card elevation={3} sx={{ borderRadius: 2, maxWidth: 520, mx: 'auto' }}>
      <CardContent sx={{ p: 4 }}>
        <Typography variant="h6" gutterBottom>
          {isEdit ? 'Editar Grupo' : 'Criar Novo Grupo'}
        </Typography>

        {error && <Typography color="error" sx={{ mb: 2 }}>{error}</Typography>}

        <Box component="form" onSubmit={handleSubmit} sx={{ mt: 2 }}>
          <TextField
            label="Nome"
            value={name}
            onChange={e => setName(e.target.value)}
            fullWidth
            margin="normal"
            required
          />
          <TextField
            label="Descrição"
            value={description}
            onChange={e => setDescription(e.target.value)}
            fullWidth
            margin="normal"
            required
            multiline
            rows={4}
          />
          <TextField
            label="Dia de fechamento (opcional)"
            type="number"
            value={closingDay}
            onChange={e => setClosingDay(e.target.value)}
            fullWidth
            margin="normal"
            helperText="Dia do mês em que o ciclo de despesas do grupo fecha (estilo fatura de cartão). Deixe em branco para usar o mês calendário."
            inputProps={{ min: 1, max: 31 }}
          />
          <Box sx={{ mt: 3, display: 'flex', gap: 2 }}>
            <Button
              type="submit"
              variant="contained"
              color="primary"
              disabled={submitting}
            >
              {isEdit ? 'Atualizar Grupo' : 'Criar Grupo'}
            </Button>
            <Button
              variant="outlined"
              onClick={() => navigate('/dashboard')}
              disabled={submitting}
            >
              Cancelar
            </Button>
          </Box>
        </Box>
      </CardContent>
    </Card>
  );
};

export default GroupForm;
