/**
 * Origem de campanha (UTM) de quem chega ao app por um link.
 *
 * Só quatro parâmetros passam — `utm_source`, `utm_medium`, `utm_campaign` e
 * `utm_content` — e só com valor no formato da convenção de campanhas do
 * projeto (minúsculas, dígitos, `.`, `_` e `-`, de 1 a 64 caracteres). O que
 * não casar é descartado sozinho, sem afetar os demais. Todo o resto da query
 * (`email`, `token`, `google_code`...) nunca é lido por aqui.
 *
 * A campanha é lida no carregamento do app e fica **só em memória** (variável
 * do módulo): nada em `localStorage`, `sessionStorage` ou cookie, porque quem
 * chega por um link de campanha ainda não aceitou o banner e não se grava nada
 * no aparelho antes disso. O preço é que um recarregamento sem os parâmetros
 * na URL perde a campanha — aceitável, o GA4 só precisa dela no primeiro envio
 * da sessão.
 *
 * Existe porque o `RouteTracker` roda no carregamento, antes do aceite, e a rota
 * seguinte (depois do aceite) já não tem a query: sem capturar antes, a origem
 * de quem veio de uma campanha seria perdida justamente no caso mais comum.
 */

const CHAVES = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'] as const;

const VALOR_VALIDO = /^[a-z0-9._-]{1,64}$/;

let pendente = '';

/** Lê os UTM válidos de `search` e os deixa pendentes para o próximo envio. */
export function captureCampaign(search: string = window.location.search): void {
  const params = new URLSearchParams(search);
  const validos = new URLSearchParams();

  for (const chave of CHAVES) {
    const valor = params.get(chave);

    if (valor !== null && VALOR_VALIDO.test(valor)) {
      validos.set(chave, valor);
    }
  }

  pendente = validos.toString();
}

/**
 * Devolve a campanha pendente como query string (`?utm_source=...`) e a
 * consome: o GA4 deriva a campanha da sessão do primeiro hit, e repeti-la em
 * todo envio seria só ruído. Vazio quando não há campanha pendente.
 */
export function consumeCampaign(): string {
  const consulta = pendente;
  pendente = '';

  return consulta === '' ? '' : `?${consulta}`;
}
