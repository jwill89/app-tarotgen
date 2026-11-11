<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { watch, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import TurndownService from 'turndown'
import { marked } from 'marked'
import Tooltip from '@/components/Tooltip.vue'

const props = withDefaults(
  defineProps<{
    modelValue: string
    placeholder?: string
    /** id of the visible caption that names the editor (aria-labelledby). */
    labelledby?: string
  }>(),
  {
    placeholder: 'Write your notes…',
    labelledby: undefined,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const turndown = new TurndownService({
  headingStyle: 'atx',
  bulletListMarker: '-',
  emDelimiter: '_',
})

/** Convert markdown string → HTML for Tiptap to consume */
function mdToHtml(md: string): string {
  if (!md) {
    return ''
  }
  return marked.parse(md, { async: false })
}

/** Convert Tiptap HTML → markdown string for the model */
function htmlToMd(html: string): string {
  if (!html || html === '<p></p>') {
    return ''
  }
  return turndown.turndown(html)
}

const editor = useEditor({
  content: mdToHtml(props.modelValue),
  // The editable region is a plain contenteditable: say what it is, and name it.
  editorProps: {
    attributes: {
      role: 'textbox',
      'aria-multiline': 'true',
      ...(props.labelledby ? { 'aria-labelledby': props.labelledby } : {}),
    },
  },
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3] },
    }),
    Link.configure({
      openOnClick: false,
      HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
    }),
    Placeholder.configure({
      placeholder: props.placeholder,
    }),
  ],
  onUpdate({ editor }) {
    const md = htmlToMd(editor.getHTML())
    emit('update:modelValue', md)
  },
})

// Sync external model changes into the editor (e.g. form reset)
watch(
  () => props.modelValue,
  (newVal) => {
    if (!editor.value) {
      return
    }
    const currentMd = htmlToMd(editor.value.getHTML())
    if (newVal !== currentMd) {
      // TipTap 3 takes an options object here; a bare `false` (the v2 form) is
      // ignored, so syncing the prop would echo an update back out.
      editor.value.commands.setContent(mdToHtml(newVal), { emitUpdate: false })
    }
  },
)

onBeforeUnmount(() => {
  editor.value?.destroy()
})

function setLink() {
  const url = window.prompt('URL')
  if (!url) {
    editor.value?.chain().focus().unsetLink().run()
    return
  }
  editor.value?.chain().focus().setLink({ href: url }).run()
}
</script>

<template>
  <div v-if="editor" class="wysiwyg-editor box">
    <div class="wysiwyg-toolbar">
      <div class="buttons has-addons are-small mb-0">
        <Tooltip text="Bold">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Bold"
            :class="{ 'is-active': editor.isActive('bold') }"
            @click="editor.chain().focus().toggleBold().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['bold']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Italic">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Italic"
            :class="{ 'is-active': editor.isActive('italic') }"
            @click="editor.chain().focus().toggleItalic().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['italic']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Heading">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Heading"
            :class="{ 'is-active': editor.isActive('heading', { level: 2 }) }"
            @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['heading']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Quote">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Quote"
            :class="{ 'is-active': editor.isActive('blockquote') }"
            @click="editor.chain().focus().toggleBlockquote().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['quote-left']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Bulleted list">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Bulleted list"
            :class="{ 'is-active': editor.isActive('bulletList') }"
            @click="editor.chain().focus().toggleBulletList().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['list-ul']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Numbered list">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Numbered list"
            :class="{ 'is-active': editor.isActive('orderedList') }"
            @click="editor.chain().focus().toggleOrderedList().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['list-ol']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Inline code">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Inline code"
            :class="{ 'is-active': editor.isActive('code') }"
            @click="editor.chain().focus().toggleCode().run()"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['code']"
            /></span>
          </button>
        </Tooltip>
        <Tooltip text="Link">
          <button
            type="button"
            class="button is-small"
            tabindex="-1"
            aria-label="Link"
            :class="{ 'is-active': editor.isActive('link') }"
            @click="setLink"
          >
            <span class="icon is-small"
              ><FontAwesomeIcon :icon="byPrefixAndName.fas['link']"
            /></span>
          </button>
        </Tooltip>
      </div>
    </div>

    <EditorContent :editor="editor" class="wysiwyg-content" />
  </div>
</template>

<style scoped>
.wysiwyg-editor {
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--myst-border-strong);
}

.wysiwyg-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.5rem;
  background: var(--myst-surface-3);
  border-bottom: 1px solid var(--myst-border);
}

.wysiwyg-toolbar .button.is-active {
  background-color: var(--bulma-link);
  border-color: transparent;
  color: rgb(255 255 255);
}

.wysiwyg-content {
  padding: 0.75rem 1rem;
  min-height: 10rem;
}

/* Tiptap editor area styling */
.wysiwyg-content :deep(.tiptap) {
  outline: none;
  min-height: 8rem;
  color: inherit;
  font-size: inherit;
  line-height: 1.6;
}

.wysiwyg-content :deep(.tiptap p) {
  margin-bottom: 0.75em;
}

.wysiwyg-content :deep(.tiptap h2) {
  font-size: 1.4em;
  font-weight: 700;
  margin-bottom: 0.5em;
}

.wysiwyg-content :deep(.tiptap h3) {
  font-size: 1.2em;
  font-weight: 600;
  margin-bottom: 0.5em;
}

.wysiwyg-content :deep(.tiptap ul),
.wysiwyg-content :deep(.tiptap ol) {
  padding-left: 1.5em;
  margin-bottom: 0.75em;
}

.wysiwyg-content :deep(.tiptap blockquote) {
  border-left: 3px solid var(--myst-border-strong);
  padding-left: 1em;
  margin-left: 0;
  margin-bottom: 0.75em;
  opacity: 0.85;
}

.wysiwyg-content :deep(.tiptap code) {
  background: rgb(255 255 255 / 8%);
  border-radius: 3px;
  padding: 0.15em 0.35em;
  font-size: 0.9em;
}

.wysiwyg-content :deep(.tiptap a) {
  color: var(--bulma-link);
  text-decoration: underline;
}

/* Placeholder */
.wysiwyg-content :deep(.tiptap p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  float: left;
  color: var(--myst-text-dim);
  pointer-events: none;
  height: 0;
}
</style>
