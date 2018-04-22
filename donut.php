<?php

include("Framebuffer.php");

$touchscreen = null;

function getPicture($out, $delay = " --delay 2 "){
        //passthru("ffmpeg -y $delay -f v4l2 -framerate 10 -video_size 320x240 -i /dev/video0 -frames:v 1 \"$out\"");
        passthru("fswebcam --no-overlay --png 2 --no-underlay --no-info --no-timestamp --no-title --no-shadow --no-banner --frames 1 --skip 10 --resolution 360x240 $delay --dev                                                                                                                                             ice /dev/video0 --save $out");
}

function writeAreaCencer(Framebuffer $f, $bg, $fg, $text, $y, $scale = 1){
	writeArea($f, $bg, $fg, $text, (int)round(($f->getX() / 2) - ($f->getLength($text, $scale) / 2)), $y, $scale);
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
function imageToArray($image, $all = true){
        $im = new \Imagick($image);
        //$im->setImageBackgroundColor(new \ImagickPixel('white'));
        $it = $im->getPixelIterator();

		$array = [];

        foreach ($it as $row => $pixels) { /* Loop through pixel rows */
			$array[$row] = [];
			foreach ($pixels as $column => $pixel) { /* Loop through the pixels in the row (columns) */
				$c = $pixel->getColor(false);
				if($all){
					$array[$row][$column] = $c["a"] !== 1 ? null : pack("v", rgb24_to_rgb16($c["r"], $c["g"], $c["b"]));
				}else if($c["a"] === 1){
					$array[$row][$column] = pack("v", rgb24_to_rgb16($c["r"], $c["g"], $c["b"]));
				}
			}
			$it->syncIterator(); /* Sync the iterator, this is important to do on each iteration */
		}
    return $array;
}

function paintArray(Framebuffer $f, array $image, $scale = 1, $center = [0, 0]){
        if($center === true){
                $startColumn = (int) round(($f->getX() - count($image[0]) * $scale) / 2);
                $startRow = (int) round(($f->getY() - count($image) * $scale) / 2);
        }else{
			$startColumn = $center[0];
			$startRow = $center[1];
		}
        foreach ($image as $row => $pixels) { /* Loop through pixel rows */
			foreach ($pixels as $column => $pixel) { /* Loop through the pixels in the row (columns) */
				if($pixel === null){
					continue;
				}
                for($x = 0; $x < $scale; ++$x){
                    for($y = 0; $y < $scale; ++$y){
                        $f->pixel($pixel, $startColumn + ($column * $scale) + $x, $startRow + ($row * $scale) + $y);
                    }
                }
			}
		}
}

function maskArray(Framebuffer $f, array $image, $mask, $scale = 1, $center = [0, 0]){
        if($center === true){
                $startColumn = (int) round(($f->getX() - count($image[0]) * $scale) / 2);
                $startRow = (int) round(($f->getY() - count($image) * $scale) / 2);
        }else{
			$startColumn = $center[0];
			$startRow = $center[1];
		}
        foreach ($image as $row => $pixels) { /* Loop through pixel rows */
			foreach ($pixels as $column => $pixel) { /* Loop through the pixels in the row (columns) */
				if($pixel === null){
					continue;
				}
                for($x = 0; $x < $scale; ++$x){
                    for($y = 0; $y < $scale; ++$y){
                        $f->pixel($mask, $startColumn + ($column * $scale) + $x, $startRow + ($row * $scale) + $y);
                    }
                }
			}
		}
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

$validIcings = [null, "choco_dark", "choco_milk", "icing_pink"];
$validSprinkles = [null, "choco", "color"];

$effectSingleHearth = [["single_heart_1"]];
$effectSingleHearthAnimated = [["single_heart_1"], ["single_heart_2"], ["single_heart_3"], ["single_heart_4"]];

$effectHearthAnimated = [["heart_1"], ["heart_2"], ["heart_3"], ["heart_4"], ["heart_5"]]; //Can be shuffled (array_shuffle)


$effectHearthMixedAnimated = [];

for($i = 0; $i < 16; ++$i){
	$hearts = [1, 2, 3, 4, 5];
	
	$number = mt_rand(0, 3);
	
	$entries = [];
	for($j = 0; $j < $number; ++$j){
		$e = mt_rand(0, count($hearts) - 1);
		$entries[$hearts[$e]] = $hearts[$e];
	}
	$e = [];
	foreach($entries as $h){
		$e[] = $h;
	}
	$effectHearthMixedAnimated[] = $e;
}

$effects = [
	"single_heart" => [
		"shuffle" => false,
		"data" => $effectSingleHearth,
	],
	"single_heart_animated" => [
		"shuffle" => false,
		"data" => $effectSingleHearthAnimated,
	],
	
	"heart_animated" => [
		"shuffle" => true,
		"data" => $effectHearthAnimated,
	],
	
	"heart_animated_mixed" => [
		"shuffle" => true,
		"data" => $effectHearthMixedAnimated,
	],
];

$maxSize = 3;
$maxFaceLevel = 5;

$state = [
	"current_tick" => 0,
	"size" => 1, // 1, 2, 3, 4
	"nib_stage" => 0, //0, 1, 2, 3
	"face" => [
		"hidden" => false,
		"level" => 3, //1, 2, 3, 4, 5
		"blush" => true,
		"eyes" => "middle_up", // <left|middle|right>_<up|middle|down>
	],
	
	"toppings" => [
		"cover" => "icing_pink", // null, choco_dark, choco_milk, icing_pink
		"sprinkles" => "color", // null, choco, color
	],
	
	"effect" => "single_heart_animated", //anything from $effects or null
	"dialog" => null, //TODO
];

$assets = [];

function getAsset($name){
	global $assets;
	if(!isset($assets[$name])){
		$assets[$name] = imageToArray("imgs/{$name}.png");
	}
	return $assets[$name];
}

function composeDonut(Framebuffer $f, $state, $animationStage){
	$center = true;
	
	$f->fill($f->getColor(Framebuffer::COLOR_WHITE), 0, 0, $f->getX(), $f->getY());
	
	if($state["nib_stage"] <= 2){
		paintArray($f, getAsset("base"), $state["size"], $center);
		
		if($state["nib_stage"] > 0){
			paintArray($f, getAsset("nib_stage" . $state["nib_stage"]), $state["size"], $center);
		}
		
		if($state["toppings"]["cover"] !== null){
			paintArray($f, getAsset("topping_" . $state["toppings"]["cover"]), $state["size"], $center);
		}
		
		if($state["toppings"]["sprinkles"] !== null){
			paintArray($f, getAsset("topping_sprinkles_" . $state["toppings"]["sprinkles"]), $state["size"], $center);
		}
		
		if($state["nib_stage"] > 0){
			maskArray($f, getAsset("nib_stage" . $state["nib_stage"] . "_mask"), $f->getColor(Framebuffer::COLOR_WHITE), $state["size"], $center);
		}
		
		if(!$state["face"]["hidden"] and $state["size"] > 1){
			paintArray($f, getAsset("face_level" . $state["face"]["level"]), $state["size"], $center);
			paintArray($f, getAsset("face_eyes_" . $state["face"]["eyes"]), $state["size"], $center);
			if($state["face"]["blush"]){
				paintArray($f, getAsset("face_blush"), $state["size"], $center);	
			}
		}
		
		if($state["effect"] !== null){
			$value = $state[$state["effect"]];
			if($value["shuffle"]){
				$assets = $value["data"][mt_rand(0, count($value["data"]))];
			}else{
				$assets = $value["data"][$animationStage % count($value["data"])];
			}
			
			foreach($assets as $asset){
				paintArray($f, getAsset($asset), $state["size"], $center);
			}
		}
	}
}


$ticksPerSecond = 5;

$lastTouch = null;

$animationStage = 0;

while(true){
	$touch = readTouchEvent($f);
	$face = "middle_up";
	if($touch !== null){
		if($touch[0] < ($f->getX() / 3)){
			$face = "left_";
		}else if($touch[0] < ($f->getX() / 3) * 2){
			$face = "middle_";
		}else{
			$face = "right_";
		}
		
		if($touch[1] < ($f->getY() / 3)){
			$face .= "up";
		}else if($touch[1] < ($f->getY() / 3) * 2){
			$face .= "middle";
		}else{
			$face .= "down";
		}
		
		$state["face"]["eyes"] = $face;
		$lastTouch = $touch;
	}
	
	if($touch === null and $lastTouch !== null){
		//We selected
		
		$state["size"]++;
		if($state["size"] > 4){
			$state["size"] = 4;
			$state["nib_stage"]++;
			if($state["nib_stage"] > 3){
				$state["size"] = 1;
				$state["nib_stage"] = 0;
			}
		}
		$lastTouch = null;
	}
	
	composeDonut($f, $state, $animationStage);
	
	if($touch !== null){
		$f->fill($b, $touch[0] - 2, $touch[1] - 2, $touch[0] + 2, $touch[1] + 2);
	}
	
	writeAreaCencer($f, $w, $b, "be gentle senpai", 200, 1);
	$f->flush();
	

	$animationStage++;
	
	usleep(1000000 / $ticksPerSecond);
}


