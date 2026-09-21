import { describe, expect, it } from 'vitest';
import { sanitizePath } from './sanitizePath';

/**
 * O que estes testes protegem: o caminho enviado ao Google Analytics não pode
 * carregar nada que identifique o usuário ou os dados dele.
 *
 * Os dois vazamentos reais deste app estão cobertos abaixo — IDs de grupo e de
 * despesa no caminho, e o e-mail + token de convite na query string de
 * `/aceitar-convite`.
 */
describe('sanitizePath', () => {
  it('troca IDs numéricos pelo padrão da rota', () => {
    expect(sanitizePath('/groups/42/expenses/1337')).toBe('/groups/:id/expenses/:id');
  });

  it('descarta a query string inteira, com e-mail e token de convite', () => {
    expect(sanitizePath('/aceitar-convite?email=a@b.com&token=xyz')).toBe('/aceitar-convite');
  });

  it('descarta o hash', () => {
    expect(sanitizePath('/manual#como-funciona')).toBe('/manual');
  });

  it('não mexe em rota sem ID nem query', () => {
    expect(sanitizePath('/meus-grupos')).toBe('/meus-grupos');
  });

  it('normaliza ID no fim do caminho', () => {
    expect(sanitizePath('/groups/7')).toBe('/groups/:id');
  });

  it('preserva a raiz', () => {
    expect(sanitizePath('/')).toBe('/');
  });

  it('não confunde segmento que apenas contém dígitos com ID', () => {
    expect(sanitizePath('/relatorios/2026-09')).toBe('/relatorios/2026-09');
  });
});
