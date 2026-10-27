<!-- ─────────────────────────────────────────
     SCREEN 9: MOBILE DASHBOARD (COMPACT 2x2)
───────────────────────────────────────── -->
<section class="app-screen" id="screen9">
  <!-- Nav Header -->
  <div class="crm-nav-header">
    <div class="crm-brand-title">
      <div class="logo-box">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
          <rect x="2" y="12" width="4" height="9" rx="1" fill="#FFFFFF"/>
          <rect x="8" y="7" width="4" height="14" rx="1" fill="#FFFFFF"/>
          <rect x="14" y="3" width="4" height="18" rx="1" fill="#FFFFFF"/>
        </svg>
      </div>
      <div class="name-txt">Software CRM</div>
    </div>
    <div class="header-action-group">
      <button class="icon-btn" @click="showToast('2 notifications')">🔔</button>
      <div class="user-avatar-btn" @click="drawerOpen = true" x-text="user.initials">SK</div>
    </div>
  </div>

  <!-- Body -->
  <div class="dashboard-body">
    <div class="welcome-banner">
      <div>
        <h2 x-text="'Good morning, ' + user.name.split(' ')[0] + '! 👋'">Good morning, Suresh! 👋</h2>
        <p style="font-size: 11px; color: var(--text-sec); margin-top: 2px;">Quick Overview</p>
      </div>
      <div class="dashboard-view-mode-pill" @click="navigateTo(2)">
        <span>Standard View ⤢</span>
      </div>
    </div>

    <!-- 2x2 Large Numbers Grid -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
      <div class="kpi-card" style="padding: 18px 14px;" @click="navigateTo(3)">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span class="kpi-label">Contacts</span>
          <div style="width: 32px; height: 32px; border-radius: 8px; background: #DBEAFE; color: #2563EB; display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" style="font-size: 28px; margin-top: 4px;" x-text="stats.total_contacts">86</div>
      </div>

      <div class="kpi-card" style="padding: 18px 14px;" @click="navigateTo(5)">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span class="kpi-label">Projects</span>
          <div style="width: 32px; height: 32px; border-radius: 8px; background: #FEF3C7; color: #D97706; display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" style="font-size: 28px; margin-top: 4px;" x-text="stats.active_projects">12</div>
      </div>

      <div class="kpi-card" style="padding: 18px 14px;" @click="navigateTo(6)">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span class="kpi-label">Tasks</span>
          <div style="width: 32px; height: 32px; border-radius: 8px; background: #D1FAE5; color: #059669; display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 11l3 3L22 4"/>
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" style="font-size: 28px; margin-top: 4px;" x-text="stats.pending_tasks">8</div>
      </div>

      <div class="kpi-card" style="padding: 18px 14px;" @click="navigateTo(8)">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span class="kpi-label">Revenue</span>
          <div style="width: 32px; height: 32px; border-radius: 8px; background: #EDE9FE; color: #7C3AED; display: flex; align-items: center; justify-content: center;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="12" y1="1" x2="12" y2="23"/>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" style="font-size: 24px; margin-top: 4px;" x-text="stats.revenue">$12.4K</div>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="content-box">
      <div class="box-header">
        <h3>Recent Activity</h3>
        <a class="link-text" @click="navigateTo(3)">View All</a>
      </div>

      <template x-for="act in recentActivities" :key="act.id">
        <div class="activity-row" @click="navigateTo(act.targetScreen || 3)">
          <div class="activity-icon" :style="'background: ' + act.bg + '; color: ' + act.color + ';'" x-text="act.icon">👤</div>
          <div class="activity-details">
            <div class="activity-title" x-text="act.title">New contact added</div>
            <div class="activity-sub" x-text="act.subtitle">Rahul Sharma</div>
          </div>
          <div class="activity-time" x-text="act.time">10m ago</div>
        </div>
      </template>
    </div>
  </div>
</section>
