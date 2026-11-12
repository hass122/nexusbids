import { Request, Response } from 'express';
import { MarketplaceService } from '../services/marketplace.service.js';
import { sendSuccess, sendError } from '../utils/response.js';

export class MarketplaceController {
  // Watchlist
  static async addToWatchlist(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.auctionId, 10);
      const userId = req.user!.userId;
      const result = await MarketplaceService.addToWatchlist(userId, auctionId);
      sendSuccess(res, result, 'Auction added to watchlist');
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  static async removeFromWatchlist(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.auctionId, 10);
      const userId = req.user!.userId;
      const result = await MarketplaceService.removeFromWatchlist(userId, auctionId);
      sendSuccess(res, result, 'Auction removed from watchlist');
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  static async getWatchlist(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getWatchlist(userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // Buyer
  static async getBuyerDashboard(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getBuyerDashboard(userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getBuyerBids(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getBuyerBids(userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getWonAuctions(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getWonAuctions(userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // Seller
  static async getSellerDashboard(req: Request, res: Response): Promise<void> {
    try {
      const sellerId = req.user!.userId;
      const result = await MarketplaceService.getSellerDashboard(sellerId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getSellerAuctions(req: Request, res: Response): Promise<void> {
    try {
      const sellerId = req.user!.userId;
      const status = req.query.status as string;
      const result = await MarketplaceService.getSellerAuctions(sellerId, status);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // Orders
  static async getBuyerProtection(req: Request, res: Response): Promise<void> {
    try {
      const buyerId = req.user!.userId;
      const result = await MarketplaceService.getBuyerProtection(buyerId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getBuyerOrders(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getOrders(userId, 'buyer');
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getSellerOrders(req: Request, res: Response): Promise<void> {
    try {
      const sellerId = req.user!.userId;
      const result = await MarketplaceService.getOrders(sellerId, 'seller');
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async getOrderById(req: Request, res: Response): Promise<void> {
    try {
      const rawId = req.params.id || req.params.orderId;
      const orderId = parseInt(rawId as string, 10);
      const userId = req.user!.userId;
      const role = req.user!.role;
      const result = await MarketplaceService.getOrderById(orderId, userId, role);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 404);
    }
  }

  static async confirmReceipt(req: Request, res: Response): Promise<void> {
    try {
      const rawId = req.params.id || req.params.orderId || req.body?.order_id;
      const orderId = parseInt(rawId as string, 10);
      if (isNaN(orderId)) {
        sendError(res, 'Invalid order ID provided', 400);
        return;
      }
      const buyerId = req.user!.userId;
      const result = await MarketplaceService.confirmReceipt(orderId, buyerId);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // Reviews
  static async addReview(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.addReview(userId, req.body);
      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  static async getMyReviews(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const result = await MarketplaceService.getMyReviews(userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // Notifications
  static async getNotifications(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      const page = Math.max(1, parseInt((req.query.page as string) || '1', 10) || 1);
      const limit = Math.min(100, Math.max(1, parseInt((req.query.limit as string) || '50', 10) || 50));
      const result = await (MarketplaceService.getNotifications as any)(userId, { page, limit });
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  static async markNotificationRead(req: Request, res: Response): Promise<void> {
    try {
      const notificationId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      await MarketplaceService.markNotificationRead(notificationId, userId);
      sendSuccess(res, { read: true });
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  static async markAllNotificationsRead(req: Request, res: Response): Promise<void> {
    try {
      const userId = req.user!.userId;
      await MarketplaceService.markAllNotificationsRead(userId);
      sendSuccess(res, { read_all: true });
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }
}
