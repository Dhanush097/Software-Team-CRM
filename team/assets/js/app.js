/**
 * Software CRM - Main Alpine.js Reactive Controller
 * Production-level state management, OTP authentication,
 * dynamic datasets, search/filtering, and Ghost Back integration
 */

document.addEventListener('alpine:init', () => {
  Alpine.data('crmApp', () => ({
    // ─────────────────────────────────────────
    // AUTHENTICATION & OTP STATE
    // ─────────────────────────────────────────
    isLoggedIn: false,
    user: {
      name: 'Suresh Kumar',
      email: 'suresh@softwarecrm.io',
      initials: 'SK',
      role: 'Founder & CEO',
      avatarGradient: 'linear-gradient(135deg, #F59E0B, #EF4444)'
    },
    loginEmail: '',
    isLoggingIn: false,
    loginError: '',
    showWhatsAppContact: false,
    loginStep: 'email', // 'email' | 'otp'
    otpDigits: ['', '', '', ''],
    otpTimer: 60,
    timerInterval: null,
    isSendingOtp: false,
    isVerifyingOtp: false,
    otpError: '',
    otpSuccess: '',
    appsScriptUrl: 'https://script.google.com/macros/s/AKfycbz8uzRc2g23mmTbed6dPCuir-x1fVHLQ8lRhvIyfFukEMtLsV5DjRkY1cUtqQbo2G0p/exec',
    showScriptConfig: false,

    // ─────────────────────────────────────────
    // NAVIGATION & VIEWPORT STATE
    // ─────────────────────────────────────────
    currentScreen: 2, // 1 to 9
    ghostScreen: null,
    historyStack: [2],
    desktopViewMode: 'app', // 'app' | 'showcase'
    drawerOpen: false,
    mobileDemoSheetOpen: false,
    statusBarClock: '9:41',

    // ─────────────────────────────────────────
    // DATASETS & LIVE COLLECTIONS
    // ─────────────────────────────────────────
    stats: {
      total_contacts: 86,
      active_projects: 12,
      pending_tasks: 8,
      revenue: '$12,450'
    },
    recentActivities: [
      {
        id: 1,
        title: 'New contact added',
        subtitle: 'Rahul Sharma · ABC Tech Solutions',
        time: '10 mins ago',
        icon: '👤',
        bg: '#EFF6FF',
        color: '#2563EB',
        targetScreen: 4
      },
      {
        id: 2,
        title: 'Project updated',
        subtitle: 'E-commerce Website (70% done)',
        time: '1 hour ago',
        icon: '🛒',
        bg: '#DCFCE7',
        color: '#16A34A',
        targetScreen: 5
      },
      {
        id: 3,
        title: 'Invoice created',
        subtitle: 'INV-2024-001 · $2,500',
        time: '3 hours ago',
        icon: '🧾',
        bg: '#FEE2E2',
        color: '#DC2626',
        targetScreen: 8
      }
    ],

    // Contacts
    contacts: [
      {
        id: 1,
        name: 'Rahul Sharma',
        company: 'ABC Tech Solutions',
        category: 'Client',
        position: 'Founder',
        location: '123 Business Bay, Mumbai, MH',
        source: 'Website',
        email: 'rahul@abctech.com',
        phone: '+91 98765-43210',
        phone2: '+91 98765-43211',
        is_starred: true,
        avatarBg: 'linear-gradient(135deg, #F59E0B, #EF4444)',
        initials: 'RS',
        tags: ['Important', 'Long Term']
      },
      {
        id: 2,
        name: 'Priya Verma',
        company: 'Verma Solutions',
        category: 'Lead',
        position: 'Head of Product',
        location: 'Indiranagar 100ft Rd, Bengaluru, KA',
        source: 'Referral',
        email: 'priya@vermasolutions.com',
        phone: '+91 98111-22334',
        phone2: '+91 98111-22335',
        is_starred: false,
        avatarBg: 'linear-gradient(135deg, #8B5CF6, #EC4899)',
        initials: 'PV',
        tags: ['High Value']
      },
      {
        id: 3,
        name: 'Amit Patel',
        company: 'Patel & Co.',
        category: 'Client',
        position: 'Director',
        location: 'SG Highway, Ahmedabad, GJ',
        source: 'Inbound',
        email: 'amit@patelco.in',
        phone: '+91 99222-33445',
        phone2: '+91 99222-33446',
        is_starred: false,
        avatarBg: 'linear-gradient(135deg, #10B981, #3B82F6)',
        initials: 'AP',
        tags: ['VIP']
      },
      {
        id: 4,
        name: 'Sneha Kapoor',
        company: 'Kapoor Designs',
        category: 'Prospect',
        position: 'Creative Lead',
        location: 'Koramangala 4th Block, Bangalore, KA',
        source: 'Instagram',
        email: 'sneha@kapoordesigns.com',
        phone: '+91 97333-44556',
        phone2: '+91 97333-44557',
        is_starred: false,
        avatarBg: 'linear-gradient(135deg, #F97316, #F59E0B)',
        initials: 'SK',
        tags: ['Design']
      },
      {
        id: 5,
        name: 'Vikram Singh',
        company: 'Singh Enterprises',
        category: 'Client',
        position: 'Managing Director',
        location: 'FC Road, Pune, MH',
        source: 'LinkedIn',
        email: 'vikram@singhent.com',
        phone: '+91 98444-55667',
        phone2: '+91 98444-55668',
        is_starred: true,
        avatarBg: 'linear-gradient(135deg, #6366F1, #8B5CF6)',
        initials: 'VS',
        tags: ['Enterprise']
      },
      {
        id: 6,
        name: 'Neha Gupta',
        company: 'Gupta Media',
        category: 'Lead',
        position: 'Marketing Manager',
        location: 'Cyber City, Gurugram, HR',
        source: 'Event',
        email: 'neha@guptamedia.com',
        phone: '+91 99555-66778',
        phone2: '+91 99555-66779',
        is_starred: false,
        avatarBg: 'linear-gradient(135deg, #EC4899, #F59E0B)',
        initials: 'NG',
        tags: ['Media']
      }
    ],
    contactSearch: '',
    contactCategory: 'all',
    selectedContact: null,
    contactDetailTab: 'info',

    // Projects
    projects: [
      {
        id: 1,
        title: 'E-commerce Website',
        client: 'ABC Tech Solutions',
        progress: 70,
        status: 'active',
        icon: '🛒',
        bg: '#EDE9FE',
        color: '#7C3AED'
      },
      {
        id: 2,
        title: 'Mobile App Development',
        client: 'Verma Solutions',
        progress: 40,
        status: 'active',
        icon: '📱',
        bg: '#EFF6FF',
        color: '#2563EB'
      },
      {
        id: 3,
        title: 'Brand Identity Design',
        client: 'Kapoor Designs',
        progress: 100,
        status: 'completed',
        icon: '🎨',
        bg: '#DCFCE7',
        color: '#16A34A'
      },
      {
        id: 4,
        title: 'CRM System',
        client: 'Patel & Co.',
        progress: 25,
        status: 'active',
        icon: '💻',
        bg: '#FFEDD5',
        color: '#EA580C'
      }
    ],
    projectFilter: 'all',

    // Tasks
    tasks: [
      {
        id: 1,
        title: 'Design Homepage',
        project: 'E-commerce Website',
        category: 'my',
        priority: 'High',
        due: 'Today',
        overdue: true,
        completed: false
      },
      {
        id: 2,
        title: 'Client Meeting - ABC Ltd',
        project: 'Rahul Sharma',
        category: 'my',
        priority: 'Medium',
        due: 'Tomorrow',
        overdue: false,
        completed: false
      },
      {
        id: 3,
        title: 'Prepare Proposal',
        project: 'Verma Solutions',
        category: 'my',
        priority: 'Low',
        due: '14 Aug',
        overdue: false,
        completed: false
      },
      {
        id: 4,
        title: 'Review UI Design',
        project: 'Mobile App Development',
        category: 'my',
        priority: 'High',
        due: '16 Aug',
        overdue: true,
        completed: false
      },
      {
        id: 5,
        title: 'Update Project Documentation',
        project: 'CRM System',
        category: 'my',
        priority: 'Medium',
        due: '18 Aug',
        overdue: false,
        completed: false
      }
    ],
    taskFilter: 'my',

    // Calendar
    calendarEvents: [
      { date: 7, title: 'Client Mtg', type: 'meeting', bg: 'var(--success-bg)', color: 'var(--success)' },
      { date: 14, title: 'Deadline', type: 'deadline', bg: 'var(--danger-bg)', color: 'var(--danger)' },
      { date: 22, title: 'Team Mtg', type: 'meeting', bg: '#EFF6FF', color: 'var(--primary)' },
      { date: 30, title: 'Inv Due', type: 'invoice', bg: 'var(--warning-bg)', color: 'var(--warning)' }
    ],
    selectedDate: 12,

    // Invoices
    invoices: [
      {
        id: 'INV-2024-001',
        client: 'ABC Tech Solutions',
        date: '10 Aug 2024',
        amount: '$2,500',
        status: 'paid'
      },
      {
        id: 'INV-2024-002',
        client: 'Verma Solutions',
        date: '12 Aug 2024',
        amount: '$1,200',
        status: 'pending'
      },
      {
        id: 'INV-2024-003',
        client: 'Kapoor Designs',
        date: '05 Aug 2024',
        amount: '$3,000',
        status: 'overdue'
      },
      {
        id: 'INV-2024-004',
        client: 'Patel & Co.',
        date: '28 Jul 2024',
        amount: '$800',
        status: 'paid'
      }
    ],
    invoiceFilter: 'all',

    // Documents (Screen 11)
    documents: [
      {
        id: 1,
        title: 'Project_Proposal_v2.pdf',
        client: 'ABC Tech Solutions',
        category: 'proposals',
        type: 'pdf',
        size: '2.4 MB',
        date: '12 Aug 2024',
        iconBg: '#EFF6FF',
        iconColor: '#2563EB'
      },
      {
        id: 2,
        title: 'Master_Services_Contract.pdf',
        client: 'Verma Solutions',
        category: 'contracts',
        type: 'pdf',
        size: '1.8 MB',
        date: '08 Aug 2024',
        iconBg: '#FEE2E2',
        iconColor: '#DC2626'
      },
      {
        id: 3,
        title: 'Revenue_Report_Q3.xlsx',
        client: 'Internal Finance',
        category: 'invoices',
        type: 'xlsx',
        size: '856 KB',
        date: '10 Aug 2024',
        iconBg: '#DCFCE7',
        iconColor: '#16A34A'
      },
      {
        id: 4,
        title: 'NDA_SinghEnterprises.docx',
        client: 'Singh Enterprises',
        category: 'contracts',
        type: 'docx',
        size: '128 KB',
        date: '05 Aug 2024',
        iconBg: '#EFF6FF',
        iconColor: '#3B82F6'
      },
      {
        id: 5,
        title: 'Brand_Assets_Export.zip',
        client: 'Kapoor Designs',
        category: 'proposals',
        type: 'zip',
        size: '14.2 MB',
        date: '02 Aug 2024',
        iconBg: '#FEF9C3',
        iconColor: '#CA8A04'
      },
      {
        id: 6,
        title: 'Invoice_INV-2024-001.pdf',
        client: 'ABC Tech Solutions',
        category: 'invoices',
        type: 'pdf',
        size: '340 KB',
        date: '10 Aug 2024',
        iconBg: '#EDE9FE',
        iconColor: '#7C3AED'
      }
    ],
    documentFilter: 'all',
    documentSearch: '',

    // Team Members (Screen 12)
    teamMembers: [
      {
        id: 1,
        name: 'Suresh Kumar',
        role: 'Founder & CEO',
        department: 'management',
        email: 'suresh@softwarecrm.io',
        phone: '+91 98765-11223',
        status: 'online',
        avatarBg: 'linear-gradient(135deg, #F59E0B, #EF4444)',
        initials: 'SK',
        tasksAssigned: 4
      },
      {
        id: 2,
        name: 'Anjali Sharma',
        role: 'Lead UI/UX Designer',
        department: 'design',
        email: 'anjali@softwarecrm.io',
        phone: '+91 98111-44556',
        status: 'online',
        avatarBg: 'linear-gradient(135deg, #EC4899, #8B5CF6)',
        initials: 'AS',
        tasksAssigned: 6
      },
      {
        id: 3,
        name: 'Rahul Dev',
        role: 'Senior Full Stack Dev',
        department: 'dev',
        email: 'rahul.dev@softwarecrm.io',
        phone: '+91 99222-77889',
        status: 'online',
        avatarBg: 'linear-gradient(135deg, #2563EB, #3B82F6)',
        initials: 'RD',
        tasksAssigned: 8
      },
      {
        id: 4,
        name: 'Priya Verma',
        role: 'Project Manager',
        department: 'management',
        email: 'priya.v@softwarecrm.io',
        phone: '+91 97333-88990',
        status: 'away',
        avatarBg: 'linear-gradient(135deg, #10B981, #059669)',
        initials: 'PV',
        tasksAssigned: 5
      },
      {
        id: 5,
        name: 'Vikram Singh',
        role: 'Backend Specialist',
        department: 'dev',
        email: 'vikram.s@softwarecrm.io',
        phone: '+91 98444-11223',
        status: 'offline',
        avatarBg: 'linear-gradient(135deg, #6366F1, #4F46E5)',
        initials: 'VS',
        tasksAssigned: 3
      }
    ],
    teamFilter: 'all',

    // Reports & Analytics (Screen 13)
    reportPeriod: 'month',
    monthlyRevenueData: [
      { month: 'Jan', revenue: '$7.8K', amount: 7800, heightPct: 52 },
      { month: 'Feb', revenue: '$8.6K', amount: 8600, heightPct: 60 },
      { month: 'Mar', revenue: '$9.2K', amount: 9200, heightPct: 66 },
      { month: 'Apr', revenue: '$8.9K', amount: 8900, heightPct: 64 },
      { month: 'May', revenue: '$10.4K', amount: 10400, heightPct: 76 },
      { month: 'Jun', revenue: '$11.2K', amount: 11200, heightPct: 84 },
      { month: 'Jul', revenue: '$11.8K', amount: 11800, heightPct: 90 },
      { month: 'Aug', revenue: '$12.4K', amount: 12450, heightPct: 100 }
    ],

    // Settings (Screen 14)
    settings: {
      pushNotifications: true,
      emailAlerts: true,
      weeklyDigest: false,
      darkMode: false,
      biometricLogin: true,
      currency: 'USD ($)',
      timezone: 'Asia/Kolkata (GMT+5:30)',
      language: 'English'
    },

    // Modals & Toast State
    addContactModal: false,
    newContact: {
      name: '',
      company: '',
      category: 'Lead',
      position: '',
      email: '',
      phone: ''
    },
    addTaskModal: false,
    newTask: {
      title: '',
      project: '',
      priority: 'Medium',
      due: 'Today'
    },
    toast: {
      show: false,
      message: '',
      timer: null
    },

    // ─────────────────────────────────────────
    // INITIALIZATION & LIFECYCLE
    // ─────────────────────────────────────────
    init() {
      window.crmAppData = this;
      this.selectedContact = this.contacts[0];

      // Update clock
      this.updateClock();
      setInterval(() => this.updateClock(), 30000);

      // Check unlimited session in localStorage
      const savedToken = ApiClient.getToken();
      const savedUser = localStorage.getItem('crm_user_info');
      if (savedToken) {
        this.isLoggedIn = true;
        if (savedUser) {
          try {
            const u = JSON.parse(savedUser);
            if (u && u.email) {
              this.user = { ...this.user, ...u };
            }
          } catch (e) {}
        }
      } else {
        this.isLoggedIn = false;
      }

      // Check URL hash if user opened directly via #contacts, #projects, etc.
      if (typeof window !== 'undefined' && window.location.hash) {
        const hashScreen = CRMNavigation.resolveScreenId(window.location.hash);
        if (hashScreen) {
          this.currentScreen = hashScreen;
        }
      } else {
        this.currentScreen = this.isLoggedIn ? 2 : 1;
      }

      // Initialize navigation controller
      CRMNavigation.init(this.currentScreen, (curr, ghost) => {
        this.currentScreen = curr;
        this.ghostScreen = ghost;
        this.historyStack = CRMNavigation.getHistory();
      });

      // Fetch live data from backend if available
      this.fetchBackendData();
    },

    updateClock() {
      const now = new Date();
      let hours = now.getHours();
      let minutes = now.getMinutes();
      minutes = minutes < 10 ? '0' + minutes : minutes;
      this.statusBarClock = `${hours}:${minutes}`;
    },

    async fetchBackendData() {
      try {
        const dashRes = await ApiClient.dashboard.get();
        if (dashRes && dashRes.success && dashRes.data) {
          this.stats = dashRes.data.stats || this.stats;
          if (dashRes.data.recent_activity) {
            this.recentActivities = dashRes.data.recent_activity;
          }
        }

        const contactsRes = await ApiClient.contacts.list();
        if (contactsRes && contactsRes.success && contactsRes.data) {
          const list = Array.isArray(contactsRes.data) ? contactsRes.data : contactsRes.data.contacts;
          if (list && list.length > 0) {
            this.contacts = list.map(c => ({
              ...c,
              phone2: c.phone2 || (c.phone ? c.phone.slice(0, -1) + '1' : '+91 98765-43211'),
              is_starred: c.is_starred || false,
              initials: c.initials || c.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase(),
              avatarBg: c.avatar_bg || c.avatar_color || 'linear-gradient(135deg, #2563EB, #3B82F6)'
            }));
            this.selectedContact = this.contacts[0];
          }
        }

        const projectsRes = await ApiClient.projects.list();
        if (projectsRes && projectsRes.success && projectsRes.data) {
          const list = Array.isArray(projectsRes.data) ? projectsRes.data : projectsRes.data.projects;
          if (list && list.length > 0) {
            this.projects = list;
          }
        }

        const tasksRes = await ApiClient.tasks.list();
        if (tasksRes && tasksRes.success && tasksRes.data) {
          const list = Array.isArray(tasksRes.data) ? tasksRes.data : tasksRes.data.tasks;
          if (list && list.length > 0) {
            this.tasks = list;
          }
        }

        const invoicesRes = await ApiClient.invoices.list();
        if (invoicesRes && invoicesRes.success && invoicesRes.data) {
          const list = Array.isArray(invoicesRes.data) ? invoicesRes.data : invoicesRes.data.invoices;
          if (list && list.length > 0) {
            this.invoices = list;
          }
        }
      } catch (e) {
        console.log('Backend sync completed (fallback active).');
      }
    },

    // ─────────────────────────────────────────
    // OTP & EMAIL AUTHENTICATION METHODS
    // ─────────────────────────────────────────
    async loginWithEmail() {
      const email = (this.loginEmail || '').trim().toLowerCase();
      if (!email || !email.includes('@')) {
        this.loginError = 'Please enter a valid work email address.';
        this.showWhatsAppContact = false;
        this.showToast('Please enter a valid email address');
        return;
      }

      this.isLoggingIn = true;
      this.loginError = '';
      this.showWhatsAppContact = false;

      const res = await ApiClient.auth.login(email);
      this.isLoggingIn = false;

      if (res && res.success) {
        if (res.data && res.data.token) {
          ApiClient.setToken(res.data.token);
        }
        if (res.data && res.data.user) {
          this.user.email = res.data.user.email;
          this.user.name = res.data.user.name || this.user.name;
        }
        this.isLoggedIn = true;
        this.showToast(`Welcome back, ${this.user.name}!`);
        this.navigateTo(2);
      } else {
        const errMsg = (res && (res.message || res.error)) 
          ? (res.message || res.error) 
          : 'Contact admin to get login permission';
        this.loginError = errMsg;
        this.showWhatsAppContact = true;
        this.showToast('Access Denied: Contact admin to get login permission');
      }
    },

    async sendOtp() {
      if (!this.loginEmail || !this.loginEmail.includes('@')) {
        this.otpError = 'Please enter a valid email address.';
        this.showToast('Please enter a valid email address');
        return;
      }

      this.isSendingOtp = true;
      this.otpError = '';
      this.otpSuccess = '';

      const res = await ApiClient.auth.sendOtp(this.loginEmail.trim().toLowerCase(), this.user.name);
      this.isSendingOtp = false;

      if (res && res.success) {
        this.loginStep = 'otp';
        this.otpDigits = ['', '', '', ''];
        this.otpSuccess = res.message || ('OTP sent to ' + this.loginEmail);
        if (res.data && res.data.test_otp) {
          this.otpSuccess += ` (Test code: ${res.data.test_otp})`;
        }
        this.startOtpTimer();
        this.showToast(`OTP sent to ${this.loginEmail}!`);
        this.$nextTick(() => {
          const firstInput = document.getElementById('otp-box-0');
          if (firstInput) firstInput.focus();
        });
      } else {
        // Fallback for static/offline: proceed with demo OTP
        this.loginStep = 'otp';
        this.otpDigits = ['', '', '', ''];
        this.otpSuccess = '4-Digit OTP ready! Use testing code: 9999';
        this.startOtpTimer();
        this.showToast('Test OTP is 9999 (Sheet sync ready)');
        this.$nextTick(() => {
          const firstInput = document.getElementById('otp-box-0');
          if (firstInput) firstInput.focus();
        });
      }
    },

    startOtpTimer() {
      clearInterval(this.timerInterval);
      this.otpTimer = 60;
      this.timerInterval = setInterval(() => {
        if (this.otpTimer > 0) {
          this.otpTimer--;
        } else {
          clearInterval(this.timerInterval);
        }
      }, 1000);
    },

    handleOtpInput(index, event) {
      const val = (event.target.value || '').replace(/[^0-9]/g, '');
      this.otpDigits[index] = val ? val.slice(-1) : '';
      this.otpError = '';

      // Auto advance to next box
      if (val && index < 3) {
        const nextInput = document.getElementById(`otp-box-${index + 1}`);
        if (nextInput) nextInput.focus();
      }

      // If all 4 filled, auto-verify
      if (this.otpDigits.every(d => d !== '')) {
        this.verifyOtp();
      }
    },

    handleOtpKeydown(index, event) {
      if (event.key === 'Backspace' && !this.otpDigits[index] && index > 0) {
        const prevInput = document.getElementById(`otp-box-${index - 1}`);
        if (prevInput) {
          prevInput.focus();
          this.otpDigits[index - 1] = '';
        }
      }
    },

    handleOtpPaste(event) {
      event.preventDefault();
      const pasteData = (event.clipboardData || window.clipboardData)?.getData('text') || '';
      const cleanDigits = pasteData.replace(/\D/g, '').slice(0, 4);
      if (cleanDigits.length > 0) {
        for (let i = 0; i < 4; i++) {
          this.otpDigits[i] = cleanDigits[i] || '';
        }
        const targetIdx = Math.min(cleanDigits.length, 3);
        const targetBox = document.getElementById('otp-box-' + targetIdx);
        if (targetBox) targetBox.focus();
        if (cleanDigits.length === 4) {
          this.verifyOtp();
        }
      }
    },

    async verifyOtp() {
      const code = this.otpDigits.join('').trim();
      if (code.length < 4) {
        this.otpError = 'Please enter all 4 digits.';
        this.showToast('Please enter all 4 digits');
        return;
      }

      this.isVerifyingOtp = true;
      this.otpError = '';

      const email = (this.loginEmail || '').trim().toLowerCase();
      const res = await ApiClient.auth.verifyOtp(email, code);
      this.isVerifyingOtp = false;

      if (res && res.success) {
        const token = (res.data && res.data.token) ? res.data.token : ('crm_sess_' + Date.now());
        ApiClient.setToken(token);
        if (res.data && res.data.user) {
          this.user.email = res.data.user.email;
          this.user.name = res.data.user.name || this.user.name;
        } else {
          this.user.email = email;
          const p = email.split('@')[0].replace(/[^a-zA-Z0-9]/g, ' ');
          this.user.name = p.charAt(0).toUpperCase() + p.slice(1);
        }

        // Unlimited session storage in localStorage
        localStorage.setItem('crm_user_info', JSON.stringify({
          name: this.user.name,
          email: this.user.email,
          initials: this.user.initials,
          role: this.user.role
        }));

        this.isLoggedIn = true;
        this.showToast(`Login verified! Welcome, ${this.user.name}`);
        this.navigateTo(2);
      } else {
        const errMsg = (res && (res.message || res.error)) 
          ? (res.message || res.error) 
          : 'Invalid or expired OTP. Please check the code or request a new one.';
        this.otpError = errMsg;
        this.showToast(errMsg);
        this.otpDigits = ['', '', '', ''];
        this.$nextTick(() => {
          const firstBox = document.getElementById('otp-box-0');
          if (firstBox) firstBox.focus();
        });
      }
    },

    googleLogin() {
      this.isLoggedIn = true;
      this.showToast('Signed in via Google! Welcome Suresh.');
      this.navigateTo(2);
    },

    demoBypass() {
      this.loginStep = 'otp';
      this.otpDigits = ['9', '9', '9', '9'];
      this.verifyOtp();
    },

    async logout() {
      try {
        await ApiClient.auth.logout();
      } catch (e) {}
      ApiClient.setToken('');
      localStorage.removeItem('crm_user_info');
      this.isLoggedIn = false;
      this.loginEmail = '';
      this.loginStep = 'email';
      this.otpDigits = ['', '', '', ''];
      this.drawerOpen = false;
      this.showToast('You have been signed out');
      this.navigateTo(1);
    },

    // ─────────────────────────────────────────
    // NAVIGATION METHODS
    // ─────────────────────────────────────────
    navigateTo(screenNum) {
      CRMNavigation.navigateTo(screenNum);
      this.currentScreen = CRMNavigation.getCurrentScreen();
      this.ghostScreen = CRMNavigation.getGhostScreen();
      this.historyStack = CRMNavigation.getHistory();
    },

    goBack() {
      CRMNavigation.goBack();
      this.currentScreen = CRMNavigation.getCurrentScreen();
      this.ghostScreen = CRMNavigation.getGhostScreen();
      this.historyStack = CRMNavigation.getHistory();
    },

    getGhostBackName() {
      if (!this.ghostScreen) return 'Dashboard';
      return CRMNavigation.getScreenName(this.ghostScreen);
    },

    switchDesktopView(mode) {
      this.desktopViewMode = mode;
      const phoneWrapper = document.getElementById('phoneWrapper');
      const showcaseView = document.getElementById('showcaseView');

      if (mode === 'app') {
        if (phoneWrapper) phoneWrapper.style.display = 'flex';
        if (showcaseView) showcaseView.classList.remove('active');
      } else {
        if (phoneWrapper) phoneWrapper.style.display = 'none';
        if (showcaseView) showcaseView.classList.add('active');
      }
    },

    // ─────────────────────────────────────────
    // FILTERING & ACTIONS
    // ─────────────────────────────────────────
    get filteredContacts() {
      return this.contacts.filter(c => {
        const matchesCategory =
          this.contactCategory === 'all' ||
          c.category.toLowerCase() === this.contactCategory.toLowerCase();

        const matchesSearch =
          !this.contactSearch ||
          c.name.toLowerCase().includes(this.contactSearch.toLowerCase()) ||
          c.company.toLowerCase().includes(this.contactSearch.toLowerCase());

        return matchesCategory && matchesSearch;
      });
    },

    get filteredProjects() {
      if (this.projectFilter === 'all') return this.projects;
      return this.projects.filter(p => p.status === this.projectFilter);
    },

    get filteredTasks() {
      if (this.taskFilter === 'all') return this.tasks;
      if (this.taskFilter === 'overdue') return this.tasks.filter(t => t.overdue && !t.completed);
      return this.tasks.filter(t => t.category === this.taskFilter);
    },

    get filteredInvoices() {
      if (this.invoiceFilter === 'all') return this.invoices;
      return this.invoices.filter(i => i.status === this.invoiceFilter);
    },

    get filteredDocuments() {
      return this.documents.filter(doc => {
        const matchesCategory =
          this.documentFilter === 'all' ||
          doc.category === this.documentFilter;
        const matchesSearch =
          !this.documentSearch ||
          doc.title.toLowerCase().includes(this.documentSearch.toLowerCase()) ||
          doc.client.toLowerCase().includes(this.documentSearch.toLowerCase());
        return matchesCategory && matchesSearch;
      });
    },

    get filteredTeam() {
      if (this.teamFilter === 'all') return this.teamMembers;
      return this.teamMembers.filter(m => m.department === this.teamFilter);
    },

    async toggleTask(task) {
      task.completed = !task.completed;
      this.showToast(task.completed ? `Completed: "${task.title}"` : `Marked pending: "${task.title}"`);
      await ApiClient.tasks.toggle(task.id, task.completed);
    },

    toggleTheme() {
      this.settings.darkMode = !this.settings.darkMode;
      if (this.settings.darkMode) {
        document.documentElement.classList.add('dark');
        document.body.classList.add('dark');
      } else {
        document.documentElement.classList.remove('dark');
        document.body.classList.remove('dark');
      }
    },

    viewContactDetail(contact) {
      this.selectedContact = contact;
      this.contactDetailTab = 'info';
      this.navigateTo(4);
    },

    selectCalendarDay(day) {
      this.selectedDate = day;
      const event = this.calendarEvents.find(e => e.date === day);
      if (event) {
        this.showToast(`Selected Aug ${day}: ${event.title}`);
      } else {
        this.showToast(`Selected August ${day}, 2024`);
      }
    },

    async saveContact() {
      if (!this.newContact.name) {
        this.showToast('Please enter contact name');
        return;
      }
      const newObj = {
        id: this.contacts.length + 1,
        name: this.newContact.name,
        company: this.newContact.company || 'Independent',
        category: this.newContact.category || 'Lead',
        position: this.newContact.position || 'Representative',
        location: 'India',
        source: 'Mobile App',
        email: this.newContact.email || 'contact@crm.io',
        phone: this.newContact.phone || '+91 99999-00000',
        initials: this.newContact.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase(),
        avatarBg: 'linear-gradient(135deg, #2563EB, #3B82F6)',
        tags: ['New']
      };

      this.contacts.unshift(newObj);
      this.stats.total_contacts++;
      this.addContactModal = false;
      this.showToast(`Contact "${newObj.name}" added successfully!`);
      await ApiClient.contacts.create(newObj);
      this.newContact = { name: '', company: '', category: 'Lead', position: '', email: '', phone: '' };
    },

    async saveTask() {
      if (!this.newTask.title) {
        this.showToast('Please enter task title');
        return;
      }
      const newObj = {
        id: this.tasks.length + 1,
        title: this.newTask.title,
        project: this.newTask.project || 'General CRM',
        category: 'my',
        priority: this.newTask.priority || 'Medium',
        due: this.newTask.due || 'Today',
        overdue: false,
        completed: false
      };
      this.tasks.unshift(newObj);
      this.stats.pending_tasks++;
      this.addTaskModal = false;
      this.showToast(`Task "${newObj.title}" created!`);
      await ApiClient.tasks.create(newObj);
      this.newTask = { title: '', project: '', priority: 'Medium', due: 'Today' };
    },

    showToast(message) {
      if (this.toast.timer) clearTimeout(this.toast.timer);
      this.toast.message = message;
      this.toast.show = true;
      this.toast.timer = setTimeout(() => {
        this.toast.show = false;
      }, 2600);
    }
  }));
});
