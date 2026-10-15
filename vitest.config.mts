/// <reference types="vitest" />
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        globals: true,
        environment: 'node',
        include: ['tests/integration/**/*.test.ts'],
        testTimeout: 30000,
        hookTimeout: 30000,
        // Use 'threads: false' for older Vitest, or it's safe to just remove pool config if not needed
        pool: 'forks',
    },
});