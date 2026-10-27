import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import PaymentSettlementsPanel, { SettlementsTab } from './PaymentSettlementsPanel';
import type { SummaryBalance, SummarySettlement } from '../hooks/useGroupCycle';

// Membros do specify §5.3. A logada é a Carla (3).
const balances: SummaryBalance[] = [
  { user_id: 1, name: 'Ana', balance: 0 },
  { user_id: 2, name: 'Bruno', balance: 0 },
  { user_id: 3, name: 'Carla', balance: 0 },
  { user_id: 4, name: 'Diego', balance: 0 },
  { user_id: 5, name: 'Eva', balance: 0 }
];

const CONFIRMED_AT = '2026-10-20T10:00:00-03:00';

const make = (
  from: number,
  to: number,
  amount: number,
  extra: Partial<SummarySettlement> = {}
): SummarySettlement => ({
  from_user_id: from,
  to_user_id: to,
  amount,
  confirmedProofUrl: null,
  confirmedAt: null,
  ...extra
});

// Os oito acertos do §5.3, entregues fora de qualquer ordem útil (como o servidor os manda).
const settlements: SummarySettlement[] = [
  make(5, 2, 70), // Eva → Bruno
  make(2, 4, 10, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/1' }), // Bruno → Diego
  make(4, 3, 40), // Diego → Carla (a receber da logada)
  make(5, 3, 20, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/2' }), // Eva → Carla (recebido)
  make(3, 1, 60), // Carla → Ana
  make(3, 2, 25, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/3' }), // Carla → Bruno
  make(4, 1, 90), // Diego → Ana
  make(5, 1, 30, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/4' }) // Eva → Ana
];

const noop = () => {};

type PanelProps = Partial<React.ComponentProps<typeof PaymentSettlementsPanel>>;

// O painel é controlado: quem o usa (Payments) guarda a aba. O wrapper faz esse papel nos testes.
const Harness = ({ initialTab = 'toPay', ...props }: PanelProps & { initialTab?: SettlementsTab }) => {
  const [tab, setTab] = useState<SettlementsTab>(initialTab);
  return (
    <PaymentSettlementsPanel
      settlements={settlements}
      balances={balances}
      currentUserId={3}
      canConfirm
      cycleSettled={false}
      tab={tab}
      onTabChange={setTab}
      onPayWithPix={noop}
      onSendProof={noop}
      {...props}
    />
  );
};

const renderPanel = (props: PanelProps & { initialTab?: SettlementsTab } = {}) => render(<Harness {...props} />);

// "Carla deve pagar R$ 60,00 a Ana", na ordem em que a aba ativa mostra.
const shownSettlements = () =>
  within(screen.getByRole('tabpanel'))
    .getAllByText(/deve pagar/)
    .map(el => (el.closest('p')?.textContent ?? '').replace(/\s+/g, ' ').trim());

const tabNamed = (name: RegExp) => screen.getByRole('tab', { name });

describe('PaymentSettlementsPanel - ordem por aba', () => {
  it('"Valores a pagar" mostra a lista do §5.3: saída da logada, depois devedores pelo total da aba', async () => {
    renderPanel();

    expect(shownSettlements()).toEqual([
      'Carla deve pagar R$ 60,00 a Ana',
      'Diego deve pagar R$ 90,00 a Ana',
      'Diego deve pagar R$ 40,00 a Carla',
      'Eva deve pagar R$ 70,00 a Bruno'
    ]);
  });

  it('"Valores pagos" mostra a lista do §5.3, com o total do devedor medido só nessa aba', async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(tabNamed(/Valores pagos/));

    expect(shownSettlements()).toEqual([
      'Carla deve pagar R$ 25,00 a Bruno',
      'Eva deve pagar R$ 30,00 a Ana',
      'Eva deve pagar R$ 20,00 a Carla',
      'Bruno deve pagar R$ 10,00 a Diego'
    ]);
  });

  it('não altera a lista recebida: a ordem de apresentação é só da tela', () => {
    const recebida = Object.freeze(settlements.map(s => ({ ...s }))) as SummarySettlement[];
    const copia = recebida.map(s => ({ ...s }));

    renderPanel({ settlements: recebida });

    expect(recebida).toEqual(copia);
  });
});

describe('PaymentSettlementsPanel - abas', () => {
  it('mostra o contador de cada aba', () => {
    renderPanel();

    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('Valores a pagar (4)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('Valores pagos (4)');
  });

  it('a soma dos contadores é o total de acertos: nenhum some e nenhum repete', () => {
    renderPanel({ settlements: [...settlements, make(1, 2, 5), make(1, 4, 7, { confirmedAt: CONFIRMED_AT })] });

    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(5)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(5)');
  });

  it('expõe tablist, tabs com aria-selected e um tabpanel ligado à aba ativa', () => {
    renderPanel();

    expect(screen.getByRole('tablist', { name: 'Acertos entre membros' })).toBeInTheDocument();
    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-selected', 'true');
    expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'false');

    const panel = screen.getByRole('tabpanel');
    expect(panel).toHaveAttribute('aria-labelledby', tabNamed(/Valores a pagar/).id);
    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-controls', panel.id);
  });

  it('troca de aba por clique e o tabpanel passa a ser descrito pela nova aba', async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(tabNamed(/Valores pagos/));

    expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'true');
    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-selected', 'false');
    expect(screen.getByRole('tabpanel')).toHaveAttribute('aria-labelledby', tabNamed(/Valores pagos/).id);
  });

  it('troca de aba pelas setas do teclado, nos dois sentidos', async () => {
    const user = userEvent.setup();
    renderPanel();

    tabNamed(/Valores a pagar/).focus();
    await user.keyboard('{ArrowRight}');
    expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'true');
    expect(tabNamed(/Valores pagos/)).toHaveFocus();

    await user.keyboard('{ArrowLeft}');
    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-selected', 'true');
  });

  it('avisa quem usa o painel qual aba foi escolhida, sem guardar estado próprio', async () => {
    const user = userEvent.setup();
    const onTabChange = vi.fn();
    render(
      <PaymentSettlementsPanel
        settlements={settlements}
        balances={balances}
        currentUserId={3}
        canConfirm
        cycleSettled={false}
        tab="toPay"
        onTabChange={onTabChange}
        onPayWithPix={noop}
        onSendProof={noop}
      />
    );

    await user.click(tabNamed(/Valores pagos/));

    expect(onTabChange).toHaveBeenCalledWith('paid');
    // Controlado: sem o pai trocar a prop, a aba ativa continua a mesma.
    expect(tabNamed(/Valores a pagar/)).toHaveAttribute('aria-selected', 'true');
  });

  it('abre na aba que o pai mandou', () => {
    renderPanel({ initialTab: 'paid' });

    expect(tabNamed(/Valores pagos/)).toHaveAttribute('aria-selected', 'true');
    expect(shownSettlements()).toHaveLength(4);
    expect(shownSettlements()[0]).toBe('Carla deve pagar R$ 25,00 a Bruno');
  });
});

