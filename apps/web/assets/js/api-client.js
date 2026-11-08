/**
 * ApiClient v2.3 — NexusBids
 * FIXED: refresh-fail redirect to /login.html (was /auth/login.html)
 */
const API_BASE = (() => {
  if (typeof window !== 'undefined' && window.location.protocol === 'file:') {
    return 'http://localhost:5000/api/v1';
  }
  return '/api/v1';
})();

class ApiClient {
  static _refreshPromise = null;
  static getBaseUrl() { return API_BASE; }

  static clearTokens() {
    try {
      localStorage.removeItem('nexusbids_token');
      localStorage.removeItem('nexusbids_access_token');
      localStorage.removeItem('nexusbids_user');
      localStorage.removeItem('token');
      localStorage.removeItem('access_token');
      sessionStorage.removeItem('nexusbids_token');
      sessionStorage.removeItem('nexusbids_access_token');
      sessionStorage.removeItem('nexusbids_user');
    } catch (e) { }
  }

  static getAccessToken() {
    const token =
      localStorage.getItem('nexusbids_token') ||
      localStorage.getItem('nexusbids_access_token') ||
      sessionStorage.getItem('nexusbids_token') ||
      sessionStorage.getItem('nexusbids_access_token') ||
      localStorage.getItem('token') ||
      null;
    // Let the server validate the token. The request() method handles 401 and refreshes automatically.
    return token || null;
  }

  static setAccessToken(t) {
    if (t) {
      localStorage.setItem('nexusbids_access_token', t);
      localStorage.setItem('nexusbids_token', t);
    } else {
      this.clearTokens();
    }
  }

  static getUserRole() {
    const token = this.getAccessToken();
    if (!token) return null;
    try {
      const parts = token.split('.');
      const payload = JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
      return (payload.role || '').toLowerCase();
    } catch (e) { return null; }
  }

  static isAuthenticated() { return !!this.getAccessToken(); }
  static isAdmin() { return this.getUserRole() === 'admin'; }

  static async request(endpoint, opts = {}) {
    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE}${endpoint}`;
    const token = this.getAccessToken();
    const isFormData = opts.body instanceof FormData;

    const headers = {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      ...(opts.headers || {}),
    };

    const body = opts.body && !isFormData && typeof opts.body === 'object'
      ? JSON.stringify(opts.body) : opts.body;

    try {
      let res;
      try {
        res = await fetch(url, { ...opts, headers, body, credentials: 'include' });
      } catch (fetchErr) {
        if (!opts._fetchRetry) {
          opts._fetchRetry = true;
          await new Promise((r) => setTimeout(r, 120));
          res = await fetch(url, { ...opts, headers, body, credentials: 'include' });
        } else { throw fetchErr; }
      }

      if (res.status === 401 && !opts._retry && !endpoint.includes('/auth/')) {
        opts._retry = true;
        const refreshed = await this._refresh();
        if (refreshed) return this.request(endpoint, opts);

        console.warn('[ApiClient] Refresh failed → redirecting to login');
        this.clearTokens();
        const redirect = encodeURIComponent(location.pathname + location.search);
        if (!location.pathname.includes('/login')) {
          // ✅ FIXED: /login.html (not /auth/login.html)
          location.href = '/login.html?redirect=' + redirect;
        }
        throw new Error('Session expired. Please log in again.');
      }

      let data;
      try { data = await res.json(); } catch { data = {}; }

      if (!res.ok) throw new Error(data.error || data.message || `HTTP ${res.status}`);
      return data;
    } catch (err) {
      if (!(err instanceof TypeError)) console.warn(`[API ${endpoint}]`, err.message);
      throw err;
    }
  }

  static async _refresh() {
    if (this._refreshPromise) return this._refreshPromise;
    this._refreshPromise = (async () => {
      try {
        const res = await fetch(`${API_BASE}/auth/refresh`, {
          method: 'POST', credentials: 'include',
          headers: { 'Content-Type': 'application/json' }
        });
        if (res.ok) {
          const d = await res.json();
          const token = d.data?.accessToken || d.data?.token;
          if (token) { this.setAccessToken(token); return true; }
        }
      } catch { }
      this.clearTokens();
      return false;
    })().finally(() => { this._refreshPromise = null; });
    return this._refreshPromise;
  }

  static get(endpoint, params = {}) {
    const cleanParams = {};
    if (params && typeof params === 'object') {
      for (const [k, v] of Object.entries(params)) {
        if (v !== undefined && v !== null && v !== '' && v !== 'undefined') {
          cleanParams[k] = v;
        }
      }
    }
    const qs = Object.keys(cleanParams).length ? '?' + new URLSearchParams(cleanParams) : '';
    return this.request(`${endpoint}${qs}`, { method: 'GET' });
  }
  static post(endpoint, body = {}) { return this.request(endpoint, { method: 'POST', body }); }
  static patch(endpoint, body = {}) { return this.request(endpoint, { method: 'PATCH', body }); }
  static put(endpoint, body = {}) { return this.request(endpoint, { method: 'PUT', body }); }
  static delete(endpoint) { return this.request(endpoint, { method: 'DELETE' }); }
  static upload(endpoint, formData) { return this.request(endpoint, { method: 'POST', body: formData }); }
}

window.ApiClient = ApiClient;