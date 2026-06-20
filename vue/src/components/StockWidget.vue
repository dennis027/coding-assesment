<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  productId:         { type: Number, required: true },
  productName:       { type: String, required: true },
  currentStock:      { type: Number, required: true },
  lowStockThreshold: { type: Number, default: 5 },
})

const emit = defineEmits(['stock-updated'])

// ── State ─────────────────────────────────────────────────────────────────────
const stock    = ref(props.currentStock)
const quantity = ref(0)  // Just the number, no sign
const loading  = ref(false)
const errorMsg = ref('')
const savedMsg = ref('')

// ── Computed ──────────────────────────────────────────────────────────────────
const isLowStock      = computed(() => stock.value > 0 && stock.value <= props.lowStockThreshold)
const isOutOfStock    = computed(() => stock.value === 0)

const wouldGoNegativeOnRemove = computed(() => (stock.value - quantity.value) < 0)

const addDisabled    = computed(() => loading.value || quantity.value <= 0)
const removeDisabled = computed(() => loading.value || quantity.value <= 0 || wouldGoNegativeOnRemove.value)

// ── Methods ───────────────────────────────────────────────────────────────────
function increment() { quantity.value++; clearMsgs() }
function decrement() { 
  if (quantity.value > 0) {
    quantity.value--
    clearMsgs()
  }
}
function onInputChange(e) {
  const val = parseInt(e.target.value, 10)
  quantity.value = isNaN(val) ? 0 : Math.max(0, val)  // Never allow negative input
  clearMsgs()
}
function clearMsgs() { errorMsg.value = ''; savedMsg.value = '' }

