/**
 * What a big delete's refusal says under its password field (ConfirmsPassword on the server): a wrong password (422)
 * or too many of them (429, "Too many wrong passwords. Try again in … seconds.") — or null for any other answer, which
 * is the page's to say. The limit belongs under the field it is about, never in a toast that goes away (owner,
 * 2026-10-07: the stress round found it said once, briefly, and the field emptied).
 */
export function passwordRefusal(error, field = 'password') {
    const status = error.response?.status;

    return status === 422 || status === 429 ? error.response.data?.errors?.[field]?.[0] ?? null : null;
}
