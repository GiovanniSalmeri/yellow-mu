<?php
// Mu extension, https://github.com/GiovanniSalmeri/yellow-mu

class YellowMu {
    const VERSION = "0.9.2";
    const ESCAPECLASS = "__mu__";
    public $yellow;         // access to API

    // Handle initialisation
    public function onLoad($yellow) {
        $this->yellow = $yellow;
        $this->yellow->system->setDefault("muPreferKatex", "0");
        $this->yellow->system->setDefault("muNaturalMath", "0");
    }

    // Handle page meta data
    public function onParseMetaData($page) {
        if ($page==$this->yellow->page) {
            $metaData = substr($page->rawData, 0, $page->metaDataOffsetBytes);
            $parserData = substr($page->rawData, $page->metaDataOffsetBytes);
            $parserData = preg_replace_callback("/\[mu((?:\s+(?:[^\]\"\s]+|\"(?:[^\"]|\"\"|\\\\\")*\"))*\s*)\]/", function($matches) {
                return "[mu".strtr($matches[1], [ "%"=>"%%", "]"=>"%|" ])."]";
            }, $parserData);
            $page->rawData = $metaData.$parserData;
        }
    }

    // Handle page content element
    public function onParseContentElement($page, $name, $text, $attributes, $type) {
        if (!isset($this->parser)) {
            $page->useKatex = $this->yellow->system->get("muPreferKatex") && $this->yellow->extension->isExisting("math");
            if ($page->useKatex) {
                $this->parser = new AsciiMathHtmlTex($this->yellow->language->getText("coreDecimalSeparator"));
            } else {
                $this->parser = new AsciiMathMlEscaped($this->yellow->language->getText("coreDecimalSeparator"));
            }
        }
        $output = null;
        if ($name=="mu" && ($type=="block" || $type=="inline" || $type=="code")) {
            if ($type=="code") {
                $expression = $text;
                $label = preg_match('/(?:^|\s)#(\S+)/', $attributes, $matches) ? $matches[1] : "";
            } else {
                list($expression, $label) = $this->yellow->toolbox->getTextArguments($text);
                $expression = strtr($expression , [ "%%"=>"%", "%|"=>"]" ]);
                $label = substr($label, 0, 1)=="#" ? substr($label, 1) : "";
            }
            if ($this->yellow->system->get("muNaturalMath")) {
                $expression = $this->naturalMath($expression, $type);
            }
            if ($type=="inline") {
                $output = "<span class=\"mu\">".$this->parser->parseMath($expression, $type!=="inline")."</span>";
                if ($label!=="") {
                    $page->muLabels = true;
                    $output = "<span class=\"mu-display\" id=\"".htmlspecialchars($label)."\"><span class=\"mu-label\">[##$label]</span> ".$output."</span>";
                }
            } else {
                $output = "<div class=\"mu\">\n".$this->parser->parseMath($expression, $type!=="inline")."\n</div>\n";
                if ($label!=="") {
                    $page->muLabels = true;
                    $output = "<div class=\"mu-display\" id=\"".htmlspecialchars($label)."\">\n<span class=\"mu-label\">[##$label]</span>\n".$output."\n</div>\n";
                }
            }
        }
        return $output;
    }

    // Handle page content in HTML format
    public function onParseContentHtml($page, $text) {
        $output = $text;
        if (empty($page->useKatex)) {
            $output = preg_replace_callback('/<span class="'.self::ESCAPECLASS.'">(.*?)<\/span>/s', function ($matches) { return htmlspecialchars_decode($matches[1]); }, $output);
        }
        if (!empty($page->muLabels)) {
            $ids = [];
            $output = preg_replace_callback('/\[##(\S+?)\]/', function ($m) use (&$ids) {
                static $currentId = 0;
                if (!isset($ids[$m[1]])) $ids[$m[1]] = ++$currentId;
                return $ids[$m[1]];
            }, $output);
            $output = preg_replace_callback('/\[#(\S+?)\]/', function ($m) use ($ids) {
                if (isset($ids[$m[1]])) {
                    return "<a class=\"mu-label\" href=\"#".htmlspecialchars($m[1])."\">{$ids[$m[1]]}</a>";
                } else {
                    return $m[0];
                }
            }, $output);
        }
        return $output!==$text ? $output : null;
    }

    // Handle page extra data
    public function onParsePageExtra($page, $name) {
        $output = null;
        if ($name=="header") {
            $assetLocation = $this->yellow->system->get("coreServerBase").$this->yellow->system->get("coreAssetLocation");
            $output = "<link rel=\"stylesheet\" type=\"text/css\" media=\"all\" href=\"{$assetLocation}mu.css\" />\n";
        }
        return $output;
    }

    // Force slash form in inline fractions
    private function naturalMath($expression, $type) {
        $strings = [];
        $expression = preg_replace_callback("/\".*?\"|\\\\?text\(.*?\)/", function ($matches) use (&$strings) {
            $strings[] = $matches[0];
            return "\"\"";
        }, $expression);
        $expression = str_replace([ "O/", "/_\\", "/_" ], [ "\emptyset", "\triangle", "\angle" ], $expression);
        $expression = preg_replace_callback("/\/\/?/", function ($matches) use ($type) { 
            return ($type!=="inline" && $matches[0]=="/") ? "/" : "//"; 
        }, $expression);
        $expression = preg_replace_callback("/\"\"/", function ($matches) use (&$strings) {
            static $index = 0;
            return $strings[$index++];
        }, $expression);
        return $expression;
    }
}

