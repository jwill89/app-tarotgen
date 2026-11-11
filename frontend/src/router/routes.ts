import type { RouteRecordRaw } from 'vue-router'

// Route components are loaded lazily (() => import(...)) so each view — and its
// heavier dependencies (e.g. the TipTap markdown editor, admin tables) — is
// split into its own chunk and only fetched when that route is visited. This
// keeps the initial bundle to the app shell + shared vendor code.
//
// The table lives apart from the router so specs can build a router on it
// (named routes, real meta) without the app's guards and history.
export const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: {
      description:
        'Draw your own tarot spreads online. Pick from a variety of decks, choose a classic spread or freely draw cards, and share your reading with a link.',
    },
    beforeEnter: (to) => {
      const rid = to.query.rid as string | undefined
      if (rid) {
        return { name: 'reading', params: { id: rid } }
      }
    },
  },
  {
    path: '/new',
    name: 'new-reading',
    component: () => import('@/views/NewReadingView.vue'),
    meta: {
      title: 'New Reading',
      description:
        'Start a new tarot reading: choose a deck and a spread, then draw your cards instantly.',
    },
  },
  {
    // The public spread library: browse by category, see each position, then
    // draw with one (/new?spread=<id>) or copy it into My Spreads.
    path: '/spreads',
    name: 'spread-library',
    component: () => import('@/views/SpreadLibraryView.vue'),
    meta: {
      title: 'Spreads',
      description:
        'Browse tarot spreads by theme, from love and career to decisions and self-reflection. See what each position asks, then draw with a spread or make it your own.',
    },
  },
  {
    path: '/custom',
    name: 'custom-reading',
    component: () => import('@/views/CustomReadingView.vue'),
    meta: {
      title: 'Custom Reading',
      description:
        'Recreate a real tarot spread by placing specific cards in the positions you choose.',
    },
  },
  {
    path: '/reading/:id/placement',
    name: 'free-draw-placement',
    component: () => import('@/views/FreeDrawPlacementView.vue'),
    meta: { title: 'Arrange Draw', noindex: true },
  },
  {
    path: '/reading/:id',
    name: 'reading',
    component: () => import('@/views/ReadingView.vue'),
    meta: { title: 'Reading', noindex: true },
  },
  {
    path: '/create-spread',
    name: 'submit-spread',
    component: () => import('@/views/SubmitSpreadView.vue'),
    meta: {
      title: 'Create Spread',
      description: 'Design a custom tarot spread to use yourself or share with other readers.',
    },
  },
  { path: '/submit-spread', redirect: { name: 'submit-spread' } },
  {
    path: '/submit-deck',
    name: 'submit-deck',
    component: () => import('@/views/SubmitDeckView.vue'),
    meta: {
      title: 'Submit a Deck',
      description: 'Suggest a tarot deck to be added to TarotGen.io.',
    },
  },
  {
    path: '/submit-deck-system',
    name: 'submit-deck-system',
    component: () => import('@/views/SubmitDeckSystemView.vue'),
    meta: {
      title: 'Submit a Deck System',
      description: 'Propose a new tarot deck system for TarotGen.io.',
    },
  },
  {
    // Public landing page: how to install and use the FFXIV (Dalamud) plugin.
    // Path is deliberately NOT /plugin — that URL is the backend-served custom
    // Dalamud repo directory (repo.json / latest.zip / icon.png).
    path: '/ffxiv-plugin',
    name: 'ffxiv-plugin',
    component: () => import('@/views/PluginView.vue'),
    meta: {
      title: 'FFXIV Plugin',
      description:
        'Draw and view TarotGen.io tarot readings inside Final Fantasy XIV. Install the free Dalamud plugin and get started in three steps.',
    },
  },
  {
    path: '/contact',
    name: 'contact',
    component: () => import('@/views/ContactView.vue'),
    meta: { title: 'Contact', description: 'Get in touch with the team behind TarotGen.io.' },
  },
  {
    path: '/privacy',
    name: 'privacy-policy',
    component: () => import('@/views/PrivacyPolicyView.vue'),
    meta: {
      title: 'Privacy Policy',
      description: 'How TarotGen.io collects, uses, and protects your data.',
    },
  },
  {
    path: '/terms',
    name: 'terms-of-service',
    component: () => import('@/views/TermsOfServiceView.vue'),
    meta: {
      title: 'Terms of Service',
      description: 'The terms governing your use of TarotGen.io.',
    },
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { title: 'Register', userGuest: true, noindex: true },
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: 'Login', userGuest: true, noindex: true },
  },
  {
    path: '/activate',
    name: 'activate',
    component: () => import('@/views/ActivateView.vue'),
    meta: { title: 'Activate Account', noindex: true },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/ForgotPasswordView.vue'),
    meta: { title: 'Forgot Password', noindex: true },
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/ResetPasswordView.vue'),
    meta: { title: 'Reset Password', noindex: true },
  },
  {
    // Browser consent step for the Dalamud plugin's account link (opened by the
    // plugin with PKCE query params). NOT userOnly: the view revalidates the
    // session itself and shows an inline "log in to continue" prompt when
    // needed, so an already-signed-in user is never bounced to /login.
    path: '/authorize',
    name: 'authorize-plugin',
    component: () => import('@/views/AuthorizePluginView.vue'),
    meta: { title: 'Authorize Plugin', noindex: true },
  },
  {
    // Where an unlinked plugin gets its draw key (opened by the plugin with PKCE
    // query params, as /authorize): a Turnstile challenge, then the key goes to
    // its loopback listener. Public. Not under /plugin/, which is the plugin's
    // Dalamud repo directory on the server (a missing file there is a 404).
    path: '/draw-key',
    name: 'plugin-draw-key',
    component: () => import('@/views/PluginDrawKeyView.vue'),
    meta: { title: 'Plugin Draw Key', noindex: true },
  },
  {
    path: '/account',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { title: 'Dashboard', userOnly: true, noindex: true },
  },
  {
    path: '/account/readings',
    name: 'account-readings',
    component: () => import('@/views/AccountReadingsView.vue'),
    meta: { title: 'My Readings', userOnly: true, noindex: true },
  },
  {
    // One card a day for a signed-in reader; a guest is sent to sign in first.
    path: '/daily',
    name: 'daily-card',
    component: () => import('@/views/DailyCardView.vue'),
    meta: { title: 'Daily Card', userOnly: true, noindex: true },
  },
  {
    path: '/account/settings',
    name: 'account-settings',
    component: () => import('@/views/AccountSettingsView.vue'),
    meta: { title: 'Account Settings', userOnly: true, noindex: true },
  },
  {
    path: '/account/spreads',
    name: 'account-spreads',
    component: () => import('@/views/AccountSpreadsView.vue'),
    meta: { title: 'My Spreads', userOnly: true, noindex: true },
  },
  // The admin-login URL redirects to the account login, which every user shares.
  { path: '/admin/login', redirect: { name: 'login' } },
  {
    path: '/admin',
    name: 'admin-dashboard',
    component: () => import('@/views/admin/DashboardView.vue'),
    meta: { title: 'Admin Dashboard', admin: true, noindex: true },
  },
  {
    path: '/admin/decks',
    name: 'admin-decks',
    component: () => import('@/views/admin/DecksView.vue'),
    meta: { title: 'Admin Decks', admin: true, noindex: true },
  },
  {
    path: '/admin/deck-systems',
    name: 'admin-deck-systems',
    component: () => import('@/views/admin/DeckSystemsView.vue'),
    meta: { title: 'Admin Deck Systems', admin: true, noindex: true },
  },
  // The deck-system editor and the submission queue are pages of their own under
  // the list, so each is in the URL and Back leaves it for the list.
  {
    path: '/admin/deck-systems/new',
    name: 'admin-deck-system-new',
    component: () => import('@/views/admin/DeckSystemEditorView.vue'),
    meta: { title: 'Admin Add Deck System', admin: true, noindex: true },
  },
  {
    path: '/admin/deck-systems/:id/edit',
    name: 'admin-deck-system-edit',
    component: () => import('@/views/admin/DeckSystemEditorView.vue'),
    meta: { title: 'Admin Edit Deck System', admin: true, noindex: true },
  },
  {
    path: '/admin/deck-systems/submissions',
    name: 'admin-deck-system-submissions',
    component: () => import('@/views/admin/DeckSystemSubmissionsView.vue'),
    meta: { title: 'Admin Deck System Submissions', admin: true, noindex: true },
  },
  {
    path: '/admin/special-cards',
    name: 'admin-special-cards',
    component: () => import('@/views/admin/SpecialCardsView.vue'),
    meta: { title: 'Admin Special Cards', admin: true, noindex: true },
  },
  {
    path: '/admin/spreads',
    name: 'admin-spreads',
    component: () => import('@/views/admin/SpreadsView.vue'),
    meta: { title: 'Admin Spreads', admin: true, noindex: true },
  },
  // The spread editor, the categories and the submission queue are pages of
  // their own under the list, so each is in the URL and Back leaves it for the list.
  {
    path: '/admin/spreads/new',
    name: 'admin-spread-new',
    component: () => import('@/views/admin/SpreadEditorView.vue'),
    meta: { title: 'Admin Add Spread', admin: true, noindex: true },
  },
  {
    path: '/admin/spreads/:id/edit',
    name: 'admin-spread-edit',
    component: () => import('@/views/admin/SpreadEditorView.vue'),
    meta: { title: 'Admin Edit Spread', admin: true, noindex: true },
  },
  {
    path: '/admin/spreads/categories',
    name: 'admin-spread-categories',
    component: () => import('@/views/admin/SpreadCategoriesView.vue'),
    meta: { title: 'Admin Spread Categories', admin: true, noindex: true },
  },
  {
    path: '/admin/spreads/submissions',
    name: 'admin-spread-submissions',
    component: () => import('@/views/admin/SpreadSubmissionsView.vue'),
    meta: { title: 'Admin Spread Submissions', admin: true, noindex: true },
  },
  {
    path: '/admin/readings',
    name: 'admin-readings',
    component: () => import('@/views/admin/ReadingsView.vue'),
    meta: { title: 'Admin Readings', admin: true, noindex: true },
  },
  {
    path: '/admin/users',
    name: 'admin-users',
    component: () => import('@/views/admin/UsersView.vue'),
    meta: { title: 'Admin Users', admin: true, noindex: true },
  },
  {
    path: '/admin/contacts',
    name: 'admin-contacts',
    component: () => import('@/views/admin/ContactsView.vue'),
    meta: { title: 'Admin Contacts', admin: true, noindex: true },
  },
  {
    path: '/admin/card-reports',
    name: 'admin-card-reports',
    component: () => import('@/views/admin/ErroredCardsView.vue'),
    meta: { title: 'Errored Cards', admin: true, noindex: true },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]
