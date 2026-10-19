<?php
/**
 * Abstract workflow DEMO seed.
 * Creates demo categories, themes, reviewers, organizers, authors,
 * reviewer mappings, and abstracts across every status, with audit logs
 * and downloadable placeholder PDFs.
 *
 * Safe to re-run: it first clears the abstract workflow tables + demo users.
 * Run:  php db/abstract_demo_seed.php
 */

require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function demo_pdf($text)
{
    $objs = [];
    $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objs[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objs[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 240] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>";
    $stream = "BT /F1 12 Tf 24 200 Td (APAPU India 2027 - Abstract Demo) Tj 0 -20 Td (" . addcslashes($text, "()\\") . ") Tj ET";
    $objs[4] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
    $objs[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $off = [];
    for ($i = 1; $i <= 5; $i++) {
        $off[$i] = strlen($pdf);
        $pdf .= "$i 0 obj\n" . $objs[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}

$uploadDir = realpath(__DIR__ . '/../../apapuindia2027/uploads/abstracts');
if ($uploadDir === false) {
    $uploadDir = __DIR__ . '/../../apapuindia2027/uploads/abstracts';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
}

// ---------- Clean slate for workflow + demo users ----------
$db->query("DELETE FROM abstract_logs");
$db->query("DELETE FROM abstract_submissions");
$db->query("DELETE FROM abstract_reviewer_assignments");
$db->query("DELETE FROM abstract_category");
$db->query("DELETE FROM abstract_theme");
$db->query("DELETE FROM admin WHERE username IN ('reviewer1','reviewer2','organizer1')");
$db->query("DELETE FROM registered_users WHERE email LIKE '%@demo.test'");

// ---------- Categories ----------
$categories = [
    1 => 'Oral Presentation',
    2 => 'Poster Presentation',
    3 => 'Workshop',
    4 => 'Panel Discussion',
];
$db->query("ALTER TABLE abstract_category AUTO_INCREMENT = 1");
foreach ($categories as $id => $name) {
    $stmt = $db->prepare("INSERT INTO abstract_category (id, name, status) VALUES (?, ?, '1')");
    $stmt->bind_param("is", $id, $name);
    $stmt->execute();
    $stmt->close();
}

// ---------- Themes ----------
$themes = [
    1 => 'Public Health & Epidemiology',
    2 => 'Maternal & Child Health',
    3 => 'Non-Communicable Diseases',
    4 => 'Health Systems & Policy',
    5 => 'Digital Health & Innovation',
];
$db->query("ALTER TABLE abstract_theme AUTO_INCREMENT = 1");
foreach ($themes as $id => $name) {
    $stmt = $db->prepare("INSERT INTO abstract_theme (id, name, status) VALUES (?, ?, '1')");
    $stmt->bind_param("is", $id, $name);
    $stmt->execute();
    $stmt->close();
}

// ---------- Admin accounts ----------
function add_admin($db, $username, $password, $roleId)
{
    $hash = md5($password);
    $email = $username . '@demo.test';
    $stmt = $db->prepare("INSERT INTO admin (username, password, email, status, history, type, mobile, Staff_Email, activation_token, admin_role, employee_id)
                          VALUES (?, ?, ?, '1', '', 'admin', '9000000000', ?, '', ?, 0)");
    $stmt->bind_param("ssssi", $username, $hash, $email, $email, $roleId);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

$rev1 = add_admin($db, 'reviewer1', 'review123', 10);
$rev2 = add_admin($db, 'reviewer2', 'review123', 10);
$org1 = add_admin($db, 'organizer1', 'org123', 11);
echo "Admins: reviewer1=$rev1 reviewer2=$rev2 organizer1=$org1\n";

// ---------- Authors ----------
function add_author($db, $email, $first, $last)
{
    $image = 'assets/img/team/default-avatar.png';
    $pass  = password_hash('secret123', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO registered_users (image, first_name, last_name, contact_number, email, password, status)
                          VALUES (?, ?, ?, '9800000000', ?, ?, '1')");
    $stmt->bind_param("sssss", $image, $first, $last, $email, $pass);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

$a1 = add_author($db, 'ananya@demo.test', 'Ananya', 'Rao');
$a2 = add_author($db, 'vikram@demo.test', 'Vikram', 'Shah');
$a3 = add_author($db, 'meera@demo.test', 'Meera', 'Nair');
echo "Authors: ananya=$a1 vikram=$a2 meera=$a3\n";

// ---------- Reviewer assignments (category + theme) ----------
$assign = [
    [$rev1, 1, 1], [$rev1, 1, 3], [$rev1, 2, 1],
    [$rev2, 1, 2], [$rev2, 1, 5], [$rev2, 2, 4],
];
foreach ($assign as $a) {
    $stmt = $db->prepare("INSERT INTO abstract_reviewer_assignments (reviewer_id, category_id, theme_id, status) VALUES (?, ?, ?, '1')");
    $stmt->bind_param("iii", $a[0], $a[1], $a[2]);
    $stmt->execute();
    $stmt->close();
}

// ---------- Submissions ----------
function add_submission($db, $d)
{
    $stmt = $db->prepare("INSERT INTO abstract_submissions
        (user_id, category_id, theme_id, title, background, keywords, presenting_author, co_authors,
         file_path, status, reviewer_id, reviewer_score, reviewer_remark, reviewed_at,
         organizer_id, organizer_remark, organizer_decided_at, sent_back_reason, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "iiisssssssiississss",
        $d['user_id'], $d['category_id'], $d['theme_id'], $d['title'], $d['background'],
        $d['keywords'], $d['presenting_author'], $d['co_authors'], $d['file_path'], $d['status'],
        $d['reviewer_id'], $d['reviewer_score'], $d['reviewer_remark'], $d['reviewed_at'],
        $d['organizer_id'], $d['organizer_remark'], $d['organizer_decided_at'], $d['sent_back_reason'],
        $d['created_at']
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    return $id;
}

function add_log($db, $sid, $type, $actorId, $actorName, $action, $remarks)
{
    $stmt = $db->prepare("INSERT INTO abstract_logs (submission_id, actor_type, actor_id, actor_name, action, remarks) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssss", $sid, $type, $actorId, $actorName, $action, $remarks);
    $stmt->execute();
    $stmt->close();
}

$rows = [
    // --- reviewer1: pending ---
    [
        'user_id' => $a1, 'category_id' => 1, 'theme_id' => 3,
        'title' => 'Prevalence of Type 2 Diabetes Mellitus in Urban Slum Populations',
        'background' => 'Background: Diabetes burden is rising in urban poor settlements. Methods: A cross-sectional survey of 1,200 adults across 6 slum clusters. Results: Overall prevalence was 14.2%, higher in women. Conclusion: Targeted screening is urgently needed.',
        'keywords' => 'diabetes, urban health, screening', 'presenting_author' => 'Dr. Ananya Rao',
        'co_authors' => 'R. Iyer, S. Menon', 'file_path' => null,
        'status' => 'submitted', 'reviewer_id' => $rev1, 'reviewer_score' => null,
        'reviewer_remark' => null, 'reviewed_at' => null, 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-11 09:15:00',
    ],
    [
        'user_id' => $a1, 'category_id' => 1, 'theme_id' => 1,
        'title' => 'Community-Based Screening for Hypertension: A Pilot Study',
        'background' => 'Background: Early detection of hypertension reduces stroke burden. Methods: Door-to-door screening in 3 villages using validated BP devices. Results: 22% newly detected. Conclusion: Community screening is feasible.',
        'keywords' => 'hypertension, community screening', 'presenting_author' => 'Dr. Ananya Rao',
        'co_authors' => '', 'file_path' => null,
        'status' => 'resubmitted', 'reviewer_id' => $rev1, 'reviewer_score' => 7,
        'reviewer_remark' => 'Acceptable after minor revisions.',
        'reviewed_at' => '2026-10-06 09:00:00', 'organizer_id' => $org1, 'organizer_remark' => null,
        'organizer_decided_at' => '2026-10-08 12:00:00',
        'sent_back_reason' => 'Please clarify the sampling strategy and add confidence intervals.',
        'created_at' => '2026-10-02 11:00:00',
    ],
    // --- reviewer1: reviewed by me ---
    [
        'user_id' => $a1, 'category_id' => 2, 'theme_id' => 1,
        'title' => 'Sleep Quality and Academic Performance Among Medical Students',
        'background' => 'Background: Poor sleep is common in medical training. Methods: Cross-sectional survey of 350 students using the PSQI. Results: 41% had poor sleep quality, associated with lower scores. Conclusion: Sleep hygiene programmes may help.',
        'keywords' => 'sleep, medical students, PSQI', 'presenting_author' => 'Dr. Ananya Rao',
        'co_authors' => 'R. Iyer', 'file_path' => null,
        'status' => 'reviewer_accepted', 'reviewer_id' => $rev1, 'reviewer_score' => 8,
        'reviewer_remark' => 'Well-designed study with clear methodology. Recommend acceptance.',
        'reviewed_at' => '2026-10-08 11:20:00', 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-05 09:15:00',
    ],
    [
        'user_id' => $a1, 'category_id' => 2, 'theme_id' => 1,
        'title' => 'Knowledge, Attitude and Practice Towards Dengue Prevention',
        'background' => 'Background: Dengue outbreaks recur seasonally. Methods: KAP questionnaire among 400 households. Results: Knowledge high but practice poor. Conclusion: Behaviour change campaigns needed.',
        'keywords' => 'dengue, KAP', 'presenting_author' => 'Dr. Ananya Rao',
        'co_authors' => '', 'file_path' => null,
        'status' => 'reviewer_rejected', 'reviewer_id' => $rev1, 'reviewer_score' => null,
        'reviewer_remark' => 'Limited novelty and small sample size; results are largely confirmatory.',
        'reviewed_at' => '2026-10-09 14:05:00', 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-06 10:00:00',
    ],
    // --- reviewer2: pending ---
    [
        'user_id' => $a2, 'category_id' => 1, 'theme_id' => 2,
        'title' => 'Telemedicine Adoption in Rural Primary Health Centres',
        'background' => 'Background: Telemedicine can bridge rural access gaps. Methods: Mixed-methods evaluation of 10 PHCs. Results: Adoption improved follow-up rates by 31%. Conclusion: Scale-up is recommended.',
        'keywords' => 'telemedicine, rural health, digital', 'presenting_author' => 'Dr. Vikram Shah',
        'co_authors' => 'P. Das', 'file_path' => null,
        'status' => 'submitted', 'reviewer_id' => $rev2, 'reviewer_score' => null,
        'reviewer_remark' => null, 'reviewed_at' => null, 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-11 08:30:00',
    ],
    [
        'user_id' => $a2, 'category_id' => 1, 'theme_id' => 5,
        'title' => 'Impact of Air Pollution on Respiratory Morbidity in Children',
        'background' => 'Background: Air quality is deteriorating in cities. Methods: Time-series analysis of hospital admissions. Results: A 10 ug/m3 rise in PM2.5 increased admissions by 6%. Conclusion: Stricter air quality standards are needed.',
        'keywords' => 'air pollution, respiratory, children', 'presenting_author' => 'Dr. Vikram Shah',
        'co_authors' => '', 'file_path' => null,
        'status' => 'resubmitted', 'reviewer_id' => $rev2, 'reviewer_score' => 6,
        'reviewer_remark' => 'Sound methods; needs clearer limitations.',
        'reviewed_at' => '2026-10-05 10:00:00', 'organizer_id' => $org1, 'organizer_remark' => null,
        'organizer_decided_at' => '2026-10-07 13:30:00',
        'sent_back_reason' => 'Please expand the methodology and add a limitations section.',
        'created_at' => '2026-09-30 13:00:00',
    ],
    // --- reviewer2: reviewed by me ---
    [
        'user_id' => $a2, 'category_id' => 2, 'theme_id' => 4,
        'title' => 'Hand Hygiene Compliance Among Nursing Staff in Tertiary Care',
        'background' => 'Background: Hand hygiene is the cornerstone of infection control. Methods: Direct observation of 300 opportunities. Results: Compliance 58%. Conclusion: Training and audits required.',
        'keywords' => 'hand hygiene, infection control', 'presenting_author' => 'Dr. Vikram Shah',
        'co_authors' => '', 'file_path' => null,
        'status' => 'reviewer_accepted', 'reviewer_id' => $rev2, 'reviewer_score' => 9,
        'reviewer_remark' => 'Excellent work with robust methodology.',
        'reviewed_at' => '2026-10-04 12:00:00', 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-01 08:30:00',
    ],
    [
        'user_id' => $a3, 'category_id' => 1, 'theme_id' => 2,
        'title' => 'Dietary Diversity and Anaemia in Adolescent Girls',
        'background' => 'Background: Anaemia remains a major adolescent health issue. Methods: Cross-sectional study of 500 girls. Results: Low dietary diversity was strongly associated with anaemia. Conclusion: Nutrition education and supplementation are needed.',
        'keywords' => 'anaemia, dietary diversity, adolescents', 'presenting_author' => 'Dr. Meera Nair',
        'co_authors' => 'K. Pillai', 'file_path' => null,
        'status' => 'reviewer_rejected', 'reviewer_id' => $rev2, 'reviewer_score' => null,
        'reviewer_remark' => 'Sample not representative; findings need stronger evidence.',
        'reviewed_at' => '2026-10-04 09:10:00', 'organizer_id' => null, 'organizer_remark' => null,
        'organizer_decided_at' => null, 'sent_back_reason' => null, 'created_at' => '2026-10-02 14:00:00',
    ],
    // --- organizer-decided (reviewer history) ---
    [
        'user_id' => $a3, 'category_id' => 1, 'theme_id' => 5,
        'title' => 'Hands-on Workshop: Building Low-Cost Health Dashboards',
        'background' => 'Background: Data literacy is low among frontline staff. Methods: 2-day workshop with 40 participants. Results: Post-test scores doubled. Conclusion: Workshop format is effective.',
        'keywords' => 'workshop, dashboards, capacity building', 'presenting_author' => 'Dr. Meera Nair',
        'co_authors' => '', 'file_path' => null,
        'status' => 'organizer_accepted', 'reviewer_id' => $rev2, 'reviewer_score' => 9,
        'reviewer_remark' => 'Very practical and well evaluated.',
        'reviewed_at' => '2026-10-03 11:00:00', 'organizer_id' => $org1,
        'organizer_remark' => 'Accepted as a pre-conference workshop.',
        'organizer_decided_at' => '2026-10-07 09:30:00', 'sent_back_reason' => null, 'created_at' => '2026-09-28 09:00:00',
    ],
    [
        'user_id' => $a3, 'category_id' => 1, 'theme_id' => 3,
        'title' => 'Hypertension Control in Primary Care: A Quality Improvement Study',
        'background' => 'Background: BP control rates are suboptimal. Methods: QI initiative across 5 clinics. Results: Control improved from 38% to 57%. Conclusion: Simple protocols and audits improve outcomes.',
        'keywords' => 'hypertension, quality improvement', 'presenting_author' => 'Dr. Meera Nair',
        'co_authors' => '', 'file_path' => null,
        'status' => 'organizer_rejected', 'reviewer_id' => $rev1, 'reviewer_score' => 5,
        'reviewer_remark' => 'Data presentation needs improvement; modest novelty.',
        'reviewed_at' => '2026-10-02 10:20:00', 'organizer_id' => $org1,
        'organizer_remark' => 'Not accepted this year.', 'organizer_decided_at' => '2026-10-05 17:00:00',
        'sent_back_reason' => null, 'created_at' => '2026-09-27 09:00:00',
    ],
    [
        'user_id' => $a2, 'category_id' => 2, 'theme_id' => 1,
        'title' => 'Barriers to Cancer Screening in Rural Women',
        'background' => 'Background: Screening uptake is low in rural areas. Methods: Focus groups with 60 women. Results: Fear, cost and distance were key barriers. Conclusion: Community outreach is essential.',
        'keywords' => 'cancer screening, rural women, barriers', 'presenting_author' => 'Dr. Vikram Shah',
        'co_authors' => '', 'file_path' => null,
        'status' => 'sent_to_reviewer', 'reviewer_id' => $rev1, 'reviewer_score' => 6,
        'reviewer_remark' => 'Good qualitative work; add a quantitative angle.',
        'reviewed_at' => '2026-10-03 15:45:00', 'organizer_id' => $org1, 'organizer_remark' => null,
        'organizer_decided_at' => '2026-10-06 11:10:00',
        'sent_back_reason' => 'Please add quantitative data and expand the discussion.',
        'created_at' => '2026-09-29 13:00:00',
    ],
    [
        'user_id' => $a3, 'category_id' => 1, 'theme_id' => 1,
        'title' => 'Maternal Nutrition and Adverse Birth Outcomes',
        'background' => 'Background: Maternal undernutrition drives poor birth outcomes. Methods: Prospective cohort of 500 mothers. Results: Low BMI linked to low birth weight. Conclusion: Nutritional support programmes are critical.',
        'keywords' => 'maternal nutrition, birth outcomes', 'presenting_author' => 'Dr. Meera Nair',
        'co_authors' => 'K. Pillai', 'file_path' => null,
        'status' => 'organizer_accepted', 'reviewer_id' => $rev1, 'reviewer_score' => 8,
        'reviewer_remark' => 'Strong prospective data with public health relevance.',
        'reviewed_at' => '2026-10-07 08:00:00', 'organizer_id' => $org1,
        'organizer_remark' => 'Strong data. Accepted.', 'organizer_decided_at' => '2026-10-10 10:00:00',
        'sent_back_reason' => null, 'created_at' => '2026-10-03 07:30:00',
    ],
];

$submissionIds = [];
$i = 0;
foreach ($rows as $r) {
    $i++;
    // create a downloadable placeholder PDF for this abstract
    $fileName = 'demo_abstract_' . $i . '.pdf';
    file_put_contents($uploadDir . '/' . $fileName, demo_pdf($r['title']));
    $r['file_path'] = $fileName;

    $sid = add_submission($db, $r);
    $submissionIds[$sid] = $r;
    echo "Submission $sid: {$r['title']} [{$r['status']}]\n";
}

// ---------- Logs ----------
$authorInfo = [
    $a1 => 'Dr. Ananya Rao',
    $a2 => 'Dr. Vikram Shah',
    $a3 => 'Dr. Meera Nair',
];
$revInfo = [$rev1 => 'reviewer1', $rev2 => 'reviewer2'];
$orgInfo = [$org1 => 'organizer1'];

foreach ($submissionIds as $sid => $r) {
    add_log($db, $sid, 'author', $r['user_id'], $authorInfo[$r['user_id']], 'submitted', 'Abstract submitted');
    $st = $r['status'];

    if (in_array($st, ['reviewer_accepted', 'reviewer_rejected', 'organizer_accepted', 'organizer_rejected', 'sent_back', 'sent_to_reviewer', 'resubmitted']) && $r['reviewer_id']) {
        $act = ($st === 'reviewer_rejected') ? 'reviewer_rejected' : 'reviewer_accepted';
        add_log($db, $sid, 'reviewer', $r['reviewer_id'], $revInfo[$r['reviewer_id']], $act, $r['reviewer_remark']);
    }

    if (in_array($st, ['organizer_accepted', 'organizer_rejected', 'sent_back', 'sent_to_reviewer', 'resubmitted']) && $r['organizer_id']) {
        if ($st === 'organizer_accepted') {
            add_log($db, $sid, 'organizer', $r['organizer_id'], $orgInfo[$r['organizer_id']], 'organizer_accepted', $r['organizer_remark']);
        } elseif ($st === 'organizer_rejected') {
            add_log($db, $sid, 'organizer', $r['organizer_id'], $orgInfo[$r['organizer_id']], 'organizer_rejected', $r['organizer_remark']);
        } elseif ($st === 'sent_to_reviewer') {
            add_log($db, $sid, 'organizer', $r['organizer_id'], $orgInfo[$r['organizer_id']], 'sent_to_reviewer', $r['sent_back_reason']);
        } else { // sent_back or resubmitted -> organizer asked for revision
            add_log($db, $sid, 'organizer', $r['organizer_id'], $orgInfo[$r['organizer_id']], 'sent_back', $r['sent_back_reason']);
        }
    }

    if ($st === 'resubmitted') {
        add_log($db, $sid, 'author', $r['user_id'], $authorInfo[$r['user_id']], 'resubmitted', 'Author revised and resubmitted the abstract');
    }
}

echo "\nDONE. Seed complete.\n";
echo "Author logins : ananya@demo.test / vikram@demo.test / meera@demo.test  (password: secret123)\n";
echo "Admin logins  : reviewer1 / reviewer2 (review123)   organizer1 (org123)   admin (existing)\n";
