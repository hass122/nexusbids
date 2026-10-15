import { Request, Response, NextFunction } from 'express';
import { sendError } from '../utils/response.js';
import { ENV } from '../config/env.js';

export function errorHandler(
  err: any,
  req: Request,
  res: Response,
  next: NextFunction
): void {
  console.error(`[ERROR] ${req.method} ${req.originalUrl}:`, err);

  // Handle CORS policy violations
  if (err.message && err.message.includes('CORS')) {
    sendError(res, 'Cross-Origin Request Blocked by Security Policy', 403);
    return;
  }

  // Handle payload too large
  if (err.type === 'entity.too.large') {
    sendError(res, 'Request payload exceeds allowable limit.', 413);
    return;
  }

  const statusCode = err.status || err.statusCode || 500;

  // In production, never leak raw SQL errors, stack traces, or internal paths
  let message = err.message || 'Internal server error';
  if (ENV.NODE_ENV === 'production' && statusCode === 500) {
    message = 'An unexpected server error occurred. Please try again later.';
  } else if (ENV.NODE_ENV === 'production' && (err.code?.startsWith('ER_') || err.sql)) {
    message = 'A database error occurred. Please try again later.';
  }

  sendError(res, message, statusCode);
}
