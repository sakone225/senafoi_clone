<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import SidebarIcon from '../components/SidebarIcon.vue'

// ---- Stores & router -------------------------------------------------
const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()

// ---- State -------------------------------------------------------------
const sidebarCollapsed  = ref(false)
const mobileOpen        = ref(false)
const showUserDropdown  = ref(false)
const expandedMenus     = ref(new Set())

// ---- Computed -----------------------------------------------------------
const pageTitle          = computed(() => route.meta?.title || 'Dashboard')
const currentPageKey     = computed(() => route.meta?.pageKey || '')


const isSenaPage = computed(() => {
  const key = currentPageKey.value
  return key?.includes('sena') || false
})

const canEditCurrentPage = computed(() => {
  if (isSenaPage.value) return true  // ← les pages sena gèrent leurs propres permissions
  return auth.canEdit(currentPageKey.value)
})

// ---- Navigation --------------------------------------------------------
// Lien principal
const navItems = [
  { name: 'dashboard', label: 'Tableau de bord', icon: 'grid' },
]

// Groupes dépliables SENA*
const expandableMenus = [
  { name: 'senafad',  label: 'SENAFAD',  icon: 'folder', children: [
    { name: 'senafad-option1',  label: 'Liste des membres' },
    { name: 'senafad-option2',  label: 'Option 2' },
  ]},
  { name: 'senafi',   label: 'SENAFI',   icon: 'folder', children: [
    { name: 'senafi-option1',   label: 'Option 1' },
    { name: 'senafi-option2',   label: 'Option 2' },
  ]},
  { name: 'senafoci', label: 'SENAFOCI', icon: 'folder', children: [
    { name: 'senafoci-option1', label: 'Option 1' },
    { name: 'senafoci-option2', label: 'Option 2' },
  ]},
  { name: 'senacef',  label: 'SENACEF',  icon: 'folder', children: [
    { name: 'senacef-option1',  label: 'Option 1' },
    { name: 'senacef-option2',  label: 'Option 2' },
  ]},
  { name: 'senasip',  label: 'SENASIP',  icon: 'folder', children: [
    { name: 'senasip-option1',  label: 'Option 1' },
    { name: 'senasip-option2',  label: 'Option 2' },
  ]},
  { name: 'senaes',   label: 'SENAES',   icon: 'folder', children: [
    { name: 'senaes-option1',   label: 'Option 1' },
    { name: 'senaes-option2',   label: 'Option 2' },
  ]},
  { name: 'senamo',   label: 'SENAMO',   icon: 'folder', children: [
    { name: 'senamo-option1',   label: 'Option 1' },
    { name: 'senamo-option2',   label: 'Option 2' },
  ]},
  { name: 'senacrex', label: 'SENACREX', icon: 'folder', children: [
    { name: 'senacrex-option1', label: 'Option 1' },
    { name: 'senacrex-option2', label: 'Option 2' },
  ]},
]

// Liens bas de sidebar
const bottomItems = [
  { name: 'login-history', label: 'Historique connexions', icon: 'clock' },
{ name: 'settings', label: 'Paramètres',  icon: 'settings' },
  { name: 'users',    label: 'Utilisateurs', icon: 'users'    },
]

const visibleNavItems        = computed(() => navItems.filter(i => auth.canView(i.name)))
const visibleExpandableMenus = computed(() => expandableMenus)
const visibleBottomItems     = computed(() => bottomItems.filter(i => auth.canView(i.name)))

// ---- Methods -----------------------------------------------------------
function handleLogout() {
  auth.logout()
  router.push({ name: 'login' })
}

function closeMobile() { mobileOpen.value = false }

