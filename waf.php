<?php

/**
* WAF - Web Application Framework
*
* This is intended to be a simple to use Web Application Framework for PHP5. It uses many features of PHP5 and
* so cannot easily be backported. In addition, it uses the popular smarty template engine.
*
* @author Colin Turner <c.turner@ulster.ac.uk>
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
* @version 0.1
* @package WAF
*/
require_once('smarty/libs/Smarty.class.php');

define(WAF_INIT_DEBUG, TRUE);

/**
* WAF Database Connections
*
* This class holds information about all the database connections that are or may be in use in the lifetime
* of the application. These objects are held in an array in waf
* @see waf
* @package WAF
*/
class waf_data_connection
{
  /** The standard format connection string for the database
  * @var string
  */
  var $con_string;

  /** indicates if the connection has been opened yet
  * @var boolean
  */
  var $con_open;

  /** indicates if the connection should be opened straight away
  * @var boolean
  */
  var $auto_open;

  /** Creates a new object with the specified data
  * 
  * @param string $con_string the standard connection string
  * @param boolean $auto_open whether to open the connection right away
  */
  function __construct($con_string, $auto_open)
  {
    $this->con_string = $con_string;
    $this->auto_open = $auto_open;
    $this->con_open = FALSE;
  }
}

/**
* WAF User Data
*
* This class contains information about the logged in user in the WAF,
* @see waf
* @package WAF
*/
class waf_user_data
{
  /**
  * The username (text login name) of the user logged in, this is always the real username, even when an
  * effective login is in progress.
  * @var string
  */
  var $username;

  /**
  * The title (salutation, Mr, Dr etc) of the logged in user if known
  * @var string
  */
  var $title;

  /**
  * The first name  of the logged in user if known
  * @var string
  */
  var $firstname;

  /**
  * The surname  of the logged in user if known
  * @var string
  */
  var $surname;

  /**
  * The email address  of the logged in user if known
  * @var string
  */
  var $email;

  /**
  * The unique user id for the logged in user. This is always the real value, even if there is an effective login.
  * @var integer
  * @see effective_uid
  */
  var $uid;

  /**
  * If a user logs in on behalf of another (if the application allows such behaviour), this contains the user id for the
  * user being assumed, otherwise it will be equal to the user_id. This is similar to su behaviour in Linux/Unix
  * @var integer
  * @see user_id
  */
  var $effective_uid;

  /**
  * Contains specific data for the user used by the application (e.g. CLAM data).
  * @var string
  */
  var $extended_data;
}

/**
* WAF Core Class
*
* This class provides access to the smarty template engine, and other essential services such as
* database layers and authentication mechanisms.
*
* @package WAF
*/
class waf extends smarty
{
  /** All components loaded into the framework for this application.
  * This is an array of waf_component types.
  * @var array (associative of waf_component)
  */
  var $components;

  /** Sister applications that can be switched to from this one.
  * This is an array of waf_application types. The first application
  * is the currently active one.
  * @var array (associative of waf_applications)
  */
  var $applications;

  /** Array of all connections the application may use.
  * Note that the first connection, is always the default one, and
  * has that identifier ('default').
  * @var array (associative of waf_data_connections)
  */
  var $connections;

  /** Array of all object names that are WAF authentication objects 
  * @var array
  */
  var $authentication_objects;
  
  /** The WAF user object for the logged in user
  * @var waf_user_data
  */
  var $user;

  /** Initialises the WAF
  * This function gets the framework ready for action. It reads data files from the
  * waf_core/applications subdirectory to establish what other applications may
  * be present, and component requirements for the current one.
  * It then autoloads components from the waf_core/components directory
  * as required.
  * @param string $application_name the identifier of the application to run
  */
  function __construct($application_name)
  {
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Creating framework, principal application is $application_name<br />";
    }
    $authentication_objects = array();
    // Is all this in the session already?
    if(isset($_SESSION['WAF']))
    {
      $this->applications = unserialize($_SESSION['WAF']['applications']);
      $this->components = unserialise($_SESSION['WAF']['components']);
      /** @todo add reg_exp in here... */
      foreach($component as $this->components)
      {
        require_once("waf_core/components/" . $component->name . ".php");
      }
      return;
    }

