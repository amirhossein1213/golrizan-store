<?php
declare(strict_types=1);

function pdf_u16(string $data,int $offset): int { $v=unpack('n',substr($data,$offset,2));return (int)($v[1]??0); }
function pdf_s16(string $data,int $offset): int { $v=pdf_u16($data,$offset);return $v>32767?$v-65536:$v; }
function pdf_u32(string $data,int $offset): int { $v=unpack('N',substr($data,$offset,4));return (int)($v[1]??0); }

function pdf_utf8_codepoints(string $text): array
{
    $points=[];$length=strlen($text);
    for($i=0;$i<$length;){$a=ord($text[$i]);if($a<128){$points[]=$a;$i++;continue;}if(($a&0xE0)===0xC0&&$i+1<$length){$points[]=(($a&31)<<6)|(ord($text[$i+1])&63);$i+=2;continue;}if(($a&0xF0)===0xE0&&$i+2<$length){$points[]=(($a&15)<<12)|((ord($text[$i+1])&63)<<6)|(ord($text[$i+2])&63);$i+=3;continue;}if(($a&0xF8)===0xF0&&$i+3<$length){$points[]=(($a&7)<<18)|((ord($text[$i+1])&63)<<12)|((ord($text[$i+2])&63)<<6)|(ord($text[$i+3])&63);$i+=4;continue;}$points[]=0xFFFD;$i++;}
    return $points;
}

function pdf_arabic_forms(): array
{
    return [
        0x0621=>[0xFE80,null,null,null],0x0622=>[0xFE81,0xFE82,null,null],0x0623=>[0xFE83,0xFE84,null,null],0x0624=>[0xFE85,0xFE86,null,null],0x0625=>[0xFE87,0xFE88,null,null],0x0626=>[0xFE89,0xFE8A,0xFE8B,0xFE8C],0x0627=>[0xFE8D,0xFE8E,null,null],
        0x0628=>[0xFE8F,0xFE90,0xFE91,0xFE92],0x067E=>[0xFB56,0xFB57,0xFB58,0xFB59],0x0629=>[0xFE93,0xFE94,null,null],0x062A=>[0xFE95,0xFE96,0xFE97,0xFE98],0x062B=>[0xFE99,0xFE9A,0xFE9B,0xFE9C],0x062C=>[0xFE9D,0xFE9E,0xFE9F,0xFEA0],0x0686=>[0xFB7A,0xFB7B,0xFB7C,0xFB7D],
        0x062D=>[0xFEA1,0xFEA2,0xFEA3,0xFEA4],0x062E=>[0xFEA5,0xFEA6,0xFEA7,0xFEA8],0x062F=>[0xFEA9,0xFEAA,null,null],0x0630=>[0xFEAB,0xFEAC,null,null],0x0631=>[0xFEAD,0xFEAE,null,null],0x0632=>[0xFEAF,0xFEB0,null,null],0x0698=>[0xFB8A,0xFB8B,null,null],
        0x0633=>[0xFEB1,0xFEB2,0xFEB3,0xFEB4],0x0634=>[0xFEB5,0xFEB6,0xFEB7,0xFEB8],0x0635=>[0xFEB9,0xFEBA,0xFEBB,0xFEBC],0x0636=>[0xFEBD,0xFEBE,0xFEBF,0xFEC0],0x0637=>[0xFEC1,0xFEC2,0xFEC3,0xFEC4],0x0638=>[0xFEC5,0xFEC6,0xFEC7,0xFEC8],0x0639=>[0xFEC9,0xFECA,0xFECB,0xFECC],0x063A=>[0xFECD,0xFECE,0xFECF,0xFED0],
        0x0641=>[0xFED1,0xFED2,0xFED3,0xFED4],0x0642=>[0xFED5,0xFED6,0xFED7,0xFED8],0x0643=>[0xFED9,0xFEDA,0xFEDB,0xFEDC],0x06A9=>[0xFB8E,0xFB8F,0xFB90,0xFB91],0x06AF=>[0xFB92,0xFB93,0xFB94,0xFB95],0x0644=>[0xFEDD,0xFEDE,0xFEDF,0xFEE0],0x0645=>[0xFEE1,0xFEE2,0xFEE3,0xFEE4],0x0646=>[0xFEE5,0xFEE6,0xFEE7,0xFEE8],0x0648=>[0xFEE9,0xFEEA,null,null],
        0x0647=>[0xFEEB,0xFEEC,0xFEED,0xFEEE],0x0649=>[0xFEEF,0xFEF0,null,null],0x064A=>[0xFEF1,0xFEF2,0xFEF3,0xFEF4],0x06CC=>[0xFBFC,0xFBFD,0xFBFE,0xFBFF],
    ];
}

