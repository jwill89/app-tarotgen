<script setup lang="ts">
/**
 * Consistent page header: a display-serif title, an optional subtitle, and
 * the signature gold hairline rule (echoing the home hero). Gives every view
 * the same elevated "page start" as the home page.
 *
 *   <PageHeader title="New Draw" subtitle="Choose the settings for your draw." />
 *
 * A default slot may be used for actions rendered to the right of the title.
 */
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <header class="page-header">
    <div class="page-header__row">
      <h1 class="page-header__title">{{ title }}</h1>
      <div v-if="$slots.default" class="page-header__actions"><slot /></div>
    </div>
    <p v-if="subtitle" class="page-header__sub">{{ subtitle }}</p>
    <div class="page-header__rule" aria-hidden="true"></div>
  </header>
</template>

<style scoped>
.page-header {
  margin-bottom: 1.75rem;
}

.page-header__row {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.page-header__title {
  font-family: var(--myst-heading-font);
  letter-spacing: 0.02em;
  color: var(--myst-text-strong);
  font-size: clamp(1.8rem, 4vw, 2.4rem);
  line-height: 1.1;
  margin: 0;
}

.page-header__actions {
  display: flex;

  /* Several actions (admin Spreads has three) wrap on a phone instead of widening the page. */
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.page-header__sub {
  color: var(--myst-text-muted);
  font-size: 1.05rem;
  margin: 0.4rem 0 0;
  max-width: 62ch;
}

.page-header__rule {
  margin-top: 0.9rem;
  height: 2px;
  width: min(420px, 100%);
  border-radius: 2px;
  background: linear-gradient(
    90deg,
    var(--myst-gold) 0%,
    var(--myst-gold) 30%,
    color-mix(in srgb, var(--myst-gold) 50%, transparent) 62%,
    transparent 100%
  );
}
</style>
