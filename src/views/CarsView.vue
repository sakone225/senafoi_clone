<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const API_URL = 'https://api.aeemci-ce.ci/senafoi/senafoi26_cars_api.php'
const auth = useAuthStore()

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toastMsg = ref('')
const toastType = ref('success')
const cars = ref([])
const selectedCar = ref(null)
const carSeminaristes = ref([])
const search = ref('')
const searchResults = ref([])
const searching = ref(false)

const form = reactive({
  id: null,
  code: '',
  nom: '',
  capacite: '',
  description: '',
})

const reassign = reactive({
  matricule: '',
  car_code: '',
})

const canManage = computed(() => auth.canEdit('cars'))
const totalCapacity = computed(() => cars.value.reduce((sum, car) => sum + Number(car.capacite || 0), 0))
const totalAssigned = computed(() => cars.value.reduce((sum, car) => sum + Number(car.assigned_count || 0), 0))
const totalRemaining = computed(() => Math.max(0, totalCapacity.value - totalAssigned.value))

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

async function api(action, payload = null, params = {}) {
  const query = new URLSearchParams({ action, ...params })
  const options = { headers: { ...auth.authHeaders() } }
  if (payload) {
    options.method = 'POST'
    options.headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(payload)
  }
  const res = await fetch(`${API_URL}?${query.toString()}`, options)
  return parseResponse(res)
}