function getInitials(name) {
  if (!name) return 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

function toggleMenu(name) {
  const next = new Set(expandedMenus.value)
  next.has(name) ? next.delete(name) : next.add(name)
  expandedMenus.value = next
}

function hasActiveChild(menu) {
  return menu.children.some(child => route.name === child.name)
}

function isMenuOpen(menu) {
  return expandedMenus.value.has(menu.name) || hasActiveChild(menu)
}
</script>

<template>
  <div class="app-shell" :class="{ 'sidebar-collapsed': sidebarCollapsed }">

    <!-- MOBILE OVERLAY -->
    <Transition name="overlay">
      <div v-if="mobileOpen" class="mobile-overlay" @click="closeMobile" aria-hidden="true" />
    </Transition>

    <!-- ═══ SIDEBAR ═══ -->
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
        <span class="logo-text">Departement</span>
        <button class="mobile-close" @click="closeMobile" aria-label="Fermer le menu">
          <SidebarIcon name="x" />
        </button>
      </div>

      <!-- Zone scrollable -->
      <div class="nav-scroll">
        <div class="nav-section">
          <p class="nav-section-label">Principal</p>
          <nav>

            <!-- Dashboard -->
            <RouterLink
              v-for="item in visibleNavItems"
              :key="item.name"
              :to="{ name: item.name }"
              class="nav-item"
              :class="{ active: route.name === item.name }"
              :title="sidebarCollapsed ? item.label : undefined"
              @click="closeMobile"
            >
              <span class="nav-icon"><SidebarIcon :name="item.icon" /></span>
              <span class="nav-label">{{ item.label }}</span>
              <span v-if="route.name === item.name" class="active-indicator" />
            </RouterLink>

            <!-- Groupes SENA* -->
            <div v-for="menu in visibleExpandableMenus" :key="menu.name" class="menu-group">
              <button
                type="button"
                class="nav-item menu-header"
                :class="{ 'menu-header--active': hasActiveChild(menu) }"
                :title="sidebarCollapsed ? menu.label : undefined"
                @click="toggleMenu(menu.name)"
              >
                <span class="nav-icon"><SidebarIcon :name="menu.icon" /></span>
                <span class="nav-label">{{ menu.label }}</span>
                <span class="menu-chevron" :class="{ 'menu-chevron--open': isMenuOpen(menu) }">
                  <SidebarIcon name="chevron-down" />
                </span>
              </button>

              <div class="submenu" :class="{ 'submenu--open': isMenuOpen(menu) }">
                <div class="submenu-inner">
                  <RouterLink
                    v-for="child in menu.children"
                    :key="child.name"
                    :to="{ name: child.name }"
                    class="submenu-link"
                    :class="{ active: route.name === child.name }"
                    @click="closeMobile"
                  >
                    <span class="submenu-dot" />
                    <span class="nav-label">{{ child.label }}</span>
                  </RouterLink>
                </div>
              </div>
            </div>

          </nav>
        </div>
      </div>

      <!-- Bas de sidebar (fixe) -->
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
            :title="sidebarCollapsed ? 'Déconnexion' : undefined"
            @click="handleLogout"
          >
            <span class="nav-icon"><SidebarIcon name="log-out" /></span>
            <span class="nav-label">Déconnexion</span>
          </button>
        </div>

        <!-- User card -->
        <div class="sidebar-user-card">
          <div class="sidebar-user-avatar">{{ getInitials(auth.user?.name) }}</div>
          <div class="sidebar-user-info">
            <p class="sidebar-user-name">{{ auth.user?.name }}</p>
            <p class="sidebar-user-role">{{ auth.user?.matricule || auth.user?.role }}</p>
          </div>
        </div>

        <!-- Bouton réduire/agrandir -->
        <button
          class="collapse-btn"
          :title="sidebarCollapsed ? 'Agrandir la sidebar' : 'Réduire la sidebar'"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <SidebarIcon :name="sidebarCollapsed ? 'chevrons-right' : 'chevrons-left'" />
          <span class="nav-label">{{ sidebarCollapsed ? 'Agrandir' : 'Réduire' }}</span>
        </button>
      </div>
    </aside>

    <!-- ═══ MAIN ═══ -->
    <div class="main-area">

      <!-- Navbar -->
      <header class="navbar">
        <button class="navbar-btn mobile-burger" aria-label="Ouvrir la navigation" @click="mobileOpen = true">
          <SidebarIcon name="menu" />
        </button>

        <div class="navbar-title">
          <h1>{{ pageTitle }}</h1>
        </div>

        <div class="navbar-right">
          <button class="navbar-btn" title="Rechercher" aria-label="Rechercher">
            <SidebarIcon name="search" />
          </button>

          <button class="navbar-btn navbar-btn--notif" title="Notifications" aria-label="Notifications">
            <SidebarIcon name="bell" />
            <span class="notif-badge" aria-hidden="true">3</span>
          </button>

          <div class="navbar-divider" aria-hidden="true"></div>

          <!-- Menu utilisateur -->
          <div class="user-menu-wrap">
            <button class="user-trigger" :title="auth.user?.name" @click="showUserDropdown = !showUserDropdown">
              <div class="user-avatar">{{ getInitials(auth.user?.name) }}</div>
              <div class="user-meta">
                <span class="user-name">{{ auth.user?.name }}</span>
                <span class="user-role">{{ auth.user?.matricule || auth.user?.role }}</span>
              </div>
              <SidebarIcon name="chevron-right" />
            </button>

            <Transition name="dropdown">
              <div v-if="showUserDropdown" class="user-dropdown" @click="showUserDropdown = false">
                <RouterLink :to="{ name: 'settings' }" class="dropdown-item">
                  <SidebarIcon name="user" /> Mon profil
                </RouterLink>
                <RouterLink :to="{ name: 'settings' }" class="dropdown-item">
                  <SidebarIcon name="settings" /> Paramètres
                </RouterLink>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item dropdown-item--danger" @click="handleLogout">
                  <SidebarIcon name="log-out" /> Déconnexion
                </button>
              </div>
            </Transition>
          </div>
        </div>
      </header>

      <!-- Contenu de la page -->
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
/* ── Layout shell ─────────────────────────────────────────────────────── */
.app-shell {
  display: grid;
  grid-template-columns: var(--sw) 1fr;
  min-height: 100vh;
  background: var(--bg);
  transition: grid-template-columns var(--t);
  font-family: 'SF Pro Text', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.app-shell.sidebar-collapsed { grid-template-columns: var(--sw-c) 1fr; }

/* ── Mobile overlay ───────────────────────────────────────────────────── */
.mobile-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 29;
  backdrop-filter: blur(2px);
}

/* ── Sidebar ──────────────────────────────────────────────────────────── */
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
  width: 32px; height: 32px;
  background: var(--accent);
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  color: white; flex-shrink: 0;
}
.logo-text {
  font-size: 15px; font-weight: 650; color: #fff;
  letter-spacing: -0.03em; white-space: nowrap;
  flex: 1; overflow: hidden;
  transition: opacity var(--t), width var(--t);
}
.sidebar-collapsed .logo-text { opacity: 0; width: 0; pointer-events: none; }

