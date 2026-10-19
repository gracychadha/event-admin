<?php
session_start();
include("db/config.php");
// echo '<pre>';
// print_r($_SESSION);
// exit;
if (!isset($_SESSION["login_user"])) {
    header("location: index.php");
    exit();
}

$admin_id = $_SESSION['login_user_id'];
$role_id = $_SESSION['admin_role'] ?? 0;
$employee_id = $_SESSION['employee_id'] ?? null;
$role_name = '';
$employee_data = null;
// echo $employee_id;
// exit;
// Fetch role name
$role_stmt = $db->prepare("SELECT role_name FROM roles WHERE role_id = ?");
$role_stmt->bind_param("i", $role_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_row = $role_result->fetch_assoc()) {
    $role_name = strtolower($role_row['role_name']);
}

// If role is employee, fetch employee data
if ($role_name === 'employee' && $employee_id) {
    $emp_stmt = $db->prepare("SELECT * FROM personal_details WHERE personal_id = ?");
    $emp_stmt->bind_param("i", $employee_id);
    $emp_stmt->execute();
    $emp_result = $emp_stmt->get_result();

    if ($emp_data = $emp_result->fetch_assoc()) {
        $employee_data = $emp_data;
    }
}
// echo $employee_id;
// exit;

$query = "SELECT * FROM admin";
$result = mysqli_query($db, $query);


// Agar lead_status column mein exact string 'own' stored hai, to:
$sql = "SELECT COUNT(*) AS own_leads_count FROM admission_enquiry WHERE lead_status = 'lead own'";

$result = mysqli_query($db, $sql);
$row = mysqli_fetch_assoc($result);
$own_leads_count = $row['own_leads_count'] ?? 0;

// Agar lead_status column mein exact string 'own' stored hai, to:
$hotlead = "SELECT COUNT(*) AS hot_leads_count FROM admission_enquiry WHERE lead_status = 'hot'";

$result_hot = mysqli_query($db, $hotlead);
$row = mysqli_fetch_assoc($result_hot);
$hot_leads_count = $row['hot_leads_count'] ?? 0;


// Agar lead_status column mein exact string 'own' stored hai, to:
$warmlead = "SELECT COUNT(*) AS warm_leads_count FROM admission_enquiry WHERE lead_status = 'own'";

$result_warm = mysqli_query($db, $warmlead);
$row = mysqli_fetch_assoc($result_warm);
$warm_leads_count = $row['warm_leads_count'] ?? 0;



// Agar lead_status column mein exact string 'own' stored hai, to:
$clients = "SELECT COUNT(*) AS clients_total_count FROM clients WHERE status = 1 AND is_deleted = 0";

$result_client = mysqli_query($db, $clients);
$row = mysqli_fetch_assoc($result_client);
$clients_total_count = $row['clients_total_count'] ?? 0;


$count_query = "
    SELECT COUNT(*) AS employee_total_count
    FROM personal_details pd
    JOIN company_details cd ON pd.personal_id = cd.user_id
    WHERE cd.employee_status = '1'
";

