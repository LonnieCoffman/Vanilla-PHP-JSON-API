<?php

class DB {

  private static $write_db_connection;
  private static $read_db_connection;

  // Separate database connections for scaling
  public static function connectWriteDB() {
    if (self::$write_db_connection === null) {
      self::$write_db_connection = new PDO('mysql:host=localhost;dbname=api;charset=utf8', 'root', 'root');
      self::$write_db_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      self::$write_db_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return self::$write_db_connection;
  }

  public static function connectReadDB() {
    if (self::$read_db_connection === null) {
      self::$read_db_connection = new PDO('mysql:host=localhost;dbname=api;charset=utf8', 'root', 'root');
      self::$read_db_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      self::$read_db_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return self::$read_db_connection;
  }

}