<?php 

  require_once('db.php');
  require_once('../model/Response.php');
  require_once('../model/Task.php');

  // connect to db
  try {
    $write_db = DB::connectWriteDB();
    $read_db =  DB::connectReadDB();
  }
  catch (PDOException $e) {
    error_log('Connection error - '.$e, 0);
    returnErrorResponse(500, 'Database connection error');
  }

  $method = $_SERVER['REQUEST_METHOD'];
  $method   === 'GET'    ? handle_get($read_db)
  : $method === 'POST'   ? handle_post($write_db)
  : $method === 'DELETE' ? handle_delete($write_db)
  : $method === 'PATCH'  ? handle_patch($write_db)
  : returnErrorResponse(405, 'Request method not allowed');

  /*
   * GET REQUESTS
   */
  function handle_get($db) {
    
    /*
     * GET SINGLE TASK
     */ 
    if (array_key_exists('taskid', $_GET)) {
      $taskid = $_GET['taskid'];
      if ($taskid == '' || !is_numeric($taskid)) {
        returnErrorResponse(400, 'Task ID cannot be blank and must be an int');
      }

      try {
        $query = $db->prepare('
          SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
          FROM tasks
          WHERE id = :taskid
        ');
        $query->execute([$taskid]);
  
        $row_count = $query->rowCount();
  
        if ($row_count === 0) {
          returnErrorResponse(404, 'Task not found');
        }
  
        while($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
          $task_array[] = $task->returnTaskAsArray();
        }
  
        $return_data = [];
        $return_data['rows_returned'] = $row_count;
        $return_data['tasks'] = $task_array;
  
        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->toCache(true);
        $response->setData($return_data);
        $response->send();
        exit;
      }
      catch (TaskException $e) {
        returnErrorResponse(500, $e->getMessage());
      }
      catch (PDOException $e){
        error_log('Database query error - '.$e, 0);
        returnErrorResponse(500, 'Failed to get task');
      }
    }
    /*
     * GET MULTIPLE TASKS
     */ 
    else if (array_key_exists('completed', $_GET)) {
      $completed = $_GET['completed'];
      if ($completed !== 'Y' && $completed !== 'N' && $completed !== 'all') returnErrorResponse(400, 'Completed filter must be Y or N');
      if ($_SERVER['REQUEST_METHOD'] !== 'GET') returnErrorResponse(405, 'Request method not allowed');

      try {
        $limit = 20;
        $page = 1;
        if (array_key_exists('page', $_GET)) {
          if (!is_numeric($_GET['page']) || $_GET['page'] == '') {
            returnErrorResponse(400, 'Page number cannot be blank and must be an int');
          }
          $page = $_GET['page'];
        }

        // complete, incomplete or all 
        $query_completed = $completed === 'all' ? '' : ' WHERE completed = ?';
  
        // get tasks and total pages
        $query = $db->prepare('SELECT count(id) AS total_tasks fROM tasks'.$query_completed);
        $query->execute($completed === 'all' ? [] : [$completed]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $total_tasks = intval($row['total_tasks']);
        $total_pages = ceil($total_tasks/$limit);
        if ($total_pages == 0) $total_pages = 1;
        echo $total_tasks;

        // handle page out of range of available pages
        if ($page > $total_pages || $page < 1) returnErrorResponse(404, 'Page not found');
  
        // calculate offset
        $offset = ($page == 1) ? 0 : ($limit * ($page - 1));
        $query_limit = ' LIMIT ? OFFSET ?';
        
        $query = $db->prepare('
          SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
          FROM tasks'
          .$query_completed
          .$query_limit
        );
        $query->execute($completed === 'all' ? [$limit, $offset] : [$completed, $limit, $offset]);
  
        $row_count = $query->rowCount();
  
        $task_array = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
          $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
          $task_array[] = $task->returnTaskAsArray();
        }
  
        $return_data = [];
        $return_data['rows_returned'] = $row_count;
        $return_data['total_rows'] = $total_tasks;
        $return_data['total_pages'] = $total_pages;
        $return_data['has_next_page'] = $page < $total_pages;
        $return_data['has_prev_page'] = $page > 1;
        $return_data['tasks'] = $task_array;
  
        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->toCache(true);
        $response->setData($return_data);
        $response->send();
        exit;
      }
      catch (TaskException $e) {
        returnErrorResponse(500, $e->getMessage());
      }
      catch (PDOException $e) {
        error_log('Database query error - '.$e, 0);
        returnErrorResponse(500, 'Failed to get tasks');
      }
    }
    // handle endpoint error
    else {
      returnErrorResponse(404, 'Endpoint not found');
    }
  }

  /*
   * POST REQUESTS
   */
  function handle_post($db) {
    // add new task
  }

  /*
   * PATCH REQUESTS
   */
  function handle_patch($db) {
    // edit task
  }

  /*
   * DELETE REQUESTS
   */
  function handle_delete($db) {
    if (array_key_exists('taskid', $_GET)) {
      $taskid = $_GET['taskid'];
      if ($taskid == '' || !is_numeric($taskid)) {
        returnErrorResponse(400, 'Task ID cannot be blank and must be an int');
      }

      try {
        $query = $db->prepare('
          DELETE FROM tasks
          WHERE id = :taskid
        ');
        $query->execute([$taskid]);
  
        $row_count = $query->rowCount();
  
        if ($row_count === 0) {
          returnErrorResponse(404, 'Task not found');
        }
  
        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->addMessage('Task Deleted');
        $response->send();
        exit;
      }
      catch(PDOException $e) {
        error_log('Database query error - '.$e, 0);
        returnErrorResponse(500, 'Failed to delete task');
      }
    }
    // handle endpoint error
    else {
      returnErrorResponse(404, 'Endpoint not found');
    }
  }

  // // Single tasks
  // if (array_key_exists('taskid', $_GET)) {
  //   $taskid = $_GET['taskid'];
  //   if ($taskid == '' || !is_numeric($taskid)) {
  //     returnErrorResponse(400, 'Task ID cannot be blank and must be an int');
  //   }

  //   $method = $_SERVER['REQUEST_METHOD'];
  //   $method   === 'GET' ? get_task($read_db, $taskid)
  //   : $method === 'DELETE' ? delete_task($write_db, $taskid)
  //   : $method === 'PATCH' ? patch_task()
  //   : error();
  // } 
  // // Multiple tasks
  // else if (array_key_exists('completed', $_GET)) {
  //   $completed = $_GET['completed'];
    
  //   if ($completed !== 'Y' && $completed !== 'N' && $completed !== 'all') returnErrorResponse(400, 'Completed filter must be Y or N');
  //   if ($_SERVER['REQUEST_METHOD'] !== 'GET') returnErrorResponse(405, 'Request method not allowed');

  //   get_task_list($read_db, $completed);
  // } else {
  //   // Catch 404
  //   returnErrorResponse(404, 'Endpoint not found');
  // }

  // // handle get request method
  // function get_task($db, $taskid) {
  //   try {
  //     $query = $db->prepare('
  //       SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
  //       FROM tasks
  //       WHERE id = :taskid
  //     ');
  //     $query->execute([$taskid]);

  //     $row_count = $query->rowCount();

  //     if ($row_count === 0) {
  //       returnErrorResponse(404, 'Task not found');
  //     }

  //     while($row = $query->fetch(PDO::FETCH_ASSOC)) {
  //       $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
  //       $task_array[] = $task->returnTaskAsArray();
  //     }

  //     $return_data = [];
  //     $return_data['rows_returned'] = $row_count;
  //     $return_data['tasks'] = $task_array;

  //     $response = new Response();
  //     $response->setHttpStatusCode(200);
  //     $response->setSuccess(true);
  //     $response->toCache(true);
  //     $response->setData($return_data);
  //     $response->send();
  //     exit;
  //   }
  //   catch (TaskException $e) {
  //     returnErrorResponse(500, $e->getMessage());
  //   }
  //   catch (PDOException $e){
  //     error_log('Database query error - '.$e, 0);
  //     returnErrorResponse(500, 'Failed to get task');
  //   }
  // }

  // // handle delete request method
  // function delete_task($db, $taskid) {
  //   try {
  //     $query = $db->prepare('
  //       DELETE FROM tasks
  //       WHERE id = :taskid
  //     ');
  //     $query->execute([$taskid]);

  //     $row_count = $query->rowCount();

  //     if ($row_count === 0) {
  //       returnErrorResponse(404, 'Task not found');
  //     }

  //     $response = new Response();
  //     $response->setHttpStatusCode(200);
  //     $response->setSuccess(true);
  //     $response->addMessage('Task Deleted');
  //     $response->send();
  //     exit;
  //   }
  //   catch(PDOException $e) {
  //     error_log('Database query error - '.$e, 0);
  //     returnErrorResponse(500, 'Failed to delete task');
  //   }
  // }

  // // handle patch request method
  // function patch_task() {
  // }

  // // handle method error
  // function error() {
  //   returnErrorResponse(405, 'Request method not allowed');
  // }

  // // handle get task list
  // function get_task_list($db, $completed) {
  //   try {
  //     $limit = 20;
  //     $page = 1;
  //     if (array_key_exists('page', $_GET)) {
  //       if (!is_numeric($_GET['page']) || $_GET['page'] == '') {
  //         returnErrorResponse(400, 'Page number cannot be blank and must be an int');
  //       }
  //       $page = $_GET['page'];
  //     }

  //     // complete, incomplete or all 
  //     $query_completed = $completed === 'all' ? '' : ' WHERE completed = ?';

  //     // get tasks and total pages
  //     $query = $db->prepare('SELECT count(id) AS total_tasks fROM tasks'.$query_completed);
  //     $query->execute([$completed]);
  //     $row = $query->fetch(PDO::FETCH_ASSOC);
  //     $total_tasks = intval($row['total_tasks']);
  //     $total_pages = ceil($total_tasks/$limit);
  //     if ($total_pages == 0) $total_pages = 1;
      
  //     // handle page out of range of available pages
  //     if ($page > $total_pages || $page < 1) returnErrorResponse(404, 'Page not found');

  //     // calculate offset
  //     $offset = ($page == 1) ? 0 : ($limit * ($page - 1));
  //     $query_limit = ' LIMIT ? OFFSET ?';
      
  //     $query = $db->prepare('
  //       SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
  //       FROM tasks'
  //       .$query_completed
  //       .$query_limit
  //     );
  //     $query->execute([$completed, $limit, $offset]);

  //     $row_count = $query->rowCount();

  //     $task_array = [];
  //     while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
  //       $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
  //       $task_array[] = $task->returnTaskAsArray();
  //     }

  //     $return_data = [];
  //     $return_data['rows_returned'] = $row_count;
  //     $return_data['total_rows'] = $total_tasks;
  //     $return_data['total_pages'] = $total_pages;
  //     $return_data['has_next_page'] = $page < $total_pages;
  //     $return_data['has_prev_page'] = $page > 1;
  //     $return_data['tasks'] = $task_array;

  //     $response = new Response();
  //     $response->setHttpStatusCode(200);
  //     $response->setSuccess(true);
  //     $response->toCache(true);
  //     $response->setData($return_data);
  //     $response->send();
  //     exit;
  //   }
  //   catch (TaskException $e) {
  //     returnErrorResponse(500, $e->getMessage());
  //   }
  //   catch (PDOException $e) {
  //     error_log('Database query error - '.$e, 0);
  //     returnErrorResponse(500, 'Failed to get tasks');
  //   }
  // }

  // return error response
  function returnErrorResponse($code, $message) {
    $response = new Response();
    $response->setHttpStatusCode($code);
    $response->setSuccess(false);
    $response->addMessage($message);
    $response->send();
    exit;
  }