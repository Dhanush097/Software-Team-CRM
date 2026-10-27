<!-- ─────────────────────────────────────────
     SCREEN 7: CALENDAR VIEW
───────────────────────────────────────── -->
<section class="app-screen" id="screen7">
  <!-- Screen Action Bar -->
  <div class="screen-action-bar">
    <div class="title-with-hamburger">
      <button class="icon-btn" @click="drawerOpen = true">
        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <h2 class="screen-heading">Calendar</h2>
    </div>
    <button class="fab-add" @click="showToast('Schedule New Event modal opened')" title="Schedule Event">
      <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
    </button>
  </div>

  <!-- Calendar Card -->
  <div class="calendar-card">
    <div class="month-nav-row">
      <button class="month-nav-btn" @click="showToast('Previous month')">‹</button>
      <div class="month-label">August 2024</div>
      <button class="month-nav-btn" @click="showToast('Next month')">›</button>
    </div>

    <!-- Weekdays Header -->
    <div class="calendar-weekdays">
      <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
    </div>

    <!-- Month Days Grid -->
    <div class="calendar-days-grid">
      <!-- Trailing previous month days -->
      <div class="day-cell muted"><span class="day-num">28</span></div>
      <div class="day-cell muted"><span class="day-num">29</span></div>
      <div class="day-cell muted"><span class="day-num">30</span></div>

      <!-- August Days -->
      <div class="day-cell" @click="selectCalendarDay(1)"><span class="day-num">1</span></div>
      <div class="day-cell" @click="selectCalendarDay(2)"><span class="day-num">2</span></div>
      <div class="day-cell" @click="selectCalendarDay(3)"><span class="day-num">3</span></div>
      <div class="day-cell" @click="selectCalendarDay(4)"><span class="day-num">4</span></div>

      <div class="day-cell" @click="selectCalendarDay(5)"><span class="day-num">5</span></div>
      <div class="day-cell" @click="selectCalendarDay(6)"><span class="day-num">6</span></div>
      <div class="day-cell" :class="{ 'selected': selectedDate === 7 }" @click="selectCalendarDay(7)">
        <span class="day-num">7</span>
        <span class="event-pill-mini" style="background: var(--success-bg); color: var(--success);">Client Mtg</span>
      </div>
      <div class="day-cell" @click="selectCalendarDay(8)"><span class="day-num">8</span></div>
      <div class="day-cell" @click="selectCalendarDay(9)"><span class="day-num">9</span></div>
      <div class="day-cell" @click="selectCalendarDay(10)"><span class="day-num">10</span></div>
      <div class="day-cell" @click="selectCalendarDay(11)"><span class="day-num">11</span></div>

      <div class="day-cell today" @click="selectCalendarDay(12)"><span class="day-num">12</span></div>
      <div class="day-cell" @click="selectCalendarDay(13)"><span class="day-num">13</span></div>
      <div class="day-cell" :class="{ 'selected': selectedDate === 14 }" @click="selectCalendarDay(14)">
        <span class="day-num">14</span>
        <span class="event-pill-mini" style="background: var(--danger-bg); color: var(--danger);">Design Deadline</span>
      </div>
      <div class="day-cell" @click="selectCalendarDay(15)"><span class="day-num">15</span></div>
      <div class="day-cell" @click="selectCalendarDay(16)"><span class="day-num">16</span></div>
      <div class="day-cell" @click="selectCalendarDay(17)"><span class="day-num">17</span></div>
      <div class="day-cell" @click="selectCalendarDay(18)"><span class="day-num">18</span></div>

      <div class="day-cell" @click="selectCalendarDay(19)"><span class="day-num">19</span></div>
      <div class="day-cell" @click="selectCalendarDay(20)"><span class="day-num">20</span></div>
      <div class="day-cell" @click="selectCalendarDay(21)"><span class="day-num">21</span></div>
      <div class="day-cell" :class="{ 'selected': selectedDate === 22 }" @click="selectCalendarDay(22)">
        <span class="day-num">22</span>
        <span class="event-pill-mini" style="background: var(--primary-light); color: var(--primary);">Team Meeting</span>
      </div>
      <div class="day-cell" @click="selectCalendarDay(23)"><span class="day-num">23</span></div>
      <div class="day-cell" @click="selectCalendarDay(24)"><span class="day-num">24</span></div>
      <div class="day-cell" @click="selectCalendarDay(25)"><span class="day-num">25</span></div>

      <div class="day-cell" @click="selectCalendarDay(26)"><span class="day-num">26</span></div>
      <div class="day-cell" @click="selectCalendarDay(27)"><span class="day-num">27</span></div>
      <div class="day-cell" @click="selectCalendarDay(28)"><span class="day-num">28</span></div>
      <div class="day-cell" @click="selectCalendarDay(29)"><span class="day-num">29</span></div>
      <div class="day-cell" :class="{ 'selected': selectedDate === 30 }" @click="selectCalendarDay(30)">
        <span class="day-num">30</span>
        <span class="event-pill-mini" style="background: var(--warning-bg); color: var(--warning);">Invoice Due</span>
      </div>
      <div class="day-cell" @click="selectCalendarDay(31)"><span class="day-num">31</span></div>
      <div class="day-cell muted"><span class="day-num">1</span></div>
    </div>
  </div>

  <!-- Events for selected day -->
  <div x-show="selectedDate" style="padding: 0 16px 16px;">
    <div class="content-box">
      <div class="box-header">
        <h3>Events on <span x-text="'Aug ' + selectedDate"></span></h3>
        <a class="link-text" @click="showToast('Add event for this date')">+ Add</a>
      </div>
      <div class="activity-row">
        <div class="activity-icon" style="background: var(--success-bg); color: var(--success);">📅</div>
        <div class="activity-details">
          <div class="activity-title">Client Meeting</div>
          <div class="activity-sub">ABC Tech Solutions — 10:00 AM</div>
        </div>
        <div class="activity-time">1h</div>
      </div>
    </div>
  </div>
</section>
