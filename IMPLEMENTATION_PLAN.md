# CRM Implementation Plan — Adding Dating.com as a Safe Source

**Date:** 2026-05-25  
**Status:** Pre-implementation planning  
**Constraint:** Repository is currently empty — this plan doubles as the initial architecture design.

---

## 1. Current Project Structure

The repository was inspected and found to be **completely empty** (no commits, no branches, no files). This document therefore serves two purposes:

1. Define the baseline architecture for the CRM.
2. Plan the safe addition of Dating.com as a second source alongside RomanceCompass.

### Proposed Directory Layout

```
crm-project/
├── manage.py                        # Django entry point
├── requirements.txt
├── .env.example
├── docker-compose.yml
├── crm/                             # Django project settings package
│   ├── settings/
│   │   ├── base.py
│   │   ├── local.py
│   │   └── production.py
│   ├── urls.py
│   └── wsgi.py
├── apps/
│   ├── accounts/                    # Operators / staff users
│   │   ├── models.py
│   │   ├── views.py
│   │   └── urls.py
│   ├── contacts/                    # Core contacts module
│   │   ├── models.py                # Contact, Source, Status
│   │   ├── admin.py
│   │   ├── views.py
│   │   ├── serializers.py
│   │   └── urls.py
│   ├── chats/                       # Messages / chat threads
│   │   ├── models.py                # ChatThread, Message
│   │   ├── admin.py
│   │   ├── views.py
│   │   └── urls.py
│   ├── sources/                     # Source integration layer
│   │   ├── base.py                  # AbstractSource interface
│   │   ├── romance_compass.py       # RomanceCompass adapter
│   │   ├── dating_com.py            # Dating.com adapter (NEW)
│   │   ├── registry.py              # Source registry
│   │   └── admin.py
│   └── notifications/               # Inbound email / webhook parsing
│       ├── models.py
│       ├── parsers/
│       │   ├── base.py
│       │   ├── romance_compass.py
│       │   └── dating_com.py        # (NEW) email notification parser
│       └── views.py                 # Webhook / inbound email endpoints
├── templates/
│   ├── base.html
│   ├── contacts/
│   └── chats/
└── static/
```

---

## 2. Where Models, Contacts, Chats, and Statuses Are Stored

All data lives in the `apps/` Django applications. Key model locations:

| Concept | App | Model |
|---|---|---|
| Operators (staff) | `apps/accounts` | `Operator` |
| Contact profiles | `apps/contacts` | `Contact` |
| Source definition | `apps/contacts` | `Source` |
| Contact status | `apps/contacts` | `ContactStatus` |
| Chat threads | `apps/chats` | `ChatThread` |
| Individual messages | `apps/chats` | `Message` |
| Raw email notifications | `apps/notifications` | `InboundNotification` |

---

## 3. Database Tables Involved

### `sources` table — `Source` model

```python
class Source(models.Model):
    ROMANCE_COMPASS = "romance_compass"
    DATING_COM       = "dating_com"
    MANUAL           = "manual"

    SOURCE_CHOICES = [
        (ROMANCE_COMPASS, "RomanceCompass"),
        (DATING_COM,      "Dating.com"),
        (MANUAL,          "Manual"),
    ]

    slug        = models.SlugField(unique=True)          # e.g. "dating_com"
    label       = models.CharField(max_length=100)       # e.g. "Dating.com"
    is_active   = models.BooleanField(default=True)
    base_url    = models.URLField(blank=True)             # profile URL template
    created_at  = models.DateTimeField(auto_now_add=True)
```

### `contacts` table — `Contact` model

```python
class Contact(models.Model):
    source            = models.ForeignKey(Source, on_delete=models.PROTECT)
    external_id       = models.CharField(max_length=255)  # ID on the platform
    display_name      = models.CharField(max_length=255)
    profile_url       = models.URLField(blank=True)
    chat_url          = models.URLField(blank=True)
    status            = models.ForeignKey("ContactStatus", on_delete=models.SET_NULL, null=True)
    operator          = models.ForeignKey(settings.AUTH_USER_MODEL, on_delete=models.SET_NULL, null=True)
    notes             = models.TextField(blank=True)
    last_message_at   = models.DateTimeField(null=True, blank=True)
    created_at        = models.DateTimeField(auto_now_add=True)
    updated_at        = models.DateTimeField(auto_now=True)

    class Meta:
        unique_together = ("source", "external_id")
```