class AsciiMathMl {

/*
This class is a PHP port of ASCIIMathML.js 2.2 Mar 3, 2014.
https://github.com/asciimath/asciimathml

This is the copyright notice of the original ASCIIMathML.js:

Copyright (c) 2014 Peter Jipsen and other ASCIIMathML.js contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

    const CONST = 0, UNARY = 1, BINARY = 2, INFIX = 3, LEFTBRACKET = 4,
        RIGHTBRACKET = 5, SPACE = 6, UNDEROVER = 7, DEFINITION = 8,
        LEFTRIGHT = 9, TEXT = 10, BIG = 11, LONG = 12, STRETCHY = 13,
        MATRIX = 14, UNARYUNDEROVER = 15; // token types

    const CAL = [ "\u{1d49c}", "\u{212c}", "\u{1d49e}", "\u{1d49f}", "\u{2130}", "\u{2131}", "\u{1d4a2}", "\u{210b}", "\u{2110}", "\u{1d4a5}", "\u{1d4a6}", "\u{2112}", "\u{2133}", "\u{1d4a9}", "\u{1d4aa}", "\u{1d4ab}", "\u{1d4ac}", "\u{211b}", "\u{1d4ae}", "\u{1d4af}", "\u{1d4b0}", "\u{1d4b1}", "\u{1d4b2}", "\u{1d4b3}", "\u{1d4b4}", "\u{1d4b5}", "\u{1d4b6}", "\u{1d4b7}", "\u{1d4b8}", "\u{1d4b9}", "\u{212f}", "\u{1d4bb}", "\u{210a}", "\u{1d4bd}", "\u{1d4be}", "\u{1d4bf}", "\u{1d4c0}", "\u{1d4c1}", "\u{1d4c2}", "\u{1d4c3}", "\u{2134}", "\u{1d4c5}", "\u{1d4c6}", "\u{1d4c7}", "\u{1d4c8}", "\u{1d4c9}", "\u{1d4ca}", "\u{1d4cb}", "\u{1d4cc}", "\u{1d4cd}", "\u{1d4ce}", "\u{1d4cf}", ];
    const FRK = [ "\u{1d504}", "\u{1d505}", "\u{212d}", "\u{1d507}", "\u{1d508}", "\u{1d509}", "\u{1d50a}", "\u{210c}", "\u{2111}", "\u{1d50d}", "\u{1d50e}", "\u{1d50f}", "\u{1d510}", "\u{1d511}", "\u{1d512}", "\u{1d513}", "\u{1d514}", "\u{211c}", "\u{1d516}", "\u{1d517}", "\u{1d518}", "\u{1d519}", "\u{1d51a}", "\u{1d51b}", "\u{1d51c}", "\u{2128}", "\u{1d51e}", "\u{1d51f}", "\u{1d520}", "\u{1d521}", "\u{1d522}", "\u{1d523}", "\u{1d524}", "\u{1d525}", "\u{1d526}", "\u{1d527}", "\u{1d528}", "\u{1d529}", "\u{1d52a}", "\u{1d52b}", "\u{1d52c}", "\u{1d52d}", "\u{1d52e}", "\u{1d52f}", "\u{1d530}", "\u{1d531}", "\u{1d532}", "\u{1d533}", "\u{1d534}", "\u{1d535}", "\u{1d536}", "\u{1d537}", ];
    const BBB = [ "\u{1d538}", "\u{1d539}", "\u{2102}", "\u{1d53b}", "\u{1d53c}", "\u{1d53d}", "\u{1d53e}", "\u{210d}", "\u{1d540}", "\u{1d541}", "\u{1d542}", "\u{1d543}", "\u{1d544}", "\u{2115}", "\u{1d546}", "\u{2119}", "\u{211a}", "\u{211d}", "\u{1d54a}", "\u{1d54b}", "\u{1d54c}", "\u{1d54d}", "\u{1d54e}", "\u{1d54f}", "\u{1d550}", "\u{2124}", "\u{1d552}", "\u{1d553}", "\u{1d554}", "\u{1d555}", "\u{1d556}", "\u{1d557}", "\u{1d558}", "\u{1d559}", "\u{1d55a}", "\u{1d55b}", "\u{1d55c}", "\u{1d55d}", "\u{1d55e}", "\u{1d55f}", "\u{1d560}", "\u{1d561}", "\u{1d562}", "\u{1d563}", "\u{1d564}", "\u{1d565}", "\u{1d566}", "\u{1d567}", "\u{1d568}", "\u{1d569}", "\u{1d56a}", "\u{1d56b}", ];

    private $decimal; // only for output
    private $isAnnotated;

    private $dom;
    private $symbols, $names;
    private $nestingDepth, $previousSymbol, $currentSymbol;

    public function __construct($decimal = ".", $isAnnotated = true) {
        $this->decimal = $decimal;
        $this->isAnnotated = $isAnnotated;
        $this->symbols = [
            // some greek symbols
            [ "input"=>"alpha",  "tag"=>"mi", "output"=>"\u{03B1}", "ttype"=>self::CONST ],
            [ "input"=>"beta",   "tag"=>"mi", "output"=>"\u{03B2}", "ttype"=>self::CONST ],
            [ "input"=>"chi",    "tag"=>"mi", "output"=>"\u{03C7}", "ttype"=>self::CONST ],
            [ "input"=>"delta",  "tag"=>"mi", "output"=>"\u{03B4}", "ttype"=>self::CONST ],
            [ "input"=>"Delta",  "tag"=>"mo", "output"=>"\u{0394}", "ttype"=>self::CONST ],
            [ "input"=>"epsi",   "tag"=>"mi", "output"=>"\u{03B5}", "tex"=>"epsilon", "ttype"=>self::CONST ],
            [ "input"=>"varepsilon", "tag"=>"mi", "output"=>"\u{025B}", "ttype"=>self::CONST ],
            [ "input"=>"eta",    "tag"=>"mi", "output"=>"\u{03B7}", "ttype"=>self::CONST ],
            [ "input"=>"gamma",  "tag"=>"mi", "output"=>"\u{03B3}", "ttype"=>self::CONST ],
            [ "input"=>"Gamma",  "tag"=>"mo", "output"=>"\u{0393}", "ttype"=>self::CONST ],
            [ "input"=>"iota",   "tag"=>"mi", "output"=>"\u{03B9}", "ttype"=>self::CONST ],
            [ "input"=>"kappa",  "tag"=>"mi", "output"=>"\u{03BA}", "ttype"=>self::CONST ],
            [ "input"=>"lambda", "tag"=>"mi", "output"=>"\u{03BB}", "ttype"=>self::CONST ],
            [ "input"=>"Lambda", "tag"=>"mo", "output"=>"\u{039B}", "ttype"=>self::CONST ],
            [ "input"=>"lamda", "tag"=>"mi", "output"=>"\u{03BB}", "ttype"=>self::CONST ],
            [ "input"=>"Lamda", "tag"=>"mo", "output"=>"\u{039B}", "ttype"=>self::CONST ],
            [ "input"=>"mu",     "tag"=>"mi", "output"=>"\u{03BC}", "ttype"=>self::CONST ],
            [ "input"=>"nu",     "tag"=>"mi", "output"=>"\u{03BD}", "ttype"=>self::CONST ],
            [ "input"=>"omega",  "tag"=>"mi", "output"=>"\u{03C9}", "ttype"=>self::CONST ],
            [ "input"=>"Omega",  "tag"=>"mo", "output"=>"\u{03A9}", "ttype"=>self::CONST ],
            [ "input"=>"phi",    "tag"=>"mi", "output"=>"\u{03D5}", "ttype"=>self::CONST ],
            [ "input"=>"varphi", "tag"=>"mi", "output"=>"\u{03C6}", "ttype"=>self::CONST ],
            [ "input"=>"Phi",    "tag"=>"mo", "output"=>"\u{03A6}", "ttype"=>self::CONST ],
            [ "input"=>"pi",     "tag"=>"mi", "output"=>"\u{03C0}", "ttype"=>self::CONST ],
            [ "input"=>"Pi",     "tag"=>"mo", "output"=>"\u{03A0}", "ttype"=>self::CONST ],
            [ "input"=>"psi",    "tag"=>"mi", "output"=>"\u{03C8}", "ttype"=>self::CONST ],
            [ "input"=>"Psi",    "tag"=>"mi", "output"=>"\u{03A8}", "ttype"=>self::CONST ],
            [ "input"=>"rho",    "tag"=>"mi", "output"=>"\u{03C1}", "ttype"=>self::CONST ],
            [ "input"=>"sigma",  "tag"=>"mi", "output"=>"\u{03C3}", "ttype"=>self::CONST ],
            [ "input"=>"Sigma",  "tag"=>"mo", "output"=>"\u{03A3}", "ttype"=>self::CONST ],
            [ "input"=>"tau",    "tag"=>"mi", "output"=>"\u{03C4}", "ttype"=>self::CONST ],
            [ "input"=>"theta",  "tag"=>"mi", "output"=>"\u{03B8}", "ttype"=>self::CONST ],
            [ "input"=>"vartheta", "tag"=>"mi", "output"=>"\u{03D1}", "ttype"=>self::CONST ],
            [ "input"=>"Theta",  "tag"=>"mo", "output"=>"\u{0398}", "ttype"=>self::CONST ],
            [ "input"=>"upsilon", "tag"=>"mi", "output"=>"\u{03C5}", "ttype"=>self::CONST ],
            [ "input"=>"xi",     "tag"=>"mi", "output"=>"\u{03BE}", "ttype"=>self::CONST ],
            [ "input"=>"Xi",     "tag"=>"mo", "output"=>"\u{039E}", "ttype"=>self::CONST ],
            [ "input"=>"zeta",   "tag"=>"mi", "output"=>"\u{03B6}", "ttype"=>self::CONST ],
            // binary operation symbols
            // [ "input"=>"-",  "tag"=>"mo", "output"=>"\u{0096}", "ttype"=>self::CONST ],
            [ "input"=>"*",  "tag"=>"mo", "output"=>"\u{22C5}", "tex"=>"cdot", "ttype"=>self::CONST ],
            [ "input"=>"**", "tag"=>"mo", "output"=>"\u{2217}", "tex"=>"ast", "ttype"=>self::CONST ],
            [ "input"=>"***", "tag"=>"mo", "output"=>"\u{22C6}", "tex"=>"star", "ttype"=>self::CONST ],
            [ "input"=>"//", "tag"=>"mo", "output"=>"/",      "ttype"=>self::CONST ],
            [ "input"=>"\\\\", "tag"=>"mo", "output"=>"\\",   "tex"=>"backslash", "ttype"=>self::CONST ],
            [ "input"=>"setminus", "tag"=>"mo", "output"=>"\\", "ttype"=>self::CONST ],
            [ "input"=>"xx", "tag"=>"mo", "output"=>"\u{00D7}", "tex"=>"times", "ttype"=>self::CONST ],
            [ "input"=>"|><", "tag"=>"mo", "output"=>"\u{22C9}", "tex"=>"ltimes", "ttype"=>self::CONST ],
            [ "input"=>"><|", "tag"=>"mo", "output"=>"\u{22CA}", "tex"=>"rtimes", "ttype"=>self::CONST ],
            [ "input"=>"|><|", "tag"=>"mo", "output"=>"\u{22C8}", "tex"=>"bowtie", "ttype"=>self::CONST ],
            [ "input"=>"-:", "tag"=>"mo", "output"=>"\u{00F7}", "tex"=>"div", "ttype"=>self::CONST ],
            [ "input"=>"divide",   "tag"=>"mo", "output"=>"-:", "ttype"=>self::DEFINITION ],
            [ "input"=>"@",  "tag"=>"mo", "output"=>"\u{2218}", "tex"=>"circ", "ttype"=>self::CONST ],
            [ "input"=>"o+", "tag"=>"mo", "output"=>"\u{2295}", "tex"=>"oplus", "ttype"=>self::CONST ],
            [ "input"=>"ox", "tag"=>"mo", "output"=>"\u{2297}", "tex"=>"otimes", "ttype"=>self::CONST ],
            [ "input"=>"o.", "tag"=>"mo", "output"=>"\u{2299}", "tex"=>"odot", "ttype"=>self::CONST ],
            [ "input"=>"sum", "tag"=>"mo", "output"=>"\u{2211}", "ttype"=>self::UNDEROVER ],
            [ "input"=>"prod", "tag"=>"mo", "output"=>"\u{220F}", "ttype"=>self::UNDEROVER ],
            [ "input"=>"^^",  "tag"=>"mo", "output"=>"\u{2227}", "tex"=>"wedge", "ttype"=>self::CONST ],
            [ "input"=>"^^^", "tag"=>"mo", "output"=>"\u{22C0}", "tex"=>"bigwedge", "ttype"=>self::UNDEROVER ],
            [ "input"=>"vv",  "tag"=>"mo", "output"=>"\u{2228}", "tex"=>"vee", "ttype"=>self::CONST ],
            [ "input"=>"vvv", "tag"=>"mo", "output"=>"\u{22C1}", "tex"=>"bigvee", "ttype"=>self::UNDEROVER ],
            [ "input"=>"nn",  "tag"=>"mo", "output"=>"\u{2229}", "tex"=>"cap", "ttype"=>self::CONST ],
            [ "input"=>"nnn", "tag"=>"mo", "output"=>"\u{22C2}", "tex"=>"bigcap", "ttype"=>self::UNDEROVER ],
            [ "input"=>"uu",  "tag"=>"mo", "output"=>"\u{222A}", "tex"=>"cup", "ttype"=>self::CONST ],
            [ "input"=>"uuu", "tag"=>"mo", "output"=>"\u{22C3}", "tex"=>"bigcup", "ttype"=>self::UNDEROVER ],
            // binary relation symbols
            [ "input"=>"!=",  "tag"=>"mo", "output"=>"\u{2260}", "tex"=>"ne", "ttype"=>self::CONST ],
            [ "input"=>":=",  "tag"=>"mo", "output"=>"\u{2254}", "ttype"=>self::CONST ], // changed in UTF-8, GS
            [ "input"=>"lt",  "tag"=>"mo", "output"=>"<",      "ttype"=>self::CONST ],
            [ "input"=>"<=",  "tag"=>"mo", "output"=>"\u{2264}", "tex"=>"le", "ttype"=>self::CONST ],
            [ "input"=>"lt=", "tag"=>"mo", "output"=>"\u{2264}", "tex"=>"leq", "ttype"=>self::CONST ],
            [ "input"=>"gt",  "tag"=>"mo", "output"=>">",      "ttype"=>self::CONST ],
            [ "input"=>"mlt", "tag"=>"mo", "output"=>"\u{226A}", "tex"=>"ll", "ttype"=>self::CONST ],
            [ "input"=>">=",  "tag"=>"mo", "output"=>"\u{2265}", "tex"=>"ge", "ttype"=>self::CONST ],
            [ "input"=>"gt=", "tag"=>"mo", "output"=>"\u{2265}", "tex"=>"geq", "ttype"=>self::CONST ],
            [ "input"=>"mgt", "tag"=>"mo", "output"=>"\u{226B}", "tex"=>"gg", "ttype"=>self::CONST ],
            [ "input"=>"-<",  "tag"=>"mo", "output"=>"\u{227A}", "tex"=>"prec", "ttype"=>self::CONST ],
            [ "input"=>"-lt", "tag"=>"mo", "output"=>"\u{227A}", "ttype"=>self::CONST ],
            [ "input"=>">-",  "tag"=>"mo", "output"=>"\u{227B}", "tex"=>"succ", "ttype"=>self::CONST ],
            [ "input"=>"-<=", "tag"=>"mo", "output"=>"\u{2AAF}", "tex"=>"preceq", "ttype"=>self::CONST ],
            [ "input"=>">-=", "tag"=>"mo", "output"=>"\u{2AB0}", "tex"=>"succeq", "ttype"=>self::CONST ],
            [ "input"=>"in",  "tag"=>"mo", "output"=>"\u{2208}", "ttype"=>self::CONST ],
            [ "input"=>"!in", "tag"=>"mo", "output"=>"\u{2209}", "tex"=>"notin", "ttype"=>self::CONST ],
            [ "input"=>"sub", "tag"=>"mo", "output"=>"\u{2282}", "tex"=>"subset", "ttype"=>self::CONST ],
            [ "input"=>"sup", "tag"=>"mo", "output"=>"\u{2283}", "tex"=>"supset", "ttype"=>self::CONST ],
            [ "input"=>"sube", "tag"=>"mo", "output"=>"\u{2286}", "tex"=>"subseteq", "ttype"=>self::CONST ],
            [ "input"=>"supe", "tag"=>"mo", "output"=>"\u{2287}", "tex"=>"supseteq", "ttype"=>self::CONST ],
            [ "input"=>"!sub", "tag"=>"mo", "output"=>"\u{2284}", "tex"=>"notsubset", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"!sup", "tag"=>"mo", "output"=>"\u{2285}", "tex"=>"notsupset", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"!sube", "tag"=>"mo", "output"=>"\u{2288}", "tex"=>"notsubseteq", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"!supe", "tag"=>"mo", "output"=>"\u{2289}", "tex"=>"notsupseteq", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"-=",  "tag"=>"mo", "output"=>"\u{2261}", "tex"=>"equiv", "ttype"=>self::CONST ],
            [ "input"=>"!-=",  "tag"=>"mo", "output"=>"\u{2262}", "tex"=>"notequiv", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"~=",  "tag"=>"mo", "output"=>"\u{2245}", "tex"=>"cong", "ttype"=>self::CONST ],
            [ "input"=>"~~",  "tag"=>"mo", "output"=>"\u{2248}", "tex"=>"approx", "ttype"=>self::CONST ],
            [ "input"=>"~",  "tag"=>"mo", "output"=>"\u{223C}", "tex"=>"sim", "ttype"=>self::CONST ],
            [ "input"=>"prop", "tag"=>"mo", "output"=>"\u{221D}", "tex"=>"propto", "ttype"=>self::CONST ],
            // logical symbols
            [ "input"=>"and", "tag"=>"mtext", "output"=>"and", "ttype"=>self::SPACE ],
            [ "input"=>"or",  "tag"=>"mtext", "output"=>"or",  "ttype"=>self::SPACE ],
            [ "input"=>"not", "tag"=>"mo", "output"=>"\u{00AC}", "tex"=>"neg", "ttype"=>self::CONST ],
            [ "input"=>"=>",  "tag"=>"mo", "output"=>"\u{21D2}", "tex"=>"implies", "ttype"=>self::CONST ],
            [ "input"=>"if",  "tag"=>"mo", "output"=>"if",     "ttype"=>self::SPACE ],
            [ "input"=>"<=>", "tag"=>"mo", "output"=>"\u{21D4}", "tex"=>"iff", "ttype"=>self::CONST ],
            [ "input"=>"AA",  "tag"=>"mo", "output"=>"\u{2200}", "tex"=>"forall", "ttype"=>self::CONST ],
            [ "input"=>"EE",  "tag"=>"mo", "output"=>"\u{2203}", "tex"=>"exists", "ttype"=>self::CONST ],
            [ "input"=>"_|_", "tag"=>"mo", "output"=>"\u{22A5}", "tex"=>"bot", "ttype"=>self::CONST ],
            [ "input"=>"TT",  "tag"=>"mo", "output"=>"\u{22A4}", "tex"=>"top", "ttype"=>self::CONST ],
            [ "input"=>"|--",  "tag"=>"mo", "output"=>"\u{22A2}", "tex"=>"vdash", "ttype"=>self::CONST ],
            [ "input"=>"|==",  "tag"=>"mo", "output"=>"\u{22A8}", "tex"=>"models", "ttype"=>self::CONST ],
            // grouping brackets
            [ "input"=>"(", "tag"=>"mo", "output"=>"(", "tex"=>"left(", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>")", "tag"=>"mo", "output"=>")", "tex"=>"right)", "ttype"=>self::RIGHTBRACKET ],
            [ "input"=>"[", "tag"=>"mo", "output"=>"[", "tex"=>"left[", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>"]", "tag"=>"mo", "output"=>"]", "tex"=>"right]", "ttype"=>self::RIGHTBRACKET ],
            [ "input"=>"{", "tag"=>"mo", "output"=>"{", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>"}", "tag"=>"mo", "output"=>"}", "ttype"=>self::RIGHTBRACKET ],
            [ "input"=>"|", "tag"=>"mo", "output"=>"|", "ttype"=>self::LEFTRIGHT ],
            [ "input"=>":|:", "tag"=>"mo", "output"=>"|", "ttype"=>self::CONST ],
            [ "input"=>"|:", "tag"=>"mo", "output"=>"|", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>":|", "tag"=>"mo", "output"=>"|", "ttype"=>self::RIGHTBRACKET ],
            // [ "input"=>"||", "tag"=>"mo", "output"=>"||", "ttype"=>self::LEFTRIGHT ],
            [ "input"=>"(:", "tag"=>"mo", "output"=>"\u{2329}", "tex"=>"langle", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>":)", "tag"=>"mo", "output"=>"\u{232A}", "tex"=>"rangle", "ttype"=>self::RIGHTBRACKET ],
            [ "input"=>"<<", "tag"=>"mo", "output"=>"\u{2329}", "ttype"=>self::LEFTBRACKET ],
            [ "input"=>">>", "tag"=>"mo", "output"=>"\u{232A}", "ttype"=>self::RIGHTBRACKET ],
            [ "input"=>"{:", "tag"=>"mo", "output"=>"{:", "ttype"=>self::LEFTBRACKET, "invisible"=>true ],
            [ "input"=>":}", "tag"=>"mo", "output"=>":}", "ttype"=>self::RIGHTBRACKET, "invisible"=>true ],
            // miscellaneous symbols
            [ "input"=>"int",  "tag"=>"mo", "output"=>"\u{222B}", "ttype"=>self::CONST ],
            [ "input"=>"dx",   "tag"=>"mi", "output"=>"{:d x:}", "ttype"=>self::DEFINITION ],
            [ "input"=>"dy",   "tag"=>"mi", "output"=>"{:d y:}", "ttype"=>self::DEFINITION ],
            [ "input"=>"dz",   "tag"=>"mi", "output"=>"{:d z:}", "ttype"=>self::DEFINITION ],
            [ "input"=>"dt",   "tag"=>"mi", "output"=>"{:d t:}", "ttype"=>self::DEFINITION ],
            [ "input"=>"oint", "tag"=>"mo", "output"=>"\u{222E}", "ttype"=>self::CONST ],
            [ "input"=>"del",  "tag"=>"mo", "output"=>"\u{2202}", "tex"=>"partial", "ttype"=>self::CONST ],
            [ "input"=>"grad", "tag"=>"mo", "output"=>"\u{2207}", "tex"=>"nabla", "ttype"=>self::CONST ],
            [ "input"=>"+-",   "tag"=>"mo", "output"=>"\u{00B1}", "tex"=>"pm", "ttype"=>self::CONST ],
            [ "input"=>"-+",   "tag"=>"mo", "output"=>"\u{2213}", "tex"=>"mp", "ttype"=>self::CONST ],
            [ "input"=>"O/",   "tag"=>"mi", "output"=>"\u{2205}", "tex"=>"emptyset", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"oo",   "tag"=>"mi", "output"=>"\u{221E}", "tex"=>"infty", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"aleph", "tag"=>"mi", "output"=>"\u{2135}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"...",  "tag"=>"mo", "output"=>"\u{2026}",    "tex"=>"ldots", "ttype"=>self::CONST ], // changed in UTF-8, GS
            [ "input"=>":.",  "tag"=>"mo", "output"=>"\u{2234}",  "tex"=>"therefore", "ttype"=>self::CONST ],
            [ "input"=>":'",  "tag"=>"mo", "output"=>"\u{2235}",  "tex"=>"because", "ttype"=>self::CONST ],
            [ "input"=>"/_",  "tag"=>"mo", "output"=>"\u{2220}",  "tex"=>"angle", "ttype"=>self::CONST ],
            [ "input"=>"/_\\",  "tag"=>"mo", "output"=>"\u{25B3}",  "tex"=>"triangle", "ttype"=>self::CONST ],
            [ "input"=>"'",   "tag"=>"mo", "output"=>"\u{2032}",  "tex"=>"prime", "ttype"=>self::CONST ],
            [ "input"=>"''",   "tag"=>"mo", "output"=>"\u{2033}",  "tex"=>"dprime", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"'''",   "tag"=>"mo", "output"=>"\u{2033}",  "tex"=>"trprime", "ttype"=>self::CONST ], // added, GS
            [ "input"=>"tilde", "tag"=>"mover", "output"=>"~", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"\\ ",  "tag"=>"mo", "output"=>"\u{00A0}", "ttype"=>self::CONST ],
            [ "input"=>"frown",  "tag"=>"mo", "output"=>"\u{2322}", "ttype"=>self::CONST ],
            [ "input"=>"quad", "tag"=>"mo", "output"=>"\u{00A0}\u{00A0}", "ttype"=>self::CONST ],
            [ "input"=>"qquad", "tag"=>"mo", "output"=>"\u{00A0}\u{00A0}\u{00A0}\u{00A0}", "ttype"=>self::CONST ],
            [ "input"=>"cdots", "tag"=>"mo", "output"=>"\u{22EF}", "ttype"=>self::CONST ],
            [ "input"=>"vdots", "tag"=>"mo", "output"=>"\u{22EE}", "ttype"=>self::CONST ],
            [ "input"=>"ddots", "tag"=>"mo", "output"=>"\u{22F1}", "ttype"=>self::CONST ],
            [ "input"=>"diamond", "tag"=>"mo", "output"=>"\u{22C4}", "ttype"=>self::CONST ],
            [ "input"=>"square", "tag"=>"mo", "output"=>"\u{25A1}", "ttype"=>self::CONST ],
            [ "input"=>"|__", "tag"=>"mo", "output"=>"\u{230A}",  "tex"=>"lfloor", "ttype"=>self::CONST ],
            [ "input"=>"__|", "tag"=>"mo", "output"=>"\u{230B}",  "tex"=>"rfloor", "ttype"=>self::CONST ],
            [ "input"=>"|~", "tag"=>"mo", "output"=>"\u{2308}",  "tex"=>"lceiling", "ttype"=>self::CONST ],
            [ "input"=>"~|", "tag"=>"mo", "output"=>"\u{2309}",  "tex"=>"rceiling", "ttype"=>self::CONST ],
            [ "input"=>"CC",  "tag"=>"mi", "output"=>"\u{2102}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"NN",  "tag"=>"mi", "output"=>"\u{2115}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"QQ",  "tag"=>"mi", "output"=>"\u{211A}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"RR",  "tag"=>"mi", "output"=>"\u{211D}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"ZZ",  "tag"=>"mi", "output"=>"\u{2124}", "ttype"=>self::CONST ], // changed in mi, GS
            [ "input"=>"f",   "tag"=>"mi", "output"=>"f",      "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"g",   "tag"=>"mi", "output"=>"g",      "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"h",   "tag"=>"mi", "output"=>"h",      "ttype"=>self::UNARY, "func"=>true ], // added, GS
            [ "input"=>"P",   "tag"=>"mi", "output"=>"P",      "ttype"=>self::UNARY, "func"=>true ], // added, GS
            [ "input"=>"hbar", "tag"=>"mo", "output"=>"\u{210F}", "ttype"=>self::CONST ], // added, GS
            // standard functions
            [ "input"=>"lim",  "tag"=>"mo", "output"=>"lim", "ttype"=>self::UNDEROVER ],
            [ "input"=>"Lim",  "tag"=>"mo", "output"=>"Lim", "ttype"=>self::UNDEROVER ],
            [ "input"=>"sin",  "tag"=>"mo", "output"=>"sin", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"cos",  "tag"=>"mo", "output"=>"cos", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"tan",  "tag"=>"mo", "output"=>"tan", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"sinh", "tag"=>"mo", "output"=>"sinh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"cosh", "tag"=>"mo", "output"=>"cosh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"tanh", "tag"=>"mo", "output"=>"tanh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"cot",  "tag"=>"mo", "output"=>"cot", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"sec",  "tag"=>"mo", "output"=>"sec", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"csc",  "tag"=>"mo", "output"=>"csc", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"arcsin",  "tag"=>"mo", "output"=>"arcsin", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"arccos",  "tag"=>"mo", "output"=>"arccos", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"arctan",  "tag"=>"mo", "output"=>"arctan", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"coth",  "tag"=>"mo", "output"=>"coth", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"sech",  "tag"=>"mo", "output"=>"sech", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"csch",  "tag"=>"mo", "output"=>"csch", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"exp",  "tag"=>"mo", "output"=>"exp", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"abs",   "tag"=>"mo", "output"=>"abs",  "ttype"=>self::UNARY, "rewriteleftright"=>["|", "|"] ],
            [ "input"=>"norm",   "tag"=>"mo", "output"=>"norm",  "ttype"=>self::UNARY, "rewriteleftright"=>["\u{2225}", "\u{2225}"] ],
            [ "input"=>"floor",   "tag"=>"mo", "output"=>"floor",  "ttype"=>self::UNARY, "rewriteleftright"=>["\u{230A}", "\u{230B}"] ],
            [ "input"=>"ceil",   "tag"=>"mo", "output"=>"ceil",  "ttype"=>self::UNARY, "rewriteleftright"=>["\u{2308}", "\u{2309}"] ],
            [ "input"=>"log",  "tag"=>"mo", "output"=>"log", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"ln",   "tag"=>"mo", "output"=>"ln",  "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"det",  "tag"=>"mo", "output"=>"det", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"dim",  "tag"=>"mo", "output"=>"dim", "ttype"=>self::CONST ],
            [ "input"=>"mod",  "tag"=>"mo", "output"=>"mod", "ttype"=>self::CONST ],
            [ "input"=>"gcd",  "tag"=>"mo", "output"=>"gcd", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"lcm",  "tag"=>"mo", "output"=>"lcm", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"lub",  "tag"=>"mo", "output"=>"lub", "ttype"=>self::CONST ],
            [ "input"=>"glb",  "tag"=>"mo", "output"=>"glb", "ttype"=>self::CONST ],
            [ "input"=>"min",  "tag"=>"mo", "output"=>"min", "ttype"=>self::UNDEROVER ],
            [ "input"=>"max",  "tag"=>"mo", "output"=>"max", "ttype"=>self::UNDEROVER ],
            [ "input"=>"Sin",  "tag"=>"mo", "output"=>"Sin", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Cos",  "tag"=>"mo", "output"=>"Cos", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Tan",  "tag"=>"mo", "output"=>"Tan", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Arcsin",  "tag"=>"mo", "output"=>"Arcsin", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Arccos",  "tag"=>"mo", "output"=>"Arccos", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Arctan",  "tag"=>"mo", "output"=>"Arctan", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Sinh", "tag"=>"mo", "output"=>"Sinh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Cosh", "tag"=>"mo", "output"=>"Cosh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Tanh", "tag"=>"mo", "output"=>"Tanh", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Cot",  "tag"=>"mo", "output"=>"Cot", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Sec",  "tag"=>"mo", "output"=>"Sec", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Csc",  "tag"=>"mo", "output"=>"Csc", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Log",  "tag"=>"mo", "output"=>"Log", "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Ln",   "tag"=>"mo", "output"=>"Ln",  "ttype"=>self::UNARY, "func"=>true ],
            [ "input"=>"Abs",   "tag"=>"mo", "output"=>"abs",  "ttype"=>self::UNARY, "notexcopy"=>true, "rewriteleftright"=>["|", "|"] ],
            // arrows
            [ "input"=>"uarr", "tag"=>"mo", "output"=>"\u{2191}", "tex"=>"uparrow", "ttype"=>self::CONST ],
            [ "input"=>"darr", "tag"=>"mo", "output"=>"\u{2193}", "tex"=>"downarrow", "ttype"=>self::CONST ],
            [ "input"=>"rarr", "tag"=>"mo", "output"=>"\u{2192}", "tex"=>"rightarrow", "ttype"=>self::CONST ],
            [ "input"=>"->",   "tag"=>"mo", "output"=>"\u{2192}", "tex"=>"to", "ttype"=>self::CONST ],
            [ "input"=>">->",   "tag"=>"mo", "output"=>"\u{21A3}", "tex"=>"rightarrowtail", "ttype"=>self::CONST ],
            [ "input"=>"->>",   "tag"=>"mo", "output"=>"\u{21A0}", "tex"=>"twoheadrightarrow", "ttype"=>self::CONST ],
            [ "input"=>">->>",   "tag"=>"mo", "output"=>"\u{2916}", "tex"=>"twoheadrightarrowtail", "ttype"=>self::CONST ],
            [ "input"=>"|->",  "tag"=>"mo", "output"=>"\u{21A6}", "tex"=>"mapsto", "ttype"=>self::CONST ],
            [ "input"=>"larr", "tag"=>"mo", "output"=>"\u{2190}", "tex"=>"leftarrow", "ttype"=>self::CONST ],
            [ "input"=>"harr", "tag"=>"mo", "output"=>"\u{2194}", "tex"=>"leftrightarrow", "ttype"=>self::CONST ],
            [ "input"=>"rArr", "tag"=>"mo", "output"=>"\u{21D2}", "tex"=>"Rightarrow", "ttype"=>self::CONST ],
            [ "input"=>"lArr", "tag"=>"mo", "output"=>"\u{21D0}", "tex"=>"Leftarrow", "ttype"=>self::CONST ],
            [ "input"=>"hArr", "tag"=>"mo", "output"=>"\u{21D4}", "tex"=>"Leftrightarrow", "ttype"=>self::CONST ],
            // commands with argument
            [ "input"=>"sqrt", "tag"=>"msqrt", "output"=>"sqrt", "ttype"=>self::UNARY ],
            [ "input"=>"root", "tag"=>"mroot", "output"=>"root", "ttype"=>self::BINARY ],
            [ "input"=>"frac", "tag"=>"mfrac", "output"=>"/",    "ttype"=>self::BINARY ],
            [ "input"=>"/",    "tag"=>"mfrac", "output"=>"/",    "ttype"=>self::INFIX ],
            [ "input"=>"stackrel", "tag"=>"mover", "output"=>"stackrel", "ttype"=>self::BINARY ],
            [ "input"=>"overset", "tag"=>"mover", "output"=>"stackrel", "ttype"=>self::BINARY ],
            [ "input"=>"underset", "tag"=>"munder", "output"=>"stackrel", "ttype"=>self::BINARY ],
            [ "input"=>"_",    "tag"=>"msub",  "output"=>"_",    "ttype"=>self::INFIX ],
            [ "input"=>"^",    "tag"=>"msup",  "output"=>"^",    "ttype"=>self::INFIX ],
            [ "input"=>"hat", "tag"=>"mover", "output"=>"\u{005E}", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"bar", "tag"=>"mover", "output"=>"\u{00AF}", "tex"=>"overline", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"vec", "tag"=>"mover", "output"=>"\u{2192}", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"dot", "tag"=>"mover", "output"=>".",      "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"ddot", "tag"=>"mover", "output"=>"..",    "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"overarc", "tag"=>"mover", "output"=>"\u{23DC}", "tex"=>"overparen", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"ul", "tag"=>"munder", "output"=>"\u{0332}", "tex"=>"underline", "ttype"=>self::UNARY, "acc"=>true ],
            [ "input"=>"ubrace", "tag"=>"munder", "output"=>"\u{23DF}", "tex"=>"underbrace", "ttype"=>self::UNARYUNDEROVER, "acc"=>true ],
            [ "input"=>"obrace", "tag"=>"mover", "output"=>"\u{23DE}", "tex"=>"overbrace", "ttype"=>self::UNARYUNDEROVER, "acc"=>true ],
            [ "input"=>"text", "tag"=>"mtext", "output"=>"text", "ttype"=>self::TEXT ],
            [ "input"=>"mbox", "tag"=>"mtext", "output"=>"mbox", "ttype"=>self::TEXT ],
            [ "input"=>"color", "tag"=>"mstyle", "output"=>"dummy", "ttype"=>self::BINARY ],
            [ "input"=>"id", "tag"=>"mrow", "output"=>"dummy", "ttype"=>self::BINARY ],
            [ "input"=>"class", "tag"=>"mrow", "output"=>"dummy", "ttype"=>self::BINARY ],
            [ "input"=>"cancel", "tag"=>"menclose", "output"=>"cancel", "ttype"=>self::UNARY ],

            [ "input"=>"\"", "tag"=>"mtext", "output"=>"mbox", "ttype"=>self::TEXT ],
            [ "input"=>"bb", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"bold", "output"=>"bb", "ttype"=>self::UNARY ],
            [ "input"=>"mathbf", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"bold", "output"=>"mathbf", "ttype"=>self::UNARY ],
            [ "input"=>"sf", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"sans-serif", "output"=>"sf", "ttype"=>self::UNARY ],
            [ "input"=>"mathsf", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"sans-serif", "output"=>"mathsf", "ttype"=>self::UNARY ],
            [ "input"=>"bbb", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"double-struck", "output"=>"bbb", "ttype"=>self::UNARY, "codes"=>self::BBB ],
            [ "input"=>"mathbb", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"double-struck", "output"=>"mathbb", "ttype"=>self::UNARY, "codes"=>self::BBB ],
            [ "input"=>"cc",  "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"script", "output"=>"cc", "ttype"=>self::UNARY, "codes"=>self::CAL ],
            [ "input"=>"mathcal", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"script", "output"=>"mathcal", "ttype"=>self::UNARY, "codes"=>self::CAL ],
            [ "input"=>"tt",  "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"monospace", "output"=>"tt", "ttype"=>self::UNARY ],
            [ "input"=>"mathtt", "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"monospace", "output"=>"mathtt", "ttype"=>self::UNARY ],
            [ "input"=>"fr",  "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"fraktur", "output"=>"fr", "ttype"=>self::UNARY, "codes"=>self::FRK ],
            [ "input"=>"mathfrak",  "tag"=>"mstyle", "atname"=>"mathvariant", "atval"=>"fraktur", "output"=>"mathfrak", "ttype"=>self::UNARY, "codes"=>self::FRK ],
        ];

        foreach ($this->symbols as $symbol) {
            if (isset($symbol["tex"])) {
                $this->symbols[] = [
                    "input"=>$symbol["tex"],
                    "tag"=>$symbol["tag"],
                    "output"=>$symbol["output"],
                    "ttype"=>$symbol["ttype"],
                    "acc"=>$symbol["acc"] ?? null,
                ];
            }
        }
        usort($this->symbols, function($s1, $s2) { return strcmp($s1["input"], $s2["input"]); });
        $this->names = array_column($this->symbols, "input");
        $this->dom = new DOMDocument("1.0", "utf-8");
    }

    private function createMmlNode($t, $frag = null) {
        $ns = "http://www.w3.org/1998/Math/MathML";
        $node = $t=="math" ? $this->dom->createElementNS($ns, $t) : $this->dom->createElement($t);
        if ($frag) @$node->appendChild($frag); // @ because $frag can be empty
        return $node;
    }

    private function removeCharsAndBlanks($str, $n) {
        // remove n characters and any following blanks
        if (strlen($str)>=$n+1 && $str[$n]=="\\" && $str[$n+1]!="\\" && $str[$n+1]!=" ") $st = substr($str, $n+1);
        else $st = substr($str, $n);
        for ($i=0; $i<strlen($st) && ord($st[$i])<=32; $i++);
        return substr($st, $i);
    }

    private function position($arr, $str, $n) {
        // return position >=n where str appears or would be inserted
        // assumes arr is sorted
        if ($n==0) {
            $n = -1;
            $h = count($arr);
            while ($n+1<$h) {
                $m = ($n+$h) >> 1;
                if (strcmp($arr[$m], $str)<0) $n = $m;
                else $h = $m;
            }
            return $h;
        } else {
            for ($i=$n; $i<count($arr) && strcmp($arr[$i], $str)<0; $i++);
        }
        return $i; // i=arr.length || arr[i]>=str
    }

    private function getSymbol($str) {
        // return maximal initial substring of str that appears in names
        // return null if there is none
        $k = 0; // new pos
        $j = 0; // old pos
        $match = "";
        $more = true;
        for ($i=1; $i<=strlen($str) && $more; $i++) {
            $st = substr($str, 0, $i); // initial substring of length i
            $j = $k;
            $k = $this->position($this->names, $st, $j);
            if ($k<count($this->names) && substr($str, 0, strlen($this->names[$k]))==$this->names[$k]) {
                $match = $this->names[$k];
                $mk = $k;
                $i = strlen($match);
            }
            $more = $k<count($this->names) && strcmp(substr($str, 0, strlen($this->names[$k])), $this->names[$k])>=0;
        }
        $this->previousSymbol = $this->currentSymbol;
        if ($match!="") {
            $this->currentSymbol = $this->symbols[$mk]["ttype"];
            return $this->symbols[$mk];
        }
        // if str[0] is a digit or - return maxsubstring of digits.digits
        $this->currentSymbol = self::CONST;
        if (preg_match('/^\d+(?:\.\d+)?(?:e[-+]?\d+)?/', $str, $matches)) { // rewritten, GS
            $st = str_replace([ ".", "-" ], [ $this->decimal, "\u{2212}" ], $matches[0]); // added, GS
            $tagst = "mn";
        } else {
            $st = substr($str, 0, 1); // take 1 character
            $tagst = !preg_match('/[A-Za-z]/', $st) ? "mo" : "mi";
        }
        if ($st=="-" && strlen($str)>1 && $str[1]!==' ' && $this->previousSymbol==self::INFIX) {
            $this->currentSymbol = self::INFIX; // trick "/" into recognizing "-" on second parse
            return [ "input"=>$st, "tag"=>$tagst, "output"=>$st, "ttype"=>self::UNARY, "func"=>true ];
        }
        return [ "input"=>$st, "tag"=>$tagst, "output"=>$st, "ttype"=>self::CONST ];
    }

    private function removeBrackets($node) {
        if (!$node->hasChildNodes()) return;
        if ($node->firstChild->hasChildNodes() && $node->nodeName=="mrow") {
            if ($node->firstChild->nextSibling && $node->firstChild->nextSibling->nodeName=="mtable") return;
            $st = $node->firstChild->firstChild->nodeValue;
            if ($st=="(" || $st=="[" || $st=="{") $node->removeChild($node->firstChild);
        }
        if ($node->lastChild->hasChildNodes() && ($node->nodeName=="mrow")) {
            $st = $node->lastChild->firstChild->nodeValue;
            if ($st==")" || $st=="]" || $st=="}") $node->removeChild($node->lastChild);
        }
    }

    /*Parsing ASCII math expressions with the following grammar
    v ::= [A-Za-z] | greek letters | numbers | other constant symbols
    u ::= sqrt | text | bb | other unary symbols for font commands
    b ::= frac | root | stackrel         binary symbols
    l ::= ( | [ | { | (: | {:            left brackets
    r ::= ) | ] | } | :) | :}            right brackets
    S ::= v | lEr | uS | bSS             Simple expression
    I ::= S_S | S^S | S_S^S | S          Intermediate expression
    E ::= IE | I/I                       Expression
    Each terminal symbol is translated into a corresponding mathml node.*/

