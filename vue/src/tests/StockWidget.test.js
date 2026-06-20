import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import StockWidget from '../components/StockWidget.vue'

// StockWidget reads the token from localStorage — seed it for every test
beforeEach(() => {
  localStorage.setItem('silk_token', 'test-token-123')
})
afterEach(() => {
  localStorage.clear()
  vi.restoreAllMocks()
})

// Helper: mount with the four required spec props
function make(overrides = {}) {
  return mount(StockWidget, {
    props: {
      productId:         1,
      productName:       'Test Product',
      currentStock:      10,
      lowStockThreshold: 5,
      ...overrides,
    },
  })
}

// Flush all pending promises (async fetch chains)
const flush = () => new Promise(r => setTimeout(r, 0))

// ─────────────────────────────────────────────────────────────────────────────
// Part 1 — Low-stock indicator
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 1 — low-stock indicator', () => {
  it('shows low-stock badge when stock equals the threshold', () => {
    const w = make({ currentStock: 5, lowStockThreshold: 5 })
    expect(w.find('.sw__badge--low').exists()).toBe(true)
  })

  it('shows low-stock badge when stock is below the threshold', () => {
    const w = make({ currentStock: 2, lowStockThreshold: 5 })
    expect(w.find('.sw__badge--low').exists()).toBe(true)
  })

  it('shows out-of-stock badge when stock is zero', () => {
    const w = make({ currentStock: 0, lowStockThreshold: 5 })
    expect(w.find('.sw__badge--out').exists()).toBe(true)
    expect(w.find('.sw__badge--low').exists()).toBe(false)
  })

  it('does NOT show low-stock badge when stock is above threshold', () => {
    const w = make({ currentStock: 10, lowStockThreshold: 5 })
    expect(w.find('.sw__badge--low').exists()).toBe(false)
    expect(w.find('.sw__badge--ok').exists()).toBe(true)
  })

  it('applies sw--low class to card when stock is low', () => {
    const w = make({ currentStock: 3, lowStockThreshold: 5 })
    expect(w.find('.sw').classes()).toContain('sw--low')
  })

  it('applies sw--out class to card when stock is zero', () => {
    const w = make({ currentStock: 0, lowStockThreshold: 5 })
    expect(w.find('.sw').classes()).toContain('sw--out')
  })

  it('applies no colour class when stock is healthy', () => {
    const w = make({ currentStock: 10, lowStockThreshold: 5 })
    expect(w.find('.sw').classes()).not.toContain('sw--low')
    expect(w.find('.sw').classes()).not.toContain('sw--out')
  })
})

// ─────────────────────────────────────────────────────────────────────────────
// Part 2 — Save / decrease blocked when adjustment would go below zero
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 2 — negative-stock guard', () => {
  it('− button is disabled when projected stock would reach zero', async () => {
    const w = make({ currentStock: 1 })
    // One click takes projected to 0 — next click is blocked
    await w.find('[aria-label="Decrease adjustment"]').trigger('click')
    expect(w.find('[aria-label="Decrease adjustment"]').attributes('disabled')).toBeDefined()
  })

  it('Save is disabled when manual input would take stock negative', async () => {
    const w = make({ currentStock: 3 })
    const input = w.find('.sw__input')
    await input.setValue(-10)
    await input.trigger('input')
    expect(w.find('.sw__save').attributes('disabled')).toBeDefined()
  })

  it('Save is disabled when delta is 0 (no change)', () => {
    const w = make({ currentStock: 10 })
    // Default delta is 0
    expect(w.find('.sw__save').attributes('disabled')).toBeDefined()
  })

  it('shows "cannot go below 0" preview text when delta is too large negative', async () => {
    const w = make({ currentStock: 3 })
    const input = w.find('.sw__input')
    await input.setValue(-5)
    await input.trigger('input')
    expect(w.find('.sw__preview--danger').exists()).toBe(true)
    expect(w.find('.sw__preview').text()).toContain('cannot go below 0')
  })

  it('does NOT fire fetch when save is blocked', async () => {
    const spy = vi.spyOn(global, 'fetch')
    const w   = make({ currentStock: 3 })
    const input = w.find('.sw__input')
    await input.setValue(-10)
    await input.trigger('input')
    await w.find('.sw__save').trigger('click')
    expect(spy).not.toHaveBeenCalled()
  })
})

