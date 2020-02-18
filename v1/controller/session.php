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

// only allow proper request method
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') handle_post($write_db);
else if ($method === 'DELETE') handle_delete($write_db);
else if ($method === 'PATCH') handle_delete($write_db);
else returnErrorResponse(405, 'Request method not allowed');

/*
 * HANDLE POST REQUEST - GENERATE TOKEN
 */ 
function handle_post($db) {

  // header must be json
  if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
    returnErrorResponse(400, 'Content type header is not set to JSON');
  }

  // body must be json
  $raw_post_data = file_get_contents('php://input');
  if (!$json_data = json_decode($raw_post_data)) {
    returnErrorResponse(400, 'Request body is not valid JSON');
  }

  $errors = [];
  $username = trim($json_data->username);
  $password = $json_data->password;
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
    returnErrorResponse(400, $errors);
  }

  try {

    $query = $db->prepare('
      SELECT id, fullname, username, password, useractive, loginattempts
      FROM users
      WHERE username = :username
    ');
    $query->execute([$username]);

    $row_count = $query->rowCount();

    if ($row_count === 0) {
      returnErrorResponse(401, 'Username or Password is incorrect');
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_id = $row['id'];
    $returned_fullname = $row['fullname'];
    $returned_username = $row['username'];
    $returned_password = $row['password'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];

    // is the user active
    if ($returned_useractive !== 'Y') {
      returnErrorResponse(401, 'User account not active');
    }

    // exceeded login attempts
    if ($returned_loginattempts >= 3) {
      returnErrorResponse(401, 'User account is currently locked out');
    }

    // validate password
    if (!password_verify($password, $returned_password)) {
      $query = $db->prepare('
        UPDATE users SET loginattempts = '.$returned_loginattempts++.' WHERE id = :id 
      ');
      $query->exectute([$returned_id]);
      returnErrorResponse(401, 'Username or Password is incorrect');
    }

    // create access token and refresh token - add unix time to end to eliminate chance of stale token usage
    $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24))).time();
    $access_token_expiry_seconds = 60 * 60; // 1 hour
    $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24))).time();
    $refresh_token_expiry_seconds = 60 * 60 * 24 * 7 * 2; // 2 weeks
    
  }
  catch (PDOException $e) {
    returnErrorResponse(500, 'There was an issue logging in');
  }

  try {
    $db->beginTransaction();

    $query = $db->prepare('
      UPDATE users SET loginattempts = 0 WHERE id = :id
    ');
    $query->execute([$returned_id]);

    $query = $db->prepare('
      INSERT INTO sessions (
        userid,
        accesstoken,
        accesstokenexpiry,
        refreshtoken,
        refreshtokenexpiry
      ) VALUES (
        :userid,
        :access_token,
        DATE_ADD(NOW(), INTERVAL :access_token_expiry_seconds SECOND),
        :refresh_token,
        DATE_ADD(NOW(), INTERVAL :refresh_token_expiry_seconds SECOND)
      )
    ');
    $query->execute([$returned_id, $access_token, $access_token_expiry_seconds, $refresh_token, $refresh_token_expiry_seconds]);

    $last_session_id = $db->lastInsertId();

    $db->commit();

    $return_data = [];
    $return_data['session_id'] = intval($last_session_id);
    $return_data['access_token'] = $access_token;
    $return_data['access_token_expires_in'] = $access_token_expiry_seconds;
    $return_data['refresh_token'] = $refresh_token;
    $return_data['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->setData($return_data);
    $response->send();
    exit;

  }
  catch (PDOException $e) {
    $db->rollBack();
    returnErrorResponse(500, 'There was an issue logging in - please try again');
  }
}

/*
 * HANDLE PATCH REQUEST - REFRESH TOKEN
 */
function handle_patch() {
  if (array_key_exists('sessionid', $_GET)) {
    
  }
  else {
    returnErrorResponse(404, 'Endpoint not found');
  }
}

/*
 * HANDLE DELETE REQUEST - LOGOUT
 */
function handle_delete() {
  if (array_key_exists('sessionid', $_GET)) {
    
  }
  else {
    returnErrorResponse(404, 'Endpoint not found');
  }
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