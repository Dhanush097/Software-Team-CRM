<!-- ─────────────────────────────────────────
     SCREEN 2: MAIN DASHBOARD
───────────────────────────────────────── -->
<section class="app-screen" id="screen2">
  <!-- Top Navigation Header -->
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
      <button class="icon-btn" @click="showToast('You have 2 unread client notifications!')">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="badge-dot">2</span>
      </button>
      <div class="user-avatar-btn" @click="drawerOpen = true" x-text="user.initials">SK</div>
    </div>
  </div>

  <!-- Dashboard Body Content -->
  <div class="dashboard-body">

    <!-- Greeting Banner -->
    <div class="welcome-banner">
      <div>
        <h2 x-text="'Good morning, ' + user.name.split(' ')[0] + '! 👋'">Good morning, Suresh! 👋</h2>
        <p>Here's what's happening today</p>
      </div>
      <div class="dashboard-view-mode-pill" @click="navigateTo(9)">
        Compact View ⤢
      </div>
    </div>

    <!-- 2×2 Metric KPI Grid -->
    <div class="kpi-grid">
      <!-- Total Contacts -->
      <div class="kpi-card" @click="navigateTo(3)">
        <div class="kpi-card-top">
          <span class="kpi-label">Total Contacts</span>
          <div class="kpi-icon-circle" style="background: #EFF6FF; color: var(--primary);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" x-text="stats.total_contacts">86</div>
        <div class="kpi-trend trend-up">↑ 12%</div>
      </div>

      <!-- Active Projects -->
      <div class="kpi-card" @click="navigateTo(5)">
        <div class="kpi-card-top">
          <span class="kpi-label">Active Projects</span>
          <div class="kpi-icon-circle" style="background: #DCFCE7; color: var(--success);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" x-text="stats.active_projects">12</div>
        <div class="kpi-trend trend-up">↑ 20%</div>
      </div>

      <!-- Pending Tasks -->
      <div class="kpi-card" @click="navigateTo(6)">
        <div class="kpi-card-top">
          <span class="kpi-label">Pending Tasks</span>
          <div class="kpi-icon-circle" style="background: #FEF9C3; color: #CA8A04;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="9 11 12 14 22 4"/>
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" x-text="stats.pending_tasks">8</div>
        <div class="kpi-trend trend-down">↓ 14%</div>
      </div>

      <!-- Total Revenue -->
      <div class="kpi-card" @click="navigateTo(8)">
        <div class="kpi-card-top">
          <span class="kpi-label">Total Revenue</span>
          <div class="kpi-icon-circle" style="background: var(--lead-bg); color: var(--lead);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="12" y1="1" x2="12" y2="23"/>
              <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
          </div>
        </div>
        <div class="kpi-number" x-text="stats.revenue">$12,450</div>
        <div class="kpi-trend trend-up">↑ 32%</div>
      </div>
    </div>

    <!-- Recent Activity Feed -->
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
            <div class="activity-sub" x-text="act.subtitle">Rahul Sharma · ABC Tech Solutions</div>
          </div>
          <div class="activity-time" x-text="act.time">10m ago</div>
        </div>
      </template>
    </div>

  </div>
</section>