describe('PaymentSettlementsPanel - o que é "pago"', () => {
  const duasAbas = (lista: SummarySettlement[]) => {
    renderPanel({ settlements: lista });
  };

  it('confirmedAt sem URL de comprovante: é pago', async () => {
    const user = userEvent.setup();
    duasAbas([make(3, 1, 60, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: null })]);

    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(1)');
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(0)');
    await user.click(tabNamed(/Valores pagos/));
    expect(shownSettlements()).toEqual(['Carla deve pagar R$ 60,00 a Ana']);
  });

  it('URL de comprovante sem confirmedAt: não é pago', () => {
    duasAbas([make(3, 1, 60, { confirmedAt: null, confirmedProofUrl: 'http://api/proofs/9' })]);

    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(1)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(0)');
    expect(shownSettlements()).toEqual(['Carla deve pagar R$ 60,00 a Ana']);
  });

  it('os dois campos: é pago; nenhum dos dois: não é', () => {
    duasAbas([
      make(3, 1, 60, { confirmedAt: CONFIRMED_AT, confirmedProofUrl: 'http://api/proofs/9' }),
      make(3, 2, 25)
    ]);

    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(1)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(1)');
  });
});

describe('PaymentSettlementsPanel - estados vazios', () => {
  it('só com acertos pagos, "Valores a pagar" diz que não há valor a pagar', () => {
    renderPanel({ settlements: [make(3, 1, 60, { confirmedAt: CONFIRMED_AT })] });

    expect(screen.getByText('Nenhum valor a pagar neste ciclo.')).toBeInTheDocument();
    expect(screen.queryByText('Ninguém deve nada a ninguém neste ciclo.')).not.toBeInTheDocument();
  });

  it('só com acertos a pagar, "Valores pagos" diz que ainda não há valor pago', async () => {
    const user = userEvent.setup();
    renderPanel({ settlements: [make(3, 1, 60)] });

    await user.click(tabNamed(/Valores pagos/));

    expect(screen.getByText('Nenhum valor pago neste ciclo ainda.')).toBeInTheDocument();
  });

  it('sem nenhum acerto no ciclo, as duas abas dizem que ninguém deve nada', async () => {
    const user = userEvent.setup();
    renderPanel({ settlements: [] });

    expect(screen.getByText('Ninguém deve nada a ninguém neste ciclo.')).toBeInTheDocument();
    await user.click(tabNamed(/Valores pagos/));
    expect(screen.getByText('Ninguém deve nada a ninguém neste ciclo.')).toBeInTheDocument();
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(0)');
    expect(tabNamed(/Valores pagos/)).toHaveTextContent('(0)');
  });

  it.each([
    ['undefined', undefined],
    ['null', null]
  ])('settlements %s (snapshot antigo) vira lista vazia, sem erro', (_nome, valor) => {
    renderPanel({ settlements: valor });

    expect(screen.getByText('Ninguém deve nada a ninguém neste ciclo.')).toBeInTheDocument();
    expect(tabNamed(/Valores a pagar/)).toHaveTextContent('(0)');
  });
});

