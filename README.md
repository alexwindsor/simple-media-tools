# simple-media-tools
Some custom tools for editing video and audio files using mostly ffmpeg


Initialise an object with the path and filename of the media file in question. It currently works with the following types:

image/jpeg
audio/mpeg
video/mp4
video/quicktime

eg.

$video = new SimpleMediaTools('/full/path/to/movie.mp4');

On initialisation, various properties are gleaned depending on the mimeType, using the ffprobe command and the file command for images, such as dimensions, duration for video and audio.

echo $video->media_data['width'];
echo $video->media_data['duration'];
print_r($video->media_data);

The following methods are currently included:


setVideoPosterImage($secs, $image_filename)

Makes an image screengrab from the video.

$secs - number of seconds into a video from where to generate an image
$image_filename - what to save the image as


rotate($direction)

Rotates both an image or a video

$direction = 1 clockwise
$direction = 2 counter clockwise


resizeToWidth($width, $suffix = '')

Resizes an image to the given width, maintaining height ratio

$width - width in pixels to resize the image to
$suffix - what to call the resized image


crop($start_time, $end_time)

Removes time from the beginning and end of a video or audio file

$start_time/$end_time - start/end time in seconds (works with milliseconds as float) in relation to original media
