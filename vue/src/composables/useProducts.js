// composables/useProducts.js
// Fetches products from GET /api/products (paginated).
// Creates via POST /api/products.
// Stock update is handled inside StockWidget directly.

import { ref, reactive } from 'vue'
import { useAuth } from './useAuth'

export function useProducts() {
  const { authHeaders, logout } = useAuth()

  const products = ref([])
  const loading  = ref(false)
  const error    = ref('')

  const pagination = reactive({
    currentPage: 1,
    lastPage:    1,
    total:       0,
    perPage:     20,
  })

  // ── GET /api/products?page=N ───────────────────────────────────────────────
  // Response shape: { current_page, data: [...], last_page, per_page, total }
  async function fetchProducts(page = 1) {
    loading.value = true
    error.value   = ''

    try {
      const res = await fetch(`http://localhost:8000/api/products?page=${page}`, {
        headers: authHeaders(),
      })

      if (res.status === 401) {
        // Token expired — force logout so login screen shows
        await logout()
        return
      }

      const data = await res.json()

      if (!res.ok) {
        throw new Error(data.message ?? `Error ${res.status}`)
      }

      // Map API field name (stock_quantity) to what StockWidget expects
      products.value         = data.data
      pagination.currentPage = data.current_page
      pagination.lastPage    = data.last_page
      pagination.total       = data.total
      pagination.perPage     = data.per_page

    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }

  // ── POST /api/products ─────────────────────────────────────────────────────
  // Body: { name, price, category, stock_quantity }
  // Returns { ok, data, errors }
  async function createProduct(payload) {
    const res = await fetch('http://localhost:8000/api/products', {
      method:  'POST',
      headers: authHeaders(),
      body:    JSON.stringify(payload),
    })

    const data = await res.json()

    if (!res.ok) {
      return { ok: false, message: data.message, errors: data.errors ?? {} }
    }

    return { ok: true, data }
  }

  // Update one product's stock_quantity locally after a successful PATCH
  function updateLocalStock(productId, newQty) {
    const p = products.value.find(p => p.id === productId)
    if (p) p.stock_quantity = newQty
  }

  return {
    products,
    loading,
    error,
    pagination,
    fetchProducts,
    createProduct,
    updateLocalStock,
  }
}
