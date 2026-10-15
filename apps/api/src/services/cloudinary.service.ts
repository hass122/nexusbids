import { v2 as cloudinary } from 'cloudinary';
import { ENV } from '../config/env.js';
import fs from 'fs';
import path from 'path';

// ─── Initialize Cloudinary ──────────────────────────────────────────
let isConfigured = false;

function ensureCloudinaryConfigured() {
  if (isConfigured) return;
  if (ENV.CLOUDINARY.ENABLED) {
    cloudinary.config({
      cloud_name: ENV.CLOUDINARY.CLOUD_NAME,
      api_key: ENV.CLOUDINARY.API_KEY,
      api_secret: ENV.CLOUDINARY.API_SECRET,
      secure: true,
    });
    isConfigured = true;
    console.log('[CloudinaryService] ✅ Cloudinary configured with cloud_name:', ENV.CLOUDINARY.CLOUD_NAME);
  }
}

export interface UploadResult {
  url: string;
  secure_url: string;
  public_id: string;
  format?: string;
  width?: number;
  height?: number;
  bytes?: number;
}

export class CloudinaryService {
  /**
   * Upload an image buffer to Cloudinary with local-disk fallback
   */
  static async uploadBuffer(
    buffer: Buffer,
    folder: string = 'nexusbids/general',
    filenamePrefix: string = 'img'
  ): Promise<UploadResult> {
    ensureCloudinaryConfigured();

    if (ENV.CLOUDINARY.ENABLED) {
      return new Promise((resolve, reject) => {
        const uploadStream = cloudinary.uploader.upload_stream(
          {
            folder,
            resource_type: 'image',
            transformation: [{ quality: 'auto:good' }, { fetch_format: 'auto' }],
          },
          (error, result) => {
            if (error || !result) {
              return reject(new Error(error?.message || 'Cloudinary upload failed'));
            }
            resolve({
              url: result.url,
              secure_url: result.secure_url,
              public_id: result.public_id,
              format: result.format,
              width: result.width,
              height: result.height,
              bytes: result.bytes,
            });
          }
        );
        uploadStream.end(buffer);
      });
    }

    // ─── Local Fallback when Cloudinary is not configured in .env ───
    // Sanitize folder ONCE and use the SAME value for both disk path and URL
    const safeFolder = folder.replace(/[^a-zA-Z0-9_\-\/]/g, '').replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
    const uploadsDir = path.resolve(process.cwd(), 'uploads', safeFolder);
    if (!fs.existsSync(uploadsDir)) {
      fs.mkdirSync(uploadsDir, { recursive: true });
    }

    const uniqueName = `${filenamePrefix}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}.jpg`;
    const localFilePath = path.join(uploadsDir, uniqueName);
    fs.writeFileSync(localFilePath, buffer);

    const relativeUrl = `/uploads/${safeFolder}/${uniqueName}`;
    return {
      url: relativeUrl,
      secure_url: relativeUrl,
      public_id: `local_${uniqueName}`,
      bytes: buffer.length,
    };
  }

  static async uploadBase64(
    base64Data: string,
    folder: string = 'nexusbids/kyc'
  ): Promise<UploadResult> {
    ensureCloudinaryConfigured();

    // Strip data URL prefix — handles image/jpeg, image/png, image/svg+xml, image/webp, etc.
    const cleanBase64 = base64Data.replace(/^data:image\/[\w+.-]+;base64,/, '');

    // Basic sanity: base64 string length must be > 100 chars for a real image
    if (cleanBase64.length < 100) {
      throw new Error('Invalid or empty base64 image payload.');
    }

    const buffer = Buffer.from(cleanBase64, 'base64');
    if (buffer.length === 0) {
      throw new Error('Base64 image decoded to 0 bytes.');
    }

    return this.uploadBuffer(buffer, folder, 'upload');
  }

  /**
   * Delete image from Cloudinary or local
   */
  static async deleteImage(publicId: string): Promise<boolean> {
    ensureCloudinaryConfigured();

    if (ENV.CLOUDINARY.ENABLED && !publicId.startsWith('local_')) {
      try {
        const res = await cloudinary.uploader.destroy(publicId);
        return res.result === 'ok';
      } catch (err: any) {
        console.warn('[CloudinaryService] Failed to delete image:', err.message);
        return false;
      }
    }
    return true;
  }
}
