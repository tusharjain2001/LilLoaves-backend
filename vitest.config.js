import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    // `describe`/`it`/`expect`/`vi` without importing them, matching the
    // frontend repo's setup so a test reads the same way in both.
    globals: true,
    environment: 'node',
  },
})
