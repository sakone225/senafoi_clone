<script setup>
import { ref, computed, onMounted } from 'vue'

// ── State ──────────────────────────────────────────────────────────────────
const loading      = ref(true)
const error        = ref(null)
const saving       = ref(false)
const activeTab    = ref('montants')

const montants     = ref([])
const codesPromo   = ref([])
const statsGlobal  = ref({})
const distribution = ref([])

// Modales
const showAddMontant   = ref(false)
const showAddCode      = ref(false)
const showSemsModal    = ref(false)
const semsModalTitle   = ref('')
const semsModalData    = ref([])
const semsModalLoading = ref(false)
const semsModalPage    = ref(1)
const semsModalTotal   = ref(0)
const semsModalTotalPg = ref(1)
const semsModalCtx     = ref(null)

// Formulaires
const formMontant = ref({ montant: '', libelle: '', transport: 1, reduction: 0, actif: 1 })
const formCode    = ref({ code: '', reduction: 0, transport: null, usage_max: 0, actif: 1, date_debut: '', date_fin: '' })

const toastMsg  = ref('')
const toastType = ref('success')

// ── API ────────────────────────────────────────────────────────────────────
const API_URL = 'https://api.aeemci-ce.ci/senafoi/paiements_config.php'

async function fetchAll() {
  loading.value = true
  error.value   = null
  try {
    // Appel GET sans paramètre action → route principale du tableau de bord
    const res = await fetch(API_URL)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)

    // Sécurité : lire le texte brut d'abord pour déboguer si le JSON est cassé
    const text = await res.text()
    let data
    try {
      data = JSON.parse(text)
    } catch {
      throw new Error(`Réponse non-JSON : ${text.slice(0, 120)}`)
    }

    if (!data.success) throw new Error(data.error || 'Erreur API inconnue')

    montants.value     = data.montants     || []
    codesPromo.value   = data.codes_promo  || []
    statsGlobal.value  = data.stats_global || {}
    distribution.value = data.distribution || []
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)

// ── Toast ──────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  toastMsg.value  = msg
  toastType.value = type
  setTimeout(() => { toastMsg.value = '' }, 3200)
}

// ── Montants ───────────────────────────────────────────────────────────────
async function addMontant() {
  if (!formMontant.value.montant || !formMontant.value.libelle) return
  saving.value = true
  try {
    const res  = await fetch(`${API_URL}?action=add_montant`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        montant:   parseFloat(formMontant.value.montant),
        libelle:   formMontant.value.libelle,
        transport: parseInt(formMontant.value.transport),
        reduction: parseFloat(formMontant.value.reduction || 0),
        actif:     parseInt(formMontant.value.actif),
      }),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    toast('Montant ajouté avec succès')
    showAddMontant.value = false
    formMontant.value    = { montant: '', libelle: '', transport: 1, reduction: 0, actif: 1 }
    await fetchAll()
  } catch (e) { toast(e.message, 'error') }
  finally { saving.value = false }
}

async function toggleMontant(m) {
  const newActif = m.actif ? 0 : 1
  try {
    const res  = await fetch(`${API_URL}?action=toggle_montant`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id: m.id, actif: newActif }),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    m.actif = newActif
    toast(newActif ? 'Montant activé' : 'Montant désactivé')
  } catch (e) { toast(e.message, 'error') }
}

async function deleteMontant(m) {
  if (!confirm(`Supprimer "${m.libelle}" (${fmt(m.montant)}) ?`)) return
  try {
    const res  = await fetch(`${API_URL}?action=delete_montant&id=${m.id}`, { method: 'DELETE' })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    toast('Montant supprimé')
    await fetchAll()
  } catch (e) { toast(e.message, 'error') }
}

// ── Codes promo ────────────────────────────────────────────────────────────
async function addCode() {
  if (!formCode.value.code) return
  saving.value = true
  try {
    const payload = {
      code:       formCode.value.code.toUpperCase().trim(),
      reduction:  parseFloat(formCode.value.reduction || 0),
      transport:  formCode.value.transport === null || formCode.value.transport === 'null' ? null : parseInt(formCode.value.transport),
      usage_max:  parseInt(formCode.value.usage_max || 0),
      actif:      parseInt(formCode.value.actif),
      date_debut: formCode.value.date_debut || null,
      date_fin:   formCode.value.date_fin   || null,
    }
    const res  = await fetch(`${API_URL}?action=add_code`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    toast('Code promo créé avec succès')
    showAddCode.value = false
    formCode.value    = { code: '', reduction: 0, transport: null, usage_max: 0, actif: 1, date_debut: '', date_fin: '' }
    await fetchAll()
  } catch (e) { toast(e.message, 'error') }
  finally { saving.value = false }
}

async function toggleCode(c) {
  const newActif = c.actif ? 0 : 1
  try {
    const res  = await fetch(`${API_URL}?action=toggle_code`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ id: c.id, actif: newActif }),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    c.actif = newActif
    toast(newActif ? 'Code activé' : 'Code désactivé')
  } catch (e) { toast(e.message, 'error') }
}

async function deleteCode(c) {
  if (!confirm(`Supprimer le code "${c.code}" ?`)) return
  try {
    const res  = await fetch(`${API_URL}?action=delete_code&id=${c.id}`, { method: 'DELETE' })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    toast('Code promo supprimé')
    await fetchAll()
  } catch (e) { toast(e.message, 'error') }
}

// ── Modal séminaristes ─────────────────────────────────────────────────────
async function openSemsMontant(m) {
  semsModalCtx.value   = { type: 'montant', montant: m.montant, transport: m.transport }
  semsModalTitle.value = `Inscrits — ${m.libelle} (${fmt(m.montant)})`
  semsModalPage.value  = 1
  showSemsModal.value  = true
  await loadSems(1)
}

