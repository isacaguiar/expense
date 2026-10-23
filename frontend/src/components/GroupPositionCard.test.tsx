import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GroupPositionCard from './GroupPositionCard';
import { overviewGroupFixture } from '../types/overview.fixtures';
import type { OverviewGroup, OverviewItem } from '../types/overview';

vi.mock('axios');

const ME = { id: 7, name: 'Isac' };

// `cycles_ago: 2` de propósito: não coincide com 0 (ciclo vigente) nem com 1, então
// um valor fixo no cliente, em qualquer das três ações, é pego pelos testes.
function group(overrides: Partial<OverviewGroup> = {}): OverviewGroup {
  const base = overviewGroupFixture();
  return overviewGroupFixture({ cycle: { ...base.cycle, cycles_ago: 2 }, ...overrides });
}

const creditorItem = (overrides: Partial<OverviewItem> = {}): OverviewItem => ({
  expense_id: 61,
  description: 'Internet',
  date: '2026-09-05',
  value: 100,
  my_share: 50,
  paid: false,
  role: 'creditor',
  creditor: { id: 7, name: 'Isac' },
  installment: null,
  can_pay: true,
  can_unpay: false,
  ...overrides,
});

function renderCard(overview: OverviewGroup) {
  const onChanged = vi.fn();

  render(
    <MemoryRouter>
      <GroupPositionCard overview={overview} currentUser={ME} onChanged={onChanged} />
    </MemoryRouter>
  );

  return { onChanged };
}

async function expand(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: /Ver detalhes/ }));
}

const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;

