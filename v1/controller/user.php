<?php

require_once('db.php');
require_once('../model/Response.php');

// db connect
try {
  $write_db = DB::connectWriteDB();
}
catch (PDOException $e) {
  error_log('Connection Error: '.$e, 0);
  returnErrorResponse(500, 'Database connection error');
}

// only accept post method
if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
  returnErrorResponse(405, 'Request method not allowed');
}

// header must be json
if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
  returnErrorResponse(400, 'Content type header is not set to JSON');
}

// body must be json
$raw_post_data = file_get_contents('php://input');
if (!$json_data = json_decode($raw_post_data)) {
  returnErrorResponse(400, 'Request body is not valid JSON');
}

$fullname = trim($json_data->fullname);
$username = trim($json_data->username);
$password = $json_data->password;

$errors = [];
// validate fullname
if (!isset($fullname)) $errors[]         = 'Fullname not provided';
else {
  if (strlen($fullname) < 1) $errors[]   = 'Fullname cannot be blank';
  if (strlen($fullname > 255)) $errors[] = 'Fullname cannot be longer than 255 characters';
}

// validate username
if (!isset($username)) $errors[]         = 'Username not provided';
else {
  if (strlen($username) < 1) $errors[]   = 'Username cannot be blank';
  if (strlen($username > 255)) $errors[] = 'Username cannot be longer than 255 characters';
}

// validate password
if (!isset($password)) $errors[]         = 'Password not provided';
else {
  if (strlen($password) < 1) $errors[]   = 'Password cannot be blank';
  if (strlen($password > 255)) $errors[] = 'Password cannot be longer than 255 characters';
}

if (!empty($errors)) {
  echo '1';
  returnErrorResponse(400, $errors);
}
try {

  // does user already exist 
  $query = $write_db->prepare('SELECT id FROM users WHERE username = :username');
  $query->execute([$username]);
  $row_count = $query->rowCount();
  if ($row_count !== 0) {
    returnErrorResponse(409, 'Username already exists');
  }

  $hashed_password = password_hash($password, PASSWORD_DEFAULT);

  // create user
  $query = $write_db->prepare('
    INSERT INTO users (
      fullname, username, password
    ) VALUES (
      :fullname, :username, :hashed_password
    )
  ');
  $query->execute([$fullname, $username, $hashed_password]);

  $row_count = $query->rowCount();

  if ($row_count === 0) {
    returnErrorResponse(500, 'Issue creating a user account - please try again');
  }

  // return to client
  $last_user_id = $write_db->lastInsertId();
  $return_data = [];
  $return_data['user_id'] = $last_user_id;
  $return_data['fullname'] = $fullname;
  $return_data['username'] = $username;

  $response = new Response();
  $response->setHttpStatusCode(201);
  $response->setSuccess(true);
  $response->addMessage('User created');
  $response->setData($return_data);
  $response->send();
  exit;
}
catch (PDOException $e) {
  error_log('Database Query Error: '.$e, 0);
  returnErrorResponse(500, 'Issue creating a user account - please try again');
}


// return error response
function returnErrorResponse($code, $messages) {
  $response = new Response();
  $response->setHttpStatusCode($code);
  $response->setSuccess(false);
  if (is_array($messages)) {
    foreach ($messages as $message) {
      $response->addMessage($message);
    }
  } else $response->addMessage($messages);
  $response->send();
  exit;
}