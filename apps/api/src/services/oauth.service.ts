import { query } from '../config/db.js';
import { ENV } from '../config/env.js';
import { AuthService } from './auth.service.js';
import {
  User,
  UserRole,
  KYCStatus,
  SellerKYCStatus,
} from '@nexusbids/shared';

export interface GoogleUserInfo {
  id: string;
  email: string;
  verified_email?: boolean;
  name?: string;
  given_name?: string;
  family_name?: string;
  picture?: string;
}

export class OAuthService {
  /**
   * Check if Google OAuth credentials are configured in environment
   */
  static isConfigured(): boolean {
    return Boolean(ENV.GOOGLE.CLIENT_ID && ENV.GOOGLE.CLIENT_SECRET && ENV.GOOGLE.CALLBACK_URL);
  }

  /**
   * Generate Google OAuth 2.0 Authorization URL
   */
  static getGoogleAuthUrl(state: string): string {
    if (!this.isConfigured()) {
      throw new Error('OAuth configuration missing');
    }

    const params = new URLSearchParams({
      client_id: ENV.GOOGLE.CLIENT_ID,
      redirect_uri: ENV.GOOGLE.CALLBACK_URL,
      response_type: 'code',
      scope: 'openid email profile',
      state,
      access_type: 'offline',
      prompt: 'select_account',
    });

    return `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`;
  }

  /**
   * Exchange OAuth authorization code for Google access token
   */
  static async exchangeCodeForTokens(code: string): Promise<{ access_token: string; id_token?: string }> {
    const tokenUrl = 'https://oauth2.googleapis.com/token';

    const body = new URLSearchParams({
      code,
      client_id: ENV.GOOGLE.CLIENT_ID,
      client_secret: ENV.GOOGLE.CLIENT_SECRET,
      redirect_uri: ENV.GOOGLE.CALLBACK_URL,
      grant_type: 'authorization_code',
    });

    const res = await fetch(tokenUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json',
      },
      body: body.toString(),
    });

    if (!res.ok) {
      const errorText = await res.text().catch(() => '');
      console.error('[Google OAuth] Token exchange error status:', res.status, errorText);
      throw new Error('Failed to exchange authorization code with Google');
    }

    const data: any = await res.json();
    if (!data.access_token) {
      throw new Error('Google token response missing access_token');
    }

    return data;
  }

  /**
   * Fetch user info from Google using access token
   */
  static async getGoogleUserInfo(accessToken: string): Promise<GoogleUserInfo> {
    const userinfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo';

    const res = await fetch(userinfoUrl, {
      headers: {
        Authorization: `Bearer ${accessToken}`,
        Accept: 'application/json',
      },
    });

    if (!res.ok) {
      const errText = await res.text().catch(() => '');
      console.error('[Google OAuth] Userinfo error status:', res.status, errText);
      throw new Error('Failed to retrieve user information from Google');
    }

    const profile = (await res.json()) as GoogleUserInfo;

    if (!profile.id || !profile.email) {
      throw new Error('Google profile incomplete: missing user ID or email');
    }

    return profile;
  }

  /**
   * Find existing user by google_id or verified email, or create new normal user.
   * Preserves existing roles and data. Default role for new users is strictly 'user'.
   */
  static async findOrCreateGoogleUser(profile: GoogleUserInfo): Promise<{
    user: User;
    accessToken: string;
    refreshToken: string;
  }> {
    const googleId = profile.id;
    const email = profile.email.toLowerCase().trim();
    const fullName = (profile.name || 'Google User').trim().slice(0, 100);
    const picture = profile.picture || null;

    // 1. Check if user with this google_id already exists
    let existing = await query<any[]>(
      'SELECT * FROM users WHERE google_id = ? LIMIT 1',
      [googleId]
    );

    let user: any = null;

    if (existing.length > 0) {
      user = existing[0];
    } else {
      // 2. Check if user with this verified email already exists
      existing = await query<any[]>(
        'SELECT * FROM users WHERE email = ? LIMIT 1',
        [email]
      );

      if (existing.length > 0) {
        user = existing[0];
        // Link Google ID to existing account and ensure email_verified is true
        // Preserve existing user role, permissions, status, and account data
        await query(
          `UPDATE users SET 
            google_id = ?,
            email_verified = 1,
            auth_provider = IF(auth_provider IS NULL OR auth_provider = 'local', 'google', auth_provider),
            profile_image = IF(profile_image IS NULL OR profile_image = '' OR profile_image = 'default.png', ?, profile_image),
            avatar = IF(avatar IS NULL OR avatar = '', ?, avatar)
           WHERE user_id = ?`,
          [googleId, picture, picture, user.user_id]
        );
        user.google_id = googleId;
        user.email_verified = 1;
        if ((!user.profile_image || user.profile_image === 'default.png') && picture) {
          user.profile_image = picture;
        }
      } else {
        // 3. User does not exist — Create normal user
        // Default role is strictly 'user' (buyer). Never assign privileged or admin roles.
        const insertResult = await query<any>(
          `INSERT INTO users 
            (full_name, email, google_id, auth_provider, role, status, email_verified, kyc_status, seller_kyc_status, profile_image, avatar, created_at)
           VALUES (?, ?, ?, 'google', 'user', 'active', 1, 'unverified', 'unverified', ?, ?, NOW())`,
          [fullName, email, googleId, picture, picture]
        );

        const freshUsers = await query<any[]>(
          'SELECT * FROM users WHERE user_id = ? LIMIT 1',
          [insertResult.insertId]
        );
        user = freshUsers[0];
      }
    }

    // 4. Verify account status
    if (user.status !== 'active') {
      throw new Error('Your account is currently disabled or blocked.');
    }

    // 5. Reset failed login attempts on successful OAuth login
    await query(
      'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?',
      [user.user_id]
    ).catch(() => {});

    // 6. Generate existing application tokens (dual JWT)
    const tokens = await AuthService.generateTokens({
      userId: user.user_id,
      email: user.email,
      role: user.role as UserRole,
      fullName: user.full_name,
      kycStatus: user.kyc_status as KYCStatus,
      sellerKycStatus: user.seller_kyc_status as SellerKYCStatus,
    });

    const userDto: User = {
      user_id: user.user_id,
      full_name: user.full_name,
      email: user.email,
      phone: user.phone || null,
      bio: user.bio || null,
      role: user.role,
      profile_image: user.profile_image || 'default.png',
      status: user.status,
      email_verified: Boolean(user.email_verified),
      kyc_status: user.kyc_status,
      seller_kyc_status: user.seller_kyc_status,
      google_id: user.google_id,
      auth_provider: user.auth_provider,
      created_at: user.created_at,
    };

    return {
      user: userDto,
      accessToken: tokens.accessToken,
      refreshToken: tokens.refreshToken,
    };
  }
}
