<?php

/**
*
* The Data Transfer Object root class, all dto classes are extended from this.
*
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @author Colin Turner <c.turner@ulster.ac.uk>
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
* @package UUWAF
* @todo Change _init to work on PDO
* @todo Add hooks for Cache
* @todo Much consolidation is possible here
*
*/

if (!defined(MAX_ROWS_RETURNED)) define("MAX_ROWS_RETURNED", 100);

class DTO 
{
  var $id = 0;
  var $_handle = 'default';
  var $_status = '';

  /**
  * Opens database ready for use
  *
  * The database is considered essential, and so a panic log entry will be created
  * if it cannot be opened, and the application will be terminated.
  *
  * @param string $handle identifier for a previously registered data source
  */
  function __construct($handle = 'default')
  {
    global $waf;

    if(!count($waf->connections))
    {
      $waf->log("No database connections registered", PEAR_LOG_EMERG, 'panic');
      WA::halt("No database connections registered");
    }
    if($waf->waf_debug)
    {
      $waf->log("DTO constructor called for " . get_class($this), PEAR_LOG_DEBUG, 'waf_debug');
    }

    $connection = $waf->connections[$handle];
    //if($connection == False)
    //{
      try
      {
        $waf->connections[$handle]->con = new PDO($connection->dsn, $connection->username, $connection->password, $connection->extra);
  
        $waf->connections[$handle]->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        //$connection->con = new PDO($connection->dsn, $connection->username, $connection->password, $connection->extra);
        $this->_handle = $handle;
      }
      catch (PDOException $e)
      {
        $error_text = $e->getMessage();
        $waf->log("Database connection failure ($error_text)", PEAR_LOG_EMERG, 'panic');
        $waf->halt("Could not connect database...");
      }
    /*}
    else
    {
      $this->_handle = $handle;
    }*/
  }


  function __destruct()
  {
    unset($con);
  }


  /**
  * Logs information about a SQL error
  *
  * The fact that an error has occured at all is logged in the general log file, while
  * the possibly sensitive details are logged in the debug log file
  */
  function _log_sql_error(PDOException $e, $class, $function="")
  {
    global $waf;

    $error_text = $e->getMessage();
    if(!empty($function)) $function = "::$function ";

    $waf->log("SQL Error Occurred, see debug log file", PEAR_LOG_ERR);
    $waf->log("SQL Error generated by class '$class' $function ($error_text)", PEAR_LOG_EMERG, 'debug');
    $waf->assign("SQL_error", $error_text);
  }

  /**
  * Count all instances of objects from the database
  *
  * By default, all objects are counted, but an optional where clause can be specified
  * @param string $where_clause an optional restriction on the count
  * @return the number of objects counted
  */
  function _count($where_clause="") 
  {    
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    $object_array = array();  

    try
    {
      $sql = $con->prepare("SELECT COUNT(*) FROM `$class` $where_clause;");
      $sql->execute();

      $results_row = $sql->fetch(PDO::FETCH_NUM);
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_count()");
    }
    return $results_row[0];
  }


  /**
  * Finds if the current object in memory exists in the database (by id)
  * @return boolean value as appropriate
  */
  function _exists()
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con; 
    $class = $this->_get_tablename();

    try
    {
      $sql = $con->prepare("SELECT id FROM `$class` WHERE id = ?;");
      $sql->execute(array($this->id));
      $results_row = $sql->fetch(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_exists()");
    }
    if ($results_row) return true; else return false;
  }


