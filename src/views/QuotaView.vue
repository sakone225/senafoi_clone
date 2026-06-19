<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const API_URL = 'https://api.aeemci-ce.ci/senafoi/quota_api.php'
const YEAR = 2026

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toastMsg = ref('')
const toastType = ref('success')
const refreshTimer = ref(null)

const quota = ref({
  annee_seminaire: YEAR,
  quota_total: 0,
  inscriptions_count: 0,
  places_disponibles: 0,
  is_full: false,
  refreshed_at: '',
})

const form = ref({
  quota_total: '',
  increment: '',
})

const quotaTotal = computed(() => Number(quota.value.quota_total) || 0)
const inscrits = computed(() => Number(quota.value.inscriptions_count) || 0)
const places = computed(() => Math.max(0, Number(quota.value.places_disponibles) || 0))
const usedPct = computed(() => {
  if (!quotaTotal.value) return 0
  return Math.min(100, Math.round((inscrits.value / quotaTotal.value) * 100))
})
const hasQuota = computed(() => quotaTotal.value > 0)
const statusLabel = computed(() => {
  if (!hasQuota.value) return 'A configurer'
  return places.value > 0 ? 'Ouvert' : 'Complet'
})
const statusClass = computed(() => {
  if (!hasQuota.value) return 'pill-muted'
  return places.value > 0 ? 'pill-ok' : 'pill-danger'
})
const lastRefresh = computed(() => {
  if (!quota.value.refreshed_at) return 'Non synchronise'
  return new Date(quota.value.refreshed_at).toLocaleTimeString('fr-FR', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
})

async function parseResponse(res) {
  const text = await res.text()
  let data
  try {
    data = JSON.parse(text)
  } catch {
    throw new Error(`Reponse non JSON : ${text.slice(0, 120)}`)
  }
  if (!res.ok || !data.success) {
    throw new Error(data.message || data.error || `HTTP ${res.status}`)
  }
  return data
}

async function fetchQuota(showLoader = false) {
  if (showLoader) loading.value = true
  error.value = ''
  try {
    const res = await fetch(`${API_URL}?action=get_quota&annee=${YEAR}&rand=${Date.now()}`)
    const data = await parseResponse(res)
    quota.value = data.data || quota.value
    if (!form.value.quota_total && Number(data.data?.quota_total)) {
      form.value.quota_total = String(data.data.quota_total)
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function saveQuotaTotal() {
  const value = Number(form.value.quota_total)
  if (!Number.isFinite(value) || value < 0) {
    toast('Entrez un quota total valide.', 'error')
    return
  }
  await mutateQuota('set_quota', { quota_total: Math.round(value) }, 'Quota total enregistre')
}

async function incrementQuota() {
  const value = Number(form.value.increment)
  if (!Number.isFinite(value) || value < 1) {
    toast('Entrez une augmentation valide.', 'error')
    return
  }
  await mutateQuota('increment_quota', { increment: Math.round(value) }, `Quota augmente de ${Math.round(value)}`)
  form.value.increment = ''
}

async function mutateQuota(action, payload, successMessage) {
  saving.value = true
  try {
    const res = await fetch(`${API_URL}?action=${action}&annee=${YEAR}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    await parseResponse(res)
    toast(successMessage)
    await fetchQuota()
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

function toast(message, type = 'success') {
  toastMsg.value = message
  toastType.value = type
  window.setTimeout(() => {
    if (toastMsg.value === message) toastMsg.value = ''
  }, 3200)
}

onMounted(async () => {
  await fetchQuota(true)
  refreshTimer.value = window.setInterval(() => fetchQuota(false), 20000)
})

onUnmounted(() => {
  if (refreshTimer.value) window.clearInterval(refreshTimer.value)
})
</script>

<template>
  <div class="page">
    <div class="breadcrumb">
      <span class="bc-root">Administration</span>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
      <span class="bc-active">Quota SENAFOI {{ YEAR }}</span>
    </div>

    <div v-if="loading" class="state-block">
      <div class="spinner"></div>
      <p>Chargement du quota...</p>
    </div>

    <div v-else-if="error" class="state-block state-error">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <p>Impossible de charger le quota</p>
      <code>{{ error }}</code>
      <button class="btn-outline" @click="fetchQuota(true)">Reessayer</button>
    </div>

    <template v-else>
      <div class="page-header">
        <div>
          <h1 class="page-title">Quota SENAFOI {{ YEAR }}</h1>
          <p class="page-sub">Suivi des places disponibles et gestion du quota d'inscription.</p>
        </div>
        <div class="header-actions">
          <span class="status-pill" :class="statusClass">{{ statusLabel }}</span>
          <button class="btn-outline" @click="fetchQuota(true)" :disabled="saving">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
            Actualiser
          </button>
        </div>
      </div>

      <section class="quota-hero" :class="{ 'quota-hero-full': hasQuota && places === 0 }">
        <div class="quota-main">
          <span class="quota-kicker">Places disponibles</span>
          <strong>{{ places.toLocaleString('fr-FR') }}</strong>
          <small>sur {{ quotaTotal.toLocaleString('fr-FR') }} places configurees</small>
        </div>
        <div class="quota-side">
          <div class="meter-head">
            <span>Taux d'occupation</span>
            <strong>{{ usedPct }}%</strong>
          </div>
          <div class="meter">
            <span :style="{ width: `${usedPct}%` }"></span>
          </div>
          <p>{{ inscrits.toLocaleString('fr-FR') }} inscription{{ inscrits > 1 ? 's' : '' }} valide{{ inscrits > 1 ? 's' : '' }} Wave pour {{ YEAR }}</p>
        </div>
      </section>

      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-icon kpi-blue">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ quotaTotal.toLocaleString('fr-FR') }}</span>
            <span class="kpi-label">Quota total</span>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon kpi-green">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ inscrits.toLocaleString('fr-FR') }}</span>
            <span class="kpi-label">Inscriptions validees 2026</span>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon kpi-amber">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ lastRefresh }}</span>
            <span class="kpi-label">Derniere synchro</span>
          </div>
          <span class="kpi-trend kpi-neutral">20 sec</span>
        </div>
      </div>

      <div class="forms-grid">
        <section class="panel">
          <div class="panel-head">
            <div>
              <h2>{{ hasQuota ? 'Modifier le quota total' : 'Creer le premier quota' }}</h2>
              <p>Definit le nombre total de places disponibles pour {{ YEAR }}.</p>
            </div>
          </div>

          <label class="field">
            <span>Quota total</span>
            <input v-model="form.quota_total" type="number" min="0" inputmode="numeric" placeholder="Ex : 500" />
          </label>

          <button class="btn-primary wide" :disabled="saving" @click="saveQuotaTotal">
            <span v-if="saving" class="mini-spinner"></span>
            {{ hasQuota ? 'Enregistrer le quota' : 'Creer le quota' }}
          </button>
        </section>

        <section class="panel">
          <div class="panel-head">
            <div>
              <h2>Augmenter le quota</h2>
              <p>Ajoute des places au quota actuel sans recalcul manuel.</p>
            </div>
          </div>

          <label class="field">
            <span>Places a ajouter</span>
            <input v-model="form.increment" type="number" min="1" inputmode="numeric" placeholder="Ex : 50" />
          </label>

          <button class="btn-primary wide" :disabled="saving || !hasQuota" @click="incrementQuota">
            <span v-if="saving" class="mini-spinner"></span>
            Augmenter le quota
          </button>
        </section>
      </div>
    </template>

    <Transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType">
        {{ toastMsg }}
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.page {
  margin: -15px;
  color: var(--text);
}

.breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 16px;
  font-size: 12px;
  color: var(--text-2);
}

.bc-root {
  font-weight: 600;
  color: var(--text);
}

.bc-active {
  color: var(--text-2);
}

.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}

.page-title {
  font-size: 22px;
  line-height: 1.15;
  font-weight: 680;
  letter-spacing: -0.03em;
}

.page-sub {
  margin-top: 5px;
  color: var(--text-2);
  font-size: 13px;
}

.header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.quota-hero {
  display: grid;
  grid-template-columns: minmax(240px, 360px) 1fr;
  gap: 18px;
  padding: 20px;
  margin-bottom: 16px;
  border: 1px solid rgba(37,99,235,.18);
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(37,99,235,.11), #fff 58%);
  box-shadow: var(--shadow);
}

.quota-hero-full {
  border-color: rgba(220,38,38,.18);
  background: linear-gradient(135deg, rgba(220,38,38,.1), #fff 58%);
}

.quota-main {
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.quota-kicker {
  font-size: 12px;
  font-weight: 700;
  color: var(--accent);
  text-transform: uppercase;
}

.quota-main strong {
  margin-top: 6px;
  font-size: 72px;
  line-height: .9;
  font-weight: 780;
  letter-spacing: -0.06em;
}

.quota-main small {
  margin-top: 10px;
  color: var(--text-2);
  font-size: 13px;
}

.quota-side {
  align-self: end;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: rgba(255,255,255,.78);
}

.meter-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
  font-size: 13px;
  color: var(--text-2);
}

.meter-head strong {
  color: var(--text);
}

.meter {
  height: 10px;
  border-radius: 999px;
  background: rgba(0,0,0,.06);
  overflow: hidden;
}

.meter span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  transition: width var(--t);
}

.quota-side p {
  margin-top: 10px;
  font-size: 12px;
  color: var(--text-2);
}

.kpi-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.kpi-card {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 82px;
  padding: 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow);
}

.kpi-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.kpi-blue { background: #eff6ff; color: #2563eb; }
.kpi-green { background: #ecfdf5; color: #059669; }
.kpi-amber { background: #fffbeb; color: #d97706; }

.kpi-data {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.kpi-val {
  font-size: 20px;
  font-weight: 700;
  letter-spacing: -0.03em;
}

.kpi-label {
  font-size: 12px;
  color: var(--text-2);
}

.kpi-trend {
  margin-left: auto;
  border-radius: 999px;
  padding: 4px 7px;
  font-size: 11px;
  font-weight: 650;
}

.kpi-neutral {
  background: var(--bg);
  color: var(--text-2);
}

.forms-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.panel {
  padding: 16px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow);
}

.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.panel h2 {
  font-size: 15px;
  font-weight: 670;
  letter-spacing: -0.02em;
}

.panel p {
  margin-top: 4px;
  color: var(--text-2);
  font-size: 12.5px;
  line-height: 1.45;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 12px;
}

.field span {
  font-size: 12px;
  font-weight: 650;
  color: var(--text);
}

.field input {
  height: 38px;
  padding: 0 11px;
  border: 1px solid var(--border);
  border-radius: 9px;
  background: #fff;
  color: var(--text);
  font-size: 13px;
  outline: none;
  transition: border-color var(--t), box-shadow var(--t);
}

.field input:focus {
  border-color: rgba(var(--accent-rgb), .55);
  box-shadow: 0 0 0 3px rgba(var(--accent-rgb), .12);
}

.btn-primary,
.btn-outline {
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0 12px;
  border-radius: 9px;
  font-size: 12.5px;
  font-weight: 650;
  cursor: pointer;
  transition: background var(--t), color var(--t), border-color var(--t), opacity var(--t);
}

.btn-primary {
  background: var(--accent);
  border: 1px solid var(--accent);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.btn-outline {
  background: #fff;
  border: 1px solid var(--border);
  color: var(--text);
}

.btn-outline:hover:not(:disabled) {
  background: var(--bg);
}

.btn-primary:disabled,
.btn-outline:disabled {
  opacity: .55;
  cursor: not-allowed;
}

.wide {
  width: 100%;
}

.status-pill {
  height: 28px;
  display: inline-flex;
  align-items: center;
  padding: 0 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
}

.pill-ok { background: #ecfdf5; color: #047857; }
.pill-danger { background: #fef2f2; color: #dc2626; }
.pill-muted { background: var(--bg); color: var(--text-2); }

.state-block {
  min-height: 280px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 28px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  color: var(--text-2);
  text-align: center;
}

.state-error {
  color: #dc2626;
}

.state-error code {
  max-width: 560px;
  padding: 8px 10px;
  border-radius: 8px;
  background: #fef2f2;
  color: #991b1b;
  font-size: 12px;
  white-space: normal;
}

.spinner,
.mini-spinner {
  border-radius: 50%;
  border: 2px solid rgba(var(--accent-rgb), .16);
  border-top-color: var(--accent);
  animation: spin .8s linear infinite;
}

.spinner {
  width: 30px;
  height: 30px;
}

.mini-spinner {
  width: 14px;
  height: 14px;
  border-color: rgba(255,255,255,.35);
  border-top-color: #fff;
}

.toast {
  position: fixed;
  right: 22px;
  bottom: 22px;
  z-index: 60;
  padding: 11px 14px;
  border-radius: 10px;
  color: #fff;
  font-size: 13px;
  font-weight: 650;
  box-shadow: 0 12px 35px rgba(0,0,0,.18);
}

.toast.success { background: #059669; }
.toast.error { background: #dc2626; }

.toast-enter-active,
.toast-leave-active {
  transition: opacity .15s ease, transform .15s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@media (max-width: 900px) {
  .quota-hero,
  .forms-grid,
  .kpi-row {
    grid-template-columns: 1fr;
  }

  .page-header {
    flex-direction: column;
  }

  .header-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 520px) {
  .page {
    margin: 0;
  }

  .quota-main strong {
    font-size: 56px;
  }
}
</style>
