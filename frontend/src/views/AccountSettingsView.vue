<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAccount } from '@/composables/useAccount'
import { useUser } from '@/composables/useUser'
import { usePasskeys } from '@/composables/usePasskeys'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { usePluginLink } from '@/composables/usePluginLink'
import PageHeader from '@/components/PageHeader.vue'
import Tooltip from '@/components/Tooltip.vue'
import { endpoints } from '@/api/endpoints'
import type { PluginToken } from '@/types'

const router = useRouter()
const route = useRoute()
const {
  changeDisplayName,
  changePassword,
  endOtherSessions,
  journalExportUrl,
  deleteAccount,
  unlinkGoogle: unlinkGoogleAccount,
} = useAccount()
const { currentUser, fetchMe, requestPasswordReset } = useUser()
const { confirm } = useConfirm()
const toasts = useToasts()
const {
  passkeys,
  loading: passkeysLoading,
  isSupported: passkeySupported,
  listPasskeys,
  registerPasskey,
  renamePasskey,
  deletePasskey,
  togglePasswordLogin,
} = usePasskeys()

// Display name
const displayName = ref(currentUser.value?.display_name ?? '')
const nameSaving = ref(false)
const nameError = ref('')

async function saveName() {
  nameError.value = ''
  nameSaving.value = true
  try {
    const res = await changeDisplayName(displayName.value)
    if (res.ok) {
      toasts.success('Display name updated.')
    } else {
      nameError.value = res.error ?? 'Could not update display name.'
    }
  } finally {
    nameSaving.value = false
  }
}

// Ways to sign in. The server keeps at least one at all times (password login
// off, deleting a passkey and unlinking Google are refused when it would leave
// none), so the controls below don't offer what it would refuse.
const hasPassword = computed(() => currentUser.value?.has_password ?? true)
const passwordWorks = computed(
  () => hasPassword.value && !(currentUser.value?.password_login_disabled ?? false),
)
const googleLinked = computed(() => currentUser.value?.google_linked ?? false)
const canDisablePasswordLogin = computed(
  () => (currentUser.value?.has_passkeys ?? false) || googleLinked.value,
)
const canUnlinkGoogle = computed(
  () => passwordWorks.value || (currentUser.value?.has_passkeys ?? false),
)
/** What turns a password into a way to sign in: setting one, or turning its login back on. */
const passwordFix = computed(() =>
  hasPassword.value ? 'turn password login back on' : 'set a password',
)
/** Whether a passkey may go: another passkey, a working password or Google stays. */
const canRemovePasskey = computed(
  () => passkeys.value.length > 1 || passwordWorks.value || googleLinked.value,
)

// Setting a first password (an account made through Google has none): the
// emailed reset link is the proof of identity, as for a forgotten one.
const setLinkSending = ref(false)
const setLinkSent = ref(false)
const setLinkDev = ref('')
const setLinkError = ref('')

async function emailSetPasswordLink() {
  const email = currentUser.value?.email
  if (!email) {
    return
  }
  setLinkError.value = ''
  setLinkSending.value = true
  try {
    const res = await requestPasswordReset(email)
    if (res.ok) {
      setLinkSent.value = true
      setLinkDev.value = res.resetLink ?? ''
    } else {
      setLinkError.value = res.message ?? 'Could not send the link. Please try again.'
    }
  } finally {
    setLinkSending.value = false
  }
}

// Password
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const pwSaving = ref(false)
const pwError = ref('')

async function savePassword() {
  pwError.value = ''
  if (newPassword.value !== confirmPassword.value) {
    pwError.value = 'New passwords do not match.'
    return
  }
  pwSaving.value = true
  try {
    const res = await changePassword(currentPassword.value, newPassword.value)
    if (res.ok) {
      toasts.success('Password changed. Your other sessions were signed out.')
      currentPassword.value = ''
      newPassword.value = ''
      confirmPassword.value = ''
    } else {
      pwError.value = res.error ?? 'Could not change password.'
    }
  } finally {
    pwSaving.value = false
  }
}

