<!-- ─────────────────────────────────────────
     SCREEN 1: 4-DIGIT OTP AUTHENTICATION
     Two-step verification synced with Google Sheet & Apps Script
───────────────────────────────────────── -->
<section class="app-screen no-bottom-bar" id="screen1">
  <div class="login-container">

    <!-- Animated Graphical Logo & Brand Header -->
    <div class="animated-brand-header">
      <div class="animated-logo-wrapper">
        <div class="logo-pulse-halo"></div>
        <div class="logo-animated-badge">
          <div class="logo-shimmer"></div>
          <svg width="36" height="36" viewBox="0 0 36 36" fill="none" class="animated-svg-logo">
            <!-- 3 Dynamic Animated Growth Bars -->
            <rect class="anim-bar anim-bar-1" x="5" y="16" width="5.5" height="15" rx="2.5" fill="#FFFFFF" fill-opacity="0.9" />
            <rect class="anim-bar anim-bar-2" x="14" y="10" width="5.5" height="21" rx="2.5" fill="#FFFFFF" fill-opacity="0.95" />
            <rect class="anim-bar anim-bar-3" x="23" y="5" width="5.5" height="26" rx="2.5" fill="#FFFFFF" />
            <!-- Upward Growth Spark Trendline & Dot -->
            <path class="anim-trend-path" d="M 6 15 Q 16 8 30 4" stroke="#FDE047" stroke-width="2.2" stroke-linecap="round" fill="none" />
            <circle class="anim-trend-dot" cx="30" cy="4" r="2.5" fill="#FEF08A" />
          </svg>
        </div>
      </div>
      <div class="brand-text-block">
        <h1 class="brand-app-title">Software CRM</h1>
        <p class="brand-app-tagline">Team &bull; Projects &bull; Clients &bull; Growth</p>
      </div>
    </div>

    <!-- Permission Denied & WhatsApp Contact Alert Box -->
    <div x-show="loginError" x-cloak class="permission-denied-box">
      <div class="permission-denied-header">
        <div class="permission-denied-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
        <div style="flex: 1; min-width: 0;">
          <div class="permission-denied-title">Permission Required</div>
          <div class="permission-denied-desc" x-text="loginError"></div>
        </div>
      </div>

      <!-- Direct WhatsApp Action Button -->
      <a
        x-show="showWhatsAppContact"
        :href="'https://wa.me/919460478224?text=' + encodeURIComponent('Hello Admin, please grant login permission for Software CRM for my email: ' + (loginEmail || ''))"
        target="_blank"
        rel="noopener noreferrer"
        class="btn-whatsapp-admin"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#FFFFFF">
          <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91C2.13 13.66 2.59 15.36 3.45 16.86L2.05 22L7.3 20.62C8.75 21.41 10.38 21.83 12.04 21.83C17.5 21.83 21.95 17.38 21.95 11.92C21.95 9.27 20.92 6.78 19.05 4.91C17.18 3.04 14.69 2 12.04 2ZM12.04 20.15C10.56 20.15 9.11 19.76 7.85 19.01L7.55 18.83L4.43 19.65L5.26 16.61L5.06 16.29C4.24 14.99 3.8 13.47 3.8 11.91C3.8 7.37 7.5 3.67 12.05 3.67C14.25 3.67 16.31 4.53 17.87 6.09C19.42 7.65 20.28 9.72 20.28 11.92C20.28 16.46 16.58 20.15 12.04 20.15ZM16.56 14.44C16.31 14.32 15.1 13.72 14.88 13.64C14.65 13.56 14.49 13.52 14.32 13.76C14.16 14.01 13.69 14.56 13.55 14.73C13.41 14.89 13.26 14.91 13.01 14.79C12.77 14.66 11.98 14.41 11.04 13.57C10.31 12.92 9.81 12.11 9.67 11.87C9.53 11.62 9.65 11.49 9.77 11.37C9.88 11.26 10.02 11.08 10.14 10.94C10.26 10.8 10.3 10.7 10.39 10.53C10.47 10.37 10.43 10.22 10.37 10.1C10.31 9.98 9.82 8.77 9.61 8.28C9.41 7.8 9.21 7.86 9.06 7.86C8.92 7.85 8.76 7.85 8.59 7.85C8.43 7.85 8.16 7.91 7.94 8.15C7.71 8.4 7.08 8.99 7.08 10.19C7.08 11.39 7.96 12.55 8.08 12.71C8.2 12.88 9.8 15.34 12.24 16.39C12.82 16.64 13.27 16.79 13.63 16.9C14.21 17.09 14.74 17.06 15.16 17C15.63 16.93 16.59 16.42 16.79 15.85C17 15.28 17 14.79 16.93 14.69C16.87 14.58 16.81 14.56 16.56 14.44Z"/>
        </svg>
        <span>Contact Admin on WhatsApp</span>
        <span style="opacity: 0.9; font-size: 11px; font-weight: 500;">(+91 94604 78224)</span>
      </a>
    </div>

    <!-- ─────────────────────────────────────────
         STEP 1: EMAIL ADDRESS INPUT
    ───────────────────────────────────────── -->
    <div x-show="loginStep === 'email'" style="width: 100%;">
      <div class="form-group">
        <label class="form-label">Work Email Address</label>
        <div class="input-with-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          <input
            type="email"
            placeholder="email@gmail.com"
            x-model="loginEmail"
            @keydown.enter.prevent="sendOtp()"
            autofocus
          />
        </div>
      </div>

      <button
        type="button"
        class="btn-login-primary"
        :disabled="isSendingOtp || !loginEmail"
        @click="sendOtp()"
        style="margin-top: 10px;"
      >
        <span x-show="!isSendingOtp">Sign In →</span>
        <span x-show="isSendingOtp">Checking Permission & Sending OTP...</span>
      </button>
    </div>

    <!-- ─────────────────────────────────────────
         STEP 2: 4-DIGIT OTP VERIFICATION SCREEN
    ───────────────────────────────────────── -->
    <div x-show="loginStep === 'otp'" x-cloak style="width: 100%;">
      <!-- Email Summary & Change Action -->
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; padding: 10px 14px; background: var(--bg-input); border-radius: 12px; border: 1px solid var(--border);">
        <div style="min-width: 0; flex: 1;">
          <div style="font-size: 10.5px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Verification Code Sent To</div>
          <div style="font-size: 13.5px; font-weight: 700; color: var(--text-pri); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;" x-text="loginEmail"></div>
        </div>
        <button
          type="button"
          @click="loginStep = 'email'; otpError = ''; loginError = '';"
          style="background: none; border: none; color: #007788; font-size: 12px; font-weight: 700; cursor: pointer; padding: 4px 8px;"
        >
          Change
        </button>
      </div>

      <!-- OTP Success Notice -->
      <div x-show="otpSuccess" class="otp-alert otp-alert-success" style="margin-bottom: 12px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span x-text="otpSuccess"></span>
      </div>

      <!-- OTP Error Alert -->
      <div x-show="otpError" class="otp-alert otp-alert-error" style="margin-bottom: 12px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span x-text="otpError"></span>
      </div>

      <!-- 4-Digit OTP Boxes -->
      <div class="otp-boxes-group">
        <template x-for="(digit, idx) in otpDigits" :key="idx">
          <input
            type="text"
            inputmode="numeric"
            maxlength="1"
            class="otp-digit-input"
            :id="'otp-box-' + idx"
            :value="otpDigits[idx]"
            @input="handleOtpInput(idx, $event)"
            @keydown="handleOtpKeydown(idx, $event)"
            @paste="handleOtpPaste($event)"
            autocomplete="one-time-code"
          />
        </template>
      </div>

      <!-- Timer & Resend Row -->
      <div class="otp-timer-row">
        <div>
          <span x-show="otpTimer > 0" style="color: var(--text-muted);">
            Resend in <strong style="color: var(--text-pri);" x-text="'00:' + (otpTimer < 10 ? '0' : '') + otpTimer"></strong>
          </span>
          <span x-show="otpTimer <= 0" style="color: var(--text-sec);">Didn't get code?</span>
        </div>
        <button
          type="button"
          class="btn-resend-otp"
          :disabled="otpTimer > 0 || isSendingOtp"
          @click="sendOtp()"
        >
          <span x-show="!isSendingOtp">Resend OTP</span>
          <span x-show="isSendingOtp">Sending...</span>
        </button>
      </div>

      <!-- Verify Button -->
      <button
        type="button"
        class="btn-login-primary"
        :disabled="isVerifyingOtp || otpDigits.some(d => !d)"
        @click="verifyOtp()"
        style="margin-top: 10px;"
      >
        <span x-show="!isVerifyingOtp">Verify & Sign In →</span>
        <span x-show="isVerifyingOtp">Verifying...</span>
      </button>

      <!-- Back to Email Button -->
      <button
        type="button"
        @click="loginStep = 'email'; otpError = ''; loginError = '';"
        style="width: 100%; margin-top: 14px; background: none; border: none; color: var(--text-muted); font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 6px;"
      >
        ← Back to Email
      </button>
    </div>

  </div>
</section>
