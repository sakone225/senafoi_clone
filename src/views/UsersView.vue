<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const API_BASE_URL = 'https://api.aeemci-ce.ci/senafoi'
const auth = useAuthStore()

const pageOptions = [
  { key: 'dashboard', label: 'Tableau de bord' },
  { key: 'seminars', label: 'SÃ©minaires' },
  { key: 'participants', label: 'Participants' },
  { key: 'quota', label: 'Quota' },
  { key: 'cars', label: 'Cars' },
  { key: 'speakers', label: 'Intervenants' },
  { key: 'rooms', label: 'Salles' },
  { key: 'evaluations', label: 'Evaluations' },
  { key: 'sante', label: 'SantÃ©' },
  { key: 'pointage', label: 'Pointage' },
  { key: 'paiements_configuration', label: 'Paiements' },
  { key: 'reports', label: 'Rapports' },
  { key: 'settings', label: 'ParamÃ¨tres' },
  { key: 'users', label: 'Utilisateurs' },
]

const users = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const selectedUser = ref(null)

const emptyAccess = () => Object.fromEntries(
  pageOptions.map(page => [page.key, { canView: false, mode: 'viewer' }])
)

const form = reactive({
  id: null,
  matricule: '',
  password: '',
  nom: '',
  prenom: '',
  contact: '',
  photo: '',
  actif: true,
  access: emptyAccess(),
})

const canManage = computed(() => auth.canEdit('users'))

function resetForm() {
  selectedUser.value = null
  form.id = null
  form.matricule = ''
  form.password = ''
  form.nom = ''
  form.prenom = ''
  form.contact = ''
  form.photo = ''
  form.actif = true
  form.access = emptyAccess()
}

function applyUserToForm(user) {
  selectedUser.value = user
  form.id = user.id
  form.matricule = user.matricule
  form.password = ''
  form.nom = user.nom
  form.prenom = user.prenom
  form.contact = user.contact || ''
  form.photo = user.photo || ''
  form.actif = Boolean(user.actif)
  form.access = emptyAccess()
  pageOptions.forEach(page => {
    const current = user.access?.[page.key] || {}
    form.access[page.key] = {
      canView: Boolean(current.canView),
      mode: current.mode === 'editor' ? 'editor' : 'viewer',
    }
  })
}

function setAccess(pageKey, value) {
  if (value === 'none') {
    form.access[pageKey] = { canView: false, mode: 'viewer' }
    return
  }
  form.access[pageKey] = { canView: true, mode: value }
}

function accessValue(pageKey) {
  const item = form.access[pageKey]
  if (!item?.canView) return 'none'
  return item.mode === 'editor' ? 'editor' : 'viewer'
}

