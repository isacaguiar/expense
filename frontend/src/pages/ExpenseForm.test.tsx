import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpenseForm from './ExpenseForm';
import { trackEvent } from '../analytics/trackEvent';

vi.mock('axios');

// O contrato da página com a camada de analytics é a chamada em si (o que o
// `trackEvent` envia ao GA já é coberto em `analytics/trackEvent.test.ts`).
vi.mock('../analytics/trackEvent', () => ({ trackEvent: vi.fn() }));

type ExpensePayload = {
  expense_type: string;
  installments: number;
  payers: number[];
  quotas: { number: number; date_expected: string; paid: boolean; value_quota: number }[];
};

function lastPostCall<T>(): [string, T] {
  const calls = vi.mocked(axios.post).mock.calls;
  return calls[calls.length - 1] as unknown as [string, T];
}

const navigateMock = vi.fn();

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return {
    ...actual,
    useNavigate: () => navigateMock,
    useParams: () => ({ id: '1' }),
  };
});

const members = [
  { id: 1, name: 'Isac' },
  { id: 2, name: 'João' },
];

function mockGetResponses() {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.includes('/members')) {
      return Promise.resolve({ data: members });
    }
    if (url.includes('/me')) {
      return Promise.resolve({ data: { id: 1 } });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

async function renderForm() {
  render(
    <MemoryRouter>
      <ExpenseForm />
    </MemoryRouter>
  );

  await screen.findByRole('checkbox', { name: 'João' });
}

async function selectExpenseType(label: string) {
  await userEvent.click(screen.getByLabelText('Tipo de despesa'));
  const listbox = await screen.findByRole('listbox');
  await userEvent.click(within(listbox).getByText(label));
}

describe('ExpenseForm', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    vi.mocked(trackEvent).mockClear();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.post).mockResolvedValue({ data: { expense_id: 1 } });
    mockGetResponses();
  });

  it('creates an "à vista" expense with a single paid quota (regression) and returns to the listing', async () => {
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
    await userEvent.type(screen.getByLabelText('Valor'), '150,00');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload).toMatchObject({
      expense_type: 'IN_CASH',
      installments: 1,
      payers: [1, 2],
    });
    expect(payload.quotas).toEqual([
      { number: 1, date_expected: expect.any(String), paid: true, value_quota: 150 },
    ]);
    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses');
  });

  it('creates an "parcelada" expense with N quotas, rounding absorbed in the last one', async () => {
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Sofá');
    await userEvent.type(screen.getByLabelText('Valor'), '100,00');
    await selectExpenseType('Parcelada');

    const installmentsField = await screen.findByLabelText('Quantidade de parcelas');
    await userEvent.type(installmentsField, '3');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.expense_type).toBe('IN_INSTALLMENTS');
    expect(payload.installments).toBe(3);
    expect(payload.quotas).toHaveLength(3);
    expect(payload.quotas.every(q => q.paid === false)).toBe(true);
    const total = payload.quotas.reduce((sum, q) => sum + q.value_quota, 0);
    expect(total).toBeCloseTo(100, 2);
  });

  it('creates a "fixa" expense with a single unpaid quota', async () => {
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Aluguel');
    await userEvent.type(screen.getByLabelText('Valor'), '1200,00');
    await selectExpenseType('Fixa');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.expense_type).toBe('FIXED');
    expect(payload.installments).toBe(1);
    expect(payload.quotas).toEqual([
      { number: 1, date_expected: expect.any(String), paid: false, value_quota: 1200 },
    ]);
  });

  it('lets the user uncheck a participant so payers[] excludes them', async () => {
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1]);
  });

  it('defaults all participants checked even when members resolve after mount (regression)', async () => {
    let resolveMembers: (value: { data: typeof members }) => void = () => {};
    const membersPromise = new Promise<{ data: typeof members }>(resolve => {
      resolveMembers = resolve;
    });

    vi.mocked(axios.get).mockImplementation((url: string) => {
      if (url.includes('/members')) {
        return membersPromise;
      }
      if (url.includes('/me')) {
        return Promise.resolve({ data: { id: 1 } });
      }
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });

    render(
      <MemoryRouter>
        <ExpenseForm />
      </MemoryRouter>
    );

    resolveMembers({ data: members });
    await screen.findByRole('checkbox', { name: 'João' });

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');
    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    const [, payload] = lastPostCall<ExpensePayload>();
    expect(payload.payers).toEqual([1, 2]);
  });

  it('blocks saving with an in-app alert (never window.alert) when no participant is selected', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
    await userEvent.type(screen.getByLabelText('Valor'), '50,00');

    await userEvent.click(screen.getByRole('checkbox', { name: 'Isac' }));
    await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(axios.post).not.toHaveBeenCalled();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Selecione ao menos um participante da divisão.'
    );
    expect(alertSpy).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('surfaces the backend error message in an in-app alert (never window.alert) when the API rejects', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.mocked(axios.post).mockRejectedValueOnce({
      response: { data: { error: 'Não é possível alterar dados de uma competência já fechada.' } },
    });
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Adestrador');
    await userEvent.type(screen.getByLabelText('Valor'), '1754,40');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Não é possível alterar dados de uma competência já fechada.'
    );
    expect(alertSpy).not.toHaveBeenCalled();
    expect(navigateMock).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('falls back to a generic in-app message when the API error has no body', async () => {
    const alertSpy = vi.spyOn(window, 'alert').mockImplementation(() => {});
    vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
    await renderForm();

    await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
    await userEvent.type(screen.getByLabelText('Valor'), '80,00');

    await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(axios.post).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toHaveTextContent('Falha ao salvar despesa.');
    expect(alertSpy).not.toHaveBeenCalled();
    alertSpy.mockRestore();
  });

  it('navigates back to the listing when Cancelar is clicked', async () => {
    await renderForm();

    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses');
  });

  // Pagamento registrado no cadastro (docs/feature/20261027-pagamento-no-cadastro-despesa,
  // plan §6): seção opcional com foto e descrição do pagamento. Sem preencher nada, o
  // cadastro é o de sempre (CA-F1) e os testes acima passam sem edição.
  describe('seção "Pagamento (opcional)"', () => {
    const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

    const fileInput = () => document.querySelector('input[type="file"]') as HTMLInputElement;
    const noteField = () => screen.getByLabelText('Descrição do pagamento (opcional)');
    const photo = (name = 'cartao.jpg') => new File(['conteudo'], name, { type: 'image/jpeg' });
    const pickFile = (file: File) => fireEvent.change(fileInput(), { target: { files: [file] } });
    const save = () => userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

    const oversizedPhoto = () => {
      const big = new File(['x'], 'grande.jpg', { type: 'image/jpeg' });
      Object.defineProperty(big, 'size', { value: 5 * 1024 * 1024 + 1 });
      return big;
    };

    async function fillBasics() {
      await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
      await userEvent.type(screen.getByLabelText('Valor'), '150,00');
    }

    async function savedRequest<T>() {
      await waitFor(() => expect(axios.post).toHaveBeenCalled());
      const calls = vi.mocked(axios.post).mock.calls;
      return calls[calls.length - 1] as unknown as [string, T, { headers: Record<string, string> }];
    }

    async function selectPayer(name: string) {
      await userEvent.click(screen.getByLabelText('Pagador'));
      const listbox = await screen.findByRole('listbox');
      await userEvent.click(within(listbox).getByText(name));
    }

    it('sends the same JSON as before when the section is empty, plus the request key (CA-F1)', async () => {
      await renderForm();
      await fillBasics();
      await save();

      const [, body] = await savedRequest<Record<string, unknown>>();

      expect(body).not.toBeInstanceOf(FormData);
      expect(Object.keys(body).sort()).toEqual([
        'client_request_id',
        'date_payment',
        'description',
        'expense_type',
        'group_id',
        'installments',
        'payers',
        'quotas',
        'total_value',
        'user_creator_id',
        'user_payer_id',
      ]);
      expect(body.client_request_id).toMatch(UUID_V4);
      expect(body).toMatchObject({ description: 'Mercado', total_value: 150, group_id: 1, user_payer_id: 1, user_creator_id: 1 });
    });

    it('sends descricao_pagamento in JSON, trimmed, when only the note is filled (CA-F2)', async () => {
      await renderForm();
      await fillBasics();
      await userEvent.type(noteField(), '  Cartão da Ana  ');
      await save();

      const [, body] = await savedRequest<Record<string, unknown>>();

      expect(body).not.toBeInstanceOf(FormData);
      expect(body.descricao_pagamento).toBe('Cartão da Ana');
      expect(body.description).toBe('Mercado');
    });

    it('sends multipart with comprovante, payers[] and quotas[i][...] when there is a photo (CA-F3)', async () => {
      await renderForm();
      await fillBasics();
      pickFile(photo());
      await save();

      const [url, body, config] = await savedRequest<FormData>();

      expect(url).toMatch(/\/api\/expenses$/);
      expect(body).toBeInstanceOf(FormData);
      expect((body.get('comprovante') as File).name).toBe('cartao.jpg');
      expect(body.getAll('payers[]')).toEqual(['1', '2']);
      expect(body.get('description')).toBe('Mercado');
      expect(body.get('total_value')).toBe('150');
      expect(body.get('user_payer_id')).toBe('1');
      expect(body.get('quotas[0][number]')).toBe('1');
      expect(body.get('quotas[0][value_quota]')).toBe('150');
      expect(body.get('quotas[0][date_expected]')).toEqual(expect.any(String));
      expect(body.get('client_request_id')).toMatch(UUID_V4);
      expect(body.has('descricao_pagamento')).toBe(false);
      // O navegador define o Content-Type com o boundary; fixá-lo quebraria o multipart.
      expect(Object.keys(config.headers).map(name => name.toLowerCase())).not.toContain('content-type');
    });

    it('does not send a whitespace-only note, with or without a photo (CA-F4)', async () => {
      await renderForm();
      await fillBasics();
      await userEvent.type(noteField(), '   ');
      await save();

      const [, json] = await savedRequest<Record<string, unknown>>();
      expect(json).not.toHaveProperty('descricao_pagamento');

      await waitFor(() => expect(screen.getByRole('button', { name: 'Salvar' })).toBeEnabled());
      vi.mocked(axios.post).mockClear();
      pickFile(photo());
      await save();

      const [, form] = await savedRequest<FormData>();
      expect(form).toBeInstanceOf(FormData);
      expect(form.has('descricao_pagamento')).toBe(false);
    });

    it('keeps the two descriptions apart by label and counts the payment note up to 500 (CA-F5)', async () => {
      await renderForm();

      // `getByLabelText` com texto exato: só o campo da despesa se chama "Descrição".
      expect(screen.getByLabelText('Descrição')).not.toBe(noteField());
      expect(screen.getByText('Pagamento (opcional)')).toBeInTheDocument();
      expect(screen.getByText('0/500')).toBeInTheDocument();
      expect(noteField()).toHaveAttribute('maxlength', '500');

      await userEvent.type(noteField(), 'Pix');

      expect(screen.getByText('3/500')).toBeInTheDocument();
    });

    it('says nothing is marked as paid while the section is empty', async () => {
      await renderForm();

      expect(screen.queryByText(/será marcada como paga/)).not.toBeInTheDocument();
      expect(screen.queryByText(/Serão marcadas como pagas/)).not.toBeInTheDocument();
      expect(screen.queryByText(/não poderá ser alterado/)).not.toBeInTheDocument();
      expect(screen.getByText(/Se esta despesa já foi paga/)).toBeInTheDocument();
    });

    it('shows the occurrence text per type and date and the fixed warning, with no confirmation step (CA-F6)', async () => {
      await renderForm();
      await userEvent.type(noteField(), 'Cartão');

      expect(screen.getByText(/Esta despesa será marcada como paga \(competência de /)).toBeInTheDocument();
      expect(
        screen.getByText('Depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado.')
      ).toBeInTheDocument();

      await selectExpenseType('Parcelada');
      await userEvent.type(await screen.findByLabelText('Quantidade de parcelas'), '3');

      expect(screen.getByText(/Serão marcadas como pagas as 3 parcelas/)).toBeInTheDocument();
      expect(screen.getByText(/O valor não poderá ser alterado depois\./)).toBeInTheDocument();

      await selectExpenseType('Fixa');
      fireEvent.change(screen.getByLabelText('Data de início'), { target: { value: '2026-09-05' } });

      expect(
        screen.getByText('Será marcada como paga apenas a ocorrência de setembro de 2026; as próximas continuam pendentes.')
      ).toBeInTheDocument();
      expect(
        screen.getByText('Depois do fechamento do ciclo, o pagamento desta compra não poderá ser alterado.')
      ).toBeInTheDocument();

      await userEvent.type(screen.getByLabelText('Descrição'), 'Aluguel');
      await userEvent.type(screen.getByLabelText('Valor'), '1200,00');
      await save();

      // Nenhuma tela entre o clique em Salvar e a requisição.
      await savedRequest<Record<string, unknown>>();
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('enables the section for another payer and says it is in their name (CA-F7)', async () => {
      await renderForm();
      await fillBasics();
      await selectPayer('João');
      await userEvent.type(noteField(), 'Cartão do João');

      expect(noteField()).toBeEnabled();
      expect(screen.getByText('O pagamento será registrado em nome de João, que será avisado.')).toBeInTheDocument();

      await save();

      const [, body] = await savedRequest<Record<string, unknown>>();
      expect(body).toMatchObject({ user_payer_id: 2, user_creator_id: 1, descricao_pagamento: 'Cartão do João' });
    });

    it('does not mention another payer when the payer is the user', async () => {
      await renderForm();
      await userEvent.type(noteField(), 'Cartão');

      expect(screen.queryByText(/registrado em nome de/)).not.toBeInTheDocument();
    });

    it('warns before sending about a photo over 5 MB or a file that is not an image, and does not keep it (CA-F10)', async () => {
      await renderForm();

      pickFile(oversizedPhoto());

      expect(await screen.findByText('A foto passa de 5 MB. Escolha uma menor ou reduza a qualidade.')).toBeInTheDocument();
      expect(screen.queryByText('grande.jpg')).not.toBeInTheDocument();

      pickFile(new File(['x'], 'boleto.pdf', { type: 'application/pdf' }));

      expect(await screen.findByText('Escolha uma imagem (JPG, PNG ou semelhante) como comprovante.')).toBeInTheDocument();
      expect(screen.queryByText('A foto passa de 5 MB. Escolha uma menor ou reduza a qualidade.')).not.toBeInTheDocument();

      pickFile(photo());

      await waitFor(() => expect(screen.queryByText(/Escolha uma imagem/)).not.toBeInTheDocument());
      expect(screen.getByRole('button', { name: /cartao\.jpg/ })).toBeInTheDocument();
    });

    it('sends no photo, and no payment, after an invalid file was refused (CA-F10)', async () => {
      await renderForm();
      await fillBasics();
      pickFile(oversizedPhoto());
      await screen.findByText(/A foto passa de 5 MB/);
      await save();

      const [, body] = await savedRequest<Record<string, unknown>>();

      expect(body).not.toBeInstanceOf(FormData);
      expect(body).not.toHaveProperty('descricao_pagamento');
    });

    it('has no capture attribute on the file input, so the library and files stay available on a phone (CA-F11)', async () => {
      await renderForm();

      expect(fileInput()).toHaveAttribute('accept', 'image/*');
      expect(fileInput()).not.toHaveAttribute('capture');
    });

    it('goes back to JSON, without comprovante, when the chosen photo is removed', async () => {
      await renderForm();
      await fillBasics();
      pickFile(photo());

      await userEvent.click(screen.getByRole('button', { name: 'Remover foto' }));

      expect(screen.queryByRole('button', { name: 'Remover foto' })).not.toBeInTheDocument();
      expect(screen.getByRole('button', { name: /Anexar foto do comprovante/ })).toBeInTheDocument();

      await save();

      const [, body] = await savedRequest<Record<string, unknown>>();
      expect(body).not.toBeInstanceOf(FormData);
    });
  });

  describe('medição da despesa registrada (expense_created)', () => {
    // Pega a medição que não existe, ou que dispara depois do navigate.
    it('mede expense_created, uma vez, antes de voltar para a listagem', async () => {
      await renderForm();

      await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
      await userEvent.type(screen.getByLabelText('Valor'), '150,00');
      await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

      await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/groups/1/expenses'));
      expect(trackEvent).toHaveBeenCalledTimes(1);
      expect(trackEvent).toHaveBeenCalledWith('expense_created');
      expect(vi.mocked(trackEvent).mock.invocationCallOrder[0]).toBeLessThan(
        navigateMock.mock.invocationCallOrder[0]
      );
    });

    // Pega a medição disparada antes de saber se a API aceitou: a competência
    // fechada recusa a despesa e ela contaria como registrada.
    it('não mede quando a API recusa a despesa', async () => {
      vi.mocked(axios.post).mockRejectedValueOnce({
        response: { data: { error: 'Não é possível alterar dados de uma competência já fechada.' } },
      });
      await renderForm();

      await userEvent.type(screen.getByLabelText('Descrição'), 'Adestrador');
      await userEvent.type(screen.getByLabelText('Valor'), '1754,40');
      await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Não é possível alterar dados de uma competência já fechada.'
      );
      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede quando o salvamento falha por erro de rede', async () => {
      vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network Error'));
      vi.spyOn(console, 'error').mockImplementation(() => {});
      await renderForm();

      await userEvent.type(screen.getByLabelText('Descrição'), 'Mercado');
      await userEvent.type(screen.getByLabelText('Valor'), '80,00');
      await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent('Falha ao salvar despesa.');
      expect(trackEvent).not.toHaveBeenCalled();
    });

    // Pega a medição colocada antes da validação local: o clique em Salvar sem
    // participante nem chega à API.
    it('não mede quando o formulário é bloqueado pela validação local', async () => {
      await renderForm();

      await userEvent.type(screen.getByLabelText('Descrição'), 'Cinema');
      await userEvent.type(screen.getByLabelText('Valor'), '50,00');
      await userEvent.click(screen.getByRole('checkbox', { name: 'Isac' }));
      await userEvent.click(screen.getByRole('checkbox', { name: 'João' }));
      await userEvent.click(screen.getByRole('button', { name: 'Salvar' }));

      expect(await screen.findByRole('alert')).toHaveTextContent(
        'Selecione ao menos um participante da divisão.'
      );
      expect(axios.post).not.toHaveBeenCalled();
      expect(trackEvent).not.toHaveBeenCalled();
    });

    it('não mede ao cancelar', async () => {
      await renderForm();

      await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

      expect(trackEvent).not.toHaveBeenCalled();
    });
  });
});
