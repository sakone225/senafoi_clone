<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { jsPDF } from 'jspdf'
import autoTable from 'jspdf-autotable'
import * as XLSX from 'xlsx'

// ─── État global ───────────────────────────────────────────────────────────
const loading         = ref(true)
const submitting      = ref(false)
const exporting       = ref(false)
const error           = ref(null)

const activeTab       = ref('evaluations')
const evaluations     = ref([])
const stats           = ref({ total_evaluations: 0, total_participants: 0, active_evaluations: 0, average_score: 0 })

const showAddModal     = ref(false)
const showEditModal    = ref(false)
const showResultsModal = ref(false)
const showQRModal      = ref(false)
const selectedEval     = ref(null)
const results          = ref(null)

const qrImageURL = ref(null)
const qrURL      = ref('')

const toast = ref({ show: false, type: 'success', message: '' })
let toastTimer = null

const pagination = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0, from: 0, to: 0 })
const filters = ref({ search: '', statut: '', date_debut: '', date_fin: '' })

const defaultForm = () => ({
  titre: '', description: '', duree: 60,
  acces_type: 'libre', matricules_autorises: '',
  questions: []
})
const form = ref(defaultForm())

// ─── État onglet Niveaux ──────────────────────────────────────────────────
const availableLevels  = ref([])
const selectedLevel    = ref('')
const levelLoaded      = ref(false)
const anneeEnCours     = ref('')

const seminaristes     = ref([])
const levelFilters     = ref({ search: '' })
const dirtyConduite    = ref({})
const savingConduite   = ref({})
const selectedSems     = ref(new Set())

const displayMode      = ref('flat')
const expandedRows     = ref(new Set())

// ─── Coefficients & édition inline des scores ────────────────────────────
const coefficients   = ref({})
const dirtyScores    = ref({})
const savingScores   = ref({})
const calcResults    = ref([])
const showCalcTable  = ref(false)

const API = 'https://api.aeemci-ce.ci/senafoi/evaluations-api.php'

// ─── Évaluations déduites des sessions ────────────────────────────────────
const levelEvaluations = computed(() => {
  const map = new Map()
  for (const s of seminaristes.value) {
    for (const sess of (s.sessions || [])) {
      if (sess.evaluation_id && Number(sess.evaluation_id) !== 40) {
        if (!map.has(sess.evaluation_id)) {
          map.set(sess.evaluation_id, { id: sess.evaluation_id, titre: sess.evaluation_titre })
        }
      }
    }
  }
  return Array.from(map.values()).sort((a, b) => a.id - b.id)
})

// ─── Helpers avatar ────────────────────────────────────────────────────────
const AVATAR_BG = ['#DBEAFE','#DCFCE7','#FEF3C7','#FCE7F3','#EDE9FE','#FEE2E2']
const AVATAR_FG = ['#1D4ED8','#15803D','#B45309','#BE185D','#6D28D9','#B91C1C']
function hashKey(s) {
  const k = `${s?.prenom||''}${s?.nom||''}`
  let h = 0; for (let i = 0; i < k.length; i++) h = k.charCodeAt(i) + ((h << 5) - h)
  return Math.abs(h)
}
const avatarBg    = s => AVATAR_BG[hashKey(s) % AVATAR_BG.length]
const avatarColor = s => AVATAR_FG[hashKey(s) % AVATAR_FG.length]

// ─── API Évaluations (tab 1) ───────────────────────────────────────────────
async function loadAll() {
  loading.value = true; error.value = null
  try { await Promise.all([loadEvaluations(), loadStats()]) }
  catch (e) { error.value = e.message }
  finally { loading.value = false }
}

async function loadEvaluations() {
  let url = `${API}?action=evaluations&page=${pagination.value.current_page}&per_page=${pagination.value.per_page}&rand=${Math.random()}`
  if (filters.value.search)     url += `&search=${encodeURIComponent(filters.value.search)}`
  if (filters.value.statut)     url += `&statut=${filters.value.statut}`
  if (filters.value.date_debut) url += `&date_debut=${filters.value.date_debut}`
  if (filters.value.date_fin)   url += `&date_fin=${filters.value.date_fin}`
  const res = await fetch(url); const data = await res.json()
  if (!data.success) throw new Error(data.error || 'Erreur API')
  evaluations.value = data.data || []
  if (data.pagination) pagination.value = { ...pagination.value, ...data.pagination }
}

async function loadStats() {
  const res = await fetch(`${API}?action=stats&rand=${Math.random()}`)
  const data = await res.json()
  if (data.success) stats.value = data.data || stats.value
}

// ─── API Niveaux ───────────────────────────────────────────────────────────
async function loadAvailableLevels() {
  try {
    const res  = await fetch(`${API}?action=list_levels&rand=${Math.random()}`)
    const data = await res.json()
    availableLevels.value = data.success && Array.isArray(data.data)
      ? data.data.sort()
      : fallbackLevels()
    if (data.annee) anneeEnCours.value = data.annee
  } catch { availableLevels.value = fallbackLevels() }
}
const fallbackLevels = () => ['1AS','2AS','3AS','1AF','2AF','3AF','1BS','2BS','3BS','1BF','2BF','3BF','4','TEST_ENTREE']

async function loadSeminaristsByLevel() {
  if (!selectedLevel.value) return
  loading.value = true
  seminaristes.value  = []
  dirtyConduite.value = {}
  dirtyScores.value   = {}
  selectedSems.value  = new Set()
  expandedRows.value  = new Set()
  showCalcTable.value = false

  try {
    let url = `${API}?action=list_seminairistes_by_level&rand=${Math.random()}`
    url += `&niveau_seminaire=${encodeURIComponent(selectedLevel.value)}`
    if (levelFilters.value.search)
      url += `&search=${encodeURIComponent(levelFilters.value.search)}`

    const res  = await fetch(url)
    const data = await res.json()

    if (!data.success) { showToast('error', data.error || 'Erreur chargement'); return }

    const sems = (data.seminaristes || []).map(s => {
      const hasConduite = (s.sessions || []).some(sess => Number(sess.evaluation_id) === 40)
      if (!hasConduite) { s.conduite_default = 16 }
      return s
    })

    seminaristes.value = sems
    if (data.annee) anneeEnCours.value = data.annee
    levelLoaded.value = true

  } catch (e) {
    showToast('error', e.message)
  } finally {
    loading.value = false
  }
}

function selectLevel(lv) {
  selectedLevel.value = lv
  levelLoaded.value   = false
  seminaristes.value  = []
  levelFilters.value.search = ''
  loadSeminaristsByLevel()
}

let _levelDebounce = null
watch(() => levelFilters.value.search, () => {
  clearTimeout(_levelDebounce)
  _levelDebounce = setTimeout(() => loadSeminaristsByLevel(), 350)
})

// ─── Score d'un séminariste ────────────────────────────────────────────────
function getScore(s, evalId) {
  if (!s.sessions) return null
  const id = Number(evalId)
  return s.sessions.find(sess => Number(sess.evaluation_id) === id) ?? null
}

function getConduite(s) {
  const sess = (s.sessions || []).find(sess => Number(sess.evaluation_id) === 40)
  if (sess) return sess.score_obtenu
  return s.conduite_default ?? 16
}

// ─── Édition inline des scores ────────────────────────────────────────────
function scoreKey(mat, evalId) { return `${mat}_${evalId}` }

function onScoreInput(s, evalId, field, val) {
  const key = scoreKey(s.matricule_seminaire, evalId)
  if (!dirtyScores.value[key]) {
    const existing = getScore(s, evalId)
    dirtyScores.value[key] = {
      score_obtenu: existing ? existing.score_obtenu : 0,
      score_total:  existing ? existing.score_total  : 0,
    }
  }
  dirtyScores.value[key][field] = parseFloat(val) || 0
  dirtyScores.value = { ...dirtyScores.value }
}

function isScoreDirty(mat, evalId) {
  return !!dirtyScores.value[scoreKey(mat, evalId)]
}

