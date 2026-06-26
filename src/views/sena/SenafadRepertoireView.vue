<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'

const API_URL = 'https://api.aeemci-ce.ci/repertoire.php'

const activeTab = ref('contacts')
const contacts = ref([])
const groupes = ref([])
const history = ref([])
const stats = ref({})
const selectedIds = ref(new Set())
const filterGroupId = ref('')
const addGroupId = ref('')
const search = ref('')
const loading = ref(true)
const saving = ref(false)
const sending = ref(false)
const error = ref('')
const success = ref('')
const contactModal = ref(false)
const groupModal = ref(false)
const smsModal = ref(false)
const editingContact = ref(null)

const page = ref(1)
const perPage = ref(25)
const total = ref(0)
const totalPages = ref(1)

const contactForm = reactive({ id: null, prenom: '', nom: '', contact: '', qualite: '', notes: '' })
const groupForm = reactive({ id: null, nom: '', description: '' })
const smsForm = reactive({ message: '', groupe_id: '' })

async function fetchJson(url, options = {}) {
  const res = await fetch(url, options)
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.success) throw new Error(data.message || data.error || `HTTP ${res.status}`)
  return data
}

async function refreshAll() {
  success.value = ''
  await Promise.all([fetchStats(), fetchContacts(page.value), fetchGroups(), fetchHistory()])
}

async function fetchStats() {
  const data = await fetchJson(`${API_URL}?action=stats&rand=${Date.now()}`)
  stats.value = data.data || {}
}

async function fetchContacts(p = page.value) {
  loading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      action: 'contacts',
      page: String(p),
      per_page: String(perPage.value),
      rand: String(Date.now()),
    })
    if (search.value.trim()) params.set('search', search.value.trim())
    if (filterGroupId.value) params.set('groupe_id', String(filterGroupId.value))
    const data = await fetchJson(`${API_URL}?${params}`)
    contacts.value = (data.data || []).map(normalizeContact)
    selectedIds.value = new Set()
    const pg = data.pagination || {}
    page.value = Number(pg.current_page || p)
    total.value = Number(pg.total || contacts.value.length)
    totalPages.value = Number(pg.last_page || 1)
  } catch (e) {
    error.value = e.message
    contacts.value = []
  } finally {
    loading.value = false
  }
}

async function fetchGroups() {
  const data = await fetchJson(`${API_URL}?action=groupes&rand=${Date.now()}`)
  groupes.value = data.data || []
}

async function fetchHistory() {
  const data = await fetchJson(`${API_URL}?action=history&page=1&per_page=8&rand=${Date.now()}`)
  history.value = data.data || []
}

function normalizeContact(c) {
  const nomComplet = `${c.prenom || ''} ${c.nom || ''}`.trim()
  return {
    ...c,
    nomComplet: nomComplet || 'Sans nom',
    avatar: initiales(nomComplet || '?'),
  }
}

function openContact(contact = null) {
  editingContact.value = contact
  Object.assign(contactForm, {
    id: contact?.id || null,
    prenom: contact?.prenom || '',
    nom: contact?.nom || '',
    contact: contact?.contact || '',
    qualite: contact?.qualite || '',
    notes: contact?.notes || '',
  })
  contactModal.value = true
}

function openGroup(group = null) {
  Object.assign(groupForm, {
    id: group?.id || null,
    nom: group?.nom || '',
    description: group?.description || '',
  })
  groupModal.value = true
}

async function saveContact() {
  saving.value = true
  error.value = ''
  try {
    const action = contactForm.id ? 'update_contact' : 'contacts'
    await fetchJson(`${API_URL}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(contactForm),
    })
    contactModal.value = false
    success.value = contactForm.id ? 'Contact mis à jour.' : 'Contact ajouté au répertoire.'
    await refreshAll()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function saveGroup() {
  saving.value = true
  error.value = ''
  try {
    const action = groupForm.id ? 'update_group' : 'groupes'
    await fetchJson(`${API_URL}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(groupForm),
    })
    groupModal.value = false
    success.value = groupForm.id ? 'Groupe mis à jour.' : 'Groupe créé.'
    await refreshAll()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function addSelectedToGroup() {
  if (!addGroupId.value || !selectedIds.value.size) return
  saving.value = true
  error.value = ''
  try {
    for (const id of selectedIds.value) {
      await fetchJson(`${API_URL}?action=add_group_member`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groupe_id: Number(addGroupId.value), membre_id: id, membre_type: 'contact' }),
      }).catch(() => null)
    }
    success.value = `${selectedIds.value.size} contact(s) ajoutés au groupe.`
    selectedIds.value = new Set()
    await Promise.all([fetchGroups(), fetchContacts(page.value)])
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

