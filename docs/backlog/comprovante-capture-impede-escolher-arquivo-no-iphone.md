# Comprovante no iPhone abre só a câmera e não deixa escolher foto da galeria nem arquivo

ID: 105
Origem: relato do mantenedor em 2026-10-27 (uso no iPhone), sem feature de origem; causa provável vista por leitura de código
Criado em: 2026-10-27
Prioridade: ALTA
Status: Aberto

## Descrição
Relato: no iPhone, ao tocar no botão de anexar o comprovante ("Selecionar foto" e equivalentes), o sistema abre para tirar a foto, mas **não permite selecionar um arquivo**. **Não reproduzido** (a sessão não tinha iPhone).

Causa provável, por leitura de código em 2026-10-27: `frontend/src/components/PaymentProofDialog.tsx:203-205` renderiza `<input type="file" accept="image/*" capture="environment" hidden>`. No Safari do iOS, o atributo `capture` faz o seletor ir direto para a câmera, sem "Biblioteca de Fotos" nem "Escolher Arquivo". O campo da foto de perfil (`Profile.tsx:211-212`) usa `accept="image/*"` sem `capture` e não tem essa limitação.

Alcance: o diálogo é o mesmo em todos os fluxos de comprovante: confirmar pagamento e editar comprovante em Despesas (`ExpenseManager.tsx`) e em Pagamentos (`Payments.tsx`), o comprovante do acerto do devedor (Pagamentos) e os do Painel (`GroupPositionCard.tsx`).

Correção provável, a escolher na hora de executar:

1. tirar o `capture`: o iOS passa a oferecer câmera, biblioteca e arquivos (confirmar também no Android, onde o seletor de `accept="image/*"` costuma listar a câmera);
2. ou dois botões, "Tirar foto" (com `capture`) e "Escolher da galeria" (sem), que mantém o atalho da câmera.

O backend só aceita imagem (`'comprovante' => 'nullable|image|max:5120'` em `ExpenseController.php:1076` e `:1234`, `required|image|max:5120` em `:1352`), então PDF fica fora. Se a ideia for aceitar PDF, é mudança de contrato do backend e merece item à parte.

Verificação: só em iPhone real (ou no simulador de Safari do iOS). Um teste de componente pode afirmar a ausência de `capture` no input, mas não prova o comportamento do sistema; a conferência manual é obrigatória.

## Por que importa
Em celular, o comprovante costuma ser o print do Pix que já está na galeria. Sem poder escolhê-lo, quem usa iPhone só consegue anexar tirando uma foto nova (por exemplo, da própria tela), e o comprovante do acerto exige foto. Atinge o fluxo principal de cobrança por Pix, por isso a prioridade ALTA (o mantenedor pode rebaixar).

Tipo sugerido: frontend. É defeito em comportamento existente, então, ao promover, o caminho é o fluxo de bugfix (`/novo-bug` e a Triagem de `docs/bugfix/README.md`), não `/promover-backlog` com feature.
