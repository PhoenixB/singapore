<?php 

/**
 * Main class.
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License
 * @copyright (c)2003, 2004 Tamlyn Rhodes
 * @version $Id: singapore.class.php,v 1.14 2004/02/02 16:36:04 tamlyn Exp $
 */
 
/**
 * Provides functions for handling galleries and images
 * @uses sgGallery
 * @uses sgImage
 * @uses sgConfig
 * @package singapore
 * @author Tamlyn Rhodes <tam at zenology dot co dot uk>
 */
class Singapore
{
  /**
   * current script version 
   * @var string
   */
  var $version = "0.9.9CVS";
  
  /**
   * instance of a {@link sgConfig} object representing the current 
   * script configuration
   * @var sgConfig
   */
  var $config;
  
  /**
   * instance of the currently selected IO handler object
   * @var sgIO_csv
   */
  var $io;
  
  /**
   * instance of a {@link Translator}
   * @var Translator
   */
  var $i18n;
  
  /**
   * instance of a {@link sgGallery} representing the current gallery
   * @var sgGallery
   */
  var $gallery;
  
  /**
   * reference to the currently selected {@link sgImage} object in the 
   * $images array of {@link $gallery}
   * @var sgImage
   */
  var $image;
  
  
  var $action = null;
  
  /**
   * Array of pcre regular expressions used by the script 
   */
  var $regexps = array( 
    'genericURL' => '(?:http://|https://|ftp://|mailto:)(?:[a-zA-Z0-9\-]+\.)+[a-zA-Z]{2,4}(?::[0-9]+)?(?:/[^ \n\r\"\'<]+)?',
    'wwwURL'     => 'www\.(?:[a-zA-Z0-9\-]+\.)*[a-zA-Z]{2,4}(?:/[^ \n\r\"\'<]+)?',
    'emailURL'   => '(?:[\w][\w\.\-]+)+@(?:[\w\-]+\.)+[a-zA-Z]{2,4}'
  );
    
    
  
  /**
   * Constructor
   * @param string the path to the base singapore directory
   */
  function Singapore($basePath = "")
  {
    //import class definitions
    require_once $basePath."includes/translator.class.php";
    require_once $basePath."includes/gallery.class.php";
    require_once $basePath."includes/image.class.php";
    require_once $basePath."includes/config.class.php";
    require_once $basePath."includes/io_csv.class.php";
    
    //start execution timer
    $this->scriptStartTime = microtime();
    
    //remove slashes
    if(get_magic_quotes_gpc())
      $_REQUEST = array_map(array("Singapore","arraystripslashes"), $_REQUEST);
    
    if(empty($galleryId)) $galleryId = isset($_REQUEST["gallery"]) ? $_REQUEST["gallery"] : ".";
    
    //load config from default ini file (singapore.ini)
    $this->config = new sgConfig("singapore.ini");
    
    //load config from gallery ini file (gallery.ini) if present
    $this->config->loadConfig($this->config->pathto_galleries.$galleryId."/gallery.ini");
    //load config from template ini file (template.ini) if present
    $this->config->loadConfig($this->config->pathto_current_template."template.ini");
    
    //read the language file
    $this->i18n = new Translator($this->config->pathto_locale."singapore.".$this->config->language.".pmo");
    
    //create IO handler
    $this->io = new sgIO_csv($this->config);
    
    //load gallery and image info
    $this->selectGallery($galleryId);
    
    //set character set
    if(!empty($this->i18n->languageStrings[0]["charset"]))
      $this->character_set = $this->i18n->languageStrings[0]["charset"];
    else
      $this->character_set = $this->config->default_charset;
      
    //temporary code
    if(isset($_REQUEST["action"])) $this->action = $_REQUEST["action"];
  }
  
  /**
   * Load gallery and image info
   * @param string the id of the gallery to load (optional)
   */
  function selectGallery($galleryId = "")
  {
    if(empty($galleryId)) $galleryId = isset($_REQUEST["gallery"]) ? $_REQUEST["gallery"] : ".";
    
    //try to validate gallery id
    if(strlen($galleryId)>1 && $galleryId{1} != '/') $galleryId = './'.$galleryId;
    
    //detect back-references to avoid file-system walking
    if(strpos($galleryId,"../")!==false) $galleryId = ".";
    
    //fetch the gallery and image info
    $this->gallery = $this->io->getGallery($galleryId);
    
    //check if gallery was successfully fetched
    if($this->gallery == null) {
      $this->gallery = new sgGallery($galleryId);
      $this->gallery->name = $this->i18n->_g("Gallery not found '%s'",$galleryId);
    }
    
    //sort galleries and images
    $GLOBALS["temp"]["gallery_sort_order"] = $this->config->gallery_sort_order;
    $GLOBALS["temp"]["image_sort_order"] = $this->config->image_sort_order;
    if($this->config->gallery_sort_order!="x") usort($this->gallery->galleries, array("Singapore","gallerySort"));
    if($this->config->image_sort_order!="x") usort($this->gallery->images, array("Singapore","imageSort"));
    
    $this->startat = isset($_REQUEST["startat"]) ? $_REQUEST["startat"] : 0;
    
    //encode the gallery name
    $bits = explode("/",$this->gallery->id);
    for($i=0;$i<count($bits);$i++)
      $bits[$i] = rawurlencode($bits[$i]);
    $this->gallery->idEncoded = implode("/",$bits);
    
    $this->gallery->idEntities = htmlspecialchars($this->gallery->id);
    
    //find the parent
    $this->gallery->parent = substr($this->gallery->idEncoded, 0, strrpos($this->gallery->idEncoded, "/"));
    $this->gallery->parentName = urldecode(substr($this->gallery->parent,strrpos($this->gallery->parent,"/")+1));
    if($this->gallery->parentName == "")
      $this->gallery->parentName = $this->config->gallery_name;
    
    //do the logging stuff and select the image (if any)
    if(empty($_REQUEST["image"])) {
      if($this->config->track_views) $hits = $this->logGalleryView();
      if($this->config->show_views) $this->gallery->hits = $hits;
      //set page title
      $this->pageTitle = $this->gallery->name;
    } else {
      $this->selectImage($_REQUEST["image"]);
      if($this->config->track_views) $hits = $this->logImageView();
      if($this->config->show_views) $this->image->hits = $hits;
      //set page title
      $this->pageTitle = $this->image->name;
    }
    
  }
  
