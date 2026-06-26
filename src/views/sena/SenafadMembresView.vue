<script setup>
import { computed, onMounted, ref, watch } from 'vue'

const API_URL = 'https://api.aeemci-ce.ci/membres.php'

const search = ref('')
const activeFilter = ref('tous')
const cardFilter = ref('tous')
const selectedMembre = ref(null)
const isModalOpen = ref(false)
const isEditing = ref(false)
const savingEdit = ref(false)
const editForm = ref({})
const secretariatStatsOpen = ref(false)
const loadingSecretariatStats = ref(false)
const success = ref('')
const notifyingIds = ref(new Set())

const page = ref(1)
const perPage = ref(25)
const total = ref(0)
const totalPages = ref(1)
const from = ref(0)
const to = ref(0)

const loading = ref(true)
const loadingStats = ref(true)
const error = ref(null)
const stats = ref({})
const secretariatStats = ref([])
const membres = ref([])

async function fetchJson(url) {
  const res = await fetch(url)
  if (!res.ok) throw new Error(`HTTP ${res.status}`)
  const data = await res.json()
  if (!data.success) throw new Error(data.message || data.error || 'Erreur API')
  return data
}

async function postJson(url, body) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.success) throw new Error(data.message || data.error || `HTTP ${res.status}`)
  return data
}

async function fetchStats() {
  loadingStats.value = true
  try {
    const data = await fetchJson(`${API_URL}?action=stats&rand=${Date.now()}`)
    stats.value = data.data || {}
    secretariatStats.value = stats.value.par_secretariat || stats.value.secretariats || []
    if (!secretariatStats.value.length && Number(stats.value.total_secretariats || 0) > 0) {
      fetchSecretariatStats()
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loadingStats.value = false
  }
}

async function fetchMembres(p = page.value) {
  loading.value = true
  error.value = null
  try {
    const params = new URLSearchParams({
      action: 'membres',
      page: String(p),
      per_page: String(perPage.value),
      rand: String(Date.now()),
    })
    if (activeFilter.value !== 'tous') params.set('type_membre', activeFilter.value)
    if (cardFilter.value !== 'tous') params.set('card_status', cardFilter.value)
    if (search.value.trim()) params.set('search', search.value.trim())

    const data = await fetchJson(`${API_URL}?${params}`)
    const pg = data.pagination || {}
    membres.value = (data.data || []).map(normalizeMembre)
    page.value = Number(pg.current_page || p)
    perPage.value = Number(pg.per_page || perPage.value)
    total.value = Number(pg.total || membres.value.length)
    totalPages.value = Number(pg.last_page || 1)
    from.value = Number(pg.from || 0)
    to.value = Number(pg.to || membres.value.length)
  } catch (e) {
    error.value = e.message
    membres.value = []
  } finally {
    loading.value = false
  }
}

function normalizeMembre(m) {
  const nomComplet = `${m.prenom || ''} ${m.nom || ''}`.trim()
  return {
    ...m,
    nomComplet: nomComplet || 'Sans nom',
    avatar: initiales(nomComplet || '?'),
    photo: m.photo || m.photo_membre || m.avatar_url || '',
    statutLabel: m.statut || 'NON_DEFINI',
    regionLabel: m.secretariat_poste || m.region || m.sr_debut || 'Non defini',
    sousComiteLabel: m.sous_comite || 'Non defini',
    carteLabel: cardStatusLabel(m.card_status),
    carteKey: normalizeCardStatus(m.card_status),
    typeLabel: m.type_membre || 'NON_DEFINI',
    cardNotifiedAt: m.card_notified_at || m.retrait_sms_sent_at || m.sms_retrait_at || null,
  }
}

onMounted(() => {
  fetchStats()
  fetchMembres(1)
})

let debounceTimer = null
watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchMembres(1)
  }, 250)
})

function refreshAll() {
  success.value = ''
  fetchStats()
  fetchMembres(page.value)
}

function setFilter(filter) {
  activeFilter.value = filter
  page.value = 1
  fetchMembres(1)
}

function setCardFilter(filter) {
  cardFilter.value = filter
  page.value = 1
  fetchMembres(1)
}

function onPerPageChange() {
  page.value = 1
  fetchMembres(1)
}

function goToPage(p) {
  if (p < 1 || p > totalPages.value || p === page.value) return
  fetchMembres(p)
}

const visiblePages = computed(() => {
  if (totalPages.value <= 7) return Array.from({ length: totalPages.value }, (_, i) => i + 1)
  const pages = new Set([1, totalPages.value, page.value])
  for (let i = page.value - 1; i <= page.value + 1; i += 1) {
    if (i > 0 && i <= totalPages.value) pages.add(i)
  }
  return Array.from(pages).sort((a, b) => a - b)
})

const filtered = computed(() => {
  return membres.value
})

const stat = computed(() => ({
  totalMembers: Number(stats.value.total_members || total.value || 0),
  paidCards: Number(stats.value.paid_cards || 0),
  printedCards: Number(stats.value.printed_cards || 0),
  retrievedCards: Number(stats.value.retrieved_cards || 0),
  validAccounts: Number(stats.value.valid_accounts || 0),
  totalSecretariats: Number(stats.value.total_secretariats || 0),
  monthMembers: Number(stats.value.month_members || 0),
  totalPaye: Number(stats.value.total_paye || 0),
  actuels: Number(stats.value.stats_by_type?.actuels || 0),
  anciens: Number(stats.value.stats_by_type?.anciens || 0),
}))

