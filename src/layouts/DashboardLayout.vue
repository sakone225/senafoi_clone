<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import SidebarIcon from '../components/SidebarIcon.vue'

// â”€â”€â”€ Stores & router â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()

// â”€â”€â”€ State â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const sidebarCollapsed  = ref(false)
const mobileOpen        = ref(false)
const showUserDropdown  = ref(false)

// â”€â”€â”€ Computed â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const pageTitle = computed(() => route.meta?.title || 'Dashboard')
const currentPageKey = computed(() => route.meta?.pageKey || '')
const canEditCurrentPage = computed(() => auth.canEdit(currentPageKey.value))

// â”€â”€â”€ Navigation items â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const navItems = [
  { name: 'dashboard',               label: 'Tableau de bord', icon: 'grid'        },
  { name: 'seminars',                label: 'SÃ©minaires',       icon: 'calendar'    },
  { name: 'participants',            label: 'Participants',      icon: 'users'       },
  { name: 'quota',                   label: 'Quota',             icon: 'activity'    },
  { name: 'cars',                    label: 'Cars',              icon: 'truck'       },
  { name: 'speakers',                label: 'Intervenants',      icon: 'mic'         },
  { name: 'rooms',                   label: 'Salles',            icon: 'map-pin'     },
  { name: 'evaluations',             label: 'Ã‰valuations',       icon: 'star'        },
  { name: 'sante',                   label: 'SantÃ©',             icon: 'heart'       },
  { name: 'paiements_configuration', label: 'Paiements',         icon: 'credit-card' },
  { name: 'reports',                 label: 'Rapports',          icon: 'bar-chart'   },
]

const bottomItems = [
  { name: 'settings', label: 'Paramètres', icon: 'settings' },
  { name: 'users', label: 'Utilisateurs', icon: 'users' },
]

const visibleNavItems = computed(() => navItems.filter(item => auth.canView(item.name)))
const visibleBottomItems = computed(() => bottomItems.filter(item => auth.canView(item.name)))

// â”€â”€â”€ Methods â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function handleLogout() {
  auth.logout()
  router.push({ name: 'login' })
}

function closeMobile() {
  mobileOpen.value = false
}

