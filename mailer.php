<?php
/**
 * Shared mail helper.
 * Usage:  require_once __DIR__ . '/mailer.php';
 *         send_app_mail('to@example.com', 'Name', 'Subject', '<h1>HTML</h1>');
 *
 * SMTP credentials are read from the `smtp_email` table (single row).
 */

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ---------------------------------------------------------------------------
 * TEMPORARILY DISABLED - SMTP sending is switched OFF for now.
 *
 * Reason: without valid SMTP credentials the SMTP connect attempt hangs the
 * request (mail.hostinger.com:465 is unreachable from this network), which
 * made abstract submit / review / bulk actions appear to "take forever".
 *
 * No mail is sent while this is false; callers continue normally (they already
 * ignore the result). Flip this to true once a working SMTP account is stored
 * in the `smtp_email` table.
 * ------------------------------------------------------------------------- */
if (!defined('APP_MAIL_ENABLED')) {
    define('APP_MAIL_ENABLED', false);
}

if (!function_exists('app_smtp_config')) {
    function app_smtp_config($db)
    {
        $cfg = [
            'from_email' => '',
            'password'   => '',
            'host'       => '',
            'port'       => 465,
        ];

        $res = mysqli_query($db, "SELECT from_email, password, host, port FROM smtp_email ORDER BY smtp_id ASC LIMIT 1");
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $cfg['from_email'] = $row['from_email'];
            $cfg['password']   = $row['password'];
            $cfg['host']       = $row['host'];
            $cfg['port']       = (int) $row['port'];
        }

        return $cfg;
    }
}

if (!function_exists('send_app_mail')) {
    /**
     * Send an HTML email via the configured SMTP account.
     *
     * @param mysqli $db        Connection (used to read smtp_email)
     * @param string $to        Recipient email
     * @param string $toName    Recipient display name
     * @param string $subject   Subject
     * @param string $htmlBody  HTML body
     * @param string $fromName  Sender display name
     * @return array            ['ok' => bool, 'error' => string]
     */
    function send_app_mail($db, $to, $toName, $subject, $htmlBody, $fromName = 'APAPU India 2027')
    {
        // SMTP sending temporarily disabled (see APP_MAIL_ENABLED above).
        if (!APP_MAIL_ENABLED) {
            return ['ok' => false, 'error' => 'Mail sending is temporarily disabled.'];
        }

        $cfg = app_smtp_config($db);

        if ($cfg['from_email'] === '' || $cfg['host'] === '') {
            return ['ok' => false, 'error' => 'SMTP is not configured.'];
        }

        $mail = new PHPMailer(true);
        try {
            $mail->Timeout    = 20;
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['from_email'];
            $mail->Password   = $cfg['password'];
            $mail->Port       = $cfg['port'];
            $mail->SMTPAutoTLS = true;

            if ($cfg['port'] === 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($cfg['port'] === 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($cfg['from_email'], $fromName);
            $mail->addAddress($to, $toName);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));

            $mail->send();
            return ['ok' => true, 'error' => ''];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => $mail->ErrorInfo];
        }
    }
}

if (!function_exists('abstract_email_wrapper')) {
    function abstract_email_wrapper($heading, $bodyHtml)
    {
        return "
        <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;border:1px solid #e6e6e6;border-radius:8px;overflow:hidden;'>
            <div style='background:#00349a;padding:18px 24px;color:#fff;'>
                <h2 style='margin:0;font-size:18px;'>APAPU India 2027 &mdash; Abstract System</h2>
            </div>
            <div style='padding:24px;color:#333;font-size:14px;line-height:1.6;'>
                <h3 style='margin-top:0;color:#00349a;'>{$heading}</h3>
                {$bodyHtml}
            </div>
            <div style='background:#f5f6f8;padding:14px 24px;color:#888;font-size:12px;'>
                This is an automated message. Please do not reply directly.
            </div>
        </div>";
    }
}
