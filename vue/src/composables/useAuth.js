// composables/useAuth.js
// Handles register, login, logout.
// Token stored in localStorage under 'silk_token'.
// Module-level refs — shared across all components that call useAuth().

import { ref, computed } from 'vue'

const TOKEN_KEY    = 'silk_token'
const MERCHANT_KEY = 'silk_merchant'

const token    = ref(localStorage.getItem(TOKEN_KEY) ?? null)
const merchant = ref(JSON.parse(localStorage.getItem(MERCHANT_KEY) ?? 'null'))

export function useAuth() {
  const isLoggedIn = computed(() => token.value !== null)

  // ── Shared headers for every authenticated API call ─────────────────────
  function authHeaders() {
    return {
      'Content-Type':  'application/json',
      'Accept':        'application/json',
      'Authorization': `Bearer ${token.value}`,
    }
  }

  // ── Register ─────────────────────────────────────────────────────────────
  // POST /api/register
  // Body: { name, business_name, email, password }
  // Success: { access_token, token_type, merchant }
  // Error:   { message, errors: { field: [string] } }
  async function register(name, businessName, email, password) {
    const res = await fetch('http://localhost:8000/api/register', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body:    JSON.stringify({
        name,
        business_name: businessName,
        email,
        password,
      }),
    })

    const data = await res.json()

    if (!res.ok) {
      // Return the full error shape so the form can show per-field errors
      return { ok: false, message: data.message, errors: data.errors ?? {} }
    }

    // Persist token and merchant info
    token.value    = data.access_token
    merchant.value = data.merchant
    localStorage.setItem(TOKEN_KEY,    data.access_token)
    localStorage.setItem(MERCHANT_KEY, JSON.stringify(data.merchant))

    return { ok: true }
  }

  // ── Login ─────────────────────────────────────────────────────────────────
  // POST /api/login
  // Body: { email, password }
  // Success: { access_token, token_type }
  // Error 401: { message: "Invalid credentials." }
  async function login(email, password) {
    const res = await fetch('http://localhost:8000/api/login', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body:    JSON.stringify({ email, password }),
    })

    const data = await res.json()

    if (!res.ok) {
      return { ok: false, message: data.message, errors: data.errors ?? {} }
    }

    token.value = data.access_token
    localStorage.setItem(TOKEN_KEY, data.access_token)
    // Login response doesn't return merchant — clear any stale merchant data
    merchant.value = null
    localStorage.removeItem(MERCHANT_KEY)

    return { ok: true }
  }

  // ── Logout ────────────────────────────────────────────────────────────────
  // POST /api/logout  (Bearer token in header)
  // Clears localStorage regardless of API response
  async function logout() {
    try {
      await fetch('http://localhost:8000/api/logout', {
        method:  'POST',
        headers: authHeaders(),
      })
    } catch (_) {
      // Network error on logout — still clear locally
    } finally {
      token.value    = null
      merchant.value = null
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(MERCHANT_KEY)
    }
  }

  return {
    token,
    merchant,
    isLoggedIn,
    authHeaders,
    register,
    login,
    logout,
  }
}