const retrievalRate = computed(() => {
  if (!stat.value.paidCards) return 0
  return Math.round((stat.value.retrievedCards / stat.value.paidCards) * 100)
})

const cardsPendingPrint = computed(() => {
  if (stats.value.pending_print_cards !== undefined) return Number(stats.value.pending_print_cards || 0)
  return Math.max(stat.value.paidCards - stat.value.printedCards - stat.value.retrievedCards, 0)
})
const cardsPrintedWaiting = computed(() => stat.value.printedCards)
const statsSecretariats = computed(() => secretariatStats.value || [])
const maxSecretariatTotal = computed(() => Math.max(...statsSecretariats.value.map((item) => Number(item.total || 0)), 1))

async function fetchSecretariatStats() {
  if (loadingSecretariatStats.value) return
  loadingSecretariatStats.value = true
  try {
    const data = await fetchJson(`${API_URL}?action=secretariats_stats&rand=${Date.now()}`)
    secretariatStats.value = data.data || []
  } catch (e) {
    await fetchSecretariatStatsFallback()
  } finally {
    loadingSecretariatStats.value = false
  }
}

async function fetchSecretariatStatsFallback() {
  try {
    const first = await fetchJson(`${API_URL}?action=membres&page=1&per_page=100&rand=${Date.now()}`)
    const pg = first.pagination || {}
    const totalPagesToLoad = Math.min(Number(pg.last_page || 1), 60)
    const all = [...(first.data || [])]

    for (let p = 2; p <= totalPagesToLoad; p += 1) {
      const data = await fetchJson(`${API_URL}?action=membres&page=${p}&per_page=100&rand=${Date.now()}`)
      all.push(...(data.data || []))
    }

    secretariatStats.value = buildSecretariatStats(all)
  } catch (e) {
    console.warn('Stats secretariat fallback impossible:', e.message)
  }
}

function buildSecretariatStats(items) {
  const rows = new Map()
  items.forEach((membre) => {
    const name = membre.secretariat_poste || membre.region || membre.sr_debut || 'Non defini'
    const row = rows.get(name) || {
      secretariat: name,
      total: 0,
      actuels: 0,
      anciens: 0,
      pending_cards: 0,
      printed_cards: 0,
      retrieved_cards: 0,
    }
    row.total += 1
    if (membre.type_membre === 'ACTUEL') row.actuels += 1
    if (membre.type_membre === 'ANCIEN') row.anciens += 1
    const status = normalizeCardStatus(membre.card_status)
    if (status === 'pending') row.pending_cards += 1
    if (status === 'printed') row.printed_cards += 1
    if (status === 'retrieved') row.retrieved_cards += 1
    rows.set(name, row)
  })
  return Array.from(rows.values()).sort((a, b) => b.total - a.total || a.secretariat.localeCompare(b.secretariat))
}

function openModal(membre) {
  selectedMembre.value = { ...membre }
  editForm.value = editableFromMembre(membre)
  isEditing.value = false
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
  isEditing.value = false
}

function editableFromMembre(membre) {
  return {
    id: membre.id,
    prenom: membre.prenom || '',
    nom: membre.nom || '',
    contact: membre.contact || '',
    sexe: membre.sexe || '',
    date_naissance: membre.date_naissance || '',
    lieu_naissance: membre.lieu_naissance || '',
    secretariat_poste: membre.secretariat_poste || membre.region || membre.sr_debut || '',
    region: membre.region || membre.secretariat_poste || '',
    sous_comite: membre.sous_comite || '',
    section: membre.section || '',
    qualite_membre: membre.qualite_membre || '',
    statut: membre.statut || '',
    type_membre: membre.type_membre || 'ACTUEL',
    annee_debut: membre.annee_debut || '',
    sr_debut: membre.sr_debut || '',
    card_status: membre.carteKey || 'pending',
  }
}

function startEdit() {
  if (!selectedMembre.value) return
  editForm.value = editableFromMembre(selectedMembre.value)
  isEditing.value = true
}

function cancelEdit() {
  if (selectedMembre.value) editForm.value = editableFromMembre(selectedMembre.value)
  isEditing.value = false
}

async function saveMemberEdit() {
  if (!selectedMembre.value || savingEdit.value) return
  savingEdit.value = true
  error.value = null
  success.value = ''
  try {
    const data = await postJson(`${API_URL}?action=modifier_membre`, {
      ...editForm.value,
      membre_id: selectedMembre.value.id,
    })
    const updated = normalizeMembre(data.data || { ...selectedMembre.value, ...editForm.value })
    selectedMembre.value = updated
    membres.value = membres.value.map((item) => (item.id === updated.id ? updated : item))
    isEditing.value = false
    success.value = data.message || 'Informations du membre mises a jour.'
    fetchStats()
  } catch (e) {
    error.value = e.message || 'Modification impossible'
  } finally {
    savingEdit.value = false
  }
}

function canNotifyCard(membre) {
  return membre.carteKey === 'printed' && !membre.cardNotifiedAt
}

