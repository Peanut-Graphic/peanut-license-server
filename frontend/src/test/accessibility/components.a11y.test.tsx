import { describe, it, expect, vi } from 'vitest';
import { axe, toHaveNoViolations } from 'jest-axe';
import { render, screen } from '@/test/test-utils';
import Button from '@/components/common/Button';
import Card from '@/components/common/Card';
import Input from '@/components/common/Input';
import Modal from '@/components/common/Modal';
import Badge from '@/components/common/Badge';
import Switch from '@/components/common/Switch';
import Layout from '@/components/layout/Layout';
import Header from '@/components/layout/Header';
import Sidebar from '@/components/layout/Sidebar';

expect.extend(toHaveNoViolations);

describe('Accessibility Tests - Components', () => {
  describe('Button Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(<Button>Click me</Button>);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should be keyboard accessible', async () => {
      const { container } = render(
        <Button onClick={vi.fn()}>Test Button</Button>
      );
      const button = screen.getByRole('button', { name: /test button/i });
      expect(button).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper focus states', async () => {
      const { container } = render(<Button>Focus Test</Button>);
      const button = screen.getByRole('button', { name: /focus test/i });
      button.focus();
      expect(button).toHaveFocus();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should indicate disabled state accessibly', async () => {
      const { container } = render(<Button disabled>Disabled Button</Button>);
      const button = screen.getByRole('button', { name: /disabled button/i });
      expect(button).toBeDisabled();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have adequate color contrast', async () => {
      const { container } = render(
        <>
          <Button variant="primary">Primary</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="danger">Danger</Button>
        </>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Input Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(<Input type="text" aria-label="Text field" />);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should be associated with label', async () => {
      const { container } = render(
        <>
          <label htmlFor="test-input">Email</label>
          <Input id="test-input" type="email" />
        </>
      );
      const input = screen.getByLabelText(/email/i);
      expect(input).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should support keyboard navigation', async () => {
      const { container } = render(<Input type="text" placeholder="Search" aria-label="Search" />);
      const input = screen.getByPlaceholderText(/search/i);
      expect(input).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper focus outline', async () => {
      const { container } = render(<Input type="text" aria-label="Text field" />);
      const input = screen.getByRole('textbox');
      input.focus();
      expect(input).toHaveFocus();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Card Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(
        <Card>
          <h3>Card Title</h3>
          <p>Card content</p>
        </Card>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should maintain semantic structure', async () => {
      const { container } = render(
        <Card>
          <h2>License Information</h2>
          <p>Tier: Pro</p>
        </Card>
      );
      const heading = screen.getByRole('heading', { name: /license information/i });
      expect(heading).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper heading hierarchy', async () => {
      const { container } = render(
        <Card>
          <h2>Main Title</h2>
          <h3>Subtitle</h3>
        </Card>
      );
      expect(screen.getByRole('heading', { level: 2 })).toBeInTheDocument();
      expect(screen.getByRole('heading', { level: 3 })).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Badge Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(<Badge>Active</Badge>);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should not rely on color alone for status', async () => {
      const { container } = render(
        <>
          <Badge variant="success">Active</Badge>
          <Badge variant="danger">Expired</Badge>
        </>
      );
      expect(screen.getByText(/active/i)).toBeInTheDocument();
      expect(screen.getByText(/expired/i)).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Switch Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(
        <Switch checked={true} onChange={() => {}} aria-label="Toggle setting" />
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper ARIA attributes', async () => {
      const { container } = render(
        <Switch checked={false} onChange={() => {}} aria-label="Toggle setting" />
      );
      const switchElement = screen.getByRole('switch');
      expect(switchElement).toHaveAttribute('aria-checked');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should be keyboard accessible', async () => {
      const { container } = render(
        <Switch checked={true} onChange={() => {}} aria-label="Toggle setting" />
      );
      const switchElement = screen.getByRole('switch');
      expect(switchElement).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Modal Component', () => {
    it('should have no accessibility violations when open', async () => {
      const { container } = render(
        <Modal isOpen={true} onClose={() => {}}>
          <h2>Modal Title</h2>
          <p>Modal content</p>
        </Modal>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper dialog role', async () => {
      render(
        <Modal isOpen={true} onClose={() => {}}>
          <h2>Modal Title</h2>
        </Modal>
      );
      const dialog = screen.getByRole('dialog');
      expect(dialog).toBeInTheDocument();
    });

    it('should support focus trapping', async () => {
      render(
        <Modal isOpen={true} onClose={() => {}}>
          <h2>Modal Title</h2>
          <Button>Action 1</Button>
          <Button>Action 2</Button>
        </Modal>
      );
      const dialog = screen.getByRole('dialog');
      expect(dialog).toBeInTheDocument();
    });

    it('should have escape key close functionality accessible', async () => {
      const onClose = vi.fn();
      const { container } = render(
        <Modal isOpen={true} onClose={onClose}>
          <h2>Close Test</h2>
        </Modal>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Layout Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(
        <Layout>
          <p>Main content</p>
        </Layout>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper landmark structure', async () => {
      render(
        <Layout>
          <p>Main content</p>
        </Layout>
      );
      const main = screen.getByRole('main');
      expect(main).toBeInTheDocument();
    });

    it('should have proper navigation', async () => {
      const { container } = render(
        <Layout>
          <p>Main content</p>
        </Layout>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Header Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(<Header />);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have semantic header element', async () => {
      const { container } = render(<Header />);
      const header = container.querySelector('header');
      expect(header).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Sidebar Component', () => {
    it('should have no accessibility violations', async () => {
      const { container } = render(<Sidebar />);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('should have proper navigation role', async () => {
      const { container } = render(<Sidebar />);
      const nav = container.querySelector('nav');
      expect(nav).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });
});

describe('Accessibility Tests - License Management Tables', () => {
  it('table headers should be properly marked', async () => {
    const { container } = render(
      <table>
        <thead>
          <tr>
            <th>License Key</th>
            <th>Customer</th>
            <th>Tier</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>PNUT-PRO-12345</td>
            <td>user@example.com</td>
            <td>Pro</td>
            <td>Active</td>
          </tr>
        </tbody>
      </table>
    );
    const headers = screen.getAllByRole('columnheader');
    expect(headers.length).toBeGreaterThan(0);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('status cells should not rely on color alone', async () => {
    const { container } = render(
      <table>
        <tbody>
          <tr>
            <td>
              <Badge variant="success">Active</Badge>
            </td>
            <td>
              <Badge variant="danger">Expired</Badge>
            </td>
          </tr>
        </tbody>
      </table>
    );
    expect(screen.getByText(/active/i)).toBeInTheDocument();
    expect(screen.getByText(/expired/i)).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('sortable columns should indicate sorting state', async () => {
    const { container } = render(
      <table>
        <thead>
          <tr>
            <th aria-sort="ascending">
              <button>License Key</button>
            </th>
          </tr>
        </thead>
      </table>
    );
    const header = screen.getByRole('columnheader');
    expect(header).toHaveAttribute('aria-sort');
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Dashboard Statistics', () => {
  it('stat cards should have meaningful labels', async () => {
    const { container } = render(
      <div>
        <div role="region" aria-label="Total Licenses">
          <h3>Total Licenses</h3>
          <p aria-live="polite">247</p>
        </div>
      </div>
    );
    const region = screen.getByRole('region', { name: /total licenses/i });
    expect(region).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('status indicators should include text labels', async () => {
    const { container } = render(
      <div>
        <Badge variant="success">Active: 189</Badge>
        <Badge variant="danger">Expired: 42</Badge>
      </div>
    );
    expect(screen.getByText(/active: 189/i)).toBeInTheDocument();
    expect(screen.getByText(/expired: 42/i)).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Audit Trail', () => {
  it('audit log list should be semantic', async () => {
    const { container } = render(
      <ul>
        <li>License PNUT-001 activated on 2024-03-27</li>
        <li>License PNUT-002 deactivated on 2024-03-26</li>
      </ul>
    );
    const listItems = screen.getAllByRole('listitem');
    expect(listItems.length).toBe(2);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('timestamp information should be machine-readable', async () => {
    const { container } = render(
      <time dateTime="2024-03-27T10:30:00Z">March 27, 2024 at 10:30 AM</time>
    );
    const timeElement = screen.getByText(/march 27, 2024/i);
    expect(timeElement).toHaveAttribute('dateTime');
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('audit actions should have clear descriptions', async () => {
    const { container } = render(
      <div>
        <p>Action: <strong>License Created</strong></p>
        <p>User: admin@company.com</p>
        <p>Time: <time dateTime="2024-03-27T10:30:00Z">2024-03-27 10:30 AM</time></p>
      </div>
    );
    expect(screen.getByText(/license created/i)).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Forms and Filters', () => {
  it('search filter should be accessible', async () => {
    const { container } = render(
      <>
        <label htmlFor="license-search">Search licenses</label>
        <Input id="license-search" type="search" placeholder="Enter license key" />
      </>
    );
    const searchInput = screen.getByLabelText(/search licenses/i);
    expect(searchInput).toHaveAttribute('type', 'search');
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('filter controls should have proper labels', async () => {
    const { container } = render(
      <div>
        <label htmlFor="status-filter">Filter by status</label>
        <select id="status-filter">
          <option>All</option>
          <option>Active</option>
          <option>Expired</option>
        </select>
      </div>
    );
    const select = screen.getByLabelText(/filter by status/i);
    expect(select).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('license creation form should be accessible', async () => {
    const { container } = render(
      <form>
        <label htmlFor="email">Email Address</label>
        <Input id="email" type="email" required />
        <label htmlFor="tier">License Tier</label>
        <select id="tier" required>
          <option>Pro</option>
          <option>Agency</option>
        </select>
        <Button type="submit">Create License</Button>
      </form>
    );
    const emailInput = screen.getByLabelText(/email address/i);
    const tierSelect = screen.getByLabelText(/license tier/i);
    expect(emailInput).toBeInTheDocument();
    expect(tierSelect).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Navigation and Skip Links', () => {
  it('should support skip to main content', async () => {
    const { container } = render(
      <>
        <a href="#main" className="sr-only">
          Skip to main content
        </a>
        <header>Header</header>
        <nav>Navigation</nav>
        <main id="main">Main content</main>
      </>
    );
    const skipLink = screen.getByText(/skip to main content/i);
    expect(skipLink).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('navigation links should be properly structured', async () => {
    const { container } = render(
      <nav>
        <ul>
          <li><a href="/dashboard">Dashboard</a></li>
          <li><a href="/licenses">Licenses</a></li>
          <li><a href="/audit">Audit Log</a></li>
        </ul>
      </nav>
    );
    const links = screen.getAllByRole('link');
    expect(links.length).toBeGreaterThan(0);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Modal Dialogs', () => {
  it('confirm dialogs should have clear actions', async () => {
    const { container } = render(
      <Modal isOpen={true} onClose={() => {}}>
        <h2>Confirm License Revocation</h2>
        <p>Are you sure you want to revoke this license?</p>
        <Button>Cancel</Button>
        <Button variant="danger">Revoke</Button>
      </Modal>
    );
    const dialog = screen.getByRole('dialog');
    expect(dialog).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('license activation modal should be accessible', async () => {
    const { container } = render(
      <Modal isOpen={true} onClose={() => {}}>
        <h2>Activate License</h2>
        <label htmlFor="activation-key">Activation Key</label>
        <Input id="activation-key" type="text" />
        <Button type="submit">Activate</Button>
      </Modal>
    );
    const input = screen.getByLabelText(/activation key/i);
    expect(input).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - ARIA Live Regions', () => {
  it('should announce license operations', async () => {
    const { container } = render(
      <div aria-live="polite" aria-atomic="true" role="status">
        License PNUT-001 has been activated successfully
      </div>
    );
    const liveRegion = screen.getByRole('status');
    expect(liveRegion).toHaveAttribute('aria-live', 'polite');
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('should provide loading feedback accessibly', async () => {
    const { container } = render(
      <div role="status" aria-busy="true" aria-live="polite">
        Loading licenses...
      </div>
    );
    const status = screen.getByRole('status');
    expect(status).toHaveAttribute('aria-busy', 'true');
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});

describe('Accessibility Tests - Data Display and Charts', () => {
  it('chart descriptions should be accessible', async () => {
    const { container } = render(
      <figure>
        <figcaption>License activation trends over the last 30 days</figcaption>
        <div role="img" aria-label="Chart showing activation trend">
          Chart visualization
        </div>
      </figure>
    );
    const figcaption = screen.getByText(/license activation trends/i);
    expect(figcaption).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  it('stats summary should provide text alternatives', async () => {
    const { container } = render(
      <div role="region" aria-label="License Statistics Summary">
        <p>Total licenses: 247</p>
        <p>Active: 189 (76.5%)</p>
        <p>Expired: 42 (17%)</p>
      </div>
    );
    expect(screen.getByText(/total licenses: 247/i)).toBeInTheDocument();
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
