<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'

const loading = ref(true)
const error   = ref(null)

const anneeActive  = ref(null)
const stats        = ref({})
const presences    = ref({ resume: {}, historique: [] })
const totalInscrits = ref(0)

const API_URL = 'https://api.aeemci-ce.ci/senafoi/seminaristes_stats.php'

async function fetchStats() {
  loading.value = true
  error.value   = null
  try {
    const res  = await fetch(`${API_URL}?date=${new Date().toISOString().slice(0,10)}`)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    if (!data.success) throw new Error(data.error || 'Erreur API')
    anneeActive.value  = data.annee_active
    stats.value        = data.stats || {}
    presences.value    = data.presences || { resume: {}, historique: [] }
    totalInscrits.value = data.total || 0
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(fetchStats)

// ── Computed stats ─────────────────────────────────────────────────────────
const statsSexe    = computed(() => stats.value.par_sexe         || [])
const statsNiveau  = computed(() => stats.value.par_niveau        || [])
const statsDortoir = computed(() => stats.value.par_dortoir       || [])
const statsVille   = computed(() => stats.value.par_ville         || [])
const statsTshirt  = computed(() => stats.value.par_taille_tshirt || [])
const statsSomme   = computed(() => stats.value.par_somme_payee   || [])
const totalSomme   = computed(() => stats.value.total_somme_payee || { total_global: 0, devise_paiement: 'XOF' })
const statsAnciens = computed(() => stats.value.anciens_participants || {})
const resumeJour   = computed(() => presences.value.resume || {})
const historique   = computed(() => presences.value.historique || [])

const masculin = computed(() => statsSexe.value.find(s => s.sexe === 'M')?.total || 0)
const feminin  = computed(() => statsSexe.value.find(s => s.sexe === 'F')?.total || 0)

function formatMontant(n, devise) {
  return new Intl.NumberFormat('fr-FR').format(n || 0) + ' ' + (devise || 'XOF')
}

const PALETTE = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#3b82f6','#f97316','#14b8a6','#ec4899','#06b6d4','#84cc16','#a855f7']
function color(i) { return PALETTE[i % PALETTE.length] }
</script>

<template>
  <div class="dashboard">

    <!-- Loading -->
    <div v-if="loading" class="state-block">
      <div class="spinner"></div>
      <p>Chargement des statistiques…</p>
    </div>

    <div v-else-if="error" class="state-block state-error">
      <p>Impossible de charger les données</p>
      <code>{{ error }}</code>
    </div>

    <template v-else>

      <!-- Greeting -->
      <div class="greeting">
        <div>
          <h2>Tableau de bord 👋</h2>
          <p>Les departements de l' AEEMCI — Vue d'ensemble des statistiques</p>
        </div>
       
      </div>

      <!-- ── KPI Row ── -->
      <div class="kpi-row">
        <div class="kpi-card">
          <div class="kpi-icon" style="background:rgba(99,102,241,.1);color:#6366f1">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ totalInscrits }}</span>
            <span class="kpi-label">Inscrits payés</span>
          </div>
          <div class="kpi-badges">
            <span class="kpi-badge" style="background:rgba(59,130,246,.1);color:#3b82f6">♂ {{ masculin }}</span>
            <span class="kpi-badge" style="background:rgba(236,72,153,.1);color:#ec4899">♀ {{ feminin }}</span>
          </div>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background:rgba(16,185,129,.1);color:#10b981">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <polyline points="20 6 9 17 4 12"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val" style="color:#10b981">{{ resumeJour.total_presents || 0 }}</span>
            <span class="kpi-label">Présents aujourd'hui</span>
          </div>
          <span class="kpi-badge" style="background:rgba(16,185,129,.1);color:#10b981">
            {{ resumeJour.taux_presence || 0 }}% présence
          </span>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:#f59e0b">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <line x1="12" y1="1" x2="12" y2="23"/>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val">{{ formatMontant(totalSomme.total_global, totalSomme.devise_paiement) }}</span>
            <span class="kpi-label">Total collecté</span>
          </div>
          <span class="kpi-badge" style="background:rgba(245,158,11,.1);color:#f59e0b">Paiements Wave</span>
        </div>

        <div class="kpi-card">
          <div class="kpi-icon" style="background:rgba(139,92,246,.1);color:#8b5cf6">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
              <polyline points="17 6 23 6 23 12"/>
            </svg>
          </div>
          <div class="kpi-data">
            <span class="kpi-val" style="color:#8b5cf6">{{ statsAnciens.taux_retour_anciens || 0 }}%</span>
            <span class="kpi-label">Taux retour anciens</span>
          </div>
          <span class="kpi-badge" style="background:rgba(139,92,246,.1);color:#8b5cf6">
            {{ statsAnciens.anciens_revenus_cette_annee || 0 }} / {{ statsAnciens.total_anciens_l_an_passe || 0 }}
          </span>
        </div>
      </div>


      <!-- ── Stats grid principale ── -->
      <div class="stats-main-grid">

        <!-- Par somme payée -->
        <div class="section-card" v-if="statsSomme.length">
          <div class="section-head"><span class="section-title">Répartition des paiements</span></div>
          <div class="somme-list">
            <div v-for="s in statsSomme" :key="s.somme_paye" class="somme-row">
              <div class="somme-info">
                <span class="somme-montant">{{ formatMontant(s.somme_paye, s.devise_paiement) }}</span>
                <div class="somme-sexe">
                  <span class="s-tag" style="color:#3b82f6">♂ {{ s.total_masculin }}</span>
                  <span class="s-tag" style="color:#ec4899">♀ {{ s.total_feminin }}</span>
                </div>
              </div>
              <div class="somme-bar-wrap">
                <div class="somme-bar" :style="{ width: Math.round(s.total / totalInscrits * 100) + '%', background: '#6366f1' }"></div>
              </div>
              <div class="somme-right">
                <span class="somme-total">{{ s.total }} inscrits</span>
                <span class="somme-sub">{{ formatMontant(s.total_montant, s.devise_paiement) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Anciens participants -->
        <div class="section-card" v-if="statsAnciens.total_anciens_l_an_passe">
          <div class="section-head"><span class="section-title">Fidélisation anciens</span></div>
          <div class="anciens-grid">
            <div class="anc-item">
              <span class="anc-val">{{ statsAnciens.total_anciens_l_an_passe }}</span>
              <span class="anc-label">Anciens séminaristes ( 2025)</span>
            </div>
            <div class="anc-item">
              <span class="anc-val" style="color:#10b981">{{ statsAnciens.anciens_revenus_cette_annee }}</span>
              <span class="anc-label">Revenus cette année</span>
            </div>
            <div class="anc-item">
              <span class="anc-val" style="color:#ef4444">{{ statsAnciens.anciens_non_revenus }}</span>
              <span class="anc-label">Non revenus</span>
            </div>
            <div class="anc-item">
              <span class="anc-val" style="color:#8b5cf6">{{ statsAnciens.taux_anciens_vs_total }}%</span>
              <span class="anc-label">Anciens / total inscrits</span>
            </div>
          </div>
          <!-- Barre de retour -->
          <div style="margin-top:16px">
            <div style="display:flex;justify-content:space-between;font-size:11px;color:#9ca3af;margin-bottom:5px">
              <span>Taux de retour des anciens</span>
              <span>{{ statsAnciens.taux_retour_anciens }}%</span>
            </div>
            <div class="pr-progress-bar">
              <div class="pr-progress-fill" :style="{ width: statsAnciens.taux_retour_anciens + '%', background: 'linear-gradient(90deg,#8b5cf6,#a78bfa)' }"></div>
            </div>
          </div>
        </div>

      </div>

      <!-- ── Stats grid secondaire (ville / dortoir / niveau / tshirt) ── -->
      <div class="stats-grid-4">

        <div class="section-card" v-if="statsVille.length">
          <div class="section-head"><span class="section-title">Par secrétariat régional</span></div>
          <div class="mini-list">
            <div v-for="(v, i) in statsVille.slice(0,8)" :key="v.ville" class="mini-row">
              <span class="mini-dot" :style="{ background: color(i) }"></span>
              <span class="mini-label">{{ v.ville }}</span>
              <div class="mini-bar-wrap">
                <div class="mini-bar" :style="{ width: Math.round(v.total / totalInscrits * 100) + '%', background: color(i) + '80' }"></div>
              </div>
              <span class="mini-val">{{ v.total }}</span>
            </div>
          </div>
        </div>

        <div class="section-card" v-if="statsDortoir.length">
          <div class="section-head"><span class="section-title">Par dortoir</span></div>
          <div class="mini-list">
            <div v-for="(d, i) in statsDortoir.slice(0,8)" :key="d.dortoir" class="mini-row">
              <span class="mini-dot" :style="{ background: color(i+2) }"></span>
              <span class="mini-label">{{ d.dortoir }}</span>
              <div class="mini-bar-wrap">
                <div class="mini-bar" :style="{ width: Math.round(d.total / totalInscrits * 100) + '%', background: color(i+2) + '80' }"></div>
              </div>
              <span class="mini-val">{{ d.total }}</span>
            </div>
          </div>
        </div>

        <div class="section-card" v-if="statsNiveau.length">
          <div class="section-head"><span class="section-title">Par niveau séminaire</span></div>
          <div class="mini-list">
            <div v-for="(n, i) in statsNiveau" :key="n.niveau_seminaire" class="mini-row">
              <span class="mini-dot" :style="{ background: color(i+4) }"></span>
              <span class="mini-label">{{ n.niveau_seminaire }}</span>
              <div class="mini-bar-wrap">
                <div class="mini-bar" :style="{ width: Math.round(n.total / totalInscrits * 100) + '%', background: color(i+4) + '80' }"></div>
              </div>
              <span class="mini-val">{{ n.total }}</span>
            </div>
          </div>
        </div>

        <div class="section-card" v-if="statsTshirt.length">
          <div class="section-head"><span class="section-title">Par taille t-shirt</span></div>
          <div class="mini-list">
            <div v-for="(t, i) in statsTshirt" :key="t.taille" class="mini-row">
              <span class="mini-dot" :style="{ background: color(i+6) }"></span>
              <span class="mini-label">{{ t.taille }}</span>
              <div class="mini-bar-wrap">
                <div class="mini-bar" :style="{ width: Math.round(t.total / totalInscrits * 100) + '%', background: color(i+6) + '80' }"></div>
              </div>
              <span class="mini-val">{{ t.total }}</span>
            </div>
          </div>
        </div>

      </div>

    </template>
  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.dashboard {
  display: flex; flex-direction: column; gap: 20px;
  font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  padding: 20px; max-width: 1600px; margin: 0 auto;
}

/* State */
.state-block { display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;padding:80px 20px;color:#6b7280;font-size:14px; }
.state-error { color:#ef4444; }
.state-error code { font-size:11px;background:rgba(239,68,68,.07);padding:4px 10px;border-radius:6px;color:#dc2626; }
.spinner { width:36px;height:36px;border:3px solid rgba(99,102,241,.15);border-top-color:#6366f1;border-radius:50%;animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* Greeting */
.greeting { display:flex;align-items:center;justify-content:space-between;padding-top:4px; }
.greeting h2 { font-size:22px;font-weight:720;color:#111;letter-spacing:-.03em;margin:0 0 3px; }
.greeting p  { font-size:13px;color:#9ca3af;margin:0; }
.btn-primary { display:flex;align-items:center;gap:8px;padding:9px 18px;background:#6366f1;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:570;font-family:inherit;cursor:pointer;text-decoration:none;box-shadow:0 2px 8px rgba(99,102,241,.3);transition:background .18s; }
.btn-primary:hover { background:#4f46e5; }

/* KPI */
.kpi-row { display:grid;grid-template-columns:repeat(4,1fr);gap:12px; }
.kpi-card { background:#fff;border:1px solid rgba(0,0,0,.07);border-radius:14px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);flex-wrap:wrap; }
.kpi-icon { width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.kpi-data { display:flex;flex-direction:column;flex:1; }
.kpi-val  { font-size:20px;font-weight:730;color:#111;letter-spacing:-.04em;line-height:1; }
.kpi-label { font-size:11.5px;color:#9ca3af;font-weight:440;margin-top:3px; }
.kpi-badges { display:flex;gap:4px;flex-wrap:wrap; }
.kpi-badge { font-size:10.5px;font-weight:570;padding:2px 7px;border-radius:20px;white-space:nowrap; }

/* Section card */
.section-card { background:#fff;border:1px solid rgba(0,0,0,.07);border-radius:14px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,.04); }
.section-head { display:flex;align-items:center;justify-content:space-between;margin-bottom:14px; }
.section-title { font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em; }
.link-more { font-size:12px;color:#6366f1;text-decoration:none;font-weight:540; }
.link-more:hover { text-decoration:underline; }

/* Historique */
.histo-wrap { display:flex;flex-direction:column;gap:10px; }
.histo-bars { display:flex;align-items:flex-end;gap:8px;height:140px; }
.histo-col { display:flex;flex-direction:column;align-items:center;gap:4px;flex:1; }
.histo-nums { display:flex;gap:4px;font-size:10px; }
.h-num { font-weight:600; }
.h-p { color:#10b981; }
.h-a { color:#ef4444; }
.histo-bar-wrap { display:flex;align-items:flex-end;gap:2px;width:100%;justify-content:center; }
.histo-bar { width:40%;border-radius:3px 3px 0 0;min-height:3px;transition:height .3s; }
.hb-p { background:rgba(16,185,129,.65); }
.hb-a { background:rgba(239,68,68,.45); }
.histo-date { font-size:10px;color:#9ca3af; }
.histo-legend { display:flex;gap:14px; }
.hleg { display:flex;align-items:center;gap:5px;font-size:11px;color:#6b7280; }
.hleg::before { content:'';display:inline-block;width:10px;height:10px;border-radius:2px; }
.hleg-p::before { background:rgba(16,185,129,.65); }
.hleg-a::before { background:rgba(239,68,68,.45); }

/* Stats main grid */
.stats-main-grid { display:grid;grid-template-columns:1fr 1fr;gap:12px; }

/* Somme */
.somme-list { display:flex;flex-direction:column;gap:10px; }
.somme-row { display:flex;align-items:center;gap:10px; }
.somme-info { min-width:140px; }
.somme-montant { display:block;font-size:13px;font-weight:620;color:#111; }
.somme-sexe { display:flex;gap:6px;margin-top:2px; }
.s-tag { font-size:10.5px;font-weight:570; }
.somme-bar-wrap { flex:1;height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden; }
.somme-bar { height:100%;border-radius:3px;transition:width .4s; }
.somme-right { text-align:right;min-width:100px; }
.somme-total { display:block;font-size:12px;font-weight:640;color:#111; }
.somme-sub { font-size:11px;color:#9ca3af; }

/* Anciens */
.anciens-grid { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
.anc-item { display:flex;flex-direction:column;gap:3px; }
.anc-val  { font-size:22px;font-weight:720;color:#111;letter-spacing:-.04em;line-height:1; }
.anc-label { font-size:11px;color:#9ca3af;font-weight:450; }
.pr-progress-bar { height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden; }
.pr-progress-fill { height:100%;border-radius:3px;transition:width .4s ease; }

/* Stats grid 4 */
.stats-grid-4 { display:grid;grid-template-columns:repeat(4,1fr);gap:12px; }

/* Mini list */
.mini-list { display:flex;flex-direction:column;gap:8px; }
.mini-row { display:flex;align-items:center;gap:7px; }
.mini-dot { width:7px;height:7px;border-radius:50%;flex-shrink:0; }
.mini-label { font-size:12px;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;width:80px;flex-shrink:0; }
.mini-bar-wrap { flex:1;height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden; }
.mini-bar { height:100%;border-radius:3px;transition:width .4s; }
.mini-val { font-size:12px;font-weight:700;color:#111;width:24px;text-align:right;flex-shrink:0; }

/* Responsive */
@media (max-width:1200px) {
  .stats-grid-4 { grid-template-columns:repeat(2,1fr); }
  .stats-main-grid { grid-template-columns:1fr; }
}
@media (max-width:900px) {
  .kpi-row { grid-template-columns:1fr 1fr; }
  .stats-grid-4 { grid-template-columns:1fr 1fr; }
}
@media (max-width:600px) {
  .kpi-row { grid-template-columns:1fr 1fr; }
  .stats-grid-4 { grid-template-columns:1fr; }
  .greeting { flex-direction:column;gap:12px;align-items:flex-start; }
}
</style>