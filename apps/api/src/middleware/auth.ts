import { Request, Response, NextFunction } from 'express';
import jwt from 'jsonwebtoken';
import { ENV } from '../config/env.js';
import { JwtPayload, UserRole, KYCStatus, SellerKYCStatus } from '@nexusbids/shared';
import { sendError } from '../utils/response.js';
import { query } from '../config/db.js';

// Extend Express Request type
declare global {
  namespace Express {
    interface Request {
      user?: JwtPayload;
    }
  }
}

export async function requireAuth(req: Request, res: Response, next: NextFunction): Promise<void> {
  let token: string | undefined;

  // 1. Check Bearer Authorization header
  const authHeader = req.headers.authorization;
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.split(' ')[1];
  }

  // 2. Check httpOnly cookie
  if (!token && req.cookies && req.cookies.accessToken) {
    token = req.cookies.accessToken;
  }

  if (!token) {
    sendError(res, 'Authentication required. Please log in.', 401);
    return;
  }

  try {
    const decoded = jwt.verify(token, ENV.JWT.ACCESS_SECRET) as JwtPayload;

    if (decoded.type !== 'access') {
      sendError(res, 'Invalid token type', 401);
      return;
    }

    // Verify user is still active in database
    const users = await query<any[]>(
      'SELECT user_id, status, kyc_status, seller_kyc_status, role FROM users WHERE user_id = ? LIMIT 1',
      [decoded.userId]
    );

    if (users.length === 0 || users[0].status !== 'active') {
      sendError(res, 'Your account is currently disabled or blocked.', 403);
      return;
    }

    // Update payload with fresh status from DB
    decoded.kycStatus = users[0].kyc_status as KYCStatus;
    decoded.sellerKycStatus = users[0].seller_kyc_status as SellerKYCStatus;
    decoded.role = users[0].role as UserRole;

    req.user = decoded;
    next();
  } catch (error) {
    sendError(res, 'Invalid or expired session. Please log in again.', 401);
  }
}

export function requireRole(...allowedRoles: UserRole[]) {
  return (req: Request, res: Response, next: NextFunction): void => {
    if (!req.user) {
      sendError(res, 'Unauthorized', 401);
      return;
    }

    if (!allowedRoles.includes(req.user.role)) {
      sendError(res, 'You do not have permission to access this resource.', 403);
      return;
    }

    next();
  };
}

export function requireKyc(req: Request, res: Response, next: NextFunction): void {
  if (!req.user) {
    sendError(res, 'Unauthorized', 401);
    return;
  }

  if (req.user.role === 'admin') {
    return next(); // Admin exempt
  }

  if (req.user.kycStatus !== 'approved') {
    sendError(
      res,
      'Identity verification required. Please complete KYC before proceeding.',
      403,
      { kyc_status: req.user.kycStatus }
    );
    return;
  }

  next();
}

export function requireSellerKyc(req: Request, res: Response, next: NextFunction): void {
  if (!req.user) {
    sendError(res, 'Unauthorized', 401);
    return;
  }

  if (req.user.role === 'admin') {
    return next();
  }

  if (req.user.role !== 'seller') {
    sendError(res, 'Only registered sellers can perform this action.', 403);
    return;
  }

  if (req.user.sellerKycStatus !== 'approved') {
    sendError(
      res,
      'Seller KYC verification required before managing auctions.',
      403,
      { seller_kyc_status: req.user.sellerKycStatus }
    );
    return;
  }

  next();
}

export async function optionalAuth(req: Request, res: Response, next: NextFunction): Promise<void> {
  let token: string | undefined;

  const authHeader = req.headers.authorization;
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.split(' ')[1];
  } else if (req.cookies && req.cookies.accessToken) {
    token = req.cookies.accessToken;
  }

  if (!token) {
    return next();
  }

  try {
    const decoded = jwt.verify(token, ENV.JWT.ACCESS_SECRET) as JwtPayload;
    if (decoded.type === 'access') {
      req.user = decoded;
    }
  } catch {}

  next();
}
