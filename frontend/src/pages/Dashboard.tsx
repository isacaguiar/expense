import React from 'react';
import { Button, TextField, Container, Typography } from '@mui/material';

const Dashboard: React.FC = () => {
  return (
    <Container maxWidth="sm" sx={{ mt: 4 }}>
      <Typography variant="h4" gutterBottom>
        Dashboard
      </Typography>
      <TextField label="Buscar" fullWidth margin="normal" />
      <Button variant="contained" color="primary">
        Enviar
      </Button>
    </Container>
  );
};

export default Dashboard;
