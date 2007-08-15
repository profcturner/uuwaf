<?php

/**
 * The Data Transfer Object Cache extends the DTO root class, all dto cacehed classes are 
 * extended from this.
 * 
 * 
 *
 */

if (!defined(MAX_ROWS_RETURNED)) define("MAX_ROWS_RETURNED", 100);

require_once("waf/db/DB_Connection.class.php"); 
require_once("waf/dto/DTO.class.php");
 
abstract class DTO_Cache extends DTO
{
  var $timestamp = "";
	var $_ttl = 0;  // default time to live in seconds

  function __construct($host, $user, $pass, $name, $ttl=3600)
  {
    global $logger;
    $logger->log("DTO construct called");
    $this->_ttl = $ttl;
    parent::__construct($host, $user, $pass, $name);
  }

/**
 * If a timestamp is old then refresh the object, this method is a template and 
 *
 *
 */

  protected abstract function _refresh();
	
	public final function _load_by_id() 
  {
    parent::_load_by_id();

    if (time() > (strtotime($this->timestamp) + $this->_ttl))
    {
      $this->_refresh();
    } 
    
    return $this;
	}

  public final function _load_by_field($field)
  {
    parent::_load_by_field($field);

    if (time() > (strtotime($this->timestamp) + $this->_ttl))
    {
      $this->_refresh();
    } 
    
    return $this;
  }
	
}	

?>