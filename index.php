<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    date_default_timezone_set("America/New_York");
    
    // require_once: include/auth.php already loads these for the per-request account check
    require_once 'database/dbPersons.php';
    require_once 'domain/Person.php';

    $person = auth_current_user();   // loaded by require_authentication()

    $isStandardUser = ($_SESSION['access_level'] === 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;700&display=swap" rel="stylesheet">
    <title>CASA Donation Management Web Application</title>
    <link rel="icon" type="image/png" href="images/RAPPAHANNOCK_v_RedBlue2.png">

    <script src="https://kit.fontawesome.com/yourkit.js" crossorigin="anonymous"></script>

    <style>
        /* --- GLOBAL RESETS --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Quicksand', sans-serif;
            background-color: #f9f9f9; /* Subtle background color */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        h2 {
            font-weight: normal;
            font-size: 30px;
            color: #333;
        }

        /* --- DASHBOARD LAYOUT --- */
        .full-width-bar {
            width: 100%;
            background: #00447b;
            padding: 30px 5%;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 25px;
        }

        .full-width-bar-sub {
            width: 100%;
            background: white;
            padding: 30px 5%;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 25px;
        }

        /* --- TOP ROW CARDS (Blue Section) --- */
        .content-box {
            flex: 1 1 280px;
            max-width: 375px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            padding-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); /* Added shadow for depth */
        }

        .content-box img {
            width: 100%;
            height: auto;
            border-bottom: 1px solid #ddd;
        }

        .small-text {
            margin-top: 15px;
            font-size: 14px;
            font-weight: 700;
            color: #00447b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .large-text {
            margin-top: 5px;
            font-size: 22px;
            font-weight: 700;
            color: #333;
            margin-bottom: 50px; /* Space for the button */
        }

        /* --- BOTTOM ROW CARDS (White Section) --- */
        .content-box-test {
            flex: 1 1 250px;
            max-width: 350px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            cursor: pointer;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
            border-radius: 12px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .content-box-test:hover {
            border-color: #007BFF;
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,123,255,0.15);
        }

        .content-box-test img.background-image {
            width: 100%;
            border-radius: 8px;
            opacity: 0.1; /* Faded background effect */
        }

        .large-text-sub {
            font-size: 20px;
            font-weight: 700;
            color: #333;
            margin-top: 15px;
            z-index: 2;
        }

        .graph-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
            z-index: 2;
        }

        /* --- ICONS & BUTTONS --- */
        .icon-overlay {
            position: absolute;
            top: 20%;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 2;
        }

        .icon-overlay img {
            width: 30px;
            height: 30px;
        }

        /* Circle Go Button */
        .circle-arrow-button {
            position: absolute;
            bottom: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
            border: none;
            font-family: 'Quicksand', sans-serif;
            font-weight: bold;
            color: #00447b;
            cursor: pointer;
            font-size: 16px;
        }

        .circle {
            width: 35px;
            height: 35px;
            background-color: #00447b;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: transform 0.3s ease, background 0.3s;
        }

        .circle-arrow-button:hover .circle {
            transform: translateX(5px);
            background-color: #0066cc;
        }

        /* Standard Arrow Button */
        .arrow-button {
            margin-top: 15px;
            background: none;
            border: none;
            font-size: 24px;
            color: #00447b;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        .content-box-test:hover .arrow-button {
            transform: translateX(5px);
        }
   </style>
</head>

