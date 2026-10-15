import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { apiPost, createTestUser } from '../helpers/api.js';
import { cleanupTestUser } from '../helpers/db.js';
import { query } from '../../apps/api/src/config/db.js';

let user1: any;
let user2: any;
const testCnic = '35201-1234567-1';
const testCnic2 = '42101-7654321-9';

beforeAll(async () => {
    user1 = await createTestUser('user');
    user2 = await createTestUser('user');
});

describe('KYC Flow', () => {
    it('rejects KYC with invalid CNIC format', async () => {
        const res = await apiPost('/kyc/buyer', {
            full_name_on_cnic: 'Test User',
            cnic_number: '12345', // Invalid length
            date_of_birth: '1990-01-01',
            cnic_front_path: 'uploads/front.jpg',
            cnic_back_path: 'uploads/back.jpg',
            selfie_path: 'uploads/selfie.jpg',
            selfie_with_cnic_path: 'uploads/selfie_cnic.jpg',
        }, user1.token);

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('13 digits');
    });

    it('rejects KYC if images are missing', async () => {
        const res = await apiPost('/kyc/buyer', {
            full_name_on_cnic: 'Test User',
            cnic_number: testCnic,
            date_of_birth: '1990-01-01',
            // Missing images
        }, user1.token);

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('All four document images are required');
    });

    it('successfully submits valid KYC for user1', async () => {
        const res = await apiPost('/kyc/buyer', {
            full_name_on_cnic: 'Test User One',
            cnic_number: testCnic,
            date_of_birth: '1990-01-01',
            cnic_front_path: 'uploads/front.jpg',
            cnic_back_path: 'uploads/back.jpg',
            selfie_path: 'uploads/selfie.jpg',
            selfie_with_cnic_path: 'uploads/selfie_cnic.jpg',
        }, user1.token);

        expect(res.status).toBe(201);
        expect(res.data.data.kyc_status).toBe('pending');

        // Verify DB
        const dbUser = await query<any[]>('SELECT kyc_status FROM users WHERE user_id = ?', [user1.userId]);
        expect(dbUser[0].kyc_status).toBe('pending');
    });

    it('blocks duplicate CNIC submission by another user', async () => {
        // user2 tries to submit the SAME CNIC as user1
        const res = await apiPost('/kyc/buyer', {
            full_name_on_cnic: 'Fake User',
            cnic_number: testCnic, // Same as user1
            date_of_birth: '1995-05-05',
            cnic_front_path: 'uploads/front.jpg',
            cnic_back_path: 'uploads/back.jpg',
            selfie_path: 'uploads/selfie.jpg',
            selfie_with_cnic_path: 'uploads/selfie_cnic.jpg',
        }, user2.token);

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('already registered with another account');
    });

    it('blocks re-submission if KYC is already pending', async () => {
        const res = await apiPost('/kyc/buyer', {
            full_name_on_cnic: 'Test User One',
            cnic_number: testCnic2, // Different CNIC
            date_of_birth: '1990-01-01',
            cnic_front_path: 'uploads/front.jpg',
            cnic_back_path: 'uploads/back.jpg',
            selfie_path: 'uploads/selfie.jpg',
            selfie_with_cnic_path: 'uploads/selfie_cnic.jpg',
        }, user1.token);

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('currently under review');
    });
});

afterAll(async () => {
    // Cleanup KYC records and users
    await query('DELETE FROM kyc_verifications WHERE user_id IN (?, ?)', [user1.userId, user2.userId]).catch(() => { });
    if (user1?.email) await cleanupTestUser(user1.email);
    if (user2?.email) await cleanupTestUser(user2.email);
});