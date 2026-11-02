<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(1);
    header("refresh:2;url=addDonor.php");
?>

    <!DOCTYPE html>
    <html>
        <head>
            <?php require_once('universal.inc') ?>
            <title>Rappahannock CASA | Add Donor</title>
        </head>
        <body>
            <?php require_once('header.php') ?>
            <h1>Donor Added!</h1>
        </body>
    </html>