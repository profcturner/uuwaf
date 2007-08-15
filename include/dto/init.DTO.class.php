<?php

/**
* Optional include for DTO.class.php which allows on the fly database creation and modification
*/

 /**
  *
  * Initialisation of database
  *
  * This creates databases and tables, and should probably be exporteed
  * to another file to be included only in "debug" mode
  *
  * @param string $host hostname of database server
  * @param string $user username used for database connection
  * @param string $pass password used for database connection
  * @param string $name database to open on the server
  * @todo Not converting all of this to PDO just yet.
  */
  function _init($host, $user, $pass, $name) 
  {
      global $logger;
      // check if the database exists

      $link = mysql_connect($host, $user, $pass);
      if (!$link) {
        $logger->log('Could not connect: ' . mysql_error());  
        $this->_status = 'Could not connect: ' . mysql_error();
      }
 
      $this->_status = 'Connected successfully';

      $db_selected = mysql_select_db($name, $link);
      if (!$db_selected) {
         $sql = "CREATE DATABASE $name";
         if (mysql_query($sql, $link)) {
            $logger->log("Database $name created successfully");
            $this->_status = "Database $name created successfully";
         } else {
            $logger->log('Error creating database: ' . mysql_error());
            $this->_status = 'Error creating database: ' . mysql_error();
         }
      }
      mysql_close($link);

      // check to see if the table exists
      
    $con = new DB_Connection($host, $user, $pass, $name);

    //$this->_con = $con;
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames(false);
    
    if ($con->table_exists($name, $class) == FALSE) 
    {
      $logger->log($sql);
      $sql = "CREATE TABLE `$class` (`id` INT NOT NULL AUTO_INCREMENT ,";
      
      foreach ($vars as $var) {
          $sql = $sql."`$var` TEXT NOT NULL ,";
    }
      
      $sql = $sql . " PRIMARY KEY ( `id` ))";
    
      $con->query($sql);
      $logger->log("Creating table `$class`, SQL = ".$sql);
    } else {
    
      $sql = "SHOW COLUMNS FROM `$class`;";
      
      $con->query($sql);
      
      $fields = array();
          
          while ($fields_row = $con->fetch_array()) {
            array_push($fields, $fields_row["Field"]);  
          }
          
          $last_field = "id";
          
          foreach ($vars as $var) {
          
            if (!in_array($var, $fields) ) {
              
              $sql = "ALTER TABLE `$class` ADD `$var` TEXT NOT NULL AFTER `$last_field`";
              
              $logger->log("Altering table `$class`, SQL = ".$sql);
              $con->query($sql);  
              
            } 
            $last_field = $var;
          }
          
    }
  }
  
?>