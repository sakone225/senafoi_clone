<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const API_BASE_URL = 'https://api.aeemci-ce.ci/senafoi'
const auth = useAuthStore()

// ── Pages alignées exactement sur le menu DashboardLayout ────────────────
const pageOptions = [
  // Principal
  { key: 'dashboard',        label: 'Tableau de bord', group: 'Principal' },

  // Modules SENA*
  { key: 'senafad-option1',  label: 'SENAFAD — Liste des membres', group: 'SENAFAD' },
  { key: 'senafad-option2',  label: 'SENAFAD — Option 2', group: 'SENAFAD' },
  { key: 'senafi-option1',   label: 'SENAFI — Option 1',  group: 'SENAFI'  },
  { key: 'senafi-option2',   label: 'SENAFI — Option 2',  group: 'SENAFI'  },
  { key: 'senafoci-option1', label: 'SENAFOCI — Option 1', group: 'SENAFOCI' },
  { key: 'senafoci-option2', label: 'SENAFOCI — Option 2', group: 'SENAFOCI' },
  { key: 'senacef-option1',  label: 'SENACEF — Option 1', group: 'SENACEF' },
  { key: 'senacef-option2',  label: 'SENACEF — Option 2', group: 'SENACEF' },
  { key: 'senasip-option1',  label: 'SENASIP — Option 1', group: 'SENASIP' },
  { key: 'senasip-option2',  label: 'SENASIP — Option 2', group: 'SENASIP' },
  { key: 'senaes-option1',   label: 'SENAES — Option 1',  group: 'SENAES'  },
  { key: 'senaes-option2',   label: 'SENAES — Option 2',  group: 'SENAES'  },
  { key: 'senamo-option1',   label: 'SENAMO — Option 1',  group: 'SENAMO'  },
  { key: 'senamo-option2',   label: 'SENAMO — Option 2',  group: 'SENAMO'  },
  { key: 'senacrex-option1', label: 'SENACREX — Option 1', group: 'SENACREX' },
  { key: 'senacrex-option2', label: 'SENACREX — Option 2', group: 'SENACREX' },

  // Compte
  { key: 'settings',         label: 'Paramètres',    group: 'Compte' },
  { key: 'users',            label: 'Utilisateurs',   group: 'Compte' },
  { key: 'login-history',    label: 'Historique connexions', group: 'Compte' },
]

// Groupes uniques pour affichage par section
const groups = computed(() => {
  const seen = []
  pageOptions.forEach(p => { if (!seen.includes(p.group)) seen.push(p.group) })
  return seen
})
function pagesOfGroup(group) {
  return pageOptions.filter(p => p.group === group)
}

// ── State ────────────────────────────────────────────────────────────────
const users        = ref([])
const loading      = ref(false)
const saving       = ref(false)
const error        = ref('')
const success      = ref('')
const selectedUser = ref(null)

const emptyAccess = () =>
  Object.fromEntries(pageOptions.map(p => [p.key, { canView: false, mode: 'viewer' }]))

const form = reactive({
  id:        null,
  matricule: '',
  password:  '',
  nom:       '',
  prenom:    '',
  contact:   '',
  photo:     '',
  actif:     true,
  access:    emptyAccess(),
})

const canManage = computed(() => auth.canEdit('users'))

// ── Helpers form ─────────────────────────────────────────────────────────
function resetForm() {
  selectedUser.value = null
  form.id        = null
  form.matricule = ''
  form.password  = ''
  form.nom       = ''
  form.prenom    = ''
  form.contact   = ''
  form.photo     = ''
  form.actif     = true
  form.access    = emptyAccess()
  error.value    = ''
  success.value  = ''
}

