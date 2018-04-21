<?php

include("Framebuffer.php");

$touchscreen = null;

function getPicture($out, $delay = " --delay 2 "){
        //passthru("ffmpeg -y $delay -f v4l2 -framerate 10 -video_size 320x240 -i /dev/video0 -frames:v 1 \"$out\"");
        passthru("fswebcam --no-overlay --png 2 --no-underlay --no-info --no-timestamp --no-title --no-shadow --no-banner --frames 1 --skip 10 --resolution 360x240 $delay --dev                                                                                                                                             ice /dev/video0 --save $out");
}

function writeArea(Framebuffer $f, $bg, $fg, $text, $x, $y, $scale = 1){
        $f->fill($bg, $x, $y, $x + $f->getLength($text, $scale) + 1, $y + Framebuffer::FONT_HEIGHT * $scale + 1);
        $f->writeText($fg, $text, $x + 1, $y + 1, $scale);
}

function initScreen(){
	global $touchscreen;
	exec("modprobe spicc");
	exec("modprobe fbtft_device name=odroidc_tft32 rotate=270 gpios=reset:116,dc:115 speed=32000000 cs=0");
	exec("modprobe ads7846");
	
	$touchscreen = fopen("/dev/input/touchscreen", "r");
	stream_set_blocking($touchscreen, 0);
}

function readTouchEvent(Framebuffer $f){
	global $touchscreen;
	/*
	struct input_event {
		struct timeval time{
			time_t      tv_sec  //seconds, 32 bit
			suseconds_t tv_usec //microseconds, 32 bit
		};
		__u16 type;
		__u16 code;
		__s32 value;
	};
	
	*/
	
	$event = null;
	$partial = [null, null];
	
	for($i = 0; $i < 32; ++$i){
		$data = fread($touchscreen, 16);
		if(strlen($data) < 16){
			return $event;
		}
		$parsed = unpack("Itime/Imicro/Stype/Scode/Ivalue", $data);
		if($parsed["type"] === 3){
			if($parsed["code"] === 0){
				$partial[0] = $parsed["value"];
				if($partial[1] !== null){
					$event = [(int)round(($partial[0] / 3900) * $f->getX()), (int)round(((3900 - $partial[1]) / 3900) * $f->getY())];
				}
			}else if($parsed["code"] === 1){
				$partial[1] = $parsed["value"];
				if($partial[0] !== null){
					$event = [(int)round(($partial[0] / 3900) * $f->getX()), (int)round(((3900 - $partial[1]) / 3900) * $f->getY())];
				}
			}
		}
	}
	stream_get_contents($touchscreen);
	return $event;
}

function findScreen(){
	foreach(scandir("/sys/class/graphics") as $f){
		if(file_exists("/sys/class/graphics/$f/name")){
			if(trim(file_get_contents("/sys/class/graphics/$f/name")) == "fb_odroidc_tft32"){
				return "/dev/$f";
			}
		}
	}
	return null;
}

function initGPIO(){
	file_put_contents("/sys/class/gpio/export", "87\n");
	file_put_contents("/sys/class/gpio/export", "102\n");
	file_put_contents("/sys/class/gpio/export", "104\n");
}

function isK2Pressed(){
	return file_get_contents("/sys/devices/virtual/gpio/gpio102/value") == "1";
}

function isK3Pressed(){
	return file_get_contents("/sys/devices/virtual/gpio/gpio104/value") == "1";
}

//$f->setInstant(true);

function rgb24_to_rgb16($r, $g, $b){
        return (($r >> 3) << 11) | (($g >> 2) << 5) | ($b >> 3);
}

function rgb_to_rgb24($r, $g, $b){

}

function paintImage(Framebuffer $f, $image, $scale = 1, $center = [0, 0]){
        $im = new \Imagick($image);
        //$im->setImageBackgroundColor(new \ImagickPixel('white'));
        $it = $im->getPixelIterator();


        if($center === true){
                $startColumn = (int) round(($f->getX() - $im->getImageWidth() * $scale) / 2);
                $startRow = (int) round(($f->getY() - $im->getImageHeight() * $scale) / 2);
        }else{
			$startColumn = $center[0];
			$startRow = $center[1];
		}
        foreach ($it as $row => $pixels) { /* Loop through pixel rows */
        foreach ($pixels as $column => $pixel) { /* Loop through the pixels in the row (columns) */
            $c = $pixel->getColor(false);
            //var_dump(str_pad(decbin(rgb24_to_rgb16($c["r"], $c["g"], $c["b"])), 16, "0", STR_PAD_LEFT));
                for($x = 0; $x < $scale; ++$x){
                        for($y = 0; $y < $scale; ++$y){
                                $f->pixel(pack("v", rgb24_to_rgb16($c["r"], $c["g"], $c["b"])), $startColumn + ($column * $scale) + $x, $startRow + ($row * $scale) + $y);
                        }
                }
        }
        $it->syncIterator(); /* Sync the iterator, this is important to do on each iteration */
    }
    $f->flush();
}

initScreen();
initGPIO();

do{
	$screen = findScreen();
	if($screen === null){
		sleep(1);
	}
}while($screen === null);

date_default_timezone_set("Europe/Stockholm");

$f = new Framebuffer($screen, 320, 240, 16);
$f->loadFont("font.php");

$b = $f->getColor(Framebuffer::COLOR_BLACK);
$w = $f->getColor(Framebuffer::COLOR_WHITE);

while(true){
	$touch = readTouchEvent($f);
	$f->fill($b, 0, 0, $f->getX(), $f->getY());
	if($touch !== null){
		$f->fill($w, $touch[0] - 2, $touch[1] - 2, $touch[0] + 2, $touch[1] + 2);
		var_dump($touch);
	}
	$f->flush();
	//sleep(1);
}


