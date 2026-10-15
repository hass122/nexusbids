/**
 * NexusBids Mobile — Push Notification Service
 *
 * Implements notifications for:
 * - Outbid
 * - Winning
 * - Auction ending
 * - Auction won
 * - Payment
 * - Order
 * - Shipping
 * - Messages
 *
 * Emits in-app animated push banners and manages unread count state.
 */

export type PushNotificationType =
  | 'outbid'
  | 'winning'
  | 'auction_ending'
  | 'auction_won'
  | 'payment'
  | 'order'
  | 'shipping'
  | 'messages';

export interface PushNotificationPayload {
  id: string;
  type: PushNotificationType;
  title: string;
  message: string;
  timestamp: string;
  auctionId?: number;
  orderId?: number;
  read: boolean;
}

type PushListener = (notification: PushNotificationPayload) => void;

class NotificationServiceManager {
  private listeners: Set<PushListener> = new Set();
  private notificationHistory: PushNotificationPayload[] = [];

  subscribe(listener: PushListener): () => void {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  }

  notify(params: {
    type: PushNotificationType;
    title: string;
    message: string;
    auctionId?: number;
    orderId?: number;
  }) {
    const payload: PushNotificationPayload = {
      id: `push-${Date.now()}-${Math.random().toString(36).substring(2, 7)}`,
      type: params.type,
      title: params.title,
      message: params.message,
      timestamp: new Date().toISOString(),
      auctionId: params.auctionId,
      orderId: params.orderId,
      read: false,
    };

    this.notificationHistory.unshift(payload);
    if (this.notificationHistory.length > 50) {
      this.notificationHistory.pop();
    }

    this.listeners.forEach((listener) => {
      try {
        listener(payload);
      } catch {}
    });
  }

  // Pre-configured notification triggers matching user requirements
  triggerOutbid(lotTitle: string, newBidAmount: number, auctionId?: number) {
    this.notify({
      type: 'outbid',
      title: '⚠️ Outbid Alert!',
      message: `A higher bid of PKR ${newBidAmount.toLocaleString()} was placed on "${lotTitle}".`,
      auctionId,
    });
  }

  triggerWinning(lotTitle: string, bidAmount: number, auctionId?: number) {
    this.notify({
      type: 'winning',
      title: '🟢 You are Highest Bidder!',
      message: `Your bid of PKR ${bidAmount.toLocaleString()} on "${lotTitle}" is currently winning.`,
      auctionId,
    });
  }

  triggerAuctionEnding(lotTitle: string, minutesRemaining: number, auctionId?: number) {
    this.notify({
      type: 'auction_ending',
      title: '⏳ Auction Ending Soon!',
      message: `"${lotTitle}" is ending in ${minutesRemaining} minutes. Place your final bids!`,
      auctionId,
    });
  }

  triggerAuctionWon(lotTitle: string, winningAmount: number, orderId?: number) {
    this.notify({
      type: 'auction_won',
      title: '🏆 Auction Won! Congratulations!',
      message: `You won "${lotTitle}" for PKR ${winningAmount.toLocaleString()}. Proceed to Escrow Checkout.`,
      orderId,
    });
  }

  triggerPayment(orderId: number, amount: number, status: string = 'Held in Escrow') {
    this.notify({
      type: 'payment',
      title: '🛡️ Escrow Payment Protected',
      message: `Payment of PKR ${amount.toLocaleString()} for Order #${orderId} is verified (${status}).`,
      orderId,
    });
  }

  triggerOrder(orderId: number, title: string) {
    this.notify({
      type: 'order',
      title: '📦 Order Confirmation',
      message: `Order #${orderId} for "${title}" has been successfully logged.`,
      orderId,
    });
  }

  triggerShipping(orderId: number, courier: string, trackingNumber: string) {
    this.notify({
      type: 'shipping',
      title: '🚚 Order Dispatched!',
      message: `Order #${orderId} is in transit via ${courier} (${trackingNumber}).`,
      orderId,
    });
  }

  triggerMessage(senderName: string, text: string) {
    this.notify({
      type: 'messages',
      title: `💬 Message from ${senderName}`,
      message: text,
    });
  }

  getHistory(): PushNotificationPayload[] {
    return [...this.notificationHistory];
  }

  getUnreadCount(): number {
    return this.notificationHistory.filter((n) => !n.read).length;
  }

  markAllAsRead() {
    this.notificationHistory.forEach((n) => {
      n.read = true;
    });
  }
}

export const NotificationService = new NotificationServiceManager();
