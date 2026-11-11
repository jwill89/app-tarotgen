import { endpoints } from '@/api/endpoints'
import { apiRequest, sendJson } from './apiClient'

export interface ContactMessage {
  name: string
  email: string
  message: string
}

export type ContactResult = { ok: true } | { ok: false; error: string }

const RATE_LIMITED = 'You have submitted too many messages recently. Please try again later.'
const SEND_FAILED = 'Failed to send your message. Please try again.'

/**
 * Sending the contact form. Kept out of ContactView so the tests exercise the
 * real request and response handling, not a copy of it. Every
 * failure — server message, rate limit, network — comes back as a user-ready
 * `error` string; this never throws.
 *
 * `turnstileToken` is the solved Turnstile challenge, which the server requires
 * whenever Turnstile is configured (empty otherwise), as sign-in does.
 */
export function useContact() {
  async function sendMessage(msg: ContactMessage, turnstileToken = ''): Promise<ContactResult> {
    const res = await apiRequest(
      endpoints.contacts,
      sendJson('POST', {
        name: msg.name.trim(),
        email: msg.email.trim(),
        message: msg.message.trim(),
        turnstile_token: turnstileToken,
      }),
      SEND_FAILED,
    )

    if (res.ok) {
      return { ok: true }
    }
    return { ok: false, error: res.status === 429 ? RATE_LIMITED : res.error }
  }

  return { sendMessage }
}
