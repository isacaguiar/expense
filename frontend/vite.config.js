console.log('🛠️  Carregando vite.config.js…')

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [
    react()
  ],
  server: {
    port: 3000,
    host: true,
    proxy: {
      // todo /auth/* vai para o Laravel em 8000, sem CORS
      '/auth': {
        target: 'http://localhost:8000',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/setupTests.ts'],
    // Node 22+ expõe `localStorage`/`sessionStorage` globais nativos (flag
    // --experimental-webstorage) que colidem com o polyfill do jsdom e
    // lançam SecurityError ao acessar localStorage nos testes. Desliga a
    // flag nos processos worker do Vitest, sem depender de NODE_OPTIONS
    // no shell (cross-platform).
    execArgv: ['--no-experimental-webstorage'],
    // Padrão do Vitest é 5000ms — curto demais quando a suíte completa roda
    // em paralelo sob CPU compartilhada (runners do GitHub Actions têm só
    // 2 vCPUs), causando timeouts intermitentes sem relação com o código
    // testado (confirmado: arquivos que "flakam" na suíte completa passam
    // 100% quando rodados isolados). Timeout maior dá margem sem mascarar
    // um hang real (segundos, não minutos).
    testTimeout: 15000,
    hookTimeout: 15000,
  },
});


/*import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000
  }
});*/
