# IMPLEMENTATION PLAN — Dating.com as Additional CRM Source

**Project:** crmrc.app (WordPress CRM for dating-platform operators)  
**Task:** Add Dating.com as a second source alongside RomanceCompass  
**Date:** 2026-05-25 (updated 2026-05-26)  
**Status:** Phase 1 implemented — browser-assisted read-only sync  
**API confirmed:** `https://api.dating.com` — REST JSON API

---

## 1. Current Project Structure

```
wp-content/
├── themes/romance-crm/
│   ├── functions.php              ← ACTIVE: all AJAX handlers, auth, RC integration
│   ├── dating-com-functions.php   ← NEW: Dating.com adapter (Phase 1)
│   ├── work-functions.php         ← DRAFT version of functions.php (not loaded)
│   ├── index.php                  ← Main template: login, model list, model detail
│   ├── logs-functions.php         ← WSAL custom alert definitions (hooks only)
│   ├── header.php                 ← HTML head + nav bar (defines ajaxurl global)
│   ├── footer.php                 ← Modals (chat, spam) + JS includes
│   └── assets/js/main.js         ← jQuery: login, contact list, chat modal, broadcast UI
│
└── plugins/
    ├── model-logger/              ← set_log() + wp_action_logs table + admin view
    └── model-user-bulk-editor/    ← Assign operators to models in bulk
```

**Important:** Only `functions.php` is auto-loaded. `work-functions.php` defines the same functions — loading both would cause fatal errors.

---

## 2. Where Models Are Stored

Models are a **WordPress custom post type** called `model`, managed via ACF.

### ACF fields per model post

| ACF key | Content |
|---|---|
| `id_model` | Model's ID on the dating platform |
| `email_model` | Login email |
| `pass_model` | Login password |
| `name_model` | Display name |
| `avatar_model` | Avatar image URL |
| `country_model` | Country |
| `years_model` | Age |
| `profession_model` | Profession |
| `interess_model` | Interests (repeater) |
| `info_model` | Bio/description |
| `user_model` | Array of assigned WP operator user IDs |
| `source_model` | **NEW** — `romance_compass` (default) or `dating_com` |

### Post meta

| Meta key | Content |
|---|---|
| `_blocked-use` | `true` if model page is locked |
| `_blocked-time` | Lock expiry timestamp |
| `_blocked-by-user` | WP user ID of the locker |
| `_dc_import_token` | **NEW** — Per-model token for bookmarklet auth |
| `_dc_contacts` | **NEW** — JSON: imported Dating.com contact list |
| `_dc_messages_{contact_id}` | **NEW** — JSON: imported messages per contact |

---

## 3. Phase 1 — Browser-Assisted Sync (IMPLEMENTED)

### Why browser-assisted?

Browser inspection confirmed the messaging API endpoint but did NOT reveal a clean
contact-list/inbox endpoint. Server-side login flow is also unconfirmed (possible
captcha/Akamai protection). Rather than block on unconfirmed server-side auth,
Phase 1 uses a bookmarklet that runs in the operator's own authenticated browser
session — no server-side auth needed at all.

### How it works

```
1. Operator opens the Dating.com model page in the CRM.
2. CRM shows a "DC Sync" panel with a draggable bookmarklet link.
3. Operator opens https://dating.com in the SAME browser (already logged in
   as that model's account).
4. Operator opens 1–10 desired dialogs on Dating.com (browser loads history).
5. Operator runs the bookmarklet (drag to bookmarks bar, or copy/paste to console).
6. Bookmarklet reads performance.getEntriesByType('resource') to find all
   api.dating.com/dialogs/messages/{op}:{contact} URLs already called by the page.
7. Bookmarklet re-fetches each chat via fetch() with credentials:'include'
   (browser's own Dating.com session cookies — these NEVER leave the browser
   or get sent to our server).
8. Bookmarklet POSTs only sanitized message fields to our WP import AJAX endpoint,
   authenticated by the model's import token (not by cookies).
9. WordPress stores contacts + messages in post meta (de-duplicated by message id).
10. Operator clicks "Обновить контакты" in the CRM panel → contact list refreshes
    from local storage.
```

### What is stored locally (post meta)

