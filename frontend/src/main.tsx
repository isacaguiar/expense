import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import { ThemeProvider } from '@mui/material/styles';
import { CssBaseline } from '@mui/material';
import { BrowserRouter } from 'react-router-dom';
import theme from './theme';
import { initAnalytics } from './analytics/consent';

// Antes de renderizar: publica o estado padrão do Consent Mode (negado) e só
// carrega o Google Analytics se já houver consentimento gravado.
initAnalytics();

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <BrowserRouter basename="/app">
        <App />
      </BrowserRouter>
    </ThemeProvider>
  </React.StrictMode>,
);
