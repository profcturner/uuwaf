<?php

/**
 * The Data Transfer Object Cache extends the DTO root class, all dto cacehed classes are 
 * extended from this.
 * 
 * 
 *
 */

if (!defined(MAX_ROWS_RETURNED)) define("MAX_ROWS_RETURNED", 100);

require_once("dto/DTO.class.php");
 
abstract class DTO_Cache extends DTO
{
  var $timestamp = "";
	var $_ttl = 0;  // default time to live in seconds

  function __construct($handle = 'default', $ttl = 3600)
  {
    $this->_ttl = $ttl;
    parent::__construct($handle);
  }

/**
 * If a timestamp is old then refresh the object, this method is a template and 
 *
 * Note that for some objects _load_by_id and _load_by_field will be used and so the
 * refresh function must use the $field parameter presence to decide how the refresh 
 * should occur.
 */

  protected abstract function _refresh($field);
	
	public final function _load_by_id() 
  {
    parent::_load_by_id();

    if (time() > (strtotime($this->timestamp) + $this->_ttl))
    {
      $this->_refresh("id");
    } 
	}

  public final function _load_by_field($field)
  {
    parent::_load_by_field($field);

    if (time() > (strtotime($this->timestamp) + $this->_ttl))
    {
      $this->_refresh($field);
    } 
  }
	
}	

?>