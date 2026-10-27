<!-- ─────────────────────────────────────────
     SCREEN 5: PROJECTS MANAGEMENT
───────────────────────────────────────── -->
<section class="app-screen" id="screen5">
  <!-- Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Projects</h2>
    </div>
    <button class="fab-add" @click="showToast('New Project modal opened')" title="New Project">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Filter Pills -->
  <div class="filter-pills-row">
    <div class="chip-tab" :class="{ 'active': projectFilter === 'all' }" @click="projectFilter = 'all'">
      All (<span x-text="projects.length">12</span>)
    </div>
    <div class="chip-tab" :class="{ 'active': projectFilter === 'active' }" @click="projectFilter = 'active'">
      Active (<span x-text="projects.filter(p => p.status === 'active').length">8</span>)
    </div>
    <div class="chip-tab" :class="{ 'active': projectFilter === 'completed' }" @click="projectFilter = 'completed'">
      Completed (<span x-text="projects.filter(p => p.status === 'completed').length">3</span>)
    </div>
  </div>

  <!-- Projects List Container -->
  <div style="flex: 1; overflow-y: auto; padding-bottom: 24px;">
    <template x-for="project in filteredProjects" :key="project.id">
      <div class="project-card-item" @click="showToast('Viewing ' + project.title)">
        <div class="project-top-row">
          <!-- Coloured icon box -->
          <div class="project-icon-badge" :style="'background: ' + project.bg + '; color: ' + project.color + ';'" x-text="project.icon">
            🛒
          </div>
          <!-- Info -->
          <div class="project-info">
            <div class="project-title" x-text="project.title">E-commerce Website</div>
            <div class="project-client" x-text="project.client">ABC Tech Solutions</div>
          </div>
          <!-- Options menu -->
          <div style="color: var(--text-muted); font-size: 16px; cursor: pointer; padding: 0 4px;" @click.stop="showToast('Project settings')">
            ⋮
          </div>
        </div>

        <!-- Progress Row -->
        <div>
          <div style="display: flex; justify-content: flex-end; margin-bottom: 5px;">
            <span
              class="project-progress-pct"
              :style="'color: ' + (project.progress === 100 ? 'var(--success)' : 'var(--primary)')"
              x-text="project.progress + '%'"
            >
              70%
            </span>
          </div>
          <!-- Progress Bar -->
          <div class="progress-bar-wrap">
            <div
              class="progress-bar-fill"
              :style="'width: ' + project.progress + '%;' + (project.progress === 100 ? ' background: var(--success);' : '')"
            ></div>
          </div>
        </div>
      </div>
    </template>

    <div x-show="filteredProjects.length === 0" style="padding: 40px 20px; text-align: center; color: var(--text-sec);">
      <p style="font-size: 14px;">No projects match the selected filter.</p>
    </div>
  </div>
</section>
