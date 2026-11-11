// Globally-registered FontAwesome components (see main.ts) so templates can use
// <FontAwesomeIcon> / <FontAwesomeLayers> with full type-checking.
//
// The top-level import makes this file a module, so `declare module 'vue'` below
// *adds* GlobalComponents to Vue's types. Without it (in vite-env.d.ts, say)
// TypeScript reads the block as the whole declaration of 'vue', and every
// `import { ref } from 'vue'` stops type-checking.
import type { FontAwesomeIcon, FontAwesomeLayers } from '@fortawesome/vue-fontawesome'

declare module 'vue' {
  export interface GlobalComponents {
    FontAwesomeIcon: typeof FontAwesomeIcon
    FontAwesomeLayers: typeof FontAwesomeLayers
  }
}