    private function parseSexpr($str) { // parses $str and returns [node, tailstr]
        $newFrag = $this->dom->createDocumentFragment();
        $str = $this->removeCharsAndBlanks($str, 0);
        $symbol = $this->getSymbol($str); // either a token or a bracket or empty
        if ($symbol==null || $symbol["ttype"]==self::RIGHTBRACKET && $this->nestingDepth>0) {
            return [ null, $str ];
        }
        if ($symbol["ttype"]==self::DEFINITION) {
            $str = $symbol["output"].$this->removeCharsAndBlanks($str, strlen($symbol["input"]));
            $symbol = $this->getSymbol($str);
        }
        switch ($symbol["ttype"]) {
            case self::UNDEROVER:
            case self::CONST:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                return [ $this->createMmlNode($symbol["tag"], // it's a constant
                    $this->dom->createTextNode($symbol["output"])), $str ];
            case self::LEFTBRACKET: // read (expr+)
                $this->nestingDepth++;
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $result = $this->parseExpr($str, true);
                $this->nestingDepth--;
                if (isset($symbol["invisible"])) {
                    $node = $this->createMmlNode("mrow", $result[0]);
                } else {
                    $node = $this->createMmlNode("mo", $this->dom->createTextNode($symbol["output"]));
                    $node = $this->createMmlNode("mrow", $node);
                    $node->appendChild($result[0]);
                }
                return [ $node, $result[1] ];
            case self::TEXT:
                if ($symbol["input"]!="\"") $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                if (substr($str, 0, 1)=="{") $i=strpos($str, "}");
                elseif (substr($str, 0, 1)=="(") $i = strpos($str, ")");
                elseif (substr($str, 0, 1)=="[") $i = strpos($str, "]");
                elseif ($symbol["input"]=="\"") $i = strpos(substr($str, 1), "\"")+1;
                else $i = 0;
                if ($i==-1) $i = strlen($str);
                $st = substr($str, 1, $i-1);
                if (substr($st, 0, 1)==" ") {
                    $node = $this->createMmlNode("mspace");
                    $node->setAttribute("width", "1ex");
                    $newFrag->appendChild($node);
                }
                $newFrag->appendChild(
                $this->createMmlNode($symbol["tag"], $this->dom->createTextNode($st)));
                if (substr($st, -1)==" ") {
                    $node = $this->createMmlNode("mspace");
                    $node->setAttribute("width", "1ex");
                    $newFrag->appendChild($node);
                }
                $str = $this->removeCharsAndBlanks($str, $i+1);
                return [ $this->createMmlNode("mrow", $newFrag), $str ];
            case self::UNARYUNDEROVER:
            case self::UNARY:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $result = $this->parseSexpr($str);
                if ($result[0]==null) { 
                    if ($symbol["tag"]=="mi" || $symbol["tag"]=="mo") {
                        return [ $this->createMmlNode($symbol["tag"], $this->dom->createTextNode($symbol["output"])), $str ];
                    } else {
                        $result[0] = $this->createMmlNode("mi");
                    }
                }
                if (isset($symbol["func"])) {
                    $st = substr($str, 0, 1);
                    if ($st=="^" || $st=="_" || $st=="/" || $st=="|" || $st=="," || (strlen($symbol["input"])==1 && preg_match('/\w/', $symbol["input"]) && $st!="(")) {
                        return [ $this->createMmlNode($symbol["tag"], $this->dom->createTextNode($symbol["output"])), $str ];
                    } else {
                        $node = $this->createMmlNode("mrow", $this->createMmlNode($symbol["tag"], $this->dom->createTextNode($symbol["output"])));
                        $node->appendChild($result[0]);
                        return [ $node, $result[1] ];
                    }
                }
                $this->removeBrackets($result[0]);
                if ($symbol["input"]=="sqrt") { // sqrt
                    return [ $this->createMmlNode($symbol["tag"], $result[0]), $result[1] ];
                } elseif (isset($symbol["rewriteleftright"])) { // abs, floor, ceil
                    $node = $this->createMmlNode("mrow", $this->createMmlNode("mo", $this->dom->createTextNode($symbol["rewriteleftright"][0])));
                    $node->appendChild($result[0]);
                    $node->appendChild($this->createMmlNode("mo", $this->dom->createTextNode($symbol["rewriteleftright"][1])));
                    return [ $node, $result[1] ];
                } elseif ($symbol["input"]=="cancel") { // cancel
                    $node = $this->createMmlNode($symbol["tag"], $result[0]);
                    $node->setAttribute("notation", "updiagonalstrike");
                    return [ $node, $result[1] ];
                } elseif (isset($symbol["acc"])) { // accent
                    $node = $this->createMmlNode($symbol["tag"], $result[0]);
                    $accnode = $this->createMmlNode("mo", $this->dom->createTextNode($symbol["output"]));
                    if ($symbol["input"]=="vec" && (
                        ($result[0]->nodeName=="mrow" &&
                            count($result[0]->childNodes)==1 &&
                            $result[0]->firstChild->nodeValue!=null &&
                            strlen($result[0]->firstChild->nodeValue)==1) ||
                        ($result[0]->nodeValue!=null &&
                            strlen($result[0]->nodeValue)==1))) { // fixed, GS
                        $accnode->setAttribute("stretchy", "false");
                    }
                    $node->appendChild($accnode);
                    return [ $node, $result[1] ];
                } else { // font change command
                    if (isset($symbol["codes"])) {
                        for ($i=0; $i<count($result[0]->childNodes); $i++) {
                            if ($result[0]->childNodes[$i]->nodeName=="mi" || $result[0]->nodeName=="mi") {
                                $st = $result[0]->nodeName=="mi" ? $result[0]->firstChild->nodeValue : $result[0]->childNodes[$i]->firstChild->nodeValue;
                                $newst = ""; // fixed, GS
                                for ($j=0; $j<strlen($st); $j++)
                                    $ord = ord($st[$j]);
                                    if ($ord>64 && $ord<91)
                                        $newst = $newst.$symbol["codes"][$ord-65];
                                    elseif ($ord>96 && $ord<123)
                                        $newst = $newst.$symbol["codes"][$ord-71];
                                    else $newst = $newst.$st[$j];
                                    if ($result[0]->nodeName=="mi")
                                        $result[0] = $this->createMmlNode("mo", $this->dom->createTextNode($newst)); 
                                    else 
                                        $result[0]->replaceChild($this->createMmlNode("mo", $this->dom->createTextNode($newst)), $result[0]->childNodes[$i]); // fixed, GS
                            }
                        }
                    }
                    $node = $this->createMmlNode($symbol["tag"], $result[0]);
                    if (!isset($symbol["codes"])) $node->setAttribute($symbol["atname"], $symbol["atval"]); // fixed, GS
                    return [ $node, $result[1] ];
                }
            case self::BINARY:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $result = $this->parseSexpr($str);
                if ($result[0]==null)
                    return [ $this->createMmlNode("mo", $this->dom->createTextNode($symbol["input"])), $str ];
                $this->removeBrackets($result[0]);
                $result2 = $this->parseSexpr($result[1]);
                if ($result2[0]==null)
                    return [ $this->createMmlNode("mo", $this->dom->createTextNode($symbol["input"])), $str ];
                $this->removeBrackets($result2[0]);
                if (in_array($symbol["input"], [ "color", "class", "id" ])) {
                    // Get the second argument
                    if (substr($str, 0, 1)=="{") $i = strpos($str, "}");
                    elseif (substr($str, 0, 1)=="(") $i = strpos($str, ")");
                    elseif (substr($str, 0, 1)=="[") $i = strpos($str, "]");
                    $st = substr($str, 1, $i-1);
                    // Make a mathml $node
                    $node = $this->createMmlNode($symbol["tag"], $result2[0]);
                    // Set the correct attribute
                    if ($symbol["input"]=="color") $node->setAttribute("mathcolor", $st);
                    elseif ($symbol["input"]=="class") $node->setAttribute("class", $st);
                    elseif ($symbol["input"]=="id") $node->setAttribute("id", $st);
                    return [ $node, $result2[1] ];
                }
                if ($symbol["input"]=="root" || $symbol["output"]=="stackrel") $newFrag->appendChild($result2[0]);
                $newFrag->appendChild($result[0]);
                if ($symbol["input"]=="frac") $newFrag->appendChild($result2[0]);
                return [ $this->createMmlNode($symbol["tag"], $newFrag), $result2[1] ];
            case self::INFIX:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                return [ $this->createMmlNode("mo", $this->dom->createTextNode($symbol["output"])), $str ];
            case self::SPACE:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $node = $this->createMmlNode("mspace");
                $node->setAttribute("width", "1ex");
                $newFrag->appendChild($node);
                $newFrag->appendChild($this->createMmlNode($symbol["tag"], $this->dom->createTextNode($symbol["output"])));
                $node = $this->createMmlNode("mspace");
                $node->setAttribute("width", "1ex");
                $newFrag->appendChild($node);
                return [ $this->createMmlNode("mrow", $newFrag), $str ];
            case self::LEFTRIGHT:
                $this->nestingDepth++;
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $result = $this->parseExpr($str, false);
                $this->nestingDepth--;
                $st = "";
                if ($result[0]->lastChild!=null) $st = $result[0]->lastChild->firstChild->nodeValue;
                if ($st=="|" && $str[0]!==",") { // it's an absolute value subterm
                    $node = $this->createMmlNode("mo", $this->dom->createTextNode($symbol["output"]));
                    $node = $this->createMmlNode("mrow", $node);
                    $node->appendChild($result[0]);
                    return [ $node, $result[1] ];
                } else { // the "|" is a \mid so use unicode 2223 (divides) for spacing
                    $node = $this->createMmlNode("mo", $this->dom->createTextNode("\u{2223}"));
                    $node = $this->createMmlNode("mrow", $node);
                    return [ $node, $str ];
                }
            default:
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                return [ $this->createMmlNode($symbol["tag"], // it's a constant
                    $this->dom->createTextNode($symbol["output"])),
                    $str
                ];
        }
    }

