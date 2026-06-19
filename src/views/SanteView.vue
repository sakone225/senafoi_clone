<script setup>
import { ref, computed, onMounted, watch } from 'vue'

// ── State ──────────────────────────────────────────────────────────────────
const loading        = ref(true)
const error          = ref(null)
const exporting      = ref(false)
const savingId       = ref(null)

const search         = ref('')
const activeFilter   = ref('tous')
const page           = ref(1)
const perPage        = ref(25)
const totalFiltered  = ref(0)
const totalPages     = ref(1)

const consultations  = ref([])
const medications    = ref([])
const stats          = ref({})
const anneeActive    = ref(null)

const isModalOpen       = ref(false)
const isConsultModal    = ref(false)
const isStockModal      = ref(false)
const selectedVisit     = ref(null)
const selectedSeminaire = ref(null)
const matriculeInput    = ref('')
const medicationSearch  = ref('')
const stockSearch       = ref('')
const showMedList       = ref(false)
const showAddMedForm    = ref(false)

const alert = ref({ show: false, type: 'success', message: '' })

// ── API ────────────────────────────────────────────────────────────────────
const API_URL      = 'https://api.aeemci-ce.ci/senafoi/seminaristes.php'
const HEALTH_API   = 'https://api.aeemci-ce.ci/senafoi/health_api.php'

const getAuthToken = () => {
  try {
    const t = localStorage.getItem('tokens')
    if (t) return JSON.parse(t).auth_token || null
    const u = localStorage.getItem('user')
    if (u) {
      const usr = JSON.parse(u)
      return usr.tokens?.auth_token || usr.auth_token || usr.token || null
    }
  } catch { return null }
  return null
}

const currentUser = ref(null)
const loadCurrentUser = () => {
  try {
    const u = localStorage.getItem('user')
    if (u) currentUser.value = JSON.parse(u)
  } catch { /* silent */ }
}

async function apiRequest(endpoint, options = {}) {
  const token = getAuthToken()
  const headers = { 'Content-Type': 'application/json', ...options.headers }
  if (token) headers['Authorization'] = `Bearer ${token}`
  const response = await fetch(`${HEALTH_API}?${endpoint}`, { ...options, headers })
  if (!response.ok) throw new Error(`HTTP ${response.status}`)
  return response.json()
}

// ── Formulaires ────────────────────────────────────────────────────────────
const consultForm = ref({ diagnosis: '', selectedMedications: [], notes: '' })
const newMedForm  = ref({ name: '', category: '', quantity: 1, unit: '', minStock: 10 })

// ── Chargement ────────────────────────────────────────────────────────────
async function fetchConsultations(q = '', p = 1) {
  loading.value = true
  error.value = null
  try {
    const params = new URLSearchParams({
      action: 'list_consultations',
      page: p,
      per_page: perPage.value,
    })
    if (q) params.set('search', q)
    if (activeFilter.value !== 'tous') params.set('period_filter', activeFilter.value)

    const data = await apiRequest(params.toString())
    if (!data.success) throw new Error(data.message || 'Erreur API')

    consultations.value = (data.data || []).map(c => ({
      id:          c.id,
      date:        c.date,
      diagnosis:   c.diagnosis,
      notes:       c.notes || '',
      medications: c.medications || [],
      seminarist:  c.seminarist || {},
      doctor:      c.doctor || {},
    }))

    stats.value = data.stats || {}
    anneeActive.value = data.annee_active || null

    const pg = data.pagination || {}
    totalFiltered.value = parseInt(pg.total || 0)
    totalPages.value    = parseInt(pg.last_page || 1)
    page.value          = parseInt(pg.current_page || p)
  } catch (e) {
    error.value = e.message
    consultations.value = []
  } finally {
    loading.value = false
  }
}

async function fetchMedications() {
  try {
    const params = new URLSearchParams({ action: 'list_medications', per_page: 200 })
    const data = await apiRequest(params.toString())
    if (data.success) medications.value = data.data || []
  } catch { /* silent */ }
}

onMounted(async () => {
  loadCurrentUser()
  await Promise.all([fetchConsultations(), fetchMedications()])
})

let _debounce = null
watch(search, q => {
  clearTimeout(_debounce)
  _debounce = setTimeout(() => { page.value = 1; fetchConsultations(q, 1) }, 350)
})

function setFilter(f) { activeFilter.value = f; page.value = 1; fetchConsultations(search.value, 1) }
function goToPage(p)  { if (p < 1 || p > totalPages.value) return; fetchConsultations(search.value, p) }

const visiblePages = computed(() => {
  const total = totalPages.value, cur = page.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  for (let i = cur - 1; i <= cur + 1; i++) if (i > 0 && i <= total) pages.add(i)
  return Array.from(pages).sort((a, b) => a - b)
})

// ── Lookup séminariste par matricule ─────────────────────────────────────
async function handleLookup() {
  const mat = matriculeInput.value.trim()
  if (!mat) { showAlert('info', 'Veuillez saisir un matricule'); return }
  loading.value = true
  try {
    const params = new URLSearchParams({ action: 'list_seminairistes', search: mat, page: 1, per_page: 10, rand: Math.random() })
    const data = await apiRequest(params.toString())
    if (data.success && data.data?.length) {
      const s = data.data.find(x => String(x.matricule_seminaire).toLowerCase() === mat.toLowerCase())
      if (s) {
        selectedSeminaire.value = { id: s.id_seminaire, name: `${s.prenom} ${s.nom}`, matricule: s.matricule_seminaire, seminarist: s }
        isConsultModal.value = true
      } else showAlert('error', `Matricule « ${mat} » introuvable`)
    } else showAlert('error', `Matricule « ${mat} » introuvable`)
  } catch (e) { showAlert('error', 'Erreur lors de la recherche') }
  finally { loading.value = false }
}

