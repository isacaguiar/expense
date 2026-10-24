import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import Sidebar from './Sidebar';
import GroupHeader from './group/GroupHeader';

// O Relatório de Fechamento esconde o que leva `no-print` na folha impressa
// (regra em `ClosingReportPanel`). Estes são os dois pedaços do shell que ficam
// ao redor do relatório e não podem sair no papel: o menu lateral e o cabeçalho.

vi.mock('../components/NotificationsMenu', () => ({ default: () => null }));

describe('shell chrome that must stay off the printed sheet', () => {
  it('marks the sidebar navigation with no-print', () => {
    const { container } = render(
      <MemoryRouter>
        <Sidebar items={[{ label: 'Resumo', icon: HomeOutlinedIcon, to: '/summary' }]} />
      </MemoryRouter>
    );

    const nav = container.querySelector('nav');
    expect(nav).not.toBeNull();
    expect(nav).toHaveClass('no-print');
  });

  it('marks the group header with no-print', () => {
    const { container } = render(
      <GroupHeader
        title="Relatórios"
        groups={[]}
        groupId=""
        onGroupChange={vi.fn()}
        userName="Ana"
        onMenuClick={vi.fn()}
      />
    );

    const header = container.querySelector('header');
    expect(header).not.toBeNull();
    expect(header).toHaveClass('no-print');
  });

  it('does not mark anything inside the header or the nav with no-print on its own', () => {
    const sidebar = render(
      <MemoryRouter>
        <Sidebar items={[{ label: 'Resumo', icon: HomeOutlinedIcon, to: '/summary' }]} />
      </MemoryRouter>
    );
    const header = render(
      <GroupHeader title="Relatórios" groups={[]} groupId="" onGroupChange={vi.fn()} userName="Ana" onMenuClick={vi.fn()} />
    );

    // Uma única marca por bloco: o pai já some inteiro, repetir nos filhos só confunde.
    expect(sidebar.container.querySelectorAll('.no-print')).toHaveLength(1);
    expect(header.container.querySelectorAll('.no-print')).toHaveLength(1);
  });
});