  /**
   * Selects an image from the current gallery
   * @param mixed either the filename of the image to select or the integer 
   *  index of its position in the images array
   * @return boolean true on success; false otherwise
   */
  function selectImage($image) 
  {
    if(is_string($image)) {
      foreach($this->gallery->images as $index => $img)
        if($img->filename == $image) {
          $this->image =& $this->gallery->images[$index];
          $this->image->index = $index;
          return true;
        }
    } elseif(is_int($image) && $image >= 0 && $image < count($this->gallery->images)) {
      $this->image =& $this->gallery->images[$image];
      $this->image->index = $image;
      return true;
    }
    $this->image = new sgImage();
    $this->image->name = $this->i18n->_g("Image not found '%s'",$image);
    return false;
  }
  
  /**
   * Callback function for sorting galleries
   * @static
   */
  function gallerySort($a, $b) {
    switch($GLOBALS["temp"]["gallery_sort_order"]) {
      case "p" : return strcmp($a->id, $b->id); //path
      case "P" : return strcmp($b->id, $a->id); //path (reverse)
      case "n" : return strcmp($a->name, $b->name); //name
      case "N" : return strcmp($b->name, $a->name); //name (reverse)
      case "i" : return strcasecmp($a->name, $b->name); //case-insensitive name
      case "I" : return strcasecmp($b->name, $a->name); //case-insensitive name (reverse)
    }
  }
  
  /**
   * Callback function for sorting images
   * @static
   */
  function imageSort($a, $b) {
    switch($GLOBALS["temp"]["image_sort_order"]) {
      case "n" : return strcmp($a->name, $b->name); //name
      case "N" : return strcmp($b->name, $a->name); //name (reverse)
      case "i" : return strcasecmp($a->name, $b->name); //case-insensitive name
      case "I" : return strcasecmp($b->name, $a->name); //case-insensitive name (reverse)
      case "a" : return strcmp($a->artist, $b->artist); //artist
      case "A" : return strcmp($b->artist, $a->artist); //artist (reverse)
      case "f" : return strcmp($a->filename, $b->filename); //filename
      case "F" : return strcmp($b->filename, $a->filename); //filename (reverse)
      case "d" : return strcmp($a->date, $b->date); //date
      case "D" : return strcmp($b->date, $a->date); //date (reverse)
      case "l" : return strcmp($a->location, $b->location); //location
      case "L" : return strcmp($b->location, $a->location); //location (reverse)
    }
  }
    
  /**
   * Callback function for recursively stripping slashes
   * @static
   */
  function arraystripslashes($toStrip)
  {
    if(is_array($toStrip))
      return array_map(array("Singapore","arraystripslashes"), $toStrip);
    else
      return stripslashes($toStrip);
  }
  
  /**
   * @return int|null the number of image hits or null
   */
  function logImageView() 
  {
    $this->gallery->hits = $this->io->getHits($this->gallery->id);
    if(!$this->gallery->hits) return null;

    if(isset($this->gallery->hits->images)) {
      //search selected for image in existing log
      for($i=0;$i<count($this->gallery->hits->images);$i++) 
        if($this->gallery->hits->images[$i]->filename == $this->image->filename) {
          $numhits = ++$this->gallery->hits->images[$i]->hits;
          $this->gallery->hits->images[$i]->lasthit = time();
          break;
        }
    } else {
      $this->gallery->hits->images = array();
      $i = 0;
    }
    //if image not found then add it
    if($i == count($this->gallery->hits->images)) {
      $this->gallery->hits->images[$i] = new stdClass;
      $this->gallery->hits->images[$i]->filename = $this->image->filename;
      $this->gallery->hits->images[$i]->hits = $numhits = 1;
      $this->gallery->hits->images[$i]->lasthit = time();
    }
    
    
    //save modified hits data
    $this->io->putHits($this->gallery->id,$this->gallery->hits);
  
    //return number of hits
    return $numhits;
  }
  
