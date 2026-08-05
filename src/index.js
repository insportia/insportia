// Cloudflare Worker entry point for insportia.org
//
// Serves the static site from /public (via the ASSETS binding configured
// in wrangler.toml) and handles two custom API routes:
//   POST /api/track    - client-side click events -> Meta Conversions API
//   POST /api/purchase - server-triggered Purchase events (called from your
//                        own bot/backend, not from the browser)
//
// Required Variables & Secrets (set in the Cloudflare dashboard, under this
// Worker's Settings -> Variables and secrets):
//   FB_PIXEL_ID   - your Meta Pixel ID (plain variable)
//   FB_CAPI_TOKEN - your Conversions API access token (encrypt as Secret)
//   TRACK_SECRET  - a random string only you and your bot know (Secret)

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (request.method === 'POST' && url.pathname === '/api/track') {
      return handleTrack(request, env);
    }

    if (request.method === 'POST' && url.pathname === '/api/purchase') {
      return handlePurchase(request, env);
    }

    if (
      (url.pathname === '/api/track' || url.pathname === '/api/purchase') &&
      request.method !== 'POST'
    ) {
      return json({ ok: false, error: 'method_not_allowed' }, 405);
    }

    // Everything else: serve the static site.
    return env.ASSETS.fetch(request);
  },
};

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

async function handleTrack(request, env) {
  let body;
  try {
    body = await request.json();
  } catch (err) {
    return json({ ok: false, error: 'invalid_json' }, 400);
  }

  if (!body || !body.event_name || !body.event_id) {
    return json({ ok: false, error: 'missing_fields' }, 400);
  }

  if (!env.FB_PIXEL_ID || !env.FB_CAPI_TOKEN) {
    console.error('Missing FB_PIXEL_ID or FB_CAPI_TOKEN');
    return json({ ok: false, error: 'not_configured' }, 500);
  }

  const customData = {};
  if (body.value) {
    customData.currency = 'USD';
    customData.value = body.value;
  }
  if (body.link) {
    customData.link_clicked = body.link;
  }

  const payload = {
    data: [
      {
        event_name: body.event_name,
        event_id: body.event_id,
        event_time: Math.floor(Date.now() / 1000),
        action_source: 'website',
        event_source_url: body.source_url || '',
        user_data: {
          client_ip_address: request.headers.get('CF-Connecting-IP') || undefined,
          client_user_agent: request.headers.get('User-Agent') || undefined,
        },
        custom_data: customData,
      },
    ],
  };

  try {
    const fbRes = await fetch(
      `https://graph.facebook.com/v21.0/${env.FB_PIXEL_ID}/events?access_token=${env.FB_CAPI_TOKEN}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const fbJson = await fbRes.json().catch(() => ({}));
    if (!fbRes.ok) console.error('Meta CAPI error:', JSON.stringify(fbJson));
    return json({ ok: fbRes.ok }, fbRes.ok ? 200 : 502);
  } catch (err) {
    console.error('Meta CAPI request failed:', err.message);
    return json({ ok: false, error: 'capi_request_failed' }, 502);
  }
}

async function handlePurchase(request, env) {
  const secret = request.headers.get('X-Track-Secret');
  if (!env.TRACK_SECRET || secret !== env.TRACK_SECRET) {
    return json({ ok: false, error: 'unauthorized' }, 401);
  }

  let body;
  try {
    body = await request.json();
  } catch (err) {
    return json({ ok: false, error: 'invalid_json' }, 400);
  }

  if (!body || !body.plan) {
    return json({ ok: false, error: 'missing_plan' }, 400);
  }

  if (!env.FB_PIXEL_ID || !env.FB_CAPI_TOKEN) {
    console.error('Missing FB_PIXEL_ID or FB_CAPI_TOKEN');
    return json({ ok: false, error: 'not_configured' }, 500);
  }

  const value = body.plan === 'vip+' ? 29 : 19;

  const userData = {};
  if (body.fbc) userData.fbc = body.fbc;
  if (body.fbp) userData.fbp = body.fbp;
  if (body.external_id) userData.external_id = [String(body.external_id)];
  if (body.email) {
    userData.em = [await sha256Hex(body.email.trim().toLowerCase())];
  }

  const eventId = 'purchase_' + (body.click_id || Date.now()) + '_' + Date.now();

  const payload = {
    data: [
      {
        event_name: 'Purchase',
        event_id: eventId,
        event_time: Math.floor(Date.now() / 1000),
        action_source: 'system_generated',
        user_data: userData,
        custom_data: {
          currency: 'USD',
          value: value,
          content_name: body.plan,
        },
      },
    ],
  };

  try {
    const fbRes = await fetch(
      `https://graph.facebook.com/v21.0/${env.FB_PIXEL_ID}/events?access_token=${env.FB_CAPI_TOKEN}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }
    );
    const fbJson = await fbRes.json().catch(() => ({}));
    if (!fbRes.ok) console.error('Meta CAPI purchase error:', JSON.stringify(fbJson));
    return json({ ok: fbRes.ok }, fbRes.ok ? 200 : 502);
  } catch (err) {
    console.error('Meta CAPI purchase request failed:', err.message);
    return json({ ok: false, error: 'capi_request_failed' }, 502);
  }
}

async function sha256Hex(input) {
  const enc = new TextEncoder().encode(input);
  const digest = await crypto.subtle.digest('SHA-256', enc);
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}
