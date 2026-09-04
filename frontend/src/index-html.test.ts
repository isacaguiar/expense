import { describe, expect, it } from 'vitest';
import indexHtml from '../index.html?raw';

// Guarda de regressão do bug docs/bugfix/concluidos/202609/20260901-frontend-meta-viewport-mobile.md:
// sem a <meta name="viewport"> os navegadores mobile assumem um viewport de layout
// de ~980px e encolhem a página inteira ("letras pequenas"), e todos os breakpoints
// do MUI (xs/md) passam a ser avaliados contra essa largura fictícia — o layout
// responsivo já existente no app nunca chega a ativar no celular.
describe('index.html', () => {
  it('declara a meta viewport para layout responsivo em mobile', () => {
    expect(indexHtml).toMatch(
      /<meta\s+name="viewport"\s+content="[^"]*width=device-width[^"]*"\s*\/?>/,
    );
  });
});
