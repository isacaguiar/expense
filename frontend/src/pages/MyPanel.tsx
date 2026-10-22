import React from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, CircularProgress, Stack, Typography } from '@mui/material';
import GroupPositionCard from '../components/GroupPositionCard';
import { useMyOverview } from '../hooks/useMyOverview';
import DespesasThemeScope from '../theme/DespesasThemeScope';

/**
 * Painel financeiro pessoal (`/painel`): para cada grupo do usuário, o ciclo em
 * foco com o que ele deve, o que tem a receber e os itens dele, e as ações de
 * confirmar pagamento. Tudo vem pronto de `GET /api/me/overview`: a página só
 * exibe, na ordem que o servidor mandou (com algo em aberto primeiro), e
 * recarrega depois de cada ação.
 *
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §2
 */
const MyPanel: React.FC = () => {
  const { data, error, reload } = useMyOverview();

  // Primeira carga, ou nova tentativa depois de um erro: ainda não há o que mostrar.
  // Um `reload` com dados na tela não cai aqui, para os cartões não piscarem.
  const loading = data === null && error === null;

  return (
    <DespesasThemeScope>
      {loading && (
        <Box display="flex" justifyContent="center" mt={4}>
          <CircularProgress />
        </Box>
      )}

      {error && (
        <Alert
          severity="error"
          sx={{ mb: 2 }}
          action={
            <Button color="inherit" size="small" onClick={reload}>
              Tentar novamente
            </Button>
          }
        >
          {error}
        </Alert>
      )}

      {data && (
        <>
          {data.truncated && (
            <Alert severity="info" sx={{ mb: 2 }}>
              Você participa de mais de 50 grupos. Aqui aparecem os 50 mais antigos; os demais estão em Meus Grupos.
            </Alert>
          )}

          {data.groups.length === 0 ? (
            <Box textAlign="center" py={4}>
              <Typography color="text.secondary" gutterBottom>
                Você ainda não participa de nenhum grupo.
              </Typography>
              <Button component={RouterLink} to="/meus-grupos">
                Ir para Meus Grupos
              </Button>
            </Box>
          ) : (
            <Stack spacing={2}>
              {data.groups.map(overview => (
                <GroupPositionCard
                  key={overview.group.id}
                  overview={overview}
                  currentUser={data.user}
                  onChanged={reload}
                />
              ))}
            </Stack>
          )}
        </>
      )}
    </DespesasThemeScope>
  );
};

export default MyPanel;
