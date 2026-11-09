import { ApiResponse } from '@nexusbids/shared';
import { SecureTokenStorage, SafeLogger } from '../security/secureStorage';

// For local testing on Android emulator use 10.0.2.2, for real phone use local IP or localhost for web/iOS
let currentBaseUrl = process.env.EXPO_PUBLIC_API_URL || 'http://10.0.2.2:5000/api/v1';

export const getApiBaseUrl = () => currentBaseUrl;
export const setApiBaseUrl = (url: string) => {
  currentBaseUrl = url;
};

// Thread-safe refresh lock
let isRefreshing = false;
let refreshQueue: Array<(token: string | null) => void> = [];

const processQueue = (newToken: string | null) => {
  refreshQueue.forEach((cb) => cb(newToken));
  refreshQueue = [];
};

// Global session expiration handler
let onSessionExpiredListener: (() => void) | null = null;
export const setOnSessionExpired = (callback: () => void) => {
  onSessionExpiredListener = callback;
};

export async function apiRequest<T = any>(
  endpoint: string,
  options: RequestInit = {},
  isRetry = false
): Promise<ApiResponse<T>> {
  const url = `${currentBaseUrl}${endpoint}`;
  const accessToken = await SecureTokenStorage.getAccessToken();
  const deviceId = await SecureTokenStorage.getDeviceId();

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Device-Id': deviceId,
    'X-Client-Platform': 'react-native-production',
    'X-Client-Version': '1.0.0',
    ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
    ...((options.headers as Record<string, string>) || {}),
  };

  try {
    const response = await fetch(url, {
      ...options,
      headers,
    });

    // Handle 401 Unauthorized with automatic atomic token refresh
    if (response.status === 401 && !isRetry && !endpoint.includes('/auth/')) {
      if (isRefreshing) {
        return new Promise((resolve, reject) => {
          refreshQueue.push((newToken) => {
            if (newToken) {
              apiRequest<T>(endpoint, options, true).then(resolve).catch(reject);
            } else {
              reject(new Error('Session expired. Please sign in again.'));
            }
          });
        });
      }

      isRefreshing = true;
      const refreshToken = await SecureTokenStorage.getRefreshToken();

      if (refreshToken) {
        try {
          const refreshRes = await fetch(`${currentBaseUrl}/auth/refresh`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ refreshToken }),
          });

          if (refreshRes.ok) {
            const refreshData = await refreshRes.json();
            const newAccess = refreshData.data?.accessToken;
            const newRefresh = refreshData.data?.refreshToken;

            if (newAccess) {
              await SecureTokenStorage.saveTokens({
                accessToken: newAccess,
                refreshToken: newRefresh,
              });
              isRefreshing = false;
              processQueue(newAccess);
              return apiRequest<T>(endpoint, options, true);
            }
          }
        } catch (err: any) {
          SafeLogger.warn('Automatic token refresh failed', err.message);
        }
      }

      isRefreshing = false;
      processQueue(null);
      await SecureTokenStorage.clear();
      if (onSessionExpiredListener) onSessionExpiredListener();
      throw new Error('Your session has expired. Please sign in again.');
    }

    const data: ApiResponse<T> = await response.json();
    if (!response.ok) {
      throw new Error(data.error || data.message || 'Request failed');
    }
    return data;
  } catch (err: any) {
    SafeLogger.error(`API Error on [${options.method || 'GET'}] ${endpoint}:`, err.message);
    throw err;
  }
}

