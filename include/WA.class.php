<?php
/**
* Main WAF Application Framework
*
* @license http://opensource.org/licenses/lgpl-2.1.php Lesser GNU Public License v2
* @package UUWAF
*/

// Internal includes
require_once("WA.data_connection.class.php");

// 3rd Party includes
require_once("Smarty.class.php");

// The Web Application Framework uses logging from the PEAR Log module
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
* Normally, a singleton UUWAF class is used to construct and access this.
*
* To instantiate the object the code should be something like
* <code>
* $config = array();
* $config['title'] = "Application Name";
* // many more options, examine __construct()
* $waf = UUWAF::get_instance($config);
* </code>
*
* @author Colin Turner <c.turner@ulster.ac.uk>
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @version 2.0
* @package UUWAF
* @see UUWAF.class.php
*
*/
class WA extends Smarty 
{
  /** The current version of UUWAF
  * @var string
  */
  var $waf_version = "2.1.0";

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

  /** The base directory for the installed application
  * @var string
  */
  var $base_dir;

  /** the handle of the log file to be used if nothing is specified
  * @var string
  */
  var $default_log;

  function __construct($config)
  {
    parent::__construct();

    // Material loaded from config which overrides internals
    $this->template_dir           = $config['templates_dir'];
    $this->compile_dir            = $config['templates_c_dir'];
    $this->config_dir             = $config['config_dir'];
    $this->cache_dir              = $config['cache_dir'];
    $this->compile_check          = $config['compile_check'];
    $this->debugging              = $config['debugging'];
    $this->caching                = $config['caching'];

    // Other material loaded from config
    foreach($config as $key => $value)
    {
      $this->$key = $value;
    }

    // Defaults for empty values
    if(empty($this->title)) $this->title = "WA_title";
    // Make an application name suitable for directories and so on
    $app_text_name = strtolower($this->title);
    $app_text_name = str_replace(" ", "_", $app_text_name);

    // Important paths
    if(empty($this->uuwaf_dir)) $this->uuwaf_dir = "/usr/share/uuwaf/";
    if(empty($this->base_dir)) $this->base_dir = "/usr/share/$app_text_name/";
    if(empty($this->var_dir)) $this->var_dir = "/var/lib/$app_text_name/";
    if(empty($this->session_dir)) $this->session_dir = $this->var_dir . "sessions/";
    // Smarty specific
    if(empty($this->compile_check)) $this->compile_check = True;
    if(empty($this->caching)) $this->caching = False;
    if(empty($this->debugging)) $this->debugging = False;
    if(empty($this->template_dir)) $this->template_dir = $this->base_dir . "templates/";
    if(empty($this->compile_dir)) $this->compile_dir = $this->var_dir . "templates_c/";
    if(empty($this->cache_dir)) $this->cache_dir = $this->var_dir . "templates_cache/";
    if(empty($this->config_dir)) $this->config_dir = $this->base_dir . "configs/";
    // Logging
    if(empty($this->log_dir)) $this->log_dir = "/var/log/$app_text_name/";
    if(empty($this->log_level)) $this->log_level = Log::UPTO(PEAR_LOG_INFO);
    if(empty($this->panic_on_sql_error)) $this->panic_on_sql_error = true;
    if(empty($this->log_mode)) $this->log_mode = '0600';
    if(empty($this->log_line_format)) $this->log_line_format = '%1$s [%2$s] %4$s';
    if(empty($this->log_time_format)) $this->log_time_format = '%d %b %y %H:%M:%S';
    // Miscellaneous
    if(empty($this->language)) $this->language = "en";
    if(empty($this->sanity_checking)) $this->sanity_checking = true;
    if(empty($this->unattended)) $this->unattended = false;
    // Database retrieval settings
    if(empty($this->rows_per_page)) $this->rows_per_page = 20;

    $this->default_log = 'general';

    // Get the session going!
    if(!empty($this->gc_probability)) ini_set('session.gc_probability', $this->gc_probability);
    if(!empty($this->gc_divisor)) ini_set('session.gc_divisor', $this->gc_divisor);
    if(!empty($this->gc_maxlifetime)) ini_set('session.gc_maxlifetime', $this->gc_maxlifetime);
    session_save_path($this->session_dir);
    session_start();

    // Check for debugging only on some IPs
    if(is_array($this->debug_only_on_IP) && $this->debugging)
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

    //  Work out the full URL in case rewriting is used
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

    if($this->sanity_checking && !isset($_SESSION['waf'])) $this->environment_sanity_check();
  }