async function loadCars(showLoader = false) {
  if (showLoader) loading.value = true
  error.value = ''
  try {
    const data = await api('list')
    cars.value = data.cars || []
    if (selectedCar.value) {
      const fresh = cars.value.find(car => car.code === selectedCar.value.code)
      selectedCar.value = fresh || null
    }
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

function resetForm() {
  Object.assign(form, { id: null, code: '', nom: '', capacite: '', description: '' })
}

function editCar(car) {
  Object.assign(form, {
    id: car.id,
    code: car.code || '',
    nom: car.nom || '',
    capacite: car.capacite || '',
    description: car.description || '',
  })
}

async function saveCar() {
  if (!canManage.value) return
  const capacite = Number(form.capacite)
  if (!form.code.trim() || !form.nom.trim() || !Number.isFinite(capacite) || capacite < 0) {
    toast('Code, nom et quota valides sont requis.', 'error')
    return
  }
  saving.value = true
  try {
    await api(form.id ? 'update' : 'create', {
      id: form.id,
      code: form.code.trim(),
      nom: form.nom.trim(),
      capacite: Math.round(capacite),
      description: form.description.trim(),
    })
    toast(form.id ? 'Car mis a jour.' : 'Car cree.')
    resetForm()
    await loadCars()
    if (selectedCar.value) await loadCarSeminaristes(selectedCar.value)
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

async function loadCarSeminaristes(car) {
  selectedCar.value = car
  reassign.car_code = car.code
  try {
    const data = await api('seminaristes', null, { car_code: car.code })
    carSeminaristes.value = data.seminaristes || []
  } catch (e) {
    toast(e.message, 'error')
  }
}

async function searchSeminaristes() {
  if (!search.value.trim()) {
    searchResults.value = []
    return
  }
  searching.value = true
  try {
    const data = await api('search_seminaristes', null, { q: search.value.trim() })
    searchResults.value = data.seminaristes || []
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    searching.value = false
  }
}

function chooseSeminariste(s) {
  reassign.matricule = s.matricule_seminaire
}

async function reassignSeminariste() {
  if (!canManage.value) return
  if (!reassign.matricule || !reassign.car_code) {
    toast('Choisis un seminariste et un car.', 'error')
    return
  }
  saving.value = true
  try {
    await api('reassign', { matricule: reassign.matricule, car_code: reassign.car_code })
    toast('Reaffectation effectuee.')
    searchResults.value = []
    search.value = ''
    reassign.matricule = ''
    await loadCars()
    const car = cars.value.find(c => c.code === reassign.car_code)
    if (car) await loadCarSeminaristes(car)
  } catch (e) {
    toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

function usagePct(car) {
  const cap = Number(car.capacite || 0)
  if (!cap) return 0
  return Math.min(100, Math.round((Number(car.assigned_count || 0) / cap) * 100))
}

function carStatus(car) {
  const remaining = Number(car.places_restantes || 0)
  if (remaining <= 0) return 'Complet'
  if (remaining <= 5) return 'Presque plein'
  return 'Disponible'
}

function initials(s) {
  return `${String(s.prenom || '').charAt(0)}${String(s.nom || '').charAt(0)}`.toUpperCase() || 'S'
}

function toast(message, type = 'success') {
  toastMsg.value = message
  toastType.value = type
  window.setTimeout(() => {
    if (toastMsg.value === message) toastMsg.value = ''
  }, 3200)
}

onMounted(() => loadCars(true))
</script>

<template>
  <div class="cars-page">
    <div class="breadcrumb">
      <span>Administration</span>
      <span>/</span>
      <strong>Cars SENAFOI 2026</strong>
    </div>

    <div class="page-header">
      <div>
        <p class="eyebrow">Transport</p>
        <h1>Gestion des cars</h1>
        <p>Configure les quotas par car, suis le remplissage et reaffecte les seminaristes sans depasser les capacites.</p>
      </div>
      <button class="btn-outline" @click="loadCars(true)">Actualiser</button>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card"><span>Capacite totale</span><strong>{{ totalCapacity }}</strong></div>
      <div class="kpi-card"><span>Affectes</span><strong>{{ totalAssigned }}</strong></div>
      <div class="kpi-card"><span>Places restantes</span><strong>{{ totalRemaining }}</strong></div>
    </div>

    <div v-if="loading" class="state">Chargement des cars...</div>
    <div v-else-if="error" class="state state-error">{{ error }}</div>

    <template v-else>
      <div class="main-grid">
        <section class="panel cars-list-panel">
          <div class="panel-head">
            <div>
              <h2>Cars configures</h2>
              <p>{{ cars.length }} car{{ cars.length > 1 ? 's' : '' }} pour 2026.</p>
            </div>
          </div>

          <div v-if="!cars.length" class="empty">Aucun car configure.</div>
          <div v-else class="cars-list">
            <button
              v-for="car in cars"
              :key="car.id"
              class="car-card"
              :class="{ active: selectedCar?.code === car.code }"
              @click="loadCarSeminaristes(car)"
            >
              <div class="car-top">
                <div>
                  <strong>{{ car.code }}</strong>
                  <span>{{ car.nom }}</span>
                </div>
                <em :class="{ full: Number(car.places_restantes) <= 0 }">{{ carStatus(car) }}</em>
              </div>
              <div class="meter"><i :style="{ width: `${usagePct(car)}%` }"></i></div>
              <div class="car-meta">
                <span>{{ car.assigned_count }} / {{ car.capacite }}</span>
                <span>{{ car.places_restantes }} place{{ Number(car.places_restantes) > 1 ? 's' : '' }}</span>
              </div>
              <div class="car-actions">
                <span>Voir la liste</span>
                <button v-if="canManage" type="button" @click.stop="editCar(car)">Modifier</button>
              </div>
            </button>
          </div>
        </section>

        <aside class="side-stack">
          <section class="panel">
            <div class="panel-head">
              <div>
                <h2>{{ form.id ? 'Modifier un car' : 'Creer un car' }}</h2>
                <p>Le quota ne peut pas etre inferieur au nombre deja affecte.</p>
              </div>
            </div>
            <div class="form-grid">
              <label><span>Code</span><input v-model="form.code" :disabled="!canManage" placeholder="CAR_01" /></label>
              <label><span>Nom</span><input v-model="form.nom" :disabled="!canManage" placeholder="Car Alpha" /></label>
              <label><span>Quota</span><input v-model="form.capacite" :disabled="!canManage" type="number" min="0" /></label>
              <label class="full"><span>Description</span><textarea v-model="form.description" :disabled="!canManage" rows="3" /></label>
            </div>
            <div class="actions">
              <button class="btn-outline" @click="resetForm">Annuler</button>
              <button class="btn-primary" :disabled="saving || !canManage" @click="saveCar">{{ form.id ? 'Enregistrer' : 'Creer' }}</button>
            </div>
          </section>

          <section class="panel">
            <div class="panel-head">
              <div>
                <h2>Reaffecter</h2>
                <p>Uniquement vers un car dont le quota n'est pas atteint.</p>
              </div>
            </div>
            <label class="search-field">
              <span>Matricule ou nom</span>
              <div>
                <input v-model="search" :disabled="!canManage" placeholder="SEM2026... ou nom" @keyup.enter="searchSeminaristes" />
                <button class="btn-outline" :disabled="searching || !canManage" @click="searchSeminaristes">Chercher</button>
              </div>
            </label>
            <div v-if="searchResults.length" class="search-results">
              <button v-for="s in searchResults" :key="s.id" @click="chooseSeminariste(s)">
                <span class="avatar">{{ initials(s) }}</span>
                <span><strong>{{ s.prenom }} {{ s.nom }}</strong><small>{{ s.matricule_seminaire }} · actuel {{ s.car_transport || 'aucun' }}</small></span>
              </button>
            </div>
            <div class="reassign-row">
              <input v-model="reassign.matricule" :disabled="!canManage" placeholder="Matricule choisi" />
              <select v-model="reassign.car_code" :disabled="!canManage">
                <option value="">Car cible</option>
                <option v-for="car in cars" :key="car.code" :value="car.code" :disabled="Number(car.places_restantes) <= 0">
                  {{ car.code }} - {{ car.nom }} ({{ car.places_restantes }} places)
                </option>
              </select>
            </div>
            <button class="btn-primary wide" :disabled="saving || !canManage" @click="reassignSeminariste">Reaffecter</button>
          </section>
        </aside>
      </div>

      <section v-if="selectedCar" class="panel selected-panel">
        <div class="panel-head">
          <div>
            <h2>{{ selectedCar.code }} - {{ selectedCar.nom }}</h2>
            <p>{{ carSeminaristes.length }} seminariste{{ carSeminaristes.length > 1 ? 's' : '' }} affecte{{ carSeminaristes.length > 1 ? 's' : '' }}.</p>
          </div>
        </div>
        <div v-if="!carSeminaristes.length" class="empty">Aucun seminariste dans ce car.</div>
        <div v-else class="seminaristes-grid">
          <div v-for="s in carSeminaristes" :key="s.id" class="seminariste-card">
            <img v-if="s.photo" :src="s.photo" alt="" />
            <span v-else class="avatar">{{ initials(s) }}</span>
            <div>
              <strong>{{ s.prenom }} {{ s.nom }}</strong>
              <small>{{ s.matricule_seminaire }} · {{ s.niveau_seminaire }}</small>
            </div>
          </div>
        </div>
      </section>
    </template>

    <Transition name="toast">
      <div v-if="toastMsg" class="toast" :class="toastType">{{ toastMsg }}</div>
    </Transition>
  </div>
</template>

<style scoped>
.cars-page { display: flex; flex-direction: column; gap: 18px; }
.breadcrumb { display: flex; gap: 8px; color: #64748b; font-size: 12px; font-weight: 700; }
.breadcrumb strong { color: #15803d; }
.page-header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.eyebrow { color: #15803d; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: .08em; }
.page-header h1 { margin: 4px 0; font-size: 30px; color: #0f172a; }
.page-header p, .panel-head p { color: #64748b; font-size: 13px; }
.kpi-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.kpi-card, .panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, .06); }
.kpi-card { padding: 16px; }
.kpi-card span { color: #64748b; font-size: 12px; font-weight: 800; }
.kpi-card strong { display: block; margin-top: 6px; font-size: 28px; color: #14532d; }
.main-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(330px, .75fr); gap: 16px; align-items: start; }
.panel { padding: 16px; }
.panel-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
.panel-head h2 { color: #111827; font-size: 18px; margin: 0 0 4px; }
.cars-list { display: grid; gap: 10px; }
.car-card { text-align: left; border: 1px solid #e5e7eb; background: #f8fafc; border-radius: 12px; padding: 13px; cursor: pointer; transition: .18s ease; }
.car-card:hover, .car-card.active { border-color: #16a34a; background: #f0fdf4; transform: translateY(-1px); }
.car-top, .car-meta, .car-actions { display: flex; justify-content: space-between; gap: 10px; align-items: center; }
.car-top strong { display: block; color: #0f172a; font-size: 16px; }
.car-top span, .car-meta { color: #64748b; font-size: 12px; }
.car-top em { border-radius: 999px; background: #dcfce7; color: #166534; padding: 4px 8px; font-size: 11px; font-style: normal; font-weight: 900; }
.car-top em.full { background: #fee2e2; color: #991b1b; }
.meter { height: 8px; border-radius: 999px; background: #e5e7eb; overflow: hidden; margin: 11px 0; }
.meter i { display: block; height: 100%; background: linear-gradient(90deg, #16a34a, #f59e0b); border-radius: inherit; }
.car-actions { margin-top: 10px; }
.car-actions span { color: #15803d; font-size: 12px; font-weight: 900; }
.car-actions button { border: 0; background: #fff; color: #15803d; border-radius: 8px; padding: 6px 9px; font-weight: 900; cursor: pointer; }
.side-stack { display: grid; gap: 16px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
label { display: grid; gap: 6px; }
label span { color: #475569; font-size: 12px; font-weight: 800; }
input, textarea, select { width: 100%; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 11px; font: inherit; color: #0f172a; background: #fff; }
textarea { resize: vertical; }
.full { grid-column: 1 / -1; }
.actions, .reassign-row { display: flex; gap: 10px; margin-top: 12px; }
.btn-primary, .btn-outline { border-radius: 10px; padding: 10px 13px; font-weight: 900; cursor: pointer; border: 1px solid transparent; }
.btn-primary { background: #15803d; color: #fff; }
.btn-outline { background: #fff; color: #15803d; border-color: #86efac; }
button:disabled, input:disabled, textarea:disabled, select:disabled { opacity: .55; cursor: not-allowed; }
.wide { width: 100%; margin-top: 12px; }
.search-field > div { display: flex; gap: 8px; }
.search-results { display: grid; gap: 8px; margin-top: 10px; max-height: 220px; overflow: auto; }
.search-results button, .seminariste-card { display: flex; align-items: center; gap: 10px; border: 1px solid #e5e7eb; background: #fff; border-radius: 11px; padding: 9px; text-align: left; }
.search-results small, .seminariste-card small { display: block; color: #64748b; font-size: 12px; margin-top: 2px; }
.avatar, .seminariste-card img { width: 38px; height: 38px; border-radius: 50%; flex: 0 0 38px; }
.avatar { display: grid; place-items: center; background: #dcfce7; color: #166534; font-size: 12px; font-weight: 900; }
.seminaristes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; }
.seminariste-card img { object-fit: cover; }
.state, .empty { border: 1px dashed #cbd5e1; border-radius: 14px; padding: 24px; text-align: center; color: #64748b; background: #fff; }
.state-error { color: #991b1b; border-color: #fecaca; background: #fef2f2; }
.toast { position: fixed; right: 24px; bottom: 24px; z-index: 30; padding: 12px 15px; border-radius: 12px; background: #14532d; color: #fff; font-weight: 800; box-shadow: 0 18px 38px rgba(15, 23, 42, .2); }
.toast.error { background: #991b1b; }
.toast-enter-active, .toast-leave-active { transition: .18s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(8px); }
@media (max-width: 980px) {
  .main-grid, .kpi-grid { grid-template-columns: 1fr; }
  .page-header, .actions, .reassign-row, .search-field > div { flex-direction: column; }
}
</style>
