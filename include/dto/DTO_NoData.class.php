<?php
/**
* Handles database transactions without having data in the class
* @package UUWAF
*/
require_once("UUWAF.class.php");
/**
*
* Handles database transactions without having data in the class
*
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @author Colin Turner <c.turner@ulster.ac.uk>
* @license http://opensource.org/licenses/lgpl-2.1.php Lesser GNU Public License v2
* @package UUWAF
*
*/

if (!defined(MAX_ROWS_RETURNED)) define("MAX_ROWS_RETURNED", 100);

class DTO_NoData
{
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
    $waf = &UUWAF::get_instance();

    if(!count($waf->connections))
    {
      $waf->log("No database connections registered", PEAR_LOG_EMERG, 'panic');
      WA::halt("error:database:no_connections");
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
        $waf->log("Database connection failure ($error_text) [$handle]", PEAR_LOG_EMERG, 'panic');
        $waf->halt("error:database:connection_failure");
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
    $waf = &UUWAF::get_instance();

    $error_text = $e->getMessage();
    if(!empty($function)) $function = "::$function ";

    $waf->log("SQL Error Occurred, see debug log file", PEAR_LOG_ERR);
    $waf->log("SQL Error generated by class '$class' $function ($error_text)", PEAR_LOG_EMERG, 'debug');
    if($waf->panic_on_sql_error)
    {
    $waf->log("SQL Error generated by class '$class' $function ($error_text)", PEAR_LOG_EMERG, 'panic');
    }
    $waf->assign("SQL_error", $error_text);
    // Sometimes, on a redirect, we lose the text, so place it in the session in case
    $_SESSION['waf']['SQL_error'] = $error_text;

  }

}
?>