function getInitials(name) {
  if (!name) return 'U'
  return name
    .split(' ')
    .map(n => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}
</script>

<template>
  <div class="app-shell" :class="{ 'sidebar-collapsed': sidebarCollapsed }">

    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         MOBILE OVERLAY
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <Transition name="overlay">
      <div
        v-if="mobileOpen"
        class="mobile-overlay"
        @click="closeMobile"
        aria-hidden="true"
      />
    </Transition>

    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         SIDEBAR
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <aside
      class="sidebar"
      :class="{ 'mobile-open': mobileOpen }"
      role="navigation"
      aria-label="Navigation principale"
    >
      <!-- Logo -->
      <div class="sidebar-logo">
        <div class="logo-mark">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polygon points="12 2 22 8.5 22 15.5 12 22 2 15.5 2 8.5 12 2"/>
          </svg>
        </div>
        <span class="logo-text">Seminar</span>

        <!-- Mobile close -->
        <button class="mobile-close" @click="closeMobile" aria-label="Fermer le menu">
          <SidebarIcon name="x" />
        </button>
      </div>

      <!-- Section : Main nav -->
      <div class="nav-section">
        <p class="nav-section-label">Principal</p>
        <nav>
          <RouterLink
            v-for="item in visibleNavItems"
            :key="item.name"
            :to="{ name: item.name }"
            class="nav-item"
            :class="{ active: route.name === item.name }"
            :title="sidebarCollapsed ? item.label : undefined"
            @click="closeMobile"
          >
            <span class="nav-icon">
              <SidebarIcon :name="item.icon" />
            </span>
            <span class="nav-label">{{ item.label }}</span>
            <span v-if="route.name === item.name" class="active-indicator" />
          </RouterLink>
        </nav>
      </div>

      <!-- Section : Bottom nav -->
      <div class="sidebar-bottom">
        <div class="nav-section">
          <p class="nav-section-label">Compte</p>
          <RouterLink
            v-for="item in visibleBottomItems"
            :key="item.name"
            :to="{ name: item.name }"
            class="nav-item"
            :class="{ active: route.name === item.name }"
            :title="sidebarCollapsed ? item.label : undefined"
            @click="closeMobile"
          >
            <span class="nav-icon"><SidebarIcon :name="item.icon" /></span>
            <span class="nav-label">{{ item.label }}</span>
          </RouterLink>

          <button
            class="nav-item nav-item--danger"
            :title="sidebarCollapsed ? 'DÃ©connexion' : undefined"
            @click="handleLogout"
          >
            <span class="nav-icon"><SidebarIcon name="log-out" /></span>
            <span class="nav-label">DÃ©connexion</span>
          </button>
        </div>

        <!-- User card (expanded only) -->
        <div class="sidebar-user-card">
          <div class="sidebar-user-avatar">{{ getInitials(auth.user?.name) }}</div>
          <div class="sidebar-user-info">
            <p class="sidebar-user-name">{{ auth.user?.name }}</p>
            <p class="sidebar-user-role">{{ auth.user?.matricule || auth.user?.role }}</p>
          </div>
        </div>

        <!-- Collapse toggle (desktop) -->
        <button
          class="collapse-btn"
          :title="sidebarCollapsed ? 'Agrandir la sidebar' : 'RÃ©duire la sidebar'"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <SidebarIcon :name="sidebarCollapsed ? 'chevrons-right' : 'chevrons-left'" />
          <span class="nav-label">{{ sidebarCollapsed ? 'Agrandir' : 'RÃ©duire' }}</span>
        </button>
      </div>
    </aside>

    <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
         MAIN
    â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
    <div class="main-area">

      <!-- Navbar -->
      <header class="navbar">
        <!-- Mobile burger -->
        <button
          class="navbar-btn mobile-burger"
          aria-label="Ouvrir la navigation"
          @click="mobileOpen = true"
        >
          <SidebarIcon name="menu" />
        </button>

        <!-- Page title -->
        <div class="navbar-title">
          <h1>{{ pageTitle }}</h1>
        </div>

        <!-- Right actions -->
        <div class="navbar-right">
          <!-- Search -->
          <button class="navbar-btn" title="Rechercher" aria-label="Rechercher">
            <SidebarIcon name="search" />
          </button>

          <!-- Notifications -->
          <button class="navbar-btn navbar-btn--notif" title="Notifications" aria-label="Notifications">
            <SidebarIcon name="bell" />
            <span class="notif-badge" aria-hidden="true">3</span>
          </button>

          <!-- Divider -->
          <div class="navbar-divider" aria-hidden="true"></div>

          <!-- User menu -->
          <div class="user-menu-wrap">
            <button
              class="user-trigger"
              :title="auth.user?.name"
              @click="showUserDropdown = !showUserDropdown"
            >
              <div class="user-avatar">{{ getInitials(auth.user?.name) }}</div>
              <div class="user-meta">
                <span class="user-name">{{ auth.user?.name }}</span>
                <span class="user-role">{{ auth.user?.matricule || auth.user?.role }}</span>
              </div>
              <SidebarIcon name="chevron-right" />
            </button>

            <!-- Dropdown -->
            <Transition name="dropdown">
              <div
                v-if="showUserDropdown"
                class="user-dropdown"
                @click="showUserDropdown = false"
              >
                <RouterLink :to="{ name: 'settings' }" class="dropdown-item">
                  <SidebarIcon name="user" />
                  Mon profil
                </RouterLink>
                <RouterLink :to="{ name: 'settings' }" class="dropdown-item">
                  <SidebarIcon name="settings" />
                  ParamÃ¨tres
                </RouterLink>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item dropdown-item--danger" @click="handleLogout">
                  <SidebarIcon name="log-out" />
                  DÃ©connexion
                </button>
              </div>
            </Transition>
          </div>
        </div>
      </header>

      <!-- Content -->
      <main class="content" :class="{ 'content--readonly': currentPageKey && !canEditCurrentPage }">
        <RouterView v-slot="{ Component }">
          <Transition name="page" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>

  </div>
</template>

<style scoped>
.app-shell {
  display: grid;
  grid-template-columns: var(--sw) 1fr;
  min-height: 100vh;
  background: var(--bg);
  transition: grid-template-columns var(--t);
  font-family: 'SF Pro Text', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.app-shell.sidebar-collapsed {
  grid-template-columns: var(--sw-c) 1fr;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MOBILE OVERLAY
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.mobile-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 29;
  backdrop-filter: blur(2px);
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   SIDEBAR
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.sidebar {
  background: var(--sb-bg);
  display: flex;
  flex-direction: column;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow: hidden;
  border-right: 1px solid var(--sb-border);
  z-index: 20;
  transition: width var(--t);
}

/* Logo */
.sidebar-logo {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 20px 16px 16px;
  border-bottom: 1px solid var(--sb-border);
  flex-shrink: 0;
  overflow: hidden;
}

.logo-mark {
  width: 32px;
  height: 32px;
  background: var(--accent);
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  flex-shrink: 0;
}

.logo-text {
  font-size: 15px;
  font-weight: 650;
  color: #fff;
  letter-spacing: -0.03em;
  white-space: nowrap;
  flex: 1;
  overflow: hidden;
  transition: opacity var(--t), width var(--t);
}

.sidebar-collapsed .logo-text {
  opacity: 0;
  width: 0;
  pointer-events: none;
}

.mobile-close {
  display: none;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  background: rgba(255,255,255,0.08);
  border: none;
  border-radius: 7px;
  color: rgba(255,255,255,0.5);
  cursor: pointer;
  margin-left: auto;
  flex-shrink: 0;
  transition: background var(--t), color var(--t);
}

.mobile-close:hover {
  background: rgba(255,255,255,0.14);
  color: #fff;
}

/* Nav sections */
.nav-section {
  padding: 10px 8px 4px;
}

.nav-section-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.22);
  padding: 0 10px;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  transition: opacity var(--t);
}

.sidebar-collapsed .nav-section-label {
  opacity: 0;
}

/* Nav items */
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: 8px;
  color: var(--sb-text);
  text-decoration: none;
  font-size: 13.5px;
  font-weight: 450;
  letter-spacing: -0.01em;
  transition: background var(--t), color var(--t);
  position: relative;
  white-space: nowrap;
  cursor: pointer;
  border: none;
  background: transparent;
  width: 100%;
  text-align: left;
  overflow: hidden;
}

