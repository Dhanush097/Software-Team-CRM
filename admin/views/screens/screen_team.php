<!-- ─────────────────────────────────────────
     SCREEN 12: TEAM MANAGEMENT
───────────────────────────────────────── -->
<section class="app-screen" id="screen12">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Team</h2>
    </div>
    <button class="fab-add" @click="showToast('Invite Team Member modal opened')" title="Invite Member">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Team Status Banner -->
  <div style="padding: 12px 18px 4px; display: flex; justify-content: space-between; align-items: center;">
    <div style="font-size: 13px; font-weight: 700; color: var(--text-pri);">
      <span x-text="teamMembers.length">5</span> Members
      <span style="color: var(--text-sec); font-weight: 500;">·</span>
      <span style="color: var(--success);" x-text="teamMembers.filter(m => m.status === 'online').length + ' Online'">4 Online</span>
    </div>
    <div style="font-size: 11px; font-weight: 600; color: var(--primary); cursor: pointer;" @click="showToast('Manage roles')">
      Manage Roles ⚙️
    </div>
  </div>

  <!-- Filter Pills Strip -->
  <div class="filter-pills-row" style="margin-top: 6px;">
    <div 
      class="chip-tab" 
      :class="{ 'active': teamFilter === 'all' }" 
      @click="teamFilter = 'all'"
    >
      All (<span x-text="teamMembers.length">5</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': teamFilter === 'management' }" 
      @click="teamFilter = 'management'"
    >
      Management (<span x-text="teamMembers.filter(m => m.department === 'management').length">2</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': teamFilter === 'design' }" 
      @click="teamFilter = 'design'"
    >
      Design (<span x-text="teamMembers.filter(m => m.department === 'design').length">1</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': teamFilter === 'dev' }" 
      @click="teamFilter = 'dev'"
    >
      Engineering (<span x-text="teamMembers.filter(m => m.department === 'dev').length">2</span>)
    </div>
  </div>

  <!-- Team Member List -->
  <div style="flex: 1; overflow-y: auto; padding-bottom: 24px;">
    <template x-for="member in filteredTeam" :key="member.id">
      <div class="team-member-card" @click="showToast('Viewing ' + member.name)">
        <!-- Avatar with Online Status Dot -->
        <div class="team-avatar-wrap">
          <div class="team-avatar" :style="'background: ' + member.avatarBg" x-text="member.initials">
            SK
          </div>
          <span 
            class="team-status-dot" 
            :class="{
              'status-online': member.status === 'online',
              'status-away': member.status === 'away',
              'status-offline': member.status === 'offline'
            }"
          ></span>
        </div>

        <!-- Info -->
        <div class="team-member-info">
          <div style="display: flex; align-items: center; gap: 6px;">
            <div class="team-name-txt" x-text="member.name">Suresh Kumar</div>
            <span 
              class="status-badge"
              :class="{
                'badge-client': member.department === 'management',
                'badge-lead': member.department === 'design',
                'badge-prospect': member.department === 'dev'
              }"
              x-text="member.department"
            ></span>
          </div>
          <div class="team-role-txt" x-text="member.role">Founder & CEO</div>
          <div class="team-sub-meta">
            <span x-text="member.email">suresh@softwarecrm.io</span>
            <span>·</span>
            <span x-text="member.tasksAssigned + ' tasks active'">4 tasks active</span>
          </div>
        </div>

        <!-- Quick Action Buttons -->
        <div class="team-actions-row">
          <button class="team-quick-btn" @click.stop="showToast('Calling ' + member.name + ' (' + member.phone + ')')">
            📞
          </button>
          <button class="team-quick-btn" @click.stop="showToast('Emailing ' + member.name)">
            ✉️
          </button>
        </div>
      </div>
    </template>
  </div>
</section>
