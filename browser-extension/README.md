# GEOFlow Chrome Operator

Manifest V3 extension for human-confirmed publishing work. It connects to a self-hosted GEOFlow instance, claims assigned manual-publication work orders, opens target pages, and fills supported editors. The operator reviews the draft and performs the final publish action.

## Local installation

1. Run the GEOFlow migration and sign in to the admin console.
2. Open `chrome://extensions`, enable Developer mode, and choose **Load unpacked**.
3. Select this `browser-extension` directory.
4. Open the toolbar action, enter the GEOFlow base URL, and approve the displayed code in GEOFlow.

Remote GEOFlow instances require HTTPS. HTTP is accepted only for `localhost` and `127.0.0.1`.

One Chrome profile connects to one GEOFlow instance. Use separate Chrome profiles when platform accounts must stay isolated.
After Chrome or the side panel restarts, reopen a claimed work order from the queue to restore its in-session context and heartbeat.

## Supported behavior

- Generic work orders: claim, open target, copy content, release, and report result.
- Zhihu answers: verify the active profile, locate the answer editor, and fill plain text.
- Self-media article v2: Baijiahao and Sohu adapters verify the domain and account, stop on verification challenges, inspect every target field before writing, and fill title, summary, body, and tags.
- Prepared cover/body images are shown as a manual-upload checklist. The extension never uploads media by itself.
- A filled v2 draft remains claimed, including across a Chrome restart. It cannot be released to another browser while the platform editor contains that draft.
- A v2 work order can be marked published only after the extension detects a public URL and the server receives a successful HTTP 200 readback receipt.
- The extension never clicks the final Publish button.
- Platform cookies, passwords, and access tokens remain in Chrome and are never sent to GEOFlow.

Other configured self-media platforms currently use the safe open-and-copy fallback. Their adapters must stay disabled until platform-specific UAT is complete.

## Verification and packaging

```bash
npm run test:browser-extension
browser-extension/scripts/package.sh
```

The package script creates a reviewable ZIP under `dist/browser-extension` by default.
