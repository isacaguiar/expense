import { render, screen, within } from '@testing-library/react';
import axios from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { GroupMember, MemberResponsible } from '../types/group';
import GroupMembersForm from './GroupMembersForm';

/**
 * Feature 20261112-ajustes-de-texto-participantes-e-pagamentos (TASK-610, CA-1 e CA-2): o selo "Paga por
 * {nome}" só existe nas linhas dos OUTROS membros. Na linha do usuário logado o texto "Você paga por: …"
 * já diz quem ele paga, e os selos repetiam isso. Arquivo próprio, com o mesmo harness de
 * `GroupMembersForm.responsible.test.tsx`.
 *
 * Nos dados, `person(2, 'Bruno', [ANA_PAYER])` quer dizer "Ana paga por Bruno": o selo "Paga por Bruno"
 * seria desenhado na linha da Ana.
 */

vi.mock('axios');

vi.mock('react-router-dom', async importOriginal => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => vi.fn(), useParams: () => ({ id: '1' }) };
});

const person = (id: number, name: string, responsibles: MemberResponsible[] = []): GroupMember => ({
  id,
  name,
  email: `${name.toLowerCase()}@example.com`,
  avatar_url: null,
  responsibles
});

const who = (id: number, name: string): MemberResponsible => ({ id, name });
const ANA_PAYER = who(1, 'Ana');
const BRUNO_PAYER = who(2, 'Bruno');

/** O usuário logado é o 1 (Ana), criadora do grupo. */
function mockApi(members: GroupMember[]) {
  vi.mocked(axios.get).mockImplementation((url: string) => {
    if (url.endsWith('/api/me')) return Promise.resolve({ data: { id: 1 } });
    if (url.endsWith('/invitations')) return Promise.resolve({ data: [] });
    if (url.includes('/members')) return Promise.resolve({ data: members });
    return Promise.resolve({
      data: {
        id: 1,
        name: 'Casa dos Amigos',
        description: 'Contas da casa',
        created_by: 1,
        creator: { id: 1, email: 'ana@example.com' }
      }
    });
  });
}

function renderForm() {
  render(
    <MemoryRouter>
      <GroupMembersForm />
    </MemoryRouter>
  );
}

const rowOf = (email: string): HTMLElement => screen.getByText(email).closest('li') as HTMLElement;

const BADGE = /^Paga por/;

describe('GroupMembersForm: selos "Paga por {nome}" só nas linhas dos outros membros', () => {
  beforeEach(() => {
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.put).mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.delete).mockReset();
  });

  it('on the own row shows "Você paga por: …" and no badge, even with dependents', async () => {
    mockApi([person(1, 'Ana'), person(2, 'Bruno', [ANA_PAYER]), person(3, 'Carla', [ANA_PAYER])]);
    renderForm();

    await screen.findByText('bruno@example.com');

    const ana = within(rowOf('ana@example.com'));
    expect(ana.getByText('Você paga por: Bruno, Carla')).toBeInTheDocument();
    expect(ana.queryByText(BADGE)).not.toBeInTheDocument();
    // Ninguém mais paga por ninguém: a tela inteira fica sem selo.
    expect(screen.queryByText(BADGE)).not.toBeInTheDocument();
  });

  it('on another member row keeps one badge per dependent, and the own row has none', async () => {
    mockApi([person(1, 'Ana'), person(2, 'Bruno'), person(3, 'Carla', [BRUNO_PAYER]), person(4, 'Davi', [BRUNO_PAYER])]);
    renderForm();

    await screen.findByText('bruno@example.com');

    const bruno = within(rowOf('bruno@example.com'));
    expect(bruno.getByText('Paga por Carla')).toBeInTheDocument();
    expect(bruno.getByText('Paga por Davi')).toBeInTheDocument();
    expect(within(rowOf('ana@example.com')).queryByText(BADGE)).not.toBeInTheDocument();
    // O texto "Você paga por" é só da linha própria, que aqui não paga por ninguém.
    expect(screen.queryByText(/Você paga por/)).not.toBeInTheDocument();
  });

  it('applies each rule to its own row when the user and another member both pay for someone', async () => {
    // Ana paga por Bruno; Bruno paga por Carla.
    mockApi([person(1, 'Ana'), person(2, 'Bruno', [ANA_PAYER]), person(3, 'Carla', [BRUNO_PAYER])]);
    renderForm();

    await screen.findByText('bruno@example.com');

    const ana = within(rowOf('ana@example.com'));
    expect(ana.getByText('Você paga por: Bruno')).toBeInTheDocument();
    expect(ana.queryByText(BADGE)).not.toBeInTheDocument();

    const bruno = within(rowOf('bruno@example.com'));
    expect(bruno.getByText('Paga por Carla')).toBeInTheDocument();
    expect(bruno.queryByText(/Você paga por/)).not.toBeInTheDocument();

    expect(within(rowOf('carla@example.com')).queryByText(BADGE)).not.toBeInTheDocument();
    expect(screen.getAllByText(BADGE)).toHaveLength(1);
  });
});