  /**
   * @return int|null the number of gallery hits or null
   */
  function logGalleryView() 
  {
    $this->gallery->hits = $this->io->getHits($this->gallery->id);
    if(!$this->gallery->hits) return null;
    
    if(isset($this->gallery->hits->hits) && $this->startat == 0) $numhits = ++$this->gallery->hits->hits;
    elseif(isset($this->gallery->hits->hits)) $numhits = $this->gallery->hits->hits;
    else $numhits = $this->gallery->hits->hits = 1;

    $this->gallery->hits->lasthit = time();
        
    //save modified hits data
    $this->io->putHits($this->gallery->id,$this->gallery->hits);
  
    //return number of hits
    return $numhits;
  }

  /**
   * @return bool true if this is an image page; false otherwise
   */
  function isImage()
  {
    return !empty($this->image);
  }
  
  /**
   * @return bool true if this is an image thumbnail page; false otherwise
   */
  function isGallery($index = null)
  {
    return !$this->galleryHasSubGalleries($index) && !$this->isImage();
  }
  
  /**
   * @return int the script execution time in seconds rounded to two decimal places
   */
  function scriptExecTime()
  {
    $scriptStartTime = $this->scriptStartTime;
    $scriptEndTime = microtime();
    
    list($usec, $sec) = explode(" ",$scriptStartTime); 
    $scriptStartTime = (float)$usec + (float)$sec; 
    list($usec, $sec) = explode(" ",$scriptEndTime); 
    $scriptEndTime = (float)$usec + (float)$sec; 
    
    $scriptExecTime = floor(($scriptEndTime - $scriptStartTime)*100)/100;
    return $scriptExecTime;
  }
  
  /**
   * Displays the script execution time if configured to do so
   * @uses scriptExecTime()
   * @returns string the script execution time
   */
  function scriptExecTimeText()
  {
    if($this->config->show_execution_time)
      return $this->i18n->_g("Page created in %s seconds",$this->scriptExecTime());
    else
      return "";
  }
  
  function versionText()
  {
    return "singapore v".$this->version;
  }
  
  function versionLink()
  {
    return '<a href="http://singapore.sourceforge.net/">'.$this->versionText().'</a>';
  }
  
  function poweredByVersion()
  {
    return $this->i18n->_g("singapore|Powered by %s",$this->versionLink());
  }
  
  function allRightsReserved()
  {
    return $this->i18n->_g("All rights reserved.");
  }
  
  function copyrightMessage()
  {
    return $this->i18n->_g("Images may not be reproduced in any form without the express written permission of the copyright holder.");
  }
  
  function adminLink()
  {
    return "<a href=\"admin.php\">".$this->i18n->_g("Log in")."</a>";
  }
  
  /**
   * @returns stdClass|false a data object representing the desired gallery
   * @static
   */
  function getListing($wd, $type = "dirs")
  {
    $dir = new stdClass;
    $dir->path = $wd;
    $dir->files = array();
    $dir->dirs = array();
    $dp = opendir($dir->path);
    
    if(!$dp) return false;

    switch($type) {
      case "images" :
        while(false !== ($entry = readdir($dp)))
          if(!is_dir($entry) && preg_match("/\.(jpeg|jpg|jpe|png|gif|bmp|tif|tiff)$/i",$entry))
            $dir->files[] = $entry;
        sort($dir->files);
        rewinddir($dp);
        //run on and get dirs too
      case "dirs" :
       while(false !== ($entry = readdir($dp)))
          if(
            is_dir($wd.$entry) && 
            $entry{0} != '.'
          ) $dir->dirs[] = $entry;
        sort($dir->dirs);
        break;
      case "all" :
        while(false !== ($entry = readdir($dp)))
          if(is_dir($wd.$entry)) $dir->dirs[] = $entry;
          else $dir->files[] = $entry;
        sort($dir->dirs);
        sort($dir->files);
        break;
      default :
        while(false !== ($entry = readdir($dp)))
          if(strpos(strtolower($entry),$type)) 
            $dir->files[] = $entry;
        sort($dir->files);
    }
    closedir($dp);
    return $dir;
  }
  
  /**
   * Recursively deletes all directories and files in the specified directory.
   * USE WITH EXTREME CAUTION!!
   * @returns boolean true on success; false otherwise
   * @static
   */
  function rmdir_all($wd)
  {
    if(!$dp = opendir($wd)) return false;
    $success = true;
    while(false !== ($entry = readdir($dp))) {
      if($entry == "." || $entry == "..") continue;
      if(is_dir("$wd/$entry")) $success &= $this->rmdir_all("$wd/$entry");
      else $success &= unlink("$wd/$entry");
    }
    closedir($dp);
    $success &= rmdir($wd);
    return $success;
  }
  
  /**
   * Checks to see if the user is currently logged in to admin mode. Also resets
   * the login timeout to the current time.
   * @returns boolean true if the user is logged in; false otherwise
   * @static
   */
  function isLoggedIn() 
  {
    if(isset($_SESSION["sgUser"]) && $_SESSION["sgUser"]->check == md5($_SERVER["REMOTE_ADDR"]) && (time() - $_SESSION["sgUser"]->loginTime < 1800)) {
  	  $_SESSION["sgUser"]->loginTime = time();
  	  return true;
    }
    return false;
  }
  
