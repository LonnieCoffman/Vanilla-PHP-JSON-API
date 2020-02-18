<?php

  require_once('Task.php');

  try {
    $task = new Task(1, 'title here', 'description here', '01/01/2019 12:00', 'N');
    header('Content-type: application/json;charset=UTF-8');
    echo json_encode($task->returnTaskAsArray());
  }
  catch (TaskException $e) {
    echo 'Error: '.$e->getMessage();
  }