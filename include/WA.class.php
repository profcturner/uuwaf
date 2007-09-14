<?php
/**
* @license http://opensource.org/licenses/gpl-license.php GNU Public License v2
* @package UUWAF
*/


// Internal includes
//require_once("WA.Utility.class.php");
require_once("WA.data_connection.class.php");

// 3rd Party includes
require_once("Smarty.class.php");

// The Web Application Framework uses logging that is global in scope (at least for now)
require_once('Log.php');

/**
* WAF - Web Application Framework
*
* This is intended to be a simple to use Web Application Framework for PHP.
* It uses many features of PHP5 such as PDO and so cannot easily be backported, if at all.
* In addition, it uses the popular smarty template engine.
* It encapsulates the work of
* <ul>
*   <li>Authentication</li>
*   <li>Logging</li>
*   <li>Database handling</li>
* </ul>
* The object instantiated <strong>must</strong> be called "waf" to correctly.
*
* To instantiate the object the code should be something like
* <code>
* $config = array();
* $config['title'] = "Application Name";
* // many more options, examine __construct()
* $waf = new WA($config);
* // Note that to work correctly, the object *must* be called $waf.
* echo "hello world
* </code>
*
* @author Colin Turner <c.turner@ulster.ac.uk>
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @version 1.0
* @package UUWAF
*
*/
class WA extends Smarty 
{

  /** An array of authentication objects
  * @var array
  */
  var $authentication_objects;

  /** An array of log objects
  * @var array
  */
  var $logs;

  /** The WAF user object for the logged in user
  * @var array
  */
  var $user;

  var $base_dir;

  function __construct($config)
  {
    $this->Smarty();

    // Material loaded from config which overrides internals
    $this->template_dir           = $config['templates_dir'];
    $this->compile_dir            = $config['templates_c_dir'];
    $this->config_dir             = $config['config_dir'];
    $this->cache_dir              = $config['cache_dir'];
    $this->compile_check          = $config['compile_check'];
    $this->debugging              = $config['debugging'];
    $this->caching                = $config['caching'];

    // Material loaded from config
    foreach($config as $key => $value)
    {
      $this->$key = $value;
    }

    // Defaults for empty values
    if(empty($this->title)) $this->title = "WA_title";
    // Make an application name suitable for directories and so on
    $app_text_name = strtolower($this->title);
    $app_text_name = str_replace(" ", "_", $app_text_name);

    if(empty($this->compile_check)) $this->compile_check = True;
    if(empty($this->caching)) $this->caching = False;
    if(empty($this->debugging)) $this->debugging = False;
    if(empty($this->language)) $this->language = "en";
    if(empty($this->base_dir)) $this->base_dir = "/usr/share/$app_text_name/";
    if(empty($this->template_dir)) $this->template_dir = $this->base_dir . "templates/";
    if(empty($this->compile_dir)) $this->compile_dir = $this->base_dir . "templates_c/";
    if(empty($this->cache_dir)) $this->cache_dir = $this->base_dir . "templates_cache/";
    if(empty($this->config_dir)) $this->config_dir = $this->base_dir . "configs/";
    if(empty($this->session_dir)) $this->session_dir = $this->base_dir . "sessions/";
    if(empty($this->log_dir)) $this->log_dir = "/var/log/$app_text_name/";
    if(empty($this->log_level)) $this->log_level = Log::UPTO(PEAR_LOG_INFO);
    if(empty($this->panic_on_sql_error)) $this->panic_on_sql_error = true;
    if(empty($this->log_mode)) $this->log_mode = '0600';
    if(empty($this->log_line_format)) $this->log_line_format = '%1$s %2$s %4$s';
    if(empty($this->log_time_format)) $this->log_time_format = '%d %b %y %H:%M:%S';

    // Get the session going!
    session_save_path($this->session_dir);
    session_start();

    // Check for debugging only on some IPs
    if(is_array($this->debug_only_on_IP))
    {
      if(in_array($_SERVER['REMOTE_ADDR'], $this->debug_only_on_IP))
      {
        $this->debugging = true;
      }
      else
      {
        $this->debugging = false;
      }
    }

    //  Work out the full URL incase rewriting is used
    $this->url = explode( "/", $_SERVER['REQUEST_URI']);

    // Prepare database connections
    $this->connections = array();

    // Prepare user object, and load it if we have one already
    $this->user = array();
    if($this->exists_user()) $this->user = $_SESSION['waf']['user'];

    // Create all the stock log files, others can be user generated
    $this->logs = array();
    $this->create_log_files();

    // Prepare any known authentication objects
    $this->authentication_objects = array();
    if(!empty($this->auth_dir))
    {
      $this->register_authentication_directory($this->auth_dir);
    }

    if(!isset($_SESSION['waf'])) $this->environment_sanity_check();
  }