.nav-item:hover {
  background: var(--sb-hover);
  color: rgba(255,255,255,0.78);
}

.nav-item.active {
  background: var(--sb-active);
  color: var(--sb-active-tx);
  font-weight: 540;
}

.nav-item--danger {
  color: rgba(255, 80, 80, 0.6);
}

.nav-item--danger:hover {
  background: rgba(255, 80, 80, 0.09);
  color: rgba(255, 80, 80, 1);
}

.nav-icon {
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.nav-label {
  flex: 1;
  overflow: hidden;
  white-space: nowrap;
  transition: opacity var(--t), width var(--t);
}

.sidebar-collapsed .nav-label {
  opacity: 0;
  width: 0;
}

.active-indicator {
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: rgba(var(--accent-rgb), 1);
  flex-shrink: 0;
  margin-left: auto;
  transition: opacity var(--t);
}

.sidebar-collapsed .active-indicator {
  opacity: 0;
}

/* Sidebar bottom area */
.sidebar-bottom {
  margin-top: auto;
  border-top: 1px solid var(--sb-border);
  display: flex;
  flex-direction: column;
}

/* User card inside sidebar */
.sidebar-user-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-top: 1px solid var(--sb-border);
  overflow: hidden;
  transition: opacity var(--t), height var(--t);
}

.sidebar-collapsed .sidebar-user-card {
  opacity: 0;
  height: 0;
  padding: 0;
  pointer-events: none;
}

.sidebar-user-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: rgba(var(--accent-rgb), 0.9);
  color: white;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.sidebar-user-info {
  overflow: hidden;
}

.sidebar-user-name {
  font-size: 12.5px;
  font-weight: 560;
  color: rgba(255,255,255,0.85);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}

.sidebar-user-role {
  font-size: 11px;
  color: rgba(255,255,255,0.35);
  text-transform: capitalize;
  white-space: nowrap;
  line-height: 1.3;
}

/* Collapse button */
.collapse-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 16px;
  background: transparent;
  border: none;
  border-top: 1px solid var(--sb-border);
  color: rgba(255,255,255,0.28);
  font-size: 12px;
  cursor: pointer;
  transition: background var(--t), color var(--t);
  white-space: nowrap;
  overflow: hidden;
}

.collapse-btn:hover {
  background: var(--sb-hover);
  color: rgba(255,255,255,0.6);
}