    private function parseIexpr($str) {
        $str = $this->removeCharsAndBlanks($str, 0);
        $sym1 = $this->getSymbol($str);
        $result = $this->parseSexpr($str);
        $node = $result[0];
        $str = $result[1];
        $symbol = $this->getSymbol($str);
        if ($symbol["ttype"]==self::INFIX && $symbol["input"]!="/") {
            $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
            $result = $this->parseSexpr($str);
            if ($result[0]==null) // show box in place of missing argument
                $result[0] = $this->createMmlNode("mo", $this->dom->createTextNode("\u{25A1}"));
            else $this->removeBrackets($result[0]);
            $str = $result[1];
            $underover = $sym1["ttype"]==self::UNDEROVER || $sym1["ttype"]==self::UNARYUNDEROVER;
            if ($symbol["input"]=="_") {
                $sym2 = $this->getSymbol($str);
                if ($sym2["input"]=="^") {
                    $str = $this->removeCharsAndBlanks($str, strlen($sym2["input"]));
                    $res2 = $this->parseSexpr($str);
                    $this->removeBrackets($res2[0]);
                    $str = $res2[1];
                    $node = $this->createMmlNode($underover ? "munderover" : "msubsup", $node);
                    $node->appendChild($result[0]);
                    $node->appendChild($res2[0]);
                    $node = $this->createMmlNode("mrow", $node); // so sum does not stretch
                } else {
                    $node = $this->createMmlNode(($underover ? "munder" : "msub"), $node);
                    $node->appendChild($result[0]);
                }
            } elseif ($symbol["input"]=="^" && $underover) {
                $node = $this->createMmlNode("mover", $node);
                $node->appendChild($result[0]);
            } else {
                $node = $this->createMmlNode($symbol["tag"], $node);
                $node->appendChild($result[0]);
            }
            if (isset($sym1["func"])) {
                $sym2 = $this->getSymbol($str);
                if ($sym2["ttype"]!=self::INFIX && $sym2["ttype"]!=self::RIGHTBRACKET &&
                    (strlen($sym1["input"])>1 || $sym2["ttype"]==self::LEFTBRACKET)) {
                    $result = $this->parseIexpr($str);
                    $node = $this->createMmlNode("mrow", $node);
                    $node->appendChild($result[0]);
                    $str = $result[1];
                }
            }
        }
        return [ $node, $str ];
    }

    private function parseExpr($str, $rightbracket) {
        $newFrag = $this->dom->createDocumentFragment();
        do {
            $str = $this->removeCharsAndBlanks($str, 0);
            $result = $this->parseIexpr($str);
            $node = $result[0];
            $str = $result[1];
            $symbol = $this->getSymbol($str);
            if ($symbol["ttype"]==self::INFIX && $symbol["input"]=="/") {
                $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
                $result = $this->parseIexpr($str);
                if ($result[0]==null) // show box in place of missing argument
                    $result[0] = $this->createMmlNode("mo", $this->dom->createTextNode("\u{25A1}"));
                else $this->removeBrackets($result[0]);
                $str = $result[1];
                $this->removeBrackets($node);
                $node = $this->createMmlNode($symbol["tag"], $node);
                $node->appendChild($result[0]);
                $newFrag->appendChild($node);
                $symbol = $this->getSymbol($str);
            } elseif (isset($node)) $newFrag->appendChild($node);
        } while (($symbol["ttype"]!=self::RIGHTBRACKET &&
             ($symbol["ttype"]!=self::LEFTRIGHT || $rightbracket) || $this->nestingDepth==0) &&
             $symbol!=null && $symbol["output"]!="");
        if ($symbol["ttype"]==self::RIGHTBRACKET || $symbol["ttype"]==self::LEFTRIGHT) {
            $len = count($newFrag->childNodes);
            if ($len>0 && $newFrag->childNodes[$len-1]->nodeName=="mrow"
                && $newFrag->childNodes[$len-1]->lastChild
                && $newFrag->childNodes[$len-1]->lastChild->firstChild ) { // matrix
                $right = $newFrag->childNodes[$len-1]->lastChild->firstChild->nodeValue;
                if ($right==")" || $right=="]") {
                    $left = $newFrag->childNodes[$len-1]->firstChild->firstChild->nodeValue;
                    if ($left=="(" && $right==")" && $symbol["output"]!="}" || $left=="[" && $right=="]") {
                        $pos = []; // positions of commas
                        $matrix = true;
                        $m = count($newFrag->childNodes);
                        for ($i=0; $matrix && $i<$m; $i=$i+2) {
                            $pos[$i] = [];
                            $node = $newFrag->childNodes[$i];
                            if ($matrix)
                                $matrix = $node->nodeName=="mrow" &&
                                    ($i==$m-1 || $node->nextSibling->nodeName=="mo" &&
                                    $node->nextSibling->firstChild->nodeValue==",") &&
                                    $node->firstChild->firstChild &&
                                    $node->firstChild->firstChild->nodeValue==$left &&
                                    $node->lastChild->firstChild &&
                                    $node->lastChild->firstChild->nodeValue==$right;
                            if ($matrix)
                                for ($j=0; $j<count($node->childNodes); $j++)
                                    if ($node->childNodes[$j]->firstChild->nodeValue==",")
                                        $pos[$i][] = $j;
                            if ($matrix && $i>1) $matrix = count($pos[$i])==count($pos[$i-2]);
                        }
                        $matrix = $matrix && (count($pos)>1 || count($pos[0])>0);
                        $columnlines = [];
                        if ($matrix) {
                            $table = $this->dom->createDocumentFragment();
                            for ($i=0; $i<$m; $i=$i+2) {
                                $row = $this->dom->createDocumentFragment();
                                $frag = $this->dom->createDocumentFragment();
                                $node = $newFrag->firstChild; // <mrow>(-,-,...,-,-)</mrow>
                                $n = count($node->childNodes);
                                $k = 0;
                                $node->removeChild($node->firstChild); // remove (
                                for ($j=1; $j<$n-1; $j++) {
                                    if (isset($pos[$i][$k]) && $j==$pos[$i][$k]) {
                                        $node->removeChild($node->firstChild); // remove ,
                                        if ($node->firstChild->nodeName=="mrow" &&
                                            count($node->firstChild->childNodes)==1 &&
                                            $node->firstChild->firstChild->firstChild->nodeValue=="\u{2223}") {
                                            // is columnline marker - skip it
                                            if ($i==0) { $columnlines[] = "solid"; }
                                            $node->removeChild($node->firstChild); // remove mrow
                                            $node->removeChild($node->firstChild); // remove ,
                                            $j += 2;
                                            $k++;
                                        } elseif ($i==0) {
                                            $columnlines[] = "none";
                                        }
                                        $row->appendChild($this->createMmlNode("mtd", $frag));
                                        $k++;
                                    } else
                                        $frag->appendChild($node->firstChild);
                                }
                                $row->appendChild($this->createMmlNode("mtd", $frag));
                                if ($i==0) { $columnlines[] = "none"; }
                                if (count($newFrag->childNodes)>2) {
                                    $newFrag->removeChild($newFrag->firstChild); // remove <mrow>)</mrow>
                                    $newFrag->removeChild($newFrag->firstChild); // remove <mo>,</mo>
                                }
                                $table->appendChild($this->createMmlNode("mtr", $row));
                            }
                            $node = $this->createMmlNode("mtable", $table);
                            $node->setAttribute("columnlines", implode(" ", $columnlines));
                            if (isset($symbol["invisible"])) $node->setAttribute("columnalign", "left");
                            $newFrag->replaceChild($node, $newFrag->firstChild);
                        }
                    }
                }
            }
            $str = $this->removeCharsAndBlanks($str, strlen($symbol["input"]));
            if (!isset($symbol["invisible"])) {
                $node = $this->createMmlNode("mo", $this->dom->createTextNode($symbol["output"]));
                $newFrag->appendChild($node);
            }
        }
        return [ $newFrag, $str ];
    }

    public function parseMath($str, $isDisplay = true) {
        $this->nestingDepth = 0;
        $frag = $this->parseExpr(ltrim($str), false)[0];
        //return $this->dom->saveXML($frag, LIBXML_NOEMPTYTAG); // DEBUG
        if ($this->isAnnotated) {
            if (count($frag->childNodes)!=1) $frag = $this->createMmlNode("mrow", $frag);
            $frag = $this->createMmlNode("semantics", $frag);
            $annotation = $this->createMmlNode("annotation", $this->dom->createTextNode(trim($str)));
            $annotation->setAttribute("encoding", "text/x-asciimath");
            $frag->appendChild($annotation);
        }
        $node = $this->createMmlNode("math", $frag);
        $node->setAttribute("display", $isDisplay ? "block" : "inline");
        return $this->dom->saveXML($node, LIBXML_NOEMPTYTAG);
    }
}

class AsciiMathMlEscaped extends AsciiMathMl {
    public function parseMath($str, $isDisplay = true) {
        return "<span class=\"".YellowMu::ESCAPECLASS."\">".htmlspecialchars(parent::parseMath($str, $isDisplay))."</span>";
    }
}