// ── Enregistrer consultation ──────────────────────────────────────────────
async function saveConsultation() {
  if (!selectedSeminaire.value) { showAlert('error', 'Séminariste non sélectionné'); return }
  if (!consultForm.value.diagnosis.trim()) { showAlert('error', 'Diagnostic obligatoire'); return }

  for (const m of consultForm.value.selectedMedications) {
    if (m.prescribedQuantity > m.quantity) { showAlert('error', `Stock insuffisant pour ${m.name}`); return }
    if (!m.dosage?.trim()) { showAlert('error', `Posologie manquante pour ${m.name}`); return }
  }

  try {
    const body = {
      seminarist_id: selectedSeminaire.value.id,
      diagnosis:     consultForm.value.diagnosis.trim(),
      notes:         consultForm.value.notes.trim() || null,
      medications:   consultForm.value.selectedMedications.map(m => ({ id: m.id, quantity: m.prescribedQuantity, dosage: m.dosage }))
    }
    const data = await apiRequest('action=create_consultation', { method: 'POST', body: JSON.stringify(body) })
    if (data.success) {
      showAlert('success', 'Consultation enregistrée avec succès')
      closeConsultModal()
      await Promise.all([fetchConsultations(search.value, page.value), fetchMedications()])
    } else throw new Error(data.message)
  } catch (e) { showAlert('error', 'Erreur lors de l\'enregistrement') }
}

// ── Médicaments ────────────────────────────────────────────────────────────
const filteredMeds = computed(() => {
  if (!medicationSearch.value || medicationSearch.value.length < 2) return []
  return medications.value.filter(m => m.name.toLowerCase().includes(medicationSearch.value.toLowerCase()) && m.quantity > 0).slice(0, 6)
})

const filteredStockMeds = computed(() => {
  if (!stockSearch.value) return medications.value
  return medications.value.filter(m => m.name.toLowerCase().includes(stockSearch.value.toLowerCase()) || m.category?.toLowerCase().includes(stockSearch.value.toLowerCase()))
})

function selectMed(m) {
  if (m.quantity === 0) { showAlert('error', 'Médicament en rupture de stock'); return }
  if (consultForm.value.selectedMedications.find(x => x.id === m.id)) { showAlert('info', 'Déjà ajouté'); return }
  consultForm.value.selectedMedications.push({ ...m, prescribedQuantity: 1, dosage: '' })
  showMedList.value = false
  medicationSearch.value = ''
}

function removeMed(idx) { consultForm.value.selectedMedications.splice(idx, 1) }

async function addMedication() {
  if (!newMedForm.value.name || !newMedForm.value.category || !newMedForm.value.unit) {
    showAlert('error', 'Champs obligatoires manquants'); return
  }
  try {
    const data = await apiRequest('action=create_medication', { method: 'POST', body: JSON.stringify({ name: newMedForm.value.name, category: newMedForm.value.category, quantity: newMedForm.value.quantity, unit: newMedForm.value.unit, min_stock: newMedForm.value.minStock }) })
    if (data.success) { showAlert('success', 'Médicament ajouté'); showAddMedForm.value = false; newMedForm.value = { name: '', category: '', quantity: 1, unit: '', minStock: 10 }; await fetchMedications() }
    else throw new Error(data.message)
  } catch { showAlert('error', 'Erreur lors de l\'ajout') }
}

async function increaseStock(m) {
  const qty = prompt(`Quantité à ajouter au stock de ${m.name} :`, '10')
  if (!qty || isNaN(qty) || +qty <= 0) return
  try {
    const data = await apiRequest('action=update_stock', { method: 'POST', body: JSON.stringify({ medication_id: m.id, quantity_change: +qty, reason: 'Ajout manuel' }) })
    if (data.success) { showAlert('success', `+${qty} ${m.unit} pour ${m.name}`); await fetchMedications() }
  } catch { showAlert('error', 'Erreur de mise à jour') }
}

// ── Export ─────────────────────────────────────────────────────────────────
async function exportExcel() {
  if (!consultations.value.length) return
  exporting.value = true
  try {
    const XLSX = await import('xlsx')
    const data = consultations.value.map(c => ({
      'Patient':     c.seminarist?.nom || '—',
      'Matricule':   c.seminarist?.matricule || '—',
      'Médecin':     c.doctor?.name || '—',
      'Date':        formatDate(c.date),
      'Heure':       formatTime(c.date),
      'Diagnostic':  c.diagnosis,
      'Médicaments': c.medications?.map(m => m.name).join(', ') || '—',
      'Notes':       c.notes || '—',
    }))
    const wb = XLSX.utils.book_new()
    const ws = XLSX.utils.json_to_sheet(data)
    ws['!cols'] = [{ wch: 22 }, { wch: 14 }, { wch: 20 }, { wch: 12 }, { wch: 8 }, { wch: 40 }, { wch: 30 }, { wch: 30 }]
    XLSX.utils.book_append_sheet(wb, ws, 'Consultations')
    XLSX.writeFile(wb, `consultations_senafoi_${new Date().toISOString().slice(0, 10)}.xlsx`)
    showAlert('success', 'Export Excel réussi')
  } finally { exporting.value = false }
}

async function exportPDF() {
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
    doc.setFontSize(18); doc.setTextColor(40, 40, 40)
    doc.text('COMMISSION SANTÉ — SENAFOI', 148, 18, { align: 'center' })
    doc.setFontSize(9); doc.setTextColor(120, 120, 120)
    doc.text(`Généré le ${new Date().toLocaleDateString('fr-FR')}`, 148, 25, { align: 'center' })

    let y = 35
    doc.setFontSize(9); doc.setTextColor(40, 40, 40)
    const headers = ['Patient', 'Médecin', 'Date', 'Heure', 'Diagnostic', 'Médicaments']
    const widths = [40, 35, 20, 15, 70, 50]
    let x = 10
    headers.forEach((h, i) => { doc.setFont('helvetica', 'bold'); doc.text(h, x, y); x += widths[i] })
    y += 5; doc.line(10, y, 285, y); y += 4

    consultations.value.forEach(c => {
      if (y > 190) { doc.addPage(); y = 20 }
      x = 10
      const row = [
        c.seminarist?.nom || '—', c.doctor?.name || '—', formatDate(c.date), formatTime(c.date),
        (c.diagnosis || '').substring(0, 60), c.medications?.map(m => m.name).join(', ').substring(0, 50) || '—'
      ]
      doc.setFont('helvetica', 'normal')
      row.forEach((v, i) => { doc.text(String(v), x, y); x += widths[i] })
      y += 6
    })
    doc.save(`consultations_senafoi_${new Date().toISOString().slice(0, 10)}.pdf`)
    showAlert('success', 'Export PDF réussi')
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Modal helpers ──────────────────────────────────────────────────────────
function openDetail(v) { selectedVisit.value = v; isModalOpen.value = true }
function closeDetail()  { isModalOpen.value = false; selectedVisit.value = null }
function closeConsultModal() { isConsultModal.value = false; selectedSeminaire.value = null; consultForm.value = { diagnosis: '', selectedMedications: [], notes: '' }; medicationSearch.value = ''; showMedList.value = false }

// ── Alert ──────────────────────────────────────────────────────────────────
function showAlert(type, message) {
  alert.value = { show: true, type, message }
  setTimeout(() => { alert.value.show = false }, 4500)
}

// ── Helpers ────────────────────────────────────────────────────────────────
function formatDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}
function truncate(str, n) { return str && str.length > n ? str.slice(0, n) + '…' : (str || '—') }
function initiales(nom) { return (nom || '').trim().split(/\s+/).map(p => p[0]?.toUpperCase() || '').join('').slice(0, 2) || '?' }

