<script setup>
import { ref, computed, onMounted } from 'vue'

// ── Permissions (sans dépendance au store pour tester) ───────────────────
const canEdit = ref(true) // mettre auth.canEdit('senafad-option1') en prod

// ── État ─────────────────────────────────────────────────────────────────
const membres       = ref([])
const loading       = ref(true)
const saving        = ref(false)
const errorMsg      = ref('')
const searchQuery   = ref('')
const filterStatut  = ref('tous')
const showModal     = ref(false)
const showDetail    = ref(false)
const membreDetail  = ref(null)
const confirmDelete = ref(null)

// ── Formulaire ────────────────────────────────────────────────────────────
const emptyForm = () => ({
  id: null, nom: '', prenom: '', matricule: '', email: '',
  telephone: '', ville: '', quartier: '', date_naissance: '',
  profession: '', statut: 'en_attente',
  date_demande: new Date().toISOString().slice(0, 10),
  photo: '', notes: '',
})
const form   = ref(emptyForm())
const isEdit = computed(() => !!form.value.id)

// ── Données mock ──────────────────────────────────────────────────────────
onMounted(() => {
  loading.value = true
  setTimeout(() => {
    // Charger depuis localStorage pour persister les données
    const saved = localStorage.getItem('senafad_membres')
    membres.value = saved ? JSON.parse(saved) : []
    loading.value = false
  }, 300)
})

// ── Stats ─────────────────────────────────────────────────────────────────
const stats = computed(() => ({
  total:     membres.value.length,
  approuves: membres.value.filter(m => m.statut === 'approuvé').length,
  attente:   membres.value.filter(m => m.statut === 'en_attente').length,
  rejetes:   membres.value.filter(m => m.statut === 'rejeté').length,
}))

// ── Filtres ───────────────────────────────────────────────────────────────
const membresFiltres = computed(() => {
  let list = membres.value
  if (filterStatut.value !== 'tous') {
    list = list.filter(m => m.statut === filterStatut.value)
  }
  const q = searchQuery.value.trim().toLowerCase()
  if (q) {
    list = list.filter(m =>
      [m.prenom, m.nom, m.matricule, m.ville, m.telephone, m.profession]
        .join(' ').toLowerCase().includes(q)
    )
  }
  return list
})

// ── Helpers ───────────────────────────────────────────────────────────────
function initiales(m) {
  return ((m.prenom?.[0] || '') + (m.nom?.[0] || '')).toUpperCase()
}
function avatarColor(mat = '') {
  const p = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#3b82f6','#f97316','#14b8a6']
  let h = 0
  for (const c of mat) h = c.charCodeAt(0) + ((h << 5) - h)
  return p[Math.abs(h) % p.length]
}
const STATUT_LABEL  = { approuvé:'Approuvé', en_attente:'En attente', rejeté:'Rejeté' }
const STATUT_CLASS  = { approuvé:'sf-badge-green', en_attente:'sf-badge-yellow', rejeté:'sf-badge-red' }

function fmtDate(d) {
  if (!d) return '—'
  return new Intl.DateTimeFormat('fr-FR', { day:'2-digit', month:'short', year:'numeric' }).format(new Date(d))
}
function calcAge(dob) {
  if (!dob) return '—'
  return Math.floor((Date.now() - new Date(dob)) / (1000*60*60*24*365.25)) + ' ans'
}

// ── CRUD ──────────────────────────────────────────────────────────────────
function openCreate() {
  console.log("Le bouton fonctionne !");
  form.value   = emptyForm()
  errorMsg.value = ''
  showModal.value = true
}
function openEdit(m) {
  form.value   = { ...m }
  errorMsg.value = ''
  showModal.value = true
}
function openDetail(m) {
  membreDetail.value = { ...m }
  showDetail.value   = true
}
function closeModal()  { showModal.value  = false }
function closeDetail() { showDetail.value = false }

// ── Sauvegarder dans localStorage à chaque changement ────────────────────
function persistMembres() {
  localStorage.setItem('senafad_membres', JSON.stringify(membres.value))
}

