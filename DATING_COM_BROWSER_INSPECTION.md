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

- [x] **Chats/inbox page URL:**  
  `https://dating.com/` (SPA — inbox loaded within the same app)

- [x] **API base URL confirmed:**  
  `https://api.dating.com`

- [x] **Logged-in operator user ID confirmed:**  
  `210860604` (appears embedded in all endpoint URLs)

- [x] **Does the page load the contact list via a separate XHR/fetch request?**  
  ☑ Yes — contact/chat data loaded via XHR to `https://api.dating.com` endpoints

- [ ] **Does the page auto-refresh / poll for new messages?**  
  ☐ Yes — every `_____` seconds, via: `_______________________________________`  
  ☐ No / uses WebSocket instead  
  ☑ Pending — contact list endpoint screenshot still needed

---

## Section 3 — Contact List Endpoint

> **Status: PENDING** — Screenshot of the inbox/contact list XHR request not yet received.  
> All other sections below this have confirmed data.

- [ ] **Endpoint URL for fetching the contact list (inbox list):**  
  `_______________________________________`  
  *(Awaiting screenshot — likely fires on page load before any individual chat is opened)*

- [ ] **HTTP method:** ☐ GET  ☐ POST

- [ ] **Request parameters / payload:**  
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

- [x] **Operator self-profile endpoint confirmed (NOT contact profile):**  
  `GET https://api.dating.com/users/private/210860604`  
  - Returns the **logged-in operator's own account data**, not a contact's data.  
  - Useful for: verifying session validity, reading operator's own profile fields.  
  - **Not useful for CRM contact list or per-contact info.**

- [ ] **Full endpoint URL for loading a single contact's profile:**  
  `_______________________________________`  
  *(Pattern `/users/private/{contact_id}` or `/users/{contact_id}` — pending screenshot)*

- [ ] **HTTP method:** ☐ GET  ☐ POST

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

- [x] **Endpoint URL confirmed:**  
  `GET https://api.dating.com/dialogs/messages/210860604:40486930031?omit=0&select=50`

- [x] **URL pattern:**  
  `GET https://api.dating.com/dialogs/messages/{operator_id}:{contact_id}?omit={offset}&select={count}`
  - `omit=0` → start from most recent (no offset)
  - `select=50` → fetch up to 50 messages
  - Pagination: increment `omit` to load older messages

- [x] **HTTP method:** GET

- [x] **Response format:** JSON array ✅ confirmed

- [x] **Confirmed message fields:**

  | Field | Description |
  |---|---|
  | `id` | Unique message ID |
  | `sender` | User ID of sender |
  | `recipient` | User ID of recipient |
  | `status` | Message status (e.g. sent, delivered) |
  | `timestamp` | Unix or ISO timestamp |
  | `read` | Boolean — whether message was read |
  | `meta` | Extra metadata (type TBC from screenshot) |
  | `text` | Message body text |
  | `tag` | Message category/tag |

- [x] **Direction logic:**  
  `sender == 210860604` → operator/model sent the message (outbound)  
  `sender == 40486930031` → contact sent the message (inbound)

- [x] **Note:** The `/users/private/210860604` endpoint seen firing alongside messages belongs  
  to **Section 4 (operator self-profile), not message history**. Do not confuse with contact data.

- [ ] **Are message poll/refresh requests visible?** (auto-check for new messages)  
  ☑ Candidate: `unseen` endpoint fires on page load — pending screenshot  
  ☐ Confirmed polling endpoint: `_______________________________` every `___` s

---

## Section 6 — Send Message Endpoint

> **Status: NOT YET INSPECTED — deferred by client instruction.**  
> Do not implement send message until this section is separately confirmed.

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

- [ ] **Are there rate-limit errors (429) when navigating quickly?**  
  ☐ No  ☐ Yes — threshold observed: `_______`

- [ ] **Does any XHR request include fingerprinting parameters** (screen resolution, timezone, canvas hash, etc.)?  
  ☐ Not visible  ☐ Yes — describe: `_______________________________________`

- [x] **Endpoints confirmed to be analytics — skip entirely:**  
  - `POST https://api.dating.com/annals/{user_id}/chat-opened` — write-only tracking, no CRM value  
  - `GET https://api.dating.com/v2/dialogs/cheers/{op}:{contact}/vibration/check` — cheers feature, not messages  
  - All `/annals/` and `/events/` URLs

- [ ] **Is Cloudflare / Akamai / PerimeterX active?** (visible in response headers: `cf-ray`, `x-px-*`, etc.)  
  ☐ No protection headers visible  ☐ Cloudflare  ☐ Other: `_______`

- [ ] **Does session expire quickly when idle?**  
  ☐ No  ☐ Yes — approximately `_______` minutes

- [ ] **Does re-login after session expiry require captcha again?**  
  ☐ No  ☐ Yes

---

## Section 8 — Data Safety Assessment

- [ ] **Is there an official operator/partner API documented anywhere?**  
  Check: account settings, developer docs, any API key in account dashboard.  
  ☐ No API found  ☐ Yes — URL/docs location: `_______________________________________`

- [ ] **Can server-side login be done without captcha?**  
  ☐ Yes — no captcha on login (Path B viable)  
  ☐ No — captcha present (Path C / manual fallback required)  
  *(Section 1 must be completed to answer this)*

- [x] **Are JSON responses sufficient to build the contact list and chat view?**  
  ☑ Yes — messages endpoint confirmed as clean JSON array with all needed fields

- [x] **What data is confirmed safely available in normal read-only responses?**  
  ☑ Contact external ID (from sender/recipient fields)  
  ☑ Message history (text, timestamp, direction via sender ID comparison)  
  ☑ Message read status  
  ☑ Message status field  
  ☐ Contact display name — pending contact list / profile screenshot  
  ☐ Photo URL — pending  
  ☐ Online status — pending  
  ☐ Unread count — `unseen` endpoint candidate, pending screenshot  
  ☐ Age / country — pending profile endpoint screenshot  
  ☐ Profile URL — pending  
  ☐ Chat thread URL — pending

- [ ] **Recommended integration path based on findings:**  
  ☑ Leaning Path B — clean REST JSON API, operator's own session credentials  
  *(Final decision after Section 1 login flow and contact list endpoint are confirmed)*

---

## Section 9 — Raw Notes

```
Confirmed API base: https://api.dating.com
Confirmed operator user ID: 210860604
Confirmed contact user ID observed: 40486930031

Operator self-profile (confirmed — useful for session check, NOT contact list):
  GET /users/private/210860604
  Returns: logged-in operator's own account data only.

Messages endpoint (confirmed):
  GET /dialogs/messages/210860604:40486930031?omit=0&select=50
  Response: JSON array
  Fields: id, sender, recipient, status, timestamp, read, meta, text, tag
  Direction: compare sender to operator ID

Analytics endpoints (confirmed — skip entirely):
  POST /annals/210860604/chat-opened
  GET  /v2/dialogs/cheers/210860604:40486930031/vibration/check
  All /annals/ and /events/ URLs

Still needed (BLOCKING implementation):
  - Section 3: contact list / inbox endpoint — fires on page load before any chat
    is opened. This is the last blocker before Step 3 of implementation can begin.

Still needed (non-blocking):
  - Section 1: login flow, captcha presence, session mechanism
  - Section 4: full contact profile endpoint URL + response fields
  - Section 7: protection headers (Cloudflare/Akamai?)
  - Section 6: send message endpoint (deferred — implement last, separate task)
```

---

**After completing this checklist**, hand it back for review.  
Implementation code will be written only after the contact list endpoint (Section 3) and login flow (Section 1) are confirmed.
