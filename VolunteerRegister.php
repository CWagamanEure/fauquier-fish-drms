<?php
require_once __DIR__ . '/include/auth.php';
require_authentication(3);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !auth_valid_username($_POST['username'] ?? null)) {
    http_response_code(400);
    exit('That username is empty, invalid, or too long. Please return to the form and try again.');
}
    require_once('include/input-validation.php');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Rappahannock CASA | Register</title>
    <link href="css/normal_tw.css" rel="stylesheet">
<?php
$tailwind_mode = true;
require_once('header.php');
?>
<style>
    .date-box {
        background: #274471;
        padding: 7px 30px;
        border-radius: 50px;
        box-shadow: -4px 4px 4px rgba(0, 0, 0, 0.25) inset;
        color: white;
        font-size: 24px;
        font-weight: 700;
        text-align: center;
    }
    .dropdown {
        padding-right: 50px;
    }
</style>
</head>
<body class="relative flex flex-col min-h-screen">
<?php
    require_once('domain/Person.php');
    require_once('database/dbPersons.php');

    $showPopup = false;

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $ignoreList = array('password', 'password-reenter');
        $args = sanitize($_POST, $ignoreList);

        $required = array(
            'first_name', 'last_name',

            'username', 'password'
        );

        $errors = false;

        if (!wereRequiredFieldsSubmitted($args, $required)) {
            $errors = true;
        }

        // Stored as HTML text (matching existing rows) and inserted with a prepared statement,
        // so no SQL escaping is applied here. The id uses the one shared stored-username form.
        $name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
              . " " . htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $id = auth_stored_username($_POST['username'] ?? '');

        $password = password_hash($args['password'], PASSWORD_BCRYPT);

        if ($errors) {
            echo '<p class="error">Your form submission contained unexpected or invalid input.</p>';
            die();
        }

        $newperson = new Person(
            $id, $password, $name, 1 // New users start with standard-user access.
        );

        $result = add_person($newperson);
        if (!$result) {
            $showPopup = true;
        } else {
            echo '<script>document.location = "VolunteerRegister.php";</script>';
        }
    }
?>

<?php if ($showPopup): ?>
<div id="popupMessage" class="absolute left-[40%] top-[20%] z-50 bg-red-800 p-4 text-white rounded-xl text-xl shadow-lg">
    That username is already taken.
</div>
<?php endif; ?>

<script>
window.addEventListener('DOMContentLoaded', () => {
    const popup = document.getElementById('popupMessage');
    if (popup) {
        popup.style.transition = 'opacity 0.5s ease';
        setTimeout(() => {
            popup.style.opacity = '0';
            setTimeout(() => {
                popup.style.display = 'none';
            }, 500);
        }, 4000);
    }
});
</script>

<?php require_once('registrationForm.php'); ?>

<?php require_once('footer.php'); ?>

</body>
</html>
