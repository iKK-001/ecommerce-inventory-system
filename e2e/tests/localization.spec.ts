import { test, expect } from '../fixtures';

test.describe('Localization', () => {
    test('switches core inventory pages to Chinese from the app chrome', async ({ page }) => {
        await page.goto('/dashboard');
        await page.evaluate(() => localStorage.removeItem('locale'));
        await page.reload();

        await page.getByRole('button', { name: /language|english/i }).click();
        await page.getByRole('button', { name: /中文/ }).click();

        await expect(page.getByRole('link', { name: '库存', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: '每周销量', exact: true })).toBeVisible();

        await page.getByRole('link', { name: '每周销量', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'SKU 运营与每周销量' })).toBeVisible();
        await expect(page.getByText('保存本周销量')).toBeVisible();
        await expect(page.getByText('实际在库')).toBeVisible();
        await expect(page.getByText('打包成本')).toBeVisible();

        await page.getByRole('link', { name: '库存', exact: true }).click();
        await expect(page.getByRole('heading', { name: '库存' })).toBeVisible();
        await expect(page.getByRole('link', { name: '添加产品' })).toBeVisible();
    });

    test('account preferences expose Chinese and apply it immediately', async ({ page }) => {
        await page.goto('/settings/account');
        await page.evaluate(() => localStorage.removeItem('locale'));
        await page.reload();

        await page.getByRole('button', { name: /preferences/i }).click();
        await expect(page.locator('#language option[value="zh-CN"]')).toHaveText(/中文/);

        await page.locator('#language').selectOption('zh-CN');

        await expect(page.getByRole('heading', { name: '账户设置' })).toBeVisible();
        await expect.poll(async () => page.evaluate(() => localStorage.getItem('locale'))).toBe('zh-CN');
    });
});
