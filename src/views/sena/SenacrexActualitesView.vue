<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import {
  AlertCircle,
  CalendarDays,
  CheckCircle,
  Eye,
  FileText,
  Image,
  LoaderCircle,
  MapPin,
  Newspaper,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  Search,
  Trash2,
  UploadCloud,
  X,
} from 'lucide-vue-next'

const API_URL = 'https://api.aeemci-ce.ci/api_mobile/aeemci_actualites_api.php'
const CLOUD_UPLOAD_URL = 'https://api.aeemci-ce.ci/cloud/upload.php'
const CLOUD_API_KEY = 'SAKONE25_04_2026_medias'

const typeOptions = ['EVENEMENT', 'FORMATION', 'SEMINAIRE', 'PARTENARIAT', 'COMMUNIQUE', 'AUTRE']
const statusOptions = ['PUBLIÉ', 'BROUILLON', 'ARCHIVÉ']

const actualites = ref([])
const latest = ref([])
const stats = ref({})
const loading = ref(true)
const saving = ref(false)
const deleting = ref(false)
const uploading = ref(false)
const modalOpen = ref(false)
const deleteTarget = ref(null)
const editingId = ref(null)
const error = ref('')
const success = ref('')

const filters = reactive({
  search: '',
  type: '',
  statut: '',
})

const form = reactive(baseForm())

function baseForm() {
  return {
    titre: '',
    type: 'EVENEMENT',
    statut: 'BROUILLON',
    lieu: '',
    date_debut: '',
    date_fin: '',
    date_specifique: '',
    auteur: 'AEEMCI Communication',
    texte_affichage: '',
    texte_detaille: '',
    photos: [],
    photo_url: '',
  }
}

async function fetchJson(url, options = {}) {
  const res = await fetch(url, options)
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.success) throw new Error(data.message || data.error || `HTTP ${res.status}`)
  return data
}

async function refreshAll() {
  success.value = ''
  await Promise.all([fetchActualites(), fetchStats(), fetchLatest()])
}

async function fetchActualites() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchJson(`${API_URL}?action=list&limit=200&rand=${Date.now()}`)
    actualites.value = (data.data || data.actualites || []).map(normalizeActualite)
  } catch (e) {
    error.value = e.message
    actualites.value = []
  } finally {
    loading.value = false
  }
}

async function fetchLatest() {
  try {
    const data = await fetchJson(`${API_URL}?action=latest&limit=4&rand=${Date.now()}`)
    latest.value = (data.data || data.actualites || []).map(normalizeActualite)
  } catch {
    latest.value = []
  }
}

async function fetchStats() {
  try {
    const data = await fetchJson(`${API_URL}?action=stats&rand=${Date.now()}`)
    stats.value = data.data || {}
  } catch {
    stats.value = {}
  }
}

function normalizeActualite(item) {
  const photos = parsePhotos(item.photos)
  const titre = item.titre || item.title || ''
  const imageUrl = item.image || item.image_url || photoUrl(photos[0])
  return {
    ...item,
    id: Number(item.id),
    titre,
    type: normalizeType(item.type || item.category || 'AUTRE'),
    statut: normalizeStatus(item.statut || item.status),
    lieu: item.lieu || item.location || '',
    texte_affichage: item.texte_affichage || item.texteAffichage || item.excerpt || '',
    texte_detaille: item.texte_detaille || item.texteDetaille || item.content || '',
    date_debut: cleanDate(item.date_debut || item.dateDebut),
    date_fin: cleanDate(item.date_fin || item.dateFin),
    date_specifique: cleanDate(item.date_specifique || item.dateSpecifique || item.date),
    auteur: item.auteur || item.author || 'AEEMCI Communication',
    photos,
    image: imageUrl,
  }
}

function parsePhotos(value) {
  if (!value) return []
  if (Array.isArray(value)) return value.map(normalizePhoto).filter(Boolean)
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      return Array.isArray(parsed) ? parsed.map(normalizePhoto).filter(Boolean) : []
    } catch {
      return value.trim() ? [{ url: value.trim(), preview: value.trim() }] : []
    }
  }
  return []
}

