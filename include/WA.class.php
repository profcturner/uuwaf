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
*
*/

require_once("WA.Utility.class.php");
require_once("Smarty.class.php");

// The Web Application Framework uses logging that is global in scope (at least for now)

class WA extends Smarty 
{
	function WA($templates_dir, $templates_c_dir, $config_dir, $cache_dir, $compile_check=True, $caching=False, $debugging=False, $title = "WA_title", $language="en") 
  {
	  session_start();
	  $this->Smarty();
	  $this->template_dir = $templates_dir;
	  $this->compile_dir = $templates_c_dir;
	  $this->config_dir = $config_dir;
	  $this->cache_dir = $cache_dir;
    $this->compile_check = $compile_check;
    $this->debugging = $debugging;
	  $this->caching = $caching;
    $this->title = $title;
    $this->url = explode( "/", $_SERVER['REQUEST_URI']);
    $this->language = $language;
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
    die();
  }


  /**
  * Logs important possible security intrusions, including the IP address of the remote end
  */
  function security_log($message)
  {
    global $logs;

    $logs['security']->log($message . "[IP:" . $_SERVER['REMOTE_ADDR'] . "]", PEAR_LOG_ALERT);
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
    if(!preg_match($group, "/^[A-Za-z0-9_-]+$/"))
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
    if(!preg_match($section, "/^[A-Za-z0-9_-]+$/"))
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
    if(!preg_match($function, "/^[A-Za-z0-9_-]+$/"))
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