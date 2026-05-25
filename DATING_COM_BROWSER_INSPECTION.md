# Dating.com — Manual Browser Inspection Checklist

**Purpose:** Identify what endpoints and data are safely available inside a real Dating.com  
operator/model account before writing any integration code.  
**Tool:** Chrome DevTools → Network tab (XHR/Fetch filter)  
**Requirement:** A real operator account with valid credentials. No automation, no scripts.

---

## How to use this checklist

1. Open Chrome. Press **F12** to open DevTools.
2. Go to the **Network** tab. Enable **Preserve log**. Filter by **Fetch/XHR**.
3. Log in and navigate normally. Observe every request that fires.
4. For each section below, fill in what you find. Write "not found" or "n/a" if absent.

---

## Section 1 — Login Flow

Navigate to the Dating.com login page and complete a normal manual login.

- [ ] **Login page URL:**  
  `_______________________________________`

- [ ] **Form POST URL** (where credentials are submitted; visible in Network tab as a POST request):  
  `_______________________________________`

- [ ] **Form field names** (inspect the login form: right-click → Inspect, or check the POST payload in DevTools):
  - Email/username field name: `_______`
  - Password field name: `_______`
  - Any hidden fields present (e.g. CSRF token, `_token`, `remember_me`):  
    `_______________________________________`

- [ ] **Does a captcha appear?**  
  ☐ No captcha  
  ☐ reCAPTCHA v2 (checkbox "I'm not a robot")  
  ☐ reCAPTCHA v3 (invisible, score-based)  
  ☐ hCaptcha  
  ☐ Custom challenge  
  ☐ Other: `_______`

- [ ] **Does 2FA / SMS verification appear?**  
  ☐ No  
  ☐ Yes — describe: `_______________________________________`

- [ ] **Session mechanism after login** (check Application → Cookies in DevTools):  
  ☐ Cookie-based session (list cookie names: `_______________________________________`)  
  ☐ JWT / Bearer token in localStorage  
  ☐ Other: `_______`

- [ ] **Is there a dedicated operator/studio login URL** different from the main consumer login?  
  ☐ No — same login page  
  ☐ Yes — URL: `_______________________________________`

- [ ] **What does the server return on successful login?**  
  ☐ Redirect (302) to dashboard  
  ☐ JSON `{"result": "ok"}` or similar  
  ☐ Full HTML page  
  ☐ Other: `_______`

---

## Section 2 — Messages / Chats Page

After login, navigate to the inbox or chat list page.

- [ ] **Chats/inbox page URL:**  
  `_______________________________________`

- [ ] **Does the page load the contact list via a separate XHR/fetch request?**  
  ☐ Yes — note URL below  
  ☐ No — list is embedded in the initial HTML page (no XHR)

- [ ] **Does the page auto-refresh / poll for new messages?**  
  ☐ Yes — every `_____` seconds, via: `_______________________________________`  
  ☐ No / uses WebSocket instead  
  ☐ Unknown

---

## Section 3 — Contact List Endpoint

Watch the Network tab while the chat/inbox page loads or auto-refreshes.

- [ ] **Endpoint URL for fetching the contact list:**  
  `_______________________________________`

- [ ] **HTTP method:** ☐ GET  ☐ POST

- [ ] **Request parameters / payload** (query string or POST body):  
  `_______________________________________`

- [ ] **Response format:**  
  ☐ JSON  ☐ HTML fragment  ☐ Other: `_______`

- [ ] **Fields visible in the response** (check each that appears):  
  ☐ Contact/user ID  
  ☐ Display name  
  ☐ Photo URL  
  ☐ Online/offline status  
  ☐ Unread message count  
  ☐ Chat/thread ID  
  ☐ Last message preview  
  ☐ Favorite/starred flag  
  ☐ Other: `_______________________________________`

- [ ] **Sample response snippet** (first item only, anonymise if needed):
  ```
  
  
  
  ```

---

## Section 4 — Open Chat / Contact Detail Endpoint

Click on one contact to open their chat. Watch Network tab.

- [ ] **Endpoint URL for loading a single contact's info** (name, photo, profile):  
  `_______________________________________`

- [ ] **HTTP method:** ☐ GET  ☐ POST

- [ ] **Request parameters** (e.g. user ID, chat ID):  
  `_______________________________________`

- [ ] **Response format:** ☐ JSON  ☐ HTML  ☐ Other: `_______`

