import { type ReactNode, useState } from 'react';
import { useTheme } from '@/contexts';
import { Sun, Moon, Monitor, Search, Bell, X } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

interface HeaderProps {
  title: string;
  description?: string;
  action?: ReactNode;
}

export default function Header({ title, description, action }: HeaderProps) {
  const { theme, setTheme, resolvedTheme } = useTheme();
  const [showSearch, setShowSearch] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const navigate = useNavigate();

  const cycleTheme = () => {
    const themes: Array<'light' | 'dark' | 'system'> = ['light', 'dark', 'system'];
    const currentIndex = themes.indexOf(theme);
    const nextTheme = themes[(currentIndex + 1) % themes.length];
    setTheme(nextTheme);
  };

  const getThemeIcon = () => {
    if (theme === 'system') return <Monitor className="w-5 h-5" />;
    if (resolvedTheme === 'dark') return <Moon className="w-5 h-5" />;
    return <Sun className="w-5 h-5" />;
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const query = searchQuery.toLowerCase();
    if (query.includes('license')) {
      navigate('/licenses');
    } else if (query.includes('analytic') || query.includes('stat') || query.includes('chart')) {
      navigate('/analytics');
    } else if (query.includes('audit') || query.includes('log') || query.includes('trail')) {
      navigate('/audit');
    } else if (query.includes('webhook') || query.includes('hook')) {
      navigate('/webhooks');
    } else if (query.includes('gdpr') || query.includes('privacy') || query.includes('export')) {
      navigate('/gdpr');
    } else if (query.includes('product') || query.includes('update')) {
      navigate('/products');
    } else if (query.includes('security') || query.includes('rate')) {
      navigate('/security');
    } else if (query.includes('setting') || query.includes('config')) {
      navigate('/settings');
    } else {
      navigate('/');
    }
    setShowSearch(false);
    setSearchQuery('');
  };

  return (
    <>
      {/* Top bar */}
      <header className="h-14 bg-white border-b border-slate-200 flex items-center justify-end px-6">
        <div className="flex items-center gap-1">
          {/* Search */}
          {showSearch ? (
            <form onSubmit={handleSearch} className="flex items-center gap-2">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search pages..."
                className="w-48 px-3 py-1.5 text-sm text-slate-900 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                autoFocus
              />
              <button
                type="button"
                onClick={() => setShowSearch(false)}
                className="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </form>
          ) : (
            <button
              onClick={() => setShowSearch(true)}
              className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
              title="Search"
            >
              <Search className="w-5 h-5" />
            </button>
          )}

          {/* Notifications/Audit */}
          <button
            onClick={() => navigate('/audit')}
            className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors relative"
            title="View Audit Trail"
          >
            <Bell className="w-5 h-5" />
          </button>

          {/* Divider */}
          <div className="w-px h-6 bg-slate-200 mx-2" />

          {/* Theme toggle */}
          <button
            onClick={cycleTheme}
            className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
            title={`Theme: ${theme === 'system' ? 'System' : theme === 'dark' ? 'Dark' : 'Light'}`}
          >
            {getThemeIcon()}
          </button>
        </div>
      </header>

      {/* Page title */}
      <div className="px-6 pt-3 pb-2 bg-slate-50">
        <div className="flex items-center justify-between">
          <div>
            {title && <h1 className="text-2xl font-bold text-slate-900">{title}</h1>}
            {description && (
              <p className="text-sm text-slate-500 mt-0.5">{description}</p>
            )}
          </div>
          {action && <div>{action}</div>}
        </div>
      </div>
    </>
  );
}
