<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { jsPDF } from 'jspdf'
import * as XLSX from 'xlsx'

// ── State ──────────────────────────────────────────────────────────────────
const search         = ref('')
const isModalOpen    = ref(false)
const selectedSeminariste = ref(null)
const activeFilter   = ref('tous')
const datePresence   = ref(new Date().toISOString().slice(0, 10))
const savingId       = ref(null)
const exporting      = ref(false)
const selectedSeminairistes = ref([])

// Pagination
const page           = ref(1)
const perPage        = ref(25)
const totalFiltered  = ref(0)
const totalPages     = ref(1)

// API state
const loading        = ref(true)
const error          = ref(null)
const anneeActive    = ref(null)
const stats          = ref({})
const presences      = ref({ resume: {}, historique: [] })
const seminairistes  = ref([])

// ── API ────────────────────────────────────────────────────────────────────
const API_URL = 'https://api.aeemci-ce.ci/senafoi/seminaristes.php'

async function fetchData(date, q = '', p = 1) {
  loading.value = true
  error.value   = null
  try {
    const params = new URLSearchParams({
      date:     date,
      search:   q,
      page:     p,
      per_page: perPage.value,
    })
    if (activeFilter.value !== 'tous') {
      params.set('statut', activeFilter.value)
    }

    const res  = await fetch(`${API_URL}?${params}`)
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    if (!data.success) throw new Error(data.error || 'Erreur API')

    anneeActive.value = data.annee_active
    stats.value       = data.stats || {}
    presences.value   = data.presences || { resume: {}, historique: [] }

    const pg         = data.pagination || {}
    totalFiltered.value = parseInt(pg.total_filtered ?? 0)
    totalPages.value    = parseInt(pg.total_pages    ?? 1)
    page.value          = parseInt(pg.page           ?? p)

    seminairistes.value = (data.seminaristes || []).map(s => ({
      id:              s.id,
      nom:             `${s.prenom} ${s.nom}`,
      prenom:          s.prenom,
      nomFamille:      s.nom,
      matricule:       s.matricule_seminaire || s.id_seminaire || `SEM-${s.id}`,
      avatar:          initiales(`${s.prenom} ${s.nom}`),
      dortoir:         s.dortoir              || 'Non assigné',
      niveauSeminaire: s.niveau_seminaire     || '—',
      niveauEcole:     s.niveau_etude         || s.niveau_actuel || '—',
      ville:           s.secretariat_regional || '—',
      contact:         s.contact              || '—',
      statut:          s.presence_statut      || 'absent',
      presence_note:   s.presence_note        || '',
      sexe:            s.sexe,
      photo:           s.photo,
      malade:          s.malade,
      detail_malade:   s.detail_malade,
      contact_parent:  s.contact_parent       || '—',
      sous_comite:     s.sous_comite          || '—',
      qualite:         s.qualite              || '—',
      taille_tshirt:   s.taille_tshirt        || '—',
      car_transport:   s.car_transport        || '—',
      statut_paiement: s.statut_paiement      || '—',
      ref_paiement:    s.ref_paiement         || '—',
      somme_paye:      s.somme_paye           || '—',
      created_at:      s.created_at           || '',
      _raw: s,
    }))
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(() => fetchData(datePresence.value, search.value, 1))

let _debounceTimer = null
watch(search, (q) => {
  clearTimeout(_debounceTimer)
  _debounceTimer = setTimeout(() => {
    page.value = 1
    fetchData(datePresence.value, q, 1)
  }, 350)
})

function onDateChange() {
  page.value = 1
  fetchData(datePresence.value, search.value, 1)
}

function setFilter(f) {
  activeFilter.value = f
  page.value = 1
  fetchData(datePresence.value, search.value, 1)
}

function goToPage(p) {
  if (p < 1 || p > totalPages.value) return
  fetchData(datePresence.value, search.value, p)
}

function onPerPageChange() {
  page.value = 1
  fetchData(datePresence.value, search.value, 1)
}

const visiblePages = computed(() => {
  const total = totalPages.value
  const cur   = page.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  for (let i = cur - 1; i <= cur + 1; i++) {
    if (i > 0 && i <= total) pages.add(i)
  }
  return Array.from(pages).sort((a, b) => a - b)
})

// ── Sélection ──────────────────────────────────────────────────────────────
const isAllSelected = computed(() =>
  seminairistes.value.length > 0 && selectedSeminairistes.value.length === seminairistes.value.length
)

function isSelected(id) {
  return selectedSeminairistes.value.some(s => s.id === id)
}

async function exportExcel() {
  if (seminairistes.value.length === 0) return

  const data = seminairistes.value.map(s => ({
    'Matricule':        s.matricule,
    'Prénom':           s.prenom,
    'Nom':              s.nomFamille,
    'Sexe':             s.sexe === 'M' ? 'Masculin' : 'Féminin',
    'Contact':          s.contact,
    'Contact Parent':   s.contact_parent,
    'Niveau Séminaire': s.niveauSeminaire,
    'Niveau École':     s.niveauEcole,
    'Ville':            s.ville,
    'Sous-comité':      s.sous_comite,
    'Qualité':          s.qualite,
    'T-shirt':          s.taille_tshirt,
    'Dortoir':          s.dortoir,
    'Car Transport':    s.car_transport,
    'Statut Paiement':  s.statut_paiement,
    'Somme Payée':      s.somme_paye,
    'Réf. Paiement':    s.ref_paiement,
    'Statut Présence':  s.statut === 'present' ? 'Présent' : 'Absent',
    'Date Inscription': formatDate(s.created_at),
  }))

  const workbook  = XLSX.utils.book_new()
  const worksheet = XLSX.utils.json_to_sheet(data)

  // Largeurs des colonnes
  worksheet['!cols'] = [
    { wch: 14 }, { wch: 15 }, { wch: 15 }, { wch: 10 }, { wch: 15 },
    { wch: 16 }, { wch: 18 }, { wch: 16 }, { wch: 18 }, { wch: 15 },
    { wch: 12 }, { wch: 10 }, { wch: 20 }, { wch: 20 }, { wch: 16 },
    { wch: 14 }, { wch: 20 }, { wch: 14 }, { wch: 18 },
  ]

  XLSX.utils.book_append_sheet(workbook, worksheet, `SENAFOI ${anneeActive.value || ''}`)

  const filename = `seminaristes_senafoi_${datePresence.value}.xlsx`
  XLSX.writeFile(workbook, filename)
}

function toggleSelect(s) {
  const idx = selectedSeminairistes.value.findIndex(x => x.id === s.id)
  if (idx > -1) selectedSeminairistes.value.splice(idx, 1)
  else selectedSeminairistes.value.push(s)
}

function toggleSelectAll() {
  if (isAllSelected.value) selectedSeminairistes.value = []
  else selectedSeminairistes.value = [...seminairistes.value]
}

// ── Marquer présence (POST) ────────────────────────────────────────────────
async function marquerPresent(id) {
  const s = seminairistes.value.find(x => x.id === id)
  if (!s) return
  const newStatut = s.statut === 'present' ? 'absent' : 'present'
  s.statut = newStatut
  savingId.value = id
  try {
    const res = await fetch(API_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        seminariste_id: id,
        statut:         newStatut,
        date:           datePresence.value,
        marque_par:     'admin',
      }),
    })
    const data = await res.json()
    if (!data.success) throw new Error(data.message)
    if (data.resume_jour) {
      const totalInscrits = seminairistes.value.length
      presences.value.resume = {
        ...presences.value.resume,
        total_presents:        data.resume_jour.total_presents,
        total_absents_pointes: data.resume_jour.total_absents,
        total_pointes:         data.resume_jour.total_pointes,
        taux_presence: totalInscrits
          ? Math.round(data.resume_jour.total_presents / totalInscrits * 100)
          : 0,
      }
    }
  } catch (e) {
    s.statut = newStatut === 'present' ? 'absent' : 'present'
    console.error('Erreur présence:', e.message)
  } finally {
    savingId.value = null
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────
function initiales(nom) {
  return nom.trim().split(/\s+/).map(p => p[0]?.toUpperCase() || '').join('').slice(0, 2)
}

const PALETTE = [
  '#6366f1','#ef4444','#10b981','#f59e0b',
  '#8b5cf6','#3b82f6','#f97316','#14b8a6',
  '#ec4899','#06b6d4','#84cc16','#a855f7',
]
function avatarColor(avatar) {
  let h = 0
  for (let c of avatar) h = (h * 31 + c.charCodeAt(0)) & 0xffff
  return PALETTE[h % PALETTE.length]
}

function formatMontant(n, devise) {
  return new Intl.NumberFormat('fr-FR').format(n || 0) + ' ' + (devise || 'XOF')
}

function formatDate(dateString) {
  if (!dateString) return '—'
  return new Date(dateString).toLocaleDateString('fr-FR', {
    year: 'numeric', month: 'long', day: 'numeric'
  })
}

// ── Computed ───────────────────────────────────────────────────────────────
const filtered = computed(() => seminairistes.value)

const resumeJour = computed(() => {
  const r = presences.value.resume || {}
  return {
    total_inscrits:        parseInt(r.total_inscrits        ?? 0),
    total_presents:        parseInt(r.total_presents        ?? 0),
    total_absents_pointes: parseInt(r.total_absents_pointes ?? 0),
    total_non_pointes:     parseInt(r.total_non_pointes     ?? 0),
    total_pointes:         parseInt(r.total_pointes         ?? 0),
    taux_presence:         parseFloat(r.taux_presence       ?? 0),
  }
})

const historique   = computed(() => presences.value.historique || [])
const statsSexe    = computed(() => stats.value.par_sexe         || [])
const statsNiveau  = computed(() => stats.value.par_niveau        || [])
const statsDortoir = computed(() => stats.value.par_dortoir       || [])
const statsVille   = computed(() => stats.value.par_ville         || [])
const statsTshirt  = computed(() => stats.value.par_taille_tshirt || [])
const totalSomme   = computed(() => stats.value.total_somme_payee || { total_global: 0, devise_paiement: 'XOF' })
const statsAnciens = computed(() => stats.value.anciens_participants || {})

const countPresents = computed(() => resumeJour.value.total_presents)
const countAbsents  = computed(() => resumeJour.value.total_absents_pointes)
const countTotal    = computed(() => resumeJour.value.total_inscrits)

// ── Actions modales ────────────────────────────────────────────────────────
function openModal(s)  { selectedSeminariste.value = { ...s }; isModalOpen.value = true }
function closeModal()  { isModalOpen.value = false }

// ── Badge — Individuel ─────────────────────────────────────────────────────
function imprimerBadge(s) {
  const color = avatarColor(s.avatar)
  const win = window.open('', '_blank', 'width=420,height=340')
  win.document.write(`<!DOCTYPE html><html><head><title>Badge — ${s.nom}</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f3f4f6;display:flex;align-items:center;justify-content:center;min-height:100vh}
    .card{background:#fff;border-radius:18px;padding:32px 36px;width:340px;box-shadow:0 8px 32px rgba(0,0,0,.13);text-align:center}
    .logo{font-size:10px;font-weight:700;letter-spacing:.14em;color:#9ca3af;text-transform:uppercase;margin-bottom:20px}
    .av{width:60px;height:60px;border-radius:14px;background:${color}18;color:${color};font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
    .name{font-size:19px;font-weight:750;color:#111;margin-bottom:2px}
    .mat{font-size:11px;color:#9ca3af;font-weight:500;letter-spacing:.04em;margin-bottom:4px}
    .lvl{font-size:12.5px;color:#6b7280;margin-bottom:20px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:left}
    .it label{font-size:9.5px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:2px}
    .it span{font-size:12.5px;color:#111;font-weight:530}
    .bar{height:4px;background:${color};border-radius:2px;margin-top:22px}
  </style></head><body>
  <div class="card">
    <div class="logo">SENAFOI ${anneeActive.value || ''}</div>
    <div class="av">${s.avatar}</div>
    <div class="name">${s.nom}</div>
    <div class="mat">${s.matricule}</div>
    <div class="lvl">${s.niveauSeminaire} · ${s.niveauEcole}</div>
    <div class="grid">
      <div class="it"><label>Dortoir</label><span>${s.dortoir}</span></div>
      <div class="it"><label>Ville</label><span>${s.ville}</span></div>
      <div class="it"><label>Contact</label><span>${s.contact}</span></div>
    </div>
    <div class="bar"></div>
  </div>
  <script>window.onload=()=>window.print()<\/script>
  </body></html>`)
  win.document.close()
}

// ── Badge PDF (individuel via jsPDF — même taille que fichier 2) ──────────
async function downloadBadgePDF(s) {
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [100, 130] })
    await _renderBadge(doc, s, 0, 0, 100, 130)
    doc.save(`badge_${s.prenom}_${s.nomFamille}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Badge PDF — Sélectionnés ───────────────────────────────────────────────
async function downloadSelectedBadges() {
  if (selectedSeminairistes.value.length === 0) return
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
    const bW = 100, bH = 130, bPR = 2, bPC = 2, bPP = bPR * bPC
    const mX = (210 - bPR * bW) / (bPR + 1), mY = (297 - bPC * bH) / (bPC + 1)
    let count = 0
    for (let i = 0; i < selectedSeminairistes.value.length; i++) {
      if (count > 0 && count % bPP === 0) doc.addPage()
      const row = Math.floor((count % bPP) / bPR)
      const col = (count % bPP) % bPR
      const x = mX + col * (bW + mX), y = mY + row * (bH + mY)
      await _renderBadge(doc, selectedSeminairistes.value[i], x, y, bW, bH)
      count++
    }
    doc.save(`badges_selectionnes_senafoi_${new Date().toISOString().slice(0,10)}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Badge PDF — Tous ───────────────────────────────────────────────────────
async function downloadAllBadges() {
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
    const bW = 100, bH = 130, bPR = 2, bPC = 2, bPP = bPR * bPC
    const mX = (210 - bPR * bW) / (bPR + 1), mY = (297 - bPC * bH) / (bPC + 1)
    let count = 0
    for (let i = 0; i < seminairistes.value.length; i++) {
      if (count > 0 && count % bPP === 0) doc.addPage()
      const row = Math.floor((count % bPP) / bPR)
      const col = (count % bPP) % bPR
      const x = mX + col * (bW + mX), y = mY + row * (bH + mY)
      await _renderBadge(doc, seminairistes.value[i], x, y, bW, bH)
      count++
    }
    doc.save(`tous_badges_senafoi_${new Date().toISOString().slice(0,10)}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Fonction interne : dessine un badge dans un doc jsPDF ─────────────────
async function _renderBadge(doc, s, x, y, width, height) {
  doc.saveGraphicsState()

  // Image de fond
  try {
    const bg = new Image()
    bg.crossOrigin = 'anonymous'
    bg.src = 'https://res.cloudinary.com/dqk65objc/image/upload/v1753396309/aeemci_seminaire_1753396301927_SENAFOI-BADGE.png.png'
    await new Promise((res, rej) => { bg.onload = res; bg.onerror = rej })
    doc.addImage(bg, 'PNG', x, y, width, height)
  } catch (e) { console.warn('Fond badge non chargé', e) }

  let yOff = y + 40.5

  // Photo
  if (s.photo) {
    try {
      const resp = await fetch(s.photo)
      const blob = await resp.blob()
      const b64  = await new Promise(res => {
        const r = new FileReader(); r.onloadend = () => res(r.result); r.readAsDataURL(blob)
      })
      await new Promise(resolve => {
        const img = new Image(); img.crossOrigin = 'anonymous'; img.src = b64
        img.onload = () => {
          const sz = 350
          const cv = document.createElement('canvas'); cv.width = cv.height = sz
          const ctx = cv.getContext('2d'); const r = 100
          ctx.beginPath()
          ctx.moveTo(r,0); ctx.lineTo(sz-r,0); ctx.quadraticCurveTo(sz,0,sz,r)
          ctx.lineTo(sz,sz-r); ctx.quadraticCurveTo(sz,sz,sz-r,sz)
          ctx.lineTo(r,sz); ctx.quadraticCurveTo(0,sz,0,sz-r)
          ctx.lineTo(0,r); ctx.quadraticCurveTo(0,0,r,0); ctx.closePath(); ctx.clip()
          ctx.drawImage(img,0,0,sz,sz)
          const size = 31.5
          doc.addImage(cv.toDataURL('image/png'), 'PNG', x+34.5, yOff, size, size+4, '', 'FAST')
          yOff += size + 4
          resolve()
        }
        img.onerror = () => resolve()
      })
    } catch (e) { yOff += 27 }
  }

  // Nom
  doc.setFontSize(14); doc.setFont('helvetica','bold'); doc.setTextColor(33,33,33)
  doc.text(`${s.prenom || ''} ${s.nomFamille || s.nom}`, x + width/2, yOff+6, { align:'center' })
  yOff += 12

  // Dortoir
  doc.setFontSize(9); doc.setFont('helvetica','bold'); doc.setTextColor(80,80,80)
  doc.text(`${s.dortoir}`, x+(width/2)-16, yOff, { align:'left' })
  yOff += 4.1

  // Niveau
  let texteNiveau = s.niveauSeminaire
  if (texteNiveau === 'TEST_ENTREE') texteNiveau = '.'
  else if (texteNiveau.length < 6) texteNiveau = 'Niveau ' + texteNiveau
  doc.setTextColor(255,165,0)
  doc.text(texteNiveau, x+(width/2)-16, yOff, { align:'left' })
  yOff += 5

  // Car transport
  doc.setFontSize(9); doc.setTextColor(80,80,80)
  doc.text(`${s.car_transport || ''}`, x+(width/2)-13, yOff, { align:'left' })
  yOff += 4

  // Matricule
  doc.setFontSize(12); doc.setFont('helvetica','bold'); doc.setTextColor(80,80,80)
  doc.text(`${s.matricule}`, x+width/2+1, yOff+1.3, { align:'center' })
  yOff += 3

  // QR Code
  try {
    const qrData = await QRCode.toDataURL(`${s.matricule}`, { width:185, margin:0 })
    doc.addImage(qrData, 'PNG', x+(width-8)/2+12.9, yOff+6.6, 16, 13)
  } catch (e) { console.warn('QR non chargé', e) }

  doc.restoreGraphicsState()
}

// ── Diplôme — Individuel (fenêtre d'impression) ────────────────────────────
function imprimerDiplome(s) {
  const win = window.open('', '_blank', 'width=900,height=680')
  win.document.write(`<!DOCTYPE html><html><head><title>Diplôme — ${s.nom}</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,sans-serif;background:#f9f5ef;display:flex;align-items:center;justify-content:center;min-height:100vh}
    .wrap{background:#fff;width:760px;padding:56px 72px;border:1px solid #e5e0d8;box-shadow:0 12px 48px rgba(0,0,0,.1);position:relative}
    .wrap::before{content:'';position:absolute;inset:12px;border:1.5px solid #d4b483;pointer-events:none}
    .corner{position:absolute;width:36px;height:36px}
    .tl{top:22px;left:22px;border-top:2px solid #d4b483;border-left:2px solid #d4b483}
    .tr{top:22px;right:22px;border-top:2px solid #d4b483;border-right:2px solid #d4b483}
    .bl{bottom:22px;left:22px;border-bottom:2px solid #d4b483;border-left:2px solid #d4b483}
    .br{bottom:22px;right:22px;border-bottom:2px solid #d4b483;border-right:2px solid #d4b483}
    .hd{text-align:center;margin-bottom:28px}
    .logo{font-size:10px;font-weight:700;letter-spacing:.2em;color:#9ca3af;text-transform:uppercase;margin-bottom:14px}
    .title{font-family:'Playfair Display',Georgia,serif;font-size:36px;color:#111;letter-spacing:-.02em;line-height:1.1}
    .sub{font-family:'Playfair Display',Georgia,serif;font-style:italic;font-size:13.5px;color:#6b7280;margin-top:6px}
    .sep{width:72px;height:2px;background:#d4b483;margin:22px auto}
    .body{text-align:center;line-height:1.9;color:#374151;font-size:14px}
    .dname{font-family:'Playfair Display',Georgia,serif;font-size:28px;color:#111;font-weight:700;margin:8px 0 4px}
    .details{display:flex;justify-content:center;gap:36px;margin:26px 0}
    .det label{font-size:9.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#9ca3af;display:block;margin-bottom:3px}
    .det span{font-size:13px;color:#111;font-weight:530}
    .signs{display:flex;justify-content:space-between;margin-top:44px}
    .sig{text-align:center}
    .sig-line{width:130px;height:1px;background:#374151;margin-bottom:5px}
    .sig-label{font-size:10.5px;color:#6b7280;letter-spacing:.04em}
  </style></head><body>
  <div class="wrap">
    <div class="corner tl"></div><div class="corner tr"></div>
    <div class="corner bl"></div><div class="corner br"></div>
    <div class="hd">
      <div class="logo">SENAFOI ${anneeActive.value || ''}</div>
      <div class="title">Certificat de Participation</div>
      <div class="sub">Décerné avec honneur</div>
    </div>
    <div class="sep"></div>
    <div class="body">
      <p>Il est certifié que</p>
      <div class="dname">${s.nom}</div>
      <p style="font-size:11px;color:#9ca3af;letter-spacing:.06em;margin:4px 0 6px">${s.matricule}</p>
      <p>a participé et complété avec succès le séminaire de formation</p>
    </div>
    <div class="details">
      <div class="det"><label>Niveau séminaire</label><span>${s.niveauSeminaire}</span></div>
      <div class="det"><label>Niveau école</label><span>${s.niveauEcole}</span></div>
      <div class="det"><label>Ville</label><span>${s.ville}</span></div>
    </div>
    <div class="signs">
      <div class="sig"><div class="sig-line"></div><div class="sig-label">Le Directeur</div></div>
      <div class="sig"><div class="sig-line"></div><div class="sig-label">Le Secrétaire Général</div></div>
    </div>
  </div>
  <script>window.onload=()=>window.print()<\/script>
  </body></html>`)
  win.document.close()
}

// ── Diplôme PDF — Individuel ───────────────────────────────────────────────
async function downloadDiplomePDF(s) {
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
    await _renderDiplome(doc, s)
    doc.save(`diplome_${s.prenom}_${s.nomFamille}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Diplôme PDF — Sélectionnés ─────────────────────────────────────────────
async function downloadSelectedDiplomes() {
  if (selectedSeminairistes.value.length === 0) return
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
    for (let i = 0; i < selectedSeminairistes.value.length; i++) {
      if (i > 0) doc.addPage()
      await _renderDiplome(doc, selectedSeminairistes.value[i])
    }
    doc.save(`diplomes_selectionnes_senafoi_${new Date().toISOString().slice(0,10)}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Diplôme PDF — Tous ─────────────────────────────────────────────────────
async function downloadAllDiplomes() {
  exporting.value = true
  try {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
    for (let i = 0; i < seminairistes.value.length; i++) {
      if (i > 0) doc.addPage()
      await _renderDiplome(doc, seminairistes.value[i])
    }
    doc.save(`tous_diplomes_senafoi_${new Date().toISOString().slice(0,10)}.pdf`)
  } catch (e) { console.error(e) }
  finally { exporting.value = false }
}

// ── Fonction interne : dessine un diplôme dans un doc jsPDF ───────────────
async function _renderDiplome(doc, s) {
  doc.saveGraphicsState()
  const pW = doc.internal.pageSize.getWidth()
  const pH = doc.internal.pageSize.getHeight()

  try {
    const bg = new Image(); bg.crossOrigin = 'anonymous'
    bg.src = 'https://res.cloudinary.com/r-sidence-meubl-e/image/upload/v1754585031/aeemci_seminaire_1754584970846_DIPLOME-1-SE.png.png'
    await new Promise((res, rej) => { bg.onload = res; bg.onerror = rej })
    doc.addImage(bg, 'PNG', 0, 0, pW, pH)
  } catch (e) { console.warn('Fond diplôme non chargé', e) }

  doc.setFontSize(40)
  doc.setFont('helvetica', 'bold')
  doc.setTextColor(134, 51, 15)
  doc.text(`${s.nomFamille || s.nom} ${s.prenom || ''}`, pW / 2, 110, { align: 'center' })

  doc.restoreGraphicsState()
}
</script>

<template>
  <div class="page" style="margin: -15px">

    <div class="content">

      <!-- ── Breadcrumb ── -->
      <div class="breadcrumb">
        <span class="bc-root">Séminaires</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
        <span class="bc-active">Séminaristes &amp; Présences</span>
      </div>

      <!-- ── Loading / Error ── -->
      <div v-if="loading" class="state-block">
        <div class="spinner"></div>
        <p>Chargement des données…</p>
      </div>

      <div v-else-if="error" class="state-block state-error">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <p>Impossible de charger les données</p>
        <code>{{ error }}</code>
      </div>

      <template v-else>

        <!-- ── Page Header ── -->
        <div class="page-header">
          <div>
            <h1 class="page-title">Séminaristes &amp; Présences</h1>
            <p class="page-sub">
              {{ resumeJour.total_inscrits }} participants inscrits · SENAFOI {{ anneeActive }}
            </p>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <!-- Badges -->
            <button
              class="btn-outline"
              :disabled="exporting || selectedSeminairistes.length === 0"
              @click="downloadSelectedBadges"
              title="Badges — séminaristes cochés"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
              </svg>
              Badges sél. ({{ selectedSeminairistes.length }})
            </button>
            <button
              class="btn-outline"
              :disabled="exporting"
              @click="downloadAllBadges"
              title="Tous les badges — page courante"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
              </svg>
              Tous les badges
            </button>
            <!-- Diplômes -->
            <button
              class="btn-outline"
              :disabled="exporting || selectedSeminairistes.length === 0"
              @click="downloadSelectedDiplomes"
              title="Diplômes — séminaristes cochés"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="8" r="6"/>
                <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
              </svg>
              Diplômes sél. ({{ selectedSeminairistes.length }})
            </button>
            <button
              class="btn-outline"
              :disabled="exporting"
              @click="downloadAllDiplomes"
              title="Tous les diplômes — page courante"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="8" r="6"/>
                <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
              </svg>
              Tous les diplômes
            </button>
            <button
              class="btn-primary"
              onclick="window.open('https://www.aeemci-ce.ci/seminaristes', '_blank')"
            >
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Nouveau séminariste
            </button>
          </div>
        </div>

        <!-- Indicateur export en cours -->
        <div v-if="exporting" class="export-banner">
          <div class="spinner" style="width:18px;height:18px;border-width:2px;"></div>
          Génération PDF en cours…
        </div>

        <!-- ── KPI Cards ── -->
        <div class="kpi-row">
          <div class="kpi-card">
            <div class="kpi-icon kpi-blue">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ resumeJour.total_inscrits }}</span>
              <span class="kpi-label">Total inscrits</span>
            </div>
            <div class="kpi-sexe" v-if="statsSexe.length">
              <span v-for="g in statsSexe" :key="g.sexe" class="kpi-trend kpi-trend-neutral">
                {{ g.sexe === 'M' ? '♂' : '♀' }} {{ g.total }}
              </span>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-green">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <polyline points="20 6 9 17 4 12"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val c-green">{{ resumeJour.total_presents }}</span>
              <span class="kpi-label">Présents</span>
            </div>
            <span class="kpi-trend kpi-trend-up">{{ resumeJour.taux_presence }}% présence</span>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-red">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val c-red">{{ resumeJour.total_absents_pointes }}</span>
              <span class="kpi-label">Absents</span>
            </div>
            <span class="kpi-trend kpi-trend-down">
              {{ resumeJour.total_inscrits > 0 ? (100 - resumeJour.taux_presence).toFixed(1) : 0 }}% du total
            </span>
          </div>

          <div class="kpi-card">
            <div class="kpi-icon kpi-amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
              </svg>
            </div>
            <div class="kpi-data">
              <span class="kpi-val">{{ formatMontant(totalSomme.total_global, totalSomme.devise_paiement) }}</span>
              <span class="kpi-label">Total collecté</span>
            </div>
            <span class="kpi-trend kpi-trend-neutral">Paiements Wave</span>
          </div>
        </div>

        <!-- ── Résumé présences du jour ── -->
        <div class="presence-resume-bar">
          <div class="pr-date">
            <label class="pr-date-label">Date de pointage</label>
            <input
              type="date"
              v-model="datePresence"
              class="date-input"
              @change="onDateChange"
            />
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val">{{ resumeJour.total_inscrits }}</span>
            <span class="pr-label">Inscrits</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val c-green">{{ resumeJour.total_presents }}</span>
            <span class="pr-label">Pointés présents</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val c-red">{{ resumeJour.total_absents_pointes }}</span>
            <span class="pr-label">Pointés absents</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val" style="color:#9ca3af">{{ resumeJour.total_non_pointes }}</span>
            <span class="pr-label">Non pointés</span>
          </div>
          <div class="pr-sep"></div>
          <div class="pr-item">
            <span class="pr-val">{{ resumeJour.taux_presence }}%</span>
            <span class="pr-label">Taux présence</span>
          </div>
          <div class="pr-progress-wrap">
            <div class="pr-progress-bar">
              <div
                class="pr-progress-fill"
                :style="{ width: resumeJour.taux_presence + '%' }"
              ></div>
            </div>
          </div>
        </div>

        <!-- ── Stats Anciens ── -->
        <div class="anciens-bar" v-if="statsAnciens.total_anciens_l_an_passe">
          <div class="anciens-item">
            <span class="anciens-val">{{ statsAnciens.total_anciens_l_an_passe }}</span>
            <span class="anciens-label">Anciens séminaristes (2025)</span>
          </div>
          <div class="anciens-sep"></div>
          <div class="anciens-item">
            <span class="anciens-val c-green">{{ statsAnciens.anciens_revenus_cette_annee }}</span>
            <span class="anciens-label">Revenus cette année</span>
          </div>
          <div class="anciens-sep"></div>
          <div class="anciens-item">
            <span class="anciens-val c-red">{{ statsAnciens.anciens_non_revenus }}</span>
            <span class="anciens-label">Non revenus</span>
          </div>
          <div class="anciens-sep"></div>
          <div class="anciens-item">
            <span class="anciens-val">{{ statsAnciens.taux_retour_anciens }}%</span>
            <span class="anciens-label">Taux de retour</span>
          </div>
          <div class="anciens-sep"></div>
          <div class="anciens-item">
            <span class="anciens-val">{{ statsAnciens.taux_anciens_vs_total }}%</span>
            <span class="anciens-label">Anciens / total</span>
          </div>
        </div>

        <!-- ── Toolbar ── -->
        <div class="toolbar">
          <div class="search-wrap">
            <svg class="search-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
              v-model="search"
              class="search-input"
              placeholder="Rechercher par nom, matricule, ville…"
            />
            <span v-if="search" class="search-clear" @click="search = ''">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </span>
          </div>
          <div class="toolbar-right">
            <div class="filter-tabs">
              <button class="ftab" :class="{ active: activeFilter === 'tous' }" @click="setFilter('tous')">
                Tous ({{ countTotal }})
              </button>
              <button class="ftab" :class="{ active: activeFilter === 'present' }" @click="setFilter('present')">
                Présents ({{ countPresents }})
              </button>
              <button class="ftab" :class="{ active: activeFilter === 'absent' }" @click="setFilter('absent')">
                Absents ({{ countAbsents }})
              </button>
            </div>
            <button class="btn-outline" @click="exportExcel">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Exporter Excel
            </button>
          </div>
        </div>

        <!-- ── Bandeau recherche active ── -->
        <div v-if="search" class="search-banner">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          Recherche <strong>« {{ search }} »</strong> — {{ totalFiltered }} résultat{{ totalFiltered > 1 ? 's' : '' }} trouvé{{ totalFiltered > 1 ? 's' : '' }}
          <button class="search-banner-clear" @click="search = ''">Effacer</button>
        </div>

        <!-- ── Table Card ── -->
        <div class="card">
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th class="th-check">
                    <input type="checkbox" class="chk" :checked="isAllSelected" @change="toggleSelectAll" />
                  </th>
                  <th>
                    Séminariste
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                      <polyline points="6 9 12 15 18 9"/>
                    </svg>
                  </th>
                  <th>Dortoir</th>
                  <th>Niv. Séminaire</th>
                  <th>Niv. École</th>
                  <th>Ville</th>
                  <th>Contact</th>
                  <th>Statut</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in filtered" :key="s.id" class="table-row" :class="{ 'row-selected': isSelected(s.id) }">
                  <td class="td-check">
                    <input type="checkbox" class="chk" :checked="isSelected(s.id)" @change="toggleSelect(s)" />
                  </td>

                  <td>
                    <div class="person">
                      <div
                        class="avatar"
                        :style="{ background: avatarColor(s.avatar) + '20', color: avatarColor(s.avatar) }"
                      >{{ s.avatar }}</div>
                      <div class="person-info">
                        <span class="person-name">{{ s.nom }}</span>
                        <span class="person-mat">{{ s.matricule }}</span>
                      </div>
                    </div>
                  </td>

                  <td><span class="dortoir-tag">{{ s.dortoir }}</span></td>
                  <td class="td-cell">{{ s.niveauSeminaire }}</td>
                  <td class="td-cell">{{ s.niveauEcole }}</td>
                  <td class="td-cell">{{ s.ville }}</td>
                  <td class="td-cell td-contact">{{ s.contact }}</td>

                  <td>
                    <div class="statut-wrap">
                      <span class="dot" :class="s.statut === 'present' ? 'dot-present' : 'dot-absent'"></span>
                      <span class="badge" :class="s.statut === 'present' ? 'b-present' : 'b-absent'">
                        {{ s.statut === 'present' ? 'Présent' : 'Absent' }}
                      </span>
                      <span v-if="s.malade" class="badge b-malade" :title="s.detail_malade">🤒 Malade</span>
                    </div>
                  </td>

                  <td>
                    <div class="actions">
                      <button class="act act-view" @click="openModal(s)" title="Voir le profil">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                      </button>
                      <!-- Badge individuel -->
                      <button class="act act-badge" @click="downloadBadgePDF(s)" title="Badge PDF" :disabled="exporting">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                          <rect x="2" y="7" width="20" height="14" rx="2"/>
                          <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
                        </svg>
                      </button>
                      <!-- Diplôme individuel -->
                      <button class="act act-diplome" @click="downloadDiplomePDF(s)" title="Diplôme PDF" :disabled="exporting">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                          <circle cx="12" cy="8" r="6"/>
                          <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
                        </svg>
                      </button>
                      <!-- Toggle présence -->
                      <button
                        class="act-toggle"
                        :class="{ 'is-present': s.statut === 'present', 'is-saving': savingId === s.id }"
                        @click="marquerPresent(s.id)"
                        :disabled="savingId === s.id"
                        :title="s.statut === 'present' ? 'Marquer absent' : 'Marquer présent'"
                      >
                        <span class="toggle-track">
                          <span class="toggle-thumb"></span>
                        </span>
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="filtered.length === 0 && !loading">
                  <td colspan="9" class="empty">
                    <template v-if="search">Aucun résultat pour « {{ search }} »</template>
                    <template v-else>Aucun séminariste dans cette catégorie</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- ── Pagination ── -->
          <div class="pagination">
            <span class="pag-info">
              Affichage de
              <strong>{{ seminairistes.length }}</strong>
              sur
              <strong>{{ totalFiltered }}</strong>
              séminariste{{ totalFiltered > 1 ? 's' : '' }}
              <template v-if="search"> · filtre « {{ search }} »</template>
              <template v-if="selectedSeminairistes.length > 0">
                · <strong style="color:#6366f1">{{ selectedSeminairistes.length }} cochés</strong>
              </template>
            </span>

            <div class="pag-pages">
              <button class="pag-btn" :disabled="page <= 1" @click="goToPage(page - 1)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                  <polyline points="15 18 9 12 15 6"/>
                </svg>
              </button>
              <template v-for="(p, i) in visiblePages" :key="p">
                <span v-if="i > 0 && p - visiblePages[i - 1] > 1" class="pag-ellipsis">…</span>
                <button class="pag-btn" :class="{ active: p === page }" @click="goToPage(p)">{{ p }}</button>
              </template>
              <button class="pag-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                  <polyline points="9 18 15 12 9 6"/>
                </svg>
              </button>
            </div>

            <div class="per-page">
              <span>Lignes par page</span>
              <select class="per-page-select" v-model="perPage" @change="onPerPageChange">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
              </select>
            </div>
          </div>
        </div>

      </template>
    </div>

    <!-- ── Modal profil séminariste (enrichie) ── -->
    <Transition name="modal">
      <div v-if="isModalOpen" class="overlay" @click.self="closeModal">
        <div class="modal modal-large">
          <div class="modal-header">
            <div class="modal-ident" v-if="selectedSeminariste">
              <!-- Photo si disponible, sinon initiales -->
              <div
                v-if="selectedSeminariste.photo"
                class="modal-avatar-photo"
              >
                <img :src="selectedSeminariste.photo" :alt="`Photo de ${selectedSeminariste.nom}`" />
              </div>
              <div
                v-else
                class="modal-avatar"
                :style="{ background: avatarColor(selectedSeminariste.avatar) + '20', color: avatarColor(selectedSeminariste.avatar) }"
              >{{ selectedSeminariste.avatar }}</div>
              <div>
                <h3 class="modal-title">{{ selectedSeminariste.nom }}</h3>
                <p class="modal-mat">{{ selectedSeminariste.matricule }}</p>
                <div class="statut-wrap" style="margin-top:4px;">
                  <span class="dot" :class="selectedSeminariste.statut === 'present' ? 'dot-present' : 'dot-absent'"></span>
                  <span class="badge" :class="selectedSeminariste.statut === 'present' ? 'b-present' : 'b-absent'">
                    {{ selectedSeminariste.statut === 'present' ? 'Présent' : 'Absent' }}
                  </span>
                  <span v-if="selectedSeminariste.malade" class="badge b-malade">🤒 {{ selectedSeminariste.detail_malade || 'Malade' }}</span>
                </div>
              </div>
            </div>
            <button class="modal-close" @click="closeModal">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
              </svg>
            </button>
          </div>

          <div class="modal-body" v-if="selectedSeminariste">

            <!-- Section 1 : Informations personnelles -->
            <div class="modal-section-title">Informations personnelles</div>
            <div class="info-grid">
              <div class="info-item">
                <label>Sexe</label>
                <span>{{ selectedSeminariste.sexe === 'M' ? 'Masculin' : selectedSeminariste.sexe === 'F' ? 'Féminin' : '—' }}</span>
              </div>
              <div class="info-item">
                <label>Contact</label>
                <span>{{ selectedSeminariste.contact }}</span>
              </div>
              <div class="info-item">
                <label>Contact parent</label>
                <span>{{ selectedSeminariste.contact_parent }}</span>
              </div>
              <div class="info-item">
                <label>Date inscription</label>
                <span>{{ formatDate(selectedSeminariste.created_at) }}</span>
              </div>
            </div>

            <!-- Section 2 : Séminaire -->
            <div class="modal-section-title" style="margin-top:18px;">Informations séminaire</div>
            <div class="info-grid">
              <div class="info-item">
                <label>Niv. Séminaire</label>
                <span>{{ selectedSeminariste.niveauSeminaire }}</span>
              </div>
              <div class="info-item">
                <label>Niv. École</label>
                <span>{{ selectedSeminariste.niveauEcole }}</span>
              </div>
              <div class="info-item">
                <label>Ville / Secrétariat</label>
                <span>{{ selectedSeminariste.ville }}</span>
              </div>
              <div class="info-item">
                <label>Sous-comité</label>
                <span>{{ selectedSeminariste.sous_comite }}</span>
              </div>
              <div class="info-item">
                <label>Qualité</label>
                <span>{{ selectedSeminariste.qualite }}</span>
              </div>
              <div class="info-item">
                <label>Taille T-shirt</label>
                <span>{{ selectedSeminariste.taille_tshirt }}</span>
              </div>
            </div>

            <!-- Section 3 : Logistique -->
            <div class="modal-section-title" style="margin-top:18px;">Logistique</div>
            <div class="info-grid">
              <div class="info-item">
                <label>Dortoir</label>
                <span>{{ selectedSeminariste.dortoir }}</span>
              </div>
              <div class="info-item">
                <label>Car transport</label>
                <span>{{ selectedSeminariste.car_transport }}</span>
              </div>
            </div>

            <!-- Section 4 : Paiement -->
            <div class="modal-section-title" style="margin-top:18px;">Paiement</div>
            <div class="info-grid">
              <div class="info-item">
                <label>Statut paiement</label>
                <span>
                  <span
                    class="badge"
                    :class="selectedSeminariste.statut_paiement === 'PAYE' ? 'b-present'
                      : selectedSeminariste.statut_paiement === 'EN_ATTENTE' ? 'b-malade' : 'b-absent'"
                  >
                    {{ selectedSeminariste.statut_paiement === 'PAYE' ? 'Payé'
                       : selectedSeminariste.statut_paiement === 'EN_ATTENTE' ? 'En attente' : (selectedSeminariste.statut_paiement || '—') }}
                  </span>
                </span>
              </div>
              <div class="info-item">
                <label>Somme payée</label>
                <span>{{ selectedSeminariste.somme_paye }}</span>
              </div>
              <div class="info-item">
                <label>Réf. paiement</label>
                <span style="font-family:monospace;font-size:12px;">{{ selectedSeminariste.ref_paiement }}</span>
              </div>
            </div>

            <!-- Section 5 : Présence -->
            <div class="modal-section-title" style="margin-top:18px;">Présence — {{ datePresence }}</div>
            <div class="info-grid">
              <div class="info-item">
                <label>Statut du jour</label>
                <div class="statut-wrap">
                  <span class="dot" :class="selectedSeminariste.statut === 'present' ? 'dot-present' : 'dot-absent'"></span>
                  <span class="badge" :class="selectedSeminariste.statut === 'present' ? 'b-present' : 'b-absent'">
                    {{ selectedSeminariste.statut === 'present' ? 'Présent' : 'Absent' }}
                  </span>
                </div>
              </div>
              <div class="info-item" v-if="selectedSeminariste.presence_note">
                <label>Note présence</label>
                <span>{{ selectedSeminariste.presence_note }}</span>
              </div>
            </div>

          </div>

          <div class="modal-footer" v-if="selectedSeminariste">
            <!-- Badge -->
            <button class="act act-badge" @click="downloadBadgePDF(selectedSeminariste)" style="padding:8px 14px;font-size:12px;" :disabled="exporting">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/>
              </svg>
              Badge PDF
            </button>
            <!-- Diplôme -->
            <button class="act act-diplome" @click="downloadDiplomePDF(selectedSeminariste)" style="padding:8px 14px;font-size:12px;" :disabled="exporting">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="8" r="6"/>
                <path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
              </svg>
              Diplôme PDF
            </button>
            <!-- Badge impression navigateur -->
            <button class="act act-badge" @click="imprimerBadge(selectedSeminariste)" style="padding:8px 14px;font-size:12px;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                <rect x="6" y="14" width="12" height="8"/>
              </svg>
              Imprimer badge
            </button>
            <button class="btn-primary" @click="closeModal">Fermer</button>
          </div>
        </div>
      </div>
    </Transition>

  </div>
</template>

<style scoped>
/* ── Reset & Base ── */
* { box-sizing: border-box; }
.page {
  min-height: 100vh;
  background: #eef0f8;
  font-family: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
  display: flex;
  flex-direction: column;
}

/* ── Content ── */
.content { padding: 20px 20px 40px; display: flex; flex-direction: column; gap: 20px; max-width: 1600px; width: 100%; margin: 0 auto; }

/* ── Loading / Error ── */
.state-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 20px; color: #6b7280; font-size: 14px; }
.state-error { color: #ef4444; }
.state-error code { font-size: 11px; background: rgba(239,68,68,.07); padding: 4px 10px; border-radius: 6px; color: #dc2626; }
.spinner { width: 36px; height: 36px; border: 3px solid rgba(99,102,241,.15); border-top-color: #6366f1; border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Breadcrumb ── */
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6b7280; padding-top: 10px; }
.bc-root { cursor: pointer; }
.bc-root:hover { color: #6366f1; }
.bc-active { color: #111; font-weight: 560; }

/* ── Export banner ── */
.export-banner { display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: rgba(99,102,241,.07); border: 1px solid rgba(99,102,241,.18); border-radius: 10px; font-size: 12.5px; color: #6366f1; }

/* ── Page Header ── */
.page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 22px; font-weight: 720; color: #111; letter-spacing: -.03em; margin-bottom: 3px; }
.page-sub   { font-size: 13px; color: #9ca3af; font-weight: 430; }

/* ── KPI Row ── */
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi-card { background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; }
.kpi-icon { width: 40px; height: 40px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-blue  { background: rgba(99,102,241,.1);  color: #6366f1; }
.kpi-green { background: rgba(16,185,129,.1);  color: #10b981; }
.kpi-red   { background: rgba(239,68,68,.1);   color: #ef4444; }
.kpi-amber { background: rgba(245,158,11,.1);  color: #f59e0b; }
.kpi-data  { display: flex; flex-direction: column; flex: 1; }
.kpi-val   { font-size: 22px; font-weight: 730; color: #111; letter-spacing: -.04em; line-height: 1; }
.c-green   { color: #10b981; }
.c-red     { color: #ef4444; }
.kpi-label { font-size: 11.5px; color: #9ca3af; font-weight: 440; margin-top: 3px; }
.kpi-trend { font-size: 10.5px; font-weight: 570; padding: 2px 7px; border-radius: 20px; white-space: nowrap; }
.kpi-trend-up      { background: rgba(16,185,129,.1);  color: #10b981; }
.kpi-trend-down    { background: rgba(239,68,68,.08);  color: #ef4444; }
.kpi-trend-neutral { background: rgba(107,114,128,.08); color: #6b7280; }
.kpi-sexe { display: flex; gap: 4px; flex-wrap: wrap; }

/* ── Résumé présences du jour ── */
.presence-resume-bar { display: flex; align-items: center; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 14px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; gap: 12px; }
.pr-date { display: flex; flex-direction: column; gap: 4px; }
.pr-date-label { font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .07em; }
.date-input { height: 34px; padding: 0 10px; border: 1px solid rgba(0,0,0,.09); border-radius: 8px; font-size: 13px; color: #111; font-family: inherit; outline: none; cursor: pointer; background: #f9fafb; transition: border-color .15s; }
.date-input:focus { border-color: #6366f1; background: #fff; }
.pr-sep   { width: 1px; background: rgba(0,0,0,.07); align-self: stretch; }
.pr-item  { display: flex; flex-direction: column; align-items: center; gap: 2px; flex: 1; min-width: 70px; }
.pr-val   { font-size: 20px; font-weight: 720; color: #111; letter-spacing: -.03em; }
.pr-label { font-size: 10.5px; color: #9ca3af; font-weight: 460; text-align: center; }
.pr-progress-wrap { width: 100%; }
.pr-progress-bar  { height: 5px; background: #f3f4f6; border-radius: 3px; overflow: hidden; }
.pr-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); border-radius: 3px; transition: width .4s ease; }

/* ── Anciens bar ── */
.anciens-bar { display: flex; align-items: center; background: #fff; border: 1px solid rgba(0,0,0,.07); border-radius: 14px; padding: 14px 24px; box-shadow: 0 1px 3px rgba(0,0,0,.04); flex-wrap: wrap; gap: 12px; }
.anciens-item  { display: flex; flex-direction: column; align-items: center; gap: 2px; flex: 1; min-width: 80px; }
.anciens-val   { font-size: 20px; font-weight: 720; color: #111; letter-spacing: -.03em; }
.anciens-label { font-size: 10.5px; color: #9ca3af; font-weight: 460; text-align: center; }
.anciens-sep   { width: 1px; background: rgba(0,0,0,.07); align-self: stretch; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.search-wrap { position: relative; width: 320px; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; background: #e5e7eb; border-radius: 50%; cursor: pointer; color: #6b7280; transition: background .13s; }
.search-clear:hover { background: #d1d5db; color: #111; }
.search-input { width: 100%; height: 38px; padding: 0 34px 0 34px; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; font-size: 13px; color: #111; font-family: inherit; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: border-color .15s, box-shadow .15s; }
.search-input::placeholder { color: #9ca3af; }
.search-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
.toolbar-right { display: flex; align-items: center; gap: 10px; }
.filter-tabs { display: flex; background: #fff; border: 1px solid rgba(0,0,0,.08); border-radius: 10px; padding: 3px; gap: 2px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.ftab { padding: 5px 13px; font-size: 12.5px; font-weight: 480; color: #6b7280; border: none; background: transparent; border-radius: 7px; cursor: pointer; font-family: inherit; transition: background .13s, color .13s; }
.ftab.active { background: #6366f1; color: #fff; font-weight: 580; }
.ftab:hover:not(.active) { background: #f3f4f6; color: #111; }

/* ── Bandeau recherche active ── */
.search-banner { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(99,102,241,.06); border: 1px solid rgba(99,102,241,.15); border-radius: 10px; font-size: 12.5px; color: #374151; }
.search-banner strong { color: #6366f1; }
.search-banner-clear { margin-left: auto; padding: 3px 10px; font-size: 11.5px; font-family: inherit; background: #fff; border: 1px solid rgba(0,0,0,.1); border-radius: 6px; cursor: pointer; color: #6b7280; transition: background .13s; }
.search-banner-clear:hover { background: #f3f4f6; color: #111; }

/* ── Buttons ── */
.btn-primary { display: flex; align-items: center; gap: 7px; padding: 9px 18px; background: #6366f1; color: #fff; border: none; border-radius: 10px; font-size: 13px; font-weight: 570; font-family: inherit; cursor: pointer; letter-spacing: -.01em; box-shadow: 0 2px 8px rgba(99,102,241,.3); transition: background .18s, transform .12s, box-shadow .18s; }
.btn-primary:hover  { background: #4f46e5; box-shadow: 0 4px 14px rgba(99,102,241,.35); }
.btn-primary:active { transform: scale(0.97); }
.btn-outline { display: flex; align-items: center; gap: 7px; padding: 9px 14px; background: #fff; color: #374151; border: 1px solid rgba(0,0,0,.09); border-radius: 10px; font-size: 13px; font-weight: 480; font-family: inherit; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: background .15s; white-space: nowrap; }
.btn-outline:hover { background: #f9fafb; }
.btn-outline:disabled { opacity: .45; cursor: default; }

/* ── Card & Table ── */
.card { background: #fff; border-radius: 16px; border: 1px solid rgba(0,0,0,.07); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.table-wrap { overflow-x: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 13px; }
.table thead tr { background: #f9fafb; border-bottom: 1px solid rgba(0,0,0,.06); }
.table th { padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 630; color: #6b7280; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; user-select: none; }
.table th svg { vertical-align: middle; margin-left: 4px; opacity: .5; }
.th-check { width: 44px; }
.table-row { border-bottom: 1px solid rgba(0,0,0,.05); transition: background .12s; }
.table-row:last-child { border-bottom: none; }
.table-row:hover { background: #fafbff; }
.table-row.row-selected { background: rgba(99,102,241,.04); }
.table td { padding: 12px 16px; vertical-align: middle; }
.td-check { width: 44px; }
.chk { width: 14px; height: 14px; accent-color: #6366f1; cursor: pointer; }

/* Person */
.person       { display: flex; align-items: center; gap: 11px; }
.avatar       { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 730; flex-shrink: 0; letter-spacing: .02em; }
.person-info  { display: flex; flex-direction: column; gap: 1px; }
.person-name  { font-size: 13.5px; font-weight: 570; color: #111; white-space: nowrap; }
.person-mat   { font-size: 11px; color: #9ca3af; font-weight: 450; letter-spacing: .03em; }
.dortoir-tag  { display: inline-block; padding: 3px 9px; background: rgba(99,102,241,.08); color: #6366f1; border-radius: 6px; font-size: 11.5px; font-weight: 570; white-space: nowrap; }
.td-cell      { color: #374151; white-space: nowrap; font-size: 13px; }
.td-contact   { font-variant-numeric: tabular-nums; color: #6b7280; }

/* Statut */
.statut-wrap { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.dot         { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.dot-present { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,.2); }
.dot-absent  { background: #ef4444; box-shadow: 0 0 0 2px rgba(239,68,68,.2); }
.badge    { display: inline-flex; align-items: center; font-size: 11px; font-weight: 610; padding: 3px 9px; border-radius: 20px; letter-spacing: .02em; white-space: nowrap; }
.b-present { background: rgba(16,185,129,.1);  color: #059669; }
.b-absent  { background: rgba(239,68,68,.1);   color: #dc2626; }
.b-malade  { background: rgba(245,158,11,.1);  color: #b45309; }

/* Actions */
.actions { display: flex; align-items: center; gap: 4px; }
.act { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; cursor: pointer; border: 1px solid transparent; transition: background .13s, transform .1s; flex-shrink: 0; }
.act:disabled { opacity: .4; cursor: default; }
.act:active { transform: scale(0.93); }
.act-view    { background: rgba(99,102,241,.08);  color: #6366f1; border-color: rgba(99,102,241,.15); }
.act-view:hover { background: rgba(99,102,241,.15); }
.act-badge   { background: rgba(139,92,246,.08); color: #8b5cf6; border-color: rgba(139,92,246,.15); }
.act-badge:hover { background: rgba(139,92,246,.15); }
.act-diplome { background: rgba(245,158,11,.08); color: #f59e0b; border-color: rgba(245,158,11,.18); }
.act-diplome:hover { background: rgba(245,158,11,.15); }

/* Toggle */
.act-toggle { background: none; border: none; cursor: pointer; padding: 0; display: flex; align-items: center; margin-left: 2px; transition: opacity .2s; }
.act-toggle:disabled { opacity: .5; cursor: wait; }
.toggle-track { width: 34px; height: 18px; border-radius: 20px; background: #d1d5db; display: flex; align-items: center; padding: 2px; transition: background .2s; position: relative; }
.toggle-thumb { width: 14px; height: 14px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: transform .2s; position: absolute; left: 2px; }
.act-toggle.is-present .toggle-track { background: #10b981; }
.act-toggle.is-present .toggle-thumb { transform: translateX(16px); }
.act-toggle.is-saving .toggle-track  { background: #9ca3af; }

/* ── Pagination ── */
.pagination { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; gap: 10px; }
.pag-info { font-size: 12px; color: #9ca3af; }
.pag-info strong { color: #374151; }
.pag-pages { display: flex; align-items: center; gap: 3px; }
.pag-ellipsis { font-size: 12px; color: #9ca3af; padding: 0 4px; }
.pag-btn { min-width: 30px; height: 30px; padding: 0 6px; border-radius: 8px; border: 1px solid rgba(0,0,0,.08); background: #fff; font-size: 12px; font-family: inherit; color: #374151; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .13s; user-select: none; }
.pag-btn:hover:not(:disabled)  { background: #f3f4f6; }
.pag-btn.active { background: #6366f1; color: #fff; border-color: #6366f1; font-weight: 600; }
.pag-btn:disabled { opacity: .35; cursor: default; }
.per-page { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #9ca3af; }
.per-page-select { border: 1px solid rgba(0,0,0,.09); border-radius: 7px; padding: 4px 8px; font-size: 12px; font-family: inherit; color: #374151; background: #fff; cursor: pointer; }
.empty { text-align: center; padding: 48px; color: #9ca3af; font-size: 13.5px; }

/* ── Modal ── */
.overlay { position: fixed; inset: 0; background: rgba(17,17,16,.4); display: flex; align-items: center; justify-content: center; z-index: 100; backdrop-filter: blur(4px); }
.modal   { background: #fff; border-radius: 18px; width: 580px; max-width: calc(100vw - 40px); box-shadow: 0 32px 80px rgba(0,0,0,.18); overflow: hidden; display: flex; flex-direction: column; }
.modal-large { width: 640px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,.06); background: #fafafa; }
.modal-ident  { display: flex; align-items: center; gap: 12px; }
.modal-avatar { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 730; flex-shrink: 0; }
.modal-avatar-photo { width: 52px; height: 52px; border-radius: 14px; overflow: hidden; flex-shrink: 0; border: 2px solid rgba(0,0,0,.07); }
.modal-avatar-photo img { width: 100%; height: 100%; object-fit: cover; }
.modal-title  { font-size: 16px; font-weight: 680; color: #111; letter-spacing: -.02em; margin: 0 0 2px; }
.modal-mat    { font-size: 11.5px; color: #9ca3af; margin: 0; letter-spacing: .03em; }
.modal-close  { width: 32px; height: 32px; border-radius: 9px; border: 1px solid rgba(0,0,0,.09); background: #fff; color: #6b7280; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .13s; }
.modal-close:hover { background: #f3f4f6; color: #111; }
.modal-body   { padding: 22px 24px; max-height: 65vh; overflow-y: auto; }
.modal-section-title { font-size: 10px; font-weight: 720; color: #9ca3af; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,.05); }
.info-grid    { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 24px; }
.info-item    { display: flex; flex-direction: column; gap: 5px; }
.info-item label { font-size: 9.5px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: .08em; }
.info-item span  { font-size: 13.5px; color: #111; font-weight: 520; }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 16px 24px; border-top: 1px solid rgba(0,0,0,.06); background: #fafafa; flex-wrap: wrap; }

/* ── Transitions ── */
.modal-enter-active, .modal-leave-active { transition: opacity .2s, transform .2s; }
.modal-enter-from { opacity: 0; transform: scale(0.96) translateY(10px); }
.modal-leave-to   { opacity: 0; transform: scale(0.96) translateY(10px); }

/* ── Responsive ── */
@media (max-width: 1000px) {
  .kpi-row      { grid-template-columns: 1fr 1fr; }
  .anciens-sep  { display: none; }
  .pr-sep       { display: none; }
}
@media (max-width: 700px) {
  .content      { padding: 10px 16px; }
  .kpi-row      { grid-template-columns: 1fr 1fr; }
  .toolbar      { flex-direction: column; align-items: stretch; }
  .search-wrap  { width: 100%; }
  .kpi-trend    { display: none; }
  .anciens-bar  { justify-content: center; }
  .pagination   { flex-direction: column; align-items: center; }
  .page-header  { flex-direction: column; }
}
</style>