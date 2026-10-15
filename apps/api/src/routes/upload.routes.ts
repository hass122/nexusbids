import { Router } from 'express';
import multer from 'multer';
import { requireAuth } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { CloudinaryService } from '../services/cloudinary.service.js';

const router = Router();

// Configure multer with memory storage and size limit (10MB)
const upload = multer({
  storage: multer.memoryStorage(),
  limits: {
    fileSize: 10 * 1024 * 1024, // 10 MB limit
    files: 6,
  },
  fileFilter: (_req, file, cb) => {
    if (file.mimetype.startsWith('image/')) {
      cb(null, true);
    } else {
      cb(new Error('Only image files (JPEG, PNG, WEBP) are allowed.'));
    }
  },
});

// ─── POST /api/v1/upload/image (Single image upload) ───────────
router.post('/image', requireAuth, upload.single('image'), async (req, res) => {
  try {
    if (!req.file) {
      sendError(res, 'No image file provided.', 400);
      return;
    }

    const folder = (req.body.folder as string) || 'nexusbids/auctions';
    const result = await CloudinaryService.uploadBuffer(req.file.buffer, folder, 'img');

    sendSuccess(res, {
      url: result.secure_url || result.url,
      public_id: result.public_id,
      bytes: result.bytes,
    }, 'Image uploaded successfully.');
  } catch (err: any) {
    sendError(res, err.message || 'Image upload failed.', 500);
  }
});

// ─── POST /api/v1/upload/multiple (Multi-image upload for auctions) ─
router.post('/multiple', requireAuth, upload.array('images', 6), async (req, res) => {
  try {
    const files = req.files as Express.Multer.File[];
    if (!files || files.length === 0) {
      sendError(res, 'No image files provided.', 400);
      return;
    }

    const folder = (req.body.folder as string) || 'nexusbids/auctions';
    const uploadPromises = files.map((file, idx) =>
      CloudinaryService.uploadBuffer(file.buffer, folder, `auction_${idx}`)
    );

    const results = await Promise.all(uploadPromises);

    sendSuccess(
      res,
      {
        images: results.map((r) => ({
          url: r.secure_url || r.url,
          public_id: r.public_id,
        })),
        count: results.length,
      },
      `${results.length} images uploaded successfully.`
    );
  } catch (err: any) {
    sendError(res, err.message || 'Failed to upload images.', 500);
  }
});

// ─── POST /api/v1/upload/base64 (Base64 image upload for KYC / Camera) ─
router.post('/base64', requireAuth, async (req, res) => {
  try {
    const { image, folder } = req.body;
    if (!image || typeof image !== 'string') {
      sendError(res, 'Missing base64 image string.', 400);
      return;
    }

    const result = await CloudinaryService.uploadBase64(
      image,
      folder || 'nexusbids/kyc'
    );

    sendSuccess(res, {
      url: result.secure_url || result.url,
      public_id: result.public_id,
    }, 'Base64 image uploaded successfully.');
  } catch (err: any) {
    sendError(res, err.message || 'Failed to process base64 upload.', 500);
  }
});

export default router;
