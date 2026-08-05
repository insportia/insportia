# Meta Pixel + Conversions API — Setup checklist (Cloudflare Workers)

Your project (`insportia/insportia` on GitHub, connected via Workers &
Pages → Build → Git repository) runs on Cloudflare's newer **Workers +
Static Assets** platform, not classic Cloudflare Pages. The structure below
is built for that.

## Project structure
```
/
├── wrangler.toml       <- Worker config, points to ./public and ./src/index.js
├── package.json
├── src/
│   └── index.js        <- Worker code: serves static files + handles /api/track, /api/purchase
└── public/
    ├── index.html
    ├── about.html
    ├── contact.html
    ├── why-us.html
    ├── partners.html
    ├── privacy.html
    ├── terms.html
    ├── promo.html
    ├── favicon.svg / favicon-32.png / apple-touch-icon.png / icon-512.png
```

## 0. Rotate your Conversions API token first
The token pasted earlier in chat is compromised. Events Manager → your
Pixel → Settings → Conversions API → Manage Integrations → remove the old
token → Generate a new one. Use the NEW token in step 2.

## 1. Push this to your GitHub repo
Replace the contents of `insportia/insportia` (main branch) with everything
in this folder — keep the `wrangler.toml`, `package.json`, `src/`, and
`public/` structure exactly as-is. Since your Worker is already connected to
this repo with `Deploy command: npx wrangler deploy`, pushing to `main`
triggers an automatic build + deploy.

## 2. Set Variables and secrets on the Worker
Cloudflare dashboard → Workers & Pages → **insportia** → Settings →
**Variables and secrets** (this section becomes usable once `src/index.js`
exists, since the Worker is no longer "static assets only") → add:

| Name | Value | Type |
|---|---|---|
| `FB_PIXEL_ID` | `1732201808432666` | Text |
| `FB_CAPI_TOKEN` | your NEW Conversions API token from step 0 | **Secret** |
| `TRACK_SECRET` | any long random string you generate yourself | **Secret** |

Add these for the Production environment (and Preview too, if you use
preview branches).

## 3. Deploy
Push to `main` on GitHub. Cloudflare Workers Builds will run
`npx wrangler deploy` automatically. Check the **Deployments** tab in the
dashboard to confirm it succeeded.

## 4. What fires automatically (no extra work)
- `PageView` — every page load
- `Lead` — clicking "Join free channel" / "Join the free channel now"
- `InitiateCheckout` (value 19) — clicking "Get VIP"
- `InitiateCheckout` (value 29) — clicking "Get VIP+"
- `Contact` — clicking "Ask us a question"

Each fires from both the browser (Pixel) and the server (`/api/track` →
Conversions API) with a shared `event_id`, so Meta deduplicates them into
one event instead of double-counting.

## 5. Purchase tracking (Telegram-side, needs a bit more wiring)
Real payments happen inside Telegram, so Meta can't see them automatically.
To report a `Purchase` event when someone actually pays for VIP/VIP+:

1. Give your Telegram links a unique reference per visitor, e.g.
   `https://t.me/insportia?start=xyz123` instead of a bare link.
2. When your bot/manager confirms payment for that Telegram user, call:

```bash
curl -X POST https://insportia.org/api/purchase \
  -H "Content-Type: application/json" \
  -H "X-Track-Secret: <your TRACK_SECRET>" \
  -d '{
    "plan": "vip",
    "click_id": "xyz123",
    "external_id": "<telegram_user_id>"
  }'
```

This reports a real `Purchase` event (value $19 or $29 depending on `plan`)
to Meta — the strongest possible signal for ad optimization. Worth wiring up
once steps 1-4 are confirmed working.

## 6. Verify everything works
Events Manager → your Pixel → Test Events → open insportia.org with the
test code appended, click around (Join, Get VIP, Get VIP+, Ask us) and
confirm each event shows both a "Browser" and "Server" match (proves
deduplication is working, not double-counting).

If something doesn't fire, check: Cloudflare dashboard → your Worker →
**Observability** / **Logs** tab — any `console.error(...)` calls from
`src/index.js` will show up there in real time.