**`_dc_contacts`** — JSON array, sorted by last_timestamp DESC:
```json
[{
  "contact_id":     "12345678",
  "operator_id":    "210860604",
  "last_message":   "Hey, how are you?",
  "last_timestamp": 1716720000,
  "unread_count":   3,
  "import_time":    1716720100
}]
```

**`_dc_messages_{contact_id}`** — JSON array, sorted by timestamp ASC, de-duped by id:
```json
[{
  "id":        98765,
  "sender":    "12345678",
  "recipient": "210860604",
  "timestamp": 1716720000,
  "read":      0,
  "text":      "Hey, how are you?",
  "tag":       "",
  "status":    "delivered"
}]
```

### Confirmed API endpoints (browser inspection 2026-05-25)

| Endpoint | Status | Used in |
|---|---|---|
| `GET /dialogs/messages/{op_id}:{contact_id}?omit=0&select=50` | ✅ Confirmed | Bookmarklet fetch |
| `GET /users/private/{op_id}` | ✅ Confirmed (self-profile only) | Not used in Phase 1 |
| Contact list / inbox endpoint | ❌ Not found in inspection | Phase 2 blocker |
| Send message endpoint | 🔒 Deferred | Phase 3 |

### Message direction logic

`msg.sender == operator_id` → outbound (model → contact)  
`msg.sender == contact_id` → inbound (contact → model)

---

## 4. How RomanceCompass Integration Works (unchanged)

### Authentication flow

```
Operator opens model page
  → PHP checks for cookie_dating_com_{id_model}.txt (DC) or cookie_{id_model}.txt (RC)
  → If missing: POST to login.romancecompass.com with email+password
  → On success: cookie saved; session valid for subsequent requests
```

### Contact list / chat flow (RC only)

```
JS polls every 15s → wp-ajax.php?action=get_contact_list
  → PHP: GET https://login.romancecompass.com/chat/
  → Response: HTML containing var contact_list_load = [{...}]
  → PHP: extracts JSON, renders contact cards as HTML
  → JS: replaces .contact-list .response with new HTML

Operator clicks contact → JS sends action=open_chat
  → PHP: GET contact profile + GET message history from RC
  → PHP: renders HTML → JS: shows Bootstrap modal
  → Modal polls check_message every 15s
```

---

## 5. Source Routing (functions.php)

All AJAX handlers check `get_field('source_model', $id) === 'dating_com'` at the top
and delegate to `dc_*` functions from `dating-com-functions.php`.
**All RC code paths are completely unchanged.**

| Handler | DC behavior |
|---|---|
| `handle_get_contact_list()` | Reads `_dc_contacts` post meta |
| `handle_open_chat()` | Reads `_dc_messages_{contact_id}` post meta |
| `handle_check_message()` | Reads `_dc_messages_{contact_id}` post meta |
| `handle_send_message()` | Returns "не поддерживается" error |
| `handle_toggle_favorite()` | Returns "не поддерживается" error |
| `handle_delete_contact()` | Returns "не поддерживается" error |
| `handle_action_spam()` | Returns "Рассылка недоступна" error |
| `handle_get_online_users()` | Returns broadcast-blocked error |
| `handle_send_message_to_user()` | Returns broadcast-blocked error |

---

## 6. Files Changed in Phase 1

| File | Change type | Summary |
|---|---|---|
| `dating-com-functions.php` | **NEW** | Full adapter: import token, AJAX import handler, local storage, contact list, open chat, check message, sync panel, bookmarklet builder, ACF field registration |
| `functions.php` | Modified | `require_once` for adapter; source routing in 9 handlers; DC branch in `get_cookie_file()`; DC guard in `load_more_models_callback()` |
| `index.php` | Modified | Source badge on model list; contact list label; `#goSpam` hidden for DC; sync panel rendered below `.cont` for DC models; bookmarklet link excluded from "leave page" confirm |
| `assets/css/main.css` | Modified | `.source-badge`, `.badge-rc`, `.badge-dating`, `.dc-sync-panel`, `.dc-sync-steps`, `.dc-bookmarklet` |
| `DATING_COM_BROWSER_INSPECTION.md` | Modified | Confirmed findings added |