function saveMembre() {
  if (!form.value.prenom.trim() || !form.value.nom.trim()) {
    errorMsg.value = 'Prénom et nom sont requis.'
    return
  }
  saving.value = true
  setTimeout(() => {
    if (isEdit.value) {
      const idx = membres.value.findIndex(m => m.id === form.value.id)
      if (idx !== -1) {
        membres.value[idx] = { ...form.value }
      }
    } else {
      const newId  = membres.value.length
        ? Math.max(...membres.value.map(m => m.id)) + 1
        : 1
      const newMat = `SFD${new Date().getFullYear()}${String(newId).padStart(3, '0')}`
      membres.value = [{ ...form.value, id: newId, matricule: newMat }, ...membres.value]
    }
    persistMembres()   // ← sauvegarde après chaque modification
    saving.value    = false
    showModal.value = false
  }, 400)
}


function changeStatut(m, s) {
  const idx = membres.value.findIndex(x => x.id === m.id)
  if (idx !== -1) {
    membres.value[idx] = { ...membres.value[idx], statut: s }
    if (membreDetail.value?.id === m.id) {
      membreDetail.value = { ...membres.value[idx] }
    }
  }
}

function doDelete() {
  membres.value  = membres.value.filter(m => m.id !== confirmDelete.value.id)
  confirmDelete.value = null
  if (showDetail.value) showDetail.value = false
}

function exportCSV() {
  const headers = ['Matricule','Prénom','Nom','Email','Téléphone','Ville','Profession','Statut','Date demande']
  const rows = membresFiltres.value.map(m => [
    m.matricule, m.prenom, m.nom, m.email, m.telephone,
    m.ville, m.profession, STATUT_LABEL[m.statut] || m.statut, m.date_demande
  ])
  const csv  = [headers, ...rows].map(r => r.map(v => `"${v || ''}"`).join(',')).join('\n')
  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
  const url  = URL.createObjectURL(blob)
  const a    = Object.assign(document.createElement('a'), { href: url, download: 'membres_senafad.csv' })
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="sf-page">

    <!-- ── En-tête ───────────────────────────────────────────────────── -->
    <div class="sf-header">
      <div class="sf-header-left">
        <span class="sf-eyebrow">SENAFAD</span>
        <h1 class="sf-title">Cartes membres</h1>
        <p class="sf-subtitle">{{ stats.total }} dossiers enregistrés</p>
      </div>
      <div class="sf-header-right">
        <button class="sf-btn sf-btn-ghost" @click="exportCSV">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          Exporter CSV
        </button>
        <button v-if="canEdit" class="sf-btn sf-btn-primary" @click="openCreate">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Nouveau membre
        </button>
      </div>
    </div>

    <!-- ── KPIs ──────────────────────────────────────────────────────── -->
    <div class="sf-kpi-row">
      <div class="sf-kpi-card" :class="{ 'sf-kpi-active': filterStatut === 'tous' }" @click="filterStatut = 'tous'">
        <div class="sf-kpi-icon" style="background:rgba(99,102,241,.12);color:#6366f1">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
        </div>
        <div class="sf-kpi-data">
          <span class="sf-kpi-val">{{ stats.total }}</span>
          <span class="sf-kpi-label">Total dossiers</span>
        </div>
      </div>

      <div class="sf-kpi-card" :class="{ 'sf-kpi-active': filterStatut === 'approuvé' }" @click="filterStatut = 'approuvé'">
        <div class="sf-kpi-icon" style="background:rgba(16,185,129,.12);color:#10b981">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>
        <div class="sf-kpi-data">
          <span class="sf-kpi-val" style="color:#10b981">{{ stats.approuves }}</span>
          <span class="sf-kpi-label">Approuvés</span>
        </div>
      </div>

      <div class="sf-kpi-card" :class="{ 'sf-kpi-active': filterStatut === 'en_attente' }" @click="filterStatut = 'en_attente'">
        <div class="sf-kpi-icon" style="background:rgba(245,158,11,.12);color:#f59e0b">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
        </div>
        <div class="sf-kpi-data">
          <span class="sf-kpi-val" style="color:#f59e0b">{{ stats.attente }}</span>
          <span class="sf-kpi-label">En attente</span>
        </div>
      </div>

      <div class="sf-kpi-card" :class="{ 'sf-kpi-active': filterStatut === 'rejeté' }" @click="filterStatut = 'rejeté'">
        <div class="sf-kpi-icon" style="background:rgba(239,68,68,.12);color:#ef4444">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </div>
        <div class="sf-kpi-data">
          <span class="sf-kpi-val" style="color:#ef4444">{{ stats.rejetes }}</span>
          <span class="sf-kpi-label">Rejetés</span>
        </div>
      </div>
    </div>

    <!-- ── Toolbar ────────────────────────────────────────────────────── -->
    <div class="sf-toolbar">
      <div class="sf-search-wrap">
        <svg class="sf-search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input
          v-model="searchQuery"
          type="search"
          placeholder="Rechercher par nom, matricule, ville…"
          class="sf-search-input"
        />
      </div>
      <div class="sf-filter-tabs">
        <button
          v-for="f in [
            { val:'tous', label:'Tous' },
            { val:'approuvé', label:'Approuvés' },
            { val:'en_attente', label:'En attente' },
            { val:'rejeté', label:'Rejetés' },
          ]"
          :key="f.val"
          class="sf-filter-tab"
          :class="{ active: filterStatut === f.val }"
          @click="filterStatut = f.val"
        >{{ f.label }}</button>
      </div>
    </div>

    <!-- ── Tableau ────────────────────────────────────────────────────── -->
    <div class="sf-table-card">

      <div v-if="loading" class="sf-state">
        <div class="sf-spinner"></div>
        <span>Chargement des membres…</span>
      </div>

      <div v-else-if="membresFiltres.length === 0" class="sf-state">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
    <circle cx="9" cy="7" r="4"/>
    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
  </svg>

  <!-- Message différent selon contexte -->
  <span v-if="membres.length === 0" class="sf-state-title">
    Aucun membre enregistré
  </span>
  <span v-else class="sf-state-title">
    Aucun résultat pour "{{ searchQuery || filterStatut }}"
  </span>

  <span v-if="membres.length === 0" class="sf-state-sub">
    Commencez par ajouter le premier membre SENAFAD.
  </span>
  <span v-else class="sf-state-sub">
    Essayez un autre terme ou réinitialisez les filtres.
  </span>

 <div style="display:flex;gap:8px;margin-top:4px">
    <button
      v-if="membres.length > 0"
      class="sf-btn sf-btn-ghost"
      @click="searchQuery = ''; filterStatut = 'tous'"
    >
      Réinitialiser les filtres
    </button>
    
  </div>