function openSms(group = null) {
  smsForm.message = ''
  smsForm.groupe_id = group?.id || ''
  smsModal.value = true
}

async function sendSms() {
  sending.value = true
  error.value = ''
  try {
    const contactsPayload = smsForm.groupe_id
      ? []
      : contacts.value.filter((c) => selectedIds.value.has(c.id)).map((c) => ({ contact: c.contact }))
    const data = await fetchJson(`${API_URL}?action=send_sms`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: smsForm.message,
        groupe_id: smsForm.groupe_id || null,
        contacts: contactsPayload,
      }),
    })
    success.value = data.message || 'SMS envoyé.'
    smsModal.value = false
    selectedIds.value = new Set()
    await Promise.all([fetchStats(), fetchHistory()])
  } catch (e) {
    error.value = e.message
  } finally {
    sending.value = false
  }
}

function toggleContact(id) {
  const next = new Set(selectedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedIds.value = next
}

function toggleAll() {
  selectedIds.value = selectedIds.value.size === contacts.value.length
    ? new Set()
    : new Set(contacts.value.map((c) => c.id))
}

function goToPage(p) {
  if (p < 1 || p > totalPages.value || p === page.value) return
  fetchContacts(p)
}

let debounceTimer = null
watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchContacts(1)
  }, 250)
})

watch(filterGroupId, () => {
  page.value = 1
  fetchContacts(1)
})

function initiales(nom) {
  return nom.trim().split(/\s+/).map((part) => part[0]?.toUpperCase() || '').join('').slice(0, 2)
}

function formatDate(value) {
  if (!value) return '-'
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T')))
}

const selectedCount = computed(() => selectedIds.value.size)
const currentGroup = computed(() => groupes.value.find((g) => Number(g.id) === Number(smsForm.groupe_id)))

onMounted(refreshAll)
</script>

