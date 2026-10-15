import { Router, Request, Response } from 'express';
import { requireAuth, requireRole } from '../middleware/auth.js';
import { sendSuccess, sendError, sendPaginated } from '../utils/response.js';
import { query, withTransaction } from '../config/db.js';
import { SettingsService } from '../services/settings.service.js';
import { validateOrderTransition } from '../utils/orderStateMachine.js';
import { EscrowService } from '../services/escrow.service.js';
import { EmailService } from '../services/email.service.js';

const router = Router();
router.use(requireAuth, requireRole('admin'));

async function logAdminActivity(
  req: Request,
  actionType: string,
  targetType: string | null = null,
  targetId: number | null = null,
  meta: Record<string, any> = {}
) {
  try {
    const adminId = (req as any).user?.userId || (req as any).user?.user_id || 1;
    const ip = req.headers['x-forwarded-for'] || req.socket.remoteAddress || '127.0.0.1';
    const ipStr = Array.isArray(ip) ? ip[0] : String(ip).slice(0, 45);
    await query(
      `INSERT INTO admin_activity_log (admin_id, action_type, target_type, target_id, meta, ip_address, created_at)
       VALUES (?, ?, ?, ?, ?, ?, NOW())`,
      [adminId, actionType, targetType, targetId, JSON.stringify(meta), ipStr]
    ).catch(async () => {
      await query(
        `INSERT INTO admin_activity_log (admin_id, action, details, activity, description, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())`,
        [adminId, actionType, JSON.stringify(meta), actionType, `${targetType || ''} #${targetId || ''}`, ipStr]
      );
    });
  } catch (err) { console.error('Audit logging error (suppressed):', err); }
}