- [ ] **Fields visible in contact detail response:**  
  ☐ User ID  ☐ Name  ☐ Age  ☐ Country  ☐ Photo URL  ☐ Profile URL  
  ☐ Other: `_______________________________________`

- [ ] **Is there a direct URL to the contact's Dating.com profile page?**  
  ☐ Yes — URL pattern: `_______________________________________`  
  ☐ No

- [ ] **Is there a direct URL to the chat thread with this contact?**  
  ☐ Yes — URL pattern: `_______________________________________`  
  ☐ No

---

## Section 5 — Load Messages Endpoint

With a chat open, watch the Network tab for the message history request.

- [ ] **Endpoint URL for loading message history:**  
  `_______________________________________`

- [ ] **HTTP method:** ☐ GET  ☐ POST

- [ ] **Request parameters** (e.g. chat_id, user_id, page/offset):  
  `_______________________________________`

- [ ] **Response format:** ☐ JSON  ☐ HTML  ☐ Other: `_______`

- [ ] **Fields visible per message:**  
  ☐ Message text  ☐ Sender direction (inbound/outbound)  ☐ Timestamp  
  ☐ Message type (text / photo / gift)  ☐ Read status  
  ☐ Other: `_______________________________________`

- [ ] **Are message poll/refresh requests visible?** (auto-check for new messages)  
  ☐ Yes — endpoint: `_______________________________` every `___` s  
  ☐ No / WebSocket

---

## Section 6 — Send Message Endpoint

**Only record if clearly visible as a normal operator action — do not attempt to reverse-engineer or trigger artificially.**

- [ ] **Endpoint URL for sending a message:**  
  `_______________________________________`

- [ ] **HTTP method:** ☐ GET  ☐ POST

- [ ] **Visible POST fields** (from the Network → Payload tab after sending one message normally):  
  `_______________________________________`

- [ ] **Response on success:** ☐ JSON  ☐ HTML  ☐ Other: `_______`

- [ ] **Any anti-CSRF token required in the request?**  
  ☐ No  ☐ Yes — header name: `_______`  ☐ Yes — field name: `_______`

---

## Section 7 — Bot / Anti-Automation Signals

Note any signs that the platform actively detects non-human activity.

- [ ] **Are there rate-limit errors (429) when navigating quickly?**  
  ☐ No  ☐ Yes — threshold observed: `_______`

- [ ] **Does any XHR request include fingerprinting parameters** (screen resolution, timezone, canvas hash, etc.)?  
  ☐ Not visible  ☐ Yes — describe: `_______________________________________`

- [ ] **Is Cloudflare / Akamai / PerimeterX active?** (visible in response headers: `cf-ray`, `x-px-*`, etc.)  
  ☐ No protection headers visible  ☐ Cloudflare  ☐ Other: `_______`

- [ ] **Does session expire quickly when idle?**  
  ☐ No  ☐ Yes — approximately `_______` minutes

- [ ] **Does re-login after session expiry require captcha again?**  
  ☐ No  ☐ Yes

---

## Section 8 — Data Safety Assessment

Answer after completing all sections above.

- [ ] **Is there an official operator/partner API documented anywhere?**  
  Check: account settings, developer docs, any API key in account dashboard.  
  ☐ No API found  ☐ Yes — URL/docs location: `_______________________________________`

- [ ] **Can server-side login be done without captcha?**  
  ☐ Yes — no captcha on login (Path B viable)  
  ☐ No — captcha present (Path C / manual fallback required)

- [ ] **Are JSON responses sufficient to build the contact list and chat view?**  
  ☐ Yes  ☐ Partially  ☐ No — only HTML available

- [ ] **What data can be safely imported into the CRM without bypassing any protection?**  
  (tick all confirmed available in normal responses)  
  ☐ Contact display name  
  ☐ Contact external ID  
  ☐ Profile URL  
  ☐ Chat/thread URL  
  ☐ Online status  
  ☐ Unread count  
  ☐ Photo URL  
  ☐ Message history (text)  
  ☐ Age / country  
  ☐ Favorite flag

- [ ] **Recommended integration path based on findings:**  
  ☐ Path A — Official partner API  
  ☐ Path B — Studio portal JSON endpoints (mirrors RC approach)  
  ☐ Path C — Operator bookmarklet / manual entry (no server-side requests to Dating.com)

---

## Section 9 — Raw Notes

Use this space for anything not covered above (screenshots, response bodies, oddities, questions).

```




```

---

**After completing this checklist**, hand it back for review.  
Implementation code will be written only after Path A, B, or C is confirmed here.
