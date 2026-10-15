<?php

/*
|--------------------------------------------------------------------------
| SMTP CONFIGURATION
|--------------------------------------------------------------------------
| Fill these in with your real email provider's SMTP details, then
| includes/mailer.php will use them automatically.
|
| OPTION A — Your hosting's own email account (recommended for a live
| site, e.g. support@yourdomain.com created in cPanel's Email Accounts):
|   MAIL_HOST      = mail.yourdomain.com   (check cPanel > Email Accounts > Connect Devices)
|   MAIL_USERNAME  = support@yourdomain.com
|   MAIL_PASSWORD  = that mailbox's password
|   MAIL_PORT      = 587 (STARTTLS) or 465 (SSL) — cPanel shows both
|   MAIL_ENCRYPTION = "tls" for 587, "ssl" for 465
|
| OPTION B — Gmail (fine for testing, not ideal for production volume):
|   MAIL_HOST      = smtp.gmail.com
|   MAIL_USERNAME  = youraddress@gmail.com
|   MAIL_PASSWORD  = a Google "App Password" (NOT your normal Gmail
|                    password — generate one at
|                    https://myaccount.google.com/apppasswords,
|                    requires 2-Step Verification enabled first)
|   MAIL_PORT      = 587
|   MAIL_ENCRYPTION = "tls"
|
| SECURITY: this file holds a real password. Keep it out of version
| control (add it to .gitignore) and never commit it to a public repo.
|--------------------------------------------------------------------------
*/

define("MAIL_HOST", "smtp.gmail.com");
define("MAIL_USERNAME", "hassanrazaq988@gmail.com");
define("MAIL_PASSWORD", "jbow zqbf kfup xkwv");
define("MAIL_PORT", 587);
define("MAIL_ENCRYPTION", "tls"); // "tls" or "ssl"

define("MAIL_FROM_ADDRESS", "hassanrazaq988@gmail.com");
define("MAIL_FROM_NAME", "NexusBids");
