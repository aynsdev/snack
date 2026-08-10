---
name: snack-mail-setup
description: "Snack's mail: Resend as the transport, and hand-branded published templates whose palette and logo PNG have no automatic link back to the app they mirror."
metadata: 
  node_type: memory
  type: project
  originSessionId: 564eb011-0fdb-424e-b9db-1a2c65bf375f
  modified: 2026-08-03T00:51:33.248Z
---

Set up 2026-08-03. Before this the whole mail layer existed but ran on `MAIL_MAILER=log`.

## Transport

`resend/resend-php` + `MAIL_MAILER=resend`. The transport class ships with the framework (`Illuminate\Mail\Transport\ResendTransport`); the package only supplies the client, so `resend/resend-laravel` is not needed. `config/mail.php` and `config/services.php` already had the `resend` entries from stock scaffolding — no config edit was required.

**A blank `RESEND_API_KEY` throws a raw `TypeError` from inside `MailManager`.** Every notification here is `ShouldQueue` against redis, so that surfaces as failed jobs on a worker, never as a red request — the same silence as [[snack-local-serving]]. Local `.env` is on `resend` with the key left blank for the user to paste.

## Templates

Published to `resources/views/vendor/mail` and branded by hand. Three things have **no automatic link** to the app they mirror, so each needs updating by hand:

- `html/themes/default.css` hardcodes the `original` colour theme from `resources/css/app.css`, flattened out of oklch (mail clients support neither oklch nor custom properties). Kept as `default` rather than a named `snack` theme so anything rendering without the config theme still comes out branded.
- `public/snack-mark.png` is `public/snack-icon.svg` rasterised at 2x. Gmail strips inline `<svg>` and blocks `data:` URIs, so the logo has to be a hosted PNG. Regenerate with headless Chrome (no rsvg/ImageMagick on this machine) — the SVG must be wrapped in a sized HTML page first or it renders at native 512px and crops.
- `asset('snack-mark.png')` resolves against `APP_URL`, so **the logo is only reachable once APP_URL is public**. On `snack.test` a real client cannot fetch it; the wordmark beside it is live text so the header degrades to type rather than to nothing.

The brand orange is **`#f97316`**, which is what `public/snack-icon.svg`, the theme-picker swatch, and the app.css comment all say. Note the `--primary` oklch actually computes to `#ff6900` — the documented hex is a stale Tailwind v3 value, and the assets win because the logo sits next to the button.

Button labels are `#171717` on the orange, not the app's white `--primary-foreground`: white is 2.89:1 and fails AA, `#171717` is 6.40:1.

## What to apply

- Rendering a `MailMessage` for assertions: `render()` returns an **`HtmlString`**, and `expect()` reads that as an object — `toContain` fails with "Expected [iterable]". Cast to `(string)` or declare a `: string` return type on the helper.
- `tests/Feature/Notifications/MailTemplateTest.php` exists because a stray `vendor:publish` would silently reset the templates and **break no other test** — every mail would still send, still say the right thing, and go out wearing Laravel's branding.
