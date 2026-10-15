import { Request, Response, NextFunction } from 'express';
import { sendError } from '../utils/response.js';

interface RateLimitRecord {
  count: number;
  resetTime: number;
}

class MemoryRateLimiter {
  private store = new Map<string, RateLimitRecord>();
  private windowMs: number;
  private maxRequests: number;
  private message: string;

  constructor(options: { windowMs: number; maxRequests: number; message: string }) {
    this.windowMs = options.windowMs;
    this.maxRequests = options.maxRequests;
    this.message = options.message;

    // Periodic cleanup of expired records every 5 minutes
    setInterval(() => this.cleanup(), 5 * 60 * 1000).unref();
  }

  private cleanup() {
    const now = Date.now();
    for (const [key, record] of this.store.entries()) {
      if (record.resetTime <= now) {
        this.store.delete(key);
      }
    }
    if (this.store.size > 20000) {
      this.store.clear();
    }
  }

  public middleware() {
    return (req: Request, res: Response, next: NextFunction): void => {
      const now = Date.now();
      // Derive client identifier: X-Forwarded-For or remote IP or user ID if authenticated
      const ip = (req.headers['x-forwarded-for'] as string)?.split(',')[0]?.trim() || req.socket.remoteAddress || 'unknown';
      const key = `${ip}:${req.user?.userId || 'anon'}`;

      let record = this.store.get(key);

      if (!record || record.resetTime <= now) {
        record = {
          count: 1,
          resetTime: now + this.windowMs,
        };
        this.store.set(key, record);
      } else {
        record.count++;
      }

      const remaining = Math.max(0, this.maxRequests - record.count);
      const resetSeconds = Math.ceil((record.resetTime - now) / 1000);

      res.setHeader('X-RateLimit-Limit', this.maxRequests);
      res.setHeader('X-RateLimit-Remaining', remaining);
      res.setHeader('X-RateLimit-Reset', resetSeconds);

      if (record.count > this.maxRequests) {
        res.setHeader('Retry-After', resetSeconds);
        sendError(res, this.message, 429, { retry_after_seconds: resetSeconds });
        return;
      }

      next();
    };
  }
}

// 1. Strict rate limiter for authentication routes (login, register, forgot-password)
export const authRateLimiter = new MemoryRateLimiter({
  windowMs: 15 * 60 * 1000, // 15 minutes
  maxRequests: 20,           // 20 requests per 15 minutes per IP
  message: 'Too many authentication attempts. Please try again in 15 minutes.',
}).middleware();

// 2. Rate limiter for placing bids
export const bidRateLimiter = new MemoryRateLimiter({
  windowMs: 60 * 1000,      // 1 minute
  maxRequests: 30,          // 30 bids per minute
  message: 'Bidding speed limit exceeded. Please wait a moment before placing another bid.',
}).middleware();

// 3. General API rate limiter
export const apiRateLimiter = new MemoryRateLimiter({
  windowMs: 60 * 1000,      // 1 minute
  maxRequests: 180,         // 180 requests per minute
  message: 'Too many requests. Please slow down.',
}).middleware();
