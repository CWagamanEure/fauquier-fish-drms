<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(1);
    header("refresh:2; url=viewAllEvents.php");
?>
    <!DOCTYPE html>
    <html>
        <head>
            <?php require_once('universal.inc') ?>
            <title>Rappahannock CASA | Sign-Up for Event</title>
        </head>
        <body>
            <?php require_once('header.php') ?>
            <h1>Oops! You are already on the sign-up waitlist for this event.</h1>
        </body>
    </html>

    <?php
    exit();
    ?>