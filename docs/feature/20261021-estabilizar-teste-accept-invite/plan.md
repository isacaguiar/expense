# Plan — Estabilizar o teste do AcceptInvitePage

> Traduz `specify.md` em decisão técnica, item por item. Toda task em `tasks.md` aponta para uma seção daqui.

Versão: 1.0 · Criado em: 20261021

---

## 0. Reprodução e medição feitas na redação do plano

O specify §2.5 deixou como pendência reproduzir a falha de forma determinística. Foi feito em cópias descartáveis do arquivo de teste (`AcceptInvitePage.repro{A,B,C}.test.tsx`, já apagadas; `git status` limpo de alterações rastreadas). Todos os números abaixo são de `npx vitest run` no `frontend/`, isolado, na máquina de desenvolvimento.

| Cópia | O que muda em relação ao arquivo atual | Resultado |
|---|---|---|
| (arquivo atual) | nada | 9 passam; `:96` leva 650 ms e `:118` leva 649 ms |
| A | `:118` ganha `await new Promise(r => setTimeout(r, 2300))` no começo, para durar mais que os 2 s do timer do `:96` | **vermelho**: `AssertionError: expected "vi.fn()" to not be called at all, but actually been called 1 times` (a mesma mensagem do backlog), só em `:118` |
| B | A + `vi.waitFor(... navigate('/'), { timeout: 3000 })` no fim de `:96` e de `:171` | **verde**, 9 passam |
| C | fake timers só no teste de sucesso do convite, com o atraso de 2,3 s num teste seguinte | **verde** em 3 de 3 rodadas |

A primeira tentativa da cópia B saiu inválida: o `sed` colou o `await` no fim de uma linha de comentário, então o `waitFor` nunca rodou e o teste continuou vermelho. Refiz com `node` e conferi o `diff` (3 inserções, nos lugares certos) antes de rodar. O resultado da tabela é o da segunda tentativa.

### Achado extra: o `waitFor` do `:153` hoje não prova o próprio redirecionamento

Na rodada de referência, `não mede a redefinição de senha como cadastro` (`:153`) levou **1306 ms**, enquanto o mesmo teste, com todos os vazamentos corrigidos (cópia B), leva **2670 ms**. A conta fecha: o `navigate('/')` que satisfaz o `waitFor` do `:153` vem do timer de 2 s deixado pelo teste do `:96`, que dispara cerca de 1,2 s depois de o `:153` começar. Ou seja, o vazamento do `:96` também mascara a espera do `:153`, que passa por causa do timer de outro teste. Com o `:96` drenando o próprio timer, cada teste de sucesso passa a esperar o seu (cópia B: 2644 a 3396 ms cada). Isso é um efeito da correção, sem código a mais.

## 1. O que muda e onde (specify §2.1 a §2.3, R1, R2, R3, D1)

- **Arquivo único:** `frontend/src/pages/AcceptInvitePage.test.tsx`. `AcceptInvitePage.tsx` não é tocado (R1).
- **Teste do convite, `:96-116`** (`submits email/token/password…`): depois do `expect(await screen.findByText(/Senha definida com sucesso/))`, acrescentar a espera do redirecionamento, com comentário de uma linha:
  `await vi.waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/'), { timeout: 3000 });`
  É a correção que o item 081 pede e a que impede a falha observada.
- **Teste do reset, `:171-191`** (`submits to the same POST…`): a mesma linha, depois do `findByText(/Senha redefinida com sucesso/)` (D1 = A). Sem falha hoje, mas é o mesmo vazamento, para o `:193`.
- **Por que não mexer no componente:** escolha do usuário (R1). Cancelar o timer no `useEffect` impediria o vazamento na origem, mas muda código de uma página de autenticação, o que exigiria o `security-reviewer` e não é o pedido. Fica registrado como item de backlog no fechamento (specify §5).
- **Asserções existentes (R3):** nada é removido nem afrouxado. O `expect(mockNavigate).not.toHaveBeenCalled()` do `:131` continua. As mudanças só **acrescentam** uma asserção a cada um dos dois testes (agora eles também provam que o redirecionamento acontece).

## 2. Técnica: `vi.waitFor` ou fake timers (D2)

