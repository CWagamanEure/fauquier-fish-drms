<?php
require_once __DIR__ . '/../include/auth.php';
require_authentication(3);
$importSessionId = session_id();
$importUserId = $_SESSION['_id'];
session_write_close();
$python_script = 'dbManager.py';
$return_var = 0;

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])){
				$fileset = $_FILES['files'];
				foreach($fileset['name'] as $index => $name){
								if($fileset['error'][$index] === UPLOAD_ERR_OK){
												// Fetch from temp directory
												$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($name);
												if(move_uploaded_file($fileset['tmp_name'][$index], $temp)){
																exec("python -u \"$python_script\" -i \"$temp\" 2>&1", $output, $return_var);
																// Re-read the session after the long import; never restore the old
																// authentication snapshot or send a replacement cookie after logout.
																session_start(['use_cookies' => false, 'cache_limiter' => '']);
																if (session_id() !== $importSessionId || !auth_is_logged_in()
																    || $_SESSION['_id'] !== $importUserId || $_SESSION['access_level'] < 3) {
																    session_abort();
																    unlink($temp);
																    header('Location: ' . auth_url('login.php'), true, 303);
																    exit;
																}
																$_SESSION["importStatus"] = $output;
																session_write_close();
																if($return_var === 0){
																				header("Location: ../import.php?success=1&file=$name");
																				
																} else{
																				header("Location: ../import.php?success=1&file=$name");
																}
																unlink($temp);
												}
												else {
																header("Location: ../import.php?success=0");
												}
								} else {
												header("Location: ../import.php?success=0");
								}
				}
} else {
				header("Location: ../import.php?success=0");
}
?>
