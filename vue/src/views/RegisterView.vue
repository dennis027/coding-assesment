<script setup>
import { ref, reactive } from 'vue'
import { useAuth } from '../composables/useAuth'

const emit = defineEmits(['switch-to-login'])

const { register } = useAuth()

const form = reactive({
  name:          '',
  business_name: '',
  email:         '',
  password:      '',
})

const loading  = ref(false)
const message  = ref('')
const fieldErr = reactive({})

async function handleRegister() {
  loading.value = true
  message.value = ''
  Object.keys(fieldErr).forEach(k => delete fieldErr[k])

  const result = await register(
    form.name,
    form.business_name,
    form.email,
    form.password
  )

  loading.value = false

  if (!result.ok) {
    message.value = result.message ?? 'Registration failed.'
    // Per-field errors e.g. { email: ["The email has already been taken."] }
    Object.assign(fieldErr, result.errors)
  }
  // On success isLoggedIn flips — App.vue switches to Dashboard automatically
}
</script>

<template>
  <div class="auth-wrap">
    <div class="auth-card">

      <div class="auth-logo">🧵</div>
      <h1 class="auth-title">SilkCommerce</h1>
      <p class="auth-sub">Create your merchant account</p>

      <!-- Top-level error e.g. "Validation failed." -->
      <div v-if="message" class="alert alert--error" role="alert">
        {{ message }}
      </div>

      <div class="form">

        <div class="field">
          <label class="field__label" for="reg-name">Full name</label>
          <input
            id="reg-name"
            v-model="form.name"
            type="text"
            class="field__input"
            :class="{ 'field__input--error': fieldErr.name }"
            placeholder="Jane Doe"
            :disabled="loading"
          />
          <span v-if="fieldErr.name" class="field__err">{{ fieldErr.name[0] }}</span>
        </div>

        <div class="field">
          <label class="field__label" for="reg-biz">Business name</label>
          <input
            id="reg-biz"
            v-model="form.business_name"
            type="text"
            class="field__input"
            :class="{ 'field__input--error': fieldErr.business_name }"
            placeholder="My Store Ltd"
            :disabled="loading"
          />
          <span v-if="fieldErr.business_name" class="field__err">{{ fieldErr.business_name[0] }}</span>
        </div>

        <div class="field">
          <label class="field__label" for="reg-email">Email</label>
          <input
            id="reg-email"
            v-model="form.email"
            type="email"
            class="field__input"
            :class="{ 'field__input--error': fieldErr.email }"
            placeholder="you@example.com"
            autocomplete="email"
            :disabled="loading"
          />
          <!-- Shows "The email has already been taken." from your API -->
          <span v-if="fieldErr.email" class="field__err">{{ fieldErr.email[0] }}</span>
        </div>

        <div class="field">
          <label class="field__label" for="reg-password">Password</label>
          <input
            id="reg-password"
            v-model="form.password"
            type="password"
            class="field__input"
            :class="{ 'field__input--error': fieldErr.password }"
            placeholder="••••••••"
            autocomplete="new-password"
            :disabled="loading"
            @keydown.enter="handleRegister"
          />
          <span v-if="fieldErr.password" class="field__err">{{ fieldErr.password[0] }}</span>
        </div>

        <button
          class="btn btn--primary"
          :disabled="loading || !form.name || !form.email || !form.password"
          @click="handleRegister"
        >
          <span v-if="loading" class="spinner">⟳</span>
          {{ loading ? 'Creating account…' : 'Create account' }}
        </button>

      </div>

      <p class="auth-switch">
        Already have an account?
        <button class="auth-switch__btn" @click="emit('switch-to-login')">Sign in</button>
      </p>

    </div>
  </div>
</template>

<style scoped>
.auth-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f9fafb;
  padding: 1rem;
}
.auth-card {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 2.5rem 2rem;
  width: 100%;
  max-width: 390px;
  text-align: center;
}
.auth-logo  { font-size: 2.5rem; margin-bottom: .4rem; }
.auth-title { font-size: 1.4rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
.auth-sub   { font-size: .85rem; color: #6b7280; margin-bottom: 1.5rem; }

.alert {
  border-radius: 8px;
  padding: .65rem .9rem;
  font-size: .875rem;
  margin-bottom: 1.1rem;
  text-align: left;
  line-height: 1.5;
}
.alert--error { background: #fef2f2; border: 1px solid #fca5a5; color: #dc2626; }

.form { text-align: left; }
.field         { margin-bottom: 1rem; }
.field__label  { display: block; font-size: .82rem; font-weight: 500; color: #374151; margin-bottom: 4px; }
.field__input  {
  width: 100%; padding: .55rem .75rem;
  border: 1px solid #d1d5db; border-radius: 7px;
  font-size: .95rem; outline: none; transition: border-color .15s;
}
.field__input:focus          { border-color: #2563eb; }
.field__input:disabled       { opacity: .6; background: #f9fafb; cursor: not-allowed; }
.field__input--error         { border-color: #f87171; }
.field__err   { font-size: .78rem; color: #dc2626; margin-top: 3px; display: block; }

.btn {
  width: 100%; padding: .65rem;
  border: none; border-radius: 7px;
  font-size: .95rem; font-weight: 600; cursor: pointer;
  margin-top: .25rem; transition: background .15s;
}
.btn--primary { background: #2563eb; color: #fff; }
.btn--primary:hover:not(:disabled) { background: #1d4ed8; }
.btn:disabled { opacity: .5; cursor: not-allowed; }

.spinner { display: inline-block; animation: spin .8s linear infinite; margin-right: 4px; }
@keyframes spin { to { transform: rotate(360deg); } }

.auth-switch       { margin-top: 1.25rem; font-size: .85rem; color: #6b7280; }
.auth-switch__btn  { background: none; border: none; color: #2563eb; cursor: pointer; font-size: .85rem; padding: 0; font-weight: 500; }
.auth-switch__btn:hover { text-decoration: underline; }
</style>
