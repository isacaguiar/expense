import React from 'react';
import { AppBar, Toolbar, Typography, Button, Box } from '@mui/material';
import { Link as RouterLink, useNavigate } from 'react-router-dom';

const Navbar = () => {
  const navigate = useNavigate();

  const handleLogout = () => {
    localStorage.clear();
    navigate('/');
  };

  return (
    <AppBar position="static" color="primary">
      <Toolbar>
        <Typography variant="h6" component={RouterLink} to="/dashboard" sx={{ flexGrow: 1, textDecoration: 'none', color: 'inherit' }}>
          SCD - Despesas Compartilhadas
        </Typography>
        <Box>
          <Button color="inherit" component={RouterLink} to="/dashboard">Dashboard</Button>
          <Button color="inherit" component={RouterLink} to="/groups">Grupos</Button>
          <Button color="inherit" component={RouterLink} to="/expenses">Despesas</Button>
          <Button color="inherit" onClick={handleLogout}>Sair</Button>
        </Box>
      </Toolbar>
    </AppBar>
  );
};

export default Navbar;
