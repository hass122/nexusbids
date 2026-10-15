import { Router } from 'express';
import { query } from '../config/db.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { requireAuth, requireRole } from '../middleware/auth.js';

const router = Router();

// GET /api/v1/categories — public
router.get('/', async (req, res) => {
  try {
    const rows = await query<any[]>(
      `SELECT c.category_id, 
              c.category_name AS name, 
              c.category_name,
              c.description, 
              COALESCE(c.category_image, c.icon) AS image, 
              c.status,
              COUNT(a.auction_id) AS active_auction_count
       FROM categories c
       LEFT JOIN auctions a ON a.category_id = c.category_id AND a.auction_status = 'active'
       WHERE c.status = 'active'
       GROUP BY c.category_id
       ORDER BY c.category_name ASC`
    );
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// Admin CRUD
router.post('/', requireAuth, requireRole('admin'), async (req, res) => {
  try {
    const { name, description, image } = req.body;
    if (!name) { sendError(res, 'Category name is required', 400); return; }
    const result = await query<any>(
      "INSERT INTO categories (category_name, description, category_image, status, created_at) VALUES (?, ?, ?, 'active', NOW())",
      [name, description || null, image || null]
    );
    sendSuccess(res, { category_id: result.insertId }, 'Category created', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

router.patch('/:id', requireAuth, requireRole('admin'), async (req, res) => {
  try {
    const { name, description, image, status } = req.body;
    const id = parseInt(req.params.id, 10);
    await query(
      'UPDATE categories SET category_name = COALESCE(?, category_name), description = COALESCE(?, description), category_image = COALESCE(?, category_image), status = COALESCE(?, status) WHERE category_id = ?',
      [name || null, description || null, image || null, status || null, id]
    );
    sendSuccess(res, { updated: true });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

router.delete('/:id', requireAuth, requireRole('admin'), async (req, res) => {
  try {
    const id = parseInt(req.params.id, 10);
    await query("UPDATE categories SET status = 'inactive' WHERE category_id = ?", [id]);
    sendSuccess(res, { deleted: true });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

export default router;
