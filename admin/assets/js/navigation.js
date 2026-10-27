/**
 * Software CRM - Ghost Back Navigation Controller
 * Manages screen transitions, history stack, Ghost Back layering,
 * friendly named URL hashes (#contacts, #projects, #team), and browser back button handling
 */

const CRMNavigation = (() => {
  const SCREEN_NAMES = {
    1: 'Login',
    2: 'Dashboard',
    3: 'Contacts',
    4: 'Contact Detail',
    5: 'Projects',
    6: 'Tasks',
    7: 'Calendar',
    8: 'Invoices',
    9: 'Compact Dash',
    10: 'Drawer Menu',
    11: 'Documents',
    12: 'Team',
    13: 'Reports',
    14: 'Settings'
  };

  const SCREEN_SLUGS = {
    1: 'login',
    2: 'dashboard',
    3: 'contacts',
    4: 'contact-detail',
    5: 'projects',
    6: 'tasks',
    7: 'calendar',
    8: 'invoices',
    9: 'compact',
    10: 'drawer',
    11: 'documents',
    12: 'team',
    13: 'reports',
    14: 'settings'
  };

  const SLUG_TO_ID = {
    'login': 1,
    'screen-1': 1,
    'dashboard': 2,
    'screen-2': 2,
    'contacts': 3,
    'contact': 3,
    'screen-3': 3,
    'contact-detail': 4,
    'contact_detail': 4,
    'detail': 4,
    'screen-4': 4,
    'projects': 5,
    'project': 5,
    'screen-5': 5,
    'tasks': 6,
    'task': 6,
    'screen-6': 6,
    'calendar': 7,
    'screen-7': 7,
    'invoices': 8,
    'invoice': 8,
    'screen-8': 8,
    'compact': 9,
    'compact-dash': 9,
    'compact-dashboard': 9,
    'screen-9': 9,
    'drawer': 10,
    'menu': 10,
    'screen-10': 10,
    'documents': 11,
    'document': 11,
    'files': 11,
    'screen-11': 11,
    'team': 12,
    'screen-12': 12,
    'reports': 13,
    'report': 13,
    'analytics': 13,
    'screen-13': 13,
    'settings': 14,
    'setting': 14,
    'screen-14': 14
  };

  let historyStack = [2]; // Root is Dashboard
  let currentScreen = 2;
  let ghostScreen = null;
  let isTransitioning = false;
  let exitArmed = false;
  let onScreenChangeCallback = null;

  function resolveScreenId(target) {
    if (typeof target === 'number') {
      return SCREEN_SLUGS[target] ? target : 2;
    }
    if (typeof target === 'string') {
      const clean = target.replace('#', '').trim().toLowerCase();
      if (SLUG_TO_ID[clean]) return SLUG_TO_ID[clean];
      const parsed = parseInt(clean.replace('screen-', '').replace('screen', ''), 10);
      if (!isNaN(parsed) && SCREEN_SLUGS[parsed]) return parsed;
    }
    return 2;
  }

  function getScreenSlug(screenId) {
    return SCREEN_SLUGS[screenId] || 'dashboard';
  }

  function getScreenFromHash() {
    if (typeof window === 'undefined' || !window.location.hash) return null;
    const clean = window.location.hash.replace('#', '').trim().toLowerCase();
    return clean ? resolveScreenId(clean) : null;
  }

  function getScreenElement(screenNum) {
    const slug = getScreenSlug(screenNum);
    return document.getElementById(`screen${screenNum}`) ||
           document.getElementById(`screen-${slug}`) ||
           document.getElementById(slug) ||
           document.querySelector(`[data-screen="${slug}"]`);
  }

  function init(initialScreen = null, callback = null) {
    const hashScreen = getScreenFromHash();
    const startScreen = hashScreen || (initialScreen ? resolveScreenId(initialScreen) : 2);

    currentScreen = startScreen;
    historyStack = [startScreen];
    ghostScreen = null;
    onScreenChangeCallback = callback;

    const slug = getScreenSlug(startScreen);
    try {
      window.history.replaceState({ screen: startScreen, depth: 1 }, '', `#${slug}`);
    } catch (e) {}

    setupPopState();
    setupHashChange();
    setupSwipeGesture();
    applyDomClasses(startScreen);

    if (onScreenChangeCallback) onScreenChangeCallback(currentScreen, ghostScreen);
  }

  function setupPopState() {
    window.addEventListener('popstate', (e) => {
      // Check if Alpine instance has open drawer or modal
      const app = window.crmAppData;
      if (app && app.drawerOpen) {
        app.drawerOpen = false;
        repushCurrentState();
        return;
      }
      if (app && app.mobileDemoSheetOpen) {
        app.mobileDemoSheetOpen = false;
        repushCurrentState();
        return;
      }
      if (app && (app.addContactModal || app.addTaskModal)) {
        app.addContactModal = false;
        app.addTaskModal = false;
        repushCurrentState();
        return;
      }

      // If we have history inside the web app, pop and restore last screen
      if (historyStack.length > 1) {
        executeBackTransition();
      } else {
        // At root screen: double press back to exit
        if (!exitArmed) {
          exitArmed = true;
          if (window.crmAppData && window.crmAppData.showToast) {
            window.crmAppData.showToast('Press back again to exit app');
          }
          repushCurrentState();
          setTimeout(() => { exitArmed = false; }, 2500);
        } else {
          // Allow exiting app
          window.history.back();
        }
      }
    });
  }

  function setupHashChange() {
    window.addEventListener('hashchange', () => {
      const hashScreen = getScreenFromHash();
      if (hashScreen && hashScreen !== currentScreen && !isTransitioning) {
        navigateTo(hashScreen, false);
      }
    });
  }

  function repushCurrentState() {
    try {
      const slug = getScreenSlug(currentScreen);
      window.history.pushState(
        { screen: currentScreen, depth: historyStack.length },
        '',
        `#${slug}`
      );
    } catch (e) {}
  }

  function navigateTo(target, pushUrl = true) {
    const targetScreen = resolveScreenId(target);

    if (targetScreen === 10) {
      if (window.crmAppData) window.crmAppData.drawerOpen = true;
      return;
    }

    if (targetScreen === currentScreen || isTransitioning) return;
    isTransitioning = true;

    const previousScreen = currentScreen;
    ghostScreen = previousScreen;
    historyStack.push(targetScreen);
    currentScreen = targetScreen;

    if (pushUrl) {
      const slug = getScreenSlug(targetScreen);
      try {
        window.history.pushState(
          { screen: targetScreen, depth: historyStack.length },
          '',
          `#${slug}`
        );
      } catch (e) {}
    }

    // Close any overlays
    if (window.crmAppData) {
      window.crmAppData.drawerOpen = false;
      window.crmAppData.mobileDemoSheetOpen = false;
      window.crmAppData.currentScreen = targetScreen;
      window.crmAppData.ghostScreen = ghostScreen;
      window.crmAppData.historyStack = [...historyStack];
    }

    const oldScreenEl = getScreenElement(previousScreen);
    const newScreenEl = getScreenElement(targetScreen);

    if (oldScreenEl && newScreenEl) {
      newScreenEl.className = 'app-screen';
      newScreenEl.style.transform = 'translate3d(100%, 0, 0)';
      newScreenEl.style.visibility = 'visible';
      newScreenEl.style.opacity = '1';
      newScreenEl.scrollTop = 0;

      void newScreenEl.offsetWidth; // Force reflow

      // Move previous screen into Ghost Back layer
      oldScreenEl.className = 'app-screen ghost-back';

      // Slide new active screen in
      newScreenEl.className = 'app-screen active';
      newScreenEl.style.transform = '';
    }

    if (onScreenChangeCallback) onScreenChangeCallback(currentScreen, ghostScreen);

    setTimeout(() => {
      isTransitioning = false;
    }, 300);
  }

  function goBack() {
    if (isTransitioning) return;
    if (historyStack.length > 1) {
      window.history.back();
    } else {
      if (window.crmAppData && window.crmAppData.showToast) {
        window.crmAppData.showToast('Already on Home screen');
      }
    }
  }

  function executeBackTransition() {
    if (isTransitioning || historyStack.length <= 1) return;
    isTransitioning = true;

    const exitingScreen = historyStack.pop();
    const targetScreen = historyStack[historyStack.length - 1];

    currentScreen = targetScreen;
    ghostScreen = historyStack.length > 1 ? historyStack[historyStack.length - 2] : null;

    if (window.crmAppData) {
      window.crmAppData.currentScreen = currentScreen;
      window.crmAppData.ghostScreen = ghostScreen;
      window.crmAppData.historyStack = [...historyStack];
    }

    const exitingEl = getScreenElement(exitingScreen);
    const targetEl = getScreenElement(targetScreen);

    if (exitingEl && targetEl) {
      exitingEl.className = 'app-screen sliding-out';
      targetEl.className = 'app-screen restoring-active';

      setTimeout(() => {
        exitingEl.className = 'app-screen';
        targetEl.className = 'app-screen active';
        exitingEl.style.transform = '';
        targetEl.style.transform = '';

        if (ghostScreen) {
          const nextGhostEl = getScreenElement(ghostScreen);
          if (nextGhostEl) nextGhostEl.className = 'app-screen ghost-back';
        }

        isTransitioning = false;
      }, 290);
    } else {
      isTransitioning = false;
    }

    if (onScreenChangeCallback) onScreenChangeCallback(currentScreen, ghostScreen);

    if (window.crmAppData && window.crmAppData.showToast) {
      window.crmAppData.showToast(`Returned to ${SCREEN_NAMES[targetScreen] || 'Screen'}`);
    }
  }

  function applyDomClasses(screenNum) {
    for (let i = 1; i <= 14; i++) {
      const scr = getScreenElement(i);
      if (scr) scr.className = 'app-screen';
    }
    const activeScr = getScreenElement(screenNum);
    if (activeScr) activeScr.className = 'app-screen active';
  }

  function setupSwipeGesture() {
    let startX = 0;
    let startY = 0;

    window.addEventListener('touchstart', (e) => {
      if (e.touches.length === 1) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
      }
    }, { passive: true });

    window.addEventListener('touchend', (e) => {
      if (e.changedTouches.length === 1) {
        const deltaX = e.changedTouches[0].clientX - startX;
        const deltaY = e.changedTouches[0].clientY - startY;

        // Swipe right from left edge (start within 30px of edge, swipe > 80px horizontal, < 60px vertical)
        if (startX <= 35 && deltaX > 80 && Math.abs(deltaY) < 60) {
          if (historyStack.length > 1) {
            goBack();
          }
        }
      }
    }, { passive: true });
  }

  return {
    init,
    navigateTo,
    goBack,
    getScreenName: (id) => SCREEN_NAMES[resolveScreenId(id)] || 'Screen',
    getScreenSlug,
    resolveScreenId,
    getCurrentScreen: () => currentScreen,
    getGhostScreen: () => ghostScreen,
    getHistory: () => [...historyStack]
  };
})();
