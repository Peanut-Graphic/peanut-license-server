import { useState } from 'react';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Badge,
  StatusBadge,
  TierBadge,
  Modal,
  ConfirmModal,
  LicensesSkeleton,
  DangerZone,
  DangerAction,
} from '@/components/common';
import {
  Key,
  Search,
  Plus,
  Copy,
  Edit2,
  Trash2,
  Pause,
  Play,
  ExternalLink,
  ChevronLeft,
  ChevronRight,
  Download,
} from 'lucide-react';
import { format, formatDistanceToNow } from 'date-fns';

// Mock data
const mockLicenses = [
  { id: 1, license_key: 'PNUT-PRO-7A3BF92C', email: 'client@example.com', tier: 'pro' as const, status: 'active' as const, max_activations: 3, activations_count: 2, created_at: new Date().toISOString(), expires_at: new Date(Date.now() + 365 * 86400000).toISOString() },
  { id: 2, license_key: 'PNUT-AGY-9F2C4D1E', email: 'agency@studio.com', tier: 'agency' as const, status: 'active' as const, max_activations: 25, activations_count: 12, created_at: new Date(Date.now() - 86400000).toISOString(), expires_at: null },
  { id: 3, license_key: 'PNUT-FREE-4D1E8B3A', email: 'user@domain.org', tier: 'free' as const, status: 'expired' as const, max_activations: 1, activations_count: 1, created_at: new Date(Date.now() - 172800000).toISOString(), expires_at: new Date(Date.now() - 86400000).toISOString() },
  { id: 4, license_key: 'PNUT-PRO-2B5C7E8F', email: 'developer@tech.io', tier: 'pro' as const, status: 'suspended' as const, max_activations: 3, activations_count: 0, created_at: new Date(Date.now() - 259200000).toISOString(), expires_at: null },
  { id: 5, license_key: 'PNUT-AGY-1A4D6F9C', email: 'enterprise@corp.com', tier: 'agency' as const, status: 'active' as const, max_activations: 25, activations_count: 18, created_at: new Date(Date.now() - 345600000).toISOString(), expires_at: null },
];

