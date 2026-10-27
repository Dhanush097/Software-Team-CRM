/**
 * Software CRM - API Client Module
 * Communicates with modular PHP backend (/api/*.php)
 * Supports session token authentication and graceful offline/demo fallbacks
 */

const ApiClient = (() => {
  const BASE_URL = 'api';
  let authToken = localStorage.getItem('crm_auth_token') || '';

  function setToken(token) {
    authToken = token;
    if (token) {
      localStorage.setItem('crm_auth_token', token);
    } else {
      localStorage.removeItem('crm_auth_token');
    }
  }

  function getToken() {
    return authToken;
  }

  async function request(endpoint, options = {}) {
    const url = `${BASE_URL}/${endpoint}`;
    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {})
    };

    if (authToken) {
      headers['Authorization'] = `Bearer ${authToken}`;
    }

    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    try {
      const response = await fetch(url, { ...options, headers });
      const data = await response.json();
      return data;
    } catch (err) {
      console.warn(`API request error on [${url}]:`, err);
      // Graceful fallback for static/offline preview
      return { success: false, error: err.message, offline: true };
    }
  }

  return {
    setToken,
    getToken,

    // Auth & Login Endpoints (Google Apps Script / Sheet tab 'otp')
    auth: {
      login: (email) =>
        request('auth_otp.php?action=login', {
          method: 'POST',
          body: { action: 'login', email }
        }),

      sendOtp: (email, full_name = '', phone = '') =>
        request('auth_otp.php?action=sendOtp', {
          method: 'POST',
          body: { action: 'sendOtp', email, full_name, phone }
        }),

      verifyOtp: (email, otp) =>
        request('auth_otp.php?action=verifyOtp', {
          method: 'POST',
          body: { action: 'verifyOtp', email, otp }
        }),

      me: () => request('auth_otp.php?action=me'),

      logout: () => {
        setToken('');
        return request('auth_otp.php?action=logout', { method: 'POST', body: { action: 'logout' } });
      }
    },

    // Dashboard
    dashboard: {
      get: () => request('dashboard.php')
    },

    // Contacts
    contacts: {
      list: (category = 'all', search = '') => {
        const query = new URLSearchParams();
        if (category && category !== 'all') query.set('category', category);
        if (search) query.set('search', search);
        return request(`contacts.php?${query.toString()}`);
      },
      get: (id) => request(`contacts.php?id=${id}`),
      create: (contact) => request('contacts.php', { method: 'POST', body: contact })
    },

    // Projects
    projects: {
      list: (status = 'all') => {
        const query = status && status !== 'all' ? `?status=${status}` : '';
        return request(`projects.php${query}`);
      }
    },

    // Tasks
    tasks: {
      list: (category = 'my') => {
        const query = category ? `?category=${category}` : '';
        return request(`tasks.php${query}`);
      },
      toggle: (id, completed) =>
        request('tasks.php', {
          method: 'POST',
          body: { action: 'toggle', id, completed }
        }),
      create: (task) =>
        request('tasks.php', {
          method: 'POST',
          body: { action: 'create', ...task }
        })
    },

    // Calendar
    calendar: {
      get: (month = 8, year = 2024) =>
        request(`calendar.php?month=${month}&year=${year}`)
    },

    // Invoices
    invoices: {
      list: (status = 'all') => {
        const query = status && status !== 'all' ? `?status=${status}` : '';
        return request(`invoices.php${query}`);
      }
    }
  };
})();
