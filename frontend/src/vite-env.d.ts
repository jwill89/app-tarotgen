/// <reference types="vite/client" />

// A script file (no top-level import/export): wildcard module declarations only
// work here. Augmentations of real modules belong in a module file instead, as
// in global-components.d.ts: an augmentation here would replace the module's
// own declaration.
declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

// CHANGELOG.md, parsed at build time by config/changelog-plugin.ts.
declare module 'virtual:changelog' {
  import type { ChangelogRelease } from '@/utils/changelog'
  /** Every release in CHANGELOG.md, newest first; [Unreleased] only when it lists something. */
  export const releases: ChangelogRelease[]
}
