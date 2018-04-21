<?php

class Framebuffer{

        private static $COLOR_TABLE = [
                16 => [
                        self::COLOR_BLACK => "\x00\x00",
                        self::COLOR_WHITE => "\xff\xff",
                        self::COLOR_NAVY => "\x0f\xff",
                        self::COLOR_ORANGE => "\x20\xfd",
                        self::COLOR_LIGHT_GREY => "\x18\xc6",
                        self::COLOR_DARK_GREY => "\xef\x7b"
                ]
        ];

        private static $FONT_TABLE = [];

        const COLOR_BLACK = 0;
        const COLOR_WHITE = 1;

        const COLOR_NAVY = 2;
        const COLOR_ORANGE = 3;
        const COLOR_LIGHT_GREY = 4;
        const COLOR_DARK_GREY = 5;

        const FONT_HEIGHT = 8;
        const FONT_WIDTH = 8;

        private $fb;
        private $xRes;
        private $yRes;
        private $bpp;
        private $instant = false;

        private $memory = [];

        public function __construct($device, $xRes, $yRes, $bitsPerPixel){
                $this->fb = fopen($device, "r+b");
                $this->xRes = $xRes;
                $this->yRes = $yRes;
                $this->bpp = $bitsPerPixel;

                $this->reset($this->getColor(self::COLOR_BLACK));
                $this->flush();
        }

        public function setInstant($bool){
                if(!$this->instant and $bool){
                        $this->flush();
                }
                $this->instant = (bool) $bool;
        }

        public function loadFont($file){
                require($file);

                for($i = 0; $i < 256; ++$i){
                        $c = [];

                        for($y = 0; $y < self::FONT_HEIGHT; ++$y){
                                $c[$y] = [];
                                for($x = 0; $x < self::FONT_WIDTH; ++$x){
                                        $c[$y][$x] = ($font[$i * self::FONT_HEIGHT + $y] & (1 << (self::FONT_WIDTH - $x - 1))) > 0;
                                }
                        }

                        self::$FONT_TABLE[chr($i)] = $c;
                }
        }

        public function getColor($colorId){
                return self::$COLOR_TABLE[$this->bpp][$colorId];
        }

        public function getX(){
                return $this->xRes;
        }

        public function getY(){
                return $this->yRes;
        }

        public function writeText($colorCode, $text, $startX, $startY, $scale = 1, $spacing = 0){
                $len = strlen($text);
                $x = 0;
                for($i = 0; $i < $len; ++$i){
                        $this->writeCharacter($colorCode, self::$FONT_TABLE[$text{$i}], $startX + $x, $startY, $scale);
                        $x += self::FONT_WIDTH * $scale + $spacing;
                }
                return $x;
        }

        public function getLength($text, $scale = 1, $spacing = 0){
                return strlen($text) * self::FONT_WIDTH * $scale + strlen($text) * $spacing;
        }

        public function writeCharacter($colorCode, $c, $startX, $startY, $scale = 1){
                for($x = 0; $x < self::FONT_WIDTH; ++$x){
                        for($y = 0; $y < self::FONT_HEIGHT; ++$y){
                                if($c[$y][$x]){
                                        for($i = 0; $i < $scale; ++$i){
                                                for($j = 0; $j < $scale; ++$j){
                                                        $this->pixel($colorCode, $x * $scale + $i + $startX, $y * $scale + $j + $startY);
                                                }
                                        }
                                }
                        }
                }
        }

        public function reset($colorCode){
                for($y = 0; $y < $this->yRes; ++$y){
                        $this->memory[$y] = [];
                        for($x = 0; $x < $this->xRes; ++$x){
                                $this->pixel($colorCode, $x, $y);
                        }
                }
        }

        public function pixel($colorCode, $x, $y){
                if($x >= $this->xRes or $y >= $this->yRes){
                        return;
                }

                $this->memory[(int) $y][(int) $x] = $colorCode;

                if($this->instant){
                        fseek($this->fb, ($y * $this->xRes + $x) * ($this->bpp / 8));
                        fwrite($this->fb, $colorCode);
                }
        }

        public function fill($colorCode, $startX, $startY, $endX, $endY){
                for($x = $startX; $x <= $endX; ++$x){
                        for($y = $startY; $y <= $endY; ++$y){
                                $this->pixel($colorCode, $x, $y);
                        }
                }
        }

        public function recover(){

        }

        public function toImage($path){
                $image = new \Imagick();
                $image->newImage($this->getX(), $this->getY(), new ImagickPixel('black'));
                $image->setImageFormat('png');
                $it = $image->getPixelIterator();
                foreach ($it as $row => $pixels) { /* Loop through pixel rows */
                        foreach ($pixels as $column => $pixel) { /* Loop through the pixels in the row (columns) */
                                $c = unpack("n", $this->memory[(int) $row][(int) $column])[1];
                                $r = (($c >> (6 + 5)) & 0b111111) << 3;
                                $g = (($c >> 5) & 0b111111) << 2;
                                $b = ($c & 0b11111) << 3;
                                //var_dump("rgb($r, $g, $b)");
                                $pixel->setColor("rgb($r, $g, $b)");
                        }
                        $it->syncIterator(); /* Sync the iterator, this is important to do on each iteration */
                }
                $image->writeImage($path);
        }

        public function flush(){
                if(!$this->instant){
                        fseek($this->fb, 0);
                        fwrite($this->fb, implode(array_map("implode", $this->memory)));
                }
        }
}
