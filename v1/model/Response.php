<?php

  class Response {

    private $_success, $_http_status_code, $_data;
    private $_messages = [];
    private $_response_data = [];
    private $_to_cache = false;

    public function setSuccess($success) {
      $this->_success = $success;
    }

    public function setHttpStatusCode($http_status_code) {
      $this->_http_status_code = $http_status_code;
    }

    public function addMessage($message) {
      $this->_messages[] = $message;
    }

    public function setData($data) {
      $this->_data = $data;
    }

    public function toCache($to_cache) {
      $this->_to_cache = $to_cache;
    }

    public function send() {
      header('Content-type: application/json;charset=utf-8');

      // Handle caching
      header($this->_to_cache ? 'Cache-Control: max-age=60' : 'Cache-Control: no-cache, no-store');

      // Build response if valid
      if (is_bool($this->_success) && is_int($this->_http_status_code)) {
        http_response_code($this->_http_status_code);
        $this->_response_data['status_code'] = $this->_http_status_code;
        $this->_response_data['success'] = $this->_success;
        $this->_response_data['messages'] = $this->_messages;
        $this->_response_data['data'] = $this->_data;
      } else {
        http_response_code(500);
        $this->_response_data['status_code'] = 500;
        $this->_response_data['success'] = false;
        $this->addMessage('Response creation error');
        $this->_response_data['messages'] = $this->_messages;
      }

      // Return response
      echo json_encode($this->_response_data);
    }

  }