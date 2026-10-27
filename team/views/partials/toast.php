<!-- Reactive Toast Notification Component -->
<div 
  class="toast-box" 
  :class="{ 'show': toast.show }"
>
  <span style="font-size: 14px;">⚡</span>
  <span x-text="toast.message">Notification message</span>
</div>