<body>

    <?php require 'header.php';?>

    <?php if ($_SESSION['access_level'] >= 2): ?>

       <div style="margin-top: 20px; padding: 30px 5%;">
             <h2><b>Welcome <?php echo htmlspecialchars($person->get_name()); ?>!</b> Let's get started.</h2>
       </div>

       <div class="full-width-bar">
            <?php if ($_SESSION['access_level'] >= 3): ?>
            <div class="content-box">
                <img src="images/blank-white-background.jpg"/>
                <div class="small-text">Analyze the data!</div>
                <div class="large-text">Generate Report</div>
                <button class="circle-arrow-button" onclick="window.location.href='ReportGeneration.php'">
                    <span class="button-text">Go</span>
                    <div class="circle">&gt;</div>
                </button>
            </div>
            <?php endif; ?>

            <div class="content-box">
                <img src="images/blank-white-background.jpg"/>
                <div class="small-text">Let’s raise some money!</div>
                <div class="large-text">Event Management</div>
                <button class="circle-arrow-button" onclick="window.location.href='viewAllEvents.php'">
                    <span class="button-text">Go</span>
                    <div class="circle">&gt;</div>
                </button>
            </div>
       </div>

        <div style="margin-top: 50px; padding: 0 5%;">
            <h2><b>Dashboard</b></h2>
        </div>

        <div class="full-width-bar-sub">
            <?php if ($_SESSION['access_level'] >= 3): ?>
            <div class="content-box-test" onclick="window.location.href='import.php'">
                <div class="icon-overlay">
                    <img src="images/toppng.com-file-save-icon-vector-980x980.png" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Import Files</div>
                <button class="arrow-button">→</button>
            </div>

            <div class="content-box-test" onclick="window.location.href='export.php'">
                <div class="icon-overlay">
                    <img src="images/toppng.com-ms-excel-icon-microsoft-powerpoint-icon-1557x1588.png" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Export Files</div>
                <button class="arrow-button">→</button>
            </div>
	    <div class="content-box-test" onclick="window.location.href='VolunteerRegister.php'">
                <div class="icon-overlay">
                    <img src="images/add-person.svg" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Create New User</div>
                <button class="arrow-button">→</button>
            </div>


            <div class="content-box-test" onclick="window.location.href='emailgen.php'">
                <div class="icon-overlay">
                    <img src="images/toppng.com-email-icon-vector-circle-980x980.png" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Generate Emails</div>
                <button class="arrow-button">→</button>
            </div>

	    <div class="content-box-test" onclick="window.location.href='addDonor.php'">
                <div class="icon-overlay">
                    <img src="images/plus-solid.svg" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Add Donors</div>
                <button class="arrow-button">→</button>
            </div>


            <div class="content-box-test" onclick="window.location.href='donationAddEdit.php'">
                <div class="icon-overlay">
                    <img src="images/toppng.com-dollar-sign-circle-svg-icon-free-dollar-sign-svg-icon-980x982.png" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Add/Edit Donations</div>
                <button class="arrow-button">→</button>
            </div>

            <?php endif; ?>
            <div class="content-box-test" onclick="window.location.href='searchindex.php'">
                <div class="icon-overlay">
                    <img src="images/magnifying-glass.svg" alt="Icon">
                </div>
                <img class="background-image" src="images/blank-white-background.jpg" />
                <div class="large-text-sub">Search</div>
                <button class="arrow-button">→</button>
            </div>
        </div>
    <?php endif; ?>


    <?php if ($isStandardUser): ?>
        <div style="margin-top: 20px; padding: 30px 5%;">
            <h2><b>Welcome <?php echo htmlspecialchars($person->get_name()); ?>!</b></h2>
            <p style="margin-top: 15px;">View shared documents and manage your password below.</p>
        </div>

        <div class="full-width-bar">
            <div class="content-box">
                <img src="images/blank-white-background.jpg" alt="">
                <div class="small-text">Shared resources</div>
                <div class="large-text">Documents</div>
                <a class="circle-arrow-button" href="viewResources.php">
                    <span class="button-text">View documents</span>
                    <span class="circle" aria-hidden="true">&gt;</span>
                </a>
            </div>
            <div class="content-box">
                <img src="images/blank-white-background.jpg" alt="">
                <div class="small-text">My account</div>
                <div class="large-text">Change Password</div>
                <a class="circle-arrow-button" href="changePassword.php">
                    <span class="button-text">Change password</span>
                    <span class="circle" aria-hidden="true">&gt;</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div style="width: 90%; height: 1px; background: #ddd; margin: 70px auto;"></div>

    <?php require_once('footer.php'); ?>

</body>
</html>