function applyUserToForm(user) {
  selectedUser.value = user
  form.id        = user.id
  form.matricule = user.matricule
  form.password  = ''
  form.nom       = user.nom
  form.prenom    = user.prenom
  form.contact   = user.contact   || ''
  form.photo     = user.photo     || ''
  form.actif     = Boolean(user.actif)
  form.access    = emptyAccess()
  pageOptions.forEach(p => {
    const cur = user.access?.[p.key] || {}
    form.access[p.key] = {
      canView: Boolean(cur.canView),
      mode:    cur.mode === 'editor' ? 'editor' : 'viewer',
    }
  })
  error.value   = ''
  success.value = ''
}

function setAccess(pageKey, value) {
  if (value === 'none') {
    form.access[pageKey] = { canView: false, mode: 'viewer' }
  } else {
    form.access[pageKey] = { canView: true, mode: value }
  }
}

function accessValue(pageKey) {
  const item = form.access[pageKey]
  if (!item?.canView) return 'none'
  return item.mode === 'editor' ? 'editor' : 'viewer'
}

// ── Sélection rapide par groupe ──────────────────────────────────────────
function setGroupAccess(group, value) {
  pagesOfGroup(group).forEach(p => setAccess(p.key, value))
}

// ── API ──────────────────────────────────────────────────────────────────
async function fetchUsers() {
  loading.value = true
  error.value   = ''
  try {
    const res  = await fetch(`${API_BASE_URL}/senafoi26_users_api.php?action=list`, {
      headers: auth.authHeaders(),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.message || 'Chargement impossible')
    users.value = data.users || []
  } catch (e) {
    error.value = e.message || 'Erreur de chargement'
  } finally {
    loading.value = false
  }
}

async function saveUser() {
  if (!canManage.value) return
  if (!form.nom.trim() || !form.prenom.trim() || (!form.id && !form.password.trim())) {
    error.value = 'Nom, prénom et mot de passe sont requis.'
    return
  }
  saving.value  = true
  error.value   = ''
  success.value = ''
  const action  = form.id ? 'update' : 'create'
  try {
    const res  = await fetch(`${API_BASE_URL}/senafoi26_users_api.php?action=${action}`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', ...auth.authHeaders() },
      body:    JSON.stringify({
        id:        form.id,
        matricule: form.matricule,
        password:  form.password,
        nom:       form.nom,
        prenom:    form.prenom,
        contact:   form.contact,
        photo:     form.photo,
        actif:     form.actif,
        access:    form.access,
      }),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.message || 'Enregistrement impossible')
    success.value = data.message || 'Enregistré avec succès.'
    await fetchUsers()
    applyUserToForm(data.user)
  } catch (e) {
    error.value = e.message || 'Erreur enregistrement'
  } finally {
    saving.value = false
  }
}

function userAccessSummary(user) {
  const visible = pageOptions.filter(p => user.access?.[p.key]?.canView)
  if (!visible.length) return 'Aucun accès'
  const editors = visible.filter(p => user.access?.[p.key]?.mode === 'editor').length
  return `${visible.length} page(s) · ${editors} en édition`
}

function getInitials(user) {
  return ((user.prenom?.[0] || '') + (user.nom?.[0] || '')).toUpperCase() || '?'
}

onMounted(fetchUsers)
</script>

<template>
  <div class="users-page">

    <!-- ═══ LISTE ═══ -->
    <section class="panel list-panel">
      <div class="panel-head">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Utilisateurs</h2>
        </div>
        <button v-if="canManage" class="btn btn-secondary" type="button" @click="resetForm">
          + Nouveau
        </button>
      </div>

      <div v-if="loading" class="state">Chargement…</div>
      <div v-else-if="!users.length" class="state">Aucun utilisateur enregistré.</div>

      <div v-else class="users-list">
        <button
          v-for="user in users"
          :key="user.id"
          type="button"
          class="user-row"
          :class="{ active: selectedUser?.id === user.id }"
          @click="applyUserToForm(user)"
        >
          <div class="avatar">
            <img v-if="user.photo" :src="user.photo" alt="" />
            <span v-else>{{ getInitials(user) }}</span>
          </div>
          <div class="user-main">
            <strong>{{ user.prenom }} {{ user.nom }}</strong>
            <span>{{ user.matricule }} · {{ user.contact || 'Sans contact' }}</span>
          </div>
          <div class="user-meta">
            <span :class="['status', user.actif ? 'on' : 'off']">
              {{ user.actif ? 'Actif' : 'Inactif' }}
            </span>
            <small>{{ userAccessSummary(user) }}</small>
          </div>
        </button>
      </div>
    </section>

    <!-- ═══ FORMULAIRE ═══ -->
    <section class="panel form-panel">
      <div class="panel-head">
        <div>
          <p class="eyebrow">{{ form.id ? 'Modification' : 'Création' }}</p>
          <h2>{{ form.id ? `${form.prenom} ${form.nom}` : 'Nouvel utilisateur' }}</h2>
        </div>
        <span class="mode-pill" :class="canManage ? 'pill-editor' : 'pill-viewer'">
          {{ canManage ? 'Éditeur' : 'Lecture seule' }}
        </span>
      </div>

      <div v-if="error"   class="alert alert-error">   {{ error }}   </div>
      <div v-if="success" class="alert alert-success">  {{ success }} </div>

      <form class="user-form" @submit.prevent="saveUser" novalidate>

        <!-- Infos de base -->
        <fieldset class="fieldset">
          <legend class="legend">Informations du compte</legend>
          <div class="grid-2">
            <label class="field-label">
              Matricule
              <input v-model="form.matricule" type="text" placeholder="COM260001"
                :disabled="!canManage || !!form.id" />
            </label>
            <label class="field-label">
              Mot de passe
              <input v-model="form.password" type="password"
                :placeholder="form.id ? 'Laisser vide pour conserver' : 'Mot de passe requis'"
                :disabled="!canManage" />
            </label>
            <label class="field-label">
              Nom
              <input v-model="form.nom" type="text" :disabled="!canManage" />
            </label>
            <label class="field-label">
              Prénom
              <input v-model="form.prenom" type="text" :disabled="!canManage" />
            </label>
            <label class="field-label">
              Contact
              <input v-model="form.contact" type="text" placeholder="07 00 00 00 00" :disabled="!canManage" />
            </label>
            <label class="field-label">
              Photo (URL)
              <input v-model="form.photo" type="url" placeholder="https://..." :disabled="!canManage" />
            </label>
          </div>

          <label class="active-line">
            <input v-model="form.actif" type="checkbox" :disabled="!canManage" />
            <span>Compte actif</span>
          </label>
        </fieldset>

        <!-- Accès par page -->
        <fieldset class="fieldset">
          <legend class="legend">Accès aux pages</legend>
          <p class="legend-sub">
            <strong>Viewer</strong> = consultation uniquement ·
            <strong>Éditeur</strong> = peut modifier
          </p>

          <!-- Groupe par groupe -->
          <div v-for="group in groups" :key="group" class="access-group">

            <!-- En-tête groupe avec sélection rapide -->
            <div class="group-head">
              <span class="group-label">{{ group }}</span>
              <div class="group-quick" v-if="canManage">
                <button type="button" class="quick-btn" @click="setGroupAccess(group, 'none')">Aucun</button>
                <button type="button" class="quick-btn" @click="setGroupAccess(group, 'viewer')">Tous viewer</button>
                <button type="button" class="quick-btn quick-btn--green" @click="setGroupAccess(group, 'editor')">Tous éditeur</button>
              </div>
            </div>

            <!-- Pages du groupe -->
            <div class="access-rows">
              <div v-for="page in pagesOfGroup(group)" :key="page.key" class="access-row">
                <span class="page-label">{{ page.label }}</span>
                <div class="segmented">
                  <button
                    type="button"
                    :class="{ active: accessValue(page.key) === 'none' }"
                    :disabled="!canManage"
                    @click="setAccess(page.key, 'none')"
                  >Non</button>
                  <button
                    type="button"
                    :class="{ active: accessValue(page.key) === 'viewer' }"
                    :disabled="!canManage"
                    @click="setAccess(page.key, 'viewer')"
                  >Viewer</button>
                  <button
                    type="button"
                    :class="{ active: accessValue(page.key) === 'editor', 'active-green': accessValue(page.key) === 'editor' }"
                    :disabled="!canManage"
                    @click="setAccess(page.key, 'editor')"
                  >Éditeur</button>
                </div>
              </div>
            </div>

          </div>
        </fieldset>

        <!-- Actions -->
        <div class="actions">
          <button class="btn btn-secondary" type="button" @click="resetForm">Réinitialiser</button>
          <button v-if="canManage" class="btn btn-primary" type="submit" :disabled="saving">
            {{ saving ? 'Enregistrement…' : (form.id ? 'Mettre à jour' : 'Créer utilisateur') }}
          </button>
        </div>

      </form>
    </section>
  </div>
</template>

<style scoped>
/* ── Layout ───────────────────────────────────────────────────────────── */
.users-page {
  display: grid;
  grid-template-columns: minmax(300px, 0.85fr) minmax(500px, 1.4fr);
  gap: 18px;
  align-items: start;
}

/* ── Panel ────────────────────────────────────────────────────────────── */
.panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  padding: 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}