const PALETTE = ['#6366f1','#ef4444','#10b981','#f59e0b','#8b5cf6','#3b82f6','#f97316','#14b8a6','#ec4899','#06b6d4']
function avatarColor(str) {
  let h = 0
  for (const c of (str || '')) h = (h * 31 + c.charCodeAt(0)) & 0xffff
  return PALETTE[h % PALETTE.length]
}

// ── Stats ─────────────────────────────────────────────────────────────────
const statTotal    = computed(() => consultations.value.length)
const statToday    = computed(() => {
  const t = new Date().toDateString()
  return consultations.value.filter(c => new Date(c.date).toDateString() === t).length
})
const statPatients = computed(() => new Set(consultations.value.map(c => c.seminarist?.matricule)).size)
const statStock    = computed(() => medications.value.reduce((s, m) => s + (m.quantity || 0), 0))
const statLowStock = computed(() => medications.value.filter(m => m.quantity <= (m.minStock || 10) && m.quantity > 0).length)
</script>

<template>
  <div class="page" style="margin: -15px">
    <div class="content">

      <!-- ── Breadcrumb ── -->
      <div class="breadcrumb">
        <span class="bc-root">Séminaires</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="bc-active">Commission Santé</span>
      </div>

      <!-- ── Loading / Error ── -->
      <div v-if="loading" class="state-block">
        <div class="spinner"></div>
        <p>Chargement des consultations…</p>
      </div>

      <div v-else-if="error" class="state-block state-error">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <p>Impossible de charger les données</p>
        <code>{{ error }}</code>
      </div>

      <template v-else>

        <!-- ── Page Header ── -->
        <div class="page-header">
          <div>
            <h1 class="page-title">Commission Santé</h1>
            <p class="page-sub">
              Consultations médicales · SENAFOI {{ anneeActive }}
              <span v-if="currentUser" class="doctor-badge">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                Dr. {{ currentUser.name }}
              </span>
            </p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="btn-outline" @click="isStockModal = true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 5h13.3M10 20a1 1 0 1 0 2 0 1 1 0 0 0-2 0M20 20a1 1 0 1 0 2 0 1 1 0 0 0-2 0"/></svg>
              Stock médicaments
              <span v-if="statLowStock > 0" class="stock-alert-dot">{{ statLowStock }}</span>
            </button>
            <button class="btn-outline" :disabled="exporting" @click="exportExcel">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Excel
            </button>
            <button class="btn-outline" :disabled="exporting" @click="exportPDF">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              PDF
            </button>
            <button class="btn-primary" @click="isConsultModal = true; selectedSeminaire = null; matriculeInput = ''">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Nouvelle consultation
            </button>
          </div>
        </div>

        <!-- Export banner -->
        <div v-if="exporting" class="export-banner">
          <div class="spinner" style="width:18px;height:18px;border-width:2px;"></div>
          Génération en cours…
        </div>

        <!-- ── KPI Cards ── -->
        <div class="kpi-row">
          <div class="kpi-card">
            <div class="kpi-icon kpi-red">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ statTotal }}</span>
              <span class="kpi-label">Consultations totales</span>
            </div>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ statPatients }}</span>
              <span class="kpi-label">Patients traités</span>
            </div>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-green">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val c-green">{{ statToday }}</span>
              <span class="kpi-label">Aujourd'hui</span>
            </div>
            <span class="kpi-trend kpi-trend-up">En cours</span>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ statStock }}</span>
              <span class="kpi-label">Unités en stock</span>
            </div>
            <span v-if="statLowStock > 0" class="kpi-trend kpi-trend-down">{{ statLowStock }} stock faible</span>
            <span v-else class="kpi-trend kpi-trend-up">Stocks OK</span>
          </div>
        </div>

        <!-- ── Toolbar ── -->
        <div class="toolbar">
          <!-- Recherche par matricule -->
          <div class="lookup-wrap">
            <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input
              v-model="matriculeInput"
              class="search-input"
              placeholder="Matricule → nouvelle consultation"
              @keyup.enter="handleLookup"
              style="text-transform:uppercase"
            />
            <button class="lookup-btn" @click="handleLookup">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              Consulter
            </button>
          </div>
          <div class="toolbar-right">
            <!-- Filtre période -->
            <div class="filter-tabs">
              <button class="ftab" :class="{ active: activeFilter === 'tous' }" @click="setFilter('tous')">Tous</button>
              <button class="ftab" :class="{ active: activeFilter === 'today' }" @click="setFilter('today')">Aujourd'hui</button>
              <button class="ftab" :class="{ active: activeFilter === 'week' }" @click="setFilter('week')">Semaine</button>
              <button class="ftab" :class="{ active: activeFilter === 'month' }" @click="setFilter('month')">Mois</button>
            </div>
            <!-- Recherche texte -->
            <div class="search-wrap">
              <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input v-model="search" class="search-input" placeholder="Rechercher diagnostic, patient…" />
              <span v-if="search" class="search-clear" @click="search = ''">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </span>
            </div>
          </div>
        </div>

        <!-- ── Table Card ── -->
        <div class="card">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Patient</th>
                  <th>Médecin</th>
                  <th>Date &amp; Heure</th>
                  <th>Diagnostic</th>
                  <th>Prescription</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in consultations" :key="c.id" class="table-row">

                  <!-- Patient -->
                  <td>
                    <div class="person">
                      <div v-if="c.seminarist?.photo" class="avatar-photo">
                        <img :src="c.seminarist.photo" :alt="c.seminarist.nom" />
                      </div>
                      <div v-else class="avatar" :style="{ background: avatarColor(c.seminarist?.nom || '') + '20', color: avatarColor(c.seminarist?.nom || '') }">
                        {{ initiales(c.seminarist?.nom || '?') }}
                      </div>
                      <div class="person-info">
                        <span class="person-name">{{ c.seminarist?.nom || '—' }}</span>
                        <span class="person-mat">{{ c.seminarist?.matricule || '—' }}</span>
                      </div>
                    </div>
                  </td>

                  <!-- Médecin -->
                  <td>
                    <div class="person">
                      <div v-if="c.doctor?.photo" class="avatar-photo">
                        <img :src="c.doctor.photo" :alt="c.doctor.name" />
                      </div>
                      <div v-else class="avatar avatar-doctor" :style="{ background: avatarColor(c.doctor?.name || '') + '20', color: avatarColor(c.doctor?.name || '') }">
                        {{ initiales(c.doctor?.name || 'Dr') }}
                      </div>
                      <div class="person-info">
                        <span class="person-name">{{ c.doctor?.name ? `Dr. ${c.doctor.name}` : '—' }}</span>
                        <span class="person-mat">{{ c.doctor?.matricule || '—' }}</span>
                      </div>
                    </div>
                  </td>

                  <!-- Date -->
                  <td>
                    <div class="datetime">
                      <span class="dt-date">{{ formatDate(c.date) }}</span>
                      <span class="dt-time">{{ formatTime(c.date) }}</span>
                    </div>
                  </td>

                  <!-- Diagnostic -->
                  <td class="td-diag">{{ truncate(c.diagnosis, 65) }}</td>

                  <!-- Prescription -->
                  <td>
                    <div v-if="c.medications?.length" class="med-badge">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                      {{ c.medications.length }} médicament{{ c.medications.length > 1 ? 's' : '' }}
                    </div>
                    <span v-else class="no-med">—</span>
                  </td>

                  <!-- Actions -->
                  <td>
                    <div class="actions">
                      <button class="act act-view" @click="openDetail(c)" title="Voir le détail">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <span class="status-dot status-done" title="Consultation terminée"></span>
                    </div>
                  </td>
                </tr>

                <tr v-if="consultations.length === 0 && !loading">
                  <td colspan="6" class="empty">
                    <div class="empty-inner">
                      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                      <p>Aucune consultation trouvée</p>
                      <span>Enregistrez une nouvelle consultation via le bouton ci-dessus</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="pagination">
            <span class="pag-info">
              Affichage de <strong>{{ consultations.length }}</strong> sur <strong>{{ totalFiltered }}</strong> consultation{{ totalFiltered > 1 ? 's' : '' }}
            </span>
            <div class="pag-pages">
              <button class="pag-btn" :disabled="page <= 1" @click="goToPage(page - 1)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
              </button>
              <template v-for="(p, i) in visiblePages" :key="p">
                <span v-if="i > 0 && p - visiblePages[i-1] > 1" class="pag-ellipsis">…</span>
                <button class="pag-btn" :class="{ active: p === page }" @click="goToPage(p)">{{ p }}</button>
              </template>
              <button class="pag-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg>
              </button>
            </div>
            <div class="per-page">
              <span>Par page</span>
              <select class="per-page-select" v-model="perPage" @change="page = 1; fetchConsultations(search, 1)">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
              </select>
            </div>
          </div>
        </div>

      </template>
    </div>

    <!-- ══ MODAL — Détail consultation ══ -->
    <Transition name="modal">
      <div v-if="isModalOpen" class="overlay" @click.self="closeDetail">
        <div class="modal modal-large">
          <div class="modal-header">
            <div class="modal-ident" v-if="selectedVisit">
              <div v-if="selectedVisit.seminarist?.photo" class="modal-avatar-photo">
                <img :src="selectedVisit.seminarist.photo" :alt="selectedVisit.seminarist.nom" />
              </div>
              <div v-else class="modal-avatar" :style="{ background: avatarColor(selectedVisit.seminarist?.nom || '') + '20', color: avatarColor(selectedVisit.seminarist?.nom || '') }">
                {{ initiales(selectedVisit.seminarist?.nom || '?') }}
              </div>
              <div>
                <h3 class="modal-title">{{ selectedVisit.seminarist?.nom || '—' }}</h3>
                <p class="modal-mat">{{ selectedVisit.seminarist?.matricule || '—' }} · {{ formatDate(selectedVisit.date) }} à {{ formatTime(selectedVisit.date) }}</p>
              </div>
            </div>
            <button class="modal-close" @click="closeDetail">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="modal-body" v-if="selectedVisit">
            <!-- Médecin -->
            <div class="modal-section-title">Médecin traitant</div>
            <div class="doctor-row">
              <div v-if="selectedVisit.doctor?.photo" class="modal-avatar-photo" style="width:42px;height:42px">
                <img :src="selectedVisit.doctor.photo" :alt="selectedVisit.doctor.name" />
              </div>
              <div v-else class="modal-avatar" style="width:42px;height:42px;font-size:12px;" :style="{ background: avatarColor(selectedVisit.doctor?.name || '') + '20', color: avatarColor(selectedVisit.doctor?.name || '') }">
                {{ initiales(selectedVisit.doctor?.name || 'Dr') }}
              </div>
              <div>
                <p style="font-size:13.5px;font-weight:570;color:#111;margin:0">{{ selectedVisit.doctor?.name ? `Dr. ${selectedVisit.doctor.name}` : '—' }}</p>
                <p style="font-size:11px;color:#9ca3af;margin:0">{{ selectedVisit.doctor?.matricule || '—' }}</p>
              </div>
            </div>

            <!-- Diagnostic -->
            <div class="modal-section-title" style="margin-top:18px">Diagnostic</div>
            <div class="diag-block">{{ selectedVisit.diagnosis }}</div>

            <!-- Prescription -->
            <div v-if="selectedVisit.medications?.length" class="modal-section-title" style="margin-top:18px">Prescription</div>
            <div v-if="selectedVisit.medications?.length" class="med-list">
              <div v-for="m in selectedVisit.medications" :key="m.id" class="med-item">
                <div class="med-item-name">{{ m.name }}</div>
                <div class="med-item-detail">{{ m.dosage }} · {{ m.quantity }} {{ m.unit }}</div>
              </div>
            </div>

            <!-- Notes -->
            <div v-if="selectedVisit.notes" class="modal-section-title" style="margin-top:18px">Notes</div>
            <div v-if="selectedVisit.notes" class="notes-block">{{ selectedVisit.notes }}</div>
          </div>

          <div class="modal-footer">
            <button class="btn-primary" @click="closeDetail">Fermer</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ══ MODAL — Nouvelle consultation ══ -->
    <Transition name="modal">
      <div v-if="isConsultModal" class="overlay" @click.self="closeConsultModal">
        <div class="modal modal-large">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="kpi-icon kpi-red" style="width:42px;height:42px;border-radius:11px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              </div>
              <div>
                <h3 class="modal-title">Nouvelle consultation</h3>
                <p class="modal-mat">{{ selectedSeminaire ? selectedSeminaire.name : 'Rechercher un séminariste par matricule' }}</p>
              </div>
            </div>
            <button class="modal-close" @click="closeConsultModal">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="modal-body">
            <!-- Lookup -->
            <div v-if="!selectedSeminaire">
              <div class="modal-section-title">Séminariste</div>
              <div class="lookup-row">
                <input
                  v-model="matriculeInput"
                  class="form-input"
                  placeholder="Matricule séminariste (ex: SEM-0042)"
                  style="text-transform:uppercase;flex:1"
                  @keyup.enter="handleLookup"
                />
                <button class="btn-primary" @click="handleLookup" style="white-space:nowrap">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                  Rechercher
                </button>
              </div>
            </div>

            <!-- Patient trouvé -->
            <div v-if="selectedSeminaire" class="patient-found-card">
              <div v-if="selectedSeminaire.seminarist?.photo" class="modal-avatar-photo" style="width:44px;height:44px">
                <img :src="selectedSeminaire.seminarist.photo" :alt="selectedSeminaire.name" />
              </div>
              <div v-else class="modal-avatar" :style="{ background: avatarColor(selectedSeminaire.name) + '20', color: avatarColor(selectedSeminaire.name) }">
                {{ initiales(selectedSeminaire.name) }}
              </div>
              <div style="flex:1">
                <p style="font-size:14px;font-weight:600;color:#111;margin:0">{{ selectedSeminaire.name }}</p>
                <p style="font-size:11.5px;color:#9ca3af;margin:2px 0 0">{{ selectedSeminaire.matricule }}</p>
              </div>
              <button class="act act-view" @click="selectedSeminaire = null" title="Changer de séminariste" style="margin-left:auto">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </button>
            </div>

            <!-- Médecin connecté -->
            <div v-if="currentUser" class="doctor-readonly-card">
              <div class="kpi-icon kpi-blue" style="width:38px;height:38px;border-radius:10px;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              </div>
              <div>
                <p style="font-size:13px;font-weight:600;color:#111;margin:0">Dr. {{ currentUser.name }}</p>
                <p style="font-size:11px;color:#9ca3af;margin:0">{{ currentUser.matricule }} · Médecin traitant</p>
              </div>
            </div>

            <!-- Form -->
            <div v-if="selectedSeminaire">
              <!-- Diagnostic -->
              <div class="modal-section-title" style="margin-top:16px">Diagnostic *</div>
              <textarea v-model="consultForm.diagnosis" class="form-textarea" rows="3" placeholder="Décrivez le diagnostic médical…" required></textarea>

              <!-- Médicaments -->
              <div class="modal-section-title" style="margin-top:16px">Prescription</div>
              <div class="med-selector">
                <div class="med-search-row">
                  <input
                    v-model="medicationSearch"
                    class="form-input"
                    placeholder="Rechercher un médicament…"
                    @input="showMedList = medicationSearch.length > 1"
                    @focus="showMedList = medicationSearch.length > 1"
                    style="flex:1"
                  />
                </div>
                <div v-if="showMedList && filteredMeds.length" class="med-dropdown">
                  <div v-for="m in filteredMeds" :key="m.id" class="med-option" @click="selectMed(m)">
                    <div>
                      <p style="margin:0;font-size:13px;font-weight:550;color:#111">{{ m.name }}</p>
                      <p style="margin:0;font-size:11px;color:#9ca3af">{{ m.category }} · Stock: {{ m.quantity }} {{ m.unit }}</p>
                    </div>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  </div>
                </div>
                <div v-if="consultForm.selectedMedications.length" class="selected-meds">
                  <div v-for="(m, idx) in consultForm.selectedMedications" :key="idx" class="selected-med-row">
                    <div class="dortoir-tag" style="min-width:120px">{{ m.name }}</div>
                    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280">
                      <label>Qté</label>
                      <input v-model.number="m.prescribedQuantity" type="number" min="1" :max="m.quantity" class="qty-input" />
                      <span>{{ m.unit }}</span>
                    </div>
                    <input v-model="m.dosage" type="text" class="form-input" style="flex:1;font-size:12px" placeholder="Posologie (ex: 1 cp 3x/j)" />
                    <button class="act" style="background:rgba(239,68,68,.08);color:#ef4444;border-color:rgba(239,68,68,.15)" @click="removeMed(idx)">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Notes -->
              <div class="modal-section-title" style="margin-top:16px">Notes médicales</div>
              <textarea v-model="consultForm.notes" class="form-textarea" rows="2" placeholder="Observations, recommandations…"></textarea>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn-outline" @click="closeConsultModal">Annuler</button>
            <button class="btn-primary" :disabled="!selectedSeminaire" @click="saveConsultation">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Enregistrer
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ══ MODAL — Stock médicaments ══ -->
    <Transition name="modal">
      <div v-if="isStockModal" class="overlay" @click.self="isStockModal = false">
        <div class="modal" style="width:720px;max-width:calc(100vw - 40px)">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="kpi-icon kpi-amber" style="width:42px;height:42px;border-radius:11px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
              </div>
              <div>
                <h3 class="modal-title">Stock médicaments</h3>
                <p class="modal-mat">{{ medications.length }} références · {{ statStock }} unités</p>
              </div>
            </div>
            <button class="modal-close" @click="isStockModal = false">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>

          <div class="modal-body">
            <!-- Barre actions -->
            <div style="display:flex;gap:8px;margin-bottom:14px;align-items:center">
              <div class="search-wrap" style="flex:1">
                <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input v-model="stockSearch" class="search-input" placeholder="Rechercher un médicament…" />
              </div>
              <button class="btn-outline" @click="showAddMedForm = !showAddMedForm">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Ajouter
              </button>
            </div>

            <!-- Form ajout -->
            <div v-if="showAddMedForm" class="add-med-form">
              <div class="add-med-grid">
                <div class="form-group">
                  <label class="form-label-sm">Nom *</label>
                  <input v-model="newMedForm.name" class="form-input" placeholder="Paracétamol 500mg" />
                </div>
                <div class="form-group">
                  <label class="form-label-sm">Catégorie *</label>
                  <select v-model="newMedForm.category" class="form-input">
                    <option value="">— Choisir —</option>
                    <option>Antalgique</option>
                    <option>Anti-inflammatoire</option>
                    <option>Antibiotique</option>
                    <option>Vitamine</option>
                    <option>Antitussif</option>
                    <option>Autre</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label-sm">Quantité *</label>
                  <input v-model.number="newMedForm.quantity" type="number" min="1" class="form-input" />
                </div>
                <div class="form-group">
                  <label class="form-label-sm">Unité *</label>
                  <select v-model="newMedForm.unit" class="form-input">
                    <option value="">— Choisir —</option>
                    <option>comprimés</option>
                    <option>gélules</option>
                    <option>flacons</option>
                    <option>ampoules</option>
                    <option>tubes</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label-sm">Stock min.</label>
                  <input v-model.number="newMedForm.minStock" type="number" min="0" class="form-input" />
                </div>
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
                <button class="btn-outline" @click="showAddMedForm = false; newMedForm = { name:'', category:'', quantity:1, unit:'', minStock:10 }">Annuler</button>
                <button class="btn-primary" @click="addMedication">Ajouter</button>
              </div>
            </div>

            <!-- Table stock -->
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr>
                    <th>Médicament</th>
                    <th>Catégorie</th>
                    <th>Quantité</th>
                    <th>Statut</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="m in filteredStockMeds" :key="m.id" class="table-row" :class="{ 'row-low': m.quantity <= (m.minStock || 10) && m.quantity > 0, 'row-out': m.quantity === 0 }">
                    <td>
                      <span class="person-name" style="font-size:13px">{{ m.name }}</span>
                    </td>
                    <td>
                      <span class="dortoir-tag">{{ m.category }}</span>
                    </td>
                    <td>
                      <span style="font-size:14px;font-weight:620;color:#111">{{ m.quantity }}</span>
                      <span style="font-size:11px;color:#9ca3af;margin-left:4px">{{ m.unit }}</span>
                    </td>
                    <td>
                      <div class="statut-wrap">
                        <span v-if="m.quantity === 0" class="dot dot-absent"></span>
                        <span v-else-if="m.quantity <= (m.minStock || 10)" class="dot dot-low"></span>
                        <span v-else class="dot dot-present"></span>
                        <span class="badge" :class="m.quantity === 0 ? 'b-absent' : m.quantity <= (m.minStock || 10) ? 'b-low' : 'b-present'">
                          {{ m.quantity === 0 ? 'Rupture' : m.quantity <= (m.minStock || 10) ? 'Stock faible' : 'En stock' }}
                        </span>
                      </div>
                    </td>
                    <td>
                      <button class="act act-view" @click="increaseStock(m)" title="Augmenter le stock">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                      </button>
                    </td>
                  </tr>
                  <tr v-if="!filteredStockMeds.length">
                    <td colspan="5" class="empty"><div class="empty-inner"><p>Aucun médicament trouvé</p></div></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="modal-footer">
            <button class="btn-primary" @click="isStockModal = false">Fermer</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ── Toast ── -->
    <Transition name="toast">
      <div v-if="alert.show" class="toast-wrap">
        <div class="toast" :class="`toast-${alert.type}`">
          <svg v-if="alert.type === 'success'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          <svg v-else-if="alert.type === 'error'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span>{{ alert.message }}</span>
          <button @click="alert.show = false" class="toast-close">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
