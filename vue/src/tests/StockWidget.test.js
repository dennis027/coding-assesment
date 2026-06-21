import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import StockWidget from '../components/StockWidget.vue'

beforeEach(() => {
  localStorage.setItem('silk_token', 'test-token-123')
})
afterEach(() => {
  localStorage.clear()
  vi.restoreAllMocks()
})

// Mount with the four required spec props
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

// Helper: set the amount input to a value
async function setAmount(wrapper, value) {
  const input = wrapper.find('.sw__input')
  await input.setValue(value)
  await input.trigger('input')
}

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
// Part 2 — Remove blocked when adjustment would go below zero
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 2 — negative-stock guard', () => {
  it('Remove button is disabled when amount exceeds current stock', async () => {
    const w = make({ currentStock: 3 })
    await setAmount(w, 5)   // 3 - 5 = -2 → blocked
    expect(w.find('.sw__btn--remove').attributes('disabled')).toBeDefined()
  })

  it('Remove button is disabled when amount is 0', () => {
    const w = make({ currentStock: 10 })
    // Default amount is 0
    expect(w.find('.sw__btn--remove').attributes('disabled')).toBeDefined()
  })

  it('Add button is disabled when amount is 0', () => {
    const w = make({ currentStock: 10 })
    expect(w.find('.sw__btn--add').attributes('disabled')).toBeDefined()
  })

  it('Remove button is enabled when amount is within stock', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)   // 10 - 5 = 5 → allowed
    expect(w.find('.sw__btn--remove').attributes('disabled')).toBeUndefined()
  })

  it('Remove button is enabled when amount exactly equals stock', async () => {
    const w = make({ currentStock: 5 })
    await setAmount(w, 5)   // 5 - 5 = 0 → allowed (empties to zero)
    expect(w.find('.sw__btn--remove').attributes('disabled')).toBeUndefined()
  })

  it('does NOT fire fetch when Remove is blocked', async () => {
    const spy = vi.spyOn(global, 'fetch')
    const w   = make({ currentStock: 3 })
    await setAmount(w, 10)  // would go negative
    await w.find('.sw__btn--remove').trigger('click')
    expect(spy).not.toHaveBeenCalled()
  })

  it('does NOT fire fetch when Add is blocked (amount = 0)', async () => {
    const spy = vi.spyOn(global, 'fetch')
    const w   = make({ currentStock: 10 })
    // amount stays 0 — both buttons disabled
    await w.find('.sw__btn--add').trigger('click')
    expect(spy).not.toHaveBeenCalled()
  })
})

// ─────────────────────────────────────────────────────────────────────────────
// Part 3 — Successful save emits stock-updated with correct value
// ─────────────────────────────────────────────────────────────────────────────
describe('Part 3 — successful save', () => {
  beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
      ok:   true,
      json: async () => ({ id: 1, name: 'Test Product', stock_quantity: 15 }),
    })
  })

  it('emits stock-updated with the server-returned quantity after Add', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    const emitted = w.emitted('stock-updated')
    expect(emitted).toBeTruthy()
    expect(emitted[0]).toEqual([15])  // server's authoritative value
  })

  it('emits stock-updated with the server-returned quantity after Remove', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok:   true,
      json: async () => ({ id: 1, name: 'Test Product', stock_quantity: 5 }),
    })
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--remove').trigger('click')
    await flush()

    expect(w.emitted('stock-updated')[0]).toEqual([5])
  })

  it('updates the displayed stock count to the server value', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()
    expect(w.find('.sw__count-num').text()).toBe('15')
  })

  it('resets amount to 0 after a successful save', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()
    expect(Number(w.find('.sw__input').element.value)).toBe(0)
  })

  it('sends the correct Bearer token in the Authorization header', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 3)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    const [, options] = global.fetch.mock.calls[0]
    expect(options.headers['Authorization']).toBe('Bearer test-token-123')
  })

  it('sends positive delta when Add is clicked', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 3)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    const [, options] = global.fetch.mock.calls[0]
    expect(JSON.parse(options.body)).toEqual({ delta: 3 })
  })

  it('sends negative delta when Remove is clicked', async () => {
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ id: 1, name: 'Test Product', stock_quantity: 7 }),
    })
    const w = make({ currentStock: 10 })
    await setAmount(w, 3)
    await w.find('.sw__btn--remove').trigger('click')
    await flush()

    const [, options] = global.fetch.mock.calls[0]
    expect(JSON.parse(options.body)).toEqual({ delta: -3 })
  })

  it('calls the correct URL with the product id', async () => {
    const w = make({ currentStock: 10, productId: 42 })
    await setAmount(w, 1)
    await w.find('.sw__btn--add').trigger('click')
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
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    expect(w.find('.sw__error').exists()).toBe(true)
    expect(w.find('.sw__error').text()).toContain('Insufficient stock')
  })

  it('does NOT update the displayed stock count on failure', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    expect(w.find('.sw__count-num').text()).toBe('10')
  })

  it('does NOT emit stock-updated on failure', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    expect(w.emitted('stock-updated')).toBeFalsy()
  })

  it('shows error when fetch itself rejects (network error)', async () => {
    global.fetch = vi.fn().mockRejectedValue(new Error('Network error'))
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    expect(w.find('.sw__error').exists()).toBe(true)
    expect(w.find('.sw__count-num').text()).toBe('10')
  })

  it('re-enables buttons after a failed save', async () => {
    const w = make({ currentStock: 10 })
    await setAmount(w, 5)
    await w.find('.sw__btn--add').trigger('click')
    await flush()

    // loading is false — Add button should be re-enabled (amount is still 5)
    expect(w.find('.sw__btn--add').attributes('disabled')).toBeUndefined()
  })
})