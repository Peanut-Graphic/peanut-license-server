import { useState } from 'react';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Badge,
  Modal,
  Input,
  HelpTooltip,
  InfoPanel,
} from '@/components/common';
import {
  Package,
  Upload,
  Download,
  Edit2,
  Clock,
} from 'lucide-react';
import { format } from 'date-fns';

// Mock data
const mockProducts = [
  {
    id: 1,
    slug: 'peanut-suite',
    name: 'Peanut Suite',
    version: '4.0.0',
    requires_php: '8.0',
    requires_wp: '6.0',
    tested_up_to: '6.4',
    updated_at: new Date().toISOString(),
    downloads: 1247,
  },
  {
    id: 2,
    slug: 'formflow',
    name: 'FormFlow',
    version: '2.8.2',
    requires_php: '7.4',
    requires_wp: '5.8',
    tested_up_to: '6.4',
    updated_at: new Date(Date.now() - 604800000).toISOString(),
    downloads: 892,
  },
  {
    id: 3,
    slug: 'peanut-booker',
    name: 'Peanut Booker',
    version: '1.2.0',
    requires_php: '8.0',
    requires_wp: '6.0',
    tested_up_to: '6.4',
    updated_at: new Date(Date.now() - 2592000000).toISOString(),
    downloads: 156,
  },
];

export default function Products() {
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [, setSelectedProduct] = useState<typeof mockProducts[0] | null>(null);

  return (
    <Layout
      title="Products"
      description="Manage plugin updates and downloads"
      action={
        <Button onClick={() => setShowUploadModal(true)}>
          <Upload className="w-4 h-4 mr-1" />
          Upload Update
        </Button>
      }
    >
      <div className="space-y-6">
        {/* Info */}
        <InfoPanel variant="tip" title="Update Server">
          <p>
            Upload new versions of your plugins here. Licensed sites will automatically
            receive update notifications and can download the latest version directly
            from WordPress admin.
          </p>
        </InfoPanel>

        {/* Products Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {mockProducts.map((product) => (
            <Card key={product.id}>
              <div className="flex items-start justify-between mb-4">
                <div className="flex items-center gap-3">
                  <div className="p-3 bg-primary-100 rounded-lg">
                    <Package className="w-6 h-6 text-primary-600" />
                  </div>
                  <div>
                    <h3 className="font-semibold text-slate-900">{product.name}</h3>
                    <code className="text-xs text-slate-500">{product.slug}</code>
                  </div>
                </div>
                <Badge variant="success">v{product.version}</Badge>
              </div>

              <div className="space-y-2 mb-4">
                <div className="flex justify-between text-sm">
                  <span className="text-slate-500">PHP Required</span>
                  <span className="font-medium">{product.requires_php}+</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-slate-500">WP Required</span>
                  <span className="font-medium">{product.requires_wp}+</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-slate-500">Tested Up To</span>
                  <span className="font-medium">{product.tested_up_to}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-slate-500">Downloads</span>
                  <span className="font-medium">{product.downloads.toLocaleString()}</span>
                </div>
              </div>

              <div className="flex items-center justify-between pt-4 border-t border-slate-100">
                <div className="flex items-center gap-1 text-xs text-slate-400">
                  <Clock className="w-3.5 h-3.5" />
                  Updated {format(new Date(product.updated_at), 'MMM d, yyyy')}
                </div>
                <div className="flex items-center gap-1">
                  <Button variant="ghost" size="sm" onClick={() => setSelectedProduct(product)}>
                    <Edit2 className="w-4 h-4" />
                  </Button>
                  <Button variant="ghost" size="sm">
                    <Download className="w-4 h-4" />
                  </Button>
                </div>
              </div>
            </Card>
          ))}
        </div>

        {/* Upload Modal */}
        <Modal
          isOpen={showUploadModal}
          onClose={() => setShowUploadModal(false)}
          title="Upload Plugin Update"
          size="md"
          footer={
            <>
              <Button variant="outline" onClick={() => setShowUploadModal(false)}>Cancel</Button>
              <Button onClick={() => setShowUploadModal(false)}>Upload</Button>
            </>
          }
        >
          <div className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="products-plugin">Plugin</label>
              <select id="products-plugin" className="w-full px-3 py-2 border border-slate-300 rounded-lg text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <option value="">Select plugin...</option>
                {mockProducts.map((p) => (
                  <option key={p.id} value={p.slug}>{p.name}</option>
                ))}
              </select>
            </div>
            <Input
              label="Version"
              placeholder="e.g., 4.0.1"
            />
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">Plugin ZIP File</label>
              <div className="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center hover:border-primary-400 transition-colors cursor-pointer">
                <Upload className="w-8 h-8 text-slate-400 mx-auto mb-2" />
                <p className="text-sm text-slate-600">Drop ZIP file here or click to browse</p>
                <p className="text-xs text-slate-400 mt-1">Maximum file size: 50MB</p>
              </div>
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Changelog
                <HelpTooltip content="Describe what's new in this version." />
              </label>
              <textarea
                className="w-full px-3 py-2 border border-slate-300 rounded-lg text-slate-900 focus:outline-none focus:ring-2 focus:ring-primary-500 h-24 resize-none"
                placeholder="What's new in this version..."
              />
            </div>
          </div>
        </Modal>
      </div>
    </Layout>
  );
}