  /**
   * Creates an array of objects each representing an item in the crumb line.
   * @return array the items of the crumb line
   */
  function crumbLineArray()
  {
    $crumb[0] = new stdClass;
    $crumb[0]->id = ".";
    $crumb[0]->path = ".";
    $crumb[0]->name = $this->config->gallery_name;
    
    if(!isset($this->gallery->id))
      return $crumb;
    
    $galleries = explode("/",$this->gallery->id);
    
    for($i=1;$i<count($galleries);$i++) {
      $crumb[$i] = new stdClass;
      $crumb[$i]->id = $galleries[$i];
      $crumb[$i]->path = $crumb[$i-1]->path."/".rawurlencode($galleries[$i]);
      $crumb[$i]->name = $galleries[$i];
    }
    
    if($this->isImage()) {
      $crumb[$i] = new stdClass;
      $crumb[$i]->id = "";
      $crumb[$i]->path = "";
      $crumb[$i]->name = $this->image->name;
    }
    
    return $crumb;
  }
  
  /**
   * @return string the complete crumb line with links
   */
  function crumbLineText()
  {
    $crumbArray = $this->crumbLineArray();
    $ret = "";
    for($i=0;$i<count($crumbArray)-1;$i++) {
      $ret .= "<a href=\"".$this->config->base_url."gallery=".$crumbArray[$i]->path."\">".$crumbArray[$i]->name."</a> &gt;\n";
    }
    $ret .= $crumbArray[$i]->name;
    return $ret;
  }
  
  function crumbLine()
  {
    return $this->i18n->_g("crumb line|You are here:")." ".$this->crumbLineText();
  }
  
  /////////////////////////////
  //////gallery functions//////
  /////////////////////////////
  
  
  /**
   * If the specified gallery is a gallery then it returns the number
   * of images contained otherwise the number of galleries is returned
   * @param int the index of the sub gallery to count (optional)
   * @return string the contents of the specified gallery
   */
  function galleryContents($index = null)
  {
    if($this->isGallery($index))
      return $this->imageCountText($index);
    else
      return $this->galleryCountText($index);
  }
  
	/**
   * @param int the index of the sub gallery to count (optional)
   * @return int the number of galleries in the specified gallery
   * or of the current gallery if $index is not specified
   */
  function galleryCount($index = null)
  {
    if($index === null)
      return count($this->gallery->galleries);
    else
      return count($this->gallery->galleries[$index]->galleries);
  }
  
  /**
   * @return string the number of galleries in the specified gallery
   */
  function galleryCountText($index = null)
  {
    return $this->i18n->_ng("%s gallery", "%s galleries", $this->galleryCount($index));
  }
  
  /**
   * @param int the index of the sub gallery to count (optional)
   * @return boolean true if the specified gallery (or the current gallery 
	 * if $index is not specified) has sub-galleries; false otherwise
   */
	function galleryHasSubGalleries($index = null)
	{
    return $this->galleryCount($index)>0;
	}
	
  /**
   * @return int the number of images in the specified gallery
   */
  function imageCount($index = null)
  {
    if($index === null)
      return count($this->gallery->images);
    else
      return count($this->gallery->galleries[$index]->images);
  }
  
  /**
   * @return string the number of images in the specified gallery
   */
  function imageCountText($index = null)
  {
    return $this->i18n->_ng("%s image", "%s images", $this->imageCount($index));
  }
  
  /**
   * @param int the index of the sub gallery to check (optional)
   * @return boolean true if the specified gallery (or the current gallery 
	 * if $index is not specified) contains one or more images; false otherwise
   */
	function galleryHasImages($index = null)
	{
	  return count($this->imageCount($index))>0;
	}
	
  /**
   * @uses imageThumbnailImage
   * @return string
   */
  function galleryThumbnailLinked($index = null)
  {
    if($index === null) $galleryId = $this->gallery->idEncoded;
    else $galleryId = urlencode($this->gallery->galleries[$index]->id);
    
    $ret  = "<a href=\"".$this->config->base_url."gallery=".$galleryId."\">";
    $ret .= $this->galleryThumbnailImage($index);
    $ret .= "</a>";
    return $ret;
  }
  
  /**
   * @return string
   */
  function galleryThumbnailImage($index = null)
  {
    if($index === null) $gal = $this->gallery;
    else $gal = $this->gallery->galleries[$index];
    
    switch($gal->filename) {
    case "__random__" :
      if(count($gal->images)>0) {
        srand(time());
        $index = rand(0,count($gal->images)-1);
        $ret  = "<img src=\"thumb.php?gallery=".urlencode($gal->id);
        $ret .= "&amp;image=".urlencode($gal->images[$index]->filename);
        $ret .= "&amp;size=".$this->config->gallery_thumb_size."\" class=\"sgGallery\" ";
        $ret .= "alt=\"".$this->i18n->_g("Sample image from gallery")."\" />";
        break;
      }
    case "__none__" :
      $ret = nl2br($this->i18n->_g("No\nthumbnail"));
      break;
    default :
      $ret  = "<img src=\"thumb.php?gallery=".urlencode($gal->id);
      $ret .= "&amp;image=".urlencode($gal->filename);
      $ret .= "&amp;size=".$this->config->gallery_thumb_size."\" class=\"sgGallery\""; 
      $ret .= "alt=\"".$this->i18n->_g("Sample image from gallery")."\" />";
    }
    return $ret;
  }
  