<template>
  <div class="page" style="margin: -15px">
    <div class="content">
      <div class="hero">
        <div>
          <div class="breadcrumb">
            <span class="bc-root">SENAFAD</span>
            <span class="bc-sep">/</span>
            <span class="bc-active">Répertoire</span>
          </div>
          <h1 class="page-title">Répertoire SMS</h1>
          <p class="page-sub">Contacts, groupes et campagnes SMS ciblées</p>
        </div>
        <div class="hero-actions">
          <button class="btn-outline" @click="refreshAll">Actualiser</button>
          <button class="btn-outline" @click="openGroup()">Groupe</button>
          <button class="btn-primary" @click="openContact()">Nouveau contact</button>
        </div>
      </div>

      <div v-if="success" class="search-banner success-banner">{{ success }}</div>
      <div v-if="error" class="search-banner error-banner">{{ error }}</div>

      <div class="kpi-row">
        <div class="kpi-card"><span class="kpi-icon kpi-blue">R</span><div><span class="kpi-val">{{ stats.contacts || 0 }}</span><span class="kpi-label">Contacts</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-green">G</span><div><span class="kpi-val">{{ stats.groupes || 0 }}</span><span class="kpi-label">Groupes</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-amber">S</span><div><span class="kpi-val">{{ stats.messages || 0 }}</span><span class="kpi-label">Messages</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-red">{{ selectedCount }}</span><div><span class="kpi-val">{{ selectedCount }}</span><span class="kpi-label">Sélectionnés</span></div></div>
      </div>

      <div class="tabs">
        <button :class="{ active: activeTab === 'contacts' }" @click="activeTab = 'contacts'">Contacts</button>
        <button :class="{ active: activeTab === 'groupes' }" @click="activeTab = 'groupes'">Groupes</button>
        <button :class="{ active: activeTab === 'history' }" @click="activeTab = 'history'">Historique SMS</button>
      </div>

      <section v-if="activeTab === 'contacts'" class="card">
        <div class="toolbar">
          <div class="search-wrap">
            <span class="search-icon">⌕</span>
            <input v-model="search" class="search-input" type="search" placeholder="Rechercher nom, qualité, contact..." />
          </div>
          <div class="inline-actions">
            <select v-model="filterGroupId" class="select">
              <option value="">Tous les groupes</option>
              <option v-for="g in groupes" :key="`filter-${g.id}`" :value="g.id">{{ g.nom }}</option>
            </select>
            <select v-model="addGroupId" class="select">
              <option value="">Ajouter à un groupe</option>
              <option v-for="g in groupes" :key="g.id" :value="g.id">{{ g.nom }}</option>
            </select>
            <button class="btn-outline" :disabled="!selectedCount || !addGroupId" @click="addSelectedToGroup">Ajouter au groupe</button>
            <button class="btn-primary" :disabled="!selectedCount" @click="openSms()">SMS</button>
          </div>
        </div>

        <div v-if="loading" class="state-block"><div class="spinner"></div><p>Chargement du répertoire...</p></div>
        <div v-else-if="!contacts.length" class="empty">Aucun contact dans le répertoire.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><input type="checkbox" :checked="selectedCount === contacts.length" @change="toggleAll" /></th>
                <th>Contact</th>
                <th>Qualité</th>
                <th>Téléphone</th>
                <th>Source</th>
                <th>Ajout</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in contacts" :key="c.id" class="table-row">
                <td><input type="checkbox" :checked="selectedIds.has(c.id)" @change="toggleContact(c.id)" /></td>
                <td><div class="person"><span class="avatar">{{ c.avatar }}</span><div><span class="person-name">{{ c.nomComplet }}</span><span class="person-mat">#{{ c.id }}</span></div></div></td>
                <td><span class="badge b-soft">{{ c.qualite || 'Non défini' }}</span></td>
                <td><span class="td-cell">{{ c.contact }}</span></td>
                <td><span class="td-cell">{{ c.source || 'manuel' }}</span></td>
                <td><span class="person-mat">{{ formatDate(c.created_at) }}</span></td>
                <td><button class="act" title="Modifier" @click="openContact(c)">✎</button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination">
          <span class="pag-info">Page <strong>{{ page }}</strong> sur <strong>{{ totalPages }}</strong> · {{ total }} contacts</span>
          <div class="pag-pages">
            <button class="pag-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹</button>
            <button class="pag-btn active">{{ page }}</button>
            <button class="pag-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">›</button>
          </div>
        </div>
      </section>

      <section v-if="activeTab === 'groupes'" class="group-grid">
        <article v-for="g in groupes" :key="g.id" class="group-card">
          <div class="group-head">
            <div><h3>{{ g.nom }}</h3><p>{{ g.membre_count || 0 }} contact(s)</p></div>
            <button class="act" @click="openGroup(g)">✎</button>
          </div>
          <p class="group-desc">{{ g.description || 'Aucune description' }}</p>
          <div class="member-chips">
            <span v-for="m in (g.membres || []).slice(0, 8)" :key="`${g.id}-${m.membre_id}`" class="chip">{{ m.prenom }} {{ m.nom }}</span>
          </div>
          <button class="btn-primary btn-wide" :disabled="!(g.membre_count > 0)" @click="openSms(g)">Envoyer SMS au groupe</button>
        </article>
        <button class="group-card group-add" @click="openGroup()">Créer un groupe</button>
      </section>

      <section v-if="activeTab === 'history'" class="card">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Date</th><th>Cible</th><th>Destinataires</th><th>Message</th><th>Statut</th></tr></thead>
            <tbody>
              <tr v-for="h in history" :key="h.id" class="table-row">
                <td><span class="td-cell">{{ formatDate(h.created_at) }}</span></td>
                <td><span class="badge b-soft">{{ h.matricule || 'REPERTOIRE' }}</span></td>
                <td><span class="td-cell">{{ h.phone_numbers }}</span></td>
                <td><span class="message-cell">{{ h.message }}</span></td>
                <td><span class="badge b-present">{{ h.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <Transition name="modal">
      <div v-if="contactModal" class="overlay" @click.self="contactModal = false">
        <div class="modal">
          <div class="modal-header"><h3>{{ editingContact ? 'Modifier le contact' : 'Nouveau contact' }}</h3><button class="modal-close" @click="contactModal = false">×</button></div>
          <form class="modal-body form-grid" @submit.prevent="saveContact">
            <label class="field">Prénom<input v-model="contactForm.prenom" required /></label>
            <label class="field">Nom<input v-model="contactForm.nom" required /></label>
            <label class="field">Contact<input v-model="contactForm.contact" required /></label>
            <label class="field">Qualité<input v-model="contactForm.qualite" /></label>
            <label class="field field-full">Notes<textarea v-model="contactForm.notes" rows="3"></textarea></label>
          </form>
          <div class="modal-footer"><button class="btn-outline" @click="contactModal = false">Annuler</button><button class="btn-primary" :disabled="saving" @click="saveContact">{{ saving ? 'Enregistrement...' : 'Enregistrer' }}</button></div>
        </div>
      </div>
    </Transition>

    <Transition name="modal">
      <div v-if="groupModal" class="overlay" @click.self="groupModal = false">
        <div class="modal">
          <div class="modal-header"><h3>{{ groupForm.id ? 'Modifier le groupe' : 'Nouveau groupe' }}</h3><button class="modal-close" @click="groupModal = false">×</button></div>
          <form class="modal-body" @submit.prevent="saveGroup">
            <label class="field">Nom du groupe<input v-model="groupForm.nom" required /></label>
            <label class="field">Description<textarea v-model="groupForm.description" rows="3"></textarea></label>
          </form>
          <div class="modal-footer"><button class="btn-outline" @click="groupModal = false">Annuler</button><button class="btn-primary" :disabled="saving" @click="saveGroup">{{ saving ? 'Enregistrement...' : 'Enregistrer' }}</button></div>
        </div>
      </div>
    </Transition>

    <Transition name="modal">
      <div v-if="smsModal" class="overlay" @click.self="smsModal = false">
        <div class="modal">
          <div class="modal-header"><h3>Envoyer un SMS</h3><button class="modal-close" @click="smsModal = false">×</button></div>
          <div class="modal-body">
            <p class="modal-mat">{{ smsForm.groupe_id ? `Groupe ${currentGroup?.nom || ''}` : `${selectedCount} contact(s) sélectionné(s)` }}</p>
            <label class="field">Message<textarea v-model="smsForm.message" rows="5" maxlength="480" required></textarea></label>
            <span class="person-mat">{{ smsForm.message.length }}/480 caractères</span>
          </div>
          <div class="modal-footer"><button class="btn-outline" @click="smsModal = false">Annuler</button><button class="btn-primary" :disabled="sending || !smsForm.message" @click="sendSms">{{ sending ? 'Envoi...' : 'Envoyer SMS' }}</button></div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.page { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 18px; max-width: 1600px; width: 100%; margin: 0 auto; }
.hero, .card, .group-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.hero { padding: 20px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.breadcrumb { display: flex; gap: 6px; font-size: 12px; color: #9ca3af; margin-bottom: 5px; }
.bc-active { color: #111; font-weight: 650; }
.page-title { font-size: 24px; font-weight: 760; color: #111; margin: 0 0 3px; letter-spacing: -.03em; }
.page-sub { color: #9ca3af; font-size: 13px; margin: 0; }
.hero-actions, .inline-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 16px; display: flex; gap: 12px; align-items: center; }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; font-weight: 760; }
.kpi-blue { background: rgba(99,102,241,.1); color: #6366f1; } .kpi-green { background: rgba(16,185,129,.1); color: #059669; } .kpi-amber { background: rgba(245,158,11,.1); color: #b45309; } .kpi-red { background: rgba(239,68,68,.1); color: #dc2626; }
.kpi-val { display: block; font-size: 22px; font-weight: 750; color: #111; line-height: 1; }
.kpi-label { font-size: 11.5px; color: #9ca3af; }
.tabs { display: flex; gap: 6px; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 6px; width: fit-content; }
.tabs button { border: 0; background: transparent; padding: 9px 14px; border-radius: 10px; cursor: pointer; color: #6b7280; font-weight: 620; }
.tabs button.active { background: #111827; color: #fff; }
.toolbar { padding: 14px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid rgba(0,0,0,.06); }
.search-wrap { position: relative; width: 360px; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search-input, .select { height: 38px; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 0 12px; background: #fff; font: inherit; font-size: 13px; color: #111; outline: none; }
.search-input { width: 100%; padding-left: 34px; }
.btn-primary, .btn-outline { display: inline-flex; align-items: center; justify-content: center; gap: 7px; border-radius: 10px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 650; cursor: pointer; }
.btn-primary { background: #6366f1; color: #fff; border: 0; box-shadow: 0 2px 8px rgba(99,102,241,.25); }
.btn-outline { background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); }
button:disabled { opacity: .5; cursor: not-allowed; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 720; color: #6b7280; text-transform: uppercase; white-space: nowrap; }
.table td { padding: 12px 14px; border-bottom: 1px solid rgba(0,0,0,.05); vertical-align: middle; }
.table-row:hover { background: #fafbff; }
.person { display: flex; align-items: center; gap: 10px; }
.avatar { width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center; background: rgba(99,102,241,.1); color: #6366f1; font-weight: 760; font-size: 11px; }
.person-name { font-size: 13.5px; font-weight: 650; color: #111; white-space: nowrap; }
.person-mat { display: block; font-size: 11px; color: #9ca3af; margin-top: 2px; }
.td-cell { color: #374151; white-space: nowrap; }
.badge { display: inline-flex; align-items: center; border-radius: 20px; padding: 3px 9px; font-size: 11px; font-weight: 650; white-space: nowrap; }
.b-soft { background: rgba(99,102,241,.09); color: #4f46e5; } .b-present { background: rgba(16,185,129,.1); color: #059669; }
.act { width: 30px; height: 30px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; cursor: pointer; color: #374151; }
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; } .pag-info strong { color: #374151; }
.pag-pages { display: flex; gap: 3px; }
.pag-btn { min-width: 30px; height: 30px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; cursor: pointer; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; }
.group-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.group-card { padding: 18px; display: flex; flex-direction: column; gap: 12px; min-height: 210px; }
.group-head { display: flex; justify-content: space-between; gap: 10px; }
.group-head h3 { margin: 0 0 3px; font-size: 16px; color: #111; }
.group-head p, .group-desc { margin: 0; color: #9ca3af; font-size: 12.5px; }
.member-chips { display: flex; gap: 6px; flex-wrap: wrap; min-height: 36px; }
.chip { background: #f3f4f6; color: #374151; border-radius: 999px; padding: 4px 8px; font-size: 11px; }
.btn-wide { width: 100%; margin-top: auto; }
.group-add { border-style: dashed; color: #6366f1; font-weight: 750; cursor: pointer; align-items: center; justify-content: center; }
.message-cell { display: block; max-width: 520px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #374151; }
.search-banner { padding: 10px 16px; border-radius: 10px; font-size: 12.5px; }
.success-banner { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.18); color: #059669; }
.error-banner { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.16); color: #dc2626; }
.state-block, .empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: #9ca3af; }
.spinner { width: 34px; height: 34px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); }
.modal { background: #fff; border-radius: 18px; width: 560px; max-width: calc(100vw - 40px); box-shadow: 0 32px 80px rgba(0,0,0,.18); overflow: hidden; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; background: #fafafa; border-bottom: 1px solid rgba(0,0,0,.06); }
.modal-header h3 { margin: 0; font-size: 16px; color: #111; }
.modal-close { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; cursor: pointer; }
.modal-body { padding: 22px; display: flex; flex-direction: column; gap: 12px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 16px 22px; background: #fafafa; border-top: 1px solid rgba(0,0,0,.06); }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { display: flex; flex-direction: column; gap: 5px; font-size: 12px; color: #374151; font-weight: 700; }
.field input, .field textarea { border: 1px solid rgba(0,0,0,.12); border-radius: 9px; padding: 9px 10px; font: inherit; font-size: 13px; outline: none; }
.field-full { grid-column: 1 / -1; }
.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from, .modal-leave-to { opacity: 0; transform: scale(.96) translateY(10px); }
@media (max-width: 900px) {
  .kpi-row { grid-template-columns: 1fr 1fr; }
  .toolbar, .hero { flex-direction: column; align-items: stretch; }
  .search-wrap { width: 100%; }
}
@media (max-width: 640px) {
  .kpi-row, .form-grid { grid-template-columns: 1fr; }
  .tabs { width: 100%; overflow-x: auto; }
}
</style>
