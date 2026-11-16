/**
 * The Content-Security-Policy nonce the server gave this page, for a `<style>` element
 * a library adds at run time: the page's policy admits no other inline style.
 *
 * The built shell's `<meta property="csp-nonce">` carries it, filled in per response
 * by og.php. The browser hides a nonce attribute's value once the page has loaded
 * under a policy, so it is read from the `nonce` property, with the attribute as a
 * fallback where the property is missing (jsdom). Undefined where the page has none.
 *
 * @returns the nonce, or undefined
 */
export function cspNonce(): string | undefined {
  const meta = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')
  return meta?.nonce || meta?.getAttribute('nonce') || undefined
}
