<?php

class testauth
{
  function waf_authenticate_user($username, $password)
  {
    echo "Test database authentication by autoload\n";

    if(User::exists(5))
    {
	return(array("hello"));
    }
    else return(false);
  }


}

?>