.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 18px;
}

.eyebrow {
  margin: 0 0 3px;
  color: #9ca3af;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
}

h2 { margin: 0; color: #111827; font-size: 18px; font-weight: 660; letter-spacing: -.02em; }
h3 { margin: 0; }
p  { margin: 0; }

/* ── Liste utilisateurs ───────────────────────────────────────────────── */
.users-list { display: flex; flex-direction: column; gap: 8px; }

.user-row {
  width: 100%;
  display: grid;
  grid-template-columns: 40px 1fr auto;
  align-items: center;
  gap: 12px;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 10px;
  padding: 10px 12px;
  text-align: left;
  cursor: pointer;
  transition: border-color .15s, background .15s;
}
.user-row:hover  { border-color: #6366f1; background: #f5f5ff; }
.user-row.active { border-color: #6366f1; background: #eef2ff; }

.avatar {
  width: 40px; height: 40px; border-radius: 10px;
  background: #6366f1; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px; overflow: hidden; flex-shrink: 0;
}
.avatar img { width: 100%; height: 100%; object-fit: cover; }

.user-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.user-main strong { font-size: 13.5px; font-weight: 600; color: #111; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-main span   { color: #9ca3af; font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.user-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
.user-meta small { color: #9ca3af; font-size: 10.5px; text-align: right; }

/* ── Status & pills ───────────────────────────────────────────────────── */
.status {
  border-radius: 999px; padding: 3px 8px;
  font-size: 10.5px; font-weight: 700;
}
.status.on  { background: #dcfce7; color: #166534; }
.status.off { background: #fee2e2; color: #991b1b; }

.mode-pill {
  border-radius: 999px; padding: 4px 10px;
  font-size: 11px; font-weight: 700;
}
.pill-editor { background: #eef2ff; color: #6366f1; }
.pill-viewer { background: #f1f5f9; color: #64748b; }

/* ── Fieldsets ────────────────────────────────────────────────────────── */
.fieldset {
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  padding: 16px;
  margin: 0 0 16px;
}
.legend {
  font-size: 11px; font-weight: 700;
  color: #6b7280; text-transform: uppercase; letter-spacing: .06em;
  padding: 0 6px;
}
.legend-sub {
  font-size: 12px; color: #9ca3af;
  margin-bottom: 14px !important;
}

/* ── Form fields ──────────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }

.field-label {
  display: flex; flex-direction: column; gap: 5px;
  font-size: 12px; font-weight: 600; color: #374151;
}
.field-label input {
  height: 38px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 0 10px;
  font-size: 13px;
  font-family: inherit;
  color: #111827;
  background: #fafafa;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.field-label input:focus {
  border-color: #6366f1;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.field-label input:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; }

.active-line {
  display: flex; flex-direction: row; align-items: center;
  gap: 8px; margin-top: 12px;
  font-size: 13px; font-weight: 600; color: #374151; cursor: pointer;
}
.active-line input[type="checkbox"] { width: 16px; height: 16px; accent-color: #6366f1; }

/* ── Accès par groupe ─────────────────────────────────────────────────── */
.access-group { margin-bottom: 14px; }
.access-group:last-child { margin-bottom: 0; }

.group-head {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; margin-bottom: 6px;
}
.group-label {
  font-size: 11px; font-weight: 700;
  color: #6366f1; text-transform: uppercase; letter-spacing: .06em;
}
.group-quick { display: flex; gap: 4px; }
.quick-btn {
  font-size: 10px; font-weight: 600;
  padding: 3px 7px; border-radius: 5px;
  border: 1px solid #e5e7eb; background: #f9fafb;
  color: #6b7280; cursor: pointer;
  transition: background .12s, border-color .12s;
}
.quick-btn:hover { background: #f3f4f6; border-color: #d1d5db; }
.quick-btn--green { color: #166534; border-color: #bbf7d0; background: #f0fdf4; }
.quick-btn--green:hover { background: #dcfce7; }

.access-rows { display: flex; flex-direction: column; gap: 5px; }

.access-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; padding: 8px 10px;
  border: 1px solid #f3f4f6; border-radius: 8px;
  background: #fafafa;
}
.page-label { font-size: 12.5px; font-weight: 500; color: #374151; }

/* Segmented control */
.segmented {
  display: flex;
  border: 1px solid #e5e7eb;
  border-radius: 7px;
  overflow: hidden;
  flex-shrink: 0;
}
.segmented button {
  border: none; border-right: 1px solid #e5e7eb;
  background: #fff; color: #6b7280;
  padding: 5px 10px; font-size: 11.5px; font-weight: 500;
  font-family: inherit; cursor: pointer;
  transition: background .12s, color .12s;
}
.segmented button:last-child { border-right: none; }
.segmented button:hover { background: #f3f4f6; }
.segmented button.active {
  background: #eef2ff; color: #6366f1; font-weight: 700;
}
.segmented button.active-green {
  background: #f0fdf4 !important; color: #166534 !important;
}
.segmented button:disabled { opacity: .5; cursor: not-allowed; }

/* ── Alertes ──────────────────────────────────────────────────────────── */
.alert {
  border-radius: 9px; padding: 10px 14px;
  margin-bottom: 14px; font-size: 13px;
}
.alert-error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

/* ── Boutons ──────────────────────────────────────────────────────────── */
.actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.btn {
  height: 38px; border-radius: 9px; padding: 0 16px;
  font-size: 13px; font-weight: 600; font-family: inherit;
  cursor: pointer; border: none; transition: background .15s, opacity .15s;
}
.btn:disabled { opacity: .55; cursor: not-allowed; }

.btn-primary   { background: #6366f1; color: #fff; }
.btn-primary:hover:not(:disabled) { background: #4f46e5; }

.btn-secondary { background: #fff; color: #6366f1; border: 1px solid #e0e7ff; }
.btn-secondary:hover { background: #eef2ff; }

/* ── État vide ────────────────────────────────────────────────────────── */
.state {
  color: #9ca3af; background: #f9fafb;
  border-radius: 8px; padding: 20px;
  text-align: center; font-size: 13px;
}

/* ── Responsive ───────────────────────────────────────────────────────── */
@media (max-width: 1100px) {
  .users-page { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
  .grid-2 { grid-template-columns: 1fr; }
  .user-row { grid-template-columns: 40px 1fr; }
  .user-meta { grid-column: 2; align-items: flex-start; }
  .group-head { flex-direction: column; align-items: flex-start; }
}
</style>