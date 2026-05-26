# IMPLEMENTATION PLAN — Dating.com as Additional CRM Source

**Project:** crmrc.app (WordPress CRM for dating-platform operators)  
**Task:** Add Dating.com as a second source alongside RomanceCompass  
**Date:** 2026-05-25  
**Status:** Plan updated after partial browser inspection — no code changed yet  
**API confirmed:** `https://api.dating.com` — clean REST JSON, Path B viable pending login flow confirmation

---

## 1. Current Project Structure

```
wp-content/
├── themes/romance-crm/
│   ├── functions.php          ← ACTIVE: all AJAX handlers, auth, RC integration
│   ├── work-functions.php     ← DRAFT/ALTERNATE version of functions.php (not loaded)
│   ├── index.php              ← Main template: login form, model list, model detail page
│   ├── logs-functions.php     ← WSAL custom alert definitions (hooks only)
│   ├── header.php             ← HTML head + nav bar
│   ├── footer.php             ← Modals (chat modal, spam/broadcast modal) + JS includes
│   └── assets/js/main.js     ← jQuery: login, contact list, chat modal, broadcast UI
│
└── plugins/
    ├── model-logger/          ← Defines set_log() + wp_action_logs table + admin view
    └── model-user-bulk-editor/← Admin tool to assign operators to models in bulk
```

**Important:** Only `functions.php` is auto-loaded by WordPress. `work-functions.php` defines the same functions — loading both would be fatal. `work-functions.php` appears to be a cleaner refactor that is not yet in production.

---

## 2. Where Models Are Stored

Models are a **WordPress custom post type** called `model`, managed via ACF (Advanced Custom Fields).

### ACF fields per model post

| ACF key | Content |
|---|---|
| `id_model` | Model's ID on the dating platform |
| `email_model` | Login email for the platform |
| `pass_model` | Login password for the platform |
| `name_model` | Display name |
| `avatar_model` | Avatar image URL |
| `country_model` | Country |
| `years_model` | Age |
| `profession_model` | Profession |
| `interess_model` | Interests (repeater field) |
| `info_model` | Bio/description |
| `user_model` | Array of WP user IDs (assigned operators) |

### Post meta (stored directly, not via ACF)

| Meta key | Content |
|---|---|
| `_blocked-use` | `true` if model page is locked by another operator |
| `_blocked-time` | Unix timestamp when lock expires |
| `_blocked-by-user` | WP user ID who locked the model |

**There is currently no `source` field.** All models are implicitly RomanceCompass.

---

## 3. Where Contacts, Chats, and Statuses Are Stored

**Contacts and messages are NOT stored in WordPress.** They are fetched **live** from the dating platform on every request.

| Data | Storage | How |
|---|---|---|
| Contact list | RomanceCompass server | `GET /chat/` → parse `contact_list_load` JS variable |
| Contact detail (name, country, age, photo) | RC server | `GET /chat/?ajax=1&action=get_contact_customer&c_id=X` |
| Chat messages | RC server | `GET /chat/?ajax=1&action=get_messages&chat_id=X` |
| Online users (for broadcast) | RC server | `GET /chat/?ajax=1&action=get_online&page_num=N` |
| Favorite status | RC server | `POST /chat/?ajax=1&action=update_states` |

**Session cookies** are stored locally as text files:  
`wp-uploads/cookies/cookie_{id_model}.txt`

**Action logs** are stored in `wp_action_logs` (MySQL), written by the `model-logger` plugin.

There is **no local contact status concept** — the CRM shows whatever the platform returns (online/offline indicator, unread count, favorite flag).

---

## 4. How the Current RomanceCompass Integration Works

### Authentication flow (`functions.php:175–242`)

```
Operator opens model page
  → PHP checks for cookie file at wp-uploads/cookies/cookie_{id_model}.txt
  → If missing: POST to https://login.romancecompass.com/ with email+password
  → On success: cookie saved to file; session is valid
  → On any subsequent request, if response contains redirect to login: re-authenticate
```

### Contact list flow (`functions.php:363–432`)

```
JS polls every 15 seconds → wp-ajax.php?action=get_contact_list
  → PHP: GET https://login.romancecompass.com/chat/
  → Response: full HTML page containing:
      var contact_list_load = [{chat_id, customer: {id, name, is_online, photo_src}, unread_cnt, favorite}, ...]
  → PHP: regex extracts the JSON, renders contact cards as HTML
  → JS: replaces .contact-list .response with new HTML
```

### Chat modal flow (`functions.php:502–619`)

