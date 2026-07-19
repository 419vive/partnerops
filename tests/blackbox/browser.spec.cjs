const { test, expect } = require('@playwright/test');

const PASSWORD = 'PartnerOps!2026';
const ACME_REQUEST_ID = '01J00000000000000000000009';
const GLOBEX_REQUEST_ID = '01J00000000000000000000011';
const INTERNAL_UPDATE = 'Playwright：已完成付款失敗重現，僅供團隊追蹤。';

async function login(page, email) {
  await page.goto('/login');
  await page.getByLabel('電子郵件').fill(email);
  await page.getByLabel('密碼').fill(PASSWORD);
  await page.getByRole('button', { name: /進入工作台/ }).click();
  await expect(page).toHaveURL(/\/$/);
}

test('team member completes the urgent request workflow', async ({ page }) => {
  await login(page, 'agent@partnerops.test');
  await page.goto(`/requests/${ACME_REQUEST_ID}`);
  await expect(page.getByRole('heading', { name: '結帳頁金流間歇失敗' })).toBeVisible();

  const assignee = page.getByLabel('負責人');
  await assignee.selectOption({ label: '林顧問' });
  await page.getByRole('button', { name: '儲存工作安排' }).click();
  await expect(page.getByRole('status')).toContainText('工作安排已更新');
  await expect(page.getByRole('article').filter({ hasText: '更新負責人' })).toBeVisible();

  await page.getByRole('button', { name: '變更為處理中' }).click();
  await expect(page.getByRole('status')).toContainText('請求狀態已更新');
  await expect(page.getByRole('article').filter({ hasText: '狀態由「新建」變更為「處理中」' })).toBeVisible();

  await page.getByLabel('留言').fill(INTERNAL_UPDATE);
  await page.getByLabel('僅團隊內部可見').check();
  await page.getByRole('button', { name: '新增留言' }).click();
  await expect(page.getByRole('status')).toContainText('留言已新增');
  const update = page.getByRole('article').filter({ hasText: INTERNAL_UPDATE });
  await expect(update).toBeVisible();
  await expect(update).toContainText('內部');
});

test('Acme client receives a generic not-found response for Globex work', async ({ page }) => {
  await login(page, 'client@acme.test');

  const response = await page.goto(`/requests/${GLOBEX_REQUEST_ID}`);
  expect(response).not.toBeNull();
  expect(response.status()).toBe(404);
  const body = await page.content();
  expect(body).not.toContain('Globex 創意');
  expect(body).not.toContain('首頁標題文案更新');
  expect(body).not.toContain('請協助將品牌首頁標題更新為七月的新版本文案。');
});
