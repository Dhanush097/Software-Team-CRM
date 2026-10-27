<!-- Screen 10: Navigation Menu Drawer (Side Menu Overlay) -->
<div 
  class="drawer-overlay" 
  :class="{ 'open': drawerOpen }" 
  @click="drawerOpen = false"
></div>

<aside class="drawer-panel" :class="{ 'open': drawerOpen }">
  <!-- Drawer Header -->
  <div class="drawer-header">
    <div class="crm-brand-title">
      <div class="logo-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <rect x="2" y="12" width="4" height="9" rx="1" fill="#FFFFFF"/>
          <rect x="8" y="7" width="4" height="14" rx="1" fill="#FFFFFF"/>
          <rect x="14" y="3" width="4" height="18" rx="1" fill="#FFFFFF"/>
        </svg>
      </div>
      <div>
        <div class="name-txt" style="color: #FFFFFF;">Software CRM</div>
        <div style="font-size: 11px; color: #94A3B8;" x-text="user.name">Suresh Kumar</div>
      </div>
    </div>
    <button class="drawer-close-btn" @click="drawerOpen = false" aria-label="Close menu">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <!-- Menu List with Modern Vector SVG Icons -->
  <div class="drawer-menu-list">
    <!-- Dashboard -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 2 }" 
      @click="navigateTo(2); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          <polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
      </span>
      <span>Dashboard</span>
    </div>

    <!-- Contacts -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 3 }" 
      @click="navigateTo(3); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </span>
      <span>Contacts</span>
    </div>

    <!-- Companies -->
    <div 
      class="drawer-menu-item" 
      @click="navigateTo(3); contactCategory = 'client'; drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 21h18"/>
          <path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
          <path d="M9 7h1"/>
          <path d="M9 11h1"/>
          <path d="M9 15h1"/>
          <path d="M14 7h1"/>
          <path d="M14 11h1"/>
          <path d="M14 15h1"/>
        </svg>
      </span>
      <span>Companies</span>
    </div>

    <!-- Projects -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 5 }" 
      @click="navigateTo(5); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
      </span>
      <span>Projects</span>
    </div>

    <!-- Tasks -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 6 }" 
      @click="navigateTo(6); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 11l3 3L22 4"/>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
      </span>
      <span>Tasks</span>
    </div>

    <!-- Calendar -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 7 }" 
      @click="navigateTo(7); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
          <line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/>
          <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
      </span>
      <span>Calendar</span>
    </div>

    <!-- Invoices -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 8 }" 
      @click="navigateTo(8); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
          <polyline points="10 9 9 9 8 9"/>
        </svg>
      </span>
      <span>Invoices</span>
    </div>

    <!-- Compact Dashboard -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 9 }" 
      @click="navigateTo(9); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7"/>
          <rect x="14" y="3" width="7" height="7"/>
          <rect x="14" y="14" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/>
        </svg>
      </span>
      <span>Compact Dashboard</span>
    </div>

    <!-- Documents -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 11 }" 
      @click="navigateTo(11); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
          <polyline points="13 2 13 9 20 9"/>
        </svg>
      </span>
      <span>Documents</span>
    </div>

    <!-- Team -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 12 }" 
      @click="navigateTo(12); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
      </span>
      <span>Team</span>
    </div>

    <!-- Reports -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 13 }" 
      @click="navigateTo(13); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="20" x2="18" y2="10"/>
          <line x1="12" y1="20" x2="12" y2="4"/>
          <line x1="6" y1="20" x2="6" y2="14"/>
        </svg>
      </span>
      <span>Reports</span>
    </div>

    <!-- Settings -->
    <div 
      class="drawer-menu-item" 
      :class="{ 'active': currentScreen === 14 }" 
      @click="navigateTo(14); drawerOpen = false;"
    >
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </span>
      <span>Settings</span>
    </div>
  </div>

  <!-- Drawer Footer: Logout -->
  <div class="drawer-footer">
    <div class="drawer-menu-item" style="color: #F87171;" @click="logout()">
      <span class="drawer-menu-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </span>
      <span>Sign Out</span>
    </div>
  </div>
</aside>
