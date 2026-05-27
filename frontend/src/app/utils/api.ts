const API_BASE = import.meta.env.VITE_API_URL || 'http://api.onlifi.net/api';

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
  const siteId = localStorage.getItem('selected_site_id');
  if (siteId) {
    headers['X-Site-ID'] = siteId;
  }
  return headers;
}

// Generic request function for Laravel API
async function request<T = any>(
  endpoint: string,
  options: RequestInit = {},
  includeAuth = true
): Promise<T> {
  const url = `${API_BASE}${endpoint}`;
  const mergedHeaders = {
    ...buildHeaders(includeAuth),
    ...(options.headers || {}),
  };
  const res = await fetch(url, {
    credentials: 'include',
    ...options,
    headers: mergedHeaders,
  });

  // Check if response has content
  const contentType = res.headers.get('content-type');
  const hasJson = contentType && contentType.includes('application/json');
  
  let data: any = null;
  
  if (hasJson) {
    const text = await res.text();
    try {
      data = text ? JSON.parse(text) : null;
    } catch (e) {
      console.error('JSON parse error:', e, 'Response text:', text);
      throw new Error('Invalid JSON response from server');
    }
  } else {
    const text = await res.text();
    console.error('Non-JSON response:', text);
    throw new Error(`Server returned non-JSON response: ${res.status} ${res.statusText}`);
  }

  if (!res.ok) {
    throw new Error(data?.message || data?.error || `Request failed: ${res.status} ${res.statusText}`);
  }

  return data;
}

