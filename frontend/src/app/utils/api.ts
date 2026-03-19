const API_BASE = '/api';

// Get auth token from localStorage
function getAuthToken(): string | null {
  return localStorage.getItem('admin_token') || localStorage.getItem('tenant_token');
}

// Build headers with auth token
function buildHeaders(includeAuth = true): HeadersInit {
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
  if (includeAuth) {
    const token = getAuthToken();
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }
  }
  return headers;
}

// Generic request function for Laravel API
async function request<T = any>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const url = `${API_BASE}${endpoint}`;
  const res = await fetch(url, {
    credentials: 'include',
    headers: buildHeaders(),
    ...options,
  });

  const data = await res.json();

  if (!res.ok) {
    throw new Error(data.message || data.error || 'Request failed');
  }

  return data;
}

// HTTP method helpers
const get = <T = any>(endpoint: string) => request<T>(endpoint, { method: 'GET' });
const post = <T = any>(endpoint: string, body?: Record<string, unknown>) =>
  request<T>(endpoint, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
const put = <T = any>(endpoint: string, body?: Record<string, unknown>) =>
  request<T>(endpoint, { method: 'PUT', body: body ? JSON.stringify(body) : undefined });
const del = <T = any>(endpoint: string) => request<T>(endpoint, { method: 'DELETE' });

// ============ SUPER ADMIN AUTH ============
export const adminLogin = (email: string, password: string) =>
  post('/super-admin/login', { email, password });

export const adminLogout = () => post('/super-admin/logout');

export const adminMe = () => get('/super-admin/me');

export const adminChangePassword = (current_password: string, password: string, password_confirmation: string) =>
  post('/super-admin/change-password', { current_password, password, password_confirmation });

// ============ TENANT MANAGEMENT (Admin) ============
export const getTenants = () => get('/super-admin/tenants');
export const getPendingTenants = () => get('/super-admin/tenants/pending');
export const getTenantStatistics = () => get('/super-admin/tenants/statistics');
export const getRecentActivity = () => get('/super-admin/tenants/recent-activity');
export const getTenant = (id: number) => get(`/super-admin/tenants/${id}`);
export const updateTenant = (id: number, data: Record<string, unknown>) => put(`/super-admin/tenants/${id}`, data);
export const deleteTenant = (id: number) => del(`/super-admin/tenants/${id}`);
export const approveTenant = (id: number) => post(`/super-admin/tenants/${id}/approve`);
export const rejectTenant = (id: number, reason: string) => post(`/super-admin/tenants/${id}/reject`, { rejection_reason: reason });
export const suspendTenant = (id: number) => post(`/super-admin/tenants/${id}/suspend`);
export const activateTenant = (id: number) => post(`/super-admin/tenants/${id}/activate`);
export const getTenantStats = (id: number) => get(`/super-admin/tenants/${id}/stats`);

// ============ ANNOUNCEMENTS (Admin) ============
export const getAnnouncements = () => get('/super-admin/announcements');
export const createAnnouncement = (data: Record<string, unknown>) => post('/super-admin/announcements', data);
export const updateAnnouncement = (id: number, data: Record<string, unknown>) => put(`/super-admin/announcements/${id}`, data);
export const deleteAnnouncement = (id: number) => del(`/super-admin/announcements/${id}`);

// ============ SYSTEM SETTINGS (Admin) ============
export const getSystemSettings = () => get('/super-admin/settings');
export const getSettingsByGroup = (group: string) => get(`/super-admin/settings/group/${group}`);
export const updateSetting = (key: string, value: string) => put(`/super-admin/settings/${key}`, { value });
export const bulkUpdateSettings = (settings: Record<string, string>) => post('/super-admin/settings/bulk-update', { settings });

// ============ PLATFORM FEES (Admin) ============
export const getPlatformFeeSettings = () => get('/super-admin/platform-fees/settings');
export const updatePlatformFeeSettings = (data: Record<string, unknown>) => put('/super-admin/platform-fees/settings', data);
export const getPlatformRevenue = (startDate?: string, endDate?: string) => {
  let endpoint = '/super-admin/platform-fees/revenue';
  const params = new URLSearchParams();
  if (startDate) params.set('start_date', startDate);
  if (endDate) params.set('end_date', endDate);
  if (params.toString()) endpoint += `?${params.toString()}`;
  return get(endpoint);
};
export const getPlatformFeeRecords = () => get('/super-admin/platform-fees/records');
export const getTenantBalances = () => get('/super-admin/platform-fees/tenant-balances');

// ============ TENANT AUTH ============
export const tenantSignup = (data: Record<string, unknown>) => post('/tenant/signup', data);

// ============ TENANT DASHBOARD (requires tenant middleware) ============
export const getTenantDashboardStats = () => get('/dashboard/stats');
export const getTenantRealtimeStats = () => get('/dashboard/realtime');

// ============ VOUCHERS (Tenant) ============
export const getVouchers = (params?: { page?: number; status?: string }) => {
  let endpoint = '/vouchers';
  const searchParams = new URLSearchParams();
  if (params?.page) searchParams.set('page', String(params.page));
  if (params?.status) searchParams.set('status', params.status);
  if (searchParams.toString()) endpoint += `?${searchParams.toString()}`;
  return get(endpoint);
};
export const getVoucher = (id: number) => get(`/vouchers/${id}`);
export const generateVouchers = (data: Record<string, unknown>) => post('/vouchers/generate-batch', data);
export const getVoucherTypes = () => get('/vouchers/types');
export const getVoucherGroups = () => get('/vouchers/groups');
export const getVoucherStatistics = () => get('/vouchers/statistics');

// ============ ROUTERS (Tenant) ============
export const getRouters = () => get('/routers');
export const getRouter = (id: number) => get(`/routers/${id}`);
export const createRouter = (data: Record<string, unknown>) => post('/routers', data);
export const updateRouter = (id: number, data: Record<string, unknown>) => put(`/routers/${id}`, data);
export const deleteRouter = (id: number) => del(`/routers/${id}`);
export const testRouterConnection = (id: number) => post(`/routers/${id}/test-connection`);
export const getRouterActiveUsers = (id: number) => get(`/routers/${id}/active-users`);

// ============ TRANSACTIONS (Tenant) ============
export const getTransactions = (params?: { page?: number; status?: string; search?: string }) => {
  let endpoint = '/transactions';
  const searchParams = new URLSearchParams();
  if (params?.page) searchParams.set('page', String(params.page));
  if (params?.status) searchParams.set('status', params.status);
  if (params?.search) searchParams.set('search', params.search);
  if (searchParams.toString()) endpoint += `?${searchParams.toString()}`;
  return get(endpoint);
};
export const getTransaction = (id: number) => get(`/transactions/${id}`);
export const getTransactionStatistics = () => get('/transactions/statistics');

// ============ PAYMENTS (Tenant) ============
export const initiatePayment = (data: Record<string, unknown>) => post('/payments/initiate', data);
export const checkPaymentStatus = (ref: string) => get(`/payments/check-status?ref=${ref}`);

// ============ LEGACY API COMPATIBILITY ============
// These maintain backward compatibility with old API calls
export const apiLogin = (username: string, password: string) => adminLogin(username, password);
export const apiLogout = () => adminLogout();
export const apiMe = () => adminMe();
export const apiStats = () => getTenantDashboardStats();
export const apiTransactions = (p: { page?: number; limit?: number; status?: string; search?: string }) =>
  getTransactions({ page: p.page, status: p.status, search: p.search });
export const apiVoucherStock = () => getVoucherStatistics();
