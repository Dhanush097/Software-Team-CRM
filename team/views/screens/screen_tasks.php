<!-- ─────────────────────────────────────────
     SCREEN 6: TASK MANAGEMENT
───────────────────────────────────────── -->
<section class="app-screen" id="screen6">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Tasks</h2>
    </div>
    <button class="fab-add" @click="addTaskModal = true" title="Create New Task">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Filter Pills Strip -->
  <div class="filter-pills-row" style="margin-top: 4px;">
    <div 
      class="chip-tab" 
      :class="{ 'active': taskFilter === 'my' }" 
      @click="taskFilter = 'my'"
    >
      My Tasks (8)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': taskFilter === 'all' }" 
      @click="taskFilter = 'all'"
    >
      All Tasks (24)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': taskFilter === 'overdue' }" 
      @click="taskFilter = 'overdue'"
    >
      Overdue (3)
    </div>
  </div>

  <!-- Tasks List -->
  <div style="flex: 1;">
    <template x-for="task in filteredTasks" :key="task.id">
      <div class="task-item-card" @click="toggleTask(task)">
        <!-- Custom Checkbox -->
        <div class="task-checkbox" :class="{ 'checked': task.completed }">
          <svg x-show="task.completed" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        </div>

        <!-- Task Content -->
        <div class="task-content">
          <div 
            class="task-title-txt" 
            :class="{ 'strikethrough': task.completed }" 
            x-text="task.title"
          >
            Design Homepage
          </div>
          <div class="task-due-info">
            <span :style="'color: ' + (task.overdue ? 'var(--danger)' : 'var(--warning)')">⏰</span>
            <span x-text="task.project">E-commerce Website</span>
          </div>
        </div>

        <!-- Priority & Due -->
        <div style="text-align: right;">
          <span 
            class="status-badge" 
            :class="{
              'badge-high': task.priority === 'High',
              'badge-medium': task.priority === 'Medium',
              'badge-low': task.priority === 'Low'
            }" 
            x-text="task.priority"
          >High</span>
          <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;" x-text="task.due">Today</div>
        </div>
      </div>
    </template>
  </div>
</section>