$count_result = mysqli_query($db, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$employee_total_count = $count_row['employee_total_count'] ?? 0;


// ✅ Get total employees
$total_query = "
    SELECT COUNT(*) AS total_employees
    FROM personal_details pd
    INNER JOIN company_details cd ON pd.personal_id = cd.user_id
    WHERE cd.employee_status = '1'
";
$total_result = mysqli_query($db, $total_query);
$total_row = mysqli_fetch_assoc($total_result);
$total_employees = $total_row['total_employees'] ?? 0;

// ✅ Get employees by employment type
$type_query = "
    SELECT cd.employement_type, COUNT(*) AS count
    FROM company_details cd
    INNER JOIN personal_details pd ON pd.personal_id = cd.user_id
    WHERE cd.employee_status = '1'
    GROUP BY cd.employement_type
";
$type_result = mysqli_query($db, $type_query);

$employment_counts = [];
while ($row = mysqli_fetch_assoc($type_result)) {
    $employment_counts[$row['employement_type']] = $row['count'];
}

// Example usage in HTML
$fulltime_count = $employment_counts['Regular'] ?? 0;
$contract_count = $employment_counts['Contract'] ?? 0;
$probation_count = $employment_counts['Intern'] ?? 0;
$wfh_count = $employment_counts['WFM'] ?? 0;


$service = "SELECT COUNT(*) AS services_total_count FROM service";
$result_service = mysqli_query($db, $service);
$row = mysqli_fetch_assoc($result_service);
$services_total_count    = $row['services_total_count'] ?? 0;


// Get current date information
$currentMonth = date('m');
$currentYear = date('Y');
$today = date('Y-m-d');
$today_md = date('m-d');
$tomorrow_md = date('m-d', strtotime('+1 day'));
$currentDay = date('d');

// Check if we're in the last week of the month
$daysInCurrentMonth = date('t'); // Total days in current month
$isLastWeek = ($daysInCurrentMonth - $currentDay) <= 7;

// Prepare query based on whether we're in last week or not
if ($isLastWeek) {
    // Get current month + next month birthdays for active employees
    $nextMonth = date('m', strtotime('+1 month'));
    $nextMonthYear = date('Y', strtotime('+1 month'));

    $query = "
        SELECT pd.name, 
               pd.dob,
               DATE_FORMAT(pd.dob, '%m-%d') as md, 
               DATE_FORMAT(pd.dob, '%d %b') as dob_display,
               MONTH(pd.dob) as birth_month,
               DAY(pd.dob) as birth_day
        FROM personal_details pd
        JOIN company_details cd ON pd.personal_id = cd.user_id
        WHERE cd.employee_status = '1'
          AND MONTH(pd.dob) IN ('$currentMonth', '$nextMonth')
        ORDER BY 
            CASE 
                WHEN MONTH(pd.dob) = '$currentMonth' THEN 1 
                ELSE 2 
            END,
            DAY(pd.dob) ASC
    ";
} else {
    // Get only current month birthdays for active employees
    $query = "
        SELECT pd.name, 
               pd.dob,
               DATE_FORMAT(pd.dob, '%m-%d') as md, 
               DATE_FORMAT(pd.dob, '%d %b') as dob_display,
               MONTH(pd.dob) as birth_month,
               DAY(pd.dob) as birth_day
        FROM personal_details pd
        JOIN company_details cd ON pd.personal_id = cd.user_id
        WHERE cd.employee_status = '1'
          AND MONTH(pd.dob) = '$currentMonth'
        ORDER BY DAY(pd.dob) ASC
    ";
}

$result = mysqli_query($db, $query);

$todayList = [];
$tomorrowList = [];
$upcomingList = [];

while ($row = mysqli_fetch_assoc($result)) {
    $birthday_md = $row['md'];
    $birth_month = $row['birth_month'];
    $birth_day = $row['birth_day'];

    // Create birthday date for current year
    $birthdayThisYear = date('Y-m-d', mktime(0, 0, 0, $birth_month, $birth_day, $currentYear));

    // If birthday has passed this year, check next year
    if ($birthdayThisYear < $today) {
        $birthdayThisYear = date('Y-m-d', mktime(0, 0, 0, $birth_month, $birth_day, $currentYear + 1));
    }

    // Calculate days difference
    $daysDiff = (strtotime($birthdayThisYear) - strtotime($today)) / (60 * 60 * 24);

    if ($daysDiff == 0) {
        // Today
        $todayList[] = $row;
    } elseif ($daysDiff == 1) {
        // Tomorrow
        $tomorrowList[] = $row;
    } elseif ($daysDiff > 1) {
        // Upcoming birthdays
        if ($isLastWeek) {
            if ($daysDiff <= 30) { // Within next 30 days
                $upcomingList[] = $row;
            }
        } else {
            if ($birth_month == $currentMonth && $birth_day > $currentDay) {
                $upcomingList[] = $row;
            }
        }
    }
}

// Sort upcoming list by actual birthday date
usort($upcomingList, function ($a, $b) use ($currentYear) {
    $dateA = mktime(0, 0, 0, $a['birth_month'], $a['birth_day'], $currentYear);
    $dateB = mktime(0, 0, 0, $b['birth_month'], $b['birth_day'], $currentYear);

    // If date has passed this year, use next year
    if ($dateA < time()) {
        $dateA = mktime(0, 0, 0, $a['birth_month'], $a['birth_day'], $currentYear + 1);
    }
    if ($dateB < time()) {
        $dateB = mktime(0, 0, 0, $b['birth_month'], $b['birth_day'], $currentYear + 1);
    }

    return $dateA - $dateB;
});


// work anniversary

// Get current date information
$currentMonth = date('m');
$currentYear = date('Y');
$today = date('Y-m-d');
$today_md = date('m-d');
$tomorrow_md = date('m-d', strtotime('+1 day'));
$currentDay = date('d');

// Check if we're in the last week of the month
$daysInCurrentMonth = date('t'); // Total days in current month
$isLastWeek = ($daysInCurrentMonth - $currentDay) <= 7;

// Prepare query based on whether we're in last week or not
if ($isLastWeek) {
    // Current + next month anniversaries
    $nextMonth = date('m', strtotime('+1 month'));

    $query = "
        SELECT cd.user_id,
               pd.name,
               cd.doj,
               DATE_FORMAT(cd.doj, '%m-%d') as md, 
               DATE_FORMAT(cd.doj, '%d %b') as doj_display,
               MONTH(cd.doj) as doj_month,
               DAY(cd.doj) as doj_day
        FROM company_details cd
        JOIN personal_details pd ON pd.personal_id = cd.user_id
        WHERE cd.employee_status = '1'
          AND MONTH(cd.doj) IN ('$currentMonth', '$nextMonth')
        ORDER BY 
            CASE 
                WHEN MONTH(cd.doj) = '$currentMonth' THEN 1 
                ELSE 2 
            END,
            DAY(cd.doj) ASC
    ";
} else {
    // Only current month anniversaries
    $query = "
        SELECT cd.user_id,
               pd.name,
               cd.doj,
               DATE_FORMAT(cd.doj, '%m-%d') as md, 
               DATE_FORMAT(cd.doj, '%d %b') as doj_display,
               MONTH(cd.doj) as doj_month,
               DAY(cd.doj) as doj_day
        FROM company_details cd
        JOIN personal_details pd ON pd.personal_id = cd.user_id
        WHERE cd.employee_status = '1'
          AND MONTH(cd.doj) = '$currentMonth'
        ORDER BY DAY(cd.doj) ASC
    ";
}

$result = mysqli_query($db, $query);

$todayAnniversary = [];
$tomorrowAnniversary = [];
$upcomingAnniversary = [];

while ($row = mysqli_fetch_assoc($result)) {
    $doj_month = $row['doj_month'];
    $doj_day = $row['doj_day'];
    $doj_date = $row['doj']; // original DOJ

    // Create anniversary date for this year
    $anniversaryThisYear = date('Y-m-d', mktime(0, 0, 0, $doj_month, $doj_day, $currentYear));

    // If anniversary has passed this year, take next year
    if ($anniversaryThisYear < $today) {
        $anniversaryThisYear = date('Y-m-d', mktime(0, 0, 0, $doj_month, $doj_day, $currentYear + 1));
    }

    // ✅ Check: has employee completed at least 1 year?
    $firstAnniversary = date('Y-m-d', strtotime("+1 year", strtotime($doj_date)));

    // If they haven't yet reached 1 year, skip
    // BUT allow their first anniversary date itself
    if ($today < $firstAnniversary && $anniversaryThisYear != $firstAnniversary) {
        continue;
    }


    // Calculate days difference
    $daysDiff = (strtotime($anniversaryThisYear) - strtotime($today)) / (60 * 60 * 24);

    if ($daysDiff == 0) {
        // Today
        $todayAnniversary[] = $row;
    } elseif ($daysDiff == 1) {
        // Tomorrow
        $tomorrowAnniversary[] = $row;
    } elseif ($daysDiff > 1) {
        // Upcoming
        if ($isLastWeek) {
            if ($daysDiff <= 30) {
                $upcomingAnniversary[] = $row;
            }
        } else {
            if ($doj_month == $currentMonth && $doj_day > $currentDay) {
                $upcomingAnniversary[] = $row;
            }
        }
    }
}


// Sort upcoming list
usort($upcomingAnniversary, function ($a, $b) use ($currentYear) {
    $dateA = mktime(0, 0, 0, $a['doj_month'], $a['doj_day'], $currentYear);
    $dateB = mktime(0, 0, 0, $b['doj_month'], $b['doj_day'], $currentYear);

    if ($dateA < time()) {
        $dateA = mktime(0, 0, 0, $a['doj_month'], $a['doj_day'], $currentYear + 1);
    }
    if ($dateB < time()) {
        $dateB = mktime(0, 0, 0, $b['doj_month'], $b['doj_day'], $currentYear + 1);
    }

    return $dateA - $dateB;
});


$query = "SELECT * FROM login_settings";
$settingsResult = mysqli_query($db, $query);
$settings = mysqli_fetch_assoc($settingsResult);

$logoPath = $settings['backend_panel_logo'];
$helpdeskNumber = $settings['helpdesk_no'];
$favicon = $settings['favicon'];
// echo $favicon;
// exit;


// Get current month/year or from URL parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Fetch events from database
$query = "SELECT * FROM event_calendar WHERE MONTH(event_date) = $month AND YEAR(event_date) = $year ORDER BY event_date";
$result = mysqli_query($db, $query);
$events = [];
while ($row = mysqli_fetch_assoc($result)) {
    $day = date('j', strtotime($row['event_date']));
    $events[$day][] = $row;
}

// Get current month/year or from URL parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Fetch events from database
$query = "SELECT * FROM event_calendar 
WHERE MONTH(event_date) = $month 
  AND YEAR(event_date) = $year 
  AND status = 1
ORDER BY event_date;
";
$result = mysqli_query($db, $query);
$events = [];
while ($row = mysqli_fetch_assoc($result)) {
    $day = date('j', strtotime($row['event_date']));
    $events[$day][] = $row;
}

// Calendar calculations
$firstDay = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = date('t', $firstDay);
$dayOfWeek = date('w', $firstDay);
$monthName = date('F Y', $firstDay);

// Navigation
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}
?>