    // Obviously not...
    $this->environment_sanity_check();

    $this->applications = array();
    // First load details for this application and store
    $core_application = $this->get_application($application_name);
    array_push($this->applications, $core_application);

    // Obtain all other applications that allow switching to this one
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Loading " . count($core_application->siblings->sibling) . " other application records<br />";
    }
    foreach ($core_application->siblings->sibling as $application_name)
    {
        $sibling_application = $this->get_application($application_name);
        array_push($this->applications, $sibling_application);
    }

    // Now autoload components
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Loading " . count($core_application->components->component) . " component records<br />";
    }
    foreach($core_application->components->component as $component_name)
    {
        /** @todo firm regexp needed here */
        $component = $this->get_component($component_name);
        array_push($this->components, $component);
        require_once("waf_core/components/$component_name.php");
    }
    
    // Put this all in the session for next time.
    $_SESSION['WAF'] = array();
    $_SESSION['WAF']['applications'] = serialize($this->applications);
    $_SESSION['WAF']['components'] = serialize($this->applications);
  }

  /** Checks the system requirements are correct for WAF applications
  * This function simply halts the whole application if there are problems.
  */
  function environment_sanity_check()
  {
     if (version_compare(phpversion(), "5.1.0", "<="))
    {
       die("WAF requires at least PHP 5.1");
    }
    if(ini_get("register_globals"))
    {
      die("WAF does not support register globals for security reasons");
    }
    if(!get_magic_quotes_gpc())
    {
      die("WAF: Requires magic quotes on for GPC");
    }
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Sanity checks passed<br />";
    }
  }

  /** Adds a data source to the WAF 
  * This goes into the array, the first registered source is the default, and the ident will
  * always be changed to 'default' to reflect this
  * @param string $ident used to identify the connection elsewhere
  * @param string $con_string the standard format connection string for the database
  * @param boolean $auto_open whether the connection should be opened right away
  */
  function register_data_connection($ident, $con_string, $auto_open = FALSE)
  {
    $connection = new waf_data_connection($con_string, $auto_open);
    if(empty($this->connections))
    {
      $this->connections = array();
      $ident = 'default';
    }
    $this->connections[$ident] = $connection;
  }

  /** Adds an authentication mechanism to the WAF
  * A WAF authentication mechanism is a special class with a member function called
  * waf_authenticate_user($username, $password), it may use cookies or the presented
  * credentials to check the user. The function should return a waf_user_data object on success,
  * or FALSE on failure.
  *
  * @param object $object_name an instance that can allow waf_authenticate_user() to be called
  */
  function register_authentication_object($object_name)
  {
      array_push($this->authentication_objects, $object_name);
  }

  /** Attempts to verify the user against all authentication mechanisms in turn
  *
  * @param string username the login name of the user (if known)
  * @param string password the password of the user (if known)
  * @param boolean effective is whether this is a second assumed login (not used yet).
  * @return the function returns a boolean success value, and populates $user on success
  * @see user
  */
  function login_user($username, $password, $effective = FALSE)
  {
    // Already in the session?
    if(isset($_SESSION['WAF']['user'])) return TRUE;

    // Try each authentication object in registration order
    foreach($this->authentication_objects as $auth_object)
    {
      $test = $auth_object->waf_authenticate_user($username, $password, $data);
      if($test != FALSE)
      {
        $user = $test;
        $_SESSION['WAF']['user'] = $user;
        return(TRUE);
      }
    }
    // All authentication mechanisms failed
    return (FALSE);
  }

  /** Logs out the current user
  */
  function logout_user()
  {
    $user = new waf_user_data;
    $this->user = $user;
    unset($_SESSION['WAF']['user']);
  }

  function get_application($name)
  {
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Loading application details for $name<br />";
    }
    $xml = file_get_contents("core/applications/$name.xml");
    if(!$xml) die("WAF: Application load error $name");
    return(simplexml_load_string($xml));
  }

  function get_component($name)
  {
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Loading component details for $name<br />";
    }
    $xml = file_get_contents("core/components/$name.xml");
    if(!$xml) die("WAF: Component load error $name");
    return(simplexml_load_string($xml));
  }

}

?>