async function saveScore(s, evalId) {
  const key  = scoreKey(s.matricule_seminaire, evalId)
  const vals = dirtyScores.value[key]
  if (!vals) return
  savingScores.value[key] = true
  try {
    const pct = vals.score_total > 0
      ? Math.round((vals.score_obtenu / vals.score_total) * 100 * 100) / 100
      : 0
    const res  = await fetch(`${API}?action=save_score&rand=${Math.random()}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        matricule:         s.matricule_seminaire,
        evaluation_id:     evalId,
        score_obtenu:      vals.score_obtenu,
        score_total:       vals.score_total,
        score_pourcentage: pct
      })
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)

    const idx = (s.sessions || []).findIndex(sess => Number(sess.evaluation_id) === Number(evalId))
    if (idx >= 0) {
      s.sessions[idx].score_obtenu      = vals.score_obtenu
      s.sessions[idx].score_total       = vals.score_total
      s.sessions[idx].score_pourcentage = pct
    } else {
      s.sessions = s.sessions || []
      s.sessions.push({
        session_id:        Date.now(),
        evaluation_id:     evalId,
        score_obtenu:      vals.score_obtenu,
        score_total:       vals.score_total,
        score_pourcentage: pct,
        statut:            'termine',
        temps_ecoule:      0,
        evaluation_titre:  levelEvaluations.value.find(e => e.id === evalId)?.titre || ''
      })
    }
    delete dirtyScores.value[key]
    dirtyScores.value = { ...dirtyScores.value }
    showToast('success', 'Score sauvegardé ✓')
  } catch { showToast('error', 'Erreur sauvegarde score') }
  finally { savingScores.value[key] = false }
}

// ─── Coefficients & calcul classement ─────────────────────────────────────
function getCoeff(evalId) {
  return parseFloat(coefficients.value[evalId]) || 1
}

function calculateRankings() {
  const scores = seminaristes.value.map(s => {
    let totalPts = 0, totalCoeff = 0

    levelEvaluations.value.forEach(ev => {
      const sc    = getScore(s, ev.id)
      const coeff = getCoeff(ev.id)
      if (sc && sc.score_total > 0) {
        const note20 = (sc.score_obtenu / sc.score_total) * 20
        totalPts   += note20 * coeff
        totalCoeff += coeff
      }
    })

    const conduite = getConduite(s)
    totalPts   += conduite * 1
    totalCoeff += 1

    const moyenne = totalCoeff > 0 ? Math.round((totalPts / totalCoeff) * 100) / 100 : 0

    return {
      matricule: s.matricule_seminaire,
      nom: s.nom,
      prenom: s.prenom,
      conduite,
      sessions: levelEvaluations.value.map(ev => {
        const sc = getScore(s, ev.id)
        return sc ? `${sc.score_obtenu}/${sc.score_total}` : '—'
      }),
      sessionsDetail: levelEvaluations.value.map(ev => {
        const sc = getScore(s, ev.id)
        return {
          titre: ev.titre,
          coeff: getCoeff(ev.id),
          score_obtenu: sc ? sc.score_obtenu : null,
          score_total: sc ? sc.score_total : null,
          score_pourcentage: sc ? sc.score_pourcentage : null,
          note20: sc && sc.score_total > 0 ? Math.round((sc.score_obtenu / sc.score_total) * 20 * 100) / 100 : null
        }
      }),
      total:       Math.round(totalPts * 100) / 100,
      totalCoeff,
      moyenne,
      rang: 0
    }
  })

  scores.sort((a, b) => b.moyenne - a.moyenne)

  let rang = 1
  scores.forEach((s, i) => {
    if (i > 0 && s.moyenne === scores[i - 1].moyenne) {
      s.rang = scores[i - 1].rang
    } else {
      s.rang = rang
    }
    rang = i + 2
  })

  calcResults.value   = scores
  showCalcTable.value = true
  showToast('success', `Classement calculé — ${scores.length} séminaristes`)
}

// ─── Bulletins PDF depuis le classement calculé ───────────────────────────
async function generateBulletinFromCalc(r) {
  exporting.value = true
  try {
    const doc = new jsPDF('p', 'mm', 'a4')
    await _renderBulletinFromCalc(doc, r)
    doc.save(`bulletin_${r.matricule}_${new Date().toISOString().slice(0, 10)}.pdf`)
    showToast('success', 'Bulletin généré !')
  } catch (e) { showToast('error', 'Erreur génération bulletin') }
  finally { exporting.value = false }
}

async function generateAllBulletinsFromCalc() {
  if (!calcResults.value.length) { showToast('info', 'Aucun résultat calculé'); return }
  exporting.value = true
  try {
    const doc = new jsPDF('p', 'mm', 'a4'); let first = true
    for (const r of calcResults.value) {
      if (!first) doc.addPage()
      await _renderBulletinFromCalc(doc, r)
      first = false
    }
    doc.save(`bulletins_classement_${selectedLevel.value}_${new Date().toISOString().slice(0, 10)}.pdf`)
    showToast('success', `${calcResults.value.length} bulletins générés`)
  } catch (e) { showToast('error', 'Erreur génération bulletins') }
  finally { exporting.value = false }
}

async function _renderBulletinFromCalc(doc, r) {
  const logoUrl = 'https://upload.wikimedia.org/wikipedia/fr/4/42/Logo_AEEMCI.jpeg'
  const today   = new Date()
  const pageW   = 210

  // ── En-tête vert ──────────────────────────────────────────────────────
  doc.setFillColor(0, 128, 0)
  doc.rect(0, 0, pageW, 38, 'F')

  try {
    const logo = new Image(); logo.crossOrigin = 'anonymous'; logo.src = logoUrl
    await new Promise((res, rej) => { logo.onload = res; logo.onerror = rej })
    doc.addImage(logo, 'JPEG', 10, 6, 26, 26)
  } catch {}

  doc.setFontSize(17); doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold')
  doc.text('AEEMCI — SENAFOI', pageW / 2, 13, { align: 'center' })
  doc.setFontSize(10); doc.setFont('helvetica', 'normal')
  doc.text("Association des Elèves et Etudiants Musulmans de Côte d'Ivoire", pageW / 2, 20, { align: 'center' })
  doc.setFontSize(13); doc.setFont('helvetica', 'bold')
  doc.text("BULLETIN DE RÉSULTATS — CLASSEMENT OFFICIEL", pageW / 2, 29, { align: 'center' })

  // ── Badge rang ────────────────────────────────────────────────────────
  const rangColor = r.rang === 1 ? [212, 175, 55] : r.rang === 2 ? [160, 160, 160] : r.rang === 3 ? [176, 101, 35] : [99, 102, 241]
  doc.setFillColor(...rangColor)
  doc.roundedRect(pageW - 40, 26, 26, 18, 3, 3, 'F')
  doc.setTextColor(255, 255, 255)
  doc.setFontSize(8); doc.setFont('helvetica', 'normal')
  doc.text('RANG', pageW - 27, 32, { align: 'center' })
  doc.setFontSize(22); doc.setFont('helvetica', 'bold')
  doc.text(String(r.rang), pageW - 27, 40, { align: 'center' })

  // ── Bloc identification ────────────────────────────────────────────────
  let y = 46
  doc.setTextColor(0, 0, 0)
  doc.setFillColor(245, 247, 255)
  doc.roundedRect(10, y, pageW - 20, 38, 4, 4, 'F')
  doc.setDrawColor(99, 102, 241); doc.setLineWidth(0.4)
  doc.roundedRect(10, y, pageW - 20, 38, 4, 4, 'S')

  doc.setFontSize(8); doc.setFont('helvetica', 'bold'); doc.setTextColor(99, 102, 241)
  doc.text('IDENTIFICATION DU SÉMINARISTE', 16, y + 7)

  doc.setTextColor(0, 0, 0); doc.setFont('helvetica', 'normal'); doc.setFontSize(9)
  const col1x = 16, col2x = 75, col3x = 130
  const infoY = y + 14

  doc.setFont('helvetica', 'bold'); doc.text('Nom complet :', col1x, infoY)
  doc.setFont('helvetica', 'normal'); doc.text(`${r.prenom} ${r.nom}`, col1x + 23, infoY)

  doc.setFont('helvetica', 'bold'); doc.text('Matricule :', col2x + 10, infoY)
  doc.setFont('helvetica', 'normal'); doc.text(r.matricule || '—', col2x + 28, infoY)

  doc.setFont('helvetica', 'bold'); doc.text('Niveau :', col3x + 10, infoY)
  doc.setFont('helvetica', 'normal'); doc.text(selectedLevel.value, col3x + 23, infoY)

  doc.setFont('helvetica', 'bold'); doc.text('Année :', col1x, infoY + 8)
  doc.setFont('helvetica', 'normal'); doc.text(anneeEnCours.value || '—', col1x + 12, infoY + 8)

  doc.setFont('helvetica', 'bold'); doc.text('Date :', col2x + 10, infoY + 8)
  doc.setFont('helvetica', 'normal'); doc.text(formatDate(today.toISOString()), col2x + 23, infoY + 8)

  // ── Tableau des évaluations ────────────────────────────────────────────
  y += 46
  doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setTextColor(99, 102, 241)
  doc.text('DÉTAIL DES ÉVALUATIONS', 16, y + 6)
  y += 10

  const evalRows = r.sessionsDetail.map(sd => [
    sd.titre,
    `×${sd.coeff}`,
    sd.score_obtenu !== null ? `${sd.score_obtenu}/${sd.score_total}` : '—',
    sd.note20 !== null ? `${sd.note20}/20` : '—',
    sd.score_pourcentage !== null ? `${sd.score_pourcentage}%` : '—'
  ])
  evalRows.push([
    'Conduite',
    '×1',
    `${r.conduite}/20`,
    `${r.conduite}/20`,
    `${Math.round((r.conduite / 20) * 100)}%`
  ])

  autoTable(doc, {
    startY: y,
    head: [['Évaluation', 'Coeff.', 'Score brut', 'Note /20', 'Pourcentage']],
    body: evalRows,
    theme: 'grid',
    headStyles: { fillColor: [0, 128, 0], textColor: 255, fontStyle: 'bold', fontSize: 9, halign: 'center' },
    bodyStyles: { fontSize: 9, cellPadding: 3 },
    columnStyles: {
      0: { fontStyle: 'bold', cellWidth: 75 },
      1: { halign: 'center', cellWidth: 20 },
      2: { halign: 'center', cellWidth: 30 },
      3: { halign: 'center', cellWidth: 30 },
      4: { halign: 'center', cellWidth: 35 }
    },
    alternateRowStyles: { fillColor: [248, 250, 252] }
  })

  // ── Bloc récapitulatif ─────────────────────────────────────────────────
  const afterTableY = doc.lastAutoTable.finalY + 8

  // Fond récap
  doc.setFillColor(0, 128, 0)
  doc.roundedRect(10, afterTableY, pageW - 20, 28, 4, 4, 'F')
  doc.setTextColor(255, 255, 255)

  const recapCols = [
    { label: 'Total points', value: String(r.total) },
    { label: 'Coeff. totaux', value: String(r.totalCoeff) },
    { label: 'Moyenne générale', value: `${r.moyenne}/20` },
    { label: 'Rang', value: `${r.rang}${r.rang === 1 ? 'er' : 'ème'}` }
  ]
  const colW = (pageW - 20) / recapCols.length
  recapCols.forEach((rc, i) => {
    const cx = 10 + i * colW + colW / 2
    doc.setFontSize(7.5); doc.setFont('helvetica', 'normal')
    doc.text(rc.label, cx, afterTableY + 9, { align: 'center' })
    doc.setFontSize(14); doc.setFont('helvetica', 'bold')
    doc.text(rc.value, cx, afterTableY + 21, { align: 'center' })
  })

  // Séparateurs verticaux
  doc.setDrawColor(255, 255, 255, 0.4); doc.setLineWidth(0.3)
  for (let i = 1; i < recapCols.length; i++) {
    const lx = 10 + i * colW
    doc.line(lx, afterTableY + 4, lx, afterTableY + 24)
  }

  // ── Mention ────────────────────────────────────────────────────────────
  const mentionY = afterTableY + 36
  const mention  = r.moyenne >= 18 ? { label: 'TRÈS HONORABLE', color: [5, 150, 105] }
                 : r.moyenne >= 16 ? { label: 'HONORABLE',       color: [16, 185, 129] }
                 : r.moyenne >= 14 ? { label: 'ASSEZ BIEN',       color: [245, 158, 11] }
                 : r.moyenne >= 12 ? { label: 'BIEN',             color: [99, 102, 241] }
                 : r.moyenne >= 10 ? { label: 'PASSABLE',         color: [156, 163, 175] }
                 : { label: 'INSUFFISANT', color: [239, 68, 68] }

  doc.setFillColor(...mention.color, 0.12)
  doc.roundedRect(pageW / 2 - 45, mentionY - 6, 90, 14, 3, 3, 'F')
  doc.setDrawColor(...mention.color); doc.setLineWidth(0.5)
  doc.roundedRect(pageW / 2 - 45, mentionY - 6, 90, 14, 3, 3, 'S')
  doc.setFontSize(11); doc.setFont('helvetica', 'bold'); doc.setTextColor(...mention.color)
  doc.text(`Mention : ${mention.label}`, pageW / 2, mentionY + 2, { align: 'center' })

  // ── Zone signature ─────────────────────────────────────────────────────
  const sigY = mentionY + 18
  doc.setTextColor(0, 0, 0); doc.setDrawColor(180, 180, 180); doc.setLineWidth(0.3)
  const sigW = 55
  ;[['Le Responsable pédagogique', 30], ['Le Directeur du séminaire', pageW / 2 - sigW / 2], ['Cachet & Signature', pageW - 30 - sigW]].forEach(([lbl, sx]) => {
    doc.setFontSize(7.5); doc.setFont('helvetica', 'normal'); doc.setTextColor(120, 120, 120)
    doc.text(lbl, sx + sigW / 2, sigY, { align: 'center' })
    doc.line(sx, sigY + 14, sx + sigW, sigY + 14)
  })

  // ── Pied de page ──────────────────────────────────────────────────────
  doc.setFillColor(0, 128, 0)
  doc.rect(0, 275, pageW, 22, 'F')
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(255, 255, 255)
  doc.text("AEEMCI - Mosquée An-Nour Riviera II / 08 BP 2462 Abidjan 08", pageW / 2, 281, { align: 'center' })
  doc.text('www.aeemci.ci | aeemci@yahoo.fr', pageW / 2, 286, { align: 'center' })
  doc.setFont('helvetica', 'bold'); doc.setFontSize(7.5)
  doc.text(`Document généré le ${formatDate(today.toISOString())} — Confidentiel`, pageW / 2, 292, { align: 'center' })
}

// ─── Accordéon ────────────────────────────────────────────────────────────
function toggleExpand(mat) {
  const s = new Set(expandedRows.value)
  s.has(mat) ? s.delete(mat) : s.add(mat)
  expandedRows.value = s
}
function isExpanded(mat) { return expandedRows.value.has(mat) }

// ─── Conduite ──────────────────────────────────────────────────────────────
function semKey(s) { return s.matricule_seminaire || String(s.id_seminaire) }

function onConduiteInput(s, event) {
  const key = semKey(s), val = event.target.value
  const current = getConduite(s)
  if (String(val) === String(current)) { delete dirtyConduite.value[key] }
  else { dirtyConduite.value[key] = val }
  dirtyConduite.value = { ...dirtyConduite.value }
}
function isConduiteDirty(s) { return dirtyConduite.value[semKey(s)] !== undefined }

async function saveConduiteRow(s) {
  const key = semKey(s), val = dirtyConduite.value[key]
  savingConduite.value[key] = true
  try {
    const res  = await fetch(`${API}?action=save_conduite&rand=${Math.random()}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ matricule: s.matricule_seminaire, valeur: val !== '' ? parseFloat(val) : null })
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)

    const idx = (s.sessions || []).findIndex(sess => Number(sess.evaluation_id) === 40)
    const newVal = val !== '' ? parseFloat(val) : null
    if (idx >= 0) {
      s.sessions[idx].score_obtenu = newVal
    } else {
      s.sessions = s.sessions || []
      s.sessions.push({
        session_id: Date.now(), evaluation_id: 40,
        score_obtenu: newVal, score_total: 20,
        score_pourcentage: newVal !== null ? Math.round((newVal / 20) * 100 * 100) / 100 : 0,
        statut: 'termine', temps_ecoule: 0, evaluation_titre: 'Conduite'
      })
    }
    delete s.conduite_default
    delete dirtyConduite.value[key]
    dirtyConduite.value = { ...dirtyConduite.value }
    showToast('success', 'Conduite enregistrée ✓')
  } catch { showToast('error', 'Erreur sauvegarde conduite') }
  finally { savingConduite.value[key] = false }
}