### `contact_statuses` table — `ContactStatus` model

```python
class ContactStatus(models.Model):
    label     = models.CharField(max_length=100)   # e.g. "New", "In Progress", "Closed"
    color     = models.CharField(max_length=7)     # hex color for UI badge
    order     = models.PositiveSmallIntegerField(default=0)
    is_active = models.BooleanField(default=True)
```

### `chat_threads` table — `ChatThread` model

```python
class ChatThread(models.Model):
    contact         = models.ForeignKey(Contact, on_delete=models.CASCADE, related_name="threads")
    source_thread_id = models.CharField(max_length=255, blank=True)  # thread ID on platform
    thread_url      = models.URLField(blank=True)
    created_at      = models.DateTimeField(auto_now_add=True)
```

### `messages` table — `Message` model

```python
class Message(models.Model):
    INBOUND  = "inbound"
    OUTBOUND = "outbound"
    DIRECTION_CHOICES = [(INBOUND, "Inbound"), (OUTBOUND, "Outbound")]

    thread      = models.ForeignKey(ChatThread, on_delete=models.CASCADE, related_name="messages")
    direction   = models.CharField(max_length=10, choices=DIRECTION_CHOICES)
    body        = models.TextField()
    sent_at     = models.DateTimeField()
    is_read     = models.BooleanField(default=False)
    raw_payload = models.JSONField(null=True, blank=True)  # original notification data
```

### `inbound_notifications` table — `InboundNotification` model

```python
class InboundNotification(models.Model):
    source      = models.ForeignKey(Source, on_delete=models.PROTECT)
    raw_body    = models.TextField()         # raw email or webhook body
    parsed      = models.BooleanField(default=False)
    created_at  = models.DateTimeField(auto_now_add=True)
```

---

## 4. How the Current RomanceCompass Source Works

Since no code exists yet, this section defines the **intended pattern** that RomanceCompass integration follows. All future sources (including Dating.com) must conform to this interface.

### Abstract Source Interface (`apps/sources/base.py`)

```python
class AbstractSource:
    slug: str         # unique identifier
    label: str        # human-readable name
    base_profile_url: str   # URL template with {external_id}
    base_chat_url: str

    def build_profile_url(self, external_id: str) -> str:
        return self.base_profile_url.format(external_id=external_id)

    def build_chat_url(self, thread_id: str) -> str:
        return self.base_chat_url.format(thread_id=thread_id)

    def parse_notification(self, raw: str) -> dict:
        """
        Parse an inbound email/webhook notification.
        Returns: {external_id, display_name, message_body, sent_at, thread_id}
        """
        raise NotImplementedError

    def validate_contact_data(self, data: dict) -> bool:
        raise NotImplementedError
```

### RomanceCompass Adapter (`apps/sources/romance_compass.py`)

```python
class RomanceCompassSource(AbstractSource):
    slug = "romance_compass"
    label = "RomanceCompass"
    base_profile_url = "https://www.romancecompass.com/profile/{external_id}"
    base_chat_url    = "https://www.romancecompass.com/chat/{thread_id}"

    def parse_notification(self, raw: str) -> dict:
        # Parses email notifications sent by RomanceCompass to the operator's
        # inbox. No scraping, no session tokens, no captcha interaction.
        # Relies solely on the email content that RC sends proactively.
        ...
```

### Integration Flow (no scraping involved)

```
Dating platform (RC / Dating.com)
  │  sends email notification to operator inbox
  ▼
Inbound email forwarded to CRM (e.g., via SendGrid Inbound Parse, Mailgun Routes,
  or operator manually copies the notification)
  ▼
InboundNotification created (raw body stored)
  ▼
Parser extracts: sender name, external_id, message snippet, profile/chat URLs
  ▼
Contact upserted (created or updated) in contacts table
  ▼
Message recorded in messages / chat_threads tables
  ▼
Operator sees new contact in CRM dashboard with source badge, status, chat link
```

