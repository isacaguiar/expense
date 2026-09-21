/**
 * Normaliza um caminho de rota para medição, removendo o que identifica o
 * usuário ou os dados dele.
 *
 * Duas coisas são descartadas, e as duas são vazamento real neste app:
 *
 * 1. **Query string e hash**, inteiros. A URL de um convite é
 *    `/aceitar-convite?email=<e-mail da pessoa>&token=<token válido>`
 *    (`pages/AcceptInvitePage.tsx`) — mandar isso ao Google entregaria o
 *    e-mail de alguém e um token de convite em uso.
 * 2. **Segmentos numéricos**, que viram `:id`. As rotas privadas são
 *    `/groups/:id/expenses/:expenseId` e afins (`App.tsx`): o caminho
 *    resolvido levaria os IDs de grupo e de despesa a cada tela aberta.
 *
 * Por que regex e não `matchRoutes()` do react-router: pegar o padrão da rota
 * exigiria manter a lista de rotas num array fora do `App.tsx`, duplicando o
 * roteamento — a duplicação que o item 053 do backlog registra como problema.
 *
 * **Limite conhecido**: um parâmetro não numérico no futuro (slug, UUID)
 * passaria como está. Se alguém adicionar uma rota assim, este é o lugar de
 * tratá-la — e o teste ao lado é onde o novo caso deve ser fixado primeiro.
 */
export function sanitizePath(path: string): string {
  const semQueryNemHash = path.split(/[?#]/)[0];

  return semQueryNemHash.replace(/\/\d+(?=\/|$)/g, '/:id');
}
