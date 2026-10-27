<!-- ─────────────────────────────────────────
     SCREEN 13: REPORTS & ANALYTICS
───────────────────────────────────────── -->
<section class="app-screen" id="screen13">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Reports</h2>
    </div>
    <button class="export-report-btn" @click="showToast('Exporting PDF Report...')">
      <span>Export</span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M7 17l9.2-9.2M17 17V8H8"/>
      </svg>
    </button>
  </div>

  <!-- Period Tabs -->
  <div class="filter-pills-row">
    <div 
      class="chip-tab" 
      :class="{ 'active': reportPeriod === 'month' }" 
      @click="reportPeriod = 'month'"
    >
      This Month (Aug)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': reportPeriod === 'quarter' }" 
      @click="reportPeriod = 'quarter'"
    >
      Quarter 3
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': reportPeriod === 'year' }" 
      @click="reportPeriod = 'year'"
    >
      Year 2024
    </div>
  </div>

  <!-- Body -->
  <div class="dashboard-body" style="padding-bottom: 24px;">
    <!-- Revenue Hero Card -->
    <div class="report-hero-card">
      <div style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
          <span style="font-size: 11px; font-weight: 600; color: var(--text-sec);">TOTAL REVENUE (AUG 2024)</span>
          <div style="font-size: 32px; font-weight: 800; color: var(--text-pri); margin-top: 4px; letter-spacing: -1px;">$12,450</div>
        </div>
        <div class="kpi-trend trend-up">
          ↑ 32.4%
        </div>
      </div>

      <!-- Bar Chart -->
      <div style="margin-top: 18px;">
        <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); margin-bottom: 12px; display: flex; justify-content: space-between;">
          <span>MONTHLY REVENUE TRAJECTORY</span>
          <span>Target: $10,000/mo</span>
        </div>

        <div class="bar-chart-container">
          <template x-for="item in monthlyRevenueData" :key="item.month">
            <div class="chart-bar-col" @click="showToast(item.month + ': ' + item.revenue)">
              <div class="chart-bar-wrap">
                <div 
                  class="chart-bar-fill" 
                  :style="'height: ' + item.heightPct + '%;' + (item.month === 'Aug' ? ' background: var(--primary);' : '')"
                ></div>
              </div>
              <div class="chart-bar-label" :style="item.month === 'Aug' ? 'color: var(--primary); font-weight: 700;' : ''" x-text="item.month">Jan</div>
            </div>
          </template>
        </div>
      </div>
    </div>

    <!-- 2x2 Metric Breakdown Grid -->
    <div class="kpi-grid">
      <div class="kpi-card" @click="navigateTo(3)">
        <div class="kpi-card-top">
          <span class="kpi-label">New Clients</span>
          <div class="kpi-icon-circle" style="background: #EFF6FF; color: var(--primary);">👥</div>
        </div>
        <div class="kpi-number">+14</div>
        <div class="kpi-trend trend-up">↑ 18% vs Jul</div>
      </div>

      <div class="kpi-card" @click="navigateTo(5)">
        <div class="kpi-card-top">
          <span class="kpi-label">Active Deals</span>
          <div class="kpi-icon-circle" style="background: #DCFCE7; color: var(--success);">📁</div>
        </div>
        <div class="kpi-number">12</div>
        <div class="kpi-trend trend-up">↑ 3 new</div>
      </div>

      <div class="kpi-card" @click="navigateTo(6)">
        <div class="kpi-card-top">
          <span class="kpi-label">Tasks Closed</span>
          <div class="kpi-icon-circle" style="background: #FEF9C3; color: #CA8A04;">☑️</div>
        </div>
        <div class="kpi-number">21</div>
        <div class="kpi-trend trend-up">92% velocity</div>
      </div>

      <div class="kpi-card" @click="navigateTo(8)">
        <div class="kpi-card-top">
          <span class="kpi-label">Invoices Cleared</span>
          <div class="kpi-icon-circle" style="background: var(--lead-bg); color: var(--lead);">🧾</div>
        </div>
        <div class="kpi-number">$7.2K</div>
        <div class="kpi-trend trend-up">100% on time</div>
      </div>
    </div>

    <!-- Conversion Funnel Summary Box -->
    <div class="content-box">
      <div class="box-header">
        <h3>Pipeline Conversion Rate</h3>
        <span style="font-size: 13px; font-weight: 800; color: var(--success);">68.2%</span>
      </div>
      <div style="padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;">
        <div class="funnel-row">
          <span class="funnel-label">Leads Contacted</span>
          <span class="funnel-val">86 Leads (100%)</span>
        </div>
        <div class="funnel-row">
          <span class="funnel-label">Proposals Sent</span>
          <span class="funnel-val">42 Proposals (48.8%)</span>
        </div>
        <div class="funnel-row">
          <span class="funnel-label">Active Contracts</span>
          <span class="funnel-val">12 Clients (13.9%)</span>
        </div>
      </div>
    </div>
  </div>
</section>