// ── Save — PATCH /api/products/{id}/stock ─────────────────────────────────────
async function saveAdjustment(delta) {
  if (delta === 0 || loading.value) return

  loading.value  = true
  errorMsg.value = ''
  savedMsg.value = ''

  const token = localStorage.getItem('silk_token')

  try {
    const res = await fetch(`http://localhost:8000/api/products/${props.productId}/stock`, {
      method:  'PATCH',
      headers: {
        'Content-Type':  'application/json',
        'Accept':        'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({ delta }),
    })

    const data = await res.json()

    if (!res.ok) throw new Error(data.message ?? `Error ${res.status}`)

    stock.value    = data.stock_quantity
    quantity.value = 0
    savedMsg.value = `✓ Stock updated to ${data.stock_quantity} units`
    setTimeout(() => { savedMsg.value = '' }, 2500)

    emit('stock-updated', data.stock_quantity)

  } catch (err) {
    errorMsg.value = err.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}

function onAdd() { saveAdjustment(quantity.value) }
function onRemove() { saveAdjustment(-quantity.value) }
</script>

<template>
  <div class="sw" :class="{ 'sw--low': isLowStock, 'sw--out': isOutOfStock }">

    <!-- Header -->
    <div class="sw__header">
      <h2 class="sw__name">{{ productName }}</h2>
      <span v-if="isOutOfStock"    class="sw__badge sw__badge--out">✕ Out of stock</span>
      <span v-else-if="isLowStock" class="sw__badge sw__badge--low">⚠ Low stock</span>
      <span v-else                 class="sw__badge sw__badge--ok">✓ In stock</span>
    </div>

    <!-- Stock count -->
    <p class="sw__count">
      <span class="sw__count-num">{{ stock }}</span>
      <span class="sw__count-unit"> units on hand</span>
    </p>

    <!-- − number input + -->
    <div class="sw__controls">
      <button
        class="sw__stepper"
        :disabled="loading || quantity === 0"
        aria-label="Decrease quantity"
        @click="decrement"
      >−</button>

      <input
        class="sw__input"
        type="number"
        min="0"
        :value="quantity"
        :disabled="loading"
        placeholder="0"
        aria-label="Quantity to add or remove"
        @input="onInputChange"
      />

      <button
        class="sw__stepper"
        :disabled="loading"
        aria-label="Increase quantity"
        @click="increment"
      >+</button>
    </div>

    <!-- Hint -->
    <p class="sw__hint">
      <span v-if="quantity === 0" class="sw__hint--idle">Enter quantity, then choose Add or Remove</span>
      <span v-else class="sw__hint--active">Ready to adjust by {{ quantity }} units</span>
    </p>

    <!-- Add / Remove buttons -->
    <div class="sw__actions">
      <button
        class="sw__btn sw__btn--add"
        :disabled="addDisabled"
        @click="onAdd"
      >
        <span v-if="loading" class="sw__spin">⟳</span>
        <span v-else>+</span>
        Add {{ quantity }}
      </button>

      <button
        class="sw__btn sw__btn--remove"
        :disabled="removeDisabled"
        @click="onRemove"
      >
        <span v-if="loading" class="sw__spin">⟳</span>
        <span v-else>−</span>
        Remove {{ quantity }}
      </button>
    </div>

    <!-- Warning if removing would go negative -->
    <p v-if="quantity > 0 && wouldGoNegativeOnRemove" class="sw__warning">
      ⚠ Cannot remove {{ quantity }} — only {{ stock }} available
    </p>

    <!-- Success -->
    <p v-if="savedMsg" class="sw__success" role="status">{{ savedMsg }}</p>

    <!-- Error -->
    <p v-if="errorMsg" class="sw__error" role="alert">❌ {{ errorMsg }}</p>

  </div>
</template>

<style scoped>
.sw {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 1.25rem 1.5rem;
  width: 280px;
  transition: border-color .2s, background .2s;
}
.sw--low { border-color: #f59e0b; background: #fffbeb; }
.sw--out { border-color: #ef4444; background: #fff5f5; }

/* Header */
.sw__header { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; margin-bottom: .5rem; }
.sw__name   { font-size: .95rem; font-weight: 600; color: #111827; margin: 0; }

/* Badge */
.sw__badge      { font-size: .7rem; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; flex-shrink: 0; }
.sw__badge--ok  { background: #d1fae5; color: #065f46; }
.sw__badge--low { background: #fef3c7; color: #92400e; }
.sw__badge--out { background: #fee2e2; color: #991b1b; }

/* Count */
.sw__count      { margin: .5rem 0 1rem; }
.sw__count-num  { font-size: 2.2rem; font-weight: 700; color: #111827; }
.sw__count-unit { font-size: .82rem; color: #6b7280; }

/* Controls: − [input] + */
.sw__controls { display: flex; gap: .5rem; align-items: center; margin-bottom: .6rem; }

.sw__stepper {
  width: 36px; height: 36px; flex-shrink: 0;
  border: 1px solid #d1d5db; border-radius: 7px;
  background: #f9fafb; font-size: 1.2rem; cursor: pointer;
  transition: background .12s;
  display: flex; align-items: center; justify-content: center;
  font-weight: 600;
}
.sw__stepper:hover:not(:disabled) { background: #e5e7eb; }
.sw__stepper:disabled              { opacity: .4; cursor: not-allowed; }

.sw__input {
  flex: 1; height: 36px; min-width: 0;
  text-align: center;
  border: 1px solid #d1d5db; border-radius: 7px;
  font-size: 1rem; font-weight: 600; padding: 0 .5rem; outline: none;
}
.sw__input:focus    { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .1); }
.sw__input:disabled { opacity: .5; background: #f9fafb; }

/* Hint */
.sw__hint { font-size: .78rem; margin: 0 0 .5rem; min-height: 1.2em; color: #9ca3af; }
.sw__hint--active { color: #2563eb; font-weight: 500; }

/* Warning */
.sw__warning { font-size: .78rem; color: #d97706; margin: 0 0 .5rem; font-weight: 500; }

/* Add / Remove buttons */
.sw__actions { display: flex; gap: .5rem; }
.sw__btn {
  flex: 1; padding: .6rem;
  border: none; border-radius: 7px;
  font-size: .9rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
  display: flex; align-items: center; justify-content: center; gap: 5px;
}
.sw__btn--add    { background: #10b981; color: #fff; }
.sw__btn--add:hover:not(:disabled)    { background: #059669; }
.sw__btn--remove { background: #ef4444; color: #fff; }
.sw__btn--remove:hover:not(:disabled) { background: #dc2626; }
.sw__btn:disabled { opacity: .45; cursor: not-allowed; }

.sw__spin { display: inline-block; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Messages */
.sw__success, .sw__error {
  margin-top: .6rem; font-size: .82rem;
  border-radius: 7px; padding: .5rem .7rem; line-height: 1.5;
}
.sw__success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
.sw__error   { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; }
</style>