<!DOCTYPE html>
<html lang="en">
<meta http-equiv="content-type" content="text/html;charset=UTF-8" />

<head>
    <title>Control Management System</title>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="" />
    <meta name="keywords" content="">
    <meta name="author" content="" />

    <link rel="icon" href="<?php echo $favicon; ?>" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/plugins/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .birthday-list {
            max-height: 100px;
            overflow-y: auto;
            /* keep scroll functionality */
            scrollbar-width: none;
            /* Firefox */
            -ms-overflow-style: none;
            /* IE and Edge */
        }

        .birthday-list::-webkit-scrollbar {
            display: none;
            /* Chrome, Safari, Opera */
        }

        .welcome-block {
            display: flex;
            
            align-items: center;
            gap: 16px;
        }

        .welcome-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #022b55;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(0, 51, 153, 0.25);
        }

        .welcome-title {
            color: #fff;
            font-weight: 600;
            margin: 0;
        }

        .welcome-sub {
            color: #e4e7e9;
            font-size: 14px;
        }

        .clock-widget {
            display: inline-block;
            background: #fff;
            border: 1px solid #eaeaea;
            border-radius: 12px;
            padding: 10px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
        }

        .clock-time {
            font-size: 26px;
            font-weight: 600;
            color: #003399;
            letter-spacing: 1px;
            line-height: 1.1;
        }

        .clock-time i {
            font-size: 20px;
            margin-right: 4px;
        }

        .clock-time small {
            font-size: 13px;
            font-weight: 600;
        }

        .clock-date {
            font-size: 13px;
            color: #6c757d;
            margin-top: 4px;
        }

        @media (max-width: 767.98px) {
            .welcome-block {
                justify-content: center;
                text-align: center;
                flex-direction: column;
            }
        }
    </style>

</head>

