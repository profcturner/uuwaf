<?php

/**
* Cookie Handling for UUWAF
*
* @license http://opensource.org/licenses/lgpl-2.1.php Lesser GNU Public License v2
* @package UUWAF
*/
require_once("UUWAF.class.php");
/**
* Cookie Handling for UUWAF
*
* @author Gordon Crawford <g.crawford@ulster.ac.uk>
* @author Colin Turner <c.turner@ulster.ac.uk>
* @package UUWAF
* @see WA.class.php
*
*/
class Cookie
{
  /**
  * attempts to read a cookie with a specified name
  *
  * Handling of verification of hash and time is taken care of here.
  *
  * @param string the name of the cookie
  * @return an associative array of variables in the cookie
  */
  function read($cookie_name)
  {
    global $waf;
    $cookie = array();

    if($waf->waf_debug)
    {
      $waf->log("attempting to read cookie $cookie_name", PEAR_LOG_DEBUG, 'waf_debug');
    }

    $pairs = explode("&",$_COOKIE[$cookie_name]);
    foreach($pairs as $pair)
    {
      $eq = explode("=", $pair);
      $cookie[$eq[0]] = $eq[1];
    }
    if (Cookie::verify($_COOKIE[$cookie_name]))
    {
      return $cookie;
    }

    if($waf->waf_debug)
    {
      $waf->log("reading cookie $cookie_name failed (invalid or missing cookie)", PEAR_LOG_DEBUG, 'waf_debug');
    }
    return false;
  }

  /**
  * checks a given cookie for validity
  *
  * @param string name of the cookie
  * @return true if cookie exists and is valid (hash and time), false otherwise
  */
  function verify($cookie)
  {
    $cookie_array = array();

    $pairs = explode("&",$cookie);
    foreach($pairs as $pair) {
        $eq = explode("=", $pair);
        $cookie_array[$eq[0]] = $eq[1];
    }
    // If the cookie has expired, it isn't valid
    if($cookie_array['expire'] < time()) return false;

    if (Cookie::hash(substr($cookie,0,-38)) == $cookie_array['hash'])
    {
      return true;
    }
    else
    {
      return false;
    }
  }

  /**
  * writes data to a cookie
  *
  * @param string $cookie_name the name of the cookie to use
  * @param string $cookie_value the url escaped values to write
  * @param int $cookie_expire the expiry time of the cookie in seconds (defaults to 1800)
  * @param string $cookie_path the path to write in the cookie (default '/')
  * @param string $cookie_host the host to write in the cookie (defaults to waf stored value)
  * @see $cookie_host;
  */
  function write($cookie_name, $cookie_value, $cookie_expire="", $cookie_path="/", $cookie_host="")
  {
    global $waf;

    if(empty($cookie_host)) $cookie_host = $waf->cookie_host;

    if($waf->waf_debug)
    {
      $waf->log("cookie written [$cookie_name:$cookie_expire:$cookie_path:$cookie_host]", PEAR_LOG_DEBUG, 'waf_debug');
    }

    if ($cookie_name)
    {
      if (strlen($cookie_expire) == 0) $cookie_expire=time()+1800;
      $cookie_value .= "&expire=$cookie_expire";
      $hash = Cookie::hash($cookie_value);
      $cookie_value .= "&hash=$hash";
      setrawcookie($cookie_name, $cookie_value, $cookie_expire, $cookie_path, $cookie_host, 0);
    }
  }

  /**
  * invalidates a cookie by writing no data to it, and expiring it
  *
  * @param string $cookie_name the name of the cookie to use
  * @param string $cookie_path the path to write in the cookie (default '/')
  * @param string $cookie_host the host to write in the cookie (defaults to internally stored value)
  * @see $cookie_host;
  * @see Cookie::write()
  */
  function delete($cookie_name, $cookie_path='/', $cookie_host="")
  {
    Cookie::write($cookie_name, "", 0, $cookie_path, $cookie_host);
  }

  /**
  * calculates the hash to use to ensure a cookie has not been tampered with
  *
  * @param $value the material to hash
  * @return the hash of the material
  * @see $secret;
  */
  private function hash($value)
  {
    global $waf;

    return md5($waf->cookie_secret.$value);
  }
}
?>
