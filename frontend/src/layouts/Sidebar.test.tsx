import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import Sidebar from './Sidebar';
import type { GroupNavItem } from './group/GroupSidebar';

const items: GroupNavItem[] = [
  { label: 'Resumo', icon: HomeOutlinedIcon, to: '/summary' },
  {
    label: 'Configurações',
    icon: SettingsOutlinedIcon,
    children: [{ label: 'Grupos', icon: HomeOutlinedIcon, to: '/dashboard' }],
  },
];

function renderSidebar(initialPath = '/summary') {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Sidebar items={items} />
    </MemoryRouter>
  );
}

describe('Sidebar', () => {
  it('renders a plain item without children as a direct link', () => {
    renderSidebar();

    expect(screen.getByRole('link', { name: 'Resumo' })).toHaveAttribute('href', '/summary');
  });

  it('renders an item with children collapsed by default, without leaking the child link', () => {
    renderSidebar();

    expect(screen.getByText('Configurações')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Grupos' })).not.toBeInTheDocument();
  });

  it('expands the children on click, revealing the nested link', async () => {
    const user = userEvent.setup();
    renderSidebar();

    await user.click(screen.getByText('Configurações'));

    expect(screen.getByRole('link', { name: 'Grupos' })).toHaveAttribute('href', '/dashboard');
  });

  it('auto-expands when the current route matches a child item', () => {
    renderSidebar('/dashboard');

    expect(screen.getByRole('link', { name: 'Grupos' })).toHaveAttribute('href', '/dashboard');
  });
});
