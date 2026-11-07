<script setup lang="ts">
/**
 * CHANGELOG.md in a dialog, opened from the admin dashboard's version tag. The
 * notes are built in (`virtual:changelog`, config/changelog-plugin.ts), so they
 * are this build's own and need no API call. Each release folds; the newest
 * starts open. Open while mounted: the parent mounts it to show it.
 */
import { ref } from 'vue'
import {
  AccordionRoot,
  AccordionItem,
  AccordionHeader,
  AccordionTrigger,
  AccordionContent,
} from 'reka-ui'
import { releases } from 'virtual:changelog'
import { byPrefixAndName } from '@/fontawesome'
import BaseModal from '@/components/BaseModal.vue'
import { renderMarkdown } from '@/utils/markdown'
import type { ChangelogRelease } from '@/utils/changelog'

const emit = defineEmits<{ close: [] }>()

/** What each release folds under: its version, or 'unreleased'. */
function key(release: ChangelogRelease): string {
  return release.version ?? 'unreleased'
}

const newest = releases.at(0)
const open = ref<string[]>(newest ? [key(newest)] : [])
</script>

<template>
  <BaseModal active title="Changelog" max-width="46rem" @close="emit('close')">
    <AccordionRoot v-if="releases.length" v-model="open" type="multiple">
      <AccordionItem
        v-for="release in releases"
        :key="key(release)"
        :value="key(release)"
        class="changelog-release"
      >
        <AccordionHeader class="changelog-heading">
          <AccordionTrigger class="changelog-trigger">
            <span class="changelog-version">{{
              release.version === null ? 'Unreleased' : `v${release.version}`
            }}</span>
            <span v-if="release.date" class="changelog-date"
              ><span class="visually-hidden">, released </span>{{ release.date }}</span
            >
            <span class="icon changelog-chevron" aria-hidden="true">
              <FontAwesomeIcon :icon="byPrefixAndName.fas['chevron-down']" />
            </span>
          </AccordionTrigger>
        </AccordionHeader>
        <AccordionContent class="collapsible-anim">
          <!-- CHANGELOG.md, sanitized by renderMarkdown() (marked + DOMPurify) — see utils/markdown.ts -->
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div class="content changelog-notes" v-html="renderMarkdown(release.body)"></div>
        </AccordionContent>
      </AccordionItem>
    </AccordionRoot>
    <p v-else class="has-text-grey">No releases yet.</p>
  </BaseModal>
</template>

<style scoped>
.changelog-release {
  border-bottom: 1px solid var(--myst-border);
}

.changelog-release:last-child {
  border-bottom: none;
}

.changelog-heading {
  margin: 0;
}

.changelog-trigger {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  width: 100%;
  padding: 0.8rem 0.25rem;
  background: none;
  border: 0;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.changelog-trigger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
  border-radius: 6px;
}

.changelog-version {
  font-family: var(--myst-heading-font);
  font-size: 1.1rem;
  letter-spacing: 0.02em;
  color: var(--myst-gold);
}

.changelog-trigger:hover .changelog-version {
  color: var(--myst-gold-bright);
}

.changelog-date {
  color: var(--myst-text-dim);
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
}

.changelog-chevron {
  margin-left: auto;
  align-self: center;
  color: var(--myst-text-muted);
  font-size: 0.8rem;
  transition: transform 0.2s ease;
}

.changelog-trigger[data-state='open'] .changelog-chevron {
  transform: rotate(180deg);
}

.changelog-notes {
  padding: 0 0.25rem 1.25rem;
  font-size: 0.92rem;
}

/* Added / Changed / Fixed …: a quiet label over each list. */
.changelog-notes :deep(h4) {
  margin: 1.25rem 0 0.5rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--myst-text-muted);
}

.changelog-notes :deep(h5) {
  margin: 1rem 0 0.4rem;
  font-size: 0.9rem;
  color: var(--myst-text-strong);
}

.changelog-notes :deep(blockquote) {
  background: var(--myst-surface-2);
  border-left: 3px solid var(--myst-gold);
  padding: 0.6rem 0.9rem;
}

.changelog-notes :deep(a) {
  color: var(--myst-gold);
}

@media (prefers-reduced-motion: reduce) {
  .changelog-chevron {
    transition: none;
  }
}
</style>
