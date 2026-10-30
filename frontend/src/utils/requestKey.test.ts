import { afterEach, describe, expect, it, vi } from 'vitest';
import { newRequestKey } from './requestKey';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('newRequestKey', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('uses crypto.randomUUID when the browser has it', () => {
    const randomUUID = vi.fn(() => '11111111-2222-4333-8444-555555555555');
    vi.stubGlobal('crypto', { randomUUID });

    expect(newRequestKey()).toBe('11111111-2222-4333-8444-555555555555');
    expect(randomUUID).toHaveBeenCalledTimes(1);
  });

  it('builds a v4 UUID from getRandomValues in an insecure context, where randomUUID does not exist', () => {
    // Acesso ao app por IP em HTTP: `crypto.randomUUID` só existe em contexto seguro.
    vi.stubGlobal('crypto', {
      getRandomValues: (bytes: Uint8Array) => {
        for (let i = 0; i < bytes.length; i += 1) bytes[i] = (i * 17 + 3) % 256;
        return bytes;
      },
    });

    expect(newRequestKey()).toMatch(UUID_V4);
  });

  it('still returns a v4 UUID when the browser has no crypto at all', () => {
    vi.stubGlobal('crypto', undefined);

    expect(newRequestKey()).toMatch(UUID_V4);
  });

  it('sets the version and variant bits even when the random bytes are all ones or all zeros', () => {
    for (const fill of [0x00, 0xff]) {
      vi.stubGlobal('crypto', {
        getRandomValues: (bytes: Uint8Array) => {
          bytes.fill(fill);
          return bytes;
        },
      });

      expect(newRequestKey()).toMatch(UUID_V4);
    }
  });

  it('does not repeat across many calls', () => {
    vi.stubGlobal('crypto', undefined);

    const keys = new Set(Array.from({ length: 1000 }, () => newRequestKey()));

    expect(keys.size).toBe(1000);
  });
});