**Key point:** The integration never logs into the dating platform, never visits pages programmatically, and never sends automated messages. It only reads email notifications the platform sends to the operator.

---

## 5. Safest Way to Add Dating.com Source

### Threat Model / Constraints

| Prohibited | Reason |
|---|---|
| Automated login / session scraping | Violates platform ToS; captcha bypass required |
| Mass automated outreach | Spam; illegal in many jurisdictions |
| Bypassing anti-bot protection | Violates ToS and potentially CFAA/GDPR |
| Scraping user profile pages | No authorization from Dating.com |

### Recommended Approach: Email Notification Parsing + Manual Operator Entry

Dating.com sends email notifications when a user receives a new message, a profile view, a like, or a match. These emails are sent **by Dating.com to the operator's inbox** — reading your own email is fully authorized. The CRM will:

1. **Receive forwarded emails** from the operator's Dating.com notification inbox (via SendGrid Inbound Parse webhook, Mailgun, Postmark, or any inbound email service).
2. **Parse the email** to extract: sender name, profile link, chat link, message snippet, timestamp.
3. **Upsert the contact** in the CRM with `source = "dating_com"`.
4. **Display** the contact in the operator's CRM dashboard with the Dating.com label, status, and direct link to the dating.com chat thread.

**Fallback (if email parsing is insufficient):** Provide an operator-facing manual entry form pre-filled with `source = dating_com`, where the operator pastes the profile URL and contact details.

### Dating.com Email Notification Structure

Dating.com sends notification emails in a consistent format. The parser will extract:

```
From:    noreply@dating.com  (or notifications@dating.com)
Subject: [Name] sent you a message / New message from [Name]
Body:    Name, profile URL, message preview
```

The `dating_com.py` parser will use regex / BeautifulSoup on the email HTML to extract these fields — **no HTTP requests to dating.com are made by the CRM**.

---

## 6. Files That Need to Be Created / Changed

### New Files

| File | Purpose |
|---|---|
| `apps/sources/dating_com.py` | Dating.com source adapter (email parser) |
| `apps/notifications/parsers/dating_com.py` | Email notification parser for Dating.com |
| `apps/contacts/migrations/000X_add_dating_com_source.py` | DB migration to seed Dating.com source row |

### Modified Files

| File | Change |
|---|---|
| `apps/sources/registry.py` | Register `DatingComSource` alongside `RomanceCompassSource` |
| `apps/contacts/models.py` | Add `DATING_COM` constant to `Source.SOURCE_CHOICES` |
| `apps/notifications/views.py` | Route inbound email webhook to correct parser by sender domain |
| `templates/contacts/contact_list.html` | Add Dating.com source badge (color, icon, label) |
| `templates/contacts/contact_detail.html` | Show Dating.com profile/chat link |
| `crm/settings/base.py` | Add `DATING_COM_EMAIL_DOMAIN` setting |
| `requirements.txt` | Add `beautifulsoup4`, `lxml` if not already present |

### No Changes Needed

| File | Reason |
|---|---|
| `apps/chats/models.py` | ChatThread/Message models are source-agnostic |
| `apps/accounts/models.py` | Operator model is source-agnostic |
| `apps/contacts/views.py` | Contact views filter by `source` slug — no change needed |

---

## 7. Backup Plan

If email notification parsing proves unreliable (e.g., Dating.com changes its email format or stops sending notifications):

### Option A — Operator Helper Bookmarklet

