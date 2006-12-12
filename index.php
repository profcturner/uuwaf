<?php

require_once('waf.php');

$WAF = new waf('SAG');

try {
   $dbh = new PDO('mysql:host=localhost;dbname=test', $user, $pass);
   foreach ($dbh->query('SELECT * from FOO') as $row) {
     print_r($row);
   }
   $dbh = null;
} catch (PDOException $e) {
   print "Error!: " . $e->getMessage() . "<br/>";
   die();
}



?>