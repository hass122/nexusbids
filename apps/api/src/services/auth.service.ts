import bcrypt from 'bcryptjs';
import jwt from 'jsonwebtoken';
import crypto from 'crypto';
import speakeasy from 'speakeasy';
import qrcode from 'qrcode';
import { query } from '../config/db.js';
import { ENV } from '../config/env.js';
import { EmailService } from './email.service.js';
import { JwtPayload, User, UserRole, KYCStatus, SellerKYCStatus, ACCESS_TOKEN_EXPIRY, REFRESH_TOKEN_EXPIRY } from '@nexusbids/shared';

export class AuthService {
  static async generateTokens(user: { userId: number; email: string; role: UserRole; fullName: string; kycStatus: KYCStatus; sellerKycStatus: SellerKYCStatus; }) {
    const accessPayload: JwtPayload = { ...user, type: 'access' };
    const refreshPayload: JwtPayload = { ...user, type: 'refresh' };

    const accessToken = jwt.sign(accessPayload, ENV.JWT.ACCESS_SECRET, { expiresIn: ACCESS_TOKEN_EXPIRY });
    const refreshToken = jwt.sign(refreshPayload, ENV.JWT.REFRESH_SECRET, { expiresIn: REFRESH_TOKEN_EXPIRY });

    const tokenHash = crypto.createHash('sha256').update(refreshToken).digest('hex');
    const expiresAt = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000);
    await query('INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)', [user.userId, tokenHash, expiresAt]).catch(() => { });

