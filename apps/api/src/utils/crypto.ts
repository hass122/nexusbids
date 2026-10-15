import crypto from 'crypto';
import { ENV } from '../config/env.js';

function getKey(): Buffer {
  const hash = crypto.createHash('sha256').update(ENV.KYC.ENCRYPTION_KEY).digest();
  return hash;
}

export function encryptCnic(plaintext: string): string {
  if (!plaintext) return '';
  const key = getKey();
  // Use a deterministic IV derived from the key to allow duplicate CNIC detection
  const iv = crypto.createHash('sha256').update(ENV.KYC.ENCRYPTION_KEY + '_iv_salt').digest().subarray(0, 16);
  const cipher = crypto.createCipheriv('aes-256-cbc', key, iv);
  const encrypted = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  return Buffer.concat([iv, encrypted]).toString('base64');
}

export function decryptCnic(encoded: string): string {
  if (!encoded) return '';
  try {
    const raw = Buffer.from(encoded, 'base64');
    if (raw.length < 17) return '';
    const iv = raw.subarray(0, 16);
    const encrypted = raw.subarray(16);
    const key = getKey();
    const decipher = crypto.createDecipheriv('aes-256-cbc', key, iv);
    const decrypted = Buffer.concat([decipher.update(encrypted), decipher.final()]);
    return decrypted.toString('utf8');
  } catch {
    return '';
  }
}

export function maskCnic(cnic: string): string {
  const digits = cnic.replace(/\D/g, '');
  if (digits.length < 13) return '*****-*******-*';
  return `${digits.slice(0, 5)}-*******-${digits.slice(-1)}`;
}