  /**
   * @return string
   */
  function galleryTab()
  {
    $showing = $this->galleryTabShowing();
    $links = $this->galleryTabLinks();
    if(empty($links)) return $showing;
    else return $showing." | ".$links;
  }
  
  /**
   * @return string
   */
  function galleryTabShowing()
  {
    if($this->isGallery()) {
      $total = $this->imageCount();
      $perPage = $this->config->main_thumb_number;
    } else {
      $total = $this->galleryCount();
      $perPage = $this->config->gallery_thumb_number;
    }
    
    if($this->startat+$perPage > $total)
      $last = $total;
    else
      $last = $this->startat+$perPage;
    
    return $this->i18n->_g("Showing %s-%s of %s",($this->startat+1),$last,$total);
  }
  
  /**
   * @return string
   */
  function galleryTabLinks()
  {
    $ret = "";
    if($this->galleryHasPrev()) 
      $ret .= $this->galleryPrevLink()." ";
    if($this->gallery->id != ".") 
      $ret .= "<a href=\"".$this->config->base_url."gallery=".$this->gallery->parent."\" title=\"".$this->i18n->_g("gallery|Up one level")."\">".$this->i18n->_g("gallery|Up")."</a>";
    if($this->galleryHasNext()) 
      $ret .= " ".$this->galleryNextLink();
        
    return $ret;
  }
  
  function navigationLinks() {
    $ret = "<link rel=\"Top\" title=\"".$this->config->gallery_name."\" href=\"".$this->config->base_url."gallery=.\">\n";
    
    if($this->isImage()) {
      $ret .= "<link rel=\"Up\" title=\"".$this->galleryName()."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."\">\n";
      if ($this->imageHasPrev()) {
        $ret .= "<link rel=\"Prev\" title=\"".$this->imageName($this->image->index-1)."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->gallery->images[$this->image->index-1]->filename)."\">\n";
        $ret .= "<link rel=\"First\" title=\"".$this->imageName(0)."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->gallery->images[0]->filename)."\">\n";
      }
      if ($this->imageHasNext()) {
        $ret .= "<link rel=\"Next\" title=\"".$this->imageName($this->image->index+1)."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->gallery->images[$this->image->index+1]->filename)."\">\n";
        $ret .= "<link rel=\"Last\" title=\"".$this->imageName($this->imageCount()-1)."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->gallery->images[$this->imageCount()-1]->filename)."\">\n";
      }
    } else {
      if($this->gallery->id != ".")
        $ret .= "<link rel=\"Up\" title=\"".$this->gallery->parentName."\" href=\"".$this->config->base_url."gallery=".$this->gallery->parent."\">\n";
      if($this->galleryHasPrev()) {
        $ret .= "<link rel=\"Prev\" title=\"".$this->i18n->_g("gallery|Previous")."\" href=\"".$this->galleryPrevURL()."\">\n";
        $ret .= "<link rel=\"First\" title=\"".$this->i18n->_g("gallery|First")."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;startat=0\">\n";
      }
      if($this->galleryHasNext()) {
        $ret .= "<link rel=\"Next\" title=\"".$this->i18n->_g("gallery|Next")."\" href=\"".$this->galleryNextURL()."\">\n";
        $ret .= "<link rel=\"Last\" title=\"".$this->i18n->_g("gallery|Last")."\" href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;startat=".$this->lastPageIndex()."\">\n";
      } 
    }
    return $ret;
  }
  
  
  /** 
   * @return int the number of 'pages' or 'screen-fulls'
   */
  function galleryPageCount() {
    if($this->isGallery())
      return intval($this->imageCount()/$this->config->main_thumb_number)+1;
    else
      return intval($this->galleryCount()/$this->config->gallery_thumb_number)+1;
  }
  
  /** 
   * @return int
   */
  function lastPageIndex() {
    if($this->isGallery())
      return ($this->galleryPageCount()-1)*
        ($this->isGallery()?$this->config->main_thumb_number:$this->config->gallery_thumb_number);
  }
  
  /**
   * @return bool true if there is at least one more page
   */
  function galleryHasNext() {
    if($this->isGallery())
      return count($this->gallery->images)>$this->startat+$this->config->main_thumb_number;
    else
      return count($this->gallery->galleries)>$this->startat+$this->config->gallery_thumb_number;
  }
  
  /**
   * @return bool true if there is at least one previous page
   */
  function galleryHasPrev() {
    return $this->startat>0;
  }
  