// Other devices: every other session of the account ends; this browser stays.
const endingSessions = ref(false)
const sessionsError = ref('')

async function signOutOtherDevices() {
  const ok = await confirm({
    title: 'Sign out of other devices',
    message:
      'Every other browser and device signed in to your account will be signed out. This browser stays signed in.',
    confirmLabel: 'Sign out other devices',
  })
  if (!ok) {
    return
  }

  sessionsError.value = ''
  endingSessions.value = true
  try {
    const res = await endOtherSessions()
    if (!res.ok) {
      sessionsError.value = res.error
    } else if (res.ended === 0) {
      toasts.success('No other devices were signed in.')
    } else {
      toasts.success(`Signed out of ${res.ended} other device${res.ended === 1 ? '' : 's'}.`)
    }
  } finally {
    endingSessions.value = false
  }
}

// Delete account
const deletePassword = ref('')
const deleting = ref(false)
const deleteError = ref('')

async function removeAccount() {
  deleteError.value = ''
  if (!deletePassword.value) {
    deleteError.value = 'Enter your password to confirm.'
    return
  }
  const ok = await confirm({
    title: 'Delete your account',
    message:
      'This permanently deletes your account and ALL of your saved readings — anyone with a link to them will no longer be able to view them. This cannot be undone. Are you sure?',
    confirmLabel: 'Delete my account',
    danger: true,
  })
  if (!ok) {
    return
  }

  deleting.value = true
  try {
    const res = await deleteAccount(deletePassword.value)
    if (res.ok) {
      toasts.success('Your account has been deleted.')
      void router.push({ name: 'home' })
    } else {
      deleteError.value = res.error ?? 'Could not delete account.'
    }
  } finally {
    deleting.value = false
  }
}

// Google link/unlink
const unlinking = ref(false)
const googleError = ref('')

// Passkeys
const passkeyName = ref('')
const passkeyRegistering = ref(false)
const passkeyError = ref('')
const passkeyTogglingPw = ref(false)

onMounted(() => {
  // Handle OAuth redirect messages on this page.
  const oauthError = route.query.oauth_error
  const oauthSuccess = route.query.oauth_success
  if (typeof oauthError === 'string' && oauthError) {
    googleError.value = oauthError
    void router.replace({ ...route, query: {} })
  }
  if (typeof oauthSuccess === 'string' && oauthSuccess) {
    toasts.success(oauthSuccess)
    void router.replace({ ...route, query: {} })
    void fetchMe()
  }

  // Load passkeys
  if (passkeySupported()) {
    void listPasskeys()
  }

  void loadPluginTokens()
})

function linkGoogle() {
  window.location.href = '/api' + endpoints.authGoogle.start + '?intent=link'
}

async function unlinkGoogle() {
  googleError.value = ''
  unlinking.value = true
  const res = await unlinkGoogleAccount()
  if (res.ok) {
    toasts.success('Google account unlinked.')
    await fetchMe()
  } else {
    googleError.value = res.error ?? 'Could not unlink Google account.'
  }
  unlinking.value = false
}

// ── Passkey management ──────────────────────────────────────────

async function addPasskey() {
  passkeyError.value = ''
  const name = passkeyName.value.trim() || 'My Passkey'
  passkeyRegistering.value = true
  try {
    const res = await registerPasskey(name)
    if (res.ok) {
      toasts.success('Passkey registered!')
      passkeyName.value = ''
    } else {
      passkeyError.value = res.error ?? 'Could not register passkey.'
    }
  } finally {
    passkeyRegistering.value = false
  }
}

