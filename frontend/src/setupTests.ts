import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

// jsdom não implementa `window.matchMedia`. O `useMediaQuery` do MUI precisa
// dele para o ramo responsivo (ex.: listagem em cartões abaixo de `sm` em
// Dashboard/ExpenseManager). Sem este polyfill, qualquer teste que renderize
// um componente com `useMediaQuery` quebra com "matchMedia is not a function".
//
// O default é `matches: false` — equivale ao layout desktop, que é o que os
// testes existentes assumem. Um teste que precise simular viewport estreito
// sobrescreve antes de renderizar e restaura depois:
//
//   vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({
//     matches: true, media: '', onchange: null,
//     addEventListener: vi.fn(), removeEventListener: vi.fn(),
//     addListener: vi.fn(), removeListener: vi.fn(), dispatchEvent: vi.fn(),
//   }));
//   // ...render...
//   vi.unstubAllGlobals();
if (typeof window !== 'undefined' && !window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }));
}