  /**
  * Performs a number of checks on the PHP environment, once only per session
  */
  function environment_sanity_check()
  {
    if (version_compare($this->waf_version, $this->required_waf_version, "<"))
    {
       $this->halt("WAF version is " . $this->waf_version . " but " . $this->title . " requires " . $this->required_waf_version);
    }
    if (version_compare(phpversion(), "5.1.0", "<"))
    {
       $this->halt("WAF requires at least PHP 5.1");
    }
    if(ini_get("register_globals"))
    {
      $this->halt("WAF does not support PHP register globals for security reasons");
    }
    if(!$this->unattended && get_magic_quotes_gpc())
    {
      $this->halt("WAF: Requires PHP magic quotes off for GPC");
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
    $this->create_log_file('general',  $this->log_level);
    $this->create_log_file('debug', $this->log_level);
    $this->create_log_file('security', $this->log_level);
    $this->create_log_file('panic', $this->log_level);
    if($this->waf_debug)
    {
      $this->create_log_file('waf_debug', $this->log_level);
    }
		if($this->log_full_backtrace)
		{
			$this->create_log_file('backtrace', $this->log_level);
		}
  }

  /**
  * Create any log file
  *
  * @param string $name the name of the log file to create
  * @param array $permissions the values as specified by PEAR LOG
  * @param array $level the log level (or bit mask) to use
  */
  function create_log_file($name,  $level = "")
  {
    if($level == "") $level = $this->log_level;

    $extras = array('mode' => $this->log_mode, 'lineFormat' => $this->log_line_format, 'timeFormat' => $this->log_time_format);

    $this->logs[$name] = &Log::singleton('file', $this->log_dir . $name . ".log", $ident, $extras, $level);
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

  /**
  * Change the default log file
  *
  * @param string $ident the new log file to use by default
  */
  function set_default_log($ident)
  {
    $this->default_log = $ident;
  }

  /**
  * Log a message
  *
  * @param string $message the message to log
  * @param int the error level to use in the logging
  * @param string the name of the log file to use, defaults to general
  */
  function log($message, $level = PEAR_LOG_NOTICE, $name = '')
  {
    if($name == '')
    {
       $name = $this->default_log;
    }
    $this->logs[$name]->log($message, $level);
  }

  /**
  * Logs important possible security intrusions, including the IP address of the remote end
  */
  function security_log($message)
  {
    $this->log($message . " [IP:" . $_SERVER['REMOTE_ADDR'] . "]", PEAR_LOG_ALERT, "security");
  }
	
	/**
	* Provides a details backtrace in the log files
	* 
	* @param string the name of the log file to use, defaults to debug
	* @param boolean whether to include details like line numbers, default is false
	*/
	function log_back_trace($name = 'debug', $full_detail = true)
	{
		// Generate the bug trace
		$backtrace = debug_backtrace();
		
		$this->log("Backtrace requested...", PEAR_LOG_ALERT, $name);
		foreach($backtrace as $item)
		{
			$log_line = "Backtrace: " . $item['class'] . "::" . $item['function'];
			$this->log($log_line, PEAR_LOG_ALERT, $name);
			if($full_detail)
			{
				$log_line = "Backtrace: at " . $item['file'] . ":" . $item['line'];
				$this->log($log_line, PEAR_LOG_ALERT, $name);
			}
		}
		
		if($this->log_full_backtrace)
		{
			//ob_start();
			//var_export($backtrace);
			//$backtrace_export = ob_get_contents();
    	//ob_end_clean(); 
			$backtrace_export = var_export($backtrace, true);
			//echo $backtrace_export; exit;
			$lines = explode("\n", $backtrace_export);
			foreach($lines as $line)
			{
				$this->log($line, PEAR_LOG_ALERT, "backtrace");
			}
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


  /* Authentication functions and user handling */

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

    if($this->waf_debug)
      $this->log("Loading from authentication directory $directory", PEAR_LOG_DEBUG, "waf_debug");

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

        if($this->waf_debug)
          $this->log("Loading $filename", PEAR_LOG_DEBUG, "waf_debug");
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


	/**
	* determines if a user is logged in already
	* 
	* @return boolean depending on whether the user is logged in
	*/
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
    $this->set_log_ident("NonAuth:" . $username);
    // Already in the session?
    if($_SESSION['waf']['user']['valid'] == true)
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
    $this->security_log("authentication failed for user: $username");

    // only if a username has been supplied

    if (strlen($username) > 0)
      sleep(5); // slow down dictionary assaults

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

	/* User input and persistence */

  /**
  * This method returns user input from a GET or POST or the SESSION
  *
  * The method always returns GET and POST values over SESSION stored values, and rewrites the SESSION stored 
  * value in this case
  *
  * @param string $name The name of the variable to return
  * @param bool $session A flag to indicate if input should be returned from session and persisted in session
  *
  */
  function request($name, $session = false)
  {
    if (key_exists($name, $_REQUEST) || key_exists($name, $_SESSION)) 
    {
      if ($session) 
      {
        if (!is_array($_REQUEST[$name]))
        {
          if (isset($_REQUEST[$name])) 
          {
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
	

	/* Navigation functions, these determine and direct flow control */

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
  * @todo Gordon, do we need this function as well as the one above?
  */
  function goto_page($user, $default="home", $error="error") 
  {
    $page = WA::request("function", False);

    if ( function_exists($page) )
    {
      $page($this, $user);
    }
    elseif (function_exists($default))
    {
      $default($this, $user);
    }
    elseif (function_exists($error))
    {
      $error($this, $user);
    }
    else
    {
      error($this, $user);
      // CT: Should this call $this->halt() as a last resort?
    }
  }

  /**
  * Load language prompts from the language.conf or local override files
  * 
  * @param $section is either empty for loading global configs, or specifies the section to load
  * @see T()
  */
  function language_load($section)
  {
  	if(empty($section))
  	{
  	  if (file_exists($this->config_dir."lang_".$this->language.".conf"))
  			$this->config_load("lang_".$this->language.".conf");
  	  if (file_exists($this->config_dir."local_".$this->language.".conf"))
  			$this->config_load("local_".$this->language.".conf");
  		
  	}
  	else
  	{
  	  if (file_exists($this->config_dir."lang_".$this->language.".conf"))
  			$this->config_load("lang_".$this->language.".conf", $section);
  	  if (file_exists($this->config_dir."local_".$this->language.".conf"))
  			$this->config_load("local_".$this->language.".conf", $section);
  	}
  }
  
  /**
  * Provides simple gettext like support for internationalisation
  * 
  * Key words and phrases are added to lang_en.conf (or similar for other languages)
  * or local_en.conf. These files are loaded with language_load() and then the prompt
  * name is given where the appropriate translation is returned.
  * 
  * @param string the prompt to translate
  * @return string the translated string
  */ 
  function T($prompt)
  {
  	$value = $this->get_config_vars($prompt);
  	if(empty($value))
  	{
  	  $this->log("No translation found for [$prompt]", PEAR_LOG_DEBUG, 'debug');
  		return($prompt); // Nothing but the original prompt 
  	}
  	return($value);
  }
  

  /**
  * A function to halt execution
  *
  * Often, the function will attempt to look up a full, potentially localised
  * message from the language files if $message takes the form foo:bar:etc
  * and tries to call any user defined error function. It falls back to a
  * simple die.
  *
  * @todo die gracefully here... and possibly terminate the page draw in the application if possible
  */
  function halt($message="")
  {
    $function = $this->app_error_function;

    if(!strlen($function) || !function_exists($function))
    {
      die($message);
    }

    $this->language_load($message);
    if(preg_match("/([a-z0-9_-]+:)+([a-z0-9_-]+)/i", $message))
    {
      $this->language_load($message);
    }
    $this->assign("error_message", $message);
    $function($this);
    die();
  }


  /**
  * Display has been extended to load the default smarty configuration files
  */
  function display($template, $section="", $content_tpl="") 
  {
    $parts = explode(":", $section);
    $this->assign("subsection", $parts[2]);
    $this->language_load($section);

    if (strlen($content_tpl) > 0) 
    {
      $content = $this->fetch($content_tpl);
      $this->assign("content", $content);
    }
    parent::display($template);
  }
	
	/* Database object manipulation functions */
	
	/**
	* Assigns a page and objects array to a template and passes the 'list' template
	* to the display method. 
	* 
	* This table creates a table view of a set of objects, based on the $list_tpl.
	* The display method is called on the $waf object and the $config_section and $list_tpl
	* are passed in.
	*
	* @param array $objects The array of objects to be displayed.
	* @param string $config_section The section configuration, used to active the navigational queues.
	* @param string $list_tpl The list template to use (default 'list.tpl').
	*
	* @uses WA::request()
	*
	*/
	function generate_table($objects, $config_section, $list_tpl='') 
	{
		// Default to framework list template
		if(empty($list_tpl)) $list_tpl = $this->uuwaf_dir . "templates/list.tpl";

		$page = WA::request("page", true);
		
		$pages = array();
		$this->assign("page", $page);
		$this->assign("objects", $objects);

		$this->display("main.tpl", $config_section, $list_tpl);
	}

	/**
	* Provides a list view of objects with various actions to act upon them.
	*
	* @param string $class_name the class the object belongs to
	* @param array $action_links the action links that should be displayed at the top for the whole page
	* @param array $actions the actions that are shown for each individual item
	* @param string $get_all_method the function to get all the objects, defaults to get_all()
	* @param array $get_all_parameter an associative array of parameters to the get_all_method
	* @param string $config_section the config section to load strings for the template from
	* @param string $list_tpl the list template to be used to render the user display, defaults to the internal framework one
	* @param string $field_def_param an optional array of field defs that override the default for the class
	*
	* @uses generate_table
	* 
	*/
	function manage_objects($class_name, $action_links, $actions, $get_all_method, $get_all_parameter='', $config_section, $list_tpl='', $field_def_param=null)
	{
		// Get the objects and the number of them
		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");
		$instance = new $object;
		$object_num = $instance->count($get_all_parameter[0]);

		if (is_array($get_all_parameter))
		{
			$objects = call_user_func_array(array($object, $get_all_method), $get_all_parameter);
		}
		else
		{
			$objects = call_user_func(array($object, $get_all_method), $get_all_parameter);
		}

		// Make various assignments to Smarty
		$this->assign("action_links", $action_links);
		$this->assign("headings", $instance->get_field_defs($field_def_param));
		$this->assign("actions", $actions);
		$this->assign("object_num", $object_num);

		// Finally call the function which generates the table
		$this->generate_table($objects, $config_section, $list_tpl);
	}


	/**
	* View an object, normally using a template that presents a read-only view.
	* 
	* The object will be found using the explicit parameters and the value of the
	* $id variable in the $_REQUEST super global.
	*
	* @param string $class_name the name of the class the object belongs to
	* @param array $action_links an array of action buttons to display with the object
	* @param array $hidden_values an array of key / value pairs that will be used in the display form as hidden variables
	* @param string $config_section a section from the config from which to read values for display in the template
	* @param string $manage_tpl if defined, a custom template to use, otherwise the framework default will be used
	* 
	* @todo do hidden values really make sense for this function? CT.
	*/
	function view_object($class_name, $action_links, $hidden_values, $config_section, $manage_tpl='')
	{
		// Default to framework manage template
		if(empty($manage_tpl)) $manage_tpl = $this->uuwaf_dir . "templates/manage.tpl";

		// Load the object
    $object = str_replace(" ", "_", ucwords($class_name));
    require_once("model/".$object.".class.php");
    $instance = new $object;
    $id = WA::request("id");
    $object = $instance->load_by_id($id, true);
		
		// Make various assignments to Smarty
    $this->assign("action_button", $action_button);
    $this->assign("action_links", $action_links);
    $this->assign("mode", "remove");
    $this->assign("object", $object);
    $this->assign("headings", $instance->get_field_defs());
    $this->assign("hidden_values", $hidden_values);
		
		// Get the core manage content and send it to the master template
    $content = $this->fetch($manage_tpl);
    $this->assign("content", $content);
    $this->display("main.tpl", $config_section, $manage_tpl);
  }


	/**
	* Provides a user interface to add an object to the database.
	*
	* @param string $class_name the name of the class the object belongs to
	* @param string $action_button The action button that should be displayed.
	* @param array $action_links The action links that should be displayed.
	* @param array $hidden_values Hidden values required for the form to work correctly
	* @param string $config_section The config section to get the page title and tag line from.
	* @param string $manage_tpl The manage template to be used to render the form on the user display.
	* @param array $additional_fields Optional field defs to merge in with existing ones
	* @param string $field_def_param Used to fine tune the field defs returned by the object
	*
	*/
	function add_object($class_name, $action_button, $action_links, $hidden_values, $config_section, $manage_tpl='', $additional_fields='', $field_def_param=null)
	{
		// Default to framework manage template
		if(empty($manage_tpl)) $manage_tpl = $this->uuwaf_dir . "templates/manage.tpl";

		// Get all the fields and their properties to create such an object
		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");
		$instance = new $object;
		$headings = $instance->get_field_defs($field_def_param);
		if (is_array($additional_fields)) $headings = array_merge($headings, $additional_fields);

		// check for any lookups and populate the required smarty objects as needed
		$this->assign_lookups($instance);

		// Make various assignments to Smarty
		$this->assign("action_button", $action_button);
		$this->assign("action_links", $action_links);
		$this->assign("mode", "add");
		$this->assign("object", $instance);
		$this->assign("headings", $headings);
		$this->assign("hidden_values", $hidden_values);
		
		// Get the core manage content and send it to the master template
		$content = $this->fetch($manage_tpl);
		$this->assign("content", $content);
		$this->display("main.tpl", $config_section, $manage_tpl);

	}

	/**
	* Process the addition of an object.
	* 
	* Normally this relies on a previous add_object call setting up the appropriate
	* user input.
	*
	* @param string $class_name the name of the class the object belongs to
	* @param string $goto the header location to go to after the insertion.
	* @param string $goto_error the function to call if an error occurs.
	*
	* @see WA::add_object()
	* @todo URGENT update notification system
	* @todo URGENT update header system to be more generic
	*/
	function add_object_do($class_name, $goto, $goto_error='')
	{
		global $config;

		// Obtain the user input and validation
		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");
		$obj = new $object;
		$nvp_array = call_user_func(array($object, "request_field_values"), False);  // false means no id is requested
		$validation_messages = $obj->_validate($nvp_array);

		// Did errors
		if (count($validation_messages) == 0)
		{
			$response = $obj->insert($nvp_array);

			if (!is_numeric($response)) 
			{
				$_SESSION['waf']['error_message'] = $response;
			}
			else
			{
				// Log insert if possible / sensible
				$id = $response;
				if(method_exists($obj, "get_name")) $human_name = "(" .$obj->get_name($id) .")";
				$this->log("new $class_name added $human_name");
			}
			header("location: " . $config['opus']['url'] . "?$goto");
		}
		else
		{
			if ($goto_error == "") $goto_error = "add_".strtolower($object);
			$this->assign("nvp_array", $nvp_array);
			$this->assign("validation_messages", $validation_messages);
			$goto_error($this);
		}
	}

	/**
	 * Edit an object.  This presents an completed form view for the editing of object information.
	 * It is normally called from the manage object UI view. 
	 *
	 * @param string $class_name The name of the class of the object being edited.
	 * @param string $action_button The action button that should be displayed.
	 * @param array $action_links The action links that should be displayed.
	 * @param array $hidden_values Hidden values required for the form to work correctly.
	 * @param string $config_section The config section to get the page title and tag line from.
	 * @param string $manage_tpl The manage template to be used to render the form on the user display.
	 * @param array $additional_fields Optional field defs to merge in with existing ones
	 * @param string $field_def_param Used to fine tune the field defs returned by the object
	 *
	 * @uses WA::request()
	 * 
	 */
	function edit_object($class_name, $action_button, $action_links, $hidden_values, $config_section, $manage_tpl='', $additional_fields='', $field_def_param=null)
	{
		// Default to framework manage template
		if(empty($manage_tpl)) $manage_tpl = $this->uuwaf_dir . "templates/manage.tpl";

		// Get all the fields and their properties to create such an object
		$object = str_replace(" ", "_", ucwords($class_name));  
		require_once("model/".$object.".class.php");
		$instance = new $object;
		$headings = $instance->get_field_defs($field_def_param);
		if (is_array($additional_fields)) $headings = array_merge($headings, $additional_fields);

		// check for any lookups and populate the required smarty objects as needed
		$this->assign_lookups($instance);

		// Load the specific object to edit
		$id = WA::request("id");
		$object = call_user_func(array($object, "load_by_id"), $id);

		// Make various assignments to Smarty
		$this->assign("action_button", $action_button);
		$this->assign("action_links", $action_links);
		$this->assign("mode", "edit");
		$this->assign("object", $object);
		$this->assign("headings", $headings);
		$this->assign("hidden_values", $hidden_values);
		
		// Get the core manage content and send it to the master template		
		$content = $this->fetch($manage_tpl);
		$this->assign("content", $content);
		$this->display("main.tpl", $config_section, $manage_tpl);

		// Log the edit, if there's a sensible method that allows that
		if(method_exists($instance, "get_name"))
		{
			$human_name = "(" .$instance->get_name($id) .")";
		  $this->log("editing $object_name $human_name");
		}
	}

	/**
	 * Process the edit of an object.  
	 *
	 * @param string $class_name The name of the class the object belongs to.
	 * @param string $goto The header location to go to after the insertion.
	 * @param string $goto_error The function to call if an error occurs.
	 *
	 * 
	 */
	function edit_object_do($class_name, $goto, $goto_error='')
	{
		global $config;

		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");

		$obj = new $object;
		$nvp_array = call_user_func(array($object, "request_field_values"), True);  // false mean no id is requested
				$validation_messages = $obj->_validate($nvp_array);

		if (count($validation_messages) == 0) {
			$obj->update($nvp_array);
			header("location: " . $config['opus']['url'] . "?$goto");
		}
		else
		{
			if ($goto_error == "") $goto_error = "edit_".strtolower($object);
			$this->assign("nvp_array", $nvp_array);
			$this->assign("validation_messages", $validation_messages);
			$goto_error($this);
		}

		// Log edit
		$id = WA::request("id");
		if(method_exists($instance, "get_name")) $human_name = "(" .$instance->get_name($id) .")";
		$this->log("changes made to $class_name $human_name");
	}

	/**
	 * Remove an object.  This presents summary view of the object to be removed and confirmation button 
	 *
	 *
	 * @param string $class_name The name of the class the object belongs to.
	 * @param string $action_button The action button that should be displayed.
	 * @param array $action_links The action links that should be displayed.
	 * @param array $hidden_values Hidden values required for the form to work correctly.
	 * @param string $config_section The config section to get the page title and tag line from.
	 * @param string $manage_tpl The manage template to be used to render the form on the user display.
	 *
	 * @uses WA::request()
	 * 
	 */
	function remove_object($class_name, $action_button, $action_links, $hidden_values, $config_section, $manage_tpl='',  $additional_fields='', $field_def_param=null)
	{
		// Default to framework manage template
		if(empty($manage_tpl)) $manage_tpl = $this->uuwaf_dir . "templates/manage.tpl";

		// Get all the fields and their properties to create such an object
		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");
		$instance = new $object;
		$headings = $instance->get_field_defs($field_def_param);
		if (is_array($additional_fields)) $headings = array_merge($headings, $additional_fields);

		// Get the specific object to remove
		$id = WA::request("id");
		$object = call_user_func_array(array($object, "load_by_id"), array($id, true));

		// Make various assignments to Smarty		
		$this->assign("action_button", $action_button);
		$this->assign("action_links", $action_links);
		$this->assign("mode", "remove");
		$this->assign("object", $object);
		$this->assign("headings", $headings);
		$this->assign("hidden_values", $hidden_values);

		// Get the core manage content and send it to the master template		
		$content = $this->fetch($manage_tpl);
		$this->assign("content", $content);
		$this->display("main.tpl", $config_section, $manage_tpl);

		// Log view
		if(method_exists($instance, "get_name")) $human_name = "(" .$instance->get_name($id) .")";
		$this->log("possibly removing $object_name $human_name");
	}

	/**
	 * Process the removal of an object.  
	 *
	 *
	 * @param string $object_name The object's name.
	 * @param string $goto The header location to go to after the insertion.
	 *
	 * 
	 */
	function remove_object_do($class_name, $goto)
	{
		global $config;

		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");

		$nvp_array = call_user_func(array($object, "request_field_values"), True);  // false mean no id is requested
		call_user_func(array($object, "remove"), $nvp_array[id]);

		// Log view
		$id = WA::request("id");
		if(method_exists($instance, "get_name")) $human_name = "(" .$instance->get_name($id) .")";
		$this->log("deleting $object_name $human_name");

		header("location: " . $config['opus']['url'] . "?$goto");
	}

	/**
	 * This assigns lookup values to for a lookup property to a Smarty variable called '$field_def[var]'.
	 * The value assigned is an array of ids and field values.
	 * 
	 * @param WA &$waf The web application instance object, pass as reference.
	 * @param object $instance An actual object instance that may contain lookup values.
	 */

	function assign_lookups($instance) 
	{
		foreach ($instance->get_field_defs() as $field_def) 
		{
			if ($field_def['type'] == "lookup") 
			{
				$lookup_name = str_replace(" ", "_", ucwords($field_def['object']));
				require_once("model/".$lookup_name.".class.php");
				$lookup_function = $field_def['lookup_function'];
				if(empty($lookup_function)) $lookup_function="get_id_and_field";
				$lookup_array = call_user_func(array($lookup_name, $lookup_function), $field_def['value']);
				$this->assign("$field_def[var]", $lookup_array);
			}
		}
	}


	/**
	* @todo Is this used? The associated do function is empty
	*/
	function associate_objects($class_name, $objects_name, $action_button, $get_all_method, $get_all_parameter="", $config_section, $assign_tpl='')
	{	
		// Get an object to do field lookups and counts
		$object = str_replace(" ", "_", ucwords($class_name));
		require_once("model/".$object.".class.php");
		$instance = new $object;

		// Get some data and assign it to Smarty
		$object_num = call_user_func(array($object, "count"));
		$waf->assign("action_links", $action_links);
		$waf->assign("headings", $instance->get_field_defs());
		$waf->assign("actions", $actions);
		$waf->assign("object_num", $object_num);

		// Get all the objects
		if (is_array($get_all_parameter))
		{ 
			$objects = call_user_func_array(array($object, $get_all_method), $get_all_parameter);
		}
		else
		{
			$objects = call_user_func(array($object, $get_all_method), $get_all_parameter);
		}

		// And now generate the table
		$waf->generate_table($objects, $config_section, $assign_tpl);
	}

	/**
	* This generates an assign table, a table that can be used to assign one object to a number of
	* instance of another object, i.e. assign a user to a number of groups.
	* 
	* @param WA &$waf The web application instance object, pass as reference.
	* @param objects $instance An array of object instances.
	*
	* @uses WA::request()
	* @todo Is this used? The associated do function is empty
	*/
	function generate_assign_table($objects, $config_section, $assign_tpl='') 
	{
		// Default to framework assign template
		if(empty($assign_tpl)) $assign_tpl = $this->uuwaf_dir . "templates/assign.tpl";
		
		$page = WA::request("page", true);

		$pages = array();

		$this->assign("page", $page);
		$this->assign("objects", $objects);

		$this->display("main.tpl", $config_section, $list_tpl);

		//    $page = WA::request("page");
		$pages = array();

		$number_of_objects = count($objects);

		for ($i = 1; $i<=$number_of_objects; $i=$i+ROWS_PER_PAGE) 
		{
			$p = (($i-1)/ROWS_PER_PAGE)+1;
			array_push ($pages, $p);
		}

		if (count($pages) == 0) $pages = array(1);
		$this->assign("page", $page);
		$this->assign("pages", $pages);
		$this->assign("objects", $objects);

		$object_list = $waf->fetch("assign_list.tpl");
		$this->assign("content", $object_list);
		$this->display("main.tpl", $config_section, $assign_tpl);
	}

	function assign_objects_do(&$WA)
	{

	}

	/**
	* This validates one field of an object.
	*
	* @uses WA::request()
	* @uses DTO::_validation_response() This is inherited by the $object instance
	*
	*/
	function validate_field() 
	{
		$object = WA::request("object");
		$field = WA::request("field");
		$value = WA::request("value");

		$lookup_name = str_replace("_", " ", $object);
		$lookup_name = str_replace(" ", "_", ucwords($lookup_name));

		require_once("model/".$lookup_name.".class.php");

		$obj = new $lookup_name;
		echo $obj->_validation_response($field, $value);
	}

	/* Deprecated, possibly unsed functions scheduled for removal */

  /**
  * assigns numerous variables from an array to the smarty object
  *
  * this loops through an array and assigns the values to the WA object, using the key as the 
  * variable name and the value is the array element value
  *
  * @param array the array to decompose and add to the smarty object
	* @deprecated
  */
  function inject($array_var)
  {
    $keys = array_keys($array_var);

    foreach ($keys as $key)
    {
      $this->assign("$key", $array_var[$key]);
    }
  }

	/**
	* @todo CT Notes this function confirmed not to be used by OPUS/PDS, will
	* delete soon... make any objections very soon!!!
	* @deprecated
	*/
  function inject_object($object, $array_var)
  {
    $obj = new $object;
    $keys = array_keys($array_var);
    foreach ($keys as $key)
    {
      $obj->$key = $array_var[$key];
    }
    $this->assign("object", $obj);
  }


}

?>