export default function Licenses() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [tierFilter, setTierFilter] = useState<string>('');
  const [isLoading] = useState(false);
  const [showAddModal, setShowAddModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState<number | null>(null);
  const [selectedLicense, setSelectedLicense] = useState<typeof mockLicenses[0] | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  // Filter licenses
  const filteredLicenses = mockLicenses.filter((license) => {
    if (search && !license.license_key.toLowerCase().includes(search.toLowerCase()) &&
        !license.email.toLowerCase().includes(search.toLowerCase())) {
      return false;
    }
    if (statusFilter && license.status !== statusFilter) return false;
    if (tierFilter && license.tier !== tierFilter) return false;
    return true;
  });

  const handleCopyKey = async (key: string) => {
    await navigator.clipboard.writeText(key);
    setCopiedKey(key);
    setTimeout(() => setCopiedKey(null), 2000);
  };

  if (isLoading) {
    return (
      <Layout title="Licenses" description="Manage license keys">
        <LicensesSkeleton />
      </Layout>
    );
  }

  return (
    <Layout
      title="Licenses"
      description="Manage license keys for your products"
      action={
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm">
            <Download className="w-4 h-4 mr-1" />
            Export
          </Button>
          <Button size="sm" onClick={() => setShowAddModal(true)}>
            <Plus className="w-4 h-4 mr-1" />
            New License
          </Button>
        </div>
      }
    >
      <div className="space-y-6">
        {/* Filters */}
        <Card>
          <div className="flex flex-wrap items-center gap-4">
            <div className="flex-1 min-w-[200px]">
              <Input
                placeholder="Search by key or email..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                leftIcon={<Search className="w-4 h-4" />}
              />
            </div>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="suspended">Suspended</option>
              <option value="revoked">Revoked</option>
            </select>
            <select
              value={tierFilter}
              onChange={(e) => setTierFilter(e.target.value)}
              className="px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500"
            >
              <option value="">All Tiers</option>
              <option value="free">Free</option>
              <option value="pro">Pro</option>
              <option value="agency">Agency</option>
            </select>
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <Key className="w-4 h-4" />
              {filteredLicenses.length} licenses
            </div>
          </div>
        </Card>

        {/* Licenses Table */}
        <Card padding="none">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50">
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">License Key</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Customer</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Tier</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Activations</th>
                  <th className="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Expires</th>
                  <th className="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredLicenses.map((license) => (
                  <tr
                    key={license.id}
                    className="border-b border-slate-100 hover:bg-slate-50 transition-colors"
                  >
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <code className="text-sm font-mono text-slate-900">{license.license_key}</code>
                        <button
                          onClick={() => handleCopyKey(license.license_key)}
                          className="p-1 text-slate-400 hover:text-slate-600 rounded"
                          title="Copy license key"
                        >
                          {copiedKey === license.license_key ? (
                            <span className="text-xs text-green-600">Copied!</span>
                          ) : (
                            <Copy className="w-3.5 h-3.5" />
                          )}
                        </button>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <p className="text-sm text-slate-900">{license.email}</p>
                      <p className="text-xs text-slate-500">
                        Created {formatDistanceToNow(new Date(license.created_at), { addSuffix: true })}
                      </p>
                    </td>
                    <td className="px-4 py-3">
                      <TierBadge tier={license.tier} />
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={license.status} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-slate-900">
                          {license.activations_count} / {license.max_activations}
                        </span>
                        {license.activations_count >= license.max_activations && (
                          <Badge variant="warning" size="sm">Full</Badge>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      {license.expires_at ? (
                        <span className={`text-sm ${
                          new Date(license.expires_at) < new Date() ? 'text-red-600' : 'text-slate-600'
                        }`}>
                          {format(new Date(license.expires_at), 'MMM d, yyyy')}
                        </span>
                      ) : (
                        <span className="text-sm text-slate-400">Never</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          onClick={() => setSelectedLicense(license)}
                          className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded"
                          title="View details"
                        >
                          <ExternalLink className="w-4 h-4" />
                        </button>
                        <button
                          className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded"
                          title="Edit license"
                        >
                          <Edit2 className="w-4 h-4" />
                        </button>
                        {license.status === 'active' ? (
                          <button
                            className="p-1.5 text-amber-500 hover:text-amber-600 hover:bg-amber-50 rounded"
                            title="Suspend license"
                          >
                            <Pause className="w-4 h-4" />
                          </button>
                        ) : license.status === 'suspended' ? (
                          <button
                            className="p-1.5 text-green-500 hover:text-green-600 hover:bg-green-50 rounded"
                            title="Reactivate license"
                          >
                            <Play className="w-4 h-4" />
                          </button>
                        ) : null}
                        <button
                          onClick={() => setShowDeleteConfirm(license.id)}
                          className="p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded"
                          title="Delete license"
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          <div className="flex items-center justify-between px-4 py-3 border-t border-slate-200">
            <p className="text-sm text-slate-500">
              Showing 1 to {filteredLicenses.length} of {filteredLicenses.length} results
            </p>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" disabled>
                <ChevronLeft className="w-4 h-4" />
              </Button>
              <Button variant="outline" size="sm" disabled>
                <ChevronRight className="w-4 h-4" />
              </Button>
            </div>
          </div>
        </Card>

        {/* Add License Modal */}
        <Modal
          isOpen={showAddModal}
          onClose={() => setShowAddModal(false)}
          title="Create New License"
          size="md"
          footer={
            <>
              <Button variant="outline" onClick={() => setShowAddModal(false)}>
                Cancel
              </Button>
              <Button onClick={() => setShowAddModal(false)}>
                Create License
              </Button>
            </>
          }
        >
          <div className="space-y-4">
            <Input
              label="Customer Email"
              type="email"
              placeholder="customer@example.com"
              required
            />
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="licenses-license-tier">License Tier</label>
              <select id="licenses-license-tier" className="w-full px-3 py-2 border border-slate-300 rounded-lg text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="free">Free (1 activation)</option>
                <option value="pro">Pro (3 activations)</option>
                <option value="agency">Agency (25 activations)</option>
              </select>
            </div>
            <Input
              label="Expiration Date"
              type="date"
              helpText="Leave empty for a lifetime license"
            />
          </div>
        </Modal>

        {/* License Details Modal */}
        {selectedLicense && (
          <Modal
            isOpen={!!selectedLicense}
            onClose={() => setSelectedLicense(null)}
            title="License Details"
            size="lg"
          >
            <div className="space-y-6">
              <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg">
                <code className="text-lg font-mono font-bold">{selectedLicense.license_key}</code>
                <div className="flex items-center gap-2">
                  <TierBadge tier={selectedLicense.tier} />
                  <StatusBadge status={selectedLicense.status} />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-sm text-slate-500">Customer</p>
                  <p className="font-medium">{selectedLicense.email}</p>
                </div>
                <div>
                  <p className="text-sm text-slate-500">Created</p>
                  <p className="font-medium">{format(new Date(selectedLicense.created_at), 'PPP')}</p>
                </div>
                <div>
                  <p className="text-sm text-slate-500">Activations</p>
                  <p className="font-medium">{selectedLicense.activations_count} / {selectedLicense.max_activations}</p>
                </div>
                <div>
                  <p className="text-sm text-slate-500">Expires</p>
                  <p className="font-medium">
                    {selectedLicense.expires_at
                      ? format(new Date(selectedLicense.expires_at), 'PPP')
                      : 'Never'}
                  </p>
                </div>
              </div>

              <DangerZone title="License Actions" description="Actions that affect this license.">
                <DangerAction
                  title="Regenerate License Key"
                  description="Generate a new key. The old key will stop working."
                  buttonLabel="Regenerate"
                  confirmTitle="Regenerate License Key"
                  confirmMessage="This will generate a new license key. The old key will immediately stop working on all activated sites."
                  onAction={() => {}}
                />
                <DangerAction
                  title="Revoke License"
                  description="Permanently deactivate this license on all sites."
                  buttonLabel="Revoke"
                  confirmTitle="Revoke License"
                  confirmMessage="This will permanently revoke the license and deactivate it on all sites. This action cannot be undone."
                  onAction={() => setSelectedLicense(null)}
                />
              </DangerZone>
            </div>
          </Modal>
        )}

        {/* Delete Confirmation */}
        <ConfirmModal
          isOpen={!!showDeleteConfirm}
          onClose={() => setShowDeleteConfirm(null)}
          onConfirm={() => setShowDeleteConfirm(null)}
          title="Delete License"
          message="Are you sure you want to delete this license? This action cannot be undone and will immediately deactivate the license on all sites."
          confirmLabel="Delete"
          confirmVariant="danger"
        />
      </div>
    </Layout>
  );
}
