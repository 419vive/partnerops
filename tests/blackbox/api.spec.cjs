const { test, expect } = require('@playwright/test');

const ACME_TOKEN = 'ptk_demo01.k7s3P2mQ8vN5xR1aC9dF4gH6jL0wY2uB7eT8iO5pZ3A';
const GLOBEX_TOKEN = 'ptk_demo02.s4F8mN2qR6vK1xC9dH5jL0wY3uB7eT8iO5pZ3A6gQ2W';
const RUN_KEY = `${process.env.GITHUB_RUN_ID || 'local'}-${Date.now()}-${process.pid}`;
const IDEMPOTENCY_KEY = `playwright-create-${RUN_KEY}`;
const VALIDATION_KEY = `playwright-invalid-${RUN_KEY}`;

function headers(token, idempotencyKey) {
  return {
    Authorization: `Bearer ${token}`,
    'Content-Type': 'application/json',
    ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
  };
}

test('API creation is idempotent, validated, and client scoped', async ({ request }) => {
  const payload = {
    title: 'Playwright 結帳重複訂單調查',
    description: '手機版送出後產生兩個訂單編號，請協助追查冪等處理流程。',
    priority: 'high',
  };

  const createdResponse = await request.post('/api/v1/requests', {
    headers: headers(ACME_TOKEN, IDEMPOTENCY_KEY),
    data: payload,
  });
  expect(createdResponse.status()).toBe(201);
  expect(createdResponse.headers()['idempotent-replayed']).toBe('false');
  const createdText = await createdResponse.text();
  const created = JSON.parse(createdText);
  expect(created.id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
  expect(created.status).toBe('new');
  expect(created.priority).toBe('high');
  expect(createdResponse.headers().location).toBe(`/api/v1/requests/${created.id}`);

  const replayResponse = await request.post('/api/v1/requests', {
    headers: headers(ACME_TOKEN, IDEMPOTENCY_KEY),
    data: payload,
  });
  expect(replayResponse.status()).toBe(201);
  expect(replayResponse.headers()['idempotent-replayed']).toBe('true');
  expect(await replayResponse.text()).toBe(createdText);
  expect(replayResponse.headers().location).toBe(createdResponse.headers().location);

  const conflictResponse = await request.post('/api/v1/requests', {
    headers: headers(ACME_TOKEN, IDEMPOTENCY_KEY),
    data: { ...payload, priority: 'urgent' },
  });
  expect(conflictResponse.status()).toBe(409);
  expect(conflictResponse.headers()['content-type']).toContain('application/problem+json');
  const conflict = await conflictResponse.json();
  expect(conflict.code).toBe('idempotency_conflict');
  expect(conflict.status).toBe(409);

  const deniedResponse = await request.get(`/api/v1/requests/${created.id}`, {
    headers: headers(GLOBEX_TOKEN),
  });
  expect(deniedResponse.status()).toBe(404);
  expect(deniedResponse.headers()['content-type']).toContain('application/problem+json');
  const deniedText = await deniedResponse.text();
  const denied = JSON.parse(deniedText);
  expect(denied.code).toBe('not_found');
  expect(denied.detail).toBe('The requested resource was not found.');
  expect(deniedText).not.toContain(payload.title);
  expect(deniedText).not.toContain(payload.description);

  const invalidResponse = await request.post('/api/v1/requests', {
    headers: headers(ACME_TOKEN, VALIDATION_KEY),
    data: {
      title: 'x',
      description: 'too short',
      priority: 'critical',
      clientId: '01J00000000000000000000002',
    },
  });
  expect(invalidResponse.status()).toBe(422);
  expect(invalidResponse.headers()['content-type']).toContain('application/problem+json');
  const invalid = await invalidResponse.json();
  expect(invalid.code).toBe('validation_failed');
  expect(invalid.errors).toHaveLength(4);
  expect(invalid.errors.map(({ field }) => field)).toEqual(
    expect.arrayContaining(['clientId', 'title', 'description', 'priority']),
  );
});
