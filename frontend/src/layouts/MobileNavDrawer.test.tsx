import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import HomeOutlinedIcon from '@mui/icons-material/HomeOutlined';
import MobileNavDrawer from './MobileNavDrawer';
import type { GroupNavItem } from './group/GroupSidebar';

const items: GroupNavItem[] = [{ label: 'Resumo', icon: HomeOutlinedIcon, to: '/summary' }];

function renderDrawer(open: boolean, onClose: () => void, drawerItems: GroupNavItem[] = items) {
  return render(
    <MemoryRouter initialEntries={['/summary']}>
      <MobileNavDrawer items={drawerItems} open={open} onClose={onClose} />
    </MemoryRouter>
  );
}

describe('MobileNavDrawer', () => {
  it('shows the navigation items when open', () => {
    renderDrawer(true, vi.fn());

    expect(screen.getByRole('link', { name: 'Resumo' })).toHaveAttribute('href', '/summary');
  });

  it('does not expose the navigation items to accessibility queries when closed', () => {
    renderDrawer(false, vi.fn());

    expect(screen.queryByRole('link', { name: 'Resumo' })).not.toBeInTheDocument();
  });

  it('calls onClose when a navigation item is clicked', async () => {
    const onClose = vi.fn();
    const user = userEvent.setup();
    renderDrawer(true, onClose);

    await user.click(screen.getByRole('link', { name: 'Resumo' }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when an action item (e.g. "Sair") is clicked', async () => {
    const onAction = vi.fn();
    const onClose = vi.fn();
    const user = userEvent.setup();
    renderDrawer(true, onClose, [{ label: 'Sair', icon: HomeOutlinedIcon, onAction }]);

    await user.click(screen.getByText('Sair'));

    expect(onAction).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