---

## 7. Security Model

| Concern | How addressed |
|---|---|
| Dating.com cookies / session | Never sent to our server. Bookmarklet uses `credentials:'include'` only for the `fetch()` to `api.dating.com` — browser handles cookies internally. |
| Import endpoint auth | Per-model token (`_dc_import_token`, 40 random chars) verified with `hash_equals()`. No WordPress session needed from the bookmarklet. |
| CORS | `Access-Control-Allow-Origin` header added for `https://dating.com` and `https://www.dating.com` origins only. `Vary: Origin` included. |
| Input sanitization | All POST fields sanitized: `sanitize_text_field()`, `sanitize_textarea_field()`, `intval()`, `ctype_digit()` checks on IDs, `mb_substr()` cap on text. Unknown JSON fields dropped. |
| No server-side auth bypass | `dc_authenticate()` is a stub that returns `false`. Server never calls Dating.com. |
| Broadcast blocked | Server-side guards on all 3 broadcast handlers + `#goSpam` hidden in UI. |

---

## 8. Phase 2 — Server-Side Contact List (BLOCKED)

Cannot proceed until:

| Blocker | Needed for | Reference |
|---|---|---|
| Login / session method confirmed | `dc_authenticate()` — cookie file creation | `DATING_COM_BROWSER_INSPECTION.md` Section 1 |
| Contact list / inbox endpoint confirmed | Full contact list without manual browsing | `DATING_COM_BROWSER_INSPECTION.md` Section 3 |

If an official operator API or confirmed login flow exists, Phase 2 implements:
- `dc_authenticate()` with stored credentials from ACF `email_model` / `pass_model`
- `dc_handle_get_contact_list()` using the confirmed endpoint
- Automatic re-auth on session expiry

---

## 9. Phase 3 — Send Message (DEFERRED)

Requires separate confirmation of the send endpoint (Section 6 of inspection checklist).
Will be a separate task — `dc_send_message()` in `dating-com-functions.php` + routing in
`handle_send_message()`. Not to be implemented until confirmed.

---

## 10. Rollback

All changes are additive and source-gated:

1. **Quick rollback:** Comment out `require_once .../dating-com-functions.php` in `functions.php`. All DC routing disappears. RC behavior unchanged.
2. **Clean rollback:** Remove `dating-com-functions.php`. Revert the 9 source-routing changes in `functions.php`. Revert badge changes in `index.php` and `main.css`.
3. **Data:** Post meta keys `_dc_contacts`, `_dc_messages_*`, `_dc_import_token` can be cleaned up with `delete_post_meta_by_key()` if needed — they do not affect RC models.
4. No schema changes, no new DB tables.

---

## 11. Test Plan

### RC regression (must all pass unchanged)

| Test | Expected |
|---|---|
| Login page renders (unauthenticated) | Login form shown |
| RC model list loads | Models with green RomanceCompass badge |
| Load-more paginates RC models | 10 more models per click |
| RC model detail opens | No errors |
| RC contact list loads within 15 s | Contacts appear |
| RC chat modal opens | Messages visible |
| RC send message works | Message sent, chat refreshes |
| RC spam start/stop logs correctly | Log entries in WP admin |

### DC scaffold (expected behavior)

| Test | Expected |
|---|---|
| DC model shows pink "Dating.com" badge | Correct badge on model list |
| DC model contact list — no import | "Контакты не синхронизированы" placeholder |
| DC sync panel visible | Bookmarklet button, copy button, refresh button |
| Bookmarklet link is draggable | javascript: URI, no confirm dialog triggered |
| Copy button copies raw JS | Clipboard contains bookmarklet code |
| Bookmarklet run on Dating.com — 2 chats open | Alert "синхронизировано 2 из 2" |
| Refresh contacts in CRM after sync | Imported contacts appear in contact list |
| Click imported contact → chat modal | Message history from local storage |
| `#goSpam` button absent | Not visible in DOM |
| Direct AJAX POST to `action_spam` with DC model ID | Server returns error, no log written |
| Direct AJAX POST to `dc_import_messages` with wrong token | Error "Недействительный токен" |
| Import token persists across page refreshes | Same token unless manually rotated |
