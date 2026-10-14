<?php
include_once('dbinfo.php');
function add_person($person) {
	if (!$person instanceof Person) {
		die("Error: add_person type mismatch");
	}

	$con = connect();
	$id = $person->get_id();
	$name = $person->get_name();
	$password = $person->get_password();
	$level = $person->get_access_level();

	// Returns false when the username is already taken. Values are bound, never interpolated.
	$stmt = $con->prepare('SELECT 1 FROM dbpersons WHERE id = ?');
	$stmt->bind_param('s', $id);
	$stmt->execute();
	$exists = $stmt->get_result()->num_rows > 0;
	$stmt->close();
	if ($exists) {
		mysqli_close($con);
		return false;
	}

	$stmt = $con->prepare('INSERT INTO dbpersons (id, name, password, accessLevel) VALUES (?, ?, ?, ?)');
	$stmt->bind_param('sssi', $id, $name, $password, $level);
	$ok = $stmt->execute();
	$stmt->close();
	mysqli_close($con);
	return (bool) $ok;
}




function remove_person($id) {
	$con=connect();
	$query = 'SELECT * FROM dbpersons WHERE id = "' . $id . '"';
	$result = mysqli_query($con,$query);
	if ($result == null || mysqli_num_rows($result) == 0) {
		mysqli_close($con);
		return false;
	}
	$query = 'DELETE FROM dbpersons WHERE id = "' . $id . '"';
	$result = mysqli_query($con,$query);
	mysqli_close($con);
	return true;
}

/*
 * @return a Person from dbPersons table matching a particular id.
 * if not in table, return false
 */

function retrieve_person($id) { // (username! not id)
	$con=connect();
	$stmt = $con->prepare('SELECT id, name, password, accessLevel, role, status, first_name, last_name, email FROM dbpersons WHERE id = ?');
	$stmt->bind_param('s', $id);
	$stmt->execute();
	$result = $stmt->get_result();
	if (mysqli_num_rows($result) !== 1) {
		$stmt->close();
		mysqli_close($con);
		return false;
	}
	$result_row = mysqli_fetch_assoc($result);
	$stmt->close();
	mysqli_close($con);
	$thePerson = make_a_person($result_row);
	return $thePerson;
}

// Name is first concat with last name. Example 'James Jones'
// return array of Persons.
function retrieve_persons_by_name ($name) {
	$persons = array();
	if (!isset($name) || $name == "" || $name == null) return $persons;
	$con=connect();
	$name = explode(" ", $name);
	$first_name = $name[0];
	$last_name = $name[1];
	$query = "SELECT * FROM dbpersons WHERE first_name = '" . $first_name . "' AND last_name = '". $last_name ."'";
	$result = mysqli_query($con,$query);
	while ($result_row = mysqli_fetch_assoc($result)) {
		$the_person = make_a_person($result_row);
		$persons[] = $the_person;
	}
	return $persons;	
}

function change_password($id, $newPass) {
	$con=connect();
	$query = 'UPDATE dbpersons SET password = "' . $newPass . '" WHERE id = "' . $id . '"';
	$result = mysqli_query($con,$query);
	mysqli_close($con);
	return $result;
}

function reset_password($id, $newPass) {
	$con=connect();
	$query = 'UPDATE dbpersons SET password = "' . $newPass . '" WHERE id = "' . $id . '"';
	$result = mysqli_query($con,$query);
	mysqli_close($con);
	return $result;
}

/*
 * @return all rows from dbPersons table ordered by last name
 * if none there, return false
 */

function getall_dbPersons($name_from, $name_to, $venue) {
	$con=connect();
	$query = "SELECT * FROM dbpersons";
	$query.= " WHERE venue = '" .$venue. "'"; 
	$query.= " AND last_name BETWEEN '" .$name_from. "' AND '" .$name_to. "'"; 
	$query.= " ORDER BY last_name,first_name";
	$result = mysqli_query($con,$query);
	if ($result == null || mysqli_num_rows($result) == 0) {
		mysqli_close($con);
		return false;
	}
	$result = mysqli_query($con,$query);
	$thePersons = array();
	while ($result_row = mysqli_fetch_assoc($result)) {
		$thePerson = make_a_person($result_row);
		$thePersons[] = $thePerson;
	}

	return $thePersons;
}



function get_total_volunteers_count($date) {
	$con = connect();

	$query = "SELECT COUNT(*) as total FROM dbpersons 
		WHERE id != 'vmsroot' 
		AND STR_TO_DATE(start_date, '%Y-%m-%d') <= ? 
		AND archived = 0";  // Ensure only active volunteers are counted

	$stmt = $con->prepare($query);
	$stmt->bind_param("s", $date);
	$stmt->execute();
	$result = $stmt->get_result();

	$row = $result->fetch_assoc();
	mysqli_close($con);

	return $row['total'] ?? 0;
}


