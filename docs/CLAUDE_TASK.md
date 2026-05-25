We are working on an authorized client CRM project.

Context:
This is an existing WordPress-based CRM from crmrc.app. The client paid for the first stage: technical analysis and adding dating.com as an additional safe source for incoming contacts/messages/statuses/links inside the current CRM.

Goal:
Inspect the existing codebase and prepare an implementation plan first. Do not write or change code yet.

Known structure from server inspection:
- Main CRM theme: wp-content/themes/romance-crm
- Important files likely include:
  - wp-content/themes/romance-crm/functions.php
  - wp-content/themes/romance-crm/work-functions.php
  - wp-content/themes/romance-crm/index.php
  - wp-content/themes/romance-crm/assets/js/main.js
- Current source uses login.romancecompass.com endpoints.
- UI contains model page, contact list, chat modal and mailing button.

Important restrictions:
Do not bypass captcha, anti-bot protection, blocks, security restrictions, or third-party platform protections.
Do not implement spam or automated mass outreach.
Do not make outbound scraping requests to dating.com unless an official/allowed integration method is confirmed.
If direct integration is not safely possible, propose a safe alternative: inbound email parsing, manual/semi-automated operator workflow, official API, or operator helper.

Need to understand:
1. current WordPress/theme/plugin structure
2. where models are stored
3. where contacts/chats/statuses are stored
4. how current RomanceCompass logic works
5. what files control the model page, contact list, chat modal and mailing button
6. safest way to add dating.com as a source
7. exact files that need changes
8. backup and rollback plan
9. test plan on one model

Output:
Create IMPLEMENTATION_PLAN.md.

Do not edit application code yet.
Do not create a new CRM from scratch unless the current CRM cannot be extended.
Do not commit secrets, credentials, wp-config.php, database dumps, backups, uploads or cookies.
