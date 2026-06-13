import { useState, useEffect } from 'react';
import { useAuth } from '../context/AuthContext';
import { apiMonitorVouchers, apiDeleteVouchers } from '../utils/api';
import { Trash2, Search, Filter, AlertTriangle } from 'lucide-react';

interface Voucher {
  id: number;
  code: string;
  type: string;
}

interface MonitorVouchersResponse {
  vouchers: Voucher[];
  total: number;
  page: number;
  limit: number;
  site: string;
}

const VOUCHER_TYPES = [
  { value: 'all', label: 'All Types' },
  { value: '2hours', label: '2 Hours' },
  { value: '3hours', label: '3 Hours' },
  { value: '12hours', label: '12 Hours' },
  { value: '23hours', label: '23 Hours' },
  { value: '24hours', label: '24 Hours' },
  { value: '7days', label: '7 Days' },
  { value: '30days', label: '30 Days' },
];

export function MonitorVouchers() {
  const { user } = useAuth();
  const [vouchers, setVouchers] = useState<Voucher[]>([]);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [limit] = useState(20);
  const [selectedType, setSelectedType] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedVouchers, setSelectedVouchers] = useState<Set<number>>(new Set());
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  const sites = user?.role === 'admin' 
    ? ['Enock', 'Richard', 'STK', 'Remmy', 'Guma']
    : [user?.site].filter(Boolean);
  const [selectedSite, setSelectedSite] = useState(sites[0] || '');

  useEffect(() => {
    fetchVouchers();
  }, [page, selectedType, selectedSite]);

  const fetchVouchers = async () => {
    setLoading(true);
    try {
      const data: MonitorVouchersResponse = await apiMonitorVouchers({
        page,
        limit,
        site: selectedSite,
        type: selectedType,
        search: searchQuery,
      });
      setVouchers(data.vouchers);
      setTotal(data.total);
    } catch (error) {
      console.error('Error fetching vouchers:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSearch = () => {
    setPage(1);
    fetchVouchers();
  };

  const handleSelectAll = () => {
    if (selectedVouchers.size === vouchers.length) {
      setSelectedVouchers(new Set());
    } else {
      setSelectedVouchers(new Set(vouchers.map(v => v.id)));
    }
  };

  const handleSelectVoucher = (id: number) => {
    const newSelected = new Set(selectedVouchers);
    if (newSelected.has(id)) {
      newSelected.delete(id);
    } else {
      newSelected.add(id);
    }
    setSelectedVouchers(newSelected);
  };

  const handleDeleteSelected = async () => {
    if (selectedVouchers.size === 0) return;

    setDeleting(true);
    try {
      const data = await apiDeleteVouchers(selectedSite, Array.from(selectedVouchers));
      if (data.ok) {
        setSelectedVouchers(new Set());
        setShowDeleteConfirm(false);
        fetchVouchers();
      }
    } catch (error) {
      console.error('Error deleting vouchers:', error);
      alert('Failed to delete vouchers. Please try again.');
    } finally {
      setDeleting(false);
    }
  };

  const totalPages = Math.ceil(total / limit);

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold text-foreground">Monitor Vouchers</h1>
        <p className="text-muted-foreground mt-1">View and manage unused vouchers</p>
      </div>

      <div className="bg-card rounded-lg border border-border p-6 space-y-4">
        <div className="flex flex-col lg:flex-row gap-4">
          {sites.length > 1 && (
            <div className="flex-1">
              <label className="block text-sm font-medium text-foreground mb-2">Site</label>
              <select
                value={selectedSite}
                onChange={(e) => {
                  setSelectedSite(e.target.value);
                  setPage(1);
                }}
                className="w-full px-4 py-2 bg-background border border-input rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              >
                {sites.map((site) => (
                  <option key={site} value={site}>{site}</option>
                ))}
              </select>
            </div>
          )}

          <div className="flex-1">
            <label className="block text-sm font-medium text-foreground mb-2">
              <Filter className="w-4 h-4 inline mr-1" />
              Voucher Type
            </label>
            <select
              value={selectedType}
              onChange={(e) => {
                setSelectedType(e.target.value);
                setPage(1);
              }}
              className="w-full px-4 py-2 bg-background border border-input rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
            >
              {VOUCHER_TYPES.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
          </div>

          <div className="flex-1">
            <label className="block text-sm font-medium text-foreground mb-2">
              <Search className="w-4 h-4 inline mr-1" />
              Search Code
            </label>
            <div className="flex gap-2">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                placeholder="Enter voucher code..."
                className="flex-1 px-4 py-2 bg-background border border-input rounded-lg text-foreground focus:outline-none focus:ring-2 focus:ring-primary"
              />
              <button
                onClick={handleSearch}
                className="px-4 py-2 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 transition-colors"
              >
                Search
              </button>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-between pt-4 border-t border-border">
          <div className="text-sm text-muted-foreground">
            Total: <span className="font-semibold text-foreground">{total}</span> unused vouchers
            {selectedVouchers.size > 0 && (
              <span className="ml-4">
                Selected: <span className="font-semibold text-foreground">{selectedVouchers.size}</span>
              </span>
            )}
          </div>

          {selectedVouchers.size > 0 && (
            <button
              onClick={() => setShowDeleteConfirm(true)}
              className="flex items-center gap-2 px-6 py-2.5 bg-destructive text-destructive-foreground rounded-lg hover:bg-destructive/90 transition-colors font-semibold shadow-lg"
            >
              <Trash2 className="w-5 h-5" />
              Delete Selected ({selectedVouchers.size})
            </button>
          )}
        </div>
      </div>

      <div className="bg-card rounded-lg border border-border overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-muted">
              <tr>
                <th className="px-6 py-4 text-left">
                  <input
                    type="checkbox"
                    checked={vouchers.length > 0 && selectedVouchers.size === vouchers.length}
                    onChange={handleSelectAll}
                    className="w-4 h-4 rounded border-input cursor-pointer"
                  />
                </th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-foreground">ID</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-foreground">Voucher Code</th>
                <th className="px-6 py-4 text-left text-sm font-semibold text-foreground">Type</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {loading ? (
                <tr>
                  <td colSpan={4} className="px-6 py-12 text-center text-muted-foreground">
                    Loading vouchers...
                  </td>
                </tr>
              ) : vouchers.length === 0 ? (
                <tr>
                  <td colSpan={4} className="px-6 py-12 text-center text-muted-foreground">
                    No unused vouchers found
                  </td>
                </tr>
              ) : (
                vouchers.map((voucher) => (
                  <tr
                    key={voucher.id}
                    className={`hover:bg-muted/50 transition-colors ${
                      selectedVouchers.has(voucher.id) ? 'bg-primary/5' : ''
                    }`}
                  >
                    <td className="px-6 py-4">
                      <input
                        type="checkbox"
                        checked={selectedVouchers.has(voucher.id)}
                        onChange={() => handleSelectVoucher(voucher.id)}
                        className="w-4 h-4 rounded border-input cursor-pointer"
                      />
                    </td>
                    <td className="px-6 py-4 text-sm text-foreground">{voucher.id}</td>
                    <td className="px-6 py-4 text-sm font-mono text-foreground">{voucher.code}</td>
                    <td className="px-6 py-4">
                      <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                        {voucher.type}
                      </span>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {totalPages > 1 && (
          <div className="px-6 py-4 border-t border-border flex items-center justify-between">
            <div className="text-sm text-muted-foreground">
              Page {page} of {totalPages}
            </div>
            <div className="flex gap-2">
              <button
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={page === 1}
                className="px-4 py-2 bg-background border border-input rounded-lg text-foreground hover:bg-muted disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                Previous
              </button>
              <button
                onClick={() => setPage(p => Math.min(totalPages, p + 1))}
                disabled={page === totalPages}
                className="px-4 py-2 bg-background border border-input rounded-lg text-foreground hover:bg-muted disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>

      {showDeleteConfirm && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-card rounded-lg border border-border max-w-md w-full p-6 space-y-4">
            <div className="flex items-start gap-4">
              <div className="w-12 h-12 rounded-full bg-destructive/10 flex items-center justify-center flex-shrink-0">
                <AlertTriangle className="w-6 h-6 text-destructive" />
              </div>
              <div className="flex-1">
                <h3 className="text-lg font-semibold text-foreground">Confirm Deletion</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  Are you sure you want to delete <strong>{selectedVouchers.size}</strong> selected voucher(s)? 
                  This action cannot be undone.
                </p>
              </div>
            </div>

            <div className="flex gap-3 justify-end pt-4">
              <button
                onClick={() => setShowDeleteConfirm(false)}
                disabled={deleting}
                className="px-4 py-2 bg-background border border-input rounded-lg text-foreground hover:bg-muted transition-colors disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={handleDeleteSelected}
                disabled={deleting}
                className="px-6 py-2 bg-destructive text-destructive-foreground rounded-lg hover:bg-destructive/90 transition-colors disabled:opacity-50 font-semibold"
              >
                {deleting ? 'Deleting...' : 'Delete Vouchers'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
