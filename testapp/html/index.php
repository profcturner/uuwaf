<?php

require_once('WA.class.php');

require_once("dto/DTO.class.php");


Class User extends DTO
{
	var $username = ''; 
	var $password="";

  var $_field_defs = array
  (
    'username'=>array('type'=>'text','size'=>15, 'header'=>true),
    'password'=>array('type'=>'password','size'=>20, 'header'=>false)
  );

  function __construct($handle = 'default')
  {
	parent::__construct($handle);
  }

  function exists($id)
  {
    $user = new User;
    $user->id = $id;
    return($user->_exists());
  }

}

$config['templates_dir']   = '/usr/share/opus4/templates';
$config['templates_c_dir'] = '/usr/share/opus4/templates_c';
$config['config_dir']      = '/usr/share/opus4/config';
$config['cache_dir']       = '/usr/share/opus4/templates_cache';
$config['title']           = 'OPUS';
$config['log_dir']         = '/var/log/opus4/';
$config['log_level']       = PEAR_LOG_DEBUG;
$config['auth_dir']        = '/usr/share/opus4/auth.d';

$waf = new WA($config);

$waf->register_data_connection('default', 'mysql:host=localhost;dbname=opus4', 'root', 'test');

$waf->set_log_ident('colin');


$waf->log("Test general logging", PEAR_LOG_ERR);
$waf->log("Test security log", PEAR_LOG_ERR, 'security');


echo "hello";

if(User::exists(1)) echo " world";
else echo " bye";

echo "<br /><h2>Test login code...</h2><br />";

if($waf->login_user("colin", "test2"))
{
  echo "success";
}
else
{
  echo "failure";
}

?>