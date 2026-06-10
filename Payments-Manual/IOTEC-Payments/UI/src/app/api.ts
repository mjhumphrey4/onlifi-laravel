export const API_BASE = import.meta.env.VITE_API_URL || "/api";

const TOKEN_KEY = "iotec_payments_admin_token";

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY);
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const token = getToken();
  const headers: HeadersInit = {
    "Content-Type": "application/json",
    Accept: "application/json",
    ...(options.headers || {}),
  };

  if (token) headers.Authorization = `Bearer ${token}`;

  const response = await fetch(`${API_BASE}${endpoint}`, {
    ...options,
    headers,
  });

  const text = await response.text();
  const data = text ? JSON.parse(text) : null;

  if (!response.ok) {
    throw new Error(data?.message || data?.error || `Request failed with status ${response.status}`);
  }

  return data as T;
}

export const api = {
  login: (token: string) =>
    request<{ token: string; admin: { name: string; role: string } }>("/auth/login", {
      method: "POST",
      body: JSON.stringify({ token }),
    }),
  dashboard: () => request<any>("/dashboard"),
  transactions: (params: Record<string, string | number | undefined>) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== "") query.set(key, String(value));
    });
    return request<any>(`/transactions?${query.toString()}`);
  },
  saveApiProfile: (record: any) =>
    request(record.id ? `/api-profiles/${record.id}` : "/api-profiles", {
      method: record.id ? "PUT" : "POST",
      body: JSON.stringify(record),
    }),
  deleteApiProfile: (id: number) => request(`/api-profiles/${id}`, { method: "DELETE" }),
  saveCallback: (record: any) =>
    request(record.id ? `/callbacks/${record.id}` : "/callbacks", {
      method: record.id ? "PUT" : "POST",
      body: JSON.stringify(record),
    }),
  deleteCallback: (id: number) => request(`/callbacks/${id}`, { method: "DELETE" }),
  saveSettings: (settings: any[]) =>
    request("/settings", {
      method: "PUT",
      body: JSON.stringify({ settings }),
    }),
  testLegacyDb: () => request<any>("/settings/test-legacy-db", { method: "POST" }),
};