// HTTP method helpers
const get = <T = any>(endpoint: string) => request<T>(endpoint, { method: 'GET' });
const post = <T = any>(endpoint: string, body?: Record<string, unknown>) =>
  request<T>(endpoint, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
const postPublic = <T = any>(endpoint: string, body?: Record<string, unknown>) =>
  request<T>(endpoint, {
    method: 'POST',
    body: body ? JSON.stringify(body) : undefined,
  }, false);
const put = <T = any>(endpoint: string, body?: Record<string, unknown>) =>
  request<T>(endpoint, { method: 'PUT', body: body ? JSON.stringify(body) : undefined });
const del = <T = any>(endpoint: string) => request<T>(endpoint, { method: 'DELETE' });

// ============ SUPER ADMIN AUTH ============
export const adminLogin = (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) =>
  post('/super-admin/login', {
    email,
    password,
    ...(twoFactorCode ? { two_factor_code: twoFactorCode } : {}),
    ...(twoFactorToken ? { two_factor_token: twoFactorToken } : {}),
  });

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
export const rejectTenant = (id: number, reason: string) => post(`/super-admin/tenants/${id}/reject`, { reason });
export const suspendTenant = (id: number) => post(`/super-admin/tenants/${id}/suspend`);
export const activateTenant = (id: number) => post(`/super-admin/tenants/${id}/activate`);
export const getTenantStats = (id: number) => get(`/super-admin/tenants/${id}/stats`);
export const extendTenantTrial = (id: number, days: number) => post(`/super-admin/tenants/${id}/extend-trial`, { days });

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
export const tenantLogin = (email: string, password: string, twoFactorCode?: string, twoFactorToken?: string) =>
  post('/tenant/login', {
    email,
    password,
    ...(twoFactorCode ? { two_factor_code: twoFactorCode } : {}),
    ...(twoFactorToken ? { two_factor_token: twoFactorToken } : {}),
  });

export const tenantForgotPassword = (email: string) => postPublic('/tenant/forgot-password', { email });
export const tenantResetPassword = (email: string, token: string, password: string, password_confirmation: string) =>
  postPublic('/tenant/reset-password', { email, token, password, password_confirmation });

export const tenantLogout = () => post('/tenant/logout');

export const tenantMe = () => get('/tenant/me');
export const getTenantBillingStatus = () => get('/tenant/billing/status');
export const initiateSubscriptionPayment = (data: Record<string, unknown>) => post('/tenant/billing/subscribe', data);
export const checkSubscriptionPaymentStatus = (ref: string) => get(`/tenant/billing/payment-status?ref=${encodeURIComponent(ref)}`);
export const getCaptivePortalTemplates = () => get('/tenant/captive-portal/templates');
export const saveCaptivePortalTemplate = (data: Record<string, unknown>) => post('/tenant/captive-portal/templates', data);
export const activateCaptivePortalTemplate = (id: number) => post(`/tenant/captive-portal/templates/${id}/activate`);
export const getSmsCredits = (page = 1) => get(`/tenant/sms-credits?page=${page}&per_page=15`);
export const updateSmsPlan = (sms_enabled: boolean) => put('/tenant/sms-credits/plan', { sms_enabled });
export const topUpSmsCredits = (data: Record<string, unknown>) => post('/tenant/sms-credits/top-up', data);
export const checkSmsCreditPaymentStatus = (ref: string) => get(`/tenant/sms-credits/payment-status?ref=${encodeURIComponent(ref)}`);
export const getRemoteAccess = () => get('/tenant/remote-access');
export const collectRouterTelemetry = (id: number) => post(`/routers/${id}/collect-telemetry`);

export const tenantChangePassword = (current_password: string, new_password: string, new_password_confirmation: string) =>
  post('/tenant/change-password', { current_password, new_password, new_password_confirmation });

export const tenantSignup = (data: Record<string, unknown>) => post('/tenant/signup', data);

// ============ TENANT DASHBOARD (requires tenant middleware) ============
export const getTenantDashboardStats = () => get('/dashboard/stats');
export const getTenantRealtimeStats = () => get('/dashboard/realtime');
export const getTelemetryStats = () => get('/telemetry/stats');

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

// ============ VOUCHER TYPES MANAGEMENT (Tenant) ============
export const listVoucherTypes = () => get('/vouchers/types');
export const createVoucherType = (data: Record<string, unknown>) => post('/vouchers/types', data);
export const updateVoucherType = (id: number, data: Record<string, unknown>) => put(`/vouchers/types/${id}`, data);
export const deleteVoucherType = (id: number) => del(`/vouchers/types/${id}`);

// ============ ROUTERS (Tenant) ============
export const getRouters = () => get('/routers');
export const getRouter = (id: number) => get(`/routers/${id}`);
export const createRouter = (data: Record<string, unknown>) => post('/routers', data);
export const updateRouter = (id: number, data: Record<string, unknown>) => put(`/routers/${id}`, data);
export const deleteRouter = (id: number) => del(`/routers/${id}`);
export const testRouterConnection = (id: number) => post(`/routers/${id}/test-connection`);
export const getRouterActiveUsers = (id: number) => get(`/routers/${id}/active-users`);

// ============ TRANSACTIONS (Tenant) ============
export const getTransactions = (params?: { page?: number; per_page?: number; status?: string; search?: string }) => {
  let endpoint = '/transactions';
  const searchParams = new URLSearchParams();
  if (params?.page) searchParams.set('page', String(params.page));
  if (params?.per_page) searchParams.set('per_page', String(params.per_page));
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
export const apiTransactions = async (p: { page?: number; limit?: number; status?: string; search?: string }) => {
  const data = await getTransactions({ page: p.page, per_page: p.limit, status: p.status, search: p.search });
  return {
    ...data,
    transactions: data.transactions ?? data.data ?? [],
    total: data.total ?? data.meta?.total ?? 0,
  };
};
export const apiVoucherStock = () => getVoucherStatistics();

// Performance analytics - placeholder until backend endpoint is implemented
export const apiPerformance = async (site: string, days: number) => {
  // TODO: Implement backend endpoint for performance analytics
  return { data: [], site, days };
};

// Withdrawals - placeholder until backend endpoint is implemented
export const apiWithdrawals = async (p: { page?: number; limit?: number }) => {
  // TODO: Implement backend endpoint for withdrawals
  return { withdrawals: [], total: 0, page: p.page ?? 1 };
};

export const apiRequestWithdrawal = async (body: { site: string; amount: number; phone: string }) => {
  // TODO: Implement backend endpoint for withdrawal requests
  return { success: false, message: 'Withdrawal feature not yet implemented' };
};

// Import vouchers - placeholder until backend endpoint is implemented
export const apiImportVouchers = async (site: string, file: File) => {
  // TODO: Implement backend endpoint for voucher import
  const formData = new FormData();
  formData.append('site', site);
  formData.append('file', file);
  return { success: false, message: 'Voucher import feature not yet implemented' };
};