// ─── Sélection ────────────────────────────────────────────────────────────
const allSelected = computed(() =>
  seminaristes.value.length > 0 && selectedSems.value.size === seminaristes.value.length
)
function toggleSem(mat) {
  const s = new Set(selectedSems.value)
  s.has(mat) ? s.delete(mat) : s.add(mat)
  selectedSems.value = s
}
function toggleSelectAll() {
  if (allSelected.value) { selectedSems.value = new Set() }
  else { selectedSems.value = new Set(seminaristes.value.map(s => s.matricule_seminaire)) }
}

// ─── Export Excel ─────────────────────────────────────────────────────────
async function exportLevelsExcel() {
  if (!seminaristes.value.length) { showToast('info', 'Aucune donnée à exporter'); return }
  exporting.value = true
  try {
    const rows = seminaristes.value.map(s => {
      const row = {
        'Nom':              s.nom,
        'Prénom':           s.prenom,
        'Matricule':        s.matricule_seminaire,
        'Niveau séminaire': getNiveauLabel(s.niveau_seminaire),
        'Niveau école':     s.niveau_etude || '—',
        'Sexe':             s.sexe || '—',
        'Conduite/20':      getConduite(s),
      }
      levelEvaluations.value.forEach(ev => {
        const sc = getScore(s, ev.id)
        row[ev.titre] = sc ? `${sc.score_obtenu}/${sc.score_total} (${sc.score_pourcentage}%)` : '—'
      })
      return row
    })
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows), 'Niveaux')
    XLSX.writeFile(wb, `niveaux_${selectedLevel.value}_${new Date().toISOString().slice(0, 10)}.xlsx`)
    showToast('success', `${rows.length} séminaristes exportés`)
  } catch { showToast('error', 'Erreur export') }
  finally { exporting.value = false }
}

async function exportClassementExcel() {
  if (!calcResults.value.length) { showToast('info', 'Calculer le classement d\'abord'); return }
  exporting.value = true
  try {
    const rows = calcResults.value.map(r => {
      const row = {
        'Rang':      r.rang,
        'Nom':       r.nom,
        'Prénom':    r.prenom,
        'Matricule': r.matricule,
        'Conduite/20': r.conduite,
      }
      levelEvaluations.value.forEach((ev, i) => {
        row[`${ev.titre} (×${getCoeff(ev.id)})`] = r.sessions[i] || '—'
      })
      row['Total points']  = r.total
      row['Moyenne/20']    = r.moyenne
      return row
    })
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(rows), 'Classement')
    XLSX.writeFile(wb, `classement_${selectedLevel.value}_${new Date().toISOString().slice(0, 10)}.xlsx`)
    showToast('success', 'Classement exporté')
  } catch { showToast('error', 'Erreur export classement') }
  finally { exporting.value = false }
}

// ─── Bulletins PDF (mode ancienne vue) ────────────────────────────────────
async function generateBulletinPDF(s) {
  exporting.value = true
  try {
    const doc = new jsPDF('p', 'mm', 'a4')
    await _renderBulletin(doc, s)
    doc.save(`bulletin_${s.matricule_seminaire}_${new Date().toISOString().slice(0, 10)}.pdf`)
    showToast('success', 'Bulletin généré !')
  } catch { showToast('error', 'Erreur génération bulletin') }
  finally { exporting.value = false }
}

async function generateSelectedBulletins() {
  const list = seminaristes.value.filter(s => selectedSems.value.has(s.matricule_seminaire))
  if (!list.length) { showToast('info', 'Aucun séminariste sélectionné'); return }
  exporting.value = true
  try {
    const doc = new jsPDF('p', 'mm', 'a4'); let first = true
    for (const s of list) { if (!first) doc.addPage(); await _renderBulletin(doc, s); first = false }
    doc.save(`bulletins_selection_${new Date().toISOString().slice(0, 10)}.pdf`)
    showToast('success', `${list.length} bulletins générés`)
  } catch { showToast('error', 'Erreur génération bulletins') }
  finally { exporting.value = false }
}

async function generateAllBulletins() {
  if (!seminaristes.value.length) return
  exporting.value = true
  try {
    const doc = new jsPDF('p', 'mm', 'a4'); let first = true
    for (const s of seminaristes.value) { if (!first) doc.addPage(); await _renderBulletin(doc, s); first = false }
    doc.save(`bulletins_${selectedLevel.value}_${new Date().toISOString().slice(0, 10)}.pdf`)
    showToast('success', `${seminaristes.value.length} bulletins générés`)
  } catch { showToast('error', 'Erreur génération bulletins') }
  finally { exporting.value = false }
}

async function _renderBulletin(doc, s) {
  const logoUrl = 'https://upload.wikimedia.org/wikipedia/fr/4/42/Logo_AEEMCI.jpeg'
  const today   = new Date()

  doc.setFillColor(0, 128, 0); doc.rect(0, 0, 210, 35, 'F')
  try {
    const logo = new Image(); logo.crossOrigin = 'anonymous'; logo.src = logoUrl
    await new Promise((res, rej) => { logo.onload = res; logo.onerror = rej })
    doc.addImage(logo, 'JPEG', 10, 6, 25, 25)
  } catch {}
  doc.setFontSize(18); doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold')
  doc.text('AEEMCI', 105, 14, { align: 'center' })
  doc.setFontSize(10)
  doc.text("Association des Elèves et Etudiants Musulmans de Côte d'Ivoire", 105, 20, { align: 'center' })
  doc.setFontSize(13)
  doc.text("BULLETIN D'ÉVALUATION - SENAFOI", 105, 28, { align: 'center' })

  let y = 45
  doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(0, 0, 0)
  doc.text('IDENTIFICATION DU SÉMINARISTE', 20, y); y += 8
  doc.setFontSize(9)
  ;[['Nom', s.nom], ['Prénom', s.prenom], ['Matricule', s.matricule_seminaire],
    ['Niveau', getNiveauLabel(s.niveau_seminaire)], ['Année', s.annee_seminaire || anneeEnCours.value]].forEach(([lbl, val]) => {
    doc.setFont('helvetica', 'normal'); doc.text(`${lbl} :`, 20, y)
    doc.setFont('helvetica', 'bold');  doc.text(val || 'N/A', 55, y); y += 6
  })

  y += 5
  doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.setTextColor(0, 102, 204)
  doc.text('RÉSULTATS DES ÉVALUATIONS', 20, y); y += 8

  const rows = (s.sessions || [])
    .filter(sess => Number(sess.evaluation_id) !== 40)
    .map(sess => [
      sess.evaluation_titre,
      sess.statut === 'termine'
        ? `${sess.score_obtenu}/${sess.score_total} (${sess.score_pourcentage}%)`
        : 'Non terminée'
    ])
  if (!rows.length) rows.push(['Aucune évaluation passée', '—'])
  rows.push(['Conduite', `${getConduite(s)}/20`])

  autoTable(doc, {
    startY: y, head: [['ÉVALUATION', 'RÉSULTAT']], body: rows, theme: 'grid',
    headStyles: { fillColor: [0, 128, 0], textColor: 255, fontStyle: 'bold' },
    styles: { fontSize: 9, cellPadding: 3 },
    columnStyles: { 0: { fontStyle: 'bold', cellWidth: 110 }, 1: { cellWidth: 50 } }
  })

  doc.setFillColor(0, 128, 0); doc.rect(0, 275, 210, 22, 'F')
  doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(255, 255, 255)
  doc.text("AEEMCI - Mosquée An-Nour Riviera II / 08 BP 2462 Abidjan 08", 105, 282, { align: 'center' })
  doc.text('www.aeemci.ci | aeemci@yahoo.fr', 105, 287, { align: 'center' })
  doc.setFont('helvetica', 'bold'); doc.setFontSize(8)
  doc.text(`Bulletin généré le ${formatDate(today.toISOString())}`, 105, 293, { align: 'center' })
}

// ─── CRUD évaluations ─────────────────────────────────────────────────────
async function addEvaluation() {
  if (!form.value.titre || form.value.questions.length === 0) {
    showToast('error', 'Titre et au moins une question requis'); return
  }
  submitting.value = true
  try {
    const res  = await fetch(`${API}?action=create_evaluation&rand=${Math.random()}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(form.value)
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    showToast('success', 'Évaluation créée avec succès')
    closeModal(); await loadAll()
  } catch (e) { showToast('error', e.message) }
  finally { submitting.value = false }
}

async function updateEvaluation() {
  if (!form.value.titre || form.value.questions.length === 0) {
    showToast('error', 'Titre et au moins une question requis'); return
  }
  submitting.value = true
  try {
    const res  = await fetch(`${API}?action=update_evaluation&id=${selectedEval.value.id}&rand=${Math.random()}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(form.value)
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    showToast('success', 'Évaluation modifiée'); closeModal(); await loadAll()
  } catch (e) { showToast('error', e.message) }
  finally { submitting.value = false }
}

async function deleteEvaluation(ev) {
  if (!confirm(`Supprimer "${ev.titre}" ?`)) return
  try {
    const res  = await fetch(`${API}?action=delete_evaluation&id=${ev.id}&rand=${Math.random()}`, { method: 'DELETE' })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    showToast('success', 'Évaluation supprimée'); await loadAll()
  } catch (e) { showToast('error', e.message) }
}

async function duplicateEvaluation(ev) {
  try {
    const res  = await fetch(`${API}?action=duplicate_evaluation&id=${ev.id}&rand=${Math.random()}`, { method: 'POST' })
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    showToast('success', 'Évaluation dupliquée'); await loadAll()
  } catch (e) { showToast('error', e.message) }
}

async function editEvaluation(ev) {
  selectedEval.value = ev
  form.value = {
    titre: ev.titre, description: ev.description || '', duree: ev.duree,
    acces_type: ev.acces_type || 'libre', matricules_autorises: ev.matricules_autorises || '', questions: []
  }
  const res  = await fetch(`${API}?action=questions&evaluation_id=${ev.id}&rand=${Math.random()}`)
  const data = await res.json()
  if (data.success) form.value.questions = data.data || []
  showEditModal.value = true
}

async function viewResults(ev) {
  selectedEval.value = ev
  try {
    const res  = await fetch(`${API}?action=resultats&evaluation_id=${ev.id}&rand=${Math.random()}`)
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    results.value = data.data; showResultsModal.value = true
  } catch (e) { showToast('error', e.message) }
}

async function exportExcel() {
  exporting.value = true
  try {
    const res  = await fetch(`${API}?action=export_results&rand=${Math.random()}`)
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data.data), 'Résultats')
    XLSX.writeFile(wb, `evaluations_${new Date().toISOString().slice(0, 10)}.xlsx`)
    showToast('success', `${data.data.length} enregistrements exportés`)
  } catch { showToast('error', 'Erreur export Excel') }
  finally { exporting.value = false }
}

async function exportEvalExcel(ev) {
  exporting.value = true
  try {
    const res  = await fetch(`${API}?action=resultats&evaluation_id=${ev.id}&rand=${Math.random()}`)
    const data = await res.json()
    if (!data.success) throw new Error(data.error)
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(data.data.participants || []), 'Participants')
    XLSX.writeFile(wb, `eval_${ev.titre.replace(/\W/g, '_')}_${new Date().toISOString().slice(0, 10)}.xlsx`)
    showToast('success', 'Export réussi')
  } catch { showToast('error', 'Erreur export') }
  finally { exporting.value = false }
}

async function openQR(ev) {
  selectedEval.value = ev
  qrURL.value = `https://aeemci-ce.ci/evaluation_question/${ev.id}`
  showQRModal.value = true
  await new Promise(r => setTimeout(r, 50))
  qrImageURL.value = await QRCode.toDataURL(qrURL.value, { width: 220, margin: 2 })
}