  /**
  * Finds if an object with a given field and a given value exists in the database
  * @return boolean value as appropriate
  */
  function _field_value_exists($field, $value) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con; 
    $class = $this->_get_tablename();

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE `$field` = ?;");
      $sql->execute(array($value));
      $results_row = $sql->fetch(PDO::FETCH_ASSOC);
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_field_value_exists()");
    }
    if ($results_row) return true; else return false;
  }


  /**
  * Loads an entry from the database whose id is stored internally in the class
  */
  function _load_by_id() 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames();

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE id = ?");
      $sql->execute(array($this->id));
      $results_row = $sql->fetch(PDO::FETCH_ASSOC);

      foreach ($vars as $var)
      {
        $this->$var = $results_row["$var"];
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_load_by_id()");
    }
  }

  /**
  * Loads an entry from the database whose given field is equal to its internally stored value
  *
  * @param string $field the name of the field to check against
  */
  function _load_by_field($field) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames();

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE `$field` = ?");
      $sql->execute(array($this->$field));
      $results_row = $sql->fetch(PDO::FETCH_ASSOC);

      foreach ($vars as $var)
      {
        $this->$var = $results_row["$var"];
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_load_by_field()");
    }
  }


  /**
  * Loads an entry from the database whose given field is equal to an externally specified value
  *
  * @param string $field the name of the field to check against
  * @param string $value the value the field must match
  */
  function _load_by_field_value($field, $value) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames();

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE `$field` = ?");
      $sql->execute(array($value));
      $results_row = $sql->fetch(PDO::FETCH_ASSOC);

      foreach ($vars as $var) {
        $this->$var = $results_row["$var"];
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_load_by_field_value()");
    }
  }

  /**
  * Loads a single object from the database for an arbitrary where clause
  *
  * Note that if the clause causes several objects to be returned that only the first match
  * will be loaded into the object.
  *
  * @param string $where_clause the full 'where' clause, that <strong>must</strong> contain 'where' itself if needed
  * @todo this looks like a prime candidate for injection, we need to explore this... is this widely used?
  */
  function _load_where($where_clause = "") 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames();

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` $where_clause");
      $sql->execute();
      $results_row = $con->fetch_array(PDO::FETCH_ASSOC);

      foreach ($vars as $var)
      {
        $this->$var = $results_row["$var"];
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_load_where()");
    }
  }


  /**
  * Inserts an item into the database
  *
  * This will be default insert all fields in the database, but can
  * be passed an explicit array of fields to insert. The object
  * will subsequently contain the id field allocated by the database
  *
  * @param multi $fields optional array of fields to insert
  * @return the last insert id used by the database engine
  * @todo the lastInsertId call should be modified to support PostgreSQL
  * @todo it looks to me (CT) like this code could be reduced safely
  */
  function _insert($fields="empty") 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $sql_insert = "INSERT INTO `$class` SET ";
    $sql_sets = "";

    $parameters = array();
    if ($fields == "empty")
    {
      $names = $this->_get_fieldnames(false);
      foreach ($names as $name)
      {
        array_push($parameters, $this->$name);
        $sql_sets = $sql_sets."`$name`= ?,";
      }
    }
    else
    {
      $names = array_keys($fields);
      foreach ($names as $name)
      {
        array_push($parameters, $fields[$name]);
        $sql_sets = $sql_sets."`$name`= ?,";
      }
    }
    $sql_sets = substr($sql_sets,0,-1).";";
    try
    {
      $sql = $con->prepare($sql_insert.$sql_sets);
      $sql->execute($parameters);

      $this->id = $con->lastInsertId();
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_insert()");
    }
    return $this->id;
  }


  /**
  * Updates an item in the database
  *
  * This will by default update all fields in the database, but can
  * be passed an explicit array of fields to update. The object
  * will subsequently contain the id field allocated by the database
  *
  * @param multi $fields optional array of fields to update
  * @todo I (CT) think _update and _insert should call a common private core
  */
  function _update($fields="empty")  
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();
    $sql_update = "UPDATE `$class` SET ";
    $sql_sets = "";

    $parameters = array();
    if ($fields == "empty")
    {
      $vars = $this->_get_fieldnames();
      foreach ($vars as $var)
      {
        if ($var != "id")
        {
          array_push($parameters, $this->$var);
          $sql_sets = $sql_sets."`$var`= ?,";
        }
      }
    }
    else
    {
      $names = array_keys($fields);
      foreach ($names as $name)
      {
        if ($name != "id")
        {
          array_push($parameters, $fields[$name]);
          $sql_sets = $sql_sets."`$name`= ?,";
        }
      }
    }

    $sql_sets = substr($sql_sets,0,-1)." WHERE id = ?;";
    array_push($parameters, $this->id);
    try
    {
      $sql = $con->prepare($sql_update.$sql_sets);
      $sql->execute($parameters);
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_update()");
    }
  }



  /**
  * Fetches all objects from the database, with optional restrictions
  *
  * Note that any supplied clauses need to be complete, that is, ensure the where_clause
  * actually contains 'where'
  *
  * @param string $where_clause any where clause required
  * @param string $order_by any 'order by' clause required
  * @param integer $start how far into the list we should capture
  * @param integer $limit the number of rows to capture
  * @return array of objects
  */
  function _get_all($where_clause="", $order_by="", $start=0, $limit=MAX_ROWS_RETURNED, $parse = False) 
  {
    global $waf;
    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    if($waf->waf_debug)
    {
      $waf->log("$class::_get_all() called [$where_clause:$order_by:$start:$limit]", PEAR_LOG_DEBUG, "waf_debug");
    }

    $object_array = array();
    if (!($start >= 0)) $start = 0; 

    try
    {
      $sql = $con->prepare("SELECT id FROM `$class` $where_clause $order_by LIMIT $start, $limit;");
      $sql->execute();

      while ($results_row = $sql->fetch(PDO::FETCH_ASSOC))
      {
        $id = $results_row["id"];
        $object_array[] = $this->load_by_id($id, $parse);
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_get_all()");
    }
    return $object_array;	
  }



  /**
  * @todo Gordon, can you document this?
  */
  function _get_id_and_field($field, $where_clause="", $order_by="", $start=0, $limit=MAX_ROWS_RETURNED) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    $object_array = array(0 => '');
    if (!($start >= 0)) $start = 0; 

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` $where_clause $order_by LIMIT $start, $limit;");
      $sql->execute();
  
      while ($results_row = $sql->fetch(PDO::FETCH_ASSOC))
      {
        $obj_id = $results_row["id"];
        $value = $results_row["$field"];
  
        $object_array = preserved_merge_array($object_array, array($obj_id => $value));	
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_get_id_and_field()");
    }
    return $object_array;
  }

  /**
  * Gets all objects where user_id in the table has a specified value
  * @todo can this not just call _get_all with a where clause?
  */
  function _get_all_by_user_id($order_by="", $start=0, $limit=MAX_ROWS_RETURNED) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;	
    $class = $this->_get_tablename();
    $vars = $this->_get_fieldnames();

    $object_array = array();
    if (!($start >= 0)) $start = 0;

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE user_id=? $order_by LIMIT $start, $limit;");
      $sql->execute(array($this->user_id));

      while ($results_row = $sql->fetch(PDO::FETCH_ASSOC))
      {
        $classname = get_class($this);
        $object = new $classname;

        $object->id = $results_row["id"];
        $object->_load_by_id();
        array_push($object_array, $object);
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_get_all_by_user_id()");
    }
    return $object_array;
  }

  /**
  * Fetches an array of objects where a given field has a given value
  *
  * @param string $field to compare
  * @param string $value the value to match)
  * @param string $order_by an optional order field
  * @param int $start what row to begin capture
  * @param int $limit how many rows are returned
  * @return an array of objects
  */
  function _get_all_by_field_value($field, $value, $order_by="", $start=0, $limit=MAX_ROWS_RETURNED) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    $object_array = array();
    if (!($start >= 0)) $start = 0;

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE `$field` = ? $order_by LIMIT $start, $limit;");
      $sql->execute(array($value));
  
      while ($results_row = $con->fetch_array())
      {
        $classname = get_class($this);
        $object = new $classname;
        $object->id = $results_row["id"];
        $object->_load_by_id();
  
        array_push($object_array, $object);	
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_get_all_by_field_value()");
    }
    return $object_array;
  }

  /**
  * Fetches an array of objects searched upon a given field
  *
  * @param string $field to search
  * @param string $value the value to search for (using SQL LIKE)
  * @param string $order_by an optional order field
  * @param int $start what row to begin capture
  * @param int $limit how many rows are returned
  * @return an array of objects
  */
  function _get_all_like_field_value($field, $value, $order_by="", $start=0, $limit=MAX_ROWS_RETURNED) 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    $object_array = array();
    if (!($start >= 0)) $start = 0; 	

    try
    {
      $sql = $con->prepare("SELECT * FROM `$class` WHERE `$field` LIKE ? $order_by LIMIT $start, $limit;");
      $sql->execute(array($value));

      while ($results_row = $sql->fetch(PDO::FETCH_ASSOC))
      {
        $classname = get_class($this);
        $object = new $classname;

        $object->id = $results_row["id"];
        $object->_load_by_id();

        array_push($object_array, $object);
      }
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_get_all_like_field_value()");
    }
    return $object_array;
  }


  /**
  * Deletes the current object from the database (by id)
  * @return a subsequent _exists call to allow confirmation
  * @todo would a logical inversion on the return not make sense?
  */
  function _remove() 
  {
    $this->_remove_where("WHERE id=" . $this->id);
    return $this->_exists();
  }


  /**
  * Deletes objects as specified by a given where clause
  *
  * Note that to delete all objects an explicit empty where clause must be given
  *
  * @param string $where_clause where clause of what to delete
  * @todo would a logical inversion on the return not make sense?
  */
  function _remove_where($where_clause="WHERE id=0") 
  {
    global $waf;

    $con = $waf->connections[$this->_handle]->con;
    $class = $this->_get_tablename();

    try
    {
      $sql = $con->prepare("DELETE FROM `$class` $where_clause;");
      $sql->execute();
    }
    catch (PDOException $e)
    {
      $this->_log_sql_error($e, $class, "_remove_where()");
    }
  }

  /**
  * Returns the tablename used within the database for these objects
  * @return the table name
  */
  function _get_tablename() 
  {
    return strtolower(get_class($this));
  }

  /**
  * Returns the field names used in the database
  *
  * @param boolean $include_id whether or not to include the id field
  * @return an array of field names
  */
  function _get_fieldnames($include_id = true) 
  {
    $fieldnames = array_keys(get_class_vars(get_class($this)));

    $fn = array();

    foreach($fieldnames as $fieldname)
    {
      if (($fieldname != "id" || $include_id) && strcmp(substr($fieldname,0,1),"_") != 0)
      {
        array_push($fn, $fieldname);
      }
    }
    return $fn;
  }


  /**
  * Converts class information to a string
  * @return class information as a string
  */
  function __toString() 
  {
    $class_string = "Object Information : ".get_class($this);
    $class_string = $class_string.", table : ".$this->_get_tablename();
    $fields = $this->_get_fieldnames();
    foreach ($fields as $field)
    {
      $class_string = $class_string.", [".$field."] = \"".$this->$field."\"";
    }
    return $class_string;
  }


  /**
  * Converts class information to HTML
  * @return class information as HTML
  */
  function _toHTML() 
  {
    $class_string = "<br /><b>class:</b>".get_class($this)."\n";
    $class_string = $class_string."<br /><b>table:</b>".$this->_get_tablename()."\n";
    $fields = $this->_get_fieldnames();
    foreach ($fields as $field)
    {
      $class_string = $class_string."<br />[".$field."] = \"".$this->$field."\"\n";
    }
    $class_string = $class_string."\n<br />";
    return $class_string;
  }

