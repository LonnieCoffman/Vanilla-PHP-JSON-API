<?php 

require_once('db.php');
require_once('../model/Response.php');

returnErrorResponse(404, 'Endpoint not found');

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