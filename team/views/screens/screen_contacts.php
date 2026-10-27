<!-- ─────────────────────────────────────────
     SCREEN 3: CONTACTS / CLIENTS LIST
───────────────────────────────────────── -->
<section class="app-screen" id="screen3">
  <!-- Sticky Header Container containing Action Bar, Search Box & Filter Nav Bar -->
  <div class="contacts-sticky-header">
    <!-- Screen Action Bar -->
    <div class="screen-action-bar">
      <div class="title-with-hamburger">
        <!-- Hamburger Menu -->
        <button class="icon-btn" @click="drawerOpen = true">
          <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
            <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
          </svg>
        </button>
        <h2 class="screen-heading">Contacts</h2>
      </div>

      <!-- FAB Add Contact -->
      <button class="fab-add" @click="addContactModal = true" title="Add New Contact">
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
        placeholder="Search contacts..." 
        x-model="contactSearch" 
      />
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" style="cursor: pointer;" @click="contactSearch = ''">
        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
      </svg>
    </div>

    <!-- Category Filter Pills Strip (Navigation Bar) -->
    <div class="filter-pills-row">
      <div 
        class="chip-tab" 
        :class="{ 'active': contactCategory === 'all' }" 
        @click="contactCategory = 'all'"
      >
        All (<span x-text="contacts.length">86</span>)
      </div>
      <div 
        class="chip-tab" 
        :class="{ 'active': contactCategory === 'client' }" 
        @click="contactCategory = 'client'"
      >
        Client
      </div>
      <div 
        class="chip-tab" 
        :class="{ 'active': contactCategory === 'lead' }" 
        @click="contactCategory = 'lead'"
      >
        Lead
      </div>
      <div 
        class="chip-tab" 
        :class="{ 'active': contactCategory === 'prospect' }" 
        @click="contactCategory = 'prospect'"
      >
        Prospect
      </div>
    </div>
  </div>

  <!-- Contacts List -->
  <div style="flex: 1;">
    <template x-for="contact in filteredContacts" :key="contact.id">
      <div class="contact-item-row" @click="viewContactDetail(contact)">
        <div class="contact-avatar" :style="'background: ' + contact.avatarBg" x-text="contact.initials">
          RS
        </div>
        <div class="contact-meta">
          <div class="contact-name-txt" x-text="contact.name">Rahul Sharma</div>
          <div class="contact-company-txt" x-text="contact.company">ABC Tech Solutions</div>
        </div>
        <div class="row-right-actions">
          <span 
            class="status-badge" 
            :class="{
              'badge-client': contact.category.toLowerCase() === 'client',
              'badge-lead': contact.category.toLowerCase() === 'lead',
              'badge-prospect': contact.category.toLowerCase() === 'prospect'
            }" 
            x-text="contact.category"
          >Client</span>
          <span style="color: var(--text-muted); font-size: 16px;">⋮</span>
        </div>
      </div>
    </template>

    <div x-show="filteredContacts.length === 0" style="padding: 40px 20px; text-align: center; color: var(--text-sec);">
      <p style="font-size: 14px;">No contacts match your search or filter.</p>
    </div>
  </div>
</section>
