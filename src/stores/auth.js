import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const API_BASE_URL = 'https://api.aeemci-ce.ci/senafoi'

export const useAuthStore = defineStore('auth', () => {
  const storedUser = JSON.parse(localStorage.getItem('seminar_user')) || null
  const token = ref(localStorage.getItem('seminar_token') || '')
  if (storedUser && !storedUser.access) {
    localStorage.removeItem('seminar_user')
    localStorage.removeItem('seminar_token')
  }
  const user = ref(storedUser?.access ? storedUser : null)

  const isAuthenticated = computed(() => !!user.value)
  const access = computed(() => user.value?.access || {})

  async function login(credentials) {
    const response = await fetch(`${API_BASE_URL}/senafoi26_users_api.php?action=login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(credentials),
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok || !data.success) {
      throw new Error(data.message || 'Identifiants incorrects.')
    }
    user.value = data.user
    token.value = data.token || ''
    localStorage.setItem('seminar_user', JSON.stringify(data.user))
    localStorage.setItem('seminar_token', token.value)
  }

  function logout() {
    user.value = null
    token.value = ''
    localStorage.removeItem('seminar_user')
    localStorage.removeItem('seminar_token')
  }

  function canView(pageKey) {
    if (!pageKey) return true
    return Boolean(access.value?.[pageKey]?.canView)
  }

  function canEdit(pageKey) {
    if (!pageKey) return false
    return Boolean(access.value?.[pageKey]?.canView && access.value?.[pageKey]?.mode === 'editor')
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

  return { user, token, isAuthenticated, login, logout, canView, canEdit, accessMode, firstAccessibleRoute, authHeaders }
})