</div>

      <div v-else class="sf-table-wrap">
        <table class="sf-table">
          <thead>
            <tr>
              <th>Membre</th>
              <th>Matricule</th>
              <th>Contact</th>
              <th>Ville</th>
              <th>Profession</th>
              <th>Statut</th>
              <th>Demande</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in membresFiltres" :key="m.id">
              <td>
                <div class="sf-membre-cell">
                  <div class="sf-avatar" :style="{ background: avatarColor(m.matricule) }">
                    <img v-if="m.photo" :src="m.photo" :alt="m.prenom" />
                    <span v-else>{{ initiales(m) }}</span>
                  </div>
                  <div class="sf-membre-info">
                    <span class="sf-membre-nom">{{ m.prenom }} {{ m.nom }}</span>
                    <span class="sf-muted">{{ calcAge(m.date_naissance) }}</span>
                  </div>
                </div>
              </td>
              <td><code class="sf-code">{{ m.matricule }}</code></td>
              <td>
                <div class="sf-contact-cell">
                  <span>{{ m.telephone }}</span>
                  <span class="sf-muted">{{ m.email }}</span>
                </div>
              </td>
              <td>{{ m.ville }}</td>
              <td>{{ m.profession }}</td>
              <td>
                <span :class="['sf-badge', STATUT_CLASS[m.statut]]">
                  {{ STATUT_LABEL[m.statut] || m.statut }}
                </span>
              </td>
              <td class="sf-muted">{{ fmtDate(m.date_demande) }}</td>
              <td>
                <div class="sf-row-actions">
                  <button class="sf-icon-btn" title="Voir le dossier" @click="openDetail(m)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                      <circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                  <button v-if="canEdit" class="sf-icon-btn" title="Modifier" @click="openEdit(m)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                  </button>
                  <button v-if="canEdit && m.statut !== 'approuvé'" class="sf-icon-btn sf-icon-green" title="Approuver" @click="changeStatut(m, 'approuvé')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                  </button>
                  <button v-if="canEdit && m.statut !== 'rejeté'" class="sf-icon-btn sf-icon-orange" title="Rejeter" @click="changeStatut(m, 'rejeté')">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                  </button>
                  <button v-if="canEdit" class="sf-icon-btn sf-icon-red" title="Supprimer" @click="confirmDelete = m">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <polyline points="3 6 5 6 21 6"/>
                      <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                      <path d="M10 11v6M14 11v6"/>
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!loading && membresFiltres.length > 0" class="sf-table-footer">
        {{ membresFiltres.length }} résultat(s) sur {{ membres.length }} membres
      </div>
    </div>

    <!-- ═══ MODAL CRÉER / MODIFIER ═══════════════════════════════════════ -->
    <Transition name="sf-modal">
      <div v-if="showModal" class="sf-modal-backdrop" @click.self="closeModal">
        <div class="sf-modal">
          <div class="sf-modal-head">
            <span class="sf-modal-title">{{ isEdit ? 'Modifier le membre' : 'Nouveau membre' }}</span>
            <button class="sf-modal-close" @click="closeModal">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <div v-if="errorMsg" class="sf-alert-error">{{ errorMsg }}</div>

          <div class="sf-modal-body">
            <div class="sf-form-grid">
              <label class="sf-field">
                <span class="sf-field-label">Prénom *</span>
                <input class="sf-input" v-model="form.prenom" type="text" placeholder="Aminata" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Nom *</span>
                <input class="sf-input" v-model="form.nom" type="text" placeholder="KONÉ" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Email</span>
                <input class="sf-input" v-model="form.email" type="email" placeholder="email@exemple.ci" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Téléphone</span>
                <input class="sf-input" v-model="form.telephone" type="tel" placeholder="07 00 00 00 00" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Date de naissance</span>
                <input class="sf-input" v-model="form.date_naissance" type="date" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Profession</span>
                <input class="sf-input" v-model="form.profession" type="text" placeholder="Étudiant(e)" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Ville</span>
                <input class="sf-input" v-model="form.ville" type="text" placeholder="Abidjan" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Quartier</span>
                <input class="sf-input" v-model="form.quartier" type="text" placeholder="Cocody" />
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Statut</span>
                <select class="sf-input" v-model="form.statut">
                  <option value="en_attente">En attente</option>
                  <option value="approuvé">Approuvé</option>
                  <option value="rejeté">Rejeté</option>
                </select>
              </label>
              <label class="sf-field">
                <span class="sf-field-label">Date de demande</span>
                <input class="sf-input" v-model="form.date_demande" type="date" />
              </label>
              <label class="sf-field sf-field-full">
                <span class="sf-field-label">Photo (URL)</span>
                <input class="sf-input" v-model="form.photo" type="url" placeholder="https://…" />
              </label>
              <label class="sf-field sf-field-full">
                <span class="sf-field-label">Notes</span>
                <textarea class="sf-input sf-textarea" v-model="form.notes" rows="2" placeholder="Remarques…"></textarea>
              </label>
            </div>
          </div>

          <div class="sf-modal-foot">
            <button class="sf-btn sf-btn-ghost" @click="closeModal">Annuler</button>
            <button class="sf-btn sf-btn-primary" :disabled="saving" @click="saveMembre">
              {{ saving ? 'Enregistrement…' : (isEdit ? 'Mettre à jour' : 'Ajouter') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ═══ PANEL DÉTAIL ══════════════════════════════════════════════════ -->
    <Transition name="sf-slide">
      <div v-if="showDetail && membreDetail" class="sf-detail-backdrop" @click.self="closeDetail">
        <div class="sf-detail-panel">
          <div class="sf-detail-top">
            <button class="sf-modal-close" @click="closeDetail">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <div class="sf-detail-profile">
            <div class="sf-detail-avatar" :style="{ background: avatarColor(membreDetail.matricule) }">
              <img v-if="membreDetail.photo" :src="membreDetail.photo" :alt="membreDetail.prenom" />
              <span v-else>{{ initiales(membreDetail) }}</span>
            </div>
            <span class="sf-detail-name">{{ membreDetail.prenom }} {{ membreDetail.nom }}</span>
            <code class="sf-code">{{ membreDetail.matricule }}</code>
            <span :class="['sf-badge', STATUT_CLASS[membreDetail.statut]]" style="margin-top:6px">
              {{ STATUT_LABEL[membreDetail.statut] }}
            </span>
          </div>

          <div class="sf-detail-section">
            <span class="sf-detail-section-title">Informations personnelles</span>
            <div class="sf-detail-grid">
              <div class="sf-di"><span class="sf-di-l">Âge</span><span class="sf-di-v">{{ calcAge(membreDetail.date_naissance) }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Naissance</span><span class="sf-di-v">{{ fmtDate(membreDetail.date_naissance) }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Profession</span><span class="sf-di-v">{{ membreDetail.profession || '—' }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Ville</span><span class="sf-di-v">{{ membreDetail.ville || '—' }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Quartier</span><span class="sf-di-v">{{ membreDetail.quartier || '—' }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Demande</span><span class="sf-di-v">{{ fmtDate(membreDetail.date_demande) }}</span></div>
            </div>
          </div>

          <div class="sf-detail-section">
            <span class="sf-detail-section-title">Contact</span>
            <div class="sf-detail-grid">
              <div class="sf-di"><span class="sf-di-l">Téléphone</span><span class="sf-di-v">{{ membreDetail.telephone || '—' }}</span></div>
              <div class="sf-di"><span class="sf-di-l">Email</span><span class="sf-di-v">{{ membreDetail.email || '—' }}</span></div>
            </div>
          </div>

          <div v-if="membreDetail.notes" class="sf-detail-section">
            <span class="sf-detail-section-title">Notes</span>
            <p class="sf-detail-notes">{{ membreDetail.notes }}</p>
          </div>

          <div v-if="canEdit" class="sf-detail-actions">
            <button class="sf-btn sf-btn-ghost" style="flex:1" @click="openEdit(membreDetail); closeDetail()">Modifier</button>
            <button v-if="membreDetail.statut !== 'approuvé'" class="sf-btn" style="flex:1;background:#10b981;color:#fff;border:none" @click="changeStatut(membreDetail, 'approuvé')">Approuver</button>
            <button v-if="membreDetail.statut !== 'rejeté'" class="sf-btn" style="flex:1;background:#ef4444;color:#fff;border:none" @click="changeStatut(membreDetail, 'rejeté')">Rejeter</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- ═══ CONFIRM DELETE ════════════════════════════════════════════════ -->
    <Transition name="sf-modal">
      <div v-if="confirmDelete" class="sf-modal-backdrop" @click.self="confirmDelete = null">
        <div class="sf-modal sf-modal-sm">
          <div class="sf-modal-head">
            <span class="sf-modal-title">Confirmer la suppression</span>
            <button class="sf-modal-close" @click="confirmDelete = null">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>
          <div class="sf-modal-body">
            <p class="sf-confirm-text">
              Supprimer le dossier de <strong>{{ confirmDelete.prenom }} {{ confirmDelete.nom }}</strong> ?
              Cette action est irréversible.
            </p>
          </div>
          <div class="sf-modal-foot">
            <button class="sf-btn sf-btn-ghost" @click="confirmDelete = null">Annuler</button>
            <button class="sf-btn" style="background:#ef4444;color:#fff;border:none" @click="doDelete">Supprimer</button>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
.sf-state-title { font-size:15px; font-weight:650; color:#374151; }
.sf-state-sub   { font-size:13px; color:#9ca3af; margin-top:-4px; }
.sf-page { display:flex; flex-direction:column; gap:20px; font-family:'SF Pro Text',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }

/* Header */
.sf-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.sf-header-left { display:flex; flex-direction:column; gap:3px; }
.sf-header-right { display:flex; gap:10px; flex-shrink:0; align-items:center; }
.sf-eyebrow { font-size:11px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#6366f1; }
.sf-title { font-size:24px; font-weight:730; color:#111; letter-spacing:-.035em; margin:0; }
.sf-subtitle { font-size:13px; color:#9ca3af; margin:0; }

/* KPIs */
.sf-kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.sf-kpi-card { background:#fff; border:1.5px solid #f3f4f6; border-radius:14px; padding:16px 18px; display:flex; align-items:center; gap:14px; box-shadow:0 1px 3px rgba(0,0,0,.04); cursor:pointer; transition:border-color .15s,box-shadow .15s; }
.sf-kpi-card:hover { border-color:#e0e7ff; box-shadow:0 2px 8px rgba(99,102,241,.08); }
.sf-kpi-card.sf-kpi-active { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.sf-kpi-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.sf-kpi-data { display:flex; flex-direction:column; }
.sf-kpi-val { font-size:22px; font-weight:730; color:#111; letter-spacing:-.04em; line-height:1; }
.sf-kpi-label { font-size:11.5px; color:#9ca3af; margin-top:3px; }

/* Toolbar */
.sf-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.sf-search-wrap { position:relative; flex:1; min-width:220px; }
.sf-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; }
.sf-search-input { width:100%; height:38px; border:1.5px solid #e5e7eb; border-radius:9px; padding:0 12px 0 36px; font-size:13.5px; font-family:inherit; color:#111; background:#fff; outline:none; transition:border-color .15s,box-shadow .15s; }
.sf-search-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.sf-filter-tabs { display:flex; gap:4px; background:#f3f4f6; border-radius:9px; padding:3px; }
.sf-filter-tab { padding:5px 12px; border:none; border-radius:7px; font-size:12.5px; font-weight:500; font-family:inherit; color:#6b7280; background:transparent; cursor:pointer; transition:background .12s,color .12s; }
.sf-filter-tab:hover { background:#e5e7eb; color:#374151; }
.sf-filter-tab.active { background:#fff; color:#6366f1; font-weight:650; box-shadow:0 1px 3px rgba(0,0,0,.08); }

/* Table */
.sf-table-card { background:#fff; border:1px solid #f3f4f6; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.sf-table-wrap { overflow-x:auto; }
.sf-table { width:100%; border-collapse:collapse; }
.sf-table thead tr { background:#fafafa; }
.sf-table th { padding:11px 16px; font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.06em; text-align:left; white-space:nowrap; border-bottom:1px solid #f3f4f6; }
.sf-table td { padding:13px 16px; font-size:13px; color:#374151; border-bottom:1px solid #f9fafb; vertical-align:middle; }
.sf-table tbody tr:last-child td { border-bottom:none; }
.sf-table tbody tr:hover { background:#fafafa; }

.sf-membre-cell { display:flex; align-items:center; gap:11px; min-width:170px; }
.sf-avatar { width:34px; height:34px; border-radius:50%; color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden; }
.sf-avatar img { width:100%; height:100%; object-fit:cover; }
.sf-membre-info { display:flex; flex-direction:column; }
.sf-membre-nom { font-weight:600; color:#111; font-size:13px; }
.sf-contact-cell { display:flex; flex-direction:column; gap:1px; }
.sf-muted { font-size:11.5px; color:#9ca3af; }
.sf-code { font-size:11.5px; font-family:'SF Mono',monospace; background:rgba(99,102,241,.08); color:#6366f1; padding:2px 7px; border-radius:5px; }

/* Badges */
.sf-badge { font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
.sf-badge-green  { background:#dcfce7; color:#166534; }
.sf-badge-yellow { background:#fef3c7; color:#92400e; }
.sf-badge-red    { background:#fee2e2; color:#991b1b; }

/* Row actions */
.sf-row-actions { display:flex; gap:4px; }
.sf-icon-btn { width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#fff; color:#6b7280; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .12s; }
.sf-icon-btn:hover    { background:#f3f4f6; color:#374151; }
.sf-icon-green:hover  { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.sf-icon-orange:hover { background:#fef3c7; color:#92400e; border-color:#fde68a; }
.sf-icon-red:hover    { background:#fee2e2; color:#991b1b; border-color:#fecaca; }

.sf-table-footer { padding:10px 16px; font-size:12px; color:#9ca3af; border-top:1px solid #f3f4f6; }

/* States */
.sf-state { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:60px 20px; color:#9ca3af; font-size:14px; }
.sf-spinner { width:32px; height:32px; border:3px solid rgba(99,102,241,.15); border-top-color:#6366f1; border-radius:50%; animation:sf-spin .7s linear infinite; }
@keyframes sf-spin { to { transform:rotate(360deg); } }

/* Buttons */
.sf-btn { display:inline-flex; align-items:center; gap:7px; height:36px; padding:0 14px; border-radius:9px; font-size:13px; font-weight:600; font-family:inherit; cursor:pointer; border:none; transition:background .15s,opacity .15s; white-space:nowrap; }
.sf-btn:disabled { opacity:.55; cursor:not-allowed; }
.sf-btn-primary { background:#6366f1; color:#fff; }
.sf-btn-primary:hover:not(:disabled) { background:#4f46e5; }
.sf-btn-ghost { background:#fff; color:#374151; border:1.5px solid #e5e7eb; }
.sf-btn-ghost:hover { background:#f9fafb; }

/* Alert */
.sf-alert-error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:9px; padding:10px 14px; font-size:13px; margin:0 24px 4px; }

/* Modal */
.sf-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.45); backdrop-filter:blur(3px); z-index:200; display:flex; align-items:center; justify-content:center; padding:20px; }
.sf-modal { background:#fff; border-radius:18px; width:100%; max-width:620px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.sf-modal-sm { max-width:420px; }
.sf-modal-head { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; border-bottom:1px solid #f3f4f6; }
.sf-modal-title { font-size:16px; font-weight:660; color:#111; letter-spacing:-.02em; }
.sf-modal-close { width:28px; height:28px; border-radius:7px; border:none; background:#f3f4f6; color:#6b7280; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .12s; flex-shrink:0; }
.sf-modal-close:hover { background:#e5e7eb; }
.sf-modal-body { padding:20px 24px; overflow-y:auto; flex:1; }
.sf-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 24px; border-top:1px solid #f3f4f6; }
.sf-confirm-text { font-size:14px; color:#374151; line-height:1.6; margin:0; }

/* Form */
.sf-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.sf-field { display:flex; flex-direction:column; gap:5px; }
.sf-field-full { grid-column:1/-1; }
.sf-field-label { font-size:12px; font-weight:600; color:#374151; }
.sf-input { height:38px; border:1.5px solid #e5e7eb; border-radius:8px; padding:0 10px; font-size:13.5px; font-family:inherit; color:#111; background:#fafafa; outline:none; transition:border-color .15s,box-shadow .15s; }
.sf-input:focus { border-color:#6366f1; background:#fff; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.sf-textarea { height:auto; padding:8px 10px; resize:vertical; }

/* Detail panel */
.sf-detail-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.3); backdrop-filter:blur(2px); z-index:200; display:flex; justify-content:flex-end; }
.sf-detail-panel { width:100%; max-width:380px; height:100%; background:#fff; overflow-y:auto; display:flex; flex-direction:column; box-shadow:-8px 0 40px rgba(0,0,0,.12); padding-bottom:24px; }
.sf-detail-top { display:flex; justify-content:flex-end; padding:16px 20px 0; }
.sf-detail-profile { display:flex; flex-direction:column; align-items:center; padding:16px 24px 24px; border-bottom:1px solid #f3f4f6; gap:6px; }
.sf-detail-avatar { width:72px; height:72px; border-radius:50%; color:#fff; font-size:22px; font-weight:700; display:flex; align-items:center; justify-content:center; overflow:hidden; margin-bottom:8px; }
.sf-detail-avatar img { width:100%; height:100%; object-fit:cover; }
.sf-detail-name { font-size:18px; font-weight:660; color:#111; letter-spacing:-.025em; text-align:center; }
.sf-detail-section { padding:18px 24px; border-bottom:1px solid #f3f4f6; }
.sf-detail-section-title { display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#9ca3af; margin-bottom:12px; }
.sf-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.sf-di { display:flex; flex-direction:column; gap:2px; }
.sf-di-l { font-size:11px; color:#9ca3af; }
.sf-di-v { font-size:13px; font-weight:550; color:#111; }
.sf-detail-notes { font-size:13px; color:#374151; line-height:1.5; margin:0; }
.sf-detail-actions { display:flex; gap:8px; padding:18px 24px 0; margin-top:auto; }

/* Transitions */
.sf-modal-enter-active, .sf-modal-leave-active { transition:opacity .2s ease; }
.sf-modal-enter-active .sf-modal, .sf-modal-leave-active .sf-modal { transition:transform .2s ease, opacity .2s ease; }
.sf-modal-enter-from, .sf-modal-leave-to { opacity:0; }
.sf-modal-enter-from .sf-modal, .sf-modal-leave-to .sf-modal { transform:scale(.96) translateY(8px); opacity:0; }
.sf-slide-enter-active, .sf-slide-leave-active { transition:opacity .2s ease; }
.sf-slide-enter-active .sf-detail-panel, .sf-slide-leave-active .sf-detail-panel { transition:transform .25s ease; }
.sf-slide-enter-from, .sf-slide-leave-to { opacity:0; }
.sf-slide-enter-from .sf-detail-panel, .sf-slide-leave-to .sf-detail-panel { transform:translateX(100%); }

/* Responsive */
@media (max-width:900px) { .sf-kpi-row { grid-template-columns:repeat(2,1fr); } }
@media (max-width:600px) {
  .sf-kpi-row { grid-template-columns:repeat(2,1fr); }
  .sf-form-grid { grid-template-columns:1fr; }
  .sf-detail-grid { grid-template-columns:1fr; }
  .sf-title { font-size:20px; }
  .sf-toolbar { flex-direction:column; align-items:stretch; }
}
</style>