import { Router, Request, Response, NextFunction } from 'express';
import crypto from 'crypto';
import { CronController } from '../controllers/cron.controller.js';
import { ENV } from '../config/env.js';
import { sendError } from '../utils/response.js';

const router = Router();

function safeCompare(a: string, b: string): boolean {
  const bufA = Buffer.from(a);
  const bufB = Buffer.from(b);
  if (bufA.length !== bufB.length) return false;
  return crypto.timingSafeEqual(bufA, bufB);
}

function requireCronSecret(req: Request, res: Response, next: NextFunction): void {
  const token =
    req.headers['x-cron-secret'] ||
    req.headers['x-cron-token'] ||
    (req.headers.authorization?.startsWith('Bearer ') ? req.headers.authorization.slice(7) : undefined) ||
    (typeof req.query.secret === 'string' ? req.query.secret : undefined);

  if (!token || !safeCompare(String(token), ENV.CRON.SECRET)) {
    sendError(res, 'Unauthorized: Invalid or missing cron secret', 401);
    return;
  }
  next();
}

router.post('/process-auctions', requireCronSecret, CronController.processAuctions);
router.get('/process-auctions', requireCronSecret, CronController.processAuctions);
router.post('/close-auctions', requireCronSecret, CronController.processAuctions);
router.get('/close-auctions', requireCronSecret, CronController.processAuctions);

router.post('/auto-release-escrow', requireCronSecret, CronController.autoReleaseEscrow);
router.get('/auto-release-escrow', requireCronSecret, CronController.autoReleaseEscrow);
router.post('/release-escrow', requireCronSecret, CronController.autoReleaseEscrow);
router.get('/release-escrow', requireCronSecret, CronController.autoReleaseEscrow);


export default router;