function pdf_visual_codepoints(string $text): array
{
    $points=pdf_utf8_codepoints($text);$forms=pdf_arabic_forms();$logical=[];$count=count($points);
    for($i=0;$i<$count;$i++){
        $cp=$points[$i];if($cp>=0x064B&&$cp<=0x065F)continue;if($cp===0x200C||$cp===0x200D){$logical[]=$cp;continue;}
        if(!isset($forms[$cp])){$logical[]=$cp;continue;}
        $prev=$points[$i-1]??null;$next=$points[$i+1]??null;$current=$forms[$cp];$prevForms=$prev!==null?($forms[$prev]??null):null;$nextForms=$next!==null?($forms[$next]??null):null;
        $joinsPrev=$prevForms!==null&&$prevForms[2]!==null&&$current[1]!==null;$joinsNext=$nextForms!==null&&$current[2]!==null&&$nextForms[1]!==null;
        $logical[]=$joinsPrev&&$joinsNext?$current[3]:($joinsPrev?$current[1]:($joinsNext?$current[2]:$current[0]));
    }
    $visual=array_values(array_reverse(array_filter($logical,static fn(int $cp):bool=>$cp!==0x200C&&$cp!==0x200D)));
    $isLtr=static fn(int $cp):bool=>($cp>=0x30&&$cp<=0x7A)||($cp>=0x0660&&$cp<=0x0669)||($cp>=0x06F0&&$cp<=0x06F9)||in_array($cp,[0x2C,0x2D,0x2E,0x2F,0x3A,0x25,0x28,0x29,0x2B],true);
    for($i=0,$n=count($visual);$i<$n;){if(!$isLtr($visual[$i])){$i++;continue;}$start=$i;while($i<$n&&$isLtr($visual[$i]))$i++;$slice=array_reverse(array_slice($visual,$start,$i-$start));array_splice($visual,$start,$i-$start,$slice);}
    return $visual;
}

function pdf_font_info(string $path): array
{
    $data=file_get_contents($path);if(!is_string($data)||strlen($data)<1000)throw new RuntimeException('فونت فارسی فاکتور خوانده نشد.');$tables=[];$num=pdf_u16($data,4);
    for($i=0;$i<$num;$i++){$offset=12+$i*16;$tag=substr($data,$offset,4);$tables[$tag]=['offset'=>pdf_u32($data,$offset+8),'length'=>pdf_u32($data,$offset+12)];}
    foreach(['cmap','head','hhea','hmtx'] as $required)if(!isset($tables[$required]))throw new RuntimeException('ساختار فونت فارسی معتبر نیست.');
    $cmapBase=$tables['cmap']['offset'];$records=pdf_u16($data,$cmapBase+2);$subtable=0;
    for($i=0;$i<$records;$i++){$record=$cmapBase+4+$i*8;$platform=pdf_u16($data,$record);$encoding=pdf_u16($data,$record+2);$candidate=$cmapBase+pdf_u32($data,$record+4);if(pdf_u16($data,$candidate)===4&&(($platform===3&&$encoding===1)||$platform===0)){$subtable=$candidate;if($platform===3)break;}}
    if(!$subtable)throw new RuntimeException('جدول نویسه‌های فونت فارسی پشتیبانی نمی‌شود.');$segCount=intdiv(pdf_u16($data,$subtable+6),2);$endStart=$subtable+14;$startStart=$endStart+$segCount*2+2;$deltaStart=$startStart+$segCount*2;$rangeStart=$deltaStart+$segCount*2;$segments=[];
    for($i=0;$i<$segCount;$i++)$segments[]=['end'=>pdf_u16($data,$endStart+$i*2),'start'=>pdf_u16($data,$startStart+$i*2),'delta'=>pdf_s16($data,$deltaStart+$i*2),'range'=>pdf_u16($data,$rangeStart+$i*2),'range_pos'=>$rangeStart+$i*2];
    $head=$tables['head']['offset'];$hhea=$tables['hhea']['offset'];$hmtx=$tables['hmtx']['offset'];$metrics=pdf_u16($data,$hhea+34);$advances=[];$last=1000;
    for($i=0;$i<$metrics;$i++){$last=pdf_u16($data,$hmtx+$i*4);$advances[$i]=$last;}
    return ['data'=>$data,'segments'=>$segments,'advances'=>$advances,'last_advance'=>$last,'units'=>pdf_u16($data,$head+18),'bbox'=>[pdf_s16($data,$head+36),pdf_s16($data,$head+38),pdf_s16($data,$head+40),pdf_s16($data,$head+42)],'ascent'=>pdf_s16($data,$hhea+4),'descent'=>pdf_s16($data,$hhea+6)];
}