function normalizePhoto(photo) {
  if (!photo) return null
  if (typeof photo === 'string') return { url: photo, preview: photo }
  const url = photo.url || photo.preview || photo.image || ''
  return url ? { ...photo, url, preview: photo.preview || url } : null
}

function photoUrl(photo) {
  if (!photo) return ''
  return typeof photo === 'string' ? photo : (photo.url || photo.preview || '')
}

function normalizeStatus(status) {
  const raw = String(status || 'BROUILLON').trim().toUpperCase()
  if (['PUBLIE', 'PUBLIÉE', 'PUBLIEE', 'ACTIF', 'ACTIVE'].includes(raw)) return 'PUBLIÉ'
  if (['ARCHIVE', 'ARCHIVÉE', 'ARCHIVEE'].includes(raw)) return 'ARCHIVÉ'
  return ['PUBLIÉ', 'BROUILLON', 'ARCHIVÉ'].includes(raw) ? raw : 'BROUILLON'
}

function normalizeType(type) {
  const raw = String(type || 'AUTRE').trim().toUpperCase()
  if (['ÉVÉNEMENT', 'EVENEMENT'].includes(raw)) return 'EVENEMENT'
  if (['SÉMINAIRE', 'SEMINAIRE'].includes(raw)) return 'SEMINAIRE'
  return raw || 'AUTRE'
}

function cleanDate(value) {
  if (!value) return ''
  const text = String(value)
  if (text.startsWith('0000-00-00')) return ''
  return text.slice(0, 10)
}

function openCreate() {
  editingId.value = null
  Object.assign(form, baseForm())
  modalOpen.value = true
}

function openEdit(actualite) {
  editingId.value = actualite.id
  Object.assign(form, baseForm(), {
    titre: actualite.titre,
    type: actualite.type,
    statut: actualite.statut,
    lieu: actualite.lieu,
    date_debut: actualite.date_debut,
    date_fin: actualite.date_fin,
    date_specifique: actualite.date_specifique,
    auteur: actualite.auteur,
    texte_affichage: actualite.texte_affichage,
    texte_detaille: actualite.texte_detaille,
    photos: [...(actualite.photos || [])],
    photo_url: '',
  })
  modalOpen.value = true
}

function closeModal() {
  if (saving.value || uploading.value) return
  modalOpen.value = false
}

function addPhotoUrl() {
  const url = form.photo_url.trim()
  if (!url) return
  form.photos.push({ url, preview: url, alt: form.titre || 'Actualite AEEMCI' })
  form.photo_url = ''
}

function removePhoto(index) {
  form.photos.splice(index, 1)
}