async function fetchUsers() {
  loading.value = true
  error.value = ''
  try {
    const response = await fetch(`${API_BASE_URL}/senafoi26_users_api.php?action=list`, {
      headers: auth.authHeaders(),
    })
    const data = await response.json()
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
    error.value = 'Nom, prÃ©nom et mot de passe sont requis.'
    return
  }

  saving.value = true
  error.value = ''
  success.value = ''
  const action = form.id ? 'update' : 'create'
  try {
    const response = await fetch(`${API_BASE_URL}/senafoi26_users_api.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...auth.authHeaders() },
      body: JSON.stringify({
        id: form.id,
        matricule: form.matricule,
        password: form.password,
        nom: form.nom,
        prenom: form.prenom,
        contact: form.contact,
        photo: form.photo,
        actif: form.actif,
        access: form.access,
      }),
    })
    const data = await response.json()
    if (!data.success) throw new Error(data.message || 'Enregistrement impossible')
    success.value = data.message || 'EnregistrÃ©.'
    await fetchUsers()
    applyUserToForm(data.user)
  } catch (e) {
    error.value = e.message || 'Erreur enregistrement'
  } finally {
    saving.value = false
  }
}

function userAccessSummary(user) {
  const entries = pageOptions.filter(page => user.access?.[page.key]?.canView)
  if (!entries.length) return 'Aucun accÃ¨s'
  const editors = entries.filter(page => user.access?.[page.key]?.mode === 'editor').length
  return `${entries.length} page(s), ${editors} en Ã©dition`
}

onMounted(fetchUsers)
</script>

<template>
  <div class="users-page">
    <section class="panel list-panel">
      <div class="panel-head">
        <div>
          <p class="eyebrow">Administration</p>
          <h2>Utilisateurs</h2>
        </div>
        <button v-if="canManage" class="btn secondary" type="button" @click="resetForm">Nouveau</button>
      </div>

      <div v-if="loading" class="state">Chargement des utilisateurs...</div>
      <div v-else-if="!users.length" class="state">Aucun utilisateur enregistrÃ©.</div>
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
            <span v-else>{{ user.prenom?.[0] }}{{ user.nom?.[0] }}</span>
          </div>
          <div class="user-main">
            <strong>{{ user.prenom }} {{ user.nom }}</strong>
            <span>{{ user.matricule }} Â· {{ user.contact || 'Sans contact' }}</span>
          </div>
          <div class="user-meta">
            <span :class="['status', user.actif ? 'on' : 'off']">{{ user.actif ? 'Actif' : 'Inactif' }}</span>
            <small>{{ userAccessSummary(user) }}</small>
          </div>
        </button>
      </div>
    </section>

    <section class="panel form-panel">
      <div class="panel-head">
        <div>
          <p class="eyebrow">{{ form.id ? 'Modification' : 'CrÃ©ation' }}</p>
          <h2>{{ form.id ? `${form.prenom} ${form.nom}` : 'Nouvel utilisateur' }}</h2>
        </div>
        <span class="mode-pill">{{ canManage ? 'Editeur' : 'Lecture seule' }}</span>
      </div>

      <div v-if="error" class="alert error">{{ error }}</div>
      <div v-if="success" class="alert success">{{ success }}</div>

      <form class="user-form" @submit.prevent="saveUser">
        <div class="grid">
          <label>
            <span>Matricule</span>
            <input v-model="form.matricule" type="text" placeholder="Auto: COM260001" :disabled="!canManage || !!form.id" />
          </label>
          <label>
            <span>Mot de passe</span>
            <input v-model="form.password" type="password" :placeholder="form.id ? 'Laisser vide pour conserver' : 'Mot de passe'" :disabled="!canManage" />
          </label>
          <label>
            <span>Nom</span>
            <input v-model="form.nom" type="text" :disabled="!canManage" />
          </label>
          <label>
            <span>PrÃ©nom</span>
            <input v-model="form.prenom" type="text" :disabled="!canManage" />
          </label>
          <label>
            <span>Contact</span>
            <input v-model="form.contact" type="text" :disabled="!canManage" />
          </label>
          <label>
            <span>Photo URL</span>
            <input v-model="form.photo" type="url" :disabled="!canManage" />
          </label>
        </div>

        <label class="active-line">
          <input v-model="form.actif" type="checkbox" :disabled="!canManage" />
          <span>Compte actif</span>
        </label>

        <div class="access-head">
          <h3>AccÃ¨s aux pages</h3>
          <p>Viewer peut consulter. Editeur peut effectuer les actions de modification prÃ©vues sur la page.</p>
        </div>

        <div class="access-grid">
          <div v-for="page in pageOptions" :key="page.key" class="access-row">
            <span>{{ page.label }}</span>
            <div class="segmented">
              <button type="button" :class="{ active: accessValue(page.key) === 'none' }" :disabled="!canManage" @click="setAccess(page.key, 'none')">Non</button>
              <button type="button" :class="{ active: accessValue(page.key) === 'viewer' }" :disabled="!canManage" @click="setAccess(page.key, 'viewer')">Viewer</button>
              <button type="button" :class="{ active: accessValue(page.key) === 'editor' }" :disabled="!canManage" @click="setAccess(page.key, 'editor')">Editeur</button>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn secondary" type="button" @click="resetForm">RÃ©initialiser</button>
          <button v-if="canManage" class="btn primary" type="submit" :disabled="saving">
            {{ saving ? 'Enregistrement...' : (form.id ? 'Mettre Ã  jour' : 'CrÃ©er utilisateur') }}
          </button>
        </div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.users-page {
  display: grid;
  grid-template-columns: minmax(320px, 0.9fr) minmax(520px, 1.4fr);
  gap: 18px;
}

.panel {
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 18px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.eyebrow {
  margin: 0 0 4px;
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
}

h2, h3, p {
  margin: 0;
}

h2 {
  color: #111827;
  font-size: 20px;
}

.users-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.user-row {
  width: 100%;
  display: grid;
  grid-template-columns: 42px 1fr auto;
  align-items: center;
  gap: 12px;
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 8px;
  padding: 10px;
  text-align: left;
  cursor: pointer;
}

.user-row.active,
.user-row:hover {
  border-color: #15803d;
  background: #f0fdf4;
}

.avatar {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  background: #14532d;
  color: #fff;
  display: grid;
  place-items: center;
  font-weight: 800;
  overflow: hidden;
}

.avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.user-main {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.user-main span,
.user-meta small {
  color: #64748b;
  font-size: 12px;
}

.user-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.status,
.mode-pill {
  border-radius: 999px;
  padding: 4px 8px;
  font-size: 11px;
  font-weight: 800;
  background: #f1f5f9;
  color: #475569;
}

.status.on {
  background: #dcfce7;
  color: #166534;
}

.status.off {
  background: #fee2e2;
  color: #991b1b;
}

.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: #334155;
  font-size: 13px;
  font-weight: 700;
}

input {
  height: 38px;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  padding: 0 10px;
  color: #111827;
}

input:focus {
  outline: none;
  border-color: #15803d;
  box-shadow: 0 0 0 3px rgba(21, 128, 61, 0.12);
}

input:disabled {
  background: #f8fafc;
  color: #94a3b8;
}

.active-line {
  flex-direction: row;
  align-items: center;
  margin: 14px 0;
}

.active-line input {
  width: 16px;
  height: 16px;
}

.access-head {
  margin: 16px 0 10px;
}

.access-head h3 {
  color: #111827;
  font-size: 16px;
}

.access-head p {
  color: #64748b;
  font-size: 12px;
  margin-top: 4px;
}

.access-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.access-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 9px;
}

.access-row > span {
  color: #334155;
  font-size: 13px;
  font-weight: 700;
}

.segmented {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  border: 1px solid #d1d5db;
  border-radius: 7px;
  overflow: hidden;
}

.segmented button {
  border: 0;
  background: #fff;
  color: #475569;
  padding: 7px 8px;
  font-size: 12px;
  cursor: pointer;
}

.segmented button + button {
  border-left: 1px solid #d1d5db;
}

.segmented button.active {
  background: #15803d;
  color: #fff;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}

.btn {
  height: 38px;
  border-radius: 8px;
  padding: 0 14px;
  border: 1px solid transparent;
  font-weight: 800;
  cursor: pointer;
}

.btn.primary {
  background: #15803d;
  color: #fff;
}

.btn.secondary {
  background: #fff;
  color: #15803d;
  border-color: #bbf7d0;
}

.alert {
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 12px;
  font-size: 13px;
}

.alert.error {
  background: #fef2f2;
  color: #991b1b;
  border: 1px solid #fecaca;
}

.alert.success {
  background: #f0fdf4;
  color: #166534;
  border: 1px solid #bbf7d0;
}

.state {
  color: #64748b;
  background: #f8fafc;
  border-radius: 8px;
  padding: 18px;
  text-align: center;
}

@media (max-width: 1100px) {
  .users-page {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .grid,
  .access-grid {
    grid-template-columns: 1fr;
  }

  .user-row {
    grid-template-columns: 42px 1fr;
  }

  .user-meta {
    grid-column: 2;
    align-items: flex-start;
  }
}
</style>

