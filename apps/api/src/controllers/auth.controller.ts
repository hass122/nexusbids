import { Request, Response } from 'express';
import crypto from 'crypto';
import { AuthService } from '../services/auth.service.js';
import { OAuthService } from '../services/oauth.service.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { ENV } from '../config/env.js';


function setAuthCookies(res: Response, accessToken?: string, refreshToken?: string) {
  // sameSite: 'none' requires secure: true (HTTPS). Use 'lax' for same-domain deployments.
  // Set ENV.COOKIE_SAMESITE='none' when frontend and API are on different domains.
  const sameSiteMode = ((ENV as any).COOKIE_SAMESITE === 'none' ? 'none' : 'lax') as 'lax' | 'none';
  const cookieOpts = {
    httpOnly: true,
    secure: ENV.NODE_ENV === 'production' || sameSiteMode === 'none',
    sameSite: sameSiteMode,
  };

  if (accessToken) {
    res.cookie('accessToken', accessToken, {
      ...cookieOpts,
      maxAge: 15 * 60 * 1000, // 15 mins
    });
  }

  if (refreshToken) {
    res.cookie('refreshToken', refreshToken, {
      ...cookieOpts,
      path: '/api/v1/auth',
      maxAge: 7 * 24 * 60 * 60 * 1000, // 7 days
    });
  }
}