<body class="">

    <div class="loader-bg">
        <div class="loader-track">
            <div class="loader-fill"></div>
        </div>
    </div>

    <!-- Header -->
    <?php
    include("header.php");
    ?>
    <!-- /Header -->

    <!-- navbar -->
    <?php
    include("navbar.php");
    ?>
    <!-- /navbar -->


    <section class="pcoded-main-container">
        <div class="pcoded-content">


            <?php
            $displayName = ($role_name === 'employee' && !empty($employee_data['name']))
                ? $employee_data['name']
                : $_SESSION['login_user'];
            $displayName = htmlspecialchars($displayName);

            $hour = (int)date('H');
            if ($hour < 12) {
                $greeting = 'Good Morning';
            } elseif ($hour < 17) {
                $greeting = 'Good Afternoon';
            } else {
                $greeting = 'Good Evening';
            }
            ?>
            <div class="page-header" style="background: linear-gradient(135deg, #003399, #0a58ca);">
                <div class="page-block">
                    <div class="row align-items-center">
                        <!-- Welcome message (left) -->
                        <div class="col-md-8">
                            <div class="welcome-block">
                                <div class="welcome-icon">
                                    <i class="feather icon-sun"></i>
                                </div>
                                <div>
                                    <h4 class="welcome-title mb-1"><?= $greeting . ', ' . $displayName; ?>!</h4>
                                    <p class="welcome-sub mb-0">Welcome back to Control Management System.</p>
                                </div>
                            </div>
                        </div>
                        <!-- Timer & date (right) -->
                        <div class="col-md-4 text-md-right text-center mt-3 mt-md-0">
                            <div class="clock-widget">
                                <div class="clock-time">
                                    <i class="feather icon-clock"></i>
                                    <span id="ct-time">--:--:--</span>
                                    <small id="ct-ampm">AM</small>
                                </div>
                                <div class="clock-date">
                                    <i class="feather icon-calendar"></i>
                                    <span id="ct-date">--</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <?php
            if (isset($_GET['loginin'])) {
                $st = $_GET['loginin'];
                $st1 = base64_decode($st);

                if ($st1 > 0) {
                    // check role
                    if ($role_name === 'employee') {
                        $userName = htmlspecialchars($_SESSION['login_user']); // session se naam
                        $welcomeMsg = "<strong><i class='feather icon-check'></i> Welcome, $userName!</strong> You have logged in successfully.";
                    } else {
                        $welcomeMsg = "<strong><i class='feather icon-check'></i> Welcome!</strong> User has been Login Successfully.";
                    }

                    echo "
        <div class='alert alert-success alert-dismissible fade show' role='alert' style='font-size:16px;' id='logged'>
            $welcomeMsg
            <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
                <span aria-hidden='true'>&times;</span>
            </button>
        </div>";
                }
            }
            ?>


            <div class="row">

                <?php if ($role_name === 'employee') { ?>
                    <!-- Only Employee-specific section -->
                    <div class="col-md-6 col-xl-4">
                        <div class="card bg-c-blue order-card">
                            <div class="card-body d-flex flex-column align-items-center text-center">
                                <i class="feather icon-user text-white mb-2" style="font-size: 36px;"></i>
                                <a href="view-profile.php">
                                    <h6 class="text-white">View Profile</h6>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="card bg-c-green order-card">
                            <div class="card-body d-flex flex-column align-items-center text-center">
                                <i class="feather icon-file-text text-white mb-2" style="font-size: 36px;"></i>
                                <a href="view-profile.php">
                                    <h6 class="text-white">Request Salary Slip</h6>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-4">
                        <div class="card bg-c-yellow order-card">
                            <div class="card-body d-flex flex-column align-items-center text-center">
                                <i class="feather icon-lock text-white mb-2" style="font-size: 36px;"></i>
                                <a href="changepass.php">
                                    <h6 class="text-white">Change Password</h6>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="custom-header">
                            <div class="d-flex flex-column justify-content-center align-items-center text-center">
                                <h4 class="text-white">
                                    <img src="assets/images/birthday-cake.png" alt="cake"> Birthdays
                                </h4>
                                <?php if ($isLastWeek): ?>
                                    <!-- <small class="text-muted">Showing current & next month</small> -->
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="birthday-card" style="height: 450px;">

                            <!-- <hr> -->

                            <!-- Today -->
                            <h6 class="text-primary">Today</h6>
                            <ul class="birthday-list">
                                <?php if (!empty($todayList)) {
                                    foreach ($todayList as $emp) { ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                            </div>
                                            <span><img src="assets/images/confetti.png"></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No birthdays today</span></li>
                                <?php } ?>
                            </ul>
                            <hr>

                            <!-- Tomorrow -->
                            <h6 class="text-success mt-3">Tomorrow</h6>
                            <ul class="birthday-list">
                                <?php if (!empty($tomorrowList)) {
                                    foreach ($tomorrowList as $emp) { ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                            </div>
                                            <span><img src="assets/images/giftbox.png"></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No birthdays tomorrow</span></li>
                                <?php } ?>
                            </ul>
                            <hr>

                            <!-- Upcoming -->
                            <h6 class="text-warning mt-3">
                                Upcoming
                                <?php if ($isLastWeek): ?>
                                    <small>(This & Next Month)</small>
                                <?php else: ?>
                                    <small>(This Month)</small>
                                <?php endif; ?>
                            </h6>
                            <ul class="birthday-list">
                                <?php if (!empty($upcomingList)) {
                                    foreach ($upcomingList as $emp) {
                                        // Calculate days until birthday
                                        $birth_month = $emp['birth_month'];
                                        $birth_day = $emp['birth_day'];
                                        $birthdayThisYear = mktime(0, 0, 0, $birth_month, $birth_day, $currentYear);

                                        if ($birthdayThisYear < time()) {
                                            $birthdayThisYear = mktime(0, 0, 0, $birth_month, $birth_day, $currentYear + 1);
                                        }

                                        $daysUntil = ceil(($birthdayThisYear - time()) / (60 * 60 * 24));
                                ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                                <small class="text-muted d-block">In <?= $daysUntil ?> day<?= $daysUntil != 1 ? 's' : '' ?></small>
                                            </div>
                                            <span><img src="assets/images/birthday-cake.png"> <?= $emp['dob_display'] ?></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No upcoming birthdays <?= $isLastWeek ? 'in next 30 days' : 'this month' ?></span></li>
                                <?php } ?>
                            </ul>

                        </div>

                    </div>
                    <div class="col-md-4">
                        <div class="custom-header">
                            <div class="d-flex flex-column justify-content-center align-items-center text-center">
                                <h4 class="text-white">
                                    <img src="assets/images/cheers.png" alt=""> Work Anniversary
                                </h4>
                            </div>
                        </div>

                        <div class="birthday-card" style="height: 349px;">
                            <!-- Today -->
                            <h6 class="text-primary">Today</h6>
                            <ul class="birthday-list">
                                <?php if (!empty($todayAnniversary)) {
                                    foreach ($todayAnniversary as $emp) { ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                            </div>
                                            <span><img src="assets/images/retreat.png"></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No anniversaries today</span></li>
                                <?php } ?>
                            </ul>
                            <hr>

                            <!-- Tomorrow -->
                            <h6 class="text-success mt-3">Tomorrow</h6>
                            <ul class="birthday-list">
                                <?php if (!empty($tomorrowAnniversary)) {
                                    foreach ($tomorrowAnniversary as $emp) { ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                            </div>
                                            <span><img src="assets/images/giftbox.png"></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No anniversaries tomorrow</span></li>
                                <?php } ?>
                            </ul>
                            <hr>

                            <!-- Upcoming -->
                            <h6 class="text-warning mt-3">
                                Upcoming
                                <?php if ($isLastWeek): ?>
                                    <small>(This & Next Month)</small>
                                <?php else: ?>
                                    <small>(This Month)</small>
                                <?php endif; ?>
                            </h6>
                            <ul class="birthday-list">
                                <?php if (!empty($upcomingAnniversary)) {
                                    foreach ($upcomingAnniversary as $emp) {
                                        $doj_month = $emp['doj_month'];
                                        $doj_day = $emp['doj_day'];
                                        $anniversaryThisYear = mktime(0, 0, 0, $doj_month, $doj_day, $currentYear);

                                        if ($anniversaryThisYear < time()) {
                                            $anniversaryThisYear = mktime(0, 0, 0, $doj_month, $doj_day, $currentYear + 1);
                                        }

                                        $daysUntil = ceil(($anniversaryThisYear - time()) / (60 * 60 * 24));
                                ?>
                                        <li>
                                            <div class="emp-info">
                                                <span><?= htmlspecialchars($emp['name']) ?></span>
                                                <small class="text-muted d-block">In <?= $daysUntil ?> day<?= $daysUntil != 1 ? 's' : '' ?></small>
                                            </div>
                                            <span><img src="assets/images/cheers.png"> <?= $emp['doj_display'] ?></span>
                                        </li>
                                    <?php }
                                } else { ?>
                                    <li><span>No upcoming anniversaries <?= $isLastWeek ? 'in next 30 days' : 'this month' ?></span></li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>


                    <div class="col-md-4">
                        <div class="custom-emp text-center">
                            <h4 class="text-white"> Clients Assigned</h4>

                        </div>
                        <div class="birthday-card" style="height: 450px;">

                            <div class="employee-status-card">




                                <!-- Clients Assigned -->
                                <div class="assigned-clients">

                                    <?php
                                    $sql = "SELECT c.name
            FROM company_details cd
            JOIN clients c ON FIND_IN_SET(c.client_id, cd.assigned_client)
            WHERE cd.user_id = ?";
                                    $stmt = $db->prepare($sql);
                                    $stmt->bind_param("i", $employee_id);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    ?>

                                    <?php if ($result->num_rows > 0): ?>
                                        <ul class="client-list">
                                            <?php while ($row = $result->fetch_assoc()): ?>
                                                <li class="client-card">
                                                    <div class="client-avatar">
                                                        <i class="fas fa-user-circle"></i>
                                                    </div>
                                                    <div class="client-info">
                                                        <strong><?= htmlspecialchars($row['name']); ?></strong>
                                                    </div>
                                                </li>
                                            <?php endwhile; ?>
                                        </ul>

                                    <?php else: ?>
                                        <p class="text-muted">No clients assigned.</p>
                                    <?php endif; ?>
                                </div>




                            </div>
                        </div>

                    </div>

                <?php } else { ?>
                    <!-- Full dashboard for other roles -->
                    <!-- order-card start -->
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-blue order-card">
                            <div class="card-body">
                                <a href="admission-leads.php">
                                    <h6 class="text-white">Total Leads</h6>
                                </a>
                                <h2 class="text-right text-white"><i
                                        class="feather icon-message-square float-left"></i><span id="new4"></span>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-green order-card">
                            <div class="card-body">
                                <a href="admission-leads.php">
                                    <h6 class="text-white">New Leads</h6>
                                </a>
                                <h2 class="text-right text-white"><i
                                        class="feather icon-user float-left"></i><span id="total"></span>
                                </h2>
                            </div>
                        </div>
                    </div>


                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-yellow order-card">
                            <div class="card-body">
                                <a href="manage-user.php">
                                    <h6 class="text-white">Admin Users</h6>
                                </a>
                                <h2 class="text-right text-white"><i
                                        class="feather icon-users float-left"></i><span id="new2"></span>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-red order-card">
                            <div class="card-body">
                                <a href="admission-leads.php">
                                    <h6 class="text-white">Total Downloads</h6>
                                </a>
                                <h2 class="text-right text-white"><i
                                        class="feather icon-download float-left"></i><span id="new3"></span>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-grren order-card">
                            <div class="card-body">
                                <a href="manage-employees.php">
                                    <h6 class="text-white">Total Employees</h6>
                                </a>
                                <h2 class="text-right text-white">
                                    <i class="feather icon-user float-left"></i>

                                    <span><?= htmlspecialchars($employee_total_count) ?></span>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-reds order-card">
                            <div class="card-body">
                                <a href="manage-service.php">
                                    <h6 class="text-white">Total Services</h6>
                                </a>
                                <h2 class="text-right text-white">
                                    <i class="feather icon-user float-left"></i>

                                    <span><?= htmlspecialchars($services_total_count) ?></span>
                                </h2>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-blue order-card">
                            <div class="card-body">
                                <a href="manage-client.php">
                                    <h6 class="text-white">Total Clients</h6>
                                </a>
                                <h2 class="text-right text-white"><i
                                        class="feather icon-users float-left"></i><?= htmlspecialchars($clients_total_count) ?></span>
                                </h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="card bg-c-warms order-card">
                            <div class="card-body">
                                <a href="own-leads.php">
                                    <h6 class="text-white">Own Leads</h6>
                                </a>
                                <h2 class="text-right text-white">
                                    <i class="feather icon-user float-left"></i>

                                    <span><?= htmlspecialchars($warm_leads_count) ?></span>
                                </h2>
                            </div>
                        </div>
                    </div>
            </div>


            <div class="row">
                <div class="col-md-4">
                    <div class="custom-header">
                        <div class="d-flex flex-column justify-content-center align-items-center text-center">
                            <h4 class="text-white">
                                <img src="assets/images/birthday-cake.png" alt="cake"> Birthdays
                            </h4>
                            <?php if ($isLastWeek): ?>
                                <!-- <small class="text-muted">Showing current & next month</small> -->
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="birthday-card" style="height: 349px;">

                        <!-- <hr> -->

                        <!-- Today -->
                        <h6 class="text-primary">Today</h6>
                        <ul class="birthday-list">
                            <?php if (!empty($todayList)) {
                                foreach ($todayList as $emp) { ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                        </div>
                                        <span><img src="assets/images/confetti.png"></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No birthdays today</span></li>
                            <?php } ?>
                        </ul>
                        <hr>

                        <!-- Tomorrow -->
                        <h6 class="text-success mt-3">Tomorrow</h6>
                        <ul class="birthday-list">
                            <?php if (!empty($tomorrowList)) {
                                foreach ($tomorrowList as $emp) { ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                        </div>
                                        <span><img src="assets/images/giftbox.png"></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No birthdays tomorrow</span></li>
                            <?php } ?>
                        </ul>
                        <hr>

                        <!-- Upcoming -->
                        <h6 class="text-warning mt-3">
                            Upcoming
                            <?php if ($isLastWeek): ?>
                                <small>(This & Next Month)</small>
                            <?php else: ?>
                                <small>(This Month)</small>
                            <?php endif; ?>
                        </h6>
                        <ul class="birthday-list">
                            <?php if (!empty($upcomingList)) {
                                foreach ($upcomingList as $emp) {
                                    // Calculate days until birthday
                                    $birth_month = $emp['birth_month'];
                                    $birth_day = $emp['birth_day'];
                                    $birthdayThisYear = mktime(0, 0, 0, $birth_month, $birth_day, $currentYear);

                                    if ($birthdayThisYear < time()) {
                                        $birthdayThisYear = mktime(0, 0, 0, $birth_month, $birth_day, $currentYear + 1);
                                    }

                                    $daysUntil = ceil(($birthdayThisYear - time()) / (60 * 60 * 24));
                            ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                            <small class="text-muted d-block">In <?= $daysUntil ?> day<?= $daysUntil != 1 ? 's' : '' ?></small>
                                        </div>
                                        <span><img src="assets/images/birthday-cake.png"> <?= $emp['dob_display'] ?></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No upcoming birthdays <?= $isLastWeek ? 'in next 30 days' : 'this month' ?></span></li>
                            <?php } ?>
                        </ul>

                    </div>

                </div>
                <div class="col-md-4">
                    <div class="custom-header">
                        <div class="d-flex flex-column justify-content-center align-items-center text-center">
                            <h4 class="text-white">
                                <img src="assets/images/cheers.png" alt=""> Work Anniversary
                            </h4>
                        </div>
                    </div>

                    <div class="birthday-card" style="height: 349px;">
                        <!-- Today -->
                        <h6 class="text-primary">Today</h6>
                        <ul class="birthday-list">
                            <?php if (!empty($todayAnniversary)) {
                                foreach ($todayAnniversary as $emp) { ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                        </div>
                                        <span><img src="assets/images/retreat.png"></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No anniversaries today</span></li>
                            <?php } ?>
                        </ul>
                        <hr>

                        <!-- Tomorrow -->
                        <h6 class="text-success mt-3">Tomorrow</h6>
                        <ul class="birthday-list">
                            <?php if (!empty($tomorrowAnniversary)) {
                                foreach ($tomorrowAnniversary as $emp) { ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                        </div>
                                        <span><img src="assets/images/giftbox.png"></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No anniversaries tomorrow</span></li>
                            <?php } ?>
                        </ul>
                        <hr>

                        <!-- Upcoming -->
                        <h6 class="text-warning mt-3">
                            Upcoming
                            <?php if ($isLastWeek): ?>
                                <small>(This & Next Month)</small>
                            <?php else: ?>
                                <small>(This Month)</small>
                            <?php endif; ?>
                        </h6>
                        <ul class="birthday-list">
                            <?php if (!empty($upcomingAnniversary)) {
                                foreach ($upcomingAnniversary as $emp) {
                                    $doj_month = $emp['doj_month'];
                                    $doj_day = $emp['doj_day'];
                                    $anniversaryThisYear = mktime(0, 0, 0, $doj_month, $doj_day, $currentYear);

                                    if ($anniversaryThisYear < time()) {
                                        $anniversaryThisYear = mktime(0, 0, 0, $doj_month, $doj_day, $currentYear + 1);
                                    }

                                    $daysUntil = ceil(($anniversaryThisYear - time()) / (60 * 60 * 24));
                            ?>
                                    <li>
                                        <div class="emp-info">
                                            <span><?= htmlspecialchars($emp['name']) ?></span>
                                            <small class="text-muted d-block">In <?= $daysUntil ?> day<?= $daysUntil != 1 ? 's' : '' ?></small>
                                        </div>
                                        <span><img src="assets/images/cheers.png"> <?= $emp['doj_display'] ?></span>
                                    </li>
                                <?php }
                            } else { ?>
                                <li><span>No upcoming anniversaries <?= $isLastWeek ? 'in next 30 days' : 'this month' ?></span></li>
                            <?php } ?>
                        </ul>
                    </div>
                </div>




                <div class="col-md-4">
                    <div class="custom-emp text-center">
                        <h4 class="text-white"> Employees Status</h4>

                    </div>
                    <div class="birthday-card">

                        <div class="employee-status-card">

                            <div class="total-employees">
                                <div class="total-count">Total Employees <?= $total_employees; ?></div>
                                <div class="progress-bar">
                                    <div class="progress-segment fulltime"></div>
                                    <div class="progress-segment contract"></div>
                                    <div class="progress-segment probation"></div>
                                    <div class="progress-segment wfh"></div>
                                </div>
                            </div>

                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-label">
                                        <span class="stat-dot dot-fulltime"></span>
                                        Regular
                                    </div>
                                    <div class="stat-value"> <?= $fulltime_count; ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">
                                        <span class="stat-dot dot-contract"></span>
                                        Contract
                                    </div>
                                    <div class="stat-value"><?= $contract_count; ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">
                                        <span class="stat-dot dot-probation"></span>
                                        Intern
                                    </div>
                                    <div class="stat-value">
                                        <div class="stat-value"><?= $probation_count; ?></div>
                                    </div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label">
                                        <span class="stat-dot dot-wfh"></span>
                                        WFH
                                    </div>
                                    <div class="stat-value"><?= $wfh_count; ?></div>
                                </div>
                            </div>

                            <!-- <div class="top-performer">
                                <h3 class="section-title">Top Performer</h3>
                                <div class="performer-card">
                                    <div class="performer-badge">🏆</div>
                                    <div class="performer-avatar">DE</div>
                                    <div class="performer-info">
                                        <div class="performer-name">Daniel Esbella</div>
                                        <div class="performer-role">iOS Developer</div>
                                    </div>
                                    <div class="performance-score">
                                        <div class="score-label">Performance</div>
                                        <div class="score-value">99%</div>
                                    </div>
                                </div>
                            </div> -->

                            <button class="view-all-btn">
                                <a href="manage-employees.php">View All Employees</a>
                            </button>
                        </div>

                    </div>

                </div>
            </div>

            <div class="dashboard mt-5">
                <div class="main-content">

                    <!-- ✅ Calendar section to reload dynamically -->
                    <div id="calendar-container">
                        <?php include 'calendar-section.php'; ?>
                    </div>

                    <!-- ✅ Sidebar or other sections remain static -->
                    <div class="sidebar">
                        <div class="sidebar-card">
                            <h3><img src="assets/images/analytics.png" style="width:30px"> Statistics</h3>
                            <div class="stats-grid">
                                <?php
                                $totalEvents = mysqli_num_rows(mysqli_query($db, "SELECT * FROM event_calendar WHERE event_date >= CURDATE()"));
                                $todayEvents = mysqli_num_rows(mysqli_query($db, "SELECT * FROM event_calendar WHERE event_date = CURDATE()"));
                                ?>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $totalEvents; ?></div>
                                    <div class="stat-labels text-center">Total Events</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?php echo $todayEvents; ?></div>
                                    <div class="stat-labels">Today</div>
                                </div>
                            </div>
                        </div>

                        <div class="sidebar-card">
                            <h3><img src="assets/images/bell.png" style="width:30px"> Upcoming Events</h3>
                            <?php
                            $upcomingQuery = "SELECT * FROM event_calendar WHERE event_date >= CURDATE() ORDER BY event_date LIMIT 5";
                            $upcomingResult = mysqli_query($db, $upcomingQuery);

                            if (mysqli_num_rows($upcomingResult) > 0):
                                while ($event = mysqli_fetch_assoc($upcomingResult)):
                            ?>
                                    <div class="upcoming-event"
                                        onclick='showEventModal([<?php echo json_encode($event); ?>], "<?php echo date('F d, Y', strtotime($event['event_date'])); ?>")'>
                                        <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                        <div class="event-date">🕐 <?php echo date('M d, Y', strtotime($event['event_date'])); ?></div>
                                        <?php if (!empty($event['description'])): ?>
                                            <div class="event-description"><?php echo htmlspecialchars($event['description']); ?></div>
                                        <?php endif; ?>
                                    </div>

                                <?php
                                endwhile;
                            else:
                                ?>
                                <p class="no-events">No upcoming events</p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        <?php } ?>
        </div>
        </div>
    </section>

    <!-- Event Modal -->
    <!-- Event Tooltip Overlay -->
    <div id="tooltipOverlay" class="tooltip-overlay" onclick="closeEventTooltip()"></div>

    <!-- Event Tooltip -->
    <div id="eventTooltip" class="event-tooltip">
        <div class="tooltip-header">
            <h3 id="tooltipDate"></h3>
            <button class="tooltip-close" onclick="closeEventTooltip()">&times;</button>
        </div>
        <div id="tooltipBody"></div>
    </div>

    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>
    <!--<script src="assets/js/menu-setting.min.js"></script>-->

    <script src="assets/js/plugins/jquery.dataTables.min.js"></script>
    <script src="assets/js/plugins/dataTables.bootstrap4.min.js"></script>
    <script src="assets/js/plugins/buttons.colVis.min.js"></script>
    <script src="assets/js/plugins/buttons.print.min.js"></script>
    <script src="assets/js/plugins/pdfmake.min.js"></script>
    <script src="assets/js/plugins/vfs_fonts.js"></script>
    <script src="assets/js/plugins/jszip.min.js"></script>
    <script src="assets/js/plugins/dataTables.buttons.min.js"></script>
    <script src="assets/js/plugins/buttons.html5.min.js"></script>
    <script src="assets/js/plugins/buttons.bootstrap4.min.js"></script>
    <script src="assets/js/pages/data-export-custom.js"></script>
</body>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new").load("loadpost.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#total").load("loadnewleads.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#user").load("load-category.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new1").load("loadpage.php");

        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new2").load("loadadmin.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new3").load("totaldownloads.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new4").load("loadleads.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        setInterval(function() {


            $("#new5").load("loadclients.php");


        }, 100);
    });
</script>

<script>
    $(document).ready(function() {
        $("#logged").delay(5000).slideUp(300);
    });
</script>

<script>
    function display_ct6() {
        var x = new Date();
        var ampm = x.getHours() >= 12 ? 'PM' : 'AM';
        var hours = x.getHours() % 12;
        hours = hours ? hours : 12;
        hours = String(hours).padStart(2, '0');
        var minutes = String(x.getMinutes()).padStart(2, '0');
        var seconds = String(x.getSeconds()).padStart(2, '0');

        var timeEl = document.getElementById('ct-time');
        var ampmEl = document.getElementById('ct-ampm');
        var dateEl = document.getElementById('ct-date');

        if (timeEl) timeEl.innerHTML = hours + ':' + minutes + ':' + seconds;
        if (ampmEl) ampmEl.innerHTML = ampm;

        if (dateEl) {
            var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            dateEl.innerHTML = days[x.getDay()] + ', ' + String(x.getDate()).padStart(2, '0') + ' ' + months[x.getMonth()] + ' ' + x.getFullYear();
        }

        display_c6();
    }

    function display_c6() {
        var refresh = 1000; // Refresh rate in milli seconds
        mytime = setTimeout('display_ct6()', refresh)
    }
    display_c6()
</script>


<script>
    function showEventModal(events, date) {
        const tooltip = document.getElementById('eventTooltip');
        const overlay = document.getElementById('tooltipOverlay');
        const tooltipDate = document.getElementById('tooltipDate');
        const tooltipBody = document.getElementById('tooltipBody');

        tooltipDate.textContent = date;

        let html = '';
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        events.forEach(event => {
            const eventDate = new Date(event.event_date);
            eventDate.setHours(0, 0, 0, 0);

            const isExpired = eventDate < today;
            const statusText = isExpired ? 'Expired' : 'Upcoming';
            const statusClass = isExpired ? 'expired' : 'upcoming';

            html += `
                    <div class="event-details">
                        <div class="event-detail-title">${escapeHtml(event.title)}</div>
                        ${event.description ? `<div class="event-detail-row"><strong>Description:</strong> ${escapeHtml(event.description)}</div>` : ''}
                        ${event.type ? `<div class="event-detail-row"><strong>Type:</strong> ${escapeHtml(event.type)}</div>` : ''}
                        ${event.location ? `<div class="event-detail-row"><strong>Location:</strong> ${escapeHtml(event.location)}</div>` : ''}
                        
                        <span class="event-badge ${statusClass}">${statusText}</span>
                       
                    </div>
                `;
        });

        tooltipBody.innerHTML = html;

        // Position tooltip in center
        overlay.classList.add('show');
        tooltip.classList.add('show');

        // Center the tooltip
        setTimeout(() => {
            const rect = tooltip.getBoundingClientRect();
            tooltip.style.left = `${(window.innerWidth - rect.width) / 2}px`;
            tooltip.style.top = `${Math.max(50, (window.innerHeight - rect.height) / 2)}px`;
        }, 10);
    }

    function closeEventTooltip() {
        const tooltip = document.getElementById('eventTooltip');
        const overlay = document.getElementById('tooltipOverlay');
        tooltip.classList.remove('show');
        overlay.classList.remove('show');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Close tooltip with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeEventTooltip();
        }
    });
</script>

<script>
    // AJAX month navigation
    $(document).on('click', '.nav-btn', function(e) {
        e.preventDefault();
        const month = $(this).data('month');
        const year = $(this).data('year');

        $('#calendar-container').html('<div class="loading">Loading...</div>');

        $.ajax({
            url: 'calendar-section.php',
            type: 'GET',
            data: {
                month: month,
                year: year
            },
            success: function(response) {
                $('#calendar-container').html(response);
            },
            error: function() {
                $('#calendar-container').html('<div class="loading">Error loading calendar.</div>');
            }
        });
    });
</script>

</html>