.mobile-close {
  display: none; align-items: center; justify-content: center;
  width: 28px; height: 28px;
  background: rgba(255,255,255,0.08); border: none; border-radius: 7px;
  color: rgba(255,255,255,0.5); cursor: pointer;
  margin-left: auto; flex-shrink: 0;
  transition: background var(--t), color var(--t);
}
.mobile-close:hover { background: rgba(255,255,255,0.14); color: #fff; }

/* Zone scrollable */
.nav-scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  scrollbar-width: thin;
  scrollbar-color: rgba(255,255,255,0.12) transparent;
}
.nav-scroll::-webkit-scrollbar { width: 4px; }
.nav-scroll::-webkit-scrollbar-track { background: transparent; }
.nav-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 99px; }
.nav-scroll::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.22); }

/* Section nav */
.nav-section { padding: 10px 8px 4px; }
.nav-section-label {
  font-size: 10px; font-weight: 600; letter-spacing: 0.07em;
  text-transform: uppercase; color: rgba(255,255,255,0.22);
  padding: 0 10px; margin-bottom: 4px;
  white-space: nowrap; overflow: hidden;
  transition: opacity var(--t);
}
.sidebar-collapsed .nav-section-label { opacity: 0; }

/* Nav items */
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 8px;
  color: var(--sb-text); text-decoration: none;
  font-size: 13.5px; font-weight: 450; letter-spacing: -0.01em;
  transition: background var(--t), color var(--t);
  position: relative; white-space: nowrap; cursor: pointer;
  border: none; background: transparent;
  width: 100%; text-align: left; overflow: hidden;
}
.nav-item:hover { background: var(--sb-hover); color: rgba(255,255,255,0.78); }
.nav-item.active { background: var(--sb-active); color: var(--sb-active-tx); font-weight: 540; }
.nav-item--danger { color: rgba(255,80,80,0.6); }
.nav-item--danger:hover { background: rgba(255,80,80,0.09); color: rgba(255,80,80,1); }

