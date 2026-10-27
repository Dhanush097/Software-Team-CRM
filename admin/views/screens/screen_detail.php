<!-- ─────────────────────────────────────────
     SCREEN 4: CONTACT DETAIL VIEW (WORTAL AESTHETIC - IMAGE 4)
───────────────────────────────────────── -->
<section class="app-screen" id="screen4">
  <!-- Top Bar with Back Arrow, Contact Name, Dark Toggle & Hamburger -->
  <div class="detail-header-bar">
    <div class="detail-header-left">
      <button class="detail-back-btn" @click="historyStack.length > 1 ? goBack() : navigateTo(3)" title="Back to Contacts">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="m15 18-6-6 6-6"/>
        </svg>
      </button>
      <div class="detail-header-title-box">
        <h2 class="detail-header-name" x-text="selectedContact ? selectedContact.name : 'Contact Details'">Rahul Sharma</h2>
        <p class="detail-header-sub" x-text="selectedContact ? (selectedContact.phone || selectedContact.company || '') : ''">+91 98765-43210</p>
      </div>
    </div>

    <div class="detail-header-right">
      <!-- Dark/Light Mode Switcher -->
      <button class="detail-header-icon-btn" @click="toggleTheme()" title="Toggle Dark Mode">
        <span x-show="!settings.darkMode">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
          </svg>
        </span>
        <span x-show="settings.darkMode">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>
          </svg>
        </span>
      </button>

      <!-- Hamburger Menu Button -->
      <button class="detail-header-icon-btn" @click="drawerOpen = true" title="Open Navigation Menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- 5-Card Navigation Grid Bar (Strictly Matching Image 4 with Teal Accent) -->
  <div class="detail-nav-grid-container">
    <div class="detail-nav-grid">
      <!-- 1. Info Tab -->
      <button 
        class="detail-nav-tab" 
        :class="{ 'active': contactDetailTab === 'info' }" 
        @click="contactDetailTab = 'info'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
        </svg>
        <span>Info</span>
      </button>

      <!-- 2. History Tab -->
      <button 
        class="detail-nav-tab" 
        :class="{ 'active': contactDetailTab === 'history' }" 
        @click="contactDetailTab = 'history'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>
        </svg>
        <span>History</span>
      </button>

      <!-- 3. Media Tab -->
      <button 
        class="detail-nav-tab" 
        :class="{ 'active': contactDetailTab === 'media' }" 
        @click="contactDetailTab = 'media'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>
        </svg>
        <span>Media</span>
      </button>

      <!-- 4. Tasks Tab -->
      <button 
        class="detail-nav-tab" 
        :class="{ 'active': contactDetailTab === 'tasks' }" 
        @click="contactDetailTab = 'tasks'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        <span>Tasks</span>
      </button>

      <!-- 5. More Tab -->
      <button 
        class="detail-nav-tab" 
        :class="{ 'active': contactDetailTab === 'more' }" 
        @click="contactDetailTab = 'more'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/><circle cx="5" cy="12" r="1.5"/>
        </svg>
        <span>More</span>
      </button>
    </div>
  </div>

  <!-- Tab Content Scrollable Container -->
  <div class="detail-scrollable-content" x-show="selectedContact">

    <!-- ==============================================
         TAB CONTENT 1: ℹ️ INFO (CONTACT PROFILE)
         ============================================== -->
    <div x-show="contactDetailTab === 'info'" class="detail-tab-pane">

      <!-- Avatar Hero Header (Wortal UI Style) -->
      <div class="wortal-hero-header">
        <div 
          class="wortal-avatar-box" 
          :style="'background: ' + (selectedContact.avatarBg || '#007788')" 
          x-text="selectedContact && selectedContact.name ? selectedContact.name.charAt(0).toUpperCase() : '?'"
        >
          R
        </div>

        <div class="wortal-hero-title-group">
          <h2 class="wortal-hero-name" x-text="selectedContact ? selectedContact.name : ''">Rahul Sharma</h2>
          <p class="wortal-hero-sub" x-text="selectedContact ? (selectedContact.phone || selectedContact.company || '') : ''">+91 98765-43210</p>
        </div>

        <!-- Edit Profile & Star Quick Actions (Wortal Pills) -->
        <div class="wortal-pills-row">
          <button class="wortal-pill active" @click="showToast('Editing ' + selectedContact.name)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>
            </svg>
            <span>Edit Profile</span>
          </button>

          <button class="wortal-pill" @click="selectedContact.is_starred = !selectedContact.is_starred; showToast(selectedContact.is_starred ? 'Starred ' + selectedContact.name : 'Removed Star')">
            <span x-text="selectedContact && selectedContact.is_starred ? '⭐' : '☆'">☆</span>
            <span x-text="selectedContact && selectedContact.is_starred ? 'Starred' : 'Star'">Star</span>
          </button>
        </div>

        <!-- 5 Outline Vector Action Buttons on White Card (WhatsApp, Call, Mail, Quote, Share) -->
        <div class="wortal-actions-row">
          <!-- WhatsApp -->
          <a 
            :href="'https://wa.me/' + (selectedContact && selectedContact.phone ? selectedContact.phone.replace(/[^0-9]/g, '') : '')" 
            target="_blank" 
            class="wortal-action-square-btn btn-wa" 
            title="WhatsApp"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>
            </svg>
          </a>

          <!-- Direct Call -->
          <a 
            :href="'tel:' + (selectedContact ? selectedContact.phone : '')" 
            class="wortal-action-square-btn btn-call" 
            title="Direct Call"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
          </a>

          <!-- Send Email -->
          <a 
            :href="'mailto:' + (selectedContact && selectedContact.email ? selectedContact.email : '')" 
            class="wortal-action-square-btn btn-mail" 
            title="Send Email"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
          </a>

          <!-- Quotation Button -->
          <button 
            class="wortal-action-square-btn btn-quote" 
            @click="showToast('Generate Quotation for ' + selectedContact.name)" 
            title="Generate Quotation"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/>
            </svg>
          </button>

          <!-- Share Contact -->
          <button 
            class="wortal-action-square-btn btn-share" 
            @click="if (navigator.share) { navigator.share({title: selectedContact.name, text: selectedContact.name + ' - ' + selectedContact.phone}); } else { showToast('Contact copied to clipboard'); }" 
            title="Share Contact"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- Numbers & Info Card (Wortal Card) -->
      <div class="wortal-card">
        <!-- Mobile Primary Row -->
        <div class="wortal-card-row">
          <div class="wortal-card-info">
            <span class="wortal-label">Mobile (Primary)</span>
            <span class="wortal-mono-val" x-text="selectedContact ? selectedContact.phone : 'Not set'">+91 98765-43210</span>
          </div>
          <div class="wortal-quick-btns">
            <a 
              :href="'https://wa.me/' + (selectedContact && selectedContact.phone ? selectedContact.phone.replace(/[^0-9]/g, '') : '')" 
              target="_blank" 
              class="wortal-quick-btn wa" 
              title="WhatsApp"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>
              </svg>
            </a>
            <a 
              :href="'tel:' + (selectedContact ? selectedContact.phone : '')" 
              class="wortal-quick-btn call" 
              title="Call"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
              </svg>
            </a>
          </div>
        </div>

        <!-- Mobile Secondary / Alternate Row -->
        <div class="wortal-card-row">
          <div class="wortal-card-info">
            <div style="display: flex; align-items: center; gap: 6px;">
              <span class="wortal-label">Mobile (Secondary)</span>
              <span class="wortal-edit-link" @click="showToast('Edit secondary number')">Edit</span>
            </div>
            <span class="wortal-mono-val" x-text="selectedContact && selectedContact.phone2 ? selectedContact.phone2 : '+91 98765 43211'">+91 98765 43211</span>
          </div>
          <div class="wortal-quick-btns">
            <a 
              :href="'https://wa.me/' + (selectedContact && selectedContact.phone2 ? selectedContact.phone2.replace(/[^0-9]/g, '') : '919876543211')" 
              target="_blank" 
              class="wortal-quick-btn wa" 
              title="WhatsApp Alternate"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>
              </svg>
            </a>
            <a 
              :href="'tel:' + (selectedContact && selectedContact.phone2 ? selectedContact.phone2 : '+919876543211')" 
              class="wortal-quick-btn call" 
              title="Call Alternate"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
              </svg>
            </a>
          </div>
        </div>

        <!-- Location Address Row -->
        <div class="wortal-card-row">
          <div class="wortal-card-info">
            <span class="wortal-label">Location Address</span>
            <span class="wortal-text-val" x-text="selectedContact && (selectedContact.address || selectedContact.location) ? (selectedContact.address || selectedContact.location) : '123 Business Bay, Mumbai, MH'">123 Business Bay, Mumbai, MH</span>
          </div>
          <div class="wortal-quick-btns">
            <button class="wortal-quick-btn location" @click="showToast('Address copied to clipboard')" title="Copy Location">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
              </svg>
            </button>
          </div>
        </div>
      </div>

      <!-- Referral & Status Info (Wortal Card) -->
      <div class="wortal-card wortal-meta-card">
        <div class="wortal-meta-row">
          <div style="display: flex; align-items: center; gap: 8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#007788" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><line x1="6" x2="6" y1="9" y2="21"/>
            </svg>
            <span style="color: var(--text-sec); font-size: 12px;">Referred By:</span>
            <strong style="font-size: 12px; color: var(--text-pri);" x-text="selectedContact && selectedContact.source ? selectedContact.source : 'Direct / Organic'">Direct / Organic</strong>
          </div>
          <button class="wortal-link-btn" @click="contactSearch = selectedContact.source || ''; navigateTo(3)">Filter Leads</button>
        </div>

        <div class="wortal-meta-divider"></div>

        <div class="wortal-meta-row">
          <span style="color: var(--text-sec); font-size: 12px;">Business / Category:</span>
          <strong style="font-size: 12px; color: var(--text-pri);" x-text="selectedContact ? (selectedContact.company || selectedContact.category || 'Client') : 'Client'">Client</strong>
        </div>
      </div>

      <!-- Generate Quotation Action Banner -->
      <button class="wortal-quotation-banner" @click="showToast('Quotation Generator launched')">
        <div class="wortal-banner-left">
          <div class="wortal-banner-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/>
            </svg>
          </div>
          <div class="wortal-banner-text">
            <div class="wortal-banner-title">Generate Quotation</div>
            <div class="wortal-banner-subtitle">PDF • Image • WhatsApp Message</div>
          </div>
        </div>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>

      <!-- Bottom Action Icons (Quote, Location, QR Code, Share Contact) -->
      <div class="wortal-bottom-grid">
        <button class="wortal-bottom-card" @click="showToast('Quotation Generator')">
          <div class="wortal-bottom-icon violet">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/>
            </svg>
          </div>
          <span class="wortal-bottom-label violet">Quotation</span>
        </button>

        <button class="wortal-bottom-card" @click="showToast('Viewing Location on Map')">
          <div class="wortal-bottom-icon blue">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
            </svg>
          </div>
          <span class="wortal-bottom-label">Location</span>
        </button>

        <button class="wortal-bottom-card" @click="showToast('QR Code Generated')">
          <div class="wortal-bottom-icon purple">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>
            </svg>
          </div>
          <span class="wortal-bottom-label">QR Code</span>
        </button>

        <button class="wortal-bottom-card" @click="if (navigator.share) { navigator.share({title: selectedContact.name, text: selectedContact.name + ' - ' + selectedContact.phone}); } else { showToast('Contact copied'); }">
          <div class="wortal-bottom-icon emerald">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
          </div>
          <span class="wortal-bottom-label">Share</span>
        </button>
      </div>

    </div>

    <!-- ==============================================
         TAB CONTENT 2: 🕒 HISTORY / FOLLOW-UPS
         ============================================== -->
    <div x-show="contactDetailTab === 'history'" class="detail-tab-pane">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="font-size: 14px; font-weight: 700; color: var(--text-pri);">Interaction Timeline</h3>
        <button class="wortal-pill active" @click="showToast('Add note or follow-up')">+ Log Activity</button>
      </div>

      <div class="wortal-card" style="margin-top: 0;">
        <div style="display: flex; flex-direction: column; gap: 14px;">
          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <div style="width: 32px; height: 32px; border-radius: 10px; background: #ECFDF5; color: #10B981; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">📞</div>
            <div style="flex: 1;">
              <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <span style="font-size: 13px; font-weight: 700; color: var(--text-pri);">Outbound Call Completed</span>
                <span style="font-size: 11px; color: var(--text-muted);">2h ago</span>
              </div>
              <p style="font-size: 12px; color: var(--text-sec); margin-top: 2px;">Discussed proposal pricing and kickoff timelines for CRM deployment.</p>
            </div>
          </div>

          <div style="height: 1px; background: var(--border);"></div>

          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <div style="width: 32px; height: 32px; border-radius: 10px; background: #EFF6FF; color: #2563EB; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">💬</div>
            <div style="flex: 1;">
              <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <span style="font-size: 13px; font-weight: 700; color: var(--text-pri);">WhatsApp Follow-up</span>
                <span style="font-size: 11px; color: var(--text-muted);">Yesterday</span>
              </div>
              <p style="font-size: 12px; color: var(--text-sec); margin-top: 2px;">Sent updated quotation PDF and feature overview brochure.</p>
            </div>
          </div>

          <div style="height: 1px; background: var(--border);"></div>

          <div style="display: flex; gap: 12px; align-items: flex-start;">
            <div style="width: 32px; height: 32px; border-radius: 10px; background: #FAF5FF; color: #9333EA; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 14px;">📝</div>
            <div style="flex: 1;">
              <div style="display: flex; justify-content: space-between; align-items: baseline;">
                <span style="font-size: 13px; font-weight: 700; color: var(--text-pri);">Client Initial Contact</span>
                <span style="font-size: 11px; color: var(--text-muted);">12 Aug</span>
              </div>
              <p style="font-size: 12px; color: var(--text-sec); margin-top: 2px;">Lead generated from website inquiry form.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ==============================================
         TAB CONTENT 3: 🖼️ MEDIA & DOCUMENTS
         ============================================== -->
    <div x-show="contactDetailTab === 'media'" class="detail-tab-pane">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="font-size: 14px; font-weight: 700; color: var(--text-pri);">Shared Files & Media</h3>
        <button class="wortal-pill active" @click="showToast('Upload File')">+ Upload</button>
      </div>

      <div class="wortal-card" style="margin-top: 0;">
        <div style="display: flex; flex-direction: column; gap: 12px;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <div style="width: 36px; height: 36px; border-radius: 10px; background: #FEE2E2; color: #DC2626; display: flex; align-items: center; justify-content: center; font-size: 14px;">📄</div>
              <div>
                <div style="font-size: 13px; font-weight: 700; color: var(--text-pri);">Service_Agreement_v2.pdf</div>
                <div style="font-size: 11px; color: var(--text-muted);">1.8 MB • Added 14 Aug</div>
              </div>
            </div>
            <button class="wortal-quick-btn location" @click="showToast('Downloading Service Agreement')">⬇️</button>
          </div>

          <div style="height: 1px; background: var(--border);"></div>

          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <div style="width: 36px; height: 36px; border-radius: 10px; background: #E0E7FF; color: #4F46E5; display: flex; align-items: center; justify-content: center; font-size: 14px;">📊</div>
              <div>
                <div style="font-size: 13px; font-weight: 700; color: var(--text-pri);">Quotation_Estimate_Aug24.xlsx</div>
                <div style="font-size: 11px; color: var(--text-muted);">450 KB • Added 10 Aug</div>
              </div>
            </div>
            <button class="wortal-quick-btn location" @click="showToast('Downloading Quotation')">⬇️</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ==============================================
         TAB CONTENT 4: 📋 TASKS & PROJECTS
         ============================================== -->
    <div x-show="contactDetailTab === 'tasks'" class="detail-tab-pane">
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <h3 style="font-size: 14px; font-weight: 700; color: var(--text-pri);">Associated Tasks</h3>
        <button class="wortal-pill active" @click="showToast('Add new task')">+ Task</button>
      </div>

      <div class="wortal-card" style="margin-top: 0;">
        <div style="display: flex; flex-direction: column; gap: 10px;">
          <template x-for="task in tasks.filter(t => t.client === selectedContact.company || t.client === selectedContact.name)" :key="task.id">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0;">
              <div style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" :checked="task.completed" @change="toggleTask(task)" style="width: 18px; height: 18px; accent-color: #007788; cursor: pointer;" />
                <span :style="task.completed ? 'text-decoration: line-through; color: var(--text-muted);' : 'font-weight: 600; color: var(--text-pri);'" style="font-size: 13px;" x-text="task.title"></span>
              </div>
              <span class="status-badge" :class="task.priority === 'High' ? 'badge-lead' : 'badge-client'" x-text="task.due">Today</span>
            </div>
          </template>

          <div x-show="tasks.filter(t => t.client === selectedContact.company || t.client === selectedContact.name).length === 0" style="text-align: center; padding: 20px 0; color: var(--text-muted); font-size: 13px;">
            No specific tasks linked to this contact.
          </div>
        </div>
      </div>
    </div>

    <!-- ==============================================
         TAB CONTENT 5: ⋯ MORE SETTINGS
         ============================================== -->
    <div x-show="contactDetailTab === 'more'" class="detail-tab-pane">
      <div class="wortal-card" style="margin-top: 0;">
        <div style="display: flex; flex-direction: column; gap: 12px;">
          <button style="display: flex; align-items: center; justify-content: space-between; background: none; border: none; cursor: pointer; text-align: left; width: 100%; padding: 4px 0;" @click="showToast('Exporting vCard...')">
            <span style="font-size: 13px; font-weight: 600; color: var(--text-pri);">Export Contact (.vcf)</span>
            <span style="color: var(--text-muted);">›</span>
          </button>
          <div style="height: 1px; background: var(--border);"></div>
          <button style="display: flex; align-items: center; justify-content: space-between; background: none; border: none; cursor: pointer; text-align: left; width: 100%; padding: 4px 0;" @click="showToast('Duplicate contact created')">
            <span style="font-size: 13px; font-weight: 600; color: var(--text-pri);">Duplicate Contact</span>
            <span style="color: var(--text-muted);">›</span>
          </button>
          <div style="height: 1px; background: var(--border);"></div>
          <button style="display: flex; align-items: center; justify-content: space-between; background: none; border: none; cursor: pointer; text-align: left; width: 100%; padding: 4px 0;" @click="showToast('Contact archived')">
            <span style="font-size: 13px; font-weight: 600; color: var(--text-sec);">Archive Contact</span>
            <span style="color: var(--text-muted);">›</span>
          </button>
          <div style="height: 1px; background: var(--border);"></div>
          <button style="display: flex; align-items: center; justify-content: space-between; background: none; border: none; cursor: pointer; text-align: left; width: 100%; padding: 4px 0;" @click="showToast('Contact deleted'); navigateTo(3)">
            <span style="font-size: 13px; font-weight: 600; color: var(--danger);">Delete Contact</span>
            <span style="color: var(--danger);">🗑️</span>
          </button>
        </div>
      </div>
    </div>

  </div>
</section>