.collapse-btn .nav-label {
  font-size: 12px;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MAIN AREA
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.main-area {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  overflow: hidden;
  min-width: 0;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   NAVBAR
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.navbar {
  height: var(--nh);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  padding: 0 20px;
  margin-bottom: -10px;
  gap: 12px;
  position: sticky;
  top: 0;
  z-index: 10;
  box-shadow: var(--shadow);
  flex-shrink: 0;
}

.mobile-burger {
  display: none;
}

.navbar-btn {
  position: relative;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--text-2);
  transition: background var(--t), color var(--t), border-color var(--t);
  flex-shrink: 0;
}

.navbar-btn:hover {
  background: var(--bg);
  color: var(--text);
  border-color: rgba(0,0,0,0.12);
}

.navbar-btn--notif .notif-badge {
  position: absolute;
  top: 5px;
  right: 5px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: #ef4444;
  color: white;
  font-size: 9px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid white;
  line-height: 1;
}

.navbar-title {
  flex: 1;
  min-width: 0;
}

.navbar-title h1 {
  font-size: 15px;
  font-weight: 620;
  color: var(--text);
  letter-spacing: -0.025em;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.navbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.navbar-divider {
  width: 1px;
  height: 22px;
  background: var(--border);
  margin: 0 4px;
}

/* User menu */
.user-menu-wrap {
  position: relative;
}

.user-trigger {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 4px 8px 4px 4px;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: transparent;
  cursor: pointer;
  transition: background var(--t), border-color var(--t);
  color: var(--text-2);
}

.user-trigger:hover {
  background: var(--bg);
  border-color: rgba(0,0,0,0.12);
}

.user-avatar {
  width: 28px;
  height: 28px;
  border-radius: 7px;
  background: var(--accent);
  color: white;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  letter-spacing: 0.02em;
  flex-shrink: 0;
}

.user-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.user-name {
  font-size: 13px;
  font-weight: 560;
  color: var(--text);
  line-height: 1.2;
  white-space: nowrap;
}

.user-role {
  font-size: 10.5px;
  color: var(--text-2);
  text-transform: capitalize;
  line-height: 1.2;
}

/* Dropdown */
.user-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 200px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06);
  padding: 6px;
  z-index: 50;
  overflow: hidden;
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 10px;
  border-radius: 7px;
  font-size: 13px;
  color: var(--text);
  text-decoration: none;
  cursor: pointer;
  border: none;
  background: transparent;
  width: 100%;
  text-align: left;
  transition: background var(--t), color var(--t);
  font-weight: 450;
}

.dropdown-item:hover {
  background: var(--bg);
}

.dropdown-item--danger {
  color: #dc2626;
}

.dropdown-item--danger:hover {
  background: #fef2f2;
}

.dropdown-divider {
  height: 1px;
  background: var(--border);
  margin: 5px 0;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   CONTENT
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.content {
  flex: 1;
  padding: 28px;
  overflow-y: auto;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   TRANSITIONS
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.page-enter-active,
.page-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.page-enter-from { opacity: 0; transform: translateY(5px); }
.page-leave-to   { opacity: 0; transform: translateY(-3px); }

.overlay-enter-active,
.overlay-leave-active {
  transition: opacity 0.2s ease;
}
.overlay-enter-from,
.overlay-leave-to {
  opacity: 0;
}

.dropdown-enter-active,
.dropdown-leave-active {
  transition: opacity 0.14s ease, transform 0.14s ease;
}
.dropdown-enter-from,
.dropdown-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.97);
}

.content--readonly {
  position: relative;
}

.content--readonly :deep(button),
.content--readonly :deep(input),
.content--readonly :deep(select),
.content--readonly :deep(textarea),
.content--readonly :deep([role="button"]) {
  pointer-events: none;
  opacity: 0.72;
}

.content--readonly::before {
  content: "Lecture seule";
  position: sticky;
  top: 0;
  z-index: 5;
  display: inline-flex;
  margin-bottom: 12px;
  border: 1px solid #fde68a;
  border-radius: 999px;
  background: #fffbeb;
  color: #92400e;
  padding: 5px 10px;
  font-size: 12px;
  font-weight: 800;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RESPONSIVE â€” mobile (â‰¤ 768px)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
@media (max-width: 768px) {
  .app-shell {
    grid-template-columns: 0 1fr;
  }

  .app-shell.sidebar-collapsed {
    grid-template-columns: 0 1fr;
  }

  .sidebar {
    position: fixed;
    left: -100%;
    top: 0;
    width: var(--sw);
    height: 100vh;
    transition: left var(--t);
    z-index: 30;
  }

  .sidebar.mobile-open {
    left: 0;
    box-shadow: 4px 0 40px rgba(0,0,0,0.22);
  }

  /* Show elements hidden on desktop for mobile */
  .mobile-overlay  { display: block; }
  .mobile-burger   { display: flex; }
  .mobile-close    { display: flex; }
  .collapse-btn    { display: none; }

  /* Reset collapsed state on mobile */
  .sidebar-collapsed .logo-text,
  .sidebar-collapsed .nav-label,
  .sidebar-collapsed .nav-section-label,
  .sidebar-collapsed .active-indicator,
  .sidebar-collapsed .sidebar-user-card {
    opacity: 1;
    width: auto;
    height: auto;
    padding: revert;
    pointer-events: auto;
  }

  .user-meta { display: none; }
  .user-trigger > svg:last-child { display: none; }

  .content { padding: 20px 16px; }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RESPONSIVE â€” tablet (769px â€“ 1024px)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
@media (min-width: 769px) and (max-width: 1024px) {
  .user-meta { display: none; }
  .user-trigger > svg:last-child { display: none; }
}
</style>
