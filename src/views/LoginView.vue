<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({ matricule: '', password: '' })
const loading = ref(false)
const error = ref('')

async function handleLogin() {
  if (!form.matricule || !form.password) {
    error.value = 'Veuillez remplir tous les champs.'
    return
  }
  loading.value = true
  error.value = ''

  try {
    await auth.login({ matricule: form.matricule, password: form.password })
    router.push({ name: auth.firstAccessibleRoute(['dashboard', 'participants', 'quota', 'users']) || 'dashboard' })
  } catch (e) {
    error.value = e.message || 'Identifiants incorrects.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-logo">
        <div class="logo-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
            <polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/>
          </svg>
        </div>
        <span>SENAFOI </span>
      </div>

      <div class="login-header">
        <h1>Connexion</h1>
        <p>Accédez à votre espace de gestion</p>
      </div>

      <form class="login-form" @submit.prevent="handleLogin">
        <div class="field">
          <label for="matricule">Matricule</label>
          <input
            id="matricule"
            v-model="form.matricule"
            type="text"
            placeholder="COM260001"
            autocomplete="username"
            :disabled="loading"
          />
        </div>

        <div class="field">
          <label for="password">Mot de passe</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            placeholder="••••••••"
            autocomplete="current-password"
            :disabled="loading"
          />
        </div>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <button type="submit" class="submit-btn" :disabled="loading">
          <span v-if="!loading">Se connecter</span>
          <span v-else class="spinner" />
        </button>
      </form>
    </div>

    <!-- Background décoratif -->
    <div class="bg-decoration" />
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f7f7f5;
  font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
  position: relative;
  overflow: hidden;
}

.bg-decoration {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(ellipse 700px 500px at 70% 20%, rgba(37,99,235,0.06) 0%, transparent 70%),
    radial-gradient(ellipse 400px 400px at 20% 80%, rgba(37,99,235,0.04) 0%, transparent 70%);
  pointer-events: none;
}

.login-card {
  width: 100%;
  max-width: 380px;
  background: #ffffff;
  border-radius: 20px;
  padding: 36px 32px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.04), 0 8px 32px rgba(0,0,0,0.08);
  border: 1px solid rgba(0,0,0,0.06);
  position: relative;
  z-index: 1;
}

.login-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 28px;
  font-size: 15px;
  font-weight: 600;
  color: #111110;
  letter-spacing: -0.02em;
}

.logo-icon {
  width: 38px;
  height: 38px;
  background: #111110;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.login-header {
  margin-bottom: 28px;
}

.login-header h1 {
  font-size: 22px;
  font-weight: 650;
  color: #111110;
  letter-spacing: -0.03em;
  margin: 0 0 6px;
}

.login-header p {
  font-size: 13.5px;
  color: #6b7280;
  margin: 0;
}

/* Form */
.login-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.field label {
  font-size: 12.5px;
  font-weight: 520;
  color: #374151;
  letter-spacing: -0.01em;
}

.field input {
  height: 42px;
  border: 1px solid rgba(0,0,0,0.12);
  border-radius: 10px;
  padding: 0 14px;
  font-size: 14px;
  font-family: inherit;
  color: #111110;
  background: #fafafa;
  outline: none;
  transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
}

.field input:focus {
  border-color: #2563eb;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.field input:disabled {
  opacity: 0.6;
}

.error-msg {
  font-size: 12.5px;
  color: #dc2626;
  background: rgba(220,38,38,0.06);
  border: 1px solid rgba(220,38,38,0.15);
  padding: 9px 12px;
  border-radius: 8px;
}

.submit-btn {
  height: 44px;
  background: #111110;
  color: white;
  border: none;
  border-radius: 11px;
  font-size: 14px;
  font-weight: 560;
  font-family: inherit;
  cursor: pointer;
  letter-spacing: -0.01em;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.18s, transform 0.12s;
  margin-top: 4px;
}

.submit-btn:hover:not(:disabled) {
  background: #2563eb;
}

.submit-btn:active:not(:disabled) {
  transform: scale(0.98);
}

.submit-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>