.nav-icon {
  width: 20px; height: 20px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.nav-label {
  flex: 1; overflow: hidden; white-space: nowrap;
  transition: opacity var(--t), width var(--t);
}
.sidebar-collapsed .nav-label { opacity: 0; width: 0; }

.active-indicator {
  width: 4px; height: 4px; border-radius: 50%;
  background: rgba(var(--accent-rgb), 1);
  flex-shrink: 0; margin-left: auto;
  transition: opacity var(--t);
}
.sidebar-collapsed .active-indicator { opacity: 0; }

/* Groupes dépliables */
.menu-group { display: flex; flex-direction: column; }
.menu-header--active { color: rgba(255,255,255,0.78); }

.menu-chevron {
  display: flex; align-items: center; justify-content: center;
  width: 14px; height: 14px; flex-shrink: 0;
  margin-left: auto; color: rgba(255,255,255,0.35);
  transition: transform var(--t);
}
.menu-chevron--open { transform: rotate(180deg); }

.submenu {
  display: grid; grid-template-rows: 0fr;
  overflow: hidden; transition: grid-template-rows 0.22s ease;
}
.submenu--open { grid-template-rows: 1fr; }
.submenu-inner {
  overflow: hidden; min-height: 0;
  display: flex; flex-direction: column; padding: 2px 0;
}

.submenu-link {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px 8px 34px; border-radius: 8px;
  color: rgba(255,255,255,0.55); text-decoration: none;
  font-size: 13px; font-weight: 440; letter-spacing: -0.01em;
  white-space: nowrap; overflow: hidden;
  transition: background var(--t), color var(--t);
}
.submenu-link:hover { background: var(--sb-hover); color: rgba(255,255,255,0.82); }
.submenu-link.active { background: var(--sb-active); color: var(--sb-active-tx); font-weight: 540; }

.submenu-dot {
  width: 4px; height: 4px; border-radius: 50%;
  background: currentColor; opacity: 0.5; flex-shrink: 0;
}

.sidebar-collapsed .menu-chevron,
.sidebar-collapsed .submenu { display: none; }

/* Bas de sidebar */
.sidebar-bottom {
  margin-top: auto;
  border-top: 1px solid var(--sb-border);
  display: flex; flex-direction: column;
  flex-shrink: 0;
}

.sidebar-user-card {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  border-top: 1px solid var(--sb-border);
  overflow: hidden;
  transition: opacity var(--t), height var(--t);
}
.sidebar-collapsed .sidebar-user-card { opacity: 0; height: 0; padding: 0; pointer-events: none; }

.sidebar-user-avatar {
  width: 30px; height: 30px; border-radius: 50%;
  background: rgba(var(--accent-rgb), 0.9); color: white;
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.sidebar-user-info { overflow: hidden; }
.sidebar-user-name {
  font-size: 12.5px; font-weight: 560; color: rgba(255,255,255,0.85);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;
}
.sidebar-user-role {
  font-size: 11px; color: rgba(255,255,255,0.35);
  text-transform: capitalize; white-space: nowrap; line-height: 1.3;
}

.collapse-btn {
  display: flex; align-items: center; gap: 10px;
  width: 100%; padding: 10px 16px;
  background: transparent; border: none;
  border-top: 1px solid var(--sb-border);
  color: rgba(255,255,255,0.28); font-size: 12px;
  cursor: pointer; white-space: nowrap; overflow: hidden;
  transition: background var(--t), color var(--t);
}
.collapse-btn:hover { background: var(--sb-hover); color: rgba(255,255,255,0.6); }
.collapse-btn .nav-label { font-size: 12px; }

/* ── Main area ────────────────────────────────────────────────────────── */
.main-area {
  display: flex; flex-direction: column;
  min-height: 100vh; overflow: hidden; min-width: 0;
}

/* Navbar */
.navbar {
  height: var(--nh);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center;
  padding: 0 20px; margin-bottom: -10px; gap: 12px;
  position: sticky; top: 0; z-index: 10;
  box-shadow: var(--shadow); flex-shrink: 0;
}
.mobile-burger { display: none; }

.navbar-btn {
  position: relative; width: 34px; height: 34px;
  border-radius: 8px; border: 1px solid var(--border);
  background: transparent; display: flex;
  align-items: center; justify-content: center;
  cursor: pointer; color: var(--text-2); flex-shrink: 0;
  transition: background var(--t), color var(--t), border-color var(--t);
}
.navbar-btn:hover { background: var(--bg); color: var(--text); border-color: rgba(0,0,0,0.12); }

.navbar-btn--notif .notif-badge {
  position: absolute; top: 5px; right: 5px;
  width: 16px; height: 16px; border-radius: 50%;
  background: #ef4444; color: white; font-size: 9px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid white; line-height: 1;
}

.navbar-title { flex: 1; min-width: 0; }
.navbar-title h1 {
  font-size: 15px; font-weight: 620; color: var(--text);
  letter-spacing: -0.025em; margin: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.navbar-right { display: flex; align-items: center; gap: 8px; margin-left: auto; }
.navbar-divider { width: 1px; height: 22px; background: var(--border); margin: 0 4px; }

/* User menu */
.user-menu-wrap { position: relative; }
.user-trigger {
  display: flex; align-items: center; gap: 9px;
  padding: 4px 8px 4px 4px; border-radius: 10px;
  border: 1px solid var(--border); background: transparent;
  cursor: pointer; color: var(--text-2);
  transition: background var(--t), border-color var(--t);
}
.user-trigger:hover { background: var(--bg); border-color: rgba(0,0,0,0.12); }

.user-avatar {
  width: 28px; height: 28px; border-radius: 7px;
  background: var(--accent); color: white;
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  letter-spacing: 0.02em; flex-shrink: 0;
}
.user-meta { display: flex; flex-direction: column; align-items: flex-start; }
.user-name { font-size: 13px; font-weight: 560; color: var(--text); line-height: 1.2; white-space: nowrap; }
.user-role { font-size: 10.5px; color: var(--text-2); text-transform: capitalize; line-height: 1.2; }

.user-dropdown {
  position: absolute; top: calc(100% + 8px); right: 0;
  width: 200px; background: var(--surface);
  border: 1px solid var(--border); border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06);
  padding: 6px; z-index: 50; overflow: hidden;
}
.dropdown-item {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px; border-radius: 7px;
  font-size: 13px; color: var(--text); text-decoration: none;
  cursor: pointer; border: none; background: transparent;
  width: 100%; text-align: left; font-weight: 450;
  transition: background var(--t), color var(--t);
}
.dropdown-item:hover { background: var(--bg); }
.dropdown-item--danger { color: #dc2626; }
.dropdown-item--danger:hover { background: #fef2f2; }
.dropdown-divider { height: 1px; background: var(--border); margin: 5px 0; }

/* ── Content ──────────────────────────────────────────────────────────── */
.content { flex: 1; padding: 28px; overflow-y: auto; }

.content--readonly { position: relative; }
.content--readonly :deep(button),
.content--readonly :deep(input),
.content--readonly :deep(select),
.content--readonly :deep(textarea),
.content--readonly :deep([role="button"]) { pointer-events: none; opacity: 0.72; }
.content--readonly::before {
  content: "Lecture seule";
  position: sticky; top: 0; z-index: 5;
  display: inline-flex; margin-bottom: 12px;
  border: 1px solid #fde68a; border-radius: 999px;
  background: #fffbeb; color: #92400e;
  padding: 5px 10px; font-size: 12px; font-weight: 800;
}

/* ── Transitions ──────────────────────────────────────────────────────── */
.page-enter-active, .page-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.page-enter-from { opacity: 0; transform: translateY(5px); }
.page-leave-to   { opacity: 0; transform: translateY(-3px); }

.overlay-enter-active, .overlay-leave-active { transition: opacity 0.2s ease; }
.overlay-enter-from, .overlay-leave-to { opacity: 0; }

.dropdown-enter-active, .dropdown-leave-active { transition: opacity 0.14s ease, transform 0.14s ease; }
.dropdown-enter-from, .dropdown-leave-to { opacity: 0; transform: translateY(-6px) scale(0.97); }

/* ── Responsive mobile ≤ 768px ────────────────────────────────────────── */
@media (max-width: 768px) {
  .app-shell,
  .app-shell.sidebar-collapsed { grid-template-columns: 0 1fr; }

  .sidebar {
    position: fixed; left: -100%; top: 0;
    width: var(--sw); height: 100vh;
    transition: left var(--t); z-index: 30;
  }
  .sidebar.mobile-open { left: 0; box-shadow: 4px 0 40px rgba(0,0,0,0.22); }

  .mobile-overlay { display: block; }
  .mobile-burger  { display: flex; }
  .mobile-close   { display: flex; }
  .collapse-btn   { display: none; }

  .sidebar-collapsed .logo-text,
  .sidebar-collapsed .nav-label,
  .sidebar-collapsed .nav-section-label,
  .sidebar-collapsed .active-indicator,
  .sidebar-collapsed .sidebar-user-card {
    opacity: 1; width: auto; height: auto; padding: revert; pointer-events: auto;
  }
  .sidebar-collapsed .menu-chevron { display: flex; }
  .sidebar-collapsed .submenu { display: grid; }

  .user-meta { display: none; }
  .user-trigger > svg:last-child { display: none; }
  .content { padding: 20px 16px; }
}

/* ── Responsive tablet 769px–1024px ──────────────────────────────────── */
@media (min-width: 769px) and (max-width: 1024px) {
  .user-meta { display: none; }
  .user-trigger > svg:last-child { display: none; }
}
</style>