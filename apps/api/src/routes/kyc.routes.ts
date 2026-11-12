import { Router } from 'express';
import { requireAuth } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { query, withTransaction } from '../config/db.js';
import { encryptCnic, decryptCnic, maskCnic } from '../utils/crypto.js';
import crypto from 'crypto';

const router = Router();

// ─── GET KYC Status ─────────────────────────────────────────
// GET /api/v1/kyc/status
router.get('/status', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const rows = await query<any[]>(
      `SELECT kyc_id, kyc_scope, status, ai_status, ai_confidence, rejection_reason, 
              cnic_number_masked, full_name_on_cnic, created_at, reviewed_at, ai_recommendation
       FROM kyc_verifications 
       WHERE user_id = ? 
       ORDER BY created_at DESC LIMIT 5`,
      [userId]
    );

    const userRows = await query<any[]>(
      'SELECT kyc_status, seller_kyc_status FROM users WHERE user_id = ? LIMIT 1',
      [userId]
    );

    sendSuccess(res, {
      kyc_status: userRows[0]?.kyc_status || 'unverified',
      seller_kyc_status: userRows[0]?.seller_kyc_status || 'unverified',
      submissions: rows,
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Submit Buyer KYC ────────────────────────────────────────
// POST /api/v1/kyc/buyer
// Note: In production, use multer + S3 upload for file handling.
// For now, accepts base64 encoded images in JSON body.
router.post('/buyer', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    // ✅ FIX: Allow admin as well for manual overrides/testing
    if (req.user!.role !== 'user' && req.user!.role !== 'admin') {
      sendError(res, 'Only buyers can submit buyer KYC', 403);
      return;
    }

    // Eligibility check
    const userRows = await query<any[]>('SELECT kyc_status FROM users WHERE user_id = ? LIMIT 1', [userId]);
    if (userRows[0]?.kyc_status === 'approved') {
      sendError(res, 'Your KYC has already been approved.', 400);
      return;
    }
    if (userRows[0]?.kyc_status === 'pending') {
      sendError(res, 'Your KYC is currently under review. Please wait for the result.', 400);
      return;
    }

    const {
      full_name_on_cnic,
      cnic_number,
      date_of_birth,
      phone,
      father_name,
      address,
      // In production these would be S3 signed upload paths:
      cnic_front_path,
      cnic_back_path,
      selfie_path,
      selfie_with_cnic_path,
    } = req.body;

    if (!full_name_on_cnic || !cnic_number || !date_of_birth) {
      sendError(res, 'Full name, CNIC number, and date of birth are required.', 400);
      return;
    }

    // Validate CNIC format (13 digits)
    const digits = cnic_number.replace(/\D/g, '');
    if (digits.length !== 13) {
      sendError(res, 'Invalid CNIC format. Must be 13 digits.', 400);
      return;
    }

    if (!cnic_front_path || !cnic_back_path || !selfie_path || !selfie_with_cnic_path) {
      sendError(res, 'All four document images are required (CNIC front, back, selfie, selfie with CNIC).', 400);
      return;
    }

    const cnicEncrypted = encryptCnic(cnic_number);
    const cnicMasked = maskCnic(cnic_number);

    await withTransaction(async (conn) => {
      // H-8: Check for duplicate CNIC already linked to another user
      const [dupRows] = await conn.execute<any[]>(
        'SELECT user_id, cnic_number_encrypted FROM kyc_verifications WHERE (cnic_number_masked = ? OR cnic_number_encrypted = ?) AND user_id != ?',
        [cnicMasked, cnicEncrypted, userId]
      );
      for (const row of dupRows) {
        const decrypted = decryptCnic(row.cnic_number_encrypted);
        if (decrypted && decrypted.replace(/\D/g, '') === cnic_number.replace(/\D/g, '')) {
          throw new Error('This CNIC number is already registered with another account.');
        }
      }

      // Insert KYC record
      await conn.execute(
        `INSERT INTO kyc_verifications 
          (user_id, kyc_scope, full_name_on_cnic, cnic_number_encrypted, cnic_number_masked,
           father_name, date_of_birth, address, phone,
           cnic_front_path, cnic_back_path, selfie_path, selfie_with_cnic_path,
           status, ai_status, ip_address, created_at)
         VALUES (?, 'buyer', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW())`,
        [
          userId,
          full_name_on_cnic,
          cnicEncrypted,
          cnicMasked,
          father_name || null,
          date_of_birth,
          address || null,
          phone || null,
          cnic_front_path,
          cnic_back_path,
          selfie_path,
          selfie_with_cnic_path,
          req.socket.remoteAddress || null,
        ]
      );

      // Update user KYC status to pending
      await conn.execute(
        "UPDATE users SET kyc_status = 'pending', kyc_submitted_at = NOW() WHERE user_id = ?",
        [userId]
      );
    });

    sendSuccess(res, {
      kyc_status: 'pending',
      message: 'KYC submitted successfully. Our team will review your documents within 24–48 hours.',
    }, undefined, 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Submit Seller KYC ───────────────────────────────────────
// POST /api/v1/kyc/seller
router.post('/seller', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    if (req.user!.role !== 'seller') {
      sendError(res, 'Only sellers can submit seller KYC', 403);
      return;
    }

    const userRows = await query<any[]>(
      'SELECT seller_kyc_status FROM users WHERE user_id = ? LIMIT 1',
      [userId]
    );
    if (userRows[0]?.seller_kyc_status === 'approved') {
      sendError(res, 'Your seller KYC has already been approved.', 400);
      return;
    }

    const {
      full_name_on_cnic,
      cnic_number,
      date_of_birth,
      phone,
      father_name,
      address,
      cnic_front_path,
      cnic_back_path,
      selfie_path,
      selfie_with_cnic_path,
      seller_type,
      bank_name,
      bank_account_holder,
      bank_account_number,
      bank_iban,
      bank_branch,
      business_name,
      business_type,
      business_reg_number,
      ntn_number,
    } = req.body;

    if (!full_name_on_cnic || !cnic_number || !date_of_birth) {
      sendError(res, 'Full name, CNIC number, and date of birth are required.', 400);
      return;
    }
    if (!bank_name || !bank_account_holder || !bank_account_number) {
      sendError(res, 'Bank account details are required for seller KYC.', 400);
      return;
    }

    // C-3: All 4 document images are required for sellers (no placeholder fallbacks)
    if (!cnic_front_path || !cnic_back_path || !selfie_path || !selfie_with_cnic_path) {
      sendError(res, 'All four document images are required (CNIC front, back, selfie, selfie with CNIC).', 400);
      return;
    }

    const cnicEncrypted = encryptCnic(cnic_number);
    const cnicMasked = maskCnic(cnic_number);

    await withTransaction(async (conn) => {
      // H-8: Check for duplicate CNIC already linked to another user
      const [dupRows] = await conn.execute<any[]>(
        'SELECT user_id, cnic_number_encrypted FROM kyc_verifications WHERE (cnic_number_masked = ? OR cnic_number_encrypted = ?) AND user_id != ?',
        [cnicMasked, cnicEncrypted, userId]
      );
      for (const row of dupRows) {
        const decrypted = decryptCnic(row.cnic_number_encrypted);
        if (decrypted && decrypted.replace(/\D/g, '') === cnic_number.replace(/\D/g, '')) {
          throw new Error('This CNIC number is already registered with another account.');
        }
      }

      await conn.execute(
        `INSERT INTO kyc_verifications 
          (user_id, kyc_scope, seller_type, full_name_on_cnic, cnic_number_encrypted, cnic_number_masked,
           father_name, date_of_birth, address, phone,
           cnic_front_path, cnic_back_path, selfie_path, selfie_with_cnic_path,
           bank_name, bank_account_holder, bank_account_number, bank_iban, bank_branch,
           business_name, business_type, business_reg_number, ntn_number,
           status, ai_status, ip_address, created_at)
         VALUES (?, 'seller', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', ?, NOW())`,
        [
          userId,
          seller_type || 'individual',
          full_name_on_cnic,
          cnicEncrypted,
          cnicMasked,
          father_name || null,
          date_of_birth,
          address || null,
          phone || null,
          cnic_front_path,
          cnic_back_path,
          selfie_path,
          selfie_with_cnic_path,
          bank_name,
          bank_account_holder,
          bank_account_number,
          bank_iban || null,
          bank_branch || null,
          business_name || null,
          business_type || null,
          business_reg_number || null,
          ntn_number || null,
          req.socket.remoteAddress || null,
        ]
      );

      await conn.execute(
        "UPDATE users SET seller_kyc_status = 'pending' WHERE user_id = ?",
        [userId]
      );
    });

    sendSuccess(res, {
      seller_kyc_status: 'pending',
      message: 'Seller KYC submitted. Our team will review your documents within 48 hours.',
    }, undefined, 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

export default router;