| | A. `vi.waitFor` | B. fake timers |
|---|---|---|
| Custo de código por teste | 1 linha (+ comentário) | 4 elementos: `vi.useFakeTimers({ shouldAdvanceTime: true })`, `userEvent.setup({ advanceTimers })`, `act` + `advanceTimersByTimeAsync(2000)` e `afterEach(vi.useRealTimers)` |
| Custo de tempo (medido) | cada teste espera o timer real: **~2,7 s** contra 0,65 s hoje, ou **+~2 s por teste, ~+4 s no arquivo** | 0,7 a 1,2 s por teste (3 rodadas): praticamente sem custo |
| Já usado no arquivo | **Sim** (`:53` e `:153`, com comentário) | Não |
| Risco | Depende de tempo real, mas a ordem dos timers protege: o de 2 s do componente nasce antes do `timeout` de 3 s do `waitFor`, e dispara antes dele mesmo sob carga | Fake timers com `userEvent` são fonte conhecida de travamento se `advanceTimers` for esquecido; 3 de 3 rodadas passaram |

**Decisão: A (`vi.waitFor`).** O que o item 081 reclama é flakiness, não lentidão, e A resolve a flakiness com uma linha que o arquivo já usa duas vezes. Os ~4 s extras num arquivo de ~7 s são um custo pequeno frente à suíte inteira (centenas de testes), e misturar duas técnicas de espera no mesmo arquivo teria custo de leitura. Se esses 4 s virarem incômodo, trocar os quatro testes de sucesso para fake timers de uma vez vira item de backlog, com a cópia C como ponto de partida.

## 3. Como provar a correção: vermelho antes, verde depois (specify R4)

O vermelho determinístico é o da cópia A (§0): atrasar o teste seguinte além dos 2 s. Esse atraso é uma **sonda temporária**, não vai para o código final (um `setTimeout` de 2,3 s num teste só gastaria tempo à toa).

1. **Vermelho do `:96`:** com o arquivo atual + sonda de 2,3 s no começo do `:118` → falha com a mensagem do backlog.
2. **Verde do `:96`:** acrescentar a espera no `:96` → com a mesma sonda, passa.
3. **Vermelho/verde do `:171`:** o vazamento do `:171` cai no `:193`, que **não** confere `navigate`, então nenhum teste atual o enxerga. Para provar a correção, a sonda desta vez é dupla e temporária no `:193`: `await new Promise(r => setTimeout(r, 2300))` no começo e `expect(mockNavigate).not.toHaveBeenCalled()` no fim. Sem a espera no `:171` falha; com ela passa. Depois a sonda sai.
4. **Checagem de mutação:** remover cada espera acrescentada e conferir que a sonda correspondente fica vermelha de novo (garante que cada linha acrescentada é necessária, e não decorativa).
5. **Estado final:** nenhuma sonda no arquivo; `git diff` mostra só as duas esperas e seus comentários.

Por que sonda e não teste permanente: o defeito está na ordem e no tempo entre testes. Um teste permanente que só existisse para provar isso seria mais frágil do que o defeito que ele cobre.

## 4. Verificação (specify R2, R4)

- `cd frontend && npx vitest run src/pages/AcceptInvitePage.test.tsx`: 9 de 9, repetido 3 vezes.
- `cd frontend && npx tsc --noEmit` sem erro (o arquivo de teste entra no projeto TypeScript).
- Suíte completa (`npx vitest run`) 3 vezes com os workers padrão, que é a condição em que a falha apareceu (2 em 3 vezes em 2026-10-10). **O resultado entra no log como veio.** Se o `RegisterPage.test.tsx` (15 s sob carga, specify §5) voltar a falhar, é outra causa e fica registrado, sem entrar nesta feature.
- **Sem validação manual no navegador:** nada que a pessoa usuária veja muda (só um arquivo de teste), então não há stack local para subir. Se você preferir ver a suíte completa rodar do seu lado antes do PR, a regra de validar antes do push continua valendo.

## 5. Ordem de execução

Os dois testes são independentes entre si (sem dependência técnica). Ordem: `:96` primeiro, porque é o que falha de verdade e é o que o item 081 pede; `:171` depois, como extensão da D1, cada um com o seu vermelho/verde de §3. A decisão de agrupar em uma ou duas tasks fica para o `tasks.md`.

Fluxo de branch conforme `docs/sdd/04-implementation.md` §1: a primeira task direto em `frontend/20261021-estabilizar-teste-accept-invite`; as seguintes em sub-branch, com `git merge --no-ff` de volta, sem PR por task. Um PR único contra `dev` no fim, depois do checklist pré-PR (`pr-readiness-checker`). Não há mudança em rota, controller ou middleware, então o `security-reviewer` não se aplica. Merge em `dev` é gate humano.