function addQuestion() {
  form.value.questions.push({ question: '', reponse_a: '', reponse_b: '', reponse_c: '', reponse_d: '', bonne_reponse: '', points: 1 })
}
function removeQuestion(i) { form.value.questions.splice(i, 1) }

async function goToPage(p) {
  if (p < 1 || p > pagination.value.last_page) return
  pagination.value.current_page = p; await loadEvaluations()
}
async function changePerPage() { pagination.value.current_page = 1; await loadEvaluations() }

let _debounce = null
watch(() => filters.value.search, () => {
  clearTimeout(_debounce)
  _debounce = setTimeout(() => { pagination.value.current_page = 1; loadEvaluations() }, 350)
})

function resetFilters() {
  filters.value = { search: '', statut: '', date_debut: '', date_fin: '' }
  pagination.value.current_page = 1; loadEvaluations()
}

function closeModal() {
  showAddModal.value = false; showEditModal.value = false
  showResultsModal.value = false; showQRModal.value = false
  selectedEval.value = null; results.value = null
  form.value = defaultForm(); qrImageURL.value = null
}

function showToast(type, message, dur = 3500) {
  clearTimeout(toastTimer)
  toast.value = { show: true, type, message }
  toastTimer = setTimeout(() => toast.value.show = false, dur)
}

// ─── Helpers ──────────────────────────────────────────────────────────────
function getStatutClass(s) {
  return s === 'active' ? 'b-present' : s === 'brouillon' ? 'b-draft' : 'b-absent'
}
function getStatutLabel(s) {
  return s === 'active' ? 'Active' : s === 'brouillon' ? 'Brouillon' : 'Terminée'
}
function getScoreClass(p) {
  p = parseFloat(p); if (isNaN(p)) return 'b-absent'
  if (p >= 80) return 'b-present'; if (p >= 60) return 'b-draft'
  if (p >= 40) return 'b-orange';  return 'b-absent'
}
function getMoyenneClass(m) {
  if (m >= 16) return 'b-present'; if (m >= 12) return 'b-draft'
  if (m >= 8)  return 'b-orange';  return 'b-absent'
}
function getNiveauLabel(n) {
  if (!n) return '—'
  const map = {
    'NIVEAU 1AS': '1ère An. Secondaire', 'NIVEAU 2AS': '2ème An. Secondaire', 'NIVEAU 3AS': '3ème An. Secondaire',
    'NIVEAU 1AF': '1ère An. Formation',  'NIVEAU 2AF': '2ème An. Formation',  'NIVEAU 3AF': '3ème An. Formation',
    'NIVEAU 1BS': '1ère An. Supérieure', 'NIVEAU 2BS': '2ème An. Supérieure', 'NIVEAU 3BS': '3ème An. Supérieure',
    'NIVEAU 1BF': '1ère An. Form. Avancée', 'NIVEAU 2BF': '2ème An. Form. Avancée', 'NIVEAU 3BF': '3ème An. Form. Avancée',
    'NIVEAU 4': 'Niveau 4',
    '1AS': '1ère An. Secondaire', '2AS': '2ème An. Secondaire', '3AS': '3ème An. Secondaire',
    '1AF': '1ère An. Formation',  '2AF': '2ème An. Formation',  '3AF': '3ème An. Formation',
    '1BS': '1ère An. Supérieure', '2BS': '2ème An. Supérieure', '3BS': '3ème An. Supérieure',
    '1BF': '1ère An. Form. Avancée', '2BF': '2ème An. Form. Avancée', '3BF': '3ème An. Form. Avancée',
    '4': 'Niveau 4', 'TEST_ENTREE': "Test d'entrée"
  }
  return map[n] || n
}
function formatTime(s) {
  if (!s) return '—'; return `${Math.floor(s / 60)}min ${s % 60}s`
}
function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('fr-FR', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const visiblePages = computed(() => {
  const total = pagination.value.last_page, cur = pagination.value.current_page
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur, cur - 1, cur + 1].filter(p => p > 0 && p <= total))
  return Array.from(pages).sort((a, b) => a - b)
})

onMounted(() => { loadAll(); loadAvailableLevels() })
</script>

