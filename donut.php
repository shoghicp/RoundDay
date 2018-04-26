<?php

include("Framebuffer.php");

$version = "v0.3";

$touchscreen = null;

function getPicture($out, $delay = " --delay 2 "){
        //passthru("ffmpeg -y $delay -f v4l2 -framerate 10 -video_size 320x240 -i /dev/video0 -frames:v 1 \"$out\"");
        passthru("fswebcam --no-overlay --png 2 --no-underlay --no-info --no-timestamp --no-title --no-shadow --no-banner --frames 1 --skip 10 --resolution 360x240 $delay --dev                                                                                                                                             ice /dev/video0 --save $out");
}

function writeAreaCenter(Framebuffer $f, $bg, $fg, $text, $y, $scale = 1){
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
	
	while(!file_exists("/dev/input/touchscreen")){
		sleep(1);
	}
	
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

function isK3Pressed(){
	return trim(file_get_contents("/sys/devices/virtual/gpio/gpio102/value")) == "0";
}

function isK2Pressed(){
	return trim(file_get_contents("/sys/devices/virtual/gpio/gpio104/value")) == "0";
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
		
		return [[$startColumn, $startRow], [$startColumn + ($column * $scale) + $x, $startRow + ($row * $scale)]];
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

$validActs = [/*null,*/ "tsundere", "kuudere", "deredere", "dandere", "depressed"];
$validLikes = [/*null,*/ "choco", "icing", "sprinkles", "color", "pet", "grow", "consumed"];

$validEyebrows = [null, "calm", "angry", "neutral"];
$validIcings = [null, "choco_dark", "choco_milk", "icing_pink"];
$validSprinkles = [null, "choco", "color"];

$effectSingleHearth = [["single_heart1"]];
$effectSingleHearthAnimated = [["single_heart1"], ["single_heart2"], ["single_heart3"], ["single_heart4"]];

$effectHearthAnimated = [["heart1"], ["heart2"], ["heart3"], ["heart4"], ["heart5"]]; //Can be shuffled (array_shuffle)


$effectHearthMixedAnimated = [];

for($i = 0; $i < 16; ++$i){
	$hearts = [1, 2, 3, 4, 5];
	
	$number = mt_rand(1, 4);
	
	$entries = [];
	for($j = 0; $j < $number; ++$j){
		$e = mt_rand(0, count($hearts) - 1);
		$entries[$hearts[$e]] = $hearts[$e];
	}
	$e = [];
	foreach($entries as $h){
		$e[] = "heart" . $h;
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

function generateNewState($validIcings, $validSprinkles, $validEyebrows){
	return [
		"current_tick" => 0,
		"size" => 1, // 1, 2, 3, 4
		"nib_stage" => 0, //0, 1, 2, 3
		"happiness" => 0,
		"face" => [
			"hidden" => false,
			"level" => mt_rand(1, 5), //1, 2, 3, 4, 5
			//TODO: special V and ^ face
			"blush" => (bool)mt_rand(0, 1),
			"eyes" => "middle_up", // <left|middle|right>_<up|middle|down>
			"eyebrows" => $validEyebrows[mt_rand(0, count($validEyebrows) - 1)], // null, angry, calm, neutral
		],
		
		"toppings" => [
			"cover" => $validIcings[mt_rand(0, count($validIcings) - 1)], // null, choco_dark, choco_milk, icing_pink
			"sprinkles" => $validSprinkles[mt_rand(0, count($validSprinkles) - 1)], // null, choco, color
		],
		
		"effect" => null,//"single_heart_animated", //anything from $effects or null
		"dialog" => null, //TODO
	];

}

function generateDonutFeelings($validLikes, $validActs, $name = ""){
	$loves = [];
	$hates = [];
	

	
	$act = $validActs[mt_rand(0, count($validActs) - 1)];
	
	
	$phrases = [
		"loves" => [],
		"neutral" => [],
		"hates" => [],
		"eat" => [],
	];
	
	$blushWhenLove = false;
	$blushWhenEat = false;
	$heartsWhenLove = false;
	$hearts = false;
	
	
	$startFace = mt_rand(2, 4);
	$lovesCount = mt_rand(2, 3);
	$hatesCount = 1;
	$maxAge = mt_rand(3, 4);
	$maxFace = 5;
	$minFace = 1;
	$eyebrows = null;
	$phrase = null;
	
	if($act === "tsundere"){
		//abuse when they like, embarrased when like?
		$startFace = mt_rand(2, 3);
		$maxFace = 4;
		$minFace = 2;
		$eyebrows = mt_rand(0, 1) ? null : "angry";
		
		$blushWhenLove = true;
		$phrases["loves"][] = "It's not like I wanted it...";
		$phrases["loves"][] = "baka~!";
		$phrases["loves"][] = "Don't get the wrong idea!";
		$phrases["loves"][] = "It's not like I hate you...";
		
		$phrases["hates"][] = "Senpai never notices me!";
		
		$phrases["neutral"][] = "Go away!";
		$phrases["neutral"][] = "Why are you so annoying";
		
		$phrases["eat"][] = "Too close... senpai";
		$phrases["eat"][] = "Stop... it!";
		
		$phrase = "Looks angry at you for some reason.";
		
	}else if($act === "kuudere"){
		//serious, neutral, but still caring
		$startFace = 2;
		$maxFace = 2;
		$minFace = 2;
		$eyebrows = mt_rand(0, 1) ? null : "neutral";
		
		$phrases["loves"][] = "I guess it's nice";
		$phrases["loves"][] = "That might be fine";
		
		$phrases["neutral"][] = "What are you doing?";
		
		$phrases["hates"][] = "I shouldn't trust you";
		$phrases["hates"][] = "Please stop that";
		
		$phrases["eat"][] = "That's fine";
		$phrases["eat"][] = "Are you done already?";
		
		$phrase = "Looks cold, hard, and crunchy.";
		
	}else if($act === "deredere"){
		//all loving and caring
		$startFace = mt_rand(3, 4);
		$lovesCount = mt_rand(2, 4);
		$maxAge = mt_rand(2, 4);
		$eyebrows = mt_rand(0, 1) ? null : "calm";
		$hatesCount = 0;
		$maxFace = 5;
		$minFace = 3;
		$heartsWhenLove = true;
		$blushWhenEat = true;
		$hearts = true;
		
		$phrases["loves"][] = "I want more!";
		$phrases["loves"][] = "You know me too well";
		
		$phrases["neutral"][] = "Just because you gave it to me...";
	
		$phrases["eat"][] = "Finally you decided";
		$phrases["eat"][] = "Please make me yours";
		$phrases["eat"][] = "I like you being so decisive";
		
		$phrase = "It already likes you. Too much.";
	}else if($act === "dandere"){
		//shy, opens up to the right person
		$startFace = mt_rand(2, 3);
		$lovesCount = mt_rand(2, 3);
		$maxAge = mt_rand(2, 4);
		$eyebrows = mt_rand(0, 1) ? null : "calm";
		$hatesCount = 1;
		$maxFace = 4;
		$minFace = 2;
		
		$blushWhenLove = true;
		$blushWhenEat = true;
		
		$phrases["loves"][] = "Please don't...";
		$phrases["loves"][] = "Too close...";
		$phrases["loves"][] = "I kinda like this...";
		
		$phrases["eat"][] = "Please be gentle senpai~~";
		$phrases["eat"][] = "Ah! That's too hard...";
		
		$phrase = "Soft and hot on the inside.";
		
	}else if($act === "depressed"){
		// :(
		$startFace = mt_rand(1, 2);
		$lovesCount = mt_rand(2, 3);
		$hatesCount = mt_rand(2, 3);
		$maxFace = 3;
		$minFace = 1;
		
		$phrases["loves"][] = "That's not that much better...";
		$phrases["loves"][] = "You don't understand me";
		$phrases["loves"][] = "My life went from black to dark";
		
		$phrases["hates"][] = "That's just great";
		$phrases["hates"][] = "You are not helping";
		
		$phrases["neutral"][] = "Life is hard";
		
		$phrases["eat"][] = "Make it fast";
		$phrases["eat"][] = "Please end my suffering";
		
		$phrase = "Has seen better days.";
	}
	
	for($i = 0; $i < $lovesCount; ++$i){
		$entry = $validLikes[mt_rand(0, count($validLikes) - 1)];
		$loves[$entry] = $entry;
	}
	
	for($i = 0; $i < $hatesCount; ++$i){
		$entry = $validLikes[mt_rand(0, count($validLikes) - 1)];
		if(!isset($loves[$entry])){
			$hates[$entry] = $entry;
		}
	}
	
	$description = "";
	
	if($name !== ""){
		$description .= "This donut is $name.\n";
	}else{
		$description .= "This donut has no name.\n";
	}
	
	$description .= "It likes";
	if(count($loves) === 0){
		$description .= " nothing.\n";
	}else{
		$description .= ":\n";
		foreach($loves as $love){
			if($love === "choco"){
				$description .= "chocolate\r";
			}else if($love === "icing"){
				$description .= "being covered\r";
			}else if($love === "color"){
				$description .= "colorful things\r";
			}else if($love === "pet"){
				$description .= "being pet\r";
			}else if($love === "consumed"){
				$description .= "being consumed\r";
			}else if($love === "grow"){
				$description .= "growing\r";
			}else if($love === "sprinkles"){
				$description .= "being sprinkled\r";
			}
		}
		$description .= "\n";
	}
	$description .= "It dislikes";
	if(count($hates) === 0){
		$description .= " nothing.\n";
	}else{
		$description .= ":\n";
		foreach($hates as $hate){
			if($hate === "choco"){
				$description .= "chocolate\r";
			}else if($hate === "icing"){
				$description .= "being covered\r";
			}else if($hate === "color"){
				$description .= "colorful things\r";
			}else if($hate === "pet"){
				$description .= "being pet\r";
			}else if($hate === "consumed"){
				$description .= "being consumed\r";
			}else if($hate === "grow"){
				$description .= "growing\r";
			}else if($hate === "sprinkles"){
				$description .= "being sprinkled\r";
			}
		}
		$description .= "\n";
	}
	
	
	if($phrase !== null){
		$description .= "$phrase\n";
		
	}
	
	

	return [
		"name" => null,
		"description" => $description,
		"act" => $act,
		"loves" => $loves,
		"hates" => $hates,
		"phrases" => $phrases,
		"start_face" => $startFace,
		"max_face" => $maxFace,
		"min_face" => $minFace,
		"max_age" => $maxAge,
		"hearts" => $hearts,
		"blush_when_loved" => $blushWhenLove,
		"blush_when_eaten" => $blushWhenEat,
		"hearts_when_loved" => $heartsWhenLove,
		"eyebrows" => $eyebrows,
		"cover" => null,
		"sprinkles" => null,
	];
	
}

$assets = [];

function getAsset($name){
	global $assets;
	if(!isset($assets[$name])){
		$assets[$name] = imageToArray("imgs/{$name}.png");
	}
	return $assets[$name];
}

function composeDonut(Framebuffer $f, $state, $animationStage, $center = true){
	global $effects;
	
	if($state["nib_stage"] <= 2){
		$area = paintArray($f, getAsset("base"), $state["size"], $center);
		
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
		
		if(!$state["face"]["hidden"]){
			paintArray($f, getAsset("face_level" . $state["face"]["level"]), $state["size"], $center);
			paintArray($f, getAsset("face_eyes_" . $state["face"]["eyes"]), $state["size"], $center);
			if($state["face"]["blush"]){
				paintArray($f, getAsset("face_blush"), $state["size"], $center);	
			}
			if($state["face"]["eyebrows"] !== null){
				paintArray($f, getAsset("face_eyebrows_" . $state["face"]["eyebrows"]), $state["size"], $center);	
			}
		}
		
		if($state["effect"] !== null){
			$value = $effects[$state["effect"]];
			if($value["shuffle"]){
				$assets = $value["data"][mt_rand(0, count($value["data"]) - 1)];
			}else{
				$assets = $value["data"][$animationStage % count($value["data"])];
			}
			
			foreach($assets as $asset){
				paintArray($f, getAsset($asset), $state["size"], $center);
			}
		}
	
		if($state["dialog"] !== null){
			writeAreaCenter($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), $state["dialog"], 220, 1);		
		}
		if($center === true){
			writeArea($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), (string)$state["happiness"], 5, 30, 2);	
		}
		return $area;
	}
	
	return null;
	
}

function alterDonutState($action, &$state, $stage){
	global $lastTick;
	
	if($stage["blush_when_loved"]){
		$state["face"]["blush"] = false;
	}
	
	if(in_array($action, $stage["loves"])){
		$state["face"]["level"] = max($stage["min_face"], min($stage["max_face"], $state["face"]["level"] + 1));
		if(count($stage["phrases"]["loves"]) > 0){
			$state["dialog"] = $stage["phrases"]["loves"][$state["text_tick"] % count($stage["phrases"]["loves"])];
			++$state["text_tick"];
		}
		
		if($stage["blush_when_loved"]){
			$state["face"]["blush"] = true;
		}
		if($stage["hearts_when_loved"]){
			$state["effect"] = "heart_animated_mixed";
		}
		$state["happiness"] += 20;
	}else if(in_array($action, $stage["hates"])){
		$state["face"]["level"] = max($stage["min_face"], min($stage["max_face"], $state["face"]["level"] - 1));
		if(count($stage["phrases"]["hates"]) > 0 and $lastTick !== $state["current_tick"]){
			$state["dialog"] = $stage["phrases"]["hates"][$state["text_tick"] % count($stage["phrases"]["hates"])];
			++$state["text_tick"];
		}
		
		if($stage["hearts_when_loved"] and $lastTick !== $state["current_tick"]){
			$state["effect"] = $stage["hearts"] ? "single_heart_animated" : null;
		}
		if($state["happiness"] >= 100 and $state["happiness"] < 115){
			$state["effect"] = $stage["hearts"] ? "single_heart_animated" : null;
		}
		$state["happiness"] -= 15;
	}else if ($lastTick !== $state["current_tick"] and $lastTick !== $state["current_tick"]){
		if(count($stage["phrases"]["neutral"]) > 0){
			$state["dialog"] = $stage["phrases"]["neutral"][$state["text_tick"] % count($stage["phrases"]["neutral"])];
			++$state["text_tick"];
		}
		$state["happiness"] += mt_rand(-5, 5);
	}
	
	if($action === "consumed"){
		if($stage["blush_when_eaten"]){
			$state["face"]["blush"] = true;
		}
		if(count($stage["phrases"]["eat"]) > 0){
			$state["dialog"] = $stage["phrases"]["eat"][$state["text_tick"] % count($stage["phrases"]["eat"])];
			++$state["text_tick"];
		}
	}
	
	if($state["happiness"] >= 100){
		$state["effect"] = "heart_animated_mixed";
	}
	
	$lastTick = $state["current_tick"];
}

$lastTick = 0;
$ticksPerSecond = 5;

$stages = [
	0 => generateDonutFeelings($validLikes, ["kuudere"], "Rei"),
	1 => generateDonutFeelings($validLikes, ["dandere"], "Mio"),
	2 => generateDonutFeelings($validLikes, ["depressed"], "Marvin"),
	3 => generateDonutFeelings($validLikes, ["tsundere"], "Asuka"),
	4 => generateDonutFeelings($validLikes, ["deredere"]),
	//0 => generateDonutFeelings($validLikes, [null], $name = "Donut-san")
];

$currentStage = 0;

paintArray($f, getAsset("intro"));
writeArea($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), $version, 280, 230, 1);	
$f->flush();

readTouchEvent($f);
$lastTouch = null;
do{
	$touch = readTouchEvent($f);
	if($touch !== null){
		$lastTouch = $touch;
	}
	if($touch === null and $lastTouch !== null){
		$lastTouch = null;
		break;
	}
	usleep(50000);
}while(true);

do{
	readTouchEvent($f);
	$lastTouch = null;

	$animationStage = 0;

	$dialogStage = 0;
	
	if(!isset($stages[$currentStage])){
		$stage = generateDonutFeelings($validLikes, $validActs);
	}else{
		$stage = $stages[$currentStage];
	}
	
	$state = [
		"current_tick" => 0,
		"text_tick" => mt_rand(10000, 90000),
		"size" => 1, // 1, 2, 3, 4
		"nib_stage" => 0, //0, 1, 2, 3
		"happiness" => 0,
		"face" => [
			"hidden" => false,
			"level" => $stage["start_face"],
			"blush" => false,
			"eyes" => "middle_up",
			"eyebrows" => $stage["eyebrows"],
		],
		
		"toppings" => [
			"cover" => $stage["cover"],
			"sprinkles" => $stage["sprinkles"],
		],
		
		"effect" => $stage["hearts"] ? "single_heart_animated" : null,
		"dialog" => null,
	];

	$dialogs = explode("\n", $stage["description"]);
	foreach($dialogs as $d){
		if(isK2Pressed() and isK3Pressed()){
			exec("con2fbmap 1 2");
			exec("chvt 1");
			exec("poweroff");
			exit();
		}
		if(isK3Pressed()){
			exit();
		}
		if(trim($d) === ""){
			continue;
		}
		$f->fill($f->getColor(Framebuffer::COLOR_WHITE), 0, 0, $f->getX(), $f->getY());
		composeDonut($f, $state, $animationStage, [200, 100]);
		foreach(explode("\r", $d) as $i => $line){
			writeArea($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), $line, 20, 20 + 12 * $i, 1);
		}
		$f->flush();
		
		do{
			$touch = readTouchEvent($f);
			if($touch !== null){
				$lastTouch = $touch;
			}
			if($touch === null and $lastTouch !== null){
				$lastTouch = null;
				break;
			}
			usleep(50000);
		}while(true);
	}
	
	$petArea = null;

	
	while($state["nib_stage"] < 3){
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
			$state["dialog"] = null;
			//We selected
			if($lastTouch[1] < 30){
				//buttons!
				if($state["size"] < $stage["max_age"] and $state["nib_stage"] === 0 and $lastTouch[0] < 300 and $lastTouch[0] > 240){
					//GROW
					$state["size"]++;
					alterDonutState("grow", $state, $stage);
				}else if($state["size"] >= min($stage["max_age"], 3) and $lastTouch[0] < 64 and $lastTouch[0] > 10){
					//EAT
					$state["nib_stage"]++;
					alterDonutState("consumed", $state, $stage);
				}else if($state["size"] >= min($stage["max_age"], 3) and $state["nib_stage"] === 0 and $lastTouch[0] < 210 and $lastTouch[0] > 80){
					//DECORATE
					$f->fill($f->getColor(Framebuffer::COLOR_WHITE), 0, 0, $f->getX(), $f->getY());
					if($state["toppings"]["cover"] === null){
						$chocoDark = paintArray($f, getAsset("topping_choco_dark"), 2, [10, 30]);
						$chocoMilk = paintArray($f, getAsset("topping_choco_milk"), 2, [90, 30]);
						$icingPink = paintArray($f, getAsset("topping_icing_pink"), 2, [170, 30]);
					}
					if($state["toppings"]["sprinkles"] === null){
						$sprinklesColor = paintArray($f, getAsset("topping_sprinkles_color"), 2, [10, 120]);
						$sprinklesChoco = paintArray($f, getAsset("topping_sprinkles_choco"), 2, [90, 120]);
					}
					
					writeArea($f, $f->getColor(Framebuffer::COLOR_ORANGE), $f->getColor(Framebuffer::COLOR_BLACK), "EXIT", 80, 5, 2);
					$f->flush();
					
					$lastTouch2 = null;
					do{
						$touch2 = readTouchEvent($f);
						if($touch2 !== null){
							$lastTouch2 = $touch2;
						}
						if($touch2 === null and $lastTouch2 !== null){
							if($lastTouch2[1] < 30){
								break;
							}
							
							if($state["toppings"]["cover"] === null){
								if($lastTouch2[0] < $chocoDark[1][0] and $lastTouch2[0] > $chocoDark[0][0] and $lastTouch2[1] < $chocoDark[1][1] and $lastTouch2[1] > $chocoDark[0][1]){
									$state["toppings"]["cover"] = "choco_dark";
									alterDonutState("choco", $state, $stage);
									alterDonutState("icing", $state, $stage);
									break;
								}else if($lastTouch2[0] < $chocoMilk[1][0] and $lastTouch2[0] > $chocoMilk[0][0] and $lastTouch2[1] < $chocoMilk[1][1] and $lastTouch2[1] > $chocoMilk[0][1]){
									$state["toppings"]["cover"] = "choco_milk";
									alterDonutState("choco", $state, $stage);
									alterDonutState("icing", $state, $stage);
									break;
								}else if($lastTouch2[0] < $icingPink[1][0] and $lastTouch2[0] > $icingPink[0][0] and $lastTouch2[1] < $icingPink[1][1] and $lastTouch2[1] > $icingPink[0][1]){
									$state["toppings"]["cover"] = "icing_pink";
									alterDonutState("color", $state, $stage);
									alterDonutState("icing", $state, $stage);
									break;
								}
							}
							if($state["toppings"]["sprinkles"] === null){
								if($lastTouch2[0] < $sprinklesColor[1][0] and $lastTouch2[0] > $sprinklesColor[0][0] and $lastTouch2[1] < $sprinklesColor[1][1] and $lastTouch2[1] > $sprinklesColor[0][1]){
									$state["toppings"]["sprinkles"] = "color";
									alterDonutState("color", $state, $stage);
									alterDonutState("sprinkles", $state, $stage);
									break;
								}else if($lastTouch2[0] < $sprinklesChoco[1][0] and $lastTouch2[0] > $sprinklesChoco[0][0] and $lastTouch2[1] < $sprinklesChoco[1][1] and $lastTouch2[1] > $sprinklesChoco[0][1]){
									$state["toppings"]["sprinkles"] = "choco";
									alterDonutState("choco", $state, $stage);
									alterDonutState("sprinkles", $state, $stage);
									break;
								}
							}
							$lastTouch2 = null;
						}
						usleep(50000);
					}while(true);
				}
				
			}
			
			if($petArea !== null){
				if($lastTouch[0] < $petArea[1][0] and $lastTouch[0] > $petArea[0][0] and $lastTouch[1] < $petArea[1][1] and $lastTouch[1] > $petArea[0][1]){
					//PET
					alterDonutState("pet", $state, $stage);
				}
			}
			
			$lastTouch = null;
		}
		
		$f->fill($f->getColor(Framebuffer::COLOR_WHITE), 0, 0, $f->getX(), $f->getY());
		
		$petArea = composeDonut($f, $state, $animationStage);
		
		if($state["size"] < $stage["max_age"] and $state["nib_stage"] === 0){
			writeArea($f, $f->getColor(Framebuffer::COLOR_ORANGE), $f->getColor(Framebuffer::COLOR_BLACK), "GROW", 240, 5, 2);			
		}
		//Let's not eat minors
		if($state["size"] >= min($stage["max_age"], 3)){
			writeArea($f, $f->getColor(Framebuffer::COLOR_ORANGE), $f->getColor(Framebuffer::COLOR_BLACK), "EAT", 10, 5, 2);
		}
		//Let's have minor/mutilated beauty pageants
		if($state["size"] >= min($stage["max_age"], 3) and $state["nib_stage"] === 0){ 
			writeArea($f, $f->getColor(Framebuffer::COLOR_ORANGE), $f->getColor(Framebuffer::COLOR_BLACK), "DECORATE", 80, 5, 2);
		}
		
		
		
		if($touch !== null){
			$f->fill($b, $touch[0] - 2, $touch[1] - 2, $touch[0] + 2, $touch[1] + 2);
		}
		
		
		
		$f->flush();
		

		$animationStage++;
		++$state["current_tick"];
		
		usleep(1000000 / $ticksPerSecond);
	}
	
	$f->fill($f->getColor(Framebuffer::COLOR_WHITE), 0, 0, $f->getX(), $f->getY());
	writeAreaCenter($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), "Happiness", 60, 4);
	writeAreaCenter($f, $f->getColor(Framebuffer::COLOR_WHITE), $f->getColor(Framebuffer::COLOR_BLACK), (string)$state["happiness"], 100, 5);
	$f->flush();
	
	++$currentStage;
	sleep(3);
}while(true);

