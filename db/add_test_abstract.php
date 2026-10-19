<?php
/**
 * Test seed: adds 3-4 fresh "submitted" abstracts for manual flow testing.
 * Author: ananya (student_id 14) / ananya@demo.test / secret123
 * Reviewer is left NULL so the organizer can assign manually.
 * Safe to re-run: each run adds new rows.
 */

require_once __DIR__ . '/config.php';

$authorId = 14; // ananya@demo.test

$list = [
    [
        'category_id'       => 1,
        'theme_id'          => 1,
        'title'             => 'Impact of Community Health Workers on Immunization Coverage: A Pilot Study',
        'background'        => "Background: Rural immunization coverage remains suboptimal.\nMethods: A community health worker-led reminder programme across 12 villages.\nResults: Full immunization rose from 61% to 82% in 6 months.\nConclusion: Trained CHWs can meaningfully improve coverage.",
        'keywords'          => 'immunization, community health workers, rural health',
        'presenting_author' => 'Dr. Ananya Rao',
        'co_authors'        => 'S. Menon, R. Iyer',
    ],
    [
        'category_id'       => 2,
        'theme_id'          => 2,
        'title'             => 'Antenatal Care Utilization and Pregnancy Outcomes Among Tribal Women',
        'background'        => "Background: Tribal women often miss early antenatal care.\nMethods: Prospective cohort of 400 tribal mothers across 3 PHCs.\nResults: Only 34% had four or more ANC visits; associated with higher preterm delivery.\nConclusion: Community-based ANC outreach is needed.",
        'keywords'          => 'antenatal care, tribal health, pregnancy outcomes',
        'presenting_author' => 'Dr. Ananya Rao',
        'co_authors'        => 'K. Pillai',
    ],
    [
        'category_id'       => 1,
        'theme_id'          => 3,
        'title'             => 'Glycemic Control and Quality of Life in Type 2 Diabetes Patients on Teleconsultation',
        'background'        => "Background: COVID-19 accelerated teleconsultation for diabetes care.\nMethods: Retrospective cohort of 600 patients over 12 months, comparing tele vs in-person follow-up.\nResults: HbA1c reduction was similar; QoL scores improved in the tele group.\nConclusion: Teleconsultation is a viable model for follow-up care.",
        'keywords'          => 'diabetes, teleconsultation, quality of life',
        'presenting_author' => 'Dr. Ananya Rao',
        'co_authors'        => '',
    ],
    [
        'category_id'       => 3,
        'theme_id'          => 4,
        'title'             => 'Workshop: Building District-Level Health Dashboards with Open-Source Tools',
        'background'        => "Background: District managers lack easy data visualization skills.\nMethods: 1-day hands-on workshop with 30 program managers using data from prior audits.\nResults: 87% could build a live dashboard independently.\nConclusion: Low-cost capacity building is feasible at scale.",
        'keywords'          => 'workshop, dashboards, capacity building',
        'presenting_author' => 'Dr. Ananya Rao',
        'co_authors'        => 'R. Iyer',
    ],
];

$created = [];

$checkStmt = $db->prepare("SELECT COUNT(*) c FROM abstract_category WHERE id = ? AND status = '1'");
$authorStmt = $db->prepare("SELECT COUNT(*) c FROM registered_users WHERE student_id = ?");

$insert = $db->prepare(
    "INSERT INTO abstract_submissions
        (user_id, category_id, theme_id, title, background, keywords, presenting_author, co_authors, file_path, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'submitted')"
);

foreach ($list as $d) {
    $checkStmt->bind_param("i", $d['category_id']);
    $checkStmt->execute();
    $catOk = (int) mysqli_fetch_assoc($checkStmt->get_result())['c'] === 1;

    $checkStmt->bind_param("i", $d['theme_id']);
    $checkStmt->execute();
    $themeOk = (int) mysqli_fetch_assoc($checkStmt->get_result())['c'] === 1;

    $authorStmt->bind_param("i", $authorId);
    $authorStmt->execute();
    $authorOk = (int) mysqli_fetch_assoc($authorStmt->get_result())['c'] === 1;

    if (!$catOk || !$themeOk || !$authorOk) {
        echo "SKIP (missing cat/theme/author): {$d['title']}\n";
        continue;
    }

    $insert->bind_param(
        "iiisssss",
        $authorId, $d['category_id'], $d['theme_id'], $d['title'], $d['background'],
        $d['keywords'], $d['presenting_author'], $d['co_authors']
    );
    if ($insert->execute()) {
        $created[] = $insert->insert_id;
        echo "Created submission #{$insert->insert_id}: {$d['title']}\n";
    } else {
        echo "FAILED: {$d['title']}\n";
    }
}

$insert->close();
$checkStmt->close();
$authorStmt->close();

echo "\nDone. " . count($created) . " abstract(s) created.\n";
echo "View them at: manage-abstract-submissions.php?search=" . urlencode('Immunization%20OR%20Antenatal%20OR%20Glycemic%20OR%20Workshop%3A') . "\n";
if ($created) {
    echo "Direct links:\n";
    foreach ($created as $id) {
        echo "  view-abstract-submission.php?id=" . base64_encode($id) . "  (#$id)\n";
    }
}