export class AuthController {
  // Register
  static async register(req: Request, res: Response): Promise<void> {
    try {
      const result = await AuthService.register(req.body);
      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Login
  static async login(req: Request, res: Response): Promise<void> {
    try {
      const { email, password } = req.body;
      const result: any = await AuthService.login(email, password);

      // If 2FA is required, don't set auth tokens yet
      if (result.requires2FA) {
        sendSuccess(res, result, result.message, 200);
        return;
      }

      setAuthCookies(res, result.accessToken, result.refreshToken);
      sendSuccess(res, result, 'Logged in successfully');
    } catch (err: any) {
      sendError(res, err.message, 401);
    }
  }

  // Refresh JWT Access Token
  static async refreshToken(req: Request, res: Response): Promise<void> {
    try {
      const rawToken =
        req.cookies?.refreshToken ||
        req.body?.refreshToken ||
        (typeof req.headers['x-refresh-token'] === 'string' ? req.headers['x-refresh-token'] : undefined) ||
        (req.headers.authorization?.startsWith('Bearer ') ? req.headers.authorization.slice(7) : undefined);

      if (!rawToken) {
        sendError(res, 'Refresh token is required', 401);
        return;
      }

      const result = await AuthService.refreshToken(rawToken);
      setAuthCookies(res, result.accessToken, result.refreshToken);
      sendSuccess(res, result, 'Token refreshed successfully');
    } catch (err: any) {
      sendError(res, err.message, 401);
    }
  }

  // Request Password Reset
  static async forgotPassword(req: Request, res: Response): Promise<void> {
    try {
      const { email } = req.body;
      const ip = req.ip || req.socket.remoteAddress;
      const result = await AuthService.forgotPassword(email, ip);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Reset Password
  static async resetPassword(req: Request, res: Response): Promise<void> {
    try {
      const { token, password } = req.body;
      const result = await AuthService.resetPassword(token, password);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Verify 2FA OTP Code
  static async verify2Fa(req: Request, res: Response): Promise<void> {
    try {
      const { user_id, userId, code } = req.body;
      const targetUserId = Number(user_id || userId);
      if (!targetUserId || !code) {
        sendError(res, 'User ID and 6-digit verification code are required', 400);
        return;
      }

      const result: any = await AuthService.verify2FaOtp(targetUserId, String(code).trim());
      setAuthCookies(res, result.accessToken, result.refreshToken);
      sendSuccess(res, result, 'Two-factor authentication verified successfully');
    } catch (err: any) {
      sendError(res, err.message, 401);
    }
  }

  // Setup 2FA (Authenticated)
  static async setup2Fa(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      const ip = req.ip || req.socket.remoteAddress;
      const result = await AuthService.setup2FA(req.user.userId, ip);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Confirm 2FA (Authenticated)
  static async confirm2Fa(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      const { code } = req.body;
      if (!code) {
        sendError(res, 'Verification code is required', 400);
        return;
      }
      const result = await AuthService.confirm2FA(req.user.userId, String(code).trim());
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Disable 2FA (Authenticated)
  static async disable2Fa(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      const { password } = req.body;
      if (!password) {
        sendError(res, 'Current password is required to disable 2FA', 400);
        return;
      }
      const result = await AuthService.disable2FA(req.user.userId, password);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Logout
  static async logout(req: Request, res: Response): Promise<void> {
    try {
      const rawToken =
        req.cookies?.refreshToken ||
        req.body?.refreshToken ||
        (typeof req.headers['x-refresh-token'] === 'string' ? req.headers['x-refresh-token'] : undefined);

      // Invalidate the refresh token server-side if present
      if (rawToken && typeof (AuthService as any).logout === 'function') {
        await (AuthService as any).logout(rawToken).catch((e: any) => {
          console.warn('[logout] refresh token revoke failed:', e?.message);
        });
      }
    } catch (err: any) {
      console.warn('[logout] error:', err?.message);
    }

    res.clearCookie('accessToken');
    res.clearCookie('refreshToken', { path: '/api/v1/auth' });
    res.clearCookie('oauth_state');
    sendSuccess(res, null, 'Logged out successfully');
  }

  // Logout from all devices
  static async logoutAll(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      res.clearCookie('accessToken');
      res.clearCookie('refreshToken', { path: '/api/v1/auth' });
      res.clearCookie('refreshToken', { path: '/api/v1/auth/refresh' });
      const result = await AuthService.logoutAll(req.user.userId);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Get current user session
  static async getMe(req: Request, res: Response): Promise<void> {
    if (!req.user) {
      sendError(res, 'Not authenticated', 401);
      return;
    }
    try {
      const { query: dbQuery } = await import('../config/db.js');
      const users = await dbQuery(
        `SELECT user_id, full_name, email, phone, city, role, status,
                kyc_status, seller_kyc_status, email_verified, twofa_enabled, created_at
         FROM users WHERE user_id = ? LIMIT 1`,
        [(req.user as any).userId]
      );
      if (!users || users.length === 0) {
        sendError(res, 'User not found', 404);
        return;
      }
      const u = users[0];
      sendSuccess(res, {
        ...u,
        is_2fa_enabled: Boolean(u.twofa_enabled),
        is_email_verified: Boolean(u.email_verified),
      });
    } catch (err: any) {
      // DB fail — DO NOT return raw req.user (incomplete data). Fail loud instead.
      console.error('[getMe] DB query failed:', err?.message);
      sendError(res, 'Could not load profile. Please try again.', 500);
    }
  }

  // Update profile
  static async updateProfile(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      const { full_name, phone, city } = req.body;
      const result = await AuthService.updateProfile(req.user.userId, { full_name, phone, city });
      sendSuccess(res, result, 'Profile updated successfully');
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Change password
  static async changePassword(req: Request, res: Response): Promise<void> {
    try {
      if (!req.user) {
        sendError(res, 'Authentication required', 401);
        return;
      }
      const { old_password, new_password } = req.body;
      if (!old_password || !new_password) {
        sendError(res, 'Both current and new passwords are required', 400);
        return;
      }
      const result = await AuthService.changePassword(req.user.userId, old_password, new_password);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Verify Email
  static async verifyEmail(req: Request, res: Response): Promise<void> {
    try {
      const token = req.query.token as string;
      if (!token) {
        sendError(res, 'Verification token is required', 400);
        return;
      }
      const result = await AuthService.verifyEmail(token);
      sendSuccess(res, result, 'Email verified successfully! You can now log in.');
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Google OAuth 2.0 Initiate
  static async googleAuth(req: Request, res: Response): Promise<void> {
    try {
      if (!OAuthService.isConfigured()) {
        res.redirect('/login.html?error=oauth_not_configured');
        return;
      }

      const state = crypto.randomBytes(32).toString('hex');
      res.cookie('oauth_state', state, {
        httpOnly: true,
        secure: ENV.NODE_ENV === 'production',
        sameSite: 'lax',
        maxAge: 10 * 60 * 1000, // 10 minutes
      });

      const authUrl = OAuthService.getGoogleAuthUrl(state);
      res.redirect(authUrl);
    } catch (err: any) {
      console.error('[Google OAuth Initiate Error]', err.message);
      res.redirect('/login.html?error=oauth_error');
    }
  }

  // Google OAuth 2.0 Callback
  static async googleCallback(req: Request, res: Response): Promise<void> {
    const { code, state, error } = req.query;

    if (error) {
      res.clearCookie('oauth_state');
      if (error === 'access_denied') {
        res.redirect('/login.html?error=user_cancelled');
      } else {
        res.redirect('/login.html?error=oauth_denied');
      }
      return;
    }

    const savedState = req.cookies?.oauth_state;
    res.clearCookie('oauth_state');

    if (!state || !savedState || state !== savedState) {
      res.redirect('/login.html?error=invalid_state');
      return;
    }

    if (!code || typeof code !== 'string') {
      res.redirect('/login.html?error=invalid_code');
      return;
    }

    try {
      const tokenData = await OAuthService.exchangeCodeForTokens(code);
      const profile = await OAuthService.getGoogleUserInfo(tokenData.access_token);

      if (!profile.email) {
        res.redirect('/login.html?error=email_unavailable');
        return;
      }

      const result = await OAuthService.findOrCreateGoogleUser(profile);

      // Set standard application authentication cookies
      setAuthCookies(res, result.accessToken, result.refreshToken);

      // Resolve destination dashboard according to user role
      let dashboardPath = '/buyer/dashboard.html';
      if (result.user.role === 'admin') dashboardPath = '/admin/dashboard.html';
      else if (result.user.role === 'seller') dashboardPath = '/seller/dashboard.html';

      // Safely deliver token to localStorage without exposing in URL query/hash
      const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>NexusBids Authentication</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { background: #0b1220; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
    .loader { text-align: center; }
    .spinner { border: 3px solid rgba(255,255,255,0.1); border-top-color: #6366f1; border-radius: 50%; width: 40px; height: 40px; animation: spin 0.8s linear infinite; margin: 0 auto 16px; }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div class="loader">
    <div class="spinner"></div>
    <p>Signing in with Google...</p>
  </div>
  <script>
    try {
      localStorage.setItem('nexusbids_access_token', ${JSON.stringify(result.accessToken)});
    } catch (e) {}
    window.location.replace(${JSON.stringify(dashboardPath)});
  </script>
</body>
</html>`;

      res.setHeader('Content-Type', 'text/html; charset=utf-8');
      res.send(html);
    } catch (err: any) {
      console.error('[Google OAuth Callback Error]', err.message);
      if (err.message?.includes('disabled or blocked')) {
        res.redirect('/login.html?error=account_blocked');
      } else if (err.message?.includes('authorization code')) {
        res.redirect('/login.html?error=invalid_code');
      } else {
        res.redirect('/login.html?error=oauth_error');
      }
    }
  }

  /**
   * POST /auth/google/mobile
   * Mobile-specific OAuth endpoint: Expo app sends Google access_token obtained
   * via expo-auth-session. Backend verifies identity with Google and returns
   * NexusBids JWT tokens in JSON (stored in SecureStore on device, not cookies).
   */
  static async googleMobileAuth(req: Request, res: Response): Promise<void> {
    try {
      const { access_token } = req.body;

      if (!access_token || typeof access_token !== 'string') {
        sendError(res, 'Google access_token is required', 400);
        return;
      }

      // Verify the token and fetch user profile from Google
      const profile = await OAuthService.getGoogleUserInfo(access_token);

      if (!profile.email) {
        sendError(res, 'Google account email is unavailable or not verified', 403);
        return;
      }

      // Find existing user or create a new one with role 'user'
      const result = await OAuthService.findOrCreateGoogleUser(profile);

      // Return tokens in JSON — mobile app will store in SecureStore
      sendSuccess(res, {
        accessToken: result.accessToken,
        refreshToken: result.refreshToken,
        user: result.user,
      }, 'Google sign-in successful');
    } catch (err: any) {
      console.error('[Google Mobile Auth Error]', err.message);
      if (err.message?.includes('disabled or blocked')) {
        sendError(res, 'Your account is currently disabled. Please contact support.', 403);
      } else {
        sendError(res, 'Google authentication failed. Please try again.', 400);
      }
    }
  }
}