// ─────────────────────────────────────────────────────────────────────────────
// Part 3 — Successful save emits stock-updated with correct value
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 3 — successful save', () => {
  beforeEach(() => {
    // Mock fetch → 200 with the server's authoritative stock_quantity
    global.fetch = vi.fn().mockResolvedValue({
      ok:   true,
      json: async () => ({ id: 1, name: 'Test Product', stock_quantity: 15 }),
    })
  })

  it('emits stock-updated with the server-returned quantity', async () => {
    const w = make({ currentStock: 10 })
    // Click + five times → delta = 5, projected = 15
    for (let i = 0; i < 5; i++) {
      await w.find('[aria-label="Increase adjustment"]').trigger('click')
    }
    await w.find('.sw__save').trigger('click')
    await flush()

    const emitted = w.emitted('stock-updated')
    expect(emitted).toBeTruthy()
    // Must be the server value (15), not the local projection
    expect(emitted[0]).toEqual([15])
  })

  it('updates the displayed stock count to the server value', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()
    expect(w.find('.sw__count-num').text()).toBe('15')
  })

  it('resets delta to 0 after a successful save', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()
    expect(Number(w.find('.sw__input').element.value)).toBe(0)
  })

  it('sends the correct Bearer token in the Authorization header', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    const [, options] = global.fetch.mock.calls[0]
    expect(options.headers['Authorization']).toBe('Bearer test-token-123')
  })

  it('sends delta in the request body', async () => {
    const w = make({ currentStock: 10 })
    for (let i = 0; i < 3; i++) {
      await w.find('[aria-label="Increase adjustment"]').trigger('click')
    }
    await w.find('.sw__save').trigger('click')
    await flush()

    const [, options] = global.fetch.mock.calls[0]
    expect(JSON.parse(options.body)).toEqual({ delta: 3 })
  })

  it('calls the correct URL with the product id', async () => {
    const w = make({ currentStock: 10, productId: 42 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    const [url] = global.fetch.mock.calls[0]
    expect(url).toBe('http://localhost:8000/api/products/42/stock')
  })
})

// ─────────────────────────────────────────────────────────────────────────────
// Part 4 — Failed save: error shown, stock unchanged
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 4 — failed save', () => {
  beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
      ok:   false,
      json: async () => ({ message: 'Insufficient stock. Current: 10, adjustment: -15.' }),
    })
  })

  it('shows the server error message on failure', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    expect(w.find('.sw__error').exists()).toBe(true)
    expect(w.find('.sw__error').text()).toContain('Insufficient stock')
  })

  it('does NOT update the displayed stock count on failure', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    // Must still show original stock, not the projected value
    expect(w.find('.sw__count-num').text()).toBe('10')
  })

  it('does NOT emit stock-updated on failure', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    expect(w.emitted('stock-updated')).toBeFalsy()
  })

  it('shows error when fetch itself rejects (network error)', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('Network error'))

    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    expect(w.find('.sw__error').exists()).toBe(true)
    expect(w.find('.sw__count-num').text()).toBe('10')
  })

  it('re-enables controls after a failed save', async () => {
    const w = make({ currentStock: 10 })
    await w.find('[aria-label="Increase adjustment"]').trigger('click')
    await w.find('.sw__save').trigger('click')
    await flush()

    // loading should be false again — controls usable
    expect(w.find('[aria-label="Increase adjustment"]').attributes('disabled')).toBeUndefined()
  })
})
