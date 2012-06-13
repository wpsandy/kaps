<?php
/**
* @package SPLIB
* @version $Id: Thumbnail.php,v 1.1 2003/12/12 08:06:06 kevin Exp $
*/
/**
* Thumbnail<br />
* Resizes images to thumbnails
* @package SPLIB
* @access public
* @todo bug fix for multiple images
* @todo PHP < 4.3.0 compatibility
*/
class Thumbnail {
    /**
    * Maximum width of the thumbnail in pixels
    * @access private
    * @var  int
    */
    var $maxWidth;

    /**
    * Maximum height of the thumbnail in pixels
    * @access private
    * @var  int
    */
    var $maxHeight;

    /**
    * Whether to scale image to fit thumbnail (true) or
    * strech to fit (false)
    * @access private
    * @var  boolean
    */
    var $scale;

    /**
    * UP = Whether to inflate images smaller the the thumbnail
    * HEIGHT = Height is the limiting factor
    * WIDTH = Width is the limiting factor
    * @access private
    * @var  str
    */
    var $inflate;

    /**
    * List of accepted image types based on MIME description
    * @access private
    * @var  array
    */
    var $types;

    /**
    * Stores function names for each image type e.g. imagecreatefromjpeg
    * @access private
    * @var array
    */
    var $imgLoaders;

    /**
    * Stores function names for each image type e.g. imagejpeg
    * @access private
    * @var array
    */
    var $imgCreators;

    /**
    * The source image
    * @access private
    * @var resource
    */
    var $source;

    /**
    * Width of source image in pixels
    * @access private
    * @var  int
    */
    var $sourceWidth;

    /**
    * Height of source image in pixels
    * @access private
    * @var  int
    */
    var $sourceHeight;

    /**
    * MIME type of source image
    * @access private
    * @var  string
    */
    var $sourceMime;

    /**
    * The thumbnail
    * @access private
    * @var  resource
    */
    var $thumb;

    /**
    * Width of thumbnail in pixels
    * @access private
    * @var  int
    */
    var $thumbWidth;

    /**
    * Height of thumbnail in pixels
    * @access private
    * @var  int
    */
    var $thumbHeight;

    /**
    * Thumbnail constructor
    * @param int max width of thumbnail
    * @param int max height of thumbnail
    * @param boolean (optional) if true image scales
    * @param boolean (optional) if true inflate small images
    * @access public
    */
    function Thumbnail ($maxWidth,$maxHeight,$scale="width",$inflate=true) {
        $this->maxWidth=$maxWidth;
        $this->maxHeight=$maxHeight;
        $this->scale=strtolower(substr($scale,0,1));
        $this->inflate=$inflate;

        // Consider modifying these to add to handle other images
        $this->types=array('image/jpeg','image/png', 'image/gif');
        $this->imgLoaders=array(
                'image/jpeg'=>'imagecreatefromjpeg',
                'image/png'=>'imagecreatefrompng',
				'image/gif'=>'imagecreatefromgif',
                    );
        $this->imgCreators=array(
                'image/jpeg'=>'imagejpeg',
                'image/png'=>'imagepng',
				'image/gif'=>'imagegif',
                    );
    }

    /**
    * Loads an image from a file
    * @param string filename (with path) of image
    * @return boolean
    * @access public
    */
    function loadFile ($image) {
        if ( !$dims=@GetImageSize($image) ) {
            trigger_error('Could not find image '.$image);
            return false;
        }
        if ( in_array($dims['mime'],$this->types) ) {
            $loader=$this->imgLoaders[$dims['mime']];
            $this->source=$loader($image);
            $this->sourceWidth=$dims[0];
            $this->sourceHeight=$dims[1];
            $this->sourceMime=$dims['mime'];
            $this->initThumb();
            return true;
        } else {
            trigger_error('Image MIME type '.$dims['mime'].' not supported');
            return false;
        }
    }

    /**
    * Loads an image from a string (e.g. database)
    * @param string the image
    * @param mime mime type of the image
    * @return boolean
    * @access public
    */
    function loadData ($image,$mime) {
        if ( in_array($mime,$this->types) ) {
            $this->source=imagecreatefromstring($image);
            $this->sourceWidth=imagesx($this->source);
            $this->sourceHeight=imagesy($this->source);
            $this->sourceMime=$mime;
            $this->initThumb();
            return true;
        } else {
            trigger_error('Image MIME type "'.$mime.'" not supported');
            return false;
        }
    }

    /**
    * If a filename is provides, creates the thumbnail using that name
    * If not, the image is output to the browser
    * @param string (optional) filename to create image with
    * @return boolean
    * @access public
    */
    function buildThumb ($file=null) {
        $creator=$this->imgCreators[$this->sourceMime];
        if ( isset ( $file ) ) {
            return $creator($this->thumb,$file);
        } else {
            return $creator($this->thumb);
        }
    }

    /**
    * Returns the mime type for the thumbnail
    * @return string
    * @access public
    */
    function getMime () {
        return $this->sourceMime;
    }

    /**
    * Returns the width of the thumbnail
    * @return int
    * @access public
    */
    function getThumbWidth() {
        return $this->thumbWidth;
    }

    /**
    * Returns the height of the thumbnail
    * @return int
    * @access public
    */
    function getThumbHeight() {
        return $this->thumbHeight;
    }

    /**
    * Creates the thumbnail
    * @return void
    * @access private
    */
    function initThumb () {
        if ( $this->scale=="u" ) {
          if ( $this->sourceWidth > $this->sourceHeight ) {
                $this->thumbWidth=$this->maxWidth;
                $this->thumbHeight=floor(
                    $this->sourceHeight*($this->maxWidth/$this->sourceWidth)
                       );
          } else if ( $this->sourceWidth < $this->sourceHeight ) {
                $this->thumbHeight=$this->maxHeight;
                $this->thumbWidth=floor(
                    $this->sourceWidth*($this->maxHeight/$this->sourceHeight)
                        );
            } else {
                $this->thumbWidth=$this->maxWidth;
                $this->thumbHeight=$this->maxHeight;
            }

        } else if ($this->scale=="w") {
        	$this->thumbHeight=$this->maxHeight;
            $this->thumbWidth=floor(
                $this->sourceWidth*($this->maxHeight/$this->sourceHeight)
            );
	    } else if ($this->scale=="h") {
	    	 $this->thumbHeight=$this->maxHeight;
             $this->thumbWidth=floor(
                 $this->sourceWidth*($this->maxHeight/$this->sourceHeight)
             );
		} else {
            $this->thumbWidth=$this->maxWidth;
            $this->thumbHeight=$this->maxHeight;
        }

        $this->thumb=imagecreatetruecolor($this->thumbWidth,
                                          $this->thumbHeight);
        if ( $this->sourceWidth <= $this->maxWidth &&
                $this->sourceHeight <= $this->maxHeight &&
                    $this->inflate == false ) {
            $this->thumb=& $this->source;
        } else {
            imagecopyresampled( $this->thumb, $this->source, 0, 0, 0, 0,
                              $this->thumbWidth, $this->thumbHeight,
                              $this->sourceWidth, $this->sourceHeight );
        }
    }
}
?>