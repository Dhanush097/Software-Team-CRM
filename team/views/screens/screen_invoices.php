<!-- ─────────────────────────────────────────
     SCREEN 8: INVOICES & PAYMENTS
───────────────────────────────────────── -->
<section class="app-screen" id="screen8">
  <!-- Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Invoices</h2>
    </div>
    <button class="fab-add" @click="showToast('Create Invoice modal opened')" title="Create Invoice">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Filter Pills Strip -->
  <div class="filter-pills-row">
    <div class="chip-tab" :class="{ 'active': invoiceFilter === 'all' }" @click="invoiceFilter = 'all'">
      All (<span x-text="invoices.length">15</span>)
    </div>
    <div class="chip-tab" :class="{ 'active': invoiceFilter === 'paid' }" @click="invoiceFilter = 'paid'">
      Paid (10)
    </div>
    <div class="chip-tab" :class="{ 'active': invoiceFilter === 'pending' }" @click="invoiceFilter = 'pending'">
      Pending (4)
    </div>
    <div class="chip-tab" :class="{ 'active': invoiceFilter === 'overdue' }" @click="invoiceFilter = 'overdue'">
      Overdue (1)
    </div>
  </div>

  <!-- Invoices List Container -->
  <div style="flex: 1;">
    <template x-for="inv in filteredInvoices" :key="inv.id">
      <div class="invoice-card-row" @click="showToast('Opening ' + inv.id + ' (' + inv.amount + ')')">
        <!-- Blue SVG document icon -->
        <div class="invoice-icon-box">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
            <polyline points="10 9 9 9 8 9"/>
          </svg>
        </div>
        <!-- Invoice info -->
        <div class="invoice-mid-info">
          <div class="invoice-id-txt" x-text="inv.id">INV-2024-001</div>
          <div class="invoice-client-txt" x-text="inv.client + ' · ' + inv.date">ABC Tech Solutions · 10 Aug 2024</div>
        </div>
        <!-- Amount + Status -->
        <div class="invoice-amount-block">
          <div class="amount-value" x-text="inv.amount">$2,500</div>
          <span
            class="status-badge"
            :class="{
              'badge-paid': inv.status === 'paid',
              'badge-pending': inv.status === 'pending',
              'badge-overdue': inv.status === 'overdue'
            }"
            x-text="inv.status"
          >Paid</span>
        </div>
      </div>
    </template>
  </div>
</section>