async function openSemsCode(c) {
  semsModalCtx.value   = { type: 'code', code: c.code }
  semsModalTitle.value = `Inscrits — code « ${c.code} »`
  semsModalPage.value  = 1
  showSemsModal.value  = true
  await loadSems(1)
}

async function loadSems(p = 1) {
  semsModalLoading.value = true
  semsModalPage.value    = p
  const ctx = semsModalCtx.value
  let url = ctx.type === 'montant'
    ? `${API_URL}?action=seminaristes_montant&montant=${ctx.montant}&transport=${ctx.transport}&page=${p}`
    : `${API_URL}?action=seminaristes_code&code=${encodeURIComponent(ctx.code)}&page=${p}`
  try {
    const res  = await fetch(url)
    const data = await res.json()
    semsModalData.value    = data.data        || []
    semsModalTotal.value   = data.total       || 0
    semsModalTotalPg.value = data.total_pages || 1
  } catch (e) { semsModalData.value = [] }
  finally { semsModalLoading.value = false }
}

// ── Computed ───────────────────────────────────────────────────────────────
// Prix de base = reduction == 0 ; promos = reduction > 0
const montantsBase  = computed(() => montants.value.filter(m => !parseInt(m.reduction)))
const montantsPromo = computed(() => montants.value.filter(m => parseInt(m.reduction) > 0))

const totalCollecte = computed(() => statsGlobal.value.total_collecte  || 0)
const totalInscrits = computed(() => statsGlobal.value.total_inscrits  || 0)
const avecTransport = computed(() => statsGlobal.value.avec_transport  || 0)
const sansTransport = computed(() => statsGlobal.value.sans_transport  || 0)
const devise        = computed(() => statsGlobal.value.devise_paiement || 'XOF')

const codesActifs   = computed(() => codesPromo.value.filter(c => c.actif).length)
const codesExpires  = computed(() => {
  const today = new Date().toISOString().slice(0, 10)
  return codesPromo.value.filter(c => c.date_fin && c.date_fin < today).length
})

// ── Helpers ────────────────────────────────────────────────────────────────
function fmt(n, d = '') {
  return new Intl.NumberFormat('fr-FR').format(n || 0) + ' ' + (d || devise.value || 'XOF')
}

function fmtDate(s) {
  if (!s) return '—'
  return new Date(s).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
}

function isExpired(c) {
  if (!c.date_fin) return false
  return c.date_fin < new Date().toISOString().slice(0, 10)
}

function usagePct(c) {
  if (!c.usage_max || !c.usage_count) return 0
  return Math.min(100, Math.round((c.usage_count / c.usage_max) * 100))
}

function initiales(prenom, nom) {
  return ((prenom?.[0] || '') + (nom?.[0] || '')).toUpperCase()
}

const PALETTE = ['#6366f1','#ef4444','#10b981','#f59e0b','#8b5cf6','#3b82f6','#f97316','#14b8a6']
function avatarColor(str) {
  let h = 0
  for (const c of (str || '')) h = (h * 31 + c.charCodeAt(0)) & 0xffff
  return PALETTE[h % PALETTE.length]
}

function generateCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let code = ''
  for (let i = 0; i < 8; i++) code += chars[Math.floor(Math.random() * chars.length)]
  formCode.value.code = code
}
</script>

