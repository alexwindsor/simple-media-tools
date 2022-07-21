<?php
namespace AlexWindsor;

class SimpleMediaTools {

  public $media_data = [];
  public $media_path;
  public $mimeType;
  public $type;

  public function __construct($media_path) {

    $this->media_path = $media_path;
    $this->mimeType = mime_content_type($media_path);
    $this->type = substr($this->mimeType, 0, 5);

    // get image data from 'file' bash command
    if ($this->type == 'image') {
      $data = shell_exec('file ' . $media_path);
      $data = preg_replace('/\[|\]/', '', $data);
      $data = explode(',', $data);

      foreach ($data as $key => $value) {
        if (strpos($value, '=')) {
          $row = explode('=', $value);
          $this->media_data[trim($row[0])] = trim($row[1]);
        }
        elseif(preg_match('/[0-9]+x[0-9]+/', $value)) {
          $dimensions = explode('x', $value);
          $this->media_data['width'] = $dimensions[0];
          $this->media_data['height'] = $dimensions[1];
        }
      }
      // check if the image is portrait, in which case, swap the width and height values
      if (isset($this->media_data['orientation']) && $this->media_data['orientation'] == 'upper-right') {
        $w = $this->media_data['height'];
        $h = $this->media_data['width'];
        $this->media_data['height'] = $h;
        $this->media_data['width'] = $w;
      }
    }

    // get video data from 'ffprobe' bash command
    elseif ($this->type == 'video') {

      $data = shell_exec('ffprobe -v quiet -select_streams v:0 -show_streams ' . $media_path);
      $data = explode(PHP_EOL, $data);

      foreach ($data as $key => $value) {
        if (!strpos($value, '=')) continue;
        $row = explode('=', $value);
        $this->media_data[$row[0]] = $row[1];
      }

      if (isset($this->media_data['rotation']) && ($this->media_data['rotation'] == '-90' || $this->media_data['rotation'] == '90')) {
        $w = $this->media_data['height'];
        $h = $this->media_data['width'];
        $this->media_data['height'] = $h;
        $this->media_data['width'] = $w;
      }
    }
    // get audio data from 'ffprobe' bash command
    elseif ($this->type == 'audio') {
      $seconds = shell_exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . $media_path);
      $this->media_data['duration'] = $seconds;
    }


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
      // rotate image
      shell_exec('ffmpeg -y -i ' . $this->media_path . ' -vf transpose=' . $direction . ' ' . $this->media_path . '.jpg');
      // overwrite original file (because ffmpeg needs to have .jpg in the filename)
      shell_exec('mv ' . $this->media_path . '.jpg ' . $this->media_path);
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
    }
    else return false;

    return true;
  }






  public function resizeToWidth($width, $image_filename) {

    if ($this->type != 'image') return false;

    $width = intval($width);

    if ($width < 2) return false;

    shell_exec('ffmpeg -i ' . $this->media_path . ' -vf scale=' . $width . ':-1 ' . $image_filename . '.jpg');
    shell_exec('mv ' . $image_filename . '.jpg ' . $image_filename);

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

  }





}