```
Operator clicks contact → JS sends action=open_chat
  → PHP calls two RC endpoints:
      1. get_contact_customer → name, photo, country, age
      2. get_messages → array of {text, gender, date}
  → PHP renders HTML with user info + message bubbles
  → JS shows Bootstrap modal with the HTML
  → Modal polls check_message every 15 seconds for new messages
```

### Key shared utilities

| Function | Location | Purpose |
|---|---|---|
| `make_curl_request()` | `functions.php:123` | Generic cURL wrapper |
| `authenticate()` | `functions.php:175` | POST login to RC |
| `get_common_headers()` | `functions.php:271` | HTTP headers for RC AJAX |
| `get_cookie_file()` | `functions.php:288` | Returns path to cookie file |

All endpoints are hardcoded to `login.romancecompass.com`.

### Broadcast ("Spam") flow (`functions.php:757–838`, `main.js:249–611`)

```
Operator enters message → JS starts loop:
  GET action=get_online_users (paged) → array of online users
  For each user: POST action=send_message_to_user with 5–8 second random delay
```

---

## 5. Safest Way to Add Dating.com as a Source

### What browser inspection confirmed

**API base:** `https://api.dating.com` — REST JSON API, clean and structured.  
**Path direction:** Leaning **Path B** (operator's own session credentials, cookie-based, mirrors RC approach).  
**Blocker remaining:** Login flow (Section 1 of checklist) must be confirmed before server-side auth is coded.

### Confirmed endpoint map (from browser inspection)

| Endpoint | Method | Purpose | Status |
|---|---|---|---|
| `GET /dialogs/messages/{op_id}:{contact_id}?omit={n}&select={n}` | GET | Load message thread | ✅ Confirmed |
| `GET /users/private/{op_id}` | GET | Operator's **own** profile (session check, not contact data) | ✅ Confirmed — not for contact list |
| `GET /users/private/{contact_id}` or `GET /users/{contact_id}` | GET | Individual contact profile | ⏳ Path pattern pending |
| `GET /…/unseen` (candidate) | GET | Unread message count | ⏳ Screenshot pending |
| **Contact list / inbox endpoint** | GET | Full conversation list | ⏳ **BLOCKING** — screenshot needed |
| Send message endpoint | POST | Send a message | 🔒 Deferred — do not implement yet |
| `POST /annals/{op_id}/chat-opened` | POST | Analytics write — **skip** | ✅ Confirmed useless |
| `GET /v2/dialogs/cheers/{op}:{contact}/vibration/check` | GET | Cheers feature — **skip** | ✅ Confirmed useless |
| All `/annals/` and `/events/` URLs | — | Analytics only — **skip** | ✅ Confirmed useless |

### Message fields confirmed

```json
{
  "id":        "...",
  "sender":    210860604,
  "recipient": 40486930031,
  "status":    "...",
  "timestamp": "...",
  "read":      true,
  "meta":      {},
  "text":      "...",
  "tag":       "..."
}
```

**Direction logic:** `sender == operator_id` → outbound (model sent); `sender == contact_id` → inbound (contact sent).

### 7-point implementation approach

The following is the exact scope of what will be implemented, in order:

#### 1. `source_model` ACF field

Add a Select field to the `model` post type:
- Key: `source_model`
- Choices: `romance_compass` (default) / `dating_com`
- Required, with `romance_compass` pre-set on all existing models

No existing model is affected until an operator explicitly switches it.

#### 2. Source badge in the UI

Model list card and model detail page both show a coloured badge:
- "RomanceCompass" — green (`#e8f4e8 / #2d6a2d`)
- "Dating.com" — pink (`#fce8f3 / #8b1a5a`)

CSS added to `main.css`. PHP badge logic added to `index.php` model card and detail header.

#### 3. Cookie / session isolation by source

`get_cookie_file()` (`functions.php:288`) updated to prefix cookie filenames with the source slug:

```
cookie_romance_compass_{id_model}.txt   ← RC (renamed from current cookie_{id}.txt)
cookie_dating_com_{id_model}.txt        ← new Dating.com sessions
```

This prevents session files colliding when the same numeric model ID exists on both platforms.

#### 4. Dating.com adapter file

New file: `wp-content/themes/romance-crm/dating-com-functions.php`

Contains Dating.com-specific versions of every RC helper, all prefixed `dc_`:

| Function | RC equivalent | Purpose |
|---|---|---|
| `dc_authenticate($id, $cookie_file)` | `authenticate()` | POST login to dating.com |
| `dc_get_common_headers()` | `get_common_headers()` | HTTP headers for api.dating.com |
| `dc_make_authenticated_request()` | (inline in handlers) | cURL + re-auth on session expiry |
| `dc_get_contact_list($id)` | `handle_get_contact_list()` | Fetch inbox/conversation list |
| `dc_open_chat($id, $contact_id)` | `handle_open_chat()` | Fetch profile + messages |
| `dc_check_messages($id, $op_id, $contact_id)` | `handle_check_message()` | Poll for new messages |

All functions in this file are **read-only** with respect to Dating.com. No writes until send endpoint is separately confirmed.

#### 5. Read-only contact list + open chat + check messages

Three AJAX handlers in `functions.php` gain a source check at the top and delegate to the `dc_` functions:

- `handle_get_contact_list()` — delegates to `dc_get_contact_list()`
- `handle_open_chat()` — delegates to `dc_open_chat()`
- `handle_check_message()` — delegates to `dc_check_messages()`

The RC code path inside each handler is **unchanged** — the delegation only fires when `source_model === 'dating_com'`.

The contact card HTML returned for Dating.com contacts will include:
- Name, contact ID, online status, unread count (once contact list endpoint is confirmed)
- Link/button to open the chat modal
- A `data-source="dating_com"` attribute so JS can distinguish if needed

#### 6. Broadcast (`#goSpam`) hidden for Dating.com

In `index.php` model detail section (line ~369), the "Рассылка" button is conditionally hidden:

```php
<?php if (get_field('source_model') !== 'dating_com'): ?>
  <button id="goSpam" class="btn btn-outline-primary" style="margin: auto;display: block;">Рассылка</button>
<?php endif; ?>
```

The `handle_get_online_users()` and `handle_send_message_to_user()` AJAX handlers will also return an error if called with a `dating_com` model ID, as a server-side safety net.

#### 7. Send message — deferred until endpoint is separately confirmed

`handle_send_message()` will **not** be modified in the initial implementation.  
The Dating.com send endpoint, its required POST fields, and any CSRF/token requirements must be confirmed via browser inspection (checklist Section 6) before this is touched.

When confirmed, `dc_send_message($id, $contact_id, $message)` will be added to `dating-com-functions.php` and `handle_send_message()` will gain the same source-routing delegation as the read handlers above.

---

## 6. Files That Need Changes

### New files to create

| File | Purpose |
|---|---|
| `wp-content/themes/romance-crm/dating-com-functions.php` | Dating.com adapter: auth, headers, cookie file, endpoint URLs |

### Files to modify

#### `wp-content/themes/romance-crm/functions.php`

**Change 1 — Add `require` for the new dating.com file** (1 line, near top)

**Change 2 — `get_cookie_file()` (line 288)**  
Prefix cookie filename with source to prevent ID collision:
```php
// Before:
return $cookie_dir . 'cookie_' . $id_model . '.txt';
// After:
$source = get_field('source_model', $id) ?: 'romance_compass';
return $cookie_dir . 'cookie_' . $source . '_' . $id_model . '.txt';
```

**Change 3 — `handle_get_contact_list()` (line 363)**  
Add source check at the top; delegate to `dc_handle_get_contact_list()` if source is `dating_com`.

**Change 4 — `handle_open_chat()` (line 502)**  
Same pattern: check source, delegate to dating.com handler.

**Change 5 — `handle_send_message()` (line 622)**  
Same pattern.

**Change 6 — `handle_check_message()` (line 677)**  
Same pattern.

**Change 7 — `handle_toggle_favorite()` (line 435)**  
Same pattern (or mark as not supported for dating.com if API doesn't expose it).

**Change 8 — `handle_delete_contact()` (line 471)**  
Same pattern.

**Change 9 — `handle_get_online_users()` (line 754)**  
Same pattern (broadcast online users).

**Change 10 — `handle_send_message_to_user()` (line 801)**  
Same pattern.

#### `wp-content/themes/romance-crm/index.php`

**Change 11 — Model list card (line 253)**  
Add source badge next to model name:
```php
$source = get_field('source_model');
$badge_label = $source === 'dating_com' ? 'Dating.com' : 'RomanceCompass';
$badge_class = $source === 'dating_com' ? 'badge-dating' : 'badge-rc';
// Add <span class="source-badge $badge_class">$badge_label</span> beside ID
```

**Change 12 — Model detail page contact list header (line 363)**  
Show source label in the "Список контактов" section header.

**Change 13 — Model detail page: conditionally hide broadcast button**  
If source is `dating_com` and broadcast is not supported: hide `#goSpam`.

#### `wp-content/themes/romance-crm/assets/js/main.js`

**Change 14 — `openChat` click handler (line 195)**  
Pass `source` as a `data-*` attribute on each contact card so the JS can send it with AJAX. The PHP already returns HTML; the source can be embedded in the contact card HTML as `data-source="dating_com"`.

No other JS changes required — all routing logic lives in PHP.

#### `wp-content/themes/romance-crm/assets/css/main.css`

**Change 15 — Add source badge styles**
```css
.source-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
.badge-rc     { background: #e8f4e8; color: #2d6a2d; }
.badge-dating { background: #fce8f3; color: #8b1a5a; }
```

### ACF (WordPress admin, not a file change)

**Add ACF field `source_model`** to the `model` post type:  
- Field type: Select  
- Choices: `romance_compass : RomanceCompass` / `dating_com : Dating.com`  
- Default: `romance_compass`  
- Required: yes  

This is done in the WordPress admin (ACF → Field Groups → model) or via an ACF JSON export file added to the theme's `acf-json/` directory.

---

## 7. Backup Plan (Path C — No Server-Side Requests to Dating.com)

If server-side login to dating.com is not safely achievable:

### Option C1 — Operator browser helper (JavaScript bookmarklet)

The operator logs in to dating.com themselves (manually, in their own browser). A one-click bookmarklet:
- Reads the current page DOM (chat list or contact page)
- Extracts: name, user ID, profile URL, chat URL, unread count, online status
- POSTs the extracted data to a new CRM endpoint: `wp-ajax.php?action=dc_import_contact`
- The CRM stores this data in a new `wp_dc_contacts` table or as post meta on the model post

This requires no server-side requests to dating.com. The operator is acting through their own authenticated browser session.

**New endpoint needed:** `handle_dc_import_contact()` in `dating-com-functions.php`  
**New DB table:** `wp_dc_contacts` (model_id, customer_id, name, photo_url, chat_url, profile_url, unread_cnt, is_online, last_synced)

### Option C2 — Manual entry form

Add a form on the model detail page (hidden behind a toggle):
- Fields: Platform ID, Display name, Profile URL, Chat URL, Note
- On submit: stores to `wp_dc_contacts` table
- Contacts show below or alongside the RC contact list with a "Dating.com" label

### Rollback plan

All changes are additive:
- New file `dating-com-functions.php` → simply remove the `require` line and delete the file
- ACF field `source_model` → set all models back to `romance_compass` default, then remove the field
- `wp_dc_contacts` table (if created) → `DROP TABLE wp_dc_contacts` (only for Path C)
- No existing RC functionality is touched by the source routing — all current code paths are unchanged when `source_model = romance_compass`

**Git safety:** All changes go on a feature branch. Production is updated only after full review and test.

---

## 8. Test Plan on One Model

### Preparation

1. Duplicate one existing RC model in WP admin (or create a test model).
2. Set `source_model = dating_com` on the test model.
3. Assign the test model to the tester's operator account.

### Test cases

#### TC-01 — Model list shows correct source badge

- Open the front page (model list).
- **Expected:** Test model shows "Dating.com" pink badge; other models show "RomanceCompass" green badge.
- **Pass criteria:** Badge text and color correct; no PHP errors in error log.

#### TC-02 — RC model contact list unaffected

- Open an existing RC model page.
- **Expected:** Contact list loads normally from RomanceCompass within 15 seconds.
- **Pass criteria:** Contacts appear; no regression; no PHP errors.

#### TC-03 — Dating.com model contact list (Path A or B)

- Open the test dating.com model page.
- **Expected:** Contact list loads from dating.com; shows contacts with online indicator, name, unread count, source label "Dating.com".
- **Pass criteria:** Contacts appear; source label visible; `cookie_dating_com_{id}.txt` created in uploads/cookies/.

#### TC-04 — Cookie file naming does not collide

- Verify file system: RC model with id `123` creates `cookie_romance_compass_123.txt`; dating.com model with id `123` creates `cookie_dating_com_123.txt`.
- **Pass criteria:** Two separate files, no overwrites.

#### TC-05 — Chat modal opens for dating.com contact

- Click a contact on the dating.com model page.
- **Expected:** Bootstrap modal shows contact info and message history from dating.com.
- **Pass criteria:** Modal appears with correct name, messages, layout.

#### TC-06 — Send message (dating.com) — DEFERRED

- **Status:** Not implemented in initial scope. Send endpoint not yet confirmed.
- Will be added as a separate task once checklist Section 6 is filled.
- **Expected (when implemented):** Message sends to dating.com contact; chat refreshes; new message appears; `wp_action_logs` entry created.

#### TC-07 — Broadcast hidden or gated for dating.com

- Open the dating.com model page.
- **Expected:** "Рассылка" button is either hidden (if not supported) or routes to dating.com online users.
- **Pass criteria:** No RC broadcast is triggered for dating.com models.

#### TC-08 — Action logs record source

- Perform open-chat and send-message on the dating.com model.
- Open WP Admin → Логи действий.
- **Expected:** Log entries reference the correct dating.com model link.
- **Pass criteria:** Log entries appear; no RC model linked by mistake.

#### TC-09 — Session expiry handled (dating.com)

- Manually delete `cookie_dating_com_{id}.txt`.
- Trigger contact list refresh.
- **Expected:** CRM re-authenticates to dating.com; contact list loads.
- **Pass criteria:** New cookie file created; contacts appear; no error shown to operator.

#### TC-10 — Invalid credentials handled gracefully

- Set a wrong password in `pass_model` for the dating.com test model.
- **Expected:** Contact list shows a clear error message, not a blank page or PHP fatal.
- **Pass criteria:** Error HTML rendered in `.contact-list .response`; no stack trace exposed.

#### TC-11 — Broadcast button absent for Dating.com model

- Open the dating.com test model page.
- **Expected:** No `#goSpam` button visible.
- **Pass criteria:** Button absent from DOM; direct AJAX call to `get_online_users` with a dating_com model ID returns a server-side error (not RC data).

#### TC-12 — Cookie file naming does not collide

- Create an RC model and a Dating.com model that both have `id_model = 100`.
- Trigger auth for both.
- **Pass criteria:** Two distinct files: `cookie_romance_compass_100.txt` and `cookie_dating_com_100.txt`; neither overwrites the other.

---

## Implementation Order

```
Step 0  BLOCKED — complete browser inspection first
         → Confirm login flow (checklist Section 1):
             login URL, POST target, form fields, captcha presence, session mechanism
         → Confirm contact list endpoint (checklist Section 3):
             URL, method, response fields
         → Confirm full profile endpoint path (checklist Section 4)
         → Confirm unseen/unread endpoint (checklist Section 5 / Section 3)
         Code starts only when Step 0 is complete.

Step 1  ACF field  [SAFE — no risk to existing models]
         → Add source_model Select field via ACF admin or acf-json export
         → Default = romance_compass; apply to all existing models

Step 2  main.css — source badge styles  [SAFE — additive CSS only]
         → .source-badge, .badge-rc, .badge-dating

Step 3  index.php — source badge + hide broadcast for Dating.com  [LOW RISK]
         → Add badge to model list card and model detail header
         → Conditionally hide #goSpam for dating_com source
         → RC models completely unaffected

Step 4  functions.php — cookie prefix + server-side broadcast guard  [LOW RISK]
         → Update get_cookie_file() to prefix with source slug
         → Add error return in handle_get_online_users() and
           handle_send_message_to_user() if source == dating_com

Step 5  dating-com-functions.php — new adapter file  [NEW FILE — no risk to existing code]
         → dc_authenticate()
         → dc_get_common_headers()
         → dc_make_authenticated_request()
         → dc_get_contact_list()   ← needs contact list endpoint from Step 0
         → dc_open_chat()          ← needs profile endpoint from Step 0
         → dc_check_messages()     ← uses confirmed /dialogs/messages/ endpoint
         → dc_send_message()       PLACEHOLDER ONLY — not connected to any handler

Step 6  functions.php — source routing in read-only handlers
         → require dating-com-functions.php
         → handle_get_contact_list(): add source check → dc_get_contact_list()
         → handle_open_chat(): add source check → dc_open_chat()
         → handle_check_message(): add source check → dc_check_messages()
         → handle_send_message(): NO CHANGE — deferred to Step 8

Step 7  Test TC-01 through TC-09 (send excluded)

Step 8  SEPARATE TASK — Send message for Dating.com
         → Requires: checklist Section 6 filled and reviewed
         → Implement dc_send_message() + route handle_send_message()
         → Re-run TC-06
```

---

## Notes on What Is Explicitly Out of Scope

- No captcha solving or bypass of any kind.
- No use of headless browsers (Puppeteer, Playwright) to drive dating.com.
- No scraping of dating.com search results or profile listings.
- No automated mass outreach unless dating.com explicitly provides a broadcast API for operators.
- No storage of third-party user personal data beyond what the platform sends to the operator's own session.
- No hardcoding of credentials — all credentials stay in ACF fields, accessible only to authenticated WP admins.
