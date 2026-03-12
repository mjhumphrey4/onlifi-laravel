const BASE = '/api/api.php';

async function req(action: string, opts: RequestInit = {}, params: Record<string, string> = {}) {
  const url = new URL(BASE, window.location.origin);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => { if (v) url.searchParams.set(k, v); });
  const res = await fetch(url.toString(), {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    ...opts,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

function get(action: string, params: Record<string, string> = {}) {
  return req(action, { method: 'GET' }, params);
}

function post(action: string, body: Record<string, unknown> = {}) {
  return req(action, { method: 'POST', body: JSON.stringify(body) });
}

export const apiLogin  = (username: string, password: string) => post('login', { username, password });
export const apiLogout = () => post('logout');
export const apiMe     = () => get('me');
export const apiStats  = () => get('stats');

export const apiTransactions = (p: { page?: number; limit?: number; status?: string; search?: string; site?: string }) =>
  get('transactions', {
    page:   String(p.page   ?? 1),
    limit:  String(p.limit  ?? 15),
    status: p.status ?? '',
    search: p.search ?? '',
    site:   p.site   ?? '',
  });

export const apiWithdrawals = (p: { page?: number; limit?: number }) =>
  get('withdrawals', { page: String(p.page ?? 1), limit: String(p.limit ?? 15) });

export const apiRequestWithdrawal = (body: { site: string; amount: number; phone: string }) =>
  post('request_withdrawal', body);

export const apiPerformance = (site: string, days: number) =>
  get('performance', { site, days: String(days) });

export const apiVoucherStock = (site: string) =>
  get('voucher_stock', { site });

export const apiImportVouchers = async (site: string, file: File) => {
  const form = new FormData();
  form.append('site', site);
  form.append('pdfFile', file);
  const url = new URL(BASE, window.location.origin);
  url.searchParams.set('action', 'import_vouchers');
  const res = await fetch(url.toString(), { method: 'POST', credentials: 'include', body: form });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Import failed');
  return data;
};