async function notifyCardReady(membre) {
  if (!canNotifyCard(membre) || notifyingIds.value.has(membre.id)) return
  error.value = null
  success.value = ''
  notifyingIds.value = new Set([...notifyingIds.value, membre.id])
  try {
    let data
    const payload = {
      id: membre.id,
      membre_id: membre.id,
      matricule: membre.matricule,
    }
    try {
      data = await postJson(`${API_URL}?action=notifier_retrait_carte`, payload)
    } catch (firstError) {
      const msg = String(firstError.message || '')
      if (!msg.toLowerCase().includes('action')) throw firstError
      data = await postJson(`${API_URL}?action=notify_card_pickup`, payload)
    }
    const notifiedAt = data.notified_at || new Date().toISOString()
    membres.value = membres.value.map((item) => (
      item.id === membre.id ? { ...item, cardNotifiedAt: notifiedAt } : item
    ))
    success.value = data.message || `SMS envoyé à ${membre.nomComplet}.`
  } catch (e) {
    error.value = e.message || 'Notification impossible'
  } finally {
    const next = new Set(notifyingIds.value)
    next.delete(membre.id)
    notifyingIds.value = next
  }
}

function initiales(nom) {
  return nom.trim().split(/\s+/).map((part) => part[0]?.toUpperCase() || '').join('').slice(0, 2)
}

