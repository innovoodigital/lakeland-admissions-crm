# Local Development

This app is plain PHP + MySQL. No Docker is required.

## Requirements

- PHP 8.x with the `pdo_mysql` extension enabled
- MySQL 8.x, MariaDB, XAMPP, WAMP, or Laragon

This machine has PHP installed at `C:\php\php.exe` and MySQL installed at
`C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe`.

If PHP ever needs to be reinstalled, run:

```bat
powershell -NoProfile -ExecutionPolicy Bypass -File install-php-local.ps1
```

If PHP complains about `VCRUNTIME140.dll`, update the Microsoft runtime:

```bat
powershell -NoProfile -ExecutionPolicy Bypass -File install-vc-redist.ps1
```

## First-Time Database Setup

Make sure your local MySQL service is running, then run:

```bat
setup-local-db.bat
```

It creates a local database named `lakeland_crm`, imports `sql/schema.sql`, and
then imports the sample leads from `sql/seed_leads.sql`.

## Run The App

After PHP is installed, run:

```bat
start-local.bat
```

Open:

- App: http://localhost:8000

Default app login:

- Username: `admin`
- Password: `admin123`

## Local Configuration

`start-local.bat` creates `config.local.php` from `config.local.example.php` if
it does not exist.

Default local settings:

- Host: `127.0.0.1`
- Database: `lakeland_crm`
- User: `root`
- Password: blank

If your local MySQL uses a password or another user, edit `config.local.php`.
The hosted cPanel values in `config.php` still work when `config.local.php` is
not present.
