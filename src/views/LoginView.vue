<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth   = useAuthStore()
const router = useRouter()

const form    = reactive({ matricule: '', password: '' })
const loading = ref(false)
const error   = ref('')
const showPwd = ref(false)

async function handleLogin() {
  if (!form.matricule.trim() || !form.password) {
    error.value = 'Veuillez remplir tous les champs.'
    return
  }
  loading.value = true
  error.value   = ''
  try {
    await auth.login({ matricule: form.matricule.trim(), password: form.password })
    router.push({
      name: auth.firstAccessibleRoute(['dashboard', 'participants', 'quota', 'users']) || 'dashboard'
    })
  } catch (e) {
    error.value = e.message || 'Identifiants incorrects.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">

    <!-- Fond décoratif -->
    <div class="bg-blobs" aria-hidden="true">
      <div class="blob blob-1" />
      <div class="blob blob-2" />
      <div class="blob blob-3" />
    </div>

    <div class="login-wrap">

      <!-- Carte -->
      <div class="login-card">

        <!-- Logo -->
        <div class="login-logo">
          <div class="logo-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2">
              <polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/>
            </svg>
          </div>
          <span class="logo-name">AEEMCI - departements</span>
        </div>

        <!-- Titre -->
        <div class="login-header">
          <h1>Bienvenue 👋</h1>
          <p>Connectez-vous à votre espace de gestion</p>
        </div>

        <!-- Formulaire -->
        <form class="login-form" @submit.prevent="handleLogin" novalidate>

          <!-- Matricule -->
          <div class="field">
            <label for="matricule">Matricule</label>
            <div class="input-wrap">
              <span class="input-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
              </span>
              <input
                id="matricule"
                v-model="form.matricule"
                type="text"
                placeholder="ex : COM260001"
                autocomplete="username"
                :disabled="loading"
                @keydown.enter="handleLogin"
              />
            </div>
          </div>

          <!-- Mot de passe -->
          <div class="field">
            <label for="password">Mot de passe</label>
            <div class="input-wrap">
              <span class="input-icon">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
              </span>
              <input
                id="password"
                v-model="form.password"
                :type="showPwd ? 'text' : 'password'"
                placeholder="••••••••"
                autocomplete="current-password"
                :disabled="loading"
              />
              <button
                type="button"
                class="pwd-toggle"
                :aria-label="showPwd ? 'Masquer' : 'Afficher'"
                @click="showPwd = !showPwd"
              >
                <!-- Œil ouvert -->
                <svg v-if="!showPwd" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
                <!-- Œil barré -->
                <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                  <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                  <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
              </button>
            </div>
          </div>

          <!-- Erreur -->
          <Transition name="err">
            <div v-if="error" class="error-msg" role="alert">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              {{ error }}
            </div>
          </Transition>

          <!-- Bouton -->
          <button type="submit" class="submit-btn" :disabled="loading">
            <span v-if="!loading">Se connecter</span>
            <span v-else class="spinner" />
          </button>

        </form>
      </div>

      <!-- Footer -->
      <p class="login-footer">AEEMCI — Gestion des séminaires</p>
    </div>
  </div>
</template>

<style scoped>
/* ── Page ─────────────────────────────────────────────────────────────── */
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f3f4f6;
  font-family: 'SF Pro Text', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  position: relative;
  overflow: hidden;
  padding: 20px;
}

/* Blobs décoratifs */
.bg-blobs { position: absolute; inset: 0; pointer-events: none; }
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.55;
}
.blob-1 {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(99,102,241,0.18), transparent 70%);
  top: -100px; right: -100px;
}
.blob-2 {
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(16,185,129,0.12), transparent 70%);
  bottom: -80px; left: -80px;
}
.blob-3 {
  width: 300px; height: 300px;
  background: radial-gradient(circle, rgba(245,158,11,0.1), transparent 70%);
  top: 50%; left: 50%; transform: translate(-50%,-50%);
}

