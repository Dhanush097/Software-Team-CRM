<!-- Add Contact Modal -->
<div 
  class="modal-overlay" 
  :class="{ 'open': addContactModal }" 
  @click.self="addContactModal = false"
>
  <div class="modal-sheet-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
      <h3 style="margin: 0;">Add New Contact</h3>
      <button style="background: none; border: none; font-size: 18px; color: var(--text-sec); cursor: pointer;" @click="addContactModal = false">✕</button>
    </div>

    <label class="form-label">Full Name *</label>
    <input type="text" class="modal-input" placeholder="e.g. Ananya Roy" x-model="newContact.name" />

    <label class="form-label">Company Name</label>
    <input type="text" class="modal-input" placeholder="e.g. Roy Tech Labs" x-model="newContact.company" />

    <label class="form-label">Category</label>
    <select class="modal-input" x-model="newContact.category">
      <option value="Lead">Lead</option>
      <option value="Client">Client</option>
      <option value="Prospect">Prospect</option>
    </select>

    <label class="form-label">Email Address</label>
    <input type="email" class="modal-input" placeholder="ananya@roytech.com" x-model="newContact.email" />

    <label class="form-label">Phone Number</label>
    <input type="tel" class="modal-input" placeholder="+91 98765-11223" x-model="newContact.phone" />

    <div class="modal-actions">
      <button class="btn-secondary" @click="addContactModal = false">Cancel</button>
      <button class="btn-primary" @click="saveContact()">Save Contact</button>
    </div>
  </div>
</div>

<!-- Create Task Modal -->
<div 
  class="modal-overlay" 
  :class="{ 'open': addTaskModal }" 
  @click.self="addTaskModal = false"
>
  <div class="modal-sheet-panel">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
      <h3 style="margin: 0;">Create New Task</h3>
      <button style="background: none; border: none; font-size: 18px; color: var(--text-sec); cursor: pointer;" @click="addTaskModal = false">✕</button>
    </div>

    <label class="form-label">Task Title *</label>
    <input type="text" class="modal-input" placeholder="e.g. Prepare Client Pitch Deck" x-model="newTask.title" />

    <label class="form-label">Project / Client</label>
    <input type="text" class="modal-input" placeholder="e.g. E-commerce Website" x-model="newTask.project" />

    <label class="form-label">Priority</label>
    <select class="modal-input" x-model="newTask.priority">
      <option value="High">High</option>
      <option value="Medium">Medium</option>
      <option value="Low">Low</option>
    </select>

    <label class="form-label">Due Date</label>
    <input type="text" class="modal-input" placeholder="Today / Tomorrow / 20 Aug" x-model="newTask.due" />

    <div class="modal-actions">
      <button class="btn-secondary" @click="addTaskModal = false">Cancel</button>
      <button class="btn-primary" @click="saveTask()">Save Task</button>
    </div>
  </div>
</div>
