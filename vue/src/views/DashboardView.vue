<script setup>
import { onMounted, ref } from 'vue'
import { useAuth } from '../composables/useAuth'
import { useProducts } from '../composables/useProducts'
import StockWidget from '../components/StockWidget.vue'

const { logout, merchant } = useAuth()
const {
  products,
  loading,
  error,
  pagination,
  fetchProducts,
  createProduct,
  updateLocalStock,
} = useProducts()

const LOW_STOCK_THRESHOLD = 5

// ── Add product form ──────────────────────────────────────────────────────────
const showForm    = ref(false)
const formLoading = ref(false)
const formMsg     = ref('')
const formErrors  = ref({})
const newProduct  = ref({ name: '', price: '', category: '', stock_quantity: '' })

async function handleAddProduct() {
  formLoading.value = true
  formMsg.value     = ''
  formErrors.value  = {}

  const result = await createProduct({
    name:           newProduct.value.name,
    price:          parseFloat(newProduct.value.price),
    category:       newProduct.value.category,
    stock_quantity: parseInt(newProduct.value.stock_quantity, 10),
  })

  formLoading.value = false

  if (!result.ok) {
    formMsg.value    = result.message ?? 'Failed to add product.'
    formErrors.value = result.errors ?? {}
    return
  }

  products.value.unshift(result.data)
  pagination.total++
  newProduct.value = { name: '', price: '', category: '', stock_quantity: '' }
  showForm.value   = false
}

onMounted(() => fetchProducts(1))
</script>

<template>
  <div class="dash">

    <!-- ── Navbar ────────────────────────────────────────────────────────── -->
    <nav class="navbar">
      <div class="navbar__brand">
        <span class="navbar__logo">🧵</span>
        <span class="navbar__name">SilkCommerce</span>
      </div>

      <div class="navbar__center" v-if="merchant">
        <span class="navbar__biz">{{ merchant.business_name }}</span>
      </div>

      <div class="navbar__right">
        <button class="nav-btn nav-btn--add" @click="showForm = !showForm">
          {{ showForm ? '✕ Cancel' : '+ Add product' }}
        </button>
        <button class="nav-btn nav-btn--logout" @click="logout">
          Sign out
        </button>
      </div>
    </nav>

    <!-- ── Page body ─────────────────────────────────────────────────────── -->
    <div class="page">

      <!-- ── Add product form ────────────────────────────────────────────── -->
      <div v-if="showForm" class="add-form">
        <h2 class="add-form__title">New product</h2>

        <div v-if="formMsg" class="alert alert--error">{{ formMsg }}</div>

        <div class="add-form__grid">
          <div class="field">
            <label class="field__label">Name</label>
            <input
              v-model="newProduct.name"
              class="field__input"
              :class="{ 'field__input--err': formErrors.name }"
              placeholder="Product name"
              :disabled="formLoading"
            />
            <span v-if="formErrors.name" class="field__err">{{ formErrors.name[0] }}</span>
          </div>

          <div class="field">
            <label class="field__label">Price (KES)</label>
            <input
              v-model="newProduct.price"
              type="number" min="0.01" step="0.01"
              class="field__input"
              :class="{ 'field__input--err': formErrors.price }"
              placeholder="0.00"
              :disabled="formLoading"
            />
            <span v-if="formErrors.price" class="field__err">{{ formErrors.price[0] }}</span>
          </div>

          <div class="field">
            <label class="field__label">Category</label>
            <input
              v-model="newProduct.category"
              class="field__input"
              :class="{ 'field__input--err': formErrors.category }"
              placeholder="e.g. Electronics"
              :disabled="formLoading"
            />
            <span v-if="formErrors.category" class="field__err">{{ formErrors.category[0] }}</span>
          </div>

          <div class="field">
            <label class="field__label">Initial stock</label>
            <input
              v-model="newProduct.stock_quantity"
              type="number" min="0" step="1"
              class="field__input"
              :class="{ 'field__input--err': formErrors.stock_quantity }"
              placeholder="0"
              :disabled="formLoading"
            />
            <span v-if="formErrors.stock_quantity" class="field__err">{{ formErrors.stock_quantity[0] }}</span>
          </div>
        </div>

        <button
          class="btn btn--primary"
          :disabled="formLoading || !newProduct.name || !newProduct.price || !newProduct.category || newProduct.stock_quantity === ''"
          @click="handleAddProduct"
        >
          <span v-if="formLoading" class="spinner">⟳</span>
          {{ formLoading ? 'Saving…' : 'Save product' }}
        </button>
      </div>

      <!-- ── Stats bar ───────────────────────────────────────────────────── -->
      <div v-if="!loading && !error && products.length" class="stats">
        <span class="stats__chip">
          {{ pagination.total }} product{{ pagination.total !== 1 ? 's' : '' }}
        </span>
        <span
          v-if="products.filter(p => p.stock_quantity <= LOW_STOCK_THRESHOLD && p.stock_quantity > 0).length"
          class="stats__chip stats__chip--warn"
        >
          ⚠ {{ products.filter(p => p.stock_quantity <= LOW_STOCK_THRESHOLD && p.stock_quantity > 0).length }} low stock
        </span>
        <span
          v-if="products.filter(p => p.stock_quantity === 0).length"
          class="stats__chip stats__chip--danger"
        >
          ✕ {{ products.filter(p => p.stock_quantity === 0).length }} out of stock
        </span>
      </div>

      <!-- ── Loading ─────────────────────────────────────────────────────── -->
      <div v-if="loading" class="state">
        <span class="spinner">⟳</span> Loading products…
      </div>

      <!-- ── Error ───────────────────────────────────────────────────────── -->
      <div v-else-if="error" class="state state--error" role="alert">
        {{ error }}
        <button class="retry" @click="fetchProducts(pagination.currentPage)">Retry</button>
      </div>

      <!-- ── Empty ───────────────────────────────────────────────────────── -->
      <div v-else-if="!products.length" class="state state--empty">
        No products yet. Click <strong>+ Add product</strong> above to get started.
      </div>

      <!-- ── Product grid ────────────────────────────────────────────────── -->
      <div v-else class="grid">
        <StockWidget
          v-for="product in products"
          :key="product.id"
          :product-id="product.id"
          :product-name="product.name"
          :current-stock="product.stock_quantity"
          :low-stock-threshold="LOW_STOCK_THRESHOLD"
          @stock-updated="qty => updateLocalStock(product.id, qty)"
        />
      </div>

      <!-- ── Pagination ──────────────────────────────────────────────────── -->
      <nav v-if="pagination.lastPage > 1" class="pager">
        <button
          class="pager__btn"
          :disabled="pagination.currentPage === 1"
          @click="fetchProducts(pagination.currentPage - 1)"
        >← Prev</button>

        <span class="pager__info">
          Page {{ pagination.currentPage }} of {{ pagination.lastPage }}
        </span>

        <button
          class="pager__btn"
          :disabled="pagination.currentPage === pagination.lastPage"
          @click="fetchProducts(pagination.currentPage + 1)"
        >Next →</button>
      </nav>

    </div>
  </div>
