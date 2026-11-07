<script setup lang="ts">
/**
 * A fixed, behind-everything field of stars. Each star gets a randomised
 * position, size, brightness and — crucially — its own animation duration and
 * (negative) delay, so they twinkle independently rather than pulsing in unison.
 * Generated once on mount; sits at z-index:-1 over the page's nebula gradient.
 */

interface Star {
  top: string
  left: string
  size: string
  opacity: number
  duration: string
  delay: string
  color: string
  twinkles: boolean
}

const COUNT = 140

// Neutral tints for the charcoal reskin: white, warm pale-gold, cool pale-silver
// (no blue — the old '#cfe0ff' read as a colour cast against the violet scheme).
const TINTS = ['#ffffff', '#ffffff', '#ffffff', '#ffe9c7', '#e2e6ef']

const rand = (min: number, max: number) => Math.random() * (max - min) + min

const stars: Star[] = Array.from({ length: COUNT }, () => {
  const size = rand(0.7, 2.6)
  return {
    top: rand(0, 100).toFixed(2) + '%',
    left: rand(0, 100).toFixed(2) + '%',
    size: size.toFixed(2) + 'px',
    opacity: +rand(0.35, 0.95).toFixed(2),
    // Independent timing is what makes it read as twinkling, not pulsing.
    duration: rand(2.2, 6.5).toFixed(2) + 's',
    delay: (-rand(0, 8)).toFixed(2) + 's',
    color: TINTS[Math.floor(Math.random() * TINTS.length)] ?? '#ffffff',
    // A quarter sit still so the sky isn't uniformly busy.
    twinkles: Math.random() > 0.25,
  }
})
</script>

<template>
  <div class="starfield" aria-hidden="true">
    <span
      v-for="(s, i) in stars"
      :key="i"
      class="star"
      :class="{ 'is-twinkling': s.twinkles }"
      :style="{
        top: s.top,
        left: s.left,
        width: s.size,
        height: s.size,
        background: s.color,
        '--star-opacity': s.opacity,
        animationDuration: s.duration,
        animationDelay: s.delay,
      }"
    />
  </div>
</template>

<style scoped>
.starfield {
  position: fixed;
  inset: 0;
  z-index: -1;
  pointer-events: none;
  overflow: hidden;
}

.star {
  position: absolute;
  border-radius: 50%;
  opacity: var(--star-opacity);
  box-shadow: 0 0 3px rgb(255 255 255 / 45%);
}

.star.is-twinkling {
  animation-name: star-twinkle;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
  will-change: opacity, transform;
}

@keyframes star-twinkle {
  0%,
  100% {
    opacity: calc(var(--star-opacity) * 0.2);
    transform: scale(0.6);
  }

  50% {
    opacity: var(--star-opacity);
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .star.is-twinkling {
    animation: none;
  }
}
</style>