describe('PaymentSettlementsPanel - valores a receber', () => {
  it('marca com texto o que a logada tem a receber, em cada aba', async () => {
    const user = userEvent.setup();
    renderPanel();

    expect(screen.getAllByText('Deve pagar a você')).toHaveLength(1);

    await user.click(tabNamed(/Valores pagos/));
    expect(screen.getAllByText('Pagou a você')).toHaveLength(1);
  });

  it('sem usuário logado identificado, nada é marcado como a receber', () => {
    renderPanel({ currentUserId: null });

    expect(screen.queryByText('Deve pagar a você')).not.toBeInTheDocument();
  });
});

describe('PaymentSettlementsPanel - ações do devedor', () => {
  it('Pix e envio de comprovante só aparecem na saída da logada, quando canConfirm', () => {
    renderPanel({ canConfirm: true });

    // Na aba "a pagar" só a Carla→Ana é saída da logada.
    expect(screen.getAllByRole('button', { name: 'Pagar com Pix' })).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: 'Enviar comprovante' })).toHaveLength(1);
  });

  it('sem canConfirm, nenhum botão: o devedor vê o aviso', () => {
    renderPanel({ canConfirm: false });

    expect(screen.queryByRole('button', { name: 'Pagar com Pix' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Enviar comprovante' })).not.toBeInTheDocument();
    expect(screen.getByText('Disponível após o fechamento do ciclo.')).toBeInTheDocument();
  });

  it('o acerto pago da logada oferece "Reenviar comprovante", na aba "Valores pagos"', async () => {
    const user = userEvent.setup();
    renderPanel();

    await user.click(tabNamed(/Valores pagos/));

    expect(screen.getByRole('button', { name: 'Reenviar comprovante' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Enviar comprovante' })).not.toBeInTheDocument();
  });

  it('entrega o acerto clicado a onPayWithPix e a onSendProof', async () => {
    const user = userEvent.setup();
    const onPayWithPix = vi.fn();
    const onSendProof = vi.fn();
    renderPanel({ onPayWithPix, onSendProof });

    await user.click(screen.getByRole('button', { name: 'Pagar com Pix' }));
    await user.click(screen.getByRole('button', { name: 'Enviar comprovante' }));

    const esperado = settlements.find(s => s.from_user_id === 3 && s.to_user_id === 1);
    expect(onPayWithPix).toHaveBeenCalledWith(esperado);
    expect(onSendProof).toHaveBeenCalledWith(esperado);
  });
});
