import { Router } from 'express';
import { AuthController } from '../controllers/auth.controller.js';
import { validateRequest } from '../middleware/validate.js';
import { requireAuth } from '../middleware/auth.js';
import { authRateLimiter } from '../middleware/rateLimit.js';
import {
  registerSchema,
  loginSchema,
  forgotPasswordSchema,
  resetPasswordSchema,
} from '@nexusbids/shared';

const router = Router();

// Public auth endpoints with brute-force / credential-stuffing protection
router.post('/register', authRateLimiter, validateRequest(registerSchema), AuthController.register);
router.post('/login', authRateLimiter, validateRequest(loginSchema), AuthController.login);
router.post('/refresh', AuthController.refreshToken);
router.post('/forgot-password', authRateLimiter, validateRequest(forgotPasswordSchema), AuthController.forgotPassword);
router.post('/reset-password', authRateLimiter, validateRequest(resetPasswordSchema), AuthController.resetPassword);
router.post('/2fa/verify', authRateLimiter, AuthController.verify2Fa);
router.get('/verify-email', AuthController.verifyEmail);

// Google OAuth 2.0 endpoints
router.get('/google', AuthController.googleAuth);
router.get('/google/callback', AuthController.googleCallback);
// Mobile OAuth: Expo app sends Google access_token, backend verifies and returns JWT
router.post('/google/mobile', authRateLimiter, AuthController.googleMobileAuth);


// Authenticated session management endpoints
router.post('/logout', requireAuth, AuthController.logout);
router.post('/logout-all', requireAuth, AuthController.logoutAll);
router.get('/me', requireAuth, AuthController.getMe);
router.patch('/profile', requireAuth, AuthController.updateProfile);
router.post('/change-password', requireAuth, AuthController.changePassword);
router.post('/2fa/setup', requireAuth, AuthController.setup2Fa);
router.post('/2fa/confirm', requireAuth, AuthController.confirm2Fa);
router.post('/2fa/disable', requireAuth, AuthController.disable2Fa);

export default router;