  /**
  * Performs a number of checks on the PHP environment, once only per session
  */
  function environment_sanity_check()
  {
    if (version_compare(phpversion(), "5.1.0", "<="))
    {
       $this->halt("WAF requires at least PHP 5.1");
    }
    if(ini_get("register_globals"))
    {
      $this->halt("WAF does not support PHP register globals for security reasons");
    }
    if(get_magic_quotes_gpc())
    {
      $this->halt("WAF: Requires PHP  magic quotes off for GPC");
    }
    if($this->waf_debug)
    {
      $this->log("WAF: Sanity checks passed", PEAR_LOG_DEBUG, 'waf_debug');
    }
    $_SESSION['waf']['sanity'] = true;
  }

  /* Log Handling Functions */

  /**
  * Create stock log files
  */
  function create_log_files()
  {
    //$extras = array('mode' => 0600, 'timeFormat' => '%X %x');

    $extras = array('mode' => $this->log_mode, 'lineFormat' => $this->log_line_format, 'timeFormat' => $this->log_time_format);
    $this->create_log_file('general', $extras, $this->log_level);
    $this->create_log_file('debug', $extras, $this->log_level);
    $this->create_log_file('security', $extras, $this->log_level);
    $this->create_log_file('panic', $extras, $this->log_level);
    if($this->waf_debug)
    {
      $this->create_log_file('waf_debug', $extras, $this->log_level);
    }
  }

  /**
  * Create any log file
  *
  * @param string $name the name of the log file to create
  * @param array $permissions the values as specified by PEAR LOG
  * @param array $level the log level (or bit mask) to use
  */
  function create_log_file($name, $permissions, $level)
  {
    $this->logs[$name] = &Log::singleton('file', $this->log_dir . $name . ".log", $ident, $permissions, $level);
  }

  /**
  * Log a message
  *
  * @param string $message the message to log
  * @param int the error level to use in the logging
  * @param string the name of the log file to use, defaults to general
  */
  function log($message, $level = PEAR_LOG_NOTICE, $name = 'general')
  {
    $this->logs[$name]->log($message, $level);
  }

  /**
  * Set the log identifier string on all logs
  *
  * @param string the new log ident string
  */
  function set_log_ident($ident)
  {
    foreach($this->logs as $log)
    {
      $log->setIdent($ident);
    }
  }

  /* Database connection functions */

  /** Adds a data source to the WAF 
  * This goes into the array, the first registered source is the default, and the ident will
  * always be changed to 'default' to reflect this
  * @param string $ident used to identify the connection elsewhere
  * @param string $dsn the standard format connection string for the database
  * @param string $username the username to use
  * @param string $password the password to use
  * @param string $extra any extra information
  */
  function register_data_connection($ident, $dsn, $username, $password, $extra=array())
  {
    if($this->waf_debug)
    {
      $this->log("Registering database connection $ident, dsn $dsn", PEAR_LOG_DEBUG, 'waf_debug');
    }
    $connection = new wa_data_connection($dsn, $username, $password, $extra);
    if(empty($this->connections))
    {
      $this->connections = array();
      $ident = 'default';
    }
    $this->connections[$ident] = $connection;
  }


  /* Authentication functions */

  /**
  * Adds an authentication mechanism to the WAF
  *
  * A WAF authentication mechanism is a special class with a member function called
  * waf_authenticate_user($username, $password), it may use cookies or the presented
  * credentials to check the user. The function should return a user array on success,
  * or FALSE on failure.
  *
  * @param object $object_name an instance that can allow waf_authenticate_user() to be called
  */
  function register_authentication_object($object_name)
  {
      array_push($this->authentication_objects, $object_name);
  }

