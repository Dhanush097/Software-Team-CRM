<!-- ─────────────────────────────────────────
     SCREEN 11: DOCUMENTS & FILES MANAGEMENT
───────────────────────────────────────── -->
<section class="app-screen" id="screen11">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Documents</h2>
    </div>
    <button class="fab-add" @click="showToast('Upload Document modal opened')" title="Upload Document">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Search Input Box -->
  <div class="search-input-box">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
    <input 
      type="text" 
      placeholder="Search documents..." 
      x-model="documentSearch" 
    />
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" style="cursor: pointer;" @click="documentSearch = ''">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
    </svg>
  </div>

  <!-- Category Filter Pills Strip -->
  <div class="filter-pills-row" style="margin-top: 4px;">
    <div 
      class="chip-tab" 
      :class="{ 'active': documentFilter === 'all' }" 
      @click="documentFilter = 'all'"
    >
      All (<span x-text="documents.length">6</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': documentFilter === 'contracts' }" 
      @click="documentFilter = 'contracts'"
    >
      Contracts (<span x-text="documents.filter(d => d.category === 'contracts').length">2</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': documentFilter === 'proposals' }" 
      @click="documentFilter = 'proposals'"
    >
      Proposals (<span x-text="documents.filter(d => d.category === 'proposals').length">2</span>)
    </div>
    <div 
      class="chip-tab" 
      :class="{ 'active': documentFilter === 'invoices' }" 
      @click="documentFilter = 'invoices'"
    >
      Invoices (<span x-text="documents.filter(d => d.category === 'invoices').length">2</span>)
    </div>
  </div>

  <!-- Documents List Container -->
  <div style="flex: 1; overflow-y: auto; padding-bottom: 24px;">
    <template x-for="doc in filteredDocuments" :key="doc.id">
      <div class="document-card-row" @click="showToast('Downloading ' + doc.title + ' (' + doc.size + ')...')">
        <!-- File Icon Badge -->
        <div class="doc-icon-badge" :style="'background: ' + doc.iconBg + '; color: ' + doc.iconColor + ';'">
          <template x-if="doc.type === 'pdf'">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="9" y1="15" x2="15" y2="15"/>
            </svg>
          </template>
          <template x-if="doc.type === 'xlsx'">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="8" y1="13" x2="16" y2="17"/>
              <line x1="16" y1="13" x2="8" y2="17"/>
            </svg>
          </template>
          <template x-if="doc.type === 'docx'">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
              <line x1="16" y1="13" x2="8" y2="13"/>
              <line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
          </template>
          <template x-if="doc.type === 'zip'">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
              <line x1="12" y1="22.08" x2="12" y2="12"/>
            </svg>
          </template>
        </div>

        <!-- Document Details -->
        <div class="doc-info-mid">
          <div class="doc-title-txt" x-text="doc.title">Project_Proposal_v2.pdf</div>
          <div class="doc-meta-txt">
            <span x-text="doc.client">ABC Tech Solutions</span>
            <span>·</span>
            <span x-text="doc.date">12 Aug 2024</span>
          </div>
        </div>

        <!-- Size Badge & Download Icon -->
        <div class="doc-action-right">
          <div class="doc-size-tag" x-text="doc.size">2.4 MB</div>
          <button class="doc-download-btn" @click.stop="showToast('Downloading ' + doc.title)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/>
              <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
          </button>
        </div>
      </div>
    </template>

    <div x-show="filteredDocuments.length === 0" style="padding: 40px 20px; text-align: center; color: var(--text-sec);">
      <p style="font-size: 14px;">No documents match your filter.</p>
    </div>
  </div>
</section>