    return { accessToken, refreshToken };
  }

  static verifyToken(token: string) { return jwt.verify(token, ENV.JWT.ACCESS_SECRET); }

  static async register(data: any) {
    const existing = await query<any[]>('SELECT user_id FROM users WHERE email = ? LIMIT 1', [data.email.toLowerCase()]);
    if (existing.length > 0) throw new Error('This email is already registered.');

    const fullName = data.full_name || data.fullName || 'User';
    const hashedPassword = await bcrypt.hash(data.password, 10);
    const verifyToken = crypto.randomBytes(32).toString('hex');
    const role = data.role === 'seller' ? 'seller' : 'user';

    const result = await query<any>(`INSERT INTO users (full_name, email, phone, password, role, verify_token, status, email_verified, kyc_status, seller_kyc_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', 0, 'unverified', 'unverified', NOW())`, [fullName, data.email.toLowerCase(), data.phone || null, hashedPassword, role, verifyToken]);

    const userId = result.insertId;
    const verifyUrl = `${ENV.CLIENT_URL || 'http://localhost:5000'}/verify-email.html?token=${verifyToken}`;
    EmailService.sendWelcome(data.email.toLowerCase(), fullName, role).catch(() => { });
    EmailService.sendEmailVerification(data.email.toLowerCase(), fullName, verifyUrl).catch(() => { });

    return { userId, user_id: userId, email: data.email.toLowerCase(), verifyToken, message: 'Account created successfully. Please verify your email.' };
  }

  static async login(email: string, passwordPlain: string) {
    const users = await query<any[]>('SELECT * FROM users WHERE email = ? LIMIT 1', [email.toLowerCase()]);
    if (users.length === 0) throw new Error('Invalid email or password.');

    const user = users[0];
    if (user.status !== 'active') throw new Error('Your account is currently disabled or blocked.');
    if (user.locked_until && new Date(user.locked_until).getTime() > Date.now()) throw new Error('Account temporarily locked.');

    let passwordHash = user.password;
    if (passwordHash.startsWith('$2y$')) passwordHash = '$2a$' + passwordHash.substring(4);

    const isValid = await bcrypt.compare(passwordPlain, passwordHash);
    if (!isValid) {
      const attempts = (user.failed_attempts || 0) + 1;
      let lockedUntil: Date | null = attempts >= 5 ? new Date(Date.now() + 15 * 60 * 1000) : null;
      await query('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE user_id = ?', [attempts, lockedUntil, user.user_id]);
      throw new Error('Invalid email or password.');
    }

    await query('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', [user.user_id]);

    if (user.twofa_enabled) {
      const preAuthToken = jwt.sign(
        { userId: user.user_id, email: user.email, type: 'pre_auth' },
        ENV.JWT.ACCESS_SECRET,
        { expiresIn: '5m' }
      );
      return {
        requires2FA: true,
        userId: user.user_id,
        email: user.email,
        preAuthToken,
        message: 'Two-factor authentication required. Please enter the 6-digit code from your Authenticator app.',
      };
    }

    const tokens = await this.generateTokens({ userId: user.user_id, email: user.email, role: user.role as UserRole, fullName: user.full_name, kycStatus: user.kyc_status as KYCStatus, sellerKycStatus: user.seller_kyc_status as SellerKYCStatus });
    const userDto: User = { user_id: user.user_id, full_name: user.full_name, email: user.email, phone: user.phone, bio: user.bio, role: user.role, profile_image: user.profile_image || 'default.png', status: user.status, email_verified: Boolean(user.email_verified), kyc_status: user.kyc_status, seller_kyc_status: user.seller_kyc_status, created_at: user.created_at };

    return { user: userDto, ...tokens };
  }

  static async refreshToken(rawToken: string) {
    if (!rawToken) throw new Error('Refresh token is required.');
    let decoded: any;
    try { decoded = jwt.verify(rawToken, ENV.JWT.REFRESH_SECRET); } catch { throw new Error('Invalid or expired refresh token.'); }

    if (decoded.type !== 'refresh') throw new Error('Invalid token type.');

    const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
    const stored = await query<any[]>('SELECT id FROM refresh_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1', [tokenHash]);
    if (stored.length === 0) throw new Error('Refresh token revoked. Please log in again.');

    await query('DELETE FROM refresh_tokens WHERE token_hash = ?', [tokenHash]).catch(() => { });

    const users = await query<any[]>('SELECT * FROM users WHERE user_id = ? LIMIT 1', [decoded.userId]);
    if (users.length === 0) throw new Error('User no longer exists.');

    const user = users[0];
    if (user.status !== 'active') throw new Error('Account blocked.');

    const tokens = await this.generateTokens({ userId: user.user_id, email: user.email, role: user.role as UserRole, fullName: user.full_name, kycStatus: user.kyc_status as KYCStatus, sellerKycStatus: user.seller_kyc_status as SellerKYCStatus });
    const userDto: User = { user_id: user.user_id, full_name: user.full_name, email: user.email, phone: user.phone, bio: user.bio, role: user.role, profile_image: user.profile_image || 'default.png', status: user.status, email_verified: Boolean(user.email_verified), kyc_status: user.kyc_status, seller_kyc_status: user.seller_kyc_status, created_at: user.created_at };

    return { user: userDto, ...tokens };
  }

  static async forgotPassword(email: string, ipAddress?: string) {
    const users = await query<any[]>('SELECT user_id, email, full_name FROM users WHERE email = ? LIMIT 1', [email.toLowerCase()]);
    if (users.length === 0) return { message: 'If this email is registered, you will receive password reset instructions.' };

    const user = users[0];
    const rawToken = crypto.randomBytes(32).toString('hex');
    const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
    const expiresAt = new Date(Date.now() + 60 * 60 * 1000);

    await query(`INSERT INTO password_resets (user_id, token, expires_at, used, ip_address, created_at) VALUES (?, ?, ?, 0, ?, NOW())`, [user.user_id, tokenHash, expiresAt, ipAddress || null]);
    await query('UPDATE users SET reset_token = ?, reset_expires = ? WHERE user_id = ?', [tokenHash, expiresAt, user.user_id]);

    // Actually send the reset email (previously missing!)
    const resetUrl = `${ENV.CLIENT_URL || 'http://localhost:5000'}/reset-password.html?token=${rawToken}`;
    EmailService.sendPasswordReset(user.email, user.full_name || 'User', resetUrl).catch((e: any) => {
      console.warn('[forgotPassword] email send failed:', e?.message);
    });

    return { message: 'If this email is registered, you will receive password reset instructions.', ...(ENV.NODE_ENV !== 'production' ? { devResetToken: rawToken } : {}) };
  }

  static async resetPassword(token: string, newPassword: string) {
    if (!token || !newPassword) throw new Error('Reset token and new password are required.');
    if (newPassword.length < 8) throw new Error('Password must be at least 8 characters long.');

    const tokenHash = crypto.createHash('sha256').update(token).digest('hex');
    const resets = await query<any[]>(`SELECT * FROM password_resets WHERE (token = ? OR token = ?) AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1`, [tokenHash, token]);

    let userId: number | null = null;
    let resetId: number | null = null;

    if (resets.length > 0) { userId = resets[0].user_id; resetId = resets[0].id; }
    else {
      const users = await query<any[]>(`SELECT user_id FROM users WHERE (reset_token = ? OR reset_token = ?) AND reset_expires > NOW() LIMIT 1`, [tokenHash, token]);
      if (users.length > 0) userId = users[0].user_id;
    }

    if (!userId) throw new Error('Invalid or expired password reset link.');

    const hashedPassword = await bcrypt.hash(newPassword, 10);
    await query(`UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL, failed_attempts = 0, locked_until = NULL WHERE user_id = ?`, [hashedPassword, userId]);

    if (resetId) await query('UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = ?', [resetId]);
    await query('UPDATE password_resets SET used = 1, used_at = NOW() WHERE user_id = ? AND used = 0', [userId]);

    return { message: 'Password reset successfully.' };
  }

  static async verify2FaOtp(userId: number, code: string, preAuthToken?: string) {
    if (!code || code.length !== 6) throw new Error('Verification code must be 6 digits.');

    const users = await query<any[]>('SELECT * FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (users.length === 0 || users[0].status !== 'active') throw new Error('User not found or inactive.');
    const user = users[0];

    if (user.locked_until && new Date(user.locked_until).getTime() > Date.now()) {
      throw new Error('Account temporarily locked due to failed attempts. Please try again later.');
    }

    if (preAuthToken) {
      try {
        const decoded: any = jwt.verify(preAuthToken, ENV.JWT.ACCESS_SECRET);
        if (decoded.type !== 'pre_auth' || decoded.userId !== userId) {
          throw new Error('Invalid pre-auth session.');
        }
      } catch {
        throw new Error('Pre-authentication session expired or invalid. Please log in again.');
      }
    }

    if (!user.twofa_secret) throw new Error('2FA is not set up properly.');

    const verified = speakeasy.totp.verify({ secret: user.twofa_secret, encoding: 'base32', token: code, window: 1 });
    if (!verified) {
      const attempts = (user.failed_attempts || 0) + 1;
      let lockedUntil: Date | null = attempts >= 5 ? new Date(Date.now() + 15 * 60 * 1000) : null;
      await query('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE user_id = ?', [attempts, lockedUntil, user.user_id]);
      throw new Error(attempts >= 5 ? 'Account locked for 15 minutes due to multiple failed 2FA attempts.' : 'Invalid Authenticator code.');
    }

    await query('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?', [user.user_id]);

    const tokens = await this.generateTokens({ userId: user.user_id, email: user.email, role: user.role as UserRole, fullName: user.full_name, kycStatus: user.kyc_status as KYCStatus, sellerKycStatus: user.seller_kyc_status as SellerKYCStatus });
    const userDto: User = { user_id: user.user_id, full_name: user.full_name, email: user.email, phone: user.phone, bio: user.bio, role: user.role, profile_image: user.profile_image || 'default.png', status: user.status, email_verified: Boolean(user.email_verified), kyc_status: user.kyc_status, seller_kyc_status: user.seller_kyc_status, created_at: user.created_at };

    return { user: userDto, ...tokens };
  }

  static async setup2FA(userId: number, ipAddress?: string) {
    const users = await query<any[]>('SELECT email FROM users WHERE user_id = ? LIMIT 1', [userId]);
    const email = users[0]?.email || 'user@nexusbids.pk';

    const secret = speakeasy.generateSecret({ name: `NexusBids (${email})` });
    await query('UPDATE users SET twofa_secret = ? WHERE user_id = ?', [secret.base32, userId]);
    const qr_url = await qrcode.toDataURL(secret.otpauth_url as string);

    return { secret: secret.base32, qr_url: qr_url, message: 'Scan the QR code with your authenticator app.' };
  }

  static async confirm2FA(userId: number, code: string) {
    const users = await query<any[]>('SELECT twofa_secret FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (users.length === 0 || !users[0].twofa_secret) throw new Error('2FA setup not initiated.');

    const secret = users[0].twofa_secret;
    const verified = speakeasy.totp.verify({ secret: secret, encoding: 'base32', token: code, window: 1 });

    if (!verified) throw new Error('Invalid Authenticator code.');

    await query('UPDATE users SET twofa_enabled = 1, twofa_confirmed_at = NOW() WHERE user_id = ?', [userId]);
    return { message: 'Two-factor authentication enabled.' };
  }

  static async disable2FA(userId: number, passwordPlain: string) {
    const users = await query<any[]>('SELECT password FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (users.length === 0) throw new Error('User not found.');

    let passwordHash = users[0].password;
    if (passwordHash.startsWith('$2y$')) passwordHash = '$2a$' + passwordHash.substring(4);

    const isValid = await bcrypt.compare(passwordPlain, passwordHash);
    if (!isValid) throw new Error('Invalid current password.');

    await query('UPDATE users SET twofa_enabled = 0, twofa_confirmed_at = NULL, twofa_secret = NULL WHERE user_id = ?', [userId]);
    return { message: 'Two-factor authentication disabled.' };
  }

  static async verifyEmail(token: string) {
    const users = await query<any[]>('SELECT user_id FROM users WHERE verify_token = ? LIMIT 1', [token]);
    if (users.length === 0) throw new Error('Invalid or expired email verification link.');
    await query('UPDATE users SET email_verified = 1, verify_token = NULL WHERE user_id = ?', [users[0].user_id]);
    return { verified: true };
  }

  static async updateProfile(userId: number, data: any) {
    const user = await query<any[]>('SELECT user_id FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (user.length === 0) throw new Error('User not found.');

    const updates: string[] = [];
    const params: any[] = [];

    if (data.full_name !== undefined && data.full_name.trim() !== '') { updates.push('full_name = ?'); params.push(data.full_name.trim()); }
    if (data.phone !== undefined) { updates.push('phone = ?'); params.push(data.phone.trim() || null); }
    if (data.city !== undefined) { updates.push('city = ?'); params.push(data.city.trim() || null); }

    if (updates.length > 0) { params.push(userId); await query(`UPDATE users SET ${updates.join(', ')} WHERE user_id = ?`, params); }

    const updated = await query<any[]>(`SELECT user_id, full_name, email, phone, city, role, status, kyc_status, seller_kyc_status, email_verified, twofa_enabled, created_at FROM users WHERE user_id = ? LIMIT 1`, [userId]);
    const u = updated[0];
    return { ...u, is_2fa_enabled: Boolean(u.twofa_enabled), is_email_verified: Boolean(u.email_verified) };
  }

  static async changePassword(userId: number, oldPasswordPlain: string, newPasswordPlain: string) {
    if (!newPasswordPlain || newPasswordPlain.length < 8) throw new Error('New password must be at least 8 characters long.');

    const users = await query<any[]>('SELECT password FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (users.length === 0) throw new Error('User not found.');

    let passwordHash = users[0].password;
    if (passwordHash.startsWith('$2y$')) passwordHash = '$2a$' + passwordHash.substring(4);

    const isValid = await bcrypt.compare(oldPasswordPlain, passwordHash);
    if (!isValid) throw new Error('Current password is incorrect.');

    const hashedNew = await bcrypt.hash(newPasswordPlain, 10);
    await query('UPDATE users SET password = ? WHERE user_id = ?', [hashedNew, userId]);
    return { message: 'Password changed successfully.' };
  }

  static async logoutAll(userId: number) {
    await Promise.all([
      query('DELETE FROM active_sessions WHERE user_id = ?', [userId]).catch(() => { }),
      query('DELETE FROM refresh_tokens WHERE user_id = ?', [userId]).catch(() => { }),
    ]);
    return { message: 'All active sessions have been revoked.' };
  }
}