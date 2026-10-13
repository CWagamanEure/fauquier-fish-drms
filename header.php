<?php
require_once __DIR__ . '/include/auth.php';
date_default_timezone_set('America/New_York');
/*
 * Copyright 2013 by Allen Tucker. */

// check if we are in locked mode
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <link rel="icon" type="image/png" href="images/RAPPAHANNOCK_v_RedBlue2.png">

  <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;700&display=swap" rel="stylesheet">

  <style>
    <?php if (empty($tailwind_mode)): ?>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    <?php endif; ?>
    body {
      font-family: 'Quicksand', sans-serif;
      padding-top: 80px; /* Space for fixed header */
    }

    /* nav */
    .navbar {
      width: 100%;
      height: 80px;
      position: fixed;
      top: 0;
      left: 0;
      background: white;
      box-shadow: 0px 2px 8px rgba(0, 0, 0, 0.15);
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0 20px;
      z-index: 1000;
      box-sizing: border-box;
    }

    /* Logo Container - FIXED STABILITY */
    .logo-container {
      background: #00447b;
      padding: 5px 15px;
      border-radius: 50px;
      display: flex;
      align-items: center;
      justify-content: center;

      /* New Stability Rules to prevent "smashing" */
      box-sizing: border-box !important;
      height: 60px;
      width: auto;
      flex-shrink: 0;
    }

    .logo-container a {
        display: flex;
        align-items: center;
        height: 100%;
    }

    .logo-container img {
      height: 50px !important; /* Force exact height */
      width: auto !important;  /* Force aspect ratio */
      max-width: none !important;
      display: block;
      filter: brightness(1000%); /* Keeps your white logo effect */
    }

    /* Hamburger Icon */
    .hamburger-icon {
      cursor: pointer;
      padding: 10px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      z-index: 1002;
    }
    .bar {
      width: 30px;
      height: 3px;
      background-color: #00447b;
      border-radius: 5px;
      transition: 0.3s;
    }

    /* Animation for X transformation */
    .hamburger-icon.open .bar:nth-child(1) { transform: rotate(-45deg) translate(-7px, 6px); }
    .hamburger-icon.open .bar:nth-child(2) { opacity: 0; }
    .hamburger-icon.open .bar:nth-child(3) { transform: rotate(45deg) translate(-7px, -6px); }

    /* side menu */
    .side-menu {
      height: 100%;
      width: 0;
      position: fixed;
      z-index: 1001;
      top: 0;
      right: 0;
      background-color: #00447b;
      overflow-x: hidden;
      transition: 0.4s;
      padding-top: 80px;
      box-shadow: -4px 0 10px rgba(0,0,0,0.2);
    }

    .side-menu.open {
      width: 300px;
    }

    .side-menu .sign-out-button {
      width: 100%;
      background: transparent;
      border: 0;
      font-family: inherit;
      cursor: pointer;
      text-align: left;
    }

    /* Menu Links */
    .side-menu a, .side-menu .sign-out-button {
      padding: 15px 30px;
      text-decoration: none;
      font-size: 20px;
      color: #f1f1f1;
      display: block;
      transition: 0.2s;
      border-bottom: 1px solid rgba(255,255,255,0.1);
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .side-menu a:hover, .side-menu .sign-out-button:hover {
      background-color: #005a9e;
      color: #fff;
      padding-left: 40px;
    }

    .side-menu .menu-section-title {
        color: #7aacf5;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 1px;
        padding: 20px 30px 5px 30px;
        margin-top: 10px;
        font-weight: bold;
    }

    .side-menu img {
        width: 24px;
        height: 24px;
        filter: brightness(0) invert(1);
    }

    /* Overlay Background */
    .menu-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: 0.3s;
    }
    .menu-overlay.open {
      opacity: 1;
      visibility: visible;
    }

    /* --- DATE DISPLAY --- */
    .header-date {
        font-weight: bold;
        color: #00447b;
        margin-right: 20px;
        display: none;
        white-space: nowrap;
    }
    @media (min-width: 768px) {
        .header-date { display: block; }
    }

  </style>

  <script>
    function toggleMenu() {
        const menu = document.getElementById("mySideMenu");
        const icon = document.getElementById("hamburgerIcon");
        const overlay = document.getElementById("menuOverlay");

        menu.classList.toggle("open");
        icon.classList.toggle("open");
        overlay.classList.toggle("open");
    }
  </script>
</head>


<header>



<div class="navbar">
    <div class="logo-container">
        <a href="index.php">
            <img src="images/RAPPAHANNOCK_v_RedBlue2.png" alt="Logo">
        </a>
    </div>

    <div style="display: flex; align-items: center; gap: 20px;">
        <div class="header-date"><?php echo date('l, F j, Y'); ?></div>

        <div class="hamburger-icon" id="hamburgerIcon" onclick="toggleMenu()">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>
    </div>
</div>

<div class="menu-overlay" id="menuOverlay" onclick="toggleMenu()"></div>

<div id="mySideMenu" class="side-menu">

    <?php if (!isset($_SESSION['logged_in'])): ?>


    <?php elseif ($_SESSION['logged_in']): ?>

        <a href="index.php">Dashboard</a>
        <a href="viewResources.php">Documents</a>
        <a href="changePassword.php">Change Password</a>

        <?php if ($_SESSION['access_level'] >= 2): ?>
        <a href="calendar.php">Calendar</a>
        <a href="viewAllEvents.php">Event Management</a>
            <div class="menu-section-title">Tools</div>
            <a href="searchindex.php">
                <img src="images/search.svg"> Search Database
            </a>
        <?php endif; ?>

        <?php if ($_SESSION['access_level'] >= 3): ?>
            <a href="VolunteerRegister.php">
                <img src="images/add-person.svg"> Add Users
            </a>
            <a href="emailgen.php">
                <img src="images/toppng.com-email-icon-vector-circle-980x980.png"> Email Gen
            </a>
            <a href="ReportGeneration.php">
                <img src="images/clipboard-regular.svg"> Report Gen
            </a>

            <div class="menu-section-title">Data Management</div>

	    <a href="addDonor.php">
                <img src="images/plus-solid.svg"> Add Donor
            </a>

	    <a href="donationAddEdit.php">
                <img src="images/toppng.com-dollar-sign-circle-svg-icon-free-dollar-sign-svg-icon-980x982.png"> Add/Edit Donations
            </a>


            <a href="import.php">
                 <img src="images/toppng.com-file-save-icon-vector-980x980.png"> Import Data
            </a>
            <a href="export.php">
                 <img src="images/toppng.com-ms-excel-icon-microsoft-powerpoint-icon-1557x1588.png"> Export Data
            </a>

        <?php endif; ?>

        <div style="margin-top: 30px; border-top: 1px solid rgba(255,255,255,0.2);">
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="sign-out-button" style="color: #ffcccc;">Sign Out</button>
            </form>
        </div>

    <?php endif; ?>
</div>

</header>