  /**
  * Adds a directory from which to auto load authentication objects
  *
  * This specifies a directory, say "auth.d" in which there are a number of php
  * files that define authentication objects. The format of the filenames should
  * be nn_classname.class.php, or simply nn_classname.php.
  * The files are loaded in order of the numbers that start the filename and then
  * objects are automatically created and registered as authentication objects.
  *
  * This provides a simple plugable interface for authentication.
  *
  * @param string the directory containing authentication files
  * @return the number of objects added, or -1 if nothing was done
  */
  function register_authentication_directory($directory)
  {
    if($this->exists_user()) return(-1); // No point until a log off

    // Declare a test expression for valid files
    $test_expr = "/^([0-9][0-9])_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+\.)?php$/";

    $objects_added = 0;

    $this->log("Loading from authentication directory $directory", PEAR_LOG_DEBUG, "debug");

    $authentication_files = array();
    try
    {
      $dir = new DirectoryIterator($directory);
      foreach($dir as $file)
      {
        // Only interested in files
        if(!$file->isfile()) continue;
        $filename = $file->getFilename();
        $matches = array();
        if(!preg_match($test_expr, $filename, $matches)) continue; // invalid filename
        // Remember the filename
        $matches['filename'] = $filename;

        $authentication_files[$matches[1]] = $matches;
      }

      // Sort array on priority, default order is sometimes wrong
      // and the directory iterator is currently undocumented so we have to do this :-(
      asort($authentication_files);

      // Now step through them
      foreach($authentication_files as $authentication_file)
      {
        $classname = $authentication_file[2];
        $filename = $authentication_file['filename'];

        $this->log("Loading $filename", PEAR_LOG_DEBUG, "debug");
        require_once($directory . "/" . $filename);

        // Register the object
        $object = new $classname;
        $this->register_authentication_object($object);
        $objects_added++;
      }
    }
    catch (RuntimeException $e)
    {
      $this->log("Error while loading the Auth directory", PEAR_LOG_DEBUG, "debug");
    }
    return($objects_added);
  }


  function exists_user()
  {
    return(isset($_SESSION['waf']['user']));
  }

  /** Attempts to verify the user against all authentication mechanisms in turn
  *
  * @param string username the login name of the user (if known)
  * @param string password the password of the user (if known)
  * @return the function returns a boolean success value, and populates $user on success
  * @see user
  */
  function login_user($username, $password)
  {
    // Already in the session?
    if(isset($_SESSION['waf']['user']))
    {
      $this->set_log_ident($this->user['username']);
      return $_SESSION['waf']['user'];
    }

    // Try each authentication object in registration order
    foreach($this->authentication_objects as $auth_object)
    {
      $test = $auth_object->waf_authenticate_user($username, $password);
      if($test != FALSE)
      {
        $this->user = $test;
        $_SESSION['waf']['user'] = $this->user;
        $this->set_log_ident($username);
        if($this->waf_debug)
        {
          $this->log("authenticated successfully with " . get_class($auth_object), PEAR_LOG_DEBUG, 'waf_debug');
        }
        return($test);
      }
      else
      {
        if($this->waf_debug)
        {
          $this->log("authentication failed with " . get_class($auth_object), PEAR_LOG_DEBUG, 'waf_debug');
        }
      }
    }
    // All authentication mechanisms failed
    return (FALSE);
  }


  /** Logs out the current user
  * 
  * Reset Session, $this and log_ident
  */
  function logout_user()
  {
    unset($_SESSION['waf']['user']);
    unset($this->user);
    $this->set_log_ident("");
  }


  /**
  * A function to halt execution
  *
  * @todo die gracefully here... and possibly terminate the page draw in the application if possible
  */
  function halt($message="")
  {
    die($message);
  }


  /**
  * Logs important possible security intrusions, including the IP address of the remote end
  */
  function security_log($message)
  {
    $this->log($message . "[IP:" . $_SERVER['REMOTE_ADDR'] . "]", PEAR_LOG_ALERT, "security");
  }


  /**
  * returns the section in use
  *
  * @param boolean $cleanurl specifies if URL rewriting is being used
  * @return the section name (if any)
  */
  function get_section($cleanurl=True) 
  {
    if ($cleanurl)
    {
      return $this->url[2];
    }
    else 
    {
      return WA::request("section");
    }
  }


  /**
  * returns the function requested
  *
  * @param boolean $cleanurl specifies if URL rewriting is being used
  * @return the function name (if any)
  */
  function get_function($cleanurl=True) 
  {
    if ($cleanurl)
    {
      return $this->url[3];
    }
    else
    {
      return WA::request("function");
    }
  }


  /**
  * Loads only a specified controller and attempts to work out navigational structure
  *
  * @param string $group the group name, which is agressively verified by regular expression check
  */
  function load_group_controller($group) 
  {
    $nav = array();
    $group = strtolower($group);
    // Check no-one is trying to insert something witty here...
    if(!preg_match('/^[a-z0-9]+$/i', $group))
    {
      $this->security_log("Illegal group attempted : [$group]");
      $this->halt();
    }
    require_once("controllers/$group/index_$group.php");
    $this->assign("$group", "true");

    $nav_func = "nav_$group";
    if (function_exists($nav_func)) 
    {
      $nav = $nav_func();
    }
    if (function_exists("nav_default")) 
    {
      $nav = array_merge_recursive($nav, nav_default());
    }
    return $nav;
  }

