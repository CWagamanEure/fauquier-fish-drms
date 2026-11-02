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
            <style>
            .centered {
            text-align: center;
            }
            </style>

            <?php require_once('header.php') ?>
            <h1>Sign-Up Request Sent!</h1>
            <p class="centered">The administrator will review your request shortly</p>
        </body>
    </html>