</template>

<style scoped>
/* ── Navbar ────────────────────────────────────────────────────────────────── */
.navbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 1.5rem;
  height: 56px;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
  position: sticky;
  top: 0;
  z-index: 10;
  gap: 1rem;
}

.navbar__brand {
  display: flex;
  align-items: center;
  gap: .5rem;
  flex-shrink: 0;
}
.navbar__logo { font-size: 1.5rem; }
.navbar__name { font-size: 1rem; font-weight: 700; color: #111827; }

.navbar__center { flex: 1; text-align: center; }
.navbar__biz    { font-size: .85rem; color: #6b7280; font-weight: 500; }

.navbar__right { display: flex; align-items: center; gap: .5rem; flex-shrink: 0; }

.nav-btn {
  padding: .4rem .9rem;
  border-radius: 7px;
  font-size: .85rem;
  font-weight: 500;
  cursor: pointer;
  border: none;
  transition: background .13s;
}
.nav-btn--add    { background: #2563eb; color: #fff; }
.nav-btn--add:hover    { background: #1d4ed8; }
.nav-btn--logout { background: none; color: #6b7280; border: 1px solid #d1d5db; }
.nav-btn--logout:hover { background: #f3f4f6; }

/* ── Page ──────────────────────────────────────────────────────────────────── */
.page { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }

/* ── Add product form ──────────────────────────────────────────────────────── */
.add-form {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
}
.add-form__title { font-size: 1rem; font-weight: 600; color: #111827; margin-bottom: 1rem; }
.add-form__grid  {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: .85rem;
  margin-bottom: 1rem;
}

.alert { padding: .6rem .85rem; border-radius: 7px; font-size: .85rem; margin-bottom: .85rem; }
.alert--error { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; }

.field         { display: flex; flex-direction: column; gap: 3px; }
.field__label  { font-size: .8rem; font-weight: 500; color: #374151; }
.field__input  {
  padding: .5rem .65rem;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  font-size: .9rem;
  outline: none;
  transition: border-color .15s;
}
.field__input:focus    { border-color: #2563eb; }
.field__input:disabled { opacity: .6; background: #f9fafb; cursor: not-allowed; }
.field__input--err     { border-color: #f87171; }
.field__err  { font-size: .75rem; color: #dc2626; }

.btn { padding: .55rem 1.2rem; border: none; border-radius: 7px; font-size: .9rem; font-weight: 600; cursor: pointer; transition: background .13s; }
.btn--primary { background: #2563eb; color: #fff; }
.btn--primary:hover:not(:disabled) { background: #1d4ed8; }
.btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Stats ─────────────────────────────────────────────────────────────────── */
.stats { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1.25rem; }
.stats__chip         { font-size: .8rem; padding: 3px 10px; border-radius: 999px; background: #f3f4f6; color: #374151; }
.stats__chip--warn   { background: #fef3c7; color: #92400e; }
.stats__chip--danger { background: #fee2e2; color: #991b1b; }

/* ── States ────────────────────────────────────────────────────────────────── */
.state { text-align: center; padding: 3.5rem 1rem; color: #6b7280; font-size: .95rem; }
.state--error { color: #dc2626; }
.state--empty { color: #9ca3af; }

.spinner { display: inline-block; animation: spin .8s linear infinite; margin-right: 4px; }
@keyframes spin { to { transform: rotate(360deg); } }

.retry {
  margin-left: .75rem; padding: .3rem .75rem;
  border: 1px solid #fca5a5; border-radius: 6px;
  background: #fff; color: #dc2626; cursor: pointer; font-size: .85rem;
}

/* ── Grid ──────────────────────────────────────────────────────────────────── */
.grid { display: flex; flex-wrap: wrap; gap: 1.1rem; }

/* ── Pagination ────────────────────────────────────────────────────────────── */
.pager { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 2rem; }
.pager__btn {
  padding: .4rem .9rem; border: 1px solid #d1d5db;
  border-radius: 6px; background: #fff; cursor: pointer; font-size: .88rem;
}
.pager__btn:disabled              { opacity: .4; cursor: not-allowed; }
.pager__btn:hover:not(:disabled)  { background: #f3f4f6; }
.pager__info { font-size: .85rem; color: #6b7280; }
</style>