/* ── Reset & Base ── */
* { box-sizing: border-box; }
.page { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; display: flex; flex-direction: column; }
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 20px; max-width: 1600px; width: 100%; margin: 0 auto; }

/* ── States ── */
.state-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 20px; color: #6b7280; font-size: 14px; }
.state-error { color: #ef4444; }
.state-error code { font-size: 11px; background: rgba(239,68,68,.07); padding: 4px 10px; border-radius: 6px; color: #dc2626; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Breadcrumb ── */
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6b7280; padding-top: 10px; }
.bc-root { cursor: pointer; } .bc-root:hover { color: #6366f1; }
.bc-active { color: #111; font-weight: 560; }

/* ── Export banner ── */
.export-banner { display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.18); border-radius: 10px; font-size: 12.5px; color: #6366f1; }

/* ── Page Header ── */
.page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 720; color: #111; letter-spacing: -.03em; margin-bottom: 3px; }
.page-sub { font-size: 13px; color: #9ca3af; font-weight: 430; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.doctor-badge { display: inline-flex; align-items: center; gap: 4px; background: rgba(16,185,129,.1); color: #059669; font-size: 11px; font-weight: 580; padding: 3px 9px; border-radius: 20px; }

/* ── KPI Row ── */
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-red   { background: rgba(239,68,68,.1);   color: #ef4444; }
.kpi-blue  { background: rgba(99,102,241,.1);  color: #6366f1; }
.kpi-green { background: rgba(16,185,129,.1);  color: #10b981; }
.kpi-amber { background: rgba(245,158,11,.1);  color: #f59e0b; }
.kpi-data  { display: flex; flex-direction: column; flex: 1; }
.kpi-val   { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.kpi-label { font-size: 11.5px; color: #9ca3af; font-weight: 440; margin-top: 3px; }
.kpi-trend { font-size: 10.5px; font-weight: 570; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.kpi-trend-up   { background: rgba(16,185,129,.1);  color: #10b981; }
.kpi-trend-down { background: rgba(239,68,68,.08);  color: #ef4444; }
.c-green { color: #10b981; }
.stock-alert-dot { display: inline-flex; align-items: center; justify-content: center; width: 17px; height: 17px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; border-radius: 50%; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.lookup-wrap { display: flex; align-items: center; gap: 6px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 0 6px 0 32px; position: relative; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.lookup-wrap .search-icon { position: absolute; left: 12px; color: #9ca3af; pointer-events: none; }
.lookup-wrap .search-input { width: 220px; height: 38px; border: none; outline: none; font-size: 13px; font-family: inherit; color: #111; background: transparent; }
.lookup-wrap .search-input::placeholder { color: #9ca3af; }
.lookup-btn { display: flex; align-items: center; gap: 5px; padding: 5px 11px; background: rgba(99,102,241,.1); color: #6366f1; border: none; border-radius: 7px; font-size: 12px; font-weight: 570; font-family: inherit; cursor: pointer; transition: background .15s; }
.lookup-btn:hover { background: rgba(99,102,241,.18); }
.toolbar-right { display: flex; align-items: center; gap: 8px; margin-left: auto; flex-wrap: wrap; }
.filter-tabs { display: flex; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 3px; gap: 2px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.ftab { padding: 5px 12px; font-size: 12px; font-weight: 480; color: #6b7280; border: none; background: transparent; border-radius: 7px; cursor: pointer; font-family: inherit; transition: background .13s, color .13s; }
.ftab.active { background: #6366f1; color: #fff; font-weight: 580; }
.ftab:hover:not(.active) { background: #f3f4f6; color: #111; }
.search-wrap { position: relative; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border-radius: 50%; cursor: pointer; color: #6b7280; }
.search-clear:hover { background: #d1d5db; }
.search-input { height: 38px; padding: 0 34px 0 34px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #111; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: border-color .15s; width: 240px; }
.search-input:focus { border-color: #6366f1; }

/* ── Buttons ── */
.btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 570; font-family: inherit; cursor: pointer; box-shadow: 0 2px 8px rgba(99,102,241,.3); transition: background .18s; }
.btn-primary:hover { background: #4f46e5; }
.btn-primary:disabled { opacity: .45; cursor: default; }
.btn-outline { display: flex; align-items: center; gap: 7px; padding: 9px 14px; background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); border-radius: 10px; font-size: 13px; font-weight: 480; font-family: inherit; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: background .15s; white-space: nowrap; }
.btn-outline:hover { background: #f9fafb; }
.btn-outline:disabled { opacity: .45; cursor: default; }

/* ── Card & Table ── */
.card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,.07); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 630; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
.table-row { border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.table-row:last-child { border-bottom: none; }
.table-row:hover { background: #fafbff; }
.table-row.row-low { background: rgba(245,158,11,.04); }
.table-row.row-out { background: rgba(239,68,68,.04); }
.table td { padding: 12px 16px; vertical-align: middle; }

/* Person */
.person { display: flex; align-items: center; gap: 11px; }
.avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 730; flex-shrink: 0; }
.avatar-doctor { border-radius: 50%; }
.avatar-photo { width: 36px; height: 36px; border-radius: 10px; overflow: hidden; flex-shrink: 0; }
.avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.person-info { display: flex; flex-direction: column; gap: 1px; }
.person-name { font-size: 13.5px; font-weight: 570; color: #111; white-space: nowrap; }
.person-mat { font-size: 11px; color: #9ca3af; font-weight: 450; }
.dortoir-tag { display: inline-block; padding: 3px 9px; background: rgba(99,102,241,.08); color: #6366f1; border-radius: 6px; font-size: 11.5px; font-weight: 570; white-space: nowrap; }
.datetime { display: flex; flex-direction: column; gap: 1px; }
.dt-date { font-size: 13px; font-weight: 550; color: #111; }
.dt-time { font-size: 11px; color: #9ca3af; }
.td-diag { color: #374151; font-size: 13px; max-width: 280px; }
.med-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; background: rgba(16,185,129,.1); color: #059669; border-radius: 6px; font-size: 11.5px; font-weight: 570; }
.no-med { color: #9ca3af; font-size: 13px; }
.statut-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-present { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
.dot-absent  { background: #ef4444; box-shadow: 0 0 0 2px rgba(239,68,68,.2); }
.dot-low     { background: #f59e0b; box-shadow: 0 0 0 2px rgba(245,158,11,.2); }
.badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 610; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.b-present { background: rgba(16,185,129,.1);  color: #059669; }
.b-absent  { background: rgba(239,68,68,.1);   color: #dc2626; }
.b-low     { background: rgba(245,158,11,.1);  color: #b45309; }
.status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.status-done { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
.actions { display: flex; align-items: center; gap: 8px; }
.act { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; cursor: pointer; border: 1px solid transparent; transition: background .13s; flex-shrink: 0; }
.act:disabled { opacity: .4; cursor: default; }
.act-view { background: rgba(99,102,241,.08); color: #6366f1; border-color: rgba(99,102,241,.15); }
.act-view:hover { background: rgba(99,102,241,.15); }

/* Empty */
.empty { padding: 0; }
.empty-inner { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 48px 20px; color: #9ca3af; }
.empty-inner p { margin: 0; font-size: 14px; font-weight: 500; color: #6b7280; }
.empty-inner span { font-size: 12.5px; }
.empty-inner svg { color: #d1d5db; }

/* Pagination */
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; }
.pag-info strong { color: #374151; }
.pag-pages { display: flex; align-items: center; gap: 3px; }
.pag-ellipsis { font-size: 12px; color: #9ca3af; padding: 0 4px; }
.pag-btn { min-width: 30px; height: 30px; padding: 0 6px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; font-size: 12px; font-family: inherit; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .13s; }
.pag-btn:hover:not(:disabled) { background: #f3f4f6; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
.pag-btn:disabled { opacity: .35; cursor: default; }
.per-page { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #9ca3af; }
.per-page-select { border: 1px solid rgba(0,0,0,.09); border-radius: 7px; padding: 4px 8px; font-size: 12px; font-family: inherit; color: #374151; background: #fff; cursor: pointer; }

/* ── Modal ── */
.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); }
.modal { background: #fff; border-radius: 18px; width: 600px; max-width: calc(100vw - 40px); max-height: 90vh; overflow-y: auto; box-shadow: 0 32px 80px rgba(0,0,0,.18); display: flex; flex-direction: column; }
.modal-large { width: 660px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; position: sticky; top: 0; z-index: 1; }
.modal-ident { display: flex; align-items: center; gap: 12px; }
.modal-avatar { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 730; flex-shrink: 0; }
.modal-avatar-photo { width: 52px; height: 52px; border-radius: 14px; overflow: hidden; flex-shrink: 0; border: 2px solid rgba(0,0,0,.07); }
.modal-avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.modal-title { font-size: 16px; font-weight: 680; color: #111; letter-spacing: -.02em; margin: 0 0 2px; }
.modal-mat { font-size: 11.5px; color: #9ca3af; margin: 0; }
.modal-close { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .13s; }
.modal-close:hover { background: #f3f4f6; }
.modal-body { padding: 22px 24px; flex: 1; }
.modal-section-title { font-size: 10px; font-weight: 720; color: #9ca3af; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,.05); }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 16px 24px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; position: sticky; bottom: 0; }
.doctor-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #f9fafb; border-radius: 10px; border: 1px solid rgba(0,0,0,.06); }
.diag-block { padding: 12px 14px; background: #f9fafb; border-radius: 10px; font-size: 13.5px; color: #111; line-height: 1.6; border: 1px solid rgba(0,0,0,.06); }
.notes-block { padding: 12px 14px; background: rgba(99,102,241,.04); border-radius: 10px; font-size: 13px; color: #374151; line-height: 1.6; border: 1px solid rgba(99,102,241,.1); }
.med-list { display: flex; flex-direction: column; gap: 8px; }
.med-item { padding: 10px 14px; background: #f9fafb; border-radius: 10px; border: 1px solid rgba(0,0,0,.06); }
.med-item-name { font-size: 13px; font-weight: 570; color: #111; }
.med-item-detail { font-size: 11.5px; color: #6b7280; margin-top: 2px; }
.patient-found-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: rgba(16,185,129,.05); border: 1px solid rgba(16,185,129,.2); border-radius: 10px; margin-bottom: 4px; }
.doctor-readonly-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: rgba(99,102,241,.05); border: 1px solid rgba(99,102,241,.15); border-radius: 10px; margin-top: 10px; }
.lookup-row { display: flex; align-items: center; gap: 8px; }
.form-input { height: 38px; padding: 0 12px; border: 1px solid rgba(0,0,0,.1); border-radius: 9px; font-size: 13px; font-family: inherit; color: #111; outline: none; width: 100%; transition: border-color .15s; }
.form-input:focus { border-color: #6366f1; }
.form-textarea { width: 100%; padding: 10px 12px; border: 1px solid rgba(0,0,0,.1); border-radius: 9px; font-size: 13px; font-family: inherit; color: #111; outline: none; resize: vertical; transition: border-color .15s; }
.form-textarea:focus { border-color: #6366f1; }
.med-selector { position: relative; }
.med-search-row { display: flex; gap: 8px; }
.med-dropdown { position: absolute; left: 0; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid rgba(0,0,0,.1); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.1); z-index: 20; overflow: hidden; }
.med-option { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.med-option:last-child { border-bottom: none; }
.med-option:hover { background: #f9fafb; }
.selected-meds { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
.selected-med-row { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: #f9fafb; border-radius: 9px; border: 1px solid rgba(0,0,0,.06); flex-wrap: wrap; }
.qty-input { width: 56px; height: 30px; padding: 0 8px; border: 1px solid rgba(0,0,0,.1); border-radius: 7px; font-size: 12px; text-align: center; outline: none; }
.add-med-form { padding: 14px; background: #f9fafb; border-radius: 12px; border: 1px solid rgba(0,0,0,.06); margin-bottom: 14px; }
.add-med-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-label-sm { font-size: 10.5px; font-weight: 650; color: #6b7280; text-transform: uppercase; letter-spacing: .06em; }

/* ── Toast ── */
.toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 200; }
.toast { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.13); min-width: 280px; font-size: 13px; color: #111; }
.toast-success { border-left: 3px solid #10b981; }
.toast-success svg { color: #10b981; }
.toast-error { border-left: 3px solid #ef4444; }
.toast-error svg { color: #ef4444; }
.toast-info { border-left: 3px solid #6366f1; }
.toast-info svg { color: #6366f1; }
.toast span { flex: 1; }
.toast-close { background: none; border: none; color: #9ca3af; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 2px; border-radius: 4px; }
.toast-close:hover { background: #f3f4f6; }

/* ── Transitions ── */
.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from { opacity: 0; transform: scale(0.96) translateY(10px); }
.modal-leave-to   { opacity: 0; transform: scale(0.96) translateY(10px); }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from { opacity: 0; transform: translateX(30px); }
.toast-leave-to   { opacity: 0; transform: translateX(30px); }

/* ── Responsive ── */
@media (max-width: 1000px) { .kpi-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 700px) {
  .content { padding: 10px 16px; }
  .kpi-row { grid-template-columns: 1fr 1fr; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .lookup-wrap { width: 100%; } .lookup-wrap .search-input { width: 100%; flex: 1; }
  .toolbar-right { flex-direction: column; width: 100%; }
  .search-input { width: 100%; }
  .page-header { flex-direction: column; }
  .pagination { flex-direction: column; align-items: center; }
}
</style>