<template>
  <div class="page" style="margin:-15px">
    <div class="content">

      <!-- ── Breadcrumb ── -->
      <div class="breadcrumb">
        <span class="bc-root">Séminaires</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="bc-active">Configuration Paiements</span>
      </div>

      <!-- ── Loading ── -->
      <div v-if="loading" class="state-block">
        <div class="spinner"></div>
        <p>Chargement de la configuration…</p>
      </div>

      <!-- ── Erreur ── -->
      <div v-else-if="error" class="state-block state-error">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p>Erreur de chargement</p>
        <code>{{ error }}</code>
        <button class="btn-outline" @click="fetchAll">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
          </svg>
          Réessayer
        </button>
      </div>

      <template v-else>

        <!-- ── Header ── -->
        <div class="page-header">
          <div>
            <h1 class="page-title">Configuration des Paiements</h1>
            <p class="page-sub">SENAFOI 2026 · Montants autorisés &amp; codes promo</p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn-outline" @click="activeTab='montants'; showAddMontant=true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nouveau montant
            </button>
            <button class="btn-primary" @click="activeTab='codes'; showAddCode=true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nouveau code promo
            </button>
          </div>
        </div>

        <!-- ── KPI ── -->
        <div class="kpi-row">
          <div class="kpi-card">
            <div class="kpi-icon kpi-green">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ fmt(totalCollecte) }}</span>
              <span class="kpi-label">Total collecté 2026</span>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ totalInscrits }}</span>
              <span class="kpi-label">Inscrits payés</span>
            </div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;">
              <span class="kpi-trend kpi-neutral">🚌 {{ avecTransport }}</span>
              <span class="kpi-trend kpi-neutral">🚶 {{ sansTransport }}</span>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ montants.length }}</span>
              <span class="kpi-label">Montants configurés</span>
            </div>
            <span class="kpi-trend kpi-up">{{ montants.filter(m => m.actif).length }} actifs</span>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-purple">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                <line x1="7" y1="7" x2="7.01" y2="7"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ codesPromo.length }}</span>
              <span class="kpi-label">Codes promo</span>
            </div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;">
              <span class="kpi-trend kpi-up">{{ codesActifs }} actifs</span>
              <span v-if="codesExpires" class="kpi-trend kpi-down">{{ codesExpires }} expirés</span>
            </div>
          </div>
        </div>

        <!-- ── Tabs ── -->
        <div class="tabs-bar">
          <button class="tab" :class="{ active: activeTab==='montants' }" @click="activeTab='montants'">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            Montants autorisés
            <span class="tab-badge">{{ montants.length }}</span>
          </button>
          <button class="tab" :class="{ active: activeTab==='codes' }" @click="activeTab='codes'">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
              <line x1="7" y1="7" x2="7.01" y2="7"/>
            </svg>
            Codes Promo
            <span class="tab-badge">{{ codesPromo.length }}</span>
          </button>
          <button class="tab" :class="{ active: activeTab==='distrib' }" @click="activeTab='distrib'">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            Distribution
          </button>
        </div>

        <!-- ════════════════════════════════════
             TAB : MONTANTS
        ════════════════════════════════════ -->
        <template v-if="activeTab==='montants'">

          <!-- Prix de base -->
          <div class="card">
            <div class="card-header">
              <div>
                <h2 class="card-title">Prix de base</h2>
                <p class="card-sub">Tarifs officiels sans réduction — utilisés par défaut lors de l'inscription</p>
              </div>
              <button class="btn-primary btn-sm" @click="showAddMontant=true">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Ajouter un montant
              </button>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Libellé</th>
                    <th>Montant</th>
                    <th>Transport</th>
                    <th>Inscrits</th>
                    <th>Répartition</th>
                    <th>Statut</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in montantsBase" :key="m.id" class="tr" :class="{ 'tr-dim': !parseInt(m.actif) }">
                    <td><span class="fw">{{ m.libelle }}</span></td>
                    <td><span class="montant-val">{{ fmt(m.montant) }}</span></td>
                    <td>
                      <span class="badge" :class="parseInt(m.transport) ? 'b-tr' : 'b-no-tr'">
                        {{ parseInt(m.transport) ? '🚌 Avec transport' : '🚶 Sans transport' }}
                      </span>
                    </td>
                    <td>
                      <button class="util-btn" @click="openSemsMontant(m)">
                        <span class="util-n">{{ m.nb_utilisations || 0 }}</span>
                        <span class="util-l">inscrits</span>
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                    </td>
                    <td>
                      <div v-if="parseInt(m.nb_utilisations) > 0" class="sexe-badges">
                        <span class="sbadge sbadge-m">♂ {{ m.nb_hommes || 0 }}</span>
                        <span class="sbadge sbadge-f">♀ {{ m.nb_femmes || 0 }}</span>
                      </div>
                      <span v-else class="muted">—</span>
                    </td>
                    <td>
                      <button class="toggle-btn" :class="{ on: parseInt(m.actif) }" @click="toggleMontant(m)">
                        <span class="ttrack"><span class="tthumb"></span></span>
                      </button>
                    </td>
                    <td>
                      <div class="acts">
                        <button class="act act-eye" @click="openSemsMontant(m)" title="Voir les inscrits">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <button class="act act-del" @click="deleteMontant(m)" :disabled="parseInt(m.nb_utilisations) > 0" title="Supprimer">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!montantsBase.length">
                    <td colspan="7" class="empty">Aucun prix de base configuré</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Montants avec réduction (codes promo) -->
          <div class="card" v-if="montantsPromo.length">
            <div class="card-header">
              <div>
                <h2 class="card-title">Montants avec réduction</h2>
                <p class="card-sub">Sommes issues des codes promo — ajoutées automatiquement lors de l'utilisation d'un code</p>
              </div>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Libellé</th>
                    <th>Montant</th>
                    <th>Transport</th>
                    <th>Réduction</th>
                    <th>Inscrits</th>
                    <th>Répartition</th>
                    <th>Statut</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in montantsPromo" :key="m.id" class="tr" :class="{ 'tr-dim': !parseInt(m.actif) }">
                    <td><span class="fw">{{ m.libelle }}</span></td>
                    <td><span class="montant-val">{{ fmt(m.montant) }}</span></td>
                    <td>
                      <span class="badge" :class="parseInt(m.transport) ? 'b-tr' : 'b-no-tr'">
                        {{ parseInt(m.transport) ? '🚌 Avec transport' : '🚶 Sans transport' }}
                      </span>
                    </td>
                    <td><span class="reduc-tag">-{{ m.reduction }}%</span></td>
                    <td>
                      <button class="util-btn" @click="openSemsMontant(m)">
                        <span class="util-n">{{ m.nb_utilisations || 0 }}</span>
                        <span class="util-l">inscrits</span>
                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                      </button>
                    </td>
                    <td>
                      <div v-if="parseInt(m.nb_utilisations) > 0" class="sexe-badges">
                        <span class="sbadge sbadge-m">♂ {{ m.nb_hommes || 0 }}</span>
                        <span class="sbadge sbadge-f">♀ {{ m.nb_femmes || 0 }}</span>
                      </div>
                      <span v-else class="muted">—</span>
                    </td>
                    <td>
                      <button class="toggle-btn" :class="{ on: parseInt(m.actif) }" @click="toggleMontant(m)">
                        <span class="ttrack"><span class="tthumb"></span></span>
                      </button>
                    </td>
                    <td>
                      <div class="acts">
                        <button class="act act-eye" @click="openSemsMontant(m)" title="Voir les inscrits">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                        <button class="act act-del" @click="deleteMontant(m)" :disabled="parseInt(m.nb_utilisations) > 0" title="Supprimer">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </template>

        <!-- ════════════════════════════════════
             TAB : CODES PROMO
        ════════════════════════════════════ -->
        <div v-if="activeTab==='codes'" class="card">
          <div class="card-header">
            <div>
              <h2 class="card-title">Codes Promo</h2>
              <p class="card-sub">Codes de réduction utilisables lors de l'inscription en ligne</p>
            </div>
            <button class="btn-primary btn-sm" @click="showAddCode=true">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Créer un code
            </button>
          </div>

          <div class="codes-grid">
            <div
              v-for="c in codesPromo" :key="c.id"
              class="code-card"
              :class="{ 'code-off': !c.actif, 'code-exp': isExpired(c) }"
            >
              <div class="code-top">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <span class="code-str">{{ c.code }}</span>
                  <span v-if="isExpired(c)" class="badge b-absent" style="font-size:9px;">Expiré</span>
                  <span v-else-if="!c.actif" class="badge b-warn" style="font-size:9px;">Inactif</span>
                  <span v-else class="badge b-present" style="font-size:9px;">Actif</span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                  <button class="toggle-btn" :class="{ on: c.actif }" @click="toggleCode(c)">
                    <span class="ttrack"><span class="tthumb"></span></span>
                  </button>
                  <button class="act act-del" @click="deleteCode(c)" :disabled="parseInt(c.usage_count)>0" title="Supprimer">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                  </button>
                </div>
              </div>

              <div class="code-meta">
                <div class="meta-item">
                  <label>Réduction</label>
                  <span class="reduc-tag">-{{ c.reduction }}%</span>
                </div>
                <div class="meta-item">
                  <label>Transport</label>
                  <span class="badge" style="font-size:10px;" :class="c.transport === null ? 'b-neutral' : parseInt(c.transport) ? 'b-tr' : 'b-no-tr'">
                    {{ c.transport === null ? '✦ Tous' : parseInt(c.transport) ? '🚌 Avec' : '🚶 Sans' }}
                  </span>
                </div>
                <div class="meta-item">
                  <label>Validité</label>
                  <span class="meta-val">
                    {{ (c.date_debut || c.date_fin) ? `${fmtDate(c.date_debut)} → ${fmtDate(c.date_fin)}` : 'Illimitée' }}
                  </span>
                </div>
              </div>

              <div class="code-usage">
                <div class="usage-hd">
                  <span>Utilisations</span>
                  <button class="usage-link" @click="openSemsCode(c)">
                    <strong>{{ c.usage_count || 0 }}</strong>
                    <template v-if="c.usage_max"> / {{ c.usage_max }}</template>
                    <template v-else> fois</template>
                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
                <div v-if="c.usage_max" class="usage-bar">
                  <div class="usage-fill" :style="{
                    width: usagePct(c)+'%',
                    background: usagePct(c)>=100 ? '#ef4444' : usagePct(c)>=80 ? '#f59e0b' : '#10b981'
                  }"></div>
                </div>
              </div>
            </div>

            <div v-if="!codesPromo.length" class="empty-codes">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#d1d5db">
                <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                <line x1="7" y1="7" x2="7.01" y2="7"/>
              </svg>
              <p>Aucun code promo configuré</p>
              <button class="btn-primary btn-sm" @click="showAddCode=true">Créer un code</button>
            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════
             TAB : DISTRIBUTION
        ════════════════════════════════════ -->
        <div v-if="activeTab==='distrib'" class="card">
          <div class="card-header">
            <div>
              <h2 class="card-title">Distribution des paiements</h2>
              <p class="card-sub">Répartition des {{ totalInscrits }} inscrits par montant payé · SENAFOI 2026</p>
            </div>
          </div>
          <div style="padding:18px;display:flex;flex-direction:column;gap:10px;">
            <div v-for="d in distribution" :key="`${d.somme_paye}-${d.transport}`" class="distrib-row">
              <div class="distrib-left">
                <span class="montant-val" style="font-size:13px;">{{ fmt(d.somme_paye) }}</span>
                <span class="badge" style="font-size:10px;" :class="parseInt(d.transport) ? 'b-tr' : 'b-no-tr'">
                  {{ parseInt(d.transport) ? '🚌' : '🚶' }}
                </span>
              </div>
              <div class="distrib-bar-wrap">
                <div class="distrib-bar">
                  <div class="distrib-fill" :style="{ width: totalInscrits > 0 ? (d.total/totalInscrits*100)+'%' : '0%' }"></div>
                </div>
                <span class="distrib-cnt">{{ d.total }} inscrits</span>
              </div>
              <div class="distrib-sexe">
                <span class="sbadge sbadge-m">♂ {{ d.hommes || 0 }}</span>
                <span class="sbadge sbadge-f">♀ {{ d.femmes || 0 }}</span>
              </div>
              <span class="distrib-pct">{{ totalInscrits > 0 ? (d.total/totalInscrits*100).toFixed(1) : 0 }}%</span>
            </div>
            <div v-if="!distribution.length" class="empty">Aucune donnée disponible</div>
          </div>
        </div>

      </template>
    </div>

    <!-- ════════════════════════════════════
         MODAL : Ajouter un montant
    ════════════════════════════════════ -->
    <Transition name="modal">
      <div v-if="showAddMontant" class="overlay" @click.self="showAddMontant=false">
        <div class="modal">
          <div class="modal-hd">
            <h3 class="modal-title">Nouveau montant autorisé</h3>
            <button class="modal-x" @click="showAddMontant=false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="info-banner">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Réduction à <strong>0</strong> = prix de base officiel. Une valeur &gt; 0 indique un montant promo lié à un code de réduction.
            </div>
            <div class="fld">
              <label>Libellé <span class="req">*</span></label>
              <input v-model="formMontant.libelle" class="finput" placeholder="ex: Prix standard avec transport" />
            </div>
            <div class="frow">
              <div class="fld">
                <label>Montant (XOF) <span class="req">*</span></label>
                <input v-model="formMontant.montant" type="number" min="0" step="500" class="finput" placeholder="25000" />
              </div>
              <div class="fld">
                <label>Réduction (%)</label>
                <input v-model="formMontant.reduction" type="number" min="0" max="100" class="finput" placeholder="0" />
                <span class="fhint">0 = prix de base (non promo)</span>
              </div>
            </div>
            <div class="fld">
              <label>Option transport <span class="req">*</span></label>
              <div class="radio-group">
                <label class="ropt" :class="{ sel: parseInt(formMontant.transport)===1 }">
                  <input type="radio" v-model="formMontant.transport" :value="1" hidden /> 🚌 Avec transport
                </label>
                <label class="ropt" :class="{ sel: parseInt(formMontant.transport)===0 }">
                  <input type="radio" v-model="formMontant.transport" :value="0" hidden /> 🚶 Sans transport
                </label>
              </div>
            </div>
            <div class="fld">
              <label>Statut initial</label>
              <div class="radio-group">
                <label class="ropt" :class="{ sel: parseInt(formMontant.actif)===1 }">
                  <input type="radio" v-model="formMontant.actif" :value="1" hidden /> ✓ Actif
                </label>
                <label class="ropt" :class="{ sel: parseInt(formMontant.actif)===0 }">
                  <input type="radio" v-model="formMontant.actif" :value="0" hidden /> Inactif
                </label>
              </div>
            </div>
          </div>
          <div class="modal-ft">
            <button class="btn-outline" @click="showAddMontant=false">Annuler</button>
            <button class="btn-primary" @click="addMontant" :disabled="saving || !formMontant.montant || !formMontant.libelle">
              <div v-if="saving" class="spinner" style="width:14px;height:14px;border-width:2px;"></div>
              <template v-else>Ajouter le montant</template>
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ════════════════════════════════════
         MODAL : Ajouter un code promo
    ════════════════════════════════════ -->
    <Transition name="modal">
      <div v-if="showAddCode" class="overlay" @click.self="showAddCode=false">
        <div class="modal">
          <div class="modal-hd">
            <h3 class="modal-title">Nouveau code promo</h3>
            <button class="modal-x" @click="showAddCode=false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div class="modal-body">
            <div class="fld">
              <label>Code <span class="req">*</span></label>
              <div style="display:flex;gap:8px;">
                <input
                  v-model="formCode.code"
                  class="finput"
                  style="font-family:monospace;font-weight:700;letter-spacing:.1em;text-transform:uppercase;"
                  placeholder="SENAFOI2026"
                  @input="formCode.code = formCode.code.toUpperCase()"
                />
                <button class="btn-outline" style="white-space:nowrap;padding:0 14px;height:38px;" @click="generateCode">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                  Générer
                </button>
              </div>
            </div>
            <div class="frow">
              <div class="fld">
                <label>Réduction (%)</label>
                <input v-model="formCode.reduction" type="number" min="0" max="100" class="finput" placeholder="10" />
              </div>
              <div class="fld">
                <label>Utilisations max</label>
                <input v-model="formCode.usage_max" type="number" min="0" class="finput" placeholder="0 = illimité" />
                <span class="fhint">0 = illimité</span>
              </div>
            </div>
            <div class="fld">
              <label>Applicable pour</label>
              <div class="radio-group" style="grid-template-columns:1fr 1fr 1fr;">
                <label class="ropt" :class="{ sel: formCode.transport===null }">
                  <input type="radio" v-model="formCode.transport" :value="null" hidden /> ✦ Tous
                </label>
                <label class="ropt" :class="{ sel: formCode.transport===1 || formCode.transport==='1' }">
                  <input type="radio" v-model="formCode.transport" :value="1" hidden /> 🚌 Avec transport
                </label>
                <label class="ropt" :class="{ sel: (formCode.transport===0 || formCode.transport==='0') && formCode.transport!==null }">
                  <input type="radio" v-model="formCode.transport" :value="0" hidden /> 🚶 Sans transport
                </label>
              </div>
            </div>
            <div class="frow">
              <div class="fld">
                <label>Date début</label>
                <input v-model="formCode.date_debut" type="date" class="finput" />
              </div>
              <div class="fld">
                <label>Date fin</label>
                <input v-model="formCode.date_fin" type="date" class="finput" />
                <span class="fhint">Laisser vide = pas d'expiration</span>
              </div>
            </div>
          </div>
          <div class="modal-ft">
            <button class="btn-outline" @click="showAddCode=false">Annuler</button>
            <button class="btn-primary" @click="addCode" :disabled="saving || !formCode.code">
              <div v-if="saving" class="spinner" style="width:14px;height:14px;border-width:2px;"></div>
              <template v-else>Créer le code</template>
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ════════════════════════════════════
         MODAL : Liste des séminaristes
    ════════════════════════════════════ -->
    <Transition name="modal">
      <div v-if="showSemsModal" class="overlay" @click.self="showSemsModal=false">
        <div class="modal modal-lg">
          <div class="modal-hd">
            <div>
              <h3 class="modal-title">{{ semsModalTitle }}</h3>
              <p style="font-size:11.5px;color:#9ca3af;margin:3px 0 0;">{{ semsModalTotal }} séminariste{{ semsModalTotal > 1 ? 's' : '' }}</p>
            </div>
            <button class="modal-x" @click="showSemsModal=false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
          <div style="max-height:55vh;overflow-y:auto;">
            <div v-if="semsModalLoading" class="state-block" style="padding:40px;">
              <div class="spinner"></div>
              <p>Chargement…</p>
            </div>
            <template v-else>
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Séminariste</th>
                      <th>Niveau</th>
                      <th>Ville</th>
                      <th>Somme</th>
                      <th>Transport</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in semsModalData" :key="s.id" class="tr">
                      <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                          <div v-if="s.photo" style="width:34px;height:34px;border-radius:10px;overflow:hidden;flex-shrink:0;border:1px solid rgba(0,0,0,.06)">
                            <img :src="s.photo" style="width:100%;height:100%;object-fit:cover;" />
                          </div>
                          <div v-else class="mini-avatar" :style="{ background: avatarColor(s.prenom+s.nom)+'20', color: avatarColor(s.prenom+s.nom) }">
                            {{ initiales(s.prenom, s.nom) }}
                          </div>
                          <div>
                            <div class="fw" style="font-size:13px;">{{ s.prenom }} {{ s.nom }}</div>
                            <div style="font-size:11px;color:#9ca3af;font-family:monospace;">{{ s.matricule_seminaire }}</div>
                          </div>
                        </div>
                      </td>
                      <td class="td-cell">{{ s.niveau_seminaire || '—' }}</td>
                      <td class="td-cell">{{ s.secretariat_regional || '—' }}</td>
                      <td><span class="montant-val" style="font-size:12.5px;">{{ fmt(s.somme_paye) }}</span></td>
                      <td>
                        <span class="badge" style="font-size:10px;" :class="parseInt(s.transport) ? 'b-tr' : 'b-no-tr'">
                          {{ parseInt(s.transport) ? '🚌 Avec' : '🚶 Sans' }}
                        </span>
                      </td>
                    </tr>
                    <tr v-if="!semsModalData.length">
                      <td colspan="5" class="empty">Aucun séminariste trouvé</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Pagination modale -->
              <div v-if="semsModalTotalPg > 1" class="modal-pag">
                <span class="pag-info">Page {{ semsModalPage }} / {{ semsModalTotalPg }}</span>
                <div style="display:flex;gap:4px;">
                  <button class="pag-btn" :disabled="semsModalPage<=1" @click="loadSems(semsModalPage-1)">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                  </button>
                  <button class="pag-btn" :disabled="semsModalPage>=semsModalTotalPg" @click="loadSems(semsModalPage+1)">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                  </button>
                </div>
              </div>
            </template>
          </div>
          <div class="modal-ft">
            <button class="btn-primary" @click="showSemsModal=false">Fermer</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ── Toast ── -->
    <Transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType==='error' ? 'toast-err' : 'toast-ok'">
        <svg v-if="toastType==='success'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>
        {{ toastMsg }}
      </div>
    </Transition>

  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.page    { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.content { padding: 20px 20px 60px; display: flex; flex-direction: column; gap: 20px; max-width: 1400px; width: 100%; margin: 0 auto; }

/* Breadcrumb */
.breadcrumb { display:flex; align-items:center; gap:6px; font-size:12.5px; color:#6b7280; padding-top:10px; }
.bc-root { cursor:pointer; } .bc-root:hover { color:#6366f1; }
.bc-active { color:#111; font-weight:560; }

/* Loading / Error */
.state-block { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:80px 20px; color:#6b7280; font-size:14px; }
.state-error { color:#ef4444; }
.state-error code { font-size:11px; background:rgba(239,68,68,.07); padding:6px 12px; border-radius:8px; color:#dc2626; display:block; max-width:100%; word-break:break-all; margin-top:4px; }
.spinner { width:36px; height:36px; border:3px solid rgba(99,102,241,.15); border-top-color:#6366f1; border-radius:50%; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Header */
.page-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; }
.page-title  { font-size:22px; font-weight:720; color:#111; letter-spacing:-.03em; margin-bottom:3px; }
.page-sub    { font-size:13px; color:#9ca3af; font-weight:430; }

/* KPI */
.kpi-row  { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.kpi-card { background:#fff; border:1px solid rgba(0,0,0,.07); border-radius:14px; padding:18px 20px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); flex-wrap:wrap; }
.kpi-icon  { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.kpi-blue  { background:rgba(99,102,241,.1); color:#6366f1; }
.kpi-green { background:rgba(16,185,129,.1); color:#10b981; }
.kpi-amber { background:rgba(245,158,11,.1); color:#f59e0b; }
.kpi-purple{ background:rgba(139,92,246,.1); color:#8b5cf6; }
.kpi-data  { display:flex; flex-direction:column; flex:1; min-width:0; }
.kpi-val   { font-size:19px; font-weight:730; color:#111; letter-spacing:-.04em; line-height:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.kpi-label { font-size:11.5px; color:#9ca3af; font-weight:440; margin-top:3px; }
.kpi-trend { font-size:10.5px; font-weight:570; padding:2px 7px; border-radius:20px; white-space:nowrap; }
.kpi-up      { background:rgba(16,185,129,.1); color:#10b981; }
.kpi-down    { background:rgba(239,68,68,.08); color:#ef4444; }
.kpi-neutral { background:rgba(107,114,128,.08); color:#6b7280; }

/* Info banner */
.info-banner { display:flex; align-items:flex-start; gap:8px; padding:10px 14px; background:rgba(99,102,241,.06); border:1px solid rgba(99,102,241,.15); border-radius:9px; font-size:12px; color:#374151; line-height:1.5; }

/* Tabs */
.tabs-bar { display:flex; background:#fff; border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:4px; gap:3px; box-shadow:0 1px 3px rgba(0,0,0,.04); width:fit-content; flex-wrap:wrap; }
.tab      { display:flex; align-items:center; gap:7px; padding:8px 16px; font-size:13px; font-weight:490; color:#6b7280; border:none; background:transparent; border-radius:9px; cursor:pointer; font-family:inherit; transition:background .13s,color .13s; white-space:nowrap; }
.tab.active { background:#6366f1; color:#fff; font-weight:590; }
.tab:hover:not(.active) { background:#f3f4f6; color:#111; }
.tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; border-radius:6px; font-size:10px; font-weight:700; background:rgba(0,0,0,.07); padding:0 4px; }
.tab.active .tab-badge { background:rgba(255,255,255,.22); color:#fff; }

/* Card */
.card        { background:#fff; border-radius:16px; border:1px solid rgba(0,0,0,.07); box-shadow:0 1px 3px rgba(0,0,0,.04); overflow:hidden; }
.card-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid rgba(0,0,0,.06); background:#fafafa; flex-wrap:wrap; gap:10px; }
.card-title  { font-size:15px; font-weight:680; color:#111; margin:0 0 2px; }
.card-sub    { font-size:12px; color:#9ca3af; margin:0; }

/* Buttons */
.btn-primary { display:flex; align-items:center; justify-content:center; gap:7px; padding:9px 18px; background:#6366f1; color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:570; font-family:inherit; cursor:pointer; box-shadow:0 2px 8px rgba(99,102,241,.3); transition:background .18s; }
.btn-primary:hover { background:#4f46e5; }
.btn-primary:disabled { opacity:.5; cursor:default; }
.btn-primary.btn-sm { padding:7px 14px; font-size:12.5px; }
.btn-outline { display:flex; align-items:center; gap:7px; padding:9px 14px; background:#fff; color:#374151; border:1px solid rgba(0,0,0,.09); border-radius:10px; font-size:13px; font-weight:480; font-family:inherit; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,.04); transition:background .15s; white-space:nowrap; }
.btn-outline:hover { background:#f9fafb; }

/* Table */
.table-wrap { overflow-x:auto; }
.table      { width:100%; border-collapse:collapse; font-size:13px; }
.table thead tr { background:#f9fafb; border-bottom:1px solid rgba(0,0,0,.06); }
.table th   { padding:10px 16px; text-align:left; font-size:10.5px; font-weight:630; color:#6b7280; letter-spacing:.04em; text-transform:uppercase; white-space:nowrap; }
.tr         { border-bottom:1px solid rgba(0,0,0,.05); transition:background .12s; }
.tr:last-child { border-bottom:none; }
.tr:hover   { background:#fafbff; }
.tr-dim     { opacity:.5; }
.table td   { padding:13px 16px; vertical-align:middle; }
.empty      { text-align:center; padding:48px; color:#9ca3af; font-size:13.5px; }
.fw         { font-weight:570; color:#111; }
.muted      { color:#d1d5db; font-size:12px; }
.td-cell    { color:#374151; white-space:nowrap; font-size:13px; }
.montant-val{ font-size:14px; font-weight:720; color:#111; font-variant-numeric:tabular-nums; }
.reduc-tag  { display:inline-flex; align-items:center; padding:3px 8px; background:rgba(139,92,246,.1); color:#8b5cf6; border-radius:6px; font-size:12px; font-weight:700; }

/* Badges */
.badge    { display:inline-flex; align-items:center; font-size:11px; font-weight:610; padding:3px 9px; border-radius:20px; letter-spacing:.02em; white-space:nowrap; }
.b-present{ background:rgba(16,185,129,.1); color:#059669; }
.b-absent { background:rgba(239,68,68,.1); color:#dc2626; }
.b-warn   { background:rgba(245,158,11,.1); color:#b45309; }
.b-tr     { background:rgba(59,130,246,.1); color:#2563eb; }
.b-no-tr  { background:rgba(107,114,128,.1); color:#4b5563; }
.b-neutral{ background:rgba(107,114,128,.1); color:#4b5563; }

/* Sexe badges inline */
.sexe-badges { display:flex; gap:5px; }
.sbadge { font-size:10.5px; font-weight:620; padding:2px 7px; border-radius:6px; }
.sbadge-m { background:rgba(59,130,246,.1); color:#2563eb; }
.sbadge-f { background:rgba(236,72,153,.1); color:#db2777; }

/* Actions */
.acts { display:flex; align-items:center; gap:4px; }
.act  { width:30px; height:30px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; cursor:pointer; border:1px solid transparent; transition:background .13s; background:none; }
.act:disabled { opacity:.3; cursor:not-allowed; }
.act-eye { background:rgba(99,102,241,.08); color:#6366f1; border-color:rgba(99,102,241,.15); }
.act-eye:hover { background:rgba(99,102,241,.15); }
.act-del { background:rgba(239,68,68,.07); color:#ef4444; border-color:rgba(239,68,68,.15); }
.act-del:not(:disabled):hover { background:rgba(239,68,68,.14); }

/* Toggle */
.toggle-btn { background:none; border:none; cursor:pointer; padding:0; display:flex; align-items:center; }
.ttrack  { width:34px; height:18px; border-radius:20px; background:#d1d5db; display:flex; align-items:center; padding:2px; transition:background .2s; position:relative; }
.tthumb  { width:14px; height:14px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:transform .2s; position:absolute; left:2px; }
.toggle-btn.on .ttrack { background:#10b981; }
.toggle-btn.on .tthumb { transform:translateX(16px); }

/* Utilisations */
.util-btn  { display:flex; align-items:center; gap:5px; padding:5px 10px; background:rgba(99,102,241,.07); border:1px solid rgba(99,102,241,.12); border-radius:8px; cursor:pointer; font-family:inherit; transition:background .13s; }
.util-btn:hover { background:rgba(99,102,241,.14); }
.util-n    { font-size:16px; font-weight:730; color:#6366f1; }
.util-l    { font-size:10.5px; color:#9ca3af; }

/* Codes grid */
.codes-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; padding:18px; }
.code-card  { background:#fafafa; border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:16px 18px; display:flex; flex-direction:column; gap:12px; transition:box-shadow .15s; }
.code-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.code-off   { opacity:.6; }
.code-exp   { border-color:rgba(239,68,68,.2); background:rgba(239,68,68,.02); }
.code-top   { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.code-str   { font-family:'SF Mono','Fira Code',monospace; font-size:16px; font-weight:800; color:#111; letter-spacing:.06em; }
.code-meta  { display:grid; grid-template-columns:auto auto 1fr; gap:8px 16px; align-items:start; }
.meta-item  { display:flex; flex-direction:column; gap:4px; }
.meta-item label { font-size:9px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.08em; }
.meta-val   { font-size:11.5px; color:#374151; }
.code-usage { display:flex; flex-direction:column; gap:6px; border-top:1px solid rgba(0,0,0,.06); padding-top:10px; }
.usage-hd   { display:flex; align-items:center; justify-content:space-between; font-size:10.5px; color:#9ca3af; font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
.usage-link { display:flex; align-items:center; gap:4px; background:none; border:none; cursor:pointer; font-family:inherit; font-size:13px; color:#6366f1; font-weight:600; padding:0; }
.usage-link:hover { text-decoration:underline; }
.usage-bar  { height:6px; background:#e5e7eb; border-radius:3px; overflow:hidden; }
.usage-fill { height:100%; border-radius:3px; transition:width .4s ease; }
.empty-codes { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:60px 20px; color:#9ca3af; font-size:13.5px; grid-column:1/-1; }

/* Distribution */
.distrib-row     { display:flex; align-items:center; gap:14px; padding:14px 0; border-bottom:1px solid rgba(0,0,0,.05); flex-wrap:wrap; }
.distrib-row:last-child { border-bottom:none; }
.distrib-left    { display:flex; align-items:center; gap:10px; min-width:190px; }
.distrib-bar-wrap{ display:flex; align-items:center; gap:10px; flex:1; min-width:120px; }
.distrib-bar     { flex:1; height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; }
.distrib-fill    { height:100%; background:linear-gradient(90deg,#6366f1,#8b5cf6); border-radius:4px; transition:width .4s ease; }
.distrib-cnt     { font-size:12.5px; font-weight:600; color:#374151; white-space:nowrap; }
.distrib-sexe    { display:flex; gap:6px; }
.distrib-pct     { font-size:13px; font-weight:700; color:#6366f1; min-width:45px; text-align:right; }

/* Modal */
.overlay   { position:fixed; inset:0; background:rgba(17,17,16,.45); display:flex; align-items:center; justify-content:center; z-index:200; backdrop-filter:blur(4px); }
.modal     { background:#fff; border-radius:18px; width:520px; max-width:calc(100vw - 32px); max-height:92vh; box-shadow:0 32px 80px rgba(0,0,0,.2); overflow:hidden; display:flex; flex-direction:column; }
.modal-lg  { width:700px; }
.modal-hd  { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid rgba(0,0,0,.06); background:#fafafa; flex-shrink:0; }
.modal-title { font-size:15px; font-weight:680; color:#111; margin:0; }
.modal-x   { width:30px; height:30px; border-radius:8px; border:1px solid rgba(0,0,0,.09); background:#fff; color:#6b7280; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .13s; }
.modal-x:hover { background:#f3f4f6; }
.modal-body{ padding:20px 22px; overflow-y:auto; display:flex; flex-direction:column; gap:16px; }
.modal-ft  { display:flex; align-items:center; justify-content:flex-end; gap:8px; padding:14px 22px; border-top:1px solid rgba(0,0,0,.06); background:#fafafa; flex-shrink:0; }
.modal-pag { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; border-top:1px solid rgba(0,0,0,.06); background:#fafafa; }
.pag-info  { font-size:12px; color:#9ca3af; }

/* Forms */
.fld   { display:flex; flex-direction:column; gap:6px; }
.frow  { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.fld label { font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.06em; }
.req   { color:#ef4444; }
.finput{ height:38px; padding:0 12px; border:1px solid rgba(0,0,0,.1); border-radius:9px; font-size:13px; font-family:inherit; color:#111; outline:none; transition:border-color .15s,box-shadow .15s; background:#fff; width:100%; }
.finput:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.fhint { font-size:10.5px; color:#9ca3af; }
.radio-group { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.ropt  { display:flex; align-items:center; gap:7px; padding:9px 12px; border:1.5px solid rgba(0,0,0,.09); border-radius:9px; cursor:pointer; font-size:12.5px; color:#374151; transition:all .13s; user-select:none; }
.ropt.sel { border-color:#6366f1; background:rgba(99,102,241,.06); color:#6366f1; font-weight:600; }
.ropt:hover:not(.sel) { background:#f9fafb; }

/* Avatar mini */
.mini-avatar { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:730; flex-shrink:0; }

/* Pagination */
.pag-btn { min-width:30px; height:30px; padding:0 6px; border-radius:8px; border:1px solid rgba(0,0,0,.08); background:#fff; font-size:12px; font-family:inherit; color:#374151; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .13s; }
.pag-btn:hover:not(:disabled) { background:#f3f4f6; }
.pag-btn:disabled { opacity:.35; cursor:default; }

/* Toast */
.toast     { position:fixed; bottom:24px; right:24px; display:flex; align-items:center; gap:8px; padding:12px 18px; border-radius:12px; font-size:13px; font-weight:530; z-index:300; box-shadow:0 8px 24px rgba(0,0,0,.15); }
.toast-ok  { background:#111; color:#fff; }
.toast-err { background:#dc2626; color:#fff; }
.toast-enter-active,.toast-leave-active { transition:opacity .25s,transform .25s; }
.toast-enter-from,.toast-leave-to { opacity:0; transform:translateY(10px); }

/* Modal transitions */
.modal-enter-active,.modal-leave-active { transition:opacity .2s,transform .2s; }
.modal-enter-from { opacity:0; transform:scale(0.96) translateY(10px); }
.modal-leave-to   { opacity:0; transform:scale(0.96) translateY(10px); }

/* Responsive */
@media (max-width:900px) { .kpi-row { grid-template-columns:1fr 1fr; } .frow { grid-template-columns:1fr; } }
@media (max-width:600px) {
  .content { padding:10px 14px; }
  .kpi-row { grid-template-columns:1fr 1fr; }
  .codes-grid { grid-template-columns:1fr; }
  .tabs-bar { flex-wrap:wrap; }
  .page-header { flex-direction:column; }
  .code-meta { grid-template-columns:1fr 1fr; }
}
</style>