// ─── 1. DASHBOARD STATS ─────────────────────────────────────
router.get('/stats', async (req: Request, res: Response) => {
  try {
    const [usersCount, activeSellers, auctionsCount, todaysBids, revenueCount, pendingPayments,
      escrowBalance, pendingPayouts, pendingKyc, openDisputes, fraudAlerts, securityAlerts] = await Promise.all([
        query<any[]>('SELECT COUNT(*) as total FROM users').catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(DISTINCT seller_id) as total FROM auctions WHERE auction_status = 'active'").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(*) as total, SUM(CASE WHEN auction_status='active' AND end_time > NOW() THEN 1 ELSE 0 END) as active FROM auctions").catch(() => [{ total: 0, active: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM bids WHERE DATE(bid_time) = CURDATE()").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(platform_fee),0) as commission FROM orders WHERE payment_status='paid'").catch(() => [{ total: 0, commission: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM orders WHERE payment_status IN ('unpaid','pending')").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COALESCE(SUM(total_amount),0) as total FROM orders WHERE payment_status='paid' AND order_status NOT IN ('completed','cancelled','refunded')").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(*) as total, COALESCE(SUM(amount),0) as total_amount FROM payouts WHERE status = 'pending'").catch(() => [{ total: 0, total_amount: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM kyc_verifications WHERE status='pending'").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM disputes WHERE status IN ('open','under_review','pending')").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM bid_fraud_alerts WHERE status IN ('new','reviewing')").catch(() => [{ total: 0 }]),
        query<any[]>("SELECT COUNT(*) as total FROM security_alerts WHERE status IN ('new','reviewing')").catch(() => [{ total: 0 }])
      ]);

    sendSuccess(res, {
      total_users: usersCount[0]?.total || 0,
      active_sellers: activeSellers[0]?.total || 0,
      total_auctions: auctionsCount[0]?.total || 0,
      active_auctions: auctionsCount[0]?.active || 0,
      todays_bids: todaysBids[0]?.total || 0,
      total_revenue_pkr: revenueCount[0]?.total || 0,
      platform_commission_pkr: revenueCount[0]?.commission || 0,
      commission_earned: revenueCount[0]?.commission || 0,
      pending_payments: pendingPayments[0]?.total || 0,
      escrow_balance_pkr: escrowBalance[0]?.total || 0,
      escrow_held: escrowBalance[0]?.total || 0,
      pending_payouts: pendingPayouts[0]?.total || 0,
      pending_payouts_amount_pkr: pendingPayouts[0]?.total_amount || 0,
      pending_kyc: pendingKyc[0]?.total || 0,
      open_disputes: openDisputes[0]?.total || 0,
      fraud_alerts: fraudAlerts[0]?.total || 0,
      security_alerts: securityAlerts[0]?.total || 0
    });
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 2. USER MANAGEMENT ─────────────────────────────────────
router.get('/users', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '20', 10));
    const offset = (page - 1) * limit;
    const search = req.query.search as string;
    const role = req.query.role as string;
    const status = req.query.status as string;

    let where = 'WHERE 1=1';
    const params: any[] = [];
    if (search) { where += ' AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.username LIKE ?)'; params.push(`%${search}%`, `%${search}%`, `%${search}%`, `%${search}%`); }
    if (role && ['user', 'seller', 'admin'].includes(role)) { where += ' AND u.role = ?'; params.push(role); }
    if (status && ['active', 'inactive', 'blocked', 'suspended'].includes(status)) { where += ' AND (u.status = ? OR (u.status = "blocked" AND ? = "suspended"))'; params.push(status, status); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM users u ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT u.user_id, u.username, u.full_name, u.email, u.phone, u.role,
              u.status as account_status, u.status, u.kyc_status, u.seller_kyc_status,
              u.created_at,
              (SELECT COUNT(*) FROM auctions WHERE seller_id = u.user_id) as auction_count,
              (SELECT COUNT(*) FROM orders WHERE buyer_id = u.user_id OR seller_id = u.user_id) as order_count
       FROM users u ${where} ORDER BY u.created_at DESC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/users/:id/status', async (req: Request, res: Response) => {
  try {
    const userId = parseInt(req.params.id, 10);
    const { status, reason } = req.body;
    const valid = ['active', 'inactive', 'blocked', 'suspended'];
    if (!valid.includes(status)) { sendError(res, `Invalid status. Allowed: ${valid.join(', ')}`, 400); return; }
    const dbStatus = status === 'suspended' ? 'blocked' : status;
    await query('UPDATE users SET status = ?, verification_notes = ? WHERE user_id = ?', [dbStatus, reason || null, userId]);
    await logAdminActivity(req, 'USER_STATUS_CHANGE', 'user', userId, { new_status: dbStatus, reason });
    sendSuccess(res, { user_id: userId, status: dbStatus, updated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.patch('/users/:id/role', async (req: Request, res: Response) => {
  try {
    const userId = parseInt(req.params.id, 10);
    const { role } = req.body;
    if (!['user', 'seller', 'admin'].includes(role)) { sendError(res, 'Role must be user, seller, or admin', 400); return; }
    await query('UPDATE users SET role = ? WHERE user_id = ?', [role, userId]);
    await logAdminActivity(req, 'USER_ROLE_CHANGE', 'user', userId, { new_role: role });
    sendSuccess(res, { user_id: userId, role, updated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.patch('/users/:id/verify', async (req: Request, res: Response) => {
  try {
    const userId = parseInt(req.params.id, 10);

    await withTransaction(async (conn) => {
      await conn.execute(
        "UPDATE users SET kyc_status = 'approved', kyc_verified_at = NOW() WHERE user_id = ?",
        [userId]
      );

      const [existing]: any = await conn.execute(
        "SELECT kyc_id FROM kyc_verifications WHERE user_id = ? AND kyc_scope = 'buyer' LIMIT 1",
        [userId]
      );
      if (existing.length > 0) {
        await conn.execute(
          "UPDATE kyc_verifications SET status = 'approved', ai_status = 'manual_override', reviewed_at = NOW() WHERE kyc_id = ?",
          [existing[0].kyc_id]
        );
      } else {
        await conn.execute(
          `INSERT INTO kyc_verifications (user_id, kyc_scope, status, ai_status, reviewed_at, created_at)
           VALUES (?, 'buyer', 'approved', 'manual_override', NOW(), NOW())`,
          [userId]
        );
      }
    });

    await logAdminActivity(req, 'USER_MANUAL_VERIFY', 'user', userId, {});
    sendSuccess(res, { user_id: userId, verified: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ✅ FIXED: respect is_banned payload — ban + unban both work
router.patch('/users/:id/ban', async (req: Request, res: Response) => {
  try {
    const userId = parseInt(req.params.id, 10);
    const { is_banned } = req.body;
    const banFlag = is_banned === true || is_banned === 'true' || is_banned === 1 || is_banned === '1';
    const newStatus = banFlag ? 'blocked' : 'active';
    const result: any = await query('UPDATE users SET status = ? WHERE user_id = ?', [newStatus, userId]);
    if (!result || result.affectedRows === 0) { sendError(res, 'User not found', 404); return; }
    await logAdminActivity(req, banFlag ? 'USER_BAN' : 'USER_UNBAN', 'user', userId, { status: newStatus });
    sendSuccess(res, { user_id: userId, status: newStatus }, banFlag ? 'User has been banned.' : 'User has been unbanned.');
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 3. SELLERS DIRECTORY ───────────────────────────────────
router.get('/sellers', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '20', 10));
    const offset = (page - 1) * limit;
    const search = req.query.search as string;

    let where = "WHERE u.role = 'seller' OR (SELECT COUNT(*) FROM auctions WHERE seller_id = u.user_id) > 0";
    const params: any[] = [];
    if (search) { where += " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.business_name LIKE ?)"; params.push(`%${search}%`, `%${search}%`, `%${search}%`); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM users u ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT u.user_id, u.full_name, u.email, u.phone, COALESCE(u.business_name, u.full_name) as store_name, u.business_name, u.seller_kyc_status,
              u.status, u.created_at,
              (SELECT COUNT(*) FROM auctions WHERE seller_id = u.user_id) as total_auctions,
              (SELECT COUNT(*) FROM auctions WHERE seller_id = u.user_id AND auction_status = 'active') as active_auctions,
              (SELECT COALESCE(SUM(seller_amount),0) FROM orders WHERE seller_id = u.user_id AND payment_status='paid') as total_sales_pkr,
              (SELECT AVG(rating) FROM reviews WHERE seller_id = u.user_id) as average_rating
       FROM users u ${where} ORDER BY total_sales_pkr DESC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 4. AUCTION MODERATION ──────────────────────────────────
router.get('/auctions', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '20', 10));
    const offset = (page - 1) * limit;
    const status = req.query.status as string;
    const search = req.query.search as string;

    let where = 'WHERE 1=1';
    const params: any[] = [];
    if (status) { where += ' AND a.auction_status = ?'; params.push(status); }
    if (search) { where += ' AND (a.title LIKE ? OR a.auction_id = ?)'; params.push(`%${search}%`, search); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM auctions a ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT a.auction_id, a.title, a.starting_price, a.current_bid, a.reserve_price,
              a.buy_now_price, a.auction_status, a.image, a.start_time, a.end_time,
              a.rejection_reason, a.created_at,
              u.full_name as seller_name, u.email as seller_email, u.user_id as seller_id,
              c.category_name,
              (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count
       FROM auctions a
       LEFT JOIN users u ON u.user_id = a.seller_id
       LEFT JOIN categories c ON c.category_id = a.category_id
       ${where} ORDER BY a.created_at DESC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/auctions/:id/moderate', async (req: Request, res: Response) => {
  try {
    const auctionId = parseInt(req.params.id, 10);
    const { action, rejection_reason } = req.body;
    if (!['approve', 'reject', 'suspend'].includes(action)) { sendError(res, "Action must be 'approve', 'reject', or 'suspend'", 400); return; }
    if (action === 'reject' && !rejection_reason) { sendError(res, 'Rejection reason is required', 400); return; }

    const newStatus = action === 'approve' ? 'active' : action === 'reject' ? 'rejected' : 'suspended';
    await query('UPDATE auctions SET auction_status = ?, rejection_reason = ? WHERE auction_id = ?', [newStatus, rejection_reason || null, auctionId]);
    await logAdminActivity(req, `AUCTION_${action.toUpperCase()}`, 'auction', auctionId, { action, rejection_reason });
    sendSuccess(res, { auction_id: auctionId, status: newStatus, moderated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ✅ NEW: alias for frontend compatibility (some clients call /status with {status})
router.patch('/auctions/:id/status', async (req: Request, res: Response) => {
  try {
    const auctionId = parseInt(req.params.id, 10);
    const { status, rejection_reason } = req.body;

    let action: string;
    if (status === 'active') action = 'approve';
    else if (status === 'suspended') action = 'suspend';
    else if (status === 'rejected') action = 'reject';
    else { sendError(res, 'Invalid status. Use active, suspended, or rejected.', 400); return; }

    if (action === 'reject' && !rejection_reason) { sendError(res, 'Rejection reason is required', 400); return; }

    const newStatus = action === 'approve' ? 'active' : action === 'reject' ? 'rejected' : 'suspended';
    await query('UPDATE auctions SET auction_status = ?, rejection_reason = ? WHERE auction_id = ?', [newStatus, rejection_reason || null, auctionId]);
    await logAdminActivity(req, `AUCTION_${action.toUpperCase()}`, 'auction', auctionId, { action, rejection_reason });
    sendSuccess(res, { auction_id: auctionId, status: newStatus, moderated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.delete('/auctions/:id', async (req: Request, res: Response) => {
  try {
    const auctionId = parseInt(req.params.id, 10);
    await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]);
    await logAdminActivity(req, 'AUCTION_DELETE', 'auction', auctionId, {});
    sendSuccess(res, { auction_id: auctionId, deleted: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 5. CATEGORIES MANAGEMENT ───────────────────────────────
router.get('/categories', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT c.*, (SELECT COUNT(*) FROM auctions WHERE category_id = c.category_id) as auction_count
       FROM categories c ORDER BY c.category_name ASC`
    );
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.post('/categories', async (req: Request, res: Response) => {
  try {
    const { category_name, description, image, icon, color } = req.body;
    if (!category_name) { sendError(res, 'Category name is required', 400); return; }
    const result: any = await query(
      'INSERT INTO categories (category_name, description, category_image, icon, color, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())',
      [category_name, description || null, image || null, icon || 'fa-tag', color || '#6366f1']
    );
    await logAdminActivity(req, 'CATEGORY_CREATE', 'category', result.insertId, { category_name });
    sendSuccess(res, { category_id: result.insertId, created: true }, 'Category created', 201);
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.put('/categories/:id', async (req: Request, res: Response) => {
  try {
    const categoryId = parseInt(req.params.id, 10);
    const { category_name, description, image, icon, color, status } = req.body;
    await query(
      `UPDATE categories SET 
         category_name = COALESCE(?, category_name), 
         description = COALESCE(?, description), 
         category_image = COALESCE(?, category_image), 
         icon = COALESCE(?, icon), 
         color = COALESCE(?, color), 
         status = COALESCE(?, status) 
       WHERE category_id = ?`,
      [category_name || null, description || null, image || null, icon || null, color || null, status || null, categoryId]
    );
    await logAdminActivity(req, 'CATEGORY_UPDATE', 'category', categoryId, { category_name });
    sendSuccess(res, { category_id: categoryId, updated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.delete('/categories/:id', async (req: Request, res: Response) => {
  try {
    const categoryId = parseInt(req.params.id, 10);
    await query('DELETE FROM categories WHERE category_id = ?', [categoryId]);
    await logAdminActivity(req, 'CATEGORY_DELETE', 'category', categoryId, {});
    sendSuccess(res, { category_id: categoryId, deleted: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 6. BIDS MONITORING ─────────────────────────────────────
router.get('/bids', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '30', 10));
    const offset = (page - 1) * limit;
    const auctionId = req.query.auction_id as string;

    let where = 'WHERE 1=1';
    const params: any[] = [];
    if (auctionId) { where += ' AND b.auction_id = ?'; params.push(auctionId); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM bids b ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT b.bid_id, b.auction_id, b.bid_amount, b.bid_time, b.is_auto_bid, b.ip_address,
              u.user_id as bidder_id, u.full_name as bidder_name, u.email as bidder_email,
              a.title as auction_title, a.current_bid
       FROM bids b
       JOIN users u ON u.user_id = b.bidder_id
       JOIN auctions a ON a.auction_id = b.auction_id
       ${where} ORDER BY b.bid_time DESC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.delete('/bids/:id', async (req: Request, res: Response) => {
  try {
    const bidId = parseInt(req.params.id, 10);
    await query('DELETE FROM bids WHERE bid_id = ?', [bidId]);
    await logAdminActivity(req, 'BID_RETRACT_DELETE', 'bid', bidId, {});
    sendSuccess(res, { bid_id: bidId, retracted: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 7. KYC COMPLIANCE ──────────────────────────────────────
router.get('/kyc', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = parseInt(req.query.limit as string || '20', 10);
    const offset = (page - 1) * limit;
    const status = req.query.status as string;

    let where = 'WHERE 1=1';
    const params: any[] = [];
    if (status && ['pending', 'approved', 'rejected', 'under_review'].includes(status)) { where += ' AND k.status = ?'; params.push(status); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM kyc_verifications k ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT k.kyc_id, k.user_id, k.kyc_scope, k.full_name_on_cnic, k.cnic_number_masked,
              k.date_of_birth, k.status, k.ai_status, k.ai_confidence, k.ai_recommendation,
              k.rejection_reason, k.cnic_front_path, k.cnic_back_path, k.selfie_path,
              k.selfie_with_cnic_path, k.bank_name, k.bank_account_holder,
              k.bank_account_number, k.seller_type, k.business_name,
              k.created_at, k.reviewed_at,
              u.full_name, u.email, u.phone
       FROM kyc_verifications k JOIN users u ON u.user_id = k.user_id
       ${where} ORDER BY k.created_at ASC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/kyc/:id/review', async (req: Request, res: Response) => {
  try {
    const kycId = parseInt(req.params.id, 10);
    const { status, rejection_reason } = req.body;
    if (!['approved', 'rejected', 'under_review'].includes(status)) { sendError(res, "Status must be 'approved', 'rejected', or 'under_review'", 400); return; }
    if (status === 'rejected' && !rejection_reason) { sendError(res, 'Rejection reason is required', 400); return; }

    const kycRows = await query<any[]>('SELECT * FROM kyc_verifications WHERE kyc_id = ? LIMIT 1', [kycId]);
    if (kycRows.length === 0) { sendError(res, 'KYC record not found', 404); return; }
    const kyc = kycRows[0];

    await withTransaction(async (conn) => {
      await conn.execute(
        "UPDATE kyc_verifications SET status = ?, rejection_reason = ?, reviewed_at = NOW() WHERE kyc_id = ?",
        [status, rejection_reason || null, kycId]
      );

      if (kyc.kyc_scope === 'buyer') {
        if (status === 'approved') {
          await conn.execute("UPDATE users SET kyc_status = 'approved', kyc_verified_at = NOW() WHERE user_id = ?", [kyc.user_id]);
        } else if (status === 'rejected') {
          await conn.execute("UPDATE users SET kyc_status = 'rejected', kyc_verified_at = NULL WHERE user_id = ?", [kyc.user_id]);
        } else {
          await conn.execute("UPDATE users SET kyc_status = 'pending' WHERE user_id = ?", [kyc.user_id]);
        }
      } else {
        if (status === 'approved') {
          await conn.execute("UPDATE users SET seller_kyc_status = 'approved', seller_kyc_verified_at = NOW() WHERE user_id = ?", [kyc.user_id]);
        } else if (status === 'rejected') {
          await conn.execute("UPDATE users SET seller_kyc_status = 'rejected', seller_kyc_verified_at = NULL WHERE user_id = ?", [kyc.user_id]);
        } else {
          await conn.execute("UPDATE users SET seller_kyc_status = 'pending' WHERE user_id = ?", [kyc.user_id]);
        }
      }
    });

    await logAdminActivity(req, `KYC_${status.toUpperCase()}`, 'kyc', kycId, { user_id: kyc.user_id, rejection_reason });

    query<any[]>('SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1', [kyc.user_id])
      .then((users) => {
        if (users.length && users[0].email) {
          const user = users[0];
          if (status === 'approved') EmailService.sendKycApproved(user.email, user.full_name || 'User', kyc.kyc_scope).catch(() => { });
          else if (status === 'rejected') EmailService.sendKycRejected(user.email, user.full_name || 'User', kyc.kyc_scope, rejection_reason || 'Documents incomplete or unclear').catch(() => { });
        }
      }).catch(() => { });

    sendSuccess(res, { kyc_id: kycId, status, reviewed: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 8. ORDERS ──────────────────────────────────────────────
router.get('/orders', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '20', 10));
    const offset = (page - 1) * limit;
    const search = req.query.search as string;
    const paymentStatus = req.query.payment_status as string;
    const orderStatus = req.query.order_status as string;

    let where = 'WHERE 1=1';
    const params: any[] = [];
    if (search) { where += ' AND (o.order_number LIKE ? OR a.title LIKE ?)'; params.push(`%${search}%`, `%${search}%`); }
    if (paymentStatus) { where += ' AND o.payment_status = ?'; params.push(paymentStatus); }
    if (orderStatus) { where += ' AND o.order_status = ?'; params.push(orderStatus); }

    const [[{ total }]] = await Promise.all([query<any[]>(`SELECT COUNT(*) as total FROM orders o LEFT JOIN auctions a ON a.auction_id = o.auction_id ${where}`, params)]);
    const rows = await query<any[]>(
      `SELECT o.order_id, o.order_number, o.winning_bid, o.platform_fee, o.seller_amount,
              o.total_amount, o.order_status, o.payment_status, o.shipping_status,
              o.courier, o.tracking_number, o.created_at,
              b.full_name as buyer_name, b.email as buyer_email,
              s.full_name as seller_name, s.email as seller_email,
              a.title as auction_title, a.auction_id
       FROM orders o
       JOIN users b ON b.user_id = o.buyer_id
       JOIN users s ON s.user_id = o.seller_id
       JOIN auctions a ON a.auction_id = o.auction_id
       ${where} ORDER BY o.created_at DESC LIMIT ? OFFSET ?`,
      [...params, limit, offset]
    );
    sendPaginated(res, rows, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/orders/:id/status', async (req: Request, res: Response) => {
  try {
    const orderId = parseInt(req.params.id, 10);
    const { order_status, payment_status, shipping_status } = req.body;
    const existingOrders = await query<any[]>('SELECT order_status, payment_status, shipping_status FROM orders WHERE order_id = ? LIMIT 1', [orderId]);
    if (!existingOrders.length) { sendError(res, 'Order not found', 404); return; }
    if (order_status && order_status !== existingOrders[0].order_status) validateOrderTransition(existingOrders[0].order_status, order_status);

    await query(
      `UPDATE orders SET order_status = COALESCE(?, order_status), payment_status = COALESCE(?, payment_status),
       shipping_status = COALESCE(?, shipping_status), updated_at = NOW() WHERE order_id = ?`,
      [order_status || null, payment_status || null, shipping_status || null, orderId]
    );
    await logAdminActivity(req, 'ORDER_STATUS_UPDATE', 'order', orderId, { order_status, payment_status, shipping_status, previous_status: existingOrders[0].order_status });
    sendSuccess(res, { order_id: orderId, updated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 9. PAYMENTS ✅ FIXED: joins orders + auctions, aliases buyer_name/buyer_email
router.get('/payments', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '20', 10));
    const offset = (page - 1) * limit;
    const [[{ total }]] = await Promise.all([query<any[]>('SELECT COUNT(*) as total FROM payments')]);

    const rows = await query<any[]>(
      `SELECT p.payment_id, p.order_id, p.amount,
              p.payment_method,
              p.payment_status,
              p.transaction_id,
              p.created_at,
              o.order_number,
              u.full_name as user_name, u.email as user_email,
              a.title as auction_title
       FROM payments p
       LEFT JOIN users u ON u.user_id = p.user_id
       LEFT JOIN orders o ON o.order_id = p.order_id
       LEFT JOIN auctions a ON a.auction_id = o.auction_id
       ORDER BY p.created_at DESC LIMIT ? OFFSET ?`,
      [limit, offset]
    );

    const formatted = rows.map((r: any) => ({
      ...r,
      buyer_name: r.user_name,
      buyer_email: r.user_email
    }));
    sendPaginated(res, formatted, total, page, limit);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 10. ESCROW ─────────────────────────────────────────────
router.get('/escrow', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT o.order_id, o.order_number, o.total_amount, o.seller_amount, o.platform_fee,
              o.order_status, o.payment_status, o.shipping_status, o.created_at,
              b.full_name as buyer_name, s.full_name as seller_name, a.title as auction_title
       FROM orders o
       JOIN users b ON b.user_id = o.buyer_id
       JOIN users s ON s.user_id = o.seller_id
       JOIN auctions a ON a.auction_id = o.auction_id
       WHERE o.payment_status = 'paid'
       ORDER BY o.created_at DESC LIMIT 100`
    );
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.post('/escrow/:id/release', async (req: Request, res: Response) => {
  try {
    const orderId = parseInt(req.params.id, 10);
    // ✅ FIX: Use EscrowService to properly credit seller's wallet instead of just updating order status
    await EscrowService.releaseEscrow(orderId, 'admin_manual_release');
    await logAdminActivity(req, 'ESCROW_MANUAL_RELEASE', 'order', orderId, {});
    sendSuccess(res, { order_id: orderId, released: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.post('/escrow/:id/refund', async (req: Request, res: Response) => {
  try {
    const orderId = parseInt(req.params.id, 10);
    const { reason } = req.body;

    await EscrowService.refundEscrow(orderId, reason || 'admin_manual_refund');

    await query(
      "UPDATE orders SET order_status = 'refunded', payment_status = 'refunded', updated_at = NOW() WHERE order_id = ?",
      [orderId]
    );

    await logAdminActivity(req, 'ESCROW_MANUAL_REFUND', 'order', orderId, { reason });
    sendSuccess(res, { order_id: orderId, refunded: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 11. REFUNDS ────────────────────────────────────────────
router.get('/refunds', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT r.*, o.order_number, b.full_name as buyer_name, s.full_name as seller_name
       FROM refunds r
       LEFT JOIN orders o ON o.order_id = r.order_id
       LEFT JOIN users b ON b.user_id = r.buyer_id
       LEFT JOIN users s ON s.user_id = r.seller_id
       ORDER BY r.requested_at DESC LIMIT 100`
    ).catch(async () => query<any[]>(
      `SELECT o.order_id, o.order_number, o.total_amount as amount, 'Refund on order' as reason,
              'completed' as status, o.updated_at as processed_at, b.full_name as buyer_name
       FROM orders o JOIN users b ON b.user_id = o.buyer_id
       WHERE o.payment_status = 'refunded' LIMIT 50`
    ));
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 12. PAYOUTS ────────────────────────────────────────────
router.get('/payouts', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT p.*, u.full_name as seller_name, u.email as seller_email, u.phone as seller_phone
       FROM payouts p JOIN users u ON u.user_id = p.seller_id
       ORDER BY p.requested_at DESC LIMIT 100`
    );
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/payouts/:id/status', async (req: Request, res: Response) => {
  try {
    const payoutId = parseInt(req.params.id, 10);
    const { status, reference, note } = req.body;
    if (!['completed', 'rejected', 'processing'].includes(status)) { sendError(res, 'Status must be completed, rejected, or processing', 400); return; }

    // ✅ FIX: Fetch payout details first for wallet refund
    const payoutRows = await query<any[]>('SELECT seller_id, amount FROM payouts WHERE payout_id = ?', [payoutId]);

    await withTransaction(async (conn) => {
      // Update Payout Status
      await conn.execute(
        "UPDATE payouts SET status = ?, reference = COALESCE(?, reference), processed_at = NOW() WHERE payout_id = ?",
        [status, reference || null, payoutId]
      );

      // ✅ FIX: Map to seller_ledger enum (no 'completed' value)
      // - completed → 'available' (money moved out, ledger entry finalized)
      // - rejected  → 'cancelled' (entry voided)
      // - processing→ 'pending'   (still in flight)
      const ledgerStatus =
        status === 'completed' ? 'available' :
          status === 'rejected' ? 'cancelled' :
            'pending';

      await conn.execute(
        "UPDATE seller_ledger SET status = ? WHERE payout_id = ?",
        [ledgerStatus, payoutId]
      );

      // ✅ FIX: Refund wallet + create audit-trail credit entry on reject
      if (status === 'rejected' && payoutRows.length > 0) {
        const sellerId = payoutRows[0].seller_id;
        const amount = payoutRows[0].amount;

        await conn.execute(
          "UPDATE seller_wallet SET available_balance = available_balance + ?, updated_at = NOW() WHERE seller_id = ?",
          [amount, sellerId]
        );

        const [walletAfter]: any = await conn.execute(
          "SELECT available_balance FROM seller_wallet WHERE seller_id = ? LIMIT 1",
          [sellerId]
        );
        const newBalance = walletAfter[0]?.available_balance || 0;

        // ✅ FIX: status = 'available' (NOT 'completed' — not in enum)
        await conn.execute(
          `INSERT INTO seller_ledger
             (seller_id, payout_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
           VALUES (?, ?, 'credit', 'payout_rejected', ?, ?, 'available', ?, ?, NOW())`,
          [sellerId, payoutId, amount, newBalance, `PAYOUT-REFUND-${payoutId}`, `Payout #${payoutId} rejected — refunded to wallet`]
        );
      }
    });

    await logAdminActivity(req, `PAYOUT_${status.toUpperCase()}`, 'payout', payoutId, { reference, note });
    sendSuccess(res, { payout_id: payoutId, status, updated: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 13. DISPUTES ───────────────────────────────────────────
router.get('/disputes', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT d.*, o.total_amount, o.buyer_id, o.seller_id, a.title as auction_title,
              b.full_name as buyer_name, s.full_name as seller_name
       FROM disputes d
       JOIN orders o ON o.order_id = d.order_id
       JOIN auctions a ON a.auction_id = o.auction_id
       JOIN users b ON b.user_id = o.buyer_id
       JOIN users s ON s.user_id = o.seller_id
       ORDER BY d.created_at DESC LIMIT 100`
    );
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/disputes/:id/resolve', async (req: Request, res: Response) => {
  try {
    const disputeId = parseInt(req.params.id, 10);
    const { resolution, in_favor_of } = req.body;
    if (!resolution) { sendError(res, 'Resolution note is required', 400); return; }
    const disputes = await query<any[]>('SELECT d.*, o.order_id FROM disputes d JOIN orders o ON o.order_id = d.order_id WHERE d.dispute_id = ? LIMIT 1', [disputeId]);
    if (disputes.length === 0) { sendError(res, 'Dispute not found', 404); return; }
    const orderId = disputes[0].order_id;

    await withTransaction(async (conn) => {
      await conn.execute("UPDATE disputes SET status = 'resolved', resolution = ?, updated_at = NOW() WHERE dispute_id = ?", [resolution, disputeId]);
    });

    if (in_favor_of === 'buyer') {
      await EscrowService.refundEscrow(orderId, `admin_dispute_resolved: ${resolution}`);
      await query("UPDATE orders SET order_status = 'refunded', payment_status = 'refunded', updated_at = NOW() WHERE order_id = ?", [orderId]);
    } else if (in_favor_of === 'seller') {
      await EscrowService.releaseEscrow(orderId, `admin_dispute_resolved: ${resolution}`);
      await query("UPDATE orders SET order_status = 'completed', updated_at = NOW() WHERE order_id = ?", [orderId]);
    }

    await logAdminActivity(req, 'DISPUTE_RESOLVE', 'dispute', disputeId, { resolution, in_favor_of });
    sendSuccess(res, { dispute_id: disputeId, resolved: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 14. FRAUD ALERTS ───────────────────────────────────────
router.get('/fraud-alerts', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT fa.*, a.title as auction_title, s.full_name as seller_name, b.full_name as bidder_name
       FROM bid_fraud_alerts fa
       LEFT JOIN auctions a ON a.auction_id = fa.auction_id
       LEFT JOIN users s ON s.user_id = fa.seller_id
       LEFT JOIN users b ON b.user_id = fa.bidder_id
       ORDER BY fa.created_at DESC LIMIT 100`
    ).catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/fraud-alerts/:id/resolve', async (req: Request, res: Response) => {
  try {
    const alertId = parseInt(req.params.id, 10);
    const { status, review_note } = req.body;
    const result: any = await query("UPDATE bid_fraud_alerts SET status = ?, review_note = ?, reviewed_at = NOW() WHERE alert_id = ?", [status || 'action_taken', review_note || null, alertId]);
    if (!result || result.affectedRows === 0) { sendError(res, 'Fraud alert not found or already resolved', 404); return; }
    await logAdminActivity(req, 'FRAUD_ALERT_RESOLVE', 'fraud_alert', alertId, { status, review_note });
    sendSuccess(res, { alert_id: alertId, resolved: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 15. SECURITY ALERTS ────────────────────────────────────
router.get('/security-alerts', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>(
      `SELECT la.*, u.full_name, u.role
       FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id
       ORDER BY la.created_at DESC LIMIT 100`
    ).catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 16. AUDIT LOGS ─────────────────────────────────────────
router.get('/activity-logs', async (req: Request, res: Response) => {
  try {
    const page = Math.max(1, parseInt(req.query.page as string || '1', 10));
    const limit = Math.min(100, parseInt(req.query.limit as string || '30', 10));
    const offset = (page - 1) * limit;
    const rows = await query<any[]>(
      `SELECT al.*, u.full_name as admin_name, u.email as admin_email
       FROM admin_activity_log al LEFT JOIN users u ON u.user_id = al.admin_id
       ORDER BY al.created_at DESC LIMIT ? OFFSET ?`,
      [limit, offset]
    ).catch(async () => query<any[]>(
      `SELECT al.*, u.full_name as admin_name FROM admin_activity_log al
       LEFT JOIN users u ON u.user_id = al.admin_id ORDER BY al.id DESC LIMIT ? OFFSET ?`,
      [limit, offset]
    ));
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 17. REPORTS (single canonical set — duplicate removed) ──
router.get('/reports', async (req: Request, res: Response) => {
  try {
    const status = req.query.status as string;
    let whereClause = '';
    const params: any[] = [];
    if (status && status !== 'all') { whereClause = 'WHERE r.status = ?'; params.push(status); }

    const rows = await query<any[]>(
      `SELECT r.id, r.reporter_id, r.target_type, r.target_id, r.reason, r.description, r.status, r.created_at,
              COALESCE(u.full_name, r.reporter_id) as reporter_name
       FROM reports r
       LEFT JOIN users u ON u.user_id = CAST(r.reporter_id AS SIGNED)
       ${whereClause}
       ORDER BY r.created_at DESC LIMIT 100`,
      params
    ).catch(() => []);

    const formattedReports = rows.map((r) => ({
      id: String(r.id || ''),
      reporter_id: r.reporter_id ? String(r.reporter_id) : 'Anonymous',
      reporter_name: r.reporter_name || 'Anonymous',
      target_type: String(r.target_type || 'listing'),
      target_id: r.target_id ? String(r.target_id) : 'N/A',
      reason: String(r.reason || 'Content Policy Violation'),
      description: r.description ? String(r.description) : '',
      status: String(r.status || 'pending'),
      created_at: r.created_at ? new Date(r.created_at).toISOString() : new Date().toISOString(),
    }));

    sendSuccess(res, formattedReports);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/reports/:id/resolve', async (req: Request, res: Response) => {
  try {
    const id = req.params.id;
    const adminId = (req as any).user?.userId || null;
    const { notes } = req.body || {};
    const result: any = await query(
      'UPDATE reports SET status = "resolved", resolution_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?',
      [notes || 'Resolved by admin', adminId, id]
    );
    if (!result || result.affectedRows === 0) { sendError(res, 'Report not found or already resolved', 404); return; }
    await logAdminActivity(req, 'REPORT_RESOLVE', 'report', id as any, { notes });
    sendSuccess(res, { id, status: 'resolved', resolved: true }, 'Report resolved successfully');
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.patch('/reports/:id/escalate', async (req: Request, res: Response) => {
  try {
    const id = req.params.id;
    const adminId = (req as any).user?.userId || null;
    const { notes } = req.body || {};
    const result: any = await query(
      'UPDATE reports SET status = "escalated", resolution_notes = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?',
      [notes || 'Escalated by admin for sanctions', adminId, id]
    );
    if (!result || result.affectedRows === 0) { sendError(res, 'Report not found or already resolved', 404); return; }
    await logAdminActivity(req, 'REPORT_ESCALATE', 'report', id as any, { notes });
    sendSuccess(res, { id, status: 'escalated', escalated: true }, 'Report escalated successfully');
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 18. ANALYTICS ✅ FIXED: proper schema + range parameter ─
router.get('/analytics', async (req: Request, res: Response) => {
  try {
    const range = (req.query.range as string) || '30d';
    let intervalOrders = '';
    let intervalBids = '';
    let intervalUsers = '';

    if (range === '7d') {
      intervalOrders = 'AND o.created_at >= NOW() - INTERVAL 7 DAY';
      intervalBids = 'AND b.bid_time >= NOW() - INTERVAL 7 DAY';
      intervalUsers = 'AND u.created_at >= NOW() - INTERVAL 7 DAY';
    } else if (range === '90d') {
      intervalOrders = 'AND o.created_at >= NOW() - INTERVAL 90 DAY';
      intervalBids = 'AND b.bid_time >= NOW() - INTERVAL 90 DAY';
      intervalUsers = 'AND u.created_at >= NOW() - INTERVAL 90 DAY';
    } else if (range === 'all') {
      // no interval
    } else {
      intervalOrders = 'AND o.created_at >= NOW() - INTERVAL 30 DAY';
      intervalBids = 'AND b.bid_time >= NOW() - INTERVAL 30 DAY';
      intervalUsers = 'AND u.created_at >= NOW() - INTERVAL 30 DAY';
    }

    const [gmvTotals, buyersTotals, sellThroughRes, bidCountRes, gmvTrend, categoryVolume, userGrowth] = await Promise.all([
      query<any[]>(`SELECT COALESCE(SUM(o.total_amount), 0) as gmv FROM orders o WHERE o.payment_status = 'paid' ${intervalOrders}`).catch(() => [{ gmv: 0 }]),
      query<any[]>(`SELECT COUNT(DISTINCT o.buyer_id) as buyers FROM orders o WHERE o.payment_status = 'paid' ${intervalOrders}`).catch(() => [{ buyers: 0 }]),
      query<any[]>(`SELECT COUNT(*) as total, SUM(CASE WHEN auction_status IN ('sold','completed','ended') THEN 1 ELSE 0 END) as sold FROM auctions`).catch(() => [{ total: 0, sold: 0 }]),
      query<any[]>(`SELECT COUNT(*) as cnt FROM bids b WHERE 1=1 ${intervalBids}`).catch(() => [{ cnt: 0 }]),
      query<any[]>(`SELECT DATE_FORMAT(o.created_at, '%Y-%m') as month, COALESCE(SUM(o.total_amount), 0) as value FROM orders o WHERE o.payment_status = 'paid' ${intervalOrders} GROUP BY DATE_FORMAT(o.created_at, '%Y-%m') ORDER BY month ASC`).catch(() => []),
      query<any[]>(`SELECT c.category_name as name, COUNT(a.auction_id) as count FROM categories c LEFT JOIN auctions a ON a.category_id = c.category_id GROUP BY c.category_id ORDER BY count DESC LIMIT 6`).catch(() => []),
      query<any[]>(`SELECT DATE(u.created_at) as day, COUNT(*) as new_users FROM users u WHERE 1=1 ${intervalUsers} GROUP BY DATE(u.created_at) ORDER BY day ASC`).catch(() => [])
    ]);

    const totalGmv = parseFloat(gmvTotals[0]?.gmv || 0);
    const activeBuyers = parseInt(buyersTotals[0]?.buyers || 0, 10);
    const stTotal = parseInt(sellThroughRes[0]?.total || 0, 10);
    const stSold = parseInt(sellThroughRes[0]?.sold || 0, 10);
    const sellThrough = stTotal > 0 ? (stSold / stTotal) * 100 : 0;

    let hoursInRange = 30 * 24;
    if (range === '7d') hoursInRange = 7 * 24;
    else if (range === '90d') hoursInRange = 90 * 24;
    else if (range === 'all') {
      const earliest = await query<any[]>('SELECT MIN(bid_time) as first FROM bids').catch(() => [{ first: null }]);
      if (earliest[0]?.first) hoursInRange = Math.max(1, (Date.now() - new Date(earliest[0].first).getTime()) / 3600000);
    }
    const bidVelocity = hoursInRange > 0 ? (parseInt(bidCountRes[0]?.cnt || 0, 10) / hoursInRange) : 0;

    sendSuccess(res, {
      range,
      totals: {
        totalGmv,
        activeBuyers,
        sellThrough: Math.round(sellThrough * 10) / 10,
        bidVelocity: Math.round(bidVelocity * 100) / 100
      },
      gmvTrend,
      categoryVolume,
      userGrowth
    });
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 19. NOTIFICATIONS ✅ FIXED: accepts target_role ────────
router.get('/notifications', async (req: Request, res: Response) => {
  try {
    const rows = await query<any[]>('SELECT * FROM admin_notifications ORDER BY created_at DESC LIMIT 50').catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.post('/notifications/broadcast', async (req: Request, res: Response) => {
  try {
    const { title, message, link_url, target_role } = req.body;
    if (!title || !message) { sendError(res, 'Title and message are required', 400); return; }
    const validRoles = ['all', 'seller', 'buyer', 'admin'];
    const role = validRoles.includes(target_role) ? target_role : 'all';
    await query(
      `INSERT INTO admin_notifications (type, title, message, link_url, target_role, created_at)
       VALUES ('broadcast', ?, ?, ?, ?, NOW())`,
      [title, message, link_url || null, role]
    );
    await logAdminActivity(req, 'NOTIFICATION_BROADCAST', null, null, { title, target_role: role });
    sendSuccess(res, { broadcasted: true, target_role: role }, 'Broadcast created', 201);
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.patch('/notifications/:id/read', async (req: Request, res: Response) => {
  try {
    const notifId = parseInt(req.params.id, 10);
    const adminId = (req as any).user?.userId || null;
    await query('UPDATE admin_notifications SET is_read = 1 WHERE id = ? AND (admin_id IS NULL OR admin_id = ?)', [notifId, adminId]);
    sendSuccess(res, { id: notifId, read: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

router.patch('/notifications/read-all', async (req: Request, res: Response) => {
  try {
    await query('UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0');
    sendSuccess(res, { marked_all_read: true });
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 20. PENDING ACTIONS ────────────────────────────────────
router.get('/pending-actions', async (req: Request, res: Response) => {
  try {
    const [kycRes, auctionsRes, disputesRes, escrowRes] = await Promise.all([
      query<any[]>("SELECT COUNT(*) as count FROM kyc_verifications WHERE status = 'pending'").catch(() => [{ count: 0 }]),
      query<any[]>("SELECT COUNT(*) as count FROM auctions WHERE auction_status IN ('pending', 'draft')").catch(() => [{ count: 0 }]),
      query<any[]>("SELECT COUNT(*) as count FROM disputes WHERE status IN ('open', 'under_review', 'pending')").catch(() => [{ count: 0 }]),
      query<any[]>("SELECT COUNT(*) as count FROM orders WHERE payment_status = 'paid' AND escrow_released_at IS NULL AND order_status != 'cancelled'").catch(() => [{ count: 0 }]),
    ]);
    sendSuccess(res, {
      kyc_pending: Number(kycRes[0]?.count || 0),
      auctions_pending: Number(auctionsRes[0]?.count || 0),
      disputes_open: Number(disputesRes[0]?.count || 0),
      escrow_releases_pending: Number(escrowRes[0]?.count || 0),
    });
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 21. RECENT ACTIVITY & SLA ALERTS ───────────────────────
router.get('/activity/recent', async (req: Request, res: Response) => {
  try {
    let limit = parseInt(req.query.limit as string, 10);
    if (isNaN(limit) || limit <= 0) limit = 20;
    if (limit > 50) limit = 50;
    const rows = await query<any[]>(
      `SELECT a.id, COALESCE(a.action_type, a.action) as action_type, a.target_type, a.target_id, a.meta, a.created_at,
              COALESCE(u.full_name, 'Admin') as admin_name
       FROM admin_activity_log a LEFT JOIN users u ON u.user_id = a.admin_id
       ORDER BY a.created_at DESC LIMIT ?`,
      [limit]
    ).catch(() => []);
    const formatted = rows.map((r) => {
      let parsedMeta = r.meta;
      if (typeof r.meta === 'string') { try { parsedMeta = JSON.parse(r.meta); } catch { } }
      return { id: r.id, action_type: r.action_type, target_type: r.target_type, target_id: r.target_id, meta: parsedMeta, created_at: r.created_at, admin_name: r.admin_name };
    });
    sendSuccess(res, formatted);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.get('/alerts', async (req: Request, res: Response) => {
  try {
    const [kycRows, auctionRows] = await Promise.all([
      query<any[]>("SELECT COUNT(*) as count FROM kyc_verifications WHERE status = 'pending' AND created_at < NOW() - INTERVAL 48 HOUR").catch(() => [{ count: 0 }]),
      query<any[]>("SELECT COUNT(*) as count FROM auctions WHERE auction_status = 'pending' AND created_at < NOW() - INTERVAL 24 HOUR").catch(() => [{ count: 0 }]),
    ]);
    const alerts: any[] = [];
    const kycCount = Number(kycRows[0]?.count || 0);
    const auctionCount = Number(auctionRows[0]?.count || 0);
    if (kycCount > 0) alerts.push({ id: 'kyc_pending_48h', severity: 'warning', message: `${kycCount} KYC verification${kycCount === 1 ? '' : 's'} pending > 48 hours`, link: '/admin/kyc.html', count: kycCount });
    if (auctionCount > 0) alerts.push({ id: 'auctions_pending_24h', severity: 'info', message: `${auctionCount} auction${auctionCount === 1 ? '' : 's'} pending review > 24 hours`, link: '/admin/auctions.html', count: auctionCount });
    sendSuccess(res, alerts);
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 22. GLOBAL SEARCH ──────────────────────────────────────
router.get('/search', async (req: Request, res: Response) => {
  try {
    const q = typeof req.query.q === 'string' ? req.query.q.trim() : '';
    if (!q || q.length < 2) { sendSuccess(res, { users: [], auctions: [], orders: [] }); return; }
    const likeTerm = `%${q}%`;
    const [users, auctions, orders] = await Promise.all([
      query<any[]>(`SELECT user_id, full_name, email, created_at FROM users WHERE full_name LIKE ? OR email LIKE ? LIMIT 5`, [likeTerm, likeTerm]).catch(() => []),
      query<any[]>(`SELECT auction_id, title, auction_status, created_at FROM auctions WHERE title LIKE ? OR CAST(auction_id AS CHAR) = ? LIMIT 5`, [likeTerm, q]).catch(() => []),
      query<any[]>(`SELECT order_id, order_number, total_amount, payment_status, order_status, created_at FROM orders WHERE order_number LIKE ? LIMIT 5`, [likeTerm]).catch(() => [])
    ]);
    sendSuccess(res, { users, auctions, orders });
  } catch (err: any) { sendError(res, err.message, 500); }
});

// ─── 23. SETTINGS ───────────────────────────────────────────
router.get('/settings', async (req: Request, res: Response) => {
  try {
    const settings = await SettingsService.getAllSettings();
    sendSuccess(res, settings);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.post('/settings', async (req: Request, res: Response) => {
  try {
    const updated = await SettingsService.updateSettings(req.body);
    await logAdminActivity(req, 'SETTINGS_UPDATE', 'system', null, req.body);
    sendSuccess(res, updated, 'Platform settings updated successfully.');
  } catch (err: any) { sendError(res, err.message, 400); }
});

// ─── 24. HEALTH ─────────────────────────────────────────────
router.get('/health', async (req: Request, res: Response) => {
  let dbStatus = 'healthy';
  let dbLatencyMs = 0;
  try { const t0 = Date.now(); await query('SELECT 1'); dbLatencyMs = Date.now() - t0; }
  catch { dbStatus = 'degraded'; }
  const mem = process.memoryUsage();
  sendSuccess(res, {
    database: { status: dbStatus, latency_ms: dbLatencyMs },
    api: { status: 'healthy', uptime_seconds: Math.floor(process.uptime()), memory_mb: Math.round(mem.rss / 1024 / 1024) },
    realtime: { status: 'healthy', active_connections: 0 },
    cron: { status_code: 'ACTIVE' },
  });
});

// ─── 25. REVIEW MODERATION ──────────────────────────────────
router.get('/reviews', async (req: Request, res: Response) => {
  try {
    const filter = (req.query.filter as string) || 'all';
    let whereClause = '';
    if (filter === 'reported') whereClause = 'WHERE r.is_reported = 1';
    const rows = await query<any[]>(
      `SELECT r.*, a.title as auction_title,
              b.full_name as buyer_name, b.email as buyer_email,
              s.full_name as seller_name, s.email as seller_email
       FROM reviews r
       LEFT JOIN auctions a ON a.auction_id = r.auction_id
       LEFT JOIN users b ON b.user_id = r.reviewer_id
       LEFT JOIN users s ON s.user_id = r.seller_id
       ${whereClause}
       ORDER BY r.is_reported DESC, r.created_at DESC LIMIT 100`
    );
    sendSuccess(res, rows);
  } catch (err: any) { sendError(res, err.message, 500); }
});

router.patch('/reviews/:id/moderate', async (req: Request, res: Response) => {
  try {
    const reviewId = parseInt(req.params.id, 10);
    const { action, note } = req.body;
    if (action === 'dismiss') {
      await query('UPDATE reviews SET is_reported = 0, report_reason = NULL WHERE review_id = ?', [reviewId]);
      await logAdminActivity(req, 'REVIEW_REPORT_DISMISSED', 'review', reviewId, { note });
      sendSuccess(res, { moderated: true, action: 'dismissed' }, 'Report dismissed. Review reinstated.');
    } else if (action === 'remove') {
      await query('DELETE FROM reviews WHERE review_id = ?', [reviewId]);
      await logAdminActivity(req, 'REVIEW_REMOVED', 'review', reviewId, { note });
      sendSuccess(res, { moderated: true, action: 'removed' }, 'Review removed successfully.');
    } else {
      sendError(res, "Invalid action. Must be 'dismiss' or 'remove'.", 400);
    }
  } catch (err: any) { sendError(res, err.message, 400); }
});

export default router;