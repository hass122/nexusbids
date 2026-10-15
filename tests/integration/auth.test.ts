import { describe, it, expect, afterAll } from 'vitest';
import { apiPost } from '../helpers/api.js';
import { cleanupTestUser } from '../helpers/db.js';

const createdEmails: string[] = [];

describe('Auth Flow', () => {
    it('registers a new buyer', async () => {
        const email = `auth_test_${Date.now()}@test.com`;
        const phone = `03${Math.floor(100000000 + Math.random() * 900000000)}`; // Unique phone
        createdEmails.push(email);

        const res = await apiPost('/auth/register', {
            full_name: 'Auth Test',
            email,
            password: 'Test@12345',
            phone: phone,
            role: 'user',
        });

        expect(res.status).toBe(201);
        expect(res.data.success).toBe(true);
    });

    it('rejects duplicate email', async () => {
        const email = `dup_test_${Date.now()}@test.com`;
        const phone = `03${Math.floor(100000000 + Math.random() * 900000000)}`;
        createdEmails.push(email);

        await apiPost('/auth/register', {
            full_name: 'First User',
            email,
            password: 'Test@12345',
            phone: phone,
        });

        const res = await apiPost('/auth/register', {
            full_name: 'Duplicate User',
            email,
            password: 'Test@12345',
            phone: `03${Math.floor(100000000 + Math.random() * 900000000)}`, // Different phone
        });

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('already registered');
    });

    it('logs in with valid credentials', async () => {
        const email = `login_test_${Date.now()}@test.com`;
        const phone = `03${Math.floor(100000000 + Math.random() * 900000000)}`;
        createdEmails.push(email);

        await apiPost('/auth/register', {
            full_name: 'Login Test',
            email,
            password: 'Test@12345',
            phone: phone,
        });

        const res = await apiPost('/auth/login', { email, password: 'Test@12345' });

        expect(res.status).toBe(200);
        expect(res.data.data.accessToken).toBeDefined();
        expect(res.data.data.user.email).toBe(email);
    });

    it('rejects wrong password', async () => {
        const email = `wrong_pw_${Date.now()}@test.com`;
        const phone = `03${Math.floor(100000000 + Math.random() * 900000000)}`;
        createdEmails.push(email);

        await apiPost('/auth/register', {
            full_name: 'Wrong PW Test',
            email,
            password: 'Test@12345',
            phone: phone,
        });

        const res = await apiPost('/auth/login', { email, password: 'WrongPass' });

        expect(res.status).toBe(401);
    });
});

afterAll(async () => {
    for (const email of createdEmails) {
        await cleanupTestUser(email);
    }
});