  /**
   * @return string the URL of the next page
   */
  function galleryNextURL() {
    return $this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;startat=".($this->startat+
      ($this->isGallery()?$this->config->main_thumb_number:$this->config->gallery_thumb_number));
  }
  
  function galleryNextLink() {
    return "<a href=\"".$this->galleryNextURL()."\">".$this->i18n->_g("gallery|Next")."</a>";
  }
  
  /**
   * @return string the URL of the previous page
   */
  function galleryPrevURL() {
    return $this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;startat=".($this->startat-
      ($this->isGallery()?$this->config->main_thumb_number:$this->config->gallery_thumb_number));
  }
  
  function galleryPrevLink() {
    return "<a href=\"".$this->galleryPrevURL()."\">".$this->i18n->_g("gallery|Previous")."</a>";
  }
  
  /**
   * @return string
   */
  function galleryByArtist()
  {
    if(!empty($this->gallery->artist)) return " ".$this->i18n->_g("artist name|by %s",$this->gallery->artist);
    else return "";
  }
  
  /**
   * @return string the name of the gallery
   */
  function galleryName($index = null)
  {
    if($index===null)
      return $this->gallery->name;
    else
      return $this->gallery->galleries[$index]->name;
  }
  
  /**
   * @return string the name of the gallery's artist
   */
  function galleryArtist($index = null)
  {
    if($index===null)
      return $this->gallery->artist;
    else
      return $this->gallery->galleries[$index]->artist;
  }
  
  /**
   * @return string the description of the gallery
   */
  function galleryDescription($index = null)
  {
    if($index===null)
      return $this->gallery->desc;
    else
      return $this->gallery->galleries[$index]->desc;
  }
  
  /**
   * Removes script-generated HTML (BRs and URLs) but leaves any other HTML
   * @return string the description of the gallery
   */
  function galleryDescriptionStripped($index = null)
  {
    $ret = $this->galleryDescription($index);
    
    $ret = str_replace("<br />","\n",$ret);
    
    if($this->config->enable_clickable_urls) {
      //strip off html from autodetected URLs
      $ret = preg_replace('{<a href="('.$this->regexps['genericURL'].')\">\1</a>}', '\1', $ret);
      $ret = preg_replace('{<a href="http://('.$this->regexps['wwwURL'].')">\1</a>}', '\1', $ret);
      $ret = preg_replace('{<a href="mailto:('.$this->regexps['emailURL'].')">\1</a>}', '\1', $ret);
    }
    
    return $ret;
  }
  
  /**
   * @return string the gallery's hits
   */
  function galleryViews($index = null)
  {
    if($index===null)
      return $this->gallery->hits->hits;
    else
      return $this->gallery->galleries[$index]->hits->hits;
  }
  
  /**
   * @return array array of details
   */
  function galleryDetailsArray()
  {
    $ret = array();
    if(!empty($this->gallery->email))
      if($this->config->obfuscate_email)
        $ret[$this->i18n->_g("Email")] = strtr($this->gallery->email,array("@" => " <b>at</b> ", "." => " <b>dot</b> "));
      else
        $ret[$this->i18n->_g("Email")] = "<a href=\"mailto:".$this->gallery->email."\">".$this->gallery->email."</a>";
    if(!empty($this->gallery->desc))
      $ret[$this->i18n->_g("Description")] = $this->gallery->desc;
    if(!empty($this->gallery->copyright))
      $ret[$this->i18n->_g("Copyright")] = $this->gallery->copyright;
    elseif(!empty($this->gallery->artist))
      $ret[$this->i18n->_g("Copyright")] = $this->gallery->artist;
    if($this->config->show_views && !empty($this->gallery->hits))
      $ret[$this->i18n->_g("Viewed")] = $this->i18n->_ng("viewed|%s time", "viewed|%s times",$this->gallery->hits);
    
    return $ret;
  }
  
  /**
   * @return array array of sgImage objects
   */
  function galleryImagesArray()
  {
    return $this->gallery->images;
  }
  
  /**
   * @return array array of {@link sgImage} objects
   */
  function gallerySelectedImagesArray()
  {
    return array_slice($this->gallery->images, $this->startat, $this->config->main_thumb_number);
  }
  
  /**
   * @return array array of {@link sgGallery} objects
   */
  function galleryGalleriesArray()
  {
    return $this->gallery->galleries;
  }
  
  /**
   * @return array array of {@link sgGallery} objects
   */
  function gallerySelectedGalleriesArray()
  {
    return array_slice($this->gallery->galleries, $this->startat, $this->config->gallery_thumb_number);
  }
  
  /**
   * @uses imageThumbnailImage
   * @return string
   */
  function imageThumbnailLinked()
  {
    $ret  = "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->image->filename)."\">";
    $ret .= $this->imageThumbnailImage();
    $ret .= "</a>";
    return $ret;
  }
  
  /**
   * @return string
   */
  function imageThumbnailImage()
  {
    list($thumbWidth, $thumbHeight) = $this->thumbnailSize($this->imageWidth(), $this->imageHeight(), $this->config->main_thumb_size);
    $ret  = "<img src=\"thumb.php?gallery=".$this->gallery->idEncoded."&amp;image=";
    $ret .= urlencode($this->image->filename)."&amp;size=".$this->config->main_thumb_size."\" ";
    $ret .= "class=\"sgThumbnail\" width=\"".$thumbWidth."\" height=\"".$thumbHeight."\" ";
    $ret .= "alt=\"".$this->imageName().$this->imageByArtist()."\" title=\"".$this->imageName().$this->imageByArtist()."\" />";
    return $ret;
  }
  
  function thumbnailSize($imageWidth, $imageHeight, $maxsize)
  {
    if($imageWidth < $imageHeight && ($imageWidth>$maxsize || $imageHeight>$maxsize)) {
      $thumbWidth = floor($imageWidth/$imageHeight * $maxsize);
      $thumbHeight = $maxsize;
    } elseif($imageWidth>$maxsize || $imageHeight>$maxsize) {
      $thumbWidth = $maxsize;
      $thumbHeight = floor($imageHeight/$imageWidth * $maxsize);
    } elseif($imageWidth == 0 || $imageHeight == 0) {
      $thumbWidth = $maxsize;
      $thumbHeight = $maxsize;
    } else {
      $thumbWidth = $imageWidth;
      $thumbHeight = $imageHeight;
    }
    return array($thumbWidth, $thumbHeight);
  }
  
  function imageWidth()
  {
    if(!$this->config->max_image_size || ($this->config->max_image_size > $this->image->width && $this->config->max_image_size > $this->image->height)) 
      return $this->image->width;
    else
      if($this->image->width < $this->image->height)
        return floor($this->image->width/$this->image->height * $this->config->max_image_size);
      else
        return $this->config->max_image_size;
  }
  
  function imageHeight()
  {
    if(!$this->config->max_image_size || ($this->config->max_image_size > $this->image->width && $this->config->max_image_size > $this->image->height)) 
      return $this->image->height;
    else
      if($this->image->width > $this->image->height)
        return floor($this->image->height/$this->image->width * $this->config->max_image_size);
      else
        return $this->config->max_image_size;
  }
  
  
  
  ///////////////////////////
  //////image functions//////
  ///////////////////////////
  
  /**
   * @return string link for adding a comment to image
   */
  function imageCommentLink()
  {
    return "<a href=\"".$this->config->base_url."action=addcomment&amp;gallery=".$this->gallery->idEncoded."&amp;image=".rawurlencode($this->image->filename)."\">".$this->i18n->_g("Add a comment")."</a>";
  }
  
  /**
   * @return string the name of the image
   */
  function imageName($index = null)
  {
    if($index===null)
      return $this->image->name;
    else
      return $this->gallery->images[$index]->name;
  }
  
  /**
   * @return string the name of the image's artist
   */
  function imageArtist($index = null)
  {
    if($index===null)
      return $this->image->artist;
    else
      return $this->gallery->images[$index]->artist;
  }
  
  /**
   * If there is an artist defined for this image it returns " by" followed
   * by the artist name; otherwise it returns and empty string
   * @return string
   */
  function imageByArtist($index = null)
  {
    if($this->imageArtist($index)!="") return " ".$this->i18n->_g("artist name|by %s",$this->imageArtist($index));
    else return "";
  }
  
  /**
   * @uses imageURL
   * @return string
   */
  function image()
  {
    $ret = "<img src=\"".$this->imageURL()."\" ";
    if($this->imageWidth() && $this->imageHeight())
      $ret .= "width=\"".$this->imageWidth()."\" height=\"".$this->imageHeight()."\" ";
    $ret .= "alt=\"".$this->imageName().$this->imageByArtist()."\" />\n";
    return $ret;
  }
  
  /**
   * @return string
   */
  function imageURL()
  {
    if($this->config->max_image_size)
      return "thumb.php?gallery=".$this->gallery->idEncoded."&amp;image=".rawurlencode($this->image->filename)."&amp;size=".$this->config->max_image_size;
    
    //check if image is local (filename does not start with 'http://')
    if(substr($this->image->filename,0,7)!="http://") 
      return $this->config->pathto_galleries.$this->gallery->idEncoded."/".rawurlencode($this->image->filename);
    else 
      return $this->image->filename;
  }
  
  /**
   * @return string
   */
  function imagePreviewThumbnails()
  {
    $ret = "";
    for($i=$this->config->preview_thumb_number;$i>0;$i--) {
      if(isset($this->gallery->images[$this->image->index-$i])) 
        $temp = $this->gallery->images[$this->image->index-$i];
      else
        continue;
      
      list($thumbWidth, $thumbHeight) = $this->thumbnailSize($temp->width, $temp->height, $this->config->preview_thumb_size);
      $ret .= "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($temp->filename)."\">";
      $ret .= "<img src=\"thumb.php?gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($temp->filename)."&amp;";
      $ret .= "size=".$this->config->preview_thumb_size."\" width=\"".$thumbWidth."\" height=\"".$thumbHeight."\" alt=\"".$temp->name."\" title=\"".$temp->name."\" />";
      $ret .= "</a>\n";
    }
    
    list($thumbWidth, $thumbHeight) = $this->thumbnailSize($this->image->width, $this->image->height, $this->config->preview_thumb_size);
    $ret .= "<img src=\"thumb.php?gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($this->image->filename)."&amp;";
    $ret .= "size=".$this->config->preview_thumb_size."\" width=\"".$thumbWidth."\" height=\"".$thumbHeight."\" alt=\"".$this->imageName()."\" title=\"".$this->imageName()."\" />\n";
    
    for($i=1;$i<=$this->config->preview_thumb_number;$i++) {
      if(isset($this->gallery->images[$this->image->index+$i])) 
        $temp = $this->gallery->images[$this->image->index+$i];
      else
        continue;
      list($thumbWidth, $thumbHeight) = $this->thumbnailSize($temp->width, $temp->height, $this->config->preview_thumb_size);
      $ret .= "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($temp->filename)."\">";
      $ret .= "<img src=\"thumb.php?gallery=".$this->gallery->idEncoded."&amp;image=".urlencode($temp->filename)."&amp;";
      $ret .= "size=".$this->config->preview_thumb_size."\" width=\"".$thumbWidth."\" height=\"".$thumbHeight."\" alt=\"".$temp->name."\" title=\"".$temp->name."\" />";
      $ret .= "</a>\n";
    }
    return $ret;
  }
  
  /**
   * @uses imageHasPrev
   * @return string html link to the previous image if one exists
   */
  function imagePrevLink()
  {
    if($this->imageHasPrev())
      return "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".
             urlencode($this->gallery->images[$this->image->index-1]->filename)."\" title=\"".$this->imageName($this->image->index-1)."\">".$this->i18n->_g("image|Previous")."</a> | \n";
  }

  /**
   * @uses imageHasPrev
   * @return string html link to the first image if not already first
   */
  function imageFirstLink()
  {
    if($this->imageHasPrev())
      return "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".
             urlencode($this->gallery->images[0]->filename)."\" title=\"".$this->imageName(0)."\">".$this->i18n->_g("image|First")."</a> | \n";
  }

  /**
   * @return boolean
   */
  function imageHasPrev()
  {
    return isset($this->gallery->images[$this->image->index-1]);
  }
  
  /**
   * @return string
   */
  function imageParentLink()
  {
    return "<a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;startat=".
           (floor($this->image->index/$this->config->main_thumb_number)*$this->config->main_thumb_number)."\" title=\"".$this->galleryName()."\">".$this->i18n->_g("image|Thumbnails")."</a>\n";
  }
  
  /**
   * @uses imageHasNext
   * @return string html link to the next image if one exists
   */
  function imageNextLink()
  {
    if($this->imageHasNext())
      return " | <a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".
             urlencode($this->gallery->images[$this->image->index+1]->filename)."\" title=\"".$this->imageName($this->image->index+1)."\">".$this->i18n->_g("image|Next")."</a>\n";
  }
  
  /**
   * @uses imageHasNext
   * @return string html link to the last image if not already last
   */
  function imageLastLink()
  {
    if($this->imageHasNext())
      return " | <a href=\"".$this->config->base_url."gallery=".$this->gallery->idEncoded."&amp;image=".
             urlencode($this->gallery->images[$this->imageCount()-1]->filename)."\" title=\"".$this->imageName($this->imageCount()-1)."\">".$this->i18n->_g("image|Last")."</a>\n";
  }
  
  /**
   * @return boolean
   */
  function imageHasNext()
  {
    return isset($this->gallery->images[$this->image->index+1]);
  }
  
  
  /**
   * @return array
   */
  function imageDetailsArray()
  {
    $ret = array();
    if(!empty($this->image->email))
      if($this->config->obfuscate_email)
        $ret[$this->i18n->_g("Email")] = strtr($this->image->email,array("@" => " <b>at</b> ", "." => " <b>dot</b> "));
      else
        $ret[$this->i18n->_g("Email")] = "<a href=\"mailto:".$this->image->email."\">".$this->image->email."</a>";
    if(!empty($this->image->location))
      $ret[$this->i18n->_g("Location")] = $this->image->location;
    if(!empty($this->image->date))
      $ret[$this->i18n->_g("Date")] = $this->image->date;
    if(!empty($this->image->desc))
      $ret[$this->i18n->_g("Description")] = $this->image->desc;
    if(!empty($this->image->camera))
      $ret[$this->i18n->_g("Camera")] = $this->image->camera;
    if(!empty($this->image->lens))
      $ret[$this->i18n->_g("Lens")] = $this->image->lens;
    if(!empty($this->image->film))
      $ret[$this->i18n->_g("Film")] = $this->image->film;
    if(!empty($this->image->darkroom))
      $ret[$this->i18n->_g("Darkroom manipulation")] = $this->image->darkroom;
    if(!empty($this->image->digital))
      $ret[$this->i18n->_g("Digital manipulation")] = $this->image->digital;
    if(!empty($this->image->copyright))
      $ret[$this->i18n->_g("Copyright")] = $this->image->copyright;
    elseif(!empty($this->image->artist))
      $ret[$this->i18n->_g("Copyright")] = $this->image->artist;
    if($this->config->show_views && !empty($this->image->hits))
      $ret[$this->i18n->_g("Viewed")] = $this->i18n->_ng("viewed|%s time", "viewed|%s times",$this->image->hits);
    
    return $ret;
  }

  
}


?>