export const MobileApi = {
  // ── Auth & Session ──────────────────────────────────────────
  login: async (credentials: { email: string; password: string }) => {
    const res = await apiRequest('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });
    if (res.data?.accessToken) {
      await SecureTokenStorage.setAccessToken(res.data.accessToken);
    }
    if (res.data?.refreshToken) {
      await SecureTokenStorage.setRefreshToken(res.data.refreshToken);
    }
    return res;
  },

  register: (payload: any) =>
    apiRequest('/auth/register', { method: 'POST', body: JSON.stringify(payload) }),

  /**
   * Mobile Google OAuth — send access_token obtained via expo-auth-session.
   * Backend verifies with Google and returns our own JWT pair.
   */
  googleAuth: async (token: string) => {
    await SecureTokenStorage.setAccessToken(token);
    try {
      const me = await apiRequest('/auth/me');
      if (me.data) return me;
    } catch {
      // If not a direct NexusBids session token, exchange with backend as Google token
      const res = await apiRequest('/auth/google/mobile', {
        method: 'POST',
        body: JSON.stringify({ access_token: token, id_token: token, token }),
      });
      if (res.data?.accessToken) {
        await SecureTokenStorage.setAccessToken(res.data.accessToken);
      }
      if (res.data?.refreshToken) {
        await SecureTokenStorage.setRefreshToken(res.data.refreshToken);
      }
      return res;
    }
  },

  verifyEmail: (token: string) =>
    apiRequest(`/auth/verify-email?token=${encodeURIComponent(token)}`),

  forgotPassword: (email: string) =>
    apiRequest('/auth/forgot-password', { method: 'POST', body: JSON.stringify({ email }) }),

  resetPassword: (payload: { token: string; password: string }) =>
    apiRequest('/auth/reset-password', { method: 'POST', body: JSON.stringify(payload) }),

  verify2Fa: async (userId: number, code: string) => {
    const res = await apiRequest('/auth/2fa/verify', {
      method: 'POST',
      body: JSON.stringify({ userId, code }),
    });
    if (res.data?.accessToken) await SecureTokenStorage.setAccessToken(res.data.accessToken);
    if (res.data?.refreshToken) await SecureTokenStorage.setRefreshToken(res.data.refreshToken);
    return res;
  },

  setup2Fa: () => apiRequest('/auth/2fa/setup', { method: 'POST' }),
  confirm2Fa: (code: string) =>
    apiRequest('/auth/2fa/confirm', { method: 'POST', body: JSON.stringify({ code }) }),
  disable2Fa: (password: string) =>
    apiRequest('/auth/2fa/disable', { method: 'POST', body: JSON.stringify({ password }) }),

  changePassword: (payload: { currentPassword: string; newPassword: string }) =>
    apiRequest('/auth/change-password', { method: 'POST', body: JSON.stringify(payload) }),

  logout: async () => {
    try {
      await apiRequest('/auth/logout', { method: 'POST' });
    } catch {}
    await SecureTokenStorage.clear();
  },

  logoutAll: async () => {
    try {
      await apiRequest('/auth/logout-all', { method: 'POST' });
    } catch {}
    await SecureTokenStorage.clear();
  },

  getMe: () => apiRequest('/auth/me'),
  updateProfile: (payload: any) =>
    apiRequest('/auth/profile', { method: 'PATCH', body: JSON.stringify(payload) }),

  // ── Auctions & Bidding ──────────────────────────────────────
  getAuctions: (params: Record<string, any> = {}) => {
    const q = new URLSearchParams(params).toString();
    return apiRequest(`/auctions${q ? `?${q}` : ''}`);
  },

  getAuctionDetail: (id: number) => apiRequest(`/auctions/${id}`),

  getAuctionBids: (id: number) => apiRequest(`/auctions/${id}/bids`),

  placeBid: (id: number, amount: number) =>
    apiRequest(`/auctions/${id}/bid`, {
      method: 'POST',
      body: JSON.stringify({ bid_amount: amount }),
    }),

  setAutoBid: (id: number, maxAmount: number) =>
    apiRequest(`/auctions/${id}/autobid`, {
      method: 'POST',
      body: JSON.stringify({ max_amount: maxAmount }),
    }),

  getMyAutoBid: (id: number) => apiRequest(`/auctions/${id}/autobid`),

  cancelAutoBid: (id: number) =>
    apiRequest(`/auctions/${id}/autobid`, { method: 'DELETE' }),

  buyNow: (id: number) =>
    apiRequest(`/auctions/${id}/buy-now`, { method: 'POST' }),

  // ── Watchlist ───────────────────────────────────────────────
  getWatchlist: () => apiRequest('/watchlist'),
  addToWatchlist: (auctionId: number) =>
    apiRequest(`/watchlist/${auctionId}`, { method: 'POST' }),
  removeFromWatchlist: (auctionId: number) =>
    apiRequest(`/watchlist/${auctionId}`, { method: 'DELETE' }),

  // ── Buyer & Orders ──────────────────────────────────────────
  getBuyerDashboard: () => apiRequest('/buyer/dashboard'),
  getMyBids: () => apiRequest('/buyer/bids'),
  getWonAuctions: () => apiRequest('/buyer/won-auctions'),
  getBuyerOrders: () => apiRequest('/orders/buyer'),
  getOrderDetail: (orderId: number) => apiRequest(`/orders/${orderId}`),
  confirmReceipt: (orderId: number) =>
    apiRequest(`/orders/${orderId}/confirm-receipt`, { method: 'POST' }),

  // ── Seller Operations ───────────────────────────────────────
  getSellerDashboard: () => apiRequest('/seller/dashboard'),
  getMySellerAuctions: (params: Record<string, any> = {}) => {
    const q = new URLSearchParams(params).toString();
    return apiRequest(`/seller/auctions${q ? `?${q}` : ''}`);
  },
  createAuction: (payload: any) =>
    apiRequest('/auctions', { method: 'POST', body: JSON.stringify(payload) }),
  updateAuction: (id: number, payload: any) =>
    apiRequest(`/auctions/${id}`, { method: 'PUT', body: JSON.stringify(payload) }),
  relistAuction: (id: number, payload?: any) =>
    apiRequest(`/auctions/${id}/relist`, {
      method: 'POST',
      body: payload ? JSON.stringify(payload) : undefined,
    }),
  suggestPrice: (payload: { title: string; category_id?: number; category?: string; condition?: string; description?: string }) =>
    apiRequest('/ai/suggest-price', { method: 'POST', body: JSON.stringify(payload) }),
  getSellerOrders: () => apiRequest('/orders/seller'),
  getSellerAnalytics: (days: number = 7) =>
    apiRequest(`/seller/analytics?days=${days}`),
  getSellerPayouts: () => apiRequest('/seller/payouts'),
  requestSellerPayout: (payload: {
    amount: number;
    method: string;
    account_title: string;
    account_number: string;
    notes?: string;
  }) => apiRequest('/seller/payout-request', { method: 'POST', body: JSON.stringify(payload) }),
  getSellerReviews: () => apiRequest('/seller/reviews'),
  replySellerReview: (reviewId: number, reply: string) =>
    apiRequest(`/seller/reviews/${reviewId}/reply`, {
      method: 'POST',
      body: JSON.stringify({ reply }),
    }),
  getSellerMessages: () => apiRequest('/seller/messages'),
  sendSellerMessage: (receiverId: number, message: string) =>
    apiRequest('/seller/messages', {
      method: 'POST',
      body: JSON.stringify({ receiver_id: receiverId, message }),
    }),

  // ── Payments & Escrow ───────────────────────────────────────
  getPaymentMethods: () => apiRequest('/payments/methods'),
  addPaymentMethod: (payload: any) =>
    apiRequest('/payments/methods', { method: 'POST', body: JSON.stringify(payload) }),
  initiateOrderPayment: (orderId: number, paymentType: string) =>
    apiRequest(`/payments/order/${orderId}/initiate`, {
      method: 'POST',
      body: JSON.stringify({ payment_type: paymentType }),
    }),
  createPaymentIntent: (orderId: number, paymentMethod: string, mode?: string) =>
    apiRequest('/payments/intent', {
      method: 'POST',
      body: JSON.stringify({ order_id: orderId, payment_method: paymentMethod, mode }),
    }),
  confirmPaymentReceipt: (orderId: number, receiptPath: string, txId: string) =>
    apiRequest(`/payments/order/${orderId}/confirm-receipt`, {
      method: 'POST',
      body: JSON.stringify({ receipt_path: receiptPath, transaction_id: txId }),
    }),
  confirmDelivery: (orderId: number, releaseEscrowNow = true) =>
    apiRequest(`/payments/order/${orderId}/confirm-delivery`, {
      method: 'POST',
      body: JSON.stringify({ release_escrow_now: releaseEscrowNow }),
    }),
  getPayouts: () => apiRequest('/payments/payouts'),
  requestPayout: (payload: { amount: number; method: string; account_details: any }) =>
    apiRequest('/payments/payouts/request', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),
  getWallet: () => apiRequest('/payments/wallet'),
  raiseDispute: (payload: { order_id: number; reason: string; description?: string }) =>
    apiRequest('/payments/dispute', { method: 'POST', body: JSON.stringify(payload) }),

  // ── Shipping & Addresses ────────────────────────────────────
  getAddresses: () => apiRequest('/shipping/addresses'),
  addAddress: (payload: any) =>
    apiRequest('/shipping/addresses', { method: 'POST', body: JSON.stringify(payload) }),
  setDefaultAddress: (id: number) =>
    apiRequest(`/shipping/addresses/${id}/default`, { method: 'PATCH' }),
  deleteAddress: (id: number) =>
    apiRequest(`/shipping/addresses/${id}`, { method: 'DELETE' }),
  createShipment: (orderId: number, payload: { courier: string; tracking_number: string; estimated_delivery?: string; note?: string }) =>
    apiRequest(`/shipping/orders/${orderId}/ship`, { method: 'POST', body: JSON.stringify(payload) }),
  getTracking: (orderId: number) =>
    apiRequest(`/shipping/orders/${orderId}/tracking`),
  addTrackingEvent: (orderId: number, payload: { status: string; location?: string; note?: string }) =>
    apiRequest(`/shipping/orders/${orderId}/tracking`, { method: 'POST', body: JSON.stringify(payload) }),

  // ── KYC Verification ────────────────────────────────────────
  getKycStatus: () => apiRequest('/kyc/status'),
  submitBuyerKyc: (payload: {
    full_name_on_cnic: string;
    cnic_number: string;
    date_of_birth: string;
    phone?: string;
    father_name?: string;
    address?: string;
    cnic_front_path: string;
    cnic_back_path: string;
    selfie_path: string;
    selfie_with_cnic_path: string;
  }) => apiRequest('/kyc/buyer', { method: 'POST', body: JSON.stringify(payload) }),
  submitSellerKyc: (payload: any) =>
    apiRequest('/kyc/seller', { method: 'POST', body: JSON.stringify(payload) }),

  // ── Notifications ───────────────────────────────────────────
  getNotifications: () => apiRequest('/notifications'),
  markNotificationsRead: () => apiRequest('/notifications/read-all', { method: 'PATCH' }),
  markNotificationRead: (id: number) => apiRequest(`/notifications/${id}/read`, { method: 'PATCH' }),
  getUnreadNotifCount: () => apiRequest('/notifications/unread-count'),

  // ── Reviews ─────────────────────────────────────────────────
  createReview: (payload: { order_id: number; seller_id: number; rating: number; comment: string }) =>
    apiRequest('/reviews', { method: 'POST', body: JSON.stringify(payload) }),
  getMyReviews: () => apiRequest('/reviews/my'),

  // ── Discovery & Categories ──────────────────────────────────
  getCategories: () => apiRequest('/categories'),
  getTrendingAuctions: (limit = 10) =>
    apiRequest(`/recommendations/trending?limit=${limit}`),
  getPersonalizedRecommendations: (limit = 10) =>
    apiRequest(`/recommendations/personalized?limit=${limit}`),

  // ── AI Assistant ────────────────────────────────────────────
  sendAiChat: (message: string) =>
    apiRequest('/ai/chat', { method: 'POST', body: JSON.stringify({ message }) }),
};

export { SecureTokenStorage };