class AsciiMathToTex {

// This class is a PHP port of asciimath2tex.js 1.50 Apr 19, 2024.
// Copyright 2024 Christian Lawson-Perfect
// https://github.com/christianp/asciimath2tex
//
//                                 Apache License
//                           Version 2.0, January 2004
//                        http://www.apache.org/licenses/
//
//   TERMS AND CONDITIONS FOR USE, REPRODUCTION, AND DISTRIBUTION
//
//   1. Definitions.
//
//      "License" shall mean the terms and conditions for use, reproduction,
//      and distribution as defined by Sections 1 through 9 of this document.
//
//      "Licensor" shall mean the copyright owner or entity authorized by
//      the copyright owner that is granting the License.
//
//      "Legal Entity" shall mean the union of the acting entity and all
//      other entities that control, are controlled by, or are under common
//      control with that entity. For the purposes of this definition,
//      "control" means (i) the power, direct or indirect, to cause the
//      direction or management of such entity, whether by contract or
//      otherwise, or (ii) ownership of fifty percent (50%) or more of the
//      outstanding shares, or (iii) beneficial ownership of such entity.
//
//      "You" (or "Your") shall mean an individual or Legal Entity
//      exercising permissions granted by this License.
//
//      "Source" form shall mean the preferred form for making modifications,
//      including but not limited to software source code, documentation
//      source, and configuration files.
//
//      "Object" form shall mean any form resulting from mechanical
//      transformation or translation of a Source form, including but
//      not limited to compiled object code, generated documentation,
//      and conversions to other media types.
//
//      "Work" shall mean the work of authorship, whether in Source or
//      Object form, made available under the License, as indicated by a
//      copyright notice that is included in or attached to the work
//      (an example is provided in the Appendix below).
//
//      "Derivative Works" shall mean any work, whether in Source or Object
//      form, that is based on (or derived from) the Work and for which the
//      editorial revisions, annotations, elaborations, or other modifications
//      represent, as a whole, an original work of authorship. For the purposes
//      of this License, Derivative Works shall not include works that remain
//      separable from, or merely link (or bind by name) to the interfaces of,
//      the Work and Derivative Works thereof.
//
//      "Contribution" shall mean any work of authorship, including
//      the original version of the Work and any modifications or additions
//      to that Work or Derivative Works thereof, that is intentionally
//      submitted to Licensor for inclusion in the Work by the copyright owner
//      or by an individual or Legal Entity authorized to submit on behalf of
//      the copyright owner. For the purposes of this definition, "submitted"
//      means any form of electronic, verbal, or written communication sent
//      to the Licensor or its representatives, including but not limited to
//      communication on electronic mailing lists, source code control systems,
//      and issue tracking systems that are managed by, or on behalf of, the
//      Licensor for the purpose of discussing and improving the Work, but
//      excluding communication that is conspicuously marked or otherwise
//      designated in writing by the copyright owner as "Not a Contribution."
//
//      "Contributor" shall mean Licensor and any individual or Legal Entity
//      on behalf of whom a Contribution has been received by Licensor and
//      subsequently incorporated within the Work.
//
//   2. Grant of Copyright License. Subject to the terms and conditions of
//      this License, each Contributor hereby grants to You a perpetual,
//      worldwide, non-exclusive, no-charge, royalty-free, irrevocable
//      copyright license to reproduce, prepare Derivative Works of,
//      publicly display, publicly perform, sublicense, and distribute the
//      Work and such Derivative Works in Source or Object form.
//
//   3. Grant of Patent License. Subject to the terms and conditions of
//      this License, each Contributor hereby grants to You a perpetual,
//      worldwide, non-exclusive, no-charge, royalty-free, irrevocable
//      (except as stated in this section) patent license to make, have made,
//      use, offer to sell, sell, import, and otherwise transfer the Work,
//      where such license applies only to those patent claims licensable
//      by such Contributor that are necessarily infringed by their
//      Contribution(s) alone or by combination of their Contribution(s)
//      with the Work to which such Contribution(s) was submitted. If You
//      institute patent litigation against any entity (including a
//      cross-claim or counterclaim in a lawsuit) alleging that the Work
//      or a Contribution incorporated within the Work constitutes direct
//      or contributory patent infringement, then any patent licenses
//      granted to You under this License for that Work shall terminate
//      as of the date such litigation is filed.
//
//   4. Redistribution. You may reproduce and distribute copies of the
//      Work or Derivative Works thereof in any medium, with or without
//      modifications, and in Source or Object form, provided that You
//      meet the following conditions:
//
//      (a) You must give any other recipients of the Work or
//          Derivative Works a copy of this License; and
//
//      (b) You must cause any modified files to carry prominent notices
//          stating that You changed the files; and
//
//      (c) You must retain, in the Source form of any Derivative Works
//          that You distribute, all copyright, patent, trademark, and
//          attribution notices from the Source form of the Work,
//          excluding those notices that do not pertain to any part of
//          the Derivative Works; and
//
//      (d) If the Work includes a "NOTICE" text file as part of its
//          distribution, then any Derivative Works that You distribute must
//          include a readable copy of the attribution notices contained
//          within such NOTICE file, excluding those notices that do not
//          pertain to any part of the Derivative Works, in at least one
//          of the following places: within a NOTICE text file distributed
//          as part of the Derivative Works; within the Source form or
//          documentation, if provided along with the Derivative Works; or,
//          within a display generated by the Derivative Works, if and
//          wherever such third-party notices normally appear. The contents
//          of the NOTICE file are for informational purposes only and
//          do not modify the License. You may add Your own attribution
//          notices within Derivative Works that You distribute, alongside
//          or as an addendum to the NOTICE text from the Work, provided
//          that such additional attribution notices cannot be construed
//          as modifying the License.
//
//      You may add Your own copyright statement to Your modifications and
//      may provide additional or different license terms and conditions
//      for use, reproduction, or distribution of Your modifications, or
//      for any such Derivative Works as a whole, provided Your use,
//      reproduction, and distribution of the Work otherwise complies with
//      the conditions stated in this License.
//
//   5. Submission of Contributions. Unless You explicitly state otherwise,
//      any Contribution intentionally submitted for inclusion in the Work
//      by You to the Licensor shall be under the terms and conditions of
//      this License, without any additional terms or conditions.
//      Notwithstanding the above, nothing herein shall supersede or modify
//      the terms of any separate license agreement you may have executed
//      with Licensor regarding such Contributions.
//
//   6. Trademarks. This License does not grant permission to use the trade
//      names, trademarks, service marks, or product names of the Licensor,
//      except as required for reasonable and customary use in describing the
//      origin of the Work and reproducing the content of the NOTICE file.
//
//   7. Disclaimer of Warranty. Unless required by applicable law or
//      agreed to in writing, Licensor provides the Work (and each
//      Contributor provides its Contributions) on an "AS IS" BASIS,
//      WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
//      implied, including, without limitation, any warranties or conditions
//      of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A
//      PARTICULAR PURPOSE. You are solely responsible for determining the
//      appropriateness of using or redistributing the Work and assume any
//      risks associated with Your exercise of permissions under this License.
//
//   8. Limitation of Liability. In no event and under no legal theory,
//      whether in tort (including negligence), contract, or otherwise,
//      unless required by applicable law (such as deliberate and grossly
//      negligent acts) or agreed to in writing, shall any Contributor be
//      liable to You for damages, including any direct, indirect, special,
//      incidental, or consequential damages of any character arising as a
//      result of this License or out of the use or inability to use the
//      Work (including but not limited to damages for loss of goodwill,
//      work stoppage, computer failure or malfunction, or any and all
//      other commercial damages or losses), even if such Contributor
//      has been advised of the possibility of such damages.
//
//   9. Accepting Warranty or Additional Liability. While redistributing
//      the Work or Derivative Works thereof, You may choose to offer,
//      and charge a fee for, acceptance of support, warranty, indemnity,
//      or other liability obligations and/or rights consistent with this
//      License. However, in accepting such obligations, You may act only
//      on Your own behalf and on Your sole responsibility, not on behalf
//      of any other Contributor, and only if You agree to indemnify,
//      defend, and hold each Contributor harmless for any liability
//      incurred by, or claims asserted against, such Contributor by reason
//      of your accepting any such warranty or additional liability.
//
//   END OF TERMS AND CONDITIONS
//
//   APPENDIX: How to apply the Apache License to your work.
//
//      To apply the Apache License to your work, attach the following
//      boilerplate notice, with the fields enclosed by brackets "[]"
//      replaced with your own identifying information. (Don't include
//      the brackets!)  The text should be enclosed in the appropriate
//      comment syntax for the file format. We also recommend that a
//      file or class name and description of purpose be included on the
//      same "printed page" as the copyright notice for easier
//      identification within third-party archives.
//
//   Copyright 2018 Christian Lawson-Perfect
//
//   Licensed under the Apache License, Version 2.0 (the "License");
//   you may not use this file except in compliance with the License.
//   You may obtain a copy of the License at
//
//       http://www.apache.org/licenses/LICENSE-2.0
//
//   Unless required by applicable law or agreed to in writing, software
//   distributed under the License is distributed on an "AS IS" BASIS,
//   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//   See the License for the specific language governing permissions and
//   limitations under the License.

    public function __construct($decimal) {
        $this->decimalsign = $decimal;
        $this->setup_symbols();
        $this->sort_symbols();
    }

