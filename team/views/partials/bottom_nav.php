<!-- Global Persistent Bottom Navigation Bar -->
<nav class="global-bottom-bar" id="globalBottomBar" :class="{ 'hidden': currentScreen === 1 }">
  <!-- Home Tab -->
  <button 
    class="nav-tab-btn" 
    :class="{ 'active': currentScreen === 2 || currentScreen === 9 }" 
    @click="navigateTo(2)"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
      <polyline points="9 22 9 12 15 12 15 22"/>
    </svg>
    <span>Home</span>
  </button>

  <!-- Contacts Tab -->
  <button 
    class="nav-tab-btn" 
    :class="{ 'active': currentScreen === 3 || currentScreen === 4 }" 
    @click="navigateTo(3)"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
      <circle cx="9" cy="7" r="4"/>
      <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
      <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
    <span>Contacts</span>
  </button>

  <!-- Projects Tab -->
  <button 
    class="nav-tab-btn" 
    :class="{ 'active': currentScreen === 5 }" 
    @click="navigateTo(5)"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
    </svg>
    <span>Projects</span>
  </button>

  <!-- Tasks Tab -->
  <button 
    class="nav-tab-btn" 
    :class="{ 'active': currentScreen === 6 }" 
    @click="navigateTo(6)"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path d="M9 11l3 3L22 4"/>
      <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
    </svg>
    <span>Tasks</span>
  </button>

  <!-- More / Drawer Menu Tab -->
  <button 
    class="nav-tab-btn" 
    :class="{ 'active': drawerOpen || (currentScreen >= 7 && currentScreen !== 9) }"
    @click="drawerOpen = true"
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <circle cx="12" cy="12" r="1"/>
      <circle cx="19" cy="12" r="1"/>
      <circle cx="5" cy="12" r="1"/>
    </svg>
    <span>More</span>
  </button>
</nav>