/* ── Wrapper ──────────────────────────────────────────────────────────── */
.login-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  width: 100%;
  max-width: 400px;
  position: relative;
  z-index: 1;
}

/* ── Carte ────────────────────────────────────────────────────────────── */
.login-card {
  width: 100%;
  background: #ffffff;
  border-radius: 22px;
  padding: 36px 32px;
  box-shadow:
    0 1px 2px rgba(0,0,0,0.04),
    0 4px 16px rgba(0,0,0,0.06),
    0 16px 48px rgba(0,0,0,0.08);
  border: 1px solid rgba(0,0,0,0.06);
}

/* Logo */
.login-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 28px;
}
.logo-icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, #1e1e2e, #6366f1);
  border-radius: 11px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(99,102,241,0.3);
}
.logo-name {
  font-size: 16px;
  font-weight: 700;
  color: #111;
  letter-spacing: -0.03em;
}

/* Header */
.login-header { margin-bottom: 28px; }
.login-header h1 {
  font-size: 24px;
  font-weight: 720;
  color: #111;
  letter-spacing: -0.035em;
  margin: 0 0 6px;
}
.login-header p {
  font-size: 13.5px;
  color: #6b7280;
  margin: 0;
}

/* Form */
.login-form { display: flex; flex-direction: column; gap: 16px; }

.field { display: flex; flex-direction: column; gap: 7px; }
.field label {
  font-size: 12.5px;
  font-weight: 560;
  color: #374151;
  letter-spacing: -0.01em;
}

.input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.input-icon {
  position: absolute;
  left: 13px;
  color: #9ca3af;
  display: flex;
  pointer-events: none;
}
.input-wrap input {
  width: 100%;
  height: 44px;
  border: 1.5px solid rgba(0,0,0,0.1);
  border-radius: 11px;
  padding: 0 40px 0 38px;
  font-size: 14px;
  font-family: inherit;
  color: #111;
  background: #fafafa;
  outline: none;
  transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
}
.input-wrap input:focus {
  border-color: #6366f1;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
}
.input-wrap input:disabled { opacity: 0.55; cursor: not-allowed; }

.pwd-toggle {
  position: absolute;
  right: 12px;
  background: none;
  border: none;
  color: #9ca3af;
  cursor: pointer;
  display: flex;
  padding: 4px;
  border-radius: 5px;
  transition: color 0.15s;
}
.pwd-toggle:hover { color: #6366f1; }

/* Erreur */
.error-msg {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 12.5px;
  color: #dc2626;
  background: rgba(220,38,38,0.06);
  border: 1px solid rgba(220,38,38,0.15);
  padding: 10px 12px;
  border-radius: 9px;
}

/* Bouton */
.submit-btn {
  height: 46px;
  background: linear-gradient(135deg, #1e1e2e, #6366f1);
  color: white;
  border: none;
  border-radius: 12px;
  font-size: 14px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  letter-spacing: -0.01em;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: opacity 0.18s, transform 0.12s, box-shadow 0.18s;
  box-shadow: 0 2px 8px rgba(99,102,241,0.25);
  margin-top: 4px;
}
.submit-btn:hover:not(:disabled) {
  opacity: 0.92;
  box-shadow: 0 4px 16px rgba(99,102,241,0.35);
}
.submit-btn:active:not(:disabled) { transform: scale(0.98); }
.submit-btn:disabled { opacity: 0.55; cursor: not-allowed; }

/* Spinner */
.spinner {
  width: 18px; height: 18px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Footer */
.login-footer {
  font-size: 12px;
  color: #9ca3af;
  text-align: center;
  margin: 0;
}

/* Transitions */
.err-enter-active, .err-leave-active { transition: opacity 0.2s, transform 0.2s; }
.err-enter-from, .err-leave-to { opacity: 0; transform: translateY(-4px); }

/* Mobile */
@media (max-width: 480px) {
  .login-card { padding: 28px 20px; border-radius: 18px; }
  .login-header h1 { font-size: 20px; }
}
</style>