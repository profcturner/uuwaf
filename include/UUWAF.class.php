<?php

/**
* Singleton wrapper for WA
*
* @license http://opensource.org/licenses/lgpl-2.1.php Lesser GNU Public License v2
* @author Colin Turner <c.turner@ulster.ac.uk>
* @package UUWAF
* @see WA
*/

/**
* Singleton wrapper for WA
*
* @license http://opensource.org/licenses/lgpl-2.1.php Lesser GNU Public License v2
* @package UUWAF
* @see WA
*/

class UUWAF
{
  static private $instance;

  static public function get_instance($config = false)
  {
    if(!self::$instance)
    {
      // Nothing defined yet
      if(!$config)
      {
        // And no configuration
        trigger_error("You must configure UUWAF on first access", E_USER_ERROR);
      }
      else
      {
        require_once("WA.class.php");
        self::$instance = new WA($config);
      }
    }
    return self::$instance;
  }
}

?>