    private function setup_symbols() {
        $this->greek_letters = [ 'alpha', 'beta', 'gamma', 'Gamma', 'delta', 'Delta', 'epsilon', 'varepsilon', 'zeta', 'eta', 'theta', 'Theta', 'vartheta', 'iota', 'kappa', 'lambda', 'Lambda', 'mu', 'nu', 'xi', 'Xi', 'pi', 'Pi', 'rho', 'sigma', 'Sigma', 'tau', 'upsilon', 'phi', 'Phi', 'varphi', 'chi', 'psi', 'Psi', 'omega', 'Omega' ];
        // the `|:` and `:|` symbols are defined for left and right vertical delimiters
        $this->relations = [ [
            'asciimath'=>":=",
            'tex'=>":="
        ], [
            'asciimath'=>":|:",
            'tex'=>"\\mid"
        ], [
            'asciimath'=>"=>",
            'tex'=>"\\Rightarrow"
        ], [
            'asciimath'=>"approx",
            'tex'=>"\\approx"
        ], [
            'asciimath'=>"~~",
            'tex'=>"\\approx"
        ], [
            'asciimath'=>"cong",
            'tex'=>"\\cong"
        ], [
            'asciimath'=>"~=",
            'tex'=>"\\cong"
        ], [
            'asciimath'=>"equiv",
            'tex'=>"\\equiv"
        ], [
            'asciimath'=>"-=",
            'tex'=>"\\equiv"
        ], [
            'asciimath'=>"exists",
            'tex'=>"\\exists"
        ], [
            'asciimath'=>"EE",
            'tex'=>"\\exists"
        ], [
            'asciimath'=>"forall",
            'tex'=>"\\forall"
        ], [
            'asciimath'=>"AA",
            'tex'=>"\\forall"
        ], [
            'asciimath'=>">=",
            'tex'=>"\\ge"
        ], [
            'asciimath'=>"ge",
            'tex'=>"\\ge"
        ], [
            'asciimath'=>"gt=",
            'tex'=>"\\geq"
        ], [
            'asciimath'=>"geq",
            'tex'=>"\\geq"
        ], [
            'asciimath'=>"gt",
            'tex'=>"\\gt"
        ], [
            'asciimath'=>"in",
            'tex'=>"\\in"
        ], [
            'asciimath'=>"<=",
            'tex'=>"\\le"
        ], [
            'asciimath'=>"le",
            'tex'=>"\\le"
        ], [
            'asciimath'=>"lt=",
            'tex'=>"\\leq"
        ], [
            'asciimath'=>"leq",
            'tex'=>"\\leq"
        ], [
            'asciimath'=>"lt",
            'tex'=>"\\lt"
        ], [
            'asciimath'=>"models",
            'tex'=>"\\models"
        ], [
            'asciimath'=>"|==",
            'tex'=>"\\models"
        ], [
            'asciimath'=>"!=",
            'tex'=>"\\ne"
        ], [
            'asciimath'=>"ne",
            'tex'=>"\\ne"
        ], [
            'asciimath'=>"notin",
            'tex'=>"\\notin"
        ], [
            'asciimath'=>"!in",
            'tex'=>"\\notin"
        ], [
            'asciimath'=>"prec",
            'tex'=>"\\prec"
        ], [
            'asciimath'=>"-lt",
            'tex'=>"\\prec"
        ], [
            'asciimath'=>"-<",
            'tex'=>"\\prec"
        ], [
            'asciimath'=>"preceq",
            'tex'=>"\\preceq"
        ], [
            'asciimath'=>"-<=",
            'tex'=>"\\preceq"
        ], [
            'asciimath'=>"propto",
            'tex'=>"\\propto"
        ], [
            'asciimath'=>"prop",
            'tex'=>"\\propto"
        ], [
            'asciimath'=>"subset",
            'tex'=>"\\subset"
        ], [
            'asciimath'=>"sub",
            'tex'=>"\\subset"
        ], [
            'asciimath'=>"subseteq",
            'tex'=>"\\subseteq"
        ], [
            'asciimath'=>"sube",
            'tex'=>"\\subseteq"
        ], [
            'asciimath'=>"succ",
            'tex'=>"\\succ"
        ], [
            'asciimath'=>">-",
            'tex'=>"\\succ"
        ], [
            'asciimath'=>"succeq",
            'tex'=>"\\succeq"
        ], [
            'asciimath'=>">-=",
            'tex'=>"\\succeq"
        ], [
            'asciimath'=>"supset",
            'tex'=>"\\supset"
        ], [
            'asciimath'=>"sup",
            'tex'=>"\\supset"
        ], [
            'asciimath'=>"supseteq",
            'tex'=>"\\supseteq"
        ], [
            'asciimath'=>"supe",
            'tex'=>"\\supseteq"
        ], [
            'asciimath'=>"vdash",
            'tex'=>"\\vdash"
        ], [
            'asciimath'=>"|--",
            'tex'=>"\\vdash"
        ] ];
        $this->constants = [ [
                'asciimath'=>"dt",
                'tex'=>"dt"
        ], [
            'asciimath'=>"dx",
            'tex'=>"dx"
        ], [
            'asciimath'=>"dy",
            'tex'=>"dy"
        ], [
            'asciimath'=>"dz",
            'tex'=>"dz"
        ], [
            'asciimath'=>"prime",
            'tex'=>"'"
        ], [
            'asciimath'=>"implies",
            'tex'=>"\\implies"
        ], [
            'asciimath'=>"epsi",
            'tex'=>"\\epsilon"
        ], [
            'asciimath'=>"leftrightarrow",
            'tex'=>"\\leftrightarrow"
        ], [
            'asciimath'=>"Leftrightarrow",
            'tex'=>"\\Leftrightarrow"
        ], [
            'asciimath'=>"rightarrow",
            'tex'=>"\\rightarrow"
        ], [
            'asciimath'=>"Rightarrow",
            'tex'=>"\\Rightarrow"
        ], [
            'asciimath'=>"backslash",
            'tex'=>"\\backslash"
        ], [
            'asciimath'=>"leftarrow",
            'tex'=>"\\leftarrow"
        ], [
            'asciimath'=>"Leftarrow",
            'tex'=>"\\Leftarrow"
        ], [
            'asciimath'=>"setminus",
            'tex'=>"\\setminus"
        ], [
            'asciimath'=>"bigwedge",
            'tex'=>"\\bigwedge"
        ], [
            'asciimath'=>"diamond",
            'tex'=>"\\diamond"
        ], [
            'asciimath'=>"bowtie",
            'tex'=>"\\bowtie"
        ], [
            'asciimath'=>"bigvee",
            'tex'=>"\\bigvee"
        ], [
            'asciimath'=>"bigcap",
            'tex'=>"\\bigcap"
        ], [
            'asciimath'=>"bigcup",
            'tex'=>"\\bigcup"
        ], [
            'asciimath'=>"square",
            'tex'=>"\\square"
        ], [
            'asciimath'=>"lamda",
            'tex'=>"\\lambda"
        ], [
            'asciimath'=>"Lamda",
            'tex'=>"\\Lambda"
        ], [
            'asciimath'=>"aleph",
            'tex'=>"\\aleph"
        ], [
            'asciimath'=>"angle",
            'tex'=>"\\angle"
        ], [
            'asciimath'=>"frown",
            'tex'=>"\\frown"
        ], [
            'asciimath'=>"qquad",
            'tex'=>"\\qquad"
        ], [
            'asciimath'=>"cdots",
            'tex'=>"\\cdots"
        ], [
            'asciimath'=>"vdots",
            'tex'=>"\\vdots"
        ], [
            'asciimath'=>"ddots",
            'tex'=>"\\ddots"
        ], [
            'asciimath'=>"cdot",
            'tex'=>"\\cdot"
        ], [
            'asciimath'=>"star",
            'tex'=>"\\star"
        ], [
            'asciimath'=>"|><|",
            'tex'=>"\\bowtie"
        ], [
            'asciimath'=>"circ",
            'tex'=>"\\circ"
        ], [
            'asciimath'=>"oint",
            'tex'=>"\\oint"
        ], [
            'asciimath'=>"grad",
            'tex'=>"\\nabla"
        ], [
            'asciimath'=>"quad",
            'tex'=>"\\quad"
        ], [
            'asciimath'=>"uarr",
            'tex'=>"\\uparrow"
        ], [
            'asciimath'=>"darr",
            'tex'=>"\\downarrow"
        ], [
            'asciimath'=>"downarrow",
            'tex'=>"\\downarrow"
        ], [
            'asciimath'=>"rarr",
            'tex'=>"\\rightarrow"
        ], [
            //'asciimath'=>">->>",
            //'tex'=>"\\twoheadrightarrowtail" // lacking in KaTeX
        //], [
            'asciimath'=>"larr",
            'tex'=>"\\leftarrow"
        ], [
            'asciimath'=>"harr",
            'tex'=>"\\leftrightarrow"
        ], [
            'asciimath'=>"rArr",
            'tex'=>"\\Rightarrow"
        ], [
            'asciimath'=>"lArr",
            'tex'=>"\\Leftarrow"
        ], [
            'asciimath'=>"hArr",
            'tex'=>"\\Leftrightarrow"
        ], [
            'asciimath'=>"ast",
            'tex'=>"\\ast"
        ], [
            'asciimath'=>"***",
            'tex'=>"\\star"
        ], [
            'asciimath'=>"|><",
            'tex'=>"\\ltimes"
        ], [
            'asciimath'=>"><|",
            'tex'=>"\\rtimes"
        ], [
            'asciimath'=>"^^^",
            'tex'=>"\\bigwedge"
        ], [
            'asciimath'=>"vvv",
            'tex'=>"\\bigvee"
        ], [
            'asciimath'=>"cap",
            'tex'=>"\\cap"
        ], [
            'asciimath'=>"nnn",
            'tex'=>"\\bigcap"
        ], [
            'asciimath'=>"cup",
            'tex'=>"\\cup"
        ], [
            'asciimath'=>"uuu",
            'tex'=>"\\bigcup"
        ], [
            'asciimath'=>"not",
            'tex'=>"\\neg"
        ], [
            'asciimath'=>"<=>",
            'tex'=>"\\Leftrightarrow"
        ], [
            'asciimath'=>"_|_",
            'tex'=>"\\bot"
        ], [
            'asciimath'=>"bot",
            'tex'=>"\\bot"
        ], [
            'asciimath'=>"int",
            'tex'=>"\\int"
        ], [
            'asciimath'=>"del",
            'tex'=>"\\partial"
        ], [
            'asciimath'=>"...",
            'tex'=>"\\ldots"
        ], [
            'asciimath'=>"/_\\",
            'tex'=>"\\triangle"
        ], [
            'asciimath'=>"|__",
            'tex'=>"\\lfloor"
        ], [
            'asciimath'=>"__|",
            'tex'=>"\\rfloor"
        ], [
            'asciimath'=>"dim",
            'tex'=>"\\dim"
        ], [
            'asciimath'=>"mod",
            'tex'=>"\\operatorname{mod}"
        ], [
            'asciimath'=>"lub",
            'tex'=>"\\operatorname{lub}"
        ], [
            'asciimath'=>"glb",
            'tex'=>"\\operatorname{glb}"
        ], [
            'asciimath'=>">->",
            'tex'=>"\\rightarrowtail"
        ], [
            'asciimath'=>"->>",
            'tex'=>"\\twoheadrightarrow"
        ], [
            'asciimath'=>"|->",
            'tex'=>"\\mapsto"
        ], [
            'asciimath'=>"lim",
            'tex'=>"\\lim"
        ], [
            'asciimath'=>"Lim",
            'tex'=>"\\operatorname{Lim}"
        ], [
            'asciimath'=>"and",
            'tex'=>"\\quad\\text{and}\\quad"
        ], [
            'asciimath'=>"**",
            'tex'=>"\\ast"
        ], [
            'asciimath'=>"//",
            'tex'=>"/"
        ], [
            'asciimath'=>"\\",
            'tex'=>"\\,"
        ], [
            'asciimath'=>"\\\\",
            'tex'=>"\\backslash"
        ], [
            'asciimath'=>"xx",
            'tex'=>"\\times"
        ], [
            'asciimath'=>"-:",
            'tex'=>"\\div"
        ], [
            'asciimath'=>"o+",
            'tex'=>"\\oplus"
        ], [
            'asciimath'=>"ox",
            'tex'=>"\\otimes"
        ], [
            'asciimath'=>"o.",
            'tex'=>"\\odot"
        ], [
            'asciimath'=>"^",
            'tex'=>"\\hat{}"
        ], [
            'asciimath'=>"_",
            'tex'=>"\\_"
        ], [
            'asciimath'=>"^^",
            'tex'=>"\\wedge"
        ], [
            'asciimath'=>"vv",
            'tex'=>"\\vee"
        ], [
            'asciimath'=>"nn",
            'tex'=>"\\cap"
        ], [
            'asciimath'=>"uu",
            'tex'=>"\\cup"
        ], [
            'asciimath'=>"TT",
            'tex'=>"\\top"
        ], [
            'asciimath'=>"+-",
            'tex'=>"\\pm"
        ], [
            'asciimath'=>"O/",
            'tex'=>"\\emptyset"
        ], [
            'asciimath'=>"oo",
            'tex'=>"\\infty"
        ], [
            'asciimath'=>":.",
            'tex'=>"\\therefore"
        ], [
            'asciimath'=>":'",
            'tex'=>"\\because"
        ], [
            'asciimath'=>"/_",
            'tex'=>"\\angle"
        ], [
            'asciimath'=>"|~",
            'tex'=>"\\lceil"
        ], [
            'asciimath'=>"~|",
            'tex'=>"\\rceil"
        ], [
            'asciimath'=>"CC",
            'tex'=>"\\mathbb{C}"
        ], [
            'asciimath'=>"NN",
            'tex'=>"\\mathbb{N}"
        ], [
            'asciimath'=>"QQ",
            'tex'=>"\\mathbb{Q}"
        ], [
            'asciimath'=>"RR",
            'tex'=>"\\mathbb{R}"
        ], [
            'asciimath'=>"ZZ",
            'tex'=>"\\mathbb{Z}"
        ], [
            'asciimath'=>"->",
            'tex'=>"\\to"
        ], [
            'asciimath'=>"or",
            'tex'=>"\\quad\\text{or}\\quad"
        ], [
            'asciimath'=>"if",
            'tex'=>"\\quad\\text{if}\\quad"
        ], [
            'asciimath'=>"iff",
            'tex'=>"\\iff"
        ], [
            'asciimath'=>"*",
            'tex'=>"\\cdot"
        ], [
            'asciimath'=>"@",
            'tex'=>"\\circ"
        ], [
            'asciimath'=>"%",
            'tex'=>"\\%"
        ], [
            'asciimath'=>"boxempty",
            'tex'=>"\\square"
        ], [
            'asciimath'=>"lambda",
            'tex'=>"\\lambda"
        ], [
            'asciimath'=>"Lambda",
            'tex'=>"\\Lambda"
        ], [
            'asciimath'=>"nabla",
            'tex'=>"\\nabla"
        ], [
            'asciimath'=>"uparrow",
            'tex'=>"\\uparrow"
        ], [
            'asciimath'=>"downarrow",
            'tex'=>"\\downarrow"
        ], [
            'asciimath'=>"twoheadrightarrowtail",
            'tex'=>"\\twoheadrightarrowtail"
        ], [
            'asciimath'=>"ltimes",
            'tex'=>"\\ltimes"
        ], [
            'asciimath'=>"rtimes",
            'tex'=>"\\rtimes"
        ], [
            'asciimath'=>"neg",
            'tex'=>"\\neg"
        ], [
            'asciimath'=>"partial",
            'tex'=>"\\partial"
        ], [
            'asciimath'=>"ldots",
            'tex'=>"\\ldots"
        ], [
            'asciimath'=>"triangle",
            'tex'=>"\\triangle"
        ], [
            'asciimath'=>"lfloor",
            'tex'=>"\\lfloor"
        ], [
            'asciimath'=>"rfloor",
            'tex'=>"\\rfloor"
        ], [
            'asciimath'=>"rightarrowtail",
            'tex'=>"\\rightarrowtail"
        ], [
            'asciimath'=>"twoheadrightarrow",
            'tex'=>"\\twoheadrightarrow"
        ], [
            'asciimath'=>"mapsto",
            'tex'=>"\\mapsto"
        ], [
            'asciimath'=>"times",
            'tex'=>"\\times"
        ], [
            'asciimath'=>"div",
            'tex'=>"\\div"
        ], [
            'asciimath'=>"divide",
            'tex'=>"\\div"
        ], [
            'asciimath'=>"oplus",
            'tex'=>"\\oplus"
        ], [
            'asciimath'=>"otimes",
            'tex'=>"\\otimes"
        ], [
            'asciimath'=>"odot",
            'tex'=>"\\odot"
        ], [
            'asciimath'=>"wedge",
            'tex'=>"\\wedge"
        ], [
            'asciimath'=>"vee",
            'tex'=>"\\vee"
        ], [
            'asciimath'=>"top",
            'tex'=>"\\top"
        ], [
            'asciimath'=>"pm",
            'tex'=>"\\pm"
        ], [
            'asciimath'=>"emptyset",
            'tex'=>"\\emptyset"
        ], [
            'asciimath'=>"infty",
            'tex'=>"\\infty"
        ], [
            'asciimath'=>"therefore",
            'tex'=>"\\therefore"
        ], [
            'asciimath'=>"because",
            'tex'=>"\\because"
        ], [
            'asciimath'=>"lceil",
            'tex'=>"\\lceil"
        ], [
            'asciimath'=>"rceil",
            'tex'=>"\\rceil"
        ], [
            'asciimath'=>"to",
            'tex'=>"\\to"
        ], [
            'asciimath'=>"langle",
            'tex'=>"\\langle"
        ], [
            'asciimath'=>"lceiling",
            'tex'=>"\\lceil"
        ], [
            'asciimath'=>"rceiling",
            'tex'=>"\\rceil"
        ], [
            'asciimath'=>"max",
            'tex'=>"\\max"
        ], [
            'asciimath'=>"min",
            'tex'=>"\\min"
        ], [
            'asciimath'=>"prod",
            'tex'=>"\\prod"
        ], [
            'asciimath'=>"sum",
            'tex'=>"\\sum"
        ]];
        $this->constants = array_merge($this->constants, $this->relations);
        $this->left_brackets = [ [
                'asciimath'=>"langle",
                'tex'=>"\\langle"
        ], [
            'asciimath'=>"(:",
            'tex'=>"\\langle"
        ], [
            'asciimath'=>"<<",
            'tex'=>"\\langle"
        ], [
            'asciimath'=>"{:",
            'tex'=>"."
        ], [
            'asciimath'=>"(",
            'tex'=>"("
        ], [
            'asciimath'=>"[",
            'tex'=>"["
        ], [
            'asciimath'=>"|:",
            'tex'=>"\\lvert"
        ], [
            'asciimath'=>"{",
            'tex'=>"\\lbrace"
        ], [
            'asciimath'=>"lbrace",
            'tex'=>"\\lbrace"
        ] ];
        $this->right_brackets = [ [
                'asciimath'=>"rangle",
                'tex'=>"\\rangle"
        ], [
            'asciimath'=>":)",
            'tex'=>"\\rangle"
        ], [
            'asciimath'=>">>",
            'tex'=>"\\rangle"
        ], [
            'asciimath'=>":}",
            'tex'=>".",
            'free_tex'=>":\\}"
        ], [
            'asciimath'=>")",
            'tex'=>")"
        ], [
            'asciimath'=>"]",
            'tex'=>"]"
        ], [
            'asciimath'=>":|",
            'tex'=>"\\rvert"
        ], [
            'asciimath'=>"}",
            'tex'=>"\\rbrace"
        ], [
            'asciimath'=>"rbrace",
            'tex'=>"\\rbrace"
        ] ];
        $this->leftright_brackets = [ [
                'asciimath'=>"|",
                'left_tex'=>"\\lvert",
                'right_tex'=>"\\rvert",
                'free_tex'=>"|",
                'mid_tex'=>"\\mid"
        ] ];
        $this->unary_symbols = [ [
                'asciimath'=>"sqrt",
                'tex'=>"\\sqrt"
        ], [
            'asciimath'=>"f",
            'tex'=>"f",
            'func'=>true
        ], [
            'asciimath'=>"g",
            'tex'=>"g",
            'func'=>true
        ], [
            'asciimath'=>"sin",
            'tex'=>"\\sin",
            'func'=>true
        ], [
            'asciimath'=>"cos",
            'tex'=>"\\cos",
            'func'=>true
        ], [
            'asciimath'=>"tan",
            'tex'=>"\\tan",
            'func'=>true
        ], [
            'asciimath'=>"arcsin",
            'tex'=>"\\arcsin",
            'func'=>true
        ], [
            'asciimath'=>"arccos",
            'tex'=>"\\arccos",
            'func'=>true
        ], [
            'asciimath'=>"arctan",
            'tex'=>"\\arctan",
            'func'=>true
        ], [
            'asciimath'=>"sinh",
            'tex'=>"\\sinh",
            'func'=>true
        ], [
            'asciimath'=>"cosh",
            'tex'=>"\\cosh",
            'func'=>true
        ], [
            'asciimath'=>"tanh",
            'tex'=>"\\tanh",
            'func'=>true
        ], [
            'asciimath'=>"cot",
            'tex'=>"\\cot",
            'func'=>true
        ], [
            'asciimath'=>"coth",
            'tex'=>"\\coth",
            'func'=>true
        ], [
            'asciimath'=>"sech",
            'tex'=>"\\operatorname{sech}",
            'func'=>true
        ], [
            'asciimath'=>"csch",
            'tex'=>"\\operatorname{csch}",
            'func'=>true
        ], [
            'asciimath'=>"sec",
            'tex'=>"\\sec",
            'func'=>true
        ], [
            'asciimath'=>"csc",
            'tex'=>"\\csc",
            'func'=>true
        ], [
            'asciimath'=>"log",
            'tex'=>"\\log",
            'func'=>true
        ], [
            'asciimath'=>"ln",
            'tex'=>"\\ln",
            'func'=>true
        ], [
            'asciimath'=>"abs",
            'rewriteleftright'=>["|", "|"]
        ], [
            'asciimath'=>"norm",
            'rewriteleftright'=>["\\|", "\\|"]
        ], [
            'asciimath'=>"floor",
            'rewriteleftright'=>["\\lfloor", "\\rfloor"]
        ], [
            'asciimath'=>"ceil",
            'rewriteleftright'=>["\\lceil", "\\rceil"]
        ], [
            'asciimath'=>"Sin",
            'tex'=>"\\Sin",
            'func'=>true
        ], [
            'asciimath'=>"Cos",
            'tex'=>"\\Cos",
            'func'=>true
        ], [
            'asciimath'=>"Tan",
            'tex'=>"\\Tan",
            'func'=>true
        ], [
            'asciimath'=>"Arcsin",
            'tex'=>"\\Arcsin",
            'func'=>true
        ], [
            'asciimath'=>"Arccos",
            'tex'=>"\\Arccos",
            'func'=>true
        ], [
            'asciimath'=>"Arctan",
            'tex'=>"\\Arctan",
            'func'=>true
        ], [
            'asciimath'=>"Sinh",
            'tex'=>"\\Sinh",
            'func'=>true
        ], [
            'asciimath'=>"Cosh",
            'tex'=>"\\Cosh",
            'func'=>true
        ], [
            'asciimath'=>"Tanh",
            'tex'=>"\\Tanh",
            'func'=>true
        ], [
            'asciimath'=>"Cot",
            'tex'=>"\\Cot",
            'func'=>true
        ], [
            'asciimath'=>"Sec",
            'tex'=>"\\Sec",
            'func'=>true
        ], [
            'asciimath'=>"Csc",
            'tex'=>"\\Csc",
            'func'=>true
        ], [
            'asciimath'=>"Log",
            'tex'=>"\\Log",
            'func'=>true
        ], [
            'asciimath'=>"Ln",
            'tex'=>"\\Ln",
            'func'=>true
        ], [
            'asciimath'=>"Abs",
            'tex'=>"\\Abs",
            'rewriteleftright'=>["|", "|"]
        ], [
            'asciimath'=>"det",
            'tex'=>"\\det",
            'func'=>true
        ], [
            'asciimath'=>"exp",
            'tex'=>"\\exp",
            'func'=>true
        ], [
            'asciimath'=>"gcd",
            'tex'=>"\\gcd",
            'func'=>true
        ], [
            'asciimath'=>"lcm",
            'tex'=>"\\operatorname{lcm}",
            'func'=>true
        ], [
            'asciimath'=>"cancel",
            'tex'=>"\\cancel"
        ], [
            'asciimath'=>"Sqrt",
            'tex'=>"\\Sqrt"
        ], [
            'asciimath'=>"hat",
            'tex'=>"\\hat",
            'acc'=>true
        ], [
            'asciimath'=>"bar",
            'tex'=>"\\overline",
            'acc'=>true
        ], [
            'asciimath'=>"overline",
            'tex'=>"\\overline",
            'acc'=>true
        ], [
            'asciimath'=>"vec",
            'tex'=>"\\vec",
            'acc'=>true
        ], [
            'asciimath'=>"tilde",
            'tex'=>"\\tilde",
            'acc'=>true
        ], [
            'asciimath'=>"dot",
            'tex'=>"\\dot",
            'acc'=>true
        ], [
            'asciimath'=>"ddot",
            'tex'=>"\\ddot",
            'acc'=>true
        ], [
            'asciimath'=>"ul",
            'tex'=>"\\underline",
            'acc'=>true
        ], [
            'asciimath'=>"underline",
            'tex'=>"\\underline",
            'acc'=>true
        ], [
            'asciimath'=>"ubrace",
            'tex'=>"\\underbrace",
            'acc'=>true
        ], [
            'asciimath'=>"underbrace",
            'tex'=>"\\underbrace",
            'acc'=>true
        ], [
            'asciimath'=>"obrace",
            'tex'=>"\\overbrace",
            'acc'=>true
        ], [
            'asciimath'=>"overbrace",
            'tex'=>"\\overbrace",
            'acc'=>true
        ], [
            'asciimath'=>"bb",
            'atname'=>"mathvariant",
            'atval'=>"bold",
            'tex'=>"\\mathbf"
        ], [
            'asciimath'=>"mathbf",
            'atname'=>"mathvariant",
            'atval'=>"bold",
            'tex'=>"mathbf"
        ], [
            'asciimath'=>"sf",
            'atname'=>"mathvariant",
            'atval'=>"sans-serif",
            'tex'=>"\\mathsf"
        ], [
            'asciimath'=>"mathsf",
            'atname'=>"mathvariant",
            'atval'=>"sans-serif",
            'tex'=>"mathsf"
        ], [
            'asciimath'=>"bbb",
            'atname'=>"mathvariant",
            'atval'=>"double-struck",
            'tex'=>"\\mathbb"
        ], [
            'asciimath'=>"mathbb",
            'atname'=>"mathvariant",
            'atval'=>"double-struck",
            'tex'=>"\\mathbb"
        ], [
            'asciimath'=>"cc",
            'atname'=>"mathvariant",
            'atval'=>"script",
            'tex'=>"\\mathcal"
        ], [
            'asciimath'=>"mathcal",
            'atname'=>"mathvariant",
            'atval'=>"script",
            'tex'=>"\\mathcal"
        ], [
            'asciimath'=>"tt",
            'atname'=>"mathvariant",
            'atval'=>"monospace",
            'tex'=>"\\mathtt"
        ], [
            'asciimath'=>"mathtt",
            'atname'=>"mathvariant",
            'atval'=>"monospace",
            'tex'=>"\\mathtt"
        ], [
            'asciimath'=>"fr",
            'atname'=>"mathvariant",
            'atval'=>"fraktur",
            'tex'=>"\\mathfrak"
        ], [
            'asciimath'=>"mathfrak",
            'atname'=>"mathvariant",
            'atval'=>"fraktur",
            'tex'=>"\\mathfrak"
        ] ];
        $this->binary_symbols = [ [
                'asciimath'=>"root",
                'tex'=>"\\sqrt",
                'option'=>true
        ], [
            'asciimath'=>"frac",
            'tex'=>"\\frac"
        ], [
            'asciimath'=>"stackrel",
            'tex'=>"\\stackrel"
        ], [
            'asciimath'=>"overset",
            'tex'=>"\\overset"
        ], [
            'asciimath'=>"underset",
            'tex'=>"\\underset"
        ], [
            'asciimath'=>"color",
            'tex'=>"\\color",
            'rawfirst'=>true
        ] ];
        $this->non_constant_symbols = [ '_', '^', '/' ];
    }

