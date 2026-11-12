import dotenv from 'dotenv';
import path from 'path';
import crypto from 'crypto';

dotenv.config();

const NODE_ENV = process.env.NODE_ENV || 'development';
const isProd = NODE_ENV === 'production';

// In production, enforce mandatory secrets and disallow predictable default placeholders
function resolveMandatorySecret(key: string, devFallback: string, minLength = 32): string {
  const val = process.env[key];
  if (!val || val === devFallback) {
    if (isProd) {
      console.warn(`[SECURITY NOTICE] Production secret "${key}" is not set in environment settings. Generating an ephemeral cryptographically secure random secret for this instance.`);
      return crypto.randomBytes(Math.max(16, Math.ceil(minLength / 2))).toString('hex');
    }
    return devFallback;
  }
  return val;
}

const accessSecret = resolveMandatorySecret(
  'JWT_ACCESS_SECRET',
  'a2bb70538d73d9e270d230a8b8cc1eab21e807d6bbe35052b943d74ce66a13b1'
);

const refreshSecret = resolveMandatorySecret(
  'JWT_REFRESH_SECRET',
  'c963cbd9b44bd51451f089712aabfaaf7ac28de6f146bb45f251a63def281483'
);

const kycEncryptionKey = resolveMandatorySecret(
  'KYC_ENCRYPTION_KEY',
  'bafabed50f19e4cc131ba632a6ba6f93',
  16
);

const cronSecret = resolveMandatorySecret(
  'CRON_SECRET',
  '0c4ae7caafa3f1bf1c23356f31e096f1233a2cb4a4ad532a',
  24
);

// Allowed CORS origins
const rawCors = process.env.CORS_ORIGIN || 'http://localhost:5000,http://127.0.0.1:5000,http://localhost:3000,http://127.0.0.1:3000';
const corsOrigins = rawCors.split(',').map((o) => o.trim()).filter(Boolean);

export const ENV = {
  PORT: parseInt(process.env.PORT || '5000', 10),
  NODE_ENV,
  IS_PRODUCTION: isProd,
  CLIENT_URL: process.env.CLIENT_URL || 'http://localhost:5000',
  CORS_ORIGINS: corsOrigins,
  DB: {
    HOST: process.env.DB_HOST || '127.0.0.1',
    PORT: parseInt(process.env.DB_PORT || '3306', 10),
    USER: process.env.DB_USER || 'root',
    PASSWORD: process.env.DB_PASSWORD || '',
    NAME: process.env.DB_NAME || 'nexus_bids',
    CONNECTION_LIMIT: parseInt(process.env.DB_CONNECTION_LIMIT || '10', 10),
  },
  JWT: {
    ACCESS_SECRET: accessSecret,
    REFRESH_SECRET: refreshSecret,
  },
  KYC: {
    ENCRYPTION_KEY: kycEncryptionKey,
  },
  CRON: {
    SECRET: cronSecret,
  },
  AI: {
    FEATURES_ENABLED: process.env.AI_FEATURES_ENABLED !== 'false',
    BASE_URL: process.env.AI_BASE_URL || 'https://api.groq.com/openai/v1',
    API_KEY: process.env.AI_API_KEY || '',
    MODEL: process.env.AI_MODEL || 'openai/gpt-oss-120b',
    FALLBACK_MODEL: process.env.AI_FALLBACK_MODEL || 'openai/gpt-oss-20b',
    GEMINI_API_KEY: process.env.GEMINI_API_KEY || '',
    GEMINI_VISION_MODEL: process.env.GEMINI_VISION_MODEL || 'gemini-1.5-flash',
  },
  PAYMENT: {
    MODE: (process.env.PAYMENT_MODE || 'sandbox') as 'demo' | 'sandbox' | 'production',
    JAZZCASH_HMAC_SECRET: process.env.JAZZCASH_HMAC_SECRET || 'nexusbids_jazzcash_sandbox_secret_2026',
    EASYPAISA_HMAC_SECRET: process.env.EASYPAISA_HMAC_SECRET || 'nexusbids_easypaisa_sandbox_secret_2026',
    STRIPE_WEBHOOK_SECRET: process.env.STRIPE_WEBHOOK_SECRET || 'whsec_nexusbids_sandbox_test_secret_2026',
  },
  GOOGLE: {
    CLIENT_ID: process.env.GOOGLE_CLIENT_ID || '',
    CLIENT_SECRET: process.env.GOOGLE_CLIENT_SECRET || '',
    CALLBACK_URL: process.env.GOOGLE_CALLBACK_URL || 'http://localhost:5000/api/v1/auth/google/callback',
  },
  PUSHER: {
    APP_ID: process.env.PUSHER_APP_ID || '',
    KEY: process.env.PUSHER_KEY || '',
    SECRET: process.env.PUSHER_SECRET || '',
    CLUSTER: process.env.PUSHER_CLUSTER || 'ap2',
    ENABLED: !!(process.env.PUSHER_APP_ID && process.env.PUSHER_KEY && process.env.PUSHER_SECRET),
  },
  CLOUDINARY: {
    CLOUD_NAME: process.env.CLOUDINARY_CLOUD_NAME || '',
    API_KEY: process.env.CLOUDINARY_API_KEY || '',
    API_SECRET: process.env.CLOUDINARY_API_SECRET || '',
    ENABLED: !!(process.env.CLOUDINARY_CLOUD_NAME && process.env.CLOUDINARY_API_KEY && process.env.CLOUDINARY_API_SECRET),
  },
  EMAIL: {
    FROM: process.env.EMAIL_FROM || 'NexusBids <noreply@nexusbids.pk>',
    USER: process.env.EMAIL_USER || '',
    PASS: process.env.EMAIL_PASS || '',
    ENABLED: process.env.EMAIL_ENABLED === 'true',
  },
};

