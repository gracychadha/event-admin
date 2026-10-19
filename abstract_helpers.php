<?php
/**
 * Abstract workflow helpers.
 * Requires an active mysqli connection in $db and (for mail) mailer.php.
 */

if (!function_exists('abstract_status_labels')) {
    function abstract_status_labels()
    {
        return [
            'submitted'           => ['label' => 'Submitted (Awaiting Review)', 'class' => 'badge bg-info'],
            'resubmitted'         => ['label' => 'Resubmitted (Awaiting Review)', 'class' => 'badge bg-info'],
            'reviewer_accepted'   => ['label' => 'Reviewer Accepted', 'class' => 'badge bg-primary'],
            'reviewer_rejected'   => ['label' => 'Reviewer Rejected', 'class' => 'badge bg-danger'],
            'sent_back'           => ['label' => 'Sent Back for Revision', 'class' => 'badge bg-warning text-dark'],
            'organizer_accepted'  => ['label' => 'Accepted', 'class' => 'badge bg-success'],
            'organizer_rejected'  => ['label' => 'Rejected', 'class' => 'badge bg-danger'],
        ];
    }
}

if (!function_exists('abstract_status_label')) {
    function abstract_status_label($status)
    {
        $map = abstract_status_labels();
        return $map[$status] ?? ['label' => ucwords(str_replace('_', ' ', (string) $status)), 'class' => 'badge bg-secondary'];
    }
}

