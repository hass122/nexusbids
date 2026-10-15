import { beforeAll, afterAll } from 'vitest';
import { query } from '../apps/api/src/config/db.js';

beforeAll(async () => {
    console.log('🧪 Test suite starting — connecting to DB');
    await query('SELECT 1');
    console.log('✅ DB connected');
});

afterAll(async () => {
    console.log('🧪 Test suite finished');
});