const { test, expect } = require('@playwright/test');

const PASSWORD = 'PartnerOps!2026';

async function login(page) {
  await page.goto('/login');
  await page.getByLabel('電子郵件').fill('client@acme.test');
  await page.getByLabel('密碼').fill(PASSWORD);
  await page.getByRole('button', { name: /進入工作台/ }).click();
  await expect(page).toHaveURL(/\/$/);
}

async function expectNoDocumentOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    documentWidth: document.documentElement.scrollWidth,
    bodyWidth: document.body.scrollWidth,
  }));

  expect(dimensions.viewportWidth).toBe(320);
  expect(Math.max(dimensions.documentWidth, dimensions.bodyWidth)).toBeLessThanOrEqual(dimensions.viewportWidth);
}

test('Acme client completes the responsive request journey at 320px', async ({ page }) => {
  await login(page);
  await expect(page.getByRole('link', { name: '服務請求', exact: true })).toBeVisible();
  await expectNoDocumentOverflow(page);

  await page.getByRole('link', { name: '服務請求', exact: true }).click();
  await expect(page.getByRole('heading', { name: '服務請求' })).toBeVisible();
  await expectNoDocumentOverflow(page);

  await page.getByRole('link', { name: '結帳頁金流間歇失敗' }).click();
  await expect(page.getByRole('heading', { name: '結帳頁金流間歇失敗' })).toBeVisible();
  await expect(page.getByRole('main')).not.toContainText('已定位到支付網關回應超時');
  await expectNoDocumentOverflow(page);

  await page.getByRole('button', { name: '登出' }).click();
  await expect(page).toHaveURL(/\/login$/);
  await expect(page.getByRole('heading', { name: '歡迎回來' })).toBeVisible();
});
