/**
 * NexusBids Enhanced Realtime Service
 * Strategy: SSE (primary, local dev) + Pusher (fallback/production)
 *
 * - Local dev: SSE works perfectly (single server process)
 * - Vercel/serverless: Pusher handles broadcasting (stateless-safe)
 * - Automatic detection: if PUSHER credentials exist → use Pusher
 */

import { Response } from 'express';
import { ENV } from '../config/env.js';

// ─── SSE Client Registry ───────────────────────────────────────
interface SSEClient {
  id: string;
  auctionId: number;
  res: Response;
}

const clients: Map<string, SSEClient> = new Map();

// ─── Pusher Instance (lazy init) ───────────────────────────────
let pusherServer: any = null;

async function getPusher(): Promise<any | null> {
  if (!ENV.PUSHER.ENABLED) return null;

  if (!pusherServer) {
    try {
      const { default: Pusher } = await import('pusher');
      pusherServer = new Pusher({
        appId: ENV.PUSHER.APP_ID,
        key: ENV.PUSHER.KEY,
        secret: ENV.PUSHER.SECRET,
        cluster: ENV.PUSHER.CLUSTER,
        useTLS: true,
      });
      console.log(`[RealtimeService] ✅ Pusher initialized (cluster: ${ENV.PUSHER.CLUSTER})`);
    } catch (err: any) {
      console.error('[RealtimeService] ❌ Pusher init failed:', err.message);
      return null;
    }
  }

  return pusherServer;
}

// ─── Channel name helper ───────────────────────────────────────
function auctionChannel(auctionId: number): string {
  return `auction-${auctionId}`;
}

export class RealtimeService {
  // ─── Register SSE Connection ──────────────────────────────
  static registerClient(clientId: string, auctionId: number, res: Response) {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'Access-Control-Allow-Origin': '*',
      'X-Accel-Buffering': 'no', // Disable nginx buffering
    });

    res.write(`data: ${JSON.stringify({ type: 'connected', auctionId, clientId, pusher_enabled: ENV.PUSHER.ENABLED })}\n\n`);

    clients.set(clientId, { id: clientId, auctionId, res });

    // Heartbeat every 20 seconds
    const heartbeat = setInterval(() => {
      if (clients.has(clientId)) {
        res.write(': heartbeat\n\n');
      } else {
        clearInterval(heartbeat);
      }
    }, 20000);

    res.on('close', () => {
      clearInterval(heartbeat);
      clients.delete(clientId);
    });
  }

  // ─── Broadcast via SSE ────────────────────────────────────
  private static broadcastSSE(auctionId: number, payload: string): void {
    for (const [_, client] of clients) {
      if (client.auctionId === auctionId) {
        try {
          client.res.write(`data: ${payload}\n\n`);
        } catch {
          // Client disconnected
        }
      }
    }
  }

  // ─── Broadcast via Pusher ─────────────────────────────────
  private static async broadcastPusher(auctionId: number, event: string, data: object): Promise<void> {
    try {
      const pusher = await getPusher();
      if (!pusher) return;
      await pusher.trigger(auctionChannel(auctionId), event, data);
    } catch (err: any) {
      console.error(`[RealtimeService] Pusher broadcast failed for auction ${auctionId}:`, err.message);
    }
  }

  // ─── Broadcast New Bid ────────────────────────────────────
  static broadcastBid(auctionId: number, data: {
    bid_amount: number;
    bidder_name: string;
    bid_time: string;
    bid_count: number;
    is_auto_bid?: boolean;
  }) {
    const payload = JSON.stringify({
      type: 'new_bid',
      auction_id: auctionId,
      ...data,
    });

    // 1. SSE broadcast (local dev / same-process)
    this.broadcastSSE(auctionId, payload);

    // 2. Pusher broadcast (production / multi-instance)
    this.broadcastPusher(auctionId, 'new_bid', {
      type: 'new_bid',
      auction_id: auctionId,
      ...data,
    }).catch(() => {});
  }

  // ─── Broadcast Status Change ──────────────────────────────
  static broadcastStatus(auctionId: number, status: string, winnerName?: string) {
    const eventData = {
      type: 'status_change',
      auction_id: auctionId,
      status,
      winner_name: winnerName || null,
    };
    const payload = JSON.stringify(eventData);

    this.broadcastSSE(auctionId, payload);
    this.broadcastPusher(auctionId, 'status_change', eventData).catch(() => {});
  }

  // ─── Broadcast Time Extension (Anti-Sniping) ──────────────
  static broadcastTimeExtension(auctionId: number, data: { new_end_time: string; extended_count: number }) {
    const eventData = {
      type: 'time_extended',
      auction_id: auctionId,
      ...data,
    };
    const payload = JSON.stringify(eventData);

    this.broadcastSSE(auctionId, payload);
    this.broadcastPusher(auctionId, 'time_extended', eventData).catch(() => {});
  }

  // ─── Get Auth Token for Private Pusher Channels ───────────
  static async authenticatePusherChannel(socketId: string, channelName: string): Promise<string | null> {
    const pusher = await getPusher();
    if (!pusher) return null;
    try {
      const auth = pusher.authorizeChannel(socketId, channelName);
      return JSON.stringify(auth);
    } catch {
      return null;
    }
  }

  // ─── Connection Stats ─────────────────────────────────────
  static getStats() {
    return {
      sse_connections: clients.size,
      pusher_enabled: ENV.PUSHER.ENABLED,
      pusher_cluster: ENV.PUSHER.CLUSTER,
    };
  }
}
