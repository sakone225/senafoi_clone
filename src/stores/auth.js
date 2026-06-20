import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const API_BASE_URL = 'https://api.aeemci-ce.ci/senafoi'
const STORAGE_USER    = 'seminar_user'
const STORAGE_TOKEN   = 'seminar_token'
const STORAGE_HISTORY = 'seminar_login_history'

export const useAuthStore = defineStore('auth', () => {

  // ── Restauration depuis localStorage ──────────────────────────────────
  const storedUser = JSON.parse(localStorage.getItem(STORAGE_USER)) || null
  if (storedUser && !storedUser.access) {
    localStorage.removeItem(STORAGE_USER)
    localStorage.removeItem(STORAGE_TOKEN)
  }

  const user  = ref(storedUser?.access ? storedUser : null)
  const token = ref(localStorage.getItem(STORAGE_TOKEN) || '')

  const isAuthenticated = computed(() => !!user.value)
  const access          = computed(() => user.value?.access || {})

  // ── Historique des connexions ──────────────────────────────────────────
  const loginHistory = ref(
    JSON.parse(localStorage.getItem(STORAGE_HISTORY)) || []
  )

  function _saveHistoryEntry(userData) {
    const entry = {
      id:        Date.now(),
      name:      userData.name      || userData.matricule || 'Inconnu',
      matricule: userData.matricule || '—',
      role:      userData.role      || '—',
      avatar:    _initials(userData.name || userData.matricule || '?'),
      loginAt:   new Date().toISOString(),
    }
    // On garde les 100 dernières connexions
    loginHistory.value = [entry, ...loginHistory.value].slice(0, 100)
    localStorage.setItem(STORAGE_HISTORY, JSON.stringify(loginHistory.value))
  }

  function clearHistory() {
    loginHistory.value = []
    localStorage.removeItem(STORAGE_HISTORY)
  }

  // ── Helpers ────────────────────────────────────────────────────────────
  function _initials(name) {
    if (!name) return '?'
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
  }

  // ── Auth actions ───────────────────────────────────────────────────────
  async function login(credentials) {
    const response = await fetch(
      `${API_BASE_URL}/senafoi26_users_api.php?action=login`,
      {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(credentials),
      }
    )
    const data = await response.json().catch(() => ({}))

    if (!response.ok || !data.success) {
      throw new Error(data.message || 'Identifiants incorrects.')
    }

    user.value  = data.user
    token.value = data.token || ''

    localStorage.setItem(STORAGE_USER,  JSON.stringify(data.user))
    localStorage.setItem(STORAGE_TOKEN, token.value)

    // Enregistrement dans l'historique
    _saveHistoryEntry(data.user)
  }

  function logout() {
    user.value  = null
    token.value = ''
    localStorage.removeItem(STORAGE_USER)
    localStorage.removeItem(STORAGE_TOKEN)
  }

  // ── Permissions ────────────────────────────────────────────────────────
  function canView(pageKey) {
    if (!pageKey) return true
    return Boolean(access.value?.[pageKey]?.canView)
  }

  function canEdit(pageKey) {
    if (!pageKey) return false
    return Boolean(
      access.value?.[pageKey]?.canView &&
      access.value?.[pageKey]?.mode === 'editor'
    )
  }

  function accessMode(pageKey) {
    if (!canView(pageKey)) return 'none'
    return access.value?.[pageKey]?.mode === 'editor' ? 'editor' : 'viewer'
  }

  function firstAccessibleRoute(routeNames = []) {
    return routeNames.find(name => canView(name)) || null
  }

  function authHeaders() {
    return token.value ? { Authorization: `Bearer ${token.value}` } : {}
  }

  return {
    user,
    token,
    isAuthenticated,
    loginHistory,
    login,
    logout,
    clearHistory,
    canView,
    canEdit,
    accessMode,
    firstAccessibleRoute,
    authHeaders,
  }
})