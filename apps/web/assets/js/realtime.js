/**
 * NexusBids Realtime SSE Client v2
 * Exponential backoff reconnect, heartbeat detection, and bid flash animations.
 */

class LiveAuctionStream {
  constructor(auctionId, options = {}) {
    this.auctionId    = auctionId;
    this.onBid        = options.onBid    || (() => {});
    this.onStatus     = options.onStatus || (() => {});
    this.onError      = options.onError  || (() => {});
    this.eventSource  = null;
    this._retries     = 0;
    this._maxRetries  = 10;
    this._baseDelay   = 2000;  // ms
    this._maxDelay    = 30000; // ms
    this._destroyed   = false;
    this._heartbeatTimer = null;
    this.connect();
  }

  connect() {
    if (this._destroyed) return;

    const url = `${ApiClient.getBaseUrl()}/realtime/stream/${this.auctionId}`;
    this.eventSource = new EventSource(url, { withCredentials: true });

    this.eventSource.addEventListener('open', () => {
      this._retries = 0;  // reset retry counter on successful connect
      this._resetHeartbeat();
    });

    this.eventSource.addEventListener('message', (event) => {
      this._resetHeartbeat();
      try {
        const payload = JSON.parse(event.data);
        if (payload.type === 'heartbeat') return;
        if (payload.type === 'new_bid')     this.onBid(payload);
        if (payload.type === 'status_change') this.onStatus(payload);
        if (payload.type === 'time_extended') this.onStatus(payload);
      } catch (err) {
        console.warn('[SSE] JSON parse error:', err);
      }
    });

    this.eventSource.addEventListener('new_bid', (event) => {
      try { this.onBid(JSON.parse(event.data)); } catch {}
    });

    this.eventSource.onerror = (err) => {
      this.eventSource?.close();
      this.eventSource = null;
      clearTimeout(this._heartbeatTimer);
      this.onError(err);

      if (this._destroyed || this._retries >= this._maxRetries) return;

      const delay = Math.min(this._maxDelay, this._baseDelay * 2 ** this._retries);
      const jitter = Math.random() * 1000;
      this._retries++;
      console.warn(`[SSE] Reconnecting in ${Math.round((delay + jitter) / 1000)}s (attempt ${this._retries})...`);
      setTimeout(() => this.connect(), delay + jitter);
    };
  }

  _resetHeartbeat() {
    clearTimeout(this._heartbeatTimer);
    // If no message for 45s assume connection is dead
    this._heartbeatTimer = setTimeout(() => {
      console.warn('[SSE] Heartbeat timeout — reconnecting...');
      this.eventSource?.close();
      this.connect();
    }, 45000);
  }

  close() {
    this._destroyed = true;
    clearTimeout(this._heartbeatTimer);
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
  }
}

window.LiveAuctionStream = LiveAuctionStream;
