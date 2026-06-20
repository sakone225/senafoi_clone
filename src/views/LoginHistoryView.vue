<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

const history = computed(() => auth.loginHistory)

const totalConnexions  = computed(() => history.value.length)
const uniqueUsers      = computed(() => new Set(history.value.map(e => e.matricule)).size)
const derniere         = computed(() => history.value[0] || null)

function formatDate(iso) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(iso))
}

function formatDateShort(iso) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('fr-FR', {
    day: '2-digit', month: 'short',
    hour: '2-digit', minute: '2-digit',
  }).format(new Date(iso))
}

function timeSince(iso) {
  if (!iso) return ''
  const diff = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (diff < 60)   return 'à l\'instant'
  if (diff < 3600) return `il y a ${Math.floor(diff / 60)} min`
  if (diff < 86400) return `il y a ${Math.floor(diff / 3600)} h`
  return `il y a ${Math.floor(diff / 86400)} j`
}

const PALETTE = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#3b82f6','#f97316','#14b8a6']
function avatarColor(matricule = '') {
  let hash = 0
  for (const c of matricule) hash = c.charCodeAt(0) + ((hash << 5) - hash)
  return PALETTE[Math.abs(hash) % PALETTE.length]
}
</script>

<template>
  <div class="page">

    <!-- KPIs -->
    <div class="kpi-row">
      <div class="kpi-card">
        <div class="kpi-icon" style="background:rgba(99,102,241,.1);color:#6366f1">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
          </svg>
        </div>
        <div class="kpi-data">
          <span class="kpi-val">{{ totalConnexions }}</span>
          <span class="kpi-label">Connexions totales</span>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-icon" style="background:rgba(16,185,129,.1);color:#10b981">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
        <div class="kpi-data">
          <span class="kpi-val">{{ uniqueUsers }}</span>
          <span class="kpi-label">Utilisateurs distincts</span>
        </div>
      </div>

      <div class="kpi-card" v-if="derniere">
        <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:#f59e0b">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
          </svg>
        </div>
        <div class="kpi-data">
          <span class="kpi-val" style="font-size:14px">{{ derniere.name }}</span>
          <span class="kpi-label">Dernière connexion · {{ timeSince(derniere.loginAt) }}</span>
        </div>
      </div>
    </div>

    <!-- Tableau -->
    <div class="card">
      <div class="card-head">
        <div>
          <h2 class="card-title">Historique des connexions</h2>
          <p class="card-sub">Les {{ history.length }} dernières connexions enregistrées</p>
        </div>
        <button
          v-if="history.length"
          class="btn-danger"
          @click="auth.clearHistory()"
        >
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
            <path d="M10 11v6"/><path d="M14 11v6"/>
          </svg>
          Vider l'historique
        </button>
      </div>

      <!-- Vide -->
      <div v-if="!history.length" class="empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
        <p>Aucune connexion enregistrée pour le moment.</p>
      </div>

      <!-- Table -->
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Utilisateur</th>
              <th>Matricule</th>
              <th>Rôle</th>
              <th>Date & heure</th>
              <th>Il y a</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(entry, i) in history" :key="entry.id">
              <td class="td-num">{{ i + 1 }}</td>
              <td class="td-user">
                <div
                  class="avatar"
                  :style="{ background: avatarColor(entry.matricule) }"
                >{{ entry.avatar }}</div>
                <span class="user-name">{{ entry.name }}</span>
              </td>
              <td><code class="badge-code">{{ entry.matricule }}</code></td>
              <td>
                <span class="badge-role">{{ entry.role }}</span>
              </td>
              <td class="td-date">{{ formatDate(entry.loginAt) }}</td>
              <td class="td-since">{{ timeSince(entry.loginAt) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 20px;
  max-width: 1100px;
  margin: 0 auto;
}

/* KPIs */
.kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.kpi-card {
  background: #fff;
  border: 1px solid rgba(0,0,0,.07);
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.kpi-icon {
  width: 42px; height: 42px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.kpi-data { display: flex; flex-direction: column; }
.kpi-val  { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.kpi-label { font-size: 11.5px; color: #9ca3af; margin-top: 3px; }

/* Card */
.card {
  background: #fff;
  border: 1px solid rgba(0,0,0,.07);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.card-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 20px 24px 16px;
  border-bottom: 1px solid rgba(0,0,0,.06);
  gap: 12px;
}
.card-title { font-size: 15px; font-weight: 650; color: #111; letter-spacing: -.025em; margin: 0 0 3px; }
.card-sub   { font-size: 12px; color: #9ca3af; margin: 0; }

.btn-danger {
  display: flex; align-items: center; gap: 6px;
  padding: 7px 12px;
  background: rgba(220,38,38,.07);
  border: 1px solid rgba(220,38,38,.15);
  border-radius: 8px;
  color: #dc2626;
  font-size: 12px; font-weight: 560; font-family: inherit;
  cursor: pointer; white-space: nowrap;
  transition: background .15s;
  flex-shrink: 0;
}
.btn-danger:hover { background: rgba(220,38,38,.13); }

/* Vide */
.empty {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 12px; padding: 60px 20px;
  color: #9ca3af;
}
.empty p { font-size: 14px; margin: 0; }

/* Table */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead tr { background: #fafafa; }
th {
  padding: 10px 16px;
  font-size: 11px; font-weight: 650;
  color: #9ca3af; text-transform: uppercase; letter-spacing: .06em;
  text-align: left; white-space: nowrap;
  border-bottom: 1px solid rgba(0,0,0,.06);
}
td {
  padding: 12px 16px;
  font-size: 13px; color: #374151;
  border-bottom: 1px solid rgba(0,0,0,.05);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: #fafafa; }

.td-num { color: #d1d5db; font-size: 12px; width: 36px; }

.td-user { display: flex; align-items: center; gap: 10px; }
.avatar {
  width: 30px; height: 30px; border-radius: 50%;
  color: white; font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.user-name { font-weight: 540; color: #111; }

.badge-code {
  font-size: 11.5px; font-family: 'SF Mono', monospace;
  background: rgba(99,102,241,.08);
  color: #6366f1; padding: 2px 7px; border-radius: 5px;
}
.badge-role {
  font-size: 11px; font-weight: 560;
  background: rgba(0,0,0,.06);
  color: #374151; padding: 2px 8px; border-radius: 20px;
}
.td-date { color: #6b7280; white-space: nowrap; }
.td-since { color: #9ca3af; font-size: 12px; white-space: nowrap; }

/* Responsive */
@media (max-width: 768px) {
  .kpi-row { grid-template-columns: 1fr; }
  .card-head { flex-direction: column; }
}
</style>