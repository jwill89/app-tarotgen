<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-8-desktop is-10-tablet">
          <PageHeader title="Privacy Policy" subtitle="Last updated: November 6, 2026" />

          <div class="settings-panel">
            <div class="content">
              <h2 class="title is-4">1. Introduction</h2>
              <p>
                Welcome to our tarot reading platform. We respect your privacy and are committed to
                protecting your personal data. This Privacy Policy explains how we collect, use, and
                safeguard your information when you use our service.
              </p>

              <h2 class="title is-4">2. Information We Collect</h2>
              <h3 class="title is-5">Account Registration</h3>
              <p>When you create an account, we collect:</p>
              <ul>
                <li>
                  <strong>Email address</strong> — used solely for account identification, login,
                  and essential account-related communications (e.g., password resets, account
                  activation).
                </li>
              </ul>

              <h3 class="title is-5">Google Sign-In</h3>
              <p>If you choose to register or log in with Google, we receive and store:</p>
              <ul>
                <li><strong>Email address</strong> — used for account identification.</li>
                <li><strong>Display name</strong> — used as your default username.</li>
              </ul>
              <p>We do not access any other Google account data, contacts, files, or activity.</p>

              <h3 class="title is-5">Readings &amp; Usage Data</h3>
              <p>
                If you are logged in, your tarot readings and any notes you add are stored in your
                account so you can access them later. We do not share your readings with anyone.
              </p>

              <h3 class="title is-5">FFXIV Plugin</h3>
              <p>
                If you use the FFXIV plugin without linking an account, it gets a draw key from this
                site once, which lets it draw readings. We keep only a fingerprint of the key, not
                the key itself, with when it was issued and last used, to limit how fast it can
                draw. The key is not linked to you, an account or your character.
              </p>

              <h2 class="title is-4">3. How We Use Your Information</h2>
              <p>We use the information we collect exclusively to:</p>
              <ul>
                <li>Create and manage your account.</li>
                <li>Authenticate you when you log in.</li>
                <li>Send essential account-related emails (activation, password reset).</li>
                <li>Store and display your saved readings and notes.</li>
              </ul>
              <p>
                We do <strong>not</strong> use your data for advertising, analytics profiling, or
                marketing emails. We will never sell or rent your personal information to third
                parties.
              </p>

              <h2 class="title is-4">4. Data Storage &amp; Security</h2>
              <p>
                Your data is stored securely on our servers. Passwords are hashed using
                industry-standard algorithms and are never stored in plain text. We implement
                reasonable technical and organizational measures to protect your personal data
                against unauthorized access, alteration, or destruction.
              </p>

              <h2 class="title is-4">5. Data Sharing</h2>
              <p>
                We do not share your personal information with any third parties, except where
                required by law, and we use no advertising services.
              </p>
              <p>
                The site is delivered through Cloudflare. We use Cloudflare Web Analytics to see, in
                aggregate, how many pages are viewed and how quickly they load. Cloudflare states
                that it does not collect or use visitors' personal data, and it stores nothing in
                your browser — no cookies, no local storage. Cloudflare also runs a short script on
                each page to tell real visitors from automated traffic.
              </p>
              <p>
                A few forms ask you to pass a Cloudflare Turnstile check, which shows that a person,
                not a bot, is sending them: signing in, the contact form, drawing a reading while
                signed out, and getting a draw key for the FFXIV plugin. Turnstile looks at signals
                such as your IP address and browser to tell people from bots, solely to stop bots.
                When you send the form, we pass the check's result and your IP address back to
                Cloudflare to confirm it, and we keep nothing from the check. Cloudflare's
                <a
                  href="https://www.cloudflare.com/turnstile-privacy-policy/"
                  target="_blank"
                  rel="noopener"
                  >Turnstile Privacy Addendum</a
                >
                describes what it collects.
              </p>

              <h2 class="title is-4">6. Cookies</h2>
              <p>
                The only cookie we use is an essential session cookie, which keeps you signed in.
                Your browser's local storage remembers a few conveniences on this device, such as
                readings you viewed recently and the deck you last used. We do not use tracking
                cookies or third-party cookies.
              </p>

              <h2 class="title is-4">7. Your Rights</h2>
              <p>You have the right to:</p>
              <ul>
                <li>Access the personal data we hold about you.</li>
                <li>Request correction of inaccurate data.</li>
                <li>Request deletion of your account and associated data.</li>
                <li>Withdraw consent for Google sign-in at any time by unlinking your account.</li>
              </ul>
              <p>
                To exercise any of these rights, please contact us through our
                <router-link :to="{ name: 'contact' }">contact form</router-link>.
              </p>

              <h2 class="title is-4">8. Data Retention</h2>
              <p>
                We retain your account data, and the readings saved to your account, for as long as
                your account is active; you can delete any of those readings at any time. If you
                delete your account, all associated personal data and readings will be permanently
                removed from our systems within 30 days.
              </p>
              <p>
                Readings made while you are signed in belong to your account and are never deleted
                by this rule. Readings made without signing in — including any you make while signed
                out — are not linked to anyone, so they are kept for
                <strong>one year</strong> from the day they were created. After that they are
                permanently deleted and their share links stop working.
              </p>
              <p>
                When you make a reading without signing in, you can choose to have it deleted
                sooner: after 1, 7 or 30 days. From that moment nobody can open it, and it is
                permanently deleted within a day.
              </p>
              <p>
                Deleted data can remain in our database backups for up to 28 days, after which the
                backups that contain it are deleted.
              </p>

              <h2 class="title is-4">9. Children's Privacy</h2>
              <p>
                Our service is not directed to children under the age of 13. We do not knowingly
                collect personal information from children. If you believe a child has provided us
                with personal data, please contact us so we can remove it.
              </p>

              <h2 class="title is-4">10. Changes to This Policy</h2>
              <p>
                We may update this Privacy Policy from time to time. Any changes will be posted on
                this page with an updated revision date. Continued use of the service after changes
                constitutes acceptance of the revised policy.
              </p>

              <h2 class="title is-4">11. Contact Us</h2>
              <p>
                If you have any questions about this Privacy Policy, please reach out via our
                <router-link :to="{ name: 'contact' }">contact form</router-link>.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