<template>
  <div class="page" style="margin:-15px">
    <div class="content">

      <!-- Breadcrumb -->
      <div class="breadcrumb">
        <span class="bc-root">Séminaires</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        <span class="bc-active">Gestion des Évaluations</span>
      </div>

      <div v-if="loading" class="state-block">
        <div class="spinner"></div>
        <p>Chargement des données…</p>
      </div>
      <div v-else-if="error" class="state-block state-error">
        <p>Impossible de charger les données</p><code>{{ error }}</code>
      </div>

      <template v-else>

        <!-- Header -->
        <div class="page-header">
          <div>
            <h1 class="page-title">Gestion des Évaluations</h1>
            <p class="page-sub">Création, suivi QCM et bulletins de niveaux · SENAFOI</p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <button class="btn-outline" :disabled="exporting" @click="exportExcel">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Exporter Excel
            </button>
            <button class="btn-primary" @click="showAddModal = true">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Créer Évaluation
            </button>
          </div>
        </div>

        <div v-if="exporting" class="export-banner">
          <div class="spinner" style="width:18px;height:18px;border-width:2px;"></div>
          Génération en cours…
        </div>

        <!-- KPI -->
        <div class="kpi-row">
          <div class="kpi-card">
            <div class="kpi-icon kpi-blue"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586l5.414 5.414V19a2 2 0 01-2 2z"/></svg></div>
            <div class="kpi-data"><span class="kpi-val">{{ stats.total_evaluations }}</span><span class="kpi-label">Total évaluations</span></div>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-green"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
            <div class="kpi-data"><span class="kpi-val c-green">{{ stats.total_participants }}</span><span class="kpi-label">Participants</span></div>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-amber"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div>
            <div class="kpi-data"><span class="kpi-val" style="color:#f59e0b">{{ stats.active_evaluations }}</span><span class="kpi-label">Évaluations actives</span></div>
          </div>
          <div class="kpi-card">
            <div class="kpi-icon kpi-violet"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg></div>
            <div class="kpi-data"><span class="kpi-val" style="color:#8b5cf6">{{ stats.average_score }}%</span><span class="kpi-label">Moyenne générale</span></div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-bar">
          <button class="tab" :class="{ active: activeTab === 'evaluations' }" @click="activeTab = 'evaluations'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586l5.414 5.414V19a2 2 0 01-2 2z"/></svg>
            Évaluations QCM
          </button>
          <button class="tab" :class="{ active: activeTab === 'levels' }" @click="activeTab = 'levels'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
            Niveaux &amp; Bulletins
          </button>
        </div>

        <!-- ════════════ TAB — ÉVALUATIONS QCM ════════════ -->
        <template v-if="activeTab === 'evaluations'">
          <div class="toolbar">
            <div class="search-wrap">
              <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input v-model="filters.search" class="search-input" placeholder="Rechercher par titre…" />
              <span v-if="filters.search" class="search-clear" @click="filters.search=''">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
              </span>
            </div>
            <div class="toolbar-right">
              <select class="filter-sel" v-model="filters.statut" @change="pagination.current_page=1; loadEvaluations()">
                <option value="">Tous les statuts</option>
                <option value="brouillon">Brouillon</option>
                <option value="active">Active</option>
                <option value="terminee">Terminée</option>
              </select>
              <input type="date" class="filter-sel" v-model="filters.date_debut" @change="pagination.current_page=1; loadEvaluations()" />
              <input type="date" class="filter-sel" v-model="filters.date_fin"   @change="pagination.current_page=1; loadEvaluations()" />
              <button class="btn-outline" @click="resetFilters">Réinitialiser</button>
            </div>
          </div>

          <div class="card">
            <div class="table-wrap">
              <table class="table">
                <thead>
                  <tr><th>Titre</th><th>Questions</th><th>Participants</th><th>Durée</th><th>Statut</th><th>Accès</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  <tr v-if="evaluations.length===0"><td colspan="7" class="empty">Aucune évaluation trouvée</td></tr>
                  <tr v-for="ev in evaluations" :key="ev.id" class="table-row">
                    <td>
                      <div class="ev-title-cell">
                        <div class="ev-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586l5.414 5.414V19a2 2 0 01-2 2z"/></svg></div>
                        <div><div class="ev-name">{{ ev.titre }}</div><div class="ev-sub">{{ ev.description?ev.description.slice(0,48)+'…':'—' }}</div></div>
                      </div>
                    </td>
                    <td><span class="badge b-blue">{{ ev.nb_questions||0 }}</span></td>
                    <td><span class="badge b-present">{{ ev.nb_participants||0 }}</span></td>
                    <td class="td-cell">{{ ev.duree }} min</td>
                    <td>
                      <div class="statut-wrap">
                        <span class="dot" :class="ev.statut==='active'?'dot-present':ev.statut==='brouillon'?'dot-draft':'dot-absent'"></span>
                        <span class="badge" :class="getStatutClass(ev.statut)">{{ getStatutLabel(ev.statut) }}</span>
                      </div>
                    </td>
                    <td><span class="badge" :class="ev.acces_type==='libre'?'b-present':'b-absent'">{{ ev.acces_type==='libre'?'Libre':'Restreint' }}</span></td>
                    <td>
                      <div class="actions">
                        <button class="act act-view"    @click="openQR(ev)"              title="QR Code"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></button>
                        <button class="act act-results" @click="viewResults(ev)"         title="Résultats"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></button>
                        <button class="act act-excel"   @click="exportEvalExcel(ev)"     :disabled="exporting" title="Excel"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></button>
                        <button class="act act-edit"    @click="editEvaluation(ev)"      title="Modifier"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>
                        <button class="act act-dup"     @click="duplicateEvaluation(ev)" title="Dupliquer"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
                        <button class="act act-del"     @click="deleteEvaluation(ev)"    title="Supprimer"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="pagination">
              <span class="pag-info"><strong>{{ pagination.from||0 }}</strong>–<strong>{{ pagination.to||0 }}</strong> sur <strong>{{ pagination.total||0 }}</strong></span>
              <div class="pag-pages">
                <button class="pag-btn" :disabled="pagination.current_page<=1" @click="goToPage(pagination.current_page-1)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg></button>
                <template v-for="(p,i) in visiblePages" :key="p">
                  <span v-if="i>0&&p-visiblePages[i-1]>1" class="pag-ellipsis">…</span>
                  <button class="pag-btn" :class="{ active: p===pagination.current_page }" @click="goToPage(p)">{{ p }}</button>
                </template>
                <button class="pag-btn" :disabled="pagination.current_page>=pagination.last_page" @click="goToPage(pagination.current_page+1)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="9 18 15 12 9 6"/></svg></button>
              </div>
              <div class="per-page">
                <span>Lignes</span>
                <select class="per-page-select" v-model="pagination.per_page" @change="changePerPage">
                  <option :value="10">10</option><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option>
                </select>
              </div>
            </div>
          </div>
        </template>

        <!-- ════════════ TAB — NIVEAUX & BULLETINS ════════════ -->
        <template v-if="activeTab === 'levels'">

          <div v-if="!selectedLevel" class="level-picker-wrap">
            <p class="level-picker-label">
              Choisissez un niveau pour afficher les participants
              <span v-if="anneeEnCours" class="annee-badge">{{ anneeEnCours }}</span>
            </p>
            <div class="level-picker-grid">
              <button v-for="lv in availableLevels" :key="lv" class="level-picker-btn" @click="selectLevel(lv)">
                <span class="level-picker-code">{{ lv }}</span>
                <span class="level-picker-name">{{ getNiveauLabel(lv) }}</span>
              </button>
            </div>
          </div>

          <template v-else>

            <div class="level-topbar">
              <button class="btn-outline btn-sm" @click="selectedLevel=''; seminaristes=[]; levelLoaded=false">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"/></svg>
                Changer de niveau
              </button>
              <div class="level-topbar-title">
                <span class="level-title">{{ getNiveauLabel(selectedLevel) }}</span>
                <span class="level-code">{{ selectedLevel }}</span>
                <span v-if="anneeEnCours" class="annee-badge">{{ anneeEnCours }}</span>
              </div>
            </div>

            <div class="toolbar">
              <div class="search-wrap">
                <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input v-model="levelFilters.search" class="search-input" placeholder="Rechercher par nom, matricule…" />
                <span v-if="levelFilters.search" class="search-clear" @click="levelFilters.search=''; loadSeminaristsByLevel()">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </span>
              </div>
              <div class="toolbar-right">
                <div class="display-mode-toggle">
                  <button class="mode-btn" :class="{ active: displayMode === 'flat' }" @click="displayMode = 'flat'" title="Vue colonnes">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    Colonnes
                  </button>
                  <button class="mode-btn" :class="{ active: displayMode === 'expand' }" @click="displayMode = 'expand'" title="Vue accordéon">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    Accordéon
                  </button>
                </div>
                <button class="btn-outline btn-sm" :disabled="exporting || selectedSems.size===0" @click="generateSelectedBulletins">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Bulletins sél. ({{ selectedSems.size }})
                </button>
                <button class="btn-outline btn-sm" :disabled="exporting" @click="generateAllBulletins">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Tous les bulletins
                </button>
                <button class="btn-outline" :disabled="exporting" @click="exportLevelsExcel">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                  Exporter Excel
                </button>
              </div>
            </div>

            <div v-if="levelLoaded && seminaristes.length===0" class="state-block"
                style="background:#fff;border-radius:16px;border:1px solid rgba(0,0,0,.07);">
              <p style="color:#9ca3af">Aucun participant trouvé pour ce niveau ({{ anneeEnCours }})</p>
            </div>

            <!-- Bandeau coefficients -->
            <div v-if="levelLoaded && seminaristes.length" class="coeff-bar">
              <span class="coeff-bar-label">Coefficients :</span>
              <div class="coeff-items">
                <div v-for="ev in levelEvaluations" :key="ev.id" class="coeff-item">
                  <span class="coeff-ev-name" :title="ev.titre">{{ ev.titre.length > 18 ? ev.titre.slice(0,18)+'…' : ev.titre }}</span>
                  <input
                    type="number" min="0.5" max="10" step="0.5"
                    class="coeff-input"
                    :value="getCoeff(ev.id)"
                    @input="coefficients[ev.id] = parseFloat($event.target.value) || 1"
                  />
                </div>
                <div class="coeff-item">
                  <span class="coeff-ev-name">Conduite/20</span>
                  <input type="number" class="coeff-input" value="1" disabled />
                </div>
              </div>
              <button class="btn-calc" @click="calculateRankings">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                  <rect x="4" y="2" width="16" height="20" rx="2"/>
                  <line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="12" y2="14"/>
                </svg>
                Calculer classement
              </button>
            </div>

            <!-- ══ TABLEAU CLASSEMENT ══ -->
            <div v-if="showCalcTable && calcResults.length" class="card calc-card">
              <div class="calc-header">
                <span class="calc-title">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                  Classement — {{ getNiveauLabel(selectedLevel) }} · {{ anneeEnCours }}
                </span>
                <div style="display:flex;gap:8px;align-items:center;">
                  <!-- ★ NOUVEAU : Tous les bulletins classement -->
                  <button class="btn-bulletin-all" :disabled="exporting" @click="generateAllBulletinsFromCalc" title="Imprimer tous les bulletins">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Imprimer tous ({{ calcResults.length }})
                  </button>
                  <button class="btn-outline btn-sm" :disabled="exporting" @click="exportClassementExcel">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Excel
                  </button>
                  <button class="btn-outline btn-sm" @click="showCalcTable = false">Fermer</button>
                </div>
              </div>
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Rang</th>
                      <th>Séminariste</th>
                      <th>Matricule</th>
                      <th v-for="ev in levelEvaluations" :key="ev.id">
                        {{ ev.titre.length > 16 ? ev.titre.slice(0,16)+'…' : ev.titre }}
                        <span class="coeff-tag">×{{ getCoeff(ev.id) }}</span>
                      </th>
                      <th>Conduite/20</th>
                      <th>Total pts</th>
                      <th>Moyenne/20</th>
                      <!-- ★ NOUVEAU : colonne bulletin -->
                      <th style="min-width:90px;">Bulletin</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in calcResults" :key="r.matricule" class="table-row"
                      :class="r.rang===1?'row-gold':r.rang===2?'row-silver':r.rang===3?'row-bronze':''">
                      <td>
                        <span class="rang-badge" :class="r.rang<=3?`rang-${r.rang}`:''">{{ r.rang }}</span>
                      </td>
                      <td class="td-cell"><strong>{{ r.prenom }} {{ r.nom }}</strong></td>
                      <td><span class="mono-badge">{{ r.matricule }}</span></td>
                      <td v-for="(sc, i) in r.sessions" :key="i">
                        <span :class="sc==='—'?'td-empty':'score-frac'">{{ sc }}</span>
                      </td>
                      <td>
                        <span class="badge" :class="getScoreClass((r.conduite/20)*100)">{{ r.conduite }}/20</span>
                      </td>
                      <td><strong>{{ r.total }}</strong></td>
                      <td>
                        <span class="badge" :class="getMoyenneClass(r.moyenne)">{{ r.moyenne }}/20</span>
                      </td>
                      <!-- ★ NOUVEAU : bouton bulletin individuel -->
                      <td>
                        <button
                          class="btn-bulletin-row"
                          :disabled="exporting"
                          @click="generateBulletinFromCalc(r)"
                          title="Générer bulletin PDF">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                          Bulletin
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- ══ MODE FLAT ══ -->
            <div v-if="seminaristes.length && displayMode === 'flat'" class="card">
              <div v-if="levelEvaluations.length" class="eval-legend" style="padding:12px 16px;border-bottom:1px solid rgba(0,0,0,.05);">
                <span class="eval-legend-title">Évaluations :</span>
                <span v-for="(ev,i) in levelEvaluations" :key="ev.id" class="eval-legend-item">
                  <span class="eval-badge" :class="`eval-badge-${(i%6)+1}`">{{ i+1 }}</span>
                  {{ ev.titre }}
                </span>
              </div>

              <div class="table-wrap">
                <table class="table level-table">
                  <thead>
                    <tr>
                      <th class="th-check">
                        <input type="checkbox" class="chk" :checked="allSelected" @change="toggleSelectAll" />
                      </th>
                      <th style="min-width:200px;">Nom &amp; Prénom</th>
                      <th style="min-width:120px;">Matricule</th>
                      <th style="min-width:120px;">Niveau sém.</th>
                      <th style="min-width:100px;">Niveau école</th>
                      <th>Sexe</th>
                      <th v-for="(ev,i) in levelEvaluations" :key="ev.id" class="th-eval">
                        <div class="th-eval-inner">
                          <span class="eval-badge" :class="`eval-badge-${(i%6)+1}`">{{ i+1 }}</span>
                          <span class="th-eval-name" :title="ev.titre">
                            {{ ev.titre.length > 22 ? ev.titre.slice(0,22)+'…' : ev.titre }}
                          </span>
                        </div>
                      </th>
                      <th style="min-width:110px;">Conduite/20</th>
                      <th style="min-width:60px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="s in seminaristes"
                      :key="s.matricule_seminaire"
                      class="table-row"
                      :class="{ 'row-selected': selectedSems.has(s.matricule_seminaire) }">

                      <td class="td-check">
                        <input type="checkbox" class="chk"
                          :checked="selectedSems.has(s.matricule_seminaire)"
                          @change="toggleSem(s.matricule_seminaire)" />
                      </td>

                      <td>
                        <div class="person">
                          <div class="avatar" :style="`background:${avatarBg(s)};color:${avatarColor(s)};`">
                            {{ (s.prenom?.[0]||'')+(s.nom?.[0]||'') }}
                          </div>
                          <div class="person-info">
                            <span class="person-name">{{ s.prenom }} {{ s.nom }}</span>
                          </div>
                        </div>
                      </td>

                      <td><span class="mono-badge">{{ s.matricule_seminaire }}</span></td>
                      <td class="td-cell">{{ getNiveauLabel(s.niveau_seminaire) }}</td>
                      <td class="td-cell">{{ s.niveau_etude || '—' }}</td>

                      <td>
                        <span v-if="s.sexe" class="badge" :class="s.sexe==='M'?'b-blue':'b-pink'">{{ s.sexe }}</span>
                        <span v-else class="td-empty">—</span>
                      </td>

                      <td v-for="ev in levelEvaluations" :key="ev.id" class="td-eval">
                        <template v-if="isScoreDirty(s.matricule_seminaire, ev.id)">
                          <div class="score-edit-cell">
                            <input type="number" class="score-mini-input"
                              :value="dirtyScores[scoreKey(s.matricule_seminaire, ev.id)]?.score_obtenu ?? 0"
                              @input="onScoreInput(s, ev.id, 'score_obtenu', $event.target.value)"
                              min="0" step="0.5" />
                            <span class="score-sep">/</span>
                            <input type="number" class="score-mini-input"
                              :value="dirtyScores[scoreKey(s.matricule_seminaire, ev.id)]?.score_total ?? 0"
                              @input="onScoreInput(s, ev.id, 'score_total', $event.target.value)"
                              min="0" step="1" />
                            <button class="act act-results act-xs"
                              :disabled="!!savingScores[scoreKey(s.matricule_seminaire, ev.id)]"
                              @click="saveScore(s, ev.id)">
                              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                            <button class="act act-del act-xs"
                              @click="delete dirtyScores[scoreKey(s.matricule_seminaire, ev.id)]; dirtyScores={...dirtyScores}">
                              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                          </div>
                        </template>
                        <template v-else-if="getScore(s, ev.id)">
                          <div class="score-cell"
                            @dblclick="onScoreInput(s, ev.id, 'score_obtenu', getScore(s,ev.id).score_obtenu); onScoreInput(s, ev.id, 'score_total', getScore(s,ev.id).score_total)">
                            <span class="score-frac">{{ getScore(s,ev.id).score_obtenu }}/{{ getScore(s,ev.id).score_total }}</span>
                            <span class="badge" :class="getScoreClass(getScore(s,ev.id).score_pourcentage)">{{ getScore(s,ev.id).score_pourcentage }}%</span>
                            <span class="edit-hint">✎</span>
                          </div>
                        </template>
                        <template v-else>
                          <span class="td-empty score-add"
                            @click="onScoreInput(s, ev.id, 'score_obtenu', 0); onScoreInput(s, ev.id, 'score_total', 0)">+ Ajouter</span>
                        </template>
                      </td>

                      <td class="td-eval">
                        <div class="conduite-cell">
                          <input type="number" min="0" max="20" step="0.5" class="score-mini-input"
                            :value="isConduiteDirty(s) ? dirtyConduite[semKey(s)] : getConduite(s)"
                            @input="onConduiteInput(s, $event)"
                            :class="{ 'input-default': !getScore(s, 40) && !isConduiteDirty(s) }" />
                          <span v-if="!getScore(s, 40) && !isConduiteDirty(s)" class="default-badge">déf.</span>
                          <button v-if="isConduiteDirty(s)" class="act act-results act-xs"
                            :disabled="!!savingConduite[semKey(s)]" @click="saveConduiteRow(s)">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                          </button>
                        </div>
                      </td>

                      <td>
                        <div class="actions">
                          <button class="act act-view" @click="generateBulletinPDF(s)" :disabled="exporting" title="Bulletin PDF">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="level-summary-bar">
                <span class="summary-item"><span class="summary-val">{{ seminaristes.length }}</span><span class="summary-lbl">participant{{ seminaristes.length>1?'s':'' }}</span></span>
                <span class="summary-sep">·</span>
                <span class="summary-item"><span class="summary-val c-green">{{ seminaristes.filter(s => (s.sessions||[]).length > 0).length }}</span><span class="summary-lbl">avec au moins une session</span></span>
                <span class="summary-sep">·</span>
                <span class="summary-item"><span class="summary-val" style="color:#6366f1">{{ levelEvaluations.length }}</span><span class="summary-lbl">évaluation{{ levelEvaluations.length>1?'s':'' }}</span></span>
                <span v-if="selectedSems.size>0" class="summary-sep">·</span>
                <span v-if="selectedSems.size>0" class="summary-item"><span class="summary-val" style="color:#f59e0b">{{ selectedSems.size }}</span><span class="summary-lbl">sélectionné{{ selectedSems.size>1?'s':'' }}</span></span>
              </div>
            </div>

            <!-- ══ MODE EXPAND ══ -->
            <div v-if="seminaristes.length && displayMode === 'expand'" class="card">
              <div class="table-wrap">
                <table class="table level-table">
                  <thead>
                    <tr>
                      <th class="th-check"><input type="checkbox" class="chk" :checked="allSelected" @change="toggleSelectAll" /></th>
                      <th style="width:28px;"></th>
                      <th style="min-width:200px;">Nom &amp; Prénom</th>
                      <th style="min-width:120px;">Matricule</th>
                      <th style="min-width:120px;">Niveau sém.</th>
                      <th style="min-width:100px;">Niveau école</th>
                      <th>Sexe</th>
                      <th>Sessions</th>
                      <th>Conduite/20</th>
                      <th style="min-width:60px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="s in seminaristes" :key="s.matricule_seminaire">
                      <tr class="table-row expand-row"
                        :class="{ 'row-selected': selectedSems.has(s.matricule_seminaire), 'row-expanded': isExpanded(s.matricule_seminaire) }"
                        @click="toggleExpand(s.matricule_seminaire)">

                        <td class="td-check" @click.stop>
                          <input type="checkbox" class="chk" :checked="selectedSems.has(s.matricule_seminaire)" @change="toggleSem(s.matricule_seminaire)" />
                        </td>
                        <td class="td-chevron">
                          <span class="chevron" :class="{ open: isExpanded(s.matricule_seminaire) }">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                          </span>
                        </td>
                        <td>
                          <div class="person">
                            <div class="avatar" :style="`background:${avatarBg(s)};color:${avatarColor(s)};`">{{ (s.prenom?.[0]||'')+(s.nom?.[0]||'') }}</div>
                            <div class="person-info"><span class="person-name">{{ s.prenom }} {{ s.nom }}</span></div>
                          </div>
                        </td>
                        <td><span class="mono-badge">{{ s.matricule_seminaire }}</span></td>
                        <td class="td-cell">{{ getNiveauLabel(s.niveau_seminaire) }}</td>
                        <td class="td-cell">{{ s.niveau_etude || '—' }}</td>
                        <td>
                          <span v-if="s.sexe" class="badge" :class="s.sexe==='M'?'b-blue':'b-pink'">{{ s.sexe }}</span>
                          <span v-else class="td-empty">—</span>
                        </td>
                        <td>
                          <div v-if="(s.sessions||[]).filter(sess => Number(sess.evaluation_id) !== 40).length" class="sessions-inline">
                            <span v-for="sess in (s.sessions||[]).filter(sess => Number(sess.evaluation_id) !== 40)" :key="sess.session_id"
                              class="session-chip" :class="getScoreClass(sess.score_pourcentage)" :title="sess.evaluation_titre">
                              {{ sess.score_pourcentage }}%
                            </span>
                          </div>
                          <span v-else class="td-empty">Aucune session</span>
                        </td>
                        <td @click.stop>
                          <div class="conduite-cell">
                            <input type="number" min="0" max="20" step="0.5" class="score-mini-input"
                              :value="isConduiteDirty(s) ? dirtyConduite[semKey(s)] : getConduite(s)"
                              @input="onConduiteInput(s, $event)"
                              :class="{ 'input-default': !getScore(s, 40) && !isConduiteDirty(s) }" />
                            <span v-if="!getScore(s, 40) && !isConduiteDirty(s)" class="default-badge">déf.</span>
                            <button v-if="isConduiteDirty(s)" class="act act-results act-xs"
                              :disabled="!!savingConduite[semKey(s)]" @click="saveConduiteRow(s)">
                              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                          </div>
                        </td>
                        <td @click.stop>
                          <div class="actions">
                            <button class="act act-view" @click="generateBulletinPDF(s)" :disabled="exporting" title="Bulletin PDF">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            </button>
                          </div>
                        </td>
                      </tr>

                      <tr v-if="isExpanded(s.matricule_seminaire)" class="expand-detail-row">
                        <td colspan="10" class="expand-detail-cell">
                          <div class="sessions-detail">
                            <div v-if="!(s.sessions||[]).filter(sess => Number(sess.evaluation_id) !== 40).length" class="sessions-empty">
                              Aucune session d'évaluation enregistrée.
                            </div>
                            <table v-else class="sessions-table">
                              <thead><tr><th>Évaluation</th><th>Score</th><th>Pourcentage</th><th>Temps</th><th>Statut</th><th>Modifier</th></tr></thead>
                              <tbody>
                                <tr v-for="sess in (s.sessions||[]).filter(sess => Number(sess.evaluation_id) !== 40)" :key="sess.session_id" class="session-row">
                                  <td>
                                    <div class="sess-title-cell">
                                      <span class="sess-dot" :class="sess.statut==='termine'?'dot-present':'dot-draft'"></span>
                                      {{ sess.evaluation_titre }}
                                    </div>
                                  </td>
                                  <td>
                                    <template v-if="isScoreDirty(s.matricule_seminaire, sess.evaluation_id)">
                                      <div class="score-edit-cell">
                                        <input type="number" class="score-mini-input"
                                          :value="dirtyScores[scoreKey(s.matricule_seminaire, sess.evaluation_id)]?.score_obtenu ?? 0"
                                          @input="onScoreInput(s, sess.evaluation_id, 'score_obtenu', $event.target.value)" min="0" step="0.5" />
                                        <span class="score-sep">/</span>
                                        <input type="number" class="score-mini-input"
                                          :value="dirtyScores[scoreKey(s.matricule_seminaire, sess.evaluation_id)]?.score_total ?? 0"
                                          @input="onScoreInput(s, sess.evaluation_id, 'score_total', $event.target.value)" min="0" step="1" />
                                      </div>
                                    </template>
                                    <strong v-else class="sess-score">{{ sess.score_obtenu }}/{{ sess.score_total }}</strong>
                                  </td>
                                  <td><span class="badge" :class="getScoreClass(sess.score_pourcentage)">{{ sess.score_pourcentage }}%</span></td>
                                  <td class="td-cell">{{ formatTime(sess.temps_ecoule) }}</td>
                                  <td><span class="badge" :class="sess.statut==='termine'?'b-present':'b-draft'">{{ sess.statut === 'termine' ? 'Terminée' : 'En cours' }}</span></td>
                                  <td>
                                    <template v-if="isScoreDirty(s.matricule_seminaire, sess.evaluation_id)">
                                      <div class="actions">
                                        <button class="act act-results act-xs" :disabled="!!savingScores[scoreKey(s.matricule_seminaire, sess.evaluation_id)]" @click="saveScore(s, sess.evaluation_id)">
                                          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        </button>
                                        <button class="act act-del act-xs" @click="delete dirtyScores[scoreKey(s.matricule_seminaire, sess.evaluation_id)]; dirtyScores={...dirtyScores}">
                                          <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                      </div>
                                    </template>
                                    <button v-else class="act act-edit act-xs"
                                      @click.stop="onScoreInput(s, sess.evaluation_id, 'score_obtenu', sess.score_obtenu); onScoreInput(s, sess.evaluation_id, 'score_total', sess.score_total)">
                                      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                        </td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>

              <div class="level-summary-bar">
                <span class="summary-item"><span class="summary-val">{{ seminaristes.length }}</span><span class="summary-lbl">participant{{ seminaristes.length>1?'s':'' }}</span></span>
                <span class="summary-sep">·</span>
                <span class="summary-item"><span class="summary-val c-green">{{ seminaristes.filter(s => (s.sessions||[]).length > 0).length }}</span><span class="summary-lbl">avec au moins une session</span></span>
                <span class="summary-sep">·</span>
                <span class="summary-item"><span class="summary-val" style="color:#6366f1">{{ seminaristes.reduce((acc, s) => acc + (s.sessions||[]).filter(sess => Number(sess.evaluation_id) !== 40).length, 0) }}</span><span class="summary-lbl">sessions au total</span></span>
                <span v-if="expandedRows.size > 0" class="summary-sep">·</span>
                <span v-if="expandedRows.size > 0" class="summary-item"><span class="summary-val" style="color:#8b5cf6">{{ expandedRows.size }}</span><span class="summary-lbl">déplié{{ expandedRows.size>1?'s':'' }}</span></span>
              </div>
            </div>

          </template>
        </template>

      </template>
    </div>

    <!-- ═══ MODALS ═══ -->
    <Transition name="modal">
      <div v-if="showAddModal || showEditModal" class="overlay" @click.self="closeModal">
        <div class="modal modal-large">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="modal-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586l5.414 5.414V19a2 2 0 01-2 2z"/></svg></div>
              <div>
                <h3 class="modal-title">{{ showAddModal ? 'Créer une évaluation' : 'Modifier l\'évaluation' }}</h3>
                <p class="modal-mat">{{ showAddModal ? 'Nouvelle évaluation QCM' : `Édition de « ${selectedEval?.titre} »` }}</p>
              </div>
            </div>
            <button class="modal-close" @click="closeModal"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          </div>
          <div class="modal-body">
            <div class="modal-section-title">Informations générales</div>
            <div class="info-grid">
              <div class="info-item" style="grid-column:span 2"><label>Titre *</label><input v-model="form.titre" class="form-input" type="text" /></div>
              <div class="info-item"><label>Durée (minutes) *</label><input v-model="form.duree" class="form-input" type="number" min="5" max="180" /></div>
              <div class="info-item"><label>Description</label><input v-model="form.description" class="form-input" type="text" /></div>
            </div>
            <div class="modal-section-title" style="margin-top:18px;">Accès</div>
            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
              <label class="radio-label"><input type="radio" v-model="form.acces_type" value="libre" /><span><strong>Accès libre</strong></span></label>
              <label class="radio-label"><input type="radio" v-model="form.acces_type" value="restreint" /><span><strong>Accès restreint</strong></span></label>
            </div>
            <div v-if="form.acces_type==='restreint'" class="info-item">
              <label>Matricules autorisés</label>
              <textarea v-model="form.matricules_autorises" class="form-input form-textarea" rows="2"></textarea>
            </div>
            <div class="modal-section-title" style="margin-top:18px;">
              Questions QCM
              <button class="btn-primary" style="margin-left:auto;padding:5px 12px;font-size:12px;" @click="addQuestion">Ajouter</button>
            </div>
            <div v-if="form.questions.length===0" class="empty" style="padding:24px;background:#f9fafb;border-radius:10px;">Aucune question</div>
            <div v-for="(q,i) in form.questions" :key="i" class="question-card">
              <div class="question-head">
                <span class="question-num">Question {{ i+1 }}</span>
                <button class="act act-del" @click="removeQuestion(i)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>
              </div>
              <div class="info-item" style="margin-bottom:10px;"><label>Question *</label><textarea v-model="q.question" class="form-input form-textarea" rows="2"></textarea></div>
              <div class="info-grid">
                <div class="info-item"><label>Réponse A</label><input v-model="q.reponse_a" class="form-input" type="text" /></div>
                <div class="info-item"><label>Réponse B</label><input v-model="q.reponse_b" class="form-input" type="text" /></div>
                <div class="info-item"><label>Réponse C</label><input v-model="q.reponse_c" class="form-input" type="text" /></div>
                <div class="info-item"><label>Réponse D</label><input v-model="q.reponse_d" class="form-input" type="text" /></div>
                <div class="info-item"><label>Bonne réponse</label><select v-model="q.bonne_reponse" class="form-input"><option value="">…</option><option>A</option><option>B</option><option>C</option><option>D</option></select></div>
                <div class="info-item"><label>Points</label><input v-model.number="q.points" class="form-input" type="number" min="1" max="10" /></div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-outline" @click="closeModal">Annuler</button>
            <button class="btn-primary" :disabled="submitting||form.questions.length===0" @click="showAddModal?addEvaluation():updateEvaluation()">
              <div v-if="submitting" class="spinner" style="width:14px;height:14px;border-width:2px;"></div>
              {{ showAddModal?'Créer':'Enregistrer' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="modal">
      <div v-if="showResultsModal && results" class="overlay" @click.self="closeModal">
        <div class="modal modal-xl">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="modal-icon" style="background:rgba(16,185,129,.12);color:#10b981;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg></div>
              <div><h3 class="modal-title">Résultats</h3><p class="modal-mat">{{ selectedEval?.titre }}</p></div>
            </div>
            <button class="modal-close" @click="closeModal"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          </div>
          <div class="modal-body">
            <div class="kpi-row" style="margin-bottom:18px;">
              <div class="kpi-card" style="gap:10px;padding:14px 16px;"><div class="kpi-data"><span class="kpi-val" style="font-size:18px;">{{ results.total_participants }}</span><span class="kpi-label">Participants</span></div></div>
              <div class="kpi-card" style="gap:10px;padding:14px 16px;"><div class="kpi-data"><span class="kpi-val" style="font-size:18px;color:#8b5cf6;">{{ results.moyenne }}</span><span class="kpi-label">Moyenne</span></div></div>
              <div class="kpi-card" style="gap:10px;padding:14px 16px;"><div class="kpi-data"><span class="kpi-val" style="font-size:18px;color:#f59e0b;">{{ results.meilleur_score }}</span><span class="kpi-label">Meilleur score</span></div></div>
              <div class="kpi-card" style="gap:10px;padding:14px 16px;"><div class="kpi-data"><span class="kpi-val" style="font-size:18px;">{{ results.taux_reussite }}%</span><span class="kpi-label">Taux réussite</span></div></div>
            </div>
            <div class="table-wrap">
              <table class="table">
                <thead><tr><th>Matricule</th><th>Nom</th><th>Sexe</th><th>Niveau étude</th><th>Score QCM</th><th>%</th><th>Temps</th><th>Date</th></tr></thead>
                <tbody>
                  <tr v-if="!results.participants?.length"><td colspan="8" class="empty">Aucun participant</td></tr>
                  <tr v-for="p in results.participants" :key="p.matricule" class="table-row">
                    <td class="td-cell" style="font-family:monospace;font-size:12px;">{{ p.matricule }}</td>
                    <td class="td-cell">{{ p.nom }} {{ p.prenom }}</td>
                    <td class="td-cell">{{ p.sexe||'—' }}</td>
                    <td class="td-cell">{{ p.niveau_etude||'—' }}</td>
                    <td><strong>{{ p.score_obtenu }}/{{ p.score_total }}</strong></td>
                    <td><span class="badge" :class="getScoreClass(p.score_pourcentage)">{{ p.score_pourcentage }}%</span></td>
                    <td class="td-cell">{{ formatTime(p.temps_ecoule) }}</td>
                    <td class="td-cell" style="font-size:11.5px;color:#9ca3af;">{{ formatDate(p.completed_at) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer"><button class="btn-outline" @click="closeModal">Fermer</button></div>
        </div>
      </div>
    </Transition>

    <Transition name="modal">
      <div v-if="showQRModal" class="overlay" @click.self="closeModal">
        <div class="modal" style="width:360px;">
          <div class="modal-header">
            <div class="modal-ident">
              <div class="modal-icon" style="background:rgba(139,92,246,.12);color:#8b5cf6;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div>
              <div><h3 class="modal-title">Code QR</h3><p class="modal-mat">{{ selectedEval?.titre }}</p></div>
            </div>
            <button class="modal-close" @click="closeModal"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
          </div>
          <div class="modal-body" style="display:flex;flex-direction:column;align-items:center;gap:14px;">
            <div class="qr-wrap"><img v-if="qrImageURL" :src="qrImageURL" alt="QR Code" class="qr-img" /><div v-else class="spinner" style="margin:30px auto;"></div></div>
            <div class="qr-url">{{ qrURL }}</div>
          </div>
          <div class="modal-footer">
            <button class="btn-outline" @click="navigator.clipboard.writeText(qrURL).then(()=>showToast('success','Lien copié !'))">Copier le lien</button>
            <a v-if="qrImageURL" :href="qrImageURL" :download="`qr-${selectedEval?.id}.png`" class="btn-primary" style="text-decoration:none;">Télécharger</a>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Toast -->
    <Transition name="toast">
      <div v-if="toast.show" class="toast" :class="'toast-'+toast.type">
        <svg v-if="toast.type==='success'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        <span>{{ toast.message }}</span>
        <button style="background:none;border:none;cursor:pointer;padding:0;display:flex;" @click="toast.show=false">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
* { box-sizing: border-box; }
.page { min-height: 100vh; background: #eef0f8; font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif; }
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 20px; max-width: 1600px; width: 100%; margin: 0 auto; }

.state-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 20px; color: #6b7280; font-size: 14px; }
.state-error { color: #ef4444; }
.state-error code { font-size: 11px; background: rgba(239,68,68,.07); padding: 4px 10px; border-radius: 6px; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6b7280; padding-top: 10px; }
.bc-active { color: #111; font-weight: 560; }
.export-banner { display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.18); border-radius: 10px; font-size: 12.5px; color: #6366f1; }

.page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 720; color: #111; letter-spacing: -.03em; margin-bottom: 3px; }
.page-sub   { font-size: 13px; color: #9ca3af; }

.kpi-row  { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.kpi-icon  { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-blue   { background: rgba(99,102,241,.1);  color: #6366f1; }
.kpi-green  { background: rgba(16,185,129,.1);  color: #10b981; }
.kpi-amber  { background: rgba(245,158,11,.1);  color: #f59e0b; }
.kpi-violet { background: rgba(139,92,246,.1);  color: #8b5cf6; }
.kpi-data  { display: flex; flex-direction: column; }
.kpi-val   { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.kpi-label { font-size: 11.5px; color: #9ca3af; font-weight: 440; margin-top: 3px; }
.c-green { color: #10b981; }

.tabs-bar { display: flex; gap: 6px; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 12px; padding: 5px; box-shadow: 0 1px 3px rgba(0,0,0,.04); align-self: flex-start; }
.tab { display: flex; align-items: center; gap: 7px; padding: 7px 16px; border-radius: 8px; border: none; background: transparent; font-size: 13px; font-weight: 480; color: #6b7280; cursor: pointer; font-family: inherit; transition: background .13s, color .13s; }
.tab:hover:not(.active) { background: #f3f4f6; color: #111; }
.tab.active { background: #6366f1; color: #fff; font-weight: 580; }

.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.search-wrap  { position: relative; width: 320px; }
.search-icon  { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border-radius: 50%; cursor: pointer; color: #6b7280; }
.search-input { width: 100%; height: 38px; padding: 0 34px 0 34px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #111; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.search-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.toolbar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.filter-sel { height: 38px; padding: 0 10px; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #374151; font-family: inherit; background: #fff; outline: none; }

.btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 570; font-family: inherit; cursor: pointer; box-shadow: 0 2px 8px rgba(99,102,241,.3); transition: background .18s; }
.btn-primary:hover  { background: #4f46e5; }
.btn-primary:disabled { opacity: .45; cursor: default; }
.btn-outline { display: flex; align-items: center; gap: 7px; padding: 9px 14px; background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); border-radius: 10px; font-size: 13px; font-weight: 480; font-family: inherit; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.btn-outline:hover    { background: #f9fafb; }
.btn-outline:disabled { opacity: .45; cursor: default; }
.btn-sm { padding: 6px 11px !important; font-size: 11.5px !important; }

/* ── Boutons bulletins classement ── */
.btn-bulletin-all {
  display: flex; align-items: center; gap: 7px;
  padding: 7px 14px;
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff; border: none; border-radius: 9px;
  font-size: 12px; font-weight: 620; font-family: inherit;
  cursor: pointer; box-shadow: 0 2px 8px rgba(16,185,129,.35);
  transition: opacity .15s;
}
.btn-bulletin-all:hover { opacity: .88; }
.btn-bulletin-all:disabled { opacity: .4; cursor: default; }

.btn-bulletin-row {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 10px;
  background: rgba(16,185,129,.1); color: #059669;
  border: 1px solid rgba(16,185,129,.2); border-radius: 7px;
  font-size: 11.5px; font-weight: 600; font-family: inherit;
  cursor: pointer; white-space: nowrap;
  transition: background .13s, color .13s;
}
.btn-bulletin-row:hover { background: rgba(16,185,129,.2); color: #047857; }
.btn-bulletin-row:disabled { opacity: .4; cursor: default; }

.display-mode-toggle { display: flex; background: #f3f4f6; border-radius: 9px; padding: 3px; gap: 2px; }
.mode-btn { display: flex; align-items: center; gap: 5px; padding: 5px 10px; border: none; border-radius: 7px; background: transparent; font-size: 11.5px; font-weight: 480; color: #6b7280; cursor: pointer; font-family: inherit; transition: background .13s, color .13s; }
.mode-btn:hover:not(.active) { background: #e9eaec; color: #374151; }
.mode-btn.active { background: #fff; color: #6366f1; font-weight: 620; box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,.07); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 630; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
.table-row { border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.table-row:last-child { border-bottom: none; }
.table-row:hover { background: #fafbff; }
.table td { padding: 10px 14px; vertical-align: middle; }
.td-cell { color: #374151; white-space: nowrap; }

.ev-title-cell { display: flex; align-items: center; gap: 10px; }
.ev-icon { width: 34px; height: 34px; background: rgba(99,102,241,.1); color: #6366f1; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ev-name { font-size: 13.5px; font-weight: 570; color: #111; }
.ev-sub  { font-size: 11.5px; color: #9ca3af; }

.person { display: flex; align-items: center; gap: 10px; }
.avatar { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; font-weight: 700; }
.person-info { display: flex; flex-direction: column; gap: 1px; }
.person-name { font-size: 13px; font-weight: 580; color: #111; }

.mono-badge { font-family: monospace; font-size: 11.5px; color: #6366f1; background: rgba(99,102,241,.07); padding: 2px 7px; border-radius: 5px; white-space: nowrap; }

.statut-wrap { display: flex; align-items: center; gap: 6px; }
.dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-present { background: #10b981; }
.dot-absent  { background: #ef4444; }
.dot-draft   { background: #f59e0b; }
.badge { display: inline-flex; align-items: center; font-size: 11px; font-weight: 610; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
.b-present { background: rgba(16,185,129,.1);  color: #059669; }
.b-absent  { background: rgba(239,68,68,.1);   color: #dc2626; }
.b-draft   { background: rgba(245,158,11,.1);  color: #b45309; }
.b-orange  { background: rgba(249,115,22,.1);  color: #ea580c; }
.b-blue    { background: rgba(59,130,246,.1);  color: #2563eb; }
.b-pink    { background: rgba(236,72,153,.1);  color: #be185d; }

.actions { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.act { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; cursor: pointer; border: 1px solid transparent; transition: background .13s; }
.act:disabled { opacity: .4; cursor: default; }
.act-view    { background: rgba(99,102,241,.08);  color: #6366f1; border-color: rgba(99,102,241,.15); }
.act-view:hover    { background: rgba(99,102,241,.15); }
.act-results { background: rgba(16,185,129,.08);  color: #10b981; border-color: rgba(16,185,129,.15); }
.act-results:hover { background: rgba(16,185,129,.15); }
.act-excel   { background: rgba(59,130,246,.08);  color: #3b82f6; border-color: rgba(59,130,246,.15); }
.act-excel:hover   { background: rgba(59,130,246,.15); }
.act-edit    { background: rgba(245,158,11,.08);  color: #f59e0b; border-color: rgba(245,158,11,.18); }
.act-edit:hover    { background: rgba(245,158,11,.15); }
.act-dup     { background: rgba(139,92,246,.08); color: #8b5cf6; border-color: rgba(139,92,246,.15); }
.act-dup:hover     { background: rgba(139,92,246,.15); }
.act-del     { background: rgba(239,68,68,.08);  color: #ef4444; border-color: rgba(239,68,68,.15); }
.act-del:hover     { background: rgba(239,68,68,.15); }

.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; }
.pag-info strong { color: #374151; }
.pag-pages { display: flex; align-items: center; gap: 3px; }
.pag-ellipsis { font-size: 12px; color: #9ca3af; padding: 0 4px; }
.pag-btn { min-width: 30px; height: 30px; padding: 0 6px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; font-size: 12px; font-family: inherit; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pag-btn:hover:not(:disabled) { background: #f3f4f6; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
.pag-btn:disabled { opacity: .35; cursor: default; }
.per-page { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #9ca3af; }
.per-page-select { border: 1px solid rgba(0,0,0,.09); border-radius: 7px; padding: 4px 8px; font-size: 12px; font-family: inherit; color: #374151; background: #fff; cursor: pointer; }
.empty { text-align: center; padding: 48px; color: #9ca3af; font-size: 13.5px; }

.level-picker-wrap  { display: flex; flex-direction: column; align-items: center; gap: 20px; padding: 20px 0; }
.level-picker-label { font-size: 14px; color: #6b7280; display: flex; align-items: center; gap: 10px; }
.level-picker-grid  { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; width: 100%; }
.level-picker-btn   { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 18px 12px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 14px; cursor: pointer; font-family: inherit; transition: border-color .15s, box-shadow .15s, background .15s; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.level-picker-btn:hover { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); background: #fafbff; }
.level-picker-code  { font-size: 16px; font-weight: 720; color: #6366f1; letter-spacing: -.02em; }
.level-picker-name  { font-size: 11px; color: #9ca3af; text-align: center; line-height: 1.3; }

.annee-badge { font-size: 11px; font-weight: 650; background: rgba(99,102,241,.1); color: #6366f1; padding: 2px 9px; border-radius: 20px; }

.level-topbar       { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.level-topbar-title { display: flex; align-items: center; gap: 8px; }
.level-title { font-size: 15px; font-weight: 680; color: #111; }
.level-code  { font-size: 11px; color: #9ca3af; font-family: monospace; background: #f3f4f6; padding: 2px 7px; border-radius: 5px; }

.eval-legend       { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 12px; }
.eval-legend-title { font-weight: 650; color: #6b7280; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.eval-legend-item  { display: flex; align-items: center; gap: 6px; color: #374151; }
.eval-badge { width: 18px; height: 18px; border-radius: 5px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; }
.eval-badge-1 { background: rgba(99,102,241,.15);  color: #4f46e5; }
.eval-badge-2 { background: rgba(16,185,129,.15);  color: #059669; }
.eval-badge-3 { background: rgba(245,158,11,.15);  color: #b45309; }
.eval-badge-4 { background: rgba(239,68,68,.15);   color: #dc2626; }
.eval-badge-5 { background: rgba(139,92,246,.15);  color: #7c3aed; }
.eval-badge-6 { background: rgba(59,130,246,.15);  color: #2563eb; }

.level-table .th-check, .level-table .td-check { width: 40px; }
.th-eval { background: #fafafa; }
.th-eval-inner { display: flex; align-items: center; gap: 6px; }
.th-eval-name { font-size: 11px; color: #374151; max-width: 140px; overflow: hidden; text-overflow: ellipsis; }
.td-eval { padding: 8px 14px !important; }
.td-empty { color: #d1d5db; font-size: 12px; }

.score-frac { font-size: 12.5px; font-weight: 600; color: #374151; white-space: nowrap; }

.row-selected { background: rgba(99,102,241,.04) !important; }
.chk { width: 14px; height: 14px; accent-color: #6366f1; cursor: pointer; }

.expand-row { cursor: pointer; user-select: none; }
.expand-row:hover { background: #f5f6ff; }
.row-expanded { background: rgba(99,102,241,.03) !important; }

.td-chevron { width: 28px; padding: 0 4px 0 8px !important; }
.chevron { display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: #f3f4f6; transition: transform .2s, background .13s; }
.chevron.open { transform: rotate(90deg); background: rgba(99,102,241,.1); color: #6366f1; }

.sessions-inline { display: flex; flex-wrap: wrap; gap: 4px; }
.session-chip { font-size: 11px; font-weight: 650; padding: 2px 7px; border-radius: 12px; cursor: default; }

.expand-detail-row { background: #f9fafb; }
.expand-detail-cell { padding: 0 !important; }
.sessions-detail { padding: 12px 20px 16px 52px; }
.sessions-empty { font-size: 12px; color: #9ca3af; padding: 12px 0; }

.sessions-table { width: 100%; border-collapse: collapse; font-size: 12.5px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
.sessions-table th { padding: 8px 14px; text-align: left; font-size: 10.5px; font-weight: 660; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; background: #f3f4f6; border-bottom: 1px solid rgba(0,0,0,.06); }
.session-row { border-bottom: 1px solid rgba(0,0,0,.04); }
.session-row:last-child { border-bottom: none; }
.session-row:hover { background: #fafbff; }
.session-row td { padding: 9px 14px; vertical-align: middle; }

.sess-title-cell { display: flex; align-items: center; gap: 8px; font-weight: 540; color: #111; }
.sess-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.sess-score { font-size: 13px; font-family: monospace; color: #374151; }

.level-summary-bar  { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 20px; border-top: 1px solid rgba(0,0,0,.05); background: #fafafa; font-size: 12px; }
.summary-item { display: flex; align-items: baseline; gap: 5px; }
.summary-val  { font-size: 15px; font-weight: 680; color: #111; }
.summary-lbl  { color: #9ca3af; }
.summary-sep  { color: #e5e7eb; }

.coeff-bar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.coeff-bar-label { font-size: 11px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .06em; flex-shrink: 0; }
.coeff-items { display: flex; gap: 10px; flex-wrap: wrap; flex: 1; }
.coeff-item { display: flex; flex-direction: column; align-items: center; gap: 3px; }
.coeff-ev-name { font-size: 10px; color: #6b7280; max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; }
.coeff-input { width: 52px; height: 28px; border: 1px solid rgba(0,0,0,.1); border-radius: 7px; font-size: 13px; font-weight: 600; text-align: center; color: #6366f1; background: #f9fafb; outline: none; font-family: inherit; }
.coeff-input:focus { border-color: #6366f1; background: #fff; }
.coeff-input:disabled { color: #9ca3af; background: #f3f4f6; }
.coeff-tag { font-size: 9px; font-weight: 700; color: #8b5cf6; margin-left: 3px; }

.btn-calc { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; box-shadow: 0 3px 12px rgba(99,102,241,.35); white-space: nowrap; flex-shrink: 0; transition: opacity .15s; }
.btn-calc:hover { opacity: .9; }

.score-edit-cell { display: flex; align-items: center; gap: 3px; }
.score-mini-input { width: 42px; height: 26px; border: 1px solid #6366f1; border-radius: 6px; font-size: 12px; font-weight: 600; text-align: center; color: #111; background: #fff; outline: none; font-family: monospace; padding: 0 3px; }
.score-mini-input:focus { box-shadow: 0 0 0 2px rgba(99,102,241,.2); }
.input-default { border-color: #f59e0b !important; color: #b45309 !important; background: rgba(245,158,11,.05) !important; }
.score-sep { font-size: 12px; color: #9ca3af; }
.act-xs { width: 24px !important; height: 24px !important; border-radius: 6px !important; }
.score-cell { position: relative; display: flex; align-items: center; gap: 6px; cursor: pointer; border-radius: 6px; padding: 2px 4px; transition: background .13s; }
.score-cell:hover { background: rgba(99,102,241,.06); }
.edit-hint { font-size: 10px; color: #9ca3af; opacity: 0; transition: opacity .13s; }
.score-cell:hover .edit-hint { opacity: 1; }
.score-add { cursor: pointer; font-size: 11px; color: #6366f1; opacity: .6; transition: opacity .13s; }
.score-add:hover { opacity: 1; }
.conduite-cell { display: flex; align-items: center; gap: 5px; }
.default-badge { font-size: 9px; font-weight: 700; color: #b45309; background: rgba(245,158,11,.12); padding: 1px 5px; border-radius: 4px; white-space: nowrap; }

.calc-card { margin-top: 0; }
.calc-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid rgba(0,0,0,.06); background: #f9fafb; flex-wrap: wrap; gap: 10px; }
.calc-title { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 680; color: #111; }
.rang-badge { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 8px; font-size: 12px; font-weight: 700; background: #f3f4f6; color: #374151; }
.rang-1 { background: linear-gradient(135deg, #ffd700, #f59e0b); color: #fff; box-shadow: 0 2px 6px rgba(245,158,11,.4); }
.rang-2 { background: linear-gradient(135deg, #c0c0c0, #9ca3af); color: #fff; }
.rang-3 { background: linear-gradient(135deg, #cd7f32, #b45309); color: #fff; }
.row-gold   { background: rgba(245,158,11,.04) !important; }
.row-silver { background: rgba(156,163,175,.04) !important; }
.row-bronze { background: rgba(180,83,9,.04) !important; }

.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); padding: 20px; }
.modal { background: #fff; border-radius: 18px; width: 540px; max-width: 100%; max-height: 90vh; box-shadow: 0 32px 80px rgba(0,0,0,.18); overflow: hidden; display: flex; flex-direction: column; }
.modal-large { width: 680px; }
.modal-xl    { width: 900px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-shrink: 0; }
.modal-ident  { display: flex; align-items: center; gap: 12px; }
.modal-icon   { width: 40px; height: 40px; background: rgba(99,102,241,.1); color: #6366f1; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.modal-title  { font-size: 15px; font-weight: 680; color: #111; margin: 0 0 2px; }
.modal-mat    { font-size: 11.5px; color: #9ca3af; margin: 0; }
.modal-close  { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.modal-close:hover { background: #f3f4f6; }
.modal-body   { padding: 22px 24px; overflow-y: auto; flex: 1; }
.modal-section-title { font-size: 10px; font-weight: 720; color: #9ca3af; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,.05); display: flex; align-items: center; justify-content: space-between; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
.info-item { display: flex; flex-direction: column; gap: 5px; }
.info-item label { font-size: 9.5px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .08em; }
.form-input  { height: 36px; padding: 0 10px; border: 1px solid rgba(0,0,0,.1); border-radius: 8px; font-size: 13px; color: #111; font-family: inherit; outline: none; background: #f9fafb; }
.form-input:focus { border-color: #6366f1; background: #fff; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.form-textarea { height: auto; padding: 8px 10px; resize: vertical; }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 16px 24px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-shrink: 0; flex-wrap: wrap; }
.radio-label { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: #374151; cursor: pointer; }
.radio-label input { margin-top: 2px; accent-color: #6366f1; }
.question-card { background: #f9fafb; border: 1px solid rgba(0,0,0,.07); border-radius: 12px; padding: 16px; margin-bottom: 12px; }
.question-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.question-num  { font-size: 12px; font-weight: 700; color: #6366f1; letter-spacing: .04em; text-transform: uppercase; }

.qr-wrap { width: 220px; height: 220px; border-radius: 14px; border: 1px solid rgba(0,0,0,.08); overflow: hidden; display: flex; align-items: center; justify-content: center; }
.qr-img  { width: 100%; height: 100%; object-fit: contain; }
.qr-url  { font-family: monospace; font-size: 11px; color: #6b7280; background: #f3f4f6; padding: 6px 12px; border-radius: 8px; word-break: break-all; text-align: center; }

.toast { position: fixed; bottom: 24px; right: 24px; z-index: 200; display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #fff; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,.14); font-size: 13px; color: #111; min-width: 260px; border-left: 4px solid #6366f1; }
.toast-success { border-left-color: #10b981; }
.toast-error   { border-left-color: #ef4444; }
.toast-success svg { color: #10b981; }
.toast-error   svg { color: #ef4444; }

.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from, .modal-leave-to { opacity: 0; transform: scale(0.96) translateY(10px); }
.toast-enter-active, .toast-leave-active { transition: opacity .25s, transform .25s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(12px); }

@media (max-width: 900px) { .kpi-row { grid-template-columns: 1fr 1fr; } .info-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .content { padding: 10px 12px; } .kpi-row { grid-template-columns: 1fr 1fr; } .toolbar { flex-direction: column; align-items: stretch; } .search-wrap { width: 100%; } .page-header { flex-direction: column; } .tabs-bar { width: 100%; } .tab { flex: 1; justify-content: center; } }
</style>