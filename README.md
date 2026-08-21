# Admissions Leads Dashboard

A small, self-hosted lead tracker for school admissions inquiries: one register of
leads, a flexible follow-up timeline per lead (add or remove entries freely),
status tracking (interested / high quality / rejected + reason / etc.), and a
monthly dashboard tracking progress toward your visit and conversion goals.

Built as plain PHP + MySQL so it runs on ordinary shared hosting — no Node,
no build step, no special server access needed. This matches your Namecheap
**Stellar Plus** hosting plan.

---

## 1. Create a subdomain (cPanel)

1. Log into cPanel (from Namecheap: Hosting List → **Go to cPanel**).
2. Under **Domains**, click **Create A New Domain** and add something like
   `leads.innovoosolutions.com` (or `admissions.innovoosolutions.com`).
3. Note the document root it creates, e.g. `/home/<cpaneluser>/leads.innovoosolutions.com`.

## 2. Create the MySQL database

1. In cPanel, open **MySQL Databases**.
2. Create a new database, e.g. `leads` → it becomes `<cpaneluser>_leads`.
3. Create a new database user with a strong password, e.g. `leads_user` →
   becomes `<cpaneluser>_leads_user`.
4. Add that user to the database with **All Privileges**.
5. Open **phpMyAdmin**, select your new database, go to the **Import** tab,
   and upload `sql/schema.sql` from this project. This creates the tables and
   a default login (see step 5).

## 3. Upload the files

1. In cPanel **File Manager**, go to the subdomain's document root folder.
2. Upload and extract this whole project into that folder (everything should
   sit directly inside it — `index.php`, `includes/`, `assets/`, etc.).
3. Rename `config.php.example` to `config.php` and edit it:
   - `DB_NAME` / `DB_USER` / `DB_PASS` → the values from step 2.
   - `SCHOOL_NAME` → the client school's name (shown in the header).
   - `MONTHLY_VISIT_GOAL` / `MONTHLY_CONVERSION_GOAL` → adjust anytime.

## 4. Visit the site

Go to `https://leads.innovoosolutions.com` (or whatever subdomain you chose).
You should land on the login page.

## 5. Log in and change the password

Default login: **username `admin`, password `admin123`**.

Log in once, then in phpMyAdmin run this to set your own password (replace
`YOUR_NEW_PASSWORD`, keep it exactly as shown otherwise):

```sql
-- Generate a hash for your new password using https://www.phpformbuilder.net/tool/bcrypt-password-generator/
-- or ask a developer to run PHP's password_hash('YOUR_NEW_PASSWORD', PASSWORD_DEFAULT)
UPDATE users SET password_hash = 'PASTE_THE_HASH_HERE' WHERE username = 'admin';
```

To create a **read-only login for the client** (they can see everything but
can't edit, delete, or import), insert a second user with `role = 'client'`:

```sql
INSERT INTO users (username, password_hash, display_name, role)
VALUES ('school_client', 'PASTE_A_HASH_HERE', 'School Admin', 'client');
```

## 6. Import your existing sheet

Go to **Import CSV** in the sidebar and upload your current lead-tracking
export (comma or tab separated — Google Sheets exports of either kind work,
including the multi-column "Status - 22nd / Status - 23rd" style you're
using now). You'll be asked to match each column to a field; any status/notes
column can be mapped to **"Turn into a follow-up entry"**, and each filled-in
cell becomes a logged follow-up automatically.

Going forward, add new leads directly with **+ Add lead**, and log each call
or WhatsApp reply on the lead's page — every entry is numbered, timestamped,
and stays fully editable (add, edit, or remove any follow-up at any time).

---

## What this gives you day to day

- **Dashboard** — this month's new inquiries, follow-ups logged, conversions,
  and a visit register that fills in as visits happen toward your goal
  (currently 20/month). A "Needs a follow-up" list surfaces anyone who hasn't
  been contacted in a day or more, so nothing goes cold.
- **Leads** — the full list, filterable by status/source and searchable by
  name, contact, or school.
- **Lead page** — full inquiry details plus a follow-up timeline (1st, 2nd,
  3rd… fully flexible) and one-click status updates (including a rejection
  reason field when marking someone not interested/rejected).
- **Import CSV** — bulk-load from whatever spreadsheet format you're handed.

## Later: Facebook Lead Ads integration

When you're ready to stop entering Facebook leads by hand, Meta's Lead Ads
webhook can POST new leads straight into the `leads` table (source =
`facebook`). That's a small follow-on piece of work — the schema already has
everything it needs (a `source` field and all the same columns), so it's a
matter of adding one endpoint that receives the webhook and inserts a row,
no redesign required.
