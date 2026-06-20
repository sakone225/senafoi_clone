import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

import DashboardLayout from '../layouts/DashboardLayout.vue'

import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import SeminarsView from '../views/SeminarsView.vue'
import ParticipantsView from '../views/ParticipantsView.vue'
import SpeakersView from '../views/SpeakersView.vue'
import RoomsView from '../views/RoomsView.vue'
import ReportsView from '../views/ReportsView.vue'
import SettingsView from '../views/SettingsView.vue'

import QuotaView from '../views/QuotaView.vue'
import CarsView from '../views/CarsView.vue'
import UsersView from '../views/UsersView.vue'

// Pages vides pour les sous-menus SENAFAD / SENAFI / SENAFOCI / SENACEF /
// SENASIP / SENAES / SENAMO / SENACREX — à remplacer par le vrai contenu
// quand chaque page sera prête.
import SenafadOption1View from '../views/sena/SenafadOption1View.vue'
import SenafadOption2View from '../views/sena/SenafadOption2View.vue'
import SenafiOption1View from '../views/sena/SenafiOption1View.vue'
import SenafiOption2View from '../views/sena/SenafiOption2View.vue'
import SenafociOption1View from '../views/sena/SenafociOption1View.vue'
import SenafociOption2View from '../views/sena/SenafociOption2View.vue'
import SenacefOption1View from '../views/sena/SenacefOption1View.vue'
import SenacefOption2View from '../views/sena/SenacefOption2View.vue'
import SenasipOption1View from '../views/sena/SenasipOption1View.vue'
import SenasipOption2View from '../views/sena/SenasipOption2View.vue'
import SenaesOption1View from '../views/sena/SenaesOption1View.vue'
import SenaesOption2View from '../views/sena/SenaesOption2View.vue'
import SenamoOption1View from '../views/sena/SenamoOption1View.vue'
import SenamoOption2View from '../views/sena/SenamoOption2View.vue'
import SenacrexOption1View from '../views/sena/SenacrexOption1View.vue'
import SenacrexOption2View from '../views/sena/SenacrexOption2View.vue'

export const accessibleRouteNames = [
  'dashboard',
  'seminars',
  'participants',
  'quota',
  'cars',
  'speakers',
  'rooms',
 
  'reports',
  'settings',
  'users',
]

// ---- Collapsible sidebar groups: SENAFAD, SENAFI, SENAFOCI, SENACEF,
// SENASIP, SENAES, SENAMO, SENACREX — each with "Option 1" / "Option 2".
// Route names here must match the children defined in AppLayout.vue
// ("<key>-option1" / "<key>-option2").
const senaModules = [
  { key: 'senafad', label: 'SENAFAD', views: [SenafadOption1View, SenafadOption2View] },
  { key: 'senafi', label: 'SENAFI', views: [SenafiOption1View, SenafiOption2View] },
  { key: 'senafoci', label: 'SENAFOCI', views: [SenafociOption1View, SenafociOption2View] },
  { key: 'senacef', label: 'SENACEF', views: [SenacefOption1View, SenacefOption2View] },
  { key: 'senasip', label: 'SENASIP', views: [SenasipOption1View, SenasipOption2View] },
  { key: 'senaes', label: 'SENAES', views: [SenaesOption1View, SenaesOption2View] },
  { key: 'senamo', label: 'SENAMO', views: [SenamoOption1View, SenamoOption2View] },
  { key: 'senacrex', label: 'SENACREX', views: [SenacrexOption1View, SenacrexOption2View] },
]

const senaRoutes = senaModules.flatMap((mod) => [
  {
    path: `${mod.key}/option-1`,
    name: `${mod.key}-option1`,
    component: mod.views[0],
    meta: { title: `${mod.label} — Option 1`, pageKey: `${mod.key}-option1` },
  },
  {
    path: `${mod.key}/option-2`,
    name: `${mod.key}-option2`,
    component: mod.views[1],
    meta: { title: `${mod.label} — Option 2`, pageKey: `${mod.key}-option2` },
  },
])

const senaRouteNames = senaModules.flatMap((mod) => [`${mod.key}-option1`, `${mod.key}-option2`])
accessibleRouteNames.push(...senaRouteNames)

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { requiresGuest: true },
  },
  {
    path: '/',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: DashboardView,
        meta: { title: 'Tableau de bord', pageKey: 'dashboard' },
      },
      {
        path: 'seminars',
        name: 'seminars',
        component: SeminarsView,
        meta: { title: 'Séminaires', pageKey: 'seminars' },
      },
      
    
      {
        path: 'quota',
        name: 'quota',
        component: QuotaView,
        meta: { title: 'Quota SENAFOI', pageKey: 'quota' },
      },
      {
        path: 'cars',
        name: 'cars',
        component: CarsView,
        meta: { title: 'Cars & quotas', pageKey: 'cars' },
      },
      {
        path: 'participants',
        name: 'participants',
        component: ParticipantsView,
        meta: { title: 'Participants', pageKey: 'participants' },
      },
      {
        path: 'speakers',
        name: 'speakers',
        component: SpeakersView,
        meta: { title: 'Intervenants', pageKey: 'speakers' },
      },
      {
        path: 'rooms',
        name: 'rooms',
        component: RoomsView,
        meta: { title: 'Salles', pageKey: 'rooms' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ReportsView,
        meta: { title: 'Rapports', pageKey: 'reports' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: SettingsView,
        meta: { title: 'Paramètres', pageKey: 'settings' },
      },
      {
        path: 'users',
        name: 'users',
        component: UsersView,
        meta: { title: 'Utilisateurs', pageKey: 'users' },
      },

      // SENAFAD / SENAFI / SENAFOCI / SENACEF / SENASIP / SENAES / SENAMO / SENACREX
      ...senaRoutes,
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.requiresGuest && auth.isAuthenticated) {
    return { name: auth.firstAccessibleRoute(accessibleRouteNames) || 'dashboard' }
  }

  const pageKey = to.meta?.pageKey

  // ✅ Ignorer le check canView pour les routes sena (option1/option2)
  const isSenaRoute = senaRouteNames.includes(to.name)

  if (to.meta.requiresAuth && pageKey && !isSenaRoute && !auth.canView(pageKey)) {
    const fallback = auth.firstAccessibleRoute(accessibleRouteNames)
    return fallback ? { name: fallback } : { name: 'login' }
  }
})

export default router