async function uploadImages(event) {
  const files = Array.from(event.target.files || [])
  event.target.value = ''
  if (!files.length) return
  uploading.value = true
  error.value = ''
  try {
    for (const file of files) {
      if (!file.type.startsWith('image/')) throw new Error('Seules les images sont acceptees.')
      if (file.size > 8 * 1024 * 1024) throw new Error('Image trop volumineuse. Maximum 8 Mo.')
      const body = new FormData()
      body.append('file', file)
      body.append('folder', 'actualites')
      body.append('subfolder', 'photos')
      const res = await fetch(CLOUD_UPLOAD_URL, {
        method: 'POST',
        headers: { 'X-API-Key': CLOUD_API_KEY },
        body,
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.success || !data.url) {
        throw new Error(data.message || data.error || 'Upload impossible.')
      }
      form.photos.push({
        url: data.url,
        preview: data.url,
        public_id: data.public_id || null,
        filename: file.name,
        alt: form.titre || file.name,
      })
    }
  } catch (e) {
    error.value = e.message
  } finally {
    uploading.value = false
  }
}

function validateForm() {
  if (!form.titre.trim()) return 'Le titre est requis.'
  if (!form.lieu.trim()) return 'Le lieu est requis.'
  if (!form.texte_affichage.trim()) return 'Le resume est requis.'
  if (!form.texte_detaille.trim()) return 'Le contenu detaille est requis.'
  return ''
}

async function saveActualite() {
  const validation = validateForm()
  if (validation) {
    error.value = validation
    return
  }

  saving.value = true
  error.value = ''
  try {
    const payload = {
      titre: form.titre.trim(),
      type: form.type,
      statut: form.statut,
      lieu: form.lieu.trim(),
      texte_affichage: form.texte_affichage.trim(),
      texte_detaille: form.texte_detaille,
      photos: form.photos,
      date_debut: form.date_debut || null,
      date_fin: form.date_fin || null,
      date_specifique: form.date_specifique || null,
      auteur: form.auteur.trim() || 'AEEMCI Communication',
    }
    const action = editingId.value ? `update&id=${editingId.value}` : 'create'
    const data = await fetchJson(`${API_URL}?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    success.value = data.message || (editingId.value ? 'Actualite mise a jour.' : 'Actualite creee.')
    modalOpen.value = false
    await refreshAll()
  } catch (e) {
    error.value = e.message
  } finally {
    saving.value = false
  }
}

async function confirmDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  error.value = ''
  try {
    await fetchJson(`${API_URL}?action=delete&id=${deleteTarget.value.id}`, { method: 'POST' })
    success.value = 'Actualite supprimee.'
    deleteTarget.value = null
    await refreshAll()
  } catch (e) {
    error.value = e.message
  } finally {
    deleting.value = false
  }
}

const filteredActualites = computed(() => {
  const q = filters.search.trim().toLowerCase()
  return actualites.value.filter((a) => {
    const haystack = [a.titre, a.texte_affichage, a.lieu, a.type, a.auteur].join(' ').toLowerCase()
    return (!q || haystack.includes(q))
      && (!filters.type || a.type === filters.type)
      && (!filters.statut || a.statut === filters.statut)
  })
})

const mainLatest = computed(() => latest.value[0] || null)
const secondaryLatest = computed(() => latest.value.slice(1, 4))

const computedStats = computed(() => ({
  total: Number(stats.value.total ?? actualites.value.length),
  publiees: Number(stats.value.publiees ?? actualites.value.filter((a) => a.statut === 'PUBLIÉ').length),
  brouillons: Number(stats.value.brouillons ?? actualites.value.filter((a) => a.statut === 'BROUILLON').length),
  images: actualites.value.reduce((sum, a) => sum + (a.photos?.length || 0), 0),
}))

function statusClass(status) {
  return String(status || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

function displayDate(actualite) {
  const raw = actualite.date_specifique || actualite.date_debut || actualite.published_at || actualite.created_at
  if (!raw) return 'Date non renseignee'
  try {
    return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(String(raw).replace(' ', 'T')))
  } catch {
    return raw
  }
}

function excerpt(text, size = 170) {
  const plain = String(text || '').replace(/<[^>]*>/g, '').trim()
  return plain.length > size ? `${plain.slice(0, size)}...` : plain
}

onMounted(refreshAll)
</script>

<template>
  <div class="page" style="margin: -15px">
    <div class="content">
      <div class="hero">
        <div>
          <div class="breadcrumb">
            <span>SENACREX</span>
            <span>/</span>
            <span class="bc-active">Actualites</span>
          </div>
          <h1 class="page-title">Actualites AEEMCI</h1>
          <p class="page-sub">Creation, publication et alimentation de la zone Actualites du site officiel.</p>
        </div>
        <div class="hero-actions">
          <a class="btn-outline" href="https://www.aeemci-ce.ci/" target="_blank" rel="noreferrer">
            <Eye :size="16" /> Site AEEMCI
          </a>
          <button class="btn-outline" type="button" @click="refreshAll">
            <RefreshCw :size="16" :class="{ spin: loading }" /> Actualiser
          </button>
          <button class="btn-primary" type="button" @click="openCreate">
            <Plus :size="16" /> Nouvelle actualite
          </button>
        </div>
      </div>

      <div v-if="success" class="banner success-banner">{{ success }}</div>
      <div v-if="error" class="banner error-banner">{{ error }}</div>

      <div class="kpi-row">
        <div class="kpi-card"><span class="kpi-icon kpi-blue"><Newspaper :size="18" /></span><div><span class="kpi-val">{{ computedStats.total }}</span><span class="kpi-label">Actualites</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-green"><CheckCircle :size="18" /></span><div><span class="kpi-val">{{ computedStats.publiees }}</span><span class="kpi-label">Publiees</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-amber"><FileText :size="18" /></span><div><span class="kpi-val">{{ computedStats.brouillons }}</span><span class="kpi-label">Brouillons</span></div></div>
        <div class="kpi-card"><span class="kpi-icon kpi-red"><Image :size="18" /></span><div><span class="kpi-val">{{ computedStats.images }}</span><span class="kpi-label">Images</span></div></div>
      </div>

      <section class="preview-band">
        <div class="section-head">
          <div>
            <h2>Affichage site public</h2>
            <p>La derniere actualite publiee devient principale, les trois suivantes restent visibles autour.</p>
          </div>
          <span class="mini-pill">{{ latest.length }}/4 visibles</span>
        </div>
        <div v-if="!latest.length" class="empty-preview">Aucune actualite publiee pour le moment.</div>
        <div v-else class="public-preview">
          <article class="featured-preview">
            <img v-if="mainLatest?.image" :src="mainLatest.image" :alt="mainLatest.titre" />
            <div v-else class="image-fallback"><Newspaper :size="34" /></div>
            <div class="featured-copy">
              <span class="status-pill publie">{{ mainLatest.type }}</span>
              <h2>{{ mainLatest.titre }}</h2>
              <p>{{ excerpt(mainLatest.texte_affichage || mainLatest.texte_detaille, 220) }}</p>
              <div class="meta-line"><span><CalendarDays :size="14" />{{ displayDate(mainLatest) }}</span><span><MapPin :size="14" />{{ mainLatest.lieu || '-' }}</span></div>
            </div>
          </article>
          <div class="side-preview">
            <article v-for="item in secondaryLatest" :key="item.id" class="mini-news">
              <img v-if="item.image" :src="item.image" :alt="item.titre" />
              <div v-else class="mini-fallback"><Newspaper :size="16" /></div>
              <div>
                <strong>{{ item.titre }}</strong>
                <span>{{ displayDate(item) }}</span>
              </div>
            </article>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="toolbar">
          <label class="search-wrap">
            <Search :size="16" class="search-icon" />
            <input v-model="filters.search" class="search-input" type="search" placeholder="Rechercher titre, lieu, auteur..." />
          </label>
          <div class="inline-actions">
            <select v-model="filters.type" class="select">
              <option value="">Tous les types</option>
              <option v-for="type in typeOptions" :key="type" :value="type">{{ type }}</option>
            </select>
            <select v-model="filters.statut" class="select">
              <option value="">Tous les statuts</option>
              <option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option>
            </select>
          </div>
        </div>

        <div v-if="loading" class="state-block"><LoaderCircle class="spin" :size="30" /><p>Chargement des actualites...</p></div>
        <div v-else-if="!filteredActualites.length" class="empty">Aucune actualite trouvee.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Actualite</th>
                <th>Type</th>
                <th>Date</th>
                <th>Lieu</th>
                <th>Statut</th>
                <th>Images</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in filteredActualites" :key="item.id" class="table-row">
                <td>
                  <div class="news-ident">
                    <div class="thumb"><img v-if="item.image" :src="item.image" :alt="item.titre" /><Newspaper v-else :size="20" /></div>
                    <div>
                      <strong>{{ item.titre }}</strong>
                      <span>{{ excerpt(item.texte_affichage, 90) || 'Aucun resume' }}</span>
                    </div>
                  </div>
                </td>
                <td><span class="badge b-soft">{{ item.type }}</span></td>
                <td><span class="td-cell">{{ displayDate(item) }}</span></td>
                <td><span class="td-cell">{{ item.lieu || '-' }}</span></td>
                <td><span class="status-pill" :class="statusClass(item.statut)">{{ item.statut }}</span></td>
                <td><span class="td-cell">{{ item.photos?.length || 0 }}</span></td>
                <td>
                  <div class="row-actions">
                    <button class="icon-btn" type="button" title="Modifier" @click="openEdit(item)"><Pencil :size="16" /></button>
                    <button class="icon-btn danger" type="button" title="Supprimer" @click="deleteTarget = item"><Trash2 :size="16" /></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <Transition name="modal">
      <div v-if="modalOpen" class="overlay" @click.self="closeModal">
        <form class="modal" @submit.prevent="saveActualite">
          <div class="modal-header">
            <div>
              <h3>{{ editingId ? 'Modifier actualite' : 'Nouvelle actualite' }}</h3>
              <p>Les actualites publiees sont visibles sur le site AEEMCI.</p>
            </div>
            <button class="modal-close" type="button" @click="closeModal"><X :size="18" /></button>
          </div>
          <div class="modal-body">
            <div class="form-grid">
              <label class="field wide">Titre<input v-model="form.titre" required /></label>
              <label class="field">Type<select v-model="form.type" required><option v-for="type in typeOptions" :key="type" :value="type">{{ type }}</option></select></label>
              <label class="field">Statut<select v-model="form.statut" required><option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option></select></label>
              <label class="field">Date principale<input v-model="form.date_specifique" type="date" /></label>
              <label class="field">Date debut<input v-model="form.date_debut" type="date" /></label>
              <label class="field">Date fin<input v-model="form.date_fin" type="date" /></label>
              <label class="field wide">Lieu<input v-model="form.lieu" required placeholder="Ville, espace, section..." /></label>
              <label class="field wide">Auteur<input v-model="form.auteur" placeholder="AEEMCI Communication" /></label>
              <label class="field wide">Resume<textarea v-model="form.texte_affichage" rows="3" required></textarea></label>
              <label class="field wide">Contenu detaille<textarea v-model="form.texte_detaille" rows="7" required></textarea></label>
            </div>

            <div class="photo-panel">
              <div class="photo-panel-head">
                <div><strong>Images</strong><span>La premiere image est utilisee sur la carte principale du site.</span></div>
                <label class="btn-outline upload-btn">
                  <UploadCloud :size="16" /> {{ uploading ? 'Upload...' : 'Uploader' }}
                  <input type="file" accept="image/*" multiple :disabled="uploading" @change="uploadImages" />
                </label>
              </div>
              <div class="url-row">
                <input v-model="form.photo_url" type="url" placeholder="Coller une URL image..." />
                <button class="btn-outline" type="button" @click="addPhotoUrl">Ajouter URL</button>
              </div>
              <div v-if="form.photos.length" class="photo-strip">
                <div v-for="(photo, index) in form.photos" :key="photo.url || index" class="photo-item">
                  <img :src="photo.url || photo.preview" alt="" />
                  <button type="button" @click="removePhoto(index)"><X :size="13" /></button>
                </div>
              </div>
              <p v-else class="photo-empty">Aucune image ajoutee.</p>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-outline" type="button" @click="closeModal">Annuler</button>
            <button class="btn-primary" type="submit" :disabled="saving || uploading">
              <LoaderCircle v-if="saving" :size="16" class="spin" />
              <Save v-else :size="16" />
              {{ saving ? 'Enregistrement...' : 'Enregistrer' }}
            </button>
          </div>
        </form>
      </div>
    </Transition>

    <Transition name="modal">
      <div v-if="deleteTarget" class="overlay" @click.self="deleteTarget = null">
        <div class="confirm">
          <div class="confirm-icon"><AlertCircle :size="22" /></div>
          <h3>Supprimer cette actualite ?</h3>
          <p>{{ deleteTarget.titre }}</p>
          <div class="modal-footer">
            <button class="btn-outline" type="button" @click="deleteTarget = null">Annuler</button>
            <button class="btn-danger" type="button" :disabled="deleting" @click="confirmDelete">{{ deleting ? 'Suppression...' : 'Supprimer' }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.page { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 18px; max-width: 1600px; width: 100%; margin: 0 auto; }
.hero, .card, .preview-band, .kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.hero { padding: 20px; display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.breadcrumb { display: flex; gap: 6px; font-size: 12px; color: #9ca3af; margin-bottom: 5px; }
.bc-active { color: #111; font-weight: 650; }
.page-title { font-size: 24px; font-weight: 760; color: #111; margin: 0 0 3px; letter-spacing: 0; }
.page-sub { margin: 0; font-size: 13px; color: #9ca3af; }
.hero-actions, .inline-actions, .row-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn-primary, .btn-outline, .btn-danger { display: inline-flex; align-items: center; justify-content: center; gap: 7px; border-radius: 10px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 650; cursor: pointer; text-decoration: none; }
.btn-primary { background: #6366f1; color: #fff; border: 0; box-shadow: 0 2px 8px rgba(99,102,241,.25); }
.btn-outline { background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); }
.btn-danger { background: #dc2626; color: #fff; border: 0; }
button:disabled { opacity: .55; cursor: not-allowed; }
.banner { padding: 10px 14px; border-radius: 10px; font-size: 13px; }
.success-banner { color: #059669; background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.18); }
.error-banner { color: #dc2626; background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.16); }
.kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.kpi-card { padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; }
.kpi-blue { background: rgba(99,102,241,.1); color: #6366f1; }
.kpi-green { background: rgba(16,185,129,.1); color: #059669; }
.kpi-amber { background: rgba(245,158,11,.11); color: #b45309; }
.kpi-red { background: rgba(239,68,68,.1); color: #dc2626; }
.kpi-val { display: block; font-size: 22px; font-weight: 730; color: #111; line-height: 1; }
.kpi-label { display: block; font-size: 11.5px; color: #9ca3af; margin-top: 4px; }
.preview-band { padding: 18px; }
.section-head { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; margin-bottom: 14px; }
.section-head h2 { font-size: 16px; color: #111; margin: 0 0 3px; }
.section-head p { margin: 0; color: #9ca3af; font-size: 12.5px; }
.mini-pill { border: 1px solid rgba(0,0,0,.08); border-radius: 999px; padding: 5px 10px; color: #4b5563; font-size: 12px; }
.public-preview { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(280px, .8fr); gap: 12px; }
.featured-preview { min-height: 300px; display: grid; grid-template-columns: 46% 1fr; background: #f8fafc; border: 1px solid rgba(0,0,0,.06); border-radius: 14px; overflow: hidden; }
.featured-preview img, .image-fallback { width: 100%; height: 100%; object-fit: cover; min-height: 300px; }
.image-fallback, .mini-fallback { display: flex; align-items: center; justify-content: center; background: #e5e7eb; color: #6b7280; }
.featured-copy { padding: 22px; display: flex; flex-direction: column; justify-content: center; gap: 10px; }
.featured-copy h2 { margin: 0; color: #111; font-size: 24px; line-height: 1.15; }
.featured-copy p { margin: 0; color: #4b5563; line-height: 1.55; font-size: 14px; }
.side-preview { display: grid; gap: 10px; }
.mini-news { display: grid; grid-template-columns: 88px 1fr; gap: 10px; align-items: center; background: #fff; border: 1px solid rgba(0,0,0,.06); border-radius: 12px; padding: 8px; }
.mini-news img, .mini-fallback { width: 88px; height: 64px; object-fit: cover; border-radius: 8px; }
.mini-news strong { display: block; color: #111; font-size: 13px; line-height: 1.25; }
.mini-news span { color: #9ca3af; font-size: 11.5px; }
.empty-preview, .empty, .state-block { display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; padding: 54px 20px; color: #9ca3af; }
.toolbar { padding: 14px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid rgba(0,0,0,.06); }
.search-wrap { position: relative; width: 380px; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
.search-input, .select, .field input, .field select, .field textarea, .url-row input { height: 38px; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 0 12px; background: #fff; font: inherit; font-size: 13px; color: #111; outline: none; }
.search-input { width: 100%; padding-left: 36px; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; min-width: 980px; }
.table th { text-align: left; font-size: 11px; color: #9ca3af; font-weight: 700; padding: 12px 16px; background: #fafafa; border-bottom: 1px solid rgba(0,0,0,.06); text-transform: uppercase; }
.table td { padding: 14px 16px; border-bottom: 1px solid rgba(0,0,0,.05); vertical-align: middle; }
.news-ident { display: flex; align-items: center; gap: 12px; min-width: 340px; }
.thumb { width: 54px; height: 42px; border-radius: 10px; background: #f3f4f6; color: #6b7280; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
.thumb img { width: 100%; height: 100%; object-fit: cover; }
.news-ident strong { display: block; color: #111; font-size: 13.5px; }
.news-ident span, .td-cell { color: #6b7280; font-size: 12.5px; }
.badge, .status-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 9px; font-size: 11px; font-weight: 700; white-space: nowrap; }
.b-soft { background: rgba(99,102,241,.09); color: #4f46e5; }
.status-pill.publie { background: rgba(16,185,129,.1); color: #047857; }
.status-pill.brouillon { background: rgba(245,158,11,.12); color: #92400e; }
.status-pill.archive { background: rgba(107,114,128,.12); color: #4b5563; }
.icon-btn { width: 34px; height: 34px; border-radius: 9px; border: 1px solid rgba(0,0,0,.08); background: #fff; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
.icon-btn.danger { color: #dc2626; }
.meta-line { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; color: #6b7280; font-size: 12px; }
.meta-line span { display: inline-flex; align-items: center; gap: 5px; }
.overlay { position: fixed; inset: 0; z-index: 80; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; padding: 18px; }
.modal { width: min(980px, 96vw); max-height: 92vh; background: #fff; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 24px 70px rgba(0,0,0,.24); }
.modal-header { padding: 18px 22px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; }
.modal-header h3 { margin: 0 0 3px; font-size: 17px; color: #111; }
.modal-header p { margin: 0; color: #9ca3af; font-size: 12.5px; }
.modal-close { width: 34px; height: 34px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; cursor: pointer; }
.modal-body { padding: 20px 22px; overflow: auto; display: flex; flex-direction: column; gap: 18px; }
.modal-footer { padding: 16px 22px; border-top: 1px solid rgba(0,0,0,.06); display: flex; justify-content: flex-end; gap: 10px; background: #fafafa; }
.form-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.field { display: flex; flex-direction: column; gap: 6px; color: #374151; font-size: 12.5px; font-weight: 650; }
.field.wide { grid-column: 1 / -1; }
.field textarea { height: auto; padding: 10px 12px; resize: vertical; line-height: 1.45; }
.photo-panel { border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 14px; background: #fbfbfd; }
.photo-panel-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 12px; }
.photo-panel-head strong { display: block; color: #111; }
.photo-panel-head span { display: block; color: #9ca3af; font-size: 12px; margin-top: 2px; }
.upload-btn { position: relative; overflow: hidden; }
.upload-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.url-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; margin-bottom: 12px; }
.photo-strip { display: flex; flex-wrap: wrap; gap: 10px; }
.photo-item { width: 112px; height: 82px; position: relative; border-radius: 10px; overflow: hidden; border: 1px solid rgba(0,0,0,.08); background: #fff; }
.photo-item img { width: 100%; height: 100%; object-fit: cover; }
.photo-item button { position: absolute; top: 5px; right: 5px; width: 24px; height: 24px; border-radius: 999px; border: 0; background: rgba(17,24,39,.82); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.photo-empty { margin: 0; color: #9ca3af; font-size: 12.5px; }
.confirm { width: min(420px, 94vw); background: #fff; border-radius: 16px; padding: 22px; text-align: center; box-shadow: 0 24px 70px rgba(0,0,0,.24); }
.confirm-icon { width: 46px; height: 46px; margin: 0 auto 12px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #dc2626; background: rgba(239,68,68,.1); }
.confirm h3 { margin: 0 0 6px; color: #111; }
.confirm p { color: #6b7280; margin: 0 0 16px; }
.spin { animation: spin .8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.modal-enter-active, .modal-leave-active { transition: opacity .18s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
@media (max-width: 980px) {
  .kpi-row, .public-preview, .featured-preview, .form-grid { grid-template-columns: 1fr; }
  .toolbar, .hero { flex-direction: column; align-items: stretch; }
  .search-wrap { width: 100%; }
  .featured-preview img, .image-fallback { min-height: 220px; }
}
@media (max-width: 640px) {
  .kpi-row { grid-template-columns: 1fr; }
  .url-row { grid-template-columns: 1fr; }
}
</style>
