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
else if ($method === 'PATCH') handle_patch($write_db);
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
    $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
    $access_token_expiry_seconds = 60 * 60; // 1 hour
    $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
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
function handle_patch($db) {
  if (array_key_exists('sessionid', $_GET)) {

    // header must be json
    if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      returnErrorResponse(400, 'Content type header is not set to JSON');
    }

    // body must be json
    $raw_post_data = file_get_contents('php://input');
    if (!$json_data = json_decode($raw_post_data)) {
      returnErrorResponse(400, 'Request body is not valid JSON');
    }

    // get session id
    $session_id = $_GET['sessionid'];
    if ($session_id === '') returnErrorResponse(400, 'Session ID cannot be blank');
    if (!is_numeric($session_id)) returnErrorResponse(400, 'Session ID must be numeric');

    // get authentication header
    if (!isset($_SERVER['HTTP_AUTHORIZATION'])) returnErrorResponse(401, 'Access token is missing from the header');
    if (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) returnErrorResponse(401, 'Access token cannot be blank');

    $access_token = $_SERVER['HTTP_AUTHORIZATION'];

    // refresh token provided
    if (!isset($json_data->refresh_token)) returnErrorResponse(400, 'Refresh token not supplied');
    if (strlen($json_data->refresh_token) < 1) returnErrorResponse(400, 'Refresh token cannot be blank');

    try {

      $refresh_token = $json_data->refresh_token;

      $query = $db->prepare('
        SELECT
          s.id as session_id,
          s.userid as user_id,
          s.accesstoken,
          s.accesstokenexpiry,
          s.refreshtoken,
          s.refreshtokenexpiry,
          u.useractive,
          u.loginattempts
        FROM
          sessions as s,
          users as u
        WHERE u.id = s.userid
        AND s.id = :session_id
        AND s.accesstoken = :access_token
        AND s.refreshtoken = :refresh_token
      ');
      $query->execute([$session_id, $access_token, $refresh_token]);

      $row_count = $query->rowCount();

      if ($row_count === 0) {
        returnErrorResponse(401, 'Access token or refresh token is incorrect for session id');
      }

      $row = $query->fetch(PDO::FETCH_ASSOC);

      $returned_session_id = $row['session_id'];
      $returned_user_id = $row['user_id'];
      $returned_access_token = $row['accesstoken'];
      $returned_access_token_expiry = $row['accesstokenexpiry'];
      $returned_refresh_token = $row['refreshtoken'];
      $returned_refresh_token_expiry = $row['refreshtokenexpiry'];
      $returned_useractive = $row['useractive'];
      $returned_loginattempts = $row['loginattempts'];

      if ($returned_useractive !== 'Y') returnErrorResponse(401, 'User account is not active');
      if ($returned_loginattempts >= 3) returnErrorResponse(401, 'User account is currently locked out');

      // refresh token expired
      if (strtotime($returned_refresh_token_expiry) < time()) returnErrorResponse(401, 'Refresh token has expired - please log in again');

      // create access token and refresh token - add unix time to end to eliminate chance of stale token usage
      $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
      $access_token_expiry_seconds = 60 * 60; // 1 hour
      $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
      $refresh_token_expiry_seconds = 60 * 60 * 24 * 7 * 2; // 2 weeks

      $query = $db->prepare('
        UPDATE sessions
        SET accesstoken = :access_token,
            accesstokenexpiry = DATE_ADD(NOW(), INTERVAL :access_token_expiry_seconds SECOND),
            refreshtoken = :refresh_token,
            refreshtokenexpiry = DATE_ADD(NOW(), INTERVAL :refresh_token_expiry_seconds SECOND)
        WHERE id = :returned_session_id
        AND userid = :returned_user_id
        AND accesstoken = :returned_access_token
        AND refreshtoken = :returned_refresh_token
      ');
      $query->execute([
        $access_token,
        $access_token_expiry_seconds,
        $refresh_token,
        $refresh_token_expiry_seconds,
        $returned_session_id,
        $returned_user_id,
        $returned_access_token,
        $returned_refresh_token
      ]);

      $row_count = $query->rowCount();

      if ($row_count === 0) returnErrorResponse(401, 'Access token could not be refreshed - please log in again');

      $return_data = [];
      $return_data['session_id'] = intval($returned_session_id);
      $return_data['access_token'] = $access_token;
      $return_data['access_token_expires_in'] = $access_token_expiry_seconds;
      $return_data['refresh_token'] = $refresh_token;
      $return_data['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage('Token refreshed');
      $response->setData($return_data);
      $response->send();
      exit;
    }
    catch (PDOException $e) {
      returnErrorResponse(500, 'There was an issue refreshing access token - please log in again');
    }
  }
  else {
    returnErrorResponse(404, 'Endpoint not found');
  }
}

/*
 * HANDLE DELETE REQUEST - LOGOUT
 */
function handle_delete($db) {
  if (array_key_exists('sessionid', $_GET)) {
    $session_id = $_GET['sessionid'];

    if ($session_id === '') returnErrorResponse(400, 'Session ID cannot be blank');
    if (!is_numeric($session_id)) returnErrorResponse(400, 'Session ID must be numeric');

    // get authentication header
    if (!isset($_SERVER['HTTP_AUTHORIZATION'])) returnErrorResponse(401, 'Access token is missing from the header');
    if (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) returnErrorResponse(401, 'Access token cannot be blank');

    $access_token = $_SERVER['HTTP_AUTHORIZATION'];

    try {
      $query = $db->prepare('
        DELETE FROM sessions WHERE id = :session_id AND accesstoken = :access_token
      ');
      $query->execute([$session_id, $access_token]);

      $row_count = $query->rowCount();

      if ($row_count === 0) {
        returnErrorResponse(400, 'Failed to log out of this session using this access token');
      }

      $return_data = [];
      $return_data['session_id'] = intval($session_id);
  
      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage('Logged out');
      $response->setData($return_data);
      $response->send();
      exit;
    }
    catch (PDOException $e) {
      $db->rollBack();
      returnErrorResponse(500, 'There was an issue logging out - please try again');
    }
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