// new method for report generation GETTING THE TOTAL NEW VOLUNTEER COUNT: YALDA
function get_new_volunteers_count($dateFrom, $dateTo) {
	$con = connect();

	$query = "SELECT COUNT(*) AS count FROM dbpersons 
		WHERE STR_TO_DATE(start_date, '%Y-%m-%d') BETWEEN ? AND ?";

	$stmt = $con->prepare($query);
	$stmt->bind_param("ss", $dateFrom, $dateTo);
	$stmt->execute();
	$result = $stmt->get_result();
	$row = $result->fetch_assoc();

	return $row['count'] ?? 0;
}


function make_a_person($result_row) {
	require_once __DIR__ . '/../domain/Person.php';
	$thePerson = new Person(
		$result_row['id'],
		$result_row['password'],
		$result_row['name'],
		$result_row['accessLevel'],
		$result_row['role'] ?? null,
		$result_row['status'] ?? null,   // unknown status is treated as not active
		$result_row['first_name'] ?? null,
		$result_row['last_name'] ?? null,
		$result_row['email'] ?? null
	);

	return $thePerson;
}

function find_users($name, $id, $phone, $zip, $type, $status) {
	$where = 'where ';
	if (!($name || $id || $phone || $zip || $type || $status)) {  // ✅ Fixed parentheses
		return [];
	}
	$first = true;
	if ($name) {
		if (strpos($name, ' ')) {
			$name = explode(' ', $name, 2);
			$first = $name[0];
			$last = $name[1];
			$where .= "first_name like '%$first%' and last_name like '%$last%'";
		} else {
			$where .= "(first_name like '%$name%' or last_name like '%$name%')";
		}
		$first = false;
	}
	if ($id) {
		if (!$first) {
			$where .= ' and ';
		}
		$where .= "id like '%$id%'";
		$first = false;
	}
	if ($phone) {
		if (!$first) {
			$where .= ' and ';
		}
		$where .= "phone1 like '%$phone%'";
		$first = false;
	}
	if ($zip) {
		if (!$first) {
			$where .= ' and ';
		}
		$where .= "zip_code like '%$zip%'";
		$first = false;
	}
	if ($type) {
		if (!$first) {
			$where .= ' and ';
		}
		$where .= "type='$type'";
		$first = false;
	}
	if ($status) {
		if (!$first) {
			$where .= ' and ';
		}
		$where .= "status='$status'";
		$first = false;
	}
	//if ($photo_release) {
	//  if (!$first) {
	//    $where .= ' and ';
	//}
	//$where .= "photo_release='$photo_release'";
	//$first = false;
	// }
	$query = "select * from dbpersons $where order by last_name, first_name";
	// echo $query;
	$connection = connect();
	$result = mysqli_query($connection, $query);
	if (!$result) {
		mysqli_close($connection);
		return [];
	}
	$raw = mysqli_fetch_all($result, MYSQLI_ASSOC);
	$persons = [];
	foreach ($raw as $row) {
		if ($row['id'] == 'vmsroot') {
			continue;
		}
		$persons []= make_a_person($row);
	}
	mysqli_close($connection);
	return $persons;
}

function searchUsers($query) {
	$conn = connect();

	// Prepare the SQL query
	$stmt = $conn->prepare("SELECT id FROM dbpersons WHERE id LIKE CONCAT(?, '%') LIMIT 10");
	$stmt->bind_param("s", $query);
	$stmt->execute();

	// Get the results
	$result = $stmt->get_result();
	$data = [];

	while ($row = $result->fetch_assoc()) {
		$data[] = $row['id']; 
	}       

	// Close statement and connection 
	$stmt->close();
	$conn->close();

	return $data; // Instead of echo, return the data
}



date_default_timezone_set("America/New_York");

function get_events_attended_by($personID) {
	$today = date("Y-m-d");
	$query = "select * from dbeventpersons, dbevents
		where userID='$personID' and eventID=id
		and date<='$today'
		order by date asc";
	$connection = connect();
	$result = mysqli_query($connection, $query);
	if ($result) {
		require_once('include/time.php');
		$rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
		mysqli_close($connection);
		foreach ($rows as &$row) {
			$row['duration'] = calculateHourDuration($row['startTime'], $row['endTime']);
		}
		unset($row); // suggested for security
		return $rows;
	} else {
		mysqli_close($connection);
		return [];
	}
}





function get_name_from_id($id) {
	if ($id == 'vmsroot') {
		return 'System';
	}
	$query = "select first_name, last_name from dbpersons
		where id='$id'";
	$connection = connect();
	$result = mysqli_query($connection, $query);
	if (!$result) {
		return null;
	}

	$row = mysqli_fetch_assoc($result);
	mysqli_close($connection);
	return $row['first_name'] . ' ' . $row['last_name'];
}