function pdf_glyph(array $font,int $codepoint): int
{
    if($codepoint>0xFFFF)return 0;foreach($font['segments'] as $segment){if($codepoint>$segment['end'])continue;if($codepoint<$segment['start'])return 0;if($segment['range']===0)return ($codepoint+$segment['delta'])&0xFFFF;$address=$segment['range_pos']+$segment['range']+2*($codepoint-$segment['start']);if($address+2>strlen($font['data']))return 0;$glyph=pdf_u16($font['data'],$address);return $glyph===0?0:(($glyph+$segment['delta'])&0xFFFF);}return 0;
}

function pdf_glyph_width(array $font,int $glyph): int
{
    $advance=$font['advances'][$glyph]??$font['last_advance'];return (int)round($advance*1000/max(1,$font['units']));
}

function pdf_wrap_text(string $text,int $limit=75): array
{
    $text=trim(preg_replace('/\s+/u',' ',$text)??$text);if($text==='')return [''];$lines=[];
    while(text_length($text)>$limit){$chunk=text_substr($text,0,$limit);$break=max((int)strrpos($chunk,' '),(int)strrpos($chunk,'،'));if($break<20)$break=$limit;$lines[]=trim(text_substr($text,0,$break));$text=trim(text_substr($text,$break,10000));}$lines[]=$text;return $lines;
}

function pdf_stream(string $data,string $extra=''): string { return '<< /Length '.strlen($data).($extra!==''?' '.$extra:'').' >>'."\nstream\n".$data."\nendstream"; }

