import { test, expect, Page } from '@playwright/test';

// The marks grid of the scorebook, as the demo headmaster uses it. The demo is
// the hard case: its demo bar puts other forms on the page ahead of the grid.

const cells = 'input[type=number][name^="scores["]';

/** Open the first class's grid in edit mode, for the period the app picks. */
async function openGrid(page: Page) {
  await page.goto('/demo/headmaster');
  await page.goto('/reporting/grades');
  const href = await page.locator('a[href*="/scorebook/class/"]').first().getAttribute('href');
  const id = href!.match(/scorebook\/class\/(\d+)/)![1];
  await page.goto(`/scorebook/class/${id}/term-grid?edit=1`);
  await expect(page.locator(cells).first()).toBeVisible();
}

/** Would the browser ask before leaving this page right now? */
function asksBeforeLeaving(page: Page) {
  return page.evaluate(() => {
    const e = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(e);
    return e.defaultPrevented;
  });
}

test.describe('Marks grid', () => {

  test('fill example marks fills every open cell within its maximum and saves', async ({ page }) => {
    await openGrid(page);
    await page.locator(cells).evaluateAll((els: HTMLInputElement[]) => els.forEach((el) => { el.value = ''; }));

    await page.getByRole('button', { name: /Fill example marks/ }).click();

    const state = await page.locator(cells).evaluateAll((els: HTMLInputElement[]) => {
      const open = els.filter((el) => !el.disabled);
      return {
        open: open.length,
        filled: open.filter((el) => el.value !== '').length,
        aboveMax: open.filter((el) => Number(el.value) > Number(el.max)).length,
        distinct: new Set(open.map((el) => el.value)).size,
      };
    });
    expect(state.open).toBeGreaterThan(0);
    expect(state.filled).toBe(state.open);
    expect(state.aboveMax).toBe(0);
    expect(state.distinct).toBeGreaterThan(3);

    // The grid saves by itself; the marks are still there after a reload.
    await expect(page.getByText('Saved')).toBeVisible({ timeout: 10_000 });
    await page.reload();
    const kept = await page.locator(cells).evaluateAll((els: HTMLInputElement[]) => els.filter((el) => el.value !== '').length);
    expect(kept).toBe(state.open);
  });

  test('asks before leaving only while a mark is not saved yet', async ({ page }) => {
    await openGrid(page);
    expect(await asksBeforeLeaving(page)).toBe(false);

    await page.locator(cells).first().fill('7');
    expect(await asksBeforeLeaving(page)).toBe(true);

    await expect(page.getByText('Saved')).toBeVisible({ timeout: 10_000 });
    expect(await asksBeforeLeaving(page)).toBe(false);
  });

  test('a mark that fails to save keeps the warning on', async ({ page }) => {
    await openGrid(page);
    await page.route('**/term-grid', (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 500 }) : route.continue()));

    await page.locator(cells).first().fill('7');
    await page.waitForResponse((res) => res.request().method() === 'POST' && res.status() === 500);

    await expect(page.getByText('Saved')).toBeHidden();
    expect(await asksBeforeLeaving(page)).toBe(true);
  });

  test('arrow keys and Enter move between cells', async ({ page }) => {
    await openGrid(page);
    const at = () => page.evaluate(() => {
      const el = document.activeElement as HTMLElement;
      return `${el.dataset.r},${el.dataset.c}`;
    });

    await page.locator(`${cells}[data-r="0"][data-c="0"]`).focus();
    await page.keyboard.press('ArrowDown');
    expect(await at()).toBe('1,0');
    await page.keyboard.press('ArrowRight');
    expect(await at()).toBe('1,1');
    await page.keyboard.press('Enter');
    expect(await at()).toBe('2,1');
    await page.keyboard.press('ArrowUp');
    expect(await at()).toBe('1,1');
  });

  test('a wheel turn over a focused mark takes the focus off it', async ({ page }) => {
    // A focused number field changes its value under the mouse wheel, so the
    // grid lets go of the field and the page scrolls instead. The wheel event
    // is sent by hand: a headless browser does not play a real wheel gesture
    // over a number field the way a desktop browser does.
    await openGrid(page);
    const cell = page.locator(`${cells}[data-r="3"][data-c="1"]`);
    await cell.fill('12');
    await expect(cell).toBeFocused();

    await cell.dispatchEvent('wheel', { deltaY: 120, bubbles: true });

    await expect(cell).not.toBeFocused();
    await expect(cell).toHaveValue('12');
  });

});
