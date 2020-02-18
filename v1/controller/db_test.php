<?php

require_once('db.php');
require_once('../model/Response.php');

try {
  $write_db = DB::connectWriteDB();
  $read_db = DB::connectReadDB();
} 
catch (PDOException $e) {
  $response = new Response();
  $response->set_http_status_code(500);
  $response->set_success(false);
  $response->add_message('Database connection error');
  $response->send();
  exit;
}
