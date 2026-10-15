const API_URL = 'http://localhost:5000/api/v1';

// Generate a unique IP per request so rate limiter doesn't block tests
function uniqueIp(): string {
    return `10.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`;
}

export async function apiPost(path: string, body: any, token?: string) {
    const res = await fetch(`${API_URL}${path}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Forwarded-For': uniqueIp(),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    return { status: res.status, data };
}

export async function apiGet(path: string, token?: string) {
    const res = await fetch(`${API_URL}${path}`, {
        headers: {
            'X-Forwarded-For': uniqueIp(),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });
    const data = await res.json().catch(() => ({}));
    return { status: res.status, data };
}

export async function createTestUser(role: 'user' | 'seller' = 'user') {
    const uniqueId = Date.now() + Math.floor(Math.random() * 1000);
    const email = `test_${role}_${uniqueId}@test.com`;
    const password = 'Test@12345';
    const phone = `03${String(Math.floor(100000000 + Math.random() * 900000000)).slice(0, 9)}`;

    const reg = await apiPost('/auth/register', {
        full_name: `Test ${role} ${uniqueId}`,
        email,
        password,
        phone,
        role,
    });

    if (reg.status !== 201) {
        throw new Error(`Register failed: ${JSON.stringify(reg.data)}`);
    }

    const login = await apiPost('/auth/login', { email, password });
    if (login.status !== 200) {
        throw new Error(`Login failed: ${JSON.stringify(login.data)}`);
    }

    return {
        email,
        password,
        userId: login.data.data.user.user_id,
        token: login.data.data.accessToken,
    };
}