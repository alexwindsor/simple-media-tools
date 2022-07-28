<?php
namespace AlexWindsor;

class SimpleMediaTools {

  /*
  requires:

  ffmpeg / ffprobe
  convert (imagemagick)
  exiftool


  */

  public $media_data = [];
  public $media_path;
  public $mimeType;
  public $type;

  public function __construct($media_path) {

    $this->media_path = $media_path;

    if (!file_exists($media_path)) die($media_path);

    // if we are processing a collection of images for a timelapse, then we expect a directory rather than a file here
    if (is_dir($this->media_path)) $this->type = 'timelapse';
    else {
      $this->mimeType = mime_content_type($media_path);
      $this->type = substr($this->mimeType, 0, 5);
    }


    // get image data from 'exif' bash command
    if ($this->type == 'image') $this->getImageData();
    // get video data from 'ffprobe' bash command
    elseif ($this->type == 'video') $this->getVideoData();
    // get duration of the sound file from 'ffprobe' bash command
    elseif ($this->type == 'audio') $this->getSoundData();


  }



  private function getImageData() {

    $this->media_data = [];

    $data = shell_exec('exiftool ' . $this->media_path);

    $data = explode("\n", $data);

    $skipped_exif_values = ['ExifTool Version Number', 'File Name', 'Directory', 'File Size', 'File Modification Date/Time', 'File Access Date/Time', 'File Inode Change Date/Time', 'File Permissions', 'File Type', 'File Type Extension', 'MIME Type'];

    for ($i = 0; $i < count($data); $i++) {
      if (!strpos($data[$i], ':')) continue; // skip lines with no data
      $row = explode(':', $data[$i], 2);
      if (in_array(trim($row[0]), $skipped_exif_values)) continue; // skip values that are in the above array
      $this->media_data[trim($row[0])] = trim($row[1]); // make associative array
    }

  }


  private function getVideoData() {

    $this->media_data = [];

    $data = shell_exec('ffprobe -v quiet -select_streams v:0 -show_streams ' . $this->media_path);
    $data = explode(PHP_EOL, $data);

    foreach ($data as $key => $value) {
      if (!strpos($value, '=')) continue;
      $row = explode('=', $value, 2);
      $this->media_data[trim($row[0])] = trim($row[1]);
    }

  }



  private function getSoundData() {

    $this->media_data = [];

    $seconds = shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . $this->media_path);
    $this->media_data['duration'] = $seconds;
  }






  public function setVideoPosterImage($secs, $image_filename) {
    // grab an image from a video
    shell_exec('ffmpeg -ss ' . $secs . ' -i ' . $this->media_path . ' -frames:v 1 -q:v 2 ' . $image_filename . '.jpg');
    // rename the image removing the extra .jpg extension
    shell_exec('mv ' . $image_filename . '.jpg ' . $image_filename);
  }



  public function rotate($direction) {

    ignore_user_abort();
    ini_set('max_execution_time', 0);

    if ($direction < 1 || $direction > 2) return false;

    if ($this->type == 'image') {
      $degrees = $direction == 1 ? '90' : '-90';
      shell_exec('convert ' . $this->media_path . ' -rotate ' . $degrees . ' ' . $this->media_path);
      // update exif
      $this->getImageData();
    }
    elseif ($this->type == 'video') {

      if ($this->mimeType == 'video/mp4') $ext = '.mp4';
      elseif ($this->mimeType == 'video/quicktime') $ext = '.mov';

      // make an empty file to indicate that the video is being processed
      touch($this->media_path . '_locked');
      // rotate the video
      shell_exec('ffmpeg -i ' . $this->media_path . ' -vf transpose=' . $direction . ' ' . $this->media_path . $ext);
      // overwrite original file (because ffmpeg needs to have .jpg in the filename)
      shell_exec('mv ' . $this->media_path . $ext . ' ' . $this->media_path);
      // finally, delete the empty file to indicate that the video is no longer processing
      unlink($this->media_path . '_locked');

      // update metadata
      $this->getVideoData();
    }
    else return false;

    return true;
  }



  public function resize($lengths, $suffixes, $dimension) {

    if ($this->type != 'image') return false;

    if (!is_array($lengths)) $length[] = $lengths;
    else $length = $lengths;
    if (!is_array($suffixes)) $suffix[] = $suffixes;
    else $suffix = $suffixes;

    if (count($length) != count($suffix)) return false;

    for ($i = 0; $i < count($length); $i++) {
      if ($length[$i] < 2) continue;

      $scale = $dimension == 'width' ? intval($length[$i]) . 'x' : 'x' . intval($length[$i]);

      shell_exec('convert -geometry ' . $scale . ' ' . $this->media_path . ' ' . $this->media_path . $suffix[$i]);

    }

  }




  public function crop($start_time, $end_time) {

    $start_time = floatval($start_time);
    $end_time = floatval($end_time);

    $time = $end_time - $start_time;

    if ($this->type == 'audio') $ext = '.mp3';
    elseif ($this->mimeType == 'video/mp4') $ext = '.mp4';
    elseif ($this->mimeType == 'video/quicktime') $ext = '.mov';

    // make an empty file to indicate that the video is being processed
    touch($this->media_path . '_locked');
    // crop the media
    shell_exec('ffmpeg -i ' . $this->media_path . ' -ss ' . $start_time . ' -t ' . $time . ' ' . $this->media_path . $ext);
    // overwrite original file (because ffmpeg needs to have the appropriate file extention)
    shell_exec('mv ' . $this->media_path . $ext . ' ' . $this->media_path);
    // finally, delete the empty file to indicate that the video is no longer processing
    unlink($this->media_path . '_locked');

    // update the meta data
    if ($this->type == 'video') $this->getVideoData();
    // get duration of the sound file from 'ffprobe' bash command
    elseif ($this->type == 'audio') $this->getSoundData();

  }



  public function makeTimelapse($width, $height, $framerate, $output_path) {

    // make the timelapse video
    shell_exec('ffmpeg -framerate ' . intval($framerate) . ' -pattern_type glob -i "' . $this->media_path . '/*.jpg" -pix_fmt yuv420p ' . $output_path . '.mp4');

    shell_exec('mv ' . $output_path . '.mp4 ' . $output_path);


    // reset the media object for this iteration as the newly created video
    $this->media_path = $output_path;
    $this->mimeType = 'video/mp4';
    $this->type == 'video';

    $this->getVideoData();

  }





}