    private function sort_symbols() {
        $by_asciimath = function($a, $b) {
            $a = strlen($a['asciimath']); $b = strlen($b['asciimath']);
            return $b - $a; // return $b <=> $a;
        };
        usort($this->constants, $by_asciimath);
        usort($this->relations, $by_asciimath);
        usort($this->left_brackets, $by_asciimath);
        usort($this->right_brackets, $by_asciimath);
        usort($this->leftright_brackets, $by_asciimath);
        usort($this->unary_symbols, $by_asciimath);
        usort($this->binary_symbols, $by_asciimath);
    }

    private function literal($token) {
        if ($token) {
            return [
                'tex'=>$token['token'],
                'pos'=>$token['pos'],
                'end'=>$token['end'],
                'ttype'=>"literal"
            ];
        }
    }

    private function longest($matches) {
        //$matches = array_filter($matches, function($x) { return (bool)$x; });
        usort($matches, function($x, $y) {
            $x = $x['end'];
            $y = $y['end'];
            return $y - $x; // return $y <=> $x;
        });
        return $matches[0];
    }

    private function escape_text($str) {
        return str_replace([ '{', '}' ], [ "\\{", "\\}" ], $str);
    }

    private function input($str) {
        $this->_source = $str;
        $this->brackets = [];
    }

    private function source($pos = 0, $end = null) {
        if ($end!==null) {
            return substr($this->_source, $pos, $end-$pos);
        } else {
            return substr($this->_source, $pos);
        }
    }

    private function eof($pos = 0) {
        $pos = $this->strip_space($pos);
        return $pos == strlen($this->_source);
    }

    private function unbracket($tok) {
        if (empty($tok)) {
            return null;
        }
        if (!isset($tok['bracket'])) {
            return $tok;
        }
        $skip_brackets = [ '(', ')', '[', ']', '{', '}' ];
        $skipleft = in_array($tok['left']['asciimath'], $skip_brackets, true);
        $skipright = in_array($tok['right']['asciimath'], $skip_brackets, true);
        $pos = $skipleft ? $tok['left']['end'] : $tok['pos'];
        $end = $skipright ? $tok['right']['pos'] : $tok['end'];
        $left = $skipleft ? '' : $tok['left']['tex'];
        $right = $skipright ? '' : $tok['right']['tex'];
        $middle = $tok['middle'] ? $tok['middle']['tex'] : '';
        if ($left || $right) {
            $left = $left ?: '.';
            $right = $right ?: '.';
            return [
                'tex'=>"\\left {$left} {$middle} \\right {$right}", // interpolation
                'pos'=>$tok['pos'],
                'end'=>$tok['end']
            ];
        } else {
            return [
                'tex'=>$middle,
                'pos'=>$tok['pos'],
                'end'=>$tok['end'],
                'middle_asciimath'=>$this->source($pos, $end)
            ];
        }
    }

    public function parse($str) {
        $this->input($str);
        $result = $this->consume();
        return $result['tex'];
    }

    private function consume($pos = 0) {
        $tex = "";
        $exprs = [];
        while (!$this->eof($pos)) {
            $expr = $this->expression_list($pos);
            if (empty($expr)) {
                $rb = $this->right_bracket($pos);
                if ($rb) {
                    if (isset($rb['def']['free_tex'])) {
                        $rb['tex'] = $rb['def']['free_tex'];
                    }

                    $expr = $rb;
                }
                $lr = $this->leftright_bracket($pos);
                if ($lr) {
                    $expr = $lr;
                    $ss = $this->subsup($lr['end']);

                    if ($ss) {
                        $expr = [
                            'tex'=>"{$expr['tex']}{$ss['tex']}", // interpolation
                            'pos'=>$pos,
                            'end'=>$ss['end'],
                            'ttype'=>"expression"
                        ];
                    }
                }
            }

            if ($expr) {
                if ($tex) {
                    $tex .= ' ';
                }
                $tex .= $expr['tex'];
                $pos = $expr['end'];
                $exprs[] = $expr;
            } elseif (!$this->eof($pos)) {
                $chr = $this->source($pos, $pos + 1);
                $exprs[] = [
                    'tex'=>$chr,
                    'pos'=>$pos,
                    'ttype'=>"character"
                ];
                $tex .= $chr;
                $pos += 1;
            }
        }
        return [
            'tex'=>$tex,
            'exprs'=>$exprs
        ];
    }

    private function strip_space($pos = 0) {
        $osource = $this->source($pos);
        $reduced = preg_replace('/^(\\s|\\\\(?![\\\\ ]))*/', '', $osource); // added whitespace
        return $pos + strlen($osource) - strlen($reduced);
    }

    /* Does the given regex match next? */
    private function match($re, $pos) {
        $pos = $this->strip_space($pos);
        preg_match($re, $this->source($pos), $m);
        if ($m) {
            $token = $m[0];
            return [
                'token'=>$token,
                'pos'=>$pos,
                'match'=>$m,
                'end'=>$pos + strlen($token),
                'ttype'=>"regex"
            ];
        }
    }

    /* Does the exact given string occur next? */
    private function exact($str, $pos) {
        $pos = $this->strip_space($pos);
        if (substr($this->source($pos), 0, strlen($str)) == $str) {
            return [
                'token'=>$str,
                'pos'=>$pos,
                'end'=>$pos + strlen($str),
                'ttype'=>"exact"
            ];
        }
    }

    private function expression_list($pos = 0) {
        $expr = $this->expression($pos);
        if (!$expr) {
            return null;
        }
        $end = $expr['end'];
        $tex = $expr['tex'];
        $exprs = [ $expr ];
        while (!$this->eof($end)) {
            $comma = $this->exact(",", $end);
            if (!$comma) {
                break;
            }
            $tex .= ' ,';
            $end = $comma['end'];
            $expr = $this->expression($end);
            if (!$expr) {
                break;
            }
            $tex .= ' ' . $expr['tex'];
            $exprs[] = $expr;
            $end = $expr['end'];
        }
        return [
            'tex'=>$tex,
            'pos'=>$pos,
            'end'=>$end,
            'exprs'=>$exprs,
            'ttype'=>"expression_list"
        ];
    }

    // E ::= IE | I/I                       Expression
    private function expression($pos = 0) {
        $negative = $this->negative_expression($pos);
        if ($negative) {
            return $negative;
        }
        $first = $this->intermediate_or_fraction($pos);
        if (!$first) {
            foreach ($this->non_constant_symbols as $c) {
                $m = $this->exact($c, $pos);
                if ($m) {
                    return [
                        'tex'=>$c,
                        'pos'=>$pos,
                        'end'=>$m['end'],
                        'ttype'=>"constant"
                    ];
                }
            }
            return null;
        }

        if ($this->eof($first['end'])) {
            return $first;
        }
        $second = $this->expression($first['end']);
        if ($second) {
            return [
                'tex'=>$first['tex'] . ' ' . $second['tex'],
                'pos'=>$first['pos'],
                'end'=>$second['end'],
                'ttype'=>"expression",
                'exprs'=>[$first, $second]
            ];
        } else {
            return $first;
        }
    }

    private function negative_expression($pos = 0) {
        $dash = $this->exact("-", $pos);
        if ($dash && !$this->other_constant($pos)) {
            $expr = $this->expression($dash['end']);
            if ($expr) {
                return [
                    'tex'=>"- {$expr['tex']}", // interpolation
                    'pos'=>$pos,
                    'end'=>$expr['end'],
                    'ttype'=>"negative_expression",
                    'dash'=>$dash,
                    'expression'=>$expr
                ];
            } else {
                return [
                    'tex'=>"-",
                    'pos'=>$pos,
                    'end'=>$dash['end'],
                    'ttype'=>"constant"
                ];
            }
        }
    }

    private function intermediate_or_fraction($pos = 0) {
        $first = $this->intermediate($pos);
        if (!$first) {
            return null;
        }
        $frac = $this->match('/^\/(?!\/)/', $first['end']);
        if ($frac) {
            $second = $this->intermediate($frac['end']);
            if ($second) {
                $ufirst = $this->unbracket($first);
                $usecond = $this->unbracket($second);
                return [
                    'tex'=>"\\frac{{$ufirst['tex']}}{{$usecond['tex']}}", // interpolation
                    'pos'=>$first['pos'],
                    'end'=>$second['end'],
                    'ttype'=>"fraction",
                    'numerator'=>$ufirst,
                    'denominator'=>$usecond,
                    'raw_numerator'=>$first,
                    'raw_denominator'=>$second
                ];
            } else {
                $ufirst = $this->unbracket($first);
                return [
                    'tex'=>"\\frac{{$ufirst['tex']}}{}", // interpolation
                    'pos'=>$first['pos'],
                    'end'=>$frac['end'],
                    'ttype'=>"fraction",
                    'numerator'=>$ufirst,
                    'denominator'=>null,
                    'raw_numerator'=>$first,
                    'raw_denominator'=>null
                ];
            }
        } else {
            return $first;
        }
    }

    // I ::= S_S | S^S | S_S^S | S          Intermediate expression
    private function intermediate($pos = 0) {
        $first = $this->simple($pos);
        if (!$first) {
            return null;
        }
        $ss = $this->subsup($first['end']);
        if ($ss) {
            return [
                'tex'=>"{$first['tex']}{$ss['tex']}", // interpolation
                'pos'=>$pos,
                'end'=>$ss['end'],
                'ttype'=>"intermediate",
                'expression'=>$first,
                'subsup'=>$ss
            ];
        } else {
            return $first;
        }
    }

    private function subsup($pos = 0) {
        $tex = "";
        $end = $pos;
        $sub = $this->exact('_', $pos);
        $sub_expr = null; $sup_expr = null;
        if ($sub) {
            $sub_expr = $this->unbracket($this->simple($sub['end']));
            if ($sub_expr) {
                $tex = "{$tex}_{{$sub_expr['tex']}}"; // interpolation
                $end = $sub_expr['end'];
            } else {
                $tex = "{$tex}_{}"; // interpolation
                $end = $sub['end'];
            }
        }
        $sup = $this->match('/^\^(?!\^)/', $end);
        if ($sup) {
            $sup_expr = $this->unbracket($this->simple($sup['end']));
            if ($sup_expr) {
                $tex = "{$tex}^{{$sup_expr['tex']}}"; // interpolation
                $end = $sup_expr['end'];
            } else {
                $tex = "{$tex}^{}"; // interpolation
                $end = $sup['end'];
            }
        }
        if ($sub || $sup) {
            return [
                'tex'=>$tex,
                'pos'=>$pos,
                'end'=>$end,
                'ttype'=>"subsup",
                'sub'=>$sub_expr,
                'sup'=>$sup_expr
            ];
        }
    }

    // S ::= v | lEr | uS | bSS             Simple expression
    private function simple($pos = 0) {
        return $this->longest([$this->bracketed_matrix($pos), $this->matrix($pos), $this->bracketed_expression($pos), $this->binary($pos), $this->constant($pos), $this->text($pos), $this->unary($pos), $this->negative_simple($pos)]);
    }

    private function negative_simple($pos = 0) {
        $dash = $this->exact("-", $pos);
        if ($dash && !$this->other_constant($pos)) {
            $expr = $this->simple($dash['end']);
            if ($expr) {
                return [
                    'tex'=>"- {$expr['tex']}", // interpolation
                    'pos'=>$pos,
                    'end'=>$expr['end'],
                    'ttype'=>"negative_simple",
                    'dash'=>$dash,
                    'expr'=>$expr
                ];
            } else {
                return [
                    'tex'=>"-",
                    'pos'=>$pos,
                    'end'=>$dash['end'],
                    'ttype'=>"constant"
                ];
            }
        }
    }

    // a matrix wrapped in brackets
    private function bracketed_matrix($pos = 0) {
        $l = $this->left_bracket($pos) ?: $this->leftright_bracket($pos);
        if(!$l) {
            return null;
        }
        $matrix = $this->longest([$this->bracketed_matrix($l['end']), $this->matrix($l['end'])]);
        if(!$matrix) {
            return null;
        }
        $r = $this->right_bracket($matrix['end']) ?: $this->leftright_bracket($matrix['end'], 'right');
        if($r) {
            return [
                'tex'=>"\\left {$l['tex']} {$matrix['tex']} \\right {$r['tex']}", // interpolation
                'pos'=>$pos,
                'end'=>$r['end'],
                'bracket'=>true,
                'left'=>$l,
                'right'=>$r,
                'middle'=>$matrix,
                'ttype'=>"bracket"
            ];
        } elseif ($this->eof($matrix['end'])) {
            return [
                'tex'=>"\\left {$l['tex']} {$matrix['tex']} \\right.", // interpolation
                'pos'=>$pos,
                'end'=>$matrix['end'],
                'bracket'=>true,
                'left'=>$l,
                'right'=>null,
                'middle'=>$matrix,
                'ttype'=>"bracket"
            ];
        }
    }

    // 'matrix'=>leftbracket "(" $expr ")" ("," "(" $expr ")")* rightbracket 
    // each row must have the same number of elements
    private function matrix($pos = 0) {
        $left = $this->left_bracket($pos);
        $lr = false;
        if (!$left) {
            $left = $this->leftright_bracket($pos, 'left');
            if (!$left) {
                return null;
            }
            $lr = true;
        }
        $contents = $this->matrix_contents($left['end'], $lr);
        if (!$contents) {
            return null;
        }
        $right = $lr ? $this->leftright_bracket($contents['end'], 'right') : $this->right_bracket($contents['end']);
        if (!$right) {
            return null;
        }
        $contents_tex = implode(" \\\\ ", array_map(function($r) { return $r['tex']; }, $contents['rows']));
        $matrix_tex = $contents["is_array"] ? "\\begin{array}{{$contents['column_desc']}} {$contents_tex} \\end{array}" : "\\begin{matrix} {$contents_tex} \\end{matrix}"; // interpolation
        return [
            'tex'=>"\\left {$left['tex']} {$matrix_tex} \\right {$right['tex']}", // interpolation
            'pos'=>$pos,
            'end'=>$right['end'],
            'ttype'=>"matrix",
            'rows'=>$contents['rows'],
            'left'=>$left,
            'right'=>$right
        ];
    }

