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
* @version 1.0
* @package WAF
*
*/

// Internal includes
//require_once("WA.Utility.class.php");
require_once("WA.data_connection.class.php");

// 3rd Party includes
require_once("Smarty.class.php");

// The Web Application Framework uses logging that is global in scope (at least for now)
require_once('Log.php');

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

  function __construct($config)
  {
    session_start();
    $this->Smarty();

    // Material loaded from config
    $this->template_dir  = $config['waf']['templates_dir'];
    $this->compile_dir   = $config['waf']['templates_c_dir'];
    $this->config_dir    = $config['waf']['config_dir'];
    $this->cache_dir     = $config['waf']['cache_dir'];
    $this->compile_check = $config['waf']['compile_check'];
    $this->debugging     = $config['waf']['debugging'];
    $this->caching       = $config['waf']['caching'];
    $this->title         = $config['waf']['title'];
    $this->language      = $config['waf']['language'];
    $this->log_dir       = $config['waf']['log_dir'];
    $this->log_level     = $config['waf']['log_level'];
    $this->log_ident     = $config['waf']['log_ident'];
    $this->auth_dir      = $config['waf']['auth_dir'];

    // Defaults for empty values
    if(empty($this->compile_check)) $this->compile_check = True;
    if(empty($this->caching)) $this->caching = False;
    if(empty($this->debugging)) $this->debugging = False;
    if(empty($this->title)) $this->title = "WA_title";
    if(empty($this->language)) $this->language = "en";
    if(empty($this->log_dir)) $this->log_dir = "/var/log/" . $this->title . "/";
    if(empty($this->log_level)) $this->log_level = Log::UPTO(PEAR_LOG_INFO);

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

  function environment_sanity_check()
  {
    if (version_compare(phpversion(), "5.1.0", "<="))
    {
       $this->halt("WAF requires at least PHP 5.1");
    }
    if(ini_get("register_globals"))
    {
      $this->halt("WAF does not support register globals for security reasons");
    }
    if(get_magic_quotes_gpc())
    {
      $this->halt("WAF: Requires magic quotes off for GPC");
    }
    if(WAF_INIT_DEBUG)
    {
      echo "WAF: Sanity checks passed<br />";
    }
    $_SESSION['waf']['sanity'] = true;
  }

  /* Log Handling Functions */

  /**
  * Create stock log files
  */
  function create_log_files()
  {
    $extras = array('mode' => 0600, 'timeFormat' => '%X %x');
    $this->create_log_file('general', $extras, $this->log_level);
    $this->create_log_file('debug', $extras, $this->log_level);
    $this->create_log_file('security', $extras, $this->log_level);
    $this->create_log_file('panic', $extras, $this->log_level);
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
  * @todo default directory order *seems* to work, if this fails on some environments note that $matches[1] contains the priority.
  */
  function register_authentication_directory($directory)
  {
    if($this->exists_user()) return(-1); // No point until a log off

    // Declare a test expression for valid files
    $test_expr = "/^([0-9][0-9])_([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+\.)?php$/";

    $objects_added = 0;

    $this->log("Loading from authentication directory $directory", PEAR_LOG_DEBUG, "debug");
    
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
  
        // Still here?
        $classname = $matches[2];
  
        $this->log("Loading $filename", PEAR_LOG_DEBUG, "debug");
        require_once($directory . "/" . $filename);
  
        // Register the object
        $object = new $classname;
        $this->register_authentication_object($object);
        $objects_added++;
      }
      return($objects_added);
    }
    catch (RuntimeException $e)
    {
      $this->log("Error while loading the Auth directory", PEAR_LOG_DEBUG, "debug");
    }
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
      return TRUE;
    }

    // Try each authentication object in registration order
    foreach($this->authentication_objects as $auth_object)
    {
      $test = $auth_object->waf_authenticate_user($username, $password, $data);
      if($test != FALSE)
      {
        $this->user = $test;
        $_SESSION['waf']['user'] = $this->user;
        $this->set_log_ident($username);
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
    unset($_SESSION['waf']['user']);
    $this->set_log_ident("");
  }


/**
 * The idea here is to return a simple array object containing all the relevant user info.
 * It is envisaged that this will be accomplished via the SLAM module.  This will enable the 
 * creation of test user in SLAM and special users and SLAM will also interogate a simple
 * web services layer to return authenticated academic and student users.
 */
	function load_user($username, $password) 
  {    
    $stub_user = array();
    
    if ($_SESSION[$this->title."_user"]['valid']) 
    {
      $stub_user['valid'] = True;
      $stub_user['user'] = $_SESSION[$this->title."_user"]['user'];
      $stub_user['groups'] = $_SESSION[$this->title."_user"]['groups'];
      $stub_user['reg_number'] = $_SESSION[$this->title."_user"]['reg_number'];
      $stub_user['user_id'] = $_SESSION[$this->title."_user"]['user_id'];;
    }
    else
    {
      switch ($username) 
      {
        case "admin" : 
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('admin');
          break;
        case "academic" :
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('academic');
          break;
        case "student" :
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('student');
          $stub_user['reg_number'] = "X1000000";
          $stub_user['user_id'] = "-1";
          break;
        case "super" :
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('super');
          break;
        case "guest" :
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('guest');
          break;
        case "multi" :
          $stub_user['valid'] = True;
          $stub_user['user'] = array('firstname'=>'Gordon', 'lastname'=>'Crawford', 'email'=>'g.crawford@ulster.ac.uk');
          $stub_user['groups'] = array('super', 'academic');
          break;
      }
  
      $_SESSION[$this->title."_user"] = $stub_user;
    }
    return $stub_user;
  }

  function unload_user()
  {
    $_SESSION[$this->title."_user"] = null;
    unset($_SESSION);
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

	function call_user_function($user, $section, $function, $default="home", $error="error") 
  {
    // Check no-one is trying to insert something witty here...
    if(!preg_match('/^[a-z0-9_]+$/i', $function))
    {
      $this->security_log("Illegal function attempted : [$function]");
      $this->halt();
    }

    $this->assign("section", $section);

		if ( function_exists($function) ) {
			$function($this, $user, $this->title);
		} elseif (function_exists($default)) {
			$default($this, $user, $USER_SESSION_NAME);
		} elseif (function_exists($error)) {
			$error($this, $user, $USER_SESSION_NAME);
		} else {
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
						$_SESSION[$name] = addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
						return addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
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
						return addslashes(str_replace("\"", "'", "$_REQUEST[$name]"));
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
    $this->config_load("lang_".$this->language.".conf", $section);
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