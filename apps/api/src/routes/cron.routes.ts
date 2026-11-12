import { Router, Request, Response, NextFunction } from 'express';
import crypto from 'crypto';
import { CronController } from '../controllers/cron.controller.js';
import { ENV } from '../config/env.js';
import { sendError } from '../utils/response.js';

const router = Router();

function requireCronSecret(req: Request, res: Response, next: NextFunction): void {
  if (CronController.isAuthorized(req)) {
    return next();
  }
  sendError(res, 'Unauthorized: Invalid or missing cron secret', 401);
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
