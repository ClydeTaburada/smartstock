# SmartStock (PHP + MySQL)

Sales & inventory system for **RF Chein Gadgets** (Bacolod City) — converted from
static HTML mockups into a database-driven PHP app running on XAMPP.

## Quick setup

1. **Put the folder in XAMPP**: `c:\xampp\htdocs\smartstock` (already done).
2. **Start Apache + MySQL** from the XAMPP control panel.
3. **Run the one-click installer**: open <http://localhost/smartstock/setup.php>.
   The page creates the `smartstock` database, all tables, and a minimal test seed in one click.
   _(Alternative: import `database.sql` manually via <http://localhost/phpmyadmin>.)_
4. **Delete `setup.php`** once it reports success (optional but recommended).
5. **Open the app**: <http://localhost/smartstock/>

## Demo accounts (password: `password123`)

| Username     | Role          |
|--------------|---------------|
| `admin`      | Super Admin   |
| `ceo`        | Admin         |
| `jfbusel`    | Supervisor    |
| `adex`       | Staff         |

First sign-in auto-upgrades the stored password to a bcrypt hash.

The SQL seed is intentionally clean: it keeps only branch scaffolding plus these four test credentials. Inventory, sales, inquiries, transfers, and logs start empty.

## Pages

| URL                              | Purpose                                   |
|----------------------------------|-------------------------------------------|
| `index.php`                      | Public storefront — browsable phones       |
| `login.php` / `logout.php`       | Authentication                            |
| `dashboard.php`                  | Staff/Admin dashboard (metrics, sales)     |
| `superadmin.php`                 | Super Admin panel (branches/users/devices) |

## Folder layout

```
smartstock/
├── config/
│   ├── credentials.php     # DB host / name / user / password
│   └── db.php              # PDO connection (uses credentials.php)
├── includes/helpers.php    # Session + helper functions
├── actions/                # POST handlers (CRUD endpoints)
│   ├── add_branch.php
│   ├── delete_branch.php
│   ├── add_user.php
│   ├── delete_user.php
│   ├── add_device.php
│   └── record_sale.php
├── index.php               # Landing / catalog
├── login.php
├── logout.php
├── dashboard.php
├── superadmin.php
└── database.sql            # Schema + seed data (run via setup.php)
```

## Database schema

- **branches** — stores RF Chein branch locations
- **users** — staff accounts with roles: `Super Admin`, `Admin`, `Supervisor`, `Staff` (legacy `Viewer` remains compatible)
- **phones** — device inventory (brand, model, condition, price, stock, branch)
- **sales** — transactions (auto-decrements `phones.stock`)
- **activity_logs** — audit trail written by every action handler

## Changing the MySQL credentials

Edit `config/credentials.php` if your XAMPP MySQL uses a non-default user/password.