A JavaScript bookmarklet the operator runs while on a Dating.com chat page in their own browser. It reads the visible page DOM (the operator's own session) and sends the contact/message data to the CRM API. No automation, no captcha — the operator performs all navigation manually.

### Option B — Manual Entry Form

A CRM form pre-configured for the Dating.com source. The operator copies the profile URL, name, and message from Dating.com and pastes it into the CRM. A URL parser then extracts the external_id and builds the chat link.

```
Profile URL input: https://dating.com/profile/12345678
                                              ^^^^^^^^ → external_id
```

### Option C — Official Partner API

If Dating.com offers a partner or affiliate API (they do have a white-label / affiliate program), credentials can be stored in the CRM's `.env` and the adapter can call the official REST API. This is the cleanest path but requires a formal agreement with Dating.com.

---

## 8. Test Plan on One Model (Operator)

### Setup

1. Create a test operator account in the CRM: `test_operator@example.com`.
2. Seed the `sources` table with `{ slug: "dating_com", label: "Dating.com", is_active: true }`.
3. Seed one `ContactStatus`: `{ label: "New", color: "#3b82f6" }`.

### Test Cases

#### TC-01: Email Notification → Contact Created

- **Input:** Forward a real Dating.com notification email to the inbound webhook endpoint (`POST /api/notifications/inbound/`).
- **Expected:** 
  - `InboundNotification` row created with `source=dating_com`, `parsed=False`.
  - Parser runs; `Contact` row created with correct `display_name`, `profile_url`, `chat_url`, `external_id`, `source=dating_com`.
  - `ChatThread` and `Message` created if message snippet present.
  - `InboundNotification.parsed` updated to `True`.

#### TC-02: Duplicate Email Notification → Contact Updated, Not Duplicated

- **Input:** Forward the same email twice.
- **Expected:** Only one `Contact` row exists (unique on `source + external_id`). `last_message_at` updated.

#### TC-03: Contact List — Source Filter

- **Input:** Operator opens `/contacts/?source=dating_com`.
- **Expected:** Only Dating.com contacts visible; correct source badge displayed.

#### TC-04: Contact List — Source Filter for RomanceCompass

- **Input:** Operator opens `/contacts/?source=romance_compass`.
- **Expected:** Dating.com contacts do NOT appear; RomanceCompass contacts visible.

#### TC-05: Manual Contact Entry (Backup Plan B)

- **Input:** Operator submits manual form with Dating.com profile URL.
- **Expected:** `external_id` extracted from URL; `Contact` created with `source=dating_com`; profile and chat links correct.

#### TC-06: Malformed Email — Graceful Failure

- **Input:** Forward a non-Dating.com email to the inbound endpoint.
- **Expected:** `InboundNotification` stored; parser raises `ParseError`; contact NOT created; error logged; HTTP 200 returned (so email provider does not retry).

#### TC-07: Security — No Outbound Requests to Dating.com

- **Input:** Any of the above inputs.
- **Expected:** Zero outbound HTTP requests to `dating.com` or any dating platform domain originate from the CRM server during test execution (verified via network mock / test assertions).

---

## Implementation Order

```
Phase 1 — Core scaffolding (unblocks all other work)
  1. Django project setup (settings, DB, auth)
  2. Source, Contact, ContactStatus models + migrations
  3. ChatThread, Message models + migrations
  4. Seed RomanceCompass source row

Phase 2 — RomanceCompass integration (establishes the pattern)
  5. AbstractSource interface
  6. RomanceCompassSource adapter + email parser
  7. Inbound notification webhook endpoint
  8. Contact list / detail templates with source badges

Phase 3 — Dating.com source (mirrors Phase 2)
  9.  DatingComSource adapter
  10. Dating.com email parser
  11. Seed dating_com source row (migration)
  12. Update source registry and templates

Phase 4 — Manual entry fallback
  13. Operator manual contact entry form for dating_com
  14. URL-based external_id extractor

Phase 5 — Testing
  15. Unit tests for parsers (TC-01 through TC-07)
  16. Integration tests for inbound webhook endpoint
```

---

## Notes on What Is Explicitly Out of Scope

- No login to Dating.com or RomanceCompass from the CRM server.
- No HTTP GET/POST to dating platform pages.
- No captcha solving.
- No automated message sending.
- No scraping of user listings or search results.
- No storing of third-party users' personal data beyond what the platform proactively sends the operator in their own notification emails.

---

*This plan must be reviewed and approved before any code is written.*
