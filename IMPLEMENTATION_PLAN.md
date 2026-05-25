# IMPLEMENTATION PLAN — Dating.com as Additional CRM Source

**Project:** crmrc.app (WordPress CRM for dating-platform operators)  
**Task:** Add Dating.com as a second source alongside RomanceCompass  
**Date:** 2026-05-25  
**Status:** Plan — no code changed yet

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

### Why the RC approach can't be blindly copied

RomanceCompass (`login.romancecompass.com`) is a **purpose-built operator/studio portal** — it expects programmatic login and has JSON AJAX endpoints that are documented or at least stable. Its authentication at the login page accepts form-encoded credentials with no captcha for studio accounts.

Dating.com is a **consumer dating site**. Its public login page may use:
- CSRF tokens that change per request
- Fingerprinting / bot detection
- Captcha on repeated logins
- 2FA for accounts

**Therefore, before any server-side integration can be coded, a dating.com operator account must be manually inspected** to determine whether:

**Path A — Official/Partner API exists:**  
Dating.com (part of Various Inc.) operates a B2B partner/studio program. If the client has an operator account with API access, the integration mirrors the RC approach using the official documented endpoints.

**Path B — Studio portal without documented API:**  
If dating.com provides an operator web portal (like `studio.dating.com` or `operator.dating.com`) that has the same type of JSON AJAX endpoints as RC's `/chat/?ajax=1&action=...`, we can inspect those endpoints using browser DevTools while the operator is normally logged in, then implement the same cookie-based approach as RC — **only if** that portal has no captcha or bot protection on server-side login.

**Path C (Backup — Safe fallback, no server-side requests to dating.com):**  
If neither Path A nor B is available without bypassing protections, the integration uses **manual or semi-automated operator entry** — the operator uses dating.com in their own browser and pastes contact data into CRM. See Section 7.

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

#### TC-06 — Send message (dating.com)

- In the open chat modal, type and send a message.
- **Expected:** Message sends to dating.com contact; chat refreshes; new message appears.
- **Pass criteria:** `send_message` returns success; `wp_action_logs` entry created.

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

---

## Implementation Order

```
Step 0  (Pre-code) Research
         → Manually inspect dating.com operator portal in browser DevTools
         → Determine Path A, B, or C
         → Document the exact login URL, form fields, session check pattern,
           and all AJAX endpoint URLs needed

Step 1  ACF field
         → Add source_model field via ACF admin or acf-json export
         → Set default = romance_compass for all existing models

Step 2  dating-com-functions.php
         → dc_authenticate(), dc_get_common_headers(), dc_get_contact_list(),
           dc_open_chat(), dc_send_message(), dc_check_message(),
           dc_get_online_users(), dc_send_message_to_user()
         → Mirror the RC function signatures exactly

Step 3  functions.php — source routing
         → Require dating-com-functions.php
         → Fix get_cookie_file() to include source prefix
         → Add source check + delegation in each AJAX handler

Step 4  index.php — source badge + conditional broadcast button

Step 5  main.css — badge styles

Step 6  Test TC-01 through TC-10

Step 7  If Path C is needed instead:
         → Create wp_dc_contacts table (dbDelta on plugin activation)
         → Build bookmarklet JS + handle_dc_import_contact() endpoint
         → Add manual entry form to index.php model detail section
         → Re-run TC-01, TC-02, TC-03 (adapted for manual data)
```

---

## Notes on What Is Explicitly Out of Scope

- No captcha solving or bypass of any kind.
- No use of headless browsers (Puppeteer, Playwright) to drive dating.com.
- No scraping of dating.com search results or profile listings.
- No automated mass outreach unless dating.com explicitly provides a broadcast API for operators.
- No storage of third-party user personal data beyond what the platform sends to the operator's own session.
- No hardcoding of credentials — all credentials stay in ACF fields, accessible only to authenticated WP admins.
