<script setup lang="ts">
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { useDataTable } from '@/composables/useDataTable'
import { useUser } from '@/composables/useUser'
import SortableTh from '@/components/admin/SortableTh.vue'
import IconButton from '@/components/IconButton.vue'
import Tooltip from '@/components/Tooltip.vue'
import { formatDateTime } from '@/utils/datetime'
import type { AdminUser } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()
const { currentUser } = useUser()

const users = ref<AdminUser[]>([])
const busyId = ref<number | null>(null)

const {
  search,
  sortKey,
  sortDir,
  rows: visibleUsers,
  toggleSort,
} = useDataTable(users, {
  searchText: (u) => `${u.email} ${u.display_name} ${u.user_id}`,
  sortAccessors: {
    user_id: (u) => u.user_id,
    email: (u) => u.email,
    display_name: (u) => u.display_name,
    is_active: (u) => (u.is_active ? 1 : 0),
    registered_at: (u) => u.registered_at,
    last_login_at: (u) => u.last_login_at ?? '',
  },
  initialSort: 'registered_at',
  initialDir: 'desc',
})

async function fetchUsers() {
  const data = await api.get<AdminUser[]>(endpoints.admin.users.list)
  if (data) {
    users.value = data
  }
}

/**
 * Your own account (Account Settings is where you leave) and a protected one
 * can't be demoted or deleted: the server refuses, so neither is offered. A
 * refusal that happens anyway comes back as a toast with the server's reason.
 */
function isLocked(user: AdminUser): boolean {
  return user.is_protected || user.user_id === currentUser.value?.user_id
}

async function activate(user: AdminUser) {
  busyId.value = user.user_id
  const result = await api.post(
    endpoints.admin.users.activate(user.user_id),
    {},
    `${user.display_name} activated.`,
  )
  busyId.value = null
  if (result) {
    await fetchUsers()
  }
}

async function resend(user: AdminUser) {
  busyId.value = user.user_id
  const result = await api.post<{ success: boolean; emailed: boolean; activation_link?: string }>(
    endpoints.admin.users.resendActivation(user.user_id),
    {},
  )
  busyId.value = null

  if (!result) {
    return
  }

  if (result.emailed) {
    toasts.success(`Activation email sent to ${user.email}.`)
  } else if (result.activation_link) {
    // Dev/no-SMTP: surface the link so it can be used directly.
    toasts.warning(`Email not configured. Activation link: ${result.activation_link}`, {
      duration: 0,
    })
  } else {
    toasts.success('Activation link regenerated.')
  }
}

async function toggleAdmin(user: AdminUser) {
  const makingAdmin = !user.is_admin
  const ok = await confirm({
    title: makingAdmin ? 'Grant admin' : 'Revoke admin',
    message: makingAdmin
      ? `Give ${user.display_name} (${user.email}) the admin flag?`
      : `Remove the admin flag from ${user.display_name} (${user.email})?`,
    confirmLabel: makingAdmin ? 'Make admin' : 'Revoke admin',
    danger: !makingAdmin,
  })
  if (!ok) {
    return
  }

  busyId.value = user.user_id
  const result = await api.patch<AdminUser>(
    endpoints.admin.users.byId(user.user_id),
    { is_admin: makingAdmin },
    makingAdmin
      ? `${user.display_name} is now an admin.`
      : `Admin removed from ${user.display_name}.`,
  )
  busyId.value = null
  if (result) {
    await fetchUsers()
  }
}

async function remove(user: AdminUser) {
  const ok = await confirm({
    title: 'Delete account',
    message: `Permanently delete ${user.display_name} (${user.email})? This also deletes ALL of their saved readings and cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) {
    return
  }
  const result = await api.del(endpoints.admin.users.byId(user.user_id), 'Account deleted.')
  if (result) {
    await fetchUsers()
  }
}

onMounted(fetchUsers)
</script>

<template>
  <AdminListPage
    title="Manage Users"
    subtitle="Activate, resend activation emails, or remove accounts."
  >
    <AdminSearchField v-model="search" placeholder="Search by email, name, or ID..." />

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="ID"
                sort-key="user_id"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Email"
                sort-key="email"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Display Name"
                sort-key="display_name"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Status"
                sort-key="is_active"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Registered"
                sort-key="registered_at"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Last Login"
                sort-key="last_login_at"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in visibleUsers" :key="user.user_id">
              <td>{{ user.user_id }}</td>
              <td>{{ user.email }}</td>
              <td>
                {{ user.display_name }}
                <Tooltip v-if="user.is_admin" text="Admin">
                  <span class="icon has-text-link ml-1"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['user-shield']"
                  /></span>
                </Tooltip>
                <Tooltip v-if="user.google_linked" text="Google linked">
                  <span class="icon has-text-info ml-1"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fab['google']"
                  /></span>
                </Tooltip>
                <Tooltip v-if="user.has_passkeys" text="Has passkey(s)">
                  <span class="icon has-text-success ml-1"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['key']"
                  /></span>
                </Tooltip>
                <Tooltip v-if="user.is_protected" text="Protected: can't be demoted or deleted">
                  <span class="icon has-text-grey ml-1"
                    ><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']"
                  /></span>
                </Tooltip>
              </td>
              <td>
                <span v-if="user.is_active" class="status-tag is-on">Active</span>
                <span v-else class="status-tag is-warn">Pending</span>
              </td>
              <td>{{ formatDateTime(user.registered_at) }}</td>
              <td>{{ user.last_login_at ? formatDateTime(user.last_login_at) : '—' }}</td>
              <td>
                <div class="row-actions">
                  <IconButton
                    v-if="!user.is_active"
                    :icon="byPrefixAndName.fas['circle-check']"
                    label="Activate"
                    intent="success"
                    :loading="busyId === user.user_id"
                    @click="activate(user)"
                  />
                  <IconButton
                    v-if="!user.is_active"
                    :icon="byPrefixAndName.fas['paper-plane']"
                    label="Resend Email"
                    intent="gold"
                    :loading="busyId === user.user_id"
                    @click="resend(user)"
                  />
                  <IconButton
                    v-if="!user.is_admin || !isLocked(user)"
                    :icon="byPrefixAndName.fas['user-shield']"
                    :label="user.is_admin ? 'Revoke Admin' : 'Make Admin'"
                    :intent="user.is_admin ? 'warning' : 'default'"
                    :disabled="busyId === user.user_id"
                    @click="toggleAdmin(user)"
                  />
                  <IconButton
                    v-if="!isLocked(user)"
                    :icon="byPrefixAndName.fas['trash']"
                    label="Delete"
                    intent="danger"
                    @click="remove(user)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="users.length === 0" class="has-text-grey">No accounts yet.</p>
  </AdminListPage>
</template>
