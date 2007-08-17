<?php

/**
* WAF Database Connections
*
* This class holds information about all the database connections that are or may be in use in the lifetime
* of the application. These objects are held in an array in waf
* @see waf
* @package WAF
*/
class wa_data_connection
{
  /** The standard format connection string for the database
  * @var string
  */
  var $dsn;

  /** The username to use to login
  * @var string
  */
  var $username;

  /** Password for database connection
  * @var string
  */
  var $password;

  /** Any extra information, passed in as an array
  * @var array
  */
  var $extra;

  /** The connection object itself
  * @var boolean
  */
  var $con;

  /** Creates a new object with the specified data
  * 
  * @param string $con_string the standard connection string
  * @param boolean $auto_open whether to open the connection right away
  */
  function __construct($dsn, $username, $password, $extra = array())
  {
    $this->dsn = $dsn;
    $this->username = $username;
    $this->password = $password;
    $this->extra = $extra;

    $this->con = False;
  }
}

?>