# GEOFlow Chrome Draft Operator 0.3.1

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
- Self-media article v3 consumes the server-validated Portable Article Document and downloads required media only from the claimed work order's protected endpoint. Every file is checked against its SHA-256 before use.
- Baijiahao, Sohu, Jianshu, and CSDN have draft-only Web API adapters. They upload every required body image, reuse the first body image as cover when configured, save as a draft, read the draft back, and compare text, heading outline, image count, and image order. These adapters remain experimental until real-account UAT succeeds.
- Zhihu Column, Toutiao, NetEase Media, Penguin, Dayu, and Douyin Article use conservative editor-only adapters. They report `editor_filled`, never `remote_saved`, and remain experimental until a reliable draft readback is implemented and verified.
- Structural editor/readback failures automatically disable only the affected account adapter. Login expiry, CAPTCHA, account mismatch, and transient network errors stop safely without automatic publication.
- A filled v3 draft remains claimed, including across a Chrome restart. It cannot be released to another browser while the platform editor contains that draft.
- A v2 work order can be marked published only after the extension detects a public URL and the server receives a successful HTTP 200 readback receipt.
- The extension never clicks the final Publish button.
- Platform cookies, passwords, and access tokens remain in Chrome and are never sent to GEOFlow.

If an adapter cannot prove the account or editor structure, the operator keeps the work order safe and the user can use the open-and-copy fallback. No adapter is considered production-ready without a real draft reopened from that platform's draft manager.

## Verification and packaging

```bash
npm run test:browser-extension
browser-extension/scripts/package.sh
```

The package script creates a reviewable ZIP under `dist/browser-extension` by default.