  /**
  * Loads only a specified controller
  *
  * @param string $group the group name
  * @section string $section the section name
  */
  function load_section_controller($group, $section) 
  {
    if(empty($section)) return;
    // Check no-one is trying to insert something witty here...
    if(!preg_match('/^[a-z0-9_]+$/i', $section))
    {
      $this->security_log("Illegal section attempted : [$section]");
      $this->halt();
    }
    if (strlen($section) == 0 || $section == "main") 
    {
      require_once("index.php");
    }
    else
    {
      require_once("controllers/$group/index_$section.php");
    }
  }

  /**
  * Executes a specific function dependant upon URL parameters
  *
  * Naturally, your application has to check for security level access on each function.
  *
  * @param array $user the user object to pass to the function
  * @param string $section the section name, validated previously by regexp
  * @param string $function the function name, validated previously by regexp
  * @param string $default the function to fall back to if there is a problem
  * @param string $error an error function to call if needed.
  */
  function call_user_function($user, $section, $function, $default="home", $error="error") 
  {
    if($this->waf_debug)
    {
      $this->log("User function $section:$function requested", PEAR_LOG_DEBUG, 'waf_debug');
    }
    // Check no-one is trying to insert something witty here...
    if(!preg_match('/^[a-z0-9_]+$/i', $function))
    {
      $this->security_log("Illegal function attempted : [$function]");
      $this->halt();
    }

    $this->assign("section", $section);

    if (function_exists($function))
    {
      $function($this, $user, $this->title);
    }
    elseif (function_exists($default))
    {
      $default($this, $user, $USER_SESSION_NAME);
    }
    elseif (function_exists($error))
    {
      $error($this, $user, $USER_SESSION_NAME);
    }
    else
    {
      error($this, $user, $USER_SESSION_NAME);
    }
  }

  /**
  * This method returns user input from a GET or POST or the SESSION
  *
  * The method always returns GET and POSt values over SESSION stored values, and rewrites the SESSION stored 
  * value in this case
  *
  * @param string $name The name of the variable to return
  * @param bool $session A flag to indicate if input should be returned from session and persisted in session
  *
  */
  function request($name, $session=False) 
  {
    if (key_exists($name, $_REQUEST) || key_exists($name, $_SESSION)) 
    {
      if ($session) 
      {
        if (!is_array($_REQUEST[$name]))
        {
          if (isset($_REQUEST[$name])) 
          {
            // CT: I suspect addition of slashes was only needed for the database
            // this is no longer the case because of PDO escaping, and probably
            // isn't needed anywhere else, commenting this while we test that.
            //$_SESSION[$name] = addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
            //return addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
            $_SESSION[$name] = $_REQUEST[$name];
            return($_REQUEST[$name]);
          }
          else
          {
            return $_SESSION[$name];
          }
        }
        else 
        {
          return $_REQUEST[$name];
        }
      }
      else
      {
        if (!is_array($_REQUEST[$name])) 
        {
          if (isset($_REQUEST[$name]))
          {
            return($_REQUEST[$name]);
            //return addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
          }
          else
          {
            return "";
          }
        }
        else
        {
          return $_REQUEST[$name];
        }
      }
    }
    else
    {
      return "";
    }
  }

  /**
  * @todo Gordon, do we need this function as well as the one above?
  */
	function goto_page($user, $default="home", $error="error") 
  {
		$page = WA::request("function", False);

		if ( function_exists($page) ) {
			$page($this, $user);
		} elseif (function_exists($default)) {
			$default($this, $user);
		} elseif (function_exists($error)) {
			$error($this, $user);
		} else {
			error($this, $user);
      // CT: Should this call $this->halt() as a last resort?
		}
	}

	function inject($array_var) {

	// this loops through an array and assigns the values to the WA object, using the key as the 
	// template name and the value is the array element value

		$keys = array_keys($array_var);

		foreach ($keys as $key) {
			$this->assign("$key", $array_var[$key]);
		}
	}

	function inject_object($object, $array_var) {
		
		$obj = new $object;
		$keys = array_keys($array_var);
		foreach ($keys as $key) {

			$obj->$key = $array_var[$key];

		}
		$this->assign("object", $obj);
	}

/**
  * Display has been extended to load the default smarty configuration files
  */
  function display($template, $section="", $content_tpl="") 
  {
    $parts = explode(":", $section);
    $this->assign("subsection", $parts[2]);
    if (file_exists($this->config_dir."lang_".$this->language.".conf"))
      $this->config_load("lang_".$this->language.".conf", $section);
    if (file_exists($this->config_dir."local_".$this->language.".conf"))
      $this->config_load("local_".$this->language.".conf", $section);
      
    if (strlen($content_tpl) > 0) 
    {
      $content = $this->fetch($content_tpl);
      $this->assign("content", $content);
    }
    parent::display($template);
  }
}

?>