async function removePasskey(id: number, name: string) {
  const ok = await confirm({
    title: 'Remove passkey',
    message: `Remove "${name}"? You won't be able to use it to sign in anymore.`,
    confirmLabel: 'Remove',
    danger: true,
  })
  if (!ok) {
    return
  }

  passkeyError.value = ''
  const res = await deletePasskey(id)
  if (res.ok) {
    toasts.success('Passkey removed.')
  } else {
    passkeyError.value = res.error ?? 'Could not remove passkey.'
  }
}

async function editPasskeyName(id: number, currentName: string) {
  const newName = prompt('Rename passkey:', currentName)
  if (!newName || newName.trim() === currentName) {
    return
  }
  const res = await renamePasskey(id, newName.trim())
  if (res.ok) {
    toasts.success('Passkey renamed.')
  } else {
    passkeyError.value = res.error ?? 'Could not rename passkey.'
  }
}

async function handleTogglePasswordLogin() {
  const nowDisabled = currentUser.value?.password_login_disabled ?? false
  const newState = !nowDisabled

  if (newState) {
    const ok = await confirm({
      title: 'Disable password login',
      message: 'You will only be able to sign in with a passkey or Google. Are you sure?',
      confirmLabel: 'Disable password login',
      danger: true,
    })
    if (!ok) {
      return
    }
  }

  passkeyTogglingPw.value = true
  passkeyError.value = ''
  try {
    const res = await togglePasswordLogin(newState)
    if (res.ok) {
      toasts.success(newState ? 'Password login disabled.' : 'Password login re-enabled.')
    } else {
      passkeyError.value = res.error ?? 'Could not update setting.'
    }
  } finally {
    passkeyTogglingPw.value = false
  }
}

// ── Connected apps (Dalamud plugin links) ───────────────────────
const { listTokens, revokeToken } = usePluginLink()
const pluginTokens = ref<PluginToken[]>([])
const pluginTokensLoading = ref(false)

async function loadPluginTokens() {
  pluginTokensLoading.value = true
  try {
    pluginTokens.value = await listTokens()
  } finally {
    pluginTokensLoading.value = false
  }
}

