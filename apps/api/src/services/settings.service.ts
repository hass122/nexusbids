import { query } from '../config/db.js';

export interface PlatformSettingsMap {
  platform_fee_percent: number;
  anti_sniping_window_sec: number;
  min_bid_increment: number;
  kyc_mandatory_for_selling: boolean;
  currency: string;
  escrow_auto_release_days: number;
  maintenance_mode: boolean;
  [key: string]: any;
}

const DEFAULT_SETTINGS: PlatformSettingsMap = {
  platform_fee_percent: 5.0,
  anti_sniping_window_sec: 120,
  min_bid_increment: 100,
  kyc_mandatory_for_selling: true,
  currency: 'PKR',
  escrow_auto_release_days: 3,
  maintenance_mode: false,
};

let cachedSettings: PlatformSettingsMap | null = null;
let cacheExpiresAt = 0;
const CACHE_TTL_MS = 45 * 1000; // 45 seconds

export class SettingsService {
  /**
   * Fetch all settings, cached for 45s with automatic fallback to defaults
   */
  static async getAllSettings(): Promise<PlatformSettingsMap> {
    const now = Date.now();
    if (cachedSettings && now < cacheExpiresAt) {
      return cachedSettings;
    }

    try {
      const rows = await query<any[]>('SELECT setting_key, setting_value FROM platform_settings').catch(() => []);
      const map: Record<string, any> = { ...DEFAULT_SETTINGS };

      for (const row of rows) {
        const k = row.setting_key;
        const v = row.setting_value;

        if (k === 'kyc_mandatory_for_selling' || k === 'maintenance_mode') {
          map[k] = v === 'true' || v === '1' || v === true;
        } else if (k === 'platform_fee_percent' || k === 'anti_sniping_window_sec' || k === 'min_bid_increment' || k === 'escrow_auto_release_days') {
          const parsed = parseFloat(v);
          map[k] = isNaN(parsed) ? (DEFAULT_SETTINGS as any)[k] : parsed;
        } else {
          map[k] = v;
        }
      }

      cachedSettings = map as PlatformSettingsMap;
      cacheExpiresAt = now + CACHE_TTL_MS;
      return cachedSettings;
    } catch {
      return DEFAULT_SETTINGS;
    }
  }

  static async getPlatformFeePercent(): Promise<number> {
    const settings = await this.getAllSettings();
    return settings.platform_fee_percent ?? 5.0;
  }

  static async getMinBidIncrement(): Promise<number> {
    const settings = await this.getAllSettings();
    return settings.min_bid_increment ?? 100;
  }

  static async getAntiSnipingWindowSec(): Promise<number> {
    const settings = await this.getAllSettings();
    return settings.anti_sniping_window_sec ?? 120;
  }

  static async isKycMandatoryForSelling(): Promise<boolean> {
    const settings = await this.getAllSettings();
    return settings.kyc_mandatory_for_selling ?? true;
  }

  static async isMaintenanceMode(): Promise<boolean> {
    const settings = await this.getAllSettings();
    return settings.maintenance_mode ?? false;
  }

  static async getEscrowAutoReleaseDays(): Promise<number> {
    const settings = await this.getAllSettings();
    return settings.escrow_auto_release_days ?? 3;
  }

  /**
   * Update one or multiple platform settings in DB and invalidate cache immediately
   */
  static async updateSettings(updates: Record<string, any>): Promise<PlatformSettingsMap> {
    for (const [key, value] of Object.entries(updates)) {
      const strVal = typeof value === 'boolean' ? (value ? 'true' : 'false') : String(value);
      try {
        await query(
          `INSERT INTO platform_settings (setting_key, setting_value, updated_at)
           VALUES (?, ?, NOW())
           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()`,
          [key, strVal]
        );
      } catch (err: any) {
        // Fail loudly so admin sees the error instead of silent "success"
        console.error(`[SettingsService] Failed to persist setting "${key}":`, err?.message);
        throw new Error(`Failed to update setting "${key}": ${err?.message || 'database error'}`);
      }
    }

    cachedSettings = null; // Invalidate cache immediately
    return await this.getAllSettings();
  }
}
