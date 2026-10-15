<?php

/*
|--------------------------------------------------------------------------
| Mailer — sends real email via SMTP using PHPMailer
|--------------------------------------------------------------------------
| YOU STILL NEED TO DO TWO THINGS FOR THIS TO ACTUALLY SEND EMAIL:
|
| 1. Install PHPMailer. Two ways — pick whichever your hosting supports:
|
|    OPTION A — Composer (if your host gives you SSH/terminal access):
|        composer require phpmailer/phpmailer
|      This creates /vendor/autoload.php, which this file will detect
|      and use automatically.
|
|    OPTION B — Manual install (works on any shared host, no SSH needed):
|        1. Go to https://github.com/PHPMailer/PHPMailer
|        2. Click "Code" -> "Download ZIP"
|        3. Open the zip, find the "src" folder inside it
|        4. Upload that "src" folder into your project as:
|               libs/PHPMailer/src/
|           so you end up with:
|               libs/PHPMailer/src/PHPMailer.php
|               libs/PHPMailer/src/SMTP.php
|               libs/PHPMailer/src/Exception.php
|      This file will detect and use those directly - no Composer needed.
|
| 2. Fill in your real SMTP details in config/mail-config.php.
|
| Until both are done, this quietly falls back to PHP's mail() (which
| usually does nothing useful on shared hosts) and logs a note via
| error_log() so you can see what's happening while testing.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/../config/mail-config.php";

function nb_load_phpmailer()
{
    // Option A: Composer install
    $composer_autoload = __DIR__ . "/../vendor/autoload.php";

    if (file_exists($composer_autoload)) {
        require_once $composer_autoload;
        return class_exists("PHPMailer\\PHPMailer\\PHPMailer");
    }

    // Option B: manually-downloaded PHPMailer in /libs/PHPMailer/src/
    $manual_path = __DIR__ . "/../libs/PHPMailer/src/";

    if (file_exists($manual_path . "PHPMailer.php")) {
        require_once $manual_path . "Exception.php";
        require_once $manual_path . "PHPMailer.php";
        require_once $manual_path . "SMTP.php";
        return class_exists("PHPMailer\\PHPMailer\\PHPMailer");
    }

    return false;
}

function nb_send_mail($to, $subject, $html_body)
{
    if (nb_load_phpmailer()) {

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host = MAIL_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = MAIL_USERNAME;
            $mail->Password = MAIL_PASSWORD;
            $mail->SMTPSecure = MAIL_ENCRYPTION;
            $mail->Port = MAIL_PORT;

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_body;

            $mail->send();

            return true;

        } catch (PHPMailer\PHPMailer\Exception $e) {

            error_log(
                "nb_send_mail() PHPMailer error sending to $to: " . $mail->ErrorInfo
            );

            return false;
        }
    }

    // PHPMailer isn't installed yet - fall back to PHP's mail(), which
    // rarely works without extra server config, but at least doesn't
    // break the registration/reset flow while you finish setup.
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_ADDRESS . ">\r\n";

    $sent = @mail($to, $subject, $html_body, $headers);

    if (!$sent) {
        error_log(
            "nb_send_mail() could not send to $to - PHPMailer isn't installed yet " .
            "(see the instructions at the top of includes/mailer.php). Subject: $subject"
        );
    }

    return $sent;
}