describe('GroupPositionCard', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: {} });
    vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('accessToken', 'token-de-teste');
  });

  afterEach(() => {
    localStorage.removeItem('accessToken');
    vi.restoreAllMocks();
  });

  describe('resumo', () => {
    it('shows the group, the cycle chip, its dates and the three totals', () => {
      renderCard(group());

      expect(screen.getByRole('heading', { name: 'Residencial' })).toBeInTheDocument();
      expect(screen.getByText('Ciclo fechado')).toBeInTheDocument();
      expect(screen.getByText('01/09 – 30/09')).toBeInTheDocument();

      expect(within(screen.getByTestId('total-owe')).getByText('A pagar')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-owe')).getByText('R$ 439,23')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-receive')).getByText('A receber')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-receive')).getByText('R$ 60,00')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-confirmed')).getByText('Já confirmado')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-confirmed')).getByText('R$ 0,00')).toBeInTheDocument();
    });

    // TASK-470 (achado 1 da validação local): quem é credor via "Já confirmado R$ 0,00"
    // mesmo com comprovantes já enviados por devedores. "Já recebido" é o que os
    // devedores já comprovaram (`receive.confirmed`), distinto do que ainda falta.
    it('shows "Já recebido" with the proofs already sent to the user, apart from what is still open', () => {
      renderCard(
        group({
          // 250 já comprovados, 400 ainda a receber (total 650): o "A receber" é só o que falta.
          receive: { total: 650, confirmed: 250, open: 400 },
        })
      );

      expect(within(screen.getByTestId('total-receive')).getByText('R$ 400,00')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-received')).getByText('Já recebido')).toBeInTheDocument();
      expect(within(screen.getByTestId('total-received')).getByText('R$ 250,00')).toBeInTheDocument();
    });

    it('shows "Já recebido" as zero when nobody sent a proof yet, and keeps it apart from "Já confirmado"', () => {
      const base = group();
      renderCard(
        group({
          owe: { total: 439.23, confirmed: 100, open: 339.23 },
          receive: { ...base.receive, total: 120, confirmed: 0, open: 120 },
        })
      );

      expect(within(screen.getByTestId('total-received')).getByText('R$ 0,00')).toBeInTheDocument();
      // O que o usuário deve e já comprovou continua no seu próprio total.
      expect(within(screen.getByTestId('total-confirmed')).getByText('R$ 100,00')).toBeInTheDocument();
    });

    it('says when the cycle closes while it is still in its grace window', () => {
      const base = group();
      renderCard(group({ cycle: { ...base.cycle, status: 'open', in_grace: true, closes_at: '2026-10-05' } }));

      expect(screen.getByText('Fecha em 05/10')).toBeInTheDocument();
      expect(screen.queryByText('Ciclo em andamento')).not.toBeInTheDocument();
    });

    it('uses the usual chip for an open cycle that is not in grace', () => {
      const base = group();
      renderCard(group({ cycle: { ...base.cycle, status: 'open', in_grace: false } }));

      expect(screen.getByText('Ciclo em andamento')).toBeInTheDocument();
    });

    it('shows "Em dia" and no alert when nothing is open', () => {
      renderCard(
        group({
          has_open: false,
          owe: { total: 0, confirmed: 0, open: 0 },
          receive: { total: 0, confirmed: 0, open: 0 },
          to_pay: [],
          to_receive: [],
        })
      );

      expect(screen.getByText('Em dia')).toBeInTheDocument();
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('warns when there is something to pay, and has no "Em dia" seal', () => {
      renderCard(group());

      expect(screen.getByRole('alert')).toHaveTextContent('Você tem pagamentos em aberto neste ciclo.');
      expect(screen.queryByText('Em dia')).not.toBeInTheDocument();
    });

    it('only informs when what is open is to receive', () => {
      renderCard(group({ owe: { total: 0, confirmed: 0, open: 0 }, to_pay: [] }));

      expect(screen.getByRole('alert')).toHaveTextContent('Você tem valores a receber em aberto neste ciclo.');
    });

    it('links to the payments of the group', () => {
      renderCard(group());

      expect(screen.getByRole('link', { name: 'Ver ciclos' })).toHaveAttribute('href', '/groups/3/payments');
    });

    it('keeps the detail closed until asked, then shows the three sections', async () => {
      const user = userEvent.setup();
      renderCard(group());

      expect(screen.queryByText('Você paga')).not.toBeInTheDocument();

      await expand(user);

      expect(screen.getByText('Você paga')).toBeInTheDocument();
      expect(screen.getByText('Você recebe')).toBeInTheDocument();
      expect(screen.getByText('Seus itens')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Ocultar detalhes/ })).toHaveAttribute('aria-expanded', 'true');
    });

    it('says so when there is nothing to pay in the cycle', async () => {
      const user = userEvent.setup();
      renderCard(group({ to_pay: [], owe: { total: 0, confirmed: 0, open: 0 } }));

      await expand(user);

      expect(screen.getByText('Você não deve nada neste ciclo.')).toBeInTheDocument();
    });
  });

  describe('comprovante do acerto (devedor)', () => {
    it('opens the dialog and posts to_user_id, comprovante and the cycles_ago of the payload', async () => {
      const user = userEvent.setup();
      const { onChanged } = renderCard(group());
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Enviar comprovante' }));

      expect(await screen.findByText('Enviar comprovante do Pix')).toBeInTheDocument();
      const confirmButton = screen.getByRole('button', { name: 'Confirmar' });
      expect(confirmButton).toBeDisabled();

      const file = new File(['foto'], 'pix.png', { type: 'image/png' });
      await user.upload(fileInput(), file);
      await user.click(confirmButton);

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body, config] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toContain('/api/groups/3/settlements/confirm');
      expect((body as FormData).get('to_user_id')).toBe('9');
      expect((body as FormData).get('comprovante')).toBe(file);
      expect((body as FormData).get('cycles_ago')).toBe('2');
      expect(config).toEqual({ headers: { Authorization: 'Bearer token-de-teste' } });

      await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
      expect(await screen.findByText('Comprovante enviado.')).toBeInTheDocument();
    });

    // A foto continua obrigatória no acerto do devedor (o servidor exige `comprovante`); a
    // descrição é só um complemento opcional.
    it('keeps Confirmar disabled with only a description, because the settlement needs the photo', async () => {
      const user = userEvent.setup();
      renderCard(group());
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Enviar comprovante' }));
      await user.type(await screen.findByRole('textbox', { name: /Descrição/ }), 'Só o texto');

      expect(screen.getByRole('button', { name: 'Confirmar' })).toBeDisabled();
      expect(axios.post).not.toHaveBeenCalled();
    });

    it('sends the description with the photo of the settlement as descricao', async () => {
      const user = userEvent.setup();
      renderCard(group());
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Enviar comprovante' }));
      const note = await screen.findByRole('textbox', { name: /Descrição/ });
      await user.upload(fileInput(), new File(['foto'], 'pix.png', { type: 'image/png' }));
      await user.type(note, '  Pix de manhã  ');
      await user.click(screen.getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body.get('descricao')).toBe('Pix de manhã');
      expect(body.get('cycles_ago')).toBe('2');
      expect(body.get('to_user_id')).toBe('9');
    });

    it('offers to send the proof again when it was already sent', async () => {
      const user = userEvent.setup();
      const base = group();
      renderCard(
        group({
          to_pay: [
            { ...base.to_pay[0], confirmed_at: '2026-10-08T14:02:11-03:00', confirmed_proof_url: 'https://example.test/c.jpg' },
          ],
        })
      );
      await expand(user);

      expect(screen.getByRole('button', { name: 'Reenviar comprovante' })).toBeInTheDocument();
      // Há dois links "Comprovante enviado" na tela: o do acerto que o usuário paga
      // (este) e o de quem lhe paga, em "Você recebe" (outra URL na fixture).
      const hrefs = screen.getAllByRole('link', { name: /Comprovante enviado/ }).map(link => link.getAttribute('href'));
      expect(hrefs).toContain('https://example.test/c.jpg');
    });

    it('hides the proof and Pix buttons when the server does not allow confirming, with the usual notice', async () => {
      const user = userEvent.setup();
      const base = group();
      renderCard(group({ to_pay: [{ ...base.to_pay[0], can_confirm: false }] }));
      await expand(user);

      expect(screen.queryByRole('button', { name: /comprovante/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: 'Pagar com Pix' })).not.toBeInTheDocument();
      expect(screen.getByText('Disponível após o fechamento do ciclo.')).toBeInTheDocument();
    });
  });

  describe('Pix', () => {
    it('opens the Pix dialog with the creditor e-mail, name and amount', async () => {
      const user = userEvent.setup();
      vi.mocked(axios.get).mockResolvedValue({ data: { qrcode: 'data:image/png;base64,AAA', copiacola: '000201pix' } });
      renderCard(group());
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Pagar com Pix' }));

      expect(await screen.findByText('Pagar Nádia')).toBeInTheDocument();
      expect(screen.getByText('R$ 439,23 via Pix')).toBeInTheDocument();
      await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(1));
      const [url, config] = vi.mocked(axios.get).mock.calls[0];
      expect(url).toContain('/api/pix/generate');
      expect(config).toMatchObject({ params: { email: 'nadia@example.test', valor: '439.23' } });
    });

    it('does not open the dialog and warns when the creditor has no Pix key', async () => {
      const user = userEvent.setup();
      const base = group();
      renderCard(group({ to_pay: [{ ...base.to_pay[0], to_user: { ...base.to_pay[0].to_user, pix: null } }] }));
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Pagar com Pix' }));

      expect(await screen.findByText('Nádia ainda não cadastrou uma chave Pix.')).toBeInTheDocument();
      expect(screen.queryByText('Pagar Nádia')).not.toBeInTheDocument();
      expect(axios.get).not.toHaveBeenCalled();
    });

    it('does not open the dialog when the creditor left the group and has no e-mail', async () => {
      const user = userEvent.setup();
      const base = group();
      renderCard(
        group({
          to_pay: [{ ...base.to_pay[0], to_user: { id: 9, name: 'Desconhecido', email: null, pix: null, avatar_url: null } }],
        })
      );
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Pagar com Pix' }));

      expect(await screen.findByText('Não foi possível identificar esse credor.')).toBeInTheDocument();
      expect(axios.get).not.toHaveBeenCalled();
    });
  });

  describe('itens (credor)', () => {
    // TASK-472 (decisão D13): o mesmo `PaymentProofDialog` de Pagamentos. Para o credor a
    // foto é opcional, mas é preciso foto OU descrição; o `cycles_ago` é sempre o do payload.
    it('asks for a photo or a description before marking as paid, then posts multipart with the cycles_ago of the payload', async () => {
      const user = userEvent.setup();
      const { onChanged } = renderCard(group({ my_items: [creditorItem()] }));
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));

      expect(await screen.findByText('Anexe uma foto do comprovante ou descreva o pagamento.')).toBeInTheDocument();
      const confirmButton = screen.getByRole('button', { name: 'Confirmar' });
      expect(confirmButton).toBeDisabled();
      expect(axios.post).not.toHaveBeenCalled();

      const file = new File(['foto'], 'comprovante.png', { type: 'image/png' });
      await user.upload(fileInput(), file);
      await user.click(confirmButton);

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toContain('/api/expenses/61/pay');
      expect(body).toBeInstanceOf(FormData);
      expect((body as FormData).get('comprovante')).toBe(file);
      expect((body as FormData).get('cycles_ago')).toBe('2');
      expect((body as FormData).has('descricao')).toBe(false);

      await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
      expect(await screen.findByText('Pagamento confirmado.')).toBeInTheDocument();
    });

    it('marks as paid with only a description, no photo, posting JSON with descricao and the cycles_ago of the payload', async () => {
      const user = userEvent.setup();
      const { onChanged } = renderCard(group({ my_items: [creditorItem()] }));
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
      const confirmButton = await screen.findByRole('button', { name: 'Confirmar' });
      expect(confirmButton).toBeDisabled();

      await user.type(screen.getByRole('textbox', { name: /Descrição/ }), '  Pago em dinheiro  ');
      expect(confirmButton).toBeEnabled();
      await user.click(confirmButton);

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toContain('/api/expenses/61/pay');
      expect(body).toEqual({ cycles_ago: 2, descricao: 'Pago em dinheiro' });

      await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
    });

    it('marks as paid with a photo and a description together', async () => {
      const user = userEvent.setup();
      renderCard(group({ my_items: [creditorItem()] }));
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Marcar como paga' }));
      await user.upload(fileInput(), new File(['foto'], 'comprovante.png', { type: 'image/png' }));
      await user.type(screen.getByRole('textbox', { name: /Descrição/ }), 'Pix do João');
      await user.click(screen.getByRole('button', { name: 'Confirmar' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
      expect(body.get('descricao')).toBe('Pix do João');
      expect(body.get('comprovante')).toBeInstanceOf(File);
      expect(body.get('cycles_ago')).toBe('2');
    });

    it('undoes a payment without a photo, posting the cycles_ago of the payload', async () => {
      const user = userEvent.setup();
      const { onChanged } = renderCard(
        group({ my_items: [creditorItem({ paid: true, can_pay: false, can_unpay: true })] })
      );
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Desfazer' }));

      await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
      const [url, body] = vi.mocked(axios.post).mock.calls[0];
      expect(url).toContain('/api/expenses/61/unpay');
      expect(body).toEqual({ cycles_ago: 2 });

      await waitFor(() => expect(onChanged).toHaveBeenCalledTimes(1));
      expect(await screen.findByText('Pagamento desfeito.')).toBeInTheDocument();
    });

    it('shows the error of a failed payment and does not reload', async () => {
      const user = userEvent.setup();
      vi.mocked(axios.post).mockRejectedValue({ response: { data: { error: 'Ciclo selado.' } } });
      const { onChanged } = renderCard(
        group({ my_items: [creditorItem({ paid: true, can_pay: false, can_unpay: true })] })
      );
      await expand(user);

      await user.click(screen.getByRole('button', { name: 'Desfazer' }));

      expect(await screen.findByText('Ciclo selado.')).toBeInTheDocument();
      expect(onChanged).not.toHaveBeenCalled();
    });
  });

  describe('você recebe', () => {
    it('lists who owes the user, without any action', async () => {
      const user = userEvent.setup();
      renderCard(group());
      await expand(user);

      expect(screen.getByTestId('receivable-4')).toBeInTheDocument();
    });
  });
});
