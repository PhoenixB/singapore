<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" 
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title><?php echo $sg->translator->_g("image|Slideshow").' - '.$sg->pageTitle(); ?></title>
<style type="text/css">@import url("<?php echo $sg->config->base_url.$sg->config->pathto_current_template.'css/layout.css");</style>'; ?>
<style type="text/css">@import url("<?php echo $sg->config->base_url.$sg->config->pathto_current_template.'css/color_'.$sg->config->template_scheme.'.css");</style>'; ?>
<!--[if IE]><link rel="stylesheet" type="text/css" media="all" href="<?php echo $sg->config->base_url.$sg->config->pathto_current_template; ?>css/ie.css"><![endif]-->
<?php if($sg->image->hasNext() && !isset($control)) {
echo '<meta http-equiv="refresh" content="5;url='.$sg->image->nextURL('slideshow').'" />';
} ?>
</head>
<body id="slideshow">
<!-- 
	This page was generated by singapore <http://singapore.sourceforge.net>
	singapore is free software licensed under the terms of the GNU GPL.
-->
	<div class="sgImageWrapper">
		<div class="sgImageBox" style="width: <?php echo $sg->image->width(); ?>px; margin-top:-<?php echo floor($sg->image->height()/2); ?>px;" title="<?php foreach($sg->image->detailsArray() as $key => $value): ?><?php echo $key; ?>: <?php echo $value; ?><?php endforeach; ?>">
			<?php echo $sg->image->imageHTML() ?>
			<a class="thumb" style="width: <?php echo $sg->image->width(); ?>px;" href="<?php echo $sg->image->parent->URL().'"><span>'.$sg->translator->_g("image|Thumbnails").'</span></a>'; ?>
			<?php if($sg->image->hasPrev()) {echo '<a class="prev" style="height:'.$sg->image->height().'px;" href="'.$sg->image->prevURL(); if (!strstr($sg->image->prevURL(), '?')) { echo '?'; } else { echo '&'; } echo 'action=slideshow"><span style="margin-top:'.floor(($sg->image->height())/2).'px;">'.$sg->translator->_g("image|Previous").'</span></a>';}?>
			<?php if($sg->image->hasNext()) {echo '<a class="next" style="height:'.$sg->image->height().'px;" href="'.$sg->image->nextURL(); if (!strstr($sg->image->nextURL(), '?')) { echo '?'; } else { echo '&'; } echo 'action=slideshow"><span style="margin-top:'.floor(($sg->image->height())/2).'px;">'.$sg->translator->_g("image|Next").'</span></a>';}?>
			<?php if (!isset($control)) {
				echo '<a class="control" style="width:'.$sg->image->width().'px;" href="'.$sg->image->URL(); if (!strstr($sg->image->URL(), '?')) { echo '?'; } else { echo '&'; } echo 'action=slideshow&control=pause"><span>'.$sg->translator->_g("image|Pause").'</span></a>'; 
				} 
				else {
				echo '<a class="control" style="width:'.$sg->image->width().'px;" href="'.$sg->image->URL(); if (!strstr($sg->image->URL(), '?')) { echo '?'; } else { echo '&'; } echo 'action=slideshow"><span>'.$sg->translator->_g("image|Play").'</span></a>'; 					
				}
			?>
		</div>	
	</div>
</body>
</html>