function generate_persian_pdf(array $entries,string $filename): never
{
    $font=pdf_font_info(BASE_PATH.'/assets/fonts/DejaVuSans.ttf');$pages=array_chunk($entries,36);if(!$pages)$pages=[[]];$used=[];$encodedPages=[];
    foreach($pages as $page){$encoded=[];foreach($page as $entry){$points=pdf_visual_codepoints((string)($entry['text']??''));$glyphs=[];$width=0;foreach($points as $cp){$gid=pdf_glyph($font,$cp);$glyphs[]=$gid;$used[$gid]=$cp;$width+=pdf_glyph_width($font,$gid);} $encoded[]=['glyphs'=>$glyphs,'width'=>$width,'size'=>(int)($entry['size']??11),'gap'=>(int)($entry['gap']??20),'rule'=>!empty($entry['rule'])];}$encodedPages[]=$encoded;}
    ksort($used);$widths=[];$maps=[];foreach($used as $gid=>$cp){$widths.=$gid.' ['.pdf_glyph_width($font,(int)$gid).'] ';$maps[]=sprintf('<%04X> <%04X>',$gid,$cp);}
    $cmap="/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /MousaviUnicode def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";foreach(array_chunk($maps,100) as $chunk)$cmap.=count($chunk)." beginbfchar\n".implode("\n",$chunk)."\nendbfchar\n";$cmap.="endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    $scale=1000/max(1,$font['units']);$bbox=array_map(static fn(int $v):int=>(int)round($v*$scale),$font['bbox']);$ascent=(int)round($font['ascent']*$scale);$descent=(int)round($font['descent']*$scale);
    $objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[3]='<< /Type /Font /Subtype /Type0 /BaseFont /DejaVuSans /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 7 0 R >>';$objects[4]='<< /Type /Font /Subtype /CIDFontType2 /BaseFont /DejaVuSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /DW 1000 /W ['.trim($widths).'] /CIDToGIDMap /Identity >>';$objects[5]='<< /Type /FontDescriptor /FontName /DejaVuSans /Flags 32 /FontBBox ['.implode(' ',$bbox).'] /ItalicAngle 0 /Ascent '.$ascent.' /Descent '.$descent.' /CapHeight '.$ascent.' /StemV 80 /FontFile2 6 0 R >>';$objects[6]=pdf_stream($font['data'],'/Length1 '.strlen($font['data']));$objects[7]=pdf_stream($cmap);
    $kids=[];$next=8;foreach($encodedPages as $page){$content='0.15 0.2 0.27 rg 0.15 0.2 0.27 RG' . "\n";$y=790;foreach($page as $line){$size=$line['size'];if($line['rule']){$content.='0.6 w 50 '.$y.' m 545 '.$y.' l S' . "\n";$y-=$line['gap'];continue;}$hex='';foreach($line['glyphs'] as $gid)$hex.=sprintf('%04X',$gid);$width=$line['width']*$size/1000;$x=max(50,545-$width);$content.='BT /F1 '.$size.' Tf 1 0 0 1 '.number_format($x,2,'.','').' '.$y.' Tm <'.$hex.'> Tj ET' . "\n";$y-=$line['gap'];}$contentId=$next++;$pageId=$next++;$objects[$contentId]=pdf_stream($content);$objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';$kids[]=$pageId.' 0 R';}
    $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';ksort($objects);$pdf="%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];$max=max(array_keys($objects));for($i=1;$i<=$max;$i++){$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".($objects[$i]??'<<>>')."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]);$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    while(ob_get_level()>0)ob_end_clean();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',$filename).'"');header('Content-Length: '.strlen($pdf));header('Cache-Control: private, no-store');echo $pdf;exit;
}

function invoice_pdf_entries(array $order,array $items): array
{
    $entries=[];$add=static function(string $text,int $size=11,int $gap=20)use(&$entries):void{foreach(pdf_wrap_text($text,$size>=15?55:78) as $line)$entries[]=['text'=>$line,'size'=>$size,'gap'=>$gap];};
    $add((string)setting('store_name','فروشگاه گلریزان'),19,28);$add('فاکتور فروش',16,26);$entries[]=['rule'=>true,'gap'=>18];$add('شماره سفارش: '.$order['order_number'].'    تاریخ: '.jalali_format($order['created_at']));$add('خریدار: '.$order['customer_name'].'    موبایل: '.$order['phone']);$add('نشانی: '.$order['province'].'، '.$order['city'].'، '.$order['address'].'، کدپستی '.$order['postal_code']);$entries[]=['rule'=>true,'gap'=>18];$add('شرح کالا  |  تعداد  |  قیمت واحد  |  جمع',12,23);
    foreach($items as $item){$optionLabel=order_item_option_label($item);$title=$item['title'].($optionLabel!==''?' - '.$optionLabel:'');$add($title.'  |  '.$item['quantity'].'  |  '.format_price((int)$item['unit_price']).' تومان  |  '.format_price((int)$item['line_total']).' تومان',10,19);}
    $entries[]=['rule'=>true,'gap'=>18];$add('جمع کالاها: '.format_price((int)$order['subtotal']).' تومان');if((float)($order['payment_markup_percent']??0)>0)$add('افزایش قیمت ترب‌پی: '.rtrim(rtrim(number_format((float)$order['payment_markup_percent'],2,'.',''),'0'),'.').'٪ ('.format_price((int)($order['payment_markup_amount']??0)).' تومان)');if((int)$order['discount_amount']>0)$add('تخفیف: '.format_price((int)$order['discount_amount']).' تومان');$add('هزینه ارسال: '.format_price((int)$order['shipping_amount']).' تومان');$add('مبلغ نهایی: '.format_price((int)$order['total']).' تومان',14,25);$add('وضعیت سفارش: '.order_status_label((string)$order['order_status']).'    وضعیت پرداخت: '.payment_status_label((string)$order['payment_status']));$entries[]=['rule'=>true,'gap'=>18];$add('این فاکتور به‌صورت سیستمی صادر شده است.');return $entries;
}
