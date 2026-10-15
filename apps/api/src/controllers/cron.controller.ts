import { Request, Response } from 'express';
import { CronService } from '../services/cron.service.js';
import { ENV } from '../config/env.js';
import { sendSuccess, sendError } from '../utils/response.js';

export class CronController {
  // Verify cron secret token
  private static isAuthorized(req: Request): boolean {
    const secret = ENV.CRON.SECRET;
    // Fail-closed: refuse to authorize if the server secret is unset/empty
    if (!secret || typeof secret !== 'string' || secret.length < 8) {
      console.error('[cron] CRON_SECRET is not configured — rejecting all cron requests');
      return false;
    }

    const authHeader = req.headers.authorization;
    const cronKey =
      req.headers['x-cron-key'] ||
      req.headers['x-cron-secret'] ||
      req.headers['x-cron-token'] ||
      (typeof req.query?.secret === 'string' ? req.query.secret : undefined);

    if (typeof authHeader === 'string' && authHeader === `Bearer ${secret}`) return true;
    if (typeof cronKey === 'string' && cronKey === secret) return true;
    return false;
  }

  // POST /api/v1/cron/process-auctions
  static async processAuctions(req: Request, res: Response): Promise<void> {
    if (!CronController.isAuthorized(req)) {
      sendError(res, 'Unauthorized cron invocation', 403);
      return;
    }

    try {
      const summary = await CronService.processEndedAuctions();
      sendSuccess(res, summary, 'Ended auctions processed successfully.');
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // POST /api/v1/cron/auto-release-escrow
  static async autoReleaseEscrow(req: Request, res: Response): Promise<void> {
    if (!CronController.isAuthorized(req)) {
      sendError(res, 'Unauthorized cron invocation', 403);
      return;
    }

    try {
      const summary = await CronService.autoReleaseEscrow();
      sendSuccess(res, summary, 'Escrow auto-release executed successfully.');
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }
}