async function revokePluginToken(token: PluginToken) {
  const ok = await confirm({
    title: 'Revoke plugin access',
    message: `Revoke "${token.label}"? The linked plugin will need to link again to reconnect.`,
    confirmLabel: 'Revoke',
    danger: true,
  })
  if (!ok) {
    return
  }

  const done = await revokeToken(token.id)
  if (done) {
    toasts.success('Plugin access revoked.')
    await loadPluginTokens()
  } else {
    toasts.error('Could not revoke access. Please try again.')
  }
}
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader
            title="Account Settings"
            subtitle="Manage your display name, password, sign-in methods, devices and connected apps, and export your journal."
          />

          <!-- Display name -->
          <div class="settings-panel mb-5">
            <h2 id="settings-display-name" class="title is-5">Display Name</h2>
            <form @submit.prevent="saveName">
              <div class="field">
                <div class="control has-icons-left">
                  <input
                    v-model="displayName"
                    class="input"
                    aria-labelledby="settings-display-name"
                    type="text"
                    maxlength="30"
                    autocomplete="nickname"
                  />
                  <span class="icon is-small is-left"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['user']"
                  /></span>
                </div>
              </div>
              <div v-if="nameError" class="notification is-danger is-light">{{ nameError }}</div>
              <button class="button is-success" type="submit" :class="{ 'is-loading': nameSaving }">
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']"
                /></span>
                <span>Save Name</span>
              </button>
            </form>
          </div>

          <!-- Password -->
          <div v-if="!hasPassword" class="settings-panel mb-5">
            <h2 class="title is-5">Password</h2>
            <p class="mb-3">
              {{
                googleLinked
                  ? 'Your account signs in with Google and has no password yet.'
                  : 'Your account has no password yet.'
              }}
              To set one, we'll email a link to <strong>{{ currentUser?.email }}</strong>. Setting
              it signs you out everywhere, so you'll sign in again with the new password.
            </p>
            <div v-if="setLinkError" class="notification is-danger is-light">
              {{ setLinkError }}
            </div>
            <div v-if="setLinkSent && !setLinkDev" class="notification is-success is-light">
              Link sent. Open the email we just sent to set your password; the link works for an
              hour.
            </div>
            <div v-if="setLinkDev" class="myst-callout mb-3">
              <p class="mb-2">
                <strong>Dev shortcut:</strong> email isn't configured here, so set it directly:
              </p>
              <a class="button is-small is-link" :href="setLinkDev">Set my password</a>
            </div>
            <button
              class="button is-success"
              type="button"
              :class="{ 'is-loading': setLinkSending }"
              @click="emailSetPasswordLink"
            >
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['envelope']" /></span>
              <span>Email me a link to set a password</span>
            </button>
          </div>
          <div v-else class="settings-panel mb-5">
            <h2 class="title is-5">Change Password</h2>
            <form @submit.prevent="savePassword">
              <div class="field">
                <label class="label" for="cur-pw">Current Password</label>
                <input
                  id="cur-pw"
                  v-model="currentPassword"
                  class="input"
                  type="password"
                  autocomplete="current-password"
                  required
                />
              </div>
              <div class="field">
                <label class="label" for="new-pw">New Password</label>
                <input
                  id="new-pw"
                  v-model="newPassword"
                  class="input"
                  type="password"
                  autocomplete="new-password"
                  required
                />
                <p class="help">At least 12 characters. A memorable passphrase works well.</p>
              </div>
              <div class="field">
                <label class="label" for="conf-pw">Confirm New Password</label>
                <input
                  id="conf-pw"
                  v-model="confirmPassword"
                  class="input"
                  type="password"
                  autocomplete="new-password"
                  required
                />
              </div>
              <div v-if="pwError" class="notification is-danger is-light">{{ pwError }}</div>
              <button class="button is-success" type="submit" :class="{ 'is-loading': pwSaving }">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['key']" /></span>
                <span>Change Password</span>
              </button>
            </form>
          </div>

          <!-- Google Account -->
          <div class="settings-panel mb-5">
            <h2 class="title is-5">Google Account</h2>
            <div v-if="googleError" class="notification is-danger is-light">{{ googleError }}</div>
            <template v-if="currentUser?.google_linked">
              <p class="mb-3">
                <span class="icon has-text-success"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-check']"
                /></span>
                Your Google account is linked. You can sign in with Google.
              </p>
              <button
                type="button"
                class="button is-warning"
                :class="{ 'is-loading': unlinking }"
                :disabled="!canUnlinkGoogle"
                @click="unlinkGoogle"
              >
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fab['google']" /></span>
                <span>Unlink Google</span>
              </button>
              <p v-if="!canUnlinkGoogle" class="help">
                Google is your only way to sign in. To unlink it, first {{ passwordFix }} or add a
                passkey.
              </p>
            </template>
            <template v-else>
              <p class="mb-3">Link your Google account to enable one-click sign in.</p>
              <button type="button" class="button is-info" @click="linkGoogle">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fab['google']" /></span>
                <span>Link Google Account</span>
              </button>
            </template>
          </div>

          <!-- Passkeys -->
          <div v-if="passkeySupported()" class="settings-panel mb-5">
            <h2 class="title is-5">Passkeys</h2>
            <p class="mb-3">
              Passkeys let you sign in with biometrics (fingerprint, face) or your device's screen
              lock instead of a password.
            </p>

            <div v-if="passkeyError" class="notification is-danger is-light">
              {{ passkeyError }}
            </div>

            <!-- Existing passkeys -->
            <div v-if="passkeys.length > 0" class="mb-4">
              <div v-for="pk in passkeys" :key="pk.passkey_id" class="passkey-item">
                <div class="passkey-info">
                  <span class="icon has-text-info"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['key']"
                  /></span>
                  <span class="passkey-name">{{ pk.name }}</span>
                  <span class="is-size-7 has-text-grey ml-2">
                    Added {{ new Date(pk.created_at).toLocaleDateString() }}
                    <template v-if="pk.last_used_at">
                      · Last used {{ new Date(pk.last_used_at).toLocaleDateString() }}</template
                    >
                  </span>
                </div>
                <div class="passkey-actions">
                  <Tooltip text="Rename">
                    <button
                      type="button"
                      class="button is-small"
                      aria-label="Rename"
                      @click="editPasskeyName(pk.passkey_id, pk.name)"
                    >
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']"
                      /></span>
                    </button>
                  </Tooltip>
                  <Tooltip text="Remove">
                    <button
                      type="button"
                      class="button is-small is-danger is-light"
                      aria-label="Remove"
                      :disabled="!canRemovePasskey"
                      @click="removePasskey(pk.passkey_id, pk.name)"
                    >
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']"
                      /></span>
                    </button>
                  </Tooltip>
                </div>
              </div>
              <p v-if="!canRemovePasskey" class="help">
                This passkey is your only way to sign in. To remove it, first {{ passwordFix }} or
                link your Google account.
              </p>
            </div>
            <p v-else-if="!passkeysLoading" class="has-text-grey mb-4">
              No passkeys registered yet.
            </p>

            <!-- Register new passkey -->
            <div class="field has-addons">
              <div class="control is-expanded">
                <input
                  v-model="passkeyName"
                  class="input"
                  aria-label="Passkey name"
                  type="text"
                  placeholder="Passkey name (e.g. MacBook, Phone)"
                  maxlength="50"
                />
              </div>
              <div class="control">
                <button
                  type="button"
                  class="button is-info"
                  :class="{ 'is-loading': passkeyRegistering }"
                  @click="addPasskey"
                >
                  <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                  <span>Add Passkey</span>
                </button>
              </div>
            </div>

            <!-- Password login toggle (pointless without a password to sign in with) -->
            <template v-if="hasPassword || currentUser?.password_login_disabled">
              <hr />
              <div class="field">
                <span class="label">Password Login</span>
                <p class="mb-2 is-size-7 has-text-grey">
                  If you have a passkey or a linked Google account, you can disable password login
                  for extra security. You can always re-enable it here. This does not affect Google
                  sign-in.
                </p>
                <button
                  type="button"
                  class="button"
                  :class="[
                    currentUser?.password_login_disabled ? 'is-success' : 'is-warning',
                    { 'is-loading': passkeyTogglingPw },
                  ]"
                  :disabled="!canDisablePasswordLogin && !currentUser?.password_login_disabled"
                  @click="handleTogglePasswordLogin"
                >
                  <span class="icon">
                    <FontAwesomeIcon
                      :icon="
                        currentUser?.password_login_disabled
                          ? byPrefixAndName.fas['lock-open']
                          : byPrefixAndName.fas['lock']
                      "
                    />
                  </span>
                  <span>{{
                    currentUser?.password_login_disabled
                      ? 'Re-enable Password Login'
                      : 'Disable Password Login'
                  }}</span>
                </button>
                <p
                  v-if="!canDisablePasswordLogin && !currentUser?.password_login_disabled"
                  class="help"
                >
                  Add a passkey or link your Google account first, so you can still sign in.
                </p>
              </div>
            </template>
          </div>

          <!-- Other devices -->
          <div class="settings-panel mb-5">
            <h2 class="title is-5">Signed-in Devices</h2>
            <p class="mb-3">
              Signed in on a shared computer, or on a device you no longer have? Sign out everywhere
              except this browser. Linked apps keep their access; revoke them under Connected Apps.
            </p>
            <div v-if="sessionsError" class="notification is-danger is-light">
              {{ sessionsError }}
            </div>
            <button
              class="button is-warning"
              type="button"
              :class="{ 'is-loading': endingSessions }"
              @click="signOutOtherDevices"
            >
              <span class="icon"
                ><FontAwesomeIcon :icon="byPrefixAndName.fas['right-from-bracket']"
              /></span>
              <span>Sign out of all other devices</span>
            </button>
          </div>

          <!-- Connected Apps (Dalamud plugin) -->
          <div class="settings-panel mb-5">
            <h2 class="title is-5">Connected Apps</h2>
            <p class="mb-3">
              Apps linked to your account, like the TarotGen FFXIV plugin. Revoke access any time; a
              revoked plugin must link again to reconnect.
            </p>
            <div v-if="pluginTokens.length > 0" class="mb-2">
              <div v-for="t in pluginTokens" :key="t.id" class="passkey-item">
                <div class="passkey-info">
                  <span class="passkey-name">{{ t.label }}</span>
                  <span class="is-size-7 has-text-grey ml-2">
                    Linked {{ new Date(t.created_at).toLocaleDateString() }}
                    <template v-if="t.last_used_at">
                      · Last used {{ new Date(t.last_used_at).toLocaleDateString() }}</template
                    >
                    <template v-if="t.revoked_at"> · Revoked</template>
                  </span>
                </div>
                <div class="passkey-actions">
                  <Tooltip v-if="!t.revoked_at" text="Revoke">
                    <button
                      type="button"
                      class="button is-small is-danger is-light"
                      @click="revokePluginToken(t)"
                    >
                      <span class="icon"
                        ><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']"
                      /></span>
                      <span>Revoke</span>
                    </button>
                  </Tooltip>
                </div>
              </div>
            </div>
            <p v-else-if="!pluginTokensLoading" class="has-text-grey">No linked apps.</p>
          </div>

          <!-- Journal export: plain links, so the browser saves the attachment itself. -->
          <div class="settings-panel mb-5">
            <h2 class="title is-5">Export Your Journal</h2>
            <p class="mb-3">Download every reading you've saved, to keep or to use elsewhere.</p>
            <div class="buttons mb-2">
              <a class="button is-link" :href="journalExportUrl('json')" download>
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['file-arrow-down']"
                /></span>
                <span>Download JSON</span>
              </a>
              <a class="button is-link" :href="journalExportUrl('csv')" download>
                <span class="icon"
                  ><FontAwesomeIcon :icon="byPrefixAndName.fas['file-arrow-down']"
                /></span>
                <span>Download CSV</span>
              </a>
            </div>
            <p class="help">
              JSON keeps everything: each reading's spread and positions, its cards and your notes.
              CSV is for spreadsheets, with one row per drawn card, so a reading's name and notes
              repeat on each of its cards' rows.
            </p>
          </div>

          <!-- Danger zone -->
          <div class="settings-panel danger-zone">
            <h2 class="title is-5 has-text-danger">Delete Account</h2>
            <p class="mb-3">
              Permanently delete your account and <strong>all of your readings</strong>. This cannot
              be undone.
            </p>
            <p v-if="!hasPassword">
              Deleting your account needs your password, and it doesn't have one yet. Set one first
              with <strong>Email me a link to set a password</strong> under Password, above.
            </p>
            <template v-else>
              <div class="field">
                <label class="label" for="del-pw">Confirm your password</label>
                <input
                  id="del-pw"
                  v-model="deletePassword"
                  class="input"
                  type="password"
                  autocomplete="current-password"
                  placeholder="Your password"
                />
              </div>
              <div v-if="deleteError" class="notification is-danger is-light">
                {{ deleteError }}
              </div>
              <button
                type="button"
                class="button is-danger"
                :class="{ 'is-loading': deleting }"
                @click="removeAccount"
              >
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                <span>Delete My Account</span>
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.danger-zone {
  border: 1px solid var(--myst-danger);
}

.passkey-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--myst-border);
}

.passkey-item:last-child {
  border-bottom: none;
}

.passkey-info {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  flex-wrap: wrap;
}

.passkey-name {
  font-weight: 600;
}

.passkey-actions {
  display: flex;
  gap: 0.25rem;
}
</style>
