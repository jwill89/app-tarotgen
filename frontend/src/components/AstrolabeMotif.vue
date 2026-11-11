<script setup lang="ts">
/**
 * Decorative astrolabe motif for the home hero. Pure line-art in `currentColor`
 * (the parent sets it gold); `aria-hidden` because it is ornamental only.
 *
 * The moon (top of the dial) is drawn as a disc + an elliptical *terminator* arc
 * — a clean "moon-phase" glyph rather than a fat lune, so it reads
 * unambiguously as a moon. The terminator path is marked `data-moon`: it is the
 * single element a dynamic-phase feature would rewrite (the arc's horizontal
 * radius maps to the phase) to match the real lunar phase from the date.
 */
</script>

<template>
  <svg
    class="astrolabe"
    viewBox="0 0 440 440"
    fill="none"
    stroke="currentColor"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <!-- rings + graduated bezel -->
    <g stroke-width="1">
      <circle cx="220" cy="220" r="212" opacity=".5" />
      <circle cx="220" cy="220" r="200" stroke-width="8" stroke-dasharray="1.4 7.4" opacity=".42" />
      <circle cx="220" cy="220" r="176" opacity=".62" />
      <circle cx="220" cy="220" r="150" stroke-dasharray="2 10" opacity=".4" />
      <circle cx="220" cy="220" r="120" opacity=".55" />
      <!-- cardinal ticks -->
      <g opacity=".7">
        <line x1="220" y1="8" x2="220" y2="44" />
        <line x1="220" y1="396" x2="220" y2="432" />
        <line x1="8" y1="220" x2="44" y2="220" />
        <line x1="396" y1="220" x2="432" y2="220" />
      </g>
      <!-- intercardinal ticks -->
      <g opacity=".38">
        <line x1="74" y1="74" x2="92" y2="92" />
        <line x1="366" y1="74" x2="348" y2="92" />
        <line x1="74" y1="366" x2="92" y2="348" />
        <line x1="366" y1="366" x2="348" y2="348" />
      </g>
    </g>

    <!-- alidade / rotating rule with sighting vanes -->
    <g stroke-width="1.2" opacity=".8" transform="rotate(-24 220 220)">
      <line x1="220" y1="46" x2="220" y2="394" />
      <circle cx="220" cy="46" r="5" />
      <circle cx="220" cy="394" r="5" />
      <path d="M212 300 H228" opacity=".55" />
      <path d="M214 148 H226" opacity=".55" />
    </g>

    <!-- central sun -->
    <g stroke-width="1.2">
      <circle cx="220" cy="220" r="15" />
      <g opacity=".9">
        <line x1="220" y1="197" x2="220" y2="204" />
        <line x1="220" y1="236" x2="220" y2="243" />
        <line x1="197" y1="220" x2="204" y2="220" />
        <line x1="236" y1="220" x2="243" y2="220" />
        <line x1="231" y1="209" x2="236" y2="204" />
        <line x1="209" y1="231" x2="204" y2="236" />
        <line x1="231" y1="231" x2="236" y2="236" />
        <line x1="209" y1="209" x2="204" y2="204" />
      </g>
    </g>

    <!-- moon: disc + terminator (data-moon = dynamic-phase hook) -->
    <g stroke-width="1.3">
      <circle cx="220" cy="70" r="18" />
      <path data-moon d="M220 52 A9 18 0 0 1 220 88" opacity=".95" />
    </g>

    <!-- sparse star sparkles -->
    <g opacity=".65" stroke-width="1">
      <path d="M150 300 v10 M145 305 h10" />
      <path d="M300 150 v8 M296 154 h8" />
    </g>
  </svg>
</template>

<style scoped>
.astrolabe {
  width: 100%;
  height: auto;
  display: block;
  color: var(--myst-gold);
}
</style>
