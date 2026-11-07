<script setup lang="ts">
/**
 * The "what's wrong with this card?" form behind the lightbox's report button.
 * A reason tells the admins what kind of fix the card needs (a re-scan, a
 * re-crop, swapping the image); the optional details tell them where to look,
 * and are required for "Something else", which says nothing on its own.
 * Stacks over the lightbox it is opened from.
 */
import { computed, ref } from 'vue'
import { RadioGroupRoot, RadioGroupItem, RadioGroupIndicator } from 'reka-ui'
import BaseModal from '@/components/BaseModal.vue'
import { useCardReports } from '@/composables/useCardReports'
import { useToasts } from '@/composables/useToasts'
import { REPORT_REASONS, REPORT_DETAILS_MAX_LENGTH, isReportReason } from '@/utils/cardReports'
import type { CardReportReason, ReadingCard } from '@/types'

const props = defineProps<{
  deckId: number
  card: ReadingCard
}>()

const emit = defineEmits<{ close: [] }>()

const { submitCardReport } = useCardReports()
const { success: toastSuccess, info: toastInfo } = useToasts()

const reason = ref<CardReportReason | null>(null)
const details = ref('')
const submitting = ref(false)
const error = ref('')
const imgFailed = ref(false)

const detailsRequired = computed(() => reason.value === 'other')
const canSubmit = computed(
  () =>
    reason.value !== null &&
    (!detailsRequired.value || details.value.trim() !== '') &&
    !submitting.value,
)
const detailsPlaceholder = computed(
  () =>
    REPORT_REASONS.find((r) => r.value === reason.value)?.placeholder ??
    'Anything that helps us find the problem',
)

// Reka's RadioGroup emits an untyped value; only accept a reason we know.
function onReasonChange(value: unknown): void {
  if (isReportReason(value)) {
    reason.value = value
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value || reason.value === null) {
    return
  }

  submitting.value = true
  error.value = ''
  const outcome = await submitCardReport({
    deckId: props.deckId,
    cardId: props.card.card_id,
    cardName: props.card.card_name,
    reason: reason.value,
    details: details.value,
  })
  submitting.value = false

  if (!outcome.ok) {
    error.value = outcome.error
    return
  }

  if (outcome.alreadyReported) {
    toastInfo("You've already reported this card recently. Thanks!")
  } else {
    toastSuccess('Thanks! Your report has been sent.')
  }
  emit('close')
}
</script>

<template>
  <!-- Once something is typed, a stray backdrop click shouldn't throw it away. -->
  <BaseModal
    active
    title="Report a problem"
    max-width="32rem"
    over-lightbox
    :close-on-backdrop="details.trim() === ''"
    @close="emit('close')"
  >
    <form id="card-report-form" class="report-form" @submit.prevent="submit">
      <div class="report-card">
        <!-- A missing image may be the very thing being reported; drop the frame then. -->
        <img
          v-if="!imgFailed"
          :src="card.thumbUrl"
          alt=""
          class="report-card__img"
          @error="imgFailed = true"
        />
        <div>
          <p class="report-card__eyebrow">Reporting</p>
          <p class="report-card__name">{{ card.card_name }}</p>
        </div>
      </div>

      <div class="field">
        <p id="card-report-reason-label" class="label">What's wrong?</p>
        <RadioGroupRoot
          :model-value="reason"
          class="report-reasons"
          aria-labelledby="card-report-reason-label"
          required
          @update:model-value="onReasonChange"
        >
          <!-- Named by the label alone, with the hint as its description, so a
               screen reader doesn't read both run together as one long name. -->
          <RadioGroupItem
            v-for="opt in REPORT_REASONS"
            :key="opt.value"
            :value="opt.value"
            class="report-reason"
            :aria-labelledby="`card-report-reason-${opt.value}`"
            :aria-describedby="`card-report-reason-${opt.value}-hint`"
          >
            <span class="report-reason__dot" aria-hidden="true">
              <RadioGroupIndicator class="report-reason__dot-fill" />
            </span>
            <span class="report-reason__text">
              <span :id="`card-report-reason-${opt.value}`" class="report-reason__label">{{
                opt.label
              }}</span>
              <span :id="`card-report-reason-${opt.value}-hint`" class="report-reason__hint">{{
                opt.hint
              }}</span>
            </span>
          </RadioGroupItem>
        </RadioGroupRoot>
      </div>

      <div class="field">
        <div class="report-form__details-head">
          <label class="label" for="card-report-details">
            Details
            <span class="report-form__optional">{{
              detailsRequired ? '(required)' : '(optional)'
            }}</span>
          </label>
          <span v-if="details.length" class="report-form__count">
            {{ details.length }} / {{ REPORT_DETAILS_MAX_LENGTH }}
          </span>
        </div>
        <textarea
          id="card-report-details"
          v-model="details"
          class="textarea"
          rows="3"
          :maxlength="REPORT_DETAILS_MAX_LENGTH"
          :placeholder="detailsPlaceholder"
          :required="detailsRequired"
        ></textarea>
      </div>

      <p v-if="error" class="help is-danger" role="alert">{{ error }}</p>
    </form>

    <template #footer>
      <button type="button" class="button" @click="emit('close')">Cancel</button>
      <button
        type="submit"
        form="card-report-form"
        class="button is-primary"
        :class="{ 'is-loading': submitting }"
        :disabled="!canSubmit"
      >
        Send report
      </button>
    </template>
  </BaseModal>
</template>

<style scoped>
.report-card {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  margin-bottom: 1rem;
}

.report-card__img {
  flex: none;
  height: 3.25rem;
  width: auto;
  border-radius: 4px;
  border: 1px solid var(--myst-border-strong);
}

.report-card__eyebrow {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--myst-text-dim);
}

.report-card__name {
  font-weight: 600;
  color: var(--myst-text-strong);
}

.report-reasons {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.report-reason {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  width: 100%;
  padding: 0.5rem 0.8rem;
  border: 1px solid var(--myst-border);
  border-radius: 10px;
  background: transparent;
  color: var(--myst-text);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}

.report-reason:hover {
  background: var(--myst-surface-2);
}

.report-reason:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--myst-gold) 25%, transparent);
}

.report-reason[data-state='checked'] {
  border-color: var(--myst-hair-gold);
  background: color-mix(in srgb, var(--myst-gold) 7%, transparent);
}

/* Optically centred on the label's first line. */
.report-reason__dot {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1rem;
  height: 1rem;
  margin-top: 0.2rem;
  border: 1.5px solid var(--myst-text-dim);
  border-radius: 50%;
  transition: border-color 0.15s ease;
}

.report-reason[data-state='checked'] .report-reason__dot {
  border-color: var(--myst-gold);
}

.report-reason__dot-fill {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: var(--myst-gold);
}

.report-reason__text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.report-reason__label {
  font-weight: 600;
  line-height: 1.4;
}

.report-reason__hint {
  font-size: 0.82rem;
  line-height: 1.35;
  color: var(--myst-text-muted);
}

.report-form__optional {
  font-weight: 400;
  color: var(--myst-text-dim);
}

/* Label left, character count right, on one line. */
.report-form__details-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.5em;
}

.report-form__details-head .label {
  margin-bottom: 0;
}

.report-form__count {
  font-size: 0.82rem;
  font-variant-numeric: tabular-nums;
  color: var(--myst-text-dim);
}
</style>
