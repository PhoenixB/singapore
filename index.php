<?php

 /* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
 *  index.php - Copyright 2003 Tamlyn Rhodes <tam@zenology.org>        *
 *                                                                     *
 *  This file is part of singapore v0.9                                *
 *                                                                     *
 *  singapore is free software; you can redistribute it and/or modify  *
 *  it under the terms of the GNU General Public License as published  *
 *  by the Free Software Foundation; either version 2 of the License,  *
 *  or (at your option) any later version.                             *
 *                                                                     *
 *  singapore is distributed in the hope that it will be useful,       *
 *  but WITHOUT ANY WARRANTY; without even the implied warranty        *
 *  of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.            *
 *  See the GNU General Public License for more details.               *
 *                                                                     *
 *  You should have received a copy of the GNU General Public License  *
 *  along with this; if not, write to the Free Software Foundation,    *
 *  Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA      *
 \* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

//only start session if session is already registered
if(isset($_REQUEST["sgAdmin"])) {
  //set session arg separator to be xml compliant
  ini_set("arg_separator.output", "&amp;");
  
  //start session
  session_name("sgAdmin");
  session_start();
}

//include required files
require "includes/config.php";
require "includes/frontend.php";
require "includes/utils.php";
require "includes/backend.php";

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" 
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>singapore</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<link rel="stylesheet" type="text/css" href="styles/main.css" />
<link rel="stylesheet" type="text/css" href="styles/extra.css" />
</head>

<body>

<?php 

if(isset($_REQUEST["image"])) sgShowImage($_REQUEST["gallery"],$_REQUEST["image"]);
elseif(isset($_REQUEST["gallery"])) sgShowThumbnails($_REQUEST["gallery"],isset($_REQUEST["startat"])?$_REQUEST["startat"]:0);
else {
  echo("<h1>singapore</h1>\n");
  
  echo "<p><a href=\"admin.php\">Admin functions</a>.</p>";
  
  sgShowIndex(sgGetConfig("gallery_root"),isset($_REQUEST["startat"])?$_REQUEST["startat"]:0);
}

?>


</body>
</html>
