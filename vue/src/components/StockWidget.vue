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
const delta    = ref(0)
const loading  = ref(false)
const errorMsg = ref('')
const savedMsg = ref('')

// ── Computed ──────────────────────────────────────────────────────────────────
const projectedStock   = computed(() => stock.value + delta.value)
const isLowStock       = computed(() => stock.value > 0 && stock.value <= props.lowStockThreshold)
const isOutOfStock     = computed(() => stock.value === 0)
const wouldGoNegative  = computed(() => projectedStock.value < 0)

// − button disabled when going one more negative would take projected below 0
const decreaseDisabled = computed(() => loading.value || (stock.value + (delta.value - 1)) < 0)
const saveDisabled     = computed(() => loading.value || delta.value === 0 || wouldGoNegative.value)

// ── Methods ───────────────────────────────────────────────────────────────────
function increment() { delta.value++; clearMsgs() }
function decrement() {
  // Allow delta to go negative — that is how stock is removed
  if ((stock.value + (delta.value - 1)) >= 0) {
    delta.value--
    clearMsgs()
  }
}
function onInputChange(e) {
  const val = parseInt(e.target.value, 10)
  delta.value = isNaN(val) ? 0 : val
  clearMsgs()
}
function clearMsgs() { errorMsg.value = ''; savedMsg.value = '' }

// ── Save — PATCH /api/products/{id}/stock ─────────────────────────────────────
async function save() {
  if (saveDisabled.value) return

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
      // delta is positive to add (e.g. 10), negative to subtract (e.g. -5)
      body: JSON.stringify({ delta: delta.value }),
    })

    const data = await res.json()

    if (!res.ok) throw new Error(data.message ?? `Error ${res.status}`)

    stock.value    = data.stock_quantity   // always trust the server value
    delta.value    = 0
    savedMsg.value = `Saved — ${data.stock_quantity} units`
    setTimeout(() => { savedMsg.value = '' }, 2500)

    emit('stock-updated', data.stock_quantity)

  } catch (err) {
    errorMsg.value = err.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
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
        :disabled="decreaseDisabled"
        aria-label="Decrease"
        @click="decrement"
      >−</button>

      <input
        class="sw__input"
        type="number"
        :value="delta"
        :disabled="loading"
        aria-label="Adjustment amount"
        @input="onInputChange"
      />

      <button
        class="sw__stepper"
        :disabled="loading"
        aria-label="Increase"
        @click="increment"
      >+</button>
    </div>

    <!-- What will happen label -->
    <p class="sw__hint">
      <span v-if="delta > 0" class="sw__hint--add">Adding {{ delta }} units</span>
      <span v-else-if="delta < 0" class="sw__hint--remove">Removing {{ Math.abs(delta) }} units</span>
      <span v-else class="sw__hint--idle">Use + or − to adjust, or type a number (negative to remove)</span>
    </p>

    <!-- Result preview -->
    <p v-if="delta !== 0" class="sw__preview" :class="{ 'sw__preview--danger': wouldGoNegative }">
      Result: <strong>{{ projectedStock }}</strong> units
      <span v-if="wouldGoNegative"> — cannot go below 0</span>
    </p>

    <!-- Save -->
    <button class="sw__save" :disabled="saveDisabled" @click="save">
      <span v-if="loading" class="sw__spin">⟳</span>
      {{ loading ? 'Saving…' : 'Save' }}
    </button>

    <!-- Success -->
    <p v-if="savedMsg" class="sw__success" role="status">✓ {{ savedMsg }}</p>

    <!-- Error -->
    <p v-if="errorMsg" class="sw__error" role="alert">{{ errorMsg }}</p>

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
}
.sw__stepper:hover:not(:disabled) { background: #e5e7eb; }
.sw__stepper:disabled              { opacity: .4; cursor: not-allowed; }

.sw__input {
  flex: 1; height: 36px; min-width: 0;
  text-align: center;
  border: 1px solid #d1d5db; border-radius: 7px;
  font-size: 1rem; padding: 0 .5rem; outline: none;
}
.sw__input:focus    { border-color: #2563eb; }
.sw__input:disabled { opacity: .5; background: #f9fafb; }

/* Preview */
.sw__hint { font-size: .78rem; margin: 0 0 .4rem; min-height: 1.2em; }
.sw__hint--idle   { color: #9ca3af; }
.sw__hint--add    { color: #2563eb; font-weight: 500; }
.sw__hint--remove { color: #dc2626; font-weight: 500; }

.sw__preview         { font-size: .83rem; color: #374151; margin: 0 0 .7rem; }
.sw__preview--danger { color: #dc2626; font-weight: 600; }

/* Save */
.sw__save {
  width: 100%; padding: .6rem;
  background: #2563eb; color: #fff;
  border: none; border-radius: 7px;
  font-size: .92rem; font-weight: 600; cursor: pointer;
  transition: background .15s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.sw__save:hover:not(:disabled) { background: #1d4ed8; }
.sw__save:disabled              { opacity: .5; cursor: not-allowed; }

.sw__spin { display: inline-block; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Messages */
.sw__success, .sw__error {
  margin-top: .6rem; font-size: .82rem;
  border-radius: 7px; padding: .45rem .7rem; line-height: 1.5;
}
.sw__success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
.sw__error   { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; }
</style>