/**
 * This method returns TRUE or FALSE, depending on whether the value validates agains the type. 
 * The field_defs can now include a validation element, that will be used instead of the type validation.
 *
 * TODO Need to add a required element to the field_def
 *
 * @param string $field
 * @param string $value
 *
 * @return bool
 *
 *
 */

  function _validate_field($field, $value) 
  {
    if (empty($this->_field_defs[$field]['validation']))
    {
      $type = $this->_field_defs[$field]['type'];
      $valid = true;

      switch ($type) {

      case "text" :   
          if ($this->_field_defs[$field]['maxsize']) {
              $maxsize = $this->_field_defs[$field]['maxsize'];
          } else {
              $maxsize = $this->_field_defs[$field]['size'];
          }
          if (strlen($value) > $maxsize) {
              $valid = false;
          }
          break;
      case "textarea" :   
          if ($this->_field_defs[$field]['maxsize']) {
              $maxsize = $this->_field_defs[$field]['maxsize'];
          } else {
              $maxsize = $this->_field_defs[$field]['rowsize']*$this->_field_defs[$field]['colsize'];
          }
          if (strlen($value) > $maxsize) {
              $valid = false;
          }
          break;
      case "email" :
          if (strlen($value) > 0 and !ereg("^[^@ ]+@[^@ ]+\.[^@ \.]+$", $value)) {
              $valid = false;
          }
          break;
      case "postcode" :
          if (!eregi('^[A-Z]{1,2}[0-9]{1,2}[[:space:]][0-9]{1}[A-Z]{2}$', $value) and strlen($value) > 0) {
              $valid = false;
          }
          break;
      case "numeric" :
          if (!is_numeric($value) and strlen($value) > 0) {
              $valid = false;
          }
          break;
      case "url" :
          if (strlen($value) > 0 and !eregi("^(((ht|f)tp(s?))\:\/\/)?(www.|[a-zA-Z].)[a-zA-Z0-9\-\.]+\.(com|edu|gov|mil|net|org|biz|info|name|museum|us|ca|uk)(\:[0-9]+)*(/($|[a-zA-Z0-9\.\,\;\?\'\\\+&%\$#\=~_\-]+))*$", $value)) {
              $valid = false;
          }
          break;
      case "currency" :
          if (strlen($value) > 0 and !eregi("^[0-9]*[.]*[0-9]{0,2}$", $value)) {
              $valid = false;
          }
          break;
      case "date" :
          if (strlen($value) > 0 and !ereg("(0[1-9]|[12][0-9]|3[01])-(0[1-9]|1[012])-([0-9]{4})", $value)) {
              $valid = false;
          }
          break;
      }
    }
    else
    {
      if (!ereg($this->_field_defs[$field]['validation'], $value)) {
        $valid = false;
      }
    }
    return $valid;
  }

  function _validate($nvp_array) 
  {

        $validation_messages = array();
        $fields = array_keys($nvp_array);
        foreach ($fields as $field) {
            if (!$this->_validate_field($field, $nvp_array[$field])) {
                $message = $this->_validate_field($field, $nvp_array[$field]);
                $validation_messages = array_merge($validation_messages, array($field => $message));
            }
        }
        //$this->_get_fieldnames($include_id
        //print_r ($validation_messages); exit;
        return $validation_messages;

    }

