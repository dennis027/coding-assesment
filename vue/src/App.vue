<script setup>
import { ref, computed } from 'vue'
import { useAuth } from './composables/useAuth'
import LoginView    from './views/LoginView.vue'
import RegisterView from './views/RegisterView.vue'
import DashboardView from './views/DashboardView.vue'

const { isLoggedIn } = useAuth()

// Toggle between login and register — no router needed for this tool
const showRegister = ref(false)
</script>

<template>
  <DashboardView v-if="isLoggedIn" />
  <RegisterView
    v-else-if="showRegister"
    @switch-to-login="showRegister = false"
  />
  <LoginView
    v-else
    @switch-to-register="showRegister = true"
  />
</template>

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: #f9fafb;
  min-height: 100vh;
}
</style>
