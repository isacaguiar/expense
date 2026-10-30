/**
 * Chave de idempotência de um cadastro de despesa: um UUID v4 gerado uma vez por
 * formulário e reenviado em todo reenvio, para o servidor reconhecer a mesma
 * tentativa (docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa, R23 e D7).
 *
 * `crypto.randomUUID` só existe em contexto seguro (HTTPS ou localhost). Quem abre o
 * app por IP em HTTP não o tem; nesse caso o v4 é montado a partir de
 * `crypto.getRandomValues`, e, sem `crypto`, de `Math.random` (a chave não é segredo:
 * só precisa não repetir entre formulários).
 */
export function newRequestKey(): string {
  const cryptoApi = typeof globalThis.crypto === 'undefined' ? undefined : globalThis.crypto;

  if (cryptoApi && typeof cryptoApi.randomUUID === 'function') {
    return cryptoApi.randomUUID();
  }

  const bytes = new Uint8Array(16);

  if (cryptoApi && typeof cryptoApi.getRandomValues === 'function') {
    cryptoApi.getRandomValues(bytes);
  } else {
    for (let i = 0; i < bytes.length; i += 1) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }

  // Versão 4 e variante RFC 4122.
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0'));

  return [
    hex.slice(0, 4).join(''),
    hex.slice(4, 6).join(''),
    hex.slice(6, 8).join(''),
    hex.slice(8, 10).join(''),
    hex.slice(10, 16).join(''),
  ].join('-');
}