/**
 * This method returns the validation indicator that is displayed to the user via the UI. 
 * It is normal the text OK or ? or images.
 *
 * @param string $field
 * @param string $value
 *
 * @uses DTO::_validate_field()
 *
 */

  function _validation_response($field, $value) 
  {

    global $config;
    // override this is the model if you like

    $valid = $this->_validate_field($field, $value);

    if ($valid) {
            if ($config[pds][validation_image_ok]) {
                return "<img src='".$config[pds][validation_image_ok]."' title='format is fine' />";
            } else {
                return "<small title='format is fine' style='cursor:pointer'>Ok</small>";
            }
        } else {
            if ($config[pds][validation_image_fail]) {
                return "<img src='".$config[pds][validation_image_fail]."' title='invalid format' />";
            } else {
                return "<small title='invalid format' style='cursor:pointer'>?<small>";
            }
        }
    }


}

  function make_safe($text)	
  {

	// this function tries to remove any harmfull characters from a string (possibly pasted from Word etc), before db insertion

	$text = preg_replace("/(\cM)/", " ", $text);
	$text = preg_replace("/(\c])/", " ", $text);
	$text = str_replace("\r\n", "\n", $text);
	$text = str_replace("\x0B", " ", $text);
	$text = str_replace('"', " ", $text);
	$text = explode("\n", $text);
	$text = implode("\n", $text);
	//$text = addslashes(trim($text));
	return($text);

  } 

  function preserved_merge_array( $newArray, $otherArray ) 
  {
		
	   foreach( $otherArray as $key => $value)
	   {
	       if ( !is_array($newArray[$key]) ) $newArray[$key] = array();
	       if ( is_array($value) ) $newArray[$key] = preserved_merge_array( $newArray[$key], $value );
	       else $newArray[$key] = $value;
	   }
	  
	   return $newArray;
	}

?>