    private function matrix_contents($pos = 0, $leftright = false) {
        $rows = [];
        $end = $pos;
        $row_length = null;
        $column_desc = null;
        $is_array = false;
        while (!$this->eof($end) && !($leftright ? $this->leftright_bracket($end) : $this->right_bracket($end))) {
            if (count($rows)) {
                $comma = $this->exact(",", $end);
                if (!$comma) {
                    return null;
                }
                $end = $comma['end'];
            }
            $lb = $this->match('/^[(\[]/', $end);
            if (!$lb) {
                return null;
            }
            $cells = [];
            $columns = [];
            $end = $lb['end'];
            while (!$this->eof($end)) {
                if (count($cells)) {
                    $comma = $this->exact(",", $end);
                    if (!$comma) {
                        break;
                    }
                    $end = $comma['end'];
                }
                $cell = $this->matrix_cell($end);
                if (!$cell) {
                    break;
                }
                if ($cell['ttype'] == 'column') {
                    $columns[] = '|';
                    $is_array = true;
                    if ($cell['expr']!==null) {
                        $columns[] = 'r';
                        $cells[] = $cell['expr'];
                    }
                } else {
                    $columns[] = 'r';
                    $cells[] = $cell;
                }
                $end = $cell['end'];
            }
            if (!count($cells)) {
                return null;
            }
            if ($row_length===null) {
                $row_length = count($cells);
            } elseif (count($cells) != $row_length) {
                return null;
            }
            $rb = $this->match('/^[)\]]/', $end);
            if (!$rb) {
                return null;
            }
            $row_column_desc = implode('', $columns);
            if ($column_desc===null) {
                $column_desc = $row_column_desc;
            } else if ($row_column_desc != $column_desc) {
                return null;
            }
            $rows[] = [
                'ttype'=>"row",
                'tex'=>implode(' & ', array_map(function($c) { return $c['tex']; }, $cells)),
                'pos'=>$lb['end'],
                'end'=>$end,
                'cells'=>$cells
            ];
            $end = $rb['end'];
        }
        if ($row_length===null || $row_length<=1 && count($rows)<=1) {
            return null;
        }
        return [
            'rows'=>$rows,
            'end'=>$end,
            'column_desc'=>$column_desc,
            'is_array'=>$is_array
        ];
    }

    private function matrix_cell($pos = 0) {
        $lvert = $this->exact('|', $pos);
        if ($lvert) {
            $middle = $this->expression($lvert['end']);
            if ($middle) {
                $rvert = $this->exact('|', $middle['end']);
                if ($rvert) {
                    $second = $this->expression($rvert['end']);
                    if ($second) {
                        return [
                            'tex'=>"\\left \\lvert {$middle['tex']} \\right \\rvert {$second['text']}", // interpolation
                            'pos'=>$lvert['pos'],
                            'end'=>$second['end'],
                            'ttype'=>"expression",
                            'exprs'=>[$middle, $second]
                        ];
                    }
                } else {
                    return [
                        'ttype'=>"column",
                        'expr'=>$middle,
                        'pos'=>$lvert['pos'],
                        'end'=>$middle['end']
                    ];
                }
            } else {
                return [
                    'ttype'=>"column",
                    'expr'=>null,
                    'pos'=>$lvert['pos'],
                    'end'=>$lvert['end']
                ];
            }
        }
        return $this->expression($pos);
    }

    private function bracketed_expression($pos = 0) {
        $l = $this->left_bracket($pos);
        if ($l) {
            $middle = $this->expression_list($l['end']);
            if ($middle) {
                $m = $this->mid_expression($l, $middle, $pos);
                if ($m) {
                    return $m;
                }
                $r = $this->right_bracket($middle['end']) ?: $this->leftright_bracket($middle['end'], 'right');
                if ($r) {
                    return [
                        'tex'=>"\\left {$l['tex']} {$middle['tex']} \\right {$r['tex']}", // interpolation
                        'pos'=>$pos,
                        'end'=>$r['end'],
                        'bracket'=>true,
                        'left'=>$l,
                        'right'=>$r,
                        'middle'=>$middle,
                        'ttype'=>"bracket"
                    ];
                } else if ($this->eof($middle['end'])) {
                    return [
                        'tex'=>"\\left {$l['tex']} {$middle['tex']} \\right.", // interpolation
                        'pos'=>$pos,
                        'end'=>$middle['end'],
                        'ttype'=>"bracket",
                        'left'=>$l,
                        'right'=>null,
                        'middle'=>$middle
                    ];
                } else {
                    return [
                        'tex'=>"{$l['tex']} {$middle['tex']}", // interpolation
                        'pos'=>$pos,
                        'end'=>$middle['end'],
                        'ttype'=>"expression",
                        'exprs'=>[$l, $middle]
                    ];
                }
            } else {
                $r = $this->right_bracket($l['end']) ?: $this->leftright_bracket($l['end'], 'right');
                if ($r) {
                    return [
                        'tex'=>"\\left {$l['tex']} \\right {$r['tex']}", // interpolation
                        'pos'=>$pos,
                        'end'=>$r['end'],
                        'bracket'=>true,
                        'left'=>$l,
                        'right'=>$r,
                        'middle'=>null,
                        'ttype'=>"bracket"
                    ];
                } else {
                    return [
                        'tex'=>$l['tex'],
                        'pos'=>$pos,
                        'end'=>$l['end'],
                        'ttype'=>"constant"
                    ];
                }
            }
        }
        if ($this->other_constant($pos)) {
            return null;
        }
        $left = $this->leftright_bracket($pos, 'left');
        if ($left) {
            $middle = $this->expression_list($left['end']);
            if ($middle) {
                $m = $this->mid_expression($left, $middle, $pos);
                if($m) {
                    return $m;
                }
                $right = $this->leftright_bracket($middle['end'], 'right') ?: $this->right_bracket($middle['end']);
                if ($right) {
                    return [
                        'tex'=>"\\left {$left['tex']} {$middle['tex']} \\right {$right['tex']}", // interpolation
                        'pos'=>$pos,
                        'end'=>$right['end'],
                        'bracket'=>true,
                        'left'=>$left,
                        'right'=>$right,
                        'middle'=>$middle,
                        'ttype'=>"bracket"
                    ];
                }
            }
        }
    }

    // Detect the case where the "middle" part of a bracketed expression ends in another bracketed expression whose left delimiter is a left/right symbol, e.g. `|`.
    // In these cases, interpret this as a bracketed expression where the left/right symbol is a 'mid' delimiter.
    private function mid_expression($l, $middle, $pos) {
        $is_mid_bracket = function($t) {
            return isset($t['ttype']) && $t['ttype']=='bracket' && $t['left']['ttype']=='leftright_bracket'; // added isset
        };
        if(count($middle['exprs'])==1 && $middle['exprs'][0]['ttype']=='expression') {
            $firsts = [ $middle['exprs'][0]['exprs'][0] ];
            $last = $middle['exprs'][0]['exprs'][1];
            $end = $middle['end'];
            while ($last['ttype']=='expression') {
                $first = $last['exprs'][0];
                if ($is_mid_bracket($first)) {
                    $last = $first;
                    $end = $first['end'];
                    break;
                }
                $firsts[] = $last['exprs'][0];
                $last = $last['exprs'][1];
            }
            if ($last['ttype']=='fraction') {
                $last = $last['raw_numerator'];
                $end = $last['end'];
            }
            if(!($last['ttype']=='bracket' && $last['left']['ttype']=='leftright_bracket')) {
                return null;
            }
            $firsttex = implode(" ", array_map(function($e) { return $e['tex']; }, $firsts));
            $mid = $last['left'];
            $lasttex = implode(" ", array_map(function($e) { return $e['tex']; }, $last['middle']['exprs']));
            $nr = $last['right'];
            return [
                'tex'=>"\\left {$l['tex']} {$firsttex} {$mid['def']['mid_tex']} {$lasttex} \\right {$nr['tex']}", // interpolation
                'pos'=> $pos,
                'end'=> $end,
                'left'=> $l,
                'right'=> $nr,
                'middle'=> [
                    'tex'=> "{$firsttex} {$mid['def']['mid_tex']} {$lasttex}", // interpolation
                    'exprs'=> array_merge($firsts, [ $mid, $last['middle'] ]),
                    'pos'=> $middle['pos'],
                    'end'=> $last['middle']['end'],
                    'ttype'=> 'expression_list'
                ]
            ];
        }
    }

    // r ::= ) | ] | } | :) | :} | other right brackets
    private function right_bracket($pos = 0) {
        foreach ($this->right_brackets as $bracket) {
            $m = $this->exact($bracket['asciimath'], $pos);
            if ($m) {
                return [
                    'tex'=>$bracket['tex'],
                    'pos'=>$pos,
                    'end'=>$m['end'],
                    'asciimath'=>$bracket['asciimath'],
                    'def'=>$bracket,
                    'ttype'=>"right_bracket"
                ];
            }
        }
    }

    // l ::= ( | [ | { | (=>| {=>| other left brackets
    private function left_bracket($pos = 0) {
        foreach ($this->left_brackets as $bracket) {
            $m = $this->exact($bracket['asciimath'], $pos);
            if ($m) {
                return [
                    'tex'=>$bracket['tex'],
                    'pos'=>$pos,
                    'end'=>$m['end'],
                    'asciimath'=>$bracket['asciimath'],
                    'ttype'=>"left_bracket"
                ];
            }
        }
    }

    private function leftright_bracket($pos = 0, $position = null) {
        foreach ($this->leftright_brackets as $lr) {
            $b = $this->exact($lr['asciimath'], $pos);
            if ($b) {
                if ($this->exact(',', $b['end'])) {
                    return [
                        'tex'=>$lr['free_tex'],
                        'pos'=>$pos,
                        'end'=>$b['end'],
                        'ttype'=>'binary'
                    ];
                } else {
                    return [
                        'tex'=>$position == 'left' ? $lr['left_tex'] : ($position == 'right' ? $lr['right_tex'] : $lr['free_tex']),
                        'pos'=>$pos,
                        'end'=>$b['end'],
                        'ttype'=>"leftright_bracket",
                        'def'=>$lr
                    ];
                }
            }
        }
    }

    private function text($pos = 0) {
        $quoted = $this->match('/^"([^"]*)"/', $pos);
        if ($quoted) {
            $text = $this->escape_text($quoted['match'][1]);
            return [
                'tex'=>"\\text{{$text}}", // interpolation
                'pos'=>$pos,
                'end'=>$quoted['end'],
                'ttype'=>"text",
                'text'=>$text
            ];
        }
        $textfn = $this->match('/^(?:mbox|text)\s*(\([^)]*\)?|\{[^}]*\}?|\[[^\]]*\]?)/', $pos);
        if ($textfn) {
            $text = $this->escape_text(substr($textfn['match'][1], 1, strlen($textfn['match'][1]) - 2));
            return [
                'tex'=>"\\text{{$text}}", // interpolation
                'pos'=>$pos,
                'end'=>$textfn['end'],
                'ttype'=>"text",
                'text'=>$text
            ];
        }
    }

    // b ::= frac | root | stackrel | other binary symbols
    private function binary($pos = 0) {
        foreach ($this->binary_symbols as $binary) {
            $m = $this->exact($binary['asciimath'], $pos);
            list($lb1, $rb1) = isset($binary['option']) ? ['[', ']'] : ['{', '}'];
            if ($m) {
                $a = $this->unbracket($this->simple($m['end']));
                if ($a) {
                    $atex = isset($binary['rawfirst']) ? $a['middle_asciimath'] : $a['tex'];
                    $b = $this->unbracket($this->simple($a['end']));
                    if ($b) {
                        return [
                            'tex'=>"{$binary['tex']}{$lb1}{$atex}{$rb1}{{$b['tex']}}", // interpolation
                            'pos'=>$pos,
                            'end'=>$b['end'],
                            'ttype'=>"binary",
                            'op'=>$binary,
                            'arg1'=>$a,
                            'arg2'=>$b
                        ];
                    } else {
                        return [
                            'tex'=>"{$binary['tex']}{$lb1}{$atex}{$rb1}{}", // interpolation
                            'pos'=>$pos,
                            'end'=>$a['end'],
                            'ttype'=>"binary",
                            'op'=>$binary,
                            'arg1'=>$a,
                            'arg2'=>null
                        ];
                    }
                } else {
                    return [
                        'tex'=>"{$binary['tex']}{$lb1}{$rb1}{}", // interpolation
                        'pos'=>$pos,
                        'end'=>$m['end'],
                        'ttype'=>"binary",
                        'op'=>$binary,
                        'arg1'=>null,
                        'arg2'=>null
                    ];
                }
            }
        }
    }

    // $u ::= sqrt | $text | bb | other unary symbols for font commands
    private function unary($pos = 0) {
        foreach ($this->unary_symbols as $u) {
            $m = $this->exact($u['asciimath'], $pos);
            if ($m) {
                $ss = $this->subsup($m['end']);
                $sstex = $ss ? $ss['tex'] : '';
                $end = $ss ? $ss['end'] : $m['end'];
                $barg = $this->simple($end);
                $arg = isset($u['func']) ? $barg : $this->unbracket($barg);
                $argtex = $arg ? (isset($u['raw']) ? $arg['middle_asciimath'] : $arg['tex']) : null;
                if (isset($u['rewriteleftright'])) {
                    list($left, $right) = $u['rewriteleftright'];
                    if ($arg) {
                        return [
                            'tex'=>"\\left {$left} {$argtex} \\right {$right} {$sstex}", // interpolation
                            'pos'=>$pos,
                            'end'=>$arg['end'],
                            'ttype'=>"unary",
                            'op'=>$m,
                            'subsup'=>$ss,
                            'arg'=>$arg
                        ];
                    } else {
                        return [
                            'tex'=>"\\left {$left} \\right {$right} {$sstex}", // interpolation
                            'pos'=>$pos,
                            'end'=>$m['end'],
                            'ttype'=>"unary",
                            'op'=>$m,
                            'subsup'=>$ss,
                            'arg'=>null
                        ];
                    }
                } else {
                    if ($arg) {
                        return [
                            'tex'=>"{$u['tex']}{$sstex}{{$argtex}}", // interpolation
                            'pos'=>$pos,
                            'end'=>$arg['end'],
                            'ttype'=>"unary",
                            'op'=>$m,
                            'subsup'=>$ss,
                            'arg'=>$arg
                        ];
                    } else {
                        return [
                            'tex'=>"{$u['tex']}{$sstex}{}", // interpolation
                            'pos'=>$pos,
                            'end'=>$m['end'],
                            'ttype'=>"unary",
                            'op'=>$m,
                            'subsup'=>$ss,
                            'arg'=>null
                        ];
                    }
                }
            }
        }
    }

    // v ::= [A-Za-z] | greek letters | numbers | other constant symbols
    private function constant($pos = 0) {
        if ($this->right_bracket($pos)) {
            return null;
        }
        return $this->longest([$this->other_constant($pos), $this->greek($pos), $this->name($pos), $this->number($pos), $this->arbitrary_constant($pos)]);
    }

    private function name($pos = 0) {
        return $this->literal($this->match('/^[A-Za-z]/', $pos));
    }

    private function greek($pos = 0) {
        $re_greek = '/^(' . implode('|', $this->greek_letters) . ')/';
        $m = $this->match($re_greek, $pos);
        if ($m) {
            return [
                'tex'=>"\\" . $m['token'],
                'pos'=>$pos,
                'end'=>$m['end'],
                'ttype'=>"greek"
            ];
        }
    }

    private function number($pos = 0) {  // rewritten, GS
        $re_number = '/^(\d+(?:\.\d+)?)(?:(e)([-+]?\d+))?/';
        $m = $this->match($re_number, $pos);
        if($m) {
            $m['match'][1] = str_replace(".", "{{$this->decimalsign}}", $m['match'][1]);
            return [
                "tex"=> isset($m['match'][2]) ? "{$m['match'][1]}\\mathrm{e}{{$m['match'][3]}}" : $m['match'][1],
                "pos"=>$m["pos"],
                "end"=>$m["end"]
            ];
        }
    }

    private function other_constant($pos = 0) {
        foreach ($this->constants as $sym) {
            $m = $this->exact($sym['asciimath'], $pos);
            if ($m) {
                return [
                    'tex'=>"{$sym['tex']}", // interpolation
                    'pos'=>$m['pos'],
                    'end'=>$m['end'],
                    'ttype'=>"other_constant"
                ];
            }
        }
        foreach ($this->relations as $sym) {
            if (!preg_match('/^!/', $sym['asciimath'])) {
                $notm = $this->exact('!' . $sym['asciimath'], $pos);
                if ($notm) {
                    return [
                        'tex'=>"\\not {$sym['tex']}", // interpolation
                        'pos'=>$notm['pos'],
                        'end'=>$notm['end'],
                        'ttype'=>"other_constant"
                    ];
                }
            }
        }
    }

    private function arbitrary_constant($pos = 0) {
        if (!$this->eof($pos)) {
            if ($this->exact(",", $pos)) {
                return null;
            }
            foreach (array_merge($this->non_constant_symbols, array_map(function ($x) { return $x['asciimath']; }, $this->left_brackets), array_map(function($x) { return $x['asciimath']; }, $this->right_brackets), array_map(function($x) { return $x['asciimath']; }, $this->leftright_brackets)) as $nc) {
                if ($this->exact($nc, $pos)) {
                    return null;
                }
            }
            $spos = $this->strip_space($pos);
            $symbol = substr($this->source($spos), 0, 1);
            return [
                'tex'=>$symbol,
                'pos'=>$pos,
                'end'=>$spos + 1,
                'ttype'=>"arbitrary_constant"
            ];
        }
    }
}

class AsciiMathHtmlTex extends AsciiMathToTex {
    public function parseMath($str, $isDisplay = true) {
        $content = $this->parse($str, $isDisplay);
        $tag = $isDisplay ? "div" : "span";
        $output = "<$tag class=\"math\">".htmlspecialchars($content)."</$tag>";
        return $output;
    }
}
