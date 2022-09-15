<?php
namespace AlexWindsor;

class SimpleMediaTools {

  /*
  requires:

  ffmpeg
  ffprobe
  exiftool
  */

  public $media_data = [];
  public $media_path;
  public $mimeType;
  public $type;

  public $ffprobe_video_data = [
    'codec_long_name' => 'Video Codec Name',
    'width' => 'Width',
    'height' => 'Height',
    'sample_aspect_ratio' => 'Sample Aspect Ratio',
    'display_aspect_ratio' => 'Display Aspect Ratio',
    'pix_fmt' => 'Pixel Format',
    'r_frame_rate' => 'Real Frame Rate',
    'avg_frame_rate' => 'Average Frame Rate',
    'bit_rate' => 'Bit Rate',
    'duration' => 'Duration (seconds)',
    'nb_frames' => 'Number of Frames',
    'TAG:rotate' => 'Rotation',
    'TAG:creation_time' => 'Create Date'
  ];

  public $ffprobe_audio_data = [
    'duration' => 'Duration (seconds)',
    'codec_long_name' => 'Audio Codec Name',
    'sample_rate' => 'Audio Sample Rate',
    'channels' => 'Audio Channels',
    'channel_layout' => 'Audio Channel Layout',
    'bit_rate' => 'Audio Bit Rate',
    'nb_frames' => 'Number of Audio Frames',
    'TAG:creation_time' => 'Create Date',
  ];




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
    // get video or sound data from 'ffprobe' bash command
    elseif ($this->type == 'video' || $this->type == 'audio') $this->getVideoOrSoundData();


  }



  private function getImageData() {

    // ini_set('memory_limit', '-1');

    $this->media_data = [];

    $data = shell_exec('exiftool ' . $this->media_path);

    $data = iconv('UTF-8', 'UTF-8//IGNORE', $data); // remove non utf8 characters that don't work in json
    $data = explode("\n", $data);

    $skipped_exif_values = ['ExifTool Version Number', 'File Name', 'Directory', 'File Size', 'File Modification Date/Time', 'File Access Date/Time', 'File Inode Change Date/Time', 'File Permissions', 'File Type', 'File Type Extension', 'MIME Type'];

    for ($i = 0; $i < count($data); $i++) {
      if (!strpos($data[$i], ':')) continue; // skip lines with no data
      $row = explode(':', $data[$i], 2);
      if (trim($row[0]) == 'File Modification Date/Time') $just_in_case_date = trim($row[1]);
      if (in_array(trim($row[0]), $skipped_exif_values)) continue; // skip values that are in the above array
      $this->media_data[trim($row[0])] = trim($row[1]); // make associative array
    }

    if (!isset($this->media_data['Create Date']) && !isset($this->media_data['Modify Date'])) $this->media_data['File Modification Date/Time'] = substr($just_in_case_date, 0, strpos($just_in_case_date, '+'));

  }


  private function getVideoOrSoundData() {

    $ffprobe = shell_exec('ffprobe -v quiet -show_streams ' . $this->media_path);

    $ffprobe = explode(PHP_EOL . '[/STREAM]' . PHP_EOL . '[STREAM]', $ffprobe);

    $this->media_data = ['audio' => false];


    foreach ($ffprobe as $channel) {

      $channel = explode(PHP_EOL, $channel);

      // video channel
      if (in_array('codec_type=video', $channel)) {
        foreach ($channel as $line) {
          if (!strpos($line ,'=')) continue;
          $data_line = explode('=', $line);
          if (in_array($data_line[0], array_keys($this->ffprobe_video_data))) $this->media_data[$this->ffprobe_video_data[$data_line[0]]] = trim($data_line[1]);
        }
      }

      // audio channel
      elseif (in_array('codec_type=audio', $channel)) {
        $this->media_data['audio'] = true;
        foreach ($channel as $line) {
          if (!strpos($line ,'=')) continue;
          $data_line = explode('=', $line);
          if (in_array($data_line[0], array_keys($this->ffprobe_audio_data))) $this->media_data[$this->ffprobe_audio_data[$data_line[0]]] = trim($data_line[1]);
        }
      }
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
      $this->getVideoOrSoundData();
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
    $this->getVideoOrSoundData();

  }



  public function makeTimelapse($width, $height, $framerate, $output_path) {

    // get exif data from the first image - we need width height and date
    $this->getImageData($this->media_path .'/000000.jpg');

    $dimensions = [$this->media_data['Image Width'], $this->media_data['Image Height']];
    sort($dimensions);

    if ($dimensions[0] > 1000) {
      $ratio = $dimensions[0] / $dimensions[1];
      $dimensions[0] = 1000;
      $dimensions[1] = $ratio * 1000;
      if ($this->media_data['Image Width'] > $this->media_data['Image Height']) {
        $width = $dimensions[0];
        $height = $dimensions[1];
      }
      else {
        $width = $dimensions[1];
        $height = $dimensions[0];
      }
    }
    else {
      $width = $this->media_data['Image Width'];
      $height = $this->media_data['Image Height'];
    }

    $date_made = $this->media_data['Create Date'] ?? $this->media_data['Modify Date'] ?? null;

    // make the timelapse video
    shell_exec('ffmpeg -framerate ' . intval($framerate) . ' -pattern_type glob -i "' . $this->media_path . '/*.jpg" -s:v ' . $width . 'x' . $height . ' -c:v libx264 -crf 17 -pix_fmt yuv420p ' . $output_path . '.mp4');

    shell_exec('mv ' . $output_path . '.mp4 ' . $output_path);


    // reset the media object for this iteration as the newly created video
    $this->media_path = $output_path;
    $this->mimeType = 'video/mp4';
    $this->type == 'video';

    $this->getVideoOrSoundData();
    $this->media_data['date_made'] = $date_made;

  }





}
