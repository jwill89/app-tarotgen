<script setup lang="ts">
import AdminListPage from '@/components/admin/AdminListPage.vue'
import AdminSearchField from '@/components/admin/AdminSearchField.vue'
import { byPrefixAndName } from '@/fontawesome'
import { ref, computed, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import BaseModal from '@/components/BaseModal.vue'
import IconButton from '@/components/IconButton.vue'
import { formatDateTime } from '@/utils/datetime'
import { renderMarkdown } from '@/utils/markdown'
import type { Contact } from '@/types'

const api = useAdminApi()

const contacts = ref<Contact[]>([])
const showRead = ref(false)
const busyId = ref<number | null>(null)
const viewingContact = ref<Contact | null>(null)

const {
  search,
  sortKey,
  sortDir,
  rows: visibleContacts,
  toggleSort,
} = useDataTable(contacts, {
  searchText: (c) => `${c.name} ${c.email} ${c.message} ${c.contact_id}`,
  sortAccessors: {
    contact_id: (c) => c.contact_id,
    submitted_at: (c) => c.submitted_at,
    name: (c) => c.name,
    email: (c) => c.email,
  },
  initialSort: 'submitted_at',
  initialDir: 'desc',
})

const modalActive = computed(() => viewingContact.value !== null)
const modalHtml = computed(() => renderMarkdown(viewingContact.value?.message ?? ''))

async function fetchContacts() {
  const data = await api.get<Contact[]>(endpoints.admin.contacts.list(showRead.value))
  if (data) {
    contacts.value = data
  }
}

async function toggleRead(contact: Contact) {
  const newRead = !contact.is_read
  busyId.value = contact.contact_id
  const result = await api.patch(endpoints.admin.contacts.byId(contact.contact_id), {
    is_read: newRead,
  })
  busyId.value = null
  if (result) {
    await fetchContacts()
  }
}

function toggleShowRead() {
  showRead.value = !showRead.value
  void fetchContacts()
}

onMounted(fetchContacts)
</script>

<template>
  <AdminListPage
    title="Contact Submissions"
    subtitle="Review messages submitted through the contact form."
  >
    <template #actions>
      <button
        type="button"
        class="button"
        :class="showRead ? 'is-link' : ''"
        @click="toggleShowRead"
      >
        <span class="icon"
          ><FontAwesomeIcon
            :icon="showRead ? byPrefixAndName.fas['eye'] : byPrefixAndName.fas['eye-slash']"
        /></span>
        <span>{{ showRead ? 'Showing Read' : 'Show Read' }}</span>
      </button>
    </template>

    <AdminSearchField v-model="search" placeholder="Search by name, email, or message..." />

    <div class="settings-panel">
      <div class="table-container">
        <table class="table is-fullwidth is-hoverable is-striped">
          <thead>
            <tr>
              <SortableTh
                label="ID"
                sort-key="contact_id"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Date"
                sort-key="submitted_at"
                :active-key="sortKey"
                :dir="sortDir"
                @sort="toggleSort"
              />
              <SortableTh
                label="Name"
                sort-key="name"
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
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="contact in visibleContacts"
              :key="contact.contact_id"
              :class="{ 'has-text-grey': contact.is_read }"
            >
              <td>{{ contact.contact_id }}</td>
              <td>{{ formatDateTime(contact.submitted_at) }}</td>
              <td>{{ contact.name }}</td>
              <td>
                <a :href="'mailto:' + contact.email">{{ contact.email }}</a>
              </td>
              <td>
                <div class="row-actions">
                  <IconButton
                    :icon="byPrefixAndName.fas['eye']"
                    label="View"
                    @click="viewingContact = contact"
                  />
                  <IconButton
                    :icon="
                      contact.is_read
                        ? byPrefixAndName.fas['envelope']
                        : byPrefixAndName.fas['envelope-open']
                    "
                    :label="contact.is_read ? 'Mark Unread' : 'Mark Read'"
                    :intent="contact.is_read ? 'warning' : 'success'"
                    :disabled="busyId === contact.contact_id"
                    @click="toggleRead(contact)"
                  />
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <p v-if="contacts.length === 0" class="has-text-grey">
      No contact submissions{{ showRead ? '' : ' (unread)' }}.
    </p>
  </AdminListPage>

  <BaseModal :active="modalActive" title="Message" max-width="40rem" @close="viewingContact = null">
    <!-- User-submitted message; sanitized by renderMarkdown() (marked + DOMPurify) -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div class="content" v-html="modalHtml"></div>
  </BaseModal>
</template>
