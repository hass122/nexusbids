/**
 * NexusBids Mobile — Realtime SSE & Polling Service
 *
 * Connects to /api/v1/realtime/stream/:auctionId
 * Receives:
 * - Current bid updates
 * - Bid count updates
 * - Auction status changes
 * - Anti-sniping remaining time extensions
 *
 * Provides graceful fallback to high-frequency polling if SSE disconnects.
 */

import { SafeLogger } from '../security/secureStorage';

export interface RealtimeBidEvent {
  type: 'new_bid';
  auction_id: number;
  bid_amount: number;
  bidder_name: string;
  bid_time: string;
  bid_count: number;
  is_auto_bid?: boolean;
}

export interface RealtimeStatusEvent {
  type: 'status_change';
  auction_id: number;
  status: string;
  winner_name?: string | null;
}

export interface RealtimeTimeExtendedEvent {
  type: 'time_extended';
  auction_id: number;
  new_end_time: string;
  extended_count: number;
}

export type RealtimeEvent =
  | RealtimeBidEvent
  | RealtimeStatusEvent
  | RealtimeTimeExtendedEvent
  | { type: 'connected'; auctionId: number };

export interface RealtimeSubscription {
  unsubscribe: () => void;
}

export class RealtimeService {
  /**
   * Subscribe to real-time events for an active auction lot.
   */
  static subscribeToAuction(
    baseUrl: string,
    auctionId: number,
    callbacks: {
      onBid?: (event: RealtimeBidEvent) => void;
      onStatus?: (event: RealtimeStatusEvent) => void;
      onTimeExtended?: (event: RealtimeTimeExtendedEvent) => void;
      onConnect?: () => void;
      onError?: (err: any) => void;
    }
  ): RealtimeSubscription {
    let isSubscribed = true;
    let pollTimer: any = null;
    let abortController: AbortController | null = null;

    const streamUrl = `${baseUrl}/realtime/stream/${auctionId}`;

    const startStreaming = async () => {
      try {
        if (typeof AbortController !== 'undefined') {
          abortController = new AbortController();
        }

        // Try fetch streaming response
        const response = await fetch(streamUrl, {
          method: 'GET',
          headers: {
            Accept: 'text/event-stream',
          },
          signal: abortController?.signal,
        });

        if (!response.ok || !response.body) {
          throw new Error(`SSE failed with HTTP ${response.status}`);
        }

        if (callbacks.onConnect) callbacks.onConnect();

        // If environment supports ReadableStream reader
        const reader = (response.body as any).getReader?.();
        if (reader) {
          const decoder = new TextDecoder();
          let buffer = '';

          while (isSubscribed) {
            const { value, done } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() || '';

            for (const line of lines) {
              const trimmed = line.trim();
              if (trimmed.startsWith('data:')) {
                const jsonStr = trimmed.replace(/^data:\s*/, '');
                try {
                  const parsed: RealtimeEvent = JSON.parse(jsonStr);
                  handleEvent(parsed);
                } catch {}
              }
            }
          }
        } else {
          // Response body reader not available, fallback to polling
          startPolling();
        }
      } catch (err: any) {
        if (!isSubscribed) return;
        SafeLogger.warn('SSE stream unavailable, falling back to live polling', err.message);
        startPolling();
      }
    };

    const handleEvent = (event: RealtimeEvent) => {
      if (!isSubscribed) return;
      if (event.type === 'connected') {
        if (callbacks.onConnect) callbacks.onConnect();
      } else if (event.type === 'new_bid') {
        if (callbacks.onBid) callbacks.onBid(event);
      } else if (event.type === 'status_change') {
        if (callbacks.onStatus) callbacks.onStatus(event);
      } else if (event.type === 'time_extended') {
        if (callbacks.onTimeExtended) callbacks.onTimeExtended(event);
      }
    };

    // Polling fallback
    let lastKnownBid: number | null = null;
    let lastKnownBidCount: number | null = null;

    const pollAuction = async () => {
      if (!isSubscribed) return;
      try {
        const res = await fetch(`${baseUrl}/auctions/${auctionId}`, {
          headers: { Accept: 'application/json' },
        });
        if (res.ok) {
          const json = await res.json();
          const auction = json.data;
          if (auction) {
            const currentBid = Number(auction.current_bid || auction.starting_price || 0);
            const bidCount = Number(auction.bid_count || 0);

            if (lastKnownBid !== null && currentBid > lastKnownBid) {
              handleEvent({
                type: 'new_bid',
                auction_id: auctionId,
                bid_amount: currentBid,
                bidder_name: 'Verified Bidder',
                bid_time: new Date().toISOString(),
                bid_count: bidCount,
              });
            }

            lastKnownBid = currentBid;
            lastKnownBidCount = bidCount;
          }
        }
      } catch (err) {
        if (callbacks.onError) callbacks.onError(err);
      }
    };

    const startPolling = () => {
      if (pollTimer) clearInterval(pollTimer);
      pollAuction();
      pollTimer = setInterval(pollAuction, 3500);
    };

    // Launch streaming
    startStreaming();

    return {
      unsubscribe: () => {
        isSubscribed = false;
        if (abortController) {
          try {
            abortController.abort();
          } catch {}
        }
        if (pollTimer) {
          clearInterval(pollTimer);
          pollTimer = null;
        }
      },
    };
  }
}
