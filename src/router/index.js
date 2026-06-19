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
import EvaluationsView from '../views/EvaluationsView.vue'
import SanteView from '../views/SanteView.vue'
import PaiementsView from '../views/PaiementsView.vue'
import QuotaView from '../views/QuotaView.vue'
import CarsView from '../views/CarsView.vue'
import UsersView from '../views/UsersView.vue'

export const accessibleRouteNames = [
  'dashboard',
  'seminars',
  'participants',
  'quota',
  'cars',
  'speakers',
  'rooms',
  'evaluations',
  'sante',
  'paiements_configuration',
  'reports',
  'settings',
  'users',
]

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
        path: 'evaluations',
        name: 'evaluations',
        component: EvaluationsView,
        meta: { title: 'Evaluations', pageKey: 'evaluations' },
      },
      {
        path: 'sante',
        name: 'sante',
        component: SanteView,
        meta: { title: 'Santé', pageKey: 'sante' },
      },
      {
        path: 'paiements_configuration',
        name: 'paiements_configuration',
        component: PaiementsView,
        meta: { title: 'Paiements', pageKey: 'paiements_configuration' },
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
  if (to.meta.requiresAuth && pageKey && !auth.canView(pageKey)) {
    const fallback = auth.firstAccessibleRoute(accessibleRouteNames)
    return fallback ? { name: fallback } : { name: 'login' }
  }
})

export default router