if (!function_exists('abstract_log')) {
    function abstract_log($db, $submissionId, $actorType, $actorId, $actorName, $action, $remarks = null)
    {
        $stmt = $db->prepare(
            "INSERT INTO abstract_logs (submission_id, actor_type, actor_id, actor_name, action, remarks)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("isisss", $submissionId, $actorType, $actorId, $actorName, $action, $remarks);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('abstract_find_reviewer')) {
    /**
     * Find a reviewer assigned to the given category + theme.
     * Returns admin _id or null.
     */
    function abstract_find_reviewer($db, $categoryId, $themeId)
    {
        // Prefer exact category+theme match, fall back to any row for the category.
        $stmt = $db->prepare(
            "SELECT reviewer_id FROM abstract_reviewer_assignments
             WHERE category_id = ? AND theme_id = ? AND status = '1'
             ORDER BY id ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("ii", $categoryId, $themeId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return (int) $row['reviewer_id'];
            }
            $stmt->close();
        }

        $stmt = $db->prepare(
            "SELECT reviewer_id FROM abstract_reviewer_assignments
             WHERE category_id = ? AND status = '1'
             ORDER BY id ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("i", $categoryId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $stmt->close();
                return (int) $row['reviewer_id'];
            }
            $stmt->close();
        }

        return null;
    }
}

if (!function_exists('abstract_reroute_pending')) {
    /**
     * Re-route all pending abstracts to a reviewer based on current assignments.
     * Pending = submitted / resubmitted / sent_to_reviewer.
     */
    function abstract_reroute_pending($db)
    {
        $res = mysqli_query($db, "SELECT id, category_id, theme_id
                                  FROM abstract_submissions
                                  WHERE status IN ('submitted','resubmitted','sent_to_reviewer')");
        if (!$res) {
            return;
        }
        $stmt = $db->prepare("UPDATE abstract_submissions SET reviewer_id = ? WHERE id = ?");
        if (!$stmt) {
            return;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            $rid = abstract_find_reviewer($db, (int) $row['category_id'], (int) $row['theme_id']);
            if (!$rid) {
                $rid = null;
            }
            $sid = (int) $row['id'];
            $stmt->bind_param("ii", $rid, $sid);
            $stmt->execute();
        }
        $stmt->close();
    }
}

if (!function_exists('abstract_fetch_submission_full')) {
    function abstract_fetch_submission_full($db, $id)
    {
        $sql = "SELECT s.*,
                       c.name AS category_name,
                       t.name AS theme_name,
                       CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS author_name,
                       u.email AS author_email
                FROM abstract_submissions s
                LEFT JOIN abstract_category c ON c.id = s.category_id
                LEFT JOIN abstract_theme t ON t.id = s.theme_id
                LEFT JOIN registered_users u ON u.student_id = s.user_id
                WHERE s.id = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('abstract_admin_name')) {
    function abstract_admin_name($db, $adminId)
    {
        $stmt = $db->prepare("SELECT username FROM admin WHERE _id = ? LIMIT 1");
        if (!$stmt) {
            return 'Admin';
        }
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row['username'] ?? 'Admin';
    }
}

if (!function_exists('abstract_admin_email')) {
    function abstract_admin_email($db, $adminId)
    {
        $stmt = $db->prepare("SELECT email FROM admin WHERE _id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row['email'] ?? '';
    }
}

if (!function_exists('abstract_notify')) {
    /**
     * Send a templated notification email about a submission.
     * $template: received|reviewer_accepted|reviewer_rejected|sent_back|organizer_accepted|organizer_rejected|category_updated|sent_to_reviewer
     */
    function abstract_notify($db, $template, $submission, $extra = [])
    {
        if (!function_exists('send_app_mail')) {
            require_once __DIR__ . '/mailer.php';
        }

        $to    = $extra['to'] ?? ($submission['author_email'] ?? '');
        $name  = trim($extra['to_name'] ?? ($submission['author_name'] ?? 'Author'));
        if ($to === '') {
            return ['ok' => false, 'error' => 'No recipient email.'];
        }

        $title = htmlspecialchars($submission['title'] ?? '');
        $cat   = htmlspecialchars($submission['category_name'] ?? '');
        $theme = htmlspecialchars($submission['theme_name'] ?? '');
        $link  = "<p>Please log in to your APAPU India 2027 account and open <strong>My Abstracts</strong> to view details.</p>";

        switch ($template) {
            case 'received':
                $subject = 'Abstract Received - ' . ($submission['title'] ?? '');
                $heading = 'Abstract Submitted Successfully';
                $body = "<p>Dear {$name},</p>
                         <p>We have received your abstract submission:</p>
                         <p><strong>Title:</strong> {$title}<br>
                            <strong>Category:</strong> {$cat}<br>
                            <strong>Theme:</strong> {$theme}</p>
                         <p>It is now pending review. You will be notified of the outcome.</p>{$link}";
                break;
            case 'reviewer_accepted':
                $score = (int) ($extra['score'] ?? 0);
                $subject = 'Abstract Under Organizer Review - ' . ($submission['title'] ?? '');
                $heading = 'Reviewer Accepted Your Abstract';
                $body = "<p>Dear {$name},</p>
                         <p>Your abstract <strong>{$title}</strong> passed the reviewer stage with a score of <strong>{$score}/10</strong>.</p>
                         <p>It has been forwarded to the organizers for a final decision.</p>{$link}";
                break;
            case 'reviewer_rejected':
                $rem = nl2br(htmlspecialchars($extra['remark'] ?? ''));
                $subject = 'Abstract Update - ' . ($submission['title'] ?? '');
                $heading = 'Abstract Not Accepted at Review Stage';
                $body = "<p>Dear {$name},</p>
                         <p>After review, your abstract <strong>{$title}</strong> was not accepted.</p>
                         <p><strong>Reviewer remarks:</strong><br>{$rem}</p>
                         <p>You may revise and resubmit from your dashboard.</p>{$link}";
                break;
            case 'sent_back':
                $reason = nl2br(htmlspecialchars($extra['reason'] ?? ''));
                $subject = 'Revision Requested - ' . ($submission['title'] ?? '');
                $heading = 'Your Abstract Needs Revision';
                $body = "<p>Dear {$name},</p>
                         <p>The organizers have requested a revision of your abstract <strong>{$title}</strong>.</p>
                         <p><strong>Remarks:</strong><br>{$reason}</p>
                         <p>Please revise and resubmit from <strong>My Abstracts</strong>.</p>{$link}";
                break;
            case 'organizer_accepted':
                $subject = 'Abstract Accepted - ' . ($submission['title'] ?? '');
                $heading = 'Congratulations - Abstract Accepted';
                $body = "<p>Dear {$name},</p>
                         <p>We are pleased to inform you that your abstract <strong>{$title}</strong> has been <strong>accepted</strong> for APAPU India 2027.</p>{$link}";
                break;
            case 'organizer_rejected':
                $rem = nl2br(htmlspecialchars($extra['remark'] ?? ''));
                $subject = 'Abstract Decision - ' . ($submission['title'] ?? '');
                $heading = 'Abstract Not Accepted';
                $body = "<p>Dear {$name},</p>
                         <p>After final review, your abstract <strong>{$title}</strong> was not accepted.</p>
                         <p><strong>Remarks:</strong><br>{$rem}</p>{$link}";
                break;
            case 'category_updated':
                $subject = 'Abstract Category Update - ' . ($submission['title'] ?? '');
                $heading = 'Your Abstract Has Been Selected For a Category';
                $body = "<p>Dear {$name},</p>
                         <p>Your abstract <strong>{$title}</strong> has been selected for the following category:</p>
                         <p><strong>Category:</strong> {$cat}<br>
                            <strong>Theme:</strong> {$theme}</p>
                         <p>You can view the details under <strong>My Abstracts</strong>.</p>{$link}";
                break;
            case 'sent_to_reviewer':
                $reason = nl2br(htmlspecialchars($extra['reason'] ?? ''));
                $subject = 'Abstract Returned for Correction - ' . ($submission['title'] ?? '');
                $heading = 'Abstract Returned to You for Re-review';
                $body = "<p>Dear {$name},</p>
                         <p>The organizers have returned abstract <strong>{$title}</strong> ({$cat} / {$theme}) to you for corrections or re-review.</p>
                         <p><strong>Organizer remarks:</strong><br>{$reason}</p>
                         <p>Please open <strong>My Review Queue</strong> in the admin panel to review it again.</p>";
                break;
            default:
                return ['ok' => false, 'error' => 'Unknown template.'];
        }

        $html = abstract_email_wrapper($heading, $body);
        return send_app_mail($db, $to, $name, $subject, $html);
    }
}

if (!function_exists('abstract_csrf_token')) {
    /**
     * Return (and lazily create) the per-session CSRF token.
     */
    function abstract_csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['abstract_csrf'])) {
            $_SESSION['abstract_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['abstract_csrf'];
    }
}

if (!function_exists('abstract_csrf_check')) {
    function abstract_csrf_check($token)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['abstract_csrf'])
            && is_string($token)
            && hash_equals($_SESSION['abstract_csrf'], $token);
    }
}

if (!function_exists('abstract_decide_submission')) {
    /**
     * Record an organizer decision on a submission.
     * $decision: 'accept' -> organizer_accepted, anything else -> organizer_rejected.
     * Only abstracts currently in 'reviewer_accepted' may be decided.
     * Returns true when applied, false when not actionable.
     */
    function abstract_decide_submission($db, $id, $decision, $adminId, $remark = '')
    {
        $id      = (int) $id;
        $adminId = (int) $adminId;
        if ($id <= 0) {
            return false;
        }

        $isAccept = ($decision === 'accept');
        $status   = $isAccept ? 'organizer_accepted' : 'organizer_rejected';
        $template = $isAccept ? 'organizer_accepted' : 'organizer_rejected';

        // State guard so a stale form cannot overwrite a later decision.
        $check = $db->prepare("SELECT id FROM abstract_submissions
                               WHERE id = ? AND status = 'reviewer_accepted' LIMIT 1");
        if (!$check) {
            return false;
        }
        $check->bind_param("i", $id);
        $check->execute();
        $check->store_result();
        $actionable = $check->num_rows > 0;
        $check->close();
        if (!$actionable) {
            return false;
        }

        $stmt = $db->prepare("UPDATE abstract_submissions
            SET status = ?, organizer_id = ?, organizer_remark = ?, organizer_decided_at = NOW()
            WHERE id = ? AND status = 'reviewer_accepted'");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("sisi", $status, $adminId, $remark, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) {
            return false;
        }

        abstract_log($db, $id, 'organizer', $adminId, abstract_admin_name($db, $adminId), $status, $remark);
        abstract_notify($db, $template, abstract_fetch_submission_full($db, $id), ['remark' => $remark]);

        return true;
    }
}

if (!function_exists('abstract_bulk_decide')) {
    /**
     * Apply the same decision to many submissions.
     * Returns ['processed' => n, 'skipped' => m].
     */
    function abstract_bulk_decide($db, array $ids, $decision, $adminId, $remark = '')
    {
        $processed = 0;
        $skipped   = 0;
        foreach ($ids as $id) {
            if (abstract_decide_submission($db, (int) $id, $decision, $adminId, $remark)) {
                $processed++;
            } else {
                $skipped++;
            }
        }
        return ['processed' => $processed, 'skipped' => $skipped];
    }
}

/* -------------------------------------------------------------------------
 * SweetAlert2 presentation helpers (UI only - no workflow logic).
 * ---------------------------------------------------------------------- */

if (!function_exists('abstract_swal_color')) {
    function abstract_swal_color($icon)
    {
        switch ($icon) {
            case 'success': return '#198754';
            case 'error':   return '#dc3545';
            case 'warning': return '#ffc107';
            default:        return '#0d6efd';
        }
    }
}

if (!function_exists('abstract_swal_css')) {
    function abstract_swal_css()
    {
        return '<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.css" rel="stylesheet">';
    }
}

if (!function_exists('abstract_swal_js')) {
    function abstract_swal_js()
    {
        return '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.js"></script>';
    }
}

if (!function_exists('abstract_swal_flash')) {
    /**
     * Queue a SweetAlert2 notification for the current/near-future request.
     */
    function abstract_swal_flash($icon, $title, $text = '')
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['abstract_swal'] = [
            'icon'  => $icon,
            'title' => $title,
            'text'  => (string) $text,
            'color' => abstract_swal_color($icon),
        ];
    }
}

if (!function_exists('abstract_swal_render')) {
    /**
     * Output any queued SweetAlert2 notification. Call after the Swal library
     * script tag has been emitted.
     */
    function abstract_swal_render()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['abstract_swal'])) {
            return;
        }
        $m = $_SESSION['abstract_swal'];
        unset($_SESSION['abstract_swal']);
        $opts = json_encode([
            'icon'               => $m['icon'] ?? 'info',
            'title'              => $m['title'] ?? '',
            'text'               => $m['text'] ?? '',
            'confirmButtonColor' => $m['color'] ?? '#0d6efd',
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        echo "<script>if(window.Swal){Swal.fire({$opts});}</script>";
    }
}

if (!function_exists('abstract_swal_page')) {
    /**
     * Standalone SweetAlert2 responder for early-exit code paths (no output yet).
     * Shows the alert, then optionally redirects / goes back. Always exits.
     *
     * @param string      $icon     success|error|warning|info|question
     * @param string      $title
     * @param string      $text
     * @param string|null $redirect URL to navigate to after the alert (null = none)
     * @param bool        $back     Use history.back() when no redirect is given
     */
    function abstract_swal_page($icon, $title, $text = '', $redirect = null, $back = false)
    {
        $opts = json_encode([
            'icon'               => $icon,
            'title'              => $title,
            'text'               => (string) $text,
            'confirmButtonColor' => abstract_swal_color($icon),
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        if ($redirect !== null) {
            $after = 'window.location.href=' . json_encode($redirect, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';';
        } elseif ($back) {
            $after = 'window.history.back();';
        } else {
            $after = '';
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Notification</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.css" rel="stylesheet">'
            . '</head><body>'
            . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.0/dist/sweetalert2.min.js"></script>'
            . "<script>Swal.fire({$opts}).then(function(){ {$after} });</script>"
            . '</body></html>';
        exit;
    }
}