const PALETTE = ['#6366f1', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#3b82f6', '#f97316', '#14b8a6']
function avatarColor(value) {
  let h = 0
  for (const c of value || '?') h = (h * 31 + c.charCodeAt(0)) & 0xffff
  return PALETTE[h % PALETTE.length]
}

function normalizeCardStatus(status) {
  if (status === 'printed') return 'printed'
  if (status === 'retrieved') return 'retrieved'
  return 'pending'
}

function cardStatusLabel(status) {
  return {
    pending: 'A imprimer',
    printed: 'Imprimee',
    retrieved: 'Retiree',
  }[normalizeCardStatus(status)]
}

function formatDate(dateString) {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleDateString('fr-FR', { year: 'numeric', month: 'long', day: 'numeric' })
}

function formatMontant(n) {
  return new Intl.NumberFormat('fr-FR').format(Number(n || 0)) + ' XOF'
}

function exportCSV() {
  const headers = ['Matricule', 'Prenom', 'Nom', 'Contact', 'Region', 'Sous-comite', 'Statut', 'Type', 'Carte', 'Paiement', 'Transaction']
  const rows = filtered.value.map((m) => [
    m.matricule,
    m.prenom,
    m.nom,
    m.contact,
    m.regionLabel,
    m.sousComiteLabel,
    m.statutLabel,
    m.typeLabel,
    m.carteLabel,
    m.statut_paiement,
    m.transaction_id,
  ])
  const csv = [headers, ...rows].map((row) => row.map((v) => `"${String(v || '').replaceAll('"', '""')}"`).join(',')).join('\n')
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = Object.assign(document.createElement('a'), { href: url, download: 'liste_membres.csv' })
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="page" style="margin: -15px">
    <div class="content">
      <div class="breadcrumb">
        <span class="bc-root">SENAFAD</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
        <span class="bc-active">Liste des membres</span>
      </div>

      <div v-if="loading && !membres.length" class="state-block">
        <div class="spinner"></div>
        <p>Chargement des membres...</p>
      </div>

      <div v-else-if="error && !membres.length" class="state-block state-error">
        <p>Impossible de charger les membres</p>
        <code>{{ error }}</code>
        <button class="btn-primary" style="margin-top:10px" @click="refreshAll">Reessayer</button>
      </div>

      <template v-else>
        <div v-if="success" class="search-banner success-banner">{{ success }}</div>
        <div v-if="error" class="search-banner error-banner">{{ error }}</div>

        <div class="page-header">
          <div>
            <h1 class="page-title">Liste des membres</h1>
            <p class="page-sub">
              {{ stat.totalMembers }} membres avec carte payee · {{ stat.totalSecretariats }} secretariats
            </p>
          </div>
          <div class="toolbar-right">
            <button class="btn-outline" @click="refreshAll">Actualiser</button>
            <button class="btn-primary" @click="exportCSV">Exporter CSV</button>
          </div>
        </div>

        <div class="kpi-row kpi-row-5" :class="{ muted: loadingStats }">
          <button class="kpi-card kpi-button" @click="setCardFilter('tous')">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ stat.totalMembers }}</span>
              <span class="kpi-label">Membres payes</span>
            </div>
            <span class="kpi-trend kpi-trend-neutral">+{{ stat.monthMembers }} ce mois</span>
          </button>

          <button class="kpi-card kpi-button" @click="setFilter('ACTUEL')">
            <div class="kpi-icon kpi-green">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val c-green">{{ stat.actuels }}</span>
              <span class="kpi-label">Membres actuels</span>
            </div>
            <span class="kpi-trend kpi-trend-up">{{ stat.validAccounts }} comptes valides</span>
          </button>

          <button class="kpi-card kpi-button" @click="setCardFilter('pending')">
            <div class="kpi-icon kpi-amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ cardsPendingPrint }}</span>
              <span class="kpi-label">En attente d'impression</span>
            </div>
            <span class="kpi-trend kpi-trend-neutral">{{ stat.paidCards }} cartes payees</span>
          </button>

          <button class="kpi-card kpi-button" @click="setCardFilter('printed')">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 8h10M7 12h6"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ cardsPrintedWaiting }}</span>
              <span class="kpi-label">Imprimees, non retirees</span>
            </div>
            <span class="kpi-trend kpi-trend-neutral">En attente</span>
          </button>

          <button class="kpi-card kpi-button" @click="setCardFilter('retrieved')">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M20 6 9 17l-5-5"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ stat.retrievedCards }}</span>
              <span class="kpi-label">Cartes retirees</span>
            </div>
            <span class="kpi-trend kpi-trend-up">{{ retrievalRate }}% retrait</span>
          </button>
        </div>

        <div class="presence-resume-bar">
          <div class="pr-item">
            <span class="pr-val">{{ formatMontant(stat.totalPaye) }}</span>
            <span class="pr-label">Total encaisse</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val">{{ stat.anciens }}</span>
            <span class="pr-label">Anciens membres</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val">{{ cardsPrintedWaiting }}</span>
            <span class="pr-label">Imprimees en attente de retrait</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-progress-wrap">
            <div style="display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;margin-bottom:5px">
              <span>Progression retrait cartes</span>
              <span>{{ retrievalRate }}%</span>
            </div>
            <div class="pr-progress-bar">
              <div class="pr-progress-fill" :style="{ width: retrievalRate + '%' }"></div>
            </div>
          </div>
        </div>

        <div class="secretariat-stats card">
          <button class="section-head section-toggle" type="button" @click="secretariatStatsOpen = !secretariatStatsOpen">
            <div>
              <h2 class="section-title">Stats par secretariat</h2>
              <p class="section-sub">
                {{ statsSecretariats.length || stat.totalSecretariats }} secretariats avec membres payes
                <span v-if="loadingSecretariatStats">· calcul en cours</span>
              </p>
            </div>
            <span class="toggle-indicator" :class="{ open: secretariatStatsOpen }">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </span>
          </button>

          <Transition name="stats-panel">
            <div v-if="secretariatStatsOpen" class="secretariat-panel">
              <div v-if="loadingSecretariatStats && !statsSecretariats.length" class="mini-empty">Chargement des secretariats...</div>
              <div v-else-if="!statsSecretariats.length" class="mini-empty">
                La liste par secretariat n'est pas encore fournie par l'API.
              </div>
              <div v-else class="secretariat-grid">
                <div class="secretariat-row" v-for="item in statsSecretariats" :key="item.secretariat">
                  <div class="secretariat-main">
                    <span class="secretariat-name">{{ item.secretariat }}</span>
                    <span class="secretariat-detail">
                      {{ item.actuels }} actuels · {{ item.anciens }} anciens · {{ item.printed_cards }} cartes imprimees
                    </span>
                  </div>
                  <div class="secretariat-bar">
                    <span :style="{ width: Math.max(5, Math.round(Number(item.total || 0) / maxSecretariatTotal * 100)) + '%' }"></span>
                  </div>
                  <strong class="secretariat-total">{{ item.total }}</strong>
                </div>
              </div>
            </div>
          </Transition>
        </div>

        <div class="toolbar">
          <div class="search-wrap">
            <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input v-model="search" class="search-input" type="search" placeholder="Rechercher nom, matricule, contact, secretariat..." />
            <span v-if="search" class="search-clear" @click="search = ''">×</span>
          </div>

          <div class="toolbar-right">
            <div class="filter-tabs">
              <button class="ftab" :class="{ active: activeFilter === 'tous' }" @click="setFilter('tous')">Tous</button>
              <button class="ftab" :class="{ active: activeFilter === 'ACTUEL' }" @click="setFilter('ACTUEL')">Actuels</button>
              <button class="ftab" :class="{ active: activeFilter === 'ANCIEN' }" @click="setFilter('ANCIEN')">Anciens</button>
            </div>
            <div class="filter-tabs">
              <button class="ftab" :class="{ active: cardFilter === 'tous' }" @click="setCardFilter('tous')">Cartes</button>
              <button class="ftab" :class="{ active: cardFilter === 'pending' }" @click="setCardFilter('pending')">A imprimer</button>
              <button class="ftab" :class="{ active: cardFilter === 'printed' }" @click="setCardFilter('printed')">Imprimees</button>
              <button class="ftab" :class="{ active: cardFilter === 'retrieved' }" @click="setCardFilter('retrieved')">Retirees</button>
            </div>
          </div>
        </div>

        <div v-if="search" class="search-banner">
          Recherche active : <strong>{{ search }}</strong>
          <button class="search-banner-clear" @click="search = ''">Effacer</button>
        </div>

        <div class="card">
          <div v-if="loading" class="state-block">
            <div class="spinner"></div>
            <p>Actualisation des membres...</p>
          </div>

          <div v-else-if="!filtered.length" class="empty">Aucun membre trouve sur cette page.</div>

          <div v-else class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Membre</th>
                  <th>Secretariat</th>
                  <th>Statut</th>
                  <th>Type</th>
                  <th>Carte</th>
                  <th>Paiement</th>
                  <th>Inscription</th>
                  <th>Notification</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in filtered" :key="m.id" class="table-row" :class="{ 'row-old-member': m.type_membre === 'ANCIEN' }">
                  <td>
                    <div class="person">
                      <div v-if="m.photo" class="avatar-photo"><img :src="m.photo" :alt="m.nomComplet" /></div>
                      <div v-else class="avatar" :style="{ background: avatarColor(m.avatar) + '20', color: avatarColor(m.avatar) }">{{ m.avatar }}</div>
                      <div class="person-info">
                        <span class="person-name">{{ m.nomComplet }}</span>
                        <span class="person-mat">{{ m.matricule || 'Sans matricule' }} · {{ m.contact || '-' }}</span>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="td-cell">{{ m.regionLabel }}</span>
                    <span class="person-mat">{{ m.sousComiteLabel }}</span>
                  </td>
                  <td><span class="dortoir-tag">{{ m.statutLabel }}</span></td>
                  <td><span class="td-cell">{{ m.typeLabel }}</span></td>
                  <td>
                    <span class="badge" :class="`b-${m.carteKey}`">{{ m.carteLabel }}</span>
                  </td>
                  <td>
                    <div class="statut-wrap">
                      <span class="dot dot-present"></span>
                      <span class="badge b-present">{{ m.statut_paiement || 'PAYE' }}</span>
                    </div>
                    <span class="person-mat">{{ m.ref_paiement || m.transaction_id || '-' }}</span>
                  </td>
                  <td><span class="td-cell">{{ formatDate(m.created_at) }}</span></td>
                  <td>
                    <button
                      v-if="canNotifyCard(m)"
                      class="notify-btn"
                      :disabled="notifyingIds.has(m.id)"
                      @click="notifyCardReady(m)"
                    >
                      {{ notifyingIds.has(m.id) ? 'Envoi...' : 'Notifier SMS' }}
                    </button>
                    <span v-else-if="m.cardNotifiedAt" class="notified-pill">Déjà notifié</span>
                    <span v-else class="person-mat">-</span>
                  </td>
                  <td>
                    <div class="actions">
                      <button class="act act-view" title="Voir le profil" @click="openModal(m)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pagination">
            <span class="pag-info">
              Affichage de <strong>{{ from }}-{{ to }}</strong> sur <strong>{{ total }}</strong>
            </span>
            <div class="pag-pages">
              <button class="pag-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹</button>
              <button
                v-for="p in visiblePages"
                :key="p"
                class="pag-btn"
                :class="{ active: p === page }"
                @click="goToPage(p)"
              >{{ p }}</button>
              <button class="pag-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">›</button>
            </div>
            <label class="per-page">
              Par page
              <select v-model.number="perPage" class="per-page-select" @change="onPerPageChange">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
              </select>
            </label>
          </div>
        </div>
      </template>
    </div>

    <Transition name="modal">
      <div v-if="isModalOpen" class="overlay" @click.self="closeModal">
        <div class="modal modal-large">
          <div class="modal-header">
            <div class="modal-ident" v-if="selectedMembre">
              <div v-if="selectedMembre.photo" class="modal-avatar-photo">
                <img :src="selectedMembre.photo" :alt="selectedMembre.nomComplet" />
              </div>
              <div
                v-else
                class="modal-avatar"
                :style="{ background: avatarColor(selectedMembre.avatar) + '20', color: avatarColor(selectedMembre.avatar) }"
              >{{ selectedMembre.avatar }}</div>
              <div>
                <h3 class="modal-title">{{ selectedMembre.nomComplet }}</h3>
                <p class="modal-mat">{{ selectedMembre.matricule || 'Sans matricule' }}</p>
                <div class="statut-wrap" style="margin-top:4px;">
                  <span class="dot dot-present"></span>
                  <span class="badge b-present">{{ selectedMembre.statut_paiement || 'PAYE' }}</span>
                  <span class="badge" :class="`b-${selectedMembre.carteKey}`">{{ selectedMembre.carteLabel }}</span>
                </div>
              </div>
            </div>
            <button class="modal-close" @click="closeModal">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <div class="modal-body" v-if="selectedMembre">
            <div v-if="isEditing" class="edit-form">
              <div class="modal-section-title">Modifier les informations</div>
              <div class="form-grid">
                <label class="field"><span>Prenom</span><input v-model="editForm.prenom" /></label>
                <label class="field"><span>Nom</span><input v-model="editForm.nom" /></label>
                <label class="field"><span>Contact</span><input v-model="editForm.contact" /></label>
                <label class="field"><span>Sexe</span><select v-model="editForm.sexe"><option value="">-</option><option value="M">M</option><option value="F">F</option></select></label>
                <label class="field"><span>Date naissance</span><input v-model="editForm.date_naissance" type="date" /></label>
                <label class="field"><span>Lieu naissance</span><input v-model="editForm.lieu_naissance" /></label>
                <label class="field"><span>Secretariat</span><input v-model="editForm.secretariat_poste" /></label>
                <label class="field"><span>Sous-comite</span><input v-model="editForm.sous_comite" /></label>
                <label class="field"><span>Section</span><input v-model="editForm.section" /></label>
                <label class="field"><span>Qualite</span><input v-model="editForm.qualite_membre" /></label>
                <label class="field"><span>Statut</span><input v-model="editForm.statut" /></label>
                <label class="field"><span>Type membre</span><select v-model="editForm.type_membre"><option value="ACTUEL">ACTUEL</option><option value="ANCIEN">ANCIEN</option></select></label>
                <label class="field"><span>Annee debut</span><input v-model="editForm.annee_debut" /></label>
                <label class="field"><span>SR debut</span><input v-model="editForm.sr_debut" /></label>
                <label class="field"><span>Statut carte</span><select v-model="editForm.card_status"><option value="pending">A imprimer</option><option value="printed">Imprimee</option><option value="retrieved">Retiree</option></select></label>
              </div>
            </div>

            <template v-else>
            <div class="modal-section-title">Informations personnelles</div>
            <div class="info-grid">
              <div class="info-item"><label>Sexe</label><span>{{ selectedMembre.sexe || '-' }}</span></div>
              <div class="info-item"><label>Contact</label><span>{{ selectedMembre.contact || '-' }}</span></div>
              <div class="info-item"><label>Date naissance</label><span>{{ formatDate(selectedMembre.date_naissance) }}</span></div>
              <div class="info-item"><label>Lieu naissance</label><span>{{ selectedMembre.lieu_naissance || '-' }}</span></div>
            </div>

            <div class="modal-section-title" style="margin-top:18px;">Engagement AEEMCI</div>
            <div class="info-grid">
              <div class="info-item"><label>Secretariat</label><span>{{ selectedMembre.regionLabel }}</span></div>
              <div class="info-item"><label>Sous-comite</label><span>{{ selectedMembre.sousComiteLabel }}</span></div>
              <div class="info-item"><label>Section</label><span>{{ selectedMembre.section || '-' }}</span></div>
              <div class="info-item"><label>Qualite</label><span>{{ selectedMembre.qualite_membre || '-' }}</span></div>
              <div class="info-item"><label>Statut</label><span>{{ selectedMembre.statutLabel }}</span></div>
              <div class="info-item"><label>Type</label><span>{{ selectedMembre.typeLabel }}</span></div>
              <div class="info-item"><label>Annee debut</label><span>{{ selectedMembre.annee_debut || '-' }}</span></div>
              <div class="info-item"><label>SR debut</label><span>{{ selectedMembre.sr_debut || '-' }}</span></div>
            </div>

            <div class="modal-section-title" style="margin-top:18px;">Paiement et carte</div>
            <div class="info-grid">
              <div class="info-item"><label>Statut paiement</label><span>{{ selectedMembre.statut_paiement || '-' }}</span></div>
              <div class="info-item"><label>Numero Wave</label><span>{{ selectedMembre.numero_wave || '-' }}</span></div>
              <div class="info-item"><label>Reference</label><span>{{ selectedMembre.ref_paiement || '-' }}</span></div>
              <div class="info-item"><label>Transaction</label><span>{{ selectedMembre.transaction_id || '-' }}</span></div>
              <div class="info-item"><label>Carte</label><span>{{ selectedMembre.carteLabel }}</span></div>
              <div class="info-item"><label>Retrait</label><span>{{ selectedMembre.retrieved_at ? formatDate(selectedMembre.retrieved_at) : '-' }}</span></div>
            </div>
            </template>
          </div>

          <div class="modal-footer">
            <button v-if="!isEditing" class="btn-outline" @click="startEdit">Modifier</button>
            <button v-if="isEditing" class="btn-outline" :disabled="savingEdit" @click="cancelEdit">Annuler</button>
            <button v-if="isEditing" class="btn-primary" :disabled="savingEdit" @click="saveMemberEdit">
              {{ savingEdit ? 'Enregistrement...' : 'Enregistrer' }}
            </button>
            <button v-else class="btn-primary" @click="closeModal">Fermer</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.page {
  min-height: 100vh;
  background: #eef0f8;
  font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  display: flex;
  flex-direction: column;
}
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 20px; max-width: 1600px; width: 100%; margin: 0 auto; }
.state-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 20px; color: #6b7280; font-size: 14px; }
.state-error { color: #ef4444; }
.state-error code { font-size: 11px; background: rgba(239,68,68,.07); padding: 4px 10px; border-radius: 6px; color: #dc2626; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6b7280; padding-top: 10px; }
.bc-root { cursor: pointer; }
.bc-root:hover { color: #6366f1; }
.bc-active { color: #111; font-weight: 560; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 720; color: #111; letter-spacing: -.03em; margin-bottom: 3px; }
.page-sub { font-size: 13px; color: #9ca3af; font-weight: 430; }
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi-row-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; }
.kpi-button { width: 100%; text-align: left; font-family: inherit; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
.kpi-button:hover { border-color: rgba(99,102,241,.32); box-shadow: 0 4px 14px rgba(99,102,241,.08); }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-blue { background: rgba(99,102,241,.1); color: #6366f1; }
.kpi-green { background: rgba(16,185,129,.1); color: #10b981; }
.kpi-amber { background: rgba(245,158,11,.1); color: #f59e0b; }
.kpi-data { display: flex; flex-direction: column; flex: 1; }
.kpi-val { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.c-green { color: #10b981; }
.kpi-label { font-size: 11.5px; color: #9ca3af; font-weight: 440; margin-top: 3px; }
.kpi-trend { font-size: 10.5px; font-weight: 570; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.kpi-trend-up { background: rgba(16,185,129,.1); color: #10b981; }
.kpi-trend-neutral { background: rgba(107,114,128,.08); color: #6b7280; }
.muted { opacity: .7; }
.presence-resume-bar { display: flex; align-items: center; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 14px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; gap: 12px; }
.pr-sep { width: 1px; background: rgba(0,0,0,.07); align-self: stretch; }
.pr-item { display: flex; flex-direction: column; align-items: center; gap: 2px; flex: 1; min-width: 90px; }
.pr-val { font-size: 20px; font-weight: 720; color: #111; letter-spacing: -.03em; }
.pr-label { font-size: 10.5px; color: #9ca3af; font-weight: 460; text-align: center; }
.pr-progress-wrap { flex: 1.4; min-width: 200px; }
.pr-progress-bar { height: 5px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
.pr-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); border-radius: 3px; transition: width .4s ease; }
.secretariat-stats { padding: 16px 18px; }
.section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.section-toggle { width: 100%; margin: 0; padding: 0; border: none; background: transparent; font-family: inherit; text-align: left; cursor: pointer; }
.section-title { margin: 0; font-size: 14px; font-weight: 760; color: #111; letter-spacing: -.02em; }
.section-sub { margin: 3px 0 0; font-size: 11.5px; color: #9ca3af; }
.toggle-indicator { width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; color: #6b7280; background: #f3f4f6; transition: transform .18s ease, background .18s ease; flex-shrink: 0; }
.toggle-indicator.open { transform: rotate(180deg); background: rgba(99,102,241,.1); color: #6366f1; }
.secretariat-panel { padding-top: 12px; border-top: 1px solid rgba(0,0,0,.06); }
.secretariat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 14px; }
.secretariat-row { display: grid; grid-template-columns: minmax(130px, 1fr) minmax(90px, 1.1fr) 42px; align-items: center; gap: 10px; min-height: 38px; padding: 8px 10px; border: 1px solid rgba(0,0,0,.055); border-radius: 10px; background: #fafafa; }
.secretariat-main { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.secretariat-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12.5px; font-weight: 700; color: #111; }
.secretariat-detail { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 10.5px; color: #9ca3af; }
.secretariat-bar { height: 6px; border-radius: 999px; background: #eef0f8; overflow: hidden; }
.secretariat-bar span { display: block; height: 100%; border-radius: inherit; background: #6366f1; }
.secretariat-total { text-align: right; font-size: 14px; color: #111; }
.mini-empty { padding: 18px; border: 1px dashed rgba(0,0,0,.12); border-radius: 10px; color: #9ca3af; font-size: 12px; text-align: center; background: #fafafa; }
.stats-panel-enter-active,
.stats-panel-leave-active { transition: opacity .18s ease, transform .18s ease; }
.stats-panel-enter-from,
.stats-panel-leave-to { opacity: 0; transform: translateY(-4px); }
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.search-wrap { position: relative; width: 340px; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border-radius: 50%; cursor: pointer; color: #6b7280; transition: background .13s; }
.search-clear:hover { background: #d1d5db; color: #111; }
.search-input { width: 100%; height: 38px; padding: 0 34px 0 34px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #111; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: border-color .15s, box-shadow .15s; }
.search-input::placeholder { color: #9ca3af; }
.search-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.toolbar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.filter-tabs { display: flex; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 3px; gap: 2px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.ftab { padding: 5px 13px; font-size: 12.5px; font-weight: 480; color: #6b7280; border: none; background: transparent; border-radius: 7px; cursor: pointer; font-family: inherit; transition: background .13s, color .13s; }
.ftab.active { background: #6366f1; color: #fff; font-weight: 580; }
.ftab:hover:not(.active) { background: #f3f4f6; color: #111; }
.search-banner { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(99,102,241,.06); border: 1px solid rgba(99,102,241,.15); border-radius: 10px; font-size: 12.5px; color: #374151; }
.success-banner { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.18); color: #059669; }
.error-banner { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.16); color: #dc2626; }
.search-banner strong { color: #6366f1; }
.search-banner-clear { margin-left: auto; padding: 3px 10px; font-size: 11.5px; font-family: inherit; background: #fff; border: 1px solid rgba(0,0,0,.1); border-radius: 6px; cursor: pointer; color: #6b7280; transition: background .13s; }
.search-banner-clear:hover { background: #f3f4f6; color: #111; }
.btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 570; font-family: inherit; cursor: pointer; letter-spacing: -.01em; box-shadow: 0 2px 8px rgba(99,102,241,.3); transition: background .18s, transform .12s, box-shadow .18s; }
.btn-primary:hover { background: #4f46e5; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
.btn-outline { display: flex; align-items: center; gap: 7px; padding: 9px 14px; background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); border-radius: 10px; font-size: 13px; font-weight: 480; font-family: inherit; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: background .15s; white-space: nowrap; }
.btn-outline:hover { background: #f9fafb; }
.btn-primary:disabled, .btn-outline:disabled { opacity: .55; cursor: wait; }
.card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,.07); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 630; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; user-select: none; }
.table-row { border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.table-row:last-child { border-bottom: none; }
.table-row:hover { background: #fafbff; }
.table-row.row-old-member { background: #f3f4f6; }
.table-row.row-old-member:hover { background: #eef0f3; }
.table-row.row-old-member .person-name,
.table-row.row-old-member .td-cell { color: #6b7280; }
.table td { padding: 12px 16px; vertical-align: middle; }
.person { display: flex; align-items: center; gap: 11px; }
.avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 730; flex-shrink: 0; letter-spacing: .02em; }
.avatar-photo { width: 36px; height: 36px; border-radius: 10px; overflow: hidden; flex-shrink: 0; border: 1px solid rgba(0,0,0,.07); }
.avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.person-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.person-name { font-size: 13.5px; font-weight: 570; color: #111; white-space: nowrap; }
.person-mat { font-size: 11px; color: #9ca3af; font-weight: 450; letter-spacing: .03em; display: block; margin-top: 2px; }
.dortoir-tag { display: inline-block; padding: 3px 9px; background: rgba(99,102,241,.08); color: #6366f1; border-radius: 6px; font-size: 11.5px; font-weight: 570; white-space: nowrap; }
.td-cell { color: #374151; white-space: nowrap; font-size: 13px; }
.statut-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-present { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
.badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 610; padding: 3px 9px; border-radius: 20px; letter-spacing: .02em; white-space: nowrap; }
.b-present, .b-retrieved { background: rgba(16,185,129,.1); color: #059669; }
.b-pending { background: rgba(245,158,11,.1); color: #b45309; }
.b-printed { background: rgba(99,102,241,.1); color: #6366f1; }
.actions { display: flex; align-items: center; gap: 4px; }
.act { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; cursor: pointer; border: 1px solid transparent; transition: background .13s, transform .1s; flex-shrink: 0; }
.act:active { transform: scale(0.93); }
.act-view { background: rgba(99,102,241,.08); color: #6366f1; border-color: rgba(99,102,241,.15); }
.act-view:hover { background: rgba(99,102,241,.15); }
.notify-btn { height: 28px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(16,185,129,.2); background: rgba(16,185,129,.08); color: #059669; border-radius: 8px; padding: 0 10px; font-size: 11.5px; font-weight: 650; font-family: inherit; cursor: pointer; white-space: nowrap; }
.notify-btn:hover:not(:disabled) { background: rgba(16,185,129,.14); }
.notify-btn:disabled { opacity: .55; cursor: wait; }
.notified-pill { display: inline-flex; align-items: center; height: 26px; padding: 0 9px; border-radius: 999px; background: #f3f4f6; color: #6b7280; font-size: 11.5px; font-weight: 650; white-space: nowrap; }
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; }
.pag-info strong { color: #374151; }
.pag-pages { display: flex; align-items: center; gap: 3px; }
.pag-btn { min-width: 30px; height: 30px; padding: 0 6px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; font-size: 12px; font-family: inherit; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .13s; user-select: none; }
.pag-btn:hover:not(:disabled) { background: #f3f4f6; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
.pag-btn:disabled { opacity: .35; cursor: default; }
.per-page { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #9ca3af; }
.per-page-select { border: 1px solid rgba(0,0,0,.09); border-radius: 7px; padding: 4px 8px; font-size: 12px; font-family: inherit; color: #374151; background: #fff; cursor: pointer; }
.empty { text-align: center; padding: 48px; color: #9ca3af; font-size: 13.5px; }
.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); }
.modal { background: #fff; border-radius: 18px; width: 580px; max-width: calc(100vw - 40px); box-shadow: 0 32px 80px rgba(0,0,0,.18); overflow: hidden; display: flex; flex-direction: column; }
.modal-large { width: 640px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; }
.modal-ident { display: flex; align-items: center; gap: 12px; }
.modal-avatar { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 730; flex-shrink: 0; }
.modal-avatar-photo { width: 52px; height: 52px; border-radius: 14px; overflow: hidden; flex-shrink: 0; border: 2px solid rgba(0,0,0,.07); }
.modal-avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.modal-title { font-size: 16px; font-weight: 680; color: #111; letter-spacing: -.02em; margin: 0 0 2px; }
.modal-mat { font-size: 11.5px; color: #9ca3af; margin: 0; letter-spacing: .03em; }
.modal-close { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .13s; }
.modal-close:hover { background: #f3f4f6; color: #111; }
.modal-body { padding: 22px 24px; max-height: 65vh; overflow-y: auto; }
.modal-section-title { font-size: 10px; font-weight: 720; color: #9ca3af; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,.05); }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
.info-item { display: flex; flex-direction: column; gap: 5px; }
.info-item label { font-size: 9.5px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .08em; }
.info-item span { font-size: 13.5px; color: #111; font-weight: 520; overflow-wrap: anywhere; }
.edit-form { display: flex; flex-direction: column; gap: 12px; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.field span { font-size: 10px; font-weight: 720; color: #9ca3af; text-transform: uppercase; letter-spacing: .08em; }
.field input, .field select { width: 100%; height: 38px; border: 1px solid rgba(0,0,0,.1); border-radius: 9px; padding: 0 10px; font-size: 13px; color: #111; background: #fff; font-family: inherit; outline: none; }
.field input:focus, .field select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 16px 24px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; }
.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from { opacity: 0; transform: scale(0.96) translateY(10px); }
.modal-leave-to { opacity: 0; transform: scale(0.96) translateY(10px); }
@media (max-width: 1000px) {
  .kpi-row, .kpi-row-5 { grid-template-columns: 1fr 1fr; }
  .pr-sep { display: none; }
}
@media (max-width: 700px) {
  .content { padding: 10px 16px; }
  .kpi-row { grid-template-columns: 1fr 1fr; }
  .secretariat-grid { grid-template-columns: 1fr; }
  .secretariat-row { grid-template-columns: minmax(100px, 1fr) minmax(70px, .8fr) 36px; }
  .form-grid { grid-template-columns: 1fr; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .search-wrap { width: 100%; }
  .kpi-trend { display: none; }
  .pagination { flex-direction: column; align-items: center; }
  .page-header { flex-direction: column; }
  .filter-tabs { overflow-x: auto; }
}
</style>
