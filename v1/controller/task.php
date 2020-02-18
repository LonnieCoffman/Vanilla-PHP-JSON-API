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
        returnErrorResponse(400, $e->getMessage());
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
        $query_completed = $completed === 'all' ? '' : ' WHERE completed = :completed';
  
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
        $query_limit = ' LIMIT :limit OFFSET :offset';
        
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
        returnErrorResponse(400, $e->getMessage());
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

    if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      returnErrorResponse(400, 'Content type header is not set to JSON');
    }

    $raw_post_data = file_get_contents('php://input');
    if (!$json_data = json_decode($raw_post_data)) {
      returnErrorResponse(400, 'Request body is not valid JSON');
    }

    if (!isset($json_data->title) || !isset($json_data->completed)) {
      $messages = [];
      if (!isset($json_data->title)) $messages[] = 'Title field is mandatory and must be provided';
      if (!isset($json_data->completed)) $messages[] = 'Completed field is mandatory and must be provided';
      returnErrorResponse(400, $messages);
    }

    /*
     *  ADD NEW TASK
     */ 

    try {
      $new_task = new Task(
        null,
        $json_data->title,
        isset($json_data->description) ? $json_data->description : null,
        isset($json_data->deadline) ? $json_data->deadline : null,
        $json_data->completed
      );

      $title = $new_task->getTitle();
      $description = $new_task->getDescription();
      $deadline = $new_task->getDeadline();
      $completed = $new_task->getCompleted();

      $query = $db->prepare('
        INSERT INTO tasks (
          title, description, deadline, completed
        ) VALUES (
          :title, :description, STR_TO_DATE(:deadline, "%m/%d/%Y %H:%i"), :completed
        )
      ');
      $query->execute([$title, $description, $deadline, $completed]);
      $row_count = $query->rowCount();

      if ($row_count === 0) {
        returnErrorResponse(500, 'Failed to create task');
      }

      $last_task_id = $db->lastInsertId();

      $query = $db->prepare('
        SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
        FROM tasks
        WHERE ID = :last_task_id
      ');
      $query->execute([$last_task_id]);

      $row_count = $query->rowCount();
      if ($row_count === 0) {
        returnErrorResponse(500, 'Failed to retrieve task after creation');
      }

      while($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
        $task_array[] = $task->returnTaskAsArray();
      }

      $return_data = [];
      $return_data['rows_returned'] = $row_count;
      $return_data['tasks'] = $task_array;

      $response = new Response();
      $response->setHttpStatusCode(201);
      $response->setSuccess(true);
      $response->addMessage('Task created');
      $response->setData($return_data);
      $response->send();
      exit;
    }
    catch (TaskException $e) {
      returnErrorResponse(400, $e->getMessage());
    }
    catch (PDOException $e) {
      error_log('Database query error - '.$e, 0);
      returnErrorResponse(500, 'Failed to insert task into database - check submitted data for errors');
    }
  }

  /*
   * PATCH REQUESTS
   */
  function handle_patch($db) {
    if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
      returnErrorResponse(400, 'Content type header is not set to JSON');
    }
    
    $raw_patch_data = file_get_contents('php://input');
    if (!$json_data = json_decode($raw_patch_data)) {
      returnErrorResponse(400, 'Request body is not valid JSON');
    }
 
    if (array_key_exists('taskid', $_GET)) {
      $taskid = $_GET['taskid'];
      if ($taskid == '' || !is_numeric($taskid)) {
        returnErrorResponse(400, 'Task ID cannot be blank and must be an int');
      }
    }

    $query = $db->prepare('
      SELECT id FROM tasks WHERE id = :taskid
    ');
    $query->execute([$taskid]);
    $row_count = $query->rowCount();

    if ($row_count === 0) {
      returnErrorResponse(404, 'No task found to update');
    }

    /*
     * UPDATE TASK
     */
    try {
      $title_updated = $description_updated = $deadline_updated = $completed_updated = false;
      $query_values = [];
      $query_bind = [];

      if (isset($json_data->title)) {
        $title_updated = true;
        $query_values[] = 'title = :title';
        $query_bind[] = $json_data->title;
      }

      if (isset($json_data->description)) {
        $description_updated = true;
        $query_values[] = 'description = :description';
        $query_bind[] = $json_data->description;
      }

      if (isset($json_data->deadline)) {
        $deadline_updated = true;
        $query_values[] = 'deadline = STR_TO_DATE(:deadline, "%m/%d/%Y %H:%i")';
        $query_bind[] = $json_data->deadline;
      }

      if (isset($json_data->completed)) {
        $completed_updated = true;
        $query_values[] = 'completed = :completed';
        $query_bind[] = $json_data->completed;
      }

      if (sizeof($query_values) === 0) {
        returnErrorResponse(400, 'No task fields provided');
      }

      array_push($query_bind, $taskid);

      $query = $db->prepare('
        UPDATE tasks SET '.implode(',', $query_values).' WHERE id = :taskid
      ');
      $query->execute($query_bind);

      $query = $db->prepare('
        SELECT id, title, description, DATE_FORMAT(deadline, "%m/%d/%Y %H:%i") AS deadline, completed
        FROM tasks
        WHERE id = :taskid
      ');
      $query->execute([$taskid]);
  
      $row_count = $query->rowCount();

      if ($row_count === 0) {
        returnErrorResponse(404, 'No task found after update');
      }

      $task_array = [];

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
      $response->addMessage('Task updated');
      $response->setData($return_data);
      $response->send();
      exit;

    }
    catch (TaskException $e) {
      returnErrorResponse(400, $e->getMessage());
    }
    catch (PDOException $e){
      error_log('Database query error - '.$e, 0);
      returnErrorResponse(500, 'Failed to update task - check your data for errors');
    }
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
      /*
       * DELETE SINGLE TASK
       */
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