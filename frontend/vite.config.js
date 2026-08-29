console.log('🛠️  Carregando vite.config.js…')

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// --experimental-webstorage só existe a partir do Node 22.4 (virou padrão,
// sem precisar de flag, a partir do Node 25) — passar essa flag pro Node
// 20 (ex.: o runner do GitHub Actions, hoje fixado em Node 20) derruba
// todo processo worker do Vitest na hora (flag desconhecida), causando
// falha total e quase instantânea da suíte inteira em CI, sem nenhuma
// relação com o código testado.
const nodeMajorVersion = Number(process.versions.node.split('.')[0]);

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
    // Node 22-24 expõe `localStorage`/`sessionStorage` globais nativos atrás
    // dessa flag (default-on sem flag a partir do Node 25), que colidem com
    // o polyfill do jsdom e lançam SecurityError ao acessar localStorage nos
    // testes. Só passa a flag em Node >=22 — em versões anteriores (ex.: o
    // runner de CI, hoje em Node 20) a flag nem existe e derrubaria todo
    // worker do Vitest na hora.
    execArgv: nodeMajorVersion >= 22 ? ['--no-experimental-webstorage'] : [],
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
