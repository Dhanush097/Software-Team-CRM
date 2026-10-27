<!-- ─────────────────────────────────────────
     SCREEN 14: SETTINGS & PREFERENCES
───────────────────────────────────────── -->
<section class="app-screen" id="screen14">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Settings</h2>
    </div>
    <button class="icon-btn" @click="showToast('Help & Support')">❓</button>
  </div>

  <div class="dashboard-body" style="padding-bottom: 30px;">
    <!-- Profile Card -->
    <div class="settings-profile-card">
      <div class="settings-avatar" x-text="user.initials">SK</div>
      <div style="flex: 1; min-width: 0;">
        <div style="font-size: 16px; font-weight: 800; color: var(--text-pri);" x-text="user.name">Suresh Kumar</div>
        <div style="font-size: 12px; color: var(--text-sec); margin-top: 1px;" x-text="user.role">Founder & CEO</div>
        <div style="font-size: 11px; color: var(--text-muted); margin-top: 1px;" x-text="user.email">suresh@softwarecrm.io</div>
      </div>
      <button class="settings-edit-btn" @click="showToast('Edit profile modal')">Edit</button>
    </div>

    <!-- Notification Settings Group -->
    <div class="settings-group-card">
      <div class="settings-group-title">NOTIFICATIONS</div>

      <div class="settings-row" @click="settings.pushNotifications = !settings.pushNotifications">
        <div>
          <div class="settings-item-label">Push Notifications</div>
          <div class="settings-item-sub">Instant alerts for new leads & deals</div>
        </div>
        <div class="toggle-switch" :class="{ 'on': settings.pushNotifications }">
          <div class="toggle-thumb"></div>
        </div>
      </div>

      <div class="settings-row" @click="settings.emailAlerts = !settings.emailAlerts">
        <div>
          <div class="settings-item-label">Email Alerts</div>
          <div class="settings-item-sub">Invoice updates and client replies</div>
        </div>
        <div class="toggle-switch" :class="{ 'on': settings.emailAlerts }">
          <div class="toggle-thumb"></div>
        </div>
      </div>

      <div class="settings-row" @click="settings.weeklyDigest = !settings.weeklyDigest">
        <div>
          <div class="settings-item-label">Weekly Performance Digest</div>
          <div class="settings-item-sub">Every Monday 9:00 AM summary</div>
        </div>
        <div class="toggle-switch" :class="{ 'on': settings.weeklyDigest }">
          <div class="toggle-thumb"></div>
        </div>
      </div>
    </div>

    <!-- App Preferences Group -->
    <div class="settings-group-card">
      <div class="settings-group-title">APP PREFERENCES</div>

      <div class="settings-row" @click="showToast('Currency selection modal')">
        <div>
          <div class="settings-item-label">Default Currency</div>
          <div class="settings-item-sub">Invoices and dashboard metrics</div>
        </div>
        <div style="font-size: 13px; font-weight: 700; color: var(--primary);" x-text="settings.currency">USD ($) ›</div>
      </div>

      <div class="settings-row" @click="navigateTo(9)">
        <div>
          <div class="settings-item-label">Dashboard View Mode</div>
          <div class="settings-item-sub">Switch to compact 2x2 grid</div>
        </div>
        <div style="font-size: 13px; font-weight: 700; color: var(--primary);">Compact View ›</div>
      </div>

      <div class="settings-row" @click="showToast('Language options')">
        <div>
          <div class="settings-item-label">Language</div>
          <div class="settings-item-sub">System interface language</div>
        </div>
        <div style="font-size: 13px; font-weight: 700; color: var(--text-sec);" x-text="settings.language">English ›</div>
      </div>
    </div>

    <!-- Security Group -->
    <div class="settings-group-card">
      <div class="settings-group-title">SECURITY & ACCESS</div>

      <div class="settings-row" @click="showToast('OTP authentication is active')">
        <div>
          <div class="settings-item-label">4-Digit OTP Authentication</div>
          <div class="settings-item-sub">Google Apps Script Email OTP active</div>
        </div>
        <span class="status-badge badge-client">Active</span>
      </div>

      <div class="settings-row" @click="settings.biometricLogin = !settings.biometricLogin">
        <div>
          <div class="settings-item-label">Biometric / FaceID Login</div>
          <div class="settings-item-sub">Fast unlock on mobile device</div>
        </div>
        <div class="toggle-switch" :class="{ 'on': settings.biometricLogin }">
          <div class="toggle-thumb"></div>
        </div>
      </div>
    </div>

    <!-- Sign Out Button -->
    <button class="settings-logout-btn" @click="logout()">
      <span>Sign Out of Software CRM</span>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
    </button>
  </div>
</section>
