<?php

class TaskException extends Exception {}

class Task {

  private $_id, $_title, $_description, $_deadline, $_completed;

  public function __construct($id, $title, $description, $deadline, $completed) {
    $this->setID($id);
    $this->setTitle($title);
    $this->setDescription($description);
    $this->setDeadline($deadline);
    $this->setCompleted($completed);
  }

  public function getID() {
    return $this->_id;
  }

  public function getTitle() {
    return $this->_title;
  }

  public function getDescription() {
    return $this->_description;
  }

  public function getDeadline() {
    return $this->_deadline;
  }

  public function getCompleted() {
    return $this->_completed;
  }

  // required, bigInt
  public function setID($id) {
    if ((($id !== null) && (!is_int($id) || $id <= 0 || $id > 9223372036854775807 || $this->_id !== null))) {
      throw new TaskException('Task ID error');
    }
    $this->_id = $id;
  }

  // required, varchar(255)
  public function setTitle($title) {
    if (strlen($title) < 1 || strlen($title) > 255) {
      throw new TaskException('Task title error');
    }
    $this->_title = $title;
  }

  // not required, mediumText
  public function setDescription($description) {
    if (($description !== null) && (strlen($description) > 16777215)) {
      throw new TaskException('Task description error');
    }
    $this->_description = $description;
  }

  // not required, datetime
  public function setDeadline($deadline) {
    if (($deadline !== null) && date_format(date_create_from_format('m/d/Y H:i', $deadline), 'm/d/Y H:i') != $deadline) {
      throw new TaskException('Task deadline datetime error');
    }
    $this->_deadline = $deadline;
  }

  // required, enum('Y','N')
  public function setCompleted($completed) {
    if (strtoupper($completed) !== 'Y' && strtoupper($completed) !== 'N') {
      throw new TaskException('Task completed must be Y or N');
    }
    $this->_completed = $completed;
  }

  // convert result into array
  public function returnTaskAsArray() {
    $task = [];
    $task['id'] = $this->getID();
    $task['title'] = $this->getTitle();
    $task['description'] = $this->getDescription();
    $task['deadline'] = $this->getDeadline();
    $task['completed'] = $this->getCompleted();
    return $task;
  }
}