<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'

const API_URL = 'https://api.aeemci-ce.ci/membres.php'
const pendingActions = ['non_payes', 'membres_non_payes', 'paiements_a_valider']

const search = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const success = ref('')
const activeAction = ref('non_payes')
const candidats = ref([])
const selected = ref(null)
const isModalOpen = ref(false)

const page = ref(1)
const perPage = ref(25)
const total = ref(0)
const totalPages = ref(1)

const paymentForm = reactive({
  ref_paiement: '',
  transaction_id: '',
  numero_wave: '',
  somme_paye: 1000,
  devise_paiement: 'XOF',
  note: '',
})

async function fetchJson(url, options = {}) {
  const res = await fetch(url, options)
  const data = await res.json().catch(() => ({}))
  if (!res.ok || !data.success) throw new Error(data.message || data.error || `HTTP ${res.status}`)
  return data
}

async function fetchPending(p = page.value) {
  loading.value = true
  error.value = ''
  success.value = ''

  for (const action of pendingActions) {
    try {
      const params = new URLSearchParams({
        action,
        page: String(p),
        per_page: String(perPage.value),
        rand: String(Date.now()),
      })
      if (search.value.trim()) params.set('search', search.value.trim())
      const data = await fetchJson(`${API_URL}?${params}`)
      activeAction.value = action
      candidats.value = (data.data || data.membres || []).map(normalizeMembre)
      const pg = data.pagination || {}
      page.value = Number(pg.current_page || pg.page || p)
      total.value = Number(pg.total || candidats.value.length)
      totalPages.value = Number(pg.last_page || pg.total_pages || 1)
      loading.value = false
      return
    } catch (e) {
      error.value = e.message
    }
  }

  candidats.value = []
  error.value = "L'API des membres non payes n'est pas encore disponible."
  loading.value = false
}

let debounceTimer = null
watch(search, () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    page.value = 1
    fetchPending(1)
  }, 300)
})

function normalizeMembre(m) {
  const nomComplet = `${m.prenom || ''} ${m.nom || ''}`.trim()
  return {
    ...m,
    nomComplet: nomComplet || 'Sans nom',
    avatar: initiales(nomComplet || '?'),
    regionLabel: m.secretariat_poste || m.region || m.sr_debut || 'Non defini',
    sousComiteLabel: m.sous_comite || 'Non defini',
  }
}

function openValidation(membre) {
  selected.value = membre
  paymentForm.ref_paiement = `MAN-${Date.now()}`
  paymentForm.transaction_id = `MANUAL-${membre.id}-${Date.now()}`
  paymentForm.numero_wave = membre.numero_wave || membre.contact || ''
  paymentForm.somme_paye = Number(membre.somme_paye || 1000)
  paymentForm.devise_paiement = membre.devise_paiement || 'XOF'
  paymentForm.note = ''
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
}

async function validatePayment() {
  if (!selected.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await fetchJson(`${API_URL}?action=valider_paiement`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: selected.value.id,
        membre_id: selected.value.id,
        ref_paiement: paymentForm.ref_paiement,
        transaction_id: paymentForm.transaction_id,
        numero_wave: paymentForm.numero_wave,
        somme_paye: Number(paymentForm.somme_paye || 0),
        devise_paiement: paymentForm.devise_paiement || 'XOF',
        note: paymentForm.note,
        statut_paiement: 'PAYE',
        payment_status_wave: 'succeeded',
      }),
    })
    success.value = `Paiement valide pour ${selected.value.nomComplet}.`
    closeModal()
    await fetchPending(page.value)
  } catch (e) {
    error.value = e.message || 'Validation impossible'
  } finally {
    saving.value = false
  }
}

function goToPage(p) {
  if (p < 1 || p > totalPages.value || p === page.value) return
  fetchPending(p)
}

function onPerPageChange() {
  page.value = 1
  fetchPending(1)
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

function formatMontant(value, devise = 'XOF') {
  return new Intl.NumberFormat('fr-FR').format(Number(value || 0)) + ' ' + devise
}

const pendingTotal = computed(() => total.value || candidats.value.length)

onMounted(() => fetchPending(1))
</script>

<template>
  <div class="page" style="margin: -15px">
    <div class="content">
      <div class="breadcrumb">
        <span class="bc-root">SENAFAD</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
        <span class="bc-active">Paiements à valider</span>
      </div>

      <div class="page-header">
        <div>
          <h1 class="page-title">Paiements à valider</h1>
          <p class="page-sub">{{ pendingTotal }} membre(s) sans carte payee · action API {{ activeAction }}</p>
        </div>
        <button class="btn-primary" @click="fetchPending(page)">Actualiser</button>
      </div>

      <div v-if="success" class="search-banner success-banner">{{ success }}</div>
      <div v-if="error" class="search-banner error-banner">{{ error }}</div>

      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-icon kpi-amber">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ pendingTotal }}</span>
            <span class="kpi-label">Paiements en attente</span>
          </div>
        </div>
        <div class="kpi-card">
          <div class="kpi-icon kpi-blue">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M3 3h18v18H3z"/><path d="M7 8h10M7 12h7"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ candidats.length }}</span>
            <span class="kpi-label">Affiches sur la page</span>
          </div>
        </div>
      </div>

      <div class="toolbar">
        <div class="search-wrap">
          <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input v-model="search" class="search-input" type="search" placeholder="Rechercher nom, matricule, contact..." />
          <span v-if="search" class="search-clear" @click="search = ''">×</span>
        </div>
      </div>

      <div class="card">
        <div v-if="loading" class="state-block">
          <div class="spinner"></div>
          <p>Chargement des membres non payes...</p>
        </div>
        <div v-else-if="!candidats.length" class="empty">Aucun membre non paye trouve.</div>
        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Membre</th>
                <th>Secretariat</th>
                <th>Contact</th>
                <th>Montant</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in candidats" :key="m.id" class="table-row">
                <td>
                  <div class="person">
                    <div v-if="m.photo" class="avatar-photo"><img :src="m.photo" :alt="m.nomComplet" /></div>
                    <div v-else class="avatar" :style="{ background: avatarColor(m.avatar) + '20', color: avatarColor(m.avatar) }">{{ m.avatar }}</div>
                    <div class="person-info">
                      <span class="person-name">{{ m.nomComplet }}</span>
                      <span class="person-mat">{{ m.matricule || 'Sans matricule' }}</span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="td-cell">{{ m.regionLabel }}</span>
                  <span class="person-mat">{{ m.sousComiteLabel }}</span>
                </td>
                <td><span class="td-cell">{{ m.contact || '-' }}</span></td>
                <td><span class="td-cell">{{ formatMontant(m.somme_paye || 1000, m.devise_paiement || 'XOF') }}</span></td>
                <td><span class="badge b-pending">{{ m.statut_paiement || 'NON_PAYE' }}</span></td>
                <td>
                  <button class="btn-primary btn-small" @click="openValidation(m)">Valider paiement</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="pagination">
          <span class="pag-info">Page <strong>{{ page }}</strong> sur <strong>{{ totalPages }}</strong></span>
          <div class="pag-pages">
            <button class="pag-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹</button>
            <button class="pag-btn active">{{ page }}</button>
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
    </div>

    <Transition name="modal">
      <div v-if="isModalOpen && selected" class="overlay" @click.self="closeModal">
        <div class="modal">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="modal-avatar" :style="{ background: avatarColor(selected.avatar) + '20', color: avatarColor(selected.avatar) }">{{ selected.avatar }}</div>
              <div>
                <h3 class="modal-title">Valider le paiement</h3>
                <p class="modal-mat">{{ selected.nomComplet }} · {{ selected.matricule || 'Sans matricule' }}</p>
              </div>
            </div>
            <button class="modal-close" @click="closeModal">×</button>
          </div>

          <form class="modal-body form-grid" @submit.prevent="validatePayment">
            <label class="field">Référence paiement<input v-model="paymentForm.ref_paiement" required /></label>
            <label class="field">Transaction ID<input v-model="paymentForm.transaction_id" required /></label>
            <label class="field">Numéro Wave<input v-model="paymentForm.numero_wave" /></label>
            <label class="field">Montant<input v-model.number="paymentForm.somme_paye" type="number" min="0" required /></label>
            <label class="field">Devise<input v-model="paymentForm.devise_paiement" required /></label>
            <label class="field field-full">Note<textarea v-model="paymentForm.note" rows="3"></textarea></label>
          </form>

          <div class="modal-footer">
            <button class="btn-outline" @click="closeModal">Annuler</button>
            <button class="btn-primary" :disabled="saving" @click="validatePayment">
              {{ saving ? 'Validation...' : 'Confirmer le paiement' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.page { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; display: flex; flex-direction: column; }
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 20px; max-width: 1600px; width: 100%; margin: 0 auto; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6b7280; padding-top: 10px; }
.bc-root:hover { color: #6366f1; }
.bc-active { color: #111; font-weight: 560; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 720; color: #111; letter-spacing: -.03em; margin-bottom: 3px; }
.page-sub { font-size: 13px; color: #9ca3af; font-weight: 430; }
.state-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 70px 20px; color: #6b7280; font-size: 14px; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.kpi-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-blue { background: rgba(99,102,241,.1); color: #6366f1; }
.kpi-amber { background: rgba(245,158,11,.1); color: #f59e0b; }
.kpi-data { display: flex; flex-direction: column; flex: 1; }
.kpi-val { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.kpi-label { font-size: 11.5px; color: #9ca3af; font-weight: 440; margin-top: 3px; }
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.search-wrap { position: relative; width: 360px; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border-radius: 50%; cursor: pointer; color: #6b7280; }
.search-input { width: 100%; height: 38px; padding: 0 34px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #111; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.search-banner { display: flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 12.5px; }
.success-banner { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.18); color: #059669; }
.error-banner { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.16); color: #dc2626; }
.btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 18px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 570; font-family: inherit; cursor: pointer; letter-spacing: -.01em; box-shadow: 0 2px 8px rgba(99,102,241,.3); }
.btn-primary:disabled { opacity: .55; cursor: not-allowed; }
.btn-outline { display: inline-flex; align-items: center; gap: 7px; padding: 9px 14px; background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); border-radius: 10px; font-size: 13px; font-weight: 480; font-family: inherit; cursor: pointer; }
.btn-small { padding: 7px 12px; font-size: 12px; box-shadow: none; }
.card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,.07); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 630; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
.table-row { border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.table-row:hover { background: #fafbff; }
.table td { padding: 12px 16px; vertical-align: middle; }
.person { display: flex; align-items: center; gap: 11px; }
.avatar, .modal-avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 730; flex-shrink: 0; letter-spacing: .02em; }
.avatar-photo { width: 36px; height: 36px; border-radius: 10px; overflow: hidden; flex-shrink: 0; border: 1px solid rgba(0,0,0,.07); }
.avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.person-info { display: flex; flex-direction: column; gap: 1px; }
.person-name { font-size: 13.5px; font-weight: 570; color: #111; white-space: nowrap; }
.person-mat { font-size: 11px; color: #9ca3af; font-weight: 450; letter-spacing: .03em; display: block; margin-top: 2px; }
.td-cell { color: #374151; white-space: nowrap; font-size: 13px; }
.badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 610; padding: 3px 9px; border-radius: 20px; letter-spacing: .02em; white-space: nowrap; }
.b-pending { background: rgba(245,158,11,.1); color: #b45309; }
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; }
.pag-info strong { color: #374151; }
.pag-pages { display: flex; align-items: center; gap: 3px; }
.pag-btn { min-width: 30px; height: 30px; padding: 0 6px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; font-size: 12px; font-family: inherit; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
.pag-btn:disabled { opacity: .35; cursor: default; }
.per-page { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #9ca3af; }
.per-page-select { border: 1px solid rgba(0,0,0,.09); border-radius: 7px; padding: 4px 8px; font-size: 12px; font-family: inherit; color: #374151; background: #fff; cursor: pointer; }
.empty { text-align: center; padding: 48px; color: #9ca3af; font-size: 13.5px; }
.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); }
.modal { background: #fff; border-radius: 18px; width: 560px; max-width: calc(100vw - 40px); box-shadow: 0 32px 80px rgba(0,0,0,.18); overflow: hidden; display: flex; flex-direction: column; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; }
.modal-ident { display: flex; align-items: center; gap: 12px; }
.modal-avatar { width: 52px; height: 52px; border-radius: 14px; font-size: 15px; }
.modal-title { font-size: 16px; font-weight: 680; color: #111; letter-spacing: -.02em; margin: 0 0 2px; }
.modal-mat { font-size: 11.5px; color: #9ca3af; margin: 0; letter-spacing: .03em; }
.modal-close { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.modal-body { padding: 22px 24px; max-height: 65vh; overflow-y: auto; }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 16px 24px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.field { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 650; color: #374151; }
.field input, .field textarea { border: 1px solid rgba(0,0,0,.12); border-radius: 8px; padding: 9px 10px; font: inherit; font-size: 13px; outline: none; }
.field-full { grid-column: 1 / -1; }
.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from { opacity: 0; transform: scale(0.96) translateY(10px); }
.modal-leave-to { opacity: 0; transform: scale(0.96) translateY(10px); }
@media (max-width: 700px) {
  .content { padding: 10px 16px; }
  .kpi-row, .form-grid { grid-template-columns: 1fr; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .search-wrap { width: 100%; }
  .pagination { flex-direction